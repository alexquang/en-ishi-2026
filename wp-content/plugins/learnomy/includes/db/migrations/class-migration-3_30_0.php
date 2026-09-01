<?php
/**
 * Migration 3.30.0 — give every answered question back its denominator.
 *
 * `lrn_question_responses.max_score` records what a question was worth in the
 * sitting it was answered in. Nothing wrote it at answer time: it was stamped
 * only by whoever graded the row, and only `auto_grade()`'s auto-gradeable
 * branch ever did. Everything needing a human or an extension — every essay,
 * every manually reviewed short answer — therefore sat at `0.00` from the
 * moment it was recorded until (at best) a grader touched it.
 *
 * Consumers multiply by that number. Pro's rubric grader computed
 * `fraction x max_score`, so a response correctly graded at 80% earned
 * `0.8 x 0 = 0` marks (Basecamp 10132809181). The student's own review renders
 * `score / max_score` and showed "2.4 / 0". And every per-question statistic
 * filters on `max_score > 0` (`get_statistics()`, `stats_for_questions()`,
 * `get_discrimination_data()`), so essays were silently absent from question
 * analytics entirely, while `score_by_category()` under-reported `possible`.
 *
 * The write paths are fixed forward — `answer_question()` now stamps the marks
 * with the answer, for every question type. But a zero here is not a fact any
 * site ever chose; it is a value that was never filled in, and it keeps
 * producing wrong output on screens that read history. So this fills it in.
 *
 * This is NOT a re-grade. Nothing here touches `score`, and no attempt total is
 * recomputed — migration 3.28.0 declined to recompute historical SCORES because
 * that changes what a student was told they earned, and that judgement stands.
 * The attempt totals never depended on this column in the first place: they are
 * summed from the paper via `Quiz_Service::marks_for_attempt()`, so they are
 * already right. Restoring the denominator only makes the row agree with the
 * total that was always computed beside it.
 *
 * Scope and its limits:
 *
 * - `max_score = 0` only. A row that already carries its marks is left alone,
 *   so a re-run cannot re-price anything.
 * - Marks come from `lrn_quiz_slots` — the same fallback `marks_for_attempt()`
 *   uses when an attempt predates the frozen-marks freeze, and the only source
 *   reachable from SQL. An attempt's frozen marks live in a JSON blob in
 *   `saved_state_json`; where the two differ, the runtime authority still wins
 *   for anything that re-grades from here on.
 * - A response whose question has no slot (a Pro random-pool draw) is
 *   deliberately SKIPPED rather than defaulted to 1.0. Its real weight is in
 *   that attempt's frozen marks, which `marks_for_question()` can still recover
 *   correctly; writing a guessed 1.0 into SQL would overwrite a recoverable
 *   value with a wrong one. Leaving 0 keeps it honest and recoverable.
 * - Slots with `marks <= 0` are skipped for the same reason: 0 is the value
 *   this migration exists to remove, not one to copy.
 *
 * Batched by id rather than run as a single `UPDATE ... JOIN`: on a site with
 * years of attempts the join form holds a lock on the whole table for its
 * duration, and migrations run on a front-end request during upgrade.
 *
 * Forward-only and idempotent — the `max_score = 0` guard makes a re-run a
 * no-op once the backfill has drained.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_30_0 {

	/** Rows repaired per statement. */
	private const BATCH_SIZE = 500;

	/** Safety stop, so a pathological data set can never spin here forever. */
	private const MAX_BATCHES = 2000;

	public function up(): void {
		global $wpdb;

		$responses = $wpdb->prefix . 'lrn_question_responses';
		$attempts  = $wpdb->prefix . 'lrn_quiz_attempts';
		$slots     = $wpdb->prefix . 'lrn_quiz_slots';

		$batches = 0;

		do {
			// One id page at a time: find rows still missing their denominator
			// and the marks their slot says they were worth.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names + one numeric placeholder.
					"SELECT r.id, s.marks
					   FROM `{$responses}` r
					   INNER JOIN `{$attempts}` a ON a.id = r.attempt_id
					   INNER JOIN `{$slots}`    s ON s.quiz_id = a.quiz_id AND s.question_id = r.question_id
					  WHERE r.max_score = 0
					    AND s.marks > 0
					  LIMIT %d",
					self::BATCH_SIZE
				)
			);

			$found = count( (array) $rows );
			if ( 0 === $found ) {
				return;
			}

			// Group the page by mark value so each distinct weight costs one
			// UPDATE instead of one per row.
			$ids_by_marks = array();
			foreach ( $rows as $row ) {
				$ids_by_marks[ (string) $row->marks ][] = (int) $row->id;
			}

			foreach ( $ids_by_marks as $marks => $ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a %d list built from the id count.
						"UPDATE `{$responses}` SET max_score = %f WHERE id IN ({$placeholders}) AND max_score = 0",
						(float) $marks,
						...$ids
					)
				); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}

			++$batches;
		} while ( $found === self::BATCH_SIZE && $batches < self::MAX_BATCHES );
	}
}
