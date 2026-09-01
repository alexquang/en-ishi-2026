<?php
/**
 * Base REST API controller — SDK envelope, pagination, caching, auth helpers.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\Permissions\Permission_Engine;
use Learnomy\Permissions\Rate_Limiter;
use Learnomy\Models\Section;
use Learnomy\Models\Lesson;
use Learnomy\Models\Quiz;

abstract class Base_Controller extends WP_REST_Controller {

	public function __construct() {
		$this->namespace = 'learnomy/v1';
	}

	// -------------------------------------------------------------------------
	// Permission helpers
	// -------------------------------------------------------------------------

	protected function check_permission( string $action, ?int $context_id = null ): bool {
		return Permission_Engine::can( get_current_user_id(), $action, $context_id );
	}

	/**
	 * Canonical permission_callback helper — returns WP_Error with a structured
	 * envelope on failure, so clients always see the same `{error:{code,message}}`
	 * shape regardless of which endpoint denied them.
	 *
	 * Use directly in register_rest_route:
	 *   'permission_callback' => fn( $req ) => $this->permission_check( 'create-course' )
	 *   'permission_callback' => fn( $req ) => $this->permission_check( 'manage_course', (int) $req['id'] )
	 *
	 * @return true|WP_Error true on allow, WP_Error(401|403) on deny.
	 */
	protected function permission_check( string $ability, ?int $context_id = null ): bool|WP_Error {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error(
				'learnomy_unauthorized',
				__( 'You must be logged in.', 'learnomy' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! Permission_Engine::can( $user_id, $ability, $context_id ) ) {
			return new WP_Error(
				'learnomy_forbidden',
				__( 'You do not have permission to perform this action.', 'learnomy' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * True when this anonymous request must not see course-describing content.
	 *
	 * The private-academy switch ("require login for all course content") is a
	 * promise about the whole catalogue, not just the course rows. Single-course
	 * reads honour it through `\Learnomy\can_view_course()`, but a LIST endpoint
	 * runs its own query and has to ask separately — which is exactly the kind
	 * of check that gets added to the first list somebody notices and to none of
	 * the others.
	 *
	 * It had. `/courses` returned an empty catalogue to an anonymous caller
	 * while `/categories` and `/tags` returned every name they held, including
	 * internal ones. Category and tag names describe what an academy teaches;
	 * on an internal-training site they can name departments, clients or
	 * unreleased products. Hiding the courses and publishing the taxonomy that
	 * describes them is not a private academy.
	 *
	 * Lives here so the next public list has one obvious thing to call instead
	 * of a fourth hand-written copy of the same condition — and delegates to
	 * `content_blocked_for()`, which is now the one copy for the whole plugin.
	 * Writing the condition out here again was how this surface would have kept
	 * answering "anonymous" after the gate learned to mean more than that.
	 *
	 * @return bool True when the caller is blocked by the private-academy gate.
	 */
	protected function private_academy_blocks_anonymous(): bool {
		return \Learnomy\content_blocked_for( get_current_user_id() );
	}

	protected function require_auth(): int|WP_Error {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error(
				'learnomy_unauthorized',
				__( 'You must be logged in.', 'learnomy' ),
				[ 'status' => 401 ]
			);
		}
		return $user_id;
	}

	/**
	 * Permission_callback helper for read endpoints that require authentication
	 * but no specific ability. Returns true|WP_Error so WP REST emits a proper
	 * 401 envelope instead of a string-callback fallback.
	 *
	 * MUST be public — WP REST's call_user_func cannot invoke a protected
	 * method from outside the class context (the `[ $this, 'method' ]` form
	 * still hits the protected-access barrier when called by WP_REST_Server).
	 * Fatal-error production bug surfaced by the security/10 journey on
	 * 2026-04-30; this docblock is a regression sentinel.
	 */
	public function permission_logged_in(): bool|WP_Error {
		$check = $this->require_auth();
		return is_wp_error( $check ) ? $check : true;
	}

	/**
	 * 403 for a caller who may not do this.
	 *
	 * The default message answers "you are not allowed". Pass $message when the
	 * refusal has a reason the caller could act on — most usefully when nothing
	 * is wrong with their permissions at all and an owner has simply switched
	 * the feature off. "You do not have permission" sends that person to check
	 * their role, which is the wrong place to look.
	 *
	 * This parameter exists because Pro was already passing one: Spaces called
	 * `permission_error( 'The site administrator has turned this off for this
	 * space.' )` against a signature that took no arguments, so PHP discarded it
	 * and shipped the generic text (PHPStan arguments.count). The seam belongs
	 * in Free — per the Free/Pro contract, an extension point Pro needs ships
	 * here rather than being re-implemented there.
	 *
	 * @param string $message Optional replacement for the default text.
	 */
	protected function permission_error( string $message = '' ): WP_Error {
		return new WP_Error(
			'learnomy_forbidden',
			'' !== $message ? $message : __( 'You do not have permission to perform this action.', 'learnomy' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * Ownership gate for parent resources named IN THE REQUEST.
	 *
	 * A create — or a re-parent — that names a parent is a write INTO that
	 * parent's course. An authoring capability answers "may this user author at
	 * all"; only the parent answers "may this user author HERE". Checking the
	 * capability alone is what let one instructor POST a quiz onto another
	 * instructor's course: a horizontal escalation between tenants of the same
	 * site, not a policy nit (Basecamp 10133286758).
	 *
	 * Every parent resolves to the course that owns it and the decision is
	 * always `Permission_Engine::can( …, 'manage_course', $course_id )`, so this
	 * gate has exactly the semantics of every existing per-course write gate:
	 * admins short-circuit on `manage_options`, the course author passes, the
	 * designated primary instructor passes, and an assigned co-instructor passes
	 * only where the site opted in via `learnomy_co_instructor_can_manage`.
	 *
	 * Absent / zero ids are skipped, so a caller may hand over its whole set of
	 * optional placement params and a genuinely parentless resource (a
	 * standalone quiz) still passes on its capability alone.
	 *
	 * @param array<string,int|int[]> $parents Parent kind => id (or list of ids).
	 *                                         Kinds: course, section, lesson, quiz.
	 * @return true|WP_Error true when every named parent is manageable.
	 */
	protected function require_parent_ownership( array $parents ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		foreach ( $parents as $kind => $ids ) {
			foreach ( (array) $ids as $id ) {
				$id = (int) $id;
				if ( $id <= 0 ) {
					continue;
				}
				if ( ! $this->parent_is_manageable( $user_id, (string) $kind, $id ) ) {
					return $this->permission_error();
				}
			}
		}

		return true;
	}

	/**
	 * Resolve one parent to its owning course and ask the engine.
	 *
	 * A parent row that does not exist is DENIED, not 404'd — the answer to
	 * "may I write into row 9999" is no, and a 404 here would turn the gate into
	 * an existence oracle for other instructors' content. Handlers still emit
	 * their own 404 for the primary resource they were asked about.
	 *
	 * @param string $kind One of: course, section, lesson, quiz.
	 */
	private function parent_is_manageable( int $user_id, string $kind, int $id ): bool {
		switch ( $kind ) {
			case 'course':
				return Permission_Engine::can( $user_id, 'manage_course', $id );

			case 'section':
				$section = Section::find( $id );
				return $section && Permission_Engine::can( $user_id, 'manage_course', (int) $section->course_id );

			case 'lesson':
				$lesson = Lesson::find( $id );
				return $lesson && Permission_Engine::can( $user_id, 'manage_course', (int) $lesson->course_id );

			case 'quiz':
				$quiz = Quiz::find( $id );
				if ( ! $quiz ) {
					return false;
				}
				if ( ! empty( $quiz->course_id ) ) {
					return Permission_Engine::can( $user_id, 'manage_course', (int) $quiz->course_id );
				}
				// A standalone quiz has no course row to own it, so its author
				// is the owner. The engine still decides for everyone else:
				// `manage_course` with no context is false for every non-admin
				// and true under `manage_options`, which is the admin bypass.
				return (int) $quiz->author_id === $user_id
					|| Permission_Engine::can( $user_id, 'manage_course', null );
		}

		// An unknown parent kind is a programming error. Defaulting to "allow"
		// is how a gate quietly stops gating, so deny.
		return false;
	}

	protected function not_found( string $what = 'Resource' ): WP_Error {
		return new WP_Error(
			'learnomy_not_found',
			/* translators: %s: resource name (already translated by not_found_noun()) */
			sprintf( __( '%s not found.', 'learnomy' ), $this->not_found_noun( $what ) ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Translate a raw English entity noun passed to not_found() into the
	 * current locale. Callers historically pass a bare English literal
	 * (`$this->not_found( 'Course' )`); this map gives every one of them a
	 * translated label without touching the call sites. A noun that is not in
	 * the map (unknown, or a caller that already passed an `__()` string) is
	 * returned unchanged.
	 *
	 * @param string $what Raw noun from the caller.
	 * @return string Translated noun (or the original when not mapped).
	 */
	protected function not_found_noun( string $what ): string {
		static $map = null;
		if ( null === $map ) {
			$map = [
				'Resource'               => __( 'Resource', 'learnomy' ),
				'Course'                 => __( 'Course', 'learnomy' ),
				'Quiz'                   => __( 'Quiz', 'learnomy' ),
				'Lesson'                 => __( 'Lesson', 'learnomy' ),
				'Parent lesson'          => __( 'Parent lesson', 'learnomy' ),
				'Attempt'                => __( 'Attempt', 'learnomy' ),
				'Subscription'           => __( 'Subscription', 'learnomy' ),
				'Question'               => __( 'Question', 'learnomy' ),
				'Membership plan'        => __( 'Membership plan', 'learnomy' ),
				'Plan'                   => __( 'Plan', 'learnomy' ),
				'Section'                => __( 'Section', 'learnomy' ),
				'Topic'                  => __( 'Topic', 'learnomy' ),
				'Instructor'             => __( 'Instructor', 'learnomy' ),
				'Instructor application' => __( 'Instructor application', 'learnomy' ),
				'Webhook'                => __( 'Webhook', 'learnomy' ),
				'Webhook event'          => __( 'Webhook event', 'learnomy' ),
				'Review'                 => __( 'Review', 'learnomy' ),
				'Withdrawal request'     => __( 'Withdrawal request', 'learnomy' ),
				'User'                   => __( 'User', 'learnomy' ),
				'Tag'                    => __( 'Tag', 'learnomy' ),
				'Student'                => __( 'Student', 'learnomy' ),
				'Response'               => __( 'Response', 'learnomy' ),
				'Notification'           => __( 'Notification', 'learnomy' ),
				'Coupon'                 => __( 'Coupon', 'learnomy' ),
				'Comment'                => __( 'Comment', 'learnomy' ),
				'Certificate'            => __( 'Certificate', 'learnomy' ),
				'Certificate template'   => __( 'Certificate template', 'learnomy' ),
				'Category'               => __( 'Category', 'learnomy' ),
				'Slot'                   => __( 'Slot', 'learnomy' ),
				'Module'                 => __( 'Module', 'learnomy' ),
				'Media'                  => __( 'Media', 'learnomy' ),
				'Enrollment'             => __( 'Enrollment', 'learnomy' ),
				'Device'                 => __( 'Device', 'learnomy' ),
				'Commission rule'        => __( 'Commission rule', 'learnomy' ),
				'Attachment'             => __( 'Attachment', 'learnomy' ),
				'API Key'                => __( 'API Key', 'learnomy' ),
				'Account'                => __( 'Account', 'learnomy' ),
				'Stripe subscription'    => __( 'Stripe subscription', 'learnomy' ),
				'Reported item'          => __( 'Reported item', 'learnomy' ),
				'Report'                 => __( 'Report', 'learnomy' ),
				// Pro entity nouns (Pro controllers extend this base and pass these).
				'Space'                  => __( 'Learning Space', 'learnomy' ),
				'Space plan'             => __( 'Space plan', 'learnomy' ),
				'Seat pack'              => __( 'Seat pack', 'learnomy' ),
				'Cohort'                 => __( 'Cohort', 'learnomy' ),
				'Group'                  => __( 'Group', 'learnomy' ),
				'Member'                 => __( 'Member', 'learnomy' ),
			];
		}

		return $map[ $what ] ?? $what;
	}

	protected function validation_error( string $message ): WP_Error {
		return new WP_Error(
			'learnomy_validation',
			$message,
			[ 'status' => 400 ]
		);
	}

	// -------------------------------------------------------------------------
	// SDK response envelope
	// -------------------------------------------------------------------------

	protected function single_response( object|array $data, array $links = [], int $status = 200 ): WP_REST_Response {
		$body = [ 'data' => $data ];

		if ( ! empty( $links ) ) {
			$body['_links'] = $links;
		}

		$response = new WP_REST_Response( $body, $status );

		// ETag caching — hash the response body and check If-None-Match.
		$etag = $this->generate_etag( $body );

		$if_none_match = sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) );
		if ( $if_none_match === '"' . $etag . '"' ) {
			return new WP_REST_Response( null, 304 );
		}

		return $this->add_etag( $response, $etag );
	}

	/**
	 * Render a paginated collection response with a correct envelope.
	 *
	 * @param array     $items      Current page of items (already sliced).
	 * @param int       $total      Pre-slice total — the true DB row count matching the query.
	 * @param int       $offset     Offset used to produce $items (0 for unpaginated / first page / cursor-based).
	 * @param bool|null $has_more   Explicit has_more override (required for cursor-based endpoints
	 *                              that fetched limit+1 and popped). When null, computed as
	 *                              (offset + count) < total — correct for offset-based lists.
	 * @param array     $extra_meta Optional additional meta keys to merge into the envelope.
	 */
	protected function paginated_response(
		array $items,
		int $total,
		int $offset = 0,
		?bool $has_more = null,
		array $extra_meta = []
	): WP_REST_Response {
		$count             = count( $items );
		$has_more_resolved = $has_more ?? ( ( $offset + $count ) < $total );
		$last_item         = end( $items );
		$cursor_next       = $last_item
			? ( is_object( $last_item ) ? (int) $last_item->id : (int) ( $last_item['id'] ?? 0 ) )
			: null;

		$body = [
			'data' => $items,
			'meta' => array_merge(
				[
					'count'       => $count,
					'total'       => $total,
					'offset'      => $offset,
					'has_more'    => $has_more_resolved,
					'cursor_next' => $has_more_resolved ? $cursor_next : null,
					// How to ask for the next page, so a client reads it instead
					// of guessing. Guessing is the whole bug class: `page` on an
					// offset route and `offset` on a cursor route both returned
					// HTTP 200 and the wrong rows, and it shipped three times.
					// Free routes take page and offset interchangeably; a route
					// that can also be walked by cursor says so via cursor_next.
					'paging'      => 'page|offset',
				],
				$extra_meta
			),
		];

		$response = new WP_REST_Response( $body );
		$response->header( 'X-WP-Total', (string) $total );

		// ETag caching — hash the response body and check If-None-Match.
		$etag = $this->generate_etag( $body );

		$if_none_match = sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) );
		if ( $if_none_match === '"' . $etag . '"' ) {
			return new WP_REST_Response( null, 304 );
		}

		return $this->add_etag( $response, $etag );
	}

	/**
	 * Paginate a Model-backed list atomically — same $where used for COUNT and SELECT,
	 * limit/offset pulled from the request. Guarantees meta.total is accurate for meta.has_more.
	 *
	 * @param class-string $model_class Fully-qualified Model subclass.
	 * @param array        $where       WHERE clause for the query (model convention).
	 * @return array{0: array, 1: int, 2: int} Tuple: [$items, $offset, $total].
	 */
	protected function paginate_query( string $model_class, array $where, WP_REST_Request $request, string $orderby = 'id', string $order = 'DESC' ): array {
		$pagination = $this->get_pagination( $request );
		$offset     = (int) $pagination['offset'];
		$limit      = (int) $pagination['limit'];

		$total = (int) $model_class::count( $where );
		$items = $model_class::where( $where, $orderby, $order, $limit, $offset );

		return [ $items, $offset, $total ];
	}

	protected function error_response( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

		$uuid       = wp_generate_uuid4();
		$request_id = 'req_' . substr( str_replace( '-', '', $uuid ), 0, 12 );

		$body = [
			'error' => [
				'code'       => $error->get_error_code(),
				'message'    => $error->get_error_message(),
				// The single (latest) data object — `{ "status": 401 }` — not
				// get_all_error_data()'s LIST wrapper, which buried status at
				// details[0].status. Reuses $data resolved above.
				'details'    => $data,
				'request_id' => $request_id,
			],
		];

		return new WP_REST_Response( $body, $status );
	}

	/**
	 * Generic capability gate. Returns WP_Error(401|403) so every
	 * endpoint that mixes "must be logged in" with "must have cap X"
	 * gets the same envelope shape clients have learned to parse.
	 *
	 * Added 2026-05-11 so Pro extensions (and Free's own controllers)
	 * stop duplicating the require_auth + current_user_can pattern. See
	 * plan/2026-05-11-scale-readiness/ARCHITECTURE-MAP.md for the
	 * Free-as-upscale-module mandate.
	 *
	 * @param string $cap WP capability slug; may be a plugin-defined cap.
	 * @return true|WP_Error
	 */
	protected function require_cap( string $cap ): bool|WP_Error {
		$auth = $this->require_auth();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		if ( ! current_user_can( $cap ) ) {
			return $this->permission_error();
		}
		return true;
	}

	/**
	 * Cursor-paginate a list query — the canonical pattern for any list
	 * endpoint that targets a table with potentially >100k rows.
	 *
	 * OFFSET pagination is O(N) past page 1000 (MySQL scans and
	 * discards every prior row). Cursor pagination is O(1) per page —
	 * the engine seeks directly to the cursor via the primary-key index.
	 *
	 * Usage:
	 *
	 *   public function list_things( WP_REST_Request $request ): WP_REST_Response {
	 *       return $this->cursor_paginate( $request, function ( int $cursor, int $limit_plus_one ) {
	 *           return Thing::where_id_lt( $cursor, $limit_plus_one );
	 *       } );
	 *   }
	 *
	 * The fetcher returns ($limit + 1) rows ordered by id DESC; this
	 * helper pops the overflow row to compute `has_more` and reports
	 * `cursor_next` (the id of the last returned row).
	 *
	 * @param WP_REST_Request $request    Current REST request.
	 * @param callable        $fetcher    `function( int $cursor, int $limit_plus_one ): array`.
	 * @param array           $extra_meta Optional extra meta keys merged into the envelope.
	 */
	protected function cursor_paginate(
		WP_REST_Request $request,
		callable $fetcher,
		array $extra_meta = []
	): WP_REST_Response {
		$per_page = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?? 20 ) ) );
		$cursor   = (int) ( $request->get_param( 'after' ) ?? 0 );

		$fetched  = (array) $fetcher( $cursor, $per_page + 1 );
		$has_more = count( $fetched ) > $per_page;
		$items    = $has_more ? array_slice( $fetched, 0, $per_page ) : $fetched;
		$next     = $has_more && ! empty( $items )
			? (int) ( end( $items )->id ?? 0 )
			: null;

		reset( $items );

		$meta = array_merge(
			[
				'count'       => count( $items ),
				'has_more'    => $has_more,
				'cursor_next' => $next,
				'per_page'    => $per_page,
			],
			$extra_meta
		);

		return new WP_REST_Response(
			[
				'data' => $items,
				'meta' => $meta,
			],
			200
		);
	}

	/**
	 * Cached read using `wp_cache_set_last_changed` incrementor.
	 *
	 * Pass a stable cache key + a resolver closure; the helper:
	 *   1. Reads `wp_cache_get_last_changed( $group )` so the effective
	 *      key auto-invalidates on any write in the group.
	 *   2. Hits cache; on miss, calls $resolver, stores, returns.
	 *
	 * Use for hot reads off Model-backed tables. The Model's
	 * `cache_group()` is the canonical group; pass it explicitly so
	 * one Service can cache reads against multiple Models.
	 *
	 * @param string   $key      Stable cache key (will be augmented with the incrementor).
	 * @param callable $resolver Closure that returns the data on miss.
	 * @param string   $group    Cache group (default `'learnomy_default'`).
	 * @param int      $ttl      TTL hint for Redis/Memcached (defaults to 300s).
	 * @return mixed The data, either from cache or fresh.
	 */
	protected function cached_read( string $key, callable $resolver, string $group = 'learnomy_default', int $ttl = 300 ) {
		$last_changed  = wp_cache_get_last_changed( $group );
		$augmented_key = sprintf( '%s_%s', $key, $last_changed );

		$cached = wp_cache_get( $augmented_key, $group );
		if ( false !== $cached ) {
			return $cached;
		}

		$data = $resolver();
		wp_cache_set( $augmented_key, $data, $group, $ttl );
		return $data;
	}

	// -------------------------------------------------------------------------
	// Cursor-based pagination
	// -------------------------------------------------------------------------

	/**
	 * Read the pagination parameters shared by every cursor-paginated route.
	 *
	 * `per_page` is accepted as a synonym for `limit`. The two vocabularies both
	 * exist across this API — /lessons and /categories read `per_page`, the
	 * cursor routes read `limit` — and a caller who guesses wrong got NO error:
	 * the parameter was simply dropped and the default silently used instead.
	 * The mobile app asked the catalog for `per_page=20` and only ever worked
	 * because 20 happened to be the default it was ignored in favour of.
	 *
	 * Accepting both is the compatible half of unifying them (card 10160235647).
	 * `limit` wins when both are sent, so nothing that works today changes.
	 */
	/**
	 * Where this page starts, whichever vocabulary the caller used.
	 *
	 * For handlers that compute their own page size — a few have semantics
	 * get_pagination() cannot express, such as "no per_page means every row" —
	 * but must still answer both `page` and `offset`. Those handlers derived
	 * offset from `page` alone and silently ignored an `offset` the caller sent.
	 *
	 * Explicit `offset` wins: it is the more specific request, and it is what a
	 * caller resuming mid-list sends.
	 *
	 * @param int $per_page The page size the handler settled on.
	 */
	protected function resolve_offset( WP_REST_Request $request, int $per_page ): int {
		$sent = array_merge(
			(array) $request->get_query_params(),
			(array) $request->get_body_params()
		);

		if ( isset( $sent['offset'] ) ) {
			return max( 0, (int) $sent['offset'] );
		}

		$page = max( 1, (int) ( $sent['page'] ?? 1 ) );

		return ( $page - 1 ) * max( 1, $per_page );
	}

	protected function get_pagination( WP_REST_Request $request ): array {
		// Read what the CALLER actually sent, not get_param(), which hands back
		// the schema default for `limit` (20) whether or not it was supplied —
		// so "did they send limit?" is unanswerable through get_param() and
		// `per_page` would never get a look in.
		$sent = array_merge(
			(array) $request->get_query_params(),
			(array) $request->get_body_params()
		);

		$limit = isset( $sent['limit'] ) ? (int) $sent['limit'] : 0;
		if ( $limit <= 0 && isset( $sent['per_page'] ) ) {
			$limit = (int) $sent['per_page'];
		}
		if ( $limit <= 0 ) {
			$limit = (int) ( $request->get_param( 'limit' ) ?? 20 );
		}
		// Match the bounds the route schema advertises, so an alias cannot be
		// used to ask for more than `limit` is allowed to.
		$limit = max( 1, min( 100, $limit ) );

		// `page` and `offset` are the same request in two vocabularies, and until
		// now each route answered only the one it happened to read: /courses
		// honoured `page` and ignored `offset`, /enrollments the reverse. Both
		// wrong guesses returned HTTP 200 with a well-formed page, which is how
		// the app shipped two silent paging bugs.
		//
		// Deriving each from the other here means a route keeps reading whichever
		// key it always read, and answers correctly whichever the caller sent.
		// An explicit `offset` wins over `page`: it is the more specific request,
		// and it is what a caller resuming mid-list sends.
		$offset = isset( $sent['offset'] ) ? max( 0, (int) $sent['offset'] ) : null;
		$page   = isset( $sent['page'] ) ? max( 1, (int) $sent['page'] ) : null;

		if ( null === $offset ) {
			$offset = null === $page ? 0 : ( $page - 1 ) * $limit;
		}
		if ( null === $page ) {
			$page = (int) floor( $offset / $limit ) + 1;
		}

		// `cursor` is the canonical name; `after` is the original and stays a
		// working alias. Core uses after/before for DATE filters, so the newer
		// name is the one to teach.
		$cursor = (int) ( $sent['cursor'] ?? $sent['after'] ?? $request->get_param( 'after' ) ?? 0 );

		return [
			'limit'    => $limit,
			'per_page' => $limit,
			'offset'   => $offset,
			'page'     => $page,
			'sort'     => $request->get_param( 'sort' ) ?? 'latest',
			'after'    => $cursor,
			'cursor'   => $cursor,
			'before'   => (int) ( $request->get_param( 'before' ) ?? 0 ),
		];
	}

	public function get_collection_params(): array {
		return [
			'limit'    => [
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			],
			// Declared so the alias is discoverable from the route schema rather
			// than being folklore. No default: it must stay unset when absent, or
			// it would always override `limit`.
			'per_page' => [
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Synonym for `limit`. `limit` wins if both are sent.', 'learnomy' ),
			],
			'after'    => [
				'type'        => 'integer',
				'default'     => 0,
				'description' => __( 'Return items after this ID (cursor-based pagination).', 'learnomy' ),
			],
			'before'   => [
				'type'        => 'integer',
				'default'     => 0,
				'description' => __( 'Return items before this ID.', 'learnomy' ),
			],
			'offset'   => [
				'type'        => 'integer',
				'default'     => 0,
				'minimum'     => 0,
				'description' => __( 'Legacy offset-based pagination (use after/before instead).', 'learnomy' ),
			],
			'sort'     => [
				'type'    => 'string',
				'default' => 'latest',
				'enum'    => [ 'latest', 'oldest', 'popular', 'title' ],
			],
			'fields'   => [
				'type'              => 'string',
				'description'       => __( 'Comma-separated list of fields to return.', 'learnomy' ),
				'sanitize_callback' => 'sanitize_text_field',
			],
			'include'  => [
				'type'              => 'string',
				'description'       => __( 'Comma-separated related resources to include.', 'learnomy' ),
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	// -------------------------------------------------------------------------
	// Field selection + resource inclusion
	// -------------------------------------------------------------------------

	protected function filter_fields( array $data, WP_REST_Request $request ): array {
		$fields_param = $request->get_param( 'fields' );
		if ( empty( $fields_param ) ) {
			return $data;
		}

		$fields = array_map( 'trim', explode( ',', $fields_param ) );
		$fields = array_filter( $fields );

		if ( empty( $fields ) ) {
			return $data;
		}

		return array_intersect_key( $data, array_flip( $fields ) );
	}

	protected function get_includes( WP_REST_Request $request ): array {
		$include_param = $request->get_param( 'include' );
		if ( empty( $include_param ) ) {
			return [];
		}

		$includes = array_map( 'trim', explode( ',', $include_param ) );
		return array_filter( $includes );
	}

	// -------------------------------------------------------------------------
	// ETag caching
	// -------------------------------------------------------------------------

	protected function maybe_not_modified( string $etag, WP_REST_Request $request ): ?WP_REST_Response {
		$if_none_match = $request->get_header( 'If-None-Match' );

		// Normalize the client's validator: a compressing web server/proxy weakens
		// our strong ETag to `W/"…"` on the wire, so the browser echoes back the
		// weak form. Strip the `W/` prefix + quotes/space before comparing, or the
		// 304 short-circuit never fires and every revalidation pays a full 200.
		if ( $if_none_match ) {
			$client = trim( preg_replace( '/^\s*W\//i', '', $if_none_match ), ' "' );
			if ( $client === $etag ) {
				$response = new WP_REST_Response( null, 304 );
				$response->header( 'ETag', '"' . $etag . '"' );
				return $response;
			}
		}

		return null;
	}

	protected function add_etag( WP_REST_Response $response, string $etag, bool $revalidate = false ): WP_REST_Response {
		$response->header( 'ETag', '"' . $etag . '"' );

		// Per-user / progression-bearing reads pass $revalidate = true: the ETag
		// (a body hash that already includes the viewer's state) stays the
		// freshness mechanism, but `no-cache` forces the client to revalidate on
		// EVERY read instead of serving a stale body for up to cache_ttl. Without
		// this a student who just completed a lesson kept seeing the old state for
		// an hour (Zoho #40979) — the response was fresh on the server, never
		// fetched by the browser. A 304 when nothing changed is still one cheap
		// round-trip, so correctness costs almost nothing here.
		if ( $revalidate ) {
			$response->header( 'Cache-Control', 'private, no-cache' );
			return $response;
		}

		// Shared, slow-changing reads (catalog, categories, plan/quiz definitions)
		// stay cacheable for cache_ttl (Settings > Advanced; default 3600s, seeded
		// in includes/class-learnomy.php). Floor at 0 so a negative value degrades
		// to "do not cache" rather than breaking the client header parse.
		$settings = get_option( 'learnomy_settings', [] );
		$max_age  = max( 0, (int) ( $settings['cache_ttl'] ?? 3600 ) );

		$response->header(
			'Cache-Control',
			sprintf( 'private, must-revalidate, max-age=%d', $max_age )
		);

		return $response;
	}

	protected function generate_etag( $data ): string {
		return md5( wp_json_encode( $data ) );
	}

	// -------------------------------------------------------------------------
	// Batch loading (N+1 prevention)
	// -------------------------------------------------------------------------

	protected function batch_load_users( array $ids ): array {
		if ( empty( $ids ) ) {
			return [];
		}

		$ids = array_unique( array_map( 'intval', $ids ) );
		$ids = array_filter( $ids );

		$cached  = [];
		$missing = [];

		foreach ( $ids as $id ) {
			$user = wp_cache_get( $id, 'users' );
			if ( false !== $user ) {
				$cached[ $id ] = $user;
			} else {
				$missing[] = $id;
			}
		}

		if ( ! empty( $missing ) ) {
			$user_query = new \WP_User_Query(
				[
					'include' => $missing,
					'fields'  => 'all',
				]
			);

			foreach ( $user_query->get_results() as $user ) {
				$cached[ (int) $user->ID ] = $user;
			}
		}

		return $cached;
	}

	protected function enrich_with_author( array $items, string $author_key = 'author_id' ): array {
		$author_ids = array_unique(
			array_filter(
				array_map(
					function ( $item ) use ( $author_key ) {
						return is_object( $item )
							? (int) ( $item->$author_key ?? 0 )
							: (int) ( $item[ $author_key ] ?? 0 );
					},
					$items
				)
			)
		);

		$users = $this->batch_load_users( $author_ids );

		foreach ( $items as &$item ) {
			$uid  = is_object( $item ) ? (int) $item->$author_key : (int) $item[ $author_key ];
			$user = $users[ $uid ] ?? null;

			$enrichment = [
				'author_name'   => $user ? $user->display_name : __( 'Anonymous', 'learnomy' ),
				'author_avatar' => $user ? get_avatar_url( $uid, [ 'size' => 64 ] ) : '',
				'author_url'    => $uid ? get_author_posts_url( $uid ) : '',
			];

			if ( is_object( $item ) ) {
				foreach ( $enrichment as $k => $v ) {
					$item->$k = $v;
				}
			} else {
				$item = array_merge( $item, $enrichment );
			}
		}

		return $items;
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

	protected function check_rate_limit( int $user_id, string $action ): bool|WP_Error {
		$limiter = new Rate_Limiter();

		// Global per-minute API cap (Settings > Advanced > API Rate Limit)
		// runs first so a flood gets stopped before we even consult the
		// per-action daily quota. Wired in Wave 2E (2026-05-16), row 2E-3.
		if ( ! $limiter->check_api_rate( $user_id ) ) {
			return new WP_Error(
				'learnomy_rate_limited',
				__( 'Too many requests. Please try again later.', 'learnomy' ),
				[ 'status' => 429 ]
			);
		}

		if ( ! $limiter->check( $user_id, $action ) ) {
			return new WP_Error(
				'learnomy_rate_limited',
				__( 'Too many requests. Please try again later.', 'learnomy' ),
				[ 'status' => 429 ]
			);
		}

		return true;
	}

	/**
	 * REST arg schema for a checkbox-friendly boolean.
	 *
	 * WP's strict boolean validator (rest_is_boolean) accepts true/false/1/0 and
	 * the strings '1'/'0'/'true'/'false', but rejects a raw HTML checkbox value
	 * ('on' when checked, '' when unchecked) with a 400 "not of type boolean".
	 * Admin forms serialise checkboxes that way, so a strict boolean arg breaks
	 * the save. This pairs the documented boolean type with rest_sanitize_boolean
	 * (coerces 'on'→true, ''→false) and a scalar-lenient validator, so the same
	 * arg accepts both app JSON booleans and HTML-form checkbox values.
	 *
	 * @param array $extra Optional extra schema keys to merge (e.g. 'default').
	 * @return array
	 */
	protected function bool_arg( array $extra = [] ): array {
		return array_merge(
			[
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'validate_callback' => [ $this, 'validate_boolish' ],
			],
			$extra
		);
	}

	/**
	 * Lenient boolean validation for {@see bool_arg()}. Any scalar is acceptable
	 * — the paired rest_sanitize_boolean coerces it to a real bool. Non-scalars
	 * (arrays/objects) are still rejected.
	 *
	 * @param mixed $value Incoming value.
	 * @return true|WP_Error
	 */
	public function validate_boolish( $value ): bool|WP_Error {
		return is_scalar( $value )
			? true
			: new WP_Error( 'rest_invalid_param', __( 'Value must be a boolean.', 'learnomy' ), [ 'status' => 400 ] );
	}
}
