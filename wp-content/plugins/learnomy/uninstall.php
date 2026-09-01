<?php
/**
 * Plugin uninstall handler.
 *
 * Runs when the plugin is deleted from the Plugins screen.
 * Checks the "Delete data on uninstall" setting before touching anything.
 *
 * This file must NOT use any plugin constants — they are not defined
 * when WordPress calls uninstall.php directly.
 *
 * @package Learnomy
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'learnomy_settings', array() );

// Two ways to opt into a destructive uninstall, kept in sync so the admin
// UI checkbox (Wave 2E, row 2E-2) and any legacy `uninstall_data` enum from
// pre-2026-05-16 installs both work:
//
// • Wave 2E canonical: learnomy_settings['delete_data_on_uninstall'] === 1
// • Legacy enum:       learnomy_settings['uninstall_data'] === 'delete_everything'
//
// Default behavior (neither set, or set falsy) is "keep all data" — this is
// the only safe default for a destructive operation users can't undo.
$delete_flag = ! empty( $settings['delete_data_on_uninstall'] );
$legacy_flag = 'delete_everything' === ( $settings['uninstall_data'] ?? 'keep_data' );

if ( ! $delete_flag && ! $legacy_flag ) {
	return;
}

global $wpdb;

// ── 1. Drop all Free lrn_* tables ───────────────────────────────────────────
//
// Derived from the LIVE database, not a hardcoded list, so a table added later
// can never be missed — a hardcoded list drifted once and left `lrn_blocked_users`
// (the App-Store block-user table) on disk after a "delete all data" uninstall.
// uninstall.php runs without the plugin bootstrap, so we can't ask Schema for the
// list; enumerating the DB is the dependency-free source of truth.
//
// EXCLUDE Pro's `lrn_pro_*` tables — Free must never drop what Pro owns (Pro's own
// uninstall handles those). The exclusion is an EXACT string-prefix check, NOT a
// `LIKE 'lrn_pro_%'`: in a LIKE pattern `_` is a single-char wildcard, so
// `lrn_pro_%` also matches `lrn_progress` and would wipe every student's progress.
// Learnomy tables use no foreign keys, so drop order is irrelevant.

$free_prefix = $wpdb->prefix . 'lrn_';
$pro_prefix  = $wpdb->prefix . 'lrn_pro_';

foreach ( (array) $wpdb->get_col( 'SHOW TABLES' ) as $table ) {
	if ( 0 !== strpos( $table, $free_prefix ) ) {
		continue; // Not a Learnomy table.
	}
	if ( 0 === strpos( $table, $pro_prefix ) ) {
		continue; // Pro-owned — leave it for Pro's uninstall.
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

// ── 2. Delete all learnomy_* options ─────────────────────────────────────────
//
// EXCLUDE `learnomy_pro_%`. It is a prefix-subset of `learnomy_%`, so an
// unqualified sweep deletes Pro's options too — including `learnomy_pro_settings`,
// the option Pro's OWN uninstall reads to decide whether the owner consented to
// data deletion. Uninstalling Free first (the natural order — it is the parent)
// would wipe that flag, Pro would then read a missing option, conclude "no", and
// leave all 42 Pro tables (PII included) on disk. The owner asked for erasure and
// silently would not get it. Pro owns its own teardown; Free must not reach into it.
$lrn_pro_option_like = $wpdb->esc_like( 'learnomy_pro_' ) . '%';

$option_rows = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
		$wpdb->esc_like( 'learnomy_' ) . '%',
		$lrn_pro_option_like
	)
);

foreach ( $option_rows as $option_name ) {
	delete_option( $option_name );
}

// Clean site options in multisite — same Pro exclusion.
if ( is_multisite() ) {
	$site_option_rows = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s AND meta_key NOT LIKE %s",
			$wpdb->esc_like( 'learnomy_' ) . '%',
			$lrn_pro_option_like
		)
	);
	foreach ( $site_option_rows as $key ) {
		delete_site_option( $key );
	}
}

// ── 3. Remove lrn_student and lrn_instructor roles ───────────────────────────

remove_role( 'lrn_student' );
remove_role( 'lrn_instructor' );

// ── 4. Delete Free's own user meta for all users ─────────────────────────────

// Usermeta is ALLOWLISTED, not blocklisted — the reverse of the option sweep
// above, and deliberately so. Options are cleanly namespaced (`learnomy_pro_*`
// for Pro), so excluding that prefix is safe. Usermeta is NOT: Pro writes
// several keys WITHOUT the pro prefix — `learnomy_stripe_account_id` (Stripe
// Connect), `learnomy_lti_*` (LTI identity), `learnomy_notes` /
// `learnomy_bookmarks` — all of which match `learnomy_%` and would be wiped by a
// blocklist sweep. A Free uninstall must never destroy that: it is a live payout
// account link, an external-identity binding, a member's saved work. And this
// file runs even when Pro is DEACTIVATED, so we cannot ask Pro what to keep.
//
// So Free deletes only the keys it owns, by name. THIS IS THE CANONICAL LIST OF
// FREE USER META KEYS — add here (and only here) whenever you add a Free user
// meta key, so uninstall stays complete without ever reaching into Pro's data.
$lrn_free_usermeta_keys = array(
	'learnomy_dark_mode',
	'learnomy_expertise',
	'learnomy_last_active',
	'learnomy_payout_email',
	'learnomy_pending_email',
	'learnomy_pending_email_key',
	'learnomy_pro_modules_notice_dismissed',
	'learnomy_require_course_approval',
	'learnomy_wishlist',
	'learnomy_cli_fixture',
	'_learnomy_notification_prefs',
);

$lrn_meta_placeholders = implode( ', ', array_fill( 0, count( $lrn_free_usermeta_keys ), '%s' ) );

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query(
	$wpdb->prepare(
		// Exact keys plus the one dynamic family (`learnomy_quiz_<id>_*` per-user
		// quiz state). Every pattern here is Free-owned.
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ( {$lrn_meta_placeholders} ) OR meta_key LIKE %s",
		...array_merge( $lrn_free_usermeta_keys, array( $wpdb->esc_like( 'learnomy_quiz_' ) . '%' ) )
	)
);

// ── 5. Unschedule all learnomy_* cron events ─────────────────────────────────

$crons = _get_cron_array();

if ( is_array( $crons ) ) {
	foreach ( $crons as $timestamp => $cron_hooks ) {
		foreach ( $cron_hooks as $hook => $events ) {
			if ( str_starts_with( $hook, 'learnomy_' ) ) {
				foreach ( $events as $event ) {
					wp_unschedule_event( $timestamp, $hook, $event['args'] );
				}
			}
		}
	}
}

// ── 5b. Cancel Action Scheduler actions (the daily/hourly jobs run on AS, not
// WP-cron, so the loop above does not reach them). Without this the recurring
// actions in the `learnomy` group survive uninstall and fatal on every tick
// once the plugin classes are gone. ────────────────────────────────────────
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'learnomy' );
}

// ── 6. Delete lrn_* transients ───────────────────────────────────────────────

$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lrn_%' OR option_name LIKE '_transient_timeout_lrn_%'"
);

if ( is_multisite() ) {
	$wpdb->query(
		"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE '_site_transient_lrn_%' OR meta_key LIKE '_site_transient_timeout_lrn_%'"
	);
}
