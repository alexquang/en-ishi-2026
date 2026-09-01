<?php
/**
 * Readers for the Stripe payload shapes that move between API versions.
 *
 * @package Learnomy
 */

declare(strict_types=1);

namespace Learnomy\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * One place that knows where a value lives in a Stripe object.
 *
 * Stripe's `2025-03-31.basil` release relocated three fields we depend on, and
 * it relocated them SILENTLY: the old key is simply absent, `??` falls through
 * to the default, and the handler returns early as if the event were not ours.
 * Nothing throws, nothing is logged, and the webhook is acknowledged with a
 * 200. That failure mode cost us every Stripe subscription purchase - the card
 * was charged, six webhooks arrived, and no subscription row was ever written.
 *
 *   invoice.subscription                 -> invoice.parent.subscription_details.subscription
 *   invoice.subscription_details.metadata-> invoice.parent.subscription_details.metadata
 *   subscription.current_period_end      -> subscription.items.data[].current_period_end
 *
 * Each reader below tries the current shape first, then the pre-Basil one, so a
 * site pinned to an older API version keeps working. Read through these rather
 * than reaching into the payload directly - a fourth relocation should be a
 * one-line change here, not another silent outage.
 *
 * @since 1.8.2
 */
final class Stripe_Payload {

	/**
	 * The subscription id an invoice belongs to.
	 *
	 * @param object $invoice Stripe Invoice payload.
	 * @return string Subscription id, or '' when the invoice is not for a subscription.
	 */
	public static function invoice_subscription_id( object $invoice ): string {
		$id = $invoice->parent->subscription_details->subscription // Basil and later.
			?? $invoice->subscription                              // Pre-Basil.
			?? '';

		// Either shape may arrive expanded into the full object.
		if ( is_object( $id ) ) {
			$id = $id->id ?? '';
		}

		return (string) $id;
	}

	/**
	 * The metadata of the subscription an invoice belongs to.
	 *
	 * This is where our `learnomy_user_id` / `learnomy_plan_id` ride, so an
	 * empty return means the invoice cannot be provisioned from the payload
	 * alone and the caller must fetch the subscription directly.
	 *
	 * @param object $invoice Stripe Invoice payload.
	 * @return object|null Metadata object, or null when absent.
	 */
	public static function invoice_subscription_metadata( object $invoice ): ?object {
		$meta = $invoice->parent->subscription_details->metadata // Basil and later.
			?? $invoice->subscription_details->metadata          // Pre-Basil.
			?? null;

		return is_object( $meta ) ? $meta : null;
	}

	/**
	 * The current billing period of a Stripe Subscription, as UNIX timestamps.
	 *
	 * Basil moved the period onto the subscription ITEM, because a subscription
	 * with items on different cadences has no single period. We bill one item
	 * per subscription, so the first item's period is the subscription's - but
	 * take the widest span across items rather than assuming, so a multi-item
	 * subscription cannot silently shorten someone's access.
	 *
	 * @param object $subscription Stripe Subscription payload.
	 * @return array{start:int,end:int} Zero for either bound that is unavailable.
	 */
	public static function subscription_period( object $subscription ): array {
		$start = (int) ( $subscription->current_period_start ?? 0 ); // Pre-Basil.
		$end   = (int) ( $subscription->current_period_end ?? 0 );

		$items = $subscription->items->data ?? array();
		foreach ( (array) $items as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			$item_start = (int) ( $item->current_period_start ?? 0 );
			$item_end   = (int) ( $item->current_period_end ?? 0 );

			if ( $item_start > 0 && ( 0 === $start || $item_start < $start ) ) {
				$start = $item_start;
			}
			if ( $item_end > $end ) {
				$end = $item_end;
			}
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * The end of a Stripe Subscription's current period, as a UNIX timestamp.
	 *
	 * @param object $subscription Stripe Subscription payload.
	 * @return int Timestamp, or 0 when unavailable.
	 */
	public static function subscription_period_end( object $subscription ): int {
		return self::subscription_period( $subscription )['end'];
	}

	/**
	 * The period an invoice BUYS, as UNIX timestamps.
	 *
	 * An invoice carries two different periods and the intuitive one is wrong.
	 * Measured against a real renewal (`billing_reason=subscription_cycle`):
	 *
	 *   invoice.period_start/end  02 Aug -> 02 Sep   the period that just ENDED
	 *   line.period.start/end     02 Sep -> 02 Oct   the period being PAID FOR
	 *
	 * Renewing from the invoice-level pair therefore sets the member's period
	 * end to the moment they were charged: they pay, and immediately read as
	 * expired. On the FIRST invoice the same field is worse still - start and
	 * end are the identical instant, a zero-length period - while the line
	 * carries the correct forward month. The line is right in both cases, so it
	 * is what we read.
	 *
	 * With proration, several lines can be present (a credit for the old period
	 * alongside the new one), so take the line reaching FURTHEST FORWARD - that
	 * is the period being bought - rather than the first or the widest.
	 *
	 * @param object $invoice Stripe Invoice payload, `lines` present.
	 * @return array{start:int,end:int} Zeros when the invoice carries no period.
	 */
	public static function invoice_billed_period( object $invoice ): array {
		$start = 0;
		$end   = 0;

		foreach ( (array) ( $invoice->lines->data ?? array() ) as $line ) {
			$line_end = (int) ( $line->period->end ?? 0 );
			if ( $line_end > $end ) {
				$end   = $line_end;
				$start = (int) ( $line->period->start ?? 0 );
			}
		}

		if ( $end > 0 ) {
			return array(
				'start' => $start,
				'end'   => $end,
			);
		}

		// No lines on the payload (an unexpanded or non-subscription invoice).
		// The invoice-level pair is all there is; a zero-length span is still
		// better than inventing one, and the caller decides what to do with it.
		return array(
			'start' => (int) ( $invoice->period_start ?? 0 ),
			'end'   => (int) ( $invoice->period_end ?? 0 ),
		);
	}

	/**
	 * The tax on an invoice, in the currency's MINOR unit.
	 *
	 * `invoice.tax` was a single integer. It is now `invoice.total_taxes`, a
	 * list — an invoice can carry several tax entries, and summing them is the
	 * only correct reading. Because the old key just disappears, every Stripe
	 * transaction we recorded after the account moved API versions stored tax
	 * as 0 while the buyer was charged it: the ledger under-reported tax and
	 * nothing failed.
	 *
	 * @param object $invoice Stripe Invoice payload.
	 * @return int Tax in minor units (0 when the invoice carries none).
	 */
	public static function invoice_tax( object $invoice ): int {
		if ( isset( $invoice->total_taxes ) && is_array( $invoice->total_taxes ) ) {
			$total = 0;
			foreach ( $invoice->total_taxes as $entry ) {
				$total += (int) ( is_object( $entry ) ? ( $entry->amount ?? 0 ) : 0 );
			}
			return $total;
		}

		return (int) ( $invoice->tax ?? 0 ); // Pre-Basil.
	}

	/**
	 * The most recent refund's own `re_*` id on a charge, when the payload
	 * carries it.
	 *
	 * `charge.refunds` is no longer included by default — it must be expanded,
	 * and a webhook payload does not expand it. That matters because the refund
	 * id is the DEDUP KEY for reconciling refunds: without it a dashboard refund
	 * and an admin refund cannot be told apart, which is what made refunds
	 * double-record before. An empty return here is not a failure, it is a
	 * signal that the caller must look the refund up.
	 *
	 * @param object $charge Stripe Charge payload.
	 * @return string Refund id, or '' when the payload does not carry one.
	 */
	public static function charge_refund_id( object $charge ): string {
		$refunds = $charge->refunds->data ?? array();
		if ( ! is_array( $refunds ) || empty( $refunds ) ) {
			return '';
		}

		return (string) ( $refunds[0]->id ?? '' );
	}

	/**
	 * The client secret to confirm a subscription's first payment with.
	 *
	 * Basil replaced the expanded `latest_invoice.payment_intent` with
	 * `latest_invoice.confirmation_secret`. Requesting the removed expand path
	 * is not an error at Stripe - it is ignored - so the only symptom is an
	 * empty secret and a Payment Element that never mounts.
	 *
	 * @param object $subscription Stripe Subscription payload, latest_invoice expanded.
	 * @return string Client secret, or '' when neither shape is present.
	 */
	public static function subscription_client_secret( object $subscription ): string {
		return (string) (
			$subscription->latest_invoice->confirmation_secret->client_secret // Basil and later.
			?? $subscription->latest_invoice->payment_intent->client_secret   // Pre-Basil.
			// Trialing subscription: the first invoice is $0 and carries no
			// payment to confirm — the card is collected through the pending
			// SetupIntent instead (expand[2] in open_subscription_intent).
			// Its secret starts `seti_`, which is how the JS knows to call
			// confirmSetup rather than confirmPayment.
			?? $subscription->pending_setup_intent->client_secret
			?? ''
		);
	}
}
