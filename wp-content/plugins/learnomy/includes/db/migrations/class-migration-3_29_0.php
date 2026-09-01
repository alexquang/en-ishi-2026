<?php
/**
 * Migration 3.29.0 — make `lrn_quiz_attempts.passed` a per-attempt fact.
 *
 * The column is per-ATTEMPT ("did this sitting clear the pass mark") but it was
 * written with a per-STUDENT verdict: Quiz_Service stamped the grading method's
 * answer onto the individual row, so with grading_method='highest' every attempt
 * a student ever made was stored as passed=1 the moment their best cleared the
 * bar — including sittings that scored 0. Quiz statistics aggregate this column
 * (`SUM(passed)`), so a quiz with one pass and one fail reported pass_rate=100
 * instead of 50 (Basecamp 10132808925).
 *
 * The write paths are fixed forward, but the aggregate the admin actually looks
 * at is computed over history. A forward-only fix would leave every existing
 * site reading the same wrong number for every attempt already taken, which is
 * the entire reported symptom. So this repairs the stored flag.
 *
 * This is NOT a re-grade, and the distinction matters — migration 3.28.0
 * deliberately declined to recompute historical SCORES, because doing so would
 * change what the student was told they earned. Nothing here touches `score`,
 * `max_score` or `percentage`. Only the derived boolean is recomputed, from the
 * percentage the row already stores and the quiz's pass mark, so the flag stops
 * contradicting the result screen the student was shown (a 0% attempt that read
 * "You passed").
 *
 * Scope and its limits:
 *
 * - `status = 'graded'` only. `submitted` rows (essay pending) were already
 *   written with the attempt's own percentage, and `in_progress` rows have no
 *   verdict yet.
 * - Survey-mode quizzes are excluded. A survey is satisfied by being submitted,
 *   not by a score; it stores percentage 0 with passed 1 on purpose, and
 *   recomputing would flip every survey attempt to failed.
 * - Rows whose quiz no longer exists are left alone (INNER JOIN).
 * - The pass mark used is the quiz's CURRENT `passing_score`. A quiz whose mark
 *   was changed after an attempt was sat is re-judged on today's mark; the row
 *   never recorded the mark it was originally graded against, so there is no
 *   historical value to prefer. The `learnomy_quiz_pass_score` filter is not
 *   applied either — it is a per-attempt runtime hook and cannot be replayed
 *   here. A site that filters the mark should re-check its statistics after
 *   upgrading.
 *
 * Consequence worth knowing: `max_failed_attempts` counts rows with passed = 0,
 * so a student whose failing sittings were credited as passes may now sit at a
 * higher failure count. That is the owner's configured ceiling finally being
 * enforced on sittings that genuinely failed, not a new restriction — but it is
 * a visible change on sites that set a ceiling.
 *
 * Course completion is unaffected: Progress_Service::has_passed_quiz() reads
 * raw percentages via QuizAttempt::get_scores(), never this column, so no
 * enrollment or certificate can be disturbed by the recompute.
 *
 * Forward-only and idempotent — re-running matches no rows the first pass
 * already corrected.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_29_0 {

	/** Rows corrected per statement, so a long history never locks the table. */
	private const BATCH_SIZE = 1000;

	public function up(): void {
		// Flip the two directions separately. One UPDATE with a CASE would
		// rewrite every graded row on the site including the ones already
		// correct; these two touch only rows that actually disagree.
		$this->correct( 'fails_marked_passed' );
		$this->correct( 'passes_marked_failed' );
	}

	/**
	 * Rewrite `passed` for graded attempts that disagree with their own score.
	 *
	 * Collects ids first, then updates by id list. MySQL rejects `LIMIT` on a
	 * multi-table `UPDATE ... JOIN`, and an unbounded join-update would hold
	 * row locks across every attempt on the site while this runs on a front-end
	 * request during upgrade.
	 *
	 * @param string $direction `fails_marked_passed` (the reported bug) or
	 *                          `passes_marked_failed` (its mirror, which the
	 *                          `average` and `first` methods could also produce).
	 */
	private function correct( string $direction ): void {
		global $wpdb;

		$attempts = $wpdb->prefix . 'lrn_quiz_attempts';
		$quizzes  = $wpdb->prefix . 'lrn_quizzes';

		if ( 'fails_marked_passed' === $direction ) {
			$mismatch = 'a.passed = 1 AND a.percentage < q.passing_score';
			$new      = 0;
		} else {
			$mismatch = 'a.passed = 0 AND a.percentage >= q.passing_score';
			$new      = 1;
		}

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names + a fixed comparison built above; no user input reaches this string.
			$ids = $wpdb->get_col(
				"SELECT a.id FROM `{$attempts}` a
				   INNER JOIN `{$quizzes}` q ON q.id = a.quiz_id
				  WHERE a.status = 'graded'
				    AND q.mode <> 'survey'
				    AND {$mismatch}
				  LIMIT " . self::BATCH_SIZE
			);

			$found = count( $ids );
			if ( 0 === $found ) {
				return;
			}

			$placeholders = implode( ',', array_fill( 0, $found, '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, a literal 0/1, and a %d list built from the id count.
					"UPDATE `{$attempts}` SET `passed` = {$new} WHERE id IN ({$placeholders})",
					...array_map( 'intval', $ids )
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} while ( $found === self::BATCH_SIZE );
	}
}
