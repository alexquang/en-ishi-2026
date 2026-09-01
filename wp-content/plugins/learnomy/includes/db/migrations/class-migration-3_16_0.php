<?php
/**
 * Migration 3.16.0 — add lrn_notifications.email_error (Basecamp #10062949997).
 *
 * The Email Log could show a failed row's status but not WHY it failed, because
 * no error was ever stored. This adds a nullable email_error column; the notifier
 * now captures the wp_mail_failed message on a failed send (and on a manual
 * Resend) and persists it here, so the admin Email Log surfaces the real reason
 * per row instead of "reason not recorded".
 *
 * Forward-only + idempotent: only adds the column when it's missing, so a re-run
 * or a fresh install (where Schema already created it) is a harmless no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_16_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_notifications';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$has_column = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'email_error'",
				$table
			)
		);

		if ( (int) $has_column > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `email_error` text DEFAULT NULL AFTER `email_status`" );
	}
}
