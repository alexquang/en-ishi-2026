<?php
/**
 * Subscription lifecycle — subscribe, cancel, renew, expire, past-due.
 *
 * Free covers: trial → active → cancel-at-period-end → expire, plus past_due
 * marking so Pro's dunning extension has a hook. Plan change and proration
 * live in Pro.
 *
 * Every state transition fires a `learnomy_subscription_*` action so Pro
 * can extend behavior without modifying this file. Each hook carries a
 * trailing `$context_type` argument (always `'plan'` today; PR-A1 will
 * pass `'course'` for course-level recurring subscriptions). Existing
 * 3-arg listeners stay compatible — PHP's positional-args contract allows
 * listeners to declare fewer params than the action passes.
 *
 * History: renamed from `Membership_Service` in PR-0 (foundation refactor
 * 2026-05-18). The old class name is preserved via `class_alias` at the
 * bottom of this file and is marked `@deprecated`. The shim ships for one
 * release; remove on v1.2.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\MembershipPlanCourse;
use Learnomy\Models\Subscription;
use Learnomy\Adapters\Adapter_Registry;
use WP_Error;
use function Learnomy\now;

class Subscription_Service {

	/**
	 * Start a subscription. If the plan has `trial_days > 0` the subscription
	 * opens in `trialing` status and `trial_ends_at` is recorded; the first
	 * paid period starts when the trial converts (via `end_trial()`).
	 *
	 * The AGREED TERMS ARE PERSISTED HERE — `amount` and `billing_interval` are
	 * written onto the row at creation. Both columns have existed since 3.0.0 but
	 * nothing ever filled them, so every subscription in the table read NULL/NULL
	 * (Basecamp 10132808810). Without them the owner cannot reconstruct what a
	 * member agreed to pay, cannot grandfather anyone through a plan price change,
	 * and cannot audit a disputed charge — the plan's *current* price is the only
	 * figure left, and it is not the one the buyer signed up at.
	 *
	 * @param int         $user_id         User id.
	 * @param int         $plan_id         Plan id.
	 * @param string      $provider        Payment provider slug (stripe|paypal|manual).
	 * @param string|null $provider_sub_id External subscription ID (Stripe sub_*, PayPal BA-*).
	 * @param string      $billing_cycle   'monthly'|'yearly' — picks the matching price when the
	 *                                     plan carries both. Empty (the default) means "the plan's
	 *                                     own interval", which is what every gateway webhook wants.
	 * @param float|null  $amount          The recurring amount actually agreed, when the caller knows
	 *                                     the provider's real figure (money standard §2: invoice what
	 *                                     the provider charged, not what the plan costs). Null derives
	 *                                     it from the plan + cycle.
	 * @return int New subscription ID, or 0 on failure.
	 */
	public function subscribe(
		int $user_id,
		int $plan_id,
		string $provider,
		?string $provider_sub_id = null,
		string $billing_cycle = '',
		?float $amount = null
	): int {
		$plan = MembershipPlan::find( $plan_id );

		if ( ! $plan || 'active' !== $plan->status ) {
			return 0;
		}

		// One resolver for "what does this plan cost on this cycle" — the same
		// one the pricing card and the checkout controller read, so the recorded
		// amount cannot disagree with the displayed one.
		$terms          = MembershipPlan::price_for_cycle( $plan, $billing_cycle );
		$agreed_amount  = null !== $amount ? $amount : (float) $terms['amount'];
		$agreed_cycle   = (string) $terms['cycle'];

		// Free plans can be capped via option; default 0 = unlimited.
		if ( 0.0 === (float) $plan->price ) {
			$cap = (int) get_option( 'learnomy_free_plan_cap', 0 );
			if ( $cap > 0 ) {
				$sub_count = count( Subscription::get_for_plan( $plan_id ) );
				if ( $sub_count >= $cap ) {
					return 0;
				}
			}
		}

		$period_start = now();
		$trial_days   = (int) ( $plan->trial_days ?? 0 );
		$status       = $trial_days > 0 ? 'trialing' : 'active';

		// Paid period derives from the plan's interval — providers will
		// refine this via `renew()` on their own billing cadence.
		$period_end    = $this->compute_period_end( $plan, $period_start, $billing_cycle );
		$trial_ends_at = null;
		if ( $trial_days > 0 ) {
			$trial_ends_at = gmdate( 'Y-m-d H:i:s', strtotime( "+{$trial_days} days", strtotime( $period_start ) ) );
			// When trialing, current_period_end covers the trial window so
			// has_access() keeps granting access until end_trial() runs.
			$period_end = $trial_ends_at;
		}

		$subscription_id = Subscription::create(
			[
				'user_id'                  => $user_id,
				'plan_id'                  => $plan_id,
				'status'                   => $status,
				'payment_provider'         => $provider,
				'provider_subscription_id' => $provider_sub_id,
				'amount'                   => $agreed_amount,
				'billing_interval'         => $agreed_cycle,
				'current_period_start'     => $period_start,
				'current_period_end'       => $period_end,
				'trial_ends_at'            => $trial_ends_at,
			]
		);

		if ( ! $subscription_id ) {
			return 0;
		}

		// Atomic plan switch: a new plan subscription supersedes any the user
		// already holds, instead of stacking a second (or third) concurrently
		// billing membership. Without this, "Switch to this" / a repeat checkout
		// left every prior subscription active and billing — customers ended up
		// with 2-3 live subscriptions and no way to tell which they wanted. Runs
		// AFTER the new row exists so a failure can never leave the user with
		// none. A normal first-time subscribe has no prior actives, so this is a
		// no-op in the common case.
		$this->supersede_other_plan_subscriptions( $user_id, $subscription_id );

		$this->enroll_plan_courses( $user_id, $plan_id, $subscription_id );

		do_action( 'learnomy_subscription_created', $subscription_id, $user_id, $plan_id, 'plan' );

		/**
		 * Plan-context subscription created.
		 *
		 * @deprecated Use `learnomy_subscription_created`, which fires for course
		 * subscriptions too and carries the context as a 4th argument. This one is
		 * still fired for pre-1.0.0 listeners and is not scheduled for removal.
		 *
		 * @param int $subscription_id Subscription row id.
		 * @param int $user_id         Subscriber.
		 * @param int $plan_id         Membership plan.
		 */
		do_action( 'learnomy_membership_created', $subscription_id, $user_id, $plan_id );

		return $subscription_id;
	}

	/**
	 * End every OTHER active plan subscription for a user when a new one starts,
	 * so a plan change supersedes rather than stacks. Cancels each prior sub at
	 * the provider first (stop billing), then immediately in our DB. Course-level
	 * recurring subs (plan_id NULL) are left untouched — they are independent
	 * products, not memberships. Filterable off for sites that intentionally
	 * allow multiple concurrent memberships.
	 *
	 * @param int $user_id           The subscriber.
	 * @param int $keep_subscription Subscription id to preserve (the new one).
	 */
	private function supersede_other_plan_subscriptions( int $user_id, int $keep_subscription ): void {
		/**
		 * Allow multiple concurrent plan subscriptions per user. Default false:
		 * a new plan supersedes the old.
		 *
		 * @param bool $allow Whether to keep prior plan subs active.
		 */
		if ( apply_filters( 'learnomy_allow_multiple_plan_subscriptions', false ) ) {
			return;
		}

		foreach ( Subscription::get_active_for_user( $user_id ) as $sub ) {
			$sub_id = (int) $sub->id;
			if ( $sub_id === $keep_subscription || null === ( $sub->plan_id ?? null ) ) {
				continue; // The new sub, or a course-level recurring sub.
			}

			// Stop billing at the provider before we drop local access, so a
			// cancelled-locally-but-still-charging gap can't open. Best-effort:
			// a provider error must not block superseding locally.
			if ( ! empty( $sub->provider_subscription_id ) && ! empty( $sub->payment_provider ) ) {
				$adapter = Adapter_Registry::get( (string) $sub->payment_provider );
				if ( $adapter && method_exists( $adapter, 'cancel_subscription' ) ) {
					try {
						$adapter->cancel_subscription( (string) $sub->provider_subscription_id );
					} catch ( \Throwable $e ) {
						\Learnomy\Models\AuditLog::log(
							$user_id,
							'subscription',
							'supersede_provider_cancel_failed',
							'subscription',
							$sub_id,
							array(
								'provider' => (string) $sub->payment_provider,
								'error'    => $e->getMessage(),
							)
						);
					}
				}
			}

			// Immediate local cancel — the replacement sub already grants access.
			$this->cancel( $sub_id, true );
		}
	}

	/**
	 * Start a course-level recurring subscription. This is the sibling of
	 * subscribe() for the new `recurring` pricing model — a single course
	 * is sold on a renewing cadence (monthly or yearly) and the resulting
	 * subscription row carries `course_id` instead of `plan_id`.
	 *
	 * The accompanying enrollment row is created with source='subscription'
	 * and `subscription_id` linking back here, so the billing page can
	 * join enrollment ↔ subscription ↔ transaction in a single query.
	 *
	 * @param int         $user_id         User id.
	 * @param int         $course_id       Course id (must have pricing_model='recurring').
	 * @param string      $provider        Payment provider slug.
	 * @param string|null $provider_sub_id External subscription ID.
	 * @param float|null  $amount          Recurring amount actually agreed, when the caller
	 *                                     knows the provider's figure. Null derives it from
	 *                                     the course's `recurring_amount`.
	 * @return int New subscription ID, or 0 on failure.
	 */
	public function subscribe_to_course(
		int $user_id,
		int $course_id,
		string $provider,
		?string $provider_sub_id = null,
		?float $amount = null
	): int {
		$course = Course::find( $course_id );

		if ( ! $course || 'published' !== ( $course->status ?? 'draft' ) ) {
			return 0;
		}

		if ( ! Course::is_recurring( $course ) ) {
			return 0;
		}

		$interval = Course::recurring_interval( $course ) ?? 'month';
		$unit_map = array(
			'month' => 'month',
			'year'  => 'year',
		);
		$unit     = $unit_map[ $interval ] ?? 'month';

		$period_start = now();
		$period_end   = gmdate( 'Y-m-d H:i:s', strtotime( "+1 {$unit}", strtotime( $period_start ) ) );

		// Same rule as plan subscriptions: record what the member agreed to pay
		// and on what cadence (Basecamp 10132808810). The course's recurring
		// price is the agreed figure unless the caller knows the provider's.
		$agreed_amount = null !== $amount
			? $amount
			: (float) ( Course::recurring_amount( $course ) ?? 0.0 );

		$subscription_id = Subscription::create(
			array(
				'user_id'                  => $user_id,
				'course_id'                => $course_id,
				'plan_id'                  => null,
				'status'                   => 'active',
				'payment_provider'         => $provider,
				'provider_subscription_id' => $provider_sub_id,
				'amount'                   => $agreed_amount,
				'billing_interval'         => 'year' === $unit ? 'yearly' : 'monthly',
				'current_period_start'     => $period_start,
				'current_period_end'       => $period_end,
			)
		);

		if ( ! $subscription_id ) {
			return 0;
		}

		// Create the course enrollment with a `subscription_id` link so the
		// billing page renders the membership-style row alongside membership
		// plan subs.
		( new Enrollment_Service() )->enroll(
			$user_id,
			$course_id,
			'subscription',
			array( 'subscription_id' => $subscription_id )
		);

		// 4th arg is the target id (course_id for course-level, plan_id for
		// plan-level). Reading the 4th arg blindly as `plan_id` was the
		// PR-0 contract; the 5th arg disambiguates plan vs course so
		// listeners that care can branch.
		do_action( 'learnomy_subscription_created', $subscription_id, $user_id, $course_id, 'course' );

		return $subscription_id;
	}

	/**
	 * Convert a trial to an active paid subscription. Called by payment
	 * webhooks when the first real charge lands, or by an Action Scheduler
	 * job when `trial_ends_at` passes.
	 */
	public function end_trial( int $subscription_id ): bool {
		$sub = Subscription::find( $subscription_id );
		if ( ! $sub || 'trialing' !== $sub->status ) {
			return false;
		}

		$plan = MembershipPlan::find( (int) $sub->plan_id );
		if ( ! $plan ) {
			return false;
		}

		// Convert on the cadence the member actually bought, not the plan's
		// default — a yearly signup that trials must resume as yearly. Rows
		// created before 1.8.1 carry NULL here and fall back to the plan's own
		// interval, which is exactly the pre-fix behaviour.
		$new_period_start = now();
		$new_period_end   = $this->compute_period_end( $plan, $new_period_start, (string) ( $sub->billing_interval ?? '' ) );

		$ok = Subscription::update(
			$subscription_id,
			[
				'status'               => 'active',
				'current_period_start' => $new_period_start,
				'current_period_end'   => $new_period_end,
			]
		);

		if ( $ok ) {
			do_action( 'learnomy_subscription_trial_ended', $subscription_id, (int) $sub->user_id, (int) $sub->plan_id, 'plan' );
		}

		return $ok;
	}

	/**
	 * Cancel a subscription. Default is "cancel at period end" — the user
	 * keeps access until their paid period runs out. Pass `$immediate = true`
	 * for abuse / fraud cases where access must revoke now.
	 */
	public function cancel( int $subscription_id, bool $immediate = false ): bool {
		$sub = Subscription::find( $subscription_id );
		if ( ! $sub ) {
			return false;
		}

		$is_course_sub = null !== $sub->course_id && null === $sub->plan_id;
		$target_id     = $is_course_sub ? (int) $sub->course_id : (int) $sub->plan_id;
		$context_type  = $is_course_sub ? 'course' : 'plan';

		if ( $immediate ) {
			$updated = Subscription::cancel( $subscription_id );
			if ( $updated ) {
				if ( $is_course_sub ) {
					$this->revoke_course_enrollment( (int) $sub->user_id, (int) $sub->course_id );
				} else {
					$this->revoke_plan_enrollments( (int) $sub->user_id, (int) $sub->plan_id );
				}
			}
		} else {
			$updated = Subscription::flag_cancel_at_period_end( $subscription_id );
		}

		if ( $updated ) {
			do_action(
				'learnomy_subscription_cancelled',
				$subscription_id,
				(int) $sub->user_id,
				$target_id,
				$immediate,
				$context_type
			);

			if ( ! $is_course_sub ) {
				/**
				 * Plan-context subscription cancelled.
				 *
				 * @deprecated Use `learnomy_subscription_cancelled` above. This
				 * one fires ONLY for plan-context subscriptions, so a listener
				 * here silently misses every course-subscription cancellation —
				 * which is exactly how the `subscription.cancelled` webhook came
				 * to under-report until 2026-08-15. Still fired for pre-1.0.0
				 * listeners (Pro's WP Fusion tag service is one) and not
				 * scheduled for removal.
				 *
				 * @param int $subscription_id Subscription row id.
				 * @param int $user_id         Subscriber.
				 * @param int $plan_id         Membership plan.
				 */
				do_action( 'learnomy_membership_cancelled', $subscription_id, (int) $sub->user_id, (int) $sub->plan_id );
			}
		}

		return $updated;
	}

	/**
	 * Reverse a pending "cancel at period end" flag. Only valid while the
	 * subscription is still live (hasn't hit expire yet).
	 */
	public function resume( int $subscription_id ): bool {
		$sub = Subscription::find( $subscription_id );
		if ( ! $sub || 1 !== (int) $sub->cancel_at_period_end ) {
			return false;
		}
		if ( ! in_array( $sub->status, [ 'active', 'trialing', 'past_due' ], true ) ) {
			return false;
		}

		$ok = Subscription::resume( $subscription_id );
		if ( $ok ) {
			do_action( 'learnomy_subscription_resumed', $subscription_id, (int) $sub->user_id, (int) $sub->plan_id, 'plan' );
		}
		return $ok;
	}

	/**
	 * Mark a subscription paused — the provider has already paused billing on
	 * its side (Stripe `customer.subscription.updated` with `status=paused`
	 * is what arrives here), so this only mirrors the state locally and
	 * stamps `paused_at`. Idempotent: a row already in `paused` returns true
	 * without re-firing the hook or re-writing the timestamp.
	 *
	 * Does NOT call the provider — the webhook arrives FROM the provider,
	 * and re-issuing a pause request would create a feedback loop.
	 *
	 * @param object $sub     Subscription row (fresh `Subscription::find()`
	 *                       result, not just an ID — caller already loaded it
	 *                       to decide whether to call us).
	 * @param array  $context Optional diagnostic context passed through to
	 *                       listeners (`source`, `event_id`, etc).
	 * @return bool|WP_Error  True on success or idempotent no-op; WP_Error
	 *                       on DB failure.
	 */
	public function pause( object $sub, array $context = [] ) {
		$subscription_id = (int) ( $sub->id ?? 0 );
		if ( $subscription_id <= 0 ) {
			return new WP_Error( 'learnomy_subscription_invalid', __( 'Subscription is missing an ID.', 'learnomy' ) );
		}

		// Idempotent: re-pausing an already-paused row is a no-op. We DO NOT
		// re-fire the hook in that case — listeners should fire once per real
		// transition, not once per duplicate webhook delivery.
		if ( 'paused' === (string) ( $sub->status ?? '' ) ) {
			return true;
		}

		$ok = Subscription::update(
			$subscription_id,
			[
				'status'    => 'paused',
				'paused_at' => now(),
			]
		);

		if ( ! $ok ) {
			return new WP_Error(
				'learnomy_subscription_pause_failed',
				/* translators: %d: subscription ID. */
				sprintf( __( 'Failed to mark subscription %d as paused.', 'learnomy' ), $subscription_id )
			);
		}

		/**
		 * Fires after a subscription is marked paused. THE one place this fires:
		 * every pause, whether it came from a provider webhook (here) or from a
		 * member clicking Pause (Pro's Pause_Service, which routes its state write
		 * through this method), lands on this hook with this signature.
		 *
		 * The docblock used to claim it "mirrors the shape of
		 * learnomy_subscription_resumed" while firing `($id, $context)` against
		 * that hook's `($id, $user_id, $plan_id, $type)` — and Pro fired a THIRD
		 * shape, `($id, $user_id, $plan_id)`. So argument 2 was an array or an int
		 * depending on who paused the subscription, and no listener could be
		 * written that survived both. Now it genuinely mirrors `resumed`.
		 *
		 * @since 3.0.0
		 * @since 1.7.0 Signature aligned with `learnomy_subscription_resumed`.
		 *              Was `($subscription_id, array $context)`.
		 *
		 * @param int    $subscription_id Local subscription row ID.
		 * @param int    $user_id         Subscriber.
		 * @param int    $plan_id         Plan the subscription is on.
		 * @param string $context_type    What the subscription is for: 'plan' (a
		 *                                membership) or 'course'.
		 */
		do_action(
			'learnomy_subscription_paused',
			$subscription_id,
			(int) ( $sub->user_id ?? 0 ),
			(int) ( $sub->plan_id ?? 0 ),
			(string) ( $context['context_type'] ?? 'plan' )
		);

		return true;
	}

	/**
	 * Record a plan / price change on a subscription. Called when the provider
	 * notifies us that the billed price has changed — typically the customer
	 * upgraded or downgraded via the provider's billing portal. We only update
	 * the local row to reflect the new price; the provider has already
	 * applied proration on its side.
	 *
	 * Idempotent: if `provider_price_id` already matches `$new_price_id` the
	 * call returns true without re-firing the hook.
	 *
	 * Does NOT call the provider — the webhook arrives FROM the provider.
	 *
	 * @param object      $sub           Subscription row.
	 * @param string      $new_price_id  Provider price/plan identifier
	 *                                   (Stripe `price_xxx`, PayPal `P-xxx`).
	 * @param float|null  $new_amount    New recurring amount in plan currency,
	 *                                   when the provider sent it.
	 * @param string|null $new_interval  New billing cadence (monthly|yearly|…),
	 *                                   when the provider sent it.
	 * @param array       $context       Optional diagnostic context.
	 * @return bool|WP_Error             True on success or idempotent no-op;
	 *                                   WP_Error on invalid input or DB
	 *                                   failure.
	 */
	public function change_plan( object $sub, string $new_price_id, ?float $new_amount = null, ?string $new_interval = null, array $context = [] ) {
		$subscription_id = (int) ( $sub->id ?? 0 );
		if ( $subscription_id <= 0 ) {
			return new WP_Error( 'learnomy_subscription_invalid', __( 'Subscription is missing an ID.', 'learnomy' ) );
		}

		if ( '' === $new_price_id ) {
			return new WP_Error( 'learnomy_subscription_price_id_empty', __( 'A non-empty provider price ID is required.', 'learnomy' ) );
		}

		$old_price_id = (string) ( $sub->provider_price_id ?? '' );

		// Idempotent: re-applying the same price ID is a no-op.
		if ( $old_price_id === $new_price_id ) {
			return true;
		}

		$updates = [ 'provider_price_id' => $new_price_id ];
		if ( null !== $new_amount ) {
			$updates['amount'] = $new_amount;
		}
		if ( null !== $new_interval && '' !== $new_interval ) {
			$updates['billing_interval'] = $new_interval;
		}

		$ok = Subscription::update( $subscription_id, $updates );
		if ( ! $ok ) {
			return new WP_Error(
				'learnomy_subscription_change_plan_failed',
				/* translators: %d: subscription ID. */
				sprintf( __( 'Failed to record plan change on subscription %d.', 'learnomy' ), $subscription_id )
			);
		}

		/**
		 * Fires after a subscription's billed price changes. Pro extensions
		 * hook this to recompute access entitlements (a plan-change can
		 * swap which courses the subscription unlocks), update receipts,
		 * and re-run the engagement-share commission split for the new tier.
		 *
		 * @since 3.0.0
		 *
		 * @param int    $subscription_id Local subscription row ID.
		 * @param string $old_price_id    Previous provider price ID (may be empty).
		 * @param string $new_price_id    Newly active provider price ID.
		 * @param array  $context         Diagnostic context (`source`, `event_id`, …).
		 */
		do_action( 'learnomy_subscription_plan_changed', $subscription_id, $old_price_id, $new_price_id, $context );

		return true;
	}

	public function renew( int $subscription_id, string $new_period_start, string $new_period_end ): bool {
		$sub = Subscription::find( $subscription_id );
		if ( ! $sub ) {
			return false;
		}

		$updated = Subscription::update(
			$subscription_id,
			[
				'status'               => 'active',
				'current_period_start' => $new_period_start,
				'current_period_end'   => $new_period_end,
			]
		);

		if ( $updated ) {
			do_action( 'learnomy_subscription_renewed', $subscription_id, (int) $sub->user_id, (int) $sub->plan_id, 'plan' );

			/**
			 * Plan-context subscription renewed.
			 *
			 * @deprecated Use `learnomy_subscription_renewed`. Still fired for
			 * pre-1.0.0 listeners; not scheduled for removal.
			 *
			 * @param int $subscription_id Subscription row id.
			 * @param int $user_id         Subscriber.
			 * @param int $plan_id         Membership plan.
			 */
			do_action( 'learnomy_membership_renewed', $subscription_id, (int) $sub->user_id, (int) $sub->plan_id );
		}

		return $updated;
	}

	/**
	 * Mark a subscription past_due after a failed payment. Free records the
	 * status and emits a hook; Pro's dunning extension listens to schedule
	 * retries.
	 */
	public function mark_past_due( int $subscription_id, string $failure_reason = '' ): bool {
		$sub = Subscription::find( $subscription_id );
		if ( ! $sub ) {
			return false;
		}

		$ok = Subscription::update( $subscription_id, [ 'status' => 'past_due' ] );
		if ( $ok ) {
			do_action(
				'learnomy_subscription_past_due',
				$subscription_id,
				(int) $sub->user_id,
				(int) $sub->plan_id,
				$failure_reason,
				'plan'
			);
		}
		return $ok;
	}

	public function expire( int $subscription_id ): void {
		$sub = Subscription::find( $subscription_id );
		if ( ! $sub ) {
			return;
		}

		$is_course_sub = null !== $sub->course_id && null === $sub->plan_id;
		$target_id     = $is_course_sub ? (int) $sub->course_id : (int) $sub->plan_id;
		$context_type  = $is_course_sub ? 'course' : 'plan';

		Subscription::update( $subscription_id, [ 'status' => 'expired' ] );

		if ( $is_course_sub ) {
			$this->revoke_course_enrollment( (int) $sub->user_id, (int) $sub->course_id );
		} else {
			$this->revoke_plan_enrollments( (int) $sub->user_id, (int) $sub->plan_id );
		}

		do_action( 'learnomy_subscription_expired', $subscription_id, (int) $sub->user_id, $target_id, $context_type );

		// Legacy hook — only meaningful for plan-context subs.
		if ( ! $is_course_sub ) {
			/**
			 * Plan-context subscription expired.
			 *
			 * @deprecated Use `learnomy_subscription_expired`. Still fired for
			 * pre-1.0.0 listeners; not scheduled for removal.
			 *
			 * @param int $subscription_id Subscription row id.
			 * @param int $user_id         Subscriber.
			 * @param int $plan_id         Membership plan.
			 */
			do_action( 'learnomy_membership_expired', $subscription_id, (int) $sub->user_id, (int) $sub->plan_id );
		}
	}

	public function has_access( int $user_id, int $course_id ): bool {
		// Course-level recurring subscription — a single subscription row
		// targets one course directly. Check this first; if a customer has
		// an active per-course sub, the membership-plan lookup is moot.
		if ( Subscription::find_active_for_user_course( $user_id, $course_id ) ) {
			$access      = true;
			$active_subs = Subscription::get_active_for_user( $user_id );
		} else {
			$active_subs = Subscription::get_active_for_user( $user_id );
			// ONE plan-coverage test, shared with matched_plan_id() below, so
			// "is this member entitled" and "which plan entitled them" can never
			// answer differently.
			$access = null !== $this->matched_plan_id( $user_id, $course_id, $active_subs );
		}

		/**
		 * Let Pro (Spaces, entitlement caps, geographic restrictions) enhance
		 * the access decision without replacing this method.
		 *
		 * @param bool  $access    Whether the base membership rule grants access.
		 * @param int   $user_id   User being checked.
		 * @param int   $course_id Course being checked.
		 * @param array $active_subs Subscriptions considered.
		 */
		return (bool) apply_filters( 'learnomy_membership_has_access', $access, $user_id, $course_id, $active_subs );
	}

	/**
	 * WHICH membership plan currently entitles this user to this course.
	 *
	 * The companion to {@see has_access()} — same coverage test, but it returns the
	 * matching plan id instead of a yes/no, so an enrollment can record what granted
	 * it. Without this, every membership enrollment stored `plan_id = NULL` and the
	 * owner could not answer "why does this person have this course?" or audit a
	 * revocation (Basecamp 10134139611).
	 *
	 * Returns NULL when nothing plan-shaped is responsible. That is an honest
	 * answer, not a failure: a course-level recurring subscription has no plan, and
	 * a Pro extension granting access through `learnomy_membership_has_access`
	 * (Spaces, entitlement caps) is not a plan either. `plan_id IS NULL` on a
	 * membership row therefore reads as "granted by something other than a plan",
	 * never as "we forgot to write it".
	 *
	 * Deterministic when several of the member's plans cover the same course: the
	 * subscriptions are already ordered newest-first by
	 * {@see Subscription::get_active_for_user()}, so the plan they most recently
	 * signed up for wins — the one they would name if asked.
	 *
	 * @param int           $user_id     Member.
	 * @param int           $course_id   Course.
	 * @param object[]|null $active_subs Pre-fetched active subscriptions, to save a
	 *                                   query when the caller already has them.
	 * @return int|null Plan id that covers the course, or NULL.
	 */
	/**
	 * Batch form of {@see has_access()} for a SET of courses.
	 *
	 * `has_access()` costs two queries per course - the member's active
	 * subscriptions, which is the same answer every time, and the plans covering
	 * that one course. Asking it per card turns a catalog grid into an N+1, which
	 * is why the card had no way to know a member already owned what it was
	 * showing them a price for (Basecamp 10207864281).
	 *
	 * Resolves both once: one subscriptions lookup, one coverage lookup for every
	 * course id. The per-course decision is then the same `in_array` test
	 * `matched_plan_id()` runs.
	 *
	 * Crucially it STILL applies `learnomy_membership_has_access` per course, so
	 * Pro's Spaces, entitlement caps and geographic restrictions get their say
	 * exactly as they do on the single-course page. A batch that answered from
	 * SQL alone would be faster and would quietly disagree with the course page
	 * on any site running those features - which is the bug class this card is
	 * an instance of, not a shortcut worth taking.
	 *
	 * Note this answers ENTITLEMENT only. It does not ask whether the member is
	 * already enrolled; the caller pairs it with the enrollment map, because
	 * "covered by your plan" and "already claimed" are different states.
	 *
	 * @param int   $user_id    Member.
	 * @param int[] $course_ids Courses to test.
	 * @return array<int,bool> course_id => whether the plan covers it.
	 */
	public function has_access_map( int $user_id, array $course_ids ): array {
		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		if ( $user_id <= 0 || ! $course_ids ) {
			return array();
		}

		$active_subs = Subscription::get_active_for_user( $user_id );
		$plan_map    = $active_subs ? MembershipPlanCourse::get_plans_for_courses( $course_ids ) : array();

		$held_plan_ids = array();
		foreach ( $active_subs as $sub ) {
			$plan_id = (int) ( $sub->plan_id ?? 0 );
			if ( $plan_id > 0 ) {
				$held_plan_ids[] = $plan_id;
			}
		}

		$out = array();
		foreach ( $course_ids as $course_id ) {
			$access = false;

			if ( $held_plan_ids ) {
				$covering = $plan_map[ $course_id ] ?? array();
				$access   = (bool) array_intersect( $held_plan_ids, $covering );
			}

			// A per-course recurring subscription entitles on its own, exactly as
			// in has_access(). Only consulted when the plan test failed, so the
			// common case stays at zero extra queries.
			if ( ! $access && Subscription::find_active_for_user_course( $user_id, $course_id ) ) {
				$access = true;
			}

			/** This filter is documented in includes/services/class-subscription-service.php */
			$out[ $course_id ] = (bool) apply_filters( 'learnomy_membership_has_access', $access, $user_id, $course_id, $active_subs );
		}

		return $out;
	}

	public function matched_plan_id( int $user_id, int $course_id, ?array $active_subs = null ): ?int {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return null;
		}

		$active_subs = null === $active_subs ? Subscription::get_active_for_user( $user_id ) : $active_subs;
		if ( empty( $active_subs ) ) {
			return null;
		}

		$plan_rows = MembershipPlanCourse::get_plans_for_course( $course_id );
		if ( empty( $plan_rows ) ) {
			return null;
		}

		$plan_ids_with_course = array_map( 'intval', array_column( $plan_rows, 'plan_id' ) );

		foreach ( $active_subs as $sub ) {
			$plan_id = null === ( $sub->plan_id ?? null ) ? 0 : (int) $sub->plan_id;
			if ( $plan_id > 0 && in_array( $plan_id, $plan_ids_with_course, true ) ) {
				return $plan_id;
			}
		}

		return null;
	}

	/**
	 * Compute current_period_end based on the plan's billing interval. Used
	 * by subscribe(), end_trial(), and the renewal cron when the provider
	 * hasn't supplied a period_end.
	 *
	 * The cycle is resolved through `MembershipPlan::price_for_cycle()` — the
	 * same call that decides what the buyer is charged — so the term a member
	 * gets can never disagree with the price they paid for it. The old inline
	 * rule required `price_yearly` to be set before it would honour a yearly
	 * request, which is why a yearly selection produced a monthly term.
	 *
	 * @param object $plan          Plan row.
	 * @param string $from_datetime Period start (UTC 'Y-m-d H:i:s').
	 * @param string $billing_cycle Requested cycle; empty means the plan's own.
	 */
	public function compute_period_end( object $plan, string $from_datetime, string $billing_cycle = '' ): string {
		$terms          = MembershipPlan::price_for_cycle( $plan, $billing_cycle );
		$interval       = (string) $terms['cycle'];
		$interval_count = (int) $terms['interval_count'];

		$map  = [
			'daily'   => 'day',
			'weekly'  => 'week',
			'monthly' => 'month',
			'yearly'  => 'year',
		];
		$unit = $map[ $interval ] ?? 'month';

		return gmdate( 'Y-m-d H:i:s', strtotime( "+{$interval_count} {$unit}", strtotime( $from_datetime ) ) );
	}

	/**
	 * Canonicalise a billing-interval string to one of daily/weekly/monthly/
	 * yearly. Plans are authored with synonyms ('annual', 'year', 'annually')
	 * that previously fell through compute_period_end()'s map to the 'month'
	 * default — so an annual plan granted one month of access. One reader for
	 * every interval comparison so a synonym can never silently shorten a term.
	 */
	public static function normalize_interval( string $interval ): string {
		$interval = strtolower( trim( $interval ) );

		switch ( $interval ) {
			case 'year':
			case 'yearly':
			case 'annual':
			case 'annually':
				return 'yearly';
			case 'month':
			case 'monthly':
				return 'monthly';
			case 'week':
			case 'weekly':
				return 'weekly';
			case 'day':
			case 'daily':
				return 'daily';
			default:
				return $interval;
		}
	}

	/**
	 * Give a new subscriber their seat in every course the plan covers.
	 *
	 * Routed through {@see Enrollment_Service::enroll()} — the one enrollment
	 * chokepoint — rather than `Enrollment::create()`. Writing the row directly
	 * skipped the terminal-row handling that the UNIQUE `(user_id, course_id,
	 * source)` key makes mandatory: a member who cancelled and later re-subscribed
	 * still owned an `expired` membership row, the direct INSERT collided with the
	 * unique key and failed **silently**, and the paying member's course stayed
	 * `expired` forever — absent from My Courses, from their progress, and from
	 * certificate eligibility, even though the permission engine (which reads the
	 * subscription, not the row) still opened the lessons. That is the same defect
	 * class as Basecamp #9961889384, left unfixed on this path because this path
	 * bypassed the service that fixes it.
	 *
	 * Going through the service also gets the plan link stamped on the row, fires
	 * `learnomy_student_enrolled`, and keeps `student_count` distinct-person
	 * accurate — three things the direct INSERT did not do.
	 *
	 * Consequence worth knowing: the service enforces prerequisites and refuses a
	 * NEW enrollment into an unpublished course. A plan course the member has not
	 * unlocked yet, or one still in draft, is now skipped instead of force-enrolled
	 * — the same rule every other paid path already followed. An existing row is
	 * always reactivated regardless, so nobody loses a seat they already had.
	 */
	private function enroll_plan_courses( int $user_id, int $plan_id, int $subscription_id ): void {
		$courses    = MembershipPlanCourse::get_courses_for_plan( $plan_id );
		$enrollment = new Enrollment_Service();

		foreach ( $courses as $row ) {
			$course_id = (int) $row->course_id;

			// Skip if already actively enrolled from any source — a member who
			// bought the course outright keeps that row and does not also collect a
			// membership one, so cancelling the plan cannot revoke what they paid
			// for separately.
			if ( Enrollment::is_enrolled( $user_id, $course_id ) ) {
				continue;
			}

			$enrollment->enroll(
				$user_id,
				$course_id,
				'membership',
				array( 'plan_id' => $plan_id )
			);
		}
	}

	private function revoke_plan_enrollments( int $user_id, int $plan_id ): void {
		// Revoke the membership enrollments this plan granted, driven by the
		// live plan->course map rather than the enrollment's stored plan_id.
		// Keying off the stored id orphans any row that never recorded which
		// plan enrolled it (legacy data, or any path that forgot to stamp it),
		// leaving the course accessible after the membership ends — the exact
		// "courses remain accessible after cancellation" defect this guards.
		//
		// Must run AFTER the subscription row has already been moved out of an
		// active state, so has_access() reflects the post-cancel reality: a
		// course also granted by another subscription the user still holds
		// (overlapping plans) stays enrolled.
		$courses = MembershipPlanCourse::get_courses_for_plan( $plan_id );

		if ( empty( $courses ) ) {
			// Plan has no current course map (e.g. the plan row was deleted).
			// Best-effort fall back to the stored-plan_id sweep.
			Enrollment::expire_for_plan( $user_id, $plan_id );
			return;
		}

		$revoke = array();
		foreach ( $courses as $row ) {
			$course_id = (int) $row->course_id;
			if ( ! $this->has_access( $user_id, $course_id ) ) {
				$revoke[] = $course_id;
			}
		}

		if ( ! empty( $revoke ) ) {
			Enrollment::expire_membership_for_courses( $user_id, $revoke );
		}
	}

	/**
	 * Mark a course-level recurring enrollment expired. Mirror of
	 * revoke_plan_enrollments() but scoped to a single (user, course) pair
	 * since course-level subs are 1:1 with their enrollment.
	 */
	private function revoke_course_enrollment( int $user_id, int $course_id ): void {
		// find_for_user_course (active + completed), NOT find_active: a recurring
		// enrollment the student has COMPLETED must still lose access when the
		// subscription is cancelled. find_active() couldn't see a completed row,
		// so a completed subscriber kept access and student_count was never
		// decremented on cancel. [find_active class 10108551275]
		$enrollment = Enrollment::find_for_user_course( $user_id, $course_id );
		if ( $enrollment && 'subscription' === $enrollment->source ) {
			// Distinct-aware: decrement only when this subscription row was the
			// user's LAST live row in the course. If they also hold a direct or
			// Space enrolment, they remain one counted student (Basecamp
			// 10109120366). Probe before the flip so the prior state is visible.
			$last_live_row = ! Enrollment::user_has_other_active_row( $user_id, $course_id, (int) $enrollment->id );
			Enrollment::update( (int) $enrollment->id, array( 'status' => 'expired' ) );
			if ( $last_live_row ) {
				Course::increment_student_count( (int) $enrollment->course_id, -1 );
			}
		}
	}

	/**
	 * Human label for a subscription's state.
	 *
	 * Centralises the DB-to-UI vocabulary translation so every surface
	 * (admin list, manage page, emails, Pro analytics) renders the same
	 * strings. The `cancel_at_period_end` flag takes precedence over the
	 * stored status — a live-but-ending sub reads "Ending {date}" rather
	 * than "Active".
	 *
	 * @param object $sub Subscription row with at least `status` and
	 *                    `cancel_at_period_end` (and optionally
	 *                    `current_period_end` for the Ending label).
	 */
	public static function status_label( object $sub ): string {
		$labels = self::status_labels();
		$status = (string) ( $sub->status ?? 'active' );

		if ( ! empty( $sub->cancel_at_period_end ) && 'active' === $status ) {
			$end = ! empty( $sub->current_period_end )
				? date_i18n( get_option( 'date_format' ), strtotime( (string) $sub->current_period_end ) )
				: '';

			return $end
				/* translators: %s: date the subscription ends. */
				? sprintf( __( 'Ending %s', 'learnomy' ), $end )
				: __( 'Ending soon', 'learnomy' );
		}

		return $labels[ $status ] ?? ucfirst( $status );
	}

	/**
	 * Canonical map of subscription statuses to human-friendly labels.
	 *
	 * Filterable via `learnomy_subscription_status_labels` so Pro can
	 * inject new states (e.g. `paused`) without duplicating the strings.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		/**
		 * Filter the subscription status → UI label map.
		 *
		 * Pro adds `paused` here so the shared vocabulary stays in one place.
		 *
		 * @param array<string, string> $labels DB status => localized label.
		 */
		return (array) apply_filters(
			'learnomy_subscription_status_labels',
			[
				'trialing'  => __( 'In trial', 'learnomy' ),
				'active'    => __( 'Active', 'learnomy' ),
				'past_due'  => __( 'Payment failed', 'learnomy' ),
				'paused'    => __( 'Paused', 'learnomy' ),
				'cancelled' => __( 'Cancelled', 'learnomy' ),
				'expired'   => __( 'Ended', 'learnomy' ),
			]
		);
	}
}

/**
 * Backward-compatibility alias for the pre-1.1 class name.
 *
 * `Membership_Service` was renamed to `Subscription_Service` in PR-0
 * (2026-05-18) — the old name implied membership-only, but the class
 * is the canonical factory for every recurring billing entity (plan-
 * level today, course-level in PR-A1). The alias keeps Pro extensions
 * and 3rd-party integrations working without a coordinated release.
 *
 * @deprecated since 1.1 — use \Learnomy\Services\Subscription_Service.
 *             Slated for removal in 1.2.
 */
class_alias( \Learnomy\Services\Subscription_Service::class, \Learnomy\Services\Membership_Service::class );
