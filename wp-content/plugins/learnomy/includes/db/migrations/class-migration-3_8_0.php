<?php
/**
 * Migration 3.8.0 — persist the real email-delivery outcome on each notification.
 *
 * The admin Email Log view (REMEDIATION id: email-log-phantom-status) rendered
 * a hardcoded "Sent" badge for every notification row because no column ever
 * recorded what `wp_mail()` actually returned. Genuine send failures, and rows
 * for which no email was even attempted (per-type toggle off, learner opt-out,
 * missing address), all looked delivered.
 *
 * `Notification_Service::send()` already captured the `wp_mail()` bool but
 * discarded it. This adds `email_status` so that outcome is written at log time
 * and the view can show the truth: sent / failed / skipped.
 *
 *   - sent    — an email was attempted and wp_mail() returned true.
 *   - failed  — an email was attempted and wp_mail() returned false.
 *   - skipped — no email was attempted (type toggle off, learner unsubscribed,
 *               or the recipient has no email address). Default for in-app-only
 *               rows so historic rows are not mislabelled "sent".
 *
 * The default is intentionally `skipped`, not `sent`: a pre-existing row's true
 * email outcome is unknown, and claiming "sent" is exactly the phantom this
 * remediation removes. New rows overwrite the default with the real outcome.
 *
 * The canonical DDL in Schema::create_tables() carries this same column in this
 * release so fresh installs get it; this migration closes the gap for already-
 * installed sites whose table predates the DDL change.
 *
 * Forward-only and idempotent — guarded by a SHOW COLUMNS check, so a re-run
 * (or a fresh install whose DDL already carries the column) is a no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_8_0 {

	public function up(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'lrn_notifications';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'email_status' ) );
		if ( $has_column ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN email_status varchar(20) NOT NULL DEFAULT 'skipped' AFTER is_read" );
	}
}
