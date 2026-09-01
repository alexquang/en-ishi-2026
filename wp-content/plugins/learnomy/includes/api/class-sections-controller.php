<?php
/**
 * Sections REST controller — ordered chapter management within a course.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\Models\Course;
use Learnomy\Models\Lesson;
use Learnomy\Models\Section;
use function Learnomy\now;

class Sections_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'sections';
	}

	public function register_routes(): void {
		// Flat list — `/sections?course_id=X`. Used by the lesson + quiz
		// admin dependent dropdowns. Delegates to the same model query as
		// the nested route so behaviour stays consistent.
		//
		// `course_id` is intentionally NOT marked `required` at the schema
		// level. WP REST validates required params BEFORE the
		// permission_callback fires, so a required schema would return
		// 400 "missing parameter" for anonymous callers — masking the
		// real 401 auth gate (smoke 2026-05-09 finding
		// C.security.public-sections-400-not-401). The handler validates
		// course_id manually below, AFTER permission_callback has run.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_sections' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => [
						'course_id' => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'description'       => __( 'Course ID to filter sections by. Required (validated post-auth).', 'learnomy' ),
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/courses/(?P<course_id>[\d]+)/sections',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_sections' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => [
						'course_id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_section' ],
					'permission_callback' => [ $this, 'can_write_for_course' ],
					'args'                => array_merge(
						[
							'course_id' => [
								'type'     => 'integer',
								'required' => true,
							],
						],
						$this->section_args()
					),
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/courses/(?P<course_id>[\d]+)/sections/reorder',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reorder_sections' ],
				'permission_callback' => [ $this, 'can_write_for_course' ],
				'args'                => [
					'course_id' => [
						'type'     => 'integer',
						'required' => true,
					],
					'order'     => [
						'type'     => 'array',
						'required' => true,
						'items'    => [ 'type' => 'integer' ],
					],
				],
			]
		);

		// Batch cross-section lesson reorder — drives the HTML5 drag-drop in the
		// curriculum tree. A single lesson may move both within its section
		// (sort_order only) or across sections (section_id + sort_order). The
		// existing `/sections/:section_id/lessons/reorder` route still works
		// for same-section moves; this endpoint accepts a flat array of
		// {lesson_id, section_id, sort_order} so the client can dispatch one
		// request per drop even when it crosses a section boundary.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reorder-lessons',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reorder_lessons_batch' ],
				'permission_callback' => [ $this, 'can_write_for_lessons_batch' ],
				'args'                => [
					'assignments' => [
						'type'     => 'array',
						'required' => true,
						'items'    => [
							'type'       => 'object',
							'properties' => [
								'lesson_id'  => [ 'type' => 'integer' ],
								'section_id' => [ 'type' => 'integer' ],
								'sort_order' => [ 'type' => 'integer' ],
							],
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_section' ],
					'permission_callback' => [ $this, 'can_write_for_section' ],
					'args'                => array_merge(
						[
							'id' => [
								'type'     => 'integer',
								'required' => true,
							],
						],
						$this->section_args( false )
					),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_section' ],
					'permission_callback' => [ $this, 'can_write_for_section' ],
					'args'                => [
						'id' => [
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

	public function list_sections( WP_REST_Request $request ): WP_REST_Response {
		// course_id is validated here (post-auth) so anonymous callers hit
		// the 401 from permission_callback first, not a 400 from missing
		// param. See the route registration comment above.
		$course_id = (int) $request->get_param( 'course_id' );
		if ( $course_id <= 0 ) {
			return $this->error_response(
				$this->validation_error( __( 'course_id is required.', 'learnomy' ) )
			);
		}

		// Thin wrapper — reuses the nested-route callback so access rules,
		// draft-course gating, and the paginated envelope all match.
		return $this->get_sections( $request );
	}

	public function get_sections( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'course_id' );
		$course    = Course::find( $course_id );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		// Non-published courses are only visible to the instructor.
		if ( 'published' !== $course->status ) {
			$user_id = get_current_user_id();
			if ( ! $user_id || ! $this->check_permission( 'manage_course', $course_id ) ) {
				return $this->error_response( $this->not_found( 'Course' ) );
			}
		}

		$sections = Section::get_for_course( $course_id );

		// Honour limit/page so meta.total/has_more are accurate (was every row
		// with total = count(returned)). Sections per course are a bounded set,
		// so slicing the in-memory result is correct and cheap. With no per_page
		// param this returns the full list with a real total (unchanged shape).
		$total    = count( $sections );
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 ) {
			$per_page = (int) $request->get_param( 'limit' );
		}
		$per_page = $per_page > 0 ? min( 200, $per_page ) : $total;
		$offset   = $this->resolve_offset( $request, (int) $per_page );
		$page_set = $per_page > 0 ? array_slice( $sections, $offset, $per_page ) : $sections;

		return $this->paginated_response( $page_set, $total, $offset );
	}

	public function create_section( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'course_id' );
		$course    = Course::find( $course_id );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$title = sanitize_text_field( wp_unslash( (string) $request->get_param( 'title' ) ) );
		if ( '' === $title ) {
			return $this->error_response(
				$this->validation_error( __( 'Section title is required.', 'learnomy' ) )
			);
		}

		$sort_order  = (int) ( $request->get_param( 'sort_order' ) ?? 0 );
		$description = $request->get_param( 'description' );

		$insert = [
			'course_id'  => $course_id,
			'title'      => $title,
			'sort_order' => $sort_order,
		];

		if ( null !== $description ) {
			$insert['description'] = sanitize_text_field( wp_unslash( $description ) );
		}

		$section_id = Section::create( $insert );
		if ( ! $section_id ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not create section. Please try again.', 'learnomy' ) )
			);
		}

		$response = $this->single_response( Section::to_typed_array( Section::find( $section_id ) ) );
		$response->set_status( 201 );

		do_action( 'learnomy_section_created', $section_id, $course_id );

		return $response;
	}

	public function update_section( WP_REST_Request $request ): WP_REST_Response {
		$section = Section::find( (int) $request->get_param( 'id' ) );
		if ( ! $section ) {
			return $this->error_response( $this->not_found( 'Section' ) );
		}

		$data = [];

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$data['title'] = sanitize_text_field( wp_unslash( $title ) );
		}

		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			$data['description'] = sanitize_text_field( wp_unslash( $description ) );
		}

		$sort_order = $request->get_param( 'sort_order' );
		if ( null !== $sort_order ) {
			$data['sort_order'] = absint( $sort_order );
		}

		// Section-level drip schedule (Plan 11). Empty string clears the
		// schedule (column becomes NULL); any other value must parse as a
		// datetime in the site timezone, which we convert to UTC for storage
		// so the gate can compare against the UTC clock without drift.
		if ( $request->has_param( 'available_date' ) ) {
			$raw = $request->get_param( 'available_date' );
			if ( null === $raw || '' === $raw ) {
				$data['available_date'] = null;
			} else {
				$local = date_create( (string) $raw, wp_timezone() );
				if ( false === $local ) {
					return $this->error_response(
						$this->validation_error( __( 'Invalid available_date format. Use YYYY-MM-DDTHH:MM in the site timezone.', 'learnomy' ) )
					);
				}
				$local->setTimezone( new \DateTimeZone( 'UTC' ) );
				$data['available_date'] = $local->format( 'Y-m-d H:i:s' );
			}
		}

		if ( empty( $data ) ) {
			return $this->error_response(
				$this->validation_error( __( 'No valid fields provided for update.', 'learnomy' ) )
			);
		}

		Section::update( (int) $section->id, $data );

		do_action( 'learnomy_section_updated', (int) $section->id, $data );

		return $this->single_response( Section::to_typed_array( Section::find( (int) $section->id ) ) );
	}

	public function delete_section( WP_REST_Request $request ): WP_REST_Response {
		$section = Section::find( (int) $request->get_param( 'id' ) );
		if ( ! $section ) {
			return $this->error_response( $this->not_found( 'Section' ) );
		}

		$course_id = (int) $section->course_id;

		// The ONE section-removal entry point: cascades everything the section
		// owns (lessons, their children, and section-attached quizzes — see
		// Section::delete()) and fires `learnomy_section_deleted` itself, so
		// this path and the course teardown announce the deletion identically.
		( new \Learnomy\Services\Course_Service() )->delete_section_with_lessons( (int) $section->id, $course_id );

		return $this->single_response(
			[
				'deleted' => true,
				'id'      => (int) $section->id,
			]
		);
	}

	/**
	 * Apply cross-section lesson reassignments in one round-trip.
	 *
	 * Each entry is `{ lesson_id, section_id, sort_order }`. Lessons whose
	 * section_id is unchanged get a plain sort_order update; lessons whose
	 * section_id changed get both columns set. We deliberately don't re-sort
	 * the peers — the client always sends the full adjusted order for the
	 * affected sections so positions stay contiguous.
	 */
	public function reorder_lessons_batch( WP_REST_Request $request ): WP_REST_Response {
		$assignments = $request->get_param( 'assignments' );
		if ( ! is_array( $assignments ) || empty( $assignments ) ) {
			return $this->error_response(
				$this->validation_error( __( 'An array of lesson assignments is required.', 'learnomy' ) )
			);
		}

		$updated_ids = [];
		foreach ( $assignments as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$lesson_id  = isset( $row['lesson_id'] ) ? absint( $row['lesson_id'] ) : 0;
			$section_id = isset( $row['section_id'] ) ? absint( $row['section_id'] ) : 0;
			$sort_order = isset( $row['sort_order'] ) ? (int) $row['sort_order'] : 0;

			if ( ! $lesson_id || ! $section_id ) {
				continue;
			}

			$lesson = Lesson::find( $lesson_id );
			if ( ! $lesson ) {
				continue;
			}

			Lesson::update(
				$lesson_id,
				[
					'section_id' => $section_id,
					'sort_order' => $sort_order,
				]
			);
			$updated_ids[] = $lesson_id;
		}

		do_action( 'learnomy_lessons_reassigned', $updated_ids );

		return $this->single_response(
			[
				'reordered' => true,
				'updated'   => $updated_ids,
			]
		);
	}

	public function reorder_sections( WP_REST_Request $request ): WP_REST_Response {
		$course_id = (int) $request->get_param( 'course_id' );
		$course    = Course::find( $course_id );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$order = $request->get_param( 'order' );
		if ( ! is_array( $order ) || empty( $order ) ) {
			return $this->error_response(
				$this->validation_error( __( 'A non-empty array of section IDs is required.', 'learnomy' ) )
			);
		}

		foreach ( $order as $position => $section_id ) {
			$section_id = absint( $section_id );
			if ( ! $section_id ) {
				continue;
			}
			Section::update( $section_id, [ 'sort_order' => $position ] );
		}

		do_action( 'learnomy_sections_reordered', $course_id, $order );

		$sections = Section::get_for_course( $course_id );

		// Honour limit/page so meta.total/has_more are accurate (was every row
		// with total = count(returned)). Sections per course are a bounded set,
		// so slicing the in-memory result is correct and cheap. With no per_page
		// param this returns the full list with a real total (unchanged shape).
		$total    = count( $sections );
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 ) {
			$per_page = (int) $request->get_param( 'limit' );
		}
		$per_page = $per_page > 0 ? min( 200, $per_page ) : $total;
		$offset   = $this->resolve_offset( $request, (int) $per_page );
		$page_set = $per_page > 0 ? array_slice( $sections, $offset, $per_page ) : $sections;

		return $this->paginated_response( $page_set, $total, $offset );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function can_write_for_course( WP_REST_Request $request ): bool|WP_Error {
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

	/**
	 * Batch reorder permission — BOTH ends of every move must belong to a course
	 * the caller can manage. We evaluate the most restrictive target because
	 * a single bad row shouldn't be silently dropped; the client wouldn't
	 * know why the drop was rejected.
	 *
	 * Checking only the destination section was a content-theft hole: the
	 * handler moves whatever `lesson_id` it is handed, so an instructor could
	 * name a lesson out of someone else's course and a section of their own and
	 * walk it across the tenant boundary (Basecamp 10133286758). The lesson the
	 * request reaches for is as much a parent-owned resource as the section it
	 * lands in.
	 */
	public function can_write_for_lessons_batch( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$assignments = $request->get_param( 'assignments' );
		if ( ! is_array( $assignments ) ) {
			return $this->permission_error();
		}

		$sections = [];
		$lessons  = [];
		foreach ( $assignments as $row ) {
			if ( ! empty( $row['section_id'] ) ) {
				$sections[] = absint( $row['section_id'] );
			}
			if ( ! empty( $row['lesson_id'] ) ) {
				$lessons[] = absint( $row['lesson_id'] );
			}
		}

		return $this->require_parent_ownership(
			[
				'section' => array_values( array_unique( $sections ) ),
				'lesson'  => array_values( array_unique( $lessons ) ),
			]
		);
	}

	public function can_write_for_section( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$section_id = (int) $request->get_param( 'id' );
		$section    = Section::find( $section_id );
		if ( ! $section ) {
			return $this->permission_error();
		}

		if ( ! $this->check_permission( 'manage_course', (int) $section->course_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function section_args( bool $title_required = true ): array {
		return [
			'title'          => [
				'type'              => 'string',
				'required'          => $title_required,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'description'    => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'sort_order'     => [
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			],
			'available_date' => [
				'type'        => [ 'string', 'null' ],
				'description' => __( 'Section-level drip schedule. Datetime in the site timezone (YYYY-MM-DDTHH:MM). Empty string clears the schedule.', 'learnomy' ),
			],
		];
	}
}
