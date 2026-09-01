<?php
/**
 * Webhooks controller — admin management of outbound endpoints and incoming enrollment trigger.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use Learnomy\Models\Webhook;
use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Transaction;
use Learnomy\Services\Webhook_Service;
use Learnomy\Services\Enrollment_Service;
use Learnomy\Services\Provisioning_Service;
use Learnomy\Security\Webhook_URL_Validator;

class Webhooks_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'webhooks';
	}

	public function register_routes(): void {
		// Admin webhook collection.
		register_rest_route(
			$this->namespace,
			'/admin/webhooks',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list_webhooks' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_webhook' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => [
						'url'    => [
							'required'          => true,
							'type'              => 'string',
							'format'            => 'uri',
							'sanitize_callback' => 'esc_url_raw',
							'validate_callback' => [ Webhook_URL_Validator::class, 'validate_format' ],
							'description'       => __( 'HTTPS endpoint that will receive event payloads.', 'learnomy' ),
						],
						'events' => [
							'required'    => true,
							'type'        => 'array',
							'items'       => [ 'type' => 'string' ],
							'description' => __( 'List of event names to subscribe to.', 'learnomy' ),
						],
						'secret' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'description'       => __( 'Signing secret used to verify payload authenticity.', 'learnomy' ),
						],
					],
				],
			]
		);

		// Admin webhook by ID.
		register_rest_route(
			$this->namespace,
			'/admin/webhooks/(?P<id>\d+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_webhook' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => [
						'id'     => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
						'url'    => [
							'type'              => 'string',
							'format'            => 'uri',
							'sanitize_callback' => 'esc_url_raw',
							'validate_callback' => [ Webhook_URL_Validator::class, 'validate_format' ],
						],
						'events' => [
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
						'secret' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'status' => [
							'type' => 'string',
							'enum' => [ 'active', 'inactive' ],
						],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_webhook' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => [
						'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					],
				],
			]
		);

		// POST /admin/webhooks/{id}/test — fire a test ping.
		register_rest_route(
			$this->namespace,
			'/admin/webhooks/(?P<id>\d+)/test',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'test_webhook' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
				],
			]
		);

		// POST /admin/webhooks/incoming/regenerate-secret — rotate the incoming signing secret.
		register_rest_route(
			$this->namespace,
			'/admin/webhooks/incoming/regenerate-secret',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'regenerate_incoming_secret' ],
				'permission_callback' => [ $this, 'is_admin' ],
			]
		);

		// POST /admin/webhooks/incoming/test — fire a real signed test enrollment.
		register_rest_route(
			$this->namespace,
			'/admin/webhooks/incoming/test',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send_test_enrollment' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'course_id'  => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'user_email' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					],
				],
			]
		);

		// POST /webhooks/enrollment — incoming trigger from a third-party LMS or payment system.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/enrollment',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'incoming_enrollment' ],
				'permission_callback' => '__return_true', // Mandatory HMAC signature, see handler.
				'args'                => [
					'user_email'   => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					],
					'course_id'    => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'action'       => [
						'type'    => 'string',
						'enum'    => [ 'enroll', 'revoke' ],
						'default' => 'enroll',
					],
					'external_ref' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'The external order / reference id (used for idempotency + reconciliation).', 'learnomy' ),
					],
					'amount'       => [
						'type'    => 'number',
						'default' => 0,
					],
					'currency'     => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'full_name'    => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'provider'     => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Name of the external system that sold the course (for the transaction record).', 'learnomy' ),
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function is_admin(): bool|\WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'manage_settings' is admin-only; Permission_Engine short-circuits on
		// manage_options so WP admins always pass through automatically.
		if ( ! $this->check_permission( 'manage_settings' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Route handlers
	// -------------------------------------------------------------------------

	public function list_webhooks( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->get_pagination( $request );
		$limit      = (int) $pagination['limit'];
		$offset     = (int) $pagination['offset'];

		// DB-level pagination — the table grows fast on sites with many
		// outbound integrations, and array_slice loaded everything each call.
		$total    = Webhook::count();
		$webhooks = Webhook::where( [], 'created_at', 'DESC', $limit, $offset );

		return $this->paginated_response(
			array_map( [ $this, 'format_webhook' ], $webhooks ),
			$total,
			$offset
		);
	}

	public function create_webhook( WP_REST_Request $request ): WP_REST_Response {
		$url    = $request->get_param( 'url' );
		$events = $request->get_param( 'events' );
		$secret = $request->get_param( 'secret' );

		// Defense in depth — the validate_callback already rejected internal /
		// metadata hosts, but never store a URL that fails the guard.
		$valid = Webhook_URL_Validator::validate( $url );
		if ( is_wp_error( $valid ) ) {
			return $this->error_response( $valid );
		}

		// Enforce the active-webhook cap. The limit is filterable via
		// learnomy_max_webhooks so Pro raises or removes it (return 0 for
		// unlimited) instead of Free duplicating the gate — Pro extends Free.
		if ( ! Webhook::can_create() ) {
			$max = Webhook::max_allowed();

			return $this->error_response(
				new \WP_Error(
					'learnomy_webhook_limit_reached',
					sprintf(
						/* translators: %d: maximum number of active webhooks allowed. */
						_n(
							'You have reached the limit of %d active webhook. Delete or deactivate one before adding another.',
							'You have reached the limit of %d active webhooks. Delete or deactivate one before adding another.',
							$max,
							'learnomy'
						),
						$max
					),
					[ 'status' => 409 ]
				)
			);
		}

		$events = array_values( array_unique( array_map( 'sanitize_text_field', (array) $events ) ) );

		// Auto-generate a secret if none was provided so the endpoint is always signed.
		if ( empty( $secret ) ) {
			$secret = wp_generate_password( 40, false );
		}

		$webhook_id = Webhook::create(
			[
				'url'         => $url,
				'events_json' => wp_json_encode( $events ),
				'secret'      => $secret,
				'status'      => 'active',
			]
		);

		if ( ! $webhook_id ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_webhook_save_failed', __( 'Could not register the webhook.', 'learnomy' ), [ 'status' => 500 ] )
			);
		}

		$webhook = Webhook::find( $webhook_id );

		return new WP_REST_Response( [ 'data' => $this->format_webhook( $webhook ) ], 201 );
	}

	public function update_webhook( WP_REST_Request $request ): WP_REST_Response {
		$webhook_id = absint( $request->get_param( 'id' ) );
		$webhook    = Webhook::find( $webhook_id );

		if ( ! $webhook ) {
			return $this->error_response( $this->not_found( 'Webhook' ) );
		}

		$fields = [];

		$url = $request->get_param( 'url' );
		if ( null !== $url ) {
			$valid = Webhook_URL_Validator::validate( $url );
			if ( is_wp_error( $valid ) ) {
				return $this->error_response( $valid );
			}
			$fields['url'] = esc_url_raw( $url );
		}

		$events = $request->get_param( 'events' );
		if ( null !== $events && is_array( $events ) ) {
			$fields['events_json'] = wp_json_encode(
				array_values( array_unique( array_map( 'sanitize_text_field', $events ) ) )
			);
		}

		$secret = $request->get_param( 'secret' );
		if ( null !== $secret ) {
			$fields['secret'] = sanitize_text_field( $secret );
		}

		$status = $request->get_param( 'status' );
		if ( null !== $status ) {
			$fields['status'] = sanitize_text_field( $status );
		}

		if ( ! empty( $fields ) ) {
			Webhook::update( $webhook_id, $fields );
		}

		return $this->single_response( $this->format_webhook( Webhook::find( $webhook_id ) ) );
	}

	public function delete_webhook( WP_REST_Request $request ): WP_REST_Response {
		$webhook_id = absint( $request->get_param( 'id' ) );
		$webhook    = Webhook::find( $webhook_id );

		if ( ! $webhook ) {
			return $this->error_response( $this->not_found( 'Webhook' ) );
		}

		// Fires `learnomy_webhook_deleted` from inside delete(), so this route
		// and every other caller announce it identically.
		Webhook::delete( $webhook_id );

		return $this->single_response(
			[
				'deleted' => true,
				'id'      => $webhook_id,
			]
		);
	}

	/**
	 * Send a test payload to one webhook and report what actually happened.
	 *
	 * `delivered` mirrors the real outcome — it is true only when the endpoint
	 * answered 2xx. It used to be hard-coded `true`, so a site owner testing a
	 * dead endpoint was told the delivery succeeded while the row was quietly
	 * marked failed (Basecamp #10143220812). The response carries the HTTP
	 * status, the resulting webhook status, and a plain-English `message` the
	 * admin UI shows verbatim, so the owner knows what to fix.
	 *
	 * The delivery runs in DIAGNOSTIC mode: a failing test reports the HTTP
	 * status but never pauses the endpoint, because pausing it excluded the
	 * webhook from every real-event fan-out (Free's and Pro's alike) — pressing
	 * Test to diagnose an endpoint used to be the thing that broke it
	 * (Basecamp 10132808815). A SUCCESSFUL test still clears a `failed` badge,
	 * which is the documented recovery path shown next to the badge.
	 */
	public function test_webhook( WP_REST_Request $request ): WP_REST_Response {
		$webhook_id = absint( $request->get_param( 'id' ) );
		$webhook    = Webhook::find( $webhook_id );

		if ( ! $webhook ) {
			return $this->error_response( $this->not_found( 'Webhook' ) );
		}

		$service = new Webhook_Service();
		$result  = $service->deliver(
			$webhook_id,
			'learnomy.test',
			[
				'event'     => 'learnomy.test',
				'message'   => 'This is a test delivery from your Learnomy site.',
				'timestamp' => time(),
			],
			true
		);

		// Fetch the fresh row so last_triggered_at and status reflect the attempt.
		$updated = Webhook::find( $webhook_id );

		$status = (string) ( $updated->status ?? '' );

		if ( $result['delivered'] ) {
			/* translators: %d: HTTP status code returned by the endpoint. */
			$message = sprintf( __( 'Test delivered. The endpoint answered HTTP %d.', 'learnomy' ), $result['status_code'] );
		} else {
			// Two complete sentences, translated whole: the reason, then what the
			// failed test did NOT do. Without the second sentence the owner is
			// left guessing whether their live events just stopped — which is
			// exactly what used to happen.
			$aftermath = 'failed' === $status
				? __( 'This endpoint is still paused from an earlier failed delivery. A successful test turns it back on.', 'learnomy' )
				: __( 'The test did not pause this webhook, so real events are still being sent.', 'learnomy' );

			$message = trim( $result['error'] . ' ' . $aftermath );
		}

		return $this->single_response(
			[
				'delivered'        => $result['delivered'],
				'last_status_code' => $result['status_code'],
				'status'           => $status,
				'message'          => $message,
			]
		);
	}

	/**
	 * Rotate the incoming-enrollment signing secret. Returns the new secret ONCE
	 * so the owner can paste it into their external system; existing connections
	 * break until they update their copy (surfaced as a confirm in the UI).
	 */
	public function regenerate_incoming_secret(): WP_REST_Response {
		$secret = wp_generate_password( 40, false );
		update_option( 'learnomy_incoming_webhook_secret', $secret );

		return $this->single_response( [ 'secret' => $secret ] );
	}

	/**
	 * Fire a real, signed test enrollment against a chosen closed course + email
	 * so the owner can confirm the whole chain (signature -> provision -> enroll)
	 * works before wiring their store. Returns the same shape the live endpoint
	 * returns, or the error, so the UI can show a clear pass/fail.
	 */
	public function send_test_enrollment( WP_REST_Request $request ): WP_REST_Response {
		$secret = (string) get_option( 'learnomy_incoming_webhook_secret', '' );
		if ( '' === $secret ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_webhook_no_secret', __( 'Set a signing secret first, then send a test.', 'learnomy' ), [ 'status' => 400 ] )
			);
		}

		$body = [
			'user_email'   => sanitize_email( (string) $request->get_param( 'user_email' ) ),
			'course_id'    => absint( $request->get_param( 'course_id' ) ),
			'action'       => 'enroll',
			'external_ref' => 'test_' . wp_generate_uuid4(),
			'amount'       => 0,
			'currency'     => 'USD',
			'provider'     => 'learnomy-admin-test',
		];

		$signature = ( new Webhook_Service() )->sign_payload( $secret, $body );

		$inner = new WP_REST_Request( 'POST', '/' . $this->namespace . '/' . $this->rest_base . '/enrollment' );
		$inner->set_header( 'Content-Type', 'application/json' );
		$inner->set_header( 'X-Learnomy-Signature', $signature );
		$inner->set_body( wp_json_encode( $body ) );

		$result = rest_do_request( $inner );

		return new WP_REST_Response( [ 'data' => $result->get_data()['data'] ?? $result->get_data() ], $result->get_status() );
	}

	/**
	 * Incoming enrollment trigger for CLOSED (externally-sold) courses.
	 *
	 * A third-party system that sold the course (own checkout, Gumroad, a CRM)
	 * POSTs here to grant or revoke access. The signature is MANDATORY — this
	 * endpoint grants paid access, so an unsigned/unverifiable request is
	 * rejected before anything else. Enrollment records an external transaction
	 * (idempotent on `external_ref`) and enrolls with source=webhook; revoke
	 * pulls the seat. Buyers without an account are provisioned, never 404'd.
	 */
	public function incoming_enrollment( WP_REST_Request $request ): WP_REST_Response {
		// 1) Mandatory HMAC signature (no secret configured => 401, not "open").
		$shared_secret = (string) get_option( 'learnomy_incoming_webhook_secret', '' );
		if ( '' === $shared_secret ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_webhook_not_configured', __( 'Incoming enrollment is not configured. Set a signing secret on the Webhooks screen first.', 'learnomy' ), [ 'status' => 401 ] )
			);
		}

		$signature = (string) $request->get_header( 'X-Learnomy-Signature' );
		if ( '' === $signature ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_missing_signature', __( 'Request signature is required.', 'learnomy' ), [ 'status' => 401 ] )
			);
		}

		$service  = new Webhook_Service();
		$expected = $service->sign_payload( $shared_secret, (array) $request->get_json_params() );
		if ( ! hash_equals( $expected, $signature ) ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_invalid_signature', __( 'Request signature does not match.', 'learnomy' ), [ 'status' => 403 ] )
			);
		}

		// 2) Let an adapter normalise a third-party payload shape into our params
		// (Gumroad/Teachable/Podia -> {user_email, course_id, external_ref, action}).
		$payload = apply_filters(
			'learnomy_incoming_webhook_payload',
			[
				'user_email'   => sanitize_email( (string) $request->get_param( 'user_email' ) ),
				'course_id'    => absint( $request->get_param( 'course_id' ) ),
				'action'       => (string) $request->get_param( 'action' ),
				'external_ref' => sanitize_text_field( (string) $request->get_param( 'external_ref' ) ),
				'amount'       => (float) $request->get_param( 'amount' ),
				'currency'     => strtoupper( (string) ( $request->get_param( 'currency' ) ?: 'USD' ) ),
				'full_name'    => sanitize_text_field( (string) $request->get_param( 'full_name' ) ),
				'provider'     => sanitize_text_field( (string) ( $request->get_param( 'provider' ) ?: 'external' ) ),
			],
			$request
		);

		$email     = sanitize_email( (string) ( $payload['user_email'] ?? '' ) );
		$course_id = absint( $payload['course_id'] ?? 0 );
		$action    = 'revoke' === ( $payload['action'] ?? 'enroll' ) ? 'revoke' : 'enroll';

		if ( ! is_email( $email ) || ! $course_id ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_webhook_bad_request', __( 'A valid user_email and course_id are required.', 'learnomy' ), [ 'status' => 400 ] )
			);
		}

		// 3) The webhook only manages CLOSED courses — priced/free go through the
		// normal on-site flows.
		$course = Course::find( $course_id );
		if ( ! $course ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}
		if ( ! Course::is_closed( $course ) ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_webhook_not_closed', __( 'This endpoint only manages courses set to external (closed) access.', 'learnomy' ), [ 'status' => 409 ] )
			);
		}

		return 'revoke' === $action
			? $this->webhook_revoke( $email, $course_id, $request )
			: $this->webhook_enroll( $email, $course_id, $payload, $request );
	}

	/**
	 * Grant access for a closed course: provision the buyer if needed, record an
	 * external transaction (idempotent on external_ref), enroll with source=webhook.
	 *
	 * @param string              $email Buyer email from the payload.
	 * @param int                 $course_id Course to grant.
	 * @param array<string,mixed> $payload Normalised webhook payload.
	 */
	private function webhook_enroll( string $email, int $course_id, array $payload, WP_REST_Request $request ): WP_REST_Response {
		$existing_user = get_user_by( 'email', $email );

		// Resolve OR create the buyer — never 404 a real customer.
		$user_id = ( new Provisioning_Service() )->ensure_user_by_email(
			$email,
			[
				'full_name' => (string) ( $payload['full_name'] ?? '' ),
				'source'    => 'closed_webhook',
			]
		);
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}
		$user_id      = (int) $user_id;
		$external_ref = (string) ( $payload['external_ref'] ?? '' );

		// Idempotency: a re-sent external_ref must not double-enroll or double-charge.
		if ( '' !== $external_ref && Transaction::find_by_provider_id( $external_ref ) ) {
			$enrollment = Enrollment::find_for_user_course( $user_id, $course_id );

			return $this->single_response(
				[
					'accepted'      => true,
					'idempotent'    => true,
					'user_id'       => $user_id,
					'user_created'  => ! $existing_user,
					'course_id'     => $course_id,
					'enrollment_id' => $enrollment ? (int) $enrollment->id : null,
				]
			);
		}

		// Record the external order so the webhook enroll-gate passes AND the
		// owner gets an auditable receipt for support / refund reconciliation.
		Transaction::record(
			[
				'user_id'                 => $user_id,
				'course_id'               => $course_id,
				'amount'                  => (float) ( $payload['amount'] ?? 0 ),
				'currency'                => (string) ( $payload['currency'] ?? 'USD' ),
				'status'                  => 'completed',
				'transaction_type'        => 'course',
				'payment_provider'        => (string) ( $payload['provider'] ?? 'external' ),
				'provider_transaction_id' => '' !== $external_ref ? $external_ref : 'external_' . wp_generate_uuid4(),
			]
		);

		$enrollment_id = ( new Enrollment_Service() )->enroll( $user_id, $course_id, 'webhook' );
		if ( is_wp_error( $enrollment_id ) ) {
			return $this->error_response( $enrollment_id );
		}

		// Post-enroll notify hook for add-ons / xAPI — the enroll itself is done.
		do_action( 'learnomy_external_enrollment_requested', $user_id, $course_id, $request );

		return $this->single_response(
			[
				'accepted'      => true,
				'user_id'       => $user_id,
				'user_created'  => ! $existing_user,
				'course_id'     => $course_id,
				'enrollment_id' => (int) $enrollment_id,
			]
		);
	}

	/**
	 * Revoke access for a closed course (external refund / cancellation).
	 * Idempotent: revoking an unknown user or an already-revoked seat is a 200.
	 */
	private function webhook_revoke( string $email, int $course_id, WP_REST_Request $request ): WP_REST_Response {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return $this->single_response(
				[
					'accepted'  => true,
					'revoked'   => false,
					'course_id' => $course_id,
				]
			);
		}

		$enrollment = Enrollment::find_for_user_course( (int) $user->ID, $course_id );
		if ( ! $enrollment ) {
			return $this->single_response(
				[
					'accepted'  => true,
					'revoked'   => false,
					'user_id'   => (int) $user->ID,
					'course_id' => $course_id,
				]
			);
		}

		( new Enrollment_Service() )->unenroll( (int) $enrollment->id );

		return $this->single_response(
			[
				'accepted'  => true,
				'revoked'   => true,
				'user_id'   => (int) $user->ID,
				'course_id' => $course_id,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Formatter
	// -------------------------------------------------------------------------

	private function format_webhook( object $wh ): array {
		$events = [];
		if ( ! empty( $wh->events_json ) ) {
			$decoded = json_decode( $wh->events_json, true );
			$events  = is_array( $decoded ) ? $decoded : [];
		}

		return [
			'id'                => (int) $wh->id,
			'url'               => $wh->url,
			'events'            => $events,
			'status'            => $wh->status,
			// Secret is write-only — never expose it in responses.
			'last_triggered_at' => $wh->last_triggered_at ?? null,
			'last_status_code'  => isset( $wh->last_status_code ) ? (int) $wh->last_status_code : null,
			'created_at'        => $wh->created_at,
		];
	}
}
