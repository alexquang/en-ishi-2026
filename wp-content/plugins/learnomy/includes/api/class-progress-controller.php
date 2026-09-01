<?php
/**
 * Progress REST controller — batch sync for offline-first clients.
 *
 * Mobile and PWA clients accumulate progress events while offline, then call
 * POST /progress/sync when connectivity resumes. Server-side conflict resolution
 * is timestamp-based: the latest client_timestamp for a given item wins.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\CompletionRulesNotMet;
use Learnomy\LessonNotReachable;
use Learnomy\Models\Progress;
use Learnomy\Models\Lesson;
use Learnomy\Models\Enrollment;
use Learnomy\Services\Lesson_Service;
use Learnomy\Services\Progress_Service;
use function Learnomy\now;

class Progress_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'progress';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sync',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'sync' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					// Not `required`: WP validates required args before the
					// permission_callback, so an anon POST would 400 (leaking the
					// schema) instead of 401. sync() checks presence post-auth.
					'events' => [
						'type'     => 'array',
						'maxItems' => 1000,
						'items'    => [
							'type'       => 'object',
							'properties' => [
								'item_type'        => [
									'type' => 'string',
									'enum' => [ 'lesson', 'topic' ],
								],
								'item_id'          => [ 'type' => 'integer' ],
								'status'           => [
									'type' => 'string',
									'enum' => [ 'completed', 'in_progress' ],
								],
								'client_timestamp' => [
									'type'      => 'string',
									'maxLength' => 64,
								],
							],
						],
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	public function sync( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$rate_check = $this->check_rate_limit( $user_id, 'progress_sync' );
		if ( is_wp_error( $rate_check ) ) {
			return $this->error_response( $rate_check );
		}

		$events = $request->get_param( 'events' );
		if ( ! is_array( $events ) || empty( $events ) ) {
			return $this->error_response(
				$this->validation_error( __( 'events must be a non-empty array.', 'learnomy' ) )
			);
		}

		$service       = new Progress_Service();
		$processed     = [];
		$skipped       = [];
		$server_states = [];

		// Group events per item so we only process the latest one per item.
		$latest = $this->reduce_to_latest_per_item( $events );

		foreach ( $latest as $key => $event ) {
			$item_type = sanitize_text_field( wp_unslash( $event['item_type'] ?? '' ) );
			$item_id   = absint( $event['item_id'] ?? 0 );
			$status    = sanitize_text_field( wp_unslash( $event['status'] ?? '' ) );

			if ( ! in_array( $item_type, [ 'lesson', 'topic' ], true ) || ! $item_id ) {
				$skipped[] = [
					'key'    => $key,
					'reason' => 'invalid_item',
				];
				continue;
			}

			if ( ! in_array( $status, [ 'completed', 'in_progress' ], true ) ) {
				$skipped[] = [
					'key'    => $key,
					'reason' => 'invalid_status',
				];
				continue;
			}

			// Enrollment gate — progress writes must come from a real
			// student. Without this guard an authenticated visitor could
			// POST `{item_type:'lesson', item_id:N, status:'completed'}`
			// for a paid course they never bought, firing
			// `learnomy_lesson_completed` → `learnomy_course_completed` →
			// the auto-issue-certificate listener. That's a free-cert /
			// commission-trigger abuse path.
			//
			// 'topic' items are sub-lessons (lessons with parent_id set);
			// their `course_id` denormalized column resolves the parent
			// course either way.
			$gate_lesson = ( 'lesson' === $item_type || 'topic' === $item_type ) ? Lesson::find( $item_id ) : null;
			if ( ! $gate_lesson || ! Enrollment::is_enrolled( $user_id, (int) $gate_lesson->course_id ) ) {
				$skipped[] = array(
					'key'    => $key,
					'reason' => 'not_enrolled',
				);
				continue;
			}

			// Conflict resolution: compare timestamps. If a newer server record
			// already exists, discard the stale client event.
			$existing  = Progress::get_for_user_and_item( $user_id, $item_type, $item_id );
			$client_ts = $this->parse_timestamp( $event['client_timestamp'] ?? '' );

			if ( $existing && $existing->updated_at ) {
				$server_ts = strtotime( $existing->updated_at );
				if ( $server_ts && $client_ts && $server_ts > $client_ts ) {
					$skipped[]       = [
						'key'               => $key,
						'reason'            => 'stale',
						'server_updated_at' => $existing->updated_at,
					];
					$server_states[] = (array) $existing;
					continue;
				}
			}

			// Reachability gate for TOPICS. Lesson items get this inside
			// mark_lesson_complete() below, but a topic completion is written
			// straight to the progress row, so it needs the check here or a
			// drip/sequence-locked topic completes from an offline batch. Not
			// routed through mark_lesson_complete(): that would change how topics
			// land in calculate_course_progress(), which is a separate question
			// from this gate and needs its own verification.
			if ( 'completed' === $status && 'topic' === $item_type
				&& ! Lesson_Service::is_reachable_by( $gate_lesson, $user_id ) ) {
				$skipped[] = array(
					'key'    => $key,
					'reason' => 'locked',
				);
				continue;
			}

			// Every per-event failure is a SKIP, never an exception out of the
			// loop. mark_lesson_complete() throws for two ordinary conditions
			// (locked lesson, unmet rules) and this route used to catch neither:
			// one gated lesson anywhere in a batch returned HTTP 500 with no
			// body, so the client could not tell what had landed, every event
			// after it never ran, and retrying the identical batch threw at the
			// same event forever. For an offline client that is unbounded,
			// silent progress loss — verified with a 4-event batch where events
			// 1-2 wrote, 3 threw and 4 vanished. A batch endpoint must always
			// report per-event outcomes.
			try {
				if ( 'completed' === $status && 'lesson' === $item_type ) {
					$service->mark_lesson_complete( $user_id, $item_id );
				} else {
					Progress::create_or_update(
						$user_id,
						$item_type,
						$item_id,
						[
							'status' => $status,
						]
					);
				}
			} catch ( LessonNotReachable $e ) {
				$skipped[] = array(
					'key'    => $key,
					'reason' => 'locked',
					'detail' => $e->get_reason(),
				);
				continue;
			} catch ( CompletionRulesNotMet $e ) {
				// The client keeps the event and retries once the student has
				// satisfied the rule, so `requirements` rides along to tell it
				// which one is outstanding.
				$skipped[] = array(
					'key'          => $key,
					'reason'       => 'completion_rules_not_met',
					'requirements' => $e->get_status()['requirements'] ?? array(),
				);
				continue;
			}

			$row             = Progress::get_for_user_and_item( $user_id, $item_type, $item_id );
			$processed[]     = $key;
			$server_states[] = $row ? (array) $row : [
				'user_id'   => $user_id,
				'item_type' => $item_type,
				'item_id'   => $item_id,
				'status'    => $status,
			];
		}

		$response = $this->single_response(
			[
				'processed'     => $processed,
				'skipped'       => $skipped,
				'server_states' => $server_states,
				'synced_at'     => now(),
			]
		);

		$response->header( 'X-Last-Sync', gmdate( 'D, d M Y H:i:s' ) . ' GMT' );

		return $response;
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Reduces a flat list of events to one per (item_type, item_id) pair,
	 * keeping only the entry with the latest client_timestamp.
	 */
	private function reduce_to_latest_per_item( array $events ): array {
		$buckets = [];

		foreach ( $events as $event ) {
			$item_type = sanitize_text_field( wp_unslash( $event['item_type'] ?? '' ) );
			$item_id   = absint( $event['item_id'] ?? 0 );

			if ( ! $item_type || ! $item_id ) {
				continue;
			}

			$key = $item_type . ':' . $item_id;
			$ts  = $this->parse_timestamp( $event['client_timestamp'] ?? '' );

			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = $event;
			} else {
				$existing_ts = $this->parse_timestamp( $buckets[ $key ]['client_timestamp'] ?? '' );
				if ( $ts && ( ! $existing_ts || $ts > $existing_ts ) ) {
					$buckets[ $key ] = $event;
				}
			}
		}

		return $buckets;
	}

	/**
	 * Parses an ISO 8601 or MySQL datetime string into a Unix timestamp.
	 * Returns null when the string is empty or unparseable.
	 */
	private function parse_timestamp( string $value ): ?int {
		if ( '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return ( false !== $ts && $ts > 0 ) ? $ts : null;
	}
}
