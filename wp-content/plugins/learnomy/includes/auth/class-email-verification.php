<?php
/**
 * Email verification — closes the registration squatting + spam-farm gap
 * (Basecamp #9928203396).
 *
 * @package Learnomy\Auth
 */

namespace Learnomy\Auth;

defined( 'ABSPATH' ) || exit;

class Email_Verification {

	/**
	 * Meta keys. We keep the verified flag separate from the token so the
	 * flag survives token expiry / re-issue and stays cheap to read on every
	 * auth-gated request.
	 */
	private const META_VERIFIED      = 'learnomy_email_verified';
	private const META_TOKEN_HASH    = 'learnomy_email_verify_token';
	private const META_TOKEN_EXPIRES = 'learnomy_email_verify_expires';

	/** Token lifetime — long enough that a forgotten tab still works the next day. */
	private const TOKEN_TTL = DAY_IN_SECONDS;

	/**
	 * Is the user's email verified?
	 *
	 * Existing users created before this feature (no meta row) are considered
	 * verified to avoid locking them out of their own accounts. New users are
	 * created with the meta explicitly set to 0, so the absence-of-meta path
	 * only catches the legacy population.
	 */
	public static function is_verified( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		$raw = get_user_meta( $user_id, self::META_VERIFIED, true );
		if ( '' === $raw ) {
			return true;
		}
		return '1' === (string) $raw;
	}

	/** Stamp a fresh user with the unverified flag. Call once after wp_insert_user. */
	public static function mark_unverified( int $user_id ): void {
		update_user_meta( $user_id, self::META_VERIFIED, 0 );
	}

	/**
	 * Block Application-Password authentication for unverified accounts.
	 *
	 * Headless-credential parity: /auth/token refuses to mint a JWT until the
	 * email is verified, and core Application Passwords (the mobile app's
	 * credential) are minted by CORE with no plugin gate — without this hook
	 * an unverified account could skip verification entirely by connecting
	 * the app. Web cookie login is deliberately unaffected: the site's chosen
	 * semantic is "verification gates headless credentials, not the session".
	 *
	 * Hooked on `wp_authenticate_application_password_errors` (fires during
	 * every App-Password authentication attempt, before success).
	 *
	 * @param \WP_Error $error Error accumulator — adding an error rejects the login.
	 * @param \WP_User  $user  The user being authenticated.
	 */
	public static function reject_unverified_app_password( \WP_Error $error, \WP_User $user ): void {
		if ( self::is_verified( (int) $user->ID ) ) {
			return;
		}

		$error->add(
			'learnomy_email_unverified',
			__( 'Please verify your email address before connecting an app. Check your inbox for the verification link.', 'learnomy' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Generate a token, store its hash + expiry, and return the plaintext
	 * token (caller embeds it in the email link). The hash-only-on-disk
	 * pattern means a DB leak doesn't yield usable tokens.
	 */
	public static function issue_token( int $user_id ): string {
		$token   = wp_generate_password( 40, false, false );
		$hashed  = wp_hash( $token );
		$expires = time() + self::TOKEN_TTL;

		update_user_meta( $user_id, self::META_TOKEN_HASH, $hashed );
		update_user_meta( $user_id, self::META_TOKEN_EXPIRES, $expires );

		return $token;
	}

	/**
	 * Build the URL the customer clicks to verify. Lands on home_url with a
	 * query var the init handler picks up.
	 */
	public static function verify_url( int $user_id, string $token ): string {
		return add_query_arg(
			array(
				'lrn_verify' => $token,
				'u'          => $user_id,
			),
			home_url( '/' )
		);
	}

	/**
	 * Constant-time check the supplied token against the stored hash + expiry.
	 * Returns true and clears the token meta on success; false otherwise.
	 */
	public static function consume_token( int $user_id, string $token ): bool {
		if ( $user_id <= 0 || '' === $token ) {
			return false;
		}

		$stored_hash = (string) get_user_meta( $user_id, self::META_TOKEN_HASH, true );
		$expires     = (int) get_user_meta( $user_id, self::META_TOKEN_EXPIRES, true );

		if ( '' === $stored_hash || $expires <= time() ) {
			return false;
		}

		if ( ! hash_equals( $stored_hash, wp_hash( $token ) ) ) {
			return false;
		}

		update_user_meta( $user_id, self::META_VERIFIED, 1 );
		delete_user_meta( $user_id, self::META_TOKEN_HASH );
		delete_user_meta( $user_id, self::META_TOKEN_EXPIRES );

		return true;
	}

	/**
	 * Send the verification email. Synchronous wp_mail — register_user is
	 * already a user-blocking POST so an extra mail send is acceptable. If
	 * delivery becomes a perf concern, switch to Action Scheduler.
	 */
	public static function send_email( int $user_id, string $token ): bool {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return false;
		}

		$verify_url = self::verify_url( $user_id, $token );
		$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		/* translators: %s: site name */
		$subject = sprintf( __( 'Verify your %s account', 'learnomy' ), $site_name );

		$message = sprintf(
			/* translators: 1: user display name 2: site name */
			__( 'Hi %1$s,', 'learnomy' ),
			$user->display_name ?: $user->user_login
		) . "\n\n";
		$message .= sprintf(
			/* translators: %s: site name */
			__( 'Thanks for signing up at %s. Click the link below to verify your email and finish setting up your account:', 'learnomy' ),
			$site_name
		) . "\n\n";
		$message .= $verify_url . "\n\n";
		$message .= __( 'This link will expire in 24 hours. If you did not create this account, you can ignore this email.', 'learnomy' );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		return wp_mail( $user->user_email, $subject, $message, $headers );
	}

	/**
	 * init-time handler — checks the inbound request for ?lrn_verify=...&u=
	 * and applies the verification + auto-login + redirect.
	 *
	 * Wired by class-learnomy.php on `init`.
	 */
	public static function maybe_handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public verify link
		$token   = isset( $_GET['lrn_verify'] ) ? sanitize_text_field( wp_unslash( $_GET['lrn_verify'] ) ) : '';
		$user_id = isset( $_GET['u'] ) ? absint( $_GET['u'] ) : 0;
		// phpcs:enable

		if ( '' === $token || $user_id <= 0 ) {
			return;
		}

		// Idempotent — repeat clicks of the verification link after success
		// just log the user back in.
		if ( self::is_verified( $user_id ) ) {
			self::finalize_session( $user_id, true );
		} elseif ( self::consume_token( $user_id, $token ) ) {
			self::finalize_session( $user_id, true );
		} else {
			self::finalize_session( 0, false );
		}
	}

	/**
	 * Auto-login the verified user and redirect them somewhere useful.
	 * $ok=false means the verification failed (expired token etc.) — bounce
	 * to the login page with a marker the frontend can render.
	 */
	private static function finalize_session( int $user_id, bool $ok ): void {
		if ( $ok && $user_id > 0 ) {
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id, true );
			$redirect = apply_filters(
				'learnomy_email_verified_redirect',
				\Learnomy\route_url( 'student-dashboard' ),
				$user_id
			);
		} else {
			$redirect = add_query_arg( array( 'lrn_verify' => 'failed' ), wp_login_url() );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
