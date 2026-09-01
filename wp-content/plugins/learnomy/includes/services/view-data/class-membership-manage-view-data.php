<?php
/**
 * View-data resolver for the membership-manage template.
 *
 * Pre-fetches active subscriptions, their plans, the courses bundled with
 * each plan, the transaction history (invoices) and the catalogue of all
 * active plans (for the upgrade-options section). Templates receive a
 * flat data array and never touch models or $wpdb.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\MembershipPlan;
use Learnomy\Models\Subscription;
use Learnomy\Models\Transaction;

class Membership_Manage_View_Data {

	/** How many bundled courses to render inline per plan before "+N more". */
	private const COURSES_PREVIEW = 12;

	/**
	 * Resolve all data needed by templates/membership-manage.php.
	 *
	 * @param array $data Route data (typically just 'route' => 'membership-manage').
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		unset( $data );

		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		// Include paused subscriptions so the member can resume from the manage
		// page. Paused subs are skipped by get_active_for_user() used for
		// access checks, but we still want them visible to their owner.
		$active_subscriptions = Subscription::get_manageable_for_user( $user_id );

		// For every active subscription resolve the plan row and the course
		// grid so the template renders zero DB queries.
		$subscription_views = array();
		foreach ( $active_subscriptions as $sub ) {
			$plan = MembershipPlan::find( (int) $sub->plan_id );
			if ( ! $plan ) {
				continue;
			}

			$courses_resolved     = self::resolve_courses_for_plan( (int) $plan->id );
			$invoices             = Transaction::get_for_subscription( (int) $sub->id, 50 );
			$next_payment_amt     = self::compute_next_payment_amount( $sub, $plan );
			$subscription_views[] = array(
				'subscription'   => $sub,
				'plan'           => $plan,
				'courses'        => $courses_resolved['courses'],
				'courses_total'  => $courses_resolved['total'],
				'invoices'       => $invoices,
				'next_payment'   => $next_payment_amt,
				'update_payment' => self::resolve_update_payment( $sub ),
			);
		}

		// Plans catalogue for the "Upgrade options" grid.
		// Only sellable plans (grant courses) — matches the public pricing page's
		// T2 rule. A zero-course plan was being offered as a "Switch to this"
		// target here.
		$all_plans = array_values(
			array_filter(
				MembershipPlan::get_active(),
				static function ( $plan ) {
					return MembershipPlan::is_sellable( $plan );
				}
			)
		);

		$settings        = get_option( 'learnomy_settings', array() );
		$currency        = strtoupper( $settings['currency'] ?? 'USD' );
		$currency_symbol = self::currency_symbol( $currency );

		// Pro toggles this to true to enable plan-change UI.
		$can_change_plan = (bool) apply_filters( 'learnomy_membership_can_change_plan', false );

		$pricing_url = \Learnomy\route_url( 'membership-pricing' );

		// Plan-switch / checkout base. The "Switch to this" cards append
		// ?plan_id=X to this URL, so it must point at the real `/enroll/`
		// route (the only checkout route the router registers) and not the
		// non-existent `/checkout` path that produced a 404.
		$checkout_url = \Learnomy\route_url( 'enroll' );

		return array(
			'active_subscriptions' => $active_subscriptions,
			'subscription_views'   => $subscription_views,
			'all_plans'            => $all_plans,
			'current_user'         => $user,
			'current_user_id'      => $user_id,
			'currency'             => $currency,
			'currency_symbol'      => $currency_symbol,
			'can_change_plan'      => $can_change_plan,
			'pricing_url'          => $pricing_url,
			'checkout_url'         => $checkout_url,
		);
	}

	/**
	 * Where to route a member to fix their payment method. Payment recovery
	 * (card capture, retries, dunning) is the gateway's job — Learnomy only
	 * links to the right hosted surface, so no card data ever touches us.
	 * Stripe (and any card gateway with the hosted portal): the existing
	 * /account/billing/ portal launcher, which also covers cancel + plan swap.
	 * PayPal: PayPal's own subscription-management page. [card 10062795906]
	 *
	 * @param object $sub Subscription row.
	 * @return array{url:string,hint:string}
	 */
	private static function resolve_update_payment( $sub ): array {
		$provider = strtolower( (string) ( $sub->payment_provider ?? '' ) );

		if ( 'paypal' === $provider ) {
			$url  = (string) apply_filters( 'learnomy_paypal_manage_url', 'https://www.paypal.com/myaccount/autopay/', $sub );
			$hint = __( 'Manage this subscription in your PayPal account.', 'learnomy' );
		} elseif ( '' !== $provider ) {
			$url  = \Learnomy\route_url( 'account-billing' );
			$hint = __( 'Update your card or cancel in your secure billing portal.', 'learnomy' );
		} else {
			$url  = '';
			$hint = '';
		}

		return array(
			'url'  => (string) apply_filters( 'learnomy_update_payment_url', $url, $sub ),
			'hint' => (string) apply_filters( 'learnomy_update_payment_hint', $hint, $sub ),
		);
	}

	/**
	 * Courses bundled in a plan. Returns the full course row array — the
	 * template maps over each entry to build the "Courses included" grid.
	 *
	 * @param int $plan_id Plan id.
	 * @return array<int, object>
	 */
	private static function resolve_courses_for_plan( int $plan_id ): array {
		// One resolver, in the model. The thank-you page needs the identical
		// "preview + real total" shape to answer "what did I just buy?", and a
		// second copy of this batching + ordering + orphan-tolerance is how the
		// two screens would eventually disagree about a plan's contents.
		return MembershipPlan::preview_courses( $plan_id, self::COURSES_PREVIEW );
	}

	/**
	 * Best-effort calculation of the next charge amount. Uses `price_yearly`
	 * when the subscription is billed yearly, otherwise `price_monthly`,
	 * otherwise the plan's flat `price`.
	 *
	 * @param object $sub  Subscription row.
	 * @param object $plan Plan row.
	 */
	private static function compute_next_payment_amount( object $sub, object $plan ): float {
		$interval = strtolower( (string) ( $plan->billing_interval ?? 'monthly' ) );
		if ( 'yearly' === $interval && ! empty( $plan->price_yearly ) ) {
			return (float) $plan->price_yearly;
		}
		if ( ! empty( $plan->price_monthly ) ) {
			return (float) $plan->price_monthly;
		}
		return (float) ( $plan->price ?? 0 );
	}

	/**
	 * Resolve an ISO-4217 currency code to a display symbol.
	 *
	 * @param string $currency ISO-4217 code, upper-case.
	 */
	private static function currency_symbol( string $currency ): string {
		// The shared glyph map, so this screen cannot disagree with
		// checkout about what a price looks like.
		$map = \Learnomy\currency_symbols();
		return $map[ $currency ] ?? ( $currency . ' ' );
	}
}
