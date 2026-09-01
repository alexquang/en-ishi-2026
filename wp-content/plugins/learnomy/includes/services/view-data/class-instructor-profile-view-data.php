<?php
/**
 * View-data resolver for the public instructor profile page.
 *
 * Handles the username/nicename lookup, course list fetch, aggregate
 * stats (total courses, students, avg rating), and user-meta resolution
 * (avatar, bio, expertise, social links). Replaces the inline data
 * fetching that lived in templates/instructor-profile.php and which
 * violated the "templates have zero logic" rule.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Instructor;

class Instructor_Profile_View_Data {

	/**
	 * Resolve profile data for the instructor at $data['slug'].
	 *
	 * The slug accepts user_login first, then user_nicename — same fallback
	 * order that the template used. Returns 'not_found' => true when the slug
	 * matches nobody OR matches somebody who is not a public instructor, so the
	 * Template Loader renders the 404 before a byte of output.
	 *
	 * @param array $data Route data — must include 'slug'.
	 * @return array Pre-fetched view data, or 'not_found' flag when slug misses.
	 */
	public static function resolve( array $data ): array {
		$slug = (string) ( $data['slug'] ?? '' );

		// User_login first (authenticated identifier), nicename fallback (URL-safe).
		$instructor = $slug ? get_user_by( 'login', $slug ) : false;
		if ( ! $instructor && $slug ) {
			$instructor = get_user_by( 'slug', $slug );
		}

		// "A WordPress user exists with this login" was the ONLY test here, so
		// /instructor/{any-member}/ published every registered account's display
		// name, avatar and bio to anonymous visitors under a heading calling them
		// an instructor — on an LMS those accounts are students. Worse, the
		// 200-for-a-member / 404-for-a-typo split answered "does this username
		// exist?" for anyone who asked, which is the first step of credential
		// stuffing.
		//
		// Both halves are closed by returning the IDENTICAL not_found verdict for
		// "no such user" and "not an instructor": same status, same body, same
		// title, so the response carries no signal to compare. Do not add an
		// early return, a distinct message or a different status between the two
		// branches — that rebuilds the oracle.
		if ( ! $instructor || ! Instructor::has_public_profile( (int) $instructor->ID ) ) {
			return array( 'not_found' => true );
		}

		// Credit co-taught courses, not only authored ones (F3): a co-instructor
		// teaching 10 courses but authoring 0 used to show "0 courses".
		// Paginated at 12 per page (matching catalog default) so instructors
		// with 25+ courses no longer silently lose entries. Aggregates
		// (total_students, avg_rating) are derived from all published courses —
		// not just the current page — so the header stats stay accurate.
		//
		// The page number comes from ?page=N in the query string (the route
		// path carries only the instructor slug; paging stays a query param so
		// rewrite rules are unchanged).
		$per_page = 12;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page is display-only, no state mutation.
		$page   = max( 1, (int) ( $data['page'] ?? ( isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1 ) ) );
		$offset = ( $page - 1 ) * $per_page;

		// Both the grid and the header count come from the visibility-gated pair,
		// so a course the instructor set to unlisted/private disappears from BOTH
		// (Basecamp 10134139515). A grid of 7 under a header saying 8 would be the
		// same bug. count_for_instructor_by_status() is the OWNER's count and is
		// deliberately not used here.
		$courses = Course::get_published_for_instructor( $instructor->ID, $per_page, $offset );
		// Same enrolled-state supply as the catalog (one query per page, none for
		// anonymous visitors), so a student browsing their instructor's profile
		// sees Continue / Review on the courses they already own.
		$courses = Course::attach_viewer_enrollment( $courses );
		// Paired deliberately: claimable means covered by the member's plan AND
		// not already enrolled, so it reads the row the line above stamped.
		$courses       = Course::attach_viewer_claimable( $courses );
		$total_courses = Course::count_published_for_instructor( $instructor->ID );
		$total_pages   = max( 1, (int) ceil( $total_courses / $per_page ) );

		// Aggregate from all courses, not just the fetched page, so the header
		// stats ("3 students", "4.7 avg rating") never shrink mid-pagination.
		$all_courses = Course::get_published_for_instructor( $instructor->ID, 1000, 0 );
		// DISTINCT people, not a sum of per-course student_count: a student in two
		// of the instructor's courses (or one course via two sources) would be
		// counted twice. sum_students_for_instructor() is COUNT(DISTINCT user_id)
		// and also credits co-taught courses.
		$total_students = Course::sum_students_for_instructor( (int) $instructor->ID );
		// Averaging avg_rating over EVERY course counted the unrated ones as zero,
		// so each course still waiting for its first review pulled the instructor's
		// public figure down — 4.75 shown as 1.6, and a five-star instructor with
		// six unreviewed courses shown as 0.71. Publishing a course made your
		// rating worse. Course::get_instructor_stats() owns the arithmetic now, so
		// this page and the other consumers cannot drift apart again.
		//
		// null (not 0.0) when nothing is rated yet; the template shows the empty
		// state rather than a zero-star score nobody gave.
		$lrn_rating_row = Course::get_instructor_stats( (int) $instructor->ID );
		$avg_rating     = null === $lrn_rating_row->avg_rating
			? null
			: round( (float) $lrn_rating_row->avg_rating, 1 );

		// lrn_instructors is the source of truth (migration 3.1.0 consolidated the
		// old user_meta + application scatter into it). Fall back to user_meta for
		// any row not yet backfilled so a profile never renders blank.
		$profile = Instructor::find_by_user( (int) $instructor->ID );
		$meta    = static function ( $value, int $user_id, string $key ) {
			$value = (string) $value;
			return '' !== trim( $value ) ? $value : (string) get_user_meta( $user_id, $key, true );
		};

		return array(
			'not_found'      => false,
			'instructor'     => $instructor,
			'courses'        => $courses,
			'total_courses'  => $total_courses,
			'total_students' => $total_students,
			'avg_rating'     => $avg_rating,
			'avatar_url'     => get_avatar_url( $instructor->ID, array( 'size' => 120 ) ),
			'headline'       => $profile ? (string) ( $profile->headline ?? '' ) : '',
			'bio'            => $meta( $profile->bio ?? '', (int) $instructor->ID, 'description' ),
			'expertise'      => $meta( $profile->expertise ?? '', (int) $instructor->ID, 'learnomy_expertise' ),
			'social'         => array(
				'twitter'  => $meta( $profile->twitter_handle ?? '', (int) $instructor->ID, 'twitter' ),
				'linkedin' => $meta( $profile->linkedin_url ?? '', (int) $instructor->ID, 'linkedin' ),
				'website'  => '' !== trim( (string) ( $profile->website_url ?? '' ) ) ? (string) $profile->website_url : (string) $instructor->user_url,
			),
			// Pagination metadata so the template can render prev/next nav.
			'page'           => $page,
			'total_pages'    => $total_pages,
			'per_page'       => $per_page,
		);
	}
}
