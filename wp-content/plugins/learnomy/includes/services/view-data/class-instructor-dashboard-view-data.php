<?php
/**
 * View-data resolver for the instructor dashboard template.
 *
 * Replaces all Model calls in templates/instructor-dashboard.php with a
 * pre-resolved data array so the template has zero DB queries.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Commission;
use Learnomy\Models\Enrollment;
use Learnomy\Models\InstructorApplication;
use Learnomy\Models\Review;
use Learnomy\Models\Transaction;
use Learnomy\Services\Grading_Service;
use Learnomy\Models\QuestionResponse;

class Instructor_Dashboard_View_Data {

	/**
	 * Resolve all data needed by templates/instructor-dashboard.php.
	 *
	 * @param array $data Route data (typically just 'route' => 'instructor-dashboard').
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		// Access check — admins, instructors by cap, or by role.
		$is_instructor = current_user_can( 'manage_options' )
			|| $user->has_cap( 'learnomy_create_course' )
			|| in_array( 'lrn_instructor', (array) $user->roles, true );

		if ( ! $is_instructor ) {
			// A pending applicant landing here should see "under review", not a
			// dead-end "apply" prompt that implies they never applied
			// (Basecamp #9919574281).
			$latest_application      = InstructorApplication::find_latest_for_user( $user_id );
			$has_pending_application = $latest_application && 'pending' === ( $latest_application->status ?? '' );

			return array(
				'user_id'                 => $user_id,
				'user'                    => $user,
				'is_instructor'           => false,
				'has_pending_application' => $has_pending_application,
			);
		}

		// Instructor's own + co-instructed courses — only the FIRST page (25)
		// is rendered; the My Courses widget pages the rest via REST
		// (GET courses/instructor-page) without reloading the dashboard. Stats
		// below are SQL aggregates over the whole catalogue, independent of this
		// page. Co-instructor rows live in `lrn_course_instructors` and were
		// previously invisible because the query only filtered on
		// `courses.author_id` — see Basecamp #9932171957.
		$my_courses_per_page = 25;
		$my_course_total     = Course::count_for_instructor( $user_id );
		$my_courses          = Course::get_for_instructor( $user_id, $my_courses_per_page, 0 );
		$viewing_all_courses = false;

		// Admin fallback — admins who have no authored courses see the
		// platform-wide view so the dashboard isn't blank. The
		// $viewing_all_courses flag drives a banner in the template.
		if ( empty( $my_courses ) && current_user_can( 'manage_options' ) ) {
			$my_course_total     = Course::count_selectable();
			$my_courses          = Course::selectable( 0, 'id', 'DESC', $my_courses_per_page );
			$viewing_all_courses = true;
		}

		$my_courses_overflow = $my_course_total > count( $my_courses );

		// Headline stats are computed in SQL across the instructor's ENTIRE
		// catalogue, not summed from the (capped) $my_courses page — otherwise
		// an instructor with more than 100 courses would see truncated totals.
		$published_count = $viewing_all_courses
			? Course::count( array( 'status' => 'published' ) )
			: Course::count_for_instructor_by_status( $user_id, 'published' );
		// Platform-wide DISTINCT students for the admin view — summing the
		// (25-capped) $my_courses page both truncated the total AND double-counted
		// any student enrolled in more than one course.
		$total_students = $viewing_all_courses
			? Enrollment::count_distinct_students()
			: Course::sum_students_for_instructor( $user_id );

		// Revenue numbers — platform-wide when admin viewing site data,
		// per-instructor for a real instructor.
		if ( $viewing_all_courses ) {
			$total_revenue         = Transaction::total_completed_revenue();
			$available_to_withdraw = 0.0;
			$this_month_earnings   = 0.0;
		} else {
			$total_revenue = Commission::sum_for_instructor( $user_id );
			// J-04.6 — "Available to Withdraw" must match what the withdrawal
			// action actually operates on. request_withdrawal() builds its
			// balance from get_pending_for_instructor() (payout_status='pending',
			// the default state on insert). The old call here queried
			// payout_status='unpaid' — a status nothing in the commission
			// lifecycle ever sets — so the dashboard always showed $0 available
			// while the withdrawal button would happily withdraw the pending
			// balance. Align the display with the action.
			$available_to_withdraw = Commission::sum_pending_for_instructor( $user_id );
			$this_month_earnings   = Commission::sum_for_instructor_since( $user_id, gmdate( 'Y-m-01 00:00:00' ) );
		}

		// Recent enrollments across instructor courses — only need a handful
		// for the dashboard feed, so we cap each course query at 3 rows.
		$recent_enrollments = array();
		foreach ( array_slice( $my_courses, 0, 5 ) as $c ) {
			$enrs = Enrollment::get_for_course( (int) $c->id, 3, 0 );
			foreach ( $enrs as $enr ) {
				$recent_enrollments[] = (object) array_merge( (array) $enr, array( 'course_title' => $c->title ) );
			}
		}
		usort( $recent_enrollments, fn( $a, $b ) => strcmp( $b->enrolled_at, $a->enrolled_at ) );
		$recent_enrollments = array_slice( $recent_enrollments, 0, 8 );

		// Pre-fetch student user data for recent enrollments.
		$enrollment_students = array();
		foreach ( $recent_enrollments as $enr ) {
			if ( ! isset( $enrollment_students[ (int) $enr->user_id ] ) ) {
				$enrollment_students[ (int) $enr->user_id ] = get_userdata( (int) $enr->user_id );
			}
		}

		// Pending reviews across the instructor's ENTIRE catalogue (SQL-scoped
		// via the instructor course subquery), not just the listed page. The
		// admin platform-wide view still scopes to the courses it fetched.
		// (Historically the old per-course loop also had a silent bug:
		// Review::get_for_course() hardcoded status='approved', so pending
		// reviews never surfaced — fixed when this moved to a single query.)
		$pending_reviews = $viewing_all_courses
			? Review::get_pending_for_courses( array_map( 'intval', wp_list_pluck( $my_courses, 'id' ) ), 10 )
			: Review::get_pending_for_instructor( $user_id, 10 );

		// Pre-fetch reviewer + course data for pending reviews.
		$review_users   = array();
		$review_courses = array();
		foreach ( $pending_reviews as $review ) {
			if ( ! isset( $review_users[ (int) $review->user_id ] ) ) {
				$review_users[ (int) $review->user_id ] = get_userdata( (int) $review->user_id );
			}
			if ( ! isset( $review_courses[ (int) $review->course_id ] ) ) {
				$review_courses[ (int) $review->course_id ] = Course::find( (int) $review->course_id );
			}
		}

		// Completion funnel across the instructor's ENTIRE catalogue (SQL-scoped),
		// not just the listed page. Admin platform-view scopes to fetched courses.
		$funnel = $viewing_all_courses
			? Enrollment::completion_funnel( array_map( 'intval', wp_list_pluck( $my_courses, 'id' ) ) )
			: Enrollment::completion_funnel_for_instructor( $user_id );

		$funnel_enrolled  = $funnel['enrolled'];
		$funnel_started   = $funnel['started'];
		$funnel_halfway   = $funnel['halfway'];
		$funnel_completed = $funnel['completed'];

		$funnel_started_pct   = $funnel_enrolled > 0 ? round( ( $funnel_started / $funnel_enrolled ) * 100 ) : 0;
		$funnel_halfway_pct   = $funnel_enrolled > 0 ? round( ( $funnel_halfway / $funnel_enrolled ) * 100 ) : 0;
		$funnel_completed_pct = $funnel_enrolled > 0 ? round( ( $funnel_completed / $funnel_enrolled ) * 100 ) : 0;

		// Engagement alerts — students who've gone quiet, scoped to the
		// instructor's catalogue (admin platform-view uses the fetched course
		// set). One COUNT(DISTINCT) each; no per-student hydration.
		if ( $viewing_all_courses ) {
			$engagement_course_ids = array_map( 'intval', wp_list_pluck( $my_courses, 'id' ) );
			$inactive_7            = Enrollment::count_inactive_students( $engagement_course_ids, 7 );
			$inactive_14           = Enrollment::count_inactive_students( $engagement_course_ids, 14 );
		} else {
			$inactive_7  = Enrollment::count_inactive_students_for_instructor( $user_id, 7 );
			$inactive_14 = Enrollment::count_inactive_students_for_instructor( $user_id, 14 );
		}

		$create_url = apply_filters( 'learnomy_instructor_create_course_url', admin_url( 'admin.php?page=learnomy-new-course' ) );

		// Essay/manual quiz attempts awaiting grading - surfaced as a dashboard
		// widget so a frontend-builder instructor sees the backlog without
		// living in wp-admin. [card 10062796121]
		//
		// The scope MUST be the grader's own, and it was not: this asked
		// "has an lrn_course_instructors row", while the grading page asks
		// Permission_Engine::gradable_course_ids(). So the badge counted
		// attempts on co-taught courses the grader would be refused, and
		// missed their own authored courses with no junction row. A badge
		// promising work that 403s is worse than no badge. One call now, the
		// same one the grading queue makes.
		//
		// No admin branch needed: $viewing_all_courses is only ever set for
		// manage_options, and the scope helper already returns null there.
		$grading_scope         = Grading_Service::scope_course_ids( $user_id );
		$pending_grading_count = QuestionResponse::count_pending_grading( $grading_scope );

		return array(
			'user_id'               => $user_id,
			'user'                  => $user,
			'is_instructor'         => true,
			'my_courses'            => $my_courses,
			'my_course_total'       => $my_course_total,
			'my_courses_overflow'   => $my_courses_overflow,
			'viewing_all_courses'   => $viewing_all_courses,
			'pending_grading_count' => $pending_grading_count,
			'published_count'       => $published_count,
			'total_students'        => $total_students,
			'total_revenue'         => $total_revenue,
			'available_to_withdraw' => $available_to_withdraw,
			'withdraw_min'          => self::resolve_withdraw_min(),
			'this_month_earnings'   => $this_month_earnings,
			'recent_enrollments'    => $recent_enrollments,
			'enrollment_students'   => $enrollment_students,
			'pending_reviews'       => $pending_reviews,
			'review_users'          => $review_users,
			'review_courses'        => $review_courses,
			'funnel_enrolled'       => $funnel_enrolled,
			'funnel_started'        => $funnel_started,
			'funnel_halfway'        => $funnel_halfway,
			'funnel_completed'      => $funnel_completed,
			'funnel_started_pct'    => $funnel_started_pct,
			'funnel_halfway_pct'    => $funnel_halfway_pct,
			'funnel_completed_pct'  => $funnel_completed_pct,
			'inactive_7'            => $inactive_7,
			'inactive_14'           => $inactive_14,
			'create_url'            => $create_url,
		);
	}

	/**
	 * Minimum balance an instructor must reach to request a withdrawal.
	 * Same resolution as Earnings_Controller + the dashboard modal JS:
	 * canonical `withdrawal_minimum`, legacy `min_withdrawal` fallback,
	 * default 25. The earnings widget uses it to disable the button below
	 * the threshold instead of offering a dead control at $0.
	 */
	private static function resolve_withdraw_min(): float {
		$settings = get_option( 'learnomy_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		return (float) ( $settings['withdrawal_minimum']
			?? $settings['min_withdrawal']
			?? 25.0 );
	}
}
