<?php
/**
 * Enrollment — records a student's access to a course and their completion state.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Enrollment extends Model {

	/**
	 * The enrollment is the student's access, so almost nothing here deletes it.
	 *
	 * - `course`: DELETE. The course is gone; there is nothing left to access,
	 *   and Course_Service::delete() already calls delete_for_course().
	 * - `cohort`: CLEAR. Cohort_Service::delete() has always blanked this by
	 *   hand via clear_cohort(); the rule now lives here instead of in one
	 *   service, so every deletion path gets it.
	 * - `plan` / `path` / `space`: CLEAR. Retiring a plan, path or space must
	 *   never revoke a student's course access, but leaving the stamp behind
	 *   renders a parent that no longer exists. Blanking the pointer keeps the
	 *   access and drops the lie. Nothing cleared these before, which is why
	 *   deleting a plan left dangling ids across four tables.
	 *
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'course' => 'course_id',
		'plan'   => array(
			'column'    => 'plan_id',
			'on_delete' => \Learnomy\DB\Relations::CLEAR,
		),
		'path'   => array(
			'column'    => 'path_id',
			'on_delete' => \Learnomy\DB\Relations::CLEAR,
		),
		'cohort' => array(
			'column'    => 'cohort_id',
			'on_delete' => \Learnomy\DB\Relations::CLEAR,
		),
		'space'  => array(
			'column'    => 'space_id',
			'on_delete' => \Learnomy\DB\Relations::CLEAR,
		),
	);


	protected const INT_COLUMNS = [
		'user_id',
		'course_id',
		'plan_id',
		'path_id',
		'cohort_id',
		'space_id',
		'progress_pct',
		'subscription_id',
	];

	/**
	 * Rows pulled per batch by the cursor-paginated distinct-user / summary
	 * helpers. Keeps any single query (and the PHP array it hydrates) bounded
	 * so admin pages and CSV exports never load the whole enrollments table at
	 * once on large sites (Basecamp #9961949958).
	 */
	protected const USER_ID_BATCH = 2000;

	/**
	 * Hard safety cap for the convenience helpers that still return one flat
	 * array (announcement fan-out, students CSV in-request export). Past this
	 * many distinct users the caller should switch to a cursor variant; the
	 * cap stops a single request from OOMing on a 100k+ enrollment site.
	 */
	protected const USER_ID_HARD_CAP = 50000;

	protected static function table_name(): string {
		return 'enrollments';
	}

	public static function create( array $enrollment ): int {
		$defaults = [
			'status'       => 'active',
			'source'       => 'free',
			'progress_pct' => 0,
			'enrolled_at'  => now(),
		];
		return static::insert( array_merge( $defaults, $enrollment ) );
	}

	public static function find_active( int $user_id, int $course_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE user_id = %d AND course_id = %d AND status = 'active' LIMIT 1",
				$user_id,
				$course_id
			)
		) ?: null;
	}

	/**
	 * How the three "which enrollment represents this relationship" lookups rank
	 * candidate rows.
	 *
	 * A member can hold SEVERAL enrollments on one course - the UNIQUE key is
	 * (user_id, course_id, source) precisely so a purchase and a membership,
	 * space or cohort grant coexist. Something has to choose which row speaks
	 * for the pair, and that choice used to be `enrolled_at DESC`: the NEWEST
	 * row won. So any fresh secondary grant shadowed the row carrying the real
	 * history - a student 65% through a course they bought was shown "0%
	 * complete" and a "Start course" button the day their team assigned them a
	 * seat, while the curriculum beneath it still ticked the lessons they had
	 * finished, because the lesson ticks read the progress table directly
	 * (Basecamp 10207881380).
	 *
	 * The row that best represents the relationship is the one that has got
	 * furthest:
	 *
	 *   1. completed beats everything - finishing is not undone by a later grant
	 *   2. then the highest progress
	 *   3. then the newest, which only decides between rows that are otherwise
	 *      identical, and is the old behaviour for the single-row case
	 *
	 * Kept as one constant because three methods answer this same question for
	 * one row, a page of users and a page of courses. They drifted apart once
	 * already; sharing the clause is what stops the course page, the catalog
	 * grid, the tile counts and the CTA resolver disagreeing about the same
	 * student.
	 */
	private const REPRESENTATIVE_ORDER = "ORDER BY ( status = 'completed' ) DESC, progress_pct DESC, enrolled_at DESC";

	public static function find_for_user_course( int $user_id, int $course_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the clause is a class constant, never input.
				'SELECT * FROM ' . static::table() . " WHERE user_id = %d AND course_id = %d AND status IN ('active','completed') " . self::REPRESENTATIVE_ORDER . ' LIMIT 1',
				$user_id,
				$course_id
			)
		) ?: null;
	}

	/**
	 * Batch sibling of {@see find_for_user_course()}: the newest active /
	 * completed enrollment row for each of a SET of users on ONE course, in a
	 * single query, keyed by user_id.
	 *
	 * Replaces the per-member `find_for_user_course()` loop in cohort progress
	 * reporting (one query per member → one query per page of members). A user
	 * can hold several enrollment rows for the same course (different sources),
	 * so we keep the same "newest active-or-completed row wins" rule as the
	 * single-row method by walking the result set ordered `enrolled_at DESC`
	 * and taking the first row seen per user.
	 *
	 * `$statuses` defaults to the live pair so every existing caller is
	 * unchanged. Pass NULL for "whatever state they are in" — a roster or
	 * gradebook grid has to show a cancelled or expired learner AS cancelled or
	 * expired, and filtering them to the live pair would silently relabel them
	 * active. That is the one shape that needs the wider read; nothing else
	 * should.
	 *
	 * @param int[]      $user_ids  Member user IDs (deduped + int-cast internally).
	 * @param int        $course_id Course the cohort is bound to.
	 * @param array|null $statuses  Statuses to consider; NULL = any status.
	 * @return array<int,object> user_id => enrollment row (newest matching).
	 */
	public static function find_for_users_course( array $user_ids, int $course_id, ?array $statuses = array( 'active', 'completed' ) ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( empty( $ids ) || $course_id <= 0 ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$status_sql   = '';
		$status_args  = array();
		if ( null !== $statuses ) {
			$statuses = array_values( array_filter( array_map( 'strval', $statuses ) ) );
			if ( empty( $statuses ) ) {
				return array();
			}
			$status_sql  = ' AND status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$status_args = $statuses;
		}
		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . static::table()
					. " WHERE course_id = %d{$status_sql}"
					. " AND user_id IN ({$placeholders})"
					. ' ' . self::REPRESENTATIVE_ORDER,
				$course_id,
				...$status_args,
				...$ids
			)
		);
		$map = array();
		foreach ( (array) $rows as $row ) {
			$uid = (int) $row->user_id;
			// Ranked by REPRESENTATIVE_ORDER, so the first row seen per user is
			// the one that got furthest — matching find_for_user_course()'s
			// LIMIT 1 contract.
			if ( ! isset( $map[ $uid ] ) ) {
				$map[ $uid ] = $row;
			}
		}
		return $map;
	}

	/**
	 * Transpose of {@see find_for_users_course()}: the newest active /
	 * completed enrollment row for ONE user across a SET of courses, in a
	 * single query, keyed by course_id.
	 *
	 * Powers the REST `viewer` enrichment on catalog/course reads (the mobile
	 * app's is_enrolled / progress_pct) — one query per page of courses, never
	 * one per row. Same "newest active-or-completed row wins" rule as the
	 * single-row method.
	 *
	 * @param int   $user_id    Member user ID.
	 * @param int[] $course_ids Course IDs (deduped + int-cast internally).
	 * @return array<int,object> course_id => enrollment row (newest active/completed).
	 */
	public static function find_for_user_courses( int $user_id, array $course_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		if ( empty( $ids ) || $user_id <= 0 ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . static::table()
					. " WHERE user_id = %d AND status IN ('active','completed')"
					. " AND course_id IN ({$placeholders})"
					. ' ' . self::REPRESENTATIVE_ORDER,
				$user_id,
				...$ids
			)
		);
		$map = array();
		foreach ( (array) $rows as $row ) {
			$cid = (int) $row->course_id;
			// Ranked by REPRESENTATIVE_ORDER, so the first row seen per course is
			// the one that got furthest.
			if ( ! isset( $map[ $cid ] ) ) {
				$map[ $cid ] = $row;
			}
		}
		return $map;
	}

	/**
	 * Find a specific enrollment row by (user, course, source) — the unique
	 * key in `lrn_enrollments` is `(user_id, course_id, source)`, so a user
	 * can hold a purchase enrollment and a membership enrollment for the same
	 * course simultaneously.
	 *
	 * Used by the demo-seeder's idempotent `ensure_enrollment()` helper.
	 */
	public static function find_by_user_course_source( int $user_id, int $course_id, string $source ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d AND course_id = %d AND source = %s LIMIT 1',
				$user_id,
				$course_id,
				$source
			)
		) ?: null;
	}

	/**
	 * Enrollments for a user, newest first. Capped by `$limit` — power users
	 * can accumulate hundreds of enrollments, and dashboard templates must
	 * not pull them all on every page load. Pair with {@see count_for_user()}
	 * for "showing X of Y" UI and use {@see Model::where()} directly when a
	 * paged view needs a specific offset.
	 *
	 * NOTE: Returns one row per (user_id, course_id, source) tuple — intentionally
	 * includes duplicate course_id rows when the student holds multiple enrollment
	 * sources (e.g. free + manual). Use {@see get_distinct_for_user()} for
	 * dashboard card grids that must show one card per course.
	 */
	public static function get_for_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		return static::where( [ 'user_id' => $user_id ], 'enrolled_at', 'DESC', $limit, $offset );
	}

	/**
	 * One enrollment row per distinct course for a user, keeping the most-recent
	 * enrollment metadata when multiple sources exist for the same course.
	 *
	 * The `lrn_enrollments` UNIQUE key is `(user_id, course_id, source)`, so a
	 * student enrolled via "free" and again via "manual" will have two rows for
	 * the same course. Calling `get_for_user()` from a dashboard card grid would
	 * render that course twice (CF-007). This method resolves the collision at the
	 * query level — GROUP BY course_id, pick the row whose enrolled_at is greatest
	 * — so callers always receive exactly one row per course, newest first.
	 *
	 * The subquery identifies the canonical row id per course before the outer
	 * SELECT fetches the full row; this avoids non-determinism in GROUP BY
	 * aggregates on non-indexed columns without requiring MySQL ONLY_FULL_GROUP_BY
	 * to be disabled.
	 *
	 * By default the list is ENTITLEMENT-ALIGNED: it returns only courses the
	 * member can actually open, using the same status pair as
	 * {@see is_enrolled()}. See {@see live_rows_where()} for why.
	 *
	 * @param int  $user_id          WP user ID.
	 * @param int  $limit            Maximum rows to return (default 200 — matches dashboard cap).
	 * @param int  $offset           Row offset for paged callers.
	 * @param bool $include_inactive Admin/instructor history view: also return cancelled + expired rows.
	 * @return object[]    One row per distinct course_id, ordered enrolled_at DESC.
	 */
	public static function get_distinct_for_user( int $user_id, int $limit = 200, int $offset = 0, bool $include_inactive = false ): array {
		$table         = static::table();
		$courses_table = \Learnomy\table( 'courses' );
		$live          = self::live_rows_where( $include_inactive );

		// INNER JOIN the courses table so enrollments whose course has been
		// deleted are excluded. Without it, an orphaned enrollment (course gone)
		// surfaces as a blank card on My Courses and inflates the count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table}/{$courses_table} are internal table names; no user input.
		// Pick exactly ONE enrollment row per course: the latest by enrolled_at,
		// ties broken by highest id. A bare `enrolled_at = MAX(enrolled_at)` join
		// double-counts a course when the student holds two rows with the SAME
		// enrolled_at (e.g. a `free` and a `membership` enrollment created in one
		// transaction share the instant) — the course then renders TWICE on My
		// Courses and inflates every count derived from this list. Selecting the
		// winning id (argmax on enrolled_at, then id) guarantees one row per
		// course regardless of timestamp ties.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table}/{$courses_table} are internal table names; no user input.
		return static::db()->get_results(
			static::db()->prepare(
				"SELECT e.*
				 FROM {$table} e
				 INNER JOIN (
				     SELECT CAST(
				         SUBSTRING_INDEX( GROUP_CONCAT( id ORDER BY enrolled_at DESC, id DESC ), ',', 1 )
				     AS UNSIGNED ) AS keep_id
				     FROM {$table}
				     WHERE user_id = %d{$live}
				     GROUP BY course_id
				 ) latest
				   ON e.id = latest.keep_id
				 INNER JOIN {$courses_table} c ON c.id = e.course_id
				 ORDER BY e.enrolled_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$limit,
				$offset
			)
		) ?: [];
	}

	public static function count_for_user( int $user_id ): int {
		return static::count( [ 'user_id' => $user_id ] );
	}

	/**
	 * Canonical "how many distinct courses for a user". COUNT(DISTINCT course_id),
	 * so a course held through more than one source (membership + purchase) counts
	 * once — the courses-for-a-user twin of count_distinct_students().
	 *
	 * @param int         $user_id
	 * @param string|null $completion Null = any status. A status string (e.g. 'completed') filters to it.
	 */
	public static function count_distinct_courses( int $user_id, ?string $completion = null ): int {
		$sql    = 'SELECT COUNT(DISTINCT course_id) FROM ' . static::table() . ' WHERE user_id = %d';
		$params = array( $user_id );
		if ( null !== $completion && '' !== $completion ) {
			$sql     .= ' AND status = %s';
			$params[] = $completion;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed internal SQL; values bound via prepare().
		return (int) static::db()->get_var( static::db()->prepare( $sql, ...$params ) );
	}

	/**
	 * Distinct courses a user has completed. Thin wrapper over the canonical
	 * count_distinct_courses(). Used by Pro's milestone engine for "N courses
	 * completed" rules.
	 */
	public static function count_completed_courses( int $user_id ): int {
		return static::count_distinct_courses( $user_id, 'completed' );
	}

	/**
	 * The batched twin of {@see count_completed_courses()}: distinct completed
	 * courses for many learners in ONE query.
	 *
	 * Exists because Pro's User Journeys admin screen lists up to 100 learners
	 * per page, and asking per learner is an N+1 that only shows up on a real
	 * roster. Counts DISTINCT course_id for the same reason the single-user
	 * version does: the enrollments key is (user, course, source, path), so a
	 * learner holding a free grant and a membership grant for one course has
	 * completed one course, not two.
	 *
	 * @param int[] $user_ids Learners to count.
	 * @return array<int,int> user_id => completed course count. Learners with
	 *                        no completions are present with 0, so callers can
	 *                        read the map directly without a null coalesce.
	 */
	public static function count_completed_courses_for_users( array $user_ids ): array {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );

		if ( ! $user_ids ) {
			return array();
		}

		$counts       = array_fill_keys( $user_ids, 0 );
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT user_id, COUNT( DISTINCT course_id ) AS completed FROM ' . static::table()
					. " WHERE status = 'completed' AND course_id > 0 AND user_id IN ({$placeholders})"
					. ' GROUP BY user_id',
				...$user_ids
			)
		);

		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->user_id ] = (int) $row->completed;
		}

		return $counts;
	}

	/**
	 * All distinct course ids a user has completed, in one query. Lets callers
	 * test many courses for completion in memory instead of one query each
	 * (Pro's skill scorer uses it to avoid an N+1 over a mapped category).
	 *
	 * @return int[]
	 */
	public static function completed_course_ids( int $user_id ): array {
		$ids = static::db()->get_col(
			static::db()->prepare(
				'SELECT DISTINCT course_id FROM ' . static::table()
					. " WHERE user_id = %d AND status = 'completed'",
				$user_id
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Enrolled + completed course counts for a SET of users, in one query.
	 * Powers the admin Students list without a per-row COUNT — the prior loop ran
	 * two Enrollment::count() calls per student (N+1). Users with no rows are
	 * absent from the map (caller defaults to 0).
	 *
	 * Counts DISTINCT course_id, NOT rows: the unique key is
	 * (user_id, course_id, source), so a course enrolled through more than one
	 * source (e.g. directly AND via a Space/membership) has two rows and would
	 * otherwise be counted twice — the "+1 mystery course" on the roster
	 * (Basecamp #40982). Same semantics as count_completed_courses() and
	 * get_student_summary(); keep all three in lockstep.
	 *
	 * @param int[]      $user_ids
	 * @param int[]|null $scope_course_ids Restrict the counts to this course set
	 *                                     (e.g. an instructor's own courses, so
	 *                                     the roster shows enrolments in THEIR
	 *                                     courses, not platform-wide). Null = all.
	 * @return array<int,array{enrolled:int,completed:int}>
	 */
	public static function counts_for_users( array $user_ids, ?array $scope_course_ids = null ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$params       = $ids;

		$scope_sql = '';
		if ( null !== $scope_course_ids ) {
			$scope_ids = array_values( array_unique( array_filter( array_map( 'intval', $scope_course_ids ) ) ) );
			if ( empty( $scope_ids ) ) {
				return array();
			}
			$scope_placeholders = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
			$scope_sql          = " AND course_id IN ({$scope_placeholders})";
			$params             = array_merge( $params, $scope_ids );
		}

		// "Enrolled" means the same here as everywhere else: a course the student
		// can actually open. Counting revoked rows made the Students list keep
		// reporting "3 enrolled" after a teacher removed access to all three,
		// which is the admin-action-with-no-visible-effect bug in list form
		// (Basecamp 10132808967). See live_rows_where().
		//
		// %s ('completed') binds first, then the user-id placeholders, then the
		// optional scope course-id placeholders — matching their order in the SQL.
		$live = self::live_rows_where( false );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT user_id, COUNT(DISTINCT course_id) AS enrolled,
					COUNT(DISTINCT CASE WHEN status = %s THEN course_id END) AS completed
				 FROM ' . static::table() . " WHERE user_id IN ({$placeholders}){$scope_sql}{$live} GROUP BY user_id",
				'completed',
				...$params
			)
		);
		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->user_id ] = array(
				'enrolled'  => (int) $row->enrolled,
				'completed' => (int) $row->completed,
			);
		}
		return $map;
	}

	/**
	 * Canonical "is this enrollment completed?" — the ROW-level twin of
	 * distinct_status_where('completed'). Completion is a STATUS concept, not a
	 * progress_pct one: a percentage-threshold or manual completion flips
	 * `status='completed'` (and stamps `completed_at`) while `progress_pct` stays
	 * at the real coverage (e.g. 33), per mark_completed(). EVERY surface must
	 * judge "completed" from this, never from `progress_pct >= 100` — otherwise a
	 * completed course shows "Continue" + a partial bar while sitting in the
	 * Completed tab, and its certificate may already exist (Basecamp 10109147988).
	 *
	 * @param object|null $row An enrollment row (reads status / completed_at / progress_pct).
	 */
	public static function is_completed( ?object $row ): bool {
		if ( ! $row ) {
			return false;
		}
		return 'completed' === (string) ( $row->status ?? '' )
			|| ! empty( $row->completed_at )
			|| 100 === (int) ( $row->progress_pct ?? 0 );
	}

	/**
	 * Canonical "is this enrollment in progress?" — the ROW-level twin of
	 * distinct_status_where('in-progress'), and the only predicate any PHP
	 * surface may use to answer it.
	 *
	 * In progress means ENROLLED AND NOT FINISHED, never "has made progress".
	 * The SQL side learned that in Basecamp 10172243212; the PHP side did not,
	 * so the student dashboard kept splitting its buckets on `progress_pct > 0`
	 * and every learner who had not completed a lesson yet fell out of the one
	 * list that was supposed to hold them. The two halves then disagreed on the
	 * same screen: the stat tile counted 60 courses in progress (SQL) while the
	 * rail beneath it offered six untouched courses and the resume hero vanished
	 * entirely (PHP), for a learner with five lessons open (Basecamp 10183993951).
	 *
	 * Liveness is a SEPARATE question and is not asked here, exactly as in the
	 * SQL twin, where live_rows_where() elects the live row before this filter
	 * runs. A caller holding raw rows that may include cancelled or expired
	 * enrollments must pair this with its own status check.
	 *
	 * Not the same question as "should the button say Continue or Start" — that
	 * one IS about progress_pct, and course-card.php is right to ask it there.
	 *
	 * @param object|null $row An enrollment row (reads status / completed_at / progress_pct).
	 */
	public static function is_in_progress( ?object $row ): bool {
		if ( ! $row ) {
			return false;
		}
		return ! self::is_completed( $row );
	}

	/**
	 * SQL WHERE fragment (on the joined `e` row) for a My Courses tab status.
	 * Static SQL only — never interpolates user input. Mirrors the PHP split in
	 * Student_Dashboard_View_Data so counts/pages match what the tabs show.
	 *
	 * @param string $status all | in-progress | completed
	 */
	/**
	 * Optional `AND <alias>.status = 'published'` for the courses JOIN.
	 *
	 * Lives in the ON clause, not the WHERE, so it composes with an empty status
	 * filter (`$status = 'all'` yields no WHERE at all).
	 *
	 * Defaults to OFF because the member's own surfaces — My Courses, the student
	 * dashboard, /enrollments — must keep showing a course they are enrolled in
	 * even after the owner unpublishes it; hiding it would strand paid access.
	 * PUBLIC surfaces pass true: a member's profile is readable by anonymous
	 * visitors, and a completed course that is now draft or private must not leak
	 * its title there.
	 *
	 * Static SQL only — never interpolates user input.
	 *
	 * @param bool   $published_only Whether to restrict to published courses.
	 * @param string $alias          Table alias used for courses in the query.
	 */
	private static function course_visibility_join( bool $published_only, string $alias = 'c' ): string {
		return $published_only ? " AND {$alias}.status = 'published'" : '';
	}

	/**
	 * `AND status IN ('active','completed')` for the row-picking subquery of the
	 * distinct-per-course lists — the SAME pair {@see is_enrolled()} uses to
	 * decide access.
	 *
	 * A list that disagrees with the access gate is a lie the member has to
	 * discover by clicking: removing a student's access left the course sitting
	 * on their dashboard, and opening it answered "you do not have access"
	 * (Basecamp 10132808967). One definition of "enrolled" now drives both.
	 *
	 * It belongs INSIDE the subquery, not the outer WHERE: the subquery elects
	 * one representative row per course (newest first). Filtering afterwards
	 * would hide a course whose newest row happens to be the cancelled one even
	 * though a live row from another source still grants access. Filtering
	 * first elects the newest LIVE row instead, and a course with no live row
	 * drops out entirely.
	 *
	 * Static SQL only — never interpolates user input.
	 *
	 * @param bool   $include_inactive True for the admin/instructor history view.
	 * @param string $alias            Table alias to qualify the column with, if any.
	 */
	private static function live_rows_where( bool $include_inactive, string $alias = '' ): string {
		if ( $include_inactive ) {
			return '';
		}

		$column = '' === $alias ? 'status' : "{$alias}.status";

		return " AND {$column} IN ( 'active', 'completed' )";
	}

	private static function distinct_status_where( string $status ): string {
		switch ( $status ) {
			case 'completed':
				return "WHERE ( e.status = 'completed' OR e.completed_at IS NOT NULL OR e.progress_pct = 100 )";
			case 'in-progress':
				// Enrolled and not finished - NOT "has made progress". The old
				// `progress_pct > 0` predicate hid every newly-enrolled learner
				// (everyone starts at 0%) and every admin-reset enrollment
				// (reset_for_course's own docblock calls that state "fresh,
				// in-progress") from all ~6 surfaces sharing this definition:
				// the My Courses tab + its count, the student dashboard, and
				// the member-credentials / enrollments REST lists (Basecamp
				// 10172243212).
				return "WHERE e.progress_pct < 100 AND ( e.status IS NULL OR e.status <> 'completed' ) AND e.completed_at IS NULL";
			default:
				return '';
		}
	}

	/**
	 * One page of distinct (one-per-course) enrollments for a user, filtered by
	 * a My Courses tab status, newest first. Backs the "Load more" pager so a
	 * 1000-course learner pages 40 at a time instead of loading everything.
	 *
	 * @param int    $user_id          Member.
	 * @param string $status           all | in-progress | completed
	 * @param int    $per_page         Page size.
	 * @param int    $offset           Rows to skip.
	 * @param bool   $published_only   Restrict to published courses (public surfaces).
	 * @param bool   $include_inactive Admin/instructor history view: also return cancelled + expired rows.
	 */
	public static function get_distinct_for_user_page( int $user_id, string $status = 'all', int $per_page = 40, int $offset = 0, bool $published_only = false, bool $include_inactive = false ): array {
		$table         = static::table();
		$courses_table = \Learnomy\table( 'courses' );
		$where         = self::distinct_status_where( $status );
		$visible       = self::course_visibility_join( $published_only );
		$live          = self::live_rows_where( $include_inactive );

		// INNER JOIN courses so enrollments for a deleted course are excluded —
		// otherwise My Courses renders blank cards (the row's course is gone) and
		// whole pages come back empty, exactly what happened when a course was
		// deleted out from under its enrollments.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table}/{$courses_table} are internal table names; {$where} is a fixed internal fragment with no user input.
		// One row per course, latest by enrolled_at with id as the tiebreaker —
		// see get_distinct_for_user() for why a bare `enrolled_at = MAX(...)` join
		// double-counts a course on same-instant multi-source enrollments. The
		// status {$where} still filters the chosen representative row on the outer
		// query, matching the prior behaviour.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table}/{$courses_table} are internal table names; {$where} is a fixed internal fragment with no user input.
		return static::db()->get_results(
			static::db()->prepare(
				"SELECT e.*
				 FROM {$table} e
				 INNER JOIN (
				     SELECT CAST(
				         SUBSTRING_INDEX( GROUP_CONCAT( id ORDER BY enrolled_at DESC, id DESC ), ',', 1 )
				     AS UNSIGNED ) AS keep_id
				     FROM {$table}
				     WHERE user_id = %d{$live}
				     GROUP BY course_id
				 ) latest
				   ON e.id = latest.keep_id
				 INNER JOIN {$courses_table} c ON c.id = e.course_id{$visible}
				 {$where}
				 ORDER BY e.enrolled_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				max( 1, $per_page ),
				max( 0, $offset )
			)
		) ?: [];
	}

	/**
	 * Total distinct courses a user is enrolled in for a My Courses tab status
	 * (for the tab count badges + the load-more "has more" check). One COUNT(*),
	 * accurate at any scale.
	 *
	 * @param int    $user_id          Member.
	 * @param string $status           all | in-progress | completed
	 * @param bool   $published_only   Restrict to published courses (public surfaces).
	 * @param bool   $include_inactive Admin/instructor history view: also count cancelled + expired rows.
	 */
	public static function count_distinct_for_user( int $user_id, string $status = 'all', bool $published_only = false, bool $include_inactive = false ): int {
		$table         = static::table();
		$courses_table = \Learnomy\table( 'courses' );
		$where         = self::distinct_status_where( $status );
		$visible       = self::course_visibility_join( $published_only, 'crs' );
		$live          = self::live_rows_where( $include_inactive );

		// INNER JOIN courses so the tab count + has-more check ignore enrollments
		// whose course was deleted — matching get_distinct_for_user_page(), so the
		// count never overstates what actually renders.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table}/{$courses_table} are internal table names; {$where} is a fixed internal fragment with no user input.
		// Same one-row-per-course selection as get_distinct_for_user_page() so the
		// count matches the grid exactly. A bare `enrolled_at = MAX(...)` join
		// counts a course twice when the student holds two same-instant rows
		// (free + membership), which is precisely how the dashboard tab badge and
		// "Courses Enrolled" stat over-reported against the de-duped card grid.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$table}/{$courses_table} are internal table names; {$where} is a fixed internal fragment with no user input.
		return (int) static::db()->get_var(
			static::db()->prepare(
				"SELECT COUNT(*) FROM (
				    SELECT e.id
				    FROM {$table} e
				    INNER JOIN (
				        SELECT CAST(
				            SUBSTRING_INDEX( GROUP_CONCAT( id ORDER BY enrolled_at DESC, id DESC ), ',', 1 )
				        AS UNSIGNED ) AS keep_id
				        FROM {$table}
				        WHERE user_id = %d{$live}
				        GROUP BY course_id
				    ) latest
				      ON e.id = latest.keep_id
				    INNER JOIN {$courses_table} crs ON crs.id = e.course_id{$visible}
				    {$where}
				) c",
				$user_id
			)
		);
	}

	/**
	 * Enrollments for a course, newest first. A popular course can have tens
	 * of thousands of enrollments, so callers must always paginate. Pair with
	 * {@see count_for_course()}.
	 */
	/**
	 * A page of a course's roster.
	 *
	 * `$orderby` exists because the instructor roster wants "attention needed
	 * first" — and sorting a PAGE client-side cannot deliver that: with 41
	 * students the first page held 12%..50% while the genuinely stuck ones
	 * (1%..11%) sat on page 2, invisible under a heading promising the
	 * opposite (Basecamp D14). Ordering has to happen where the paging does.
	 *
	 * @param string $orderby 'enrolled_at' (default) or 'progress_pct'.
	 * @param string $order   'ASC' or 'DESC'.
	 */
	public static function get_for_course(
		int $course_id,
		int $limit = 50,
		int $offset = 0,
		string $orderby = 'enrolled_at',
		string $order = 'DESC'
	): array {
		// Allow-list the column — this reaches ORDER BY, so never pass through
		// caller input unchecked.
		$orderby = in_array( $orderby, [ 'enrolled_at', 'progress_pct' ], true ) ? $orderby : 'enrolled_at';
		$order   = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		return static::where(
			[
				'course_id'  => $course_id,
				'status__in' => static::current_statuses(),
			],
			$orderby,
			$order,
			$limit,
			$offset
		);
	}

	/**
	 * A page of a course's roster — one row per PERSON, not per enrollment.
	 *
	 * A learner can legitimately hold several enrollment rows for one course:
	 * the unique key is (user_id, course_id, source, path_id), so a free grant
	 * and a membership grant coexist by design. `get_for_course()` answers
	 * "what records exist" and is right for REST and diagnostics. This answers
	 * "who is on this course", which is what every surface pairing a list with
	 * count_distinct_students() actually asks — and pairing that count with a
	 * raw row list is what made the panel show five rows under "4 students
	 * enrolled" (Basecamp 10167508313).
	 *
	 * The representative row is the learner's BEST standing, not their newest
	 * grant. Newest-wins reports someone as 20% through a course they already
	 * finished under another grant, because the later row is a fresh space
	 * enrolment at zero progress.
	 *
	 * Each returned row carries two extra properties:
	 *   - grant_sources (string[]) every source this learner holds, sorted
	 *   - grant_count   (int)      how many rows collapsed into this one
	 *
	 * Sources are surfaced rather than hidden because on a site where
	 * instructors sell courses, 'free' vs 'purchase' vs 'membership' is the
	 * difference between a paying student and a comped one.
	 *
	 * @param int $course_id Course to page.
	 * @param int $limit     People per page.
	 * @param int $offset    People to skip.
	 * @return array<int, object>
	 */
	public static function get_roster_for_course( int $course_id, int $limit = 50, int $offset = 0 ): array {
		$db    = static::db();
		$table = static::table();
		$limit = $limit > 0 ? $limit : 50;

		// Rank inside the person, then take the top row per person. The CASE
		// mirrors the status vocabulary; anything unrecognised sorts last so a
		// future status never silently outranks 'completed'.
		$rows = $db->get_results(
			$db->prepare(
				"SELECT e.* FROM {$table} e
				 INNER JOIN (
					SELECT user_id, SUBSTRING_INDEX(
						GROUP_CONCAT(
							id ORDER BY
								CASE status WHEN 'completed' THEN 3 WHEN 'active' THEN 2 ELSE 1 END DESC,
								progress_pct DESC,
								id DESC
						), ',', 1
					) AS keep_id
					FROM {$table}
					WHERE course_id = %d
					GROUP BY user_id
				 ) best ON best.keep_id = e.id
				 ORDER BY e.enrolled_at DESC, e.id DESC
				 LIMIT %d OFFSET %d",
				$course_id,
				$limit,
				max( 0, $offset )
			)
		);

		if ( ! $rows ) {
			return array();
		}

		// One batched query for the grants behind the people on this page —
		// never a per-row lookup inside the loop.
		$user_ids     = array_map( static fn( $r ) => (int) $r->user_id, $rows );
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		$grants = $db->get_results(
			$db->prepare(
				"SELECT user_id, source FROM {$table}
				 WHERE course_id = %d AND user_id IN ({$placeholders})",
				array_merge( array( $course_id ), $user_ids )
			)
		);

		$by_user = array();
		foreach ( (array) $grants as $grant ) {
			$by_user[ (int) $grant->user_id ][] = (string) $grant->source;
		}

		foreach ( $rows as $row ) {
			$sources = $by_user[ (int) $row->user_id ] ?? array();
			$sources = array_values( array_unique( array_filter( $sources ) ) );
			sort( $sources );
			$row->grant_sources = $sources;
			$row->grant_count   = count( $by_user[ (int) $row->user_id ] ?? array() );
		}

		return (array) $rows;
	}

	/**
	 * How many distinct PEOPLE are on a course — the companion count to
	 * {@see get_roster_for_course()}. Delegates so there is one definition.
	 */
	public static function count_roster_for_course( int $course_id ): int {
		return static::count_distinct_students( array( $course_id ), array() );
	}

	/**
	 * Human label for an enrollment source.
	 *
	 * One definition, because an instructor selling courses reads these to tell
	 * a paying student from a comped one, and three screens re-spelling them
	 * differently is how that stops being trustworthy.
	 *
	 * @param string $source Raw `source` column value.
	 * @return string Translated label; the raw value humanised if unrecognised.
	 */
	public static function source_label( string $source ): string {
		$labels = array(
			'purchase'      => __( 'Purchase', 'learnomy' ),
			'paid'          => __( 'Purchase', 'learnomy' ),
			'subscription'  => __( 'Subscription', 'learnomy' ),
			'membership'    => __( 'Membership', 'learnomy' ),
			'free'          => __( 'Free', 'learnomy' ),
			'manual'        => __( 'Added by admin', 'learnomy' ),
			'space'         => __( 'Learning Space', 'learnomy' ),
			'learning_path' => __( 'Learning path', 'learnomy' ),
		);

		/**
		 * Filters the human label for an enrollment source, so an integration
		 * adding its own source can name it rather than showing a raw slug.
		 *
		 * @param string $label  The resolved label.
		 * @param string $source The raw source value.
		 */
		return (string) apply_filters(
			'learnomy_enrollment_source_label',
			$labels[ $source ] ?? ucfirst( str_replace( '_', ' ', $source ) ),
			$source
		);
	}

	/**
	 * Whether a source represents money changing hands. Drives the chip accent
	 * so a roster answers "who paid" at a glance.
	 *
	 * @param string $source Raw `source` column value.
	 */
	public static function source_is_paid( string $source ): bool {
		return (bool) apply_filters(
			'learnomy_enrollment_source_is_paid',
			in_array( $source, array( 'purchase', 'paid', 'subscription', 'membership' ), true ),
			$source
		);
	}

	/** How many of a course's students have finished — COUNT(*), not a page scan. */
	public static function count_completed_for_course( int $course_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE course_id = %d AND progress_pct >= 100',
				$course_id
			)
		);
	}

	public static function count_for_course( int $course_id ): int {
		return static::count(
			[
				'course_id'  => $course_id,
				'status__in' => static::current_statuses(),
			]
		);
	}

	/**
	 * Mean progress_pct across a course's CURRENT enrolments, computed in SQL so
	 * it scales.
	 *
	 * Scoped through {@see current_statuses()} like every other reader: a
	 * cancelled enrolment's progress was being averaged into the course's mean,
	 * so removing a struggling student silently moved the number an owner reads
	 * as course health (Basecamp 10132808987).
	 *
	 * KNOWN AND DELIBERATELY UNFIXED HERE: with no rows, `AVG()` returns SQL
	 * NULL and this still casts it to `0` - reporting "averaged zero" where the
	 * truth is "nothing to average" (Basecamp 10184535576). Both callers put the
	 * value straight into a REST/ability response, so widening the return to
	 * `?int` is an openapi contract change the mobile app generates against, and
	 * it belongs to that card's sweep with the app team rather than to this one.
	 */
	public static function avg_progress_for_course( int $course_id ): int {
		$statuses     = static::current_statuses();
		$placeholders = implode( ', ', array_fill( 0, max( 1, count( $statuses ) ), '%s' ) );

		$value = static::db()->get_var(
			static::db()->prepare(
				'SELECT AVG(progress_pct) FROM ' . static::table()
					. " WHERE course_id = %d AND status IN ( {$placeholders} )",
				$course_id,
				...( $statuses ?: [ '' ] )
			)
		);

		return (int) round( (float) $value );
	}

	/**
	 * The statuses that mean "this person is currently enrolled".
	 *
	 * THE definition, and the only one. Four readers answered this question and
	 * three of them answered it by not asking: `is_enrolled()` correctly said
	 * active-or-completed, while `get_for_course()`, `count_for_course()` and
	 * `avg_progress_for_course()` filtered on `course_id` alone and counted
	 * cancelled rows as students (Basecamp 10132808987).
	 *
	 * The visible result: removing a student returned 200, the roster still
	 * listed them, the count still included them, and only the denormalised
	 * `lrn_courses.student_count` dropped - so an owner saw a table of 5 people
	 * under a heading that said 4. Measured on lms.local at the time: 29
	 * cancelled rows being counted as enrolments across the site.
	 *
	 * Cancelling is deliberately a soft write. The row anchors quiz attempts,
	 * progress, certificates and - on a paid course - a purchase, so deleting it
	 * would destroy the record of a sale. It stops COUNTING, it does not stop
	 * EXISTING.
	 *
	 * Filterable so a site with its own lifecycle status (paused, suspended) can
	 * say whether it still counts, without editing four queries.
	 *
	 * @return string[]
	 */
	public static function current_statuses(): array {
		/**
		 * Statuses treated as a live enrolment by rosters, counts and averages.
		 *
		 * @param string[] $statuses Default: active, completed.
		 */
		return (array) apply_filters( 'learnomy_enrollment_current_statuses', [ 'active', 'completed' ] );
	}

	public static function is_enrolled( int $user_id, int $course_id ): bool {
		return ! empty(
			static::where(
				[
					'user_id'    => $user_id,
					'course_id'  => $course_id,
					'status__in' => static::current_statuses(),
				],
				'id',
				'DESC',
				1
			)
		);
	}

	/**
	 * Did the user complete a course (regardless of source)?
	 *
	 * Used by Learning Paths / Cohorts to gate step access — they care
	 * about the completion state, not which path/membership wrote the
	 * original enrollment row.
	 */
	public static function has_completed_course( int $user_id, int $course_id ): bool {
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT id FROM ' . static::table() . " WHERE user_id = %d AND course_id = %d AND status = 'completed' LIMIT 1",
				$user_id,
				$course_id
			)
		);
		return null !== $row;
	}

	/**
	 * Find an active path enrollment for a user.
	 *
	 * Path enrollments live in this table with `course_id = 0` and
	 * `path_id` set — that's the convention Pro Learning Paths writes.
	 * Surfacing this lookup as a Model method keeps Pro out of raw SQL
	 * against Free's table.
	 */
	public static function find_active_path_enrollment( int $user_id, int $path_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE user_id = %d AND path_id = %d AND status = 'active' LIMIT 1", // active-only-ok — dedicated in-progress lookup.
				$user_id,
				$path_id
			)
		) ?: null;
	}

	/**
	 * Path-enrollment ENTITLEMENT finder — active OR completed. The path twin of
	 * find_for_user_course(): a graduate whose path row flipped to 'completed'
	 * (J-PRO-PATH.3) is still enrolled for display/access purposes, so anything
	 * asking "is this user in this path?" (single-path is_enrolled, optional-course
	 * enrollability) must use THIS, not find_active_path_enrollment() — otherwise a
	 * completed path reads as never-enrolled (Basecamp find_active sweep). Excludes
	 * cancelled/expired, unlike the any-status find_path_enrollment().
	 */
	public static function find_for_user_path( int $user_id, int $path_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE user_id = %d AND path_id = %d AND status IN ( 'active', 'completed' ) ORDER BY FIELD( status, 'active', 'completed' ) LIMIT 1",
				$user_id,
				$path_id
			)
		) ?: null;
	}

	/**
	 * Any-status path enrollment for a (user, path) — including completed.
	 *
	 * The enroll dedupe guard must use this, not find_active_path_enrollment():
	 * once a path is completed its enrollment row flips to status='completed'
	 * (J-PRO-PATH.3), so an active-only check misses it and a re-enroll then
	 * hits the (user_id, course_id, source) UNIQUE index with a raw DB error.
	 *
	 * @param int $user_id User ID.
	 * @param int $path_id Learning-path ID.
	 * @return object|null The enrollment row, or null when the user was never
	 *                     enrolled in this path.
	 */
	public static function find_path_enrollment( int $user_id, int $path_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d AND path_id = %d LIMIT 1',
				$user_id,
				$path_id
			)
		) ?: null;
	}

	/**
	 * Distinct active/completed students enrolled in a learning PATH. The path
	 * analog of count_distinct_students() — Pro's Learning_Path uses it to keep
	 * its denormalized student_count accurate instead of an increment-only counter
	 * that never decremented on leave (Basecamp — denormalized-column audit).
	 */
	public static function count_distinct_students_for_path( int $path_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(DISTINCT user_id) FROM ' . static::table()
					. " WHERE path_id = %d AND status IN ( 'active', 'completed' )",
				$path_id
			)
		);
	}

	/**
	 * Active path enrollments for a user, newest first.
	 * Caps at 200 — even power-learners rarely exceed that.
	 */
	public static function get_user_active_paths( int $user_id, int $limit = 200 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE user_id = %d AND path_id > 0 AND status = 'active' ORDER BY enrolled_at DESC LIMIT %d", // active-only-ok — the get_user_paths() twin is active+completed for display.
				$user_id,
				$limit
			)
		) ?: [];
	}

	/**
	 * Path enrollments to DISPLAY for a user — active OR completed, newest first.
	 * The list twin of find_for_user_path(): "My Learning Paths" and the /my-paths
	 * REST feed must keep a completed path (a graduate should still see their
	 * finished path + its certificate), so they use this, not the active-only
	 * get_user_active_paths() which dropped finished paths (Basecamp find_active
	 * sweep). Excludes cancelled/expired.
	 */
	public static function get_user_paths( int $user_id, int $limit = 200 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE user_id = %d AND path_id > 0 AND status IN ( 'active', 'completed' ) ORDER BY enrolled_at DESC LIMIT %d",
				$user_id,
				$limit
			)
		) ?: [];
	}

	/**
	 * Completion funnel for a set of courses — how many enrolled, started, halfway, completed.
	 * Useful for instructor analytics dashboards.
	 */
	public static function completion_funnel( array $course_ids ): array {
		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		if ( empty( $course_ids ) ) {
			return array(
				'enrolled'  => 0,
				'started'   => 0,
				'halfway'   => 0,
				'completed' => 0,
			);
		}

		// Cached under the enrollments incrementor group — the funnel is four
		// aggregate queries rendered on the instructor dashboard, and any
		// enrollment/progress write bumps the group so the cache self-invalidates.
		$group  = static::cache_group();
		$key    = 'funnel_' . md5( (string) wp_json_encode( $course_ids ) . '|' . wp_cache_get_last_changed( $group ) );
		$found  = false;
		$cached = wp_cache_get( $key, $group, false, $found );
		if ( $found && is_array( $cached ) ) {
			return $cached;
		}

		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
		$table        = static::table();

		// Completed enrollments still count as "enrolled / started / halfway"
		// — finishing a course doesn't undo the fact that the student
		// enrolled, started, and passed the halfway mark. Earlier the funnel
		// scoped every step to `status='active'`, so a completed student
		// vanished from every funnel cell except the final one, producing
		// totals that were lower than the completed count itself
		// (Basecamp #9925395083).
		$active_or_done = "( 'active', 'completed' )";

		// COUNT(DISTINCT user_id), not COUNT(*): the unique key is
		// (user_id, course_id, source, path_id), so a student holding one course
		// through two sources (e.g. direct + Space) is two rows and would inflate
		// every funnel cell. The funnel is about people at each stage, so all four
		// cells count distinct people together (converting them piecemeal would
		// break the enrolled/started/halfway/completed ratios).
		$enrolled  = (int) static::db()->get_var( static::db()->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE course_id IN ({$placeholders}) AND status IN {$active_or_done}", ...$course_ids ) );
		$started   = (int) static::db()->get_var( static::db()->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE course_id IN ({$placeholders}) AND status IN {$active_or_done} AND progress_pct > 0", ...$course_ids ) );
		$halfway   = (int) static::db()->get_var( static::db()->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE course_id IN ({$placeholders}) AND status IN {$active_or_done} AND progress_pct >= 50", ...$course_ids ) );
		$completed = (int) static::db()->get_var( static::db()->prepare( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE course_id IN ({$placeholders}) AND status = 'completed'", ...$course_ids ) );

		$funnel = compact( 'enrolled', 'started', 'halfway', 'completed' );
		wp_cache_set( $key, $funnel, $group, 300 );
		return $funnel;
	}

	/**
	 * Completion funnel scoped to every course an instructor owns or
	 * co-teaches, computed in SQL via Course's id subquery — so the funnel
	 * reflects the instructor's whole catalogue, not the page of courses the
	 * dashboard happens to list. The subquery is already prepared (its only
	 * inputs are the integer user id), so it is safe to interpolate here.
	 *
	 * @param int $user_id Instructor user ID.
	 * @return array{enrolled:int,started:int,halfway:int,completed:int}
	 */
	public static function completion_funnel_for_instructor( int $user_id ): array {
		$empty = array(
			'enrolled'  => 0,
			'started'   => 0,
			'halfway'   => 0,
			'completed' => 0,
		);
		if ( $user_id <= 0 ) {
			return $empty;
		}

		$table    = static::table();
		$subquery = \Learnomy\Models\Course::instructor_course_ids_subquery( $user_id );
		$scope    = "course_id IN ({$subquery})";
		$done     = "( 'active', 'completed' )";

		// COUNT(DISTINCT user_id) for the same multi-source reason as
		// completion_funnel() — a student on two of the instructor's courses (or
		// one course via two sources) must not inflate the stage counts.
		return array(
			'enrolled'  => (int) static::db()->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE {$scope} AND status IN {$done}" ),
			'started'   => (int) static::db()->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE {$scope} AND status IN {$done} AND progress_pct > 0" ),
			'halfway'   => (int) static::db()->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE {$scope} AND status IN {$done} AND progress_pct >= 50" ),
			'completed' => (int) static::db()->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE {$scope} AND status = 'completed'" ),
		);
	}

	/**
	 * THE canonical inactive-student query — every "who has gone quiet"
	 * surface derives from this one shape (Basecamp 10127571047).
	 *
	 * Before consolidation there were THREE definitions and two of them
	 * disagreed on the same site by 5-7 people: the alert count included
	 * COMPLETED students (someone who finished the course counted as at
	 * risk), and the at-risk list measured activity SITE-WIDE (a student
	 * busy in another instructor's course read as active here). The owner
	 * decisions that settled it, recorded on the card:
	 *
	 *   1. Completed students are NOT at risk. A finished student is a
	 *      success, not a re-engagement target.
	 *   2. Inactive is PER-COURSE. The instructor is asking "who has gone
	 *      quiet in THIS course"; activity elsewhere must not mask it.
	 *
	 * The signal is `e.last_accessed_at` — a column ON the (user, course)
	 * enrollment row, touched by Progress_Service on every progress event,
	 * so it is per-course by construction and needs no join. Never-accessed
	 * falls back to `enrolled_at`: enrolled N days ago and never showed up
	 * IS inactive.
	 *
	 * Grouped per (user, course) with the cutoff in HAVING over MAX/MIN,
	 * because the unique key is (user_id, course_id, source): a learner
	 * holding a course through a purchase AND a Space seat has two rows,
	 * and a row-level WHERE would let a stale source row flag a person
	 * whose other row was active yesterday.
	 *
	 * @param string $course_in SQL fragment for the course filter's IN(...)
	 *                          body — %d placeholders or a trusted internal
	 *                          subquery. Empty string = no course filter.
	 * @return string Inner SELECT yielding (user_id, course_id, enrolled_at,
	 *                last_activity) pairs; carries ONE %d for the day window,
	 *                to be bound LAST after any course-id params.
	 */
	private static function inactive_pairs_sql( string $course_in ): string {
		$table         = static::table();
		$course_filter = '' !== $course_in ? "AND e.course_id IN ({$course_in})" : '';
		return "SELECT e.user_id, e.course_id,
		               MIN(e.enrolled_at)      AS enrolled_at,
		               MAX(e.last_accessed_at) AS last_activity
		        FROM {$table} e
		        WHERE e.status = 'active'
		          AND e.completed_at IS NULL
		          {$course_filter}
		        GROUP BY e.user_id, e.course_id
		        HAVING COALESCE( MAX(e.last_accessed_at), MIN(e.enrolled_at) ) < ( NOW() - INTERVAL %d DAY )";
	}

	/**
	 * Count DISTINCT students inactive on an instructor's courses — the
	 * Engagement Alerts number. Derived from inactive_pairs_sql() so it can
	 * NEVER disagree with the at-risk list or the roster filter.
	 */
	public static function count_inactive_students_for_instructor( int $user_id, int $days ): int {
		if ( $user_id <= 0 || $days <= 0 ) {
			return 0;
		}
		$subquery = \Learnomy\Models\Course::instructor_course_ids_subquery( $user_id );
		$pairs    = self::inactive_pairs_sql( $subquery );
		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- canonical fragment + trusted subquery; %d is bound.
				"SELECT COUNT(DISTINCT x.user_id) FROM ( {$pairs} ) x",
				$days
			)
		);
	}

	/**
	 * course_ids variant of count_inactive_students_for_instructor() — for the
	 * admin platform-wide dashboard view, scoped to the passed course set.
	 * Same canonical shape.
	 */
	public static function count_inactive_students( array $course_ids, int $days ): int {
		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		if ( ! $course_ids || $days <= 0 ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
		$pairs        = self::inactive_pairs_sql( $placeholders );
		return (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- canonical fragment + placeholders; %d bound last.
				"SELECT COUNT(DISTINCT x.user_id) FROM ( {$pairs} ) x",
				...array_merge( $course_ids, array( $days ) )
			)
		);
	}

	/**
	 * Canonical distinct-enrolled-user-id list. ONE WHERE clause — DISTINCT
	 * user_id over active/completed enrollments — shared by every "who's
	 * enrolled" reader, scoped and optionally keyset-paged. The named wrappers
	 * below express intent at the call site; this is the single query.
	 *
	 * @param int[]    $course_ids Empty = every course (site-wide). A site-wide
	 *                             fan-out should page via $after/$limit — an
	 *                             unscoped unbounded call is a full-table scan.
	 * @param int|null $after      Keyset cursor: only user_id > $after (ORDER BY user_id ASC).
	 * @param int      $limit      0 = unbounded; > 0 = LIMIT.
	 * @return int[]
	 */
	public static function enrolled_user_ids( array $course_ids = array(), ?int $after = null, int $limit = 0 ): array {
		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		$where      = array( "status IN ( 'active', 'completed' )" );
		$params     = array();
		if ( $course_ids ) {
			$where[] = 'course_id IN (' . implode( ',', array_fill( 0, count( $course_ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $course_ids );
		}
		if ( null !== $after ) {
			$where[]  = 'user_id > %d';
			$params[] = $after;
		}
		$sql = 'SELECT DISTINCT user_id FROM ' . static::table() . ' WHERE ' . implode( ' AND ', $where );
		if ( null !== $after ) {
			$sql .= ' ORDER BY user_id ASC';
		}
		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = $limit;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE fragments are internal; all values bound via prepare().
		$ids = $params
			? static::db()->get_col( static::db()->prepare( $sql, ...$params ) )
			: static::db()->get_col( $sql );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * User IDs who are currently active OR completed on a course. Thin wrapper
	 * over enrolled_user_ids().
	 *
	 * Used by community bridges (BuddyNext profile feed, Jetonomy forum
	 * membership) — they want everyone who's earned a seat, not just the active
	 * subset. KEEP this public name/signature: it is an external bridge API.
	 */
	public static function get_enrolled_user_ids_for_course( int $course_id ): array {
		return static::enrolled_user_ids( array( $course_id ) );
	}

	/**
	 * Keyset-paginated sibling of {@see get_enrolled_user_ids_for_course()}:
	 * the next page of DISTINCT enrolled (active|completed) user_ids for a
	 * course whose user_id is greater than `$after_user_id`, ordered ascending.
	 *
	 * The unbounded variant loads every enrolled user into PHP in one shot,
	 * which breaks any caller that then fans out an external call per user
	 * (the Jetonomy forum-join bridge). This bounds the window so the fan-out
	 * can be chunked over Action Scheduler: callers pass the last user_id of
	 * the previous page and loop until an empty result. Because user_id is the
	 * cursor (stable, ascending), a retried/replayed batch processes the exact
	 * same window.
	 *
	 * @param int $course_id     Course ID.
	 * @param int $after_user_id Cursor: return user_ids strictly greater than this.
	 * @param int $limit         Page size.
	 * @return int[] Ascending distinct user_ids, at most `$limit` of them.
	 */
	public static function enrolled_user_ids_for_course_after( int $course_id, int $after_user_id = 0, int $limit = 500 ): array {
		return static::enrolled_user_ids( array( $course_id ), $after_user_id, $limit );
	}

	/**
	 * Cursor-paginated iteration for batch back-fills. Returns the next
	 * page of rows after `$after_id`, ordered by id ASC. Callers loop
	 * until an empty result. Avoids OFFSET which dies past page 1000.
	 */
	public static function page_after( int $after_id, int $limit = 500 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table()
					. ' WHERE id > %d ORDER BY id ASC LIMIT %d',
				$after_id,
				$limit
			)
		) ?: [];
	}

	/**
	 * Distinct user_ids of students whose enrollment is currently live —
	 * `active` (still working through the course) OR `completed` (finished
	 * but still enrolled). Cancelled / expired rows are excluded.
	 *
	 * Used for announcement fan-out + admin "View All Students" listing —
	 * those callers want everyone who can still see the course, not just
	 * the ones mid-lesson. The prior contract returned only `active` rows
	 * which silently hid every completed student from "View All Students"
	 * (Basecamp #9932098717) and broadcast announcements (#9932090711).
	 */
	public static function get_active_user_ids( int $course_id = 0 ): array {
		$table = static::table();

		// Single-course path is naturally bounded by the course roster — one
		// query via the canonical builder.
		if ( $course_id > 0 ) {
			return static::enrolled_user_ids( array( $course_id ) );
		}

		// Site-wide fan-out (course_id = 0) is a full-table DISTINCT scan — at
		// 100k+ enrollments the flat result OOMs. Batch it (USER_ID_BATCH per
		// query) up to USER_ID_HARD_CAP recipients so a single broadcast request
		// stays bounded (Basecamp #9961949958).
		$all    = array();
		$offset = 0;

		do {
			$batch       = array_map(
				'intval',
				(array) static::db()->get_col(
					static::db()->prepare(
						"SELECT DISTINCT user_id FROM {$table} WHERE status IN ( 'active', 'completed' )
						 ORDER BY user_id ASC LIMIT %d OFFSET %d",
						static::USER_ID_BATCH,
						$offset
					)
				)
			);
			$batch_count = count( $batch );
			foreach ( $batch as $id ) {
				$all[] = $id;
			}
			$offset += static::USER_ID_BATCH;
			$total   = count( $all );
		} while ( static::USER_ID_BATCH === $batch_count && $total < static::USER_ID_HARD_CAP );

		if ( count( $all ) > static::USER_ID_HARD_CAP ) {
			$all = array_slice( $all, 0, static::USER_ID_HARD_CAP );
		}

		return $all;
	}

	/**
	 * Students at risk inside the given instructor's courses — active
	 * enrollments where the LATEST progress event is older than
	 * $days_inactive days (or no progress event exists at all). Used by
	 * the instructor-dashboard "Students at Risk" widget.
	 *
	 * Bounds the candidate set to the instructor's authored courses
	 * BEFORE the progress lookup via CourseInstructor — keeps the
	 * subquery cardinality small even on busy sites.
	 *
	 * @return object[] Rows: user_id, course_id, enrolled_at, last_activity,
	 *                  student_display_name, course_title.
	 */
	public static function at_risk_for_instructor( int $instructor_id, int $days_inactive = 14, int $limit = 5 ): array {
		if ( $instructor_id <= 0 ) {
			return array();
		}
		if ( ! class_exists( '\\Learnomy\\Models\\CourseInstructor' ) ) {
			return array();
		}
		$ci_rows    = \Learnomy\Models\CourseInstructor::get_for_user( $instructor_id );
		$course_ids = array_map( static fn( $r ) => (int) $r->course_id, $ci_rows );
		if ( empty( $course_ids ) ) {
			return array();
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );

		// Canonical shape (inactive_pairs_sql) — the widget, the alert count
		// and the roster filter all derive from the same query, which is the
		// whole point of Basecamp 10127571047: this list used to measure
		// activity SITE-WIDE via a MAX over lrn_progress grouped by user
		// only, so a student busy in another instructor's course read as
		// active here, and it disagreed with the alert count by 5-7 people
		// on a single site. The per-course signal is e.last_accessed_at on
		// the enrollment row itself (written by Progress_Service on every
		// progress event) — no progress-table join at all now, and
		// `last_activity IS NULL` still means "never opened anything".
		//
		// GROUP BY (user_id, course_id) is preserved: a learner holding one
		// course through a purchase AND a Space seat must not burn two of
		// the widget's five slots (Basecamp 10144356243).
		$pairs = self::inactive_pairs_sql( $placeholders );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT x.* FROM ( {$pairs} ) x
				 ORDER BY ( x.last_activity IS NULL ) DESC, x.last_activity ASC
				 LIMIT %d",
				...array_merge( $course_ids, array( $days_inactive, $limit ) )
			)
		);
		if ( empty( $rows ) ) {
			return array();
		}

		// Hydrate course titles in ONE batched lookup (id => row map) instead
		// of a Course::find() per authored course. find_in() selects every
		// status (matching the prior find() behavior), so titles are identical.
		$course_titles = array();
		if ( class_exists( '\\Learnomy\\Models\\Course' ) ) {
			foreach ( \Learnomy\Models\Course::find_in( $course_ids ) as $cid => $course ) {
				$course_titles[ (int) $cid ] = (string) $course->title;
			}
		}

		// Prime the WP user cache in ONE query, then read each display name from
		// cache instead of issuing a get_user_by() lookup per at-risk row.
		$at_risk_user_ids = array_values( array_unique( array_map( static fn( $r ) => (int) $r->user_id, $rows ) ) );
		if ( ! empty( $at_risk_user_ids ) ) {
			cache_users( $at_risk_user_ids );
		}
		foreach ( $rows as $row ) {
			$row->course_title         = $course_titles[ (int) $row->course_id ] ?? '';
			$user                      = get_user_by( 'id', (int) $row->user_id );
			$row->student_display_name = $user ? (string) $user->display_name : __( 'Student', 'learnomy' );
		}
		return $rows;
	}

	/**
	 * THE canonical "how many people" count. `COUNT(DISTINCT user_id)` over the
	 * enrollments table, optionally scoped to a course set and a status set.
	 *
	 * Always use this instead of `COUNT(*)` for a student count: the unique key is
	 * (user_id, course_id, source, path_id), so one person can hold several rows
	 * for a course (e.g. a direct enrolment AND a Space/membership seat) and
	 * `COUNT(*)` double-counts them. That divergence was the root of the roster
	 * (#40982), student_count (10109120366) and funnel over-counts.
	 *
	 * Cached under the enrollments incrementor group, so any enrollment write
	 * (which bumps the group via invalidate_cache / Rule 14) orphans it. These
	 * counts are hot — catalog, instructor dashboard, admin, REST — and were
	 * previously an uncached live query on every render.
	 *
	 * @param int[]    $course_ids Empty = platform-wide.
	 * @param string[] $statuses   Empty = every status. Default = a "live seat" (active + completed).
	 */
	public static function count_distinct_students( array $course_ids = array(), array $statuses = array( 'active', 'completed' ) ): int {
		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		$statuses   = array_values( array_filter( array_map( 'strval', $statuses ) ) );

		$group  = static::cache_group();
		$key    = 'count_students_' . md5( (string) wp_json_encode( array( $course_ids, $statuses ) ) . '|' . wp_cache_get_last_changed( $group ) );
		$found  = false;
		$cached = wp_cache_get( $key, $group, false, $found );
		if ( $found ) {
			return (int) $cached;
		}

		$where  = array();
		$params = array();
		if ( $course_ids ) {
			$where[] = 'course_id IN (' . implode( ',', array_fill( 0, count( $course_ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $course_ids );
		}
		if ( $statuses ) {
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params  = array_merge( $params, $statuses );
		}
		$sql = 'SELECT COUNT(DISTINCT user_id) FROM ' . static::table();
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- WHERE fragments are internal; all values are bound via prepare().
		$count = $params
			? (int) static::db()->get_var( static::db()->prepare( $sql, ...$params ) )
			: (int) static::db()->get_var( $sql );

		wp_cache_set( $key, $count, $group, 300 );
		return $count;
	}

	/**
	 * Does this user hold ANOTHER active/completed enrolment row in the course,
	 * besides the row identified by $exclude_id? Powers the distinct-aware
	 * student_count deltas — increment on a user's FIRST live row, decrement on
	 * their LAST — so a member holding a course through two sources counts as one
	 * student, not two (Basecamp 10109120366).
	 */
	public static function user_has_other_active_row( int $user_id, int $course_id, int $exclude_id = 0 ): bool {
		return (bool) static::db()->get_var(
			static::db()->prepare(
				'SELECT 1 FROM ' . static::table()
					. " WHERE user_id = %d AND course_id = %d AND id <> %d AND status IN ( 'active', 'completed' ) LIMIT 1",
				$user_id,
				$course_id,
				$exclude_id
			)
		);
	}

	/**
	 * Distinct active/completed users across multiple courses. Thin wrapper over
	 * the canonical count_distinct_students() — kept as a named pre-flight for
	 * broadcast callers (fail fast before loading the full user-id array).
	 *
	 * @param int[] $course_ids
	 */
	public static function count_active_for_courses( array $course_ids ): int {
		return static::count_distinct_students( $course_ids );
	}

	/**
	 * Distinct user_ids enrolled across multiple courses — used by scoped
	 * broadcasts (an instructor sending to "everyone in any of MY courses").
	 *
	 * @param int[] $course_ids
	 * @return int[]
	 */
	public static function get_active_user_ids_for_courses( array $course_ids ): array {
		return static::enrolled_user_ids( $course_ids );
	}

	/**
	 * Expire all active / completed membership-sourced enrollments for a
	 * user + plan and decrement the parent course's denormalized
	 * `student_count` once per row that actually left an active state.
	 *
	 * A row counts toward `student_count` while it is `active` OR `completed`
	 * (completion never decrements the live counter), so the set that loses a
	 * student on expiry is exactly `status IN ('active','completed')`. We read
	 * that set first (grouped by course), flip it to `expired`, then decrement
	 * each course by the number of rows that moved. Rows already `expired` /
	 * `cancelled` are excluded by the WHERE, so re-processing the same plan is
	 * a no-op for the counter — no double-decrement, and the GREATEST(...,0)
	 * clamp in increment_student_count() keeps it from ever going negative.
	 */
	public static function expire_for_plan( int $user_id, int $plan_id ): void {
		$table = static::table();

		// Which courses this expiry touches. Read BEFORE the UPDATE (the predicate
		// still matches). We only need the course ids, not a row count: the
		// counter is recomputed per touched course from COUNT(DISTINCT user_id)
		// after the flip, so a member who keeps a direct/Space row in the same
		// course is not decremented (Basecamp 10109120366). A hand-computed
		// per-row delta cannot express "distinct users who fully left".
		$touched = array_map(
			'intval',
			(array) static::db()->get_col(
				static::db()->prepare(
					"SELECT DISTINCT course_id FROM {$table}
					 WHERE user_id = %d AND plan_id = %d AND source = 'membership'
					   AND status IN ( 'active', 'completed' )",
					$user_id,
					$plan_id
				)
			)
		);

		if ( empty( $touched ) ) {
			return;
		}

		static::db()->query(
			static::db()->prepare(
				"UPDATE {$table} SET status = 'expired'
				 WHERE user_id = %d AND plan_id = %d AND source = 'membership'
				   AND status IN ( 'active', 'completed' )",
				$user_id,
				$plan_id
			)
		);

		Course::recompute_student_count_for_courses( $touched );
		static::invalidate_cache();
	}

	/**
	 * Expire a single membership-sourced course enrollment for a user/plan.
	 *
	 * The plan-wide {@see expire_for_plan()} flips EVERY course on the plan; on a
	 * plan change only the courses dropped from the new plan should expire, while
	 * courses carried over to the new plan must stay active. This scopes the same
	 * status flip + student_count decrement to one course so the reconciler can
	 * call it per dropped course without touching the retained ones.
	 *
	 * @param int $user_id   Subscriber user ID.
	 * @param int $plan_id   The plan the enrollment was sourced from.
	 * @param int $course_id The single course to expire.
	 */
	public static function expire_for_plan_course( int $user_id, int $plan_id, int $course_id ): void {
		$table = static::table();

		$leaving = static::db()->get_var(
			static::db()->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE user_id = %d AND plan_id = %d AND course_id = %d AND source = 'membership'
				   AND status IN ( 'active', 'completed' )",
				$user_id,
				$plan_id,
				$course_id
			)
		);

		if ( ! $leaving ) {
			return;
		}

		static::db()->query(
			static::db()->prepare(
				"UPDATE {$table} SET status = 'expired'
				 WHERE user_id = %d AND plan_id = %d AND course_id = %d AND source = 'membership'
				   AND status IN ( 'active', 'completed' )",
				$user_id,
				$plan_id,
				$course_id
			)
		);

		// Recompute this course from COUNT(DISTINCT user_id) rather than
		// decrementing by the row count: if the user still holds a direct/Space
		// row here they remain one counted student (Basecamp 10109120366).
		Course::recompute_student_count_for_courses( array( $course_id ) );
		static::invalidate_cache();
	}

	/**
	 * Expire a user's membership-sourced enrollments for a specific set of
	 * courses, regardless of which plan_id the enrollment row recorded.
	 *
	 * Cancellation can't safely key off the enrollment's stored plan_id: a row
	 * that was created without it (legacy data, or any path that forgot to
	 * stamp it) would be orphaned and never revoked, leaving the course
	 * accessible after the membership ends. The caller resolves the courses a
	 * cancelled plan actually granted from the live plan->course map and the
	 * post-cancel access check, then hands them here to expire by course. This
	 * is the source-of-truth-correct counterpart to {@see expire_for_plan()}.
	 *
	 * @param int   $user_id    Subscriber user ID.
	 * @param int[] $course_ids Courses whose membership enrollment to expire.
	 */
	public static function expire_membership_for_courses( int $user_id, array $course_ids ): void {
		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		if ( empty( $course_ids ) ) {
			return;
		}

		$table        = static::table();
		$placeholders = implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) );

		// Courses actually touched (read BEFORE the flip). Course ids only — the
		// counter is recomputed per touched course from COUNT(DISTINCT user_id)
		// after the flip, so a member who keeps a direct/Space row in the same
		// course is not decremented (Basecamp 10109120366).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$touched = array_map(
			'intval',
			(array) static::db()->get_col(
				static::db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT DISTINCT course_id FROM {$table}
					 WHERE user_id = %d AND source = 'membership'
					   AND status IN ( 'active', 'completed' )
					   AND course_id IN ( {$placeholders} )",
					array_merge( array( $user_id ), $course_ids )
				)
			)
		);

		if ( empty( $touched ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET status = 'expired'
				 WHERE user_id = %d AND source = 'membership'
				   AND status IN ( 'active', 'completed' )
				   AND course_id IN ( {$placeholders} )",
				array_merge( array( $user_id ), $course_ids )
			)
		);

		Course::recompute_student_count_for_courses( $touched );
		static::invalidate_cache();
	}

	/**
	 * Override base delete() to cascade dependent rows + maintain the
	 * denormalized student_count on the parent course.
	 *
	 * Why: `Progress`, `QuizAttempt`, and `lrn_courses.student_count` are
	 * not maintained by MySQL FK cascades (the schema doesn't declare any),
	 * so every code path that physically removes an enrollment row must
	 * also clear the (user, course)-keyed dependents and decrement the
	 * counter. Centralising that here means demo-seeder, fixture-builder,
	 * Privacy erasure, REST cleanup, and any future caller inherit the
	 * behaviour automatically.
	 *
	 * Note: `unenroll()` (status='cancelled') is the soft-delete path used
	 * by real users and intentionally preserves progress so re-enroll
	 * picks up where the student left off. This override is the
	 * hard-delete path — fixture teardown, GDPR erasure, admin purge.
	 */
	public static function delete( int $id ): bool {
		$row = static::find( $id );
		if ( ! $row ) {
			return false;
		}

		$user_id   = (int) $row->user_id;
		$course_id = (int) $row->course_id;

		// A row counts toward student_count while active OR completed — same
		// predicate as recompute_student_counts(). The old gate checked only
		// 'active', so hard-deleting a COMPLETED enrollment left the counter too
		// high until the next recompute. And it must be distinct-aware: only
		// decrement when this was the user's LAST live row in the course, else a
		// member who keeps a direct/Space row would be wrongly uncounted
		// (Basecamp 10109120366). Probe BEFORE the delete so the prior state shows.
		$was_live      = in_array( (string) ( $row->status ?? '' ), array( 'active', 'completed' ), true );
		$last_live_row = $was_live && ! static::user_has_other_active_row( $user_id, $course_id, $id );

		Progress::delete_for_user_course( $user_id, $course_id );
		QuizAttempt::delete_for_user_course( $user_id, $course_id );

		$ok = parent::delete( $id );

		if ( $ok && $last_live_row ) {
			Course::increment_student_count( $course_id, -1 );
		}

		return $ok;
	}

	/**
	 * Paginated list of distinct user_ids that have at least one
	 * enrollment row. Optional `$course_id` narrows to one course's
	 * roster; optional `$search` matches wp_users display_name /
	 * user_login / user_email.
	 *
	 * Used by the admin Students page. The prior implementation queried
	 * `WP_User_Query` with `role='lrn_student'` first, which silently
	 * dropped enrolled users who didn't carry the role (admin-manual
	 * enrollments, purchase guests, demo seeds) — Basecamp #9932098717.
	 *
	 * @return array{user_ids: int[], total: int}
	 */
	/**
	 * Every distinct enrolled user id (no pagination) — for the admin Students
	 * CSV export, so it mirrors the enrollment-driven list instead of
	 * `role='lrn_student'` (which silently dropped enrolled users without the
	 * role — admin-manual / purchase-guest / demo enrollments). Basecamp #9958305530.
	 *
	 * Batched internally (USER_ID_BATCH per query) and accumulated up to
	 * USER_ID_HARD_CAP distinct ids so a single request never loads the whole
	 * enrollments table into one query result — guards the in-request students
	 * CSV export against OOM on 100k+ enrollment sites (Basecamp #9961949958).
	 * Callers that need an unbounded walk (offline CLI export) should page with
	 * get_distinct_user_ids_page() instead.
	 *
	 * @return int[]
	 */
	public static function get_all_distinct_user_ids(): array {
		$all    = array();
		$offset = 0;

		do {
			$batch       = static::get_distinct_user_ids_page( static::USER_ID_BATCH, $offset );
			$batch_count = count( $batch );
			foreach ( $batch as $id ) {
				$all[] = $id;
			}
			$offset += static::USER_ID_BATCH;
			$total   = count( $all );
		} while ( static::USER_ID_BATCH === $batch_count && $total < static::USER_ID_HARD_CAP );

		if ( count( $all ) > static::USER_ID_HARD_CAP ) {
			$all = array_slice( $all, 0, static::USER_ID_HARD_CAP );
		}

		return $all;
	}

	/**
	 * One bounded page of distinct enrolled user ids (`user_id <> 0`), ordered
	 * by user_id ASC so the cursor is stable across pages. Callers loop with an
	 * increasing `$offset` until a short page comes back. This is the batching
	 * primitive behind get_all_distinct_user_ids() and the CLI students export.
	 *
	 * @return int[]
	 */
	public static function get_distinct_user_ids_page( int $limit = self::USER_ID_BATCH, int $offset = 0 ): array {
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		return array_map(
			'intval',
			(array) static::db()->get_col(
				static::db()->prepare(
					'SELECT DISTINCT user_id FROM ' . static::table()
						. ' WHERE user_id <> 0 ORDER BY user_id ASC LIMIT %d OFFSET %d',
					$limit,
					$offset
				)
			)
		);
	}

	public static function get_distinct_user_ids_paginated( int $course_id = 0, int $per_page = 20, int $offset = 0, string $search = '', ?array $scope_course_ids = null, int $days_inactive = 0 ): array {
		global $wpdb;
		$table       = static::table();
		$users_table = $wpdb->users;

		$where  = array( 'e.user_id <> 0' );
		$params = array();
		$join   = '';

		if ( $course_id > 0 ) {
			$where[]  = 'e.course_id = %d';
			$params[] = $course_id;
		}

		// Optional scope to a SET of courses — the admin Students page passes a
		// non-admin instructor's own courses (author + co) so they only see THEIR
		// students, not the whole site. An empty scope means "no courses" → no
		// students (returned early below).
		if ( is_array( $scope_course_ids ) ) {
			$scope_ids = array_values( array_unique( array_filter( array_map( 'intval', $scope_course_ids ) ) ) );
			if ( empty( $scope_ids ) ) {
				return array(
					'user_ids' => array(),
					'total'    => 0,
				);
			}
			$placeholders = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
			$where[]      = "e.course_id IN ({$placeholders})";
			foreach ( $scope_ids as $scope_id ) {
				$params[] = $scope_id;
			}
		}

		if ( '' !== $search ) {
			$join     = "INNER JOIN {$users_table} u ON u.ID = e.user_id";
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// Inactive filter — the roster leg of the canonical definition
		// (inactive_pairs_sql), so the Engagement Alerts count, the at-risk
		// widget and this filtered roster can never disagree about who is
		// quiet (Basecamp 10127571047). The pair set inherits whatever course
		// scope this call already carries; a single course narrows it, an
		// instructor scope bounds it, an admin with neither filters site-wide.
		if ( $days_inactive > 0 ) {
			$inactive_in = '';
			if ( $course_id > 0 ) {
				$inactive_in = '%d';
				$params[]    = $course_id;
			} elseif ( isset( $scope_ids ) && is_array( $scope_course_ids ) ) {
				$inactive_in = implode( ',', array_fill( 0, count( $scope_ids ), '%d' ) );
				foreach ( $scope_ids as $scope_id ) {
					$params[] = $scope_id;
				}
			}
			$pairs    = self::inactive_pairs_sql( $inactive_in );
			$where[]  = "e.user_id IN ( SELECT x.user_id FROM ( {$pairs} ) x )";
			$params[] = $days_inactive;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$count_sql = "SELECT COUNT( DISTINCT e.user_id ) FROM {$table} e {$join} {$where_sql}";
		$total     = (int) static::db()->get_var(
			$params ? static::db()->prepare( $count_sql, ...$params ) : $count_sql // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$list_sql = "SELECT e.user_id FROM {$table} e {$join} {$where_sql}
		             GROUP BY e.user_id
		             ORDER BY MAX( e.enrolled_at ) DESC
		             LIMIT %d OFFSET %d";

		$paged_params = array_merge( $params, array( $per_page, $offset ) );
		$ids          = (array) static::db()->get_col(
			static::db()->prepare( $list_sql, ...$paged_params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return array(
			'user_ids' => array_map( 'intval', $ids ),
			'total'    => $total,
		);
	}

	/**
	 * Deletes all enrollment rows for a user — used during GDPR erasure.
	 *
	 * Iterates so the per-row `delete()` override fires for each — keeps
	 * progress + counters in sync.
	 */
	public static function delete_for_user( int $user_id ): int {
		$ids     = (array) static::db()->get_col(
			static::db()->prepare( 'SELECT id FROM ' . static::table() . ' WHERE user_id = %d', $user_id )
		);
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( static::delete( (int) $id ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Deletes all enrollment rows for a course — used by CLI fixture
	 * teardown + course hard-delete cascade.
	 *
	 * Iterates so the per-row `delete()` override fires for each.
	 */
	public static function delete_for_course( int $course_id ): int {
		$ids     = (array) static::db()->get_col(
			static::db()->prepare( 'SELECT id FROM ' . static::table() . ' WHERE course_id = %d', $course_id )
		);
		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( static::delete( (int) $id ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Count of distinct enrolled students (active + completed) for a course.
	 *
	 * Used by the CLI drip-preview command to show how many students will
	 * receive an unlock notification when a lesson becomes available.
	 */
	public static function count_active_for_course( int $course_id ): int {
		return static::count_distinct_students( array( $course_id ) );
	}

	/**
	 * Count of paying enrolments (purchase + subscription) for a course that
	 * are not cancelled / expired / pending. Drives the pricing-model lock on
	 * the course edit screen: once real money has come in for a course, its
	 * pricing model can't be changed out from under those buyers.
	 */
	public static function count_paying_for_course( int $course_id ): int {
		// DISTINCT user_id: a learner holding both a purchase AND a subscription
		// row on one course is one paying person, not two.
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(DISTINCT user_id) FROM ' . static::table() . " WHERE course_id = %d AND source IN ( 'purchase', 'subscription' ) AND status NOT IN ( 'cancelled', 'expired', 'pending' )",
				$course_id
			)
		);
	}

	/**
	 * Fixed-term enrolments for a user: those with a non-null expires_at that
	 * are active or expired, oldest-expiry first. Powers the time-limited
	 * access list + countdown chips on the account billing page.
	 *
	 * @return object[] Rows of { id, course_id, status, enrolled_at, expires_at }.
	 */
	public static function time_limited_for_user( int $user_id ): array {
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT id, course_id, status, enrolled_at, expires_at FROM ' . static::table() . " WHERE user_id = %d AND expires_at IS NOT NULL AND status IN ( 'active', 'expired' ) ORDER BY expires_at ASC",
				$user_id
			)
		);
		return $rows ?: array();
	}

	public static function mark_completed( int $id ): bool {
		// `status` and `progress_pct` are two independent concepts:
		// * `status='completed'` → the student has satisfied the course
		// completion rule (could be all-lessons, percentage-threshold,
		// lessons+quiz, or manual admin mark).
		// * `progress_pct` → the ratio of lessons the student has
		// actually covered.
		//
		// For a `completion_type=percentage` course with
		// `completion_value=33`, hitting 1 of 3 lessons satisfies the
		// rule. Status flips to `completed` but progress_pct stays at 33
		// — UI shows "33% complete" with the Completed badge separately.
		//
		// Forcing progress_pct=100 here misrepresented partial-coverage
		// completions (Basecamp #9928225031 follow-up). Now we only
		// flip the status + stamp completed_at; the calculated
		// progress_pct from the upstream `calculate_course_progress()`
		// call stays intact.
		//
		// Written to EVERY row the student holds for this course, for the same
		// reason as set_progress_for_user_course() below: "has finished this
		// course" is a (user, course) fact stored on a (user, course, source)
		// grain. Flipping only the row the caller happened to resolve left the
		// sibling row reading `active` / not-completed, and every surface that
		// judges completion from its OWN row lookup then disagreed with the one
		// that wrote (Basecamp 10134140444). Terminal rows (cancelled, expired,
		// refunded) are left alone — a revoked enrollment does not become a
		// completed one — but the caller's own row is always included so an
		// explicit admin mark on a non-active row still lands.
		$row = static::find( $id );
		if ( ! $row ) {
			return false;
		}

		$table = static::table();
		$ok    = static::db()->query(
			static::db()->prepare(
				"UPDATE {$table}
				 SET status = 'completed', completed_at = %s
				 WHERE user_id = %d AND course_id = %d AND ( id = %d OR status = 'active' )",
				now(),
				(int) $row->user_id,
				(int) $row->course_id,
				$id
			)
		);
		if ( false !== $ok ) {
			static::invalidate_cache();
		}
		return false !== $ok;
	}

	/**
	 * Write the denormalized course progress onto EVERY enrollment row the
	 * student holds for this course.
	 *
	 * `progress_pct` answers "how far through this course is this student",
	 * which is a (user, course) fact — but the table's grain is
	 * (user, course, **source**), so a student who holds both a purchase and a
	 * membership/space enrollment has two rows carrying the same answer. While
	 * the writers updated one row and the readers resolved another, the two
	 * drifted apart: the write path picked the row `find_active()` returned
	 * (no ORDER BY at all), the dashboard read the row `get_distinct_for_user()`
	 * kept (argmax on enrolled_at, id), and the cron recompute used a third rule
	 * (`find_for_user_course()`, enrolled_at DESC). Completing a lesson wrote 24%
	 * to one row while My Courses rendered the other row's stale 10%
	 * (Basecamp 10134139787).
	 *
	 * Keeping every row in step removes the question instead of answering it:
	 * whichever row a reader resolves, it carries the same number. One UPDATE,
	 * served by the `(user_id, course_id)` index, so this costs the same as the
	 * single-row write it replaces.
	 *
	 * @param int $user_id   Student.
	 * @param int $course_id Course.
	 * @param int $pct       Progress percentage, clamped to 0-100.
	 * @return int Number of rows actually changed.
	 */
	public static function set_progress_for_user_course( int $user_id, int $course_id, int $pct ): int {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return 0;
		}

		$pct   = max( 0, min( 100, $pct ) );
		$table = static::table();
		$count = (int) static::db()->query(
			static::db()->prepare(
				"UPDATE {$table} SET progress_pct = %d
				 WHERE user_id = %d AND course_id = %d AND progress_pct <> %d",
				$pct,
				$user_id,
				$course_id,
				$pct
			)
		);
		if ( $count > 0 ) {
			static::invalidate_cache();
		}
		return $count;
	}

	/**
	 * Reactivate a previously cancelled / expired enrollment row in place.
	 *
	 * The `lrn_enrollments` UNIQUE key is `(user_id, course_id, source)`, so a
	 * student who cancels a course-level recurring subscription and then
	 * re-subscribes cannot get a fresh INSERT — the old terminal-state row
	 * collides with the unique key and `$wpdb->insert()` fails silently
	 * (Basecamp #9961889384). Re-subscription must therefore RESET the existing
	 * row rather than insert a new one: flip status back to `active`, stamp a
	 * fresh `enrolled_at`, reset progress, and clear the completion / expiry /
	 * reminder timestamps so the row behaves like a brand-new enrollment.
	 *
	 * `plan_id`, `subscription_id`, and `expires_at` are passed through from the
	 * new enrollment context (a re-subscribe links a new subscription row; a
	 * fixed-term re-enroll gets a new expiry), and any of the three can legitimately
	 * be NULL — a course-level subscription has no plan, a non-fixed-term course has
	 * no expiry.
	 *
	 * **Written through `update()` — never a hand-rolled `prepare()`.** This method
	 * used to build the SET clause itself, on the belief that `wpdb->update()`
	 * coerces PHP null to "". It does not: `wpdb::update()` emits a literal `NULL`
	 * for a null value. `wpdb::prepare()` is the one that coerces — `%s` renders
	 * null as `''`, `%d` as `0` — and because WordPress deliberately strips
	 * `STRICT_TRANS_TABLES` / `NO_ZERO_DATE` from the session sql_mode
	 * (`wpdb::set_sql_mode()`), MySQL then stored `expires_at = ''` as the
	 * zero-date `0000-00-00 00:00:00` instead of SQL NULL. A zero-date is
	 * `IS NOT NULL` and sorts before every real timestamp, so
	 * {@see self::get_expired()} matched the freshly reactivated row and the next
	 * `Cron::expire_enrollments()` tick revoked a subscriber who had just paid
	 * (Basecamp 10134140255 / 10134140217). `plan_id` / `subscription_id` landed as
	 * the `0` sentinel for the same reason. The workaround caused precisely the
	 * NULL-predicate breakage it was written to avoid.
	 *
	 * @param int         $id              Enrollment row id to reactivate.
	 * @param int|null    $plan_id         Membership plan link (NULL for course subs / free).
	 * @param int|null    $subscription_id Course-level subscription link (NULL when none).
	 * @param string|null $expires_at      Fixed-term expiry (NULL clears any prior expiry).
	 */
	public static function reactivate( int $id, ?int $plan_id = null, ?int $subscription_id = null, ?string $expires_at = null ): bool {
		return static::update(
			$id,
			array(
				'status'           => 'active',
				'progress_pct'     => 0,
				'enrolled_at'      => now(),
				'completed_at'     => null,
				'reminder_sent_at' => null,
				'plan_id'          => null === $plan_id ? null : (int) $plan_id,
				'subscription_id'  => null === $subscription_id ? null : (int) $subscription_id,
				'expires_at'       => ( null === $expires_at || '' === $expires_at ) ? null : $expires_at,
			)
		);
	}

	/**
	 * Reopens a completed enrollment for a retake. Status goes back to
	 * `active`, `completed_at` is cleared (NULL), and `progress_pct` is
	 * reset to `$progress_pct` — callers pass 0 when `reset_on_retake` is
	 * true or leave the existing value otherwise.
	 *
	 * The raw UPDATE spells `completed_at = NULL` as a literal SQL token, which is
	 * the only safe way to null a column from a hand-built statement: a `%s`
	 * placeholder fed a PHP null renders as `''`, and MySQL stores that in a
	 * datetime column as the zero-date (see {@see self::reactivate()} for the bug
	 * that caused). `wpdb->update()` is also safe — it emits a literal NULL — so
	 * either shape works; `prepare()` with a null argument never does.
	 */
	public static function reopen( int $id, int $progress_pct = 0 ): bool {
		$table = static::table();
		$ok    = static::db()->query(
			static::db()->prepare(
				"UPDATE {$table} SET status = %s, completed_at = NULL, progress_pct = %d WHERE id = %d",
				'active',
				$progress_pct,
				$id
			)
		);
		return false !== $ok;
	}

	/**
	 * Reset every enrollment row for a user on one course back to a fresh,
	 * in-progress state: `status='active'`, `progress_pct=0`, `completed_at=NULL`.
	 *
	 * Pairs with {@see \Learnomy\Models\Progress::reset_for_course()} so the admin
	 * "Reset progress" action wipes the denormalized enrollment summary as well as
	 * the per-lesson rows — otherwise the enrollments table keeps showing "100%"
	 * and the Completed badge after the lessons have been wiped (Basecamp
	 * #10042843844). A user can hold several rows for one course (different
	 * `source` values, e.g. purchase + membership), so this resets ALL of them in
	 * one statement rather than the newest.
	 *
	 * One raw UPDATE because this is a multi-row write keyed on (user, course), not
	 * on an id — `wpdb->update()` cannot express it. `completed_at = NULL` is a
	 * literal SQL token, never a `%s` placeholder fed a PHP null (that renders as
	 * `''` and lands as the zero-date — see {@see self::reactivate()}). The
	 * `enrolled_at`, `source`, `plan_id`, `subscription_id`, and `expires_at` links
	 * are intentionally left intact — this resets progress, it does not unenroll.
	 *
	 * @return int Number of enrollment rows reset.
	 */
	public static function reset_for_user_course( int $user_id, int $course_id ): int {
		$table = static::table();
		$count = (int) static::db()->query(
			static::db()->prepare(
				"UPDATE {$table}
				 SET status = 'active',
				     progress_pct = 0,
				     completed_at = NULL
				 WHERE user_id = %d AND course_id = %d",
				$user_id,
				$course_id
			)
		);
		if ( $count > 0 ) {
			static::invalidate_cache();
		}
		return $count;
	}

	/**
	 * Null out the `cohort_id` stamp on every enrollment tied to a cohort.
	 *
	 * Called from Pro's `Cohort_Service::delete()` so deleting a cohort leaves
	 * no orphaned `cohort_id` references on `lrn_enrollments`. The enrollment
	 * itself is kept (the learner stays enrolled in the course) — only the
	 * cohort link is cleared. Raw UPDATE because `wpdb->update()` coerces PHP
	 * null to "" / the zero-date on some DB versions, which would defeat the
	 * `cohort_id IS NULL` checks. Returns rows cleared.
	 *
	 * @param int $cohort_id The deleted cohort's id.
	 */
	public static function clear_cohort( int $cohort_id ): int {
		if ( $cohort_id <= 0 ) {
			return 0;
		}
		$table = static::table();
		$ok    = static::db()->query(
			static::db()->prepare(
				"UPDATE {$table} SET cohort_id = NULL WHERE cohort_id = %d",
				$cohort_id
			)
		);
		return false === $ok ? 0 : (int) static::db()->rows_affected;
	}

	/**
	 * Delete the path-level enrollment rows for a deleted learning path.
	 *
	 * Called from Pro's `Path_Service::delete()` so deleting a learning path
	 * leaves no orphaned `path_id` references on `lrn_enrollments`. Mirrors
	 * the `clear_cohort()` cascade, but DELETES instead of nulling: a cohort
	 * link is a stamp on an otherwise-valid course enrollment, whereas a
	 * path-level row exists ONLY to represent the path enrollment
	 * (`course_id = 0`, `path_id` set — the convention `Path_Service::enroll()`
	 * writes). Once the path is gone that row is dead data; it can never become
	 * `find_active_path_enrollment()`-reachable again, yet `get_user_active_paths()`
	 * (which filters on `path_id > 0 AND status = 'active'` with no join to
	 * `lrn_pro_learning_paths`) would keep returning it as a phantom path on
	 * the student's account.
	 *
	 * Scoped to `course_id = 0` so the independent course-step enrollments
	 * `enroll()` also created (real course rows, `path_id` 0) are never
	 * touched — a course can sit in several paths or be enrolled standalone, so
	 * the learner keeps that course access. Single bulk DELETE rather than
	 * per-row `delete()` to avoid an N+1 and the course-counter side effects
	 * that hard-delete carries (those rows have `course_id = 0`). Returns rows
	 * removed.
	 *
	 * @param int $path_id The deleted learning path's id.
	 */
	public static function clear_path( int $path_id ): int {
		if ( $path_id <= 0 ) {
			return 0;
		}
		$table = static::table();
		$ok    = static::db()->query(
			static::db()->prepare(
				"DELETE FROM {$table} WHERE path_id = %d AND course_id = 0",
				$path_id
			)
		);
		return false === $ok ? 0 : (int) static::db()->rows_affected;
	}

	/**
	 * Active enrollments whose expires_at has passed.
	 *
	 * Returns at most $limit rows, ordered oldest-first by `id`, starting after
	 * the `$after_id` cursor. The cron handler walks the full set in bounded
	 * batches (fetch $limit, process, advance the cursor to the last id, repeat)
	 * so a site with 100k+ expired enrollments never loads the whole set into
	 * memory in one pass. `id > %d` + `ORDER BY id ASC` gives a stable, gap-safe
	 * cursor on the PRIMARY KEY even as earlier rows are flipped to `expired`.
	 *
	 * @param string $now      Cutoff timestamp (UTC `Y-m-d H:i:s`).
	 * @param int    $limit    Max rows per batch.
	 * @param int    $after_id Only return rows with `id` greater than this cursor.
	 */
	public static function get_expired( string $now, int $limit = 500, int $after_id = 0 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				// `expires_at` rides along so the sweep's audit row can name the
				// expiry that justified taking a paying customer's access away.
				'SELECT id, user_id, course_id, expires_at FROM ' . static::table() . " WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s AND id > %d ORDER BY id ASC LIMIT %d",
				$now,
				$after_id,
				$limit
			)
		) ?: [];
	}

	/**
	 * Active enrollments expiring between $now and $until that haven't had a
	 * reminder sent yet. Used by the cron expiry reminder job.
	 *
	 * Bounded to $limit rows per call and seeked past the `$after_id` cursor so
	 * the reminder cron processes a large backlog in batches instead of loading
	 * every matching row at once. Ordered by `id` (PRIMARY KEY) for a stable
	 * cursor; processed rows are stamped via `mark_reminder_sent()` so they drop
	 * out of subsequent batches regardless of the cursor.
	 *
	 * @param string $now      Window start (UTC `Y-m-d H:i:s`).
	 * @param string $until    Window end (UTC `Y-m-d H:i:s`).
	 * @param int    $limit    Max rows per batch.
	 * @param int    $after_id Only return rows with `id` greater than this cursor.
	 */
	public static function get_expiring_unremindered( string $now, string $until, int $limit = 500, int $after_id = 0 ): array {
		$table = static::table();
		return static::db()->get_results(
			static::db()->prepare(
				"SELECT e.id, e.user_id, e.course_id, e.expires_at
				FROM {$table} e
				WHERE e.status = 'active'
				AND e.expires_at IS NOT NULL
				AND e.expires_at > %s
				AND e.expires_at <= %s
				AND e.id > %d
				AND NOT EXISTS (
					SELECT 1 FROM {$table} e2
					WHERE e2.id = e.id
					AND e2.reminder_sent_at IS NOT NULL
				)
				ORDER BY e.id ASC
				LIMIT %d",
				$now,
				$until,
				$after_id,
				$limit
			)
		) ?: [];
	}

	/** Marks an enrollment as having had its reminder sent. */
	public static function mark_reminder_sent( int $id, string $now ): void {
		static::db()->update( static::table(), [ 'reminder_sent_at' => $now ], [ 'id' => $id ] );
	}

	/**
	 * Active enrollments expiring inside the (`$now`, `$until`] window —
	 * unfiltered by reminder state. Used by the PR-E reminder cron, which
	 * dedups against `lrn_notifications` per-cadence instead of using the
	 * `reminder_sent_at` column (the column only covers the legacy 7-day
	 * pass; per-cadence dedup needs a separate signal anyway).
	 *
	 * Bounded to $limit rows per call and seeked past the `$after_id` cursor
	 * (PRIMARY KEY, `ORDER BY id ASC`) so the reminder cron walks a large
	 * window in batches instead of one unbounded result set.
	 *
	 * @param string $now      Window start (UTC `Y-m-d H:i:s`).
	 * @param string $until    Window end (UTC `Y-m-d H:i:s`).
	 * @param int    $limit    Max rows per batch.
	 * @param int    $after_id Only return rows with `id` greater than this cursor.
	 */
	public static function get_expiring_between( string $now, string $until, int $limit = 500, int $after_id = 0 ): array {
		$table = static::table();
		return static::db()->get_results(
			static::db()->prepare(
				"SELECT e.id, e.user_id, e.course_id, e.expires_at
				FROM {$table} e
				WHERE e.status = 'active'
				AND e.expires_at IS NOT NULL
				AND e.expires_at > %s
				AND e.expires_at <= %s
				AND e.id > %d
				ORDER BY e.id ASC
				LIMIT %d",
				$now,
				$until,
				$after_id,
				$limit
			)
		) ?: [];
	}

	/**
	 * Per-student summary for CSV export: enrolled courses and avg progress.
	 *
	 * The GROUP BY scan returns one row per student; on a 100k+ enrollment site
	 * that is an unbounded result set, so the query is paginated. The CLI export
	 * loops with an increasing `$offset` (USER_ID_BATCH per call) until a short
	 * page comes back, exporting everyone without ever holding the whole set in
	 * one query result (Basecamp #9961949958).
	 *
	 * Ordered by user_id ASC (not enrolled_courses) so the cursor is stable
	 * across pages — an aggregate sort key would shuffle rows between offsets
	 * and drop/duplicate students at the page boundary.
	 */
	public static function get_student_summary( int $limit = self::USER_ID_BATCH, int $offset = 0 ): array {
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		return static::db()->get_results(
			static::db()->prepare(
				'SELECT user_id,
				        COUNT(DISTINCT course_id)   AS enrolled_courses,
				        ROUND(AVG(progress_pct), 1) AS completion_rate
				 FROM ' . static::table() . '
				 GROUP BY user_id
				 ORDER BY user_id ASC
				 LIMIT %d OFFSET %d',
				$limit,
				$offset
			)
		) ?: [];
	}

	/** Enrollment counts grouped by status. */
	public static function count_by_status(): array {
		return static::db()->get_results(
			'SELECT status, COUNT(*) AS n FROM ' . static::table() . ' GROUP BY status'
		) ?: [];
	}

	/** Average progress_pct across active enrollments. */
	public static function avg_completion(): float {
		return (float) static::db()->get_var(
			'SELECT AVG(progress_pct) FROM ' . static::table() . " WHERE status = 'active'"
		);
	}

	/** Daily enrollment counts since a date for dashboard charts. */
	public static function get_daily_since( string $since ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT DATE(enrolled_at) AS day, COUNT(*) AS total
				 FROM ' . static::table() . '
				 WHERE enrolled_at >= %s
				 GROUP BY DATE(enrolled_at)
				 ORDER BY day ASC',
				$since
			)
		) ?: [];
	}

	/** Daily enrollment counts for a specific course since a date. */
	public static function get_daily_for_course_since( int $course_id, string $since ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT DATE(enrolled_at) AS day, COUNT(*) AS total
				 FROM ' . static::table() . '
				 WHERE course_id = %d AND enrolled_at >= %s
				 GROUP BY DATE(enrolled_at)
				 ORDER BY day ASC',
				$course_id,
				$since
			)
		) ?: [];
	}

	/** Top courses: student count, revenue, and completion rate via 3-table join. */
	public static function get_top_courses( int $limit = 5 ): array {
		$courses_table      = \Learnomy\table( 'courses' );
		$transactions_table = \Learnomy\table( 'transactions' );
		$table              = static::table();

		// Per-course aggregates via correlated SUBQUERIES, not a shared
		// GROUP BY over both LEFT JOINs. Joining enrollments AND transactions to
		// the same course is a CARTESIAN fan-out: a course with E enrollments and
		// T transactions yields E*T rows, so SUM(t.amount) was multiplied by the
		// enrollment count (revenue wildly inflated) and COUNT(e.id) by the
		// transaction count. The subqueries also count DISTINCT people for the
		// completion rate, so a multi-source learner does not skew it (Basecamp
		// 10109120366).
		return static::db()->get_results(
			static::db()->prepare(
				"SELECT c.id, c.title, c.student_count,
					( SELECT COALESCE(SUM(t.amount), 0)
					  FROM {$transactions_table} t
					  WHERE t.course_id = c.id AND t.status = 'completed' ) AS revenue,
					( SELECT COALESCE(
							ROUND(
								COUNT(DISTINCT CASE WHEN e.status = 'completed' THEN e.user_id END) * 100
								/ NULLIF(COUNT(DISTINCT e.user_id), 0)
							, 1), 0)
					  FROM {$table} e
					  WHERE e.course_id = c.id ) AS completion_rate
				 FROM {$courses_table} c
				 ORDER BY c.student_count DESC
				 LIMIT %d",
				$limit
			)
		) ?: [];
	}

	/**
	 * Enrollment rows whose WordPress user no longer exists.
	 *
	 * These predate the `deleted_user` hook that now purges a user's LMS rows
	 * (CF-005 / F-DB-01) — the same class the orphan-certificate cleanup exists
	 * for. Cleared by `wp learnomy cleanup orphan-enrollments`.
	 *
	 * @return int[]
	 */
	public static function get_orphan_ids(): array {
		$enrollments_table = static::table();
		$users_table       = static::db()->users;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = static::db()->get_col(
			"SELECT e.id FROM {$enrollments_table} e
			 LEFT JOIN {$users_table} u ON u.ID = e.user_id
			 WHERE u.ID IS NULL"
		);

		return array_map( 'intval', (array) $ids );
	}
}
