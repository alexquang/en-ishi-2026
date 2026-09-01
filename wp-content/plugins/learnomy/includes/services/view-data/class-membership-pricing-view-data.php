<?php
/**
 * View-data resolver for the membership pricing template.
 *
 * Pre-fetches all plans, the visitor's active subscriptions, currency and
 * checkout URLs so the template never touches the DB or the service layer.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\MembershipPlan;
use Learnomy\Models\MembershipPlanCourse;
use Learnomy\Models\Subscription;

class Membership_Pricing_View_Data {

	/**
	 * Resolve all data needed by templates/membership-pricing.php.
	 *
	 * @param array $data Route data (typically just 'route' => 'membership-pricing').
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		unset( $data );

		$user_id          = get_current_user_id();
		$current_subs     = array();
		$current_plan_ids = array();

		if ( $user_id ) {
			$current_subs = Subscription::get_active_for_user( $user_id );
			foreach ( $current_subs as $sub ) {
				$current_plan_ids[] = (int) $sub->plan_id;
			}
		}

		$plans = MembershipPlan::get_active();

		// J-02.4 — decorate every plan with its bundled-course count so the
		// card template can show the real value instead of a hard-coded
		// "Unlimited courses" line. Counts are pulled once per plan in
		// a single COUNT query; for the typical pricing-page surface
		// (≤ 10 plans) this is cheap. If you have hundreds of plans you
		// should refactor to a single GROUP BY query.
		foreach ( $plans as $plan ) {
			$plan->bundled_course_count = MembershipPlanCourse::count(
				array( 'plan_id' => (int) ( $plan->id ?? 0 ) )
			);
		}

		// T2 — hide plans from the PUBLIC pricing page that grant no courses
		// (no bundle AND no course_limit). Per the access model a membership
		// only unlocks courses its plan explicitly includes, so an empty plan
		// would sell a buyer access to nothing and render a "Course list coming
		// soon" placeholder that reads as broken. Such plans are test /
		// misconfigured; keep them out of the storefront (the Memberships admin
		// still lists them so the owner can finish configuring).
		$plans = array_values(
			array_filter(
				$plans,
				static function ( $plan ) {
					return \Learnomy\Models\MembershipPlan::is_sellable( $plan );
				}
			)
		);

		// Determine whether any plan has both monthly + yearly prices so the
		// toggle at the top of the pricing page only renders when it makes
		// sense. A pure monthly catalogue should not show the Yearly switch.
		$show_toggle = false;
		foreach ( $plans as $plan ) {
			$monthly = isset( $plan->price_monthly ) ? (float) $plan->price_monthly : 0.0;
			$yearly  = isset( $plan->price_yearly ) ? (float) $plan->price_yearly : 0.0;
			if ( $monthly > 0 && $yearly > 0 ) {
				$show_toggle = true;
				break;
			}
		}

		$settings        = get_option( 'learnomy_settings', array() );
		$currency        = strtoupper( $settings['currency'] ?? 'USD' );
		$currency_symbol = self::currency_symbol( $currency );

		// Pro extensions can unlock mid-cycle plan switching via this filter.
		// Free always returns false — subscribers see "Currently subscribed".
		$can_change_plan = (bool) apply_filters( 'learnomy_membership_can_change_plan', false );

		// Checkout URL base — plan + coupon are appended per-card.
		// Router rewrites `/enroll/` → template checkout.php; see class-router.php:116.
		$checkout_url = \Learnomy\route_url( 'enroll' );

		// Admin-editable page content (Settings → Membership Page). Empty =
		// fall back to the built-in copy so the page reads well out of the box.
		$headline = isset( $settings['membership_headline'] ) ? trim( (string) $settings['membership_headline'] ) : '';
		$subtitle = isset( $settings['membership_subtitle'] ) ? trim( (string) $settings['membership_subtitle'] ) : '';

		return array(
			'plans'            => $plans,
			'current_user_id'  => $user_id,
			'current_subs'     => $current_subs,
			'current_plan_ids' => $current_plan_ids,
			'show_toggle'      => $show_toggle,
			'currency'         => $currency,
			'currency_symbol'  => $currency_symbol,
			'can_change_plan'  => $can_change_plan,
			'checkout_url'     => $checkout_url,
			// Editable content + section toggles (default ON when unset).
			'page_headline'    => '' !== $headline ? $headline : __( 'Simple, transparent pricing', 'learnomy' ),
			// Default deliberately does NOT claim "Every course": a plan may
			// include only the courses linked to it, and the default is what
			// 10k sites ship with. Owners who do sell catalog-wide access can
			// say so via the membership_subtitle setting (Basecamp 10132713132).
			'page_subtitle'    => '' !== $subtitle ? $subtitle : __( 'One subscription. Cancel anytime.', 'learnomy' ),
			'faq_items'        => \Learnomy\membership_faq_items(),
			'show_faq'         => ! isset( $settings['show_membership_faq'] ) || ! empty( $settings['show_membership_faq'] ),
			'show_gift'        => ! isset( $settings['show_membership_gift'] ) || ! empty( $settings['show_membership_gift'] ),
		);
	}

	/**
	 * Resolve an ISO-4217 currency code to a display symbol. Falls back to
	 * the code itself when we don't have a hard-coded glyph.
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
