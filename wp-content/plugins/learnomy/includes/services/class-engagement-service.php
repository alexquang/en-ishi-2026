<?php
/**
 * Engagement Service — increments per-(instructor, plan, period) lesson counts
 * each time a student on an active membership completes a lesson.
 *
 * The monthly engagement-split cron (Commission_Service::calculate_engagement_split)
 * reads from `lrn_engagement_monthly` to distribute that month's membership
 * revenue across instructors in proportion to how many lessons their courses
 * delivered to paying members. If nothing populates the table, the split loop
 * sees `total_lessons = 0` for every plan and bails — meaning instructors on a
 * membership-only site never see their revenue share.
 *
 * Pre-this-service, the only caller of EngagementMonthly::increment() was the
 * CLI test runner (class-test-runner.php:938). Production sites accrued zero
 * engagement rows and silently paid out zero membership commissions. This
 * service is the missing listener.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\EngagementMonthly;
use Learnomy\Models\Subscription;

class Engagement_Service {

	/**
	 * Registers the lesson-completed listener. Called once from plugin boot.
	 *
	 * Priority 20 leaves room for any earlier listener (notifications,
	 * progress rollups) to run first — this hook fires exactly once per
	 * completion (verified at class-progress-service.php:94) so no dedup
	 * is required at the service level. The UNIQUE
	 * (instructor_id, plan_id, period) on the table backs the upsert.
	 */
	public function register_hooks(): void {
		add_action( 'learnomy_lesson_completed', array( $this, 'record_lesson_completion' ), 20, 3 );
	}

	/**
	 * Walk the student's active memberships and the course's instructors,
	 * incrementing `lrn_engagement_monthly` once per (instructor, plan, period).
	 *
	 * The period is derived from current_time('mysql', true) so a completion
	 * at 23:59 UTC on the last day of the month rolls into THAT month — the
	 * monthly cron, which runs against `gmdate('first day of last month')`,
	 * stays consistent.
	 *
	 * Quiet no-ops:
	 *   - student has no active membership: no row to attribute revenue to.
	 *   - course has no instructors recorded in instructors_json: nothing to
	 *     credit (matches Commission_Service::calculate single-purchase fallback,
	 *     which routes the pool to transaction.user_id — but for engagement
	 *     splits we have no transaction, so silent skip is the safe call).
	 *
	 * @param int $user_id   Student who completed the lesson.
	 * @param int $lesson_id Lesson that was completed.
	 * @param int $course_id Course the lesson belongs to.
	 */
	public function record_lesson_completion( int $user_id, int $lesson_id, int $course_id ): void {
		unset( $lesson_id );

		if ( $user_id <= 0 || $course_id <= 0 ) {
			return;
		}

		$memberships = Subscription::get_active_for_user( $user_id );
		if ( empty( $memberships ) ) {
			return;
		}

		// Co-author model: engagement (and the membership commission it drives)
		// credits the course's MAIN AUTHOR only. Co-instructors get credit +
		// reporting access, never a payout, so we no longer increment every
		// instructor on the course.
		$main_author_id = Course::main_author_id( $course_id );
		if ( $main_author_id <= 0 ) {
			return;
		}

		$period = gmdate( 'Y-m' );

		foreach ( $memberships as $membership ) {
			$plan_id = (int) ( $membership->plan_id ?? 0 );
			if ( $plan_id <= 0 ) {
				continue;
			}

			EngagementMonthly::increment( $main_author_id, $plan_id, $period );
		}
	}
}
