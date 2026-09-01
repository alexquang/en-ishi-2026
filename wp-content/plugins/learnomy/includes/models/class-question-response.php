<?php
/**
 * Question response — a single answer recorded during a quiz attempt.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use Learnomy\DB\Relations;

use function Learnomy\now;

class QuestionResponse extends Model {

	/**
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		// KEEP, not delete. A response row carries attempt_id, score, max_score
		// and the question_version that was answered — it IS the evidence behind
		// a graded attempt. Cascading it would erase the answers that produced a
		// stored score: disputes become unanswerable and a regrade impossible,
		// while the attempt still shows a mark. The question_version column
		// exists precisely because this table is meant to outlive edits to the
		// question, so deleting on question removal contradicts its own design.
		'question' => array(
			'column'    => 'question_id',
			'on_delete' => Relations::KEEP,
		),
	);

	protected static function table_name(): string {
		return 'question_responses';
	}

	/**
	 * Record the learner's answer to one question of an attempt.
	 *
	 * An attempt answers a question ONCE: this row is the student's CURRENT
	 * answer, not an append-only log of every keystroke. Re-answering — changing
	 * a choice mid-run (the player POSTs /answer on every selection when
	 * feedback is per-question), or the batch `answers` map re-sending the whole
	 * sitting at submit — updates that row instead of inserting a second one.
	 *
	 * The plain INSERT this replaced let both paths stack rows, and auto_grade()
	 * summed every one of them: a 4-question quiz answered normally graded 8/8
	 * with 8 review entries, and a single changed answer scored 2/3 because the
	 * abandoned wrong answer still occupied a mark (Basecamp 10134140396).
	 * `UNIQUE KEY attempt_question` on the table is the matching guarantee.
	 *
	 * Any earlier grade is cleared with the answer it belonged to, so grading
	 * re-derives the score from what the student actually left behind.
	 *
	 * `max_score` is NOT cleared with it. The marks earned are a fact about the
	 * answer; the marks available are a fact about the PAPER, and changing an
	 * answer does not change what the question is worth. Resetting it here (the
	 * caller passes the sitting's marks in `$response`) wiped the denominator on
	 * every re-answer, which is one of the two ways an essay reached its grader
	 * at max_score = 0.00 and had its correct fraction multiplied by nothing
	 * (Basecamp 10132809181).
	 *
	 * @param int   $attempt_id  Attempt the answer belongs to.
	 * @param int   $question_id Question being answered.
	 * @param array $response    Column data (response_json, question_version,
	 *                           max_score, …).
	 * @return int The response row id.
	 */
	public static function record( int $attempt_id, int $question_id, array $response ): int {
		$existing = static::find_for_question( $attempt_id, $question_id );

		if ( $existing ) {
			static::update(
				(int) $existing->id,
				array_merge(
					$response,
					[
						'score'     => 0,
						'graded_at' => null,
					]
				)
			);
			return (int) $existing->id;
		}

		return static::insert(
			array_merge(
				[
					'attempt_id'  => $attempt_id,
					'question_id' => $question_id,
				],
				$response
			)
		);
	}

	/** The attempt's current answer to one question, or null when unanswered. */
	public static function find_for_question( int $attempt_id, int $question_id ): ?object {
		$rows = static::where(
			[
				'attempt_id'  => $attempt_id,
				'question_id' => $question_id,
			],
			'id',
			'ASC',
			1
		);

		return $rows[0] ?? null;
	}

	/**
	 * THE decoder for `response_json` on an option-selecting question — the one
	 * place that knows the column's shapes.
	 *
	 * The column holds a JSON scalar OR a JSON array, and which one you get is
	 * an accident of the path that wrote it, not of the question type: on the
	 * reference install **640 of 888 `mc_single` responses are stored as a JSON
	 * string** (`"26531"`) and the remaining 246 as an array (`[26613]`), with
	 * `true_false` split 12/36 the same way. Any surface that handles only one
	 * shape is correct on some rows and wrong on others, which is precisely how
	 * the instructor grader came to print raw option ids while the student's own
	 * review of the same attempt read correctly (Basecamp 10184327418).
	 *
	 * Returns selections as STRINGS because that is what answer-row ids are
	 * compared as everywhere else; callers map them to option text themselves.
	 * Never call this for a text-response type — see QuestionTypes::selects_options().
	 *
	 * @param string|null $response_json Raw column value.
	 * @return array<int,string> Selected answer-row ids, in stored order.
	 */
	public static function selected_ids( ?string $response_json ): array {
		$decoded = json_decode( (string) $response_json, true );

		if ( is_scalar( $decoded ) ) {
			$decoded = [ $decoded ];
		}
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$ids = [];
		foreach ( $decoded as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$ids[] = $value;
			}
		}

		return $ids;
	}

	/** Count of responses in an attempt that haven't been graded yet. */
	public static function count_ungraded( int $attempt_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE attempt_id = %d AND graded_at IS NULL',
				$attempt_id
			)
		);
	}

	/** Per-question statistics: times answered and average score ratio. */
	public static function get_statistics( int $question_id ): object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT COUNT(*) AS times_answered, COALESCE(AVG(score / NULLIF(max_score, 0)), 0) AS avg_score_ratio FROM ' . static::table() . ' WHERE question_id = %d AND graded_at IS NOT NULL',
				$question_id
			)
		) ?: (object) [
			'times_answered'  => 0,
			'avg_score_ratio' => 0,
		];
	}

	/**
	 * Batched per-question statistics — the {@see get_statistics()} aggregate for
	 * a SET of questions in one query (no N+1), powering get-quiz-analytics over
	 * a whole quiz. Lower avg_score_ratio = harder question.
	 *
	 * @param int[] $question_ids Question IDs.
	 * @return array<int,array{times_answered:int,avg_score_ratio:float}> Keyed by question_id.
	 */
	public static function stats_for_questions( array $question_ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $question_ids ) ) );
		if ( empty( $ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are a %d list built from the id count.
				'SELECT question_id, COUNT(*) AS times_answered, COALESCE(AVG(score / NULLIF(max_score, 0)), 0) AS avg_score_ratio FROM ' . static::table()
					. " WHERE question_id IN ({$placeholders}) AND graded_at IS NOT NULL GROUP BY question_id",
				...$ids
			)
		);

		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->question_id ] = [
				'times_answered'  => (int) $row->times_answered,
				'avg_score_ratio' => round( (float) $row->avg_score_ratio, 4 ),
			];
		}
		return $map;
	}

	/** Bulk-delete responses for a set of attempt IDs. */
	public static function delete_for_attempts( array $attempt_ids ): void {
		if ( empty( $attempt_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $attempt_ids ), '%d' ) );
		static::db()->query(
			static::db()->prepare(
				'DELETE FROM ' . static::table() . " WHERE attempt_id IN ({$placeholders})",
				...$attempt_ids
			)
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
	}

	public static function get_for_attempt( int $attempt_id ): array {
		return static::where( [ 'attempt_id' => $attempt_id ], 'id', 'ASC' );
	}

	/**
	 * Bulk-read responses for a set of attempt IDs, grouped by attempt.
	 *
	 * The read twin of delete_for_attempts(). The GDPR exporter needs the answers
	 * belonging to a page of attempts, and calling get_for_attempt() in the loop
	 * would be one query per attempt; this is one query for the page.
	 *
	 * @param int[] $attempt_ids Attempts to read.
	 * @return array<int,object[]> attempt_id => responses, oldest first.
	 */
	public static function get_for_attempts( array $attempt_ids ): array {
		$attempt_ids = array_values( array_unique( array_map( 'intval', $attempt_ids ) ) );
		if ( empty( $attempt_ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $attempt_ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE attempt_id IN ({$placeholders}) ORDER BY id ASC",
				...$attempt_ids
			)
		) ?: [];

		$grouped = [];
		foreach ( $rows as $row ) {
			$grouped[ (int) $row->attempt_id ][] = $row;
		}

		return $grouped;
	}

	/**
	 * Per-category score breakdown for one attempt (A2). ONE grouped query joins
	 * the attempt's responses to their question's category and the category name,
	 * summing earned vs possible marks per category. Powers the results screen's
	 * "Algebra 8/10, Geometry 5/8" breakdown when the quiz groups by category.
	 *
	 * Uncategorised questions (category_id 0, no joined row) collapse into a
	 * single bucket the caller labels "General".
	 *
	 * @param int $attempt_id Attempt to summarise.
	 * @return object[] Rows: category_id, category_name, earned, possible, answered.
	 */
	public static function score_by_category( int $attempt_id ): array {
		$responses  = static::table();
		$questions  = \Learnomy\table( 'questions' );
		$categories = \Learnomy\table( 'question_categories' );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names + one numeric placeholder only.
				"SELECT COALESCE(q.category_id, 0) AS category_id,
				        COALESCE(c.name, '')       AS category_name,
				        SUM(qr.score)              AS earned,
				        SUM(qr.max_score)          AS possible,
				        COUNT(qr.id)               AS answered
				   FROM {$responses} qr
				   INNER JOIN {$questions}  q ON q.id = qr.question_id
				   LEFT  JOIN {$categories} c ON c.id = q.category_id
				  WHERE qr.attempt_id = %d
				  GROUP BY COALESCE(q.category_id, 0), COALESCE(c.name, '')
				  ORDER BY category_name ASC",
				$attempt_id
			)
		) ?: [];
	}

	/**
	 * The grading inbox query: one row per submitted attempt that still has at
	 * least one ungraded response, newest first. Powers the admin Grading page
	 * (`render_quiz_grading`) so a grader can find pending manual work across
	 * every quiz without knowing the quiz id up front.
	 *
	 * This is the cross-quiz, ownership-scoped companion to
	 * {@see get_pending_essays_for_quiz()} (which is per-quiz and Pro-only).
	 *
	 * @param int[]|null $course_ids Restrict to quizzes in these courses. `null`
	 *                               = no restriction (admins). An empty array
	 *                               means "the caller owns no courses" and
	 *                               returns nothing rather than running an
	 *                               invalid `IN ()`.
	 * @param int        $limit      Page size.
	 * @param int        $offset     Page offset.
	 * @return object[] Rows: attempt_id, quiz_id, course_id, user_id,
	 *                  quiz_title, submitted_at, ungraded_count.
	 */
	public static function get_pending_grading( ?array $course_ids, int $limit, int $offset ): array {
		if ( is_array( $course_ids ) && empty( $course_ids ) ) {
			return [];
		}

		$responses = static::table();
		$attempts  = \Learnomy\table( 'quiz_attempts' );
		$quizzes   = \Learnomy\table( 'quizzes' );

		$where  = "qr.graded_at IS NULL AND qa.status = 'submitted'";
		$params = [];

		if ( is_array( $course_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
			$where       .= " AND q.course_id IN ({$placeholders})";
			$params       = array_map( 'intval', $course_ids );
		}

		$params[] = $limit;
		$params[] = $offset;

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names + numeric placeholders only.
				"SELECT qa.id AS attempt_id, qa.quiz_id, q.course_id, qa.user_id,
				        q.title AS quiz_title, qa.submitted_at,
				        COUNT(qr.id) AS ungraded_count
				   FROM {$responses} qr
				   INNER JOIN {$attempts} qa ON qa.id = qr.attempt_id
				   INNER JOIN {$quizzes}  q  ON q.id  = qa.quiz_id
				  WHERE {$where}
				  GROUP BY qa.id
				  ORDER BY qa.submitted_at DESC
				  LIMIT %d OFFSET %d",
				...$params
			)
		) ?: [];
	}

	/**
	 * Count of submitted attempts awaiting manual grading. Mirrors the scope
	 * rules of {@see get_pending_grading()} so the list total and the menu
	 * badge agree.
	 *
	 * @param int[]|null $course_ids Restrict to quizzes in these courses, or
	 *                               `null` for no restriction.
	 */
	public static function count_pending_grading( ?array $course_ids ): int {
		if ( is_array( $course_ids ) && empty( $course_ids ) ) {
			return 0;
		}

		$responses = static::table();
		$attempts  = \Learnomy\table( 'quiz_attempts' );
		$quizzes   = \Learnomy\table( 'quizzes' );

		$where  = "qr.graded_at IS NULL AND qa.status = 'submitted'";
		$params = [];

		if ( is_array( $course_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
			$where       .= " AND q.course_id IN ({$placeholders})";
			$params       = array_map( 'intval', $course_ids );
		}

		$sql = "SELECT COUNT(*) FROM (
		            SELECT qa.id
		              FROM {$responses} qr
		              INNER JOIN {$attempts} qa ON qa.id = qr.attempt_id
		              INNER JOIN {$quizzes}  q  ON q.id  = qa.quiz_id
		             WHERE {$where}
		             GROUP BY qa.id
		        ) t";

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, no user input.
			return (int) static::db()->get_var( $sql );
		}

		return (int) static::db()->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names + numeric placeholders only.
			static::db()->prepare( $sql, ...$params )
		);
	}

	/**
	 * The "already graded" half of the grading queue: one row per attempt that a
	 * human has graded at least one response on, most recently graded first.
	 *
	 * `grader_id IS NOT NULL` is what makes this HUMAN-graded work — auto-grading
	 * stamps `graded_at` but never a grader, so an auto-scored multiple-choice
	 * attempt is not something anyone "did" and must not appear in a grader's
	 * history. Same ownership scoping as {@see get_pending_grading()}, so a
	 * teacher's done list and their to-do list cover the same courses.
	 *
	 * @param int[]|null $course_ids Restrict to quizzes in these courses. `null`
	 *                               = no restriction (admins). An empty array
	 *                               means "the caller owns no courses".
	 * @param int        $limit      Page size.
	 * @param int        $offset     Page offset.
	 * @return object[] Rows: attempt_id, quiz_id, course_id, user_id, quiz_title,
	 *                  submitted_at, graded_at, graded_count, ungraded_count.
	 */
	public static function get_recently_graded( ?array $course_ids, int $limit, int $offset ): array {
		if ( is_array( $course_ids ) && empty( $course_ids ) ) {
			return [];
		}

		$responses = static::table();
		$attempts  = \Learnomy\table( 'quiz_attempts' );
		$quizzes   = \Learnomy\table( 'quizzes' );

		$where  = 'qr.grader_id IS NOT NULL';
		$params = [];

		if ( is_array( $course_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
			$where       .= " AND q.course_id IN ({$placeholders})";
			$params       = array_map( 'intval', $course_ids );
		}

		$params[] = $limit;
		$params[] = $offset;

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names + numeric placeholders only.
				"SELECT qa.id AS attempt_id, qa.quiz_id, q.course_id, qa.user_id,
				        q.title AS quiz_title, qa.submitted_at,
				        MAX(qr.graded_at) AS graded_at,
				        COUNT(qr.id) AS graded_count,
				        (
				          SELECT COUNT(*) FROM {$responses} qr2
				           WHERE qr2.attempt_id = qa.id AND qr2.graded_at IS NULL
				        ) AS ungraded_count
				   FROM {$responses} qr
				   INNER JOIN {$attempts} qa ON qa.id = qr.attempt_id
				   INNER JOIN {$quizzes}  q  ON q.id  = qa.quiz_id
				  WHERE {$where}
				  GROUP BY qa.id
				  ORDER BY MAX(qr.graded_at) DESC
				  LIMIT %d OFFSET %d",
				...$params
			)
		) ?: [];
	}

	/**
	 * Count of attempts with human-graded responses. Mirrors the scope rules of
	 * {@see get_recently_graded()} so the list total and its pager agree.
	 *
	 * @param int[]|null $course_ids Restrict to quizzes in these courses, or
	 *                               `null` for no restriction.
	 */
	public static function count_recently_graded( ?array $course_ids ): int {
		if ( is_array( $course_ids ) && empty( $course_ids ) ) {
			return 0;
		}

		$responses = static::table();
		$attempts  = \Learnomy\table( 'quiz_attempts' );
		$quizzes   = \Learnomy\table( 'quizzes' );

		$where  = 'qr.grader_id IS NOT NULL';
		$params = [];

		if ( is_array( $course_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
			$where       .= " AND q.course_id IN ({$placeholders})";
			$params       = array_map( 'intval', $course_ids );
		}

		$sql = "SELECT COUNT(*) FROM (
		            SELECT qa.id
		              FROM {$responses} qr
		              INNER JOIN {$attempts} qa ON qa.id = qr.attempt_id
		              INNER JOIN {$quizzes}  q  ON q.id  = qa.quiz_id
		             WHERE {$where}
		             GROUP BY qa.id
		        ) t";

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only, no user input.
			return (int) static::db()->get_var( $sql );
		}

		return (int) static::db()->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names + numeric placeholders only.
			static::db()->prepare( $sql, ...$params )
		);
	}

	public static function get_for_question( int $question_id ): array {
		return static::where( [ 'question_id' => $question_id ], 'id', 'DESC' );
	}

	/**
	 * Delete every response row recorded for a given question.
	 *
	 * Called when a question is removed from the bank entirely so historical
	 * attempt data never references a non-existent question. An orphaned
	 * ungraded essay response permanently inflates count_ungraded(), which
	 * keeps maybe_finalize_attempt() from ever firing and strands the attempt
	 * at status=submitted. Index-backed by KEY question_id on
	 * lrn_question_responses.
	 *
	 * @param int $question_id Question being deleted.
	 * @return int Rows removed (or 0 on failure).
	 */
	public static function delete_for_question( int $question_id ): int {
		$deleted = (int) static::db()->delete( static::table(), [ 'question_id' => $question_id ] );
		static::invalidate_cache();
		return $deleted;
	}

	/**
	 * Returns ungraded essay responses across all attempts for a quiz.
	 *
	 * Used by the Pro rubric-grading admin view to surface pending essay
	 * responses. Joins through quiz_attempts so the caller never touches
	 * lrn_quiz_attempts directly.
	 *
	 * @param int $quiz_id Quiz to scope the query to.
	 * @return array Rows from lrn_question_responses with attempt_id, question_id, graded_at.
	 */
	public static function get_pending_essays_for_quiz( int $quiz_id ): array {
		$responses_table = static::table();
		$attempts_table  = \Learnomy\table( 'quiz_attempts' );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT qr.*
				   FROM {$responses_table} qr
				  INNER JOIN {$attempts_table} qa ON qa.id = qr.attempt_id
				  WHERE qa.quiz_id = %d
				    AND qr.graded_at IS NULL",
				$quiz_id
			)
		) ?: [];
	}

	/**
	 * Returns graded response rows with attempt percentage for discrimination analysis.
	 * Each row contains: score, max_score, attempt_pct.
	 */
	public static function get_discrimination_data( int $question_id ): array {
		$responses_table = static::table();
		$attempts_table  = \Learnomy\table( 'quiz_attempts' );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT qr.score, qr.max_score, qa.percentage AS attempt_pct
				   FROM {$responses_table} qr
				  INNER JOIN {$attempts_table} qa ON qa.id = qr.attempt_id
				  WHERE qr.question_id = %d
				    AND qr.graded_at IS NOT NULL
				    AND qr.max_score > 0
				    AND qa.status = 'graded'",
				$question_id
			)
		) ?: [];
	}
}
