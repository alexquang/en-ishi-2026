<?php
/**
 * Test / sandbox payment gateway.
 *
 * Immediately succeeds every payment without hitting any external service.
 * Useful for development, automated testing, and demos. Only active when
 * learnomy_settings['enable_test_gateway'] is explicitly turned on — it will
 * never appear on a production site that hasn't opted in.
 *
 * @package Learnomy
 */

namespace Learnomy\Adapters;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\AuditLog;
use Learnomy\Models\Transaction;
use Learnomy\Services\Coupon_Service;
use Learnomy\Services\Purchase_Recorder;
use Learnomy\Services\Enrollment_Service;
use Learnomy\Services\Subscription_Service;

class Test_Adapter implements Payment_Adapter {

	public function get_id(): string {
		return 'test';
	}

	public function get_label(): string {
		return __( 'Test Gateway (Development Only)', 'learnomy' );
	}

	public function is_available(): bool {
		$settings = get_option( 'learnomy_settings', [] );
		return ! empty( $settings['enable_test_gateway'] );
	}

	/**
	 * @deprecated 2.3.0 Thin shim. Maps legacy course/plan args onto the
	 *             unified create_purchase_session(). Removed in v2.5.
	 */
	public function create_checkout( array $args ): array {
		return $this->create_purchase_session(
			$this->translate_legacy_args( $args, 'one_time' )
		);
	}

	/**
	 * @deprecated 2.3.0 Thin shim. See create_checkout().
	 */
	public function create_subscription( array $args ): array {
		return $this->create_purchase_session(
			$this->translate_legacy_args( $args, 'subscription' )
		);
	}

	/**
	 * Translate the legacy course_id/plan_id arg shape into the unified
	 * purchase_type/item_id shape so the old shims keep working.
	 */
	private function translate_legacy_args( array $args, string $mode ): array {
		$out = $args;
		if ( empty( $args['purchase_type'] ) ) {
			$plan_id   = (int) ( $args['plan_id'] ?? 0 );
			$course_id = (int) ( $args['course_id'] ?? 0 );
			if ( $plan_id ) {
				$out['purchase_type'] = 'membership';
				$out['item_id']       = $plan_id;
				$out['mode']          = $mode;
			} elseif ( $course_id ) {
				$out['purchase_type'] = 'course';
				$out['item_id']       = $course_id;
				$out['mode']          = $mode;
			}
		}
		if ( ! isset( $out['mode'] ) ) {
			$out['mode'] = $mode;
		}
		return $out;
	}

	/**
	 * Unified one-time + subscription entry point.
	 *
	 * The Test adapter records the payment synchronously, fires
	 * `learnomy_purchase_completed` (the canonical action listeners hook),
	 * and additionally fires:
	 *   - `learnomy_payment_completed` (legacy, kept one release as a shim)
	 *   - `learnomy_external_purchase_completed` when purchase_type is set
	 *     and the type is not 'course' or 'membership' (legacy, kept one
	 *     release; Pro Spaces still listens to this until it migrates)
	 *
	 * Returns the synthetic session_id appended to return_url so the
	 * thank-you page can resolve the transaction we just recorded.
	 */
	public function create_purchase_session( array $args ): array {
		// Shared normaliser: legacy arg shapes, plus Learnomy's tax on top of
		// Learnomy's price, so a rehearsal quotes the same total a real gateway
		// would charge.
		$args = Adapter_Registry::normalize_purchase_args( $args, $this );

		// Legacy callers (Free's checkout controller during the transition
		// window) still pass course_id / plan_id without setting
		// purchase_type. Normalise here so the rest of the method is
		// dual-shape clean.
		$purchase_type = isset( $args['purchase_type'] ) ? sanitize_key( (string) $args['purchase_type'] ) : '';
		$item_id       = (int) ( $args['item_id'] ?? 0 );
		if ( '' === $purchase_type ) {
			$legacy_plan   = (int) ( $args['plan_id'] ?? 0 );
			$legacy_course = (int) ( $args['course_id'] ?? 0 );
			if ( $legacy_plan ) {
				$purchase_type = 'membership';
				$item_id       = $legacy_plan;
			} elseif ( $legacy_course ) {
				$purchase_type = 'course';
				$item_id       = $legacy_course;
			}
		}

		$session_id = 'test_' . wp_generate_uuid4();
		$user_id    = (int) ( $args['user_id'] ?? 0 );
		$mode       = (string) ( $args['mode'] ?? 'one_time' );
		$coupon_id  = isset( $args['coupon_id'] ) ? (int) $args['coupon_id'] : 0;

		// Flatten the extra metadata once; it rides through the gateway on
		// real adapters but the Test adapter consumes it inline.
		$extra = array();
		foreach ( (array) ( $args['extra'] ?? $args['extra_metadata'] ?? array() ) as $meta_key => $meta_value ) {
			if ( is_scalar( $meta_value ) ) {
				$extra[ sanitize_key( (string) $meta_key ) ] = (string) $meta_value;
			}
		}

		if ( $user_id ) {
			$subscription_id      = null;
			$course_id_for_record = null;
			$plan_id_for_record   = null;

			if ( 'membership' === $purchase_type && $item_id ) {
				$plan_id_for_record = $item_id;
				// Carry the agreed cycle and the RECURRING amount (not the
				// coupon-discounted first period) onto the subscription row, so
				// the term and the renewal price recorded are the ones the buyer
				// signed up for.
				$subscription_id = ( new Subscription_Service() )->subscribe(
					$user_id,
					$item_id,
					'test',
					null,
					(string) ( $args['billing_cycle'] ?? '' ),
					isset( $args['amount'] ) ? (float) $args['amount'] : null
				);
			} elseif ( 'course' === $purchase_type && $item_id && 'subscription' === $mode ) {
				// Recurring-priced course: a SUBSCRIPTION sale, exactly as the
				// real adapters treat it (Stripe/PayPal/Woo each re-check
				// Course::is_recurring themselves). This adapter used to fall
				// through to the one-time branch and book lifetime access at
				// one period's price with no subscription row — so an owner
				// trialling recurring pricing on the Test gateway saw the
				// wrong business model working (Basecamp 10155999596).
				// subscribe_to_course() creates the subscription AND the
				// enrollment (source=subscription), so the enroll branch
				// below must not run for this sale.
				$course_id_for_record = $item_id;
				$subscription_id      = ( new Subscription_Service() )->subscribe_to_course(
					$user_id,
					$item_id,
					'test',
					$session_id,
					isset( $args['amount'] ) ? (float) $args['amount'] : null,
					(string) ( $args['billing_cycle'] ?? '' )
				);
			} elseif ( 'course' === $purchase_type && $item_id ) {
				$course_id_for_record = $item_id;
			}

			// SELL-02: the Test gateway takes no real money, so record the order
			// at $0 and tag it test_mode. Every revenue / earnings / payout sum
			// then excludes it by value (nothing to add), while the row still
			// exists so the buy -> enrol -> access journey is fully verifiable.
			// The intended price is kept in metadata for reference only. It is
			// the tax-inclusive total the normaliser produced — the figure a real
			// gateway would have charged — because a rehearsal that quotes a
			// number the buyer would never see is not a rehearsal.
			$intended_amount = round( (float) ( $args['amount'] ?? 0 ), 2 );

			$txn_id = Purchase_Recorder::record(
				array(
					'user_id'         => $user_id,
					'gateway'         => 'test',
					'provider_id'     => $session_id,
					'type'            => $purchase_type ?: 'course',
					'item_id'         => $item_id,
					'course_id'       => (int) $course_id_for_record,
					'plan_id'         => (int) $plan_id_for_record,
					'subscription_id' => (int) $subscription_id,
					'coupon_id'       => $coupon_id,
					// Zero by design — no money moved. `tax` stays 0 with it, so
					// nothing is deducted from a split that is already nothing.
					'amount'          => 0.0,
					'currency'        => strtoupper( $args['currency'] ?? 'USD' ),
					'meta'            => array(
						'test_mode'       => true,
						'intended_amount' => $intended_amount,
					),
					'ext'             => $extra,
				)
			);

			// Course path: enroll synchronously so the buyer lands on the
			// course already enrolled. 'purchase' is the canonical paid-enroll
			// source (the real Stripe/PayPal/Woo adapters all emit it). Those
			// three adapters have always reported an enroll refusal through
			// report_purchase_failure(); this one silently dropped the return,
			// so a completed Test order could write no enrollment while the
			// thank-you said "confirmed" (Basecamp 10155834186). A recurring
			// course sale skips this — subscribe_to_course() above already
			// enrolled with source=subscription.
			if ( 'course' === $purchase_type && $course_id_for_record && ! $subscription_id ) {
				$enroll_result = ( new Enrollment_Service() )->enroll( $user_id, $course_id_for_record, 'purchase' );
				if ( is_wp_error( $enroll_result ) || (int) $enroll_result <= 0 ) {
					Enrollment_Service::report_purchase_failure(
						$user_id,
						$course_id_for_record,
						$enroll_result,
						'test',
						array( 'transaction_id' => (int) $txn_id )
					);
				}
			}

			if ( $coupon_id && $txn_id ) {
				( new Coupon_Service() )->apply( $coupon_id, $user_id, (int) $txn_id );
			}

			// The completion actions fired here by hand; Purchase_Recorder fires
			// them as part of recording, because a row written without them is a
			// payment taken and nothing provisioned.
		}

		$return_url = (string) ( $args['return_url'] ?? home_url( '/' ) );
		if ( $return_url && false === strpos( $return_url, 'session=' ) ) {
			$return_url = add_query_arg( 'session', $session_id, $return_url );
		}

		return array(
			'redirect_url' => $return_url,
			'session_id'   => $session_id,
		);
	}

	public function cancel_subscription( string $provider_sub_id ): bool {
		return true;
	}

	/**
	 * The test gateway sends no real webhooks, so this is intentionally empty.
	 */
	public function process_webhook( \WP_REST_Request $request ): void {
		status_header( 200 );
	}

	/**
	 * The test gateway is synchronous — create_checkout() records the
	 * transaction and enrollment before returning the redirect URL. Any
	 * session id we issued was already "paid" by the time the buyer reads
	 * the thank-you page. There is no asynchronous webhook race to worry
	 * about, so this always returns 'paid'.
	 */
	public function verify_payment_status( string $session_id ): string {
		return '' === $session_id ? 'unknown' : 'paid';
	}

	/**
	 * Always succeeds. Logs the refund to the audit trail so tests can assert
	 * that refund logic was triggered.
	 */
	public function refund( string $transaction_id, float $amount ): string {
		AuditLog::log(
			0,
			'payment',
			'refund.test',
			'transaction',
			0,
			[
				'provider_transaction_id' => $transaction_id,
				'amount'                  => $amount,
				'gateway'                 => 'test',
			]
		);

		// A synthetic, unique refund id. The Test gateway echoes nothing, so this
		// is never reconciled against a webhook — it just keeps the ledger row
		// keyed like the real gateways instead of on an empty string.
		return 'test_re_' . uniqid( '', true );
	}
}
