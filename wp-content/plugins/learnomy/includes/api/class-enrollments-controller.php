<?php
/**
 * Enrollments REST controller — enroll, unenroll, and track student access.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Services\Enrollment_Service;

class Enrollments_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'enrollments';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_my_enrollments' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => $this->get_collection_params(),
			]
		);

		// "My Courses" load-more: next page of rendered course cards for an
		// account-dashboard tab, so a learner with hundreds of courses pages
		// 40 at a time on the same URL (no slug change) instead of loading all.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/page',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_my_courses_page' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'tab'    => [
						'type'              => 'string',
						'default'           => 'all',
						'sanitize_callback' => 'sanitize_key',
					],
					'offset' => [
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/courses/(?P<course_id>[\d]+)/enroll',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'enroll' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'course_id' => [
						'type'     => 'integer',
						'required' => true,
					],
					// `source` and `plan_id` are deliberately NOT exposed —
					// the server derives both from the course's access_type
					// and the caller's verified state (completed transaction
					// or active membership). Pre-fix the public endpoint
					// trusted whatever `source` the client sent, so a student
					// could POST `source=free` on a paid course and bypass
					// payment entirely. Adapter webhook handlers call
					// `Enrollment_Service::enroll()` directly with their own
					// verified source.
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'unenroll' ],
				'permission_callback' => [ $this, 'can_unenroll' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		// The mirror of the DELETE above. Removing access is a soft cancel that
		// keeps the record for refunds + reporting, so the owner needs a way to
		// undo it that keeps the student's progress — an action with no visible
		// undo is one people are afraid to use (Basecamp 10132808967).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/restore',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'restore' ],
				'permission_callback' => [ $this, 'can_restore' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		// Admin bulk-unenroll for the Students admin page. Removes every
		// enrollment for the given user_id in one round-trip — the row-level
		// `DELETE /enrollments/{id}` route requires an enrollment_id that the
		// student-list view doesn't carry (it lists users, not rows), so the
		// previous wiring built `DELETE /enrollments/NaN` and 404'd.
		// (Basecamp #9927159497.)
		register_rest_route(
			$this->namespace,
			'/students/(?P<user_id>[\d]+)/enrollments',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'remove_all_for_user' ],
				'permission_callback' => function (): bool {
					return \Learnomy\Permissions\Permission_Engine::can( get_current_user_id(), 'manage_settings' );
				},
				'args'                => [
					'user_id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/courses/(?P<course_id>[\d]+)/students',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_students' ],
				'permission_callback' => [ $this, 'can_view_students' ],
				'args'                => array_merge(
					[
						'course_id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
					$this->get_collection_params(),
					[
						// The roster's "attention needed first" ordering has to
						// happen in SQL; a client sorting one page reorders the
						// wrong 30 rows (Basecamp D14).
						'orderby' => [
							'type'    => 'string',
							'enum'    => [ 'enrolled_at', 'progress_pct' ],
							'default' => 'enrolled_at',
						],
						'order'   => [
							'type'    => 'string',
							'enum'    => [ 'asc', 'desc', 'ASC', 'DESC' ],
							'default' => 'desc',
						],
					]
				),
			]
		);

		register_rest_route(
			$this->namespace,
			'/courses/(?P<course_id>[\d]+)/progress',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_course_progress_stats' ],
				'permission_callback' => [ $this, 'can_view_students' ],
				'args'                => [
					'course_id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * One "Load more" page of rendered course cards for a My Courses tab.
	 * Returns { html, has_more, total, offset } — the dashboard JS appends the
	 * html to the active panel and advances the offset. Cards are rendered with
	 * the same `course-card.php` partial the page uses (no card markup in JS).
	 */
	public function get_my_courses_page( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$tab     = (string) $request->get_param( 'tab' );
		$offset  = max( 0, (int) $request->get_param( 'offset' ) );

		$per = (int) apply_filters( 'learnomy_my_courses_per_page', 40 );
		$per = max( 1, min( 100, $per ) );

		if ( ! in_array( $tab, array( 'all', 'in-progress', 'completed', 'wishlist' ), true ) ) {
			$tab = 'all';
		}

		$courses   = array();
		$enroll_by = array();
		$total     = 0;
		$fetched   = 0;

		if ( 'wishlist' === $tab ) {
			$wishlist = \Learnomy\wishlist_ids( $user_id );
			$total    = count( $wishlist );
			$ids      = array_slice( $wishlist, $offset, $per );
			$fetched  = count( $ids );
			foreach ( $ids as $cid ) {
				$course = Course::find( (int) $cid );
				if ( $course && 'published' === ( $course->status ?? '' ) ) {
					$courses[] = $course;
				}
			}
		} else {
			$rows    = Enrollment::get_distinct_for_user_page( $user_id, $tab, $per, $offset );
			$total   = Enrollment::count_distinct_for_user( $user_id, $tab );
			$fetched = count( $rows );
			foreach ( $rows as $row ) {
				$course = Course::find( (int) $row->course_id );
				if ( $course ) {
					$courses[]                      = $course;
					$enroll_by[ (int) $course->id ] = $row;
				}
			}
		}

		// Render each card through the shared partial. $course / $enrollment /
		// $lrn_wishlist are the locals course-card.php reads.
		$lrn_wishlist = \Learnomy\wishlist_ids( $user_id );

		$html = '';
		foreach ( $courses as $course ) {
			$enrollment = $enroll_by[ (int) $course->id ] ?? null;
			ob_start();
			include \Learnomy\template_path( 'partials/course-card.php' );
			$html .= (string) ob_get_clean();
		}

		// Advance by rows fetched (not rendered) so a filtered-out course can't
		// shift the window and cause overlap on the next page.
		$next_offset = $offset + $fetched;

		return $this->single_response(
			array(
				'html'     => $html,
				'has_more' => $next_offset < $total,
				'total'    => $total,
				'offset'   => $next_offset,
			)
		);
	}

	public function get_my_enrollments( WP_REST_Request $request ): WP_REST_Response {
		$user_id    = get_current_user_id();
		$pagination = $this->get_pagination( $request );
		$limit      = (int) $pagination['limit'];
		$offset     = (int) $pagination['offset'];

		// DB-level pagination — the old code loaded every enrollment the user
		// ever had into memory before slicing. Now we page at the query and
		// use a separate COUNT for `meta.total`.
		$total       = Enrollment::count_for_user( $user_id );
		$enrollments = Enrollment::get_for_user( $user_id, $limit, $offset );

		// Attach course summary to each enrollment for convenience.
		$course_ids = array_unique( array_map( fn( $e ) => (int) $e->course_id, $enrollments ) );
		$courses    = [];
		foreach ( $course_ids as $cid ) {
			$course = Course::find( $cid );
			if ( $course ) {
				$courses[ $cid ] = [
					'id'        => (int) $course->id,
					'title'     => $course->title,
					'thumbnail' => $course->thumbnail_url ?? '',
					'status'    => $course->status,
				];
			}
		}

		$items = array_map(
			function ( $enrollment ) use ( $courses ) {
				$row           = (array) $enrollment;
				$row['course'] = $courses[ (int) $enrollment->course_id ] ?? null;
				return $row;
			},
			$enrollments
		);

		return $this->paginated_response( $items, $total, $offset );
	}

	public function enroll( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = get_current_user_id();
		$course_id = (int) $request->get_param( 'course_id' );

		$course = Course::find( $course_id );
		if ( ! $course || 'published' !== $course->status ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$rate_check = $this->check_rate_limit( $user_id, 'enroll' );
		if ( is_wp_error( $rate_check ) ) {
			return $this->error_response( $rate_check );
		}

		// Server-derived source — never trust the client. Free courses go in
		// directly; paid / paid_or_membership / membership courses must already
		// have proof on file (completed transaction or active subscription
		// covering this course) before this public endpoint will enroll. Paid
		// courses without proof get a 402 telling the client to use /checkout.
		$service = new Enrollment_Service();
		$source  = $this->derive_enrollment_source( $user_id, $course );
		if ( is_wp_error( $source ) ) {
			return $this->error_response( $source );
		}

		$result = $service->enroll( $user_id, $course_id, $source );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		if ( ! $result ) {
			// Either prerequisites not met or already enrolled with the same source.
			$existing = Enrollment::find_for_user_course( $user_id, $course_id );
			if ( $existing ) {
				return $this->error_response(
					$this->validation_error( __( 'You are already enrolled in this course.', 'learnomy' ) )
				);
			}
			return $this->error_response(
				$this->validation_error( __( 'You must complete the required prerequisite courses first.', 'learnomy' ) )
			);
		}

		$enrollment = Enrollment::find( $result );
		$response   = $this->single_response( Enrollment::to_typed_array( $enrollment ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Derive the enrollment source from the course's access_type plus the
	 * caller's verified state. The public REST endpoint never accepts a
	 * client-supplied source — that's how the pre-fix paid-bypass worked.
	 *
	 * Returns one of: 'free', 'purchase', 'membership' on success;
	 * `WP_Error` on access-type / proof failure.
	 */
	private function derive_enrollment_source( int $user_id, $course ) {
		$course_id   = (int) $course->id;
		// Ask the ACCESS RULE, not the raw column. MODEL_ACCESS_MAP collapses
		// `invite_only` onto `closed` precisely so both share every enforcement
		// path, but comparing the literal below made invite-only match no branch
		// at all and fall through to the "not recognised" 400 - the same failure
		// the closed branch was added to remove (Basecamp 10127614908), left in
		// place for the other half of the very rule it fixed.
		$access_type = \Learnomy\Models\Course::access_for_model(
			\Learnomy\Models\Course::pricing_model( $course )
		);

		if ( 'free' === $access_type ) {
			return 'free';
		}

		if ( 'paid' === $access_type ) {
			$txn = \Learnomy\Models\Transaction::find_completed_for_user_course( $user_id, $course_id );
			if ( $txn ) {
				return 'purchase';
			}
			// A paid-model course whose effective price is zero charges
			// nothing, so there is no transaction to demand — same answer
			// the enroll CTA gives when it renders "Enroll Now" for it.
			if ( \Learnomy\Models\Course::is_zero_charge( $course ) ) {
				return 'free';
			}
			return new \WP_Error(
				'learnomy_enroll_payment_required',
				__( 'This course requires payment. Please complete checkout first.', 'learnomy' ),
				array(
					'status'       => 402,
					'checkout_url' => \Learnomy\route_url( 'enroll' ) . '?course_id=' . $course_id,
				)
			);
		}

		if ( 'membership' === $access_type ) {
			$membership_service = new \Learnomy\Services\Subscription_Service();
			if ( $membership_service->has_access( $user_id, $course_id ) ) {
				return 'membership';
			}
			return new \WP_Error(
				'learnomy_enroll_membership_required',
				__( 'This course requires an active membership.', 'learnomy' ),
				array(
					'status'         => 402,
					'membership_url' => \Learnomy\route_url( 'membership-pricing' ),
				)
			);
		}

		if ( 'paid_or_membership' === $access_type ) {
			$membership_service = new \Learnomy\Services\Subscription_Service();
			if ( $membership_service->has_access( $user_id, $course_id ) ) {
				return 'membership';
			}
			$txn = \Learnomy\Models\Transaction::find_completed_for_user_course( $user_id, $course_id );
			if ( $txn ) {
				return 'purchase';
			}
			return new \WP_Error(
				'learnomy_enroll_payment_or_membership_required',
				__( 'This course requires payment or an active membership.', 'learnomy' ),
				array(
					'status'       => 402,
					'checkout_url' => \Learnomy\route_url( 'enroll' ) . '?course_id=' . $course_id,
				)
			);
		}

		// Closed courses are provisioned externally (LMS admin, LTI, a bulk grant)
		// and never accept a self-serve enrolment. Return a clear 403 rather than
		// falling through to the confusing "not recognised" 400 (Basecamp 10127614908).
		if ( 'closed' === $access_type ) {
			// Names no role, for the same reason the enrol card doesn't: this
			// message is read by a student on a company intranet, a school and a
			// solo creator's site, and only one of those has a "site
			// administrator" the reader could go and find. Say what is true
			// everywhere — they cannot do this themselves — and leave who to ask
			// to the site, which knows.
			return new \WP_Error(
				'learnomy_enroll_closed_course',
				__( 'This course does not accept direct enrollments. Access is granted for you rather than requested.', 'learnomy' ),
				array( 'status' => 403 )
			);
		}

		return new \WP_Error(
			'learnomy_enroll_unknown_access_type',
			/* translators: %s: invalid access_type value */
			sprintf( __( 'Course access_type "%s" is not recognised.', 'learnomy' ), $access_type ),
			array( 'status' => 400 )
		);
	}

	public function unenroll( WP_REST_Request $request ): WP_REST_Response {
		$enrollment_id = (int) $request->get_param( 'id' );
		$enrollment    = Enrollment::find( $enrollment_id );
		if ( ! $enrollment ) {
			return $this->error_response( $this->not_found( 'Enrollment' ) );
		}

		$service    = new Enrollment_Service();
		$unenrolled = $service->unenroll( $enrollment_id );

		if ( ! $unenrolled ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not remove the enrollment. Please try again.', 'learnomy' ) )
			);
		}

		return $this->single_response(
			[
				'unenrolled' => true,
				'id'         => $enrollment_id,
			]
		);
	}

	/**
	 * Undo a removal: give a cancelled / expired enrolment its access back with
	 * the student's progress and completion intact.
	 */
	public function restore( WP_REST_Request $request ): WP_REST_Response {
		$enrollment_id = (int) $request->get_param( 'id' );
		$enrollment    = Enrollment::find( $enrollment_id );
		if ( ! $enrollment ) {
			return $this->error_response( $this->not_found( 'Enrollment' ) );
		}

		if ( ! ( new Enrollment_Service() )->restore_access( $enrollment_id ) ) {
			// The only non-error reason restore_access() declines is "already
			// live", so say that rather than a generic failure — two admins on
			// the same row must not read as a broken button.
			return $this->error_response(
				$this->validation_error( __( 'This student already has access to the course.', 'learnomy' ) )
			);
		}

		$restored = Enrollment::find( $enrollment_id );

		return $this->single_response(
			[
				'restored' => true,
				'id'       => $enrollment_id,
				'status'   => (string) ( $restored->status ?? '' ),
			]
		);
	}

	/**
	 * Admin Remove-from-Students for the wp-admin Students list.
	 *
	 * Drops every enrollment row for the user AND removes the `lrn_student`
	 * role so the user vanishes from the Students list (the list is a
	 * WP_User_Query keyed off `role=lrn_student`). The WP user account
	 * itself stays — re-enrolling on a course re-adds the role automatically
	 * via the existing Enrollment_Service flow.
	 *
	 * Mirror of the per-row DELETE /enrollments/{id} route, but keyed off
	 * `user_id` because the Students list is per-user (not per-enrollment)
	 * and never had the row IDs to send the granular call. See Basecamp
	 * #9927159497.
	 */
	public function remove_all_for_user( WP_REST_Request $request ): WP_REST_Response {
		$user_id = (int) $request->get_param( 'user_id' );
		if ( $user_id <= 0 ) {
			return $this->error_response( $this->validation_error( __( 'A user_id is required.', 'learnomy' ) ) );
		}

		$count = Enrollment::delete_for_user( $user_id );

		$user         = get_user_by( 'ID', $user_id );
		$role_removed = false;
		if ( $user instanceof \WP_User && in_array( 'lrn_student', (array) $user->roles, true ) ) {
			$user->remove_role( 'lrn_student' );
			$role_removed = true;
		}

		return $this->single_response(
			[
				'user_id'      => $user_id,
				'removed'      => $count,
				'role_removed' => $role_removed,
			]
		);
	}

	public function get_students( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'course_id' );
		$course    = Course::find( $course_id );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$pagination = $this->get_pagination( $request );
		$limit      = (int) $pagination['limit'];
		$offset     = (int) $pagination['offset'];

		// Page at the DB level — a popular course can have 50k enrollments,
		// far too many to slice in PHP.
		$total       = Enrollment::count_for_course( $course_id );
		$enrollments = Enrollment::get_for_course(
			$course_id,
			$limit,
			$offset,
			(string) $request->get_param( 'orderby' ),
			(string) $request->get_param( 'order' )
		);

		// Batch-load student display names.
		$user_ids = array_map( fn( $e ) => (int) $e->user_id, $enrollments );
		$users    = $this->batch_load_users( $user_ids );

		// Email opt-in — only when the caller explicitly asks for it AND
		// holds `manage_options` (site admins, not just course instructors).
		// Default response omits the column so the typical instructor
		// view of the student list doesn't ship customer PII over the
		// wire. Site admins doing CSV exports / payroll / GDPR work pass
		// `?include=emails` to opt back in. (Basecamp #9928063949.)
		$include      = (string) ( $request->get_param( 'include' ) ?? '' );
		$include_keys = $include ? array_map( 'trim', explode( ',', $include ) ) : array();
		$expose_email = in_array( 'emails', $include_keys, true )
			&& \Learnomy\Permissions\Permission_Engine::can( get_current_user_id(), 'manage_settings' );

		$items = array_map(
			function ( $enrollment ) use ( $users, $expose_email ) {
				$row                   = (array) $enrollment;
				$user                  = $users[ (int) $enrollment->user_id ] ?? null;
				$row['student_name']   = $user ? $user->display_name : '';
				$row['student_avatar'] = $user ? get_avatar_url( (int) $enrollment->user_id, [ 'size' => 48 ] ) : '';
				if ( $expose_email ) {
					$row['student_email'] = $user ? $user->user_email : '';
				}
				return $row;
			},
			$enrollments
		);

		/**
		 * Filter the REST course-students rows before the response. Lets an
		 * extension anonymise learner identity (name / avatar; emails are
		 * already admin-gated via ?include=emails) for instructor-facing API
		 * consumers, a per-site privacy policy that lives outside core.
		 *
		 * @param array $items     Student rows.
		 * @param int   $course_id Course id.
		 * @param int   $user_id   Current viewer id.
		 */
		$items = (array) apply_filters( 'learnomy_course_students_rest_items', $items, $course_id, get_current_user_id() );

		// `completed` as a COUNT(*), not a tally of the page in hand. The app was
		// counting completions across LOADED pages, so the figure climbed as the
		// instructor scrolled and was only right at the last page (D14).
		return $this->paginated_response(
			$items,
			$total,
			$offset,
			null,
			[ 'completed' => Enrollment::count_completed_for_course( $course_id ) ]
		);
	}

	public function get_course_progress_stats( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'course_id' );
		$course    = Course::find( $course_id );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		// Compute buckets in SQL rather than loading every enrollment into PHP
		// and iterating — at scale this endpoint was hitting PHP memory limits
		// for popular courses. Uses the existing funnel helper plus a targeted
		// AVG query for the progress mean.
		$funnel = Enrollment::completion_funnel( array( $course_id ) );
		// Distinct people on the same active+completed basis as the funnel, so the
		// not_started = total - completed - in_progress math is coherent and a
		// multi-source student is not counted twice (was count_for_course = rows,
		// all statuses).
		$total_students = Enrollment::count_distinct_students( array( $course_id ) );

		if ( 0 === $total_students ) {
			return $this->single_response(
				[
					'course_id'        => $course_id,
					'total_students'   => 0,
					'completed'        => 0,
					'in_progress'      => 0,
					'not_started'      => 0,
					'avg_progress_pct' => 0,
				]
			);
		}

		$completed   = (int) $funnel['completed'];
		$in_progress = (int) $funnel['started'];
		$not_started = max( 0, $total_students - $completed - $in_progress );
		$avg         = Enrollment::avg_progress_for_course( $course_id );

		return $this->single_response(
			[
				'course_id'        => $course_id,
				'total_students'   => $total_students,
				'completed'        => $completed,
				'in_progress'      => $in_progress,
				'not_started'      => $not_started,
				'avg_progress_pct' => $avg,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function can_unenroll( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$enrollment_id = (int) $request->get_param( 'id' );
		$enrollment    = Enrollment::find( $enrollment_id );

		if ( ! $enrollment ) {
			return $this->permission_error();
		}

		// Students can remove their own enrollment; instructors can remove any.
		if ( (int) $enrollment->user_id === $user_id ) {
			return true;
		}

		if ( $this->check_permission( 'manage_course', (int) $enrollment->course_id ) ) {
			return true;
		}

		return $this->permission_error();
	}

	/**
	 * Restoring access is a grant, so — unlike unenroll — the student may NOT
	 * do it to their own row. Only someone who can manage the course (admins
	 * short-circuit inside Permission_Engine) can hand access back, or a
	 * learner could reverse their own removal and walk back through the paywall.
	 */
	public function can_restore( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$enrollment = Enrollment::find( (int) $request->get_param( 'id' ) );
		if ( ! $enrollment ) {
			return $this->permission_error();
		}

		if ( ! $this->check_permission( 'manage_course', (int) $enrollment->course_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	public function can_view_students( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$course_id = (int) $request->get_param( 'course_id' );
		if ( ! $this->check_permission( 'manage_course', $course_id ) ) {
			return $this->permission_error();
		}

		return true;
	}
}
