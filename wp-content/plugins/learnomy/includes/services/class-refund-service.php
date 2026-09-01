<?php
/**
 * Applies the local side effects of a payment refund.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

use Learnomy\DB\Tx;
use Learnomy\Models\Transaction;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Subscription;
use Learnomy\Models\Commission;

defined( 'ABSPATH' ) || exit;

/**
 * Reconciles a refund inside Learnomy after the money has already moved at
 * the gateway.
 *
 * Gateway-agnostic by design. Every refund entry point routes through here:
 * the admin REST action (`Payments_Admin_Controller::refund_transaction`) and
 * each gateway's inbound refund signal (`charge.refunded`,
 * `PAYMENT.CAPTURE.REFUNDED`, `woocommerce_order_refunded`). The adapters
 * themselves only talk to the provider API and return success; the ledger
 * reversal and access revocation live here so all four gateways behave
 * identically.
 */
class Refund_Service {

	/**
	 * Reconcile a refund: reverse the ledger, mark the original refunded, and
	 * (for a full refund) revoke the access it paid for.
	 *
	 * A transaction can be refunded more than once, up to the amount charged. Refund
	 * $25 of a $100 sale today and the other $75 stays claimable. It did not use to:
	 * the guard here was `find_refund_id()` — "does ANY refund row exist" — which
	 * answered "already refunded" to the second refund and stranded the remainder.
	 * That was invisible while a partial refund was impossible to issue, and became a
	 * real trap the moment the amount field shipped.
	 *
	 * Idempotency therefore can no longer mean "one refund per original". The race it
	 * has to survive is the admin action and the provider's echo webhook arriving for
	 * the SAME refund, and the thing that identifies that refund is the provider's own
	 * refund id — so that is the dedup key. A refund with no provider id (the Test
	 * gateway) cannot be deduped this way and does not need to be: nothing echoes it.
	 *
	 * @param object $original           The original (non-refund) transaction row.
	 * @param float  $amount             Refunded amount, positive.
	 * @param string $provider           Gateway slug (stripe / paypal / woocommerce / test).
	 * @param string $provider_refund_id Provider's refund identifier, when known.
	 * @return int The refund transaction row ID. 0 when this refund was already
	 *             recorded, or when there is nothing left to refund.
	 */
	public function apply( object $original, float $amount, string $provider, string $provider_refund_id = '' ): int {
		$amount = round( $amount, 2 );

		// Serialize concurrent refunds on this original. The remaining-check and
		// the insert have to be atomic: two refunds that both read "enough left"
		// before either writes would each pass the check and each insert, taking
		// the total past the charge (an admin double-click, or two partials
		// landing together). A row lock on the original makes the second caller
		// wait for the first to commit, then re-read the true remaining. v1.6.0
		// could not hit this — the old any-refund-exists guard blocked a second
		// refund outright; 1.7.0's partial feature made the remaining-check
		// load-bearing without locking it.
		//
		// Only the money math runs in here. The side effects below do their own
		// writes and must not hold the row lock while they run.
		[ $refund_id, $is_full_refund ] = Tx::run(
			function () use ( $original, $amount, $provider, $provider_refund_id ): array {
				Transaction::lock_for_update( (int) $original->id );

				// Same refund arriving twice (admin action + the gateway's echo webhook).
				if ( '' !== $provider_refund_id && Transaction::find_by_provider_id( $provider_refund_id ) ) {
					return array( 0, false );
				}

				$remaining = Transaction::refundable_remaining( $original );

				// Nothing left, or someone is trying to refund more than was ever
				// charged. The gateway would reject the latter, but we should not
				// have asked.
				if ( $amount <= 0.0 || $remaining <= 0.0 || $amount > $remaining + 0.01 ) {
					return array( 0, false );
				}

				// A refund row carries the refunded portion as a negative amount so the
				// revenue rollups net out, points back at the original via refund_of,
				// and stays `completed` (the money moved) — only the ORIGINAL flips to
				// `refunded`. When the provider gives us its own refund id we store it;
				// otherwise we synthesize a stable, unique handle.
				$ledger_ref = '' !== $provider_refund_id
					? $provider_refund_id
					: (string) $original->provider_transaction_id . '_refund_' . time();

				$refund_id = Transaction::record(
					array(
						'user_id'                 => (int) $original->user_id,
						'course_id'               => $original->course_id ? (int) $original->course_id : null,
						'plan_id'                 => $original->plan_id ? (int) $original->plan_id : null,
						'amount'                  => -1 * $amount,
						'currency'                => (string) $original->currency,
						'status'                  => 'completed',
						'transaction_type'        => 'refund',
						'payment_provider'        => $provider,
						'provider_transaction_id' => $ledger_ref,
						'refund_of'               => (int) $original->id,
					)
				);

				// Per Plan 00 D12: only a full refund revokes access. "Full" means the sale is
				// now refunded IN TOTAL — not that this one refund happened to equal the
				// charge. Refunding $25 and then $75 of a $100 sale is a full refund, and the
				// student must lose access at the end of it; measuring this single call against
				// the charge would miss that and leave them enrolled on a fully refunded order.
				// A 1-cent tolerance absorbs currency rounding on the gateway side.
				$refunded_total = Transaction::refunded_total( (int) $original->id );
				$is_full_refund = abs( $refunded_total - (float) $original->amount ) < 0.01;

				// Say which it was. This used to stamp 'refunded' unconditionally, which was
				// harmless only because a partial refund was impossible to issue — the route
				// took no amount and the one refund button always sent the full sum. Now that
				// partials are reachable, calling a 25%-refunded $100 sale "refunded" would
				// misreport revenue and hide the remaining 75% from the site owner.
				Transaction::update(
					(int) $original->id,
					array( 'status' => $is_full_refund ? 'refunded' : 'partially_refunded' )
				);

				return array( (int) $refund_id, $is_full_refund );
			}
		);

		// Nothing was recorded (duplicate, or nothing left to refund).
		if ( 0 === $refund_id ) {
			return 0;
		}

		if ( $is_full_refund ) {
			$this->revoke_access( $original );

			// Void the instructor commission earned on this sale — same
			// full-refund gate as access revocation (a partial refund leaves the
			// commission intact rather than clawing back the whole split over a
			// small partial). Gateway-agnostic: this single chokepoint means a
			// PayPal / WooCommerce / Test refund reverses the commission too, not
			// just Stripe Connect. Only not-yet-paid rows (pending/requested) flip
			// to `reversed`, which removes them from the withdrawable balance and
			// lifetime earnings; already-PAID commissions are clawed back
			// separately by Pro's Stripe-Connect Refund_Handler via
			// lrn_pro_payout_debts.
			Commission::reverse_for_transaction( (int) $original->id );
		}

		do_action( 'learnomy_payment_refunded', (int) $original->id, $amount, $provider, $is_full_refund );

		return (int) $refund_id;
	}

	/**
	 * Reconcile the ledger to a gateway's CUMULATIVE refunded total.
	 *
	 * Stripe's charge.refunded webhook reports `amount_refunded` as a running
	 * total for the charge, not the delta of the one refund that fired it.
	 * Recording that figure as a fresh refund row double-counts every earlier
	 * partial: a $25 admin refund plus the echo's cumulative $25 would ledger
	 * $50 — the money never left the customer twice, but our books say it did,
	 * which flips a partially-refunded sale to fully refunded, revokes access and
	 * reverses commission on money the buyer still owes us nothing back on.
	 *
	 * So record only the portion not already on the ledger. If an admin action
	 * already captured this refund, the delta is zero and this is a no-op — the
	 * idempotency the old any-refund-exists guard used to give, restored for
	 * partials. A dashboard-initiated refund (no admin action) reconciles to the
	 * new higher total exactly once.
	 *
	 * @param object $original            The original (non-refund) transaction row.
	 * @param float  $cumulative_refunded The gateway's running refunded total.
	 * @param string $provider            Gateway slug.
	 * @param string $provider_refund_id  The gateway's id for the latest refund.
	 * @return int The refund row id, or 0 when nothing new needed recording.
	 */
	public function reconcile_cumulative( object $original, float $cumulative_refunded, string $provider, string $provider_refund_id = '' ): int {
		$already = Transaction::refunded_total( (int) $original->id );
		$delta   = round( $cumulative_refunded - $already, 2 );

		// Already reconciled (admin action beat the echo, or a duplicate echo).
		if ( $delta <= 0.0 ) {
			return 0;
		}

		return $this->apply( $original, $delta, $provider, $provider_refund_id );
	}

	/**
	 * Revoke whatever the original transaction paid for — a course enrollment
	 * for a course purchase, or the matching subscription for a membership.
	 *
	 * A membership refund cancels immediately so the plan's enrollments are
	 * revoked rather than left to ride out the paid period the buyer just got
	 * their money back for.
	 *
	 * @param object $original The original transaction row.
	 */
	private function revoke_access( object $original ): void {
		if ( ! empty( $original->course_id ) ) {
			$enrollment = Enrollment::find_for_user_course( (int) $original->user_id, (int) $original->course_id );
			if ( $enrollment ) {
				( new Enrollment_Service() )->unenroll( (int) $enrollment->id );
			}
			return;
		}

		if ( ! empty( $original->plan_id ) ) {
			foreach ( Subscription::get_active_for_user( (int) $original->user_id ) as $sub ) {
				if ( (int) ( $sub->plan_id ?? 0 ) === (int) $original->plan_id ) {
					( new Subscription_Service() )->cancel( (int) $sub->id, true );
					return;
				}
			}
		}
	}
}
