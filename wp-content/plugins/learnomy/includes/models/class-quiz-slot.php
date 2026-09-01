<?php
/**
 * Quiz slot — ordered join between a quiz and its pool of questions.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class QuizSlot extends Model {

	/**
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'quiz'     => 'quiz_id',
		'question' => 'question_id',
	);

	protected static function table_name(): string {
		return 'quiz_slots';
	}

	public static function add_question( int $quiz_id, int $question_id, int $sort_order = 0 ): int {
		return static::insert(
			[
				'quiz_id'     => $quiz_id,
				'question_id' => $question_id,
				'sort_order'  => $sort_order,
			]
		);
	}

	public static function remove_question( int $quiz_id, int $question_id ): bool {
		$deleted = false !== static::db()->delete(
			static::table(),
			[
				'quiz_id'     => $quiz_id,
				'question_id' => $question_id,
			]
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
		return $deleted;
	}

	public static function get_for_quiz( int $quiz_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE quiz_id = %d ORDER BY sort_order ASC',
				$quiz_id
			)
		);
	}

	/**
	 * Quiz IDs the given question is assigned to. Used by the question
	 * editor to render "Assigned to" badges when editing an existing
	 * question (Basecamp #9925596331).
	 *
	 * @return int[] Quiz IDs.
	 */
	public static function get_quiz_ids_for_question( int $question_id ): array {
		$ids = static::db()->get_col(
			static::db()->prepare(
				'SELECT DISTINCT quiz_id FROM ' . static::table() . ' WHERE question_id = %d',
				$question_id
			)
		);
		return array_map( 'intval', $ids ?: array() );
	}

	/**
	 * Bulk-sets sort_order from an ordered array of slot IDs.
	 * Cheaper than re-fetching and diffing individual rows.
	 */
	public static function reorder( array $slot_ids ): void {
		foreach ( $slot_ids as $position => $slot_id ) {
			static::update( (int) $slot_id, [ 'sort_order' => $position + 1 ] );
		}
	}

	/**
	 * Delete all slot rows for a quiz.
	 *
	 * Called by CLI fixture teardown before the quiz row itself is deleted
	 * so referential integrity is preserved without FK cascades.
	 */
	public static function delete_for_quiz( int $quiz_id ): bool {
		$deleted = false !== static::db()->delete( static::table(), [ 'quiz_id' => $quiz_id ] );
		static::invalidate_cache();
		return $deleted;
	}

	/**
	 * Delete every slot row that references a given question.
	 *
	 * Called when a question is removed from the bank entirely (not merely
	 * detached from one quiz) so no slot is left pointing at a non-existent
	 * question. An orphaned slot still contributes its marks to the quiz
	 * max_score in Quiz_Service::auto_grade() while the per-question guard
	 * skips scoring, silently lowering attempt scores. Index-backed by
	 * KEY question_id on lrn_quiz_slots.
	 *
	 * @param int $question_id Question being deleted.
	 * @return bool Whether the delete statement ran without error.
	 */
	public static function delete_for_question( int $question_id ): bool {
		$deleted = false !== static::db()->delete( static::table(), [ 'question_id' => $question_id ] );
		static::invalidate_cache();
		return $deleted;
	}

	/**
	 * Question (slot) count per quiz, in one grouped query. Powers a live count
	 * on the admin quizzes list without an N+1: the denormalised
	 * `lrn_quizzes.question_count` column is never maintained (adding/removing a
	 * slot doesn't touch it), so reading it showed 0/stale — this is the batched
	 * equivalent of the per-quiz QuizSlot::count() the detail views already use
	 * (Basecamp 10109341075).
	 *
	 * @param int[] $quiz_ids
	 * @return array<int,int> quiz_id => question count (absent = 0).
	 */
	public static function counts_for_quizzes( array $quiz_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $quiz_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT quiz_id, COUNT(*) AS n FROM ' . static::table() . " WHERE quiz_id IN ({$placeholders}) GROUP BY quiz_id",
				...$ids
			)
		);
		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->quiz_id ] = (int) $row->n;
		}
		return $map;
	}
}
