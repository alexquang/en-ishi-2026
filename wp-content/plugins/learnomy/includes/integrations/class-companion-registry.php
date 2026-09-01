<?php
/**
 * Academy Stack companion registry.
 *
 * A single declarative, filterable catalog of the Wbcom plugins Learnomy
 * integrates with (MediaShield, Jetonomy, WB Gamification, …). Each entry is
 * DATA, not code — Pro and third parties extend the list via the
 * `learnomy_companions` filter. Every UI + integration decision keys off
 * `status()` / `is_active()` (a runtime capability probe), never a hardcoded
 * plugin path, so "works standalone" and "no duplication" both hold:
 * capability present -> delegate; absent -> hide.
 *
 * Self-contained on purpose: this whole `includes/integrations/` wrapper is
 * designed to copy cleanly into the other Wbcom plugins (they already bundle
 * the identical EDD SL SDK the installer speaks to).
 *
 * @package Learnomy\Integrations
 */

namespace Learnomy\Integrations;

defined( 'ABSPATH' ) || exit;

final class Companion_Registry {

	/**
	 * Resolve the companion catalog. Each entry:
	 *   label      string   Display name.
	 *   why        string   One-line value proposition.
	 *   detect     callable Returns true when the companion's capability is live.
	 *   free       array    { item_id, key, basename } for one-click free install.
	 *   pro        array    { item_id, basename } for the license-gated upgrade (optional).
	 *   store_url  string   Product page for the "Upgrade to Pro" link.
	 *   unlocks    string   What this turns on inside Learnomy when connected.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function all(): array {
		/**
		 * Filter the Academy Stack companion catalog. Pro + third-party plugins
		 * add their own entries here; the installer + admin screen render whatever
		 * this returns.
		 *
		 * @param array<string, array<string, mixed>> $companions Slug => entry.
		 */
		return (array) apply_filters(
			'learnomy_companions',
			array(
				'mediashield'     => array(
					'label'     => __( 'MediaShield', 'learnomy' ),
					'why'       => __( 'Protected, signed video with milestones and ad/sponsor surfaces.', 'learnomy' ),
					'detect'    => static fn() => post_type_exists( 'mediashield_video' ) || shortcode_exists( 'mediashield' ),
					'free'      => array(
						'item_id'  => 1661218,
						// MediaShield's own free key (validated: check -> site_inactive,
						// activate -> valid). NOTE: the store currently returns "No
						// download package available" for this item — a package file
						// must be attached to the MediaShield free product on the EDD
						// store before the one-click download can work (card #9993365409).
						'key'      => 'mediasheild7c2a9e5d1f8b4c6a3e0d9b2f7c1a8e12',
						'basename' => 'mediashield/mediashield.php',
					),
					'pro'       => array(
						'item_id'  => 1661219,
						'basename' => 'mediashield-pro/mediashield-pro.php',
					),
					'store_url' => 'https://wbcomdesigns.com',
					'unlocks'   => __( 'The Protected Video lesson type becomes available.', 'learnomy' ),
				),
				'jetonomy'        => array(
					'label'     => __( 'Jetonomy', 'learnomy' ),
					'why'       => __( 'Discussion spaces, topics, and a leaderboard plus a token economy around your courses.', 'learnomy' ),
					'detect'    => static fn() => function_exists( 'jetonomy' ) || class_exists( '\\Jetonomy\\Jetonomy' ),
					'free'      => array(
						'item_id'  => 1660320,
						// Full free key for Jetonomy (item 1660320). Verified live:
						// activate_license -> valid, package download authorized.
						'key'      => 'wbcomfreec7e2a9b45d8f1c3e6a0b9d2f7c4e8a11',
						'basename' => 'jetonomy/jetonomy.php',
					),
					'store_url' => 'https://wbcomdesigns.com',
					'unlocks'   => __( 'Community discussion + credits around your academy.', 'learnomy' ),
				),
				'wb-gamification' => array(
					'label'     => __( 'WB Gamification', 'learnomy' ),
					'why'       => __( 'Points, badges, and leaderboards for course, lesson, and quiz activity.', 'learnomy' ),
					'detect'    => static fn() => function_exists( 'wb_gam' ) || defined( 'WB_GAM_VERSION' ),
					'free'      => array(
						'item_id'  => 1662147,
						// WB Gamification's own free key (validated: check -> site_inactive,
						// activate -> valid, download OK v1.5.5).
						'key'      => 'wbcomfree6e2a9c1d7b4f3c8a0e5d9b2f1a7c6e11',
						'basename' => 'wb-gamification/wb-gamification.php',
					),
					'store_url' => 'https://wbcomdesigns.com',
					'unlocks'   => __( 'Points, badges, and leaderboards for learner activity.', 'learnomy' ),
				),
			)
		);
	}

	/**
	 * A single companion entry, or null when the slug is unknown.
	 *
	 * @param string $slug Companion slug.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $slug ): ?array {
		$all = self::all();
		return $all[ $slug ] ?? null;
	}

	/**
	 * Lifecycle status of a companion:
	 *   'active'             - its capability probe returns true (installed + on).
	 *   'installed_inactive' - the plugin file is present but not active.
	 *   'not_installed'      - absent.
	 *
	 * @param string $slug Companion slug.
	 */
	public static function status( string $slug ): string {
		$entry = self::get( $slug );
		if ( null === $entry ) {
			return 'not_installed';
		}

		$detect = $entry['detect'] ?? null;
		if ( is_callable( $detect ) && (bool) $detect() ) {
			return 'active';
		}

		// Capability absent — is the free plugin at least on disk (so we offer
		// "Activate" instead of "Install")?
		$basename = (string) ( $entry['free']['basename'] ?? '' );
		if ( '' !== $basename && self::plugin_file_exists( $basename ) ) {
			return 'installed_inactive';
		}

		return 'not_installed';
	}

	/**
	 * Whether a companion's capability is live. The single gate Learnomy
	 * integration code should call before delegating to a companion.
	 *
	 * @param string $slug Companion slug.
	 */
	public static function is_active( string $slug ): bool {
		return 'active' === self::status( $slug );
	}

	/**
	 * Whether a plugin file exists under wp-content/plugins, without loading the
	 * (potentially expensive) full plugin list on every call when WP's helper
	 * isn't available yet.
	 *
	 * @param string $basename e.g. "mediashield/mediashield.php".
	 */
	private static function plugin_file_exists( string $basename ): bool {
		$path = trailingslashit( WP_PLUGIN_DIR ) . ltrim( $basename, '/' );
		return file_exists( $path );
	}
}
