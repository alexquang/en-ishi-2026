<?php
/**
 * Answer — one selectable option belonging to a question.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Answer extends Model {

	protected const BOOL_COLUMNS = [
		'is_correct',
	];

	protected const INT_COLUMNS = [
		'question_id',
		'sort_order',
	];

	protected const FLOAT_COLUMNS = [
		'fraction',
	];

	/**
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'question' => 'question_id',
	);

	protected static function table_name(): string {
		return 'question_answers';
	}

	public static function create( array $answer ): int {
		return static::insert( $answer );
	}

	public static function get_for_question( int $question_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE question_id = %d ORDER BY sort_order ASC',
				$question_id
			)
		);
	}

	/**
	 * Batch fetch — one query for many question ids. Returns a map keyed by
	 * question_id, each value an ORDER-BY-sort_order list of answer rows.
	 *
	 * Backs the N+1 fix in Quizzes_Controller::list_quiz_questions
	 * (Basecamp #9927991339). Without this, a 50-question quiz issued 50
	 * separate Answer::where() calls on the student quiz-loading hot path.
	 *
	 * @param int[] $question_ids
	 * @return array<int, object[]>
	 */
	public static function get_for_questions( array $question_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $question_ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE question_id IN ({$placeholders}) ORDER BY question_id ASC, sort_order ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$ids
			)
		);
		$grouped      = array_fill_keys( $ids, array() );
		foreach ( (array) $rows as $row ) {
			$grouped[ (int) $row->question_id ][] = $row;
		}
		return $grouped;
	}

	public static function get_correct_for_question( int $question_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE question_id = %d AND is_correct = 1 ORDER BY sort_order ASC',
				$question_id
			)
		);
	}

	/**
	 * Deletes every answer row belonging to a question.
	 *
	 * Callers routinely need to "replace" the answer set on save (the editor
	 * re-posts the entire list). Doing the wipe in one statement avoids an
	 * N-round-trip loop that would otherwise be easy to forget.
	 *
	 * @param int $question_id Parent question identifier.
	 * @return int             Rows removed (or 0 on failure).
	 */
	public static function delete_for_question( int $question_id ): int {
		$deleted = (int) static::db()->delete( static::table(), [ 'question_id' => $question_id ] );
		static::invalidate_cache();
		return $deleted;
	}
}
