<?php
/**
 * Migration 2.5.0 — Reminder cadence dedup + cancellation table rollback.
 *
 * Two unrelated-but-bundled changes (PR-DE-clean):
 *
 *   A. Add the compound index `(user_id, type, object_type, object_id)`
 *      on `lrn_notifications` so PR-E's reminder-dedup query
 *      (`NOT EXISTS notification with type=X for object=Y`) stays
 *      O(log n) at 100k+ rows. Without it the query degrades to a
 *      table scan at scale.
 *
 *   B. Roll back the short-lived `lrn_cancellation_feedback` table that
 *      PR-D (2.4.0) introduced. It duplicated what `lrn_audit_log`
 *      already supports — cancellation is a subscription event; the
 *      `metadata_json` column on audit_log holds the reason / feedback
 *      / accepted_offer triple. The new cancel endpoint writes there
 *      directly. This migration:
 *        1. Copies every existing row from `lrn_cancellation_feedback`
 *           into `lrn_audit_log` (type='subscription_event',
 *           action='cancelled', object_type='subscription').
 *        2. DROPs `lrn_cancellation_feedback`.
 *
 * Both steps idempotent — INFORMATION_SCHEMA guards every check so
 * re-running on already-migrated installs is a no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_5_0 {

	public function up(): void {
		$this->add_notification_dedup_index();
		$this->migrate_cancellation_feedback_to_audit_log();
		$this->drop_cancellation_feedback_table();
	}

	// ── Step A — Compound index for PR-E reminder dedup ───────────────────

	private function add_notification_dedup_index(): void {
		$table = $this->db()->prefix . 'lrn_notifications';
		$this->add_index_if_missing(
			$table,
			'user_type_object',
			'(user_id, type, object_type, object_id)'
		);
	}

	// ── Step B — Cancellation feedback rollback ───────────────────────────

	/**
	 * Copy every row from the deprecated `lrn_cancellation_feedback` table
	 * into `lrn_audit_log` so the churn-reason history survives the table
	 * drop. The audit_log row carries the reason / feedback / accepted_offer
	 * triple in `metadata_json`, keyed on `(type, action)` so the future
	 * churn dashboard can query with one indexed scan.
	 *
	 * Idempotent — if the source table doesn't exist (fresh install that
	 * never ran 2.4.0), early-return. If rows have already been migrated
	 * the loop just inserts duplicates into audit_log, but audit_log has
	 * no UNIQUE constraint here, so re-runs would over-record. Guard by
	 * recording a marker row after the bulk copy and bailing on second
	 * pass when the marker exists.
	 */
	private function migrate_cancellation_feedback_to_audit_log(): void {
		$source = $this->db()->prefix . 'lrn_cancellation_feedback';
		$dest   = $this->db()->prefix . 'lrn_audit_log';

		if ( ! $this->table_exists( $source ) ) {
			return;
		}

		// Re-run guard: marker row in audit_log with action='cancellation_feedback_migrated'.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$already = $this->db()->get_var(
			"SELECT id FROM {$dest} WHERE type = 'migration' AND action = 'cancellation_feedback_migrated' LIMIT 1"
		);
		if ( $already ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db()->get_results(
			"SELECT id, user_id, subscription_id, reason, feedback_text, accepted_offer, created_at FROM {$source}",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $rows as $row ) {
			$metadata = wp_json_encode(
				array(
					'reason'         => (string) ( $row['reason'] ?? '' ),
					'feedback_text'  => (string) ( $row['feedback_text'] ?? '' ),
					'accepted_offer' => (string) ( $row['accepted_offer'] ?? '' ),
				)
			);
			$this->db()->insert(
				$dest,
				array(
					'user_id'       => (int) $row['user_id'],
					'type'          => 'subscription_event',
					'action'        => 'cancelled',
					'object_type'   => 'subscription',
					'object_id'     => (int) $row['subscription_id'],
					'metadata_json' => $metadata,
					'created_at'    => (string) $row['created_at'],
				)
			);
		}

		// Marker row so a re-run sees this migration is complete.
		$this->db()->insert(
			$dest,
			array(
				'user_id'       => 0,
				'type'          => 'migration',
				'action'        => 'cancellation_feedback_migrated',
				'object_type'   => '',
				'object_id'     => 0,
				'metadata_json' => wp_json_encode( array( 'rows' => count( (array) $rows ) ) ),
				'created_at'    => current_time( 'mysql', true ),
			)
		);
	}

	private function drop_cancellation_feedback_table(): void {
		$table = $this->db()->prefix . 'lrn_cancellation_feedback';
		if ( ! $this->table_exists( $table ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query( "DROP TABLE IF EXISTS {$table}" );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	private function table_exists( string $table ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $this->db()->get_var(
			$this->db()->prepare(
				'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s LIMIT 1',
				DB_NAME,
				$table
			)
		);
		return ! empty( $found );
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
