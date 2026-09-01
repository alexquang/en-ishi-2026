<?php
/**
 * The one place a paid purchase becomes a Learnomy transaction.
 *
 * @package Learnomy
 */

declare(strict_types=1);

namespace Learnomy\Services;

use Learnomy\Models\Transaction;

defined( 'ABSPATH' ) || exit;

/**
 * Records a completed purchase and fires the fulfilment events.
 *
 * Every gateway used to do this by hand. Twelve call sites across four
 * adapters, each re-deciding which fields to write, how to key idempotency,
 * where tax goes, and which actions to fire afterwards — and the differences
 * between them were not design, they were drift:
 *
 *   - `tax` was recorded in six of the twelve. The commission base is gross
 *     minus tax, so the other six paid the platform and the instructor out of
 *     money owed to the tax authority. That shipped on WooCommerce and on all
 *     three PayPal paths, and was fixed twice, separately, in one day.
 *   - `handle_checkout_completed()` contains TWO recording sites, one of which
 *     records tax and one of which does not.
 *   - Nothing tied recording to fulfilment. An adapter that wrote the row and
 *     forgot the action would take the money and provision nothing, silently.
 *
 * So the rules live here once: what a transaction row looks like, that the tax
 * recorded is only the tax INSIDE the amount, that the provider's id is the
 * idempotency key, and that recording and fulfilment are the same act.
 *
 * A gateway is left with the only thing it actually knows — what the provider
 * said happened.
 *
 * @since 1.8.2
 */
class Purchase_Recorder {

	/**
	 * Record a completed purchase and fire fulfilment.
	 *
	 * Idempotent on `provider_transaction_id`: a replayed webhook, a
	 * processing→completed transition, or a double-clicked return URL finds the
	 * existing row and fires nothing a second time.
	 *
	 * @param array $purchase {
	 *     @type int    $user_id        Buyer. Required.
	 *     @type string $gateway        Adapter id ('stripe', 'woocommerce', …). Required.
	 *     @type string $provider_id    The provider's durable id for this payment. Required.
	 *     @type float  $amount         What was charged, INCLUDING any tax inside it.
	 *     @type string $currency       ISO code.
	 *     @type string $type           Entity type ('course', 'membership', 'space', …).
	 *     @type int    $item_id        Entity id the purchase refers to.
	 *     @type float  $tax            The tax INSIDE `amount`. Never a tax the
	 *                                  gateway added on top of it.
	 *     @type int    $course_id      Optional explicit course id.
	 *     @type int    $plan_id        Optional explicit plan id.
	 *     @type int    $subscription_id Optional local subscription row id.
	 *     @type int    $coupon_id      Optional coupon used.
	 *     @type array  $meta           Extra metadata to merge into metadata_json.
	 *     @type array  $ext            Extension context passed to the fulfilment action.
	 *     @type string $action_type    Type announced to listeners, when it differs
	 *                                  from the stored one. A recurring course is
	 *                                  stored as `subscription_course` (the ledger
	 *                                  distinguishes it) but announced as `course`
	 *                                  (listeners do not, and treating it as an
	 *                                  unknown type would wrongly fire the
	 *                                  external-purchase shim). Defaults to `type`.
	 *     @type int    $action_item_id Item id announced to listeners. Defaults to `item_id`.
	 *     @type bool   $fulfil         Whether to fire the fulfilment events.
	 *                                  Defaults to true, because a purchase that
	 *                                  is recorded and not fulfilled is money
	 *                                  taken for nothing. Pass false ONLY for a
	 *                                  ledger row that represents no new
	 *                                  entitlement — a subscription renewal,
	 *                                  where access already exists and the
	 *                                  period was extended by the subscription
	 *                                  service. Announcing those as purchases
	 *                                  would re-run provisioning every cycle.
	 *     @type string $status         Defaults to 'completed'.
	 * }
	 * @return int Transaction id, or 0 when the purchase could not be recorded.
	 */
	public static function record( array $purchase ): int {
		$user_id     = (int) ( $purchase['user_id'] ?? 0 );
		$gateway     = sanitize_key( (string) ( $purchase['gateway'] ?? '' ) );
		$provider_id = (string) ( $purchase['provider_id'] ?? '' );

		if ( $user_id <= 0 || '' === $gateway || '' === $provider_id ) {
			return 0;
		}

		// The provider's id is the idempotency key. Returning the existing row
		// rather than 0 matters: a caller that treats "already recorded" as
		// failure would report a completed purchase as broken.
		$existing = Transaction::find_by_provider_id( $provider_id );
		if ( $existing ) {
			return (int) $existing->id;
		}

		$type    = sanitize_key( (string) ( $purchase['type'] ?? '' ) );
		$item_id = (int) ( $purchase['item_id'] ?? 0 );

		// The entity ids follow from the type unless the caller was explicit —
		// the gateways each had their own version of this ternary.
		$course_id = (int) ( $purchase['course_id'] ?? ( 'course' === $type ? $item_id : 0 ) );
		$plan_id   = (int) ( $purchase['plan_id'] ?? ( 'membership' === $type ? $item_id : 0 ) );

		// Tax rides in metadata because that is where the commission engine
		// reads it (Commission_Service::tax_from_transaction). Merged with any
		// caller metadata rather than overwriting it.
		$tax  = round( (float) ( $purchase['tax'] ?? 0 ), 2 );
		$meta = isset( $purchase['meta'] ) && is_array( $purchase['meta'] ) ? $purchase['meta'] : array();
		if ( $tax > 0 ) {
			$meta['tax'] = $tax;
		}

		// Freeze the billing address onto the order.
		//
		// The member's profile holds their CURRENT address; an invoice has to
		// show what was billed at the time. Someone who moves house next year
		// must not find last year's receipt silently rewritten to an address
		// they did not live at when they paid — the same split WooCommerce makes
		// between `billing_*` on the user and `_billing_*` on the order.
		//
		// Taken here rather than passed in by each adapter, so every gateway
		// gets it without knowing it exists, and a new one cannot forget.
		if ( empty( $meta['billing'] ) ) {
			$snapshot = Billing_Profile::snapshot( Billing_Profile::get( $user_id ) );
			if ( ! empty( $snapshot ) ) {
				$meta['billing'] = $snapshot;
			}
		}

		$txn_id = (int) Transaction::record(
			array(
				'user_id'                 => $user_id,
				'course_id'               => $course_id ?: null,
				'plan_id'                 => $plan_id ?: null,
				'subscription_id'         => (int) ( $purchase['subscription_id'] ?? 0 ) ?: null,
				'coupon_id'               => (int) ( $purchase['coupon_id'] ?? 0 ) ?: null,
				'amount'                  => round( (float) ( $purchase['amount'] ?? 0 ), 2 ),
				'currency'                => strtoupper( (string) ( $purchase['currency'] ?? 'USD' ) ),
				'status'                  => (string) ( $purchase['status'] ?? 'completed' ),
				'transaction_type'        => $type ?: 'course',
				'payment_provider'        => $gateway,
				'provider_transaction_id' => $provider_id,
				'metadata_json'           => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
			)
		);

		if ( $txn_id <= 0 ) {
			return 0;
		}

		if ( false === ( $purchase['fulfil'] ?? true ) ) {
			return $txn_id;
		}

		self::fire_fulfilment(
			$txn_id,
			$user_id,
			(string) ( $purchase['action_type'] ?? $type ),
			(int) ( $purchase['action_item_id'] ?? $item_id ?: ( $course_id ?: $plan_id ) ),
			$purchase,
			$gateway
		);

		return $txn_id;
	}

	/**
	 * Fire the fulfilment events for a recorded purchase.
	 *
	 * Recording and fulfilment are one act, so this is not optional and not the
	 * caller's to remember. Enrolment, subscriptions, spaces, gifts and the
	 * commission engine all listen to `learnomy_purchase_completed` and none of
	 * them know which gateway fired it.
	 *
	 * @param int    $txn_id   Recorded transaction id.
	 * @param int    $user_id  Buyer.
	 * @param string $type     Entity type.
	 * @param int    $item_id  Entity id.
	 * @param array  $purchase Original purchase array (for `ext`).
	 * @param string $gateway  Adapter id.
	 */
	private static function fire_fulfilment( int $txn_id, int $user_id, string $type, int $item_id, array $purchase, string $gateway ): void {
		$ext = isset( $purchase['ext'] ) && is_array( $purchase['ext'] ) ? $purchase['ext'] : array();

		/**
		 * A purchase completed and was recorded. THE canonical fulfilment event.
		 *
		 * @param int    $txn_id  Transaction id.
		 * @param int    $user_id Buyer.
		 * @param string $type    Entity type ('course', 'membership', 'space', …).
		 * @param int    $item_id Entity id.
		 * @param array  $ext     Extension context from the gateway metadata.
		 * @param string $gateway Adapter id.
		 */
		do_action( 'learnomy_purchase_completed', $txn_id, $user_id, $type, $item_id, $ext, $gateway );

		// Legacy shims. `external_purchase_completed` only ever fired for types
		// that are neither a course nor a membership, which is the condition
		// each adapter re-implemented inline.
		if ( '' !== $type && ! in_array( $type, array( 'course', 'membership' ), true ) ) {
			do_action( 'learnomy_external_purchase_completed', $txn_id, $user_id, $type, $ext, $gateway );
		}

		do_action( 'learnomy_payment_completed', $txn_id, $user_id, $gateway );
	}
}
