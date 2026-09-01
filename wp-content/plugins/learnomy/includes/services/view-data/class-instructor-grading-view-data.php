<?php
/**
 * View-data resolver for the instructor Grading inbox (/instructor/grading/).
 *
 * Two modes from one route, exactly like the wp-admin screen it mirrors:
 *   - Queue: submitted attempts still owed a manual grade (`?status=pending`,
 *     the default) or attempts a human has already graded (`?status=graded`),
 *     paginated, optionally narrowed to one course.
 *   - Grader (`?attempt=N`): one attempt's answers, with a bounded score input
 *     and feedback box on every ungraded response.
 *
 * Every scope and permission decision is delegated to {@see Grading_Service} so
 * this page can never show an attempt the REST write route would refuse a grade
 * for. This class does no DB work of its own.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Services\Grading_Service;

class Instructor_Grading_View_Data {

	private const PER_PAGE = 20;

	/**
	 * @param array $data Route data (unused).
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		unset( $data );

		$user_id = get_current_user_id();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET navigation.
		$attempt_id = isset( $_GET['attempt'] ) ? absint( wp_unslash( $_GET['attempt'] ) ) : 0;
		$course_id  = isset( $_GET['course'] ) ? absint( wp_unslash( $_GET['course'] ) ) : 0;
		$status     = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : Grading_Service::STATUS_PENDING;
		// The /instructor/grading/page/N/ rewrite rule sets lrn_page; current_page()
		// also covers the legacy ?lrn_page= form.
		$page = \Learnomy\current_page();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( $attempt_id > 0 ) {
			return self::resolve_grader( $attempt_id, $user_id );
		}

		return self::resolve_queue( $user_id, Grading_Service::normalize_status( $status ), $course_id, $page );
	}

	/**
	 * The queue. Ownership scope first, then the optional course narrowing —
	 * a course the caller does not teach yields nothing, never that course's
	 * work.
	 *
	 * @param int    $user_id   Viewer.
	 * @param string $status    'pending' or 'graded'.
	 * @param int    $course_id Course filter (0 = all).
	 * @param int    $page      1-based page.
	 */
	private static function resolve_queue( int $user_id, string $status, int $course_id, int $page ): array {
		$scope     = Grading_Service::scope_course_ids( $user_id );
		$effective = Grading_Service::apply_course_filter( $course_id, $scope );

		// An instructor on no courses has no queue and no grader role to play
		// here. Say so plainly instead of rendering an empty table that reads
		// as "your students have not submitted anything".
		if ( is_array( $scope ) && empty( $scope ) ) {
			return array(
				'mode'     => 'denied',
				'status'   => $status,
				'base_url' => \Learnomy\route_url( 'instructor-grading' ),
			);
		}

		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * self::PER_PAGE;
		$queue  = Grading_Service::queue( $effective, $status, self::PER_PAGE, $offset );

		// Batch the student lookups so the row loop issues no per-row queries.
		$student_ids = array_values( array_unique( array_map( static fn( $r ) => (int) $r->user_id, $queue['rows'] ) ) );
		$students    = array();
		if ( ! empty( $student_ids ) ) {
			$user_query = new \WP_User_Query(
				array(
					'include' => $student_ids,
					'number'  => count( $student_ids ),
					'fields'  => array( 'ID', 'display_name' ),
				)
			);
			foreach ( $user_query->get_results() as $student ) {
				$students[ (int) $student->ID ] = (string) $student->display_name;
			}
		}

		$base_url = \Learnomy\route_url( 'instructor-grading' );
		$rows     = array();

		foreach ( $queue['rows'] as $row ) {
			$student_id = (int) $row->user_id;
			$rows[]     = array(
				'attempt_id'     => (int) $row->attempt_id,
				'quiz_title'     => (string) $row->quiz_title,
				'student_name'   => $students[ $student_id ] ?? __( 'Deleted user', 'learnomy' ),
				'student_avatar' => get_avatar( $student_id, 40, '', '', array( 'class' => 'lrn-admin-identity__avatar' ) ),
				'submitted_at'   => (string) ( $row->submitted_at ?? '' ),
				'graded_at'      => (string) ( $row->graded_at ?? '' ),
				'ungraded_count' => (int) ( $row->ungraded_count ?? 0 ),
				'graded_count'   => (int) ( $row->graded_count ?? 0 ),
				'grade_url'      => add_query_arg( 'attempt', (int) $row->attempt_id, $base_url ),
			);
		}

		return array(
			'mode'           => 'queue',
			'status'         => $status,
			'rows'           => $rows,
			'total'          => (int) $queue['total'],
			'page'           => $page,
			'per_page'       => self::PER_PAGE,
			'course_id'      => $course_id,
			'filter_courses' => Grading_Service::filter_courses( $user_id, $scope ),
			'base_url'       => $base_url,
			'pending_url'    => self::queue_url( $base_url, Grading_Service::STATUS_PENDING, $course_id ),
			'graded_url'     => self::queue_url( $base_url, Grading_Service::STATUS_GRADED, $course_id ),
		);
	}

	/**
	 * One attempt's answers. Returns the denied shell when the attempt is gone
	 * or belongs to another instructor's course — the same answer the wp-admin
	 * grader and the REST grade route give.
	 *
	 * @param int $attempt_id Attempt to grade.
	 * @param int $user_id    Grader.
	 */
	private static function resolve_grader( int $attempt_id, int $user_id ): array {
		$base_url = \Learnomy\route_url( 'instructor-grading' );
		$detail   = Grading_Service::attempt_detail( $attempt_id, $user_id );

		if ( null === $detail ) {
			return array(
				'mode'     => 'denied',
				'status'   => Grading_Service::STATUS_PENDING,
				'base_url' => $base_url,
			);
		}

		$student = $detail['student'];

		return array(
			'mode'              => 'grader',
			'status'            => Grading_Service::STATUS_PENDING,
			'base_url'          => $base_url,
			'attempt_id'        => (int) $detail['attempt']->id,
			'quiz_title'        => (string) $detail['quiz']->title,
			'student_name'      => $student ? (string) $student->display_name : __( 'Deleted user', 'learnomy' ),
			'submitted_at'      => (string) ( $detail['attempt']->submitted_at ?? '' ),
			'rows'              => $detail['rows'],
			'pending_count'     => (int) $detail['pending_count'],
			'ip_address'        => (string) $detail['ip_address'],
			'tab_tracking'      => (bool) $detail['tab_tracking'],
			'tab_switches'      => (int) $detail['tab_switches'],
			'tab_flagged'       => (bool) $detail['tab_flagged'],
			'exam_late'         => (bool) $detail['exam_late'],
			'exam_late_minutes' => (int) $detail['exam_late_minutes'],
		);
	}

	/**
	 * Canonical queue URL for a status + course pair, so the tabs and the course
	 * filter never drop each other's state on navigation.
	 *
	 * @param string $base_url  Route base.
	 * @param string $status    Queue status.
	 * @param int    $course_id Active course filter (0 = all).
	 */
	private static function queue_url( string $base_url, string $status, int $course_id ): string {
		$args = array();
		if ( Grading_Service::STATUS_PENDING !== $status ) {
			$args['status'] = $status;
		}
		if ( $course_id > 0 ) {
			$args['course'] = $course_id;
		}

		return $args ? add_query_arg( $args, $base_url ) : $base_url;
	}
}
