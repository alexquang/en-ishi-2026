<?php
/**
 * Migration 3.40.0 — clear child rows left behind by deleted questions.
 *
 * `lrn_questions` has no FK ON DELETE CASCADE, and until this release the
 * cascade lived in the REST controller rather than in the model. Every other
 * caller that hard-deletes a question — the CSV tooling, the fixture builder,
 * the demo seeder, WP-CLI — therefore left the question's answers, recorded
 * responses and quiz slots on disk with nothing pointing at them.
 *
 * The leftovers are not inert:
 *
 *   - An orphaned ungraded essay RESPONSE keeps `count_ungraded()` above zero
 *     forever, so the attempt it belonged to can never leave `submitted`.
 *   - An orphaned SLOT still contributes its marks to `auto_grade()`'s marks
 *     map while the per-question guard skips scoring it, silently lowering
 *     every affected attempt's score.
 *   - Orphaned ANSWERS are dead weight that also make the bank's own counts
 *     disagree with what an instructor sees.
 *
 * The cascade now lives in `Question::delete()`, so this only has to sweep the
 * rows already on disk. Each statement is scoped by NOT EXISTS against the
 * parent table, so it can only ever touch rows whose question is genuinely
 * gone — re-running finds nothing left to do.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_40_0 {

	public function up(): void {
		$this->purge_orphans( 'lrn_question_responses' );
		$this->purge_orphans( 'lrn_quiz_slots' );
		$this->purge_orphans( 'lrn_question_answers' );
	}

	/**
	 * Delete every row in $table whose `question_id` names a question that no
	 * longer exists.
	 *
	 * @param string $table Unprefixed table name.
	 */
	private function purge_orphans( string $table ): void {
		global $wpdb;

		$child  = $wpdb->prefix . $table;
		$parent = $wpdb->prefix . 'lrn_questions';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are internal constants; no user input.
		$wpdb->query(
			"DELETE c FROM {$child} c
			  WHERE c.question_id > 0
			    AND NOT EXISTS ( SELECT 1 FROM {$parent} q WHERE q.id = c.question_id )"
		);
	}
}
