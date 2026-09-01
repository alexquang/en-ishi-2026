<?php
/**
 * Member credentials REST controller — a member's PUBLIC Learnomy standing for
 * external consumers (the BuddyNext portfolio, the native app): the courses they
 * have completed (certified when a certificate was issued) and the published
 * courses they teach. Read-only and public — it exposes only published/completed
 * data, the same rules the profile surfaces already apply — so a member's
 * credentials render from one REST source on web and app alike.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Certificate;
use Learnomy\Models\Course;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\Enrollment;
use Learnomy\Permissions\Permission_Engine;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Member_Credentials_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'members';
	}

	public function register_routes(): void {
		// A member's completed courses (one per course; certified when any
		// certificate was issued for it).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/certifications',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_certifications' ],
				'permission_callback' => '__return_true',
				'args'                => $this->member_args(),
			]
		);

		// The published courses a member teaches (instructor = a credential).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/teaching',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_teaching' ],
				'permission_callback' => '__return_true',
				'args'                => $this->member_args(),
			]
		);

		// The member's currently-enrolled (in-progress) courses — active
		// enrolments that have not yet completed, each with its progress. Unlike
		// certifications/teaching this is a "continue learning" shelf, not a public
		// credential: in-progress courses and completion percentages are private
		// study data. It is therefore the ONE route on this otherwise-public
		// controller that carries a permission gate — see permission_self_or_staff().
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/enrolled',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_enrolled' ],
				'permission_callback' => [ $this, 'permission_self_or_staff' ],
				'args'                => $this->member_args(),
			]
		);
	}

	/**
	 * Gate for the "continue learning" shelf: the member themselves, or staff who
	 * may already list students (admins, and instructors through
	 * Permission_Engine). Everyone else — including logged-out visitors — is
	 * refused.
	 *
	 * Why this route and not its siblings: /certifications and /teaching publish
	 * credentials a member has earned, which the profile already shows to the
	 * world. /enrolled publishes what someone is *part-way through*, which it does
	 * not. Shipping it on this controller's file-level __return_true allowlist
	 * entry made every member's in-progress courses and progress percentages
	 * readable by anonymous callers (1.6.0); the gate below is that fix, and
	 * `security/11-member-enrolled-owner-gate.md` is its regression sentinel.
	 *
	 * MUST be public — WP REST's call_user_func cannot invoke a protected method
	 * (same constraint documented on Base_Controller::permission_logged_in()).
	 */
	public function permission_self_or_staff( WP_REST_Request $request ): bool|WP_Error {
		$current = $this->require_auth();
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$member_id = (int) $request['id'];
		if ( $current === $member_id ) {
			return true;
		}

		if ( Permission_Engine::can( $current, 'list-students' ) ) {
			return true;
		}

		return $this->permission_error();
	}

	/**
	 * Shared route args: the member id + an optional page size.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function member_args(): array {
		return [
			'id'       => [
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			],
			'per_page' => [
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 50,
				'sanitize_callback' => 'absint',
			],
		];
	}

	/**
	 * GET /members/{id}/certifications — completed courses as credentials.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_certifications( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = (int) $request['id'];
		$per_page = (int) $request['per_page'];
		if ( $user_id <= 0 ) {
			return $this->empty_panel();
		}

		// "Completed" is defined ONCE, by the model. This route used to test
		// `status = 'completed'` in its own SQL, which is narrower than the
		// canonical rule (status OR completed_at OR progress_pct = 100) — so a
		// learner who finished every lesson of a lessons_and_quiz course without
		// passing the quiz sat at progress_pct=100/status='active' and showed as
		// Completed in My Courses while this panel said otherwise. Basecamp
		// #10114604236.
		// published_only: this route is PUBLIC, so a completed course the owner has
		// since unpublished must not surface its title to anonymous callers.
		$total       = Enrollment::count_distinct_for_user( $user_id, 'completed', true );
		$enrollments = Enrollment::get_distinct_for_user_page( $user_id, 'completed', $per_page, 0, true );

		return $this->course_panel(
			$user_id,
			$enrollments,
			// Completed courses link to their certificate when one was issued.
			function ( object $course, array $certificates ): array {
				$uuid      = $certificates[ (int) $course->id ] ?? '';
				$certified = '' !== $uuid;

				return [
					'course_id' => (int) $course->id,
					'title'     => (string) $course->title,
					'certified' => $certified,
					'image'     => (string) ( $course->thumbnail_url ?? '' ),
					'url'       => $certified
						? $this->route( 'certificate-verify', [ 'slug' => $uuid ] )
						: $this->route( 'course', [ 'slug' => (string) $course->slug ] ),
				];
			},
			true,
			$total
		);
	}

	/**
	 * GET /members/{id}/teaching — published courses the member teaches.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_teaching( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = (int) $request['id'];
		$per_page = (int) $request['per_page'];
		if ( $user_id <= 0 ) {
			return $this->empty_panel();
		}

		$total      = CourseInstructor::count_published_for_user( $user_id );
		$course_ids = CourseInstructor::published_course_ids_for_user( $user_id, $per_page );
		$courses    = $this->courses_in_order( $course_ids );

		$items = [];
		foreach ( $courses as $course ) {
			$items[] = [
				'title' => (string) $course->title,
				'image' => (string) ( $course->thumbnail_url ?? '' ),
				'url'   => $this->route( 'course', [ 'slug' => (string) $course->slug ] ),
			];
		}

		return new WP_REST_Response(
			[
				'items' => $items,
				'total' => $total,
			],
			200
		);
	}

	/**
	 * GET /members/{id}/enrolled — the member's in-progress courses.
	 *
	 * Active enrolments (not yet completed) in published courses, one row per
	 * course (a course enrolled via both membership and purchase appears once),
	 * each carrying its 0-100 progress so the consumer can render a "resume"
	 * shelf. Ordered most-recently-enrolled first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_enrolled( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = (int) $request['id'];
		$per_page = (int) $request['per_page'];
		if ( $user_id <= 0 ) {
			return $this->empty_panel();
		}

		// Same single definition as the Completed panel and the My Courses tabs —
		// see get_certifications(). This route previously tested `status = 'active'`,
		// which counts a progress_pct=100 learner as still in progress while every
		// other surface calls them finished.
		$total       = Enrollment::count_distinct_for_user( $user_id, 'in-progress', true );
		$enrollments = Enrollment::get_distinct_for_user_page( $user_id, 'in-progress', $per_page, 0, true );

		return $this->course_panel(
			$user_id,
			$enrollments,
			function ( object $course, array $certificates, object $enrollment ): array {
				return [
					'title'    => (string) $course->title,
					'image'    => (string) ( $course->thumbnail_url ?? '' ),
					'url'      => $this->route( 'course', [ 'slug' => (string) $course->slug ] ),
					'progress' => (int) ( $enrollment->progress_pct ?? 0 ),
				];
			},
			false,
			$total
		);
	}

	/**
	 * Shape one page of enrollment rows into a panel payload.
	 *
	 * Hydrates every course in ONE batched read and (when asked) every
	 * certificate in one more, so a 50-row page costs two queries rather than
	 * 100. Enrollment order is preserved — the models return newest-first and
	 * the panels render in that order.
	 *
	 * @param int      $user_id      Member.
	 * @param array    $enrollments  Rows from the model.
	 * @param callable $shape        Row builder.
	 * @param bool     $with_certs   Look up certificates.
	 * @param int      $total        Total for the badge.
	 */
	private function course_panel( int $user_id, array $enrollments, callable $shape, bool $with_certs, int $total ): WP_REST_Response {
		$by_course = [];
		foreach ( $enrollments as $enrollment ) {
			$by_course[ (int) $enrollment->course_id ] = $enrollment;
		}

		$courses      = $this->courses_in_order( array_keys( $by_course ) );
		$certificates = $with_certs
			? Certificate::active_uuids_for_user_courses( $user_id, array_keys( $by_course ) )
			: [];

		$items = [];
		foreach ( $courses as $course ) {
			$items[] = $shape( $course, $certificates, $by_course[ (int) $course->id ] );
		}

		return new WP_REST_Response(
			[
				'items' => $items,
				'total' => $total,
			],
			200
		);
	}

	/**
	 * Batch-load courses and return them in the order the ids were given, which
	 * is the order the model chose (newest enrolment / newest course first).
	 * Course::find_in() returns an id-keyed map, so without this the panels would
	 * silently re-order to whatever the map iteration yields.
	 *
	 * @param array $course_ids Ordered course ids.
	 * @return array
	 */
	private function courses_in_order( array $course_ids ): array {
		if ( empty( $course_ids ) ) {
			return [];
		}

		$found  = Course::find_in( $course_ids );
		$ranked = [];
		foreach ( $course_ids as $course_id ) {
			if ( isset( $found[ $course_id ] ) ) {
				$ranked[] = $found[ $course_id ];
			}
		}

		return $ranked;
	}

	/**
	 * The uniform empty payload every panel returns for an unresolvable member.
	 */
	private function empty_panel(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'items' => [],
				'total' => 0,
			],
			200
		);
	}

	/**
	 * Resolve a Learnomy front URL, or '' when the route helper is unavailable.
	 *
	 * @param string              $name Route name.
	 * @param array<string,mixed> $args Route args.
	 * @return string
	 */
	private function route( string $name, array $args ): string {
		return function_exists( '\\Learnomy\\route_url' ) ? (string) \Learnomy\route_url( $name, $args ) : '';
	}
}
