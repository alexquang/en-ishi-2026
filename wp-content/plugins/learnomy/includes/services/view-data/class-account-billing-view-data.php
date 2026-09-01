<?php
/**
 * View-data resolver for the unified customer billing page.
 *
 * Renders three sections regardless of whether the customer bought a single
 * course, subscribed to a membership, or subscribed to a recurring course:
 *
 *   1. Active subscriptions — plan-targeted AND course-targeted (post-PR-A1
 *      `subscriptions.course_id` column makes the table polymorphic).
 *   2. Time-limited access — fixed-term enrollments with expiry countdown.
 *   3. Order history — every transaction, with linked entity + receipt link.
 *
 * Templates receive a flat data array and never touch models or $wpdb.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\Subscription;
use Learnomy\Models\Transaction;

class Account_Billing_View_Data {

	/**
	 * Resolve all data needed by templates/account-billing.php.
	 *
	 * @param array $data Route data (typically just 'route' => 'account-billing').
	 * @return array
	 */
	public static function resolve( array $data ): array {
		unset( $data );

		$user_id = get_current_user_id();
		$user    = wp_get_current_user();

		// All transactions for this user, newest first. Caps at 100 for
		// initial render; pagination is a Phase 2 nice-to-have.
		$transactions      = Transaction::get_for_user( $user_id, 100 );
		$transaction_views = array();
		$refunds_by_origin = array();

		// Index refund rows by their `refund_of` parent so we can show
		// "Refunded {date}" next to the original transaction.
		foreach ( $transactions as $txn ) {
			if ( 'refund' === ( $txn->transaction_type ?? '' ) && ! empty( $txn->refund_of ) ) {
				$refunds_by_origin[ (int) $txn->refund_of ] = $txn;
			}
		}

		foreach ( $transactions as $txn ) {
			// Skip refund rows here — they're surfaced as a flag on the parent.
			if ( 'refund' === ( $txn->transaction_type ?? '' ) ) {
				continue;
			}
			$transaction_views[] = self::build_transaction_view( $txn, $refunds_by_origin );
		}

		// Active subscriptions (both plan + course targeted). We use
		// get_manageable_for_user() which already returns active/trialing/
		// past_due/paused — the four states a customer can manage.
		$subscriptions      = Subscription::get_manageable_for_user( $user_id );
		$subscription_views = array();
		foreach ( $subscriptions as $sub ) {
			$subscription_views[] = self::build_subscription_view( $sub );
		}

		// Time-limited (fixed-term) enrollments — active enrollments with a
		// non-null expires_at. Shown in their own section so customers can
		// see countdown + Renew button before access lapses.
		$time_limited = self::resolve_time_limited_enrollments( $user_id );

		$settings        = get_option( 'learnomy_settings', array() );
		$currency        = strtoupper( $settings['currency'] ?? 'USD' );
		$currency_symbol = self::currency_symbol( $currency );

		$view_data = array(
			'current_user'       => $user,
			'current_user_id'    => $user_id,
			'subscription_views' => $subscription_views,
			'time_limited'       => $time_limited,
			'transaction_views'  => $transaction_views,
			'currency'           => $currency,
			'currency_symbol'    => $currency_symbol,
			'billing_url'        => \Learnomy\route_url( 'account-billing' ),

			/*
			 * The member's own billing address, so this page can EDIT it.
			 *
			 * Checkout was the only writer in the entire plugin pair — no
			 * account surface, no wp-admin surface, no REST route. Once checkout
			 * began genuinely refusing incomplete details, a member who mistyped
			 * an address could only correct it by buying something else
			 * (Basecamp 10171338907). CAPABILITIES.md meanwhile promised an
			 * address "kept on the account", and checkout tells the buyer
			 * "saved to your account, so you only enter this once".
			 *
			 * Same helper checkout reads, so the two cannot disagree about what
			 * is stored or which countries exist.
			 */
			'billing_profile'    => \Learnomy\Services\Billing_Profile::get( $user_id ),
			'billing_countries'  => \Learnomy\countries(),
			'billing_missing'    => \Learnomy\Services\Billing_Profile::missing_required(
				\Learnomy\Services\Billing_Profile::get( $user_id )
			),
			'browse_courses_url' => \Learnomy\route_url( 'catalog' ),
			'membership_url'     => \Learnomy\route_url( 'membership-pricing' ),
			'manage_url'         => \Learnomy\route_url( 'membership-manage' ),
		);

		/**
		 * Filter the resolved view-data array. Pro extensions can decorate
		 * with extra cards (e.g. credit balance, store credit) here.
		 */
		return (array) apply_filters( 'learnomy_account_billing_view_data', $view_data, $user_id );
	}

	/**
	 * Build the view shape for one transaction row.
	 */
	private static function build_transaction_view( object $txn, array $refunds_by_origin ): array {
		$item_title = '';
		$item_kind  = '';
		$start_url  = '';

		if ( ! empty( $txn->course_id ) ) {
			$course = Course::find( (int) $txn->course_id );
			if ( $course ) {
				$item_title = (string) $course->title;
				$item_kind  = 'course';
				$start_url  = \Learnomy\route_url( 'course', array( 'slug' => (string) ( $course->slug ?? '' ) ) );
			}
		}
		if ( ! $item_title && ! empty( $txn->plan_id ) ) {
			$plan = MembershipPlan::find( (int) $txn->plan_id );
			if ( $plan ) {
				$item_title = (string) $plan->title;
				$item_kind  = 'membership';
				$start_url  = \Learnomy\route_url( 'membership-manage' );
			}
		}
		if ( ! $item_title ) {
			$item_title = __( 'Purchase', 'learnomy' );
		}

		$refund = $refunds_by_origin[ (int) $txn->id ] ?? null;

		$billing_slug = (string) apply_filters( 'learnomy_billing_slug', 'account/billing' );
		$receipt_url  = home_url( sprintf( '/%s/%d/receipt/', trim( $billing_slug, '/' ), (int) $txn->id ) );

		return array(
			'id'               => (int) $txn->id,
			'item_title'       => $item_title,
			'item_kind'        => $item_kind,
			'transaction_type' => (string) ( $txn->transaction_type ?? '' ),
			'amount'           => (float) $txn->amount,
			'currency'         => strtoupper( (string) ( $txn->currency ?? 'USD' ) ),
			'status'           => (string) ( $txn->status ?? 'pending' ),
			// SELL-02: true for a simulated Test-gateway order (recorded at
			// $0), so the template can say "no charge" instead of a bare
			// $0.00 the customer never agreed to pay.
			'is_test_order'    => null !== Transaction::simulated_listed_price( $txn ),
			'payment_provider' => (string) ( $txn->payment_provider ?? '' ),
			'created_at'       => (string) ( $txn->created_at ?? '' ),
			'refund'           => $refund ? array(
				'id'         => (int) $refund->id,
				'amount'     => (float) $refund->amount,
				'created_at' => (string) $refund->created_at,
			) : null,
			'receipt_url'      => $receipt_url,
			'start_url'        => $start_url,
		);
	}

	/**
	 * Build the view shape for one subscription row.
	 */
	private static function build_subscription_view( object $sub ): array {
		$kind       = 'plan';
		$title      = __( 'Subscription', 'learnomy' );
		$manage_url = \Learnomy\route_url( 'membership-manage' );

		if ( ! empty( $sub->plan_id ) ) {
			$plan = MembershipPlan::find( (int) $sub->plan_id );
			if ( $plan ) {
				$title = (string) $plan->title;
			}
		} elseif ( ! empty( $sub->course_id ) ) {
			$kind   = 'course';
			$course = Course::find( (int) $sub->course_id );
			if ( $course ) {
				$title      = (string) $course->title;
				$manage_url = \Learnomy\route_url( 'course', array( 'slug' => (string) ( $course->slug ?? '' ) ) );
			}
		}

		// Compute next-payment amount for course subscriptions (membership
		// plan amounts are already on the plan row).
		$next_payment_amount = 0.0;
		if ( 'course' === $kind && ! empty( $sub->course_id ) ) {
			$c = Course::find( (int) $sub->course_id );
			if ( $c && isset( $c->recurring_amount ) ) {
				$next_payment_amount = (float) $c->recurring_amount;
			}
		}

		return array(
			'id'                   => (int) $sub->id,
			'kind'                 => $kind,
			'title'                => $title,
			'status'               => (string) ( $sub->status ?? 'active' ),
			'payment_provider'     => (string) ( $sub->payment_provider ?? '' ),
			'current_period_end'   => (string) ( $sub->current_period_end ?? '' ),
			'cancel_at_period_end' => ! empty( $sub->cancel_at_period_end ),
			'manage_url'           => $manage_url,
			'next_payment_amount'  => $next_payment_amount,
		);
	}

	/**
	 * Fetch fixed-term enrollments (those with a non-null expires_at) for
	 * the current user. Each row gets a computed `days_remaining` for the
	 * countdown chip and a `renew_url` pointing back at the course's checkout.
	 */
	private static function resolve_time_limited_enrollments( int $user_id ): array {
		$rows = Enrollment::time_limited_for_user( $user_id );

		if ( ! $rows ) {
			return array();
		}

		$now = time();
		$out = array();
		foreach ( $rows as $row ) {
			$course = Course::find( (int) $row->course_id );
			if ( ! $course ) {
				continue;
			}

			$expires_ts     = strtotime( (string) $row->expires_at );
			$days_remaining = max( 0, (int) floor( ( $expires_ts - $now ) / DAY_IN_SECONDS ) );
			$is_expired     = $expires_ts < $now;

			$out[] = array(
				'enrollment_id'  => (int) $row->id,
				'course_id'      => (int) $row->course_id,
				'course_title'   => (string) $course->title,
				'course_url'     => \Learnomy\route_url( 'course', array( 'slug' => (string) ( $course->slug ?? '' ) ) ),
				'expires_at'     => (string) $row->expires_at,
				'days_remaining' => $days_remaining,
				'is_expired'     => $is_expired,
				'renew_url'      => add_query_arg(
					array(
						'lrn_action' => 'enroll',
						'course_id'  => (int) $row->course_id,
						'_wpnonce'   => wp_create_nonce( 'lrn_enroll' ),
					),
					home_url( '/' )
				),
			);
		}

		return $out;
	}

	/**
	 * Three-letter currency code → display symbol. Falls back to the code
	 * itself for currencies we don't have a glyph for.
	 */
	private static function currency_symbol( string $code ): string {
		// The shared glyph map, so a receipt and a checkout cannot render the
		// same currency differently.
		$map = \Learnomy\currency_symbols();

		return $map[ strtoupper( $code ) ] ?? $code;
	}
}
