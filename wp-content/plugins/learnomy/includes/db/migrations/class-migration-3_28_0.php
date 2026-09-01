<?php
/**
 * Migration 3.28.0 — one answer row per question per attempt.
 *
 * `QuestionResponse::record()` was a plain INSERT, so every re-answer stacked a
 * new row: the player POSTs /answer on each selection when feedback is
 * per-question, and submit re-sends the whole `answers` map on top. Grading
 * summed all of them, so a 4-question quiz answered normally graded 8/8, and a
 * student who simply changed one answer scored 2/3 because the abandoned wrong
 * answer still occupied a mark (Basecamp 10134140396).
 *
 * record() now upserts. This migration makes that a guarantee rather than a
 * convention: collapse the duplicates history already collected, then add
 * `UNIQUE KEY attempt_question`. The surviving row per pair is the HIGHEST id —
 * the student's last answer, which is the one they meant to submit and the one
 * the player showed them when they hit Submit.
 *
 * Historical attempt scores are NOT recomputed. `lrn_quiz_attempts` stores the
 * score the student was shown, and silently re-grading finished sittings (which
 * could flip a pass to a fail) is not a migration's call to make. New submits,
 * and any attempt an instructor re-grades from here on, use the corrected
 * totals.
 *
 * The old `KEY attempt_id` is dropped: the new UNIQUE key has attempt_id as its
 * left-most column, so every lookup it served is still index-backed.
 *
 * Forward-only and idempotent — each step is guarded by an information_schema
 * check, so re-running is a no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_28_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_question_responses';

		if ( ! $this->index_exists( $table, 'attempt_question' ) ) {
			$this->drop_duplicate_responses( $table );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE `{$table}` ADD UNIQUE KEY `attempt_question` (`attempt_id`,`question_id`)" );
		}

		// Only after the replacement index is in place, so the table is never
		// left without one covering attempt_id.
		if ( $this->index_exists( $table, 'attempt_question' ) && $this->index_exists( $table, 'attempt_id' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `attempt_id`" );
		}
	}

	/**
	 * Delete every response row superseded by a later answer to the same
	 * question in the same attempt.
	 *
	 * Deletes in id batches rather than one `DELETE … JOIN`: on a site with
	 * years of attempts the join form locks the whole table for the duration,
	 * and this runs on a front-end request during upgrade.
	 *
	 * @param string $table Fully-prefixed responses table.
	 */
	private function drop_duplicate_responses( string $table ): void {
		global $wpdb;

		$batch_size = 500;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$stale_ids = $wpdb->get_col(
				"SELECT r.id FROM `{$table}` r
				  INNER JOIN (
				      SELECT attempt_id, question_id, MAX(id) AS keep_id
				        FROM `{$table}`
				       GROUP BY attempt_id, question_id
				      HAVING COUNT(*) > 1
				  ) d ON d.attempt_id = r.attempt_id AND d.question_id = r.question_id
				 WHERE r.id <> d.keep_id
				 LIMIT {$batch_size}"
			);

			$found = count( $stale_ids );
			if ( 0 === $found ) {
				return;
			}

			$placeholders = implode( ',', array_fill( 0, count( $stale_ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a %d list built from the id count.
					"DELETE FROM `{$table}` WHERE id IN ({$placeholders})",
					...array_map( 'intval', $stale_ids )
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} while ( $found === $batch_size );
	}

	/** Whether a named index already exists on the table. */
	private function index_exists( string $table, string $index ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
				$table,
				$index
			)
		) > 0;
	}
}
