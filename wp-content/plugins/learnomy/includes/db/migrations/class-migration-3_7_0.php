<?php
/**
 * Migration 3.7.0 — Index hot-path WHERE/ORDER BY columns.
 *
 * The 2026-06-04 schema audit (REMEDIATION id: missing-index-migration)
 * flagged several hot query columns that drive filesorts / full table
 * scans at scale because no covering index leads with the filtered or
 * sorted column. Each index below was verified against the live schema
 * (column exists, no equivalent index already present) and against a
 * real query in the models before being added:
 *
 *   1. lrn_courses (status, created_at)
 *      Course::list_published() / sitemap / catalog-search all run
 *      `WHERE status = 'published' ORDER BY created_at DESC`. The only
 *      composites that carry `created_at` lead with category_id or
 *      author_id, so a status-only catalog query cannot use them and
 *      filesorts the whole published set.
 *
 *   2. lrn_progress (user_id, updated_at)
 *      Progress::current_streak() (student-dashboard Streak widget) runs
 *      `WHERE user_id = %d AND updated_at >= %s ORDER BY ... DESC`. The
 *      existing user_item index covers the user_id equality but not the
 *      updated_at range/sort, so every matching row is filesorted.
 *
 *   3. lrn_certificates (expires_at)
 *      Certificate::get_newly_expired() (daily expiry cron) runs
 *      `WHERE expires_at IS NOT NULL AND expires_at < NOW() AND ...`.
 *      No index touches expires_at, so the cron full-scans the table.
 *
 *   4. lrn_transactions (refund_of)
 *      Transaction::find_refund_id() runs `WHERE refund_of = %d`. No
 *      index exists on refund_of, so every refund reconciliation scans.
 *
 *   5. lrn_transactions (transaction_type, status)
 *      Transaction::get_completed_for_period() / get_by_type() filter on
 *      `transaction_type = %s [AND status = 'completed']`. The existing
 *      status_created index leads with status, not transaction_type, so a
 *      type-led query cannot seek on it.
 *
 *   6. lrn_notifications (type, created_at)
 *      Notification::get_announcements() / get_past_announcements() run
 *      `WHERE type = 'announcement' ORDER BY created_at DESC`. The
 *      user_type_object index leads with user_id, so a type-only feed
 *      cannot use it and filesorts.
 *
 *   7. lrn_webhooks (status)
 *      Webhook::get_active() runs `WHERE status = 'active' ORDER BY
 *      created_at ASC` on every dispatch. No index on status.
 *
 * Index-only adds — the table set is unchanged. The canonical DDL in
 * Schema::create_tables() carries these same KEYs in this release so
 * fresh installs get them; this migration closes the gap for already-
 * installed sites whose tables predate the DDL change.
 *
 * Idempotent and forward-only — each ADD is guarded by an
 * INFORMATION_SCHEMA check, so a re-run (or a fresh install whose DDL
 * already carries the index) is a no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_7_0 {

	public function up(): void {
		$p = $this->db()->prefix;

		$this->add_index_if_missing( $p . 'lrn_courses', 'status_created', '(status, created_at)' );
		$this->add_index_if_missing( $p . 'lrn_progress', 'user_updated', '(user_id, updated_at)' );
		$this->add_index_if_missing( $p . 'lrn_certificates', 'expires_at', '(expires_at)' );
		$this->add_index_if_missing( $p . 'lrn_transactions', 'refund_of', '(refund_of)' );
		$this->add_index_if_missing( $p . 'lrn_transactions', 'type_status', '(transaction_type, status)' );
		$this->add_index_if_missing( $p . 'lrn_notifications', 'type_created', '(type, created_at)' );
		$this->add_index_if_missing( $p . 'lrn_webhooks', 'status', '(status)' );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Add a non-unique index only when no index of that name already
	 * exists on the table. Guard runs against INFORMATION_SCHEMA so the
	 * migration is safe to re-run and safe on fresh installs whose DDL
	 * already carries the key.
	 *
	 * @param string $table      Fully-prefixed table name.
	 * @param string $index_name Index name to create / check.
	 * @param string $cols       Parenthesised column list, e.g. '(status, created_at)'.
	 */
	private function add_index_if_missing( string $table, string $index_name, string $cols ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $this->db()->get_var(
			$this->db()->prepare(
				'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
				DB_NAME,
				$table,
				$index_name
			)
		);
		if ( $exists ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query( "ALTER TABLE {$table} ADD INDEX {$index_name} {$cols}" );
	}
}
