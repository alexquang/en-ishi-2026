<?php
/**
 * Builds the instructor dashboard widget context ($lrn_dashboard_ctx) from the
 * view-data vars already in scope. Shared by the instructor dashboard and every
 * instructor section page (Courses / Students / Earnings / …) so the widget
 * context is constructed in exactly one place — no per-section duplication.
 *
 * Expects in scope (from Instructor_Dashboard_View_Data): $user_id, $user,
 *   $my_courses, $total_students, $total_revenue, … (the full dashboard set).
 * Optional in scope: $lrn_ctx_route (defaults to instructor-dashboard).
 *
 * Sets: $lrn_dashboard_ctx.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_dashboard_ctx = array(
	'surface'   => 'instructor',
	'route'     => isset( $lrn_ctx_route ) ? (string) $lrn_ctx_route : 'instructor-dashboard',
	'user_id'   => (int) ( $user_id ?? get_current_user_id() ),
	'user'      => $user ?? wp_get_current_user(),
	'view_data' => array(
		'my_courses'            => $my_courses ?? array(),
		'my_course_total'       => $my_course_total ?? ( isset( $my_courses ) ? count( $my_courses ) : 0 ),
		'my_courses_overflow'   => $my_courses_overflow ?? false,
		'viewing_all_courses'   => $viewing_all_courses ?? false,
		'published_count'       => $published_count ?? 0,
		'total_students'        => $total_students ?? 0,
		'total_revenue'         => $total_revenue ?? 0,
		'available_to_withdraw' => $available_to_withdraw ?? 0,
		'withdraw_min'          => $withdraw_min ?? 0,
		'this_month_earnings'   => $this_month_earnings ?? 0,
		'recent_enrollments'    => $recent_enrollments ?? array(),
		'enrollment_students'   => $enrollment_students ?? array(),
		'pending_reviews'       => $pending_reviews ?? array(),
		'review_users'          => $review_users ?? array(),
		'review_courses'        => $review_courses ?? array(),
		'funnel_enrolled'       => $funnel_enrolled ?? 0,
		'funnel_started'        => $funnel_started ?? 0,
		'funnel_halfway'        => $funnel_halfway ?? 0,
		'funnel_completed'      => $funnel_completed ?? 0,
		'funnel_started_pct'    => $funnel_started_pct ?? 0,
		'funnel_halfway_pct'    => $funnel_halfway_pct ?? 0,
		'funnel_completed_pct'  => $funnel_completed_pct ?? 0,
		'inactive_7'            => $inactive_7 ?? 0,
		'inactive_14'           => $inactive_14 ?? 0,
		'pending_grading_count' => $pending_grading_count ?? 0,
		'create_url'            => $create_url ?? apply_filters( 'learnomy_instructor_create_course_url', admin_url( 'admin.php?page=learnomy-new-course' ) ),
	),
);
