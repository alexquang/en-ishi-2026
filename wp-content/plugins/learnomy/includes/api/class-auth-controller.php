<?php
/**
 * Authentication controller — token issuance, refresh, revocation, and user profile.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Learnomy\Models\Subscription;
use Learnomy\Models\InstructorApplication;
use Learnomy\Models\Api_Key;
use Learnomy\Services\Auth_Service;
use Learnomy\Services\Consent_Service;
use Learnomy\Permissions\Rate_Limiter;

class Auth_Controller extends Base_Controller {

	/** Character ceiling for a given/family name, shared by register + account update. */
	public const MAX_NAME_LENGTH = 100;

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'auth';
	}

	public function register_routes(): void {
		// POST /auth/token — exchange credentials for a token pair.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/token',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'login' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'username' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Your email address or username.', 'learnomy' ),
					],
					'password' => [
						'required'    => true,
						'type'        => 'string',
						'description' => __( 'Your account password.', 'learnomy' ),
					],
				],
			]
		);

		// POST /auth/token/refresh — swap a refresh token for a new access token.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/token/refresh',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'refresh' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'refresh_token' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// POST /auth/token/revoke — log out / invalidate a refresh token.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/token/revoke',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'revoke' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'refresh_token' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// POST /auth/app-password — trade a WordPress login for an Application
		// Password, which is the credential the mobile app actually holds.
		//
		// Public by necessity: this is how a member gets their FIRST credential,
		// so there is nothing to authenticate with yet. Core will not do this
		// exchange (its Basic auth accepts application passwords only, so the
		// route that mints them already requires one), and every core path to a
		// first credential runs through wp-admin, which WooCommerce makes
		// unreachable for students and subscribers.
		//
		// Guarded in App_Credentials: owner switch, TLS, uniform failures, the
		// email-verification gate, and a 409 rather than a silent 2FA bypass.
		// Rate limiting happens in the callback, BEFORE any credential is read.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/app-password',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'issue_app_password' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'username' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Your email address or username.', 'learnomy' ),
					],
					'password' => [
						'required'    => true,
						'type'        => 'string',
						'description' => __( 'Your account password.', 'learnomy' ),
					],
					'app_name' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Name shown beside this credential in your profile.', 'learnomy' ),
					],
					'app_id'   => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Stable identifier for this app install.', 'learnomy' ),
					],
				],
			]
		);

		// POST /auth/register — create a new student or instructor-applicant account.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/register',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'register_user' ],
				'permission_callback' => '__return_true',
				'args'                => [
					// `required => true` only asserts the key is PRESENT. An
					// empty string satisfies it, which is how a blank first name
					// walked in and produced accounts whose display name was
					// their login slug. Blankness is refused in register_user()
					// so the member gets "Please enter your first name." rather
					// than core's generic "Invalid parameter."; the validator
					// here holds the length ceiling.
					//
					// The validator is also NOT optional in the presence of a
					// sanitize_callback: WP only applies schema validation when
					// an arg names no sanitizer, so declaring one silently turns
					// off every keyword on the arg.
					'first_name'          => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => [ self::class, 'validate_person_name' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'last_name'           => [
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => [ self::class, 'validate_person_name' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'email'               => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					],
					'username'            => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_user',
						// Characters, not bytes. Byte-counting broke this bound in
						// BOTH directions: a single CJK character is 3 bytes and
						// so cleared the 3-character minimum, while a 25-character
						// Devanagari name is 75 bytes and was refused as too long.
						'validate_callback' => static function ( $v ) {
							return is_string( $v ) && mb_strlen( $v ) >= 3 && mb_strlen( $v ) <= 60;
						},
					],
					'password'            => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static function ( $v ) {
							// Passwords are not sanitized — sanitization would silently
							// strip valid characters. Cast to string for type safety.
							return is_string( $v ) ? $v : '';
						},
						'validate_callback' => static function ( $v ) {
							// Length-cap to prevent BCrypt DoS on giant inputs.
							return is_string( $v ) && strlen( $v ) <= 200;
						},
					],
					'password_confirm'    => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static function ( $v ) {
							return is_string( $v ) ? $v : '';
						},
						'validate_callback' => static function ( $v ) {
							return is_string( $v ) && strlen( $v ) <= 200;
						},
					],
					'role'                => [
						'required' => true,
						'type'     => 'string',
						'enum'     => [ 'student', 'instructor' ],
					],
					'bio'                 => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => static function ( $v ) {
							// Characters, not bytes — a 5000-byte cap gives a Hindi
							// or Japanese applicant roughly 1,600 characters where
							// an English one gets 5,000 (Basecamp 10190134583).
							return ! is_string( $v ) || mb_strlen( $v ) <= 5000;
						},
					],
					'teaching_experience' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => static function ( $v ) {
							// Characters, not bytes — a 5000-byte cap gives a Hindi
							// or Japanese applicant roughly 1,600 characters where
							// an English one gets 5,000 (Basecamp 10190134583).
							return ! is_string( $v ) || mb_strlen( $v ) <= 5000;
						},
					],
					'course_idea'         => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => static function ( $v ) {
							// Characters, not bytes — a 5000-byte cap gives a Hindi
							// or Japanese applicant roughly 1,600 characters where
							// an English one gets 5,000 (Basecamp 10190134583).
							return ! is_string( $v ) || mb_strlen( $v ) <= 5000;
						},
					],
					// paypal_email / bank_name / account_number args removed 2026-07-12
					// (seam P1-5/V3) — the payout details they carried were stored
					// encrypted and never read. Removed with the form + write path.
					// Honeypot — a hidden field bots fill in but humans don't.
					// Server rejects on any non-empty value (see register_user()).
					'lrn_hp'              => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					// Terms-of-service acceptance. Deliberately NOT `required`
					// in the schema: the gate only exists once the site owner
					// has published something to agree to, so requiredness is a
					// runtime question (Consent_Service::is_required()) that
					// register_user() answers with a readable message. `boolean`
					// makes WP accept the HTML checkbox wire values ('1', 'on',
					// 'true') as well as a JSON true from the mobile app.
					'accept_terms'        => [
						'required' => false,
						'type'     => 'boolean',
					],
				],
			]
		);

		// GET /auth/me — profile for the currently authenticated user.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'me' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
			]
		);

		// POST /auth/account — update the current user's profile. Name fields
		// update directly; email + password changes require the current
		// password, and an email change is staged behind a confirm-link sent to
		// the new address (never switched silently).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/account',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'update_account' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					// On THIS endpoint an omitted-or-empty name means "leave it
					// alone" (see update_account()), so blankness is legal here
					// and only the ceiling is enforced — the same ceiling
					// registration uses, so a name that is acceptable at signup
					// stays acceptable when edited.
					'display_name'     => [
						'type'              => 'string',
						'validate_callback' => [ self::class, 'validate_person_name' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'first_name'       => [
						'type'              => 'string',
						'validate_callback' => [ self::class, 'validate_person_name' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'last_name'        => [
						'type'              => 'string',
						'validate_callback' => [ self::class, 'validate_person_name' ],
						'sanitize_callback' => 'sanitize_text_field',
					],
					'email'            => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					],
					// Passwords are intentionally NOT sanitized (they may contain
					// any character); they are verified/validated in the handler.
					'current_password' => [ 'type' => 'string' ],
					'new_password'     => [ 'type' => 'string' ],
				],
			]
		);

		// POST /auth/api-keys — generate a new API key.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/api-keys',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_api_key' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => [
						'label' => [
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'A descriptive label for this key.', 'learnomy' ),
						],
					],
				],
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list_api_keys' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
				],
			]
		);

		// DELETE /auth/api-keys/{id} — revoke a key.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/api-keys/(?P<key_id>[a-f0-9\-]{36})',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_api_key' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'key_id' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);

		// DELETE /auth/account — student self-service account deletion (GDPR right to erasure).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/account',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_account' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
			]
		);

		// POST /auth/account/avatar — upload a square profile photo (multipart;
		// the file field is `avatar`). DELETE removes it (revert to Gravatar).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/account/avatar',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'update_avatar' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_avatar' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
				],
			]
		);

		// GET /auth/me/notifications — fetch notification preferences.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me/notifications',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_notification_prefs' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_notification_prefs' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => [
						'email_enrolled'    => [ 'type' => 'boolean' ],
						'email_completed'   => [ 'type' => 'boolean' ],
						'email_new_content' => [ 'type' => 'boolean' ],
						'push_reminders'    => [ 'type' => 'boolean' ],
						// `dark_mode` rides on this endpoint as the user-
						// preferences write surface — frontend's toggle
						// in assets/js/view.js posts here so the choice
						// survives a reload. The handler stores it in a
						// separate user_meta key (learnomy_dark_mode)
						// that Template_Loader::render() reads on every
						// Learnomy frontend page to attach the .lrn-dark
						// body class.
						'dark_mode'         => [ 'type' => 'boolean' ],
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Route handlers
	// -------------------------------------------------------------------------

	public function login( WP_REST_Request $request ): WP_REST_Response {
		$username = $request->get_param( 'username' );
		$password = $request->get_param( 'password' );

		// Protect the login endpoint from brute-force — track by IP.
		$ip      = \Learnomy\client_ip() ?: 'unknown';
		$rate_ok = $this->check_rate_limit( 0, 'auth_login_' . md5( $ip ) );
		if ( is_wp_error( $rate_ok ) ) {
			return $this->error_response( $rate_ok );
		}

		$service = new Auth_Service();
		$result  = $service->authenticate( $username, $password );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $this->login_error( $result ) );
		}

		// Email-verification gate (Basecamp #9928203396). Auth_Service issued
		// a JWT against valid credentials; we revoke it before returning if
		// the user hasn't clicked the verification link yet. Otherwise the
		// squatting attacker could just log in with a password they already
		// knew (the one they used to register the victim's email).
		$user_id_for_check = (int) ( $result['user']['id'] ?? 0 );
		if ( $user_id_for_check > 0 && ! \Learnomy\Auth\Email_Verification::is_verified( $user_id_for_check ) ) {
			// Re-issue + re-send the verification email so the customer has
			// a fresh link in their inbox after this failed attempt.
			$token = \Learnomy\Auth\Email_Verification::issue_token( $user_id_for_check );
			\Learnomy\Auth\Email_Verification::send_email( $user_id_for_check, $token );

			return $this->error_response(
				new \WP_Error(
					'learnomy_email_unverified',
					__( 'Please verify your email address before signing in. We just sent you a fresh verification link.', 'learnomy' ),
					[ 'status' => 403 ]
				)
			);
		}

		return $this->single_response( $result );
	}

	/**
	 * Turn an authentication failure into the right response.
	 *
	 * Two different things arrive here and they are not the same answer:
	 *
	 * - A CREDENTIAL failure (wrong password, unknown user). Answered with one
	 *   generic 401 that never says which half was wrong, because saying so
	 *   turns the endpoint into a username oracle.
	 * - A NON-CREDENTIAL denial. The password was right and something else in
	 *   the site's `authenticate` chain refused: a second factor, a security
	 *   plugin's challenge, an IP gate. These reach us because Auth_Service
	 *   authenticates through wp_signon(), which is the same reason those
	 *   plugins protect this endpoint at all.
	 *
	 * Both used to collapse into "the username or password you entered is
	 * incorrect." On a 2FA site that told a customer with a perfectly good
	 * password that it was wrong, and left them resetting a password that had
	 * never been the problem. The 409 restores the contract App_Connect already
	 * documents: a 2FA site answers 409 and the client falls back to the site's
	 * own login, which can complete the step we cannot.
	 *
	 * Keeping `learnomy_login_failed` for credential failures alone also keeps
	 * the app-password route honest — it counts only that code against its
	 * lockout buckets, on the stated grounds that a second factor means the
	 * password was fine.
	 *
	 * On enumeration: a 409 does tell an attacker the password was valid. That
	 * bit is already public — wp-login.php shows the second-factor screen for
	 * exactly the same input — so withholding it here protects nothing and only
	 * locks out the real customer. The third-party message is NOT forwarded; it
	 * may carry markup or internals.
	 *
	 * @param \WP_Error $error Error from the authenticate chain.
	 * @return \WP_Error Coded and statused for the client.
	 */
	private function login_error( \WP_Error $error ): \WP_Error {
		/**
		 * Error codes meaning "these credentials are wrong" and nothing more.
		 * Anything else came from a filter on the authenticate chain.
		 *
		 * @param string[] $codes Credential-failure error codes.
		 */
		$credential_codes = (array) apply_filters(
			'learnomy_login_credential_error_codes',
			array(
				'empty_username',
				'empty_password',
				'invalid_username',
				'invalid_email',
				'incorrect_password',
				'authentication_failed',
			)
		);

		if ( in_array( $error->get_error_code(), $credential_codes, true ) ) {
			return new \WP_Error(
				'learnomy_login_failed',
				__( 'The username or password you entered is incorrect.', 'learnomy' ),
				array( 'status' => 401 )
			);
		}

		return new \WP_Error(
			'learnomy_login_requires_site_login',
			__( 'This site needs an extra step to sign you in, such as a security code. Please sign in on the site to continue.', 'learnomy' ),
			array(
				'status' => 409,
				// The originating code, so a client or support can tell a second
				// factor from an IP block without anyone guessing plugin names.
				'reason' => $error->get_error_code(),
			)
		);
	}

	/**
	 * POST /auth/app-password — hand back the credential the app will hold.
	 *
	 * The account password is read from the request, passed straight to
	 * `wp_authenticate()`, and never stored, logged or echoed. Only the minted
	 * Application Password comes back.
	 */
	public function issue_app_password( WP_REST_Request $request ): WP_REST_Response {
		// Throttle BEFORE a credential is read. This route accepts a real
		// account password, which makes it a brute-force oracle in a way
		// wp-login.php is not: that page inherits whatever protection the site
		// has installed, a bare REST route inherits nothing.
		//
		// Two buckets, because one alone is not enough: the IP bucket stops one
		// host grinding through passwords, the username bucket stops a
		// distributed run at a single account. Note this does NOT use
		// check_rate_limit() — Rate_Limiter::check() returns true for any
		// action it has no configured limit for, which is safe for the student
		// actions it was written for and would mean "unlimited password
		// guesses" here.
		$username = (string) $request->get_param( 'username' );
		$ip       = \Learnomy\client_ip() ?: 'unknown';
		$limiter  = new Rate_Limiter();
		$buckets  = [ 'ip:' . $ip, 'user:' . strtolower( $username ) ];

		foreach ( $buckets as $bucket ) {
			if ( ! $limiter->check_credential_attempt( $bucket ) ) {
				return $this->error_response(
					new \WP_Error(
						'learnomy_too_many_attempts',
						__( 'Too many sign-in attempts. Please wait a few minutes and try again.', 'learnomy' ),
						[ 'status' => 429 ]
					)
				);
			}
		}

		// The global per-minute cap still applies on top, as it does everywhere.
		$rate_ok = $this->check_rate_limit( 0, 'auth_app_password' );
		if ( is_wp_error( $rate_ok ) ) {
			return $this->error_response( $rate_ok );
		}

		$app_name = (string) $request->get_param( 'app_name' );
		$app_id   = (string) $request->get_param( 'app_id' );

		$result = \Learnomy\Auth\App_Credentials::exchange(
			$username,
			(string) $request->get_param( 'password' ),
			'' !== $app_name ? $app_name : __( 'Learnomy app', 'learnomy' ),
			$app_id
		);

		if ( is_wp_error( $result ) ) {
			// Only a rejected CREDENTIAL counts against the buckets. A site
			// that wants a second factor, an unverified email or a disabled
			// switch are all "your password was fine" — counting those would
			// lock out members who did nothing wrong.
			if ( 'learnomy_login_failed' === $result->get_error_code() ) {
				foreach ( $buckets as $bucket ) {
					$limiter->record_credential_failure( $bucket );
				}
			}

			return $this->error_response( $result );
		}

		// An honest member who mistyped twice should not carry those failures.
		foreach ( $buckets as $bucket ) {
			$limiter->clear_credential_failures( $bucket );
		}

		return $this->single_response( $result );
	}

	/**
	 * A person's given/family name is a string within a sane ceiling.
	 *
	 * Deliberately does NOT reject a blank value, even on registration where a
	 * name is mandatory. The schema validator can only answer with WP core's
	 * generic "Invalid parameter.", and that is the wrong sentence to show
	 * someone who simply left a box empty. Emptiness is therefore enforced in
	 * `register_user()`, which owns the human message, while the ceiling lives
	 * here because it is a hard bound rather than a conversation.
	 *
	 * 100 characters is generous next to the longest names on record and still
	 * far below the `wp_usermeta.meta_value` longtext it lands in.
	 *
	 * @param mixed $value Raw request value.
	 */
	public static function validate_person_name( $value ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}

		return mb_strlen( trim( $value ) ) <= self::MAX_NAME_LENGTH;
	}

	/**
	 * Register a new student or instructor-applicant account.
	 *
	 * Students are assigned the `lrn_student` role immediately. Instructor
	 * applicants also start as `lrn_student` but an application row is
	 * created in `lrn_instructor_applications` for admin review.
	 */
	public function register_user( WP_REST_Request $request ): WP_REST_Response {
		// Honeypot — bots fill hidden fields humans never see. Reject silently
		// (200 OK) so scrapers can't probe the failure shape.
		$honeypot = (string) $request->get_param( 'lrn_hp' );
		if ( '' !== trim( $honeypot ) ) {
			return $this->single_response(
				[
					'user_id'               => 0,
					'message'               => __( 'Account created. Check your email to verify the address and finish setup.', 'learnomy' ),
					'verification_required' => true,
				],
				[],
				201
			);
		}

		/*
		 * Order matters here: the rate limiter runs BEFORE the challenge. A
		 * challenge consumer may call its provider over the network (Turnstile,
		 * reCAPTCHA), so verifying first would let anyone hammering this endpoint
		 * force one outbound request each - our latency and our provider quota,
		 * spent on their flood. Cheap local checks gate first; anything that talks
		 * to a third party goes behind them.
		 */
		// Rate-limit by IP to slow down automated sign-ups.
		$ip      = \Learnomy\client_ip() ?: 'unknown';
		$rate_ok = $this->check_rate_limit( 0, 'auth_register_' . md5( $ip ) );
		if ( is_wp_error( $rate_ok ) ) {
			return $this->error_response( $rate_ok );
		}

		/**
		 * Owner-pluggable signup challenge (CAPTCHA, arithmetic question, an
		 * allow-list — whatever the site wants), verified BEFORE the account
		 * exists.
		 *
		 * This seam exists because the site's own protection cannot reach this
		 * form. Registration creates the user with wp_insert_user() and fires
		 * none of the `register_*` hooks, so a security plugin's signup CAPTCHA
		 * never runs here, and an owner who switched one on has no way to know
		 * that.
		 *
		 * Core's `registration_errors` is deliberately NOT fired to close that
		 * gap. This route takes a JSON body, so $_POST is empty, and a CAPTCHA
		 * consumer reading its response field from $_POST would find nothing and
		 * fail every signup on the site. Turning "unprotected" into "nobody can
		 * register" is the worse outcome, so the seam is explicit and a consumer
		 * opts in knowing where to read the request from.
		 *
		 * Return true to allow, or a WP_Error to refuse — its message is shown
		 * to the person signing up, so write it for them.
		 *
		 * @param true|\WP_Error  $result  Verification result. Default true.
		 * @param \WP_REST_Request $request The registration request.
		 */
		$challenge = apply_filters( 'learnomy_registration_challenge_verify', true, $request );
		if ( is_wp_error( $challenge ) ) {
			$challenge->add_data( array( 'status' => 400 ) );
			return $this->error_response( $challenge );
		}

		$email      = $request->get_param( 'email' );
		$username   = $request->get_param( 'username' );
		$password   = $request->get_param( 'password' );
		$confirm    = $request->get_param( 'password_confirm' );
		$first_name = $request->get_param( 'first_name' );
		$last_name  = $request->get_param( 'last_name' );
		$role       = $request->get_param( 'role' );

		// Instructor registration closed (solo academy mode): reject a role=
		// instructor signup at the API too, matching the 404'd public route.
		if ( 'instructor' === $role && ! \Learnomy\instructor_registration_enabled() ) {
			return $this->error_response(
				$this->validation_error( __( 'Instructor registration is not open on this site.', 'learnomy' ) )
			);
		}

		// Instructor applicants must supply the application fields. Without
		// this gate the form's "instructor" radio could be submitted with empty
		// bio/expertise and we'd create a half-formed application row.
		if ( 'instructor' === $role ) {
			$bio  = trim( (string) $request->get_param( 'bio' ) );
			$idea = trim( (string) $request->get_param( 'course_idea' ) );
			if ( '' === $bio || '' === $idea ) {
				return $this->error_response(
					$this->validation_error( __( 'Instructor applications require a bio and a course idea.', 'learnomy' ) )
				);
			}
		}

		// Names, re-checked AFTER sanitization. The arg validator runs on the
		// raw value, so a submission of "<b></b>" passes it and then sanitizes
		// down to an empty string. This is the layer that guarantees the stored
		// value is a name, and it answers with a field-specific message rather
		// than the schema validator's generic "Invalid parameter".
		if ( '' === trim( (string) $first_name ) ) {
			return $this->error_response(
				$this->validation_error( __( 'Please enter your first name.', 'learnomy' ) )
			);
		}

		if ( '' === trim( (string) $last_name ) ) {
			return $this->error_response(
				$this->validation_error( __( 'Please enter your last name.', 'learnomy' ) )
			);
		}

		// Terms of service. Enforced server-side, because a `required` checkbox
		// is a browser courtesy that any scripted POST skips — and because the
		// point is not the click, it is the RECORD written below once the
		// account exists.
		if ( Consent_Service::is_required() && ! $request->get_param( 'accept_terms' ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_terms_not_accepted',
					__( 'Please accept the terms before creating your account.', 'learnomy' ),
					[ 'status' => 400 ]
				)
			);
		}

		// Basic server-side validation.
		if ( $password !== $confirm ) {
			return $this->error_response(
				$this->validation_error( __( 'Passwords do not match.', 'learnomy' ) )
			);
		}

		if ( strlen( $password ) < 8 ) {
			return $this->error_response(
				$this->validation_error( __( 'Password must be at least 8 characters.', 'learnomy' ) )
			);
		}

		if ( ! is_email( $email ) ) {
			return $this->error_response(
				$this->validation_error( __( 'Please enter a valid email address.', 'learnomy' ) )
			);
		}

		if ( email_exists( $email ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_email_exists',
					__( 'An account with this email address already exists.', 'learnomy' ),
					[ 'status' => 409 ]
				)
			);
		}

		if ( username_exists( $username ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_username_exists',
					__( 'This username is already taken.', 'learnomy' ),
					[ 'status' => 409 ]
				)
			);
		}

		// Reject usernames that shadow a plugin route segment — e.g. a user
		// named "create-course" makes /instructor/create-course/ resolve to the
		// builder instead of their profile (Basecamp #9937134001).
		if ( \Learnomy\is_reserved_slug( (string) $username ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_username_reserved',
					__( 'This username is reserved. Please choose a different one.', 'learnomy' ),
					[ 'status' => 422 ]
				)
			);
		}

		// Required custom account fields (site-owner-defined) — enforce before
		// creating the user so "required" actually blocks signup. The context is
		// the FORM being submitted: a field scoped to instructor applications
		// must not block a student signup that never showed it.
		$field_errors = ( new \Learnomy\Services\Custom_Fields() )->validate(
			$request,
			'instructor' === $role ? 'registration_instructor' : 'registration_student'
		);
		if ( ! empty( $field_errors ) ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_required_field', implode( ' ', $field_errors ), array( 'status' => 400 ) )
			);
		}

		/**
		 * Reject a registration or account save before anything is written.
		 *
		 * The partner to `learnomy_account_fields` (render) and
		 * `learnomy_account_fields_save` (persist). Save fires AFTER the account
		 * row exists, which is the right moment to store a value and far too late
		 * to refuse one, so a contributed field had no way to be required. This
		 * is that way: return a WP_Error and the request stops here, with the
		 * message shown on the form.
		 *
		 * @param true|\WP_Error   $valid   Validity so far. Default true.
		 * @param \WP_REST_Request $request The submitted request.
		 * @param string           $context Surface: registration_student or registration_instructor.
		 */
		$lrn_field_valid = apply_filters( 'learnomy_account_fields_validate', true, $request, 'instructor' === $role ? 'registration_instructor' : 'registration_student' );
		if ( is_wp_error( $lrn_field_valid ) ) {
			$lrn_field_valid->add_data( array( 'status' => 400 ) );
			return $this->error_response( $lrn_field_valid );
		}

		// Create the WordPress user — always starts as lrn_student.
		$user_id = wp_insert_user(
			[
				'user_login' => $username,
				'user_email' => $email,
				'user_pass'  => $password,
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'role'       => 'lrn_student',
			]
		);

		if ( is_wp_error( $user_id ) ) {
			$user_id->add_data( [ 'status' => 400 ] );
			return $this->error_response( $user_id );
		}

		// Write the consent proof the moment the account exists — who, when,
		// which documents, from which IP. Recorded only when the gate was
		// actually shown, so an install with no legal pages stores no
		// meaningless "agreed to nothing" stamp.
		if ( Consent_Service::is_required() ) {
			Consent_Service::record( (int) $user_id, 'registration' );
		}

		// Instructor applicants: create the application.
		$message = __( 'Account created successfully.', 'learnomy' );

		if ( 'instructor' === $role ) {
			$application_id = $this->create_instructor_application( $user_id, $request );
			// Payout-details collection removed 2026-07-12 (seam P1-5/V3): it stored
			// encrypted financial PII that nothing could read (no decrypt path, no
			// display surface). Instructor payouts run through Pro's Stripe Connect.
			$message = __( 'Account created and instructor application submitted. We will review it shortly.', 'learnomy' );

			/**
			 * Fires when a new instructor application is submitted through
			 * registration. The Notifier listens to email the applicant
			 * (received / pending) and the site admin (new application to
			 * review) — neither was notified before (Basecamp #9919574281).
			 *
			 * @param int $application_id The new application row ID.
			 * @param int $user_id        The applicant user ID.
			 */
			do_action( 'learnomy_instructor_application_submitted', $application_id, $user_id );
		}

		// Email verification (Basecamp #9928203396). Skip auto-login until
		// the user clicks the link emailed to the address they registered
		// with. Closes the squatting + spam-farm attack — an attacker can no
		// longer mint a logged-in session on a victim's email, and bots
		// can't chain straight into authenticated endpoints.
		\Learnomy\Auth\Email_Verification::mark_unverified( $user_id );
		$verify_token = \Learnomy\Auth\Email_Verification::issue_token( $user_id );
		\Learnomy\Auth\Email_Verification::send_email( $user_id, $verify_token );

		$message = 'instructor' === $role
			? __( 'Account created and instructor application submitted. Check your email to verify the address and finish setup.', 'learnomy' )
			: __( 'Account created. Check your email to verify the address and finish setup.', 'learnomy' );

		/**
		 * Fires after a new user registers through the Learnomy frontend.
		 *
		 * @param int    $user_id The newly created user ID.
		 * @param string $role    Either 'student' or 'instructor'.
		 */
		do_action( 'learnomy_user_registered', $user_id, $role );

		/**
		 * Persist custom account fields captured at REGISTRATION. Same action the
		 * account-details update path fires, so the `learnomy_account_fields`
		 * render hook (shown on both the registration forms and account-details)
		 * has a matching save on both surfaces — the headline use case is
		 * capturing compliance fields (license #, member ID, consent) at signup.
		 * Storage stays in the consumer plugin's user meta; $request carries the
		 * submitted values (WP core's user_register only passes the id).
		 *
		 * @param int             $user_id The newly created user.
		 * @param WP_REST_Request $request The registration request.
		 */
		do_action( 'learnomy_account_fields_save', $user_id, $request );

		return $this->single_response(
			[
				'user_id'               => $user_id,
				'message'               => $message,
				'verification_required' => true,
			],
			[],
			201
		);
	}

	public function refresh( WP_REST_Request $request ): WP_REST_Response {
		$raw_token = $request->get_param( 'refresh_token' );

		$service = new Auth_Service();
		$result  = $service->refresh( $raw_token );

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 401 ] );
			return $this->error_response( $result );
		}

		return $this->single_response( $result );
	}

	public function revoke( WP_REST_Request $request ): WP_REST_Response {
		$raw_token = $request->get_param( 'refresh_token' );

		$service = new Auth_Service();
		$service->revoke( $raw_token );

		// Always return success — callers should not be able to determine whether
		// a token existed before the revocation call.
		return $this->single_response( [ 'revoked' => true ] );
	}

	public function me( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return $this->error_response( $this->not_found( 'User' ) );
		}

		$active_subs = Subscription::get_active_for_user( $user_id );

		$profile = [
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'avatar_url'   => get_avatar_url( $user_id, [ 'size' => 96 ] ),
			'roles'        => $user->roles,
			'registered'   => $user->user_registered,
			'memberships'  => array_values( array_map( [ $this, 'format_subscription' ], $active_subs ) ),
		];

		$etag = $this->generate_etag( $profile );

		$not_modified = $this->maybe_not_modified( $etag, $request );
		if ( $not_modified ) {
			return $not_modified;
		}

		$response = $this->single_response( $profile );
		// Per-user (the caller's own profile): revalidate so a just-edited
		// profile is never served stale.
		return $this->add_etag( $response, $etag, true );
	}

	/**
	 * Update the current user's account: name (direct), email + password
	 * (current-password gated). Email changes are staged and confirmed via a
	 * link sent to the new address — never switched on this request.
	 */
	public function update_account( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		if ( ! $user ) {
			return $this->error_response( $this->not_found( 'User' ) );
		}

		// Required custom account fields — enforce before persisting anything.
		$field_errors = ( new \Learnomy\Services\Custom_Fields() )->validate( $request, 'account' );
		if ( ! empty( $field_errors ) ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_required_field', implode( ' ', $field_errors ), array( 'status' => 400 ) )
			);
		}

		/**
		 * Reject the save before anything is written. Partner to
		 * `learnomy_account_fields` and `learnomy_account_fields_save`; see the
		 * registration route for why this exists separately from save.
		 *
		 * @param true|\WP_Error   $valid   Validity so far. Default true.
		 * @param \WP_REST_Request $request The submitted request.
		 * @param string           $context Surface: account.
		 */
		$lrn_field_valid = apply_filters( 'learnomy_account_fields_validate', true, $request, 'account' );
		if ( is_wp_error( $lrn_field_valid ) ) {
			$lrn_field_valid->add_data( array( 'status' => 400 ) );
			return $this->error_response( $lrn_field_valid );
		}

		$display = trim( (string) $request->get_param( 'display_name' ) );
		$first   = trim( (string) $request->get_param( 'first_name' ) );
		$last    = trim( (string) $request->get_param( 'last_name' ) );
		$email   = trim( (string) $request->get_param( 'email' ) );
		$cur_pw  = (string) $request->get_param( 'current_password' );
		$new_pw  = (string) $request->get_param( 'new_password' );

		$email_changed = '' !== $email && strtolower( $email ) !== strtolower( $user->user_email );
		$pw_changed    = '' !== $new_pw;

		// Sensitive changes (email / password) require the current password.
		if ( $email_changed || $pw_changed ) {
			if ( '' === $cur_pw || ! wp_check_password( $cur_pw, $user->user_pass, $user_id ) ) {
				return $this->error_response( $this->validation_error( __( 'Your current password is incorrect.', 'learnomy' ) ) );
			}
		}

		if ( $pw_changed && strlen( $new_pw ) < 8 ) {
			return $this->error_response( $this->validation_error( __( 'Your new password must be at least 8 characters.', 'learnomy' ) ) );
		}

		// Validate + stage a new email; confirm before switching.
		$email_pending = false;
		if ( $email_changed ) {
			if ( ! is_email( $email ) ) {
				return $this->error_response( $this->validation_error( __( 'That email address is not valid.', 'learnomy' ) ) );
			}
			if ( email_exists( $email ) ) {
				return $this->error_response( $this->validation_error( __( 'That email address is already in use.', 'learnomy' ) ) );
			}
			$token = wp_generate_password( 24, false );
			update_user_meta( $user_id, 'learnomy_pending_email', $email );
			update_user_meta( $user_id, 'learnomy_pending_email_key', wp_hash_password( $token ) );
			\Learnomy\send_email_change_confirmation( $user_id, $email, $token );
			$email_pending = true;
		}

		// Apply name + password changes via the core API.
		$update = [ 'ID' => $user_id ];
		if ( '' !== $display ) {
			$update['display_name'] = $display;
		}
		if ( '' !== $first ) {
			$update['first_name'] = $first;
		}
		if ( '' !== $last ) {
			$update['last_name'] = $last;
		}
		if ( $pw_changed ) {
			$update['user_pass'] = $new_pw;
		}
		if ( count( $update ) > 1 ) {
			$result = wp_update_user( $update );
			if ( is_wp_error( $result ) ) {
				return $this->error_response( $this->validation_error( $result->get_error_message() ) );
			}
		}

		/**
		 * Fires after the core account fields persist, so integrations can save
		 * their own profile fields (license numbers, member IDs, consent flags…)
		 * rendered via the `learnomy_account_fields` action. Field storage stays
		 * in the consumer plugin (user meta); Learnomy only exposes the surface.
		 *
		 * @param int             $user_id The account being updated.
		 * @param WP_REST_Request $request The account-update request.
		 */
		do_action( 'learnomy_account_fields_save', $user_id, $request );

		// Changing the password destroys the user's sessions — re-issue the
		// current one so the customer isn't logged out of the page they're on.
		if ( $pw_changed ) {
			wp_set_auth_cookie( $user_id, true );
		}

		$fresh = get_userdata( $user_id );
		return $this->single_response(
			[
				'id'               => $user_id,
				'display_name'     => $fresh->display_name,
				'email'            => $fresh->user_email,
				'pending_email'    => $email_pending ? $email : '',
				'password_changed' => $pw_changed,
			]
		);
	}

	public function get_notification_prefs( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$prefs   = $this->load_notification_prefs( $user_id );
		return $this->single_response( $prefs );
	}

	public function update_notification_prefs( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$current = $this->load_notification_prefs( $user_id );

		$updatable = [ 'email_enrolled', 'email_completed', 'email_new_content', 'push_reminders' ];

		foreach ( $updatable as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$current[ $key ] = (bool) $value;
			}
		}

		update_user_meta( $user_id, '_learnomy_notification_prefs', $current );

		// `dark_mode` is a frontend display preference — stored in its own
		// user_meta key (`learnomy_dark_mode`) that Template_Loader reads
		// on every Learnomy frontend page render. We piggyback on this
		// notifications endpoint because it's the only existing user-prefs
		// write surface; if a future redesign adds a generic /me/preferences
		// endpoint, dark_mode should move there.
		$dark_mode = $request->get_param( 'dark_mode' );
		if ( null !== $dark_mode ) {
			update_user_meta( $user_id, 'learnomy_dark_mode', (bool) $dark_mode ? 1 : 0 );
			$current['dark_mode'] = (bool) $dark_mode;
		}

		return $this->single_response( $current );
	}

	// -------------------------------------------------------------------------
	// API key management
	// -------------------------------------------------------------------------

	/**
	 * Generate a new API key. The full key is returned exactly once.
	 */
	public function create_api_key( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$label   = $request->get_param( 'label' );

		if ( empty( $label ) ) {
			return $this->error_response(
				$this->validation_error( __( 'A label is required.', 'learnomy' ) )
			);
		}

		$result = Api_Key::create( $user_id, $label );

		return $this->single_response(
			[
				'id'      => $result['id'],
				'label'   => $result['label'],
				'api_key' => $result['raw_key'],
				'message' => __( 'API key created. Copy it now. It will not be shown again.', 'learnomy' ),
			],
			[],
			201
		);
	}

	/**
	 * List all API keys for the current user (safe output — no hashes).
	 */
	public function list_api_keys( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$keys    = Api_Key::get_all_for_user( $user_id );

		$safe = array_map( [ Api_Key::class, 'safe_output' ], $keys );

		return $this->paginated_response( $safe, count( $safe ) );
	}

	/**
	 * Revoke an API key by its UUID.
	 */
	public function delete_api_key( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$key_id  = $request->get_param( 'key_id' );

		$revoked = Api_Key::revoke( $user_id, $key_id );

		if ( ! $revoked ) {
			return $this->error_response( $this->not_found( 'API Key' ) );
		}

		return $this->single_response( [ 'revoked' => true ] );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function format_subscription( object $sub ): array {
		return [
			'id'                   => (int) $sub->id,
			'plan_id'              => (int) $sub->plan_id,
			'status'               => $sub->status,
			'current_period_start' => $sub->current_period_start,
			'current_period_end'   => $sub->current_period_end,
		];
	}

	/**
	 * Create an instructor application row from registration data.
	 */
	private function create_instructor_application( int $user_id, WP_REST_Request $request ): int {
		// The registration form collects one combined "Bio / Expertise" field
		// (stored as `bio`) — there is no separate `expertise` input, so the
		// legacy `expertise` column was always written empty. The admin review
		// now surfaces Bio + Course Idea instead; we no longer write the dead
		// `expertise` column.
		return InstructorApplication::create(
			[
				'user_id'             => $user_id,
				'bio'                 => $request->get_param( 'bio' ) ?? '',
				'teaching_experience' => $request->get_param( 'teaching_experience' ) ?? '',
				'course_idea'         => $request->get_param( 'course_idea' ) ?? '',
				'status'              => 'pending',
			]
		);
	}

	// store_payout_details(), payout_data_key() and encrypt_payout_payload()
	// removed 2026-07-12 (seam P1-5/V3): they encrypted instructor payout PII
	// into user meta that nothing ever read (no decrypt path, no display
	// surface). Instructor payouts run through Pro's Stripe Connect. Re-add
	// only alongside a genuine read/display surface.


	private function load_notification_prefs( int $user_id ): array {
		$stored = get_user_meta( $user_id, '_learnomy_notification_prefs', true );
		return is_array( $stored )
			? array_merge( self::notification_pref_defaults(), $stored )
			: self::notification_pref_defaults();
	}

	/**
	 * Canonical default values for the notification-preferences user_meta key.
	 *
	 * Single source of truth consumed by both this controller and
	 * Account_Details_View_Data so a new pref key is never added in one
	 * place but not the other.
	 *
	 * @return array<string,bool>
	 */
	public static function notification_pref_defaults(): array {
		return [
			'email_enrolled'    => true,
			'email_completed'   => true,
			'email_new_content' => false,
			'push_reminders'    => true,
		];
	}

	/**
	 * DELETE /auth/account — student self-service account deletion.
	 *
	 * Runs every eraser registered on `wp_privacy_personal_data_erasers` — the
	 * same set Tools → Erase Personal Data uses, i.e. Learnomy Free's, Pro's and
	 * any third party's — via `Privacy::run_registered_erasers()`, and only then
	 * removes the WP user row. That ordering is load-bearing: every eraser
	 * resolves its subject with `get_user_by( 'email' )`, so anything run after
	 * `wp_delete_user()` silently no-ops.
	 *
	 * This docblock used to describe that flow while the code below called
	 * `wp_delete_user()` and nothing else. Cleanup then came only from the
	 * `deleted_user` hook (`User_Cleanup_Service::purge_user_data()`), a narrower
	 * and separately-maintained list that had drifted from
	 * `Privacy::learner_data_tables()` — so push tokens, notifications, lesson
	 * unlocks, instructor applications and every Pro table survived a call that
	 * answered `{"deleted":true}` (Basecamp 10134140053).
	 *
	 * The student is logged out before the row is removed so the auth cookie
	 * can't be replayed.
	 *
	 * Admins cannot self-erase via this endpoint (manage_options check) — they
	 * must use the WP admin panel to preserve audit trails.
	 */
	public function delete_account( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return $this->error_response( $this->not_found( 'Account' ) );
		}

		// Re-authenticate with the ACCOUNT password before destroying the
		// account (mobile-API contract WI-6, Apple 5.1.1(v)). App Passwords are
		// minted by core and the app holds one — a stolen app credential must
		// NOT be able to delete the account. Require the real login password
		// plus an explicit typed confirmation so a mis-fired request is inert.
		$password = (string) $request->get_param( 'password' );
		$confirm  = (string) $request->get_param( 'confirm' );

		if ( 'DELETE' !== $confirm ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_delete_unconfirmed',
					__( 'Account deletion must be confirmed.', 'learnomy' ),
					[ 'status' => 400 ]
				)
			);
		}

		if ( '' === $password || ! wp_check_password( $password, $user->user_pass, $user_id ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_delete_bad_password',
					__( 'Your account password is incorrect.', 'learnomy' ),
					[ 'status' => 403 ]
				)
			);
		}

		// Prevent admins from accidentally self-deleting via the student flow.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_admin_cannot_self_delete',
					__( 'Administrators cannot delete their own account through this endpoint. Use the WordPress admin panel.', 'learnomy' ),
					[ 'status' => 403 ]
				)
			);
		}

		// Invalidate all sessions before removal so in-flight requests can't
		// authenticate after the row is gone.
		$sessions = \WP_Session_Tokens::get_instance( $user_id );
		$sessions->destroy_all();

		/**
		 * Fires immediately before the WordPress user row is removed during
		 * a student self-service account deletion. Use this hook to clean up
		 * any plugin-owned data keyed on user_id BEFORE the user is gone.
		 * Learnomy's own GDPR exporters/erasers in class-privacy.php handle
		 * the LMS tables; Pro extensions can hook here for their own tables.
		 *
		 * @param int    $user_id    The user being erased.
		 * @param string $user_email The user's email address (for audit logs).
		 */
		do_action( 'learnomy_before_student_self_delete', $user_id, $user->user_email );

		// Erase first, delete second. Every registered eraser looks the subject
		// up by email, so this cannot be moved below wp_delete_user().
		$erasure = \Learnomy\Privacy::run_registered_erasers( $user->user_email );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$deleted = wp_delete_user( $user_id );

		if ( ! $deleted ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_delete_failed',
					__( 'Your account could not be deleted. Please contact support.', 'learnomy' ),
					[ 'status' => 500 ]
				)
			);
		}

		/**
		 * Fires after the student user row is successfully removed.
		 *
		 * @param string $user_email The deleted user's email (for audit logging).
		 */
		do_action( 'learnomy_student_account_deleted', $user->user_email );

		// Report retention HONESTLY, the same way the WP erasure screen does.
		// Some rows legitimately survive with the person unlinked (financial and
		// audit records carry a retention duty), and the member is entitled to be
		// told which — "deleted: true" alone is the claim that got this wrong.
		return $this->single_response(
			[
				'deleted'  => true,
				'erasers'  => (int) $erasure['erasers'],
				'retained' => (bool) $erasure['items_retained'],
				'messages' => array_map( 'strval', $erasure['messages'] ),
			]
		);
	}

	/**
	 * POST /auth/account/avatar — store the member's square profile photo.
	 * Expects a multipart upload in the `avatar` file field (the cropped square
	 * blob produced by the account page). Returns the new avatar URL so the UI
	 * can repaint without a reload.
	 */
	public function update_avatar( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$files = $request->get_file_params();
		$file  = isset( $files['avatar'] ) && is_array( $files['avatar'] ) ? $files['avatar'] : null;
		if ( null === $file ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_avatar_no_file',
					__( 'No image was received. Please choose a photo and try again.', 'learnomy' ),
					[ 'status' => 400 ]
				)
			);
		}

		$service       = new \Learnomy\Services\Avatar_Service();
		$attachment_id = $service->set_from_upload( (int) $user_id, $file );
		if ( is_wp_error( $attachment_id ) ) {
			return $this->error_response( $attachment_id );
		}

		return $this->single_response(
			[
				'avatar_id'  => (int) $attachment_id,
				'avatar_url' => get_avatar_url( (int) $user_id, [ 'size' => 192 ] ),
			]
		);
	}

	/**
	 * DELETE /auth/account/avatar — remove the custom photo, reverting the
	 * member to their Gravatar fallback.
	 */
	public function delete_avatar( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$service = new \Learnomy\Services\Avatar_Service();
		$service->remove( (int) $user_id );

		return $this->single_response(
			[
				'removed'    => true,
				'avatar_url' => get_avatar_url( (int) $user_id, [ 'size' => 192 ] ),
			]
		);
	}
}
