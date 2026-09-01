<?php
/**
 * Topics REST controller — sub-lesson units within a lesson.
 *
 * Topics are stored as child rows in the lessons table (parent_id = lesson id),
 * but exposed under their own endpoint for clarity.
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
use Learnomy\Services\Progress_Service;

class Topics_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'topics';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/lessons/(?P<lesson_id>[\d]+)/topics',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_topics' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => [
						'lesson_id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_topic' ],
					'permission_callback' => [ $this, 'can_write_for_lesson' ],
					'args'                => array_merge(
						[
							'lesson_id' => [
								'type'     => 'integer',
								'required' => true,
							],
						],
						$this->topic_args()
					),
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_topic' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_topic' ],
					'permission_callback' => [ $this, 'can_write_for_topic' ],
					'args'                => array_merge(
						[
							'id' => [
								'type'     => 'integer',
								'required' => true,
							],
						],
						$this->topic_args( false )
					),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_topic' ],
					'permission_callback' => [ $this, 'can_write_for_topic' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/complete',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'mark_complete' ],
				'permission_callback' => [ $this, 'is_enrolled_student' ],
				'args'                => [
					'id' => [
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

	public function get_topics( WP_REST_Request $request ): WP_REST_Response {
		$lesson_id = (int) $request->get_param( 'lesson_id' );
		$lesson    = Lesson::find( $lesson_id );
		if ( ! $lesson ) {
			return $this->error_response( $this->not_found( 'Lesson' ) );
		}

		// Access check: preview lessons are open; others need course enrollment.
		if ( ! $lesson->is_preview ) {
			$course = Course::find( (int) $lesson->course_id );
			if ( ! $course || ! $this->can_view_course_content( $course ) ) {
				return $this->error_response( $this->permission_error() );
			}
		}

		$topics = Lesson::get_topics( $lesson_id );

		// Honour limit/page so meta.total/has_more are accurate (was every row
		// with total = count(returned)). Topics per lesson are a bounded set, so
		// slicing the in-memory result is correct; no per_page returns all.
		$total    = count( $topics );
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 ) {
			$per_page = (int) $request->get_param( 'limit' );
		}
		$per_page = $per_page > 0 ? min( 200, $per_page ) : $total;
		$offset   = $this->resolve_offset( $request, (int) $per_page );
		$page_set = $per_page > 0 ? array_slice( $topics, $offset, $per_page ) : $topics;

		return $this->paginated_response( $page_set, $total, $offset );
	}

	public function get_topic( WP_REST_Request $request ): WP_REST_Response {
		$topic = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $topic || ! $topic->parent_id ) {
			return $this->error_response( $this->not_found( 'Topic' ) );
		}

		$parent = Lesson::find( (int) $topic->parent_id );
		if ( $parent && ! $parent->is_preview ) {
			$course = Course::find( (int) $topic->course_id );
			if ( ! $course || ! $this->can_view_course_content( $course ) ) {
				return $this->error_response( $this->permission_error() );
			}
		}

		$etag         = $this->generate_etag( $topic );
		$not_modified = $this->maybe_not_modified( $etag, $request );
		if ( $not_modified ) {
			return $not_modified;
		}

		$filtered = $this->filter_fields( Lesson::to_typed_array( $topic ) ?? [], $request );
		$response = $this->single_response( $filtered );

		return $this->add_etag( $response, $etag );
	}

	public function create_topic( WP_REST_Request $request ): WP_REST_Response {
		$lesson_id = (int) $request->get_param( 'lesson_id' );
		$lesson    = Lesson::find( $lesson_id );
		if ( ! $lesson ) {
			return $this->error_response( $this->not_found( 'Lesson' ) );
		}

		$title = sanitize_text_field( wp_unslash( (string) $request->get_param( 'title' ) ) );
		if ( '' === $title ) {
			return $this->error_response(
				$this->validation_error( __( 'Topic title is required.', 'learnomy' ) )
			);
		}

		$data               = $this->extract_topic_fields( $request );
		$data['parent_id']  = $lesson_id;
		$data['course_id']  = (int) $lesson->course_id;
		$data['section_id'] = (int) $lesson->section_id;

		$topic_id = Lesson::create( $data );
		if ( ! $topic_id ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not create topic. Please try again.', 'learnomy' ) )
			);
		}

		$response = $this->single_response( Lesson::to_typed_array( Lesson::find( $topic_id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_topic( WP_REST_Request $request ): WP_REST_Response {
		$topic = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $topic || ! $topic->parent_id ) {
			return $this->error_response( $this->not_found( 'Topic' ) );
		}

		$data = $this->extract_topic_fields( $request );
		if ( empty( $data ) ) {
			return $this->error_response(
				$this->validation_error( __( 'No valid fields provided for update.', 'learnomy' ) )
			);
		}

		// `content_plain` is derived by Lesson::search_projections() inside the
		// model write below — one derivation for every caller.
		Lesson::update( (int) $topic->id, $data );

		return $this->single_response( Lesson::to_typed_array( Lesson::find( (int) $topic->id ) ) );
	}

	public function delete_topic( WP_REST_Request $request ): WP_REST_Response {
		$topic = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $topic || ! $topic->parent_id ) {
			return $this->error_response( $this->not_found( 'Topic' ) );
		}

		Lesson::delete( (int) $topic->id );

		return $this->single_response(
			[
				'deleted' => true,
				'id'      => (int) $topic->id,
			]
		);
	}

	public function mark_complete( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$topic = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $topic || ! $topic->parent_id ) {
			return $this->error_response( $this->not_found( 'Topic' ) );
		}

		// Topics track progress independently; they don't roll up into the course
		// completion calculation the same way lessons do — that stays in Progress_Service.
		$service = new Progress_Service();
		$service->mark_lesson_complete( $user_id, (int) $topic->id );

		// progress-view.js reads completed_count + total_count to render the
		// "X of Y topics" pill. Topics are siblings under the same parent lesson,
		// so we count completed/total siblings in that scope.
		$counts = $this->compute_topic_counts( $user_id, (int) $topic->parent_id );

		// Auto-advance target, identical to the lesson-complete endpoint so the
		// app gets a consistent next-URL whichever endpoint it completes a unit
		// through. Walks the flattened sequence: next sibling sub-lesson, else
		// the next top-level lesson after the parent.
		$next            = Lesson::find_next_in_sequence( (int) $topic->id, (int) $topic->course_id );
		$next_lesson_url = $next ? Lesson::get_permalink( $next ) : null;

		return $this->single_response(
			[
				'topic_id'        => (int) $topic->id,
				'completed'       => true,
				'completed_count' => $counts['completed'],
				'total_count'     => $counts['total'],
				'next_lesson_url' => $next_lesson_url,
			]
		);
	}

	/**
	 * Returns ['completed' => N, 'total' => M] for topics under one parent lesson.
	 *
	 * @return array{completed:int,total:int}
	 */
	private function compute_topic_counts( int $user_id, int $parent_lesson_id ): array {
		$siblings = Lesson::get_topics( $parent_lesson_id );
		$total    = count( $siblings );
		if ( 0 === $total ) {
			return [
				'completed' => 0,
				'total'     => 0,
			];
		}

		$completed = 0;
		foreach ( $siblings as $sibling ) {
			$row = \Learnomy\Models\Progress::get_for_user_and_item( $user_id, 'lesson', (int) $sibling->id );
			if ( $row && 'completed' === $row->status ) {
				++$completed;
			}
		}

		return [
			'completed' => $completed,
			'total'     => $total,
		];
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function can_write_for_lesson( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$lesson_id = (int) $request->get_param( 'lesson_id' );
		$lesson    = Lesson::find( $lesson_id );
		if ( ! $lesson ) {
			return $this->permission_error();
		}

		if ( ! $this->check_permission( 'manage_course', (int) $lesson->course_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	public function can_write_for_topic( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$topic_id = (int) $request->get_param( 'id' );
		$topic    = Lesson::find( $topic_id );
		if ( ! $topic || ! $topic->parent_id ) {
			return $this->permission_error();
		}

		if ( ! $this->check_permission( 'manage_course', (int) $topic->course_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	public function is_enrolled_student( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$topic_id = (int) $request->get_param( 'id' );
		$topic    = Lesson::find( $topic_id );
		if ( ! $topic || ! $topic->parent_id ) {
			return $this->permission_error();
		}

		if ( ! $this->check_permission( 'access_course', (int) $topic->course_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Gate on ENROLLMENT, not publish status (Basecamp 10104915939) — same fix
	 * as the lessons controller: the `published → true` rule leaked paid content
	 * to non-enrolled users and denied enrolled students of unpublished courses.
	 */
	private function can_view_course_content( object $course ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		if ( \Learnomy\Models\Enrollment::is_enrolled( $user_id, (int) $course->id ) ) {
			return true;
		}
		return $this->check_permission( 'manage_course', (int) $course->id );
	}

	private function extract_topic_fields( WP_REST_Request $request ): array {
		$data = [];

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$data['title'] = sanitize_text_field( wp_unslash( $title ) );
		}

		$content = $request->get_param( 'content' );
		if ( null !== $content ) {
			$data['content'] = wp_kses_post( wp_unslash( $content ) );
		}

		$content_type = $request->get_param( 'content_type' );
		if ( null !== $content_type ) {
			$allowed = [ 'text', 'video', 'audio' ];
			if ( in_array( $content_type, $allowed, true ) ) {
				$data['content_type'] = $content_type;
			}
		}

		$sort_order = $request->get_param( 'sort_order' );
		if ( null !== $sort_order ) {
			$data['sort_order'] = absint( $sort_order );
		}

		$duration = $request->get_param( 'duration_seconds' );
		if ( null !== $duration ) {
			$data['duration_seconds'] = absint( $duration );
		}

		return $data;
	}

	private function topic_args( bool $title_required = true ): array {
		return [
			'title'            => [
				'type'              => 'string',
				'required'          => $title_required,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'content'          => [ 'type' => 'string' ],
			'content_type'     => [
				'type' => 'string',
				'enum' => [ 'text', 'video', 'audio' ],
			],
			'sort_order'       => [
				'type'    => 'integer',
				'minimum' => 0,
				'default' => 0,
			],
			'duration_seconds' => [
				'type'    => 'integer',
				'minimum' => 0,
			],
		];
	}
}
