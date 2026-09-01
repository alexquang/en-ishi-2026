<?php
/**
 * Progress — per-user, per-item completion state with time tracking.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;
use function Learnomy\table;

class Progress extends Model {

	protected static function table_name(): string {
		return 'progress';
	}

	/**
	 * Inserts a new row or updates the existing one on the UNIQUE key (user_id, item_type, item_id).
	 * Returns the row id in both cases.
	 */
	public static function create_or_update( int $user_id, string $item_type, int $item_id, array $fields = [] ): int {
		$base = [
			'user_id'    => $user_id,
			'item_type'  => $item_type,
			'item_id'    => $item_id,
			'status'     => 'in_progress',
			'created_at' => now(),
			'updated_at' => now(),
		];

		$data = array_merge( $base, $fields );

		// ON DUPLICATE KEY lets us do this in a single round-trip.
		// CF-008: time_spent_seconds is only written when the caller explicitly
		// passes it. When present it ACCUMULATES (existing + new) so that a
		// Mark Complete click with a session-time payload adds to, rather than
		// replacing, any seconds the /progress heartbeat already stored. Callers
		// that do NOT pass time_spent_seconds leave the column untouched — the
		// column is intentionally absent from $update_parts in that path because
		// MySQL's VALUES(col) for a column not in the INSERT list is undefined
		// and can silently zero out the stored value.
		$update_parts = [ 'status = VALUES(status)', 'updated_at = VALUES(updated_at)' ];
		if ( isset( $fields['time_spent_seconds'] ) ) {
			$update_parts[] = 'time_spent_seconds = time_spent_seconds + VALUES(time_spent_seconds)';
		}
		if ( isset( $fields['completed_at'] ) ) {
			$update_parts[] = 'completed_at = VALUES(completed_at)';
		}

		$columns      = implode( ', ', array_keys( $data ) );
		$placeholders = implode( ', ', array_fill( 0, count( $data ), '%s' ) );
		$on_dup       = implode( ', ', $update_parts );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'INSERT INTO ' . static::table() . " ({$columns}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$on_dup}",
				...array_values( $data )
			)
		);
		static::invalidate_cache();

		// Fetch the id regardless of whether we inserted or updated.
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT id FROM ' . static::table() . ' WHERE user_id = %d AND item_type = %s AND item_id = %d LIMIT 1',
				$user_id,
				$item_type,
				$item_id
			)
		);

		return $row ? (int) $row->id : 0;
	}

	public static function mark_completed( int $user_id, string $item_type, int $item_id ): void {
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . " SET status = 'completed', completed_at = %s, updated_at = %s WHERE user_id = %d AND item_type = %s AND item_id = %d",
				now(),
				now(),
				$user_id,
				$item_type,
				$item_id
			)
		);
		static::invalidate_cache();
	}

	public static function get_for_user_and_item( int $user_id, string $item_type, int $item_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d AND item_type = %s AND item_id = %d LIMIT 1',
				$user_id,
				$item_type,
				$item_id
			)
		) ?: null;
	}

	/**
	 * Count completed lessons per user, for a SET of users restricted to a
	 * SET of lesson IDs, in one query — keyed by user_id.
	 *
	 * Replaces the per-member `Progress::where(...)` + in-PHP `in_array`
	 * filter that cohort progress reporting ran once per member (one query
	 * per member → one query per page of members). The `(user_id, item_type,
	 * item_id)` unique key serves the filter. Users with zero completed
	 * lessons in the set are simply absent from the map (callers default to 0).
	 *
	 * @param int[] $user_ids   Member user IDs (deduped + int-cast internally).
	 * @param int[] $lesson_ids The course's lesson IDs to count against.
	 * @return array<int,int> user_id => count of completed lessons in the set.
	 */
	public static function completed_lesson_counts_for_users( array $user_ids, array $lesson_ids ): array {
		$uids     = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		$item_ids = array_values( array_unique( array_filter( array_map( 'intval', $lesson_ids ) ) ) );
		if ( empty( $uids ) || empty( $item_ids ) ) {
			return array();
		}
		$user_ph = implode( ',', array_fill( 0, count( $uids ), '%d' ) );
		$item_ph = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		$rows    = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT user_id, COUNT(*) AS completed FROM ' . static::table()
					. " WHERE item_type = 'lesson' AND status = 'completed'"
					. " AND user_id IN ({$user_ph}) AND item_id IN ({$item_ph})"
					. ' GROUP BY user_id',
				...array_merge( $uids, $item_ids )
			)
		);
		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->user_id ] = (int) $row->completed;
		}
		return $map;
	}

	/**
	 * Per-lesson engagement for a set of lessons, in ONE grouped query.
	 *
	 * Returns `lesson_id => { started, completed, avg_seconds }` where:
	 *   started   = learners who actually reached the lesson
	 *   completed = learners whose row reached 'completed'
	 *   avg_seconds = mean time_spent_seconds across rows that recorded any
	 *
	 * `started` counts rows whose status is NOT 'not_started', not simply every
	 * row. A row can exist in the not_started state — reset_for_course() writes
	 * exactly that, and any placeholder-row path would too. Counting those as
	 * reaches inflates the top of the funnel, which makes the curve rise where
	 * it should fall and hides the real drop-off point.
	 *
	 * This is what makes a drop-off report possible: the lesson where `started`
	 * falls off a cliff relative to the one before it is where learners quit,
	 * and it is the single most actionable number an instructor can be given.
	 *
	 * Pass $user_ids to scope the report to a group — a cohort's members, a
	 * space's members. The restriction rides the same UNIQUE
	 * `(user_id, item_type, item_id)` key, so a scoped report is still one
	 * query; that is what lets "is the Tuesday cohort behind on lesson 4?" be
	 * answered without a second reporting path. Null means every learner.
	 *
	 * Scale: one GROUP BY over `item_type_id (item_type, item_id)`, which the
	 * schema already indexes — no per-lesson query, and the cost does not grow
	 * with the number of learners. Callers pass the lesson set, so it is bounded
	 * by course size, not enrolment size.
	 *
	 * @param int[]      $lesson_ids Lesson ids to report on.
	 * @param int[]|null $user_ids   Restrict to these learners; null for all.
	 * @return array<int, array{started:int, completed:int, avg_seconds:int}>
	 */
	public static function lesson_engagement( array $lesson_ids, ?array $user_ids = null ): array {
		$item_ids = array_values( array_unique( array_filter( array_map( 'intval', $lesson_ids ) ) ) );
		if ( empty( $item_ids ) ) {
			return array();
		}

		$item_ph  = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );
		$params   = $item_ids;
		$user_sql = '';

		if ( null !== $user_ids ) {
			$uids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );

			// An empty group is nobody, not everybody. Falling through to the
			// unscoped query here would show one cohort another cohort's numbers
			// — a wrong report reads as a real one, which is worse than no report.
			if ( empty( $uids ) ) {
				return array();
			}

			$user_ph  = implode( ',', array_fill( 0, count( $uids ), '%d' ) );
			$user_sql = " AND user_id IN ({$user_ph})";
			$params   = array_merge( $params, $uids );
		}

		// Rule 15 note: COUNT(*)/SUM here ARE distinct people. This table's
		// UNIQUE key is (user_id, item_type, item_id), so grouped by item_id a
		// learner can contribute at most one row. The rule guards
		// lrn_enrollments, whose key is (user_id, course_id, source, path_id)
		// and therefore does double-count a member holding a course two ways.
		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT item_id,'
					. " SUM(CASE WHEN status <> 'not_started' THEN 1 ELSE 0 END) AS started," // count-rows-ok: UNIQUE(user_id,item_type,item_id) = 1 row per learner per lesson.
					. " SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed," // count-rows-ok: same UNIQUE key.
					. ' AVG(NULLIF(time_spent_seconds, 0)) AS avg_seconds'
					. ' FROM ' . static::table()
					. " WHERE item_type = 'lesson' AND item_id IN ({$item_ph})"
					. $user_sql
					. ' GROUP BY item_id',
				...$params
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->item_id ] = array(
				'started'     => (int) $row->started,
				'completed'   => (int) $row->completed,
				// NULL when no row recorded time — report 0, not a fake average.
				'avg_seconds' => (int) round( (float) ( $row->avg_seconds ?? 0 ) ),
			);
		}

		return $map;
	}

	/** Resets all lesson progress for a user in a course back to not_started. */
	public static function reset_for_course( int $user_id, int $course_id ): void {
		$progress_table = static::table();
		$lessons_table  = table( 'lessons' );
		static::db()->query(
			static::db()->prepare(
				"UPDATE {$progress_table} p INNER JOIN {$lessons_table} l ON p.item_id = l.id AND p.item_type = 'lesson' SET p.status = 'not_started', p.completed_at = NULL WHERE p.user_id = %d AND l.course_id = %d",
				$user_id,
				$course_id
			)
		);
		static::invalidate_cache();
	}

	/** Whether a user has completed at least one lesson in a course. */
	public static function has_completed_lesson_in_course( int $user_id, int $course_id ): bool {
		$progress_table = static::table();
		$lessons_table  = table( 'lessons' );
		return (bool) static::db()->get_var(
			static::db()->prepare(
				"SELECT 1 FROM {$progress_table} p INNER JOIN {$lessons_table} l ON p.item_id = l.id AND p.item_type = 'lesson' WHERE p.user_id = %d AND l.course_id = %d AND p.status = 'completed' LIMIT 1",
				$user_id,
				$course_id
			)
		);
	}

	/** Deletes all progress rows for a user — GDPR erasure. */
	public static function delete_for_user( int $user_id ): int {
		$deleted = (int) static::db()->delete( static::table(), [ 'user_id' => $user_id ], [ '%d' ] );
		static::invalidate_cache();
		return $deleted;
	}

	/**
	 * Delete every progress row for a single lesson (across all students).
	 *
	 * Called by the {@see Lesson::delete()} cascade. Progress is keyed by
	 * `(item_type, item_id)` with no lesson FK, so the match is scoped by type
	 * rather than by a foreign key — leaving quiz/course progress rows
	 * untouched. Orphaned lesson-progress rows would otherwise silently inflate
	 * course-completion math against a lesson that no longer exists.
	 *
	 * BOTH lesson-shaped types are removed. A topic is a `lrn_lessons` row with
	 * a `parent_id`, and the progress endpoint accepts `item_type` of `lesson`
	 * or `topic` for exactly those rows, writing whichever the client sent. A
	 * cascade that matched `'lesson'` alone therefore deleted a topic's row only
	 * when the client happened to have called it a lesson, and orphaned it when
	 * the client called it a topic — the same row, the same delete, two
	 * outcomes. Ids are unique across the table, so widening the type list can
	 * never reach another entity's progress.
	 *
	 * @param int $lesson_id Lesson (or topic) row id.
	 * @return int Number of progress rows removed.
	 */
	public static function delete_for_lesson( int $lesson_id ): int {
		if ( $lesson_id <= 0 ) {
			return 0;
		}
		$deleted = (int) static::db()->query(
			static::db()->prepare(
				'DELETE FROM ' . static::table() . " WHERE item_id = %d AND item_type IN ( 'lesson', 'topic' )",
				$lesson_id
			)
		);
		static::invalidate_cache();
		return $deleted;
	}

	/**
	 * Wipes every lesson progress row for a user inside a single course.
	 *
	 * Used by the course retake flow when `reset_on_retake` is true — the
	 * student's enrollment row is reopened and every prior completion is
	 * removed so the curriculum strip, completion counters, and locking
	 * logic start from a clean slate.
	 *
	 * Joins through `lrn_lessons` because `lrn_progress` stores `item_id`
	 * (the lesson id) with no course_id column of its own.
	 */
	public static function delete_for_user_course( int $user_id, int $course_id ): int {
		$progress_table = static::table();
		$lessons_table  = table( 'lessons' );
		$deleted        = (int) static::db()->query(
			static::db()->prepare(
				"DELETE p FROM {$progress_table} p INNER JOIN {$lessons_table} l ON p.item_id = l.id AND p.item_type = 'lesson' WHERE p.user_id = %d AND l.course_id = %d",
				$user_id,
				$course_id
			)
		);
		static::invalidate_cache();
		return $deleted;
	}

	/**
	 * Upserts time spent on a lesson. First visit creates the row; revisits
	 * accumulate seconds via ON DUPLICATE KEY UPDATE.
	 */
	public static function record_time( int $user_id, int $lesson_id, int $seconds ): void {
		$now   = \Learnomy\now();
		$table = static::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$table} (user_id, item_type, item_id, status, time_spent_seconds, created_at, updated_at)
				VALUES (%d, %s, %d, %s, %d, %s, %s)
				ON DUPLICATE KEY UPDATE
					time_spent_seconds = time_spent_seconds + VALUES(time_spent_seconds),
					updated_at = VALUES(updated_at)",
				$user_id,
				'lesson',
				$lesson_id,
				'in_progress',
				$seconds,
				$now,
				$now
			)
		);
		static::invalidate_cache();
	}

	/**
	 * Persist the student's video watch percentage as a high-water mark — a
	 * later seek-back never lowers the stored maximum (GREATEST). Powers the
	 * `auto_video` completion rule, which reads this column so the watched %
	 * survives between the heartbeat and the authoritative /complete call.
	 *
	 * @param int $user_id   Student id.
	 * @param int $lesson_id Lesson id.
	 * @param int $pct       Watched percentage 0-100 (clamped).
	 */
	public static function record_video_pct( int $user_id, int $lesson_id, int $pct ): void {
		$pct   = max( 0, min( 100, $pct ) );
		$now   = \Learnomy\now();
		$table = static::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		static::db()->query(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$table} (user_id, item_type, item_id, status, video_watch_pct, created_at, updated_at)
				VALUES (%d, %s, %d, %s, %d, %s, %s)
				ON DUPLICATE KEY UPDATE
					video_watch_pct = GREATEST( video_watch_pct, VALUES(video_watch_pct) ),
					updated_at = VALUES(updated_at)",
				$user_id,
				'lesson',
				$lesson_id,
				'in_progress',
				$pct,
				$now,
				$now
			)
		);
		static::invalidate_cache();
	}

	/**
	 * Returns progress rows for every lesson in a course for a given student.
	 * Joining through lessons avoids a subquery and keeps the result set bounded by the course.
	 */
	public static function get_all_for_course( int $user_id, int $course_id ): array {
		$lessons_table = static::db()->prefix . 'lrn_lessons';
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT p.* FROM ' . static::table() . ' p
				INNER JOIN ' . $lessons_table . ' l ON l.id = p.item_id AND p.item_type = %s
				WHERE p.user_id = %d AND l.course_id = %d',
				'lesson',
				$user_id,
				$course_id
			)
		) ?: [];
	}

	/**
	 * Per-lesson progress for a SET of users in one course, in one query.
	 *
	 * The batched sibling of get_all_for_course(). A roster grid needs the
	 * lesson-by-lesson position of every student on the page; calling the
	 * single-user method per row is the N+1 this exists to prevent.
	 *
	 * Returns rows keyed user_id => lesson_id => row, so a caller walking the
	 * course outline can look up any cell in O(1) without re-scanning.
	 *
	 * "Furthest lesson reached" is deliberately NOT a separate query: the
	 * caller already holds the ordered lesson list for the outline, so the
	 * furthest completed lesson is a walk over this map. A SQL version would
	 * need window functions (MySQL 8.0+), which WordPress does not require.
	 *
	 * Scale: bounded by page size × course length, not by enrolment count.
	 * Served by the UNIQUE `(user_id, item_type, item_id)` key.
	 *
	 * @param int[] $user_ids  Users on the current page.
	 * @param int   $course_id Course whose lessons to report on.
	 * @return array<int, array<int, object>> user_id => lesson_id => progress row.
	 */
	public static function get_for_users_course( array $user_ids, int $course_id ): array {
		$uids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( empty( $uids ) || $course_id <= 0 ) {
			return array();
		}

		$lessons_table = static::db()->prefix . 'lrn_lessons';
		$user_ph       = implode( ',', array_fill( 0, count( $uids ), '%d' ) );

		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT p.user_id, p.item_id, p.status, p.completed_at, p.time_spent_seconds'
					. ' FROM ' . static::table() . ' p'
					. ' INNER JOIN ' . $lessons_table . ' l ON l.id = p.item_id'
					. " WHERE p.item_type = 'lesson' AND l.course_id = %d"
					. " AND p.user_id IN ({$user_ph})",
				...array_merge( array( $course_id ), $uids )
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->user_id ][ (int) $row->item_id ] = $row;
		}

		return $map;
	}

	/**
	 * Consecutive-day activity streak ending today (site timezone).
	 *
	 * Walks DISTINCT(DATE(updated_at)) descending from today; the first
	 * gap stops the count. Returns 0 when the user had no activity today
	 * AND no activity yesterday — a 2-day gap breaks the streak so a
	 * single day off doesn't reset the user mid-week.
	 *
	 * Bounded to the last 60 days; if a real streak exceeds that we cap
	 * it at 60 and the widget reads "60+" (motivational value flattens
	 * past two months).
	 *
	 * Used by the student-dashboard "Streak" widget.
	 */
	public static function current_streak( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		$cutoff = wp_date( 'Y-m-d', strtotime( '-60 days' ) );
		$days   = static::db()->get_col(
			static::db()->prepare(
				'SELECT DISTINCT DATE( updated_at ) AS day
				 FROM ' . static::table() . '
				 WHERE user_id = %d AND updated_at >= %s
				 ORDER BY day DESC',
				$user_id,
				$cutoff . ' 00:00:00'
			)
		);
		if ( empty( $days ) ) {
			return 0;
		}

		$today_str    = wp_date( 'Y-m-d' );
		$yesterday    = wp_date( 'Y-m-d', strtotime( '-1 day' ) );
		$activity_set = array_flip( $days );

		if ( ! isset( $activity_set[ $today_str ] ) && ! isset( $activity_set[ $yesterday ] ) ) {
			return 0;
		}

		$streak = 0;
		$cursor = isset( $activity_set[ $today_str ] ) ? $today_str : $yesterday;
		for ( $i = 0; $i < 60; $i++ ) {
			if ( ! isset( $activity_set[ $cursor ] ) ) {
				break;
			}
			++$streak;
			$cursor = wp_date( 'Y-m-d', strtotime( $cursor . ' -1 day' ) );
		}
		return $streak;
	}

	/**
	 * Most-recent activity timestamp per user, in ONE query.
	 *
	 * Returns `user_id => 'Y-m-d H:i:s'` (the MAX(updated_at) across all of the
	 * user's progress rows — any course). Users with no progress row are absent
	 * from the map, so the caller treats "missing" as "never active". Used by
	 * engagement/at-risk passes (e.g. the cohort at-risk nudge) that need a
	 * recency signal for a set of members without an N+1 per-user lookup.
	 * Backed by the `user_updated (user_id, updated_at)` index.
	 *
	 * @param int[] $user_ids
	 * @return array<int,string> user_id => last-activity datetime.
	 */
	public static function last_activity_for_users( array $user_ids, ?array $course_ids = null ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		// Unscoped, this answers "when did this person last touch ANYTHING on
		// the site", and it fills the "last seen" column on a teacher's roster.
		// So the physics teacher's column reported the student's chemistry
		// activity: a learner last seen in someone else's course reads as
		// active here, and the teacher chasing them never does.
		//
		// Scoping needs the lesson's course, because lrn_progress stores
		// (item_type, item_id) rather than a course_id. Only 'lesson' rows can
		// be attributed to a course today - that is the sole item_type in use -
		// so a scoped call deliberately counts lesson activity only. If another
		// item_type is ever written, it must join its own way to a course here
		// or it will silently drop out of the scoped answer.
		$table = static::table();

		if ( null === $course_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$sql          = 'SELECT user_id, MAX( updated_at ) AS last_activity FROM ' . $table
				. " WHERE user_id IN ({$placeholders}) GROUP BY user_id";
			$params       = $ids;
		} else {
			$courses = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
			if ( empty( $courses ) ) {
				return array();
			}
			$lessons   = \Learnomy\table( 'lessons' );
			$user_ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$course_ph = implode( ',', array_fill( 0, count( $courses ), '%d' ) );
			$sql       = "SELECT p.user_id, MAX( p.updated_at ) AS last_activity
				 FROM {$table} p
				 INNER JOIN {$lessons} l ON l.id = p.item_id AND p.item_type = 'lesson'
				 WHERE p.user_id IN ({$user_ph}) AND l.course_id IN ({$course_ph})
				 GROUP BY p.user_id";
			$params    = array_merge( $ids, $courses );
		}

		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from int counts.
				$sql,
				...$params
			)
		);
		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->user_id ] = (string) $row->last_activity;
		}
		return $map;
	}

	/**
	 * How a progress row should be announced in an activity feed.
	 *
	 * The Recent Activity widget printed "Completed <lesson>" with a green
	 * check-circle for EVERY row regardless of status, so a learner who had
	 * merely opened five lessons was told they had finished all five - and for a
	 * new learner, whose rows are all `in_progress`, that is 100% of what they
	 * see on their first screen (Basecamp 10184532025). Measured site-wide at
	 * the time: 417 `in_progress` rows against 2,503 `completed`, so roughly one
	 * announcement in seven was false.
	 *
	 * The verb and the icon are decided together, in one place, because they are
	 * one statement: a check-circle beside "Started" is as wrong as the word
	 * was. Filterable so a site can reword its feed without editing a template,
	 * and so a status added later fails loudly here rather than silently
	 * inheriting "Completed".
	 *
	 * @param string $status Progress row status.
	 * @return array{text: string, icon: string} `text` is a sprintf format
	 *         taking the item title.
	 */
	public static function activity_announcement( string $status ): array {
		$map = [
			/* translators: %s: lesson title */
			'completed'   => [
				'text' => __( 'Completed %s', 'learnomy' ),
				'icon' => 'check-circle',
			],
			/* translators: %s: lesson title */
			'in_progress' => [
				'text' => __( 'Started %s', 'learnomy' ),
				'icon' => 'play-circle',
			],
		];

		/**
		 * Verb and icon used to announce a progress row in an activity feed.
		 *
		 * @param array<string, array{text: string, icon: string}> $map    Status => announcement.
		 * @param string                                           $status The status being announced.
		 */
		$map = (array) apply_filters( 'learnomy_progress_activity_announcement', $map, $status );

		// An unknown status must not borrow "Completed". Saying a learner
		// opened something is recoverable; telling them they finished it is the
		// bug this method exists to end.
		return $map[ $status ] ?? ( $map['in_progress'] ?? [
			/* translators: %s: lesson title */
			'text' => __( 'Started %s', 'learnomy' ),
			'icon' => 'play-circle',
		] );
	}
}
