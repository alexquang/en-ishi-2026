<?php
/**
 * Scope-aware announcement broadcasts — instructor + space-owner surface.
 *
 * The site-admin broadcast at POST /admin/announcements is a separate
 * endpoint with a separate permission gate (manage_options). THIS
 * controller serves the non-admin personas:
 *
 *   - Instructor sending to students of THEIR courses (audience =
 *     "instructor_courses" or "course:N" where actor authors course N)
 *   - Space Owner sending to members of THEIR space (audience =
 *     "space:N" where actor owns space N)
 *
 * Permission gating happens inside the audience parser — each scope
 * verifies actor↔target ownership before the fan-out. A misclick from
 * an instructor never broadcasts beyond their own students; a space
 * owner can never reach members outside their space.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Notification;
use Learnomy\Services\Notification_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Broadcasts_Controller extends Base_Controller {

	protected $namespace = 'learnomy/v1';
	protected $rest_base = 'broadcasts';

	public function register_routes(): void {
		// GET — read back what the caller has SENT. The route was write-only:
		// an instructor could broadcast and had no way to see their own
		// history, so a successful send and a silent failure looked identical,
		// and there was no way to check what had already been said before
		// saying it again (Basecamp 10198897839).
		//
		// The data and the grouping already existed - Notification::
		// sent_by_actor() powers the instructor dashboard's "recently sent"
		// widget, and it already returns the recipient count. Only the API was
		// missing it, which is why this is a read with no new query behind it.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'sent_broadcasts' ),
				// NOT check_audience_permission - that callback exists to
				// validate the POST's `audience` parameter, which a read does
				// not send, so reusing it rejected every GET with
				// "Unrecognized audience".
				//
				// Auth is the whole gate here because the query is scoped to
				// actor_id: a caller can only ever read announcements they
				// themselves sent. A student with no announcements gets an
				// empty list, which is the truthful answer, and no capability
				// check would make it truer.
				'permission_callback' => array( $this, 'check_can_read_own_broadcasts' ),
				'args'                => array(
					// No default: an empty string is not in the enum, so a
					// default of '' made the schema reject every request that
					// did not narrow by object - including the common one.
					'object_type' => array(
						'type' => 'string',
						'enum' => array( 'course', 'space' ),
					),
					'object_id'   => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'limit'       => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'broadcast' ),
				'permission_callback' => array( $this, 'check_audience_permission' ),
				'args'                => array(
					// Limits are counted in CHARACTERS, not bytes, because the
					// form's `maxlength` attributes (200 and 2000) count UTF-16
					// code units — so a byte cap here rejects text the browser
					// invited the instructor to type. It did: 100 Devanagari
					// characters is 300 bytes, well inside `maxlength="200"`,
					// and `strlen()` answered 400 rest_invalid_param. That hit
					// every non-Latin script and every emoji, and the message
					// field was worse — a 2000-character Hindi announcement is
					// ~6000 bytes, so it capped at roughly a third of what the
					// textarea allowed (Basecamp 10190134583).
					'title'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $v ) {
							return is_string( $v ) && '' !== trim( $v ) && mb_strlen( $v ) <= 200;
						},
					),
					'message'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'validate_callback' => static function ( $v ) {
							return is_string( $v ) && '' !== trim( $v ) && mb_strlen( $v ) <= 2000;
						},
					),
					'audience'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $v ) {
							if ( ! is_string( $v ) || '' === $v ) {
								return false;
							}
							// Allowed shapes: 'instructor_courses', 'course:NUM', 'space:NUM'.
							if ( 'instructor_courses' === $v ) {
								return true;
							}
							return (bool) preg_match( '/^(course|space):[1-9][0-9]*$/', $v );
						},
					),
					'send_email' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
	}

	/**
	 * Permission callback — branches on the `audience` param so each
	 * scope verifies actor↔target ownership before the fan-out runs.
	 *
	 * Returning WP_Error here yields a 401/403 with the documented
	 * envelope shape instead of a generic core REST 401.
	 */
	public function check_audience_permission( WP_REST_Request $request ): bool|WP_Error {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'learnomy_unauthorized',
				__( 'You must be logged in to broadcast.', 'learnomy' ),
				array( 'status' => 401 )
			);
		}

		$audience = (string) $request->get_param( 'audience' );

		// Ownership is asked through Course::ids_for_instructor(), which is the
		// only place that knows a course can be owned TWO ways: `author_id` on
		// the course row, or a `lrn_course_instructors` join row. These checks
		// used to consult CourseInstructor alone, so an author with no join row
		// was told "You do not author this course" about a course they wrote —
		// and `instructor_courses` refused outright with "You do not author any
		// courses" while My Courses, which asks the OR correctly, listed all of
		// them. The join row is only written when a create/update request
		// carries an explicit `instructors` param, so any course made without
		// it lands in exactly that state (Basecamp 10190134583).
		//
		// Limit 0 deliberately: a permission scope cut off at 100 does not deny
		// the 101st course, it silently hides it.
		if ( 'instructor_courses' === $audience ) {
			if ( empty( Course::ids_for_instructor( $user_id, 0 ) ) ) {
				return new WP_Error(
					'learnomy_no_authored_courses',
					__( 'You do not author any courses, so there is no audience to broadcast to.', 'learnomy' ),
					array( 'status' => 403 )
				);
			}
			return true;
		}

		if ( 0 === strpos( $audience, 'course:' ) ) {
			$course_id = (int) substr( $audience, 7 );
			if ( $course_id <= 0 ) {
				return new WP_Error( 'learnomy_bad_audience', __( 'Invalid course audience.', 'learnomy' ), array( 'status' => 400 ) );
			}
			if ( user_can( $user_id, 'manage_options' ) ) {
				return true;
			}
			if ( in_array( $course_id, Course::ids_for_instructor( $user_id, 0 ), true ) ) {
				return true;
			}
			return new WP_Error(
				'learnomy_forbidden',
				__( 'You do not author this course.', 'learnomy' ),
				array( 'status' => 403 )
			);
		}

		if ( 0 === strpos( $audience, 'space:' ) ) {
			$space_id = (int) substr( $audience, 6 );
			if ( $space_id <= 0 ) {
				return new WP_Error( 'learnomy_bad_audience', __( 'Invalid space audience.', 'learnomy' ), array( 'status' => 400 ) );
			}
			// Defer to a Pro-side filter — Free has no Spaces model. Pro's
			// Spaces extension implements this with its own owner check
			// (Space.owner_id + Manager-role grant). Site admins bypass
			// via the default true.
			$is_owner = (bool) apply_filters(
				'learnomy_pro_can_broadcast_to_space',
				user_can( $user_id, 'manage_options' ),
				$space_id,
				$user_id
			);
			if ( ! $is_owner ) {
				return new WP_Error(
					'learnomy_forbidden',
					__( 'You do not own this space.', 'learnomy' ),
					array( 'status' => 403 )
				);
			}
			return true;
		}

		return new WP_Error( 'learnomy_bad_audience', __( 'Unrecognized audience.', 'learnomy' ), array( 'status' => 400 ) );
	}

	/**
	 * POST /learnomy/v1/broadcasts
	 *
	 * Resolves the audience to a set of user_ids, fans out one
	 * Notification per recipient through Notification_Service, and
	 * returns a count. Reuses the same async-threshold pattern as
	 * the admin broadcast (200 recipients → Action Scheduler).
	 */
	/**
	 * GET /broadcasts — announcements the CALLER has sent, newest first.
	 *
	 * Grouped so one broadcast fanned out to N recipients counts once, and each
	 * row carries `recipients`. That count is the other half of the card: an
	 * instructor who broadcasts to a course with nobody enrolled currently gets
	 * "sent" with no indication it reached no one, and a client holding the
	 * count can say "0 students were notified" instead.
	 *
	 * Scoped to the caller's own announcements by actor_id, so this cannot
	 * surface another instructor's. Pass object_type + object_id to narrow to
	 * one course or space.
	 */
	/**
	 * Read gate for GET /broadcasts.
	 *
	 * Deliberately only asks "are you logged in". The handler scopes every
	 * query to the caller's own actor_id, so there is nothing here that a
	 * capability check would protect - and a stricter gate would mean an
	 * instructor who lost a role could no longer see what they had already
	 * sent, which is a record of their own actions.
	 */
	public function check_can_read_own_broadcasts( WP_REST_Request $request ): bool|WP_Error {
		if ( get_current_user_id() <= 0 ) {
			return new WP_Error(
				'learnomy_unauthorized',
				__( 'You must be logged in to read your announcements.', 'learnomy' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	public function sent_broadcasts( WP_REST_Request $request ): WP_REST_Response {
		$actor_id = get_current_user_id();
		$limit    = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?: 20 ) ) );

		$object_type = (string) ( $request->get_param( 'object_type' ) ?? '' );
		$object_id   = absint( $request->get_param( 'object_id' ) );

		$rows = ( '' !== $object_type && $object_id > 0 )
			? Notification::sent_by_actor_for_object( $actor_id, $object_type, $object_id, $limit )
			: Notification::sent_by_actor( $actor_id, $limit );

		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'title'       => (string) ( $row->title ?? '' ),
				'message'     => (string) ( $row->message ?? '' ),
				'object_type' => (string) ( $row->object_type ?? '' ),
				'object_id'   => (int) ( $row->object_id ?? 0 ),
				'sent_at'     => $row->created_at ?? null,
				'recipients'  => (int) ( $row->recipients ?? 0 ),
			);
		}

		return $this->single_response( array( 'broadcasts' => $out ) );
	}

	public function broadcast( WP_REST_Request $request ): WP_REST_Response {
		$title      = (string) $request->get_param( 'title' );
		$message    = (string) $request->get_param( 'message' );
		$audience   = (string) $request->get_param( 'audience' );
		$send_email = (bool) $request->get_param( 'send_email' );
		$actor_id   = get_current_user_id();

		/**
		 * Filter whether this broadcast may also go out by email.
		 *
		 * The in-app notification is always sent; this covers only the email
		 * copy, which is the more intrusive half and is therefore separately
		 * refusable. A listener may only turn the channel OFF — returning true
		 * cannot email an audience the caller did not ask to email.
		 *
		 * Pro's Spaces uses this to honour a per-space `allow_broadcast_email`
		 * setting, so hiding the checkbox and refusing the parameter are the
		 * same decision rather than two that can drift apart.
		 *
		 * @param bool   $send_email Whether to email the audience.
		 * @param string $audience   Audience token, e.g. 'space:12'.
		 * @param int    $actor_id   User sending the broadcast.
		 */
		$send_email = $send_email && (bool) apply_filters( 'learnomy_broadcast_send_email', true, $audience, $actor_id );

		$resolved = $this->resolve_audience( $audience, $actor_id );
		if ( is_wp_error( $resolved ) ) {
			return $this->error_response( $resolved );
		}

		list( $user_ids, $object_type, $object_id, $context_title ) = $resolved;

		if ( empty( $user_ids ) ) {
			return $this->error_response(
				$this->validation_error( __( 'No recipients found for the selected audience.', 'learnomy' ) )
			);
		}

		$base_data = array(
			'title'        => $title,
			'message'      => $message,
			'object_type'  => $object_type,
			'object_id'    => $object_id,
			'actor_id'     => $actor_id,
			'course_title' => $context_title,
			// Carries the sender's "Also send email" choice down to the one place
			// that decides delivery. Without it the parameter was accepted,
			// validated and then dropped: leaving the box unticked still emailed
			// every recipient, because the site-wide announcement toggle defaults
			// to on. The site toggle is still the ceiling — this can only decline.
			'send_email'   => $send_email,
		);

		// The broadcast's identity: ONE compose-time timestamp on every
		// recipient row, same as the Admin_Controller twin — recall and the
		// grouped announcement lists key on (title, created_at, object_id),
		// so worker-time timestamps splinter the broadcast and recall misses
		// recipients (Basecamp 10132809006).
		$base_data['created_at'] = \Learnomy\now();
		// ...and one identity for the whole fan-out, so recall targets the
		// broadcast itself rather than inferring it from a timestamp tuple
		// (Basecamp 10132809203).
		$base_data['broadcast_id'] = wp_generate_password( 32, false, false );

		// Fan-out through the one shared primitive — queues one Action Scheduler
		// job per recipient by default so the REST call never blocks on N
		// sequential wp_mail / push. Replaces this twin's own copy of the
		// threshold+loop that had drifted from the Admin_Controller twin.
		$service = new Notification_Service();
		$count   = $service->send_many( $user_ids, 'announcement', $base_data );

		return $this->single_response(
			array(
				'sent'   => true,
				'queued' => $service->deliver_async_enabled(),
				'count'  => $count,
			)
		);
	}

	/**
	 * Turn an audience string into ( user_ids, object_type, object_id, title ).
	 *
	 * A COUNT(*) pre-flight runs before the full ID fetch so a broadcast to
	 * 50 courses × 500 students does not allocate a 25 000-element array before
	 * the async-threshold guard has a chance to redirect traffic to Action
	 * Scheduler. If the count exceeds the hard cap (default 10 000, filterable
	 * via `learnomy_broadcast_max_audience`) the method returns a WP_Error
	 * immediately rather than letting the ID query OOM the request.
	 *
	 * @return array{0:int[],1:string,2:int,3:string}|WP_Error
	 */
	private function resolve_audience( string $audience, int $actor_id ) {
		/**
		 * Hard ceiling for a broadcast audience. Requests that resolve more
		 * distinct user IDs than this value are rejected with a 422. The operator
		 * should segment the audience (by course) or use a dedicated bulk-email
		 * tool. Default 10 000 — well above any single-course scenario while still
		 * protecting a large multi-course instructor's request from silently
		 * allocating a huge array.
		 *
		 * @param int $cap Maximum allowed audience size.
		 */
		$max_audience = (int) apply_filters( 'learnomy_broadcast_max_audience', 10000 );

		if ( 'instructor_courses' === $audience ) {
			// Same both-ways ownership question the permission check asks. When
			// this read CourseInstructor alone it could resolve an EMPTY course
			// list for an instructor the permission check had just approved,
			// which surfaced as "No recipients found for the selected audience."
			$course_ids = Course::ids_for_instructor( $actor_id, 0 );

			// Count before fetching IDs — keeps the memory footprint bounded even
			// when the instructor teaches dozens of large courses.
			$audience_count = Enrollment::count_active_for_courses( $course_ids );
			if ( $max_audience > 0 && $audience_count > $max_audience ) {
				return new WP_Error(
					'learnomy_audience_too_large',
					sprintf(
						/* translators: 1: resolved audience count, 2: maximum allowed */
						__( 'Audience is too large to broadcast in one request (%1$d recipients, limit %2$d). Broadcast to individual courses instead.', 'learnomy' ),
						$audience_count,
						$max_audience
					),
					array( 'status' => 422 )
				);
			}

			$user_ids = Enrollment::get_active_user_ids_for_courses( $course_ids );
			return array( $user_ids, 'instructor_courses', $actor_id, __( 'Your courses', 'learnomy' ) );
		}

		if ( 0 === strpos( $audience, 'course:' ) ) {
			$course_id = (int) substr( $audience, 7 );
			$user_ids  = Enrollment::get_active_user_ids( $course_id );
			$course    = Course::find( $course_id );
			$title     = $course ? (string) $course->title : __( 'Course', 'learnomy' );
			return array( $user_ids, 'course', $course_id, $title );
		}

		if ( 0 === strpos( $audience, 'space:' ) ) {
			$space_id = (int) substr( $audience, 6 );
			// Pro owns the Space_Member model; surface a filter so the
			// Spaces extension can resolve member ids without Free
			// reaching into a Pro table.
			$user_ids = (array) apply_filters( 'learnomy_pro_space_member_user_ids', array(), $space_id );
			$title    = (string) apply_filters( 'learnomy_pro_space_title', __( 'Learning Space', 'learnomy' ), $space_id );
			return array( array_map( 'intval', $user_ids ), 'space', $space_id, $title );
		}

		return new WP_Error( 'learnomy_bad_audience', __( 'Unrecognized audience.', 'learnomy' ), array( 'status' => 400 ) );
	}
}
