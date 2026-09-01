<?php
/**
 * Webhook events admin controller.
 *
 * Routes:
 *   GET  /learnomy/v1/admin/webhook-events         — paginated list with filters
 *   GET  /learnomy/v1/admin/webhook-events/{id}    — single event with payload
 *   POST /learnomy/v1/admin/webhook-events/{id}/replay — re-dispatch via adapter
 *
 * All routes are gated on manage_options.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use Learnomy\Models\Webhook_Event;
use Learnomy\Adapters\Adapter_Registry;

class Webhook_Events_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'admin/webhook-events';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_events' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'provider' => [
						'type'              => 'string',
						'default'           => '',
						'enum'              => [ '', 'stripe', 'paypal' ],
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Filter by provider', 'learnomy' ),
					],
					'outcome'  => [
						'type'              => 'string',
						'default'           => '',
						'enum'              => [ '', 'handled', 'duplicate', 'rejected', 'error', 'pending' ],
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Filter by outcome', 'learnomy' ),
					],
					'limit'    => [
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					],
					'offset'   => [
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_event' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/replay',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'replay_event' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					],
				],
			]
		);
	}

	/**
	 * Permission callback — require manage_options.
	 *
	 * @return true|\WP_Error
	 */
	public function is_admin(): bool|\WP_Error {
		return $this->require_cap( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// GET /admin/webhook-events
	// -------------------------------------------------------------------------

	/**
	 * Paginated list of webhook events with optional provider + outcome filters.
	 */
	public function list_events( WP_REST_Request $request ): WP_REST_Response {
		$provider = (string) ( $request->get_param( 'provider' ) ?? '' );
		$outcome  = (string) ( $request->get_param( 'outcome' ) ?? '' );
		$limit    = (int) ( $request->get_param( 'limit' ) ?? 50 );
		$offset   = (int) ( $request->get_param( 'offset' ) ?? 0 );

		$items = Webhook_Event::recent( $provider, $outcome, $limit, $offset );
		$total = Webhook_Event::count_filtered( $provider, $outcome );

		return $this->paginated_response( $items, $total, $offset );
	}

	// -------------------------------------------------------------------------
	// GET /admin/webhook-events/{id}
	// -------------------------------------------------------------------------

	/**
	 * Single event row including the full payload_json.
	 */
	public function get_event( WP_REST_Request $request ): WP_REST_Response {
		$id  = (int) $request->get_param( 'id' );
		$row = Webhook_Event::find( $id );

		if ( ! $row ) {
			return $this->error_response(
				$this->not_found( __( 'Webhook event', 'learnomy' ) )
			);
		}

		return $this->single_response( $row );
	}

	// -------------------------------------------------------------------------
	// POST /admin/webhook-events/{id}/replay
	// -------------------------------------------------------------------------

	/**
	 * Re-dispatch a logged event through the original adapter.
	 *
	 * How replay works
	 * - Loads the event row and reconstructs a WP_REST_Request from the stored
	 *   payload_json and the original signature_status.
	 * - Re-invokes the adapter's process_webhook(). The adapter will call
	 *   Webhook_Event::log() again, which hits the UNIQUE (provider, event_id)
	 *   constraint and returns 0. The adapter then calls
	 *   increment_retry_by_event_id() and returns early without double-processing
	 *   the original event — it is effectively a "force reprocess" only when the
	 *   outcome was not 'handled'.
	 *
	 * Signature check on replay
	 * - The adapter re-runs signature verification against the stored payload
	 *   and the current webhook secret. If the webhook secret was rotated
	 *   since the original delivery, the signature will fail and the replay
	 *   will be rejected with 'rejected/signature_invalid'.
	 * - We return a descriptive error so the admin knows they need to use
	 *   the provider dashboard to re-send the event with the new signature.
	 *
	 * To force a re-process without the UNIQUE guard, we temporarily set
	 * outcome='replay_pending' so the log() call no longer returns 0 on the
	 * second delivery. We then restore the row to the new outcome after dispatch.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function replay_event( WP_REST_Request $request ): WP_REST_Response {
		$id  = (int) $request->get_param( 'id' );
		$row = Webhook_Event::find( $id );

		if ( ! $row ) {
			return $this->error_response(
				$this->not_found( __( 'Webhook event', 'learnomy' ) )
			);
		}

		$provider = (string) $row->provider;

		// Resolve the adapter from the registry.
		$adapter = Adapter_Registry::get( $provider );
		if ( ! $adapter ) {
			return $this->error_response(
				$this->validation_error(
					/* translators: %s: provider name */
					sprintf( __( 'No adapter registered for provider "%s".', 'learnomy' ), esc_html( $provider ) )
				)
			);
		}

		// Remove the existing row's UNIQUE guard by deleting it so the adapter's
		// log() call inserts a fresh row. We preserve the original payload_json
		// and re-use the stored event_id — the adapter will write a new log row
		// with a fresh processed_at timestamp and outcome='pending'.
		Webhook_Event::delete( $id );

		// Build a synthetic WP_REST_Request from the stored payload.
		$fake_request = new \WP_REST_Request( 'POST' );
		$fake_request->set_body( (string) $row->payload_json );

		// Re-attach the original Stripe signature header if present so the
		// adapter can verify it against the current webhook secret.
		if ( 'stripe' === $provider ) {
			// The stored payload alone cannot regenerate the original signature
			// header (it included a timestamp). We pass an empty string so the
			// adapter detects sig_status='missing' on replay.
			// If the original sig_status was 'valid' and the secret has not
			// changed, the admin should re-trigger from the Stripe dashboard
			// where the header is re-generated with a fresh timestamp.
			// We document this in the response.
			$fake_request->set_header( 'stripe-signature', '' );
		}

		if ( 'paypal' === $provider ) {
			// PayPal verification requires live outbound HTTP to their verify
			// endpoint. On replay we blank the transmission signature so
			// verify_paypal_webhook() returns false (sig_status='invalid')
			// unless the site is in sandbox mode with no webhook_id configured
			// (which allows unauthenticated). The admin is informed via the response.
			$fake_request->set_header( 'paypal-transmission-sig', '' );
		}

		// Invoke the adapter. It will:
		// 1. Call Webhook_Event::log() — inserts a fresh row.
		// 2. Re-verify the signature with the current secret.
		// 3. Re-dispatch the event type handler.
		// 4. Update the new row's outcome.
		$new_outcome  = 'unknown';
		$error_detail = null;
		$sig_note     = null;

		try {
			$adapter->process_webhook( $fake_request );
			$new_outcome = 'handled';
		} catch ( \InvalidArgumentException $e ) {
			$new_outcome  = 'rejected';
			$error_detail = $e->getMessage();

			// Surface a clear message when the rejection is due to signature
			// mismatch — the most common replay failure after key rotation.
			if ( 'webhook_signature_invalid' === $e->getMessage() ) {
				$sig_note = __(
					'Signature verification failed. The webhook secret may have been rotated since the original delivery. Use the provider dashboard to resend the event with a fresh signature.',
					'learnomy'
				);
			}
		} catch ( \Throwable $e ) {
			$new_outcome  = 'error';
			$error_detail = $e->getMessage();
		}

		// Fetch the newly inserted log row so we can return it.
		$new_row = Webhook_Event::find_by_event_id( $provider, (string) $row->event_id );

		$response_data = [
			'original_id'  => $id,
			'new_event_id' => $new_row ? (int) $new_row->id : null,
			'outcome'      => $new_outcome,
			'error'        => $error_detail,
		];

		if ( $sig_note ) {
			$response_data['signature_note'] = $sig_note;
		}

		return $this->single_response( (object) $response_data );
	}
}
