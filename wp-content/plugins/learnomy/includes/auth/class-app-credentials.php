<?php
/**
 * Trade a member's ordinary WordPress login for an Application Password.
 *
 * WHY THIS EXISTS AT ALL
 *
 * WordPress core will not do this exchange. Core's Basic auth handler
 * (`wp_authenticate_application_password()`) validates against stored
 * application passwords ONLY, so `POST /wp/v2/users/{id}/application-passwords`
 * already requires one: the route that mints the credential needs the
 * credential. Every core path to a member's FIRST Application Password runs
 * through a wp-admin screen under cookie auth, and on a WooCommerce site those
 * screens are unreachable for students and subscribers (see
 * `App_Authorize_Access`, which repairs that half).
 *
 * The result was that the people the app is built for could not sign in, while
 * every owner reported the same thing: their members have a WordPress password
 * and expect to type it.
 *
 * WHAT THIS IS NOT
 *
 * Not a second authentication system. `wp_authenticate()` does the actual
 * authentication, exactly as wp-login.php does, so every `authenticate` filter
 * on the site still runs — security plugins, membership gates and 2FA all get
 * their say. This class only decides what to hand back once core has said yes.
 *
 * THE AUTHORIZE FLOW REMAINS PREFERRED, and the app treats it as the default.
 * Core's screen runs the site's real login, so it can complete an interactive
 * second factor; a single REST call cannot. When this route detects that the
 * site wanted more than a password it answers 409 and tells the app to send the
 * member through the interactive flow rather than pretending the password was
 * wrong or, worse, letting them past a factor the owner configured.
 *
 * THE RISK, STATED PLAINLY
 *
 * This route accepts a real account password, which makes it a brute-force
 * oracle in a way `wp-login.php` is not (that page inherits whatever protection
 * the site has installed). It is therefore: HTTPS-only, rate-limited per IP
 * before any credential is read, uniform in its failure message so it cannot
 * enumerate accounts, off when the owner turns it off, and silent about the
 * password in every log and error path.
 *
 * @package Learnomy
 */

namespace Learnomy\Auth;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_User;

class App_Credentials {

	/**
	 * Error codes that mean "those credentials were wrong".
	 *
	 * Anything else coming back from `wp_authenticate()` means the site
	 * rejected the sign-in for its OWN reason -- a second factor, a membership
	 * gate, a security plugin -- which is a different answer and gets a
	 * different status. Mirrors `Login_Flow`'s list, which collapses the same
	 * three codes so the response cannot answer "does this account exist?".
	 */
	private const CREDENTIAL_FAILURE_CODES = [
		'invalid_username',
		'invalid_email',
		'incorrect_password',
		'authentication_failed',
	];

	/**
	 * Is the credentials exchange available on this site?
	 *
	 * Owner switch, default ON. An owner running 2FA will want every member on
	 * the interactive flow, and turning this off is how they say so.
	 */
	public static function is_enabled(): bool {
		$settings = get_option( 'learnomy_settings', [] );
		$on       = ! isset( $settings['app_password_login'] ) || ! empty( $settings['app_password_login'] );

		/**
		 * Filter whether members may exchange a WordPress password for an
		 * Application Password.
		 *
		 * @since 1.8.1
		 *
		 * @param bool $on Whether the exchange is available.
		 */
		return (bool) apply_filters( 'learnomy_app_password_login_enabled', $on );
	}

	/**
	 * Exchange credentials for an Application Password.
	 *
	 * @param string $username Username or email.
	 * @param string $password The member's account password.
	 * @param string $app_name Label shown in the member's profile.
	 * @param string $app_id   Stable per-install id, so a repeat sign-in
	 *                         replaces the row instead of adding another.
	 * @return array{user_login:string,password:string,app_id:string}|WP_Error
	 */
	public static function exchange( string $username, string $password, string $app_name, string $app_id ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error(
				'learnomy_app_passwords_off',
				__( 'This site has turned off app sign-in. Ask the site owner to enable it.', 'learnomy' ),
				[ 'status' => 403 ]
			);
		}

		// Core refuses to issue Application Passwords without TLS, and so must
		// we: this request carries the real account password. A Local / dev
		// environment is the documented exception, and it is core's own.
		if ( ! wp_is_application_passwords_available() ) {
			return new WP_Error(
				'learnomy_app_passwords_off',
				__( 'This site cannot issue app passwords. It needs a secure (https) connection.', 'learnomy' ),
				[ 'status' => 403 ]
			);
		}

		$user = wp_authenticate( $username, $password );

		if ( is_wp_error( $user ) ) {
			return self::translate_auth_error( $user );
		}

		if ( ! $user instanceof WP_User ) {
			return new WP_Error(
				'learnomy_login_failed',
				__( 'The username or password you entered is incorrect.', 'learnomy' ),
				[ 'status' => 401 ]
			);
		}

		// `wp_authenticate()` does not log anyone in, but a site's own filters
		// may have primed a session. Nothing here wants a cookie: the app is a
		// stateless client and the credential it gets back IS the session.
		wp_clear_auth_cookie();

		// Parity with /auth/token: an unverified email must not be able to mint
		// a credential either, or this route becomes the verification bypass
		// that gate exists to close.
		if ( ! Email_Verification::is_verified( $user->ID ) ) {
			$token = Email_Verification::issue_token( $user->ID );
			Email_Verification::send_email( $user->ID, $token );

			return new WP_Error(
				'learnomy_email_unverified',
				__( 'Please verify your email address before signing in. We just sent you a fresh verification link.', 'learnomy' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new WP_Error(
				'learnomy_app_passwords_off',
				__( 'App sign-in is not available for this account.', 'learnomy' ),
				[ 'status' => 403 ]
			);
		}

		self::forget_previous_install( $user->ID, $app_id );

		$created = \WP_Application_Passwords::create_new_application_password(
			$user->ID,
			[
				'name'   => $app_name,
				'app_id' => $app_id,
			]
		);

		if ( is_wp_error( $created ) ) {
			return new WP_Error(
				'learnomy_app_passwords_off',
				__( 'This site could not issue an app password. Ask the site owner to check their settings.', 'learnomy' ),
				[ 'status' => 403 ]
			);
		}

		/**
		 * Fires after a member exchanges their password for an app credential.
		 *
		 * The credential itself is deliberately NOT passed: a listener that
		 * logged it would undo the reason this class is careful.
		 *
		 * @since 1.8.1
		 *
		 * @param int    $user_id  Member who signed in.
		 * @param string $app_id   Stable per-install id.
		 * @param string $app_name Label stored on the credential.
		 */
		do_action( 'learnomy_app_credential_issued', $user->ID, $app_id, $app_name );

		return [
			'user_login' => $user->user_login,
			'password'   => $created[0],
			'app_id'     => $app_id,
		];
	}

	/**
	 * Drop the credential this install was given last time.
	 *
	 * Without this, every sign-in leaves another row in the member's profile
	 * and they accumulate credentials they will never know to revoke. Keyed on
	 * `app_id` so it only ever forgets THIS install, never another device.
	 *
	 * @param int    $user_id Member.
	 * @param string $app_id  Stable per-install id.
	 */
	private static function forget_previous_install( int $user_id, string $app_id ): void {
		if ( '' === $app_id ) {
			return;
		}

		foreach ( \WP_Application_Passwords::get_user_application_passwords( $user_id ) as $existing ) {
			if ( isset( $existing['app_id'] ) && $app_id === $existing['app_id'] ) {
				\WP_Application_Passwords::delete_application_password( $user_id, $existing['uuid'] );
			}
		}
	}

	/**
	 * Decide what a failed `wp_authenticate()` actually means.
	 *
	 * A wrong password and "this site wants a second factor" are different
	 * answers and must not collapse into one. Reporting a 2FA block as a bad
	 * password would send the member round the same failing loop; reporting it
	 * as success would walk them past a factor the owner configured. So: known
	 * credential codes become a uniform 401, and everything else becomes a 409
	 * that tells the app to hand off to the interactive flow, which CAN
	 * complete a second step.
	 *
	 * @param WP_Error $error The error `wp_authenticate()` returned.
	 * @return WP_Error
	 */
	private static function translate_auth_error( WP_Error $error ): WP_Error {
		$codes = $error->get_error_codes();

		foreach ( $codes as $code ) {
			if ( in_array( $code, self::CREDENTIAL_FAILURE_CODES, true ) ) {
				// Uniform on purpose: `invalid_username` vs `incorrect_password`
				// is exactly the oracle that answers "does this account exist?".
				return new WP_Error(
					'learnomy_login_failed',
					__( 'The username or password you entered is incorrect.', 'learnomy' ),
					[ 'status' => 401 ]
				);
			}
		}

		return new WP_Error(
			'learnomy_second_factor',
			__( 'This site needs another step to sign you in. Use the WordPress approval screen instead.', 'learnomy' ),
			[ 'status' => 409 ]
		);
	}
}
