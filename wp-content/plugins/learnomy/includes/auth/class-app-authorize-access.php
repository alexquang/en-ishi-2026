<?php
/**
 * Keep WordPress core's Application Passwords authorize screen reachable for
 * members, so the mobile app can be connected by the people it is built for.
 *
 * THE PROBLEM THIS SOLVES
 *
 * The app signs in with WP core Application Passwords, and the only screens
 * that issue the first one are `wp-admin/authorize-application.php` and
 * `wp-admin/profile.php`. Both live in wp-admin. WooCommerce redirects every
 * user without `edit_posts` / `manage_woocommerce` / `view_admin_dashboard`
 * away from every wp-admin script (`WC_Admin::prevent_admin_access()`), and it
 * exempts only `admin-post.php` and `admin-ajax.php`. Students and subscribers
 * hold none of those caps, so on a WooCommerce site they can neither approve
 * the app nor create a password by hand: they cannot sign in at all, with no
 * workaround available to them.
 *
 * This is not a capability problem. Core allows Application Passwords for
 * subscribers (`create_app_password` maps to `edit_user` on your own account),
 * and a credential minted for a student authenticates correctly. Only the
 * screen that issues it is unreachable.
 *
 * WHY IT IS SAFE TO UNBLOCK THAT ONE SCREEN
 *
 * `authorize-application.php` enforces its own gates: it requires a logged-in
 * user, and it refuses outright unless Application Passwords are available for
 * that user. Letting a member reach it grants no admin capability, exposes no
 * other screen, and shows them nothing but their own approval prompt. Every
 * other wp-admin screen stays blocked -- see the tests: `/wp-admin/` and
 * `/wp-admin/profile.php` still redirect a student away.
 *
 * WHY THE PROTOCOL FILTER IS HERE TOO
 *
 * Core renders the return URL through `esc_url()` (authorize-application.php),
 * which drops any scheme not in the kses allowlist. The app's deep-link scheme
 * is not a core protocol, so without this the hidden `success_url` field
 * renders empty: the member approves and lands on a page showing a raw password
 * instead of being handed back to the app. That failure hits every role,
 * including the instructors and admins who were never blocked by WooCommerce.
 *
 * Both filters are scoped to the authorize request itself. A global protocol
 * allowance would let the app scheme through kses in post and comment content
 * site-wide, which is unnecessary surface for a one-screen need.
 *
 * @package Learnomy
 */

namespace Learnomy\Auth;

defined( 'ABSPATH' ) || exit;

class App_Authorize_Access {

	/**
	 * The core screen that mints a member's first Application Password.
	 */
	private const AUTHORIZE_SCRIPT = 'authorize-application.php';

	/**
	 * Default deep-link scheme the mobile app is registered for.
	 *
	 * A white-labelled build ships its own scheme, which is why this is a
	 * starting value and not a hardcoded constant -- see `app_scheme()`.
	 */
	private const DEFAULT_APP_SCHEME = 'learnomyapp';

	/**
	 * Wire both filters.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_prevent_admin_access', array( self::class, 'allow_authorize_screen' ) );
		add_filter( 'kses_allowed_protocols', array( self::class, 'allow_app_scheme' ) );
	}

	/**
	 * Is the current request the core authorize screen?
	 *
	 * Matched on SCRIPT_FILENAME rather than a query arg or referer, because
	 * that is what actually determines which PHP file is executing and is not
	 * something a visitor can spoof into pointing at a different screen. This
	 * mirrors how WooCommerce itself decides what to exempt.
	 */
	public static function is_authorize_request(): bool {
		if ( ! isset( $_SERVER['SCRIPT_FILENAME'] ) ) {
			return false;
		}

		$script = basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) );

		return self::AUTHORIZE_SCRIPT === $script;
	}

	/**
	 * The app's deep-link scheme.
	 *
	 * Filterable so a white-labelled build, which ships a different scheme, does
	 * not silently lose its return URL to `esc_url()`.
	 *
	 * @return string Lowercase scheme with no separator, e.g. `learnomyapp`.
	 */
	public static function app_scheme(): string {
		/**
		 * Filter the mobile app's deep-link scheme.
		 *
		 * @since 1.8.1
		 *
		 * @param string $scheme Scheme with no `://` separator.
		 */
		$scheme = (string) apply_filters( 'learnomy_app_scheme', self::DEFAULT_APP_SCHEME );

		return strtolower( preg_replace( '/[^a-z0-9+.-]/i', '', $scheme ) );
	}

	/**
	 * Stop WooCommerce redirecting a member away from the authorize screen.
	 *
	 * Only ever turns the block OFF, and only for that one script. It never
	 * turns a block ON, so a site that already allows admin access is unchanged.
	 *
	 * @param bool $prevent Whether WooCommerce intends to block this request.
	 * @return bool
	 */
	public static function allow_authorize_screen( $prevent ) {
		if ( ! $prevent ) {
			return $prevent;
		}

		return self::is_authorize_request() ? false : $prevent;
	}

	/**
	 * Let `esc_url()` keep the app scheme on the authorize screen.
	 *
	 * Scoped to that request: everywhere else the allowlist is untouched, so the
	 * scheme never becomes linkable in post or comment content.
	 *
	 * @param string[] $protocols Allowed protocols.
	 * @return string[]
	 */
	public static function allow_app_scheme( $protocols ) {
		if ( ! is_array( $protocols ) || ! self::is_authorize_request() ) {
			return $protocols;
		}

		$scheme = self::app_scheme();

		if ( '' !== $scheme && ! in_array( $scheme, $protocols, true ) ) {
			$protocols[] = $scheme;
		}

		return $protocols;
	}
}
