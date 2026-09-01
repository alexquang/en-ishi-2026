<?php
/**
 * Lesson Attachments REST sub-resource — multi-file resources per lesson.
 *
 * Exposes the `lrn_lesson_attachments` table under `/lessons/:id/attachments`.
 * Enrolled students can GET the list to render a Resources section on the
 * student lesson page; instructors who own the lesson's course can POST to
 * attach a WP media item, DELETE to detach, and POST `/reorder` to persist
 * drag-drop ordering.
 *
 * This controller self-registers on `rest_api_init` — it is not in the
 * `Api` class's static list. The spec keeps controller files owned by a
 * single agent; adding it to the central registry would split ownership.
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
use Learnomy\Models\Lesson_Attachment;

class Lesson_Attachments_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'lessons';
	}

	public function register_routes(): void {
		// List + create under /lessons/:id/attachments.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/attachments',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_attachments' ],
					'permission_callback' => [ $this, 'can_read_for_lesson' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_attachment' ],
					'permission_callback' => [ $this, 'can_write_for_lesson' ],
					'args'                => [
						'id'       => [
							'type'     => 'integer',
							'required' => true,
						],
						'media_id' => [
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						],
						'filename' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		// Delete under /lessons/:id/attachments/:attachment_id.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/attachments/(?P<attachment_id>[\d]+)',
			[
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_attachment' ],
					'permission_callback' => [ $this, 'can_write_for_lesson' ],
					'args'                => [
						'id'            => [
							'type'     => 'integer',
							'required' => true,
						],
						'attachment_id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);

		// Reorder under /lessons/:id/attachments/reorder.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/attachments/reorder',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'reorder_attachments' ],
					'permission_callback' => [ $this, 'can_write_for_lesson' ],
					'args'                => [
						'id'    => [
							'type'     => 'integer',
							'required' => true,
						],
						'order' => [
							'type'     => 'array',
							'required' => true,
							'items'    => [ 'type' => 'integer' ],
						],
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	public function list_attachments( WP_REST_Request $request ): WP_REST_Response {
		$lesson_id = (int) $request->get_param( 'id' );
		$rows      = Lesson::get_attachments( $lesson_id );

		$items = array_map( [ $this, 'shape_row' ], $rows );

		/**
		 * Fire once per list fetch so later surfaces (student Resources section,
		 * analytics, caching layers) can attach without needing another REST
		 * hop.
		 *
		 * @param int   $lesson_id Lesson id the list belongs to.
		 * @param array $items     Shaped attachment rows.
		 */
		do_action( 'learnomy_lesson_resources', $lesson_id, $items );

		return $this->paginated_response( $items, count( $items ) );
	}

	public function create_attachment( WP_REST_Request $request ): WP_REST_Response {
		$lesson_id = (int) $request->get_param( 'id' );
		$media_id  = absint( $request->get_param( 'media_id' ) );
		$filename  = $request->get_param( 'filename' );

		if ( $media_id <= 0 ) {
			return $this->error_response(
				$this->validation_error( __( 'media_id is required.', 'learnomy' ) )
			);
		}

		// Verify the media item actually exists — prevents dangling references.
		$media = get_post( $media_id );
		if ( ! $media instanceof \WP_Post || 'attachment' !== $media->post_type ) {
			return $this->error_response( $this->not_found( 'Media' ) );
		}

		$attachment_id = Lesson::attach( $lesson_id, $media_id, is_string( $filename ) ? $filename : null );

		// Attaching moves the file into the private store. When that is refused
		// — the file is also a course thumbnail, or uploads is not writable —
		// say WHY, because the instructor can act on both of those and cannot
		// act on "Could not attach the file."
		if ( is_wp_error( $attachment_id ) ) {
			return $this->error_response(
				$this->validation_error( $attachment_id->get_error_message() )
			);
		}

		if ( ! $attachment_id ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not attach the file. Please try again.', 'learnomy' ) )
			);
		}

		$row      = Lesson_Attachment::find( $attachment_id );
		$response = $this->single_response( $this->shape_row( $row ) );
		$response->set_status( 201 );

		return $response;
	}

	public function delete_attachment( WP_REST_Request $request ): WP_REST_Response {
		$lesson_id     = (int) $request->get_param( 'id' );
		$attachment_id = (int) $request->get_param( 'attachment_id' );

		$row = Lesson_Attachment::find( $attachment_id );
		if ( ! $row || (int) $row->lesson_id !== $lesson_id ) {
			return $this->error_response( $this->not_found( 'Attachment' ) );
		}

		Lesson::detach( $attachment_id );

		return $this->single_response(
			[
				'deleted' => true,
				'id'      => $attachment_id,
			]
		);
	}

	public function reorder_attachments( WP_REST_Request $request ): WP_REST_Response {
		$lesson_id = (int) $request->get_param( 'id' );
		$order     = $request->get_param( 'order' );

		// Accept either a flat id array or the documented [{id, sort_order}] shape.
		$ids = [];
		if ( is_array( $order ) ) {
			foreach ( $order as $entry ) {
				if ( is_int( $entry ) || ctype_digit( (string) $entry ) ) {
					$ids[] = (int) $entry;
					continue;
				}
				if ( is_array( $entry ) && isset( $entry['id'] ) ) {
					$ids[ (int) ( $entry['sort_order'] ?? count( $ids ) ) ] = (int) $entry['id'];
				}
			}
			ksort( $ids );
			$ids = array_values( array_filter( $ids ) );
		}

		if ( empty( $ids ) ) {
			return $this->error_response(
				$this->validation_error( __( 'order must be a non-empty list.', 'learnomy' ) )
			);
		}

		Lesson_Attachment::reorder( $lesson_id, $ids );

		do_action( 'learnomy_lesson_attachments_reordered', $lesson_id, $ids );

		return $this->single_response( [ 'reordered' => true ] );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Whoever may read the lesson body may list its attachments — one gate, so
	 * the handouts can never be reachable on a lesson the reader cannot open.
	 *
	 * This used to hand-roll the rule as `is_preview || access_course ||
	 * manage_course`, which is entitlement WITHOUT the drip schedule or the draft
	 * rule. An enrolled student got the filename, size and public URL of every
	 * handout on a lesson the player had locked, and the file downloaded
	 * (verified 2026-07-30 against a drip-locked lesson). Same root cause as
	 * Basecamp 10143220266 on the body itself; found while fixing it.
	 *
	 * `user_can_read_body()` is a superset of what this replaced: it keeps the
	 * anonymous free-preview path and the course-owner path, and additionally
	 * admits co-instructors — correct, since reading a handout for a course you
	 * teach is not managing it.
	 *
	 * The BYTES obey the same rule, and through the same method: attachment
	 * files now live off the public path in `Protected_Uploads`, and the only
	 * published URL is the `lesson-attachment` route, whose handler calls
	 * `user_can_read_body()` too (Basecamp 10148249510). One gate, two doors.
	 */
	public function can_read_for_lesson( WP_REST_Request $request ): bool|WP_Error {
		$lesson = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $lesson ) {
			return $this->not_found( 'Lesson' );
		}

		if ( \Learnomy\Services\Lesson_Service::user_can_read_body( $lesson, get_current_user_id() ) ) {
			return true;
		}

		return $this->permission_error();
	}

	/**
	 * Only the course manager (instructor / admin) can mutate attachments.
	 */
	public function can_write_for_lesson( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$lesson = Lesson::find( (int) $request->get_param( 'id' ) );
		if ( ! $lesson ) {
			return $this->not_found( 'Lesson' );
		}

		if ( ! $this->check_permission( 'manage_course', (int) $lesson->course_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Project a storage row into the SDK-friendly shape consumed by the admin
	 * uploader + the student Resources section.
	 *
	 * @return array{id:int,lesson_id:int,media_id:int,filename:string,mime_type:string,size_bytes:int,sort_order:int,url:string}
	 */
	private function shape_row( ?object $row ): array {
		if ( ! $row ) {
			return [];
		}
		return [
			'id'         => (int) $row->id,
			'lesson_id'  => (int) $row->lesson_id,
			'media_id'   => (int) $row->media_id,
			'filename'   => (string) ( $row->filename ?? '' ),
			'mime_type'  => (string) ( $row->mime_type ?? '' ),
			'size_bytes' => (int) ( $row->size_bytes ?? 0 ),
			'sort_order' => (int) ( $row->sort_order ?? 0 ),
			'url'        => Lesson_Attachment::get_url( $row ),
		];
	}
}
