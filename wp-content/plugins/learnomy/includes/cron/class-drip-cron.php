<?php
/**
 * Drip schedule cron — fires `learnomy_lesson_unlocked` for newly-available lessons.
 *
 * Runs hourly via Action Scheduler. The handler scans `lrn_lessons` for rows
 * whose `available_date` fell between the last successful run and the current
 * tick, then for each enrolled student of the parent course fires the
 * canonical `learnomy_lesson_unlocked` action so notifiers can dispatch
 * email + in-app notifications.
 *
 * Idempotency
 * - The bounded BETWEEN window relies on `learnomy_drip_last_run` being
 *   updated after a successful tick. A second run inside the same hour finds
 *   zero rows because `from > to`-style windows produce empty result sets.
 * - First-ever run looks back 24h so a fresh install does not fire unlock
 *   events for every drip-set lesson on the site at once.
 *
 * Re-entrancy
 * - `wp_cache` flag with a 5-minute TTL prevents two overlapping ticks.
 * - The flag is cleared in a `finally` block so a fatal during processing
 *   does not leave the job permanently locked.
 *
 * @package Learnomy\Cron
 */

namespace Learnomy\Cron;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Enrollment;
use Learnomy\Models\Lesson;
use Learnomy\Models\Lesson_Unlock;

final class Drip_Cron {

	/** Action Scheduler hook name — also used by WP-CLI for manual runs. */
	public const HOOK = 'learnomy_unlock_drip_lessons';

	/** Option that stores the last successful run timestamp (UTC, MySQL format). */
	public const LAST_RUN_OPTION = 'learnomy_drip_last_run';

	/** wp_cache key used as the re-entrancy flag. */
	public const REENTRANCY_KEY = 'learnomy_drip_running';

	/** wp_cache group for the re-entrancy flag — kept under the plugin namespace. */
	public const REENTRANCY_GROUP = 'learnomy';

	/** TTL for the re-entrancy flag — generous so heavy runs are not preempted. */
	public const REENTRANCY_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Action Scheduler tick handler.
	 *
	 * Bounded by the re-entrancy guard. Updates the last-run watermark only
	 * after `process_window()` returns cleanly, so a transient failure leaves
	 * the previous watermark intact and the next tick covers the gap.
	 */
	public static function process(): void {
		if ( wp_cache_get( self::REENTRANCY_KEY, self::REENTRANCY_GROUP ) ) {
			return;
		}
		wp_cache_set( self::REENTRANCY_KEY, 1, self::REENTRANCY_GROUP, self::REENTRANCY_TTL );

		try {
			$now_utc = current_time( 'mysql', true );
			self::process_window( self::last_run_utc(), $now_utc );
			update_option( self::LAST_RUN_OPTION, $now_utc, false );
		} finally {
			wp_cache_delete( self::REENTRANCY_KEY, self::REENTRANCY_GROUP );
		}
	}

	/**
	 * Process all lessons whose drip schedule fell inside ($from_utc, $to_utc].
	 *
	 * Two scans, one notification path
	 *   1. Fixed-date drip — `lrn_lessons.available_date` rows where
	 *      `drip_mode != 'relative'`. Fans out to every enrolled student of
	 *      the parent course (1.9.0 behaviour, preserved).
	 *   2. Relative drip — `lrn_lesson_unlocks.unlocks_at` rows. Each row is
	 *      already (user, lesson)-specific so we fire once per row without a
	 *      second enrolment lookup.
	 *
	 * Both paths fire `learnomy_lesson_unlocked( $lesson_id, $user_id, $course_id )`,
	 * so the Notifier and any other downstream listeners stay agnostic to
	 * which drip mode produced the unlock.
	 *
	 * Public so WP-CLI can invoke a manual run with an explicit window.
	 *
	 * @param string $from_utc Lower bound (exclusive), MySQL datetime in UTC.
	 * @param string $to_utc   Upper bound (inclusive), MySQL datetime in UTC.
	 * @return int             Total unlock events fired across both scans.
	 */
	public static function process_window( string $from_utc, string $to_utc ): int {
		$fired  = self::process_fixed_window( $from_utc, $to_utc );
		$fired += self::process_relative_window( $from_utc, $to_utc );
		return $fired;
	}

	/**
	 * Scan `lrn_lessons.available_date` for fixed-date drip rows that
	 * crossed NOW(), and fire one unlock event per enrolled student.
	 *
	 * Excludes relative-drip rows so the row-level `available_date` (which
	 * is unused for relative mode) cannot accidentally double-fire.
	 */
	private static function process_fixed_window( string $from_utc, string $to_utc ): int {
		$lessons = Lesson::get_fixed_drip_in_window( $from_utc, $to_utc );

		if ( empty( $lessons ) ) {
			return 0;
		}

		$fired = 0;

		foreach ( $lessons as $lesson ) {
			$lesson_id = (int) $lesson->id;
			$course_id = (int) $lesson->course_id;

			if ( $course_id <= 0 ) {
				continue;
			}

			// Fan out the unlock event per enrolled student using a keyset
			// cursor instead of loading every student_id into memory at once.
			// A popular course can have tens of thousands of enrollments; the
			// unbounded sibling (get_enrolled_user_ids_for_course) would
			// exhaust PHP memory and time out the cron worker. user_id is the
			// stable ascending cursor, so a replayed tick re-scans the same
			// windows deterministically.
			$batch_size = 500;
			$after_id   = 0;
			do {
				$student_ids = Enrollment::enrolled_user_ids_for_course_after( $course_id, $after_id, $batch_size );
				$batch_count = count( $student_ids );

				foreach ( $student_ids as $user_id ) {
					$user_id = (int) $user_id;
					if ( $user_id <= 0 ) {
						continue;
					}

					/**
					 * Fires once when a previously-locked lesson becomes available
					 * for a specific enrolled student.
					 *
					 * @param int $lesson_id Lesson ID that just unlocked.
					 * @param int $user_id   Student user ID.
					 * @param int $course_id Parent course ID.
					 */
					do_action( 'learnomy_lesson_unlocked', $lesson_id, $user_id, $course_id );
					++$fired;
					$after_id = $user_id;
				}
			} while ( $batch_count === $batch_size );
		}

		return $fired;
	}

	/**
	 * Scan `lrn_lesson_unlocks.unlocks_at` for relative-drip rows that
	 * crossed NOW(), and fire one unlock event per row.
	 *
	 * Each row already encodes (user, lesson) so there is no second
	 * enrolment fan-out — that work was done at enrolment time when the row
	 * was inserted by `Enrollment_Service::compute_relative_unlocks()`.
	 *
	 * Resolves `course_id` per row through the lesson model so the
	 * downstream notifier receives the same three-arg signature as the
	 * fixed-date path.
	 */
	private static function process_relative_window( string $from_utc, string $to_utc ): int {
		$rows = Lesson_Unlock::get_unlocks_in_window( $from_utc, $to_utc );

		if ( empty( $rows ) ) {
			return 0;
		}

		$fired         = 0;
		$course_id_map = []; // Memoise lesson_id => course_id within the tick.

		foreach ( $rows as $row ) {
			$lesson_id = (int) $row->lesson_id;
			$user_id   = (int) $row->user_id;

			if ( $lesson_id <= 0 || $user_id <= 0 ) {
				continue;
			}

			if ( ! isset( $course_id_map[ $lesson_id ] ) ) {
				$lesson                      = Lesson::find( $lesson_id );
				$course_id_map[ $lesson_id ] = $lesson ? (int) $lesson->course_id : 0;
			}

			$course_id = $course_id_map[ $lesson_id ];
			if ( $course_id <= 0 ) {
				continue;
			}

			// A relative-unlock row is read straight from the ledger, so unlike
			// the fixed-date path (which fans out over CURRENTLY-enrolled
			// students) it can outlive the reason it was written: the student
			// gets refunded / expired / removed, or a drip module that created
			// the row is later disabled. Firing then sends a "your lesson just
			// unlocked" email for a course the student can no longer access.
			// Gate every row on a validity check — default: the student is still
			// enrolled — with a seam so an extension can veto its own orphans.
			if ( ! self::unlock_is_valid( $user_id, $lesson_id, $course_id ) ) {
				continue;
			}

			do_action( 'learnomy_lesson_unlocked', $lesson_id, $user_id, $course_id );
			++$fired;
		}

		return $fired;
	}

	/**
	 * Whether a computed relative-drip unlock row should still fire.
	 *
	 * The default is "the student is still enrolled in the parent course"
	 * (`Enrollment::is_enrolled()` accepts active + completed), which drops the
	 * common orphan cases — refunded / expired / removed students — without
	 * touching a legitimately-enrolled student's drip. The
	 * `learnomy_lesson_unlock_is_valid` filter lets an extension veto rows it no
	 * longer owns (e.g. content-drip purging on disable).
	 *
	 * @param int $user_id   Student user id.
	 * @param int $lesson_id Lesson id.
	 * @param int $course_id Parent course id.
	 */
	private static function unlock_is_valid( int $user_id, int $lesson_id, int $course_id ): bool {
		$valid = Enrollment::is_enrolled( $user_id, $course_id );

		/**
		 * Filter whether a relative-drip unlock row is still valid to fire.
		 *
		 * @param bool $valid     Default: student is actively enrolled in the course.
		 * @param int  $user_id   Student user id.
		 * @param int  $lesson_id Lesson id.
		 * @param int  $course_id Parent course id.
		 */
		return (bool) apply_filters( 'learnomy_lesson_unlock_is_valid', $valid, $user_id, $lesson_id, $course_id );
	}

	/**
	 * Resolve the lower bound of the current run window.
	 *
	 * Falls back to "24 hours ago" on the very first run so we do not fire
	 * unlock events for the entire history of drip-set lessons at once.
	 */
	private static function last_run_utc(): string {
		$stored = (string) get_option( self::LAST_RUN_OPTION, '' );
		if ( '' === $stored ) {
			return gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		}
		return $stored;
	}

	/**
	 * Schedule the recurring Action Scheduler job.
	 *
	 * Guarded by `as_has_scheduled_action()` so a second invocation does not
	 * stack duplicate schedules. Degrades silently when Action Scheduler is
	 * not loaded — drip notifications then require a manual `wp learnomy drip`
	 * run, which matches how the membership lifecycle jobs degrade.
	 */
	public static function register(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			// Action Scheduler isn't loaded — fall back to a WP-Cron hourly
			// tick so drip lessons still unlock. The handler is bound at the
			// bottom of this file regardless of AS.
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
			}
			return;
		}

		// Action Scheduler owns the tick. Clear any WP-Cron fallback left from
		// a window when AS wasn't loaded so drip doesn't run on both engines.
		wp_clear_scheduled_hook( self::HOOK );

		if ( as_has_scheduled_action( self::HOOK, array(), 'learnomy' ) ) {
			return;
		}

		as_schedule_recurring_action(
			time() + HOUR_IN_SECONDS,
			HOUR_IN_SECONDS,
			self::HOOK,
			array(),
			'learnomy'
		);
	}
}

// Bind the handler to its hook. The class file is loaded by the autoloader
// the first time `Cron::schedule_membership_actions()` references the class,
// so the binding is in place before Action Scheduler dispatches the tick.
add_action( Drip_Cron::HOOK, array( Drip_Cron::class, 'process' ) );
