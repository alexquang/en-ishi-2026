<?php
/**
 * Registration captcha — a built-in, first-party signup challenge.
 *
 * A consumer of the `learnomy_registration_challenge` /
 * `_verify` seam, exactly like a third-party provider would be. Nothing here is
 * privileged: an owner who installs a Turnstile add-on later gets the same
 * hooks, and can switch this one off.
 *
 * Deliberately an arithmetic question rather than an image or a hosted widget:
 *
 * - It calls nobody. No third-party script, no API key to manage, no visitor
 *   data leaving the site, which is the whole reason to have a built-in option.
 * - A screen reader can answer it. Distorted-image captchas fail that outright,
 *   and audio fallbacks are their own accessibility problem.
 *
 * What it is honestly for: the generic spam bot that posts at any form it
 * finds. A bot written for THIS plugin can read the question and add two
 * numbers. That is true of every question-based captcha, and it is why this
 * sits alongside the honeypot, per-IP rate limiting and email verification
 * rather than replacing any of them.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and verifies the built-in signup challenge.
 */
class Registration_Captcha {

	/** Settings key holding the on/off switch. */
	public const SETTING_KEY = 'registration_captcha';

	/** Form field carrying the person's answer. */
	private const FIELD_ANSWER = 'lrn_captcha_answer';

	/** Form field carrying the signed expectation. */
	private const FIELD_TOKEN = 'lrn_captcha_token';

	/**
	 * How long a rendered question stays valid.
	 *
	 * Long, on purpose. The form is a public page that a host may serve from a
	 * cache, so the token has to outlive the cached copy or every visitor gets
	 * an expired challenge and nobody can sign up. Two hours is well past any
	 * sane page-cache TTL and still short enough that a harvested token is not
	 * a lasting asset.
	 */
	private const TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Wire the render + verify consumers onto the shared seam.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'learnomy_registration_challenge', array( $this, 'render' ) );
		add_filter( 'learnomy_registration_challenge_verify', array( $this, 'verify' ), 10, 2 );
	}

	/**
	 * Whether the owner switched it on.
	 *
	 * Off by default. Switching this on for every existing site during an update
	 * would put a new required question in front of a signup form that was
	 * converting fine, which is not ours to decide.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$settings = get_option( 'learnomy_settings', array() );
		$on       = is_array( $settings ) && ! empty( $settings[ self::SETTING_KEY ] );

		/**
		 * Filter whether the built-in signup captcha runs.
		 *
		 * @param bool $on Whether the challenge is active.
		 */
		return (bool) apply_filters( 'learnomy_registration_captcha_enabled', $on );
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Print the question, its signed expectation, and the answer input.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}

		$left   = wp_rand( 1, 9 );
		$right  = wp_rand( 1, 9 );
		$expiry = time() + self::TTL;
		$token  = $this->sign( $left + $right, $expiry );

		$id = 'lrn-captcha-answer';
		?>
		<p class="lrn-field lrn-captcha">
			<label class="lrn-field__label" for="<?php echo esc_attr( $id ); ?>">
				<?php
				printf(
					/* translators: 1: first number, 2: second number. */
					esc_html__( 'Anti-spam check: what is %1$d + %2$d?', 'learnomy' ),
					(int) $left,
					(int) $right
				);
				?>
				<span class="required" aria-hidden="true">*</span>
			</label>
			<input
				class="lrn-field__input"
				type="text"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( self::FIELD_ANSWER ); ?>"
				inputmode="numeric"
				autocomplete="off"
				required
			>
			<?php // Carries the expected answer as a signature, so nothing has to be stored server-side per visitor. ?>
			<input type="hidden" name="<?php echo esc_attr( self::FIELD_TOKEN ); ?>" value="<?php echo esc_attr( $expiry . '|' . $token ); ?>">
		</p>
		<?php
	}

	// ── Verify ────────────────────────────────────────────────────────────────

	/**
	 * Refuse the registration when the answer is wrong, missing or stale.
	 *
	 * @param true|\WP_Error   $result  Result so far from other consumers.
	 * @param \WP_REST_Request $request The registration request.
	 * @return true|\WP_Error
	 */
	public function verify( $result, $request ) {
		// Another consumer already refused — leave their reason intact.
		if ( is_wp_error( $result ) || ! $this->is_enabled() ) {
			return $result;
		}

		$answer = trim( (string) $request->get_param( self::FIELD_ANSWER ) );
		$raw    = (string) $request->get_param( self::FIELD_TOKEN );

		if ( '' === $answer || '' === $raw || ! str_contains( $raw, '|' ) ) {
			return $this->refuse();
		}

		list( $expiry, $token ) = explode( '|', $raw, 2 );
		$expiry                 = (int) $expiry;

		if ( $expiry < time() ) {
			return new \WP_Error(
				'learnomy_captcha_expired',
				__( 'This form has been open for a while. Please reload the page and try again.', 'learnomy' )
			);
		}

		// Compare signatures, not answers: the expected value never travels in
		// readable form, and hash_equals keeps the comparison constant-time.
		if ( ! hash_equals( $this->sign( (int) $answer, $expiry ), $token ) ) {
			return $this->refuse();
		}

		return $result;
	}

	/**
	 * The one refusal message. Says what to do, blames nobody.
	 *
	 * @return \WP_Error
	 */
	private function refuse(): \WP_Error {
		return new \WP_Error(
			'learnomy_captcha_failed',
			__( 'That answer was not right. Please try the anti-spam question again.', 'learnomy' )
		);
	}

	/**
	 * Sign an expected answer for a given expiry.
	 *
	 * Keyed on wp_salt(), so a token minted on one site is worthless on another
	 * and nothing needs storing between the render and the submit.
	 *
	 * @param int $answer Expected answer.
	 * @param int $expiry Unix timestamp the token dies at.
	 * @return string
	 */
	private function sign( int $answer, int $expiry ): string {
		return hash_hmac( 'sha256', $answer . '|' . $expiry, wp_salt( 'learnomy_captcha' ) );
	}
}
