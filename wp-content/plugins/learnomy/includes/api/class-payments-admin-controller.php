<?php
/**
 * Payments admin controller — test-connection endpoint for Stripe and PayPal.
 *
 * POST /learnomy/v1/admin/payments/test-connection
 *   Body: { "provider": "stripe" | "paypal" }
 *   Response (success): { "data": { "provider", "mode_detected", "account_id" | "merchant_id" } }
 *   Response (failure): { "error": { "code", "message" } }
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;

class Payments_Admin_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'admin/payments';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/test-connection',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'is_admin' ),
				'args'                => array(
					'provider'  => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'stripe', 'paypal' ),
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Payment provider to test.', 'learnomy' ),
					),
					'secret'    => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Override secret key. When supplied, tests this value instead of the saved option. Lets admins validate a typed-but-unsaved key.', 'learnomy' ),
					),
					'client_id' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Override PayPal Client ID. Only used when provider=paypal.', 'learnomy' ),
					),
				),
			)
		);

		// Refund endpoint — site-owner-only. Refunds are transaction-specific
		// per user policy (one txn per call, no bulk).
		register_rest_route(
			$this->namespace,
			'/admin/transactions/(?P<id>\d+)/refund',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'refund_transaction' ),
				'permission_callback' => array( $this, 'is_admin' ),
				'args'                => array(
					'id'     => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					// PARTIAL REFUNDS. Optional — omit for a full refund, so every
					// existing caller keeps working unchanged.
					//
					// The capability was always there: all three adapters implement
					// `refund( string $transaction_id, float $amount )` and
					// Refund_Service::apply() takes an $amount. The route simply never
					// offered the argument, so the handler hardcoded the full
					// transaction amount and the only refund button in the product could
					// not do anything else. A refund service that supports partials, and
					// a UI that cannot reach them, is a feature nobody can use.
					'amount' => array(
						'required' => false,
						'type'     => 'number',
					),
				),
			)
		);
	}

	/**
	 * Verify admin capability. Reuses the is_admin helper convention from
	 * Admin_Controller — manage_options gates all admin-only routes.
	 *
	 * @return true|\WP_Error
	 */
	public function is_admin(): bool|\WP_Error {
		return $this->require_cap( 'manage_options' );
	}

	/**
	 * Handles POST /admin/payments/test-connection.
	 *
	 * Makes a live outbound HTTP request to the provider using whichever key
	 * set is currently active (mode-specific first, legacy fallback second).
	 * Returns the account/merchant info on success or a structured error on
	 * failure so the frontend can render an inline message without guessing.
	 */
	public function test_connection( WP_REST_Request $request ): WP_REST_Response {
		$provider = $request->get_param( 'provider' );

		// Customers want to paste a key, click Test, then Save — gating
		// on the saved option meant they couldn't validate a key without
		// first committing it (Basecamp #9932052574). Accept an optional
		// `secret` body parameter so the JS can pass the typed-but-
		// unsaved field value. Empty / missing → fall back to the saved
		// option (existing behaviour).
		$override_secret = (string) ( $request->get_param( 'secret' ) ?? '' );

		if ( 'stripe' === $provider ) {
			return $this->test_stripe( $override_secret );
		}

		$override_client = (string) ( $request->get_param( 'client_id' ) ?? '' );
		return $this->test_paypal( $override_client, $override_secret );
	}

	// -------------------------------------------------------------------------
	// Stripe
	// -------------------------------------------------------------------------

	/**
	 * Calls GET /v1/account with the currently active secret key.
	 * A valid key returns the Stripe account object; an invalid key returns 401.
	 */
	private function test_stripe( string $override_secret = '' ): WP_REST_Response {
		// When the admin pasted a key into the form and clicked Test
		// before saving, prefer that value. Otherwise fall back to the
		// saved option for the active mode. Sites without either get a
		// clear "enter a key" error.
		$secret = trim( $override_secret );
		if ( '' === $secret ) {
			$settings = get_option( 'learnomy_settings', array() );
			$mode     = $settings['stripe_mode'] ?? 'test';
			$secret   = \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_' . $mode . '_secret' );

			// Legacy fallback.
			if ( '' === $secret ) {
				$secret = \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_secret_key' );
			}
		}

		if ( '' === $secret ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_stripe_no_key',
					__( 'No Stripe secret key is saved for this mode. Enter a key and save settings first.', 'learnomy' ),
					array( 'status' => 400 )
				)
			);
		}

		$response = wp_remote_get(
			'https://api.stripe.com/v1/account',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_stripe_connection_error',
					/* translators: %s: WP HTTP error message */
					sprintf( __( 'Could not reach Stripe: %s', 'learnomy' ), $response->get_error_message() ),
					array( 'status' => 502 )
				)
			);
		}

		$body        = json_decode( wp_remote_retrieve_body( $response ) );
		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			$api_message = $body->error->message ?? __( 'Stripe returned an unexpected error.', 'learnomy' );
			$hint        = 401 === $status_code
				? __( 'The key may be invalid or from the wrong mode (test vs live).', 'learnomy' )
				: '';
			return $this->error_response(
				new \WP_Error(
					'learnomy_stripe_api_error',
					$hint ? $api_message . ' ' . $hint : $api_message,
					array( 'status' => $status_code >= 500 ? 502 : 422 )
				)
			);
		}

		// Detect mode from the key prefix (sk_test_ vs sk_live_) rather than
		// from settings — confirms the actual key matches the intended mode.
		$mode_detected = str_starts_with( $secret, 'sk_test_' ) ? 'test' : 'live';

		return $this->single_response(
			array(
				'provider'      => 'stripe',
				'mode_detected' => $mode_detected,
				'account_id'    => $body->id ?? '',
				'display_name'  => $body->settings->dashboard->display_name ?? '',
				'country'       => $body->country ?? '',
			)
		);
	}

	// -------------------------------------------------------------------------
	// PayPal
	// -------------------------------------------------------------------------

	/**
	 * Calls GET /v1/identity/oauth2/userinfo using a fresh OAuth2 token.
	 * If the token exchange fails, the client_id+secret combo is invalid.
	 */
	private function test_paypal( string $override_client = '', string $override_secret = '' ): WP_REST_Response {
		// Same "test before save" pattern as Stripe — honour typed-but-
		// unsaved client_id + secret when both are supplied, else fall
		// back to the saved options for the active mode.
		$client_id = trim( $override_client );
		$secret    = trim( $override_secret );

		if ( '' === $client_id || '' === $secret ) {
			$settings = get_option( 'learnomy_settings', array() );
			$mode     = $settings['paypal_mode'] ?? 'sandbox';

			if ( '' === $client_id ) {
				$client_id = 'sandbox' === $mode
					? ( $settings['paypal_sandbox_client'] ?? '' )
					: ( $settings['paypal_live_client'] ?? '' );

				// Legacy fallback.
				if ( '' === $client_id ) {
					$client_id = $settings['paypal_client_id'] ?? '';
				}
			}

			if ( '' === $secret ) {
				$secret = \Learnomy\Security\Encrypted_Option::get( 'learnomy_paypal_' . $mode . '_secret' );
				if ( '' === $secret ) {
					$secret = \Learnomy\Security\Encrypted_Option::get( 'learnomy_paypal_secret' );
				}
			}
		}

		if ( '' === $client_id || '' === $secret ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_paypal_no_credentials',
					__( 'No PayPal credentials saved for this mode. Enter a Client ID and Secret and save settings first.', 'learnomy' ),
					array( 'status' => 400 )
				)
			);
		}

		$base_url = 'sandbox' === $mode
			? 'https://api-m.sandbox.paypal.com'
			: 'https://api-m.paypal.com';

		// Step 1 — Exchange client credentials for an access token.
		$token_response = wp_remote_post(
			$base_url . '/v1/oauth2/token',
			array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => 'grant_type=client_credentials',
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $token_response ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_paypal_connection_error',
					/* translators: %s: WP HTTP error message */
					sprintf( __( 'Could not reach PayPal: %s', 'learnomy' ), $token_response->get_error_message() ),
					array( 'status' => 502 )
				)
			);
		}

		$token_body        = json_decode( wp_remote_retrieve_body( $token_response ) );
		$token_status_code = (int) wp_remote_retrieve_response_code( $token_response );
		$access_token      = $token_body->access_token ?? '';

		if ( 200 !== $token_status_code || '' === $access_token ) {
			$api_message = $token_body->error_description
				?? $token_body->message
				?? __( 'PayPal rejected the credentials.', 'learnomy' );
			$hint        = 401 === $token_status_code
				? __( 'Check that the Client ID and Secret match and belong to the correct environment (sandbox vs live).', 'learnomy' )
				: '';
			return $this->error_response(
				new \WP_Error(
					'learnomy_paypal_auth_error',
					$hint ? $api_message . ' ' . $hint : $api_message,
					array( 'status' => 422 )
				)
			);
		}

		// Step 2 — Fetch the merchant's identity info.
		$me_response = wp_remote_get(
			$base_url . '/v1/identity/oauth2/userinfo?schema=paypalv1.1',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $me_response ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_paypal_connection_error',
					/* translators: %s: WP HTTP error message */
					sprintf( __( 'Token exchange succeeded but merchant lookup failed: %s', 'learnomy' ), $me_response->get_error_message() ),
					array( 'status' => 502 )
				)
			);
		}

		$me_body = json_decode( wp_remote_retrieve_body( $me_response ) );

		return $this->single_response(
			array(
				'provider'      => 'paypal',
				'mode_detected' => $mode,
				'merchant_id'   => $me_body->user_id ?? '',
				'email'         => $me_body->emails[0]->value ?? '',
			)
		);
	}

	/**
	 * Handles POST /learnomy/v1/admin/transactions/{id}/refund.
	 *
	 * Refunds the original payment via the gateway used to capture it, marks
	 * the existing transaction row `status = refunded`, and records a new
	 * negative-amount transaction with `transaction_type = refund` and
	 * `refund_of = original_id` so the ledger reconciles.
	 *
	 * Refunds are transaction-specific by policy. There is no bulk endpoint.
	 *
	 * @param WP_REST_Request $request Holds the {id} URL param.
	 * @return WP_REST_Response
	 */
	public function refund_transaction( WP_REST_Request $request ): WP_REST_Response {
		$id  = (int) $request->get_param( 'id' );
		$txn = \Learnomy\Models\Transaction::find( $id );

		if ( ! $txn ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_txn_not_found',
					__( 'Transaction not found.', 'learnomy' ),
					array( 'status' => 404 )
				)
			);
		}

		if ( 'refund' === ( $txn->transaction_type ?? '' ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_txn_is_refund',
					__( 'This row is itself a refund. Refund the original transaction instead.', 'learnomy' ),
					array( 'status' => 400 )
				)
			);
		}

		// FULLY-refunded guard. This asks "is there anything left?", not "has a refund
		// ever happened" — the old guard asked the latter (`find_refund_id()`), so the
		// second refund on a partially-refunded sale got a 409 and the remainder was
		// stranded forever. The amount field shipped; the guard behind it did not move.
		//
		// MUST run before the status check below: a refund flips the original away from
		// 'completed', so otherwise a repeat attempt would trip the status check first
		// and return a misleading 400 "not completed" instead of a truthful 409.
		$remaining = \Learnomy\Models\Transaction::refundable_remaining( $txn );
		if ( $remaining <= 0.0 ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_txn_already_refunded',
					__( 'This transaction has already been refunded in full.', 'learnomy' ),
					array( 'status' => 409 )
				)
			);
		}

		// 'partially_refunded' is refundable — that is the whole point of the remainder.
		if ( ! in_array( (string) ( $txn->status ?? '' ), array( 'completed', 'partially_refunded' ), true ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_txn_not_completed',
					/* translators: %s: status */
					sprintf( __( 'Only completed transactions can be refunded. Current status: %s', 'learnomy' ), (string) $txn->status ),
					array( 'status' => 400 )
				)
			);
		}

		$provider = (string) $txn->payment_provider;
		$adapter  = \Learnomy\Adapters\Adapter_Registry::get( $provider );
		if ( ! $adapter ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_no_adapter',
					/* translators: %s: provider */
					sprintf( __( 'No adapter registered for gateway: %s', 'learnomy' ), $provider ),
					array( 'status' => 400 )
				)
			);
		}

		$ptid = (string) $txn->provider_transaction_id;

		// Refund the whole REMAINDER unless the caller asks for less — not the whole
		// original charge, which on an already-partially-refunded sale would try to
		// return more money than is left and be rejected by the gateway.
		//
		// Validated against the remainder for the same reason: a typo (or a hostile
		// client) must not be able to refund past what is still owed.
		$requested = $request->get_param( 'amount' );
		$amount    = ( null === $requested || '' === $requested )
			? $remaining
			: round( (float) $requested, 2 );

		if ( $amount <= 0 || $amount > $remaining ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_refund_amount_invalid',
					sprintf(
						/* translators: %s: the amount still refundable on this transaction. */
						__( 'Refund amount must be greater than zero and no more than the amount still refundable (%s).', 'learnomy' ),
						\Learnomy\format_price( $remaining )
					),
					array( 'status' => 400 )
				)
			);
		}

		// Stripe checkout sessions (cs_*) cannot be refunded directly via the
		// /v1/refunds endpoint — it needs a payment_intent (pi_*) or charge (ch_*).
		// Resolve the session → payment_intent before handing off.
		if ( 'stripe' === $provider && 0 === strpos( $ptid, 'cs_' ) ) {
			$resolved = self::resolve_stripe_payment_intent( $ptid );
			if ( $resolved ) {
				$ptid = $resolved;
			}
		}

		$provider_refund_id = $adapter->refund( $ptid, $amount );

		if ( '' === $provider_refund_id ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_refund_failed',
					__( 'The gateway rejected the refund. Check the provider dashboard for details.', 'learnomy' ),
					array( 'status' => 502 )
				)
			);
		}

		// Reverse the ledger and revoke access through the shared service, so an
		// admin refund behaves exactly like a gateway-initiated one. We pass the
		// gateway's own refund id: the provider's echo webhook that follows this
		// call reconciles against the SAME id (Stripe/PayPal) or cumulative total
		// (Stripe), so it records nothing new. Passing an empty id here is what
		// let the echo double-record a partial refund (a $25 refund ledgered as
		// $50). apply() fires the canonical learnomy_payment_refunded action.
		( new \Learnomy\Services\Refund_Service() )->apply( $txn, $amount, $provider, $provider_refund_id );

		// Audit log for compliance — records which admin clicked refund.
		\Learnomy\Models\AuditLog::log(
			get_current_user_id(),
			'payment',
			'refund.admin',
			'transaction',
			(int) $txn->id,
			array(
				'amount'   => $amount,
				'currency' => (string) $txn->currency,
				'provider' => $provider,
			)
		);

		return $this->single_response(
			array(
				'refunded'        => true,
				'transaction_id'  => (int) $txn->id,
				'refund_amount'   => $amount,
				'refund_currency' => (string) $txn->currency,
			)
		);
	}

	/**
	 * Resolves a Stripe Checkout Session ID (cs_*) to its underlying
	 * payment_intent (pi_*). The /v1/refunds endpoint refuses cs_ inputs.
	 *
	 * Returns null on failure; caller falls back to the original ID and lets
	 * Stripe error meaningfully.
	 */
	private static function resolve_stripe_payment_intent( string $cs_id ): ?string {
		$settings = get_option( 'learnomy_settings', array() );
		$mode     = $settings['stripe_mode'] ?? 'test';
		$secret   = \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_' . $mode . '_secret' );
		if ( '' === $secret ) {
			return null;
		}

		$response = wp_remote_get(
			'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode( $cs_id ),
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $secret ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );
		return isset( $body->payment_intent ) && is_string( $body->payment_intent ) ? $body->payment_intent : null;
	}
}
