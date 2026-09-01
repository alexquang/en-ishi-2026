<?php
/**
 * Reviews REST controller — course ratings and feedback from enrolled students.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\Models\Review;
use Learnomy\Models\Course;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Blocked_User;
use Learnomy\Services\Review_Service;

class Reviews_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'reviews';
	}

	public function register_routes(): void {
		// List reviews for a course (public).
		register_rest_route(
			$this->namespace,
			'/courses/(?P<course_id>[\d]+)/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_course_reviews' ],
					'permission_callback' => '__return_true',
					'args'                => array_merge(
						$this->get_collection_params(),
						[
							'course_id' => [
								'type'     => 'integer',
								'required' => true,
							],
						]
					),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'submit_review' ],
					'permission_callback' => [ $this, 'can_review' ],
					'args'                => [
						'course_id' => [
							'type'     => 'integer',
							'required' => true,
						],
						'rating'    => [
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
							'maximum'  => 5,
						],
						// maxLength is what turns an unbounded free-text field into
						// a bounded one at the BOUNDARY: core answers 400 with a
						// readable message before a single row is touched. The
						// title ceiling is the varchar(255) column width, because
						// wpdb refuses (not truncates) an over-length value and
						// the refused insert used to surface as an HTTP 500.
						// `validate_callback` is NOT redundant here. WP only falls
						// back to `rest_parse_request_arg` (which validates the
						// schema keywords) when an arg declares NO
						// sanitize_callback — see WP_REST_Request::sanitize_params().
						// Naming a sanitizer therefore silently disables maxLength
						// unless the validator is named too, which is exactly why
						// `content` (no sanitizer) rejected at the boundary while
						// `title` fell through to the service.
						'title'     => [
							'type'              => 'string',
							'maxLength'         => Review::MAX_TITLE_LENGTH,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'content'   => [
							'type'      => 'string',
							'required'  => true,
							'maxLength' => Review::MAX_CONTENT_LENGTH,
						],
					],
				],
			]
		);

		// Load-more page for the single-course reviews section. Returns the
		// reviews rendered through the SAME `review-card.php` partial the page
		// uses (so the markup never drifts) plus has_more/total, mirroring the
		// `/enrollments/page` pattern. Public — reviews are public; the reply
		// affordance only renders for the course owner / admin.
		register_rest_route(
			$this->namespace,
			'/courses/(?P<course_id>[\d]+)/' . $this->rest_base . '/page',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_course_reviews_page' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'course_id' => [
						'type'     => 'integer',
						'required' => true,
					],
					'offset'    => [
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					],
				],
			]
		);

		// Update or delete a single review.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_review' ],
					'permission_callback' => [ $this, 'can_edit_review' ],
					'args'                => [
						'id'      => [
							'type'     => 'integer',
							'required' => true,
						],
						'rating'  => [
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 5,
						],
						'title'   => [
							'type'              => 'string',
							'maxLength'         => Review::MAX_TITLE_LENGTH,
							// See the POST arm: a named sanitizer suppresses the
							// default schema validation, so name the validator too.
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'content' => [
							'type'      => 'string',
							'maxLength' => Review::MAX_CONTENT_LENGTH,
						],
						'reply'   => [
							'type'        => 'string',
							'maxLength'   => Review::MAX_REPLY_LENGTH,
							'description' => __( 'Instructor reply to the review.', 'learnomy' ),
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_review' ],
					'permission_callback' => [ $this, 'can_delete_review' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);

		// PATCH /admin/reviews/{id}/status — single-row status flip from the
		// admin moderation page. Distinct from the per-row PATCH above so the
		// audit trail records "moderation action" vs "edit the row".
		register_rest_route(
			$this->namespace,
			'/admin/' . $this->rest_base . '/(?P<id>\d+)/status',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'admin_update_status' ],
				'permission_callback' => [ $this, 'require_manage' ],
				'args'                => [
					'id'     => [
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
					],
					'status' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'approved', 'pending', 'rejected', 'spam' ),
					],
				],
			]
		);

		// POST /admin/reviews/bulk — bulk apply a moderation action across many
		// rows. Returns the list of ids actually processed so the UI can
		// reconcile when some rows were already gone or failed the action.
		register_rest_route(
			$this->namespace,
			'/admin/' . $this->rest_base . '/bulk',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'admin_bulk_action' ],
				'permission_callback' => [ $this, 'require_manage' ],
				'args'                => [
					'ids'    => [
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					],
					'action' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'approve', 'reject', 'spam', 'pending', 'delete' ),
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	public function get_course_reviews( WP_REST_Request $request ): WP_REST_Response {
		$course_id  = absint( $request->get_param( 'course_id' ) );
		$pagination = $this->get_pagination( $request );
		$page       = max( 1, (int) ( $pagination['offset'] / $pagination['limit'] ) + 1 );

		// App Store 1.2: drop reviews authored by anyone the viewer has blocked,
		// from both the page and the total so pagination stays honest.
		$blocked = Blocked_User::blocked_ids_for( get_current_user_id() );

		$reviews = Review::get_for_course( $course_id, $page, $pagination['limit'], $blocked );

		$includes = $this->get_includes( $request );
		if ( in_array( 'author', $includes, true ) ) {
			$reviews = $this->enrich_with_author( $reviews, 'user_id' );
		}

		// viewer.has_reported — so a member who already reported a review sees
		// "Reported" instead of being offered the action again.
		//
		// Without it the app re-offered Report on a review the member had
		// already flagged, and the honest reading for them was that their
		// report did nothing (Basecamp 10198897839). The web templates were
		// fixed the same way earlier in this release; this is the same state on
		// the API so the two surfaces agree.
		//
		// One query for the page: AuditLog::reported_ids() loads everything this
		// user has reported OF THIS TYPE once per request, so the per-row test
		// below is an isset() and not a lookup.
		$reported = \Learnomy\Models\AuditLog::reported_ids( get_current_user_id(), 'review' );
		foreach ( $reviews as $review ) {
			$viewer = (array) ( $review->viewer ?? array() );
			// Merged into an existing viewer block rather than added as a
			// sibling key, so every per-viewer fact stays in one place on the row.
			$viewer['has_reported'] = isset( $reported[ (int) $review->id ] );
			$review->viewer         = $viewer;
		}

		// ETag AFTER the viewer block is attached. Generated before it, two
		// members with different report states would share a cache entry and the
		// second would be told 304 with the first member's flags.
		$etag         = $this->generate_etag( $reviews );
		$not_modified = $this->maybe_not_modified( $etag, $request );
		if ( $not_modified ) {
			return $not_modified;
		}

		$response = $this->paginated_response(
			$reviews,
			Review::count_for_course( $course_id, $blocked ),
			(int) $pagination['offset']
		);

		return $this->add_etag( $response, $etag );
	}

	/**
	 * GET /courses/{course_id}/reviews/page — the load-more page for the
	 * single-course reviews section. Renders each approved review through the
	 * shared `review-card.php` partial (no JS-side card markup to drift) and
	 * returns has_more/total/offset so the button can advance and hide itself.
	 * Per-page size matches the page-load fetch (10) via a filter both sides read.
	 */
	public function get_course_reviews_page( WP_REST_Request $request ): WP_REST_Response {
		$course_id = absint( $request->get_param( 'course_id' ) );
		$offset    = max( 0, (int) $request->get_param( 'offset' ) );

		$per_page = (int) apply_filters( 'learnomy_course_reviews_per_page', 10, $course_id );
		$per_page = max( 1, min( 50, $per_page ) );

		$page = (int) floor( $offset / $per_page ) + 1;

		// App Store 1.2: drop reviews by anyone the viewer has blocked, from both
		// the page and the total, matching get_course_reviews() — otherwise a
		// blocked author reappears when the member clicks "load more".
		$blocked = Blocked_User::blocked_ids_for( get_current_user_id() );

		$reviews = Review::get_for_course( $course_id, $page, $per_page, $blocked );
		$total   = Review::count_for_course( $course_id, $blocked );

		// Pre-fetch reviewer WP_User objects in one query (review-card.php reads
		// $reviewer_map to avoid an N+1 of get_userdata() per card).
		$reviewer_ids = array_filter( array_unique( array_map( static fn( $r ) => (int) $r->user_id, $reviews ) ) );
		$reviewer_map = array();
		if ( $reviewer_ids ) {
			foreach ( get_users(
				array(
					'include' => $reviewer_ids,
					'number'  => count( $reviewer_ids ),
				)
			) as $ru ) {
				$reviewer_map[ (int) $ru->ID ] = $ru;
			}
		}

		// The reply affordance renders only for the course owner / admin — the
		// same rule the page applies (Single_Course_View_Data::$can_reply_reviews).
		$user_id   = get_current_user_id();
		$course    = Course::find( $course_id );
		$can_reply = $user_id > 0
			&& (
				current_user_can( 'manage_options' )
				|| ( $course && (int) ( $course->author_id ?? 0 ) === $user_id )
			);

		$GLOBALS['lrn_can_reply_reviews'] = $can_reply;
		$html                             = '';
		foreach ( $reviews as $review ) {
			ob_start();
			include \Learnomy\template_path( 'partials/review-card.php' );
			$html .= (string) ob_get_clean();
		}
		unset( $GLOBALS['lrn_can_reply_reviews'] );

		$next_offset = $offset + count( $reviews );

		return $this->single_response(
			array(
				'html'     => $html,
				'has_more' => $next_offset < $total,
				'total'    => $total,
				'offset'   => $next_offset,
			)
		);
	}

	public function submit_review( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$rate_check = $this->check_rate_limit( $user_id, 'leave_review' );
		if ( is_wp_error( $rate_check ) ) {
			return $this->error_response( $rate_check );
		}

		$course_id = absint( $request->get_param( 'course_id' ) );
		$rating    = (int) $request->get_param( 'rating' );
		$title     = sanitize_text_field( wp_unslash( $request->get_param( 'title' ) ?? '' ) );
		$content   = wp_kses_post( wp_unslash( $request->get_param( 'content' ) ) );

		if ( Review::has_reviewed( $user_id, $course_id ) ) {
			return $this->error_response(
				$this->validation_error( __( 'You have already submitted a review for this course.', 'learnomy' ) )
			);
		}

		try {
			$service   = new Review_Service();
			$review_id = $service->submit( $user_id, $course_id, $rating, $title, $content );
		} catch ( \RuntimeException $e ) {
			return $this->error_response( $this->validation_error( $e->getMessage() ) );
		}

		$response = $this->single_response( Review::to_typed_array( Review::find( $review_id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_review( WP_REST_Request $request ): WP_REST_Response {
		$review = Review::find( (int) $request->get_param( 'id' ) );
		if ( ! $review ) {
			return $this->error_response( $this->not_found( 'Review' ) );
		}

		$user_id  = get_current_user_id();
		$is_own   = (int) $review->user_id === $user_id;
		$is_admin = user_can( $user_id, 'manage_options' );

		$data = [];

		// Students can update their rating and text; admins/instructors can post a reply.
		if ( $is_own || $is_admin ) {
			$rating = $request->get_param( 'rating' );
			if ( null !== $rating ) {
				$data['rating'] = max( 1, min( 5, (int) $rating ) );
			}

			$title = $request->get_param( 'title' );
			if ( null !== $title ) {
				$data['title'] = sanitize_text_field( wp_unslash( $title ) );
			}

			$content = $request->get_param( 'content' );
			if ( null !== $content ) {
				$data['content'] = wp_kses_post( wp_unslash( $content ) );
			}
		}

		// Instructor reply — requires admin OR the current user must be an
		// assigned instructor on THIS review's course. The prior gate only
		// checked the global `learnomy_manage_own_courses` cap, which every
		// instructor holds, so any instructor could reply to any review on
		// any course (cross-course tampering / IDOR).
		$reply = $request->get_param( 'reply' );
		if ( null !== $reply ) {
			$is_course_instructor = $this->user_is_instructor_of_course(
				$user_id,
				(int) $review->course_id
			);
			if ( ! $is_admin && ! $is_course_instructor ) {
				// Not the course owner (nor an admin). Return an explicit 403
				// instead of silently dropping the reply and answering 200,
				// which made the caller believe the reply had posted.
				return $this->error_response( $this->permission_error() );
			}
			try {
				$service = new Review_Service();
				$service->reply( (int) $review->id, wp_unslash( $reply ) );
			} catch ( \RuntimeException $e ) {
				// The arg schema's maxLength rejects first; this catches any
				// caller that reaches the service another way, and keeps a
				// length refusal a 400 instead of an uncaught 500.
				return $this->error_response( $this->validation_error( $e->getMessage() ) );
			}
		}

		if ( empty( $data ) && null === $reply ) {
			return $this->error_response( $this->validation_error( __( 'Nothing to update.', 'learnomy' ) ) );
		}

		if ( ! empty( $data ) ) {
			$data['updated_at'] = \Learnomy\now();
			Review::update( (int) $review->id, $data );

			// If rating changed, re-tally the course average.
			if ( isset( $data['rating'] ) ) {
				$service = new Review_Service();
				$service->recalculate_rating( (int) $review->course_id );
			}
		}

		return $this->single_response( Review::to_typed_array( Review::find( (int) $review->id ) ) );
	}

	public function delete_review( WP_REST_Request $request ): WP_REST_Response {
		$review = Review::find( (int) $request->get_param( 'id' ) );
		if ( ! $review ) {
			return $this->error_response( $this->not_found( 'Review' ) );
		}

		$service = new Review_Service();
		if ( ! $service->delete( (int) $review->id ) ) {
			return $this->error_response( $this->validation_error( __( 'The review could not be deleted. Please try again.', 'learnomy' ) ) );
		}

		return $this->single_response(
			[
				'deleted' => true,
				'id'      => (int) $review->id,
			]
		);
	}

	/**
	 * Does the user hold an instructor row for this course? Used by the
	 * review-reply IDOR gate so an instructor can only reply to reviews
	 * on a course they're actually assigned to.
	 */
	private function user_is_instructor_of_course( int $user_id, int $course_id ): bool {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return false;
		}
		foreach ( CourseInstructor::get_for_course( $course_id ) as $row ) {
			if ( (int) $row->user_id === $user_id ) {
				return true;
			}
		}
		return false;
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function can_review( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Enrollment is an authorization requirement, so gate it HERE as a 403
		// rather than letting Review_Service throw a 400 deeper in the call. This
		// also means a future service refactor can't silently drop the gate — the
		// service keeps its own check as defense-in-depth.
		$course_id = (int) $request['course_id'];
		if ( ! Enrollment::is_enrolled( $user_id, $course_id ) ) {
			return new WP_Error(
				'learnomy_forbidden',
				__( 'You must be enrolled in this course to leave a review.', 'learnomy' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	public function can_edit_review( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// Admins pass via manage_options short-circuit; instructors pass via
		// learnomy_manage_own_courses; both short-circuit before ownership check.
		if ( $this->check_permission( 'manage_own_courses' ) ) {
			return true;
		}

		// Students may edit their own review; no Permission_Engine ability covers
		// per-row ownership, so this ownership check is a data predicate, not a
		// capability check. Permission_Engine has already run above.
		$review = Review::find( (int) $request->get_param( 'id' ) );
		if ( $review && (int) $review->user_id === $user_id ) {
			return true;
		}

		return $this->permission_error();
	}

	public function can_delete_review( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// Admins pass via manage_options short-circuit (manage_settings check).
		if ( $this->check_permission( 'manage_settings' ) ) {
			return true;
		}

		// Students may delete their own review; no Permission_Engine ability
		// covers per-row ownership — this is a data predicate only.
		$review = Review::find( (int) $request->get_param( 'id' ) );
		if ( $review && (int) $review->user_id === $user_id ) {
			return true;
		}

		return $this->permission_error();
	}

	/**
	 * Admin moderation gate. Mirrors `Membership_Controller::require_manage()`
	 * — routes through Permission_Engine so the same admin-only short-circuit
	 * applies. Used by the admin moderation page endpoints only.
	 */
	public function require_manage(): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( ! $this->check_permission( 'manage_settings' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * PATCH /admin/reviews/{id}/status — single-row moderation action.
	 *
	 * Routes through `Review_Service::set_status()` (private seam) via the
	 * approve/reject/spam wrappers + a pending-revert path. Each branch fires
	 * a distinct `learnomy_review_*` action so the notifier can react.
	 */
	public function admin_update_status( WP_REST_Request $request ): WP_REST_Response {
		$review_id = absint( $request->get_param( 'id' ) );
		$status    = (string) $request->get_param( 'status' );

		$review = Review::find( $review_id );
		if ( ! $review ) {
			return $this->error_response( $this->not_found( 'Review' ) );
		}

		$service = new Review_Service();
		$ok      = false;
		switch ( $status ) {
			case 'approved':
				$ok = $service->approve( $review_id );
				break;
			case 'rejected':
				$ok = $service->reject( $review_id );
				break;
			case 'spam':
				$ok = $service->spam( $review_id );
				break;
			case 'pending':
				// No notifier action for pending-revert — admin is just
				// re-queuing the row, not making a moderation decision yet.
				$ok = (bool) Review::update(
					$review_id,
					array(
						'status'     => 'pending',
						'updated_at' => \Learnomy\now(),
					)
				);
				if ( $ok ) {
					$service->recalculate_rating( (int) $review->course_id );
				}
				break;
		}

		if ( ! $ok ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not update review status.', 'learnomy' ) )
			);
		}

		return $this->single_response(
			array(
				'id'     => $review_id,
				'status' => $status,
			)
		);
	}

	/**
	 * POST /admin/reviews/bulk — moderate many rows at once.
	 *
	 * Caps the input at 100 ids per call to bound the cost of a single
	 * request; the JS pages bulk into 100-id chunks so this isn't a
	 * functional limit. Returns the rows we actually processed so the UI
	 * can flash the count instead of re-counting client-side.
	 */
	public function admin_bulk_action( WP_REST_Request $request ): WP_REST_Response {
		$ids    = (array) $request->get_param( 'ids' );
		$action = (string) $request->get_param( 'action' );

		// Cap + de-dupe — bulk actions over 100 rows must paginate from JS.
		$ids = array_values( array_unique( array_map( 'absint', $ids ) ) );
		$ids = array_slice( array_filter( $ids ), 0, 100 );

		if ( empty( $ids ) ) {
			return $this->error_response(
				$this->validation_error( __( 'No reviews selected.', 'learnomy' ) )
			);
		}

		$service   = new Review_Service();
		$processed = array();

		foreach ( $ids as $review_id ) {
			$ok = false;
			switch ( $action ) {
				case 'approve':
					$ok = $service->approve( $review_id );
					break;
				case 'reject':
					$ok = $service->reject( $review_id );
					break;
				case 'spam':
					$ok = $service->spam( $review_id );
					break;
				case 'pending':
					$row = Review::find( $review_id );
					if ( $row ) {
						$ok = (bool) Review::update(
							$review_id,
							array(
								'status'     => 'pending',
								'updated_at' => \Learnomy\now(),
							)
						);
						if ( $ok ) {
							$service->recalculate_rating( (int) $row->course_id );
						}
					}
					break;
				case 'delete':
					$ok = $service->delete( $review_id );
					break;
			}
			if ( $ok ) {
				$processed[] = $review_id;
			}
		}

		return $this->single_response(
			array(
				'action'    => $action,
				'processed' => $processed,
				'count'     => count( $processed ),
			)
		);
	}
}
