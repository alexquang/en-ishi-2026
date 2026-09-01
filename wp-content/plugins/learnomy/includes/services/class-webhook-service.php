<?php
/**
 * Outbound webhook dispatch — fan-out, HMAC signing, async delivery.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Webhook;
use Learnomy\Security\Webhook_URL_Validator;

class Webhook_Service {

	/**
	 * Fans out a named event to all active webhooks subscribed to it.
	 * Schedules each delivery asynchronously so the calling request
	 * returns quickly regardless of how many endpoints are registered.
	 */
	public function dispatch( string $event, array $payload ): void {
		$webhooks = Webhook::get_active();
		if ( empty( $webhooks ) ) {
			return;
		}

		foreach ( $webhooks as $webhook ) {
			$events = json_decode( $webhook->events_json ?? '[]', true );
			if ( ! is_array( $events ) || ! in_array( $event, $events, true ) ) {
				continue;
			}

			// Prefer Action Scheduler (WooCommerce/bundled) for reliable async delivery;
			// fall back to a single-fire WP cron event.
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action(
					time(),
					'learnomy_deliver_webhook',
					[ (int) $webhook->id, $event, $payload ],
					'learnomy'
				);
			} else {
				wp_schedule_single_event(
					time(),
					'learnomy_deliver_webhook',
					[ (int) $webhook->id, $event, $payload ]
				);
			}
		}
	}

	/**
	 * Performs the actual HTTP delivery of a single webhook event.
	 * Called by the async action hook and by the admin "Test" button.
	 *
	 * Returns the outcome so callers can report it truthfully instead of
	 * assuming success — the admin Test endpoint used to hard-code
	 * `delivered: true` because this method told it nothing (Basecamp
	 * #10143220812).
	 *
	 * Only an `inactive` webhook is skipped: that status means the operator
	 * paused it. A `failed` webhook is still delivered to, because `failed`
	 * is a health signal, not a pause — and the self-healing reset below is
	 * unreachable if a failed endpoint can never be attempted again. Routine
	 * fan-out already skips failed endpoints upstream, in
	 * {@see Webhook::get_active()}, so this does not resurrect background
	 * traffic to a dead URL.
	 *
	 * `$diagnostic` separates "the product tried to deliver an event" from
	 * "the operator asked whether this endpoint works". A diagnostic may HEAL
	 * (a 2xx clears a `failed` badge) but must never HARM: it never flips a
	 * live webhook to `failed`, because the operator pressed Test to find out
	 * what is wrong, not to take the endpoint out of service. Before this,
	 * one failed Test excluded the webhook from {@see Webhook::get_active()} —
	 * which is the gate on Free's fan-out AND on every entry point of Pro's
	 * retry-capable dispatcher — so a diagnostic silently stopped all real
	 * event delivery (Basecamp 10132808815). The HTTP status and the attempt
	 * time are still recorded either way: those are the diagnosis.
	 *
	 * @param int                  $webhook_id Webhook row id.
	 * @param string               $event      Event name.
	 * @param array<string, mixed> $payload    JSON body to sign and send.
	 * @param bool                 $diagnostic True for an operator-initiated test ping.
	 * @return array{delivered: bool, status_code: int, error: string} Delivery outcome.
	 */
	public function deliver( int $webhook_id, string $event, array $payload, bool $diagnostic = false ): array {
		$webhook = Webhook::find( $webhook_id );
		if ( ! $webhook ) {
			return $this->outcome( false, 0, __( 'The webhook no longer exists.', 'learnomy' ) );
		}

		if ( 'inactive' === $webhook->status ) {
			return $this->outcome( false, 0, __( 'This webhook is paused, so nothing was sent.', 'learnomy' ) );
		}

		// Re-validate the destination at delivery time, after DNS resolution.
		// A URL that was public when saved can resolve to an internal address
		// later (DNS rebinding, or the operator repointed the hostname). Abort
		// before any HTTP call rather than leak an internal response. SSRF guard,
		// Basecamp #9937420156.
		if ( ! Webhook_URL_Validator::is_safe( $webhook->url ) ) {
			Webhook::update( $webhook_id, [ 'last_status_code' => 0 ] );
			return $this->outcome(
				false,
				0,
				__( 'The destination URL is not reachable from a public address, so the delivery was blocked.', 'learnomy' )
			);
		}

		$body      = wp_json_encode( $payload );
		$signature = $this->sign_payload( (string) $webhook->secret, $payload );

		// wp_safe_remote_post (not wp_remote_post) so WordPress's own
		// wp_http_validate_url() is a second floor against loopback / private hosts.
		$response = wp_safe_remote_post(
			$webhook->url,
			[
				'timeout'     => 15,
				'redirection' => 0, // Don't follow redirects — destination should be stable.
				'body'        => $body,
				'headers'     => [
					'Content-Type'         => 'application/json',
					'X-Learnomy-Event'     => $event,
					'X-Learnomy-Signature' => $signature,
					'X-Learnomy-Delivery'  => wp_generate_uuid4(),
				],
			]
		);

		$status_code = is_wp_error( $response )
			? 0
			: (int) wp_remote_retrieve_response_code( $response );

		$transport_error = is_wp_error( $response ) ? $response->get_error_message() : '';

		Webhook::update_last_triggered( $webhook_id );

		// Store the HTTP status so admins can diagnose failing endpoints.
		Webhook::update( $webhook_id, [ 'last_status_code' => $status_code ] );

		// A 2xx response means the endpoint accepted the delivery. Any other
		// outcome (network error, redirect, client/server error) marks the
		// webhook `failed` so the operator sees a warning badge in the list
		// and knows to investigate. A subsequent successful delivery resets
		// the status to `active` so self-healing endpoints clear automatically.
		// A test ping heals but never pauses — see the $diagnostic note above.
		$succeeded = $status_code >= 200 && $status_code < 300;
		if ( $succeeded ) {
			if ( 'active' !== $webhook->status ) {
				Webhook::update( $webhook_id, [ 'status' => 'active' ] );
			}

			return $this->outcome( true, $status_code, '' );
		}

		if ( ! $diagnostic ) {
			Webhook::update( $webhook_id, [ 'status' => 'failed' ] );
		}

		if ( '' !== $transport_error ) {
			/* translators: %s: HTTP transport error reported by WordPress. */
			$message = sprintf( __( 'The endpoint could not be reached: %s', 'learnomy' ), $transport_error );
		} else {
			/* translators: %d: HTTP status code returned by the endpoint. */
			$message = sprintf( __( 'The endpoint answered with HTTP %d instead of a 2xx success.', 'learnomy' ), $status_code );
		}

		return $this->outcome( false, $status_code, $message );
	}

	/**
	 * Shapes a delivery outcome so every return path reports the same keys.
	 *
	 * @param bool   $delivered   Whether the endpoint accepted the payload (2xx).
	 * @param int    $status_code HTTP status, or 0 when no response was received.
	 * @param string $error       Human-readable failure reason, empty on success.
	 * @return array{delivered: bool, status_code: int, error: string}
	 */
	private function outcome( bool $delivered, int $status_code, string $error ): array {
		return [
			'delivered'   => $delivered,
			'status_code' => $status_code,
			'error'       => $error,
		];
	}

	/**
	 * Returns "sha256=<hex>" — same convention used by GitHub and Stripe webhooks
	 * so receiving parties can use familiar verification libraries.
	 */
	public function sign_payload( string $secret, array $payload ): string {
		$body = wp_json_encode( $payload );
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}
}
