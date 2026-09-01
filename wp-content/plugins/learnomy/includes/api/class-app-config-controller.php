<?php
/**
 * App-config controller — the mobile app's bootstrap endpoint.
 *
 * `GET /app/config` is the ONE unauthenticated call the mobile app makes at
 * connect/verify time, before any credential exists: feature flags (which
 * screens to mount), branding (the site's white-label identity), legal URLs,
 * limits, and the `app_enabled` gate. Everything the core `/wp-json/` index
 * already expresses (site name, description, icon, auth support) is
 * deliberately NOT restated here.
 *
 * The app is a licensed Pro benefit: `app_enabled` defaults to FALSE and only
 * the Pro plugin's `learnomy_app_enabled` filter (license-checked) can raise
 * it — a Free-only or lapsed-license site keeps the app blocked at verify,
 * fail-closed, while every WEB feature stays license-independent.
 *
 * Backward compatibility is part of the contract: keys are never removed,
 * renamed, or retyped — `?contract_version=1` returns exactly this key set
 * forever; new keys are additive.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use Learnomy\Modules\Module_Kernel;

class App_Config_Controller extends Base_Controller {

	/**
	 * The contract version this controller implements.
	 */
	private const CONTRACT_VERSION = 1;

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/app/config',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_config' ],
					// Public door by design: this is the app's pre-login
					// bootstrap. It exposes only owner-published branding,
					// boolean feature flags, and public legal URLs — no
					// member data, no secrets (Rule 2 allowlisted, budget 1).
					'permission_callback' => '__return_true',
					'args'                => [
						'contract_version' => [
							'type'    => 'integer',
							'default' => self::CONTRACT_VERSION,
						],
					],
				],
			]
		);
	}

	/**
	 * Build the bootstrap payload.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_config( WP_REST_Request $request ): WP_REST_Response {
		$settings = get_option( 'learnomy_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];

		$config = [
			'contract_version' => self::CONTRACT_VERSION,

			/**
			 * Filter the minimum app version this site supports. The app
			 * force-upgrades below this floor.
			 *
			 * @param string $version Semver floor, e.g. '1.0.0'.
			 */
			'min_app_version'  => (string) apply_filters( 'learnomy_app_min_version', '1.0.0' ),

			'app_enabled'      => $this->app_enabled( $settings ),
			'branding'         => $this->branding(),
			'features'         => $this->features( $settings ),
			'legal'            => $this->legal( $settings ),

			// How this SITE signs a member into the app (Wbcom App Auth
			// standard shape — one reader in the app serves every Wbcom
			// product). On combined sites `connect_url` is BuddyNext's
			// bridge; standalone it is empty and the app routes through
			// core's authorize screen or the credentials exchange
			// (`features.password_login` remains the switch the shipped
			// app reads for the latter).
			'auth'             => \Learnomy\Auth\App_Connect::auth_block(),
			'limits'           => $this->limits(),
			'checkout'         => [
				'web_checkout_base' => \Learnomy\route_url( 'catalog' ),
				'currency'          => strtoupper( (string) ( $settings['currency'] ?? 'USD' ) ),
			],

			// The lesson content types THIS site can serve, each described with a
			// label and a semantic icon hint. It belongs in the bootstrap rather
			// than on every lesson row: it is a property of the site's installed
			// modules, not of a lesson, and it changes only when a module is
			// activated.
			//
			// A client that reads this renders a type it was never compiled with —
			// which is the point, because the app is one binary across every site
			// and Pro can register a new type (SCORM, QTI, LTI, H5P) long after
			// that binary shipped.
			'lesson_types'     => \Learnomy\Models\Lesson::content_type_descriptors(),
		];

		return $this->single_response( $config );
	}

	// -------------------------------------------------------------------------
	// Blocks
	// -------------------------------------------------------------------------

	/**
	 * The app gate: owner toggle AND the Pro-side license filter.
	 *
	 * Default FALSE — only Pro's `learnomy_app_enabled` filter (which checks
	 * `License::is_valid()`) can enable the app. This is the single place the
	 * license gates anything; do not add license checks to feature code.
	 *
	 * @param array $settings The learnomy_settings option.
	 */
	private function app_enabled( array $settings ): bool {
		// Owner toggle, default ON (absent key = on, matching the toggle UI).
		$owner_on = ! isset( $settings['mobile_app_enabled'] ) || ! empty( $settings['mobile_app_enabled'] );

		/**
		 * Filter whether the mobile app may connect to this site.
		 *
		 * Free defaults to false (the app is a Pro benefit). Learnomy Pro
		 * raises this to `License::is_valid()`.
		 *
		 * @param bool $enabled Whether the app is enabled. Default false.
		 */
		$licensed = (bool) apply_filters( 'learnomy_app_enabled', false );

		return $owner_on && $licensed;
	}

	/**
	 * White-label branding block. Free supplies neutral defaults; the Pro
	 * white-label extension overrides via `learnomy_app_config_branding`.
	 */
	private function branding(): array {
		$defaults = [
			// Null app_name = the app falls back to the site name from the
			// core /wp-json/ index (never restated here).
			'app_name'          => null,
			// Learnomy's default accent token (--lrn-accent). The app derives
			// its entire light+dark palette from this one value.
			'accent_color'      => '#2383e2',
			'logo_url'          => $this->site_logo_url(),
			'login_bg_url'      => null,
			'dark_mode_default' => false,
		];

		/**
		 * Filter the app branding block. The Pro white-label extension maps
		 * its plugin_name / admin_logo_url / accent_color settings in here.
		 *
		 * @param array $branding Branding block (see defaults above).
		 */
		$branding = (array) apply_filters( 'learnomy_app_config_branding', $defaults );

		// The contract guarantees the key set — a filter cannot remove keys.
		return array_merge( $defaults, array_intersect_key( $branding, $defaults ) );
	}

	/**
	 * Feature flags. Free contributes module/setting-derived flags; Pro
	 * injects its extension flags via `learnomy_app_config_features`.
	 *
	 * Flags are PER-SITE toggles, not free/pro tiering: `app_enabled` already
	 * guarantees Pro, so a false Pro flag means the owner disabled that
	 * extension — the app hides the matching screens.
	 *
	 * @param array $settings The learnomy_settings option.
	 */
	private function features( array $settings ): array {
		$modules = Module_Kernel::instance();

		// Checkbox settings default ON when the key has never been saved.
		$setting_on = static function ( string $key ) use ( $settings ): bool {
			return ! isset( $settings[ $key ] ) || ! empty( $settings[ $key ] );
		};

		$features = [
			'learnomy'         => true,
			// Diagnostics only — the app never branches on this (it branches
			// on app_enabled + the individual extension flags).
			'pro_active'       => false,

			'memberships'      => $modules->is_enabled( 'membership' ),
			'coupons'          => $modules->is_enabled( 'coupons' ),
			'reviews'          => $modules->is_enabled( 'reviews' ) && $setting_on( 'enable_reviews' ),
			'certificates'     => $modules->is_enabled( 'certificates' ) && $setting_on( 'enable_certificates' ),
			'notifications'    => $modules->is_enabled( 'notifications' ),
			'lesson_comments'  => $setting_on( 'enable_lesson_comments' ),
			'wishlist'         => true,
			'live_sessions'    => true,
			'private_academy'  => \Learnomy\require_login_for_content(),

			// Store-compliance surfaces — the app renders these controls only
			// where the plugin supports them. `blocking` went true in 3.22.0 when
			// the member block list (POST/DELETE /users/{id}/block) shipped, so
			// the app can pair block with report on UGC (Apple 1.2).
			'reporting'        => true,
			'blocking'         => true,
			'account_deletion' => true,

			// May a member sign in by typing their WordPress password, or must
			// they go through core's authorize screen? Owner switch, default
			// on. An owner running 2FA turns it off, because a second factor
			// cannot complete in a single REST call — and the app needs to know
			// that BEFORE it renders the control, so it never offers a path
			// this site will refuse.
			'password_login'   => \Learnomy\Auth\App_Credentials::is_enabled(),
		];

		/**
		 * Filter the app feature flags. Learnomy Pro injects one flag per
		 * extension (assignments, cohorts, learning_paths, notes_bookmarks,
		 * advanced_quizzes, …) and flips pro_active.
		 *
		 * BuddyNext-forward: other Wbcom plugins on the same site can add
		 * their own top-level flags here.
		 *
		 * @param array $features Flag map. Values are coerced to bool.
		 */
		$features = (array) apply_filters( 'learnomy_app_config_features', $features );

		// Strict booleans — a strict client parser must never see 0/""/null.
		return array_map( 'boolval', $features );
	}

	/**
	 * Legal block. Null for anything the owner has not set — the app hides
	 * the row; never a placeholder string.
	 *
	 * @param array $settings The learnomy_settings option.
	 */
	private function legal( array $settings ): array {
		$privacy = get_privacy_policy_url();
		$terms   = (string) ( $settings['terms_url'] ?? '' );

		$legal = [
			'privacy_policy_url'       => '' !== $privacy ? $privacy : null,
			'terms_url'                => '' !== $terms ? $terms : null,
			'community_guidelines_url' => null,
			'abuse_contact_email'      => (string) get_option( 'admin_email' ),
		];

		/**
		 * Filter the app legal block (per-site URLs, owner-configurable).
		 *
		 * @param array $legal Legal block.
		 */
		$filtered = (array) apply_filters( 'learnomy_app_config_legal', $legal );

		return array_merge( $legal, array_intersect_key( $filtered, $legal ) );
	}

	/**
	 * Client-relevant server limits.
	 */
	private function limits(): array {
		return [
			'upload_max_mb'    => (int) floor( wp_max_upload_size() / MB_IN_BYTES ),
			'review_min_chars' => 0,
		];
	}

	/**
	 * The site's own logo (theme custom logo), if set. Falls back to null —
	 * the app then uses the site icon from the core index.
	 */
	private function site_logo_url(): ?string {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id > 0 ) {
			$url = wp_get_attachment_image_url( $logo_id, 'medium' );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return null;
	}
}
