<?php
/**
 * Courses REST controller — CRUD, publishing, duplication, and stats.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\Models\Course;
use Learnomy\Models\Section;
use Learnomy\Models\Lesson;
use Learnomy\Models\Quiz;
use Learnomy\Models\Tag;
use Learnomy\Services\Course_Service;
use Learnomy\Services\Enrollment_Service;
use Learnomy\Services\Wishlist_Service;

class Courses_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'courses';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_courses' ],
					'permission_callback' => '__return_true',
					// `page` is declared here rather than in the shared
					// get_collection_params(), which every list controller uses
					// — the catalog is the endpoint that offers offset paging
					// alongside the cursor, so the schema says so here. It read
					// the param before this (WP surfaces unregistered query
					// args too) but nothing documented it, so it never reached
					// docs/api/openapi.json and no client could discover it.
					'args'                => array_merge(
						$this->get_collection_params(),
						[
							'page' => [
								'type'              => 'integer',
								'default'           => 1,
								'minimum'           => 1,
								'sanitize_callback' => 'absint',
								'description'       => __( 'Page number for the offset-paginated sorts (popular, rating, price, featured). Ignored when `after` is supplied, which paginates by cursor.', 'learnomy' ),
							],
							// The catalog's OWN sort enum, overriding the shared
							// one from get_collection_params(). Three surfaces
							// disagreed about what a course sort is: the web UI
							// offered rating / price_asc / price_desc and served
							// them correctly, Course::catalog_order_by()
							// implemented those plus featured, and the shared
							// REST enum allowed none of them — so the API 400'd
							// on every sort the catalog page itself puts in its
							// dropdown, and the mobile app could not sort by
							// rating or price at all.
							'sort' => [
								'type'    => 'string',
								'default' => 'latest',
								'enum'    => [ 'latest', 'oldest', 'popular', 'title', 'rating', 'price_asc', 'price_desc', 'featured' ],
							],
						]
					),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_course' ],
					'permission_callback' => [ $this, 'can_create' ],
					'args'                => $this->create_course_args(),
				],
			]
		);

		// POST /courses/bulk — Stripe-style per-id result array. Replaces the
		// legacy AJAX bulk_course_action that loop-updated rows and returned
		// just a count; this endpoint reports per-id success/error so the
		// admin UI can surface partial failures instead of "X courses
		// updated" hiding a silent skip.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk_courses' ],
				'permission_callback' => [ $this, 'can_bulk' ],
				'args'                => [
					'action' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => [ 'delete', 'published', 'archived', 'draft', 'trashed' ],
					],
					'ids'    => [
						'required' => true,
						'type'     => 'array',
						'items'    => [ 'type' => 'integer' ],
					],
				],
			]
		);

		// GET /courses/instructor-page — paginated rows of the current user's own
		// + co-instructed courses, rendered as <tr> HTML for the My Courses
		// dashboard widget's async Prev/Next pager (no dashboard reload). Scoped
		// to the current user, so a plain logged-in check is the gate.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/instructor-page',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'instructor_courses_page' ],
				'permission_callback' => static fn() => is_user_logged_in(),
				'args'                => [
					'page' => [
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// GET /courses/teaching — JSON list of courses the caller teaches (author
		// OR co-instructor), for the app's Teach tab. instructor-page returns HTML
		// <tr> rows for the web pager; the app needs JSON, so this is its own route.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/teaching',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'teaching_courses' ],
				'permission_callback' => static fn() => is_user_logged_in(),
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_course' ],
					'permission_callback' => '__return_true',
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_course' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => array_merge(
						[
							'id' => [
								'type'     => 'integer',
								'required' => true,
							],
						],
						$this->create_course_args( false )
					),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_course' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);

		// POST /courses/{id}/retake — REST replacement for the legacy
		// wp_ajax_learnomy_retake_course handler. Resets enrollment state
		// per Enrollment_Service::retake() (which fires the
		// learnomy_course_retaken extension hook). Auth: must be enrolled
		// in this course; the service enforces the course's allow_retake
		// setting and refuses when disabled.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/retake',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'retake_course' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/publish',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'publish_course' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/duplicate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'duplicate_course' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/outline',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_outline' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/stats',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_stats' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		// POST /courses/{id}/wishlist — add course to the logged-in user's wishlist.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/wishlist',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'add_to_wishlist' ],
					'permission_callback' => [ $this, 'can_wishlist' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'remove_from_wishlist' ],
					'permission_callback' => [ $this, 'can_wishlist' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);

		// GET /wishlist — get the logged-in user's wishlisted course IDs.
		register_rest_route(
			$this->namespace,
			'/wishlist',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_wishlist' ],
				'permission_callback' => [ $this, 'can_wishlist' ],
				'args'                => [
					'page'     => [
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					],
					'per_page' => [
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					],
				],
			]
		);

		// /courses/{id}/instructors — list, attach, or detach co-instructors.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/instructors',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_course_instructors' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'attach_course_instructor' ],
					'permission_callback' => [ $this, 'can_manage_team' ],
					'args'                => [
						'id'        => [
							'type'     => 'integer',
							'required' => true,
						],
						'user_id'   => [
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 1,
						],
						'share_pct' => [
							'type'     => 'number',
							'required' => true,
							'minimum'  => 0,
							'maximum'  => 100,
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/instructors/(?P<user_id>[\d]+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_course_instructor_share' ],
					'permission_callback' => [ $this, 'can_manage_team' ],
					'args'                => [
						'id'        => [
							'type'     => 'integer',
							'required' => true,
						],
						'user_id'   => [
							'type'     => 'integer',
							'required' => true,
						],
						'share_pct' => [
							'type'     => 'number',
							'required' => true,
							'minimum'  => 0,
							'maximum'  => 100,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'detach_course_instructor' ],
					'permission_callback' => [ $this, 'can_manage_team' ],
					'args'                => [
						'id'      => [
							'type'     => 'integer',
							'required' => true,
						],
						'user_id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	public function get_courses( WP_REST_Request $request ): WP_REST_Response {
		// Private-academy gate. The single-course reads go through
		// \Learnomy\can_view_course(), but this LIST runs a direct catalog query,
		// so it needs the same site-wide check: when the owner requires login for
		// all course content, an anonymous caller gets an empty catalogue here
		// instead of the full course list (titles, descriptions, prices).
		if ( $this->private_academy_blocks_anonymous() ) {
			return $this->paginated_response( array(), 0, 0, false );
		}

		$pagination = $this->get_pagination( $request );
		$after      = $pagination['after'];
		$limit      = $pagination['limit'];

		// Delegate filter/sort/pagination to the shared catalog query so the
		// first-paint template and this endpoint behave identically. Fetch
		// limit + 1 so we can detect has_more without a second COUNT.
		$args = [
			'search'      => (string) $request->get_param( 'search' ),
			'category'    => (string) $request->get_param( 'category' ),
			'sort'        => (string) $request->get_param( 'sort' ),
			'access_type' => (string) $request->get_param( 'access_type' ),
			'after'       => $after,
			'per_page'    => $limit + 1,
			// Honour the caller's page. This was hardcoded to 1, so every
			// offset-paginated sort — popular, rating, price_asc, price_desc,
			// featured, and anything else where the `after` cursor is disabled
			// — returned the FIRST page no matter what page was requested. A
			// client paging through a 78-course catalogue by popularity got the
			// same 20 rows forever (Basecamp 10159960755).
			//
			// `page` is read directly rather than from get_pagination(), which
			// exposes limit/offset/after/before but not page. Callers that send
			// a cursor are unaffected: search_catalog() ignores `page` whenever
			// `after` is in play.
			'page'        => max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) ),
		];

		// Carry registered catalog facets (Facets framework) so load-more pages
		// keep the active filters — without this, page 2+ ignored every facet an
		// extension registered (filtered first page, unfiltered cursor pages).
		// resolve_active_from_request() reads the GET query string, which the JS
		// pager now forwards on each cursor request.
		if ( class_exists( \Learnomy\Catalog\Facets::class ) ) {
			$args['facets'] = \Learnomy\Catalog\Facets::resolve_active_from_request();
		}

		$courses = Course::search_catalog( $args );

		$has_more = count( $courses ) > $limit;
		if ( $has_more ) {
			array_pop( $courses );
		}

		$includes = $this->get_includes( $request );
		if ( in_array( 'author', $includes, true ) ) {
			$courses = $this->enrich_with_author( $courses, 'author_id' );
		}

		// Total uses the filter set without the cursor so meta.total is the
		// true filtered count, not the remaining-after-cursor count.
		$total = Course::count_catalog( $args );

		// Dual-format read (R1/INV-8): catalog rows keep `description` as Editor.js
		// JSON (+ description_html) whatever the row stores, matching get_course.
		$courses = \Learnomy\Content\Content_HTML::normalize_rows( $courses, 'description' );

		// Viewer enrichment (mobile-API contract WI-4): is_enrolled /
		// progress_pct / is_wishlisted per row, batch-fetched — one enrollment
		// query + one wishlist read per PAGE, never per row.
		$courses = $this->attach_viewer( $courses );

		// The catalog is page-paginated for the ranked sorts (popular, rating,
		// price, featured) and cursor-paginated otherwise — `page` is ignored the
		// moment a cursor is supplied, per the route's own `page` description. It
		// never honours a raw `offset`, so say so rather than inheriting the
		// generic declaration.
		$courses = $this->with_price_display( $courses );

		return $this->paginated_response( $courses, $total, 0, $has_more, array( 'paging' => 'page|cursor' ) );
	}

	/**
	 * One page of the current instructor's courses (own + co-instructed),
	 * rendered as <tr> HTML for the My Courses dashboard widget's async pager.
	 *
	 * Returns rendered rows (not raw JSON rows) so the row markup lives in one
	 * place — templates/dashboard-widgets/instructor/my-courses-rows.php — and
	 * never has to be duplicated in JS. Mirrors the account-courses pattern.
	 */
	public function instructor_courses_page( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$per     = 25;
		$offset  = $this->resolve_offset( $request, $per );
		// Kept: this response reports total_pages, so it needs the page number
		// itself — derived from the resolved offset so `page` and `offset`
		// callers are described identically.
		$page = (int) floor( $offset / $per ) + 1;

		$total       = Course::count_for_instructor( $user_id );
		$total_pages = (int) max( 1, (int) ceil( $total / $per ) );
		$page        = min( $page, $total_pages );
		$offset      = ( $page - 1 ) * $per;

		$my_courses = Course::get_for_instructor( $user_id, $per, $offset );

		// Render rows through the shared partial. $my_courses is the local it
		// reads — exactly what the widget passes on first paint.
		ob_start();
		require \Learnomy\template_path( 'dashboard-widgets/instructor/my-courses-rows.php' );
		$html = (string) ob_get_clean();

		return $this->single_response(
			[
				'html'        => $html,
				'page'        => $page,
				'total_pages' => $total_pages,
				'total'       => $total,
				'has_prev'    => $page > 1,
				'has_next'    => $page < $total_pages,
			]
		);
	}

	/**
	 * GET /courses/teaching — courses the current user teaches (author OR
	 * co-instructor), as JSON, for the app's Teach tab. Distinct, paginated.
	 * Reuses Course::get_for_instructor so it matches the grading queue's scope.
	 */
	public function teaching_courses( WP_REST_Request $request ): WP_REST_Response {
		$user_id    = get_current_user_id();
		$pagination = $this->get_pagination( $request );
		$courses    = Course::get_for_instructor( $user_id, (int) $pagination['limit'], (int) $pagination['offset'] );
		$total      = Course::count_for_instructor( $user_id );
		$items      = array_map(
			static fn( $course ) => Course::to_typed_array( $course ),
			$courses
		);
		return $this->paginated_response( $items, $total, (int) $pagination['offset'] );
	}

	public function get_course( WP_REST_Request $request ): WP_REST_Response {
		$course = Course::find( (int) $request->get_param( 'id' ) );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		// Unpublished → owner/admin only; private → owner/admin or enrolled. Both
		// rules live in \Learnomy\can_view_course() (audit 2026-05-30 fixed them
		// here; the learnomy/get-course ability was the same door with no gate and
		// kept leaking until 1.7.0). One gate, every reader.
		if ( ! \Learnomy\can_view_course( $course, get_current_user_id() ) ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		// Viewer enrichment participates in the ETag: a completed lesson changes
		// the viewer's progress_pct without touching the course row, and a
		// course-only hash would keep serving 304s with stale progress.
		$viewer = $this->viewer_for_courses( [ (int) $course->id ] )[ (int) $course->id ] ?? null;
		if ( null !== $viewer ) {
			$viewer['can_enroll_free'] = ! $viewer['is_enrolled'] && Course::is_free( $course );
		}

		$etag         = $this->generate_etag( [ $course, $viewer ] );
		$not_modified = $this->maybe_not_modified( $etag, $request );
		if ( $not_modified ) {
			return $not_modified;
		}

		$data           = $this->course_envelope( (int) $course->id );
		$data['viewer'] = $viewer;
		$filtered       = $this->filter_fields( $data, $request );
		// Dual-format read (INV-8): the rich `description` always returns Editor.js
		// JSON, normalised from whatever the row stores, so the app/Pro builder
		// keep parsing JSON after a site flips storage to HTML.
		if ( isset( $filtered['description'] ) && is_string( $filtered['description'] ) ) {
			$raw                          = $filtered['description'];
			$filtered['description']      = \Learnomy\Content\Content_HTML::for_editor( $raw );  // Editor.js JSON — the app today.
			$filtered['description_html'] = \Learnomy\Content\Content_HTML::for_storage( $raw );  // Canonical HTML — Phase 6 opt-in.
		}

		$response = $this->single_response(
			$filtered,
			[ 'self' => [ [ 'href' => rest_url( $this->namespace . '/courses/' . $course->id ) ] ] ]
		);

		// Per-user (etag folds in viewer progress_pct): revalidate every read so
		// progression is never served stale (Zoho #40979).
		return $this->add_etag( $response, $etag, true );
	}

	public function create_course( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$rate_check = $this->check_rate_limit( $user_id, 'create_course' );
		if ( is_wp_error( $rate_check ) ) {
			return $this->error_response( $rate_check );
		}

		$data           = $this->extract_course_fields( $request );
		$settings       = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : [];
		$tag_ids        = isset( $data['tag_ids'] ) && is_array( $data['tag_ids'] ) ? $data['tag_ids'] : null;
		$instructors    = isset( $data['instructors'] ) && is_array( $data['instructors'] ) ? $data['instructors'] : null;
		$has_commission = array_key_exists( 'commission_rate', $data );
		$commission     = $has_commission ? $data['commission_rate'] : false;
		// Admin may create a course on a presenter's behalf: author_id (set by
		// extract_course_fields from primary_instructor_id, admin-gated) becomes
		// the create() author so the presenter is the primary instructor from the
		// start. Falls back to the creating user.
		$author_id = array_key_exists( 'author_id', $data ) ? (int) $data['author_id'] : $user_id;
		unset( $data['settings'], $data['tag_ids'], $data['instructors'], $data['commission_rate'], $data['author_id'] );

		// Sale price must stay below the regular price (Basecamp #9925579263).
		$candidate_price = (float) ( $data['price'] ?? 0 );
		$candidate_sale  = (float) ( $data['sale_price'] ?? 0 );
		if ( $candidate_sale > 0 && $candidate_sale >= $candidate_price ) {
			return $this->error_response(
				$this->validation_error( __( 'Sale price must be lower than the regular price.', 'learnomy' ) )
			);
		}

		$service   = new Course_Service();
		$course_id = $service->create( $data, $author_id );

		if ( ! $course_id ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not create course. Please check your input and try again.', 'learnomy' ) )
			);
		}

		if ( ! empty( $settings ) ) {
			Course::update_settings( $course_id, $settings );
		}
		if ( null !== $tag_ids ) {
			$this->sync_course_tags( $course_id, $tag_ids );
		}
		if ( null !== $instructors ) {
			$this->sync_course_instructors( $course_id, $instructors );
		}
		if ( $has_commission ) {
			\Learnomy\Models\CommissionRule::set_course_rate( $course_id, $commission );
		}

		/**
		 * Fires after a course is created or updated via REST, carrying the
		 * request, so integrations can persist their own course fields (courses
		 * are custom-table rows with no CPT meta-box path). Pairs with the
		 * `learnomy_course_editor_fields` render hook in the editors.
		 *
		 * @param int             $course_id The saved course.
		 * @param WP_REST_Request $request   The create/update request.
		 */
		do_action( 'learnomy_course_saved', $course_id, $request );

		$response = $this->single_response( $this->course_envelope( $course_id ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_course( WP_REST_Request $request ): WP_REST_Response {
		$course = Course::find( (int) $request->get_param( 'id' ) );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$data           = $this->extract_course_fields( $request );
		$settings       = isset( $data['settings'] ) && is_array( $data['settings'] ) ? $data['settings'] : [];
		$tag_ids        = isset( $data['tag_ids'] ) && is_array( $data['tag_ids'] ) ? $data['tag_ids'] : null;
		$instructors    = isset( $data['instructors'] ) && is_array( $data['instructors'] ) ? $data['instructors'] : null;
		$has_commission = array_key_exists( 'commission_rate', $data );
		$commission     = $has_commission ? $data['commission_rate'] : false;
		unset( $data['settings'], $data['tag_ids'], $data['instructors'], $data['commission_rate'] );

		// Sale price must stay strictly below the regular price. We compare
		// against whichever side of each pair is being updated, falling back
		// to the existing course row when only one is in the payload.
		// (Basecamp #9925579263.)
		$next_price = array_key_exists( 'price', $data ) ? (float) $data['price'] : (float) $course->price;
		$next_sale  = array_key_exists( 'sale_price', $data ) ? (float) $data['sale_price'] : (float) ( $course->sale_price ?? 0 );
		if ( $next_sale > 0 && $next_sale >= $next_price ) {
			return $this->error_response(
				$this->validation_error( __( 'Sale price must be lower than the regular price.', 'learnomy' ) )
			);
		}

		if ( empty( $data ) && empty( $settings ) && null === $tag_ids && null === $instructors && ! $has_commission ) {
			return $this->error_response(
				$this->validation_error( __( 'No valid fields provided for update.', 'learnomy' ) )
			);
		}

		$service = new Course_Service();

		if ( ! empty( $data ) ) {
			$updated = $service->update( (int) $course->id, $data );
			if ( ! $updated ) {
				return $this->error_response(
					$this->validation_error( __( 'Update failed. Please try again.', 'learnomy' ) )
				);
			}
		}

		if ( ! empty( $settings ) ) {
			Course::update_settings( (int) $course->id, $settings );
		}

		if ( null !== $tag_ids ) {
			$this->sync_course_tags( (int) $course->id, $tag_ids );
		}

		$primary_reassigned = array_key_exists( 'author_id', $data );
		if ( null !== $instructors ) {
			// The supplied roster drives the junction; author_id (already
			// persisted above) decides which row is primary.
			$this->sync_course_instructors( (int) $course->id, $instructors );
		} elseif ( $primary_reassigned ) {
			// Primary changed but no roster supplied — keep the existing
			// co-instructors and their shares, just move is_primary to the new
			// author (sync reads the now-updated author_id).
			$existing = array();
			foreach ( \Learnomy\Models\CourseInstructor::get_for_course( (int) $course->id ) as $row ) {
				$existing[ (int) $row->user_id ] = (float) $row->share_pct;
			}
			$this->sync_course_instructors( (int) $course->id, $existing );
		}

		if ( $has_commission ) {
			\Learnomy\Models\CommissionRule::set_course_rate( (int) $course->id, $commission );
		}

		/** Documented in create_course(). */
		do_action( 'learnomy_course_saved', (int) $course->id, $request );

		return $this->single_response( $this->course_envelope( (int) $course->id ) );
	}

	/**
	 * Replace the course → tag junction with the given list.
	 *
	 * Delegates entirely to the model layer so (a) the duplicate raw-$wpdb
	 * code in this controller is gone, (b) usage_count is kept accurate on
	 * every save (the previous copy silently skipped the recompute step),
	 * and (c) orphan junction rows (tag deleted, map row left behind) are
	 * never returned via get_ids_for_course() because the model validates
	 * each tag ID against lrn_tags before inserting.
	 *
	 * @param int   $course_id Course the tags attach to.
	 * @param int[] $tag_ids   Tag IDs to sync onto the course.
	 */
	private function sync_course_tags( int $course_id, array $tag_ids ): void {
		Tag::sync_object_tags( $course_id, 'course', $tag_ids );
	}

	/**
	 * Build the public-facing course payload: every column plus a nested
	 * `settings` object filled in from `settings_json` (defaults applied).
	 * Keeps callers from having to read `settings_json` themselves.
	 *
	 * Tag IDs come from Tag::get_ids_for_course() (model layer) instead of
	 * a raw $wpdb query so orphan junction rows (tag deleted, map row left
	 * behind) are silently filtered and the response shape stays consistent
	 * with what the editor round-trips through sync_course_tags().
	 *
	 * @return array<string,mixed>
	 */
	/**
	 * Batch-compute the `viewer` block for a set of course IDs (mobile-API
	 * contract WI-4). ONE enrollment query + ONE wishlist read for the whole
	 * set — never per-row (big-site checklist rule 3).
	 *
	 * @param int[] $course_ids Course IDs on the current page.
	 * @return array<int,?array> course_id => viewer block, or null values when
	 *                           the caller is anonymous (key always present so
	 *                           the response shape is stable for strict parsers).
	 */
	private function viewer_for_courses( array $course_ids ): array {
		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		$user_id    = get_current_user_id();

		if ( $user_id <= 0 || empty( $course_ids ) ) {
			return array_fill_keys( $course_ids, null );
		}

		$enrollments = \Learnomy\Models\Enrollment::find_for_user_courses( $user_id, $course_ids );
		$wishlist    = \Learnomy\wishlist_ids( $user_id );

		$viewer = [];
		foreach ( $course_ids as $course_id ) {
			$enrollment           = $enrollments[ $course_id ] ?? null;
			$viewer[ $course_id ] = [
				'is_enrolled'   => null !== $enrollment,
				'progress_pct'  => null !== $enrollment ? (int) ( $enrollment->progress_pct ?? 0 ) : 0,
				'is_completed'  => null !== $enrollment && ( 'completed' === ( $enrollment->status ?? '' ) || null !== ( $enrollment->completed_at ?? null ) ),
				'is_wishlisted' => in_array( $course_id, $wishlist, true ),
			];
		}

		return $viewer;
	}

	/**
	 * Attach the `viewer` block to a page of course rows (arrays). Adds a
	 * `can_enroll_free` convenience the app's enroll-CTA state machine reads.
	 *
	 * @param array $rows Course rows (each an array with at least `id`).
	 * @return array Rows with `viewer` attached (null when anonymous).
	 */
	private function attach_viewer( array $rows ): array {
		$ids    = array_map( static fn( $row ) => (int) ( is_array( $row ) ? ( $row['id'] ?? 0 ) : ( $row->id ?? 0 ) ), $rows );
		$viewer = $this->viewer_for_courses( $ids );

		foreach ( $rows as $i => $row ) {
			$is_array = is_array( $row );
			$id       = (int) ( $is_array ? ( $row['id'] ?? 0 ) : ( $row->id ?? 0 ) );
			$block    = $viewer[ $id ] ?? null;

			if ( null !== $block ) {
				// Free course the viewer hasn't joined — the app shows the
				// one-tap in-app enroll instead of the web-checkout handoff.
				$block['can_enroll_free'] = ! $block['is_enrolled'] && Course::is_free( $is_array ? (object) $row : $row );
			}

			if ( $is_array ) {
				$rows[ $i ]['viewer'] = $block;
			} else {
				$rows[ $i ]->viewer = $block;
			}
		}

		return $rows;
	}

	/**
	 * Attach the price as the SITE would print it.
	 *
	 * A native client cannot format money for a Learnomy site. `format_price()`
	 * resolves the symbol (explicit `currency_symbol` setting, else the ISO code
	 * through the filterable `learnomy_currency_symbols` map), honours symbol
	 * POSITION, uses zero decimals for zero-decimal currencies, and runs the
	 * digits through number_format_i18n. An app reimplementing that gets a
	 * different answer from the web on the same install — "USD 49" where the site
	 * shows "$49", "€49" where it shows "49 €", "¥1000.00" where it shows "¥1000".
	 *
	 * Sent alongside the raw numbers, not instead of them: a client still needs
	 * `price` to compare or sort. This is what it PRINTS.
	 *
	 * @param array<int,array<string,mixed>>|array<string,mixed> $rows One row or many.
	 * @param bool                                               $single Treat $rows as a single row.
	 * @return array
	 */
	private function with_price_display( array $rows, bool $single = false ): array {
		$decorate = static function ( $row ) {
			$row = (array) $row;
			if ( ! array_key_exists( 'price', $row ) ) {
				return $row;
			}
			$row['price_display'] = \Learnomy\format_price( (float) ( $row['price'] ?? 0 ) );
			if ( isset( $row['sale_price'] ) && null !== $row['sale_price'] && '' !== $row['sale_price'] ) {
				$row['sale_price_display'] = \Learnomy\format_price( (float) $row['sale_price'] );
			}
			return $row;
		};

		if ( $single ) {
			return $decorate( $rows );
		}

		return array_map(
			static function ( $row ) use ( $decorate ) {
				$out = $decorate( $row );
				return is_object( $row ) ? (object) $out : $out;
			},
			$rows
		);
	}

	private function course_envelope( int $course_id ): array {
		$course = Course::find( $course_id );
		if ( ! $course ) {
			return [];
		}
		$row             = Course::to_typed_array( $course ) ?? [];
		$row['settings'] = Course::get_all_settings( $course_id );
		// Don't leak the raw JSON blob to clients — they use `settings` instead.
		unset( $row['settings_json'] );
		// Tag model validates each ID against lrn_tags and filters orphan rows.
		$row['tag_ids'] = Tag::get_ids_for_course( $course_id );
		return $this->with_price_display( $row, true );
	}

	public function delete_course( WP_REST_Request $request ): WP_REST_Response {
		$course = Course::find( (int) $request->get_param( 'id' ) );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$service = new Course_Service();
		$deleted = $service->delete( (int) $course->id );

		if ( ! $deleted ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not delete the course. Please try again.', 'learnomy' ) )
			);
		}

		return $this->single_response(
			[
				'deleted' => true,
				'id'      => (int) $course->id,
			]
		);
	}

	/**
	 * Apply a single bulk action across multiple courses. Returns a
	 * Stripe-style results array — one entry per id with explicit success
	 * or error per row, so a partial failure surfaces in the UI rather
	 * than disappearing into a "X updated" count.
	 */
	public function bulk_courses( WP_REST_Request $request ): WP_REST_Response {
		$action = (string) $request->get_param( 'action' );
		$ids    = array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) );

		$service = new Course_Service();
		$results = [];

		foreach ( $ids as $id ) {
			$course = Course::find( $id );
			if ( ! $course ) {
				$results[] = [
					'id'      => $id,
					'success' => false,
					'error'   => [
						'code'    => 'not_found',
						'message' => __( 'Course not found', 'learnomy' ),
					],
				];
				continue;
			}

			if ( 'delete' === $action ) {
				$ok        = $service->delete( $id );
				$results[] = $ok
					? [
						'id'      => $id,
						'success' => true,
						'deleted' => true,
					]
					: [
						'id'      => $id,
						'success' => false,
						'error'   => [
							'code'    => 'delete_failed',
							'message' => __( 'Could not delete.', 'learnomy' ),
						],
					];
				continue;
			}

			// Status flip — `action` doubles as the target status (enum on the route).
			// Route through Course_Service::update() (NOT the bare model) so the
			// lifecycle hooks fire: a bulk publish flips draft -> published and must
			// emit `learnomy_course_published` exactly like the single-row PATCH and
			// the /publish endpoint do. The bare Course::update() skipped them, so a
			// course published via the bulk action never triggered its webhook
			// (Basecamp Card F).
			$ok        = false !== $service->update( $id, [ 'status' => $action ] );
			$results[] = $ok
				? [
					'id'      => $id,
					'success' => true,
					'status'  => $action,
				]
				: [
					'id'      => $id,
					'success' => false,
					'error'   => [
						'code'    => 'update_failed',
						'message' => __( 'Could not update.', 'learnomy' ),
					],
				];
		}

		return $this->single_response(
			[
				'action'  => $action,
				'results' => $results,
			]
		);
	}

	/**
	 * Reset enrollment progress so the student can take the course again.
	 *
	 * Replaces the legacy `wp_ajax_learnomy_retake_course` handler. The
	 * heavy lifting (and the `learnomy_course_retaken` extension hook
	 * that customer code may listen to) lives in
	 * Enrollment_Service::retake() — this controller is a thin authn /
	 * authz / response-envelope wrapper.
	 *
	 * Permission: must be logged in (Base_Controller::permission_logged_in).
	 *             The service refuses the operation when the course's
	 *             allow_retake setting is off, surfacing as 403.
	 *
	 * Response shape mirrors what the AJAX handler returned so the JS
	 * caller can keep its existing `result.data.message` / `result.data.redirect`
	 * access pattern after the migration.
	 */
	public function retake_course( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'id' );

		$course = Course::find( $course_id );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$user_id = get_current_user_id();
		$service = new Enrollment_Service();

		if ( ! $service->retake( $user_id, $course_id ) ) {
			return $this->error_response(
				new WP_Error(
					'learnomy_retake_unavailable',
					__( 'Retake is not available for this course.', 'learnomy' ),
					[ 'status' => 403 ]
				)
			);
		}

		// Retake just wiped progress, so send the student into the first
		// lesson to actually start re-taking — landing back on the same
		// course page they submitted from read as a silent no-op. Fall back
		// to the course page (with a retaken flag so the reload is never
		// invisible) when the course has no lessons or no resolvable slug.
		$slug         = (string) ( $course->slug ?? '' );
		$first_lesson = $slug ? ( Lesson::get_for_course( $course_id )[0] ?? null ) : null;
		$first_slug   = $first_lesson ? (string) ( $first_lesson->slug ?? '' ) : '';

		if ( '' !== $first_slug ) {
			$redirect_url = \Learnomy\route_url(
				'lesson',
				array(
					'slug'        => $slug,
					'lesson_slug' => $first_slug,
				)
			);
		} elseif ( '' !== $slug ) {
			$redirect_url = add_query_arg( 'retaken', '1', \Learnomy\route_url( 'course', array( 'slug' => $slug ) ) );
		} else {
			$redirect_url = \Learnomy\route_url( 'catalog' );
		}

		return $this->single_response(
			[
				'message'  => __( 'Retake started. Loading the first lesson…', 'learnomy' ),
				'redirect' => $redirect_url,
			]
		);
	}

	public function publish_course( WP_REST_Request $request ): WP_REST_Response {
		$course = Course::find( (int) $request->get_param( 'id' ) );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		if ( 'published' === $course->status ) {
			return $this->error_response(
				$this->validation_error( __( 'This course is already published.', 'learnomy' ) )
			);
		}

		$service   = new Course_Service();
		$published = $service->publish( (int) $course->id );

		if ( ! $published ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not publish the course. Please try again.', 'learnomy' ) )
			);
		}

		return $this->single_response( Course::to_typed_array( Course::find( (int) $course->id ) ) );
	}

	public function duplicate_course( WP_REST_Request $request ): WP_REST_Response {
		$course = Course::find( (int) $request->get_param( 'id' ) );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$service       = new Course_Service();
		$new_course_id = $service->duplicate( (int) $course->id );

		if ( ! $new_course_id ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not duplicate the course. Please try again.', 'learnomy' ) )
			);
		}

		$response = $this->single_response( Course::to_typed_array( Course::find( $new_course_id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	public function get_stats( WP_REST_Request $request ): WP_REST_Response {
		$course = Course::find( (int) $request->get_param( 'id' ) );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$user_id = get_current_user_id();

		$service           = new Course_Service();
		$completion_status = $service->get_completion_status( $user_id, (int) $course->id );

		$stats = [
			'course_id'     => (int) $course->id,
			'student_count' => (int) $course->student_count,
			'avg_rating'    => (float) $course->avg_rating,
			'review_count'  => (int) $course->review_count,
			'completion'    => $completion_status,
		];

		return $this->single_response( $stats );
	}

	public function get_outline( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'id' );
		$course    = Course::find( $course_id );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$sections = Section::get_for_course( $course_id );
		$outline  = [];

		// Batch-load the whole outline tree in two queries instead of
		// O(sections × lessons): all top-level lessons for every section, then
		// all topics for every lesson. Avoids the per-section get_for_section()
		// and per-lesson get_topics() N+1.
		$section_ids        = array_map(
			static function ( $section ) {
				return (int) $section->id;
			},
			$sections
		);
		$lessons_by_section = Lesson::get_for_sections( $section_ids );

		$lesson_ids = [];
		foreach ( $lessons_by_section as $section_lessons ) {
			foreach ( $section_lessons as $lesson ) {
				$lesson_ids[] = (int) $lesson->id;
			}
		}
		$topics_by_lesson = Lesson::get_topics_for_parents( $lesson_ids );

		// Editor-only route (`can_manage`), so it reports the real outline
		// including drafts — this is the preview surface authors build against.
		$quizzes = Quiz::get_for_course( $course_id, null );

		// Quizzes are placed, not just owned: a quiz naming a lesson belongs
		// under that lesson, one left on "end of section" closes its section,
		// and one attached to no section is a course-level assessment. The
		// payload used to say none of that — every quiz arrived in a single
		// flat list beside the sections — so a client could not reproduce the
		// outline the web renders even though the rows carried section_id and
		// lesson_id all along (Basecamp 10181611470). Same splitter the web
		// surfaces use, so the two cannot describe a course differently.
		$quizzes_by_section = [];
		foreach ( $quizzes as $quiz_row ) {
			$quiz_section = (int) ( $quiz_row->section_id ?? 0 );
			if ( $quiz_section > 0 ) {
				$quizzes_by_section[ $quiz_section ][] = $quiz_row;
			}
		}

		$typed_quiz = static function ( $q ) {
			return Quiz::to_typed_array( $q );
		};

		foreach ( $sections as $section ) {
			$lessons     = $lessons_by_section[ (int) $section->id ] ?? [];
			$lesson_data = [];

			$quiz_groups = Quiz::split_by_lesson(
				$quizzes_by_section[ (int) $section->id ] ?? [],
				array_map( static fn( $l ) => (int) $l->id, $lessons )
			);

			foreach ( $lessons as $lesson ) {
				$topics                = $topics_by_lesson[ (int) $lesson->id ] ?? [];
				$lesson_item           = Lesson::to_typed_array( $lesson );
				$lesson_item['topics'] = array_map(
					function ( $t ) {
						return Lesson::to_typed_array( $t );
					},
					$topics
				);
				// Present on every lesson, empty when it has none, so a client
				// can iterate without testing for the key's existence.
				$lesson_item['quizzes'] = array_map( $typed_quiz, $quiz_groups['by_lesson'][ (int) $lesson->id ] ?? [] );
				$lesson_data[]          = $lesson_item;
			}

			$section_item             = Section::to_typed_array( $section );
			$section_item['lessons']  = $lesson_data;
			$section_item['quizzes']  = array_map( $typed_quiz, $quiz_groups['section_end'] );
			$outline[]                = $section_item;
		}

		$data             = Course::to_typed_array( $course ) ?? [];
		$data['sections'] = $outline;
		// The flat list stays for now. It is what every existing client reads,
		// including shipped app builds, so removing it in the same release that
		// adds the nested form would break them on Apple's schedule rather than
		// ours. Deprecate it once the app ships against the nested shape.
		$data['quizzes']  = array_map( $typed_quiz, $quizzes );

		return $this->single_response( $data );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function can_create(): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'create_course' resolves to learnomy_create_course: the cap the
		// wp-admin "Add New Course" screen has always been gated on, the one
		// the instructor role grants, and the one the docs name. This route
		// used to check `manage_own_courses` instead, so revoking the
		// documented cap had no effect on the API (Basecamp 10133286758).
		// Admins pass via Permission_Engine's manage_options short-circuit.
		if ( ! $this->check_permission( 'create_course' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	public function can_manage( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$course_id = (int) $request->get_param( 'id' );
		if ( ! $this->check_permission( 'manage_course', $course_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * Co-instructor assignment + revenue-split writes are SITE-OWNER ONLY.
	 * Assigning instructors and dividing revenue is a platform decision, not
	 * something an owning instructor sets for themselves (mirrors the
	 * admin-only Instructors card + commission gating in the course editor).
	 * The GET list stays on can_manage so an owner can still SEE their team;
	 * only attach / update-share / detach require manage_options.
	 */
	public function can_manage_team( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->permission_error();
		}
		return true;
	}

	/**
	 * Bulk endpoint gate — admin-only. Per-row permission would be too
	 * fine-grained here (ids are mixed authorship); the legacy AJAX
	 * required manage_options too.
	 */
	public function can_bulk(): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// Bulk operations mix course ownership; admin-only via manage_settings.
		// Permission_Engine short-circuits on manage_options automatically.
		if ( ! $this->check_permission( 'manage_settings' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * Any authenticated user can toggle their wishlist.
	 */
	public function can_wishlist(): bool|WP_Error {
		$result = $this->require_auth();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Wishlist handlers
	// -------------------------------------------------------------------------

	/**
	 * Add a course to the current user's wishlist.
	 */
	public function add_to_wishlist( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'id' );
		$user_id   = get_current_user_id();

		if ( ! Course::find( $course_id ) ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		try {
			// The service owns both the meta and the already-enrolled guard, so
			// the mobile app and any other caller get the same answer.
			$wishlist = Wishlist_Service::add( $user_id, $course_id );
		} catch ( \RuntimeException $e ) {
			return $this->error_response( $this->validation_error( $e->getMessage() ) );
		}

		return $this->single_response(
			[
				'wishlisted' => true,
				'course_id'  => $course_id,
				'wishlist'   => $wishlist,
			]
		);
	}

	/**
	 * Remove a course from the current user's wishlist.
	 */
	public function remove_from_wishlist( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'id' );
		$user_id   = get_current_user_id();
		$wishlist  = Wishlist_Service::remove( $user_id, $course_id );

		return $this->single_response(
			[
				'wishlisted' => false,
				'course_id'  => $course_id,
				'wishlist'   => $wishlist,
			]
		);
	}

	/**
	 * Return the full wishlist (course IDs) for the current user.
	 */
	public function get_wishlist( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();

		// Only ids the caller can actually render. This returned every saved id,
		// including courses since unpublished or deleted, so a client had no way to
		// tell a real saved course from one that would 404 — the app showed broken
		// rows and the web tab counted items it could not display.
		//
		// Same source as the account page (Wishlist_Service::visible_courses), so
		// the two surfaces cannot disagree. Storage is untouched: a course that is
		// unpublished for editing reappears here when it is published again.
		$courses = \Learnomy\Services\Wishlist_Service::visible_courses( $user_id );

		// Return the ROWS, not just their ids.
		//
		// This handler always had the full course rows and mapped them down to
		// ids, so a client rendering a wishlist screen had to fetch every course
		// back one at a time - N requests for an N-course wishlist, which is the
		// N+1 the scale checklist forbids, and there is no ids/include filter on
		// GET /courses to hydrate them in one call (Basecamp 10198895068, app
		// #10198771229). The web account page never had this problem: it reads
		// Wishlist_Service::visible_courses() directly and has had the rows all
		// along. The API was the degraded surface, not the feature.
		//
		// Rows go through the SAME three transforms as GET /courses -
		// normalize_rows, attach_viewer, with_price_display - so the shape is
		// identical and a client can reuse its course-card component unchanged.
		// Copying the shape by hand is how two list endpoints drift.
		$total    = count( $courses );
		$per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$course_ids = array_map( static fn( $course ) => (int) $course->id, $courses );

		$courses = array_slice( $courses, $offset, $per_page );
		$courses = \Learnomy\Content\Content_HTML::normalize_rows( $courses, 'description' );
		$courses = $this->attach_viewer( $courses );
		$courses = $this->with_price_display( $courses );

		return $this->paginated_response(
			$courses,
			$total,
			$offset,
			( $offset + count( $courses ) ) < $total,
			[
				// Kept alongside the rows: the web wishlist tab and the heart
				// toggle read ids, and removing them would break those for a
				// gain nobody asked for. It is the COMPLETE id set, not just
				// this page, which is what a toggle needs.
				'course_ids' => $course_ids,
			]
		);
	}

	// ─── Co-instructor management ────────────────────────────────────────────
	//
	// These endpoints let the frontend builder maintain the
	// `lrn_course_instructors` junction without kicking instructors into
	// wp-admin. Revenue share validation happens up-front so the row is
	// always coherent.

	public function list_course_instructors( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'id' );
		if ( ! Course::find( $course_id ) ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$rows  = \Learnomy\Models\CourseInstructor::get_for_course( $course_id );
		$items = array_map(
			function ( $row ) {
				$user = get_userdata( (int) $row->user_id );
				return [
					'user_id'      => (int) $row->user_id,
					'display_name' => $user ? $user->display_name : '',
					'email'        => $user ? $user->user_email : '',
					'avatar_url'   => $user ? get_avatar_url( (int) $row->user_id, [ 'size' => 48 ] ) : '',
					'share_pct'    => (float) $row->share_pct,
					'is_primary'   => (bool) $row->is_primary,
					'added_at'     => $row->added_at,
				];
			},
			$rows
		);

		return $this->single_response( [ 'instructors' => $items ] );
	}

	public function attach_course_instructor( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'id' );
		$user_id   = (int) $request->get_param( 'user_id' );
		$share     = (float) $request->get_param( 'share_pct' );

		if ( ! Course::find( $course_id ) ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return $this->error_response( $this->validation_error( __( 'Instructor user not found.', 'learnomy' ) ) );
		}

		// Reject if this user is already attached — updates go through PATCH.
		foreach ( \Learnomy\Models\CourseInstructor::get_for_course( $course_id ) as $existing ) {
			if ( (int) $existing->user_id === $user_id ) {
				return $this->error_response(
					$this->validation_error( __( 'That user is already an instructor on this course.', 'learnomy' ) )
				);
			}
		}

		// Enforce the 100% share cap across all instructors — shrink primary
		// automatically so the split stays balanced.
		$this->rebalance_shares_for_attach( $course_id, $share );

		\Learnomy\Models\CourseInstructor::add_instructor( $course_id, $user_id, $share, false );

		return $this->list_course_instructors( $request );
	}

	public function update_course_instructor_share( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'id' );
		$user_id   = (int) $request->get_param( 'user_id' );
		$share     = (float) $request->get_param( 'share_pct' );

		$found = null;
		foreach ( \Learnomy\Models\CourseInstructor::get_for_course( $course_id ) as $existing ) {
			if ( (int) $existing->user_id === $user_id ) {
				$found = $existing;
				break;
			}
		}
		if ( ! $found ) {
			return $this->error_response( $this->not_found( 'Instructor' ) );
		}

		// Swap the existing row for one with the new share. add_instructor
		// handles the insert; remove_instructor tidies up the old row.
		\Learnomy\Models\CourseInstructor::remove_instructor( $course_id, $user_id );
		\Learnomy\Models\CourseInstructor::add_instructor(
			$course_id,
			$user_id,
			$share,
			(bool) $found->is_primary
		);

		return $this->list_course_instructors( $request );
	}

	public function detach_course_instructor( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'id' );
		$user_id   = (int) $request->get_param( 'user_id' );

		$rows   = \Learnomy\Models\CourseInstructor::get_for_course( $course_id );
		$target = null;
		foreach ( $rows as $row ) {
			if ( (int) $row->user_id === $user_id ) {
				$target = $row;
				break;
			}
		}
		if ( ! $target ) {
			return $this->error_response( $this->not_found( 'Instructor' ) );
		}
		if ( (int) $target->is_primary === 1 ) {
			return $this->error_response(
				$this->validation_error( __( 'The primary instructor cannot be removed from a course.', 'learnomy' ) )
			);
		}

		\Learnomy\Models\CourseInstructor::remove_instructor( $course_id, $user_id );

		// Return the primary instructor's share to 100% of what's left.
		$this->rebalance_shares_after_detach( $course_id );

		return $this->list_course_instructors( $request );
	}

	/**
	 * Before we attach a new instructor with $new_share%, take that amount
	 * out of the primary instructor's share so the total stays at 100.
	 */
	private function rebalance_shares_for_attach( int $course_id, float $new_share ): void {
		foreach ( \Learnomy\Models\CourseInstructor::get_for_course( $course_id ) as $inst ) {
			if ( 1 === (int) $inst->is_primary ) {
				$new_primary = max( 0.0, (float) $inst->share_pct - $new_share );
				\Learnomy\Models\CourseInstructor::remove_instructor( $course_id, (int) $inst->user_id );
				\Learnomy\Models\CourseInstructor::add_instructor( $course_id, (int) $inst->user_id, $new_primary, true );
				break;
			}
		}
	}

	/**
	 * After a co-instructor leaves, add their former share back onto the
	 * primary instructor so the total returns to 100%.
	 */
	private function rebalance_shares_after_detach( int $course_id ): void {
		$rows  = \Learnomy\Models\CourseInstructor::get_for_course( $course_id );
		$total = 0.0;
		foreach ( $rows as $row ) {
			$total += (float) $row->share_pct;
		}
		if ( $total >= 100.0 ) {
			return; // Nothing to reclaim.
		}
		$missing = 100.0 - $total;
		foreach ( $rows as $row ) {
			if ( 1 === (int) $row->is_primary ) {
				$adjusted = (float) $row->share_pct + $missing;
				\Learnomy\Models\CourseInstructor::remove_instructor( $course_id, (int) $row->user_id );
				\Learnomy\Models\CourseInstructor::add_instructor( $course_id, (int) $row->user_id, $adjusted, true );
				return;
			}
		}
	}

	/**
	 * Read the wishlist from usermeta as an array of integer course IDs.
	 */
	private function get_user_wishlist( int $user_id ): array {
		// Delegates to the single robust reader (handles both the JSON-string
		// and raw-array storage shapes — see \Learnomy\wishlist_ids).
		return \Learnomy\wishlist_ids( $user_id );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function extract_course_fields( WP_REST_Request $request ): array {
		$data = [];

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$data['title'] = sanitize_text_field( wp_unslash( $title ) );
		}

		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			// HTML-first storage cutover (ON by default, kill-switch) — the rich course "About" body.
			// Editor posts Editor.js JSON; for_storage() canonicalises to HTML
			// via the one shared converter (INV-9). Off → store as before. Reads
			// stay dual-format (INV-8).
			// Do NOT wp_unslash() — REST JSON body params are not slashed, and
			// unslashing strips the \" escaping in the Editor.js description
			// (e.g. a shortcode attribute), corrupting the JSON. Same fix as the
			// lessons `content` field.
			$raw                 = (string) $description;
			$data['description'] = \Learnomy\Content\Content_HTML::storage_enabled()
				? \Learnomy\Content\Content_HTML::for_storage( $raw )
				: wp_kses_post( $raw );
			// `description_plain` (the FULLTEXT projection) is NOT derived here.
			// It is derived by Course::search_projections() on every write, so
			// CLI, seeder and importer saves get it too — deriving it again here
			// is what made this controller the only path that maintained it.
		}

		$short_description = $request->get_param( 'short_description' );
		if ( null !== $short_description ) {
			$data['short_description'] = sanitize_text_field( wp_unslash( $short_description ) );
		}

		$status = $request->get_param( 'status' );
		if ( null !== $status ) {
			// Status changes route through Permission_Engine — the canonical
			// entry point. `publish_course` honours both course ownership AND
			// the site's self-publish policy; administrators always pass via
			// the manage_options short-circuit inside Permission_Engine::can().
			// Instructors without ownership or the policy are limited to draft
			// / pending_review.
			//
			// ON CREATE THE COURSE ID IS 0 — there is no row to own yet, so
			// `publish_course` cannot be the question. Ask the half that IS
			// answerable: `self_publish_course` (site policy for this user).
			// Ownership is not in doubt on create, because the requester
			// becomes the author (see create_course()'s $author_id, which only
			// an administrator may point elsewhere). Asking the ownership
			// question anyway returned a false negative, so an instructor who
			// could publish the very same course one click later from the
			// editor had their create-flow Publish diverted into the approval
			// queue — the asymmetry in Basecamp 10132217506.
			$course_id      = (int) $request->get_param( 'id' );
			$current_status = $course_id ? (string) ( Course::find( $course_id )->status ?? '' ) : '';

			if ( $status === $current_status ) {
				// No transition — an instructor re-saving an already-approved
				// course submits the current status (e.g. 'published'), which
				// isn't in their allowed transition set. The old fallback forced
				// 'draft', silently unpublishing a live course on every edit
				// (Basecamp #9919776646). A no-op keeps the existing status.
				$data['status'] = $current_status;
			} else {
				$can_publish      = $course_id
					? $this->check_permission( 'publish_course', $course_id )
					: $this->check_permission( 'self_publish_course' );
				$allowed_statuses = $can_publish
					? [ 'draft', 'pending_review', 'published', 'archived', 'trashed' ]
					: [ 'draft', 'pending_review' ];
				if ( in_array( $status, $allowed_statuses, true ) ) {
					$data['status'] = $status;
				} elseif ( 'published' === $status ) {
					// An instructor without publish_course asking to publish is
					// SUBMITTING FOR APPROVAL — that is what the approval flow
					// exists for. The old fallback kept the current status
					// (draft), so the request vanished: no reviewer ever saw
					// it, while the builder's redirect claimed success
					// (Basecamp 10132217506). The builder reads the response's
					// real status and words its toast accordingly.
					$data['status'] = 'pending_review';
				} else {
					// Any other disallowed transition keeps the current status
					// (never demote a live course); only a brand-new course
					// with no persisted status degrades to draft.
					$data['status'] = '' !== $current_status ? $current_status : 'draft';
				}
			}
		}

		$price = $request->get_param( 'price' );
		if ( null !== $price ) {
			$data['price'] = max( 0, (float) $price );
		}

		$access_type = $request->get_param( 'access_type' );
		if ( null !== $access_type ) {
			if ( in_array( $access_type, \Learnomy\Models\Course::ACCESS_TYPES, true ) ) {
				/*
				 * The purchase lock has to hold on THIS path too.
				 *
				 * It used to guard only the explicit `pricing_model` branch
				 * below, so a caller sending `access_type` walked straight past
				 * it and the mirror three lines down rewrote pricing_model
				 * anyway. The admin editor is exactly such a caller: it posts a
				 * hidden access_type mirror on every save.
				 *
				 * Combined with the editor reading a stale column, that turned
				 * "edit the title of a course with three paying customers" into
				 * "make it free and zero its price" (Basecamp 10171334397). A
				 * lock that only covers one of two doors is not a lock.
				 *
				 * Refused silently, matching the pricing_model branch: the save
				 * succeeds for every other field and the model is left alone,
				 * which is what the disabled radios already promise the owner.
				 */
				$course_id_for_lock  = (int) $request->get_param( 'id' );
				$model_would_change  = \Learnomy\Models\Course::model_for_access( $access_type );
				$access_locked       = $course_id_for_lock
					&& \Learnomy\Models\Course::lock_pricing_model_if_purchased( $course_id_for_lock );

				if ( $access_locked ) {
					$existing = \Learnomy\Models\Course::find( $course_id_for_lock );
					if ( $existing && \Learnomy\Models\Course::pricing_model( $existing ) !== $model_would_change ) {
						// Drop both keys; the course keeps the model its
						// customers bought under.
						$access_type = null;
					}
				}
			}
			if ( null !== $access_type && in_array( $access_type, \Learnomy\Models\Course::ACCESS_TYPES, true ) ) {
				$data['access_type'] = $access_type;

				// Keep pricing_model in lockstep with access_type when the
				// caller writes access_type alone (the legacy admin-form path).
				// Without this, a course saved as `paid` kept its default
				// `pricing_model='free'`, and Course::pricing_model() then read
				// the stale column and rendered the paid course as a free
				// enrollment on /enroll/. The explicit-pricing_model branch
				// below overrides this mirror when the caller sends both. The
				// map is the single source of truth on the Course model.
				$data['pricing_model'] = \Learnomy\Models\Course::model_for_access( $access_type );
			}
		}

		// PR-A1: pricing_model is the canonical flexible-pricing column.
		// When the caller passes it, we write `pricing_model` AND keep the
		// legacy `access_type` mirror in sync so older display code keeps
		// rendering. Sticky-model enforcement happens here: if the course
		// already has paying customers, refuse to overwrite pricing_model.
		$pricing_model = $request->get_param( 'pricing_model' );
		if ( null !== $pricing_model ) {
			if ( in_array( $pricing_model, \Learnomy\Models\Course::PRICING_MODELS, true ) ) {
				$course_id_for_lock = (int) $request->get_param( 'id' );
				$is_locked          = $course_id_for_lock && \Learnomy\Models\Course::lock_pricing_model_if_purchased( $course_id_for_lock );
				if ( ! $is_locked ) {
					$data['pricing_model'] = $pricing_model;

					// Mirror to access_type so legacy reads (course catalog,
					// older templates) stay correct without a second save. The
					// model->access map is the single source on the Course model.
					$data['access_type'] = \Learnomy\Models\Course::access_for_model( $pricing_model );
				}
			}
		}

		$access_duration_days = $request->get_param( 'access_duration_days' );
		if ( null !== $access_duration_days ) {
			$days                         = (int) $access_duration_days;
			$data['access_duration_days'] = $days > 0 ? $days : null;
		}

		$recurring_amount = $request->get_param( 'recurring_amount' );
		if ( null !== $recurring_amount ) {
			$amount                   = (float) $recurring_amount;
			$data['recurring_amount'] = $amount > 0 ? $amount : null;
		}

		$recurring_interval = $request->get_param( 'recurring_interval' );
		if ( null !== $recurring_interval ) {
			$allowed_intervals          = array( 'month', 'year' );
			$data['recurring_interval'] = in_array( $recurring_interval, $allowed_intervals, true ) ? $recurring_interval : null;
		}

		$category_id = $request->get_param( 'category_id' );
		if ( null !== $category_id ) {
			// `category_id` is a first-class column on `lrn_courses`. The
			// frontend builder and admin form both write it, but the previous
			// extractor never copied it through, so instructor saves silently
			// dropped the selected category and the catalog filter never saw
			// the assignment. See Basecamp #9919751484.
			$data['category_id'] = max( 0, (int) $category_id );
		}

		$slug = $request->get_param( 'slug' );
		if ( null !== $slug ) {
			$data['slug'] = sanitize_title( wp_unslash( $slug ) );
		}

		$thumbnail_url = $request->get_param( 'thumbnail_url' );
		if ( null !== $thumbnail_url ) {
			$data['thumbnail_url'] = esc_url_raw( wp_unslash( $thumbnail_url ) );

			// Resolve attachment ID when the picker selected a media library
			// item — keeps the courses → attachments association so the WP
			// delete-attachment hook can null this row out properly.
			if ( ! empty( $data['thumbnail_url'] ) ) {
				$attachment_id = attachment_url_to_postid( $data['thumbnail_url'] );
				if ( $attachment_id ) {
					$data['thumbnail_id'] = $attachment_id;
				}
			}
		}

		// The Pro front-end builder sends a `thumbnail_id` (media attachment ID)
		// rather than a URL. Resolve it to a URL so both authoring surfaces
		// (admin form = thumbnail_url, builder = thumbnail_id) persist the cover
		// image. Only used when thumbnail_url wasn't already supplied above.
		if ( ! isset( $data['thumbnail_url'] ) ) {
			$thumbnail_id = $request->get_param( 'thumbnail_id' );
			if ( null !== $thumbnail_id ) {
				$tid = absint( $thumbnail_id );
				if ( $tid > 0 ) {
					$url = wp_get_attachment_image_url( $tid, 'full' );
					if ( $url ) {
						$data['thumbnail_url'] = esc_url_raw( $url );
						$data['thumbnail_id']  = $tid;
					}
				}
			}
		}

		$completion_type = $request->get_param( 'completion_type' );
		if ( null !== $completion_type ) {
			$allowed_completion = [ 'all_lessons', 'lessons_and_quiz', 'percentage', 'manual' ];
			if ( in_array( $completion_type, $allowed_completion, true ) ) {
				$data['completion_type'] = $completion_type;
			}
		}

		$completion_value = $request->get_param( 'completion_value' );
		if ( null !== $completion_value ) {
			$data['completion_value'] = absint( $completion_value );
		}

		// Per-course enforcement override (tri-state plus inherit). Empty
		// string / 'inherit' / null clears the override so resolution falls
		// back to the site-wide setting.
		if ( $request->has_param( 'completion_enforcement' ) ) {
			$enf = $request->get_param( 'completion_enforcement' );
			if ( null === $enf || '' === $enf || 'inherit' === $enf ) {
				$data['completion_enforcement'] = null;
			} elseif ( in_array( $enf, array( 'strict', 'advisory', 'off' ), true ) ) {
				$data['completion_enforcement'] = (string) $enf;
			}
		}

		$duration_seconds = $request->get_param( 'duration_seconds' );
		if ( null !== $duration_seconds ) {
			$data['duration_seconds'] = absint( $duration_seconds );
		}

		// Per-course SEO overrides. An empty string is a real instruction — the
		// owner cleared the field and wants the derived value back — so it is
		// stored as NULL rather than skipped, which is what "no override" means
		// to the read path.
		foreach ( array( 'seo_title', 'meta_description', 'og_image_url', 'meta_robots' ) as $seo_field ) {
			$seo_value = $request->get_param( $seo_field );
			if ( null !== $seo_value ) {
				$seo_value          = trim( (string) $seo_value );
				$data[ $seo_field ] = '' === $seo_value ? null : $seo_value;
			}
		}

		$og_image_id = $request->get_param( 'og_image_id' );
		if ( null !== $og_image_id ) {
			$og_image_id         = absint( $og_image_id );
			$data['og_image_id'] = $og_image_id > 0 ? $og_image_id : null;
		}

		$difficulty = $request->get_param( 'difficulty' );
		if ( null !== $difficulty ) {
			$data['difficulty'] = sanitize_text_field( wp_unslash( $difficulty ) );
		}

		$video_url = $request->get_param( 'video_url' );
		if ( null !== $video_url ) {
			$data['video_url'] = esc_url_raw( wp_unslash( $video_url ) );
		}

		$sale_price = $request->get_param( 'sale_price' );
		if ( null !== $sale_price ) {
			// A submitted-but-empty/zero sale price means "no sale" — store NULL
			// so it truly clears (and the editor field shows blank on reload)
			// instead of persisting an old value (Basecamp #9981555911).
			$sale_value         = max( 0, (float) $sale_price );
			$data['sale_price'] = $sale_value > 0 ? $sale_value : null;
		}

		$sale_starts = $request->get_param( 'sale_starts' );
		if ( null !== $sale_starts ) {
			$data['sale_starts'] = sanitize_text_field( $sale_starts );
		}

		$sale_ends = $request->get_param( 'sale_ends' );
		if ( null !== $sale_ends ) {
			$data['sale_ends'] = sanitize_text_field( $sale_ends );
		}

		// Per-course commission override. Kept off the column set; applied
		// after the save via CommissionRule::set_course_rate(). The key is
		// emitted (possibly null) only when the caller sent the field, so the
		// handler can distinguish "inherit" (null → clear the course rule) from
		// "not provided" (key absent → leave the rule untouched).
		//
		// SITE-OWNER ONLY: the commission rate is the platform's cut. An
		// instructor managing their own course must never set it, so the field
		// is ignored entirely unless the caller can manage_options — even though
		// can_manage() lets the owning instructor reach this endpoint.
		//
		// MODULE GATE: with `commissions` off there is no control that renders
		// this field and no screen that would ever show the result, so a write
		// through the API is a hidden setting — the site owner would have a
		// commission rule silently governing their payouts with nowhere to see
		// or clear it. Treated as a no-op (the field is dropped) rather than an
		// error, matching how every other module-gated field behaves and how
		// the same request behaves for a non-admin caller.
		if ( $request->has_param( 'commission_rate' )
			&& current_user_can( 'manage_options' )
			&& \Learnomy\Modules\Module_Kernel::instance()->is_enabled( 'commissions' ) ) {
			$cr                      = $request->get_param( 'commission_rate' );
			$data['commission_rate'] = ( null === $cr || '' === $cr || 'inherit' === $cr )
				? null
				: max( 0.0, min( 100.0, (float) $cr ) );
		}

		// Nested `settings` object — flexible toggles + text that live in
		// `settings_json`. Sanitized and stored by a separate call to
		// Course::update_settings() after the main update lands.
		$settings = $request->get_param( 'settings' );
		if ( is_array( $settings ) ) {
			$data['settings'] = $this->sanitize_settings_payload( $settings );
		}

		// Tag IDs — the junction table is kept outside the main column set;
		// the caller syncs after the core course save so save order is clear.
		$tag_ids = $request->get_param( 'tag_ids' );
		if ( is_array( $tag_ids ) ) {
			$data['tag_ids'] = array_values( array_filter( array_map( 'absint', $tag_ids ) ) );
		}

		// Prerequisites — JSON-encoded array of course IDs stored on the
		// course row. Always emit the column when the key is present
		// (including empty array) so unchecking everything in the form
		// actually clears the list.
		$prerequisites = $request->get_param( 'prerequisites' );
		if ( null !== $prerequisites ) {
			$ids                   = is_array( $prerequisites )
				? array_values( array_unique( array_filter( array_map( 'absint', $prerequisites ) ) ) )
				: [];
			$data['prerequisites'] = wp_json_encode( $ids );
		}

		// Instructors — array of { user_id, share_pct } objects, kept off
		// the main column set so the caller can sync the CourseInstructor
		// junction after the core save lands. Mirrors the legacy AJAX
		// `instructors[]` + `share_pct[<uid>]` shape but in a single clean
		// nested array.
		//
		// SITE-OWNER ONLY: assigning co-instructors + revenue splits is a
		// platform control. A non-admin owner editing their course must not
		// reassign instructors or shares, so the field is ignored unless the
		// caller can manage_options (can_manage() lets the owner reach here).
		$instructors = current_user_can( 'manage_options' ) ? $request->get_param( 'instructors' ) : null;
		if ( is_array( $instructors ) ) {
			$rows = [];
			foreach ( $instructors as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$uid = isset( $row['user_id'] ) ? absint( $row['user_id'] ) : 0;
				if ( $uid <= 0 ) {
					continue;
				}
				$rows[ $uid ] = isset( $row['share_pct'] )
					? max( 0.0, min( 100.0, (float) $row['share_pct'] ) )
					: 0.0;
			}
			$data['instructors'] = $rows;
		}

		// Admin-only: set the course author / primary instructor to a chosen
		// user — the "content created on behalf of a presenter" case. Persisted
		// as author_id; create()/update() + sync_course_instructors() then make
		// that user the is_primary=1 lead (and the commission recipient follows).
		// Non-admins can never reassign a course's owner.
		$primary_instructor_id = $request->get_param( 'primary_instructor_id' );
		if ( null !== $primary_instructor_id && current_user_can( 'manage_options' ) ) {
			$pid = absint( $primary_instructor_id );
			if ( $pid > 0 && get_userdata( $pid ) ) {
				$data['author_id'] = $pid;
			}
		}

		// PRICE AND ACCESS TYPE ARE ONE DECISION, NOT TWO.
		//
		// They were written independently, so the two could disagree and the
		// API let them, silently, in both directions:
		//
		// POST { price: 49 }            -> stored price 0.00, access free.
		// The price was DROPPED and a 201
		// returned, so an owner creating a
		// paid course over the API got a
		// free one and no error at all.
		// PATCH { price: 99 } on free   -> stored price 99.00, access free.
		// A course that says free and
		// carries a price -- and checkout
		// reads the price, so it opens a
		// PAID session on a "free" course.
		//
		// The second is the dangerous one: money can be taken for something the
		// catalogue, the course card and the owner's own settings all call
		// free.
		//
		// The rule, in the order an owner means it:
		// 1. An explicit access_type always wins -- if you said free, it is
		// free, and any price sent alongside is contradictory input, so
		// the price is zeroed rather than left to drift.
		// 2. Otherwise a price > 0 MEANS paid. Setting a price is how you say
		// you intend to charge; there is no other reason to set one.
		//
		// pricing_model follows through the same map access_type already uses,
		// so the third column cannot drift either -- the comment above records
		// that access_type and pricing_model drifted apart in exactly this way
		// once already.
		$lrn_access_explicit = null !== $request->get_param( 'access_type' )
			|| null !== $request->get_param( 'pricing_model' );

		if ( array_key_exists( 'price', $data ) ) {
			$lrn_effective_access = $data['access_type'] ?? null;

			// On a PATCH the caller may be adding a price to a course whose
			// access_type is already stored; read it rather than assume free.
			if ( null === $lrn_effective_access ) {
				$lrn_course_id = (int) $request->get_param( 'id' );
				if ( $lrn_course_id > 0 ) {
					$lrn_existing         = \Learnomy\Models\Course::find( $lrn_course_id );
					$lrn_effective_access = $lrn_existing->access_type ?? 'free';
				} else {
					$lrn_effective_access = 'free';
				}
			}

			$lrn_is_free = in_array( (string) $lrn_effective_access, array( 'free', 'closed' ), true );

			if ( $lrn_is_free && (float) $data['price'] > 0 ) {
				if ( $lrn_access_explicit ) {
					// They said free in THIS request and sent a price too.
					// Honour the words, drop the number.
					$data['price'] = 0.0;
				} else {
					// They sent only a price. That is the intent to charge.
					$data['access_type']   = 'paid';
					$data['pricing_model'] = \Learnomy\Models\Course::model_for_access( 'paid' );
				}
			}
		}

		$data = apply_filters( 'learnomy_course_rest_fields', $data, $request );

		return $data;
	}

	/**
	 * Replace the CourseInstructor junction with the given map. The course's
	 * primary instructor (the row's `author_id`) is always preserved — even
	 * if the form omits them — and gets the leftover share if the supplied
	 * map didn't sum to 100 %.
	 *
	 * @param int              $course_id Owning course.
	 * @param array<int,float> $map       user_id → share_pct.
	 */
	private function sync_course_instructors( int $course_id, array $map ): void {
		$course = Course::find( $course_id );
		if ( ! $course ) {
			return;
		}
		$primary_id = (int) ( $course->author_id ?? 0 );

		\Learnomy\Models\CourseInstructor::delete_for_course( $course_id );

		if ( $primary_id > 0 && ! array_key_exists( $primary_id, $map ) ) {
			$map[ $primary_id ] = 100.00;
		}

		foreach ( $map as $uid => $share ) {
			// Validate user_id existence. Without this guard a course
			// author could PATCH the course with an instructors map of
			// {999999: 100} and the row would land in the junction
			// table with a non-existent user_id — every future
			// commission for that course would write to a recipient_id
			// that doesn't resolve, silently leaking revenue or
			// breaking payout queries. The primary fallback above
			// already references the course author so we don't lose
			// the author row when every supplied user_id is invalid.
			$uid_int = (int) $uid;
			if ( $uid_int <= 0 || ! get_userdata( $uid_int ) ) {
				continue;
			}
			\Learnomy\Models\CourseInstructor::add_instructor(
				$course_id,
				$uid_int,
				(float) $share,
				$uid_int === $primary_id
			);
		}
	}

	/**
	 * Sanitize the per-field bucket of the `settings` object. Unknown keys
	 * pass through so add-ons can extend without touching this file; known
	 * keys are coerced to their expected PHP type.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function sanitize_settings_payload( array $raw ): array {
		$bools = [
			'certificate_enabled',
			'allow_retake',
			'reset_on_retake',
			'require_sequential',
			'comments_enabled',
			'reviews_enabled',
			'qa_enabled',
			'announcements_enabled',
			'is_featured',
			'is_private',
		];
		$ints  = [ 'certificate_template_id', 'min_time_seconds' ];
		// Plain-text settings — single-line and short.
		$texts = [ 'target_audience', 'language', 'closed_cta_label' ];
		// URL settings — the closed-model custom-button link.
		$urls = [ 'closed_cta_url' ];
		// Rich-text settings — displayed as HTML to students, allow safe markup.
		$html = [ 'completion_message', 'welcome_message' ];

		$out = [];

		foreach ( $bools as $k ) {
			if ( array_key_exists( $k, $raw ) ) {
				$out[ $k ] = (bool) $raw[ $k ];
			}
		}
		foreach ( $ints as $k ) {
			if ( array_key_exists( $k, $raw ) ) {
				$out[ $k ] = max( 0, (int) $raw[ $k ] );
			}
		}
		foreach ( $texts as $k ) {
			if ( array_key_exists( $k, $raw ) ) {
				$out[ $k ] = sanitize_textarea_field( wp_unslash( (string) $raw[ $k ] ) );
			}
		}
		foreach ( $urls as $k ) {
			if ( array_key_exists( $k, $raw ) ) {
				$out[ $k ] = esc_url_raw( wp_unslash( (string) $raw[ $k ] ) );
			}
		}
		foreach ( $html as $k ) {
			if ( array_key_exists( $k, $raw ) ) {
				// Rich welcome/completion messages are authored by the same
				// block-editor mount as the description, so they convert to
				// canonical HTML through the one shared converter when the cutover
				// is on (INV-9 — keeps these durable; the bulk migrator converts
				// them too). Off → store as before.
				$msg_raw   = wp_unslash( (string) $raw[ $k ] );
				$out[ $k ] = \Learnomy\Content\Content_HTML::storage_enabled()
					? \Learnomy\Content\Content_HTML::for_storage( $msg_raw )
					: wp_kses_post( $msg_raw );
			}
		}

		// Three-way visibility (public | unlisted | private). Canonical control;
		// `is_private` is kept as a derived mirror so the enrolled-only gate and
		// the Pro front-end builder keep reading one boolean.
		if ( array_key_exists( 'visibility', $raw ) ) {
			$visibility        = (string) $raw['visibility'];
			$visibility        = in_array( $visibility, [ 'public', 'unlisted', 'private' ], true ) ? $visibility : 'public';
			$out['visibility'] = $visibility;
			$out['is_private'] = ( 'private' === $visibility );
		}

		if ( array_key_exists( 'learning_outcomes', $raw ) && is_array( $raw['learning_outcomes'] ) ) {
			$out['learning_outcomes'] = array_values(
				array_filter(
					array_map(
						static fn( $item ) => sanitize_text_field( wp_unslash( (string) $item ) ),
						$raw['learning_outcomes']
					)
				)
			);
		}

		// Allow plugins to accept additional keys that their own code knows how to read.
		$out = apply_filters( 'learnomy_course_settings_rest', $out, $raw );

		return $out;
	}

	private function create_course_args( bool $title_required = true ): array {
		$args = [
			'title'                  => [
				'type'              => 'string',
				'required'          => $title_required,
				'sanitize_callback' => 'sanitize_text_field',
			],
			// Editor.js JSON. NO arg-level sanitize_callback: wp_kses_post would
			// corrupt the JSON (and shortcodes with angle-bracket attributes)
			// before parsing — the bug that dumped raw JSON on the course page.
			// The storage layer sanitises after parsing via for_storage()
			// (role-aware kses) or wp_kses_post() when the cutover is off.
			'description'            => [
				'type' => 'string',
			],
			'short_description'      => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'status'                 => [
				'type' => 'string',
				'enum' => [ 'draft', 'pending_review', 'published', 'archived', 'trashed' ],
			],
			'price'                  => [
				'type'    => 'number',
				'minimum' => 0,
			],
			'access_type'            => [
				'type' => 'string',
				// Keep this enum aligned with the admin form options at
				// `includes/admin/views/partials/course-settings-pricing.php:30-35`
				// AND the model's filter list at `class-course.php:172`. The
				// admin dropdown offers `paid_or_membership` as the 4th
				// access mode (course buyable OR included with a plan); if
				// it's missing here the REST validator silently rejects
				// every save where the admin picked that option, leaving
				// the column empty / unchanged.
				'enum' => \Learnomy\Models\Course::ACCESS_TYPES,
			],
			'slug'                   => [
				// sanitize_title() is multi-arg — using it as a REST
				// sanitize_callback crashes when the slug is empty (the
				// request object becomes the fallback_title arg). Sanitize
				// internally instead.
				'type' => 'string',
			],
			'category_id'            => [
				'type'    => 'integer',
				'minimum' => 0,
			],
			'thumbnail_url'          => [
				'type'   => 'string',
				'format' => 'uri',
			],
			'thumbnail_id'           => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'completion_type'        => [
				'type' => 'string',
				'enum' => [ 'all_lessons', 'lessons_and_quiz', 'percentage', 'manual' ],
			],
			'completion_value'       => [
				'type'    => 'integer',
				'minimum' => 0,
			],
			'completion_enforcement' => [
				'type'        => array( 'string', 'null' ),
				'enum'        => array( null, '', 'inherit', 'strict', 'advisory', 'off' ),
				'description' => __( 'Per-course override of completion-rule strictness. NULL / inherit falls back to the site setting.', 'learnomy' ),
			],
			'duration_seconds'       => [
				'type'    => 'integer',
				'minimum' => 0,
			],
			// Per-course SEO overrides. Each is optional; anything left empty
			// keeps deriving from the course itself, which is the behaviour every
			// existing course already has.
			'seo_title'              => [
				'type'              => 'string',
				'maxLength'         => 255,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Overrides the page title and og:title for this course. Leave empty to use the course title.', 'learnomy' ),
			],
			'meta_description'       => [
				'type'              => 'string',
				// 320, not 155: the 155 cap applies to DERIVED text so machine
				// copy cannot run long. An owner who typed a longer one meant it.
				'maxLength'         => 320,
				// Not sanitize_text_field: a description legitimately contains
				// punctuation and accented characters, and this is emitted
				// through esc_attr at print time.
				'sanitize_callback' => static fn( $v ) => trim( wp_strip_all_tags( (string) $v ) ),
				'description'       => __( 'Overrides the search and social description. Leave empty to derive it from the course description.', 'learnomy' ),
			],
			// Both accept an EMPTY value as well as a real one: clearing the
			// field in an editor is how an owner removes an override, and a
			// strict `integer` / `format: uri` rejected the empty string the
			// form sends, failing the whole PATCH with a 400 — every other
			// field on the course silently failed to save with it.
			'og_image_id'            => [
				'type'        => [ 'integer', 'string', 'null' ],
				'description' => __( 'Media-library id for the share image. Used when no direct URL is set.', 'learnomy' ),
			],
			'og_image_url'           => [
				'type'              => [ 'string', 'null' ],
				'maxLength'         => 500,
				'sanitize_callback' => static fn( $v ) => '' === trim( (string) $v ) ? '' : esc_url_raw( (string) $v ),
				'description'       => __( 'Direct share-image URL. Takes precedence over the media-library id.', 'learnomy' ),
			],
			'meta_robots'            => [
				'type'              => 'string',
				'maxLength'         => 64,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Robots directive for this course, e.g. noindex. Unrecognised values are ignored when the tag is emitted.', 'learnomy' ),
			],
			'difficulty'             => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'video_url'              => [
				'type'   => 'string',
				'format' => 'uri',
			],
			'sale_price'             => [
				'type'    => 'number',
				'minimum' => 0,
			],
			'sale_starts'            => [
				'type'   => 'string',
				'format' => 'date-time',
			],
			'sale_ends'              => [
				'type'   => 'string',
				'format' => 'date-time',
			],
			'pricing_model'          => [
				'type'        => 'string',
				'enum'        => \Learnomy\Models\Course::PRICING_MODELS,
				'description' => __( 'Canonical pricing model (PR-A1). Replaces access_type for new courses; the controller mirrors the legacy column on save.', 'learnomy' ),
			],
			'access_duration_days'   => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Days of access for fixed-term courses. Ignored for other pricing models.', 'learnomy' ),
			],
			'recurring_amount'       => [
				'type'        => 'number',
				'minimum'     => 0,
				'description' => __( 'Per-period charge for recurring courses.', 'learnomy' ),
			],
			'recurring_interval'     => [
				'type'        => 'string',
				'enum'        => [ 'month', 'year' ],
				'description' => __( 'Billing interval for recurring courses.', 'learnomy' ),
			],
			'prerequisites'          => [
				'type'        => 'array',
				'items'       => [ 'type' => 'integer' ],
				'description' => __( 'Course IDs the student must complete first.', 'learnomy' ),
			],
			'instructors'            => [
				'type'        => 'array',
				'description' => __( 'Co-instructors as { user_id, share_pct } objects. The course author is always preserved as primary even if omitted.', 'learnomy' ),
			],
			'primary_instructor_id'  => [
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => __( 'Admin-only: user ID to set as the course author / primary instructor (e.g. content created on behalf of a presenter).', 'learnomy' ),
			],
		];

		$args = apply_filters( 'learnomy_course_rest_args', $args );

		return $args;
	}
}
