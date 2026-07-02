<?php
/**
 * Plugin Name: BuddyNext Isolation
 * Description: Strips non-essential plugins on BuddyNext front-end routes to save 20-40 MB per request.
 * Version:     1.0.0
 * Author:      Wbcom Designs
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

// No-op on admin pages and WP-CLI runs — isolation is for front-end only.
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

/**
 * Determine whether the current HTTP request targets a BuddyNext front-end route.
 *
 * Queries wp_options directly via $wpdb — WordPress option API is not yet
 * available this early in the bootstrap sequence.
 *
 * Uses a static guard so the DB query runs at most once per request.
 *
 * @return bool
 */
function buddynext_mu_is_bn_request() {
	static $result = null;

	if ( null !== $result ) {
		return $result;
	}

	// Parse the bare path from REQUEST_URI and strip the leading slash.
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw comparison only, never output.
	$path        = ltrim( strtok( $request_uri, '?' ), '/' );

	if ( '' === $path ) {
		$result = false;
		return false;
	}

	global $wpdb;

	// Fetch all six slug options in a single query.
	$option_names = array(
		'buddynext_slug_activity',
		'buddynext_slug_people',
		'buddynext_slug_spaces',
		'buddynext_slug_messages',
		'buddynext_slug_notifications',
		'buddynext_slug_auth',
	);

	$placeholders = implode( ', ', array_fill( 0, count( $option_names ), "'%s'" ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ( {$placeholders} )",
			$option_names
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

	// Build a map of option_name => value, then merge with hard-coded defaults.
	$fetched = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$fetched[ $row['option_name'] ] = $row['option_value'];
		}
	}

	$defaults = array(
		'buddynext_slug_activity'      => 'activity',
		'buddynext_slug_people'        => 'members',
		'buddynext_slug_spaces'        => 'spaces',
		'buddynext_slug_messages'      => 'messages',
		'buddynext_slug_notifications' => 'notifications',
		'buddynext_slug_auth'          => 'login',
	);

	$slugs = array_merge( $defaults, $fetched );

	foreach ( $slugs as $slug ) {
		// Match the first path segment exactly — not a bare prefix — so a page
		// like /membership/ does not get isolated by the 'members' slug.
		if ( '' !== $slug && ( $path === $slug || 0 === strpos( $path, $slug . '/' ) ) ) {
			$result = true;
			return true;
		}
	}

	$result = false;
	return false;
}

if ( buddynext_mu_is_bn_request() ) {
	/**
	 * Strip all non-whitelisted plugins before WordPress loads them.
	 *
	 * The whitelist is filterable so site owners can add cache or security
	 * plugins that must remain active on every request.
	 *
	 * @param string[]|mixed $plugins List of active plugin paths from wp_options.
	 * @return string[] Filtered list.
	 */
	add_filter(
		'option_active_plugins',
		static function ( $plugins ) {
			if ( ! is_array( $plugins ) ) {
				return $plugins;
			}

			// Self-guard: if BuddyNext itself is not active, do nothing. A
			// mu-plugin left behind after BuddyNext is deactivated must never
			// strip plugins on /members/, /spaces/ etc. (it matches those paths
			// by slug and cannot tell BuddyNext is gone) — that would break the
			// site. With BuddyNext inactive the mu-plugin is inert.
			if ( ! in_array( 'buddynext/buddynext.php', $plugins, true ) ) {
				return $plugins;
			}

			// Essentials that must ALWAYS survive — BuddyNext + Pro, operational
			// plugins, AND the full in-house integration family. The family is
			// hard-coded here (not left to the option below) so Portfolio tabs / nav
			// appear even on the very first request, or when a tester's mu-plugin
			// file is stale, or before PluginIsolation has populated the option.
			// Keep in sync with PluginIsolation::CORE_INTEGRATIONS + the Pro
			// buddynext_isolation_plugins filter.
			$essentials = array(
				'buddynext/buddynext.php',
				'buddynext-pro/buddynext-pro.php',
				// Core integrations (Free first-class).
				'wpmediaverse/wpmediaverse.php',
				'wpmediaverse-pro/wpmediaverse-pro.php',
				'jetonomy/jetonomy.php',
				'jetonomy-pro/jetonomy-pro.php',
				'wb-gamification/wb-gamification.php',
				// Pro application-layer integrations (Portfolio panels).
				'wp-career-board/wp-career-board.php',
				'wb-listora/wb-listora.php',
				'learnomy/learnomy.php',
				'learnomy-pro/learnomy-pro.php',
				// Operational.
				'redis-cache/redis-cache.php',
				'query-monitor/query-monitor.php',
			);

			// Plus any dynamic / 3rd-party additions BuddyNext mirrors into the
			// `buddynext_isolation_plugins` option (raw SQL: the WP option API is not
			// available this early). The hard-coded family above is the floor; this
			// merge only adds extras a filter contributed at runtime.
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$json         = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'buddynext_isolation_plugins' LIMIT 1" );
			$integrations = is_string( $json ) ? json_decode( $json, true ) : null;
			if ( ! is_array( $integrations ) ) {
				$integrations = array();
			}

			$whitelist = apply_filters(
				'buddynext_isolation_whitelist',
				array_values( array_unique( array_merge( $essentials, $integrations ) ) )
			);

			return array_values( array_intersect( $plugins, $whitelist ) );
		}
	);
}