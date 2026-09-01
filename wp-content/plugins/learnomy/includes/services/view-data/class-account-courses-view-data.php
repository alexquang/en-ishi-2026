<?php
/**
 * View-data resolver for the dedicated "My Courses" account section.
 *
 * Feeds templates/account-courses.php, which reuses the shared enrollments
 * tab partial. Unlike the dashboard home, this surface owns the FULL course
 * list, so it pages the database (first 40 rows per tab) and counts via a
 * dedicated COUNT(*) — never `count( list_all() )` — so it stays fast for a
 * learner with hundreds of courses. The "Load more" button pages the rest in
 * via REST (GET learnomy/v1/enrollments/page).
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Enrollment;
use Learnomy\Models\Course;

class Account_Courses_View_Data {

	/**
	 * First page of each tab + accurate totals. The template binds these to the
	 * shared `dashboard-widgets/student/enrollments.php` partial.
	 *
	 * @param array $data Route data (unused; the user comes from the session).
	 * @return array
	 */
	public static function resolve( array $data ): array {
		$user_id = get_current_user_id();

		// One page per tab. The REST "Load more" route reads the same filter so
		// the page size stays consistent between the initial render and paging.
		$per = (int) apply_filters( 'learnomy_my_courses_per_page', 40 );
		$per = max( 1, min( 100, $per ) );

		$all_rows         = Enrollment::get_distinct_for_user_page( $user_id, 'all', $per, 0 );
		$in_progress_rows = Enrollment::get_distinct_for_user_page( $user_id, 'in-progress', $per, 0 );
		$completed_rows   = Enrollment::get_distinct_for_user_page( $user_id, 'completed', $per, 0 );

		$total_enrolled    = Enrollment::count_distinct_for_user( $user_id, 'all' );
		$total_in_progress = Enrollment::count_distinct_for_user( $user_id, 'in-progress' );
		$total_completed   = Enrollment::count_distinct_for_user( $user_id, 'completed' );

		// Course objects for every enrollment row across the three tabs, fetched
		// once so the partial never queries inside its render loop.
		$enrollment_courses = array();
		foreach ( array_merge( $all_rows, $in_progress_rows, $completed_rows ) as $row ) {
			$course_id = (int) $row->course_id;
			if ( ! isset( $enrollment_courses[ $course_id ] ) ) {
				$course = Course::find( $course_id );
				if ( $course ) {
					$enrollment_courses[ $course_id ] = $course;
				}
			}
		}

		// Wishlist — published courses the learner saved. Read through the shared
		// helper, which tolerates BOTH meta storage shapes (the JSON string the
		// wishlist controller writes AND a raw/serialized PHP array from the demo
		// seeder). A raw json_decode() here returned empty for seeded users, so
		// their Wishlist tab showed nothing (Basecamp #9981514248).
		// The count and the list must come from the SAME set. They did not: the
		// badge counted saved IDS while the panel rendered PUBLISHED courses, so a
		// saved course that was later unpublished or deleted left the tab claiming
		// items the panel could not show and the learner could not clear.
		//
		// Wishlist_Service::visible_courses() answers "what can actually be shown"
		// in one query, and is what the REST route uses too, so the web and the app
		// cannot drift apart again. Stale saves stay in storage — see that method.
		$lrn_wishlist     = \Learnomy\wishlist_ids( $user_id );
		$lrn_visible      = \Learnomy\Services\Wishlist_Service::visible_courses( $user_id );
		$total_wishlist   = count( $lrn_visible );
		$wishlist_courses = array_slice( $lrn_visible, 0, $per );

		// Per-tab "is there a next page?" — drives the Load more button. Wishlist
		// measures the VISIBLE set for the same reason the count above does: keyed
		// off saved ids it offered Load more for pages made entirely of courses
		// that would never render.
		$has_more = array(
			'all'         => count( $all_rows ) < $total_enrolled,
			'in-progress' => count( $in_progress_rows ) < $total_in_progress,
			'completed'   => count( $completed_rows ) < $total_completed,
			'wishlist'    => $total_wishlist > $per,
		);

		return array(
			'user_id'                 => $user_id,
			'enrollments'             => $all_rows,
			'in_progress_enrollments' => $in_progress_rows,
			'completed_enrollments'   => $completed_rows,
			'wishlist_courses'        => $wishlist_courses,
			'enrollment_courses'      => $enrollment_courses,
			'lrn_wishlist'            => $lrn_wishlist,
			'total_enrolled'          => $total_enrolled,
			'total_in_progress'       => $total_in_progress,
			'total_completed'         => $total_completed,
			'total_wishlist'          => $total_wishlist,
			// Consumed by the enrollments partial to render per-tab Load more.
			'courses_has_more'        => $has_more,
			'courses_per_page'        => $per,
		);
	}
}
