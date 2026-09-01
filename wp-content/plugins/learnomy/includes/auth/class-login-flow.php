<?php
/**
 * WordPress login integration — where a member lands, and what a failed
 * attempt is allowed to reveal.
 *
 * Learnomy ships no login form of its own: members sign in through core's
 * `wp-login.php`. That makes core's two defaults Learnomy's problem, because it
 * is Learnomy that put thousands of student and instructor accounts behind that
 * form.
 *
 * @package Learnomy
 */

namespace Learnomy\Auth;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_User;

class Login_Flow {

	/**
	 * Core error codes that answer "does this account exist?".
	 *
	 * `invalid_username` / `invalid_email` mean no such account; the sibling
	 * `incorrect_password` means the account is real. Telling those two apart is
	 * the whole oracle, so all three collapse into one answer.
	 */
	private const CREDENTIAL_FAILURE_CODES = [
		'invalid_username',
		'invalid_email',
		'incorrect_password',
	];

	/**
	 * The single code every credential failure is reported under.
	 *
	 * It has to be one of core's own, because wp-login.php branches on the code
	 * twice more after the message is set — see filter_wp_login_errors().
	 * `incorrect_password` is the branch that keeps the typed username in the
	 * field, which is the kinder of the two identical outcomes.
	 */
	private const UNIFORM_FAILURE_CODE = 'incorrect_password';

	public static function init(): void {
		add_filter( 'login_redirect', [ __CLASS__, 'filter_login_redirect' ], 10, 3 );
		add_filter( 'wp_login_errors', [ __CLASS__, 'filter_wp_login_errors' ], 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Where a member lands after signing in
	// -------------------------------------------------------------------------

	/**
	 * Send a member to their own dashboard instead of the WordPress profile
	 * screen.
	 *
	 * Core's default for a user without `edit_posts` is `wp-admin/profile.php`,
	 * so on a stock Learnomy install BOTH a student and an instructor finished
	 * signing in on a WordPress admin screen — a surface neither has any
	 * business on, showing none of the courses they came for. Verified live
	 * before this filter existed: both roles 302'd to `/wp-admin/profile.php`.
	 *
	 * Two rules, in this order:
	 *
	 * 1. A destination the member actually asked for wins. Someone who clicked
	 *    "Enrol" on a course and got bounced to the login form is trying to get
	 *    back to that course, not to a dashboard.
	 * 2. Otherwise route by what the member is: instructors to the instructor
	 *    dashboard, everyone else who can learn to the student dashboard.
	 *
	 * Anyone who belongs in wp-admin — an admin, an editor, any user with
	 * `edit_posts` — keeps core's destination untouched. Site owners are not
	 * members and must not be redirected out of their own back end.
	 *
	 * @param string        $redirect_to           Destination core resolved.
	 * @param string        $requested_redirect_to Destination the request asked for.
	 * @param WP_User|mixed $user                  The authenticated user, or a WP_Error.
	 */
	public static function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ): string {
		$redirect_to = (string) $redirect_to;

		if ( ! $user instanceof WP_User ) {
			return $redirect_to;
		}

		$is_admin_worker = $user->has_cap( 'edit_posts' ) || $user->has_cap( 'manage_options' );

		// Honour a destination the member asked for, but validate it here
		// rather than trusting the request. Core does run the final value
		// through wp_safe_redirect(), so an off-site `redirect_to` is already
		// neutralised today (verified: an external host lands on /wp-admin/).
		// Re-validating costs nothing and means this filter can never become
		// the open redirect it is standing next to.
		//
		// One "request" is not a request: core's stock login FORM injects a
		// hidden redirect_to=admin_url() on every plain /wp-login.php visit,
		// so a member signing in normally arrives here with an admin-rooted
		// "requested" destination they never chose. Honouring it re-created
		// the exact bug this filter exists to fix — the student landed on
		// /wp-admin/profile.php (or, with WooCommerce active, got bounced to
		// Woo's /my-account/ instead of the LMS account). A member has no use
		// for ANY wp-admin URL, so for them an admin-rooted target is core's
		// default, not intent, and falls through to member_home()
		// (Basecamp 10134139585, second bounce). Front-end deep links — the
		// "clicked Enrol, got sent to login" case — still win.
		$requested = (string) $requested_redirect_to;
		if ( '' !== $requested ) {
			$safe = wp_validate_redirect( $requested, '' );
			if ( '' !== $safe ) {
				$admin_path     = (string) wp_parse_url( admin_url(), PHP_URL_PATH );
				$safe_path      = (string) wp_parse_url( $safe, PHP_URL_PATH );
				$is_admin_target = '' !== $admin_path && 0 === strpos( $safe_path, $admin_path );

				if ( $is_admin_worker || ! $is_admin_target ) {
					return $safe;
				}
			}
		}

		// Users who work in wp-admin keep core's behaviour.
		if ( $is_admin_worker ) {
			return $redirect_to;
		}

		$destination = self::member_home( $user );

		/**
		 * Filter where a member lands after signing in.
		 *
		 * Return '' to fall back to whatever core resolved.
		 *
		 * @param string  $destination Resolved member home.
		 * @param WP_User $user        The member who just signed in.
		 * @param string  $redirect_to Destination core resolved.
		 */
		$destination = (string) apply_filters( 'learnomy_login_redirect', $destination, $user, $redirect_to );

		if ( '' === $destination ) {
			return $redirect_to;
		}

		return wp_validate_redirect( $destination, $redirect_to );
	}

	/**
	 * The route a member calls home. Instructors teach, everyone else learns.
	 */
	private static function member_home( WP_User $user ): string {
		$is_instructor = in_array( 'lrn_instructor', (array) $user->roles, true )
			|| $user->has_cap( 'learnomy_create_course' );

		if ( $is_instructor ) {
			return (string) \Learnomy\route_url( 'instructor-dashboard' );
		}

		if ( $user->has_cap( 'learnomy_learn' ) || $user->has_cap( 'read' ) ) {
			return (string) \Learnomy\route_url( 'student-dashboard' );
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// What a failed sign-in is allowed to reveal
	// -------------------------------------------------------------------------

	/**
	 * Answer every failed sign-in identically.
	 *
	 * Core distinguishes "the username <strong>alice</strong> is not registered
	 * on this site" from "the password you entered for the username
	 * <strong>alice</strong> is incorrect", which turns the login form into a
	 * membership oracle: walk a wordlist, keep every name that comes back as
	 * "wrong password". On a Learnomy site that list IS the student roster, and
	 * it feeds credential stuffing and "your course access has expired"
	 * phishing.
	 *
	 * Learnomy already treats account probing as in scope on the surfaces it
	 * owns — the REST sign-in at `/auth/token` returns one generic message for
	 * both failures, and registration answers a honeypot hit with a normal 201
	 * so the failure shape cannot be probed. Hardening the side doors while the
	 * front door enumerates freely is not a position.
	 *
	 * WHY THIS FILTER AND NOT `login_errors`: the message is only one of three
	 * tells. wp-login.php branches on the error CODE twice more after the
	 * message is rendered — it re-fills the username field only for
	 * `incorrect_password`/`empty_password` (wp-login.php:1500) and blanks the
	 * field via the focus script only for `invalid_username`
	 * (wp-login.php:1602-1613). Both are readable without parsing a single word
	 * of the error. `login_errors` receives HTML that is already past those
	 * branches, so filtering there would look fixed while the oracle stayed
	 * open. `wp_login_errors` runs before all three (wp-login.php:1482) and is
	 * handed the WP_Error itself, so replacing the code normalises the message,
	 * the field value and the focus target in one move.
	 *
	 * @param WP_Error|mixed $errors      Errors core is about to render.
	 * @param string         $redirect_to Redirect destination (unused).
	 * @return WP_Error|mixed
	 */
	public static function filter_wp_login_errors( $errors, $redirect_to ) {
		unset( $redirect_to );

		if ( ! $errors instanceof WP_Error || ! $errors->has_errors() ) {
			return $errors;
		}

		/**
		 * Whether to answer every failed sign-in identically.
		 *
		 * Default true. A site owner who prefers core's more helpful (and more
		 * talkative) wording, or whose security plugin already normalises this,
		 * can return false.
		 *
		 * @param bool $generic Whether to genericize login errors.
		 */
		if ( ! apply_filters( 'learnomy_generic_login_errors', true ) ) {
			return $errors;
		}

		$codes = $errors->get_error_codes();
		if ( ! array_intersect( $codes, self::CREDENTIAL_FAILURE_CODES ) ) {
			return $errors;
		}

		$normalized = new WP_Error();
		$normalized->add( self::UNIFORM_FAILURE_CODE, self::generic_failure_message() );

		// Anything core reported that is NOT an account-existence tell is
		// carried through untouched: an expired password-reset key, a blocked
		// account, a required field left empty. Those are messages the member
		// needs in order to recover, and none of them reveal whether an account
		// exists.
		foreach ( $codes as $code ) {
			if ( in_array( $code, self::CREDENTIAL_FAILURE_CODES, true ) ) {
				continue;
			}
			foreach ( $errors->get_error_messages( $code ) as $message ) {
				$normalized->add( $code, $message, $errors->get_error_data( $code ) );
			}
		}

		return $normalized;
	}

	/**
	 * The one answer. Keeps core's "Lost your password?" link, because losing
	 * the recovery path is a worse outcome for the member who simply mistyped
	 * than the enumeration this closes.
	 */
	private static function generic_failure_message(): string {
		return sprintf(
			'<strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a>',
			esc_html__( 'Error:', 'learnomy' ),
			esc_html__( 'The username or password you entered is incorrect.', 'learnomy' ),
			esc_url( wp_lostpassword_url() ),
			esc_html__( 'Lost your password?', 'learnomy' )
		);
	}
}
