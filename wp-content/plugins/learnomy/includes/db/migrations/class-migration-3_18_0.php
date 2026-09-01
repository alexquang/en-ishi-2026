<?php
/**
 * Migration 3.18.0 — give live sessions their own start time.
 *
 * A live lesson had nowhere to store WHEN the session is, so the player read the
 * start out of `available_date` — the DRIP column. Those are two different
 * questions:
 *
 *   available_date     = when does this lesson UNLOCK for the student
 *   scheduled_start_at = when does the session HAPPEN
 *
 * Answering both with one column meant every scheduled live session was locked
 * to every student until the instant it began. The countdown, the join link, the
 * "starts in 2 hours" state — all of it was unreachable for exactly the people it
 * was built for. Only admins and the course author ever saw it, because they
 * bypass the drip gate (Lesson_Service::is_available_to). That is why this shipped
 * and stayed: it looked fine to the only people who could see it.
 *
 * So: move the value to the column that means what it was being used for, and
 * clear the drip lock it was accidentally imposing. Existing live lessons unlock.
 *
 * The move is safe even though a live lesson COULD in principle have wanted a real
 * drip date, because there was never a way to set one independently — the editor
 * exposed exactly one date field, the player read it as the session start, and
 * nothing in the UI ever told an author it also locked the lesson. One value, one
 * intent. We keep the intent the player acted on and drop the lock nobody asked
 * for.
 *
 * Forward-only + idempotent: adds each column only when missing, and the data move
 * is a no-op on re-run (it only touches rows that still carry an available_date).
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_18_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_lessons';

		$this->add_column(
			$table,
			'scheduled_start_at',
			"ADD COLUMN `scheduled_start_at` timestamp NULL DEFAULT NULL COMMENT 'When a live session starts (UTC). Distinct from available_date.' AFTER `duration_seconds`"
		);

		$this->add_column(
			$table,
			'live_duration_minutes',
			"ADD COLUMN `live_duration_minutes` smallint unsigned DEFAULT NULL COMMENT 'How long the live session runs, in minutes. NULL = default 60.' AFTER `scheduled_start_at`"
		);

		// Move every existing live session's start out of the drip column, and
		// release the lock it was imposing. Those lessons become visible to
		// students for the first time — that is the point, not a side effect.
		//
		// Scoped to fixed-mode rows: a relative-drip lesson keeps its schedule in
		// lrn_lesson_unlocks and never read available_date as a start, so there is
		// nothing to move and its lock is real.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"UPDATE `{$table}`
			    SET `scheduled_start_at` = `available_date`,
			        `available_date`     = NULL
			  WHERE `content_type`   = 'live'
			    AND `available_date` IS NOT NULL
			    AND `drip_mode`      = 'fixed'"
		);
	}

	/**
	 * Add a column only when it is missing, so a re-run — or a fresh install where
	 * Schema already created it — is a harmless no-op.
	 */
	private function add_column( string $table, string $column, string $clause ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);

		if ( (int) $exists > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` {$clause}" );
	}
}
