<?php
/**
 * Migration 3.4.0 — persist video watch percentage on lesson progress.
 *
 * `completion_mode = auto_video` (and combined) gates a lesson on watching N%
 * of its video, but `lrn_progress` had nowhere to store the watched percentage,
 * so the authoritative `/complete` evaluation always saw 0% and the rule could
 * never be satisfied. Adds `video_watch_pct` so the heartbeat can persist the
 * high-water mark and the completion check can read it.
 *
 * Forward-only and idempotent.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_4_0 {

	public function up(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'lrn_progress';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'video_watch_pct' ) );
		if ( $has_column ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN video_watch_pct tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER time_spent_seconds" );
	}
}
