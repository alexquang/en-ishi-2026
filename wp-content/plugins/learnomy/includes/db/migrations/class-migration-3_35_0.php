<?php
/**
 * Migration 3.35.0 — indexes the Email Log needs to stay usable at scale.
 *
 * Two indexes, both for the Email Log work in this release:
 *
 *   A. `lrn_notifications` gets `KEY created_at (created_at)`. The table had
 *      compound keys led by `user_id` and by `type`, so neither could serve
 *      the two queries that matter here: the log's default listing
 *      (`ORDER BY created_at DESC` with no WHERE, previously a filesort over
 *      the whole table) and the new retention prune
 *      (`DELETE ... WHERE created_at < cutoff`, previously a full scan on a
 *      table that carries a row per email AND per in-app notification).
 *      `lrn_audit_log` has carried the same index since it shipped, for the
 *      same reason.
 *
 *   B. `lrn_audit_log` gets `KEY object (object_type, object_id)`. Rows are
 *      now written per email resend and read back per notification, and the
 *      table had no way to look a row up by the object it is about — every
 *      such read was a table scan. Pre-existing per-object reads
 *      (`AuditLog::has_reported()`, the moderation queue's duplicate check)
 *      were scanning too and are fixed by the same index.
 *
 * Idempotent: INFORMATION_SCHEMA guards both, so re-running is a no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_35_0 {

	public function up(): void {
		$this->add_index_if_missing(
			$this->db()->prefix . 'lrn_notifications',
			'created_at',
			'(created_at)'
		);

		$this->add_index_if_missing(
			$this->db()->prefix . 'lrn_audit_log',
			'object',
			'(object_type, object_id)'
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	private function add_index_if_missing( string $table, string $index_name, string $cols ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query( "ALTER TABLE {$table} ADD INDEX {$index_name} {$cols}" );
	}
}
