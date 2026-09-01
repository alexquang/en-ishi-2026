<?php
/**
 * Migration 3.22.0 — member block list (App Store 1.2 UGC safety).
 *
 * Adds ONE table, `lrn_blocked_users`, recording that one member has blocked
 * another. This is BUSINESS STATE, not a historical signal (rule 4a): it is read
 * on every lesson-comment and course-review list to hide a blocked author's
 * content from the blocker. `lrn_audit_log` (append-only, report-oriented) can't
 * answer "is B blocked by A?" on a hot read path, and no existing table hosts a
 * user↔user relationship — so the table earns its keep.
 *
 * Why now: the mobile app ships lesson comments + course reviews (user-generated
 * content). Apple Guideline 1.2 requires BOTH report (already: POST /reports) and
 * a way to BLOCK an abusive user. Free owns comments + reviews, so block lives in
 * Free (Pro never owns a Free-visible surface). The `blocking` app-config flag
 * flips true once this table exists.
 *
 * Idempotent + forward-only: CREATE TABLE IF NOT EXISTS, guarded by table_exists,
 * so a fresh install that already got the table from Schema::create_tables() is a
 * no-op here.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_22_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_blocked_users';

		if ( $this->table_exists( $table ) ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				blocker_id BIGINT UNSIGNED NOT NULL,
				blocked_id BIGINT UNSIGNED NOT NULL,
				created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY ux_pair (blocker_id, blocked_id),
				KEY idx_blocker (blocker_id)
			) ENGINE=InnoDB {$charset_collate}"
		);
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);
	}
}
