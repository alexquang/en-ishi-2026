<?php
/**
 * App-connect wiring — which door the Learnomy mobile app walks through to
 * acquire its credential, per the Wbcom App Auth standard.
 *
 * The credential is always a WordPress core Application Password. Learnomy
 * already ships two doors of its own on this branch: core's authorize screen
 * (kept reachable by `App_Authorize_Access`) and the credentials exchange
 * (`App_Credentials`). This class answers the remaining, SITE-level question:
 *
 *   WHICH DOOR OWNS THIS SITE? One connect bridge per site. Many customer
 *   sites run BuddyNext alongside Learnomy, and BuddyNext owns site auth
 *   there — its connect bridge carries the site's social providers and
 *   two-factor. So when BuddyNext is active, `bridge_info()` points the app
 *   at BN's bridge and Learnomy registers its deep-link scheme into BN's
 *   allowlist, so a Learnomy-app connection through BN's door may deliver
 *   the credential to `learnomyapp://`. Standalone, Learnomy deliberately
 *   has NO bridge of its own: core's repaired authorize screen runs the
 *   site's real login (including any second factor), which is the very thing
 *   a bridge exists to provide. The app never contains any of this logic —
 *   it reads the `auth` block on `/app/config` and goes where it is pointed.
 *
 *   WHICH SCHEMES? The custom URL schemes this site may hand a credential
 *   to. Learnomy's own comes from `App_Authorize_Access::app_scheme()` (one
 *   white-label seam, not a second constant); sibling Wbcom apps join
 *   through the `learnomy_app_connect_schemes` filter, mirroring the seam
 *   BuddyNext exposes for us.
 *
 * @package Learnomy
 */

namespace Learnomy\Auth;

defined( 'ABSPATH' ) || exit;

class App_Connect {

	/**
	 * Hook the cross-plugin seam. Registered unconditionally at boot — the
	 * BuddyNext filter is harmless when BN is absent, and unconditional
	 * registration is what makes plugin activation ORDER irrelevant.
	 */
	public static function init(): void {
		add_filter( 'buddynext_app_connect_schemes', array( self::class, 'join_buddynext_allowlist' ) );

		// Reconnect REPLACES, on every door. The credentials exchange prunes
		// before it mints, but core's authorize screen — the standalone
		// interactive door — stacks a new row per approval (core's
		// create_new_application_password does not dedupe, verified live:
		// same app_id, two rows). Members accumulate credentials they will
		// never know to revoke. Core fires this action after every mint, so
		// one listener here holds the invariant for all three doors at once.
		add_action( 'wp_create_application_password', array( self::class, 'forget_older_installs' ), 10, 2 );
	}

	/**
	 * After a mint, drop OLDER credential rows carrying the same app_id.
	 *
	 * Keyed on `app_id` so this only ever forgets the same install
	 * reconnecting, never another device. Rows minted without an app_id
	 * (a member creating one by hand in their profile) are never touched.
	 *
	 * @param int   $user_id  Member the credential was minted for.
	 * @param array $new_item The row core just created (uuid, app_id, ...).
	 */
	public static function forget_older_installs( $user_id, $new_item ): void {
		$app_id   = isset( $new_item['app_id'] ) ? (string) $new_item['app_id'] : '';
		$new_uuid = isset( $new_item['uuid'] ) ? (string) $new_item['uuid'] : '';

		if ( '' === $app_id || '' === $new_uuid ) {
			return;
		}

		foreach ( \WP_Application_Passwords::get_user_application_passwords( (int) $user_id ) as $existing ) {
			if ( isset( $existing['app_id'], $existing['uuid'] )
				&& $app_id === $existing['app_id']
				&& $new_uuid !== $existing['uuid'] ) {
				\WP_Application_Passwords::delete_application_password( (int) $user_id, $existing['uuid'] );
			}
		}
	}

	/**
	 * Register the Learnomy app's scheme with BuddyNext's connect bridge.
	 *
	 * @param mixed $schemes Schemes BN will hand credentials to — a filter
	 *                       value, so guarded rather than trusted.
	 * @return array
	 */
	public static function join_buddynext_allowlist( $schemes ): array {
		$schemes   = is_array( $schemes ) ? $schemes : array();
		$schemes[] = App_Authorize_Access::app_scheme();

		return array_values( array_unique( array_filter( $schemes ) ) );
	}

	/**
	 * The schemes THIS site's Learnomy flows may hand a credential to.
	 *
	 * @return string[]
	 */
	public static function schemes(): array {
		/**
		 * Filter the custom URL schemes the Learnomy app-connect flow may
		 * deliver a credential to. Every scheme here can RECEIVE an
		 * Application Password — add a scheme only for an app you ship,
		 * never a wildcard.
		 *
		 * @since 1.8.1
		 *
		 * @param string[] $schemes Allowed schemes.
		 */
		$schemes = (array) apply_filters(
			'learnomy_app_connect_schemes',
			array( App_Authorize_Access::app_scheme() )
		);

		return array_values( array_filter( array_map( 'strval', $schemes ) ) );
	}

	/**
	 * Which bridge serves this site, per the one-door rule.
	 *
	 * @return array{owner: string, connect_url: string, connect_schemes: string[]}
	 */
	public static function bridge_info(): array {
		if ( class_exists( '\BuddyNext\App\AppConnectService' ) ) {
			$info = array(
				'owner'           => 'buddynext',
				'connect_url'     => (string) \BuddyNext\App\AppConnectService::connect_url(),
				'connect_schemes' => (array) \BuddyNext\App\AppConnectService::schemes(),
			);
		} else {
			// No Learnomy bridge, by decision: standalone sites use core's
			// authorize screen (repaired by App_Authorize_Access) or the
			// credentials exchange. An empty connect_url is how the app
			// learns to route through those instead.
			$info = array(
				'owner'           => 'learnomy',
				'connect_url'     => '',
				'connect_schemes' => self::schemes(),
			);
		}

		/**
		 * Filter the resolved app-connect bridge for this site.
		 *
		 * Tests use it to simulate the BuddyNext-active path; a site with an
		 * unusual auth topology can point the app at its own door.
		 *
		 * @since 1.8.1
		 *
		 * @param array $info { owner, connect_url, connect_schemes }.
		 */
		return (array) apply_filters( 'learnomy_app_connect_bridge', $info );
	}

	/**
	 * The `auth` block published on `GET /app/config` — the standard shape
	 * every Wbcom product advertises, so ONE app-side reader serves the
	 * whole fleet.
	 *
	 * `social_providers` is always empty: Learnomy has no social login
	 * system of its own, and on combined sites the bridge (BuddyNext's)
	 * carries the site's providers behind its own login page. `twofactor`
	 * is false because Learnomy's credential routes cannot complete an
	 * inline second factor — a 2FA site answers 409 on the exchange and the
	 * app falls back to the interactive flow.
	 *
	 * @return array
	 */
	public static function auth_block(): array {
		$bridge = self::bridge_info();

		return array(
			'social_providers'        => array(),
			'twofactor'               => false,
			// Student registration (`POST /auth/register`) is unconditionally
			// open; only the instructor ROLE is separately gated.
			'register'                => true,
			'app_passwords_available' => wp_is_application_passwords_available(),
			'connect_url'             => (string) ( $bridge['connect_url'] ?? '' ),
			'connect_schemes'         => array_values( (array) ( $bridge['connect_schemes'] ?? array() ) ),
		);
	}
}
