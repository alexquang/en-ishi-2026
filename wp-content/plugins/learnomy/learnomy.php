<?php
/**
 * Plugin Name: Learnomy - Simple LMS
 * Plugin URI:  https://learnomy.com
 * Description: The simplest LMS for WordPress: courses, quizzes, certificates, memberships. App-ready API.
 * Version:     1.9.3
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Author:      Wbcom Designs
 * Author URI:  https://wbcomdesigns.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: learnomy
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'LEARNOMY_VERSION', '1.9.3' );
define( 'LEARNOMY_DB_VERSION', '3.48.0' );
define( 'LEARNOMY_FILE', __FILE__ );
define( 'LEARNOMY_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEARNOMY_URL', plugin_dir_url( __FILE__ ) );

// Restore the modern PHP 7.1+ float precision when a host still ships the
// legacy serialize_precision = 17. At 17, json_encode()/serialize() emit
// binary-float artifacts (e.g. a 99.99 price becomes 99.989999999999995) that
// leak into REST responses, course settings_json, and transaction metadata;
// -1 uses the shortest round-trippable representation (the current PHP
// default). One global, set before any encoding runs, only when a host
// overrides it. Note: round()/number_format-to-float do NOT help — the float
// stays binary-imprecise; only -1 (or string formatting) fixes the output.
// (Basecamp Card I — incorrect price display from float precision.)
if ( '-1' !== ini_get( 'serialize_precision' ) ) {
	ini_set( 'serialize_precision', '-1' ); // phpcs:ignore WordPress.PHP.IniSet.serialize_precision_Blacklisted,WordPress.PHP.IniSet.Risky
}

require_once LEARNOMY_DIR . 'includes/class-autoloader.php';
Learnomy\Autoloader::register();

// Global helper functions (Learnomy\table(), route_url(), …) — required HERE at
// plugin-file-load time, NOT deferred to init()/load_dependencies() on
// plugins_loaded. Models call Learnomy\table(); if another plugin (e.g.
// learnomy-pro) is activated in the SAME request and its activation hook touches
// a Free Model before Free's plugins_loaded init has run, table() would be
// undefined and fatal the activation. functions.php is pure definitions (only
// add_filter() registrations run at include time), so loading it this early is
// side-effect-free and makes the helpers available whenever this file is loaded.
require_once LEARNOMY_DIR . 'includes/functions.php';

// Action Scheduler — the async/cron backbone (recurring renewals, reminders,
// membership dunning, webhook delivery, large announcement broadcasts). Loaded
// HERE at plugin-file-load time, NOT inside a plugins_loaded callback: AS
// registers its version on plugins_loaded@0 and initializes the latest on @1,
// so a require after that (e.g. our init() at @10) would miss both hooks and
// never define the as_*() functions. Bundled UNSCOPED so its global as_*() API
// and cross-plugin version negotiation work — if WooCommerce (or any other
// plugin) ships a newer copy, ActionScheduler_Versions picks the highest one.
// Without this, a standalone Learnomy (no WooCommerce) had no scheduler and
// every recurring/async job silently no-op'd.
$lrn_action_scheduler = LEARNOMY_DIR . 'libs/action-scheduler/action-scheduler.php';
if ( file_exists( $lrn_action_scheduler ) ) {
	require_once $lrn_action_scheduler;
}
unset( $lrn_action_scheduler );

require_once LEARNOMY_DIR . 'includes/class-learnomy.php';

function learnomy(): Learnomy\Learnomy {
	return Learnomy\Learnomy::instance();
}

learnomy();

// EDD Software Licensing — free plugin gets auto-updates with a preset key.
add_action(
	'edd_sl_sdk_registry',
	function ( $registry ) {
		// EDD product ID on wbcomdesigns.com. The guard stays as defense: the
		// shared edd-sl-sdk Registry throws on an empty item_id (which would
		// fatal once any plugin loads the SDK), so a 0/filtered-out id skips
		// registration instead of crashing.
		$item_id = (int) apply_filters( 'learnomy_edd_item_id', 1662698 );
		if ( $item_id <= 0 ) {
			return;
		}
		$registry->register(
			array(
				'id'      => 'learnomy',
				'url'     => 'https://wbcomdesigns.com',
				'item_id' => $item_id,
				'version' => LEARNOMY_VERSION,
				'file'    => LEARNOMY_FILE,
				'license' => '7bd8ab074c1c90ef9a02e95b48c553e7',
			)
		);
	}
);

// EDD Software Licensing SDK — bundled (no composer / nothing to install).
// Self-contained: ships its own autoloader and version-negotiates a singleton
// across every Wbcom plugin that bundles a copy, so the highest version loads
// once and fires `edd_sl_sdk_registry` (Pro registers its item on that hook).
//
// Guarded on the CLASS FILE the entry point needs, not on the entry point.
// edd-sl-sdk.php calls EasyDigitalDownloads\Updater\Versions::instance() at load
// with no class_exists() guard of its own, so an SDK whose src/ went missing —
// a truncated upload, a scanner quarantining a file, or the unanchored exclude
// glob that stripped src/ from WB Listora 1.2.1's zip — took the whole site to a
// fatal. Measured: renaming libs/edd-sl-sdk/src turned every page 500. Licensing
// and updates are worth degrading; the site staying up is not negotiable.
if ( file_exists( LEARNOMY_DIR . 'libs/edd-sl-sdk/src/Versions.php' ) ) {
	require_once LEARNOMY_DIR . 'libs/edd-sl-sdk/edd-sl-sdk.php';
} elseif ( file_exists( LEARNOMY_DIR . 'libs/edd-sl-sdk/edd-sl-sdk.php' ) ) {
	// Entry file present, classes missing: a damaged install. Say so once, to the
	// people who can fix it, rather than silently dropping licensing and updates.
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__(
					'Learnomy: the bundled licensing SDK is incomplete, so licence checks and automatic updates are unavailable. Reinstalling the plugin restores them.',
					'learnomy'
				)
			);
		}
	);
}
