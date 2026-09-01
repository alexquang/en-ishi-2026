<?php
/**
 * PayPal payment gateway adapter.
 *
 * Uses PayPal Orders API v2 and Subscriptions API v2 directly via WordPress
 * HTTP functions. Sandbox vs live is controlled by learnomy_settings['paypal_sandbox'].
 *
 * @package Learnomy
 */

namespace Learnomy\Adapters;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\Subscription;
use Learnomy\Models\Transaction;
use Learnomy\Models\Webhook_Event;
use Learnomy\Services\Coupon_Service;
use Learnomy\Services\Enrollment_Service;
use Learnomy\Services\Subscription_Service;
use Learnomy\Services\Refund_Service;
use Learnomy\Services\Purchase_Recorder;
use function Learnomy\now;

class PayPal_Adapter implements Payment_Adapter {

	const API_LIVE    = 'https://api-m.paypal.com';
	const API_SANDBOX = 'https://api-m.sandbox.paypal.com';

	public function get_id(): string {
		return 'paypal';
	}

	public function get_label(): string {
		return __( 'PayPal', 'learnomy' );
	}

	public function is_available(): bool {
		$settings = get_option( 'learnomy_settings', [] );

		// enable_paypal toggle must be on — unchecked means fully disabled.
		if ( empty( $settings['enable_paypal'] ) ) {
			return false;
		}

		$mode   = $settings['paypal_mode'] ?? 'sandbox';
		$client = $this->client_id( $mode );
		$secret = \Learnomy\Security\Encrypted_Option::get( 'learnomy_paypal_' . $mode . '_secret' );

		// Fallback to legacy keys for sites that haven't re-saved since Phase 1.
		if ( '' === $client ) {
			$client = $settings['paypal_client_id'] ?? '';
		}
		if ( '' === $secret ) {
			$secret = \Learnomy\Security\Encrypted_Option::get( 'learnomy_paypal_secret' );
		}

		return ! empty( $client ) && '' !== $secret;
	}

	/**
	 * Returns the client ID for the given mode. Reads from the new
	 * mode-specific settings key with a legacy fallback.
	 */
	private function client_id( string $mode = '' ): string {
		$settings = get_option( 'learnomy_settings', [] );
		if ( '' === $mode ) {
			$mode = $settings['paypal_mode'] ?? 'sandbox';
		}
		$key = 'sandbox' === $mode ? 'paypal_sandbox_client' : 'paypal_live_client';
		return $settings[ $key ] ?? '';
	}

	/**
	 * Returns the webhook ID for the given mode. Falls back to the legacy
	 * encrypted option for sites configured before Phase 3.
	 */
	/**
	 * The URL the buyer must visit to approve, from PayPal's HATEOAS links.
	 *
	 * PayPal renamed this relation. An order used to come back `status: CREATED`
	 * with `rel: "approve"`; it now comes back `status: PAYER_ACTION_REQUIRED`
	 * with `rel: "payer-action"` pointing at the same /checkoutnow URL. Looking
	 * only for "approve" returned an EMPTY string, and an empty string is not an
	 * error - so an order was created at PayPal, charged nothing, and the buyer
	 * was redirected nowhere. Every PayPal purchase and subscription on the site
	 * died there, silently, which is exactly why this path had never been
	 * verified end to end.
	 *
	 * Both names are accepted rather than swapped, because PayPal still returns
	 * "approve" on some flows and account configurations; taking whichever is
	 * present keeps old and new behaviour working at once.
	 *
	 * @param array|object $links The `links` array from an order or subscription.
	 * @return string Approval URL, or '' when PayPal sent neither relation.
	 */
	private function approval_link( $links ): string {
		foreach ( (array) $links as $link ) {
			$rel = is_object( $link ) ? ( $link->rel ?? '' ) : ( $link['rel'] ?? '' );
			if ( 'approve' === $rel || 'payer-action' === $rel ) {
				return (string) ( is_object( $link ) ? ( $link->href ?? '' ) : ( $link['href'] ?? '' ) );
			}
		}

		return '';
	}

	private function webhook_id( string $mode = '' ): string {
		$settings = get_option( 'learnomy_settings', [] );
		if ( '' === $mode ) {
			$mode = $settings['paypal_mode'] ?? 'sandbox';
		}
		$key = 'sandbox' === $mode ? 'paypal_sandbox_webhook_id' : 'paypal_live_webhook_id';
		$id  = $settings[ $key ] ?? '';
		if ( '' === $id ) {
			// Legacy fallback — encrypted option used in phases 1+2.
			$id = \Learnomy\Security\Encrypted_Option::get( 'learnomy_paypal_webhook_id' );
		}
		return $id;
	}

	/**
	 * Unified entry point — see the Payment_Adapter interface.
	 *
	 * @since 2.3.0
	 */
	public function create_purchase_session( array $args ): array {
		$mode = (string) ( $args['mode'] ?? 'one_time' );

		// Translate purchase_type/item_id → legacy course_id/plan_id; alias
		// extra → extra_metadata and item_label → label. Shared across all
		// adapters via Adapter_Registry::normalize_purchase_args().
		$args = Adapter_Registry::normalize_purchase_args( $args, $this );

		return 'subscription' === $mode
			? $this->create_subscription( $args )
			: $this->create_checkout( $args );
	}

	/**
	 * Creates a PayPal order and returns the approval URL that sends the buyer
	 * to PayPal's checkout. After the buyer approves, PayPal sends them back
	 * to return_url where the order is captured via webhook.
	 */
	public function create_checkout( array $args ): array {
		$course_id = (int) ( $args['course_id'] ?? 0 );
		$course    = $course_id ? Course::find( $course_id ) : null;

		// Recurring-course subscriptions via PayPal land in PR-F. Refuse the
		// checkout cleanly so the customer sees a "PayPal not available for
		// this course" notice rather than a failed redirect.
		if ( $course && Course::is_recurring( $course ) ) {
			return array();
		}

		$token = $this->get_access_token();
		if ( ! $token ) {
			return [];
		}

		// Tax. This adapter shipped with NO tax code at all — the string "tax"
		// appeared zero times in this file — while the Stripe adapter applied the
		// configured rate. So a site owner who turned tax on charged it to Stripe
		// buyers and not to PayPal buyers: same site, same course, two different
		// totals, and nothing surfaced the discrepancy short of reconciling the books.
		//
		// Resolved through the ONE shared reader (\Learnomy\tax_amount) rather than a
		// private copy, because a private copy per adapter is precisely how this
		// happened.
		$subtotal = round( (float) ( $args['subtotal'] ?? $args['amount'] ?? 0 ), 2 );
		$tax      = round( (float) ( $args['tax'] ?? 0 ), 2 );
		$total    = round( (float) ( $args['amount'] ?? 0 ), 2 );
		$currency = strtoupper( $args['currency'] ?? 'USD' );
		// PayPal requires the amount's decimal count to match the currency:
		// "1000.00" is REJECTED for a zero-decimal currency (JPY/KRW) — it must
		// be "1000". Format every value below to the currency's own precision.
		$decimals   = \Learnomy\currency_decimals( $currency );
		$amount     = number_format( $total, $decimals, '.', '' );
		$return_url = $args['return_url'] ?? home_url( '/' );
		$cancel_url = $args['cancel_url'] ?? home_url( '/' );

		// Fixed-term courses ride the same one-time-payment flow; the
		// access window flows through the custom_id bundle so the webhook
		// can stamp enrollment.expires_at without re-reading the course.
		$access_days = 0;
		if ( $course && Course::is_fixed_term( $course ) ) {
			$access_days = isset( $course->access_duration_days ) ? (int) $course->access_duration_days : 0;
		}

		// Pipe-delimited ID bundle — survives the PayPal redirect so the
		// webhook can reconstruct user/course/plan/coupon plus the fixed-term
		// access window.
		$custom_chunks = [
			'u:' . ( $args['user_id'] ?? 0 ),
			'c:' . ( $args['course_id'] ?? 0 ),
			'p:' . ( $args['plan_id'] ?? 0 ),
			'k:' . ( $args['coupon_id'] ?? 0 ),
			'd:' . $access_days,
		];

		// External purchase (gift seats, etc.): an extension flags a non-course,
		// non-plan payment via purchase_type + a flat extra_metadata map. Both
		// ride the same custom_id bundle so the webhook can fire
		// learnomy_external_purchase_completed. (PayPal custom_id caps at 127
		// chars; the gift bundle is well under that.)
		$purchase_type = isset( $args['purchase_type'] ) ? sanitize_key( (string) $args['purchase_type'] ) : '';
		$purchase_unit = [
			'amount' => [
				'currency_code' => $currency,
				'value'         => $amount,
			],
		];

		// Itemise the tax so the buyer SEES it on PayPal's own review screen rather
		// than a silently larger number. PayPal requires the breakdown to sum exactly
		// to `value`, which is why $total is computed from the rounded parts above.
		if ( $tax > 0 ) {
			$purchase_unit['amount']['breakdown'] = [
				'item_total' => [
					'currency_code' => $currency,
					'value'         => number_format( $subtotal, $decimals, '.', '' ),
				],
				'tax_total'  => [
					'currency_code' => $currency,
					'value'         => number_format( $tax, $decimals, '.', '' ),
				],
			];
		}
		if ( '' !== $purchase_type ) {
			$custom_chunks[] = 'pt:' . $purchase_type;
			foreach ( (array) ( $args['extra_metadata'] ?? array() ) as $meta_key => $meta_value ) {
				if ( is_scalar( $meta_value ) ) {
					$custom_chunks[] = 'e_' . sanitize_key( (string) $meta_key ) . ':' . rawurlencode( (string) $meta_value );
				}
			}
			if ( ! empty( $args['label'] ) ) {
				$purchase_unit['description'] = mb_substr( (string) $args['label'], 0, 127 );
			}
		}
		$purchase_unit['custom_id'] = implode( '|', $custom_chunks );

		// Hand PayPal the member's billing details too.
		//
		// PayPal used to receive nothing from us — the address existed only
		// inside Stripe's Address Element, so a PayPal buyer's order carried
		// whatever PayPal happened to hold and our records carried nothing. Now
		// that the address belongs to the member it can go to every rail, which
		// also means PayPal prefills its own screen instead of asking again.
		$payload = [
			'intent'              => 'CAPTURE',
			'purchase_units'      => [ $purchase_unit ],
			'application_context' => [
				'return_url' => $return_url,
				'cancel_url' => $cancel_url,
			],
		];

		$billing = \Learnomy\Services\Billing_Profile::get( (int) ( $args['user_id'] ?? 0 ) );
		if ( '' !== trim( (string) ( $billing['billing_address_1'] ?? '' ) ) ) {
			$payload['payment_source'] = [
				'paypal' => [
					'name'    => [
						'given_name' => (string) $billing['billing_first_name'],
						'surname'    => (string) $billing['billing_last_name'],
					],
					'address' => array_filter(
						[
							'address_line_1' => (string) $billing['billing_address_1'],
							'address_line_2' => (string) $billing['billing_address_2'],
							'admin_area_2'   => (string) $billing['billing_city'],
							'admin_area_1'   => (string) $billing['billing_state'],
							'postal_code'    => (string) $billing['billing_postcode'],
							'country_code'   => (string) $billing['billing_country'],
						],
						static fn( $value ) => '' !== $value
					),
				],
			];
		}

		$body = wp_json_encode( $payload );

		$response = wp_remote_post(
			$this->base_url() . '/v2/checkout/orders',
			[
				'headers' => $this->json_headers( $token ),
				'body'    => $body,
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$order = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $order->id ) ) {
			return [];
		}

		$approval_link = $this->approval_link( $order->links ?? [] );

		return [
			'redirect_url' => $approval_link,
			'session_id'   => $order->id,
		];
	}

	/**
	 * Creates a PayPal billing subscription.
	 * Requires a PayPal Plan ID stored against the membership plan in settings.
	 */
	public function create_subscription( array $args ): array {
		$token   = $this->get_access_token();
		$plan_id = (int) ( $args['plan_id'] ?? 0 );

		if ( ! $token || ! $plan_id ) {
			return [];
		}

		// The billing plan comes from OUR price, the same way Stripe's does and
		// the same way the one-time path always has. Nobody sets a price twice.
		//
		// This used to read a PayPal plan id the owner had to create by hand in
		// PayPal and paste into the plan editor — and return nothing when it was
		// missing, so a membership silently would not sell through PayPal at all.
		// It also went stale exactly like Stripe's did: edit the price here and
		// PayPal kept charging the old figure, because the id was keyed on the
		// plan alone with no amount in it.
		$paypal_plan_id = $this->ensure_billing_plan( $args, $plan_id, $token );
		if ( '' === $paypal_plan_id ) {
			return [];
		}

		$payload = [
			'plan_id'             => $paypal_plan_id,
			'application_context' => [
				'return_url'  => $args['return_url'] ?? home_url( '/' ),
				'cancel_url'  => $args['cancel_url'] ?? home_url( '/' ),
				'user_action' => 'SUBSCRIBE_NOW',
			],
			// `bc` carries the billing cycle the buyer agreed to, so the
			// BILLING.SUBSCRIPTION.ACTIVATED webhook can record the same term the
			// checkout screen showed. parse_custom_id() casts to int, so the raw
			// chunk is re-read separately in handle_subscription_activated().
			'custom_id'           => implode(
				'|',
				[
					'u:' . ( $args['user_id'] ?? 0 ),
					'p:' . $plan_id,
					'bc:' . ( 'yearly' === ( $args['billing_cycle'] ?? '' ) ? 'yearly' : 'monthly' ),
				]
			),
		];

		// Tax on recurring charges. Stripe attaches a TaxRate to its subscriptions
		// (Stripe_Adapter::ensure_uniform_tax_rate); PayPal's analogue is
		// plan_overrides.taxes, applied per-subscription on top of the billing plan.
		// Without this, enabling tax raised the price for Stripe subscribers and left
		// PayPal subscribers paying the pre-tax amount forever.
		//
		// CAVEAT for the site owner: the tax rate is OURS (Settings → Commerce). If
		// you have also configured tax inside the PayPal billing plan itself, this
		// override replaces it — it does not stack. One rate, from one place.
		$tax_rate = \Learnomy\tax_rate();
		if ( $tax_rate > 0 ) {
			$payload['plan_overrides'] = [
				'taxes' => [
					'percentage' => number_format( $tax_rate, 2, '.', '' ),
					'inclusive'  => false, // Exclusive, matching the Stripe path.
				],
			];
		}

		$body = wp_json_encode( $payload );

		$response = wp_remote_post(
			$this->base_url() . '/v1/billing/subscriptions',
			[
				'headers' => $this->json_headers( $token ),
				'body'    => $body,
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$subscription = json_decode( wp_remote_retrieve_body( $response ) );
		if ( empty( $subscription->id ) ) {
			return [];
		}

		$approval_link = $this->approval_link( $subscription->links ?? [] );

		return [
			'redirect_url' => $approval_link,
			'session_id'   => $subscription->id,
		];
	}

	/**
	 * Cancels a PayPal billing subscription immediately.
	 */
	public function cancel_subscription( string $provider_sub_id ): bool {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return false;
		}

		$url      = $this->base_url() . '/v1/billing/subscriptions/' . sanitize_text_field( $provider_sub_id ) . '/cancel';
		$response = wp_remote_post(
			$url,
			[
				'headers' => array_merge( $this->json_headers( $token ), [ 'Content-Type' => 'application/json' ] ),
				'body'    => wp_json_encode( [ 'reason' => 'Cancelled by site admin.' ] ),
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		return 204 === (int) $code;
	}

	/**
	 * Processes inbound PayPal webhook events.
	 *
	 * Every webhook is logged to lrn_webhook_events BEFORE processing so the
	 * admin has a permanent audit trail and can replay failed events. The
	 * log row is written with outcome='pending' and updated after dispatch.
	 *
	 * Idempotency: if log() returns 0 the UNIQUE constraint fired. Increment
	 * retry_count and return 200 — the event was already handled.
	 *
	 * Signature verification is done via PayPal's verify-webhook-signature
	 * endpoint. Events are still logged when verification fails so invalid
	 * attempts are visible in the admin viewer.
	 *
	 * Handles: CHECKOUT.ORDER.APPROVED, BILLING.SUBSCRIPTION.ACTIVATED,
	 *          BILLING.SUBSCRIPTION.CANCELLED, PAYMENT.SALE.COMPLETED,
	 *          BILLING.SUBSCRIPTION.UPDATED.
	 *
	 * @throws \InvalidArgumentException When the payload is malformed, or its signature fails verification. Either way the REST layer answers 400 instead of silently succeeding.
	 */
	// phpcs:ignore Squiz.Commenting.FunctionCommentThrowTag.Missing -- the sniff counts throw STATEMENTS (2) but dedupes @throws by TYPE (1); both throws are \InvalidArgumentException, so this cannot be satisfied without inventing a second exception type.
	public function process_webhook( \WP_REST_Request $request ): void {
		$payload = $request->get_body();
		$headers = $request->get_headers();

		// Determine signature status and parse the event before logging.
		$sig_status = 'missing';
		$event      = null;
		$event_id   = '';
		$event_type = '';

		try {
			$sig_status = $this->verify_paypal_webhook( $payload, $headers )
				? 'valid'
				: 'invalid';

			$event      = json_decode( (string) $payload );
			$event_id   = $this->derive_event_id( $event, $headers, $payload );
			$event_type = isset( $event->event_type ) ? (string) $event->event_type : '';
		} catch ( \Throwable $e ) {
			// Unparseable payload — treat as an invalid signature. The
			// pre-initialised event/id/type defaults stand so the delivery is
			// still logged below with sig_status='invalid'.
			$sig_status = 'invalid';
		}

		// Log up front — every delivery is on record regardless of outcome.
		$log_id = Webhook_Event::log(
			[
				'provider'         => 'paypal',
				'event_type'       => $event_type,
				'event_id'         => $event_id,
				'payload_json'     => $payload,
				'signature_status' => $sig_status,
				'outcome'          => 'pending',
				'error_message'    => null,
			]
		);

		// Duplicate delivery detected (UNIQUE violation) — ack and skip. Returning
		// normally lets the REST wrapper send its default 200.
		if ( 0 === $log_id ) {
			Webhook_Event::increment_retry_by_event_id( 'paypal', $event_id );
			return;
		}

		// Error signalling: THROW so the REST wrapper (Checkout_Controller::
		// process_webhook) maps it to a real 4xx/5xx. status_header() inside a
		// REST callback is silently overridden by the framework serializing the
		// returned WP_REST_Response, so the old status_header()+return always
		// 200'd — PayPal then never retried genuine failures. Mirrors the Stripe
		// adapter's throw contract.
		if ( 'valid' !== $sig_status ) {
			Webhook_Event::update_outcome( $log_id, 'rejected', 'signature_invalid' );
			throw new \InvalidArgumentException( 'webhook_signature_invalid' );
		}

		if ( ! $event || empty( $event->event_type ) ) {
			Webhook_Event::update_outcome( $log_id, 'rejected', 'payload_malformed' );
			throw new \InvalidArgumentException( 'webhook_payload_malformed' );
		}

		try {
			switch ( $event->event_type ) {
				case 'CHECKOUT.ORDER.APPROVED':
					$this->handle_order_approved( $event->resource );
					break;

				case 'BILLING.SUBSCRIPTION.ACTIVATED':
					$this->handle_subscription_activated( $event->resource );
					break;

				case 'BILLING.SUBSCRIPTION.CANCELLED':
					$this->handle_subscription_cancelled( $event->resource );
					break;

				case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
					$this->handle_subscription_payment_failed( $event->resource );
					break;

				case 'PAYMENT.SALE.COMPLETED':
					$this->handle_subscription_renewed( $event->resource );
					break;

				case 'BILLING.SUBSCRIPTION.UPDATED':
					$this->handle_subscription_updated( $event->resource );
					break;

				case 'PAYMENT.CAPTURE.REFUNDED':
					$this->handle_capture_refunded( $event->resource );
					break;
			}

			Webhook_Event::update_outcome( $log_id, 'handled' );
		} catch ( \Throwable $e ) {
			Webhook_Event::update_outcome( $log_id, 'error', $e->getMessage() );
			// Re-throw so the REST wrapper returns 500 and PayPal retries the
			// delivery (it only retries on non-2xx). status_header() here is a
			// no-op inside the REST callback.
			throw $e;
		}

		// Success: return normally; the REST wrapper sends the default 200.
	}

	/**
	 * Derives a stable idempotency key for an inbound PayPal webhook.
	 *
	 * The webhook envelope's top-level `id` (the WH-xxx event ID) is the ideal
	 * key, but real PayPal deliveries — and the standard PAYMENT.SALE.COMPLETED
	 * payload — frequently omit it, carrying the identifying ID on the resource
	 * instead. With an empty key, Webhook_Event::log() collides on the UNIQUE
	 * (provider, event_id) constraint after the first event and silently drops
	 * every later delivery (including paid renewals). The fallback chain keeps
	 * each distinct event loggable once while still deduping replays:
	 *
	 *   top-level id  →  resource.id  →  resource.billing_agreement_id
	 *                 →  resource.sale_id  →  sha1(payload)
	 *
	 * The payload hash is the last resort: it is deterministic for a given body,
	 * so an identical replay still dedupes, while two genuinely different events
	 * always produce different keys.
	 *
	 * @param object|null $event   Decoded webhook body (null if JSON failed).
	 * @param array       $headers Request headers (PayPal transmission IDs).
	 * @param string      $payload Raw request body, used for the hash fallback.
	 * @return string A non-empty idempotency key.
	 */
	private function derive_event_id( ?object $event, array $headers, string $payload ): string {
		if ( $event instanceof \stdClass ) {
			if ( ! empty( $event->id ) ) {
				return (string) $event->id;
			}

			$resource = $event->resource ?? null;
			if ( $resource instanceof \stdClass ) {
				foreach ( [ 'id', 'billing_agreement_id', 'sale_id' ] as $key ) {
					if ( ! empty( $resource->$key ) ) {
						return (string) $resource->$key;
					}
				}
			}
		}

		// The transmission ID is per-delivery (it changes on each retry), so it
		// cannot dedupe replays. The payload hash is stable across retries and
		// guarantees a non-empty, collision-resistant key for every event.
		return 'pp_' . sha1( $payload );
	}

	/**
	 * Issues a refund on a captured PayPal payment.
	 * $transaction_id should be the PayPal capture ID stored at payment time.
	 */
	public function refund( string $transaction_id, float $amount ): string {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return '';
		}

		$capture_id = sanitize_text_field( $transaction_id );

		// PayPal validates the refund amount against the capture's own currency,
		// so a hardcoded USD rejected every non-USD refund. Read the currency
		// from the original transaction (this is request construction, not refund
		// bookkeeping — the ledger reversal lives in Refund_Service).
		$original = Transaction::find_by_provider_id( $capture_id );
		$currency = $original ? strtoupper( (string) $original->currency ) : 'USD';

		$url  = $this->base_url() . '/v2/payments/captures/' . $capture_id . '/refund';
		$body = wp_json_encode(
			[
				'amount' => [
					// Match the currency's decimal count (JPY refund = "1000", not
					// "1000.00", or PayPal rejects it).
					'value'         => number_format( $amount, \Learnomy\currency_decimals( $currency ), '.', '' ),
					'currency_code' => $currency,
				],
			]
		);

		$response = wp_remote_post(
			$url,
			[
				'headers' => $this->json_headers( $token ),
				'body'    => $body,
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		// Return PayPal's refund id — the PAYMENT.CAPTURE.REFUNDED echo carries
		// the same id (resource.id), so threading it into the ledger dedups the
		// admin action against the webhook instead of double-recording.
		$result = json_decode( wp_remote_retrieve_body( $response ) );
		return isset( $result->id ) ? (string) $result->id : '';
	}

	/**
	 * Reconciles a PayPal dashboard / API refund into Learnomy.
	 *
	 * PayPal sends PAYMENT.CAPTURE.REFUNDED for every capture refund, including
	 * the echo of an admin-initiated one (Refund_Service no-ops the duplicate).
	 * The refund resource points back at its capture through the rel="up" link;
	 * that capture id is what we stored as the original transaction's
	 * provider_transaction_id.
	 *
	 * @param object|null $resource The PayPal refund resource from the webhook.
	 */
	private function handle_capture_refunded( $resource ): void {
		if ( ! is_object( $resource ) ) {
			return;
		}

		$capture_id = '';
		if ( ! empty( $resource->links ) && is_array( $resource->links ) ) {
			foreach ( $resource->links as $link ) {
				if ( isset( $link->rel, $link->href ) && 'up' === $link->rel ) {
					$capture_id = (string) substr( strrchr( (string) $link->href, '/' ), 1 );
					break;
				}
			}
		}

		if ( '' === $capture_id ) {
			return;
		}

		$original = Transaction::find_by_provider_id( $capture_id );
		if ( ! $original || 'refunded' === ( $original->status ?? '' ) ) {
			return;
		}

		$amount = isset( $resource->amount->value )
			? (float) $resource->amount->value
			: (float) $original->amount;

		( new Refund_Service() )->apply( $original, $amount, 'paypal', (string) ( $resource->id ?? '' ) );
	}

	/**
	 * Looks up a PayPal order by ID and maps its status to the adapter contract.
	 *
	 * The thank-you page calls this when the local transaction row is
	 * missing (webhook race). PayPal Orders v2 status values map as:
	 *   - COMPLETED → 'paid'
	 *   - APPROVED  → 'pending' (buyer approved but capture not yet done;
	 *                  the CHECKOUT.ORDER.APPROVED webhook will run capture)
	 *   - VOIDED / CANCELLED / FAILED → 'failed'
	 *   - CREATED / SAVED / PAYER_ACTION_REQUIRED → 'pending'
	 *
	 * @param string $session_id PayPal order ID (the value returned in
	 *                           create_checkout's session_id field).
	 * @return string 'paid' | 'pending' | 'failed' | 'unknown'.
	 */
	public function verify_payment_status( string $session_id ): string {
		if ( '' === $session_id ) {
			return 'unknown';
		}

		$token = $this->get_access_token();
		if ( '' === $token ) {
			return 'unknown';
		}

		$response = wp_remote_get(
			$this->base_url() . '/v2/checkout/orders/' . rawurlencode( $session_id ),
			array(
				'headers' => $this->json_headers( $token ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return 'unknown';
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			// Order id doesn't exist in PayPal's records — treat as failure
			// rather than pending, since the buyer can't recover by waiting.
			return 'failed';
		}
		if ( $code >= 400 ) {
			return 'unknown';
		}

		$order  = json_decode( wp_remote_retrieve_body( $response ) );
		$status = is_object( $order ) && isset( $order->status )
			? strtoupper( (string) $order->status )
			: '';

		switch ( $status ) {
			case 'COMPLETED':
				return 'paid';
			case 'VOIDED':
			case 'CANCELLED':
			case 'CANCELED':
			case 'FAILED':
			case 'DECLINED':
				return 'failed';
			case 'APPROVED':
			case 'CREATED':
			case 'SAVED':
			case 'PAYER_ACTION_REQUIRED':
				return 'pending';
			default:
				return 'unknown';
		}
	}

	// -------------------------------------------------------------------------
	// Webhook event handlers
	// -------------------------------------------------------------------------

	private function handle_order_approved( object $order ): void {
		// Parse our custom_id to recover user/course/plan.
		$custom_id = $order->purchase_units[0]->custom_id ?? '';
		$ids       = $this->parse_custom_id( $custom_id );

		// External-purchase context rides the same bundle as string values
		// (parse_custom_id casts to int, so it's parsed separately here).
		$purchase_type = '';
		$ext           = array();
		foreach ( explode( '|', $custom_id ) as $chunk ) {
			[ $chunk_key, $chunk_value ] = array_pad( explode( ':', $chunk, 2 ), 2, '' );
			$chunk_key                   = trim( $chunk_key );
			if ( 'pt' === $chunk_key ) {
				$purchase_type = sanitize_key( trim( $chunk_value ) );
			} elseif ( str_starts_with( $chunk_key, 'e_' ) ) {
				$ext[ substr( $chunk_key, 2 ) ] = rawurldecode( trim( $chunk_value ) );
			}
		}

		$user_id     = $ids['u'] ?? 0;
		$course_id   = $ids['c'] ?? 0;
		$plan_id     = $ids['p'] ?? 0;
		$coupon_id   = (int) ( $ids['k'] ?? 0 );
		$access_days = (int) ( $ids['d'] ?? 0 );

		if ( ! $user_id ) {
			return;
		}

		// Capture the payment before recording anything.
		$capture = $this->capture_order( $order->id );
		if ( ! $capture ) {
			return;
		}

		$capture_id = $capture->purchase_units[0]->payments->captures[0]->id ?? '';
		$amount     = (float) ( $capture->purchase_units[0]->payments->captures[0]->amount->value ?? 0 );
		$currency   = strtoupper( $capture->purchase_units[0]->payments->captures[0]->amount->currency_code ?? 'USD' );

		// The tax INSIDE that amount. `$amount` is the captured total, which is
		// subtotal + tax, and the commission base is gross minus tax — so a tax
		// left unrecorded is silently split between the platform and the
		// instructor instead of being set aside. On a $49 course at 10% that
		// paid the instructor 18.87 where 17.15 was owed, on every taxed PayPal
		// sale. Read from the breakdown we sent when the order was created:
		// the capture carries it on some flows, the order always does.
		$tax = $this->tax_from_paypal_units( $capture->purchase_units ?? array() );
		if ( $tax <= 0 ) {
			$tax = $this->tax_from_paypal_units( $order->purchase_units ?? array() );
		}

		if ( Transaction::find_by_provider_id( $capture_id ) ) {
			return;
		}

		// --- External-purchase branch (gift seats, etc.) ---
		// A captured payment an extension flagged via purchase_type — not a
		// course or plan purchase. Record it and hand the context to the
		// extension; no enrollment is created here.
		if ( '' !== $purchase_type ) {
			Purchase_Recorder::record(
				[
					'user_id'     => $user_id,
					'gateway'     => 'paypal',
					'provider_id' => (string) $capture_id,
					'type'        => $purchase_type,
					'amount'      => $amount,
					'currency'    => $currency,
					'tax'         => $tax,
					'ext'         => $ext,
				]
			);
			return;
		}

		$txn_id = Purchase_Recorder::record(
			[
				'user_id'     => $user_id,
				'gateway'     => 'paypal',
				'provider_id' => (string) $capture_id,
				'type'        => $plan_id ? 'membership' : 'course',
				'course_id'   => $course_id,
				'plan_id'     => $plan_id,
				'coupon_id'   => $coupon_id,
				'amount'      => $amount,
				'currency'    => $currency,
				'tax'         => $tax,
			]
		);

		if ( $course_id ) {
			$enrollment_id = ( new Enrollment_Service() )->enroll( $user_id, $course_id, 'purchase' );

			if ( is_wp_error( $enrollment_id ) || (int) $enrollment_id <= 0 ) {
				// Customer was charged but not enrolled (e.g. a pre-enrol gate
				// vetoed it). Never silent — audit + self-heal/alert hook.
				Enrollment_Service::report_purchase_failure(
					$user_id,
					$course_id,
					$enrollment_id,
					'paypal',
					array( 'capture_id' => $capture_id )
				);
			} elseif ( $access_days > 0 ) {
				// Fixed-term: stamp expires_at if the order metadata carries
				// a positive access-duration window. Enrollment_Service::enroll()
				// already derives it from the course row; the metadata path
				// just honours an override that survived the PayPal redirect.
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $access_days * DAY_IN_SECONDS ) );
				Enrollment::update( (int) $enrollment_id, array( 'expires_at' => $expires_at ) );
			}
		}

		if ( $coupon_id && $txn_id ) {
			( new Coupon_Service() )->apply( $coupon_id, $user_id, (int) $txn_id );
		}
	}

	private function handle_subscription_activated( object $resource ): void {
		$custom_id = $resource->custom_id ?? '';
		$ids       = $this->parse_custom_id( $custom_id );

		$user_id = $ids['u'] ?? 0;
		$plan_id = $ids['p'] ?? 0;

		if ( ! $user_id || ! $plan_id ) {
			return;
		}

		// The billing cycle is a string, so it cannot come through
		// parse_custom_id()'s int cast — read the raw chunk. Subscriptions
		// created before 1.8.1 have no `bc:` chunk and resolve to the plan's own
		// interval, which is exactly the pre-fix behaviour.
		$billing_cycle = '';
		foreach ( explode( '|', (string) $custom_id ) as $chunk ) {
			[ $key, $value ] = array_pad( explode( ':', $chunk, 2 ), 2, '' );
			if ( 'bc' === trim( $key ) ) {
				$billing_cycle = sanitize_key( trim( $value ) );
			}
		}

		$membership_service = new Subscription_Service();
		$membership_service->subscribe( $user_id, $plan_id, 'paypal', $resource->id, $billing_cycle );
	}

	private function handle_subscription_cancelled( object $resource ): void {
		$sub = Subscription::find_by_provider_id( $resource->id );
		if ( ! $sub ) {
			return;
		}

		$membership_service = new Subscription_Service();
		$membership_service->expire( (int) $sub->id );
	}

	/**
	 * Handles BILLING.SUBSCRIPTION.PAYMENT.FAILED — fired when PayPal fails to
	 * collect a recurring payment. Without this case the switch matched nothing,
	 * marked the event handled, and returned 200, leaving the subscription
	 * 'active' — so the customer kept paid access while not paying, dunning never
	 * ran, and no "Payment failed" state ever appeared until PayPal eventually
	 * cancelled the agreement many cycles later. Stripe (invoice.payment_failed)
	 * and Woo (on_subscription_payment_failed) both mark past_due for the same
	 * event; this brings PayPal to parity by routing through the same service
	 * method, which fires `learnomy_subscription_past_due` (dunning + emails).
	 *
	 * The resource is the subscription object, so `resource->id` is the billing
	 * agreement / subscription id — the same field handle_subscription_cancelled
	 * resolves on.
	 */
	private function handle_subscription_payment_failed( object $resource ): void {
		$sub = Subscription::find_by_provider_id( $resource->id ?? '' );
		if ( ! $sub ) {
			return;
		}

		$membership_service = new Subscription_Service();
		$membership_service->mark_past_due( (int) $sub->id, 'paypal_payment_failed' );
	}

	/**
	 * Handles PAYMENT.SALE.COMPLETED — fired by PayPal for every recurring
	 * billing cycle on an active subscription. This is the only signal we
	 * receive for subscription renewals; without it, `Subscription_Service::renew()`
	 * is never called and subscriptions silently lapse.
	 *
	 * Only sale objects with a `billing_agreement_id` (the I-xxx format
	 * subscription ID) are subscription renewals. Sales without it are
	 * one-time order captures that go through `handle_order_approved()`.
	 */
	private function handle_subscription_renewed( object $sale ): void {
		// Only subscription renewal payments carry a billing_agreement_id.
		// One-time order captures do not — those are handled via CHECKOUT.ORDER.APPROVED.
		$provider_sub_id = isset( $sale->billing_agreement_id ) ? (string) $sale->billing_agreement_id : '';
		if ( '' === $provider_sub_id ) {
			return;
		}

		$subscription = Subscription::find_by_provider_id( $provider_sub_id );
		if ( ! $subscription ) {
			// Webhook fired before we persisted the subscription row.
			// This is a race condition on BILLING.SUBSCRIPTION.ACTIVATED — safe to ignore.
			return;
		}

		// Idempotency: bail out if this sale was already recorded.
		// PayPal retries webhooks up to 3×; the second delivery must not
		// extend the period a second time.
		$sale_id = isset( $sale->id ) ? (string) $sale->id : '';
		if ( '' !== $sale_id && Transaction::find_by_provider_id( $sale_id ) ) {
			return;
		}

		$amount   = (float) ( $sale->amount->total ?? 0 );
		$currency = strtoupper( (string) ( $sale->amount->currency ?? 'USD' ) );

		// Recurring tax rides on the billing plan (plan_overrides.taxes), so a
		// renewal's total includes it and PayPal itemises it under
		// amount.details.tax. Same rule as the one-time path: the tax inside the
		// amount has to be recorded, or the split pays it out as revenue.
		$tax = (float) ( $sale->amount->details->tax ?? 0 );

		Purchase_Recorder::record(
			[
				'user_id'     => (int) $subscription->user_id,
				'gateway'     => 'paypal',
				'provider_id' => (string) $sale_id,
				'type'        => 'membership_renewal',
				// Ledger only — the period is extended below and access never
				// lapsed, so this is not a new entitlement to announce.
				'fulfil'      => false,
				'plan_id'     => (int) $subscription->plan_id,
				'amount'      => $amount,
				'currency'    => $currency,
				'tax'         => $tax,
			]
		);

		// Extend the subscription period. We compute the next period from
		// now() rather than from the current period_end to stay aligned with
		// PayPal's actual charge date even if our cron-sweep was slightly late.
		$plan = MembershipPlan::find( (int) $subscription->plan_id );
		if ( ! $plan ) {
			return;
		}

		$membership_service = new Subscription_Service();
		$new_period_start   = now();
		$new_period_end     = $membership_service->compute_period_end( $plan, $new_period_start );

		// Subscription_Service::renew() persists the period extension and fires
		// learnomy_subscription_renewed + learnomy_membership_renewed hooks.
		$membership_service->renew( (int) $subscription->id, $new_period_start, $new_period_end );
	}

	/**
	 * Handles BILLING.SUBSCRIPTION.UPDATED — fired for plan changes, pauses,
	 * and resumes. Full handling requires plan-change proration (a Pro feature).
	 * Free records the event and delegates resume via the existing service.
	 */
	private function handle_subscription_updated( object $resource ): void {
		$sub = Subscription::find_by_provider_id( (string) ( $resource->id ?? '' ) );
		if ( ! $sub ) {
			return;
		}

		$status = strtoupper( (string) ( $resource->status ?? '' ) );

		if ( 'ACTIVE' === $status && 'past_due' === $sub->status ) {
			// Subscription recovered from past-due — treat as a resume.
			$membership_service = new Subscription_Service();
			$membership_service->resume( (int) $sub->id );
			return;
		}

		// Other status transitions (plan upgrades, pauses) require Pro's
		// proration engine. Log for visibility and leave state unchanged.
		do_action( 'learnomy_paypal_subscription_updated', (int) $sub->id, $resource->status ?? '', $resource );
	}

	// -------------------------------------------------------------------------
	// PayPal API helpers
	// -------------------------------------------------------------------------

	private function capture_order( string $order_id ): ?object {
		$token = $this->get_access_token();
		if ( ! $token ) {
			return null;
		}

		$url      = $this->base_url() . '/v2/checkout/orders/' . sanitize_text_field( $order_id ) . '/capture';
		$response = wp_remote_post(
			$url,
			[
				'headers' => $this->json_headers( $token ),
				'body'    => '{}',
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );
		return ( $result && ! empty( $result->id ) ) ? $result : null;
	}

	/**
	 * Public wrapper around get_access_token() for Pro extensions that need
	 * to make authenticated PayPal API calls without re-implementing the
	 * OAuth2 dance (e.g. Membership_Pro's PayPal_Proration).
	 *
	 * Returns an empty string on failure — callers must guard.
	 */
	public function access_token(): string {
		return $this->get_access_token();
	}

	/**
	 * Public accessor for the sandbox/live base URL so Pro extensions
	 * target the same environment as Free.
	 */
	/**
	 * The tax PayPal itemised inside a purchase unit's total.
	 *
	 * We send the breakdown ourselves when tax applies (item_total + tax_total,
	 * which PayPal requires to sum exactly to `value`), so reading it back is
	 * reading our own figure rather than trusting PayPal to recompute it.
	 *
	 * @param array $units purchase_units from an order or capture payload.
	 * @return float Tax in major units, 0.0 when the unit carries no breakdown.
	 */
	private function tax_from_paypal_units( $units ): float {
		foreach ( (array) $units as $unit ) {
			$tax = $unit->amount->breakdown->tax_total->value ?? null;
			if ( null !== $tax ) {
				return (float) $tax;
			}
		}

		return 0.0;
	}

	public function api_base_url(): string {
		return $this->base_url();
	}

	/**
	 * The PayPal billing plan for a set of terms, derived from our own price.
	 *
	 * Public because Pro needs the SAME derivation. Membership Pro's proration
	 * revises a subscription onto a different plan, and PayPal will only accept
	 * a plan id — so if Pro derived its own, an upgrade could bill a figure the
	 * plan does not advertise. One derivation, both callers.
	 *
	 * @param array $args Terms: plan_id, amount, currency, billing_cycle/interval, label.
	 * @return string PayPal plan id, or '' when it could not be derived.
	 */
	public function billing_plan_for( array $args ): string {
		$token = $this->get_access_token();
		if ( '' === $token ) {
			return '';
		}

		return $this->ensure_billing_plan( $args, (int) ( $args['plan_id'] ?? 0 ), $token );
	}

	/**
	 * The PayPal billing plan for what the buyer is purchasing, derived from our
	 * own price.
	 *
	 * PayPal has no inline-pricing equivalent to Stripe's `price_data` — a
	 * subscription must reference a plan, and a plan has the amount baked into
	 * it. So the amount goes in the CACHE KEY. A price edit produces a different
	 * key, which derives a new plan, and the old one is simply never asked for
	 * again. That gives the same guarantee as sending the price inline: what we
	 * charge always comes from our row, and there is no stored figure that can
	 * quietly disagree with the one on screen.
	 *
	 * People already subscribed keep billing on the plan they signed up to,
	 * which is what they agreed to.
	 *
	 * @param array  $args    create_purchase_session args.
	 * @param int    $plan_id Learnomy membership plan id.
	 * @param string $token   OAuth token.
	 * @return string PayPal plan id, or '' when it could not be derived.
	 */
	private function ensure_billing_plan( array $args, int $plan_id, string $token ): string {
		$amount   = isset( $args['amount'] ) ? (float) $args['amount'] : 0.0;
		$currency = strtoupper( (string) ( $args['currency'] ?? 'USD' ) );
		$interval = ( 'yearly' === ( $args['billing_cycle'] ?? '' ) || 'year' === ( $args['interval'] ?? '' ) ) ? 'YEAR' : 'MONTH';
		$label    = (string) ( $args['label'] ?? $args['item_label'] ?? '' );

		$plan = \Learnomy\Models\MembershipPlan::find( $plan_id );
		if ( $plan ) {
			if ( $amount <= 0 ) {
				$amount = (float) ( $plan->price ?? 0 );
			}
			if ( '' === $label ) {
				$label = (string) ( $plan->title ?? '' );
			}
		}
		if ( $amount <= 0 ) {
			return '';
		}
		if ( '' === $label ) {
			$label = __( 'Membership', 'learnomy' );
		}

		// The plan's free trial, expressed to PayPal as a TRIAL tenure below.
		// Callers have passed `trial_days` since the Stripe leg was fixed; this
		// adapter ignored it, so a buyer promised a free trial was billed by
		// PayPal from cycle one (Basecamp 10184336183). Falls back to the plan
		// row for the membership path, exactly as Stripe does.
		$trial_days = isset( $args['trial_days'] ) ? max( 0, (int) $args['trial_days'] ) : 0;
		if ( ! isset( $args['trial_days'] ) && $plan ) {
			$trial_days = max( 0, (int) ( $plan->trial_days ?? 0 ) );
		}

		$settings  = get_option( 'learnomy_settings', [] );
		$mode      = is_array( $settings ) ? ( (string) ( $settings['paypal_mode'] ?? 'sandbox' ) ) : 'sandbox';
		// Trial length is part of the identity of a PayPal billing plan: the
		// plan object carries the trial, and these are cached and reused, so
		// omitting it from the key would serve a trial-bearing plan to a buyer
		// who is not entitled to one (and vice versa) for as long as the option
		// survives.
		$cache_key = 'learnomy_paypal_billing_plan_' . $plan_id . '_' . $interval . '_' . str_replace( '.', '_', (string) round( $amount, 2 ) ) . '_' . strtolower( $currency ) . '_' . $mode . ( $trial_days > 0 ? '_t' . $trial_days : '' );

		$cached = (string) get_option( $cache_key, '' );
		if ( '' !== $cached ) {
			return $cached;
		}

		$product_id = $this->ensure_catalog_product( $label, $plan_id, $mode, $token );
		if ( '' === $product_id ) {
			return '';
		}

		// PayPal expresses a free trial as its own TRIAL tenure occupying
		// sequence 1, with the paid REGULAR cycle pushed to sequence 2. The
		// sequences must be contiguous and the trial must come first, so the
		// regular cycle's sequence is derived rather than hardcoded.
		$billing_cycles = [];
		if ( $trial_days > 0 ) {
			$billing_cycles[] = [
				'frequency'      => [
					'interval_unit'  => 'DAY',
					'interval_count' => $trial_days,
				],
				'tenure_type'    => 'TRIAL',
				'sequence'       => 1,
				'total_cycles'   => 1,
				'pricing_scheme' => [
					'fixed_price' => [
						'value'         => '0.00',
						'currency_code' => $currency,
					],
				],
			];
		}
		$billing_cycles[] = [
			'frequency'      => [
				'interval_unit'  => $interval,
				'interval_count' => 1,
			],
			'tenure_type'    => 'REGULAR',
			'sequence'       => $trial_days > 0 ? 2 : 1,
			'total_cycles'   => 0, // Bill until cancelled.
			'pricing_scheme' => [
				'fixed_price' => [
					'value'         => number_format( $amount, 2, '.', '' ),
					'currency_code' => $currency,
				],
			],
		];

		$response = wp_remote_post(
			$this->base_url() . '/v1/billing/plans',
			[
				'headers' => $this->json_headers( $token ),
				'timeout' => 20,
				'body'    => wp_json_encode(
					[
						'product_id'          => $product_id,
						'name'                => mb_substr( $label, 0, 127 ),
						'status'              => 'ACTIVE',
						'billing_cycles'      => $billing_cycles,
						'payment_preferences' => [
							'auto_bill_outstanding'     => true,
							'setup_fee_failure_action'  => 'CONTINUE',
							'payment_failure_threshold' => 3,
						],
					]
				),
			]
		);

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$id   = (string) ( $body['id'] ?? '' );
		if ( '' === $id ) {
			return '';
		}

		update_option( $cache_key, $id, false );
		return $id;
	}

	/**
	 * The PayPal catalog product a plan bills against, created once and reused.
	 *
	 * Keyed without the amount on purpose: a product carries a NAME and no
	 * money, so unlike a billing plan it cannot drift out of step with what we
	 * charge.
	 *
	 * @param string $label   Human name shown to the buyer.
	 * @param int    $plan_id Learnomy membership plan id.
	 * @param string $mode    'sandbox' | 'live'.
	 * @param string $token   OAuth token.
	 * @return string Product id, or '' on failure.
	 */
	private function ensure_catalog_product( string $label, int $plan_id, string $mode, string $token ): string {
		$cache_key = 'learnomy_paypal_product_' . $plan_id . '_' . $mode;

		$cached = (string) get_option( $cache_key, '' );
		if ( '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			$this->base_url() . '/v1/catalogs/products',
			[
				'headers' => $this->json_headers( $token ),
				'timeout' => 20,
				'body'    => wp_json_encode(
					[
						'name' => mb_substr( $label, 0, 127 ),
						'type' => 'SERVICE',
					]
				),
			]
		);

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$id   = (string) ( $body['id'] ?? '' );
		if ( '' === $id ) {
			return '';
		}

		update_option( $cache_key, $id, false );
		return $id;
	}

	/**
	 * Fetches a short-lived OAuth2 access token using client credentials.
	 * We don't cache it — tokens are valid for 9 hours but requests are
	 * infrequent enough that a fresh token per operation is fine.
	 */
	private function get_access_token(): string {
		$settings = get_option( 'learnomy_settings', [] );
		$mode     = $settings['paypal_mode'] ?? 'sandbox';

		// Mode-specific client + secret with legacy fallback.
		$client_id     = $this->client_id( $mode );
		$client_secret = \Learnomy\Security\Encrypted_Option::get( 'learnomy_paypal_' . $mode . '_secret' );

		if ( '' === $client_id ) {
			$client_id = $settings['paypal_client_id'] ?? '';
		}
		if ( '' === $client_secret ) {
			$client_secret = \Learnomy\Security\Encrypted_Option::get( 'learnomy_paypal_secret' );
		}

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return '';
		}

		$response = wp_remote_post(
			$this->base_url() . '/v1/oauth2/token',
			[
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body'    => 'grant_type=client_credentials',
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		return $body->access_token ?? '';
	}

	/**
	 * Verifies the webhook payload via PayPal's verification endpoint.
	 * This makes an outbound API call but is the only reliable way to confirm
	 * that a PayPal webhook is genuine.
	 */
	private function verify_paypal_webhook( string $payload, array $headers ): bool {
		$settings   = get_option( 'learnomy_settings', [] );
		$webhook_id = $this->webhook_id();

		if ( empty( $webhook_id ) ) {
			// No webhook ID configured — skip verification in sandbox/dev mode only.
			$mode = $settings['paypal_mode'] ?? 'sandbox';
			return 'sandbox' === $mode || ! empty( $settings['paypal_sandbox'] );
		}

		$token = $this->get_access_token();
		if ( ! $token ) {
			return false;
		}

		$body = wp_json_encode(
			[
				'auth_algo'         => $headers['paypal_auth_algo'][0] ?? '',
				'cert_url'          => $headers['paypal_cert_url'][0] ?? '',
				'transmission_id'   => $headers['paypal_transmission_id'][0] ?? '',
				'transmission_sig'  => $headers['paypal_transmission_sig'][0] ?? '',
				'transmission_time' => $headers['paypal_transmission_time'][0] ?? '',
				'webhook_id'        => $webhook_id,
				'webhook_event'     => json_decode( (string) $payload ),
			]
		);

		$response = wp_remote_post(
			$this->base_url() . '/v1/notifications/verify-webhook-signature',
			[
				'headers' => $this->json_headers( $token ),
				'body'    => $body,
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$result = json_decode( wp_remote_retrieve_body( $response ) );
		return isset( $result->verification_status ) && 'SUCCESS' === $result->verification_status;
	}

	private function base_url(): string {
		$settings = get_option( 'learnomy_settings', [] );
		$mode     = $settings['paypal_mode'] ?? 'sandbox';

		// paypal_mode is the canonical flag from Phase 3+. Fall back to the
		// legacy paypal_sandbox boolean for sites that haven't re-saved.
		if ( 'sandbox' === $mode || ! empty( $settings['paypal_sandbox'] ) ) {
			return self::API_SANDBOX;
		}

		return self::API_LIVE;
	}

	private function json_headers( string $token ): array {
		return [
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		];
	}

	/**
	 * Decodes the pipe-delimited custom_id string we attach to PayPal orders.
	 * Format: "u:123|c:456|p:0"
	 */
	private function parse_custom_id( string $custom_id ): array {
		$result = [];
		foreach ( explode( '|', $custom_id ) as $chunk ) {
			[ $key, $value ]        = array_pad( explode( ':', $chunk, 2 ), 2, '' );
			$result[ trim( $key ) ] = (int) trim( $value );
		}
		return $result;
	}
}
