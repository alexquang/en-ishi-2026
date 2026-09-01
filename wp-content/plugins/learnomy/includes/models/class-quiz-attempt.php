<?php
/**
 * Quiz attempt — one sitting of a user working through a quiz.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;
use function Learnomy\table;

class QuizAttempt extends Model {

	/**
	 * Attempts go with their quiz — Quiz_Service::delete_quiz() already removes
	 * responses, then attempts, then slots.
	 *
	 * `enrollment_id` is KEEP: an attempt is the record of a student sitting a
	 * quiz. Un-enrolling, or an enrollment row being removed, must not erase
	 * the grade that was earned.
	 *
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'quiz'       => 'quiz_id',
		'enrollment' => array(
			'column'    => 'enrollment_id',
			'on_delete' => \Learnomy\DB\Relations::KEEP,
		),
	);


	protected const BOOL_COLUMNS = [
		'passed',
	];

	protected const INT_COLUMNS = [
		'quiz_id',
		'user_id',
		'enrollment_id',
		'time_spent_seconds',
		'tab_switches',
	];

	protected const FLOAT_COLUMNS = [
		'score',
		'max_score',
		'percentage',
	];

	protected static function table_name(): string {
		return 'quiz_attempts';
	}

	/**
	 * Opens a new attempt row. IP is captured here so grading code never needs
	 * to worry about request context — the attempt record is self-contained.
	 */
	public static function start( int $user_id, int $quiz_id ): int {
		return static::insert(
			[
				'user_id'    => $user_id,
				'quiz_id'    => $quiz_id,
				'status'     => 'in_progress',
				'started_at' => now(),
				'ip_address' => \Learnomy\client_ip(),
			]
		);
	}

	/** Returns the most recent unfinished attempt, or null if the user has no open sitting. */
	public static function get_active( int $user_id, int $quiz_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . "
				WHERE user_id = %d AND quiz_id = %d AND status = 'in_progress'
				ORDER BY started_at DESC LIMIT 1",
				$user_id,
				$quiz_id
			)
		) ?: null;
	}

	/**
	 * A4 large-exam runner state, persisted as ONE JSON blob in the reserved
	 * `saved_state_json` longtext column — no dedicated columns (anti-bloat rule
	 * 4a: an existing column fits). Shape:
	 *   [
	 *     'frozen_order'  => int[]  // question ids in the order frozen at start
	 *     'deadline'      => string // ISO-8601 wall-clock deadline; '' when untimed
	 *     'current_index' => int    // last-viewed question index (resume position)
	 *     'flagged'       => int[]  // question ids flagged for review
	 *   ]
	 *
	 * @return array<string,mixed> Empty array when the attempt has no saved state.
	 */
	public static function get_runner_state( int $id ): array {
		$row = static::find( $id );
		if ( ! $row || empty( $row->saved_state_json ) ) {
			return [];
		}
		$decoded = json_decode( (string) $row->saved_state_json, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Merge-write the runner-state blob (shallow merge over the existing state).
	 *
	 * @param int                 $id    Attempt id.
	 * @param array<string,mixed> $patch Keys to set/overwrite.
	 * @return array<string,mixed> The merged state that was persisted.
	 */
	public static function save_runner_state( int $id, array $patch ): array {
		$state = array_merge( static::get_runner_state( $id ), $patch );
		static::update( $id, [ 'saved_state_json' => wp_json_encode( $state ) ] );
		return $state;
	}

	/** True once the attempt has frozen runner state (started, not a blank row). */
	public static function has_runner_state( int $id ): bool {
		return [] !== static::get_runner_state( $id );
	}

	/**
	 * Attempts for a quiz, newest first. Used by the admin attempt-review list.
	 *
	 * Capped by default (50) so a quiz with 10k+ attempts never loads the whole
	 * set into memory. Pass $limit = 0 only when you truly need every row and
	 * have a reason it can't run away — the cascade-delete path instead uses
	 * {@see get_ids_for_quiz()}, which never hydrates full rows. The
	 * quiz_user_created index (quiz_id, user_id, started_at) keeps the scan
	 * index-bound; pair with {@see count_for_quiz()} for the total.
	 *
	 * @param int $quiz_id Quiz to list attempts for.
	 * @param int $limit  Max rows (default 50; 0 = all).
	 * @param int $offset Rows to skip when paginating.
	 * @return object[]
	 */
	public static function get_for_quiz( int $quiz_id, int $limit = 50, int $offset = 0 ): array {
		return static::where( [ 'quiz_id' => $quiz_id ], 'started_at', 'DESC', $limit, $offset );
	}

	/** Total attempts for a quiz. Companion to {@see get_for_quiz()} for paging. */
	public static function count_for_quiz( int $quiz_id ): int {
		return static::count( [ 'quiz_id' => $quiz_id ] );
	}

	/**
	 * Aggregate pass/score statistics for a quiz in one query — powers the
	 * get-quiz-analytics ability. Pass rate is over GRADED attempts only (an
	 * in-progress attempt has no pass/fail yet).
	 *
	 * Per-ATTEMPT, like every aggregate over the `passed` column: "of the
	 * sittings taken on this quiz, what share cleared the pass mark". It is not
	 * "what share of students passed" — a student with three attempts counts
	 * three times. For the student-level, grading-method-aware verdict use
	 * Quiz_Service::student_passed_by_grading_method().
	 *
	 * @param int $quiz_id Quiz ID.
	 * @return array{total_attempts:int,graded:int,passed:int,pass_rate:float,avg_percentage:float}
	 */
	public static function pass_stats_for_quiz( int $quiz_id ): array {
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT COUNT(*) AS total,'
					. " SUM(CASE WHEN status = 'graded' THEN 1 ELSE 0 END) AS graded,"
					. " SUM(CASE WHEN status = 'graded' AND passed = 1 THEN 1 ELSE 0 END) AS passed,"
					. " COALESCE(AVG(CASE WHEN status = 'graded' THEN percentage END), 0) AS avg_percentage"
					. ' FROM ' . static::table() . ' WHERE quiz_id = %d',
				$quiz_id
			)
		);

		$graded = (int) ( $row->graded ?? 0 );
		$passed = (int) ( $row->passed ?? 0 );

		return [
			'total_attempts' => (int) ( $row->total ?? 0 ),
			'graded'         => $graded,
			'passed'         => $passed,
			'pass_rate'      => $graded > 0 ? round( ( $passed / $graded ) * 100, 2 ) : 0.0,
			'avg_percentage' => round( (float) ( $row->avg_percentage ?? 0 ), 2 ),
		];
	}

	/**
	 * The student's best graded quiz percentage across every quiz in a course,
	 * or null when the course has no graded attempt for that student. Powers the
	 * {score} certificate merge tag.
	 */
	public static function best_percentage_for_course( int $user_id, int $course_id ): ?float {
		// Delegates to the batch sibling so "best graded attempt in the course"
		// is defined in exactly one query, not two that could drift.
		$map = self::best_percentage_for_course_users( $course_id, array( $user_id ) );
		return $map[ $user_id ] ?? null;
	}

	/**
	 * Best graded quiz percentage per user for a course, for a SET of users, in
	 * ONE grouped query — the batch form of best_percentage_for_course().
	 *
	 * The gradebook grid pages a course's enrolments and needs every student's
	 * quiz component in one call; looping the single-user method would be an
	 * N+1. Users with no graded attempt are simply absent from the map (the
	 * caller treats a missing source as no-data). Served by the same
	 * user_id + course join the single-user version uses.
	 *
	 * @param int   $course_id Course id.
	 * @param int[] $user_ids  Users to score.
	 * @return array<int, float> user_id => best percentage (0..100).
	 */
	public static function best_percentage_for_course_users( int $course_id, array $user_ids ): array {
		$uids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( empty( $uids ) ) {
			return array();
		}

		$quizzes = \Learnomy\table( 'quizzes' );
		$user_ph = implode( ',', array_fill( 0, count( $uids ), '%d' ) );

		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names + generated int placeholders.
				'SELECT qa.user_id, MAX(qa.percentage) AS best_pct FROM ' . static::table() . " qa
				   INNER JOIN {$quizzes} q ON q.id = qa.quiz_id
				  WHERE qa.user_id IN ({$user_ph}) AND q.course_id = %d AND qa.status = 'graded'
				  GROUP BY qa.user_id",
				...array_merge( $uids, array( $course_id ) )
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->user_id ] = (float) $row->best_pct;
		}
		return $map;
	}

	/**
	 * Attempt IDs for a quiz, selecting only the id column.
	 *
	 * The cascade-delete path (Quiz_Service::delete_quiz, fixture teardown)
	 * needs every attempt id to delete child responses, but hydrating 10k+ full
	 * attempt rows just to read their ids is the actual OOM risk flagged in the
	 * bug. This streams ids in id-cursor batches so the cascade stays flat in
	 * memory no matter how many attempts a quiz has.
	 *
	 * @param int $quiz_id Quiz whose attempt ids to collect.
	 * @return int[] All matching attempt ids.
	 */
	public static function get_ids_for_quiz( int $quiz_id ): array {
		$table   = static::table();
		$ids     = [];
		$last_id = 0;
		$batch   = 1000;

		do {
			$rows    = static::db()->get_col(
				static::db()->prepare(
					'SELECT id FROM ' . $table . ' WHERE quiz_id = %d AND id > %d ORDER BY id ASC LIMIT %d',
					$quiz_id,
					$last_id,
					$batch
				)
			);
			$fetched = count( $rows );
			foreach ( $rows as $id ) {
				$ids[]   = (int) $id;
				$last_id = (int) $id;
			}
		} while ( $fetched === $batch );

		return $ids;
	}

	public static function get_for_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		return static::where( [ 'user_id' => $user_id ], 'started_at', 'DESC', $limit, $offset );
	}

	/**
	 * A user's most recent FINISHED attempts (graded or submitted), newest
	 * first by submission time. Excludes `in_progress` so the dashboard's
	 * recent-attempts feed and average-score never count a never-submitted
	 * attempt. `Model::where` only does equality, so the `status IN (...)`
	 * filter lives here (same idiom as count_graded/get_scores).
	 */
	public static function get_recent_finished_for_user( int $user_id, int $limit = 5, int $offset = 0 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE user_id = %d AND status IN ('graded','submitted') ORDER BY COALESCE(submitted_at, started_at) DESC LIMIT %d OFFSET %d",
				$user_id,
				max( 1, $limit ),
				max( 0, $offset )
			)
		) ?: [];
	}

	public static function count_for_user( int $user_id ): int {
		return static::count( [ 'user_id' => $user_id ] );
	}

	/**
	 * Aggregate statistics for a quiz — attempt count, average score, pass count.
	 *
	 * Per-ATTEMPT: `pass_count` counts graded SITTINGS whose own percentage
	 * cleared the pass mark, which is what makes the REST `pass_rate` comparable
	 * to `avg_score` over the same rows. It counted the student's best score
	 * instead until 1.8.1, so a quiz where every student had one pass and one
	 * fail reported a 100% pass rate (Basecamp 10132808925).
	 */
	public static function get_statistics( int $quiz_id ): object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT COUNT(*) AS attempt_count, COALESCE(AVG(percentage), 0) AS avg_score, SUM(passed) AS pass_count FROM ' . static::table() . " WHERE quiz_id = %d AND status = 'graded'",
				$quiz_id
			)
		) ?: (object) [
			'attempt_count' => 0,
			'avg_score'     => 0,
			'pass_count'    => 0,
		];
	}

	/** How many graded/submitted attempts a user has for a quiz. */
	public static function count_graded( int $user_id, int $quiz_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . " WHERE user_id = %d AND quiz_id = %d AND status IN ('graded','submitted')",
				$user_id,
				$quiz_id
			)
		);
	}

	/**
	 * {@see count_graded()} for a SET of quizzes in one grouped query.
	 *
	 * The admin student page shows attempts-used against the cap for every quiz
	 * a student has touched; asking per quiz would be one query per row.
	 *
	 * @param int   $user_id  Student.
	 * @param int[] $quiz_ids Quizzes to count.
	 * @return array<int,int> Keyed by quiz_id; quizzes with no finished attempt are absent.
	 */
	public static function count_graded_by_quiz( int $user_id, array $quiz_ids ): array {
		$ids = array_values( array_unique( array_map( 'intval', $quiz_ids ) ) );
		if ( empty( $ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are a %d list built from the id count.
				'SELECT quiz_id, COUNT(*) AS finished FROM ' . static::table()
					. " WHERE user_id = %d AND quiz_id IN ({$placeholders}) AND status IN ('graded','submitted') GROUP BY quiz_id",
				$user_id,
				...$ids
			)
		);

		$counts = [];
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->quiz_id ] = (int) $row->finished;
		}
		return $counts;
	}

	/**
	 * Most recent attempt — regardless of status — for a user on a quiz.
	 *
	 * Used by cooldown enforcement: we need the last submitted_at to decide
	 * whether the student is still inside the cooldown window. Falls back to
	 * started_at for rows that never submitted (abandoned), so this never
	 * returns a phantom "null submission" that looks retakeable.
	 */
	public static function find_latest( int $user_id, int $quiz_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d AND quiz_id = %d ORDER BY COALESCE(submitted_at, started_at) DESC LIMIT 1',
				$user_id,
				$quiz_id
			)
		) ?: null;
	}

	/**
	 * Count graded attempts that did not meet the quiz's passing score.
	 *
	 * Drives the `max_failed_attempts` lockout check — only finalized,
	 * failing attempts count toward the ceiling. In-progress and ungraded
	 * essay rows are excluded because the outcome isn't known yet.
	 *
	 * Per-ATTEMPT by definition: the ceiling counts sittings, so it reads the
	 * sitting's own verdict. Before 1.8.1 the column carried the student's best
	 * score, which meant a student who had once passed a `highest`-graded quiz
	 * could never accumulate a failure again — the owner's configured ceiling
	 * silently stopped applying to them.
	 */
	public static function count_failed( int $user_id, int $quiz_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . " WHERE user_id = %d AND quiz_id = %d AND status = 'graded' AND passed = 0",
				$user_id,
				$quiz_id
			)
		);
	}

	// has_passed() removed in 1.8.1. It answered "does ANY sitting have
	// passed = 1", which is not a student-level verdict on a quiz the owner
	// graded by `latest`, `average` or `first`: one lucky sitting outvoted the
	// grading method the teacher configured. It was the third independent
	// encoding of that rule (with Progress_Service::has_passed_quiz() and Pro's
	// journey aggregate), and the three could disagree with each other — a skill
	// badge for a quiz the certificate said was failed. The student-level answer
	// is Quiz_Service::passed_by_grading_method() (batch) or
	// ::student_passed_by_grading_method() (single). Do not reintroduce it.
	// Per-SITTING questions still read the column directly — see count_failed().

	/** Returns chronological score percentages for a user's attempts on a quiz. */
	public static function get_scores( int $user_id, int $quiz_id ): array {
		// Delegates to the batch sibling so "the scores that count" is defined by
		// exactly one query, not two that could drift on status or ordering.
		return self::get_scores_by_quiz( $user_id, [ $quiz_id ] )[ $quiz_id ] ?? [];
	}

	/**
	 * {@see get_scores()} for a SET of quizzes, in ONE grouped query.
	 *
	 * The course-completion gate asks the grading-method predicate about every
	 * quiz in a course, and it runs on every lesson completion — the hottest
	 * write path in the plugin. Asking per quiz was a query per quiz there.
	 * Served by the same (user_id, quiz_id) index the single-quiz form uses.
	 *
	 * @param int   $user_id  Student.
	 * @param int[] $quiz_ids Quizzes to read.
	 * @return array<int, float[]> quiz_id => chronological percentages. Quizzes
	 *                             with no finished attempt are absent.
	 */
	public static function get_scores_by_quiz( int $user_id, array $quiz_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $quiz_ids ) ) ) );
		if ( empty( $ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a %d list built from the id count.
				'SELECT quiz_id, percentage FROM ' . static::table()
					. " WHERE user_id = %d AND quiz_id IN ({$placeholders}) AND status IN ('graded','submitted') ORDER BY quiz_id ASC, graded_at ASC",
				$user_id,
				...$ids
			)
		);

		$scores = [];
		foreach ( (array) $rows as $row ) {
			$scores[ (int) $row->quiz_id ][] = (float) $row->percentage;
		}
		return $scores;
	}

	// percentages_excluding() removed in 1.8.1. It existed only so the `average`
	// grading method could be evaluated at WRITE time, against a set that still
	// held the in-flight attempt with a stale percentage. The grading method no
	// longer writes anything — Quiz_Service::student_passed_by_grading_method()
	// reads it after the attempt is stored, where get_scores() is already the
	// complete and correct set. Do not reintroduce it.

	/** Deletes all attempts for a user within a course — joins through quizzes table. */
	public static function delete_for_course( int $user_id, int $course_id ): void {
		$attempts_table = static::table();
		$quizzes_table  = table( 'quizzes' );
		static::db()->query(
			static::db()->prepare(
				"DELETE a FROM {$attempts_table} a INNER JOIN {$quizzes_table} q ON a.quiz_id = q.id WHERE a.user_id = %d AND q.course_id = %d",
				$user_id,
				$course_id
			)
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
	}

	/**
	 * Symmetric name with {@see Progress::delete_for_user_course()} — called
	 * by the course retake flow when `reset_on_retake` is true. Thin alias
	 * around {@see self::delete_for_course()} so service code reads the same
	 * on both models.
	 */
	public static function delete_for_user_course( int $user_id, int $course_id ): void {
		static::delete_for_course( $user_id, $course_id );
	}

	/** Anonymizes attempt data for GDPR erasure — zeroes user_id and removes IP. */
	public static function anonymize_for_user( int $user_id ): void {
		static::db()->update(
			static::table(),
			[
				'user_id'    => 0,
				'ip_address' => null,
			],
			[ 'user_id' => $user_id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
	}

	/** Purges abandoned in-progress attempts older than the cutoff timestamp. */
	public static function delete_abandoned( string $cutoff ): void {
		static::db()->query(
			static::db()->prepare(
				'DELETE FROM ' . static::table() . " WHERE status = 'in_progress' AND started_at < %s",
				$cutoff
			)
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
	}

	public static function submit( int $attempt_id, array $scores = [] ): bool {
		return static::update(
			$attempt_id,
			array_merge(
				[
					'status'       => 'submitted',
					'submitted_at' => now(),
				],
				$scores
			)
		);
	}

	/**
	 * Delete all attempt rows for a quiz.
	 *
	 * Used by CLI fixture teardown and test cleanup so quiz rows created
	 * during a test scenario are removed before the quiz row itself is
	 * deleted — preserving referential integrity without FK cascades.
	 */
	public static function delete_for_quiz( int $quiz_id ): bool {
		$deleted = false !== static::db()->delete( static::table(), [ 'quiz_id' => $quiz_id ] );
		static::invalidate_cache();
		return $deleted;
	}
}
