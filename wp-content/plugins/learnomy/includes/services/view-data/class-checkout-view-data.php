<?php
/**
 * View-data resolver for the checkout template.
 *
 * Replaces all Model calls in templates/checkout.php with a
 * pre-resolved data array so the template has zero DB queries.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\Enrollment;
use Learnomy\Services\Payment_Settings;

class Checkout_View_Data {

	/**
	 * Resolve all data needed by templates/checkout.php.
	 *
	 * @param array $data Route data (typically just 'route' => 'enroll').
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		$course_id = absint( $_GET['course_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plan_id   = absint( $_GET['plan_id'] ?? 0 );   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// The cycle the buyer chose on the pricing page rides in on the link.
		$billing_cycle = sanitize_key( (string) ( $_GET['billing'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id       = get_current_user_id();
		$settings      = get_option( 'learnomy_settings', array() );

		$item             = null;
		$item_title       = '';
		$item_price       = 0.0;
		$sale_price       = 0.0;
		$thumbnail        = '';
		$instructor       = '';
		$is_free          = true;
		$is_plan          = false;
		$is_recurring     = false;
		$currency         = strtoupper( $settings['currency'] ?? 'USD' );
		$already_enrolled = false;
		$course_url       = '';
		$error_type       = '';

		if ( $course_id ) {
			$item = Course::find( $course_id );

			if ( ! $item || 'published' !== ( $item->status ?? 'draft' ) ) {
				return array(
					'error_type' => 'course_unavailable',
					'item'       => null,
				);
			}

			$item_title = $item->title ?? '';
			$thumbnail  = $item->thumbnail_url ?? '';

			// A recurring-priced course is quoted at its RECURRING terms —
			// the amount actually charged each period — never the one-time
			// price column, and sales do not apply (a sale on a subscription
			// would discount every renewal silently). The page previously
			// showed a flat price with no billing term for a purchase that
			// renews (Basecamp 10155999596): shown must equal charged.
			if ( Course::is_recurring( $item ) ) {
				$is_recurring  = true;
				$item_price    = (float) ( $item->recurring_amount ?? 0 );
				$sale_price    = 0.0;
				$billing_cycle = sanitize_key( (string) ( $item->recurring_interval ?? 'monthly' ) );
			} else {
				$item_price = (float) ( $item->price ?? 0 );
				$sale_price = (float) ( $item->sale_price ?? 0 );
			}
			$is_free = Course::is_purchasable_free( $item, $item_price, $sale_price );

			$author = ! empty( $item->author_id ) ? get_userdata( (int) $item->author_id ) : null;
			if ( $author ) {
				$instructor = $author->display_name;
			}

			$enrollment       = Enrollment::find_for_user_course( $user_id, $course_id );
			$already_enrolled = null !== $enrollment;

			$base_slug  = $settings['base_slug'] ?? 'courses';
			$course_url = home_url( '/' . $base_slug . '/' . ( $item->slug ?? '' ) . '/' );

		} elseif ( $plan_id ) {
			$item    = MembershipPlan::find( $plan_id );
			$is_plan = true;

			if ( ! $item || 'active' !== ( $item->status ?? 'inactive' ) ) {
				return array(
					'error_type' => 'plan_unavailable',
					'item'       => null,
				);
			}

			// Price the chosen cycle through the ONE resolver the charge also
			// uses. Reading `price ?? price_monthly` here is what let the
			// checkout page quote $19/mo to a buyer who had picked the $190/yr
			// term (Basecamp 10143220483).
			$terms         = MembershipPlan::price_for_cycle( $item, $billing_cycle );
			$billing_cycle = (string) $terms['cycle'];

			$item_title = $item->title ?? '';
			$item_price = (float) $terms['amount'];
			$thumbnail  = $item->thumbnail_url ?? '';
			$is_free    = ( $item_price <= 0 );

			// A plan may bill in its own currency. The page used to render the
			// SITE currency symbol while `charge_currency( $plan )` charged the
			// plan's — so a EUR plan quoted "$19.00" and took €19.
			$currency = strtoupper( \Learnomy\charge_currency( $item ) );
		} else {
			return array(
				'error_type' => 'no_item',
				'item'       => null,
			);
		}

		// Gateway availability. Delegated to Payment_Settings so the answer
		// the checkout view sees is identical to the answer the adapters
		// give. Reading flat `stripe_secret_key` / `paypal_client_id` here
		// is the bug fixed in the 2026-05-16 payment-onboarding sweep — the
		// Phase 1 migration (2026-05-13) split those into mode-aware keys
		// and the view-data layer was never updated, so a fully configured
		// Stripe install rendered "No payment methods are currently available".
		$stripe_enabled = Payment_Settings::stripe_enabled();
		$paypal_enabled = Payment_Settings::paypal_enabled();
		$wc_active      = class_exists( 'WooCommerce' ) && ! empty( $settings['enable_woocommerce'] );
		$test_enabled   = ! empty( $settings['enable_test_gateway'] );

		// Effective price (sale price takes priority).
		$effective_price = ( $sale_price > 0 ) ? $sale_price : $item_price;

		// The shared glyph map. Five view-data classes each carried their own
		// six-entry copy, so the same price rendered differently depending on
		// which screen you were on, and a site whose currency was in one copy
		// but not another saw a symbol on checkout and a bare code on billing.
		$currency_symbols = \Learnomy\currency_symbols();
		$currency_symbol  = $currency_symbols[ $currency ] ?? $currency . ' ';

		return array(
			'error_type'        => $error_type,
			'item'              => $item,
			'item_title'        => $item_title,
			'item_price'        => $item_price,
			'sale_price'        => $sale_price,
			'thumbnail'         => $thumbnail,
			'instructor'        => $instructor,
			'is_free'           => $is_free,
			'is_plan'           => $is_plan,
			// A recurring-priced COURSE (plans carry their own term handling).
			'is_recurring'      => $is_recurring,
			'currency'          => $currency,
			'currency_symbol'   => $currency_symbol,
			'already_enrolled'  => $already_enrolled,
			'course_url'        => $course_url,
			'course_id'         => $course_id,
			'plan_id'           => $plan_id,
			'billing_cycle'     => $billing_cycle,
			// The buyer's own billing details, so the checkout starts filled in
			// rather than asking a returning member for an address they have
			// already given us. Stored on the USER, so it survives the gateway.
			'billing_profile'   => \Learnomy\Services\Billing_Profile::get( $user_id ),
			'billing_countries' => \Learnomy\countries(),
			'user_id'           => $user_id,
			'settings'          => $settings,
			'stripe_enabled'    => $stripe_enabled,
			'paypal_enabled'    => $paypal_enabled,
			'wc_active'         => $wc_active,
			'test_enabled'      => $test_enabled,
			'effective_price'   => $effective_price,
		);
	}
}
