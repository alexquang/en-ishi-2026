<?php
/**
 * Payment adapter contract — every gateway must implement this.
 *
 * @package Learnomy
 */

namespace Learnomy\Adapters;

defined( 'ABSPATH' ) || exit;

interface Payment_Adapter {

	/**
	 * Unique machine identifier for this gateway (e.g. 'stripe', 'paypal').
	 */
	public function get_id(): string;

	/**
	 * Human-readable gateway name shown in settings and order records.
	 */
	public function get_label(): string;

	/**
	 * Returns true when the gateway has valid API credentials configured.
	 * Used to filter out unconfigured gateways from the active list.
	 */
	public function is_available(): bool;

	/**
	 * Initiates a one-time payment checkout session.
	 *
	 * @deprecated 2.3.0 Use create_purchase_session() with mode='one_time'.
	 *             This stays for one release as a backward-compat shim.
	 *
	 * @param array $args Checkout parameters.
	 * @return array
	 */
	public function create_checkout( array $args ): array;

	/**
	 * Initiates a recurring billing subscription.
	 *
	 * @deprecated 2.3.0 Use create_purchase_session() with mode='subscription'.
	 *
	 * @param array $args Subscription parameters.
	 * @return array
	 */
	public function create_subscription( array $args ): array;

	/**
	 * Unified entry point for ANY sellable thing — courses, membership plans,
	 * spaces, and future product types all flow through this method. Replaces
	 * the historical create_checkout / create_subscription split (which forced
	 * each new product type to bolt onto whichever path was closest).
	 *
	 * The product type is a label, not a code branch — the adapter handles
	 * payment, the listener for `learnomy_purchase_completed` handles
	 * fulfillment. Adding a new product type means writing a listener; no
	 * adapter changes required.
	 *
	 * Required keys:
	 *   purchase_type string  — 'course' | 'membership' | 'space' | … (free-form)
	 *   item_id       int     — primary entity (course_id, plan_id, space_plan_id)
	 *   item_label    string  — human-readable label shown on the gateway page
	 *   amount        float   — total to charge in the chosen currency
	 *   currency      string  — ISO 4217, e.g. 'USD'
	 *   mode          string  — 'one_time' | 'subscription'
	 *   user_id       int     — buyer
	 *   return_url    string
	 *   cancel_url    string
	 *
	 * Optional keys:
	 *   interval      string  — 'month' | 'year' (only when mode='subscription')
	 *   price_id      string  — pre-stored provider Price ID (Memberships +
	 *                           Space Plans set this so we skip lazy-create)
	 *   coupon_id     int|null
	 *   extra         array<string,scalar> — product-type-specific metadata
	 *                           rides as gateway metadata; webhook re-emits
	 *                           it on the completion action so the fulfillment
	 *                           listener can read it (e.g. space_name).
	 *
	 * Returns:
	 *   redirect_url  string  — where to send the user
	 *   session_id    string  — provider's session / order identifier
	 *
	 * Returns an empty array on any failure.
	 *
	 * @since 2.3.0
	 *
	 * @param array $args
	 * @return array
	 */
	public function create_purchase_session( array $args ): array;

	/**
	 * Cancels an active subscription at the provider side.
	 *
	 * @param string $provider_sub_id The provider's subscription ID.
	 * @return bool True on success.
	 */
	public function cancel_subscription( string $provider_sub_id ): bool;

	/**
	 * Handles an incoming webhook or IPN from the payment provider.
	 * Responsible for verifying the payload signature and triggering
	 * the appropriate Learnomy actions (enrollment, renewal, expiry).
	 *
	 * @param \WP_REST_Request $request Incoming request from the provider.
	 */
	public function process_webhook( \WP_REST_Request $request ): void;

	/**
	 * Issues a full or partial refund for a completed transaction.
	 *
	 * Returns the gateway's own refund identifier so the ledger reversal can be
	 * deduped against the provider's echo webhook (which carries the same id).
	 * An empty string means the gateway rejected the refund — the id is the
	 * success signal, not a separate bool.
	 *
	 * @param string $transaction_id Provider-side charge or capture ID.
	 * @param float  $amount         Amount to refund.
	 * @return string The provider's refund id, or '' on failure.
	 */
	public function refund( string $transaction_id, float $amount ): string;

	/**
	 * Looks up the payment status of a provider session or order by its ID.
	 *
	 * Called from the post-purchase thank-you page when the buyer lands
	 * before the webhook has been delivered (or when the webhook race
	 * leaves the local lrn_transactions row missing). Hits the provider's
	 * REST API server-side so the page can decide whether to render the
	 * paid view, the pending "still processing" view, or the failed view.
	 *
	 * Return contract (every adapter MUST return one of these):
	 *   'paid'    — provider confirms money landed; safe to render success
	 *   'pending' — provider is still processing; render the polling view
	 *   'failed'  — provider rejected the payment; render the retry view
	 *   'unknown' — adapter cannot answer (no API call available, no
	 *               credentials configured); render the polling view
	 *
	 * @since 2.2.0
	 *
	 * @param string $session_id Provider session / order identifier
	 *                           (Stripe Checkout Session ID like cs_test_*,
	 *                           PayPal order ID, etc).
	 * @return string One of: 'paid', 'pending', 'failed', 'unknown'.
	 */
	public function verify_payment_status( string $session_id ): string;
}
