<?php
/**
 * View-data resolver for the instructor Students roster (/instructor/students/).
 *
 * Two modes from one route:
 *   - List: a searchable, paginated roster of every distinct student across the
 *     instructor's courses (author + co-taught), with per-student aggregates.
 *   - Detail (?student=N): one student's enrolments in THIS instructor's courses,
 *     ownership-gated so an instructor can't open a student they don't teach.
 *
 * Mirrors the admin Students page's enrollment-driven, DB-paged approach
 * (Enrollment::get_distinct_user_ids_paginated + scope_course_ids) so a big
 * roster never loads every row into PHP. Admins see every student; an instructor
 * is scoped to their own courses.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;

class Instructor_Students_View_Data {

	private const PER_PAGE = 20;

	/**
	 * @param array $data Route data (unused).
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		unset( $data );

		$user_id  = get_current_user_id();
		$is_admin = user_can( $user_id, 'manage_options' );

		// Scope: admins see every student (null = no course filter); an
		// instructor is scoped to their own courses (author OR co-instructor) so
		// they can never fish another instructor's roster.
		$scope_course_ids = null;
		if ( ! $is_admin ) {
			$scope_course_ids = array_map(
				static fn( $c ) => (int) $c->id,
				Course::get_for_instructor( $user_id, 1000, 0 )
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET navigation.
		$student_id = isset( $_GET['student'] ) ? absint( wp_unslash( $_GET['student'] ) ) : 0;
		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// Inactivity filter, arrived at from the Engagement Alerts widget's
		// View Details link. Clamped to a sane window: 0 = off, and anything
		// past a year is a typo, not a re-engagement strategy.
		$inactive   = isset( $_GET['inactive'] ) ? min( 365, absint( wp_unslash( $_GET['inactive'] ) ) ) : 0;
		// Read the page from the registry's canonical var (the /students/page/N/
		// rewrite rule sets lrn_page); current_page() also covers legacy ?lrn_page=.
		$page       = \Learnomy\current_page();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $student_id ) {
			return self::resolve_detail( $student_id, $scope_course_ids, $is_admin );
		}

		return self::resolve_list( $scope_course_ids, $search, $page, $inactive );
	}

	/**
	 * Paginated, searchable roster.
	 *
	 * @param int[]|null $scope_course_ids Instructor's course ids, or null for admin.
	 * @param string     $search           Name / login / email search.
	 * @param int        $page             1-based page.
	 */
	private static function resolve_list( ?array $scope_course_ids, string $search, int $page, int $inactive_days = 0 ): array {
		$offset    = ( $page - 1 ) * self::PER_PAGE;
		$paginated = Enrollment::get_distinct_user_ids_paginated(
			0,
			self::PER_PAGE,
			$offset,
			$search,
			$scope_course_ids,
			$inactive_days
		);

		$user_ids = $paginated['user_ids'];
		$total    = (int) $paginated['total'];

		$students = array();
		if ( ! empty( $user_ids ) ) {
			// Batch the user rows + per-student aggregates — no per-row queries.
			$user_query = new \WP_User_Query(
				array(
					'include' => $user_ids,
					'orderby' => 'include',
					'number'  => self::PER_PAGE,
				)
			);
			$users      = $user_query->get_results();
			// Scope the counts + course names to the instructor's own courses
			// (null for admins) so an instructor sees enrolments in THEIR courses,
			// not the student's platform-wide history.
			// Counts only. The roster used to also pull recent course TITLES for
			// every row and print them in a Courses column, which made a single
			// student six lines tall on real title lengths and stopped the list
			// being scannable at exactly the size where scanning matters. The
			// enrolled count and the View button already answer it, and the
			// student detail page lists every enrollment in full, so the titles
			// were the most expensive way to say the least (Basecamp 10171596010).
			$counts = Enrollment::counts_for_users( $user_ids, $scope_course_ids );

			foreach ( $users as $u ) {
				$uid        = (int) $u->ID;
				$count      = $counts[ $uid ] ?? array(
					'enrolled'  => 0,
					'completed' => 0,
				);
				$students[] = array(
					'id'         => $uid,
					'name'       => $u->display_name,
					'email'      => $u->user_email,
					'avatar'     => get_avatar( $uid, 40, '', $u->display_name, array( 'class' => 'lrn-admin-identity__avatar' ) ),
					'enrolled'   => (int) ( $count['enrolled'] ?? 0 ),
					'completed'  => (int) ( $count['completed'] ?? 0 ),
					'detail_url' => add_query_arg( 'student', $uid, \Learnomy\route_url( 'instructor-students' ) ),
				);
			}
		}

		/**
		 * Filter the instructor roster rows before render. An extension can
		 * anonymise individual learner identity (name / email / avatar) while
		 * keeping the counts + metrics — a per-site privacy policy that belongs
		 * in the consuming plugin, not core. $is_admin is passed so consumers
		 * never mask data for admins (instructor scope === null means admin).
		 *
		 * @param array      $students         Roster rows.
		 * @param int[]|null $scope_course_ids Instructor course scope (null = admin).
		 * @param bool       $is_admin         Whether the viewer is an admin.
		 */
		$students = (array) apply_filters( 'learnomy_instructor_students_list', $students, $scope_course_ids, null === $scope_course_ids );

		return array(
			'mode'          => 'list',
			'students'      => $students,
			'total'         => $total,
			'page'          => $page,
			'per_page'      => self::PER_PAGE,
			'search'        => $search,
			'inactive_days' => $inactive_days,
			'base_url'      => \Learnomy\route_url( 'instructor-students' ),
			'student'       => null,
		);
	}

	/**
	 * One student's record, restricted to the instructor's own courses.
	 *
	 * @param int        $student_id       Student user id.
	 * @param int[]|null $scope_course_ids Instructor's course ids, or null for admin.
	 * @param bool       $is_admin         Whether the viewer is an admin.
	 */
	private static function resolve_detail( int $student_id, ?array $scope_course_ids, bool $is_admin ): array {
		$user = get_userdata( $student_id );
		if ( ! $user ) {
			return self::denied();
		}

		// The student's distinct enrolments, then keep only those in courses the
		// instructor actually teaches (admins keep all). An empty result means
		// this isn't the instructor's student -> deny (no cross-instructor peek).
		//
		// History view: cancelled + expired rows are INCLUDED (each row renders
		// its own status) so a teacher can still review someone whose access was
		// removed — and so removing access never silently drops a past student
		// out of the roster. The member-facing lists are entitlement-aligned
		// instead; see Enrollment::live_rows_where().
		$enrollments = Enrollment::get_distinct_for_user( $student_id, 200, 0, true );
		if ( null !== $scope_course_ids ) {
			$scope       = array_flip( array_map( 'intval', $scope_course_ids ) );
			$enrollments = array_values(
				array_filter(
					$enrollments,
					static fn( $e ) => isset( $scope[ (int) $e->course_id ] )
				)
			);
		}

		if ( empty( $enrollments ) && ! $is_admin ) {
			return self::denied();
		}

		// Batch the course titles for the rows.
		$course_ids = array_values( array_unique( array_map( static fn( $e ) => (int) $e->course_id, $enrollments ) ) );
		$course_map = array();
		foreach ( $course_ids ? Course::find_in( $course_ids ) : array() as $c ) {
			$course_map[ (int) $c->id ] = $c;
		}

		$rows = array();
		foreach ( $enrollments as $e ) {
			$course = $course_map[ (int) $e->course_id ] ?? null;
			if ( ! $course ) {
				continue;
			}
			$completed = Enrollment::is_completed( $e );
			$rows[]    = array(
				'course_title' => (string) ( $course->title ?? '' ),
				'course_url'   => \Learnomy\route_url( 'course', array( 'slug' => (string) ( $course->slug ?? '' ) ) ),
				'progress'     => (int) ( $e->progress_pct ?? 0 ),
				'completed'    => $completed,
				'status'       => (string) ( $e->status ?? 'active' ),
				'enrolled_at'  => (string) ( $e->enrolled_at ?? '' ),
			);
		}

		$student = array(
			'id'         => $student_id,
			'name'       => $user->display_name,
			'email'      => $user->user_email,
			'avatar'     => get_avatar( $student_id, 72, '', $user->display_name, array( 'class' => 'lrn-student-detail__avatar' ) ),
			'registered' => (string) $user->user_registered,
			'rows'       => $rows,
			'enrolled'   => count( $rows ),
			'completed'  => count( array_filter( $rows, static fn( $r ) => $r['completed'] ) ),
		);

		/**
		 * Filter one student's instructor-facing detail record. Companion to
		 * learnomy_instructor_students_list — lets an extension anonymise the
		 * drill-down identity for instructors while admins keep full visibility.
		 *
		 * @param array $student  The student detail record.
		 * @param bool  $is_admin Whether the viewer is an admin.
		 */
		$student = (array) apply_filters( 'learnomy_instructor_student_detail', $student, $is_admin );

		return array(
			'mode'     => 'detail',
			'base_url' => \Learnomy\route_url( 'instructor-students' ),
			'student'  => $student,
		);
	}

	/** Access-denied shell — student isn't one of the instructor's. */
	private static function denied(): array {
		return array(
			'mode'     => 'denied',
			'base_url' => \Learnomy\route_url( 'instructor-students' ),
			'student'  => null,
		);
	}
}
