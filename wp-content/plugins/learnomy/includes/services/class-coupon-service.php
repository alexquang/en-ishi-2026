<?php
/**
 * Coupon validation, application, and discount math.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Coupon;
use Learnomy\Models\CouponUsage;

class Coupon_Service {

	/**
	 * One spelling for a coupon's scope.
	 *
	 * 'all' is canonical -- the label map and the stored rows already use it.
	 * 'both' is the admin form's legacy spelling and means the same thing;
	 * mapping it here rather than at each comparison keeps the alias in ONE
	 * place, so a fourth spelling cannot creep in unnoticed.
	 *
	 * @param string $scope Raw applies_to value.
	 * @return string 'course' | 'membership' | 'all'
	 */
	public static function canonical_scope( string $scope ): string {
		$scope = strtolower( trim( $scope ) );

		if ( in_array( $scope, array( 'both', 'all' ), true ) ) {
			return 'all';
		}

		return in_array( $scope, array( 'course', 'membership' ), true ) ? $scope : 'course';
	}

	/**
	 * Returns ['valid' => true, 'discount' => float, 'coupon' => object]
	 * or      ['valid' => false, 'reason' => string].
	 *
	 * When $price is supplied, the coupon's `min_purchase` floor is enforced —
	 * the caller gets a `reason` back instead of a valid result if the cart
	 * total is below that floor. Passing null keeps pre-checkout lookups
	 * working (admin preview, code probing) without guessing a price.
	 */
	public function validate( string $code, ?int $course_id = null, ?int $plan_id = null, ?int $user_id = null, ?float $price = null ): array {
		$coupon = Coupon::find_by_code( $code );
		if ( ! $coupon ) {
			return [
				'valid'  => false,
				'reason' => __( 'Coupon code not found.', 'learnomy' ),
			];
		}

		if ( ! Coupon::is_valid( $code ) ) {
			return [
				'valid'  => false,
				'reason' => __( 'This coupon is not currently active or has expired.', 'learnomy' ),
			];
		}

		// applies_to: 'course', 'membership', or 'all'.
		//
		// THREE vocabularies existed for this one field: the admin form wrote
		// 'both', this service tested 'both', the label map knew 'all' and not
		// 'both', and the database held both spellings. A coupon stored as
		// 'all' therefore failed the 'both' shortcut, fell through the two
		// checks below without matching either, and was then treated as a
		// COURSE coupon by the items_json branch -- so the broadest-sounding
		// scope silently behaved as the narrowest.
		//
		// 'all' is canonical: it is what the label map and the existing rows
		// already say. 'both' is accepted as a legacy alias so coupons saved
		// by the old form keep working rather than quietly narrowing.
		$applies_to = self::canonical_scope( (string) ( $coupon->applies_to ?? 'course' ) );

		if ( 'all' !== $applies_to ) {
			if ( 'course' === $applies_to && null === $course_id ) {
				return [
					'valid'  => false,
					'reason' => __( 'This coupon only applies to course purchases.', 'learnomy' ),
				];
			}
			if ( 'membership' === $applies_to && null === $plan_id ) {
				return [
					'valid'  => false,
					'reason' => __( 'This coupon only applies to membership plans.', 'learnomy' ),
				];
			}
		}

		// If items_json is set, the coupon is restricted to specific courses or plans.
		if ( ! empty( $coupon->items_json ) ) {
			$items = json_decode( $coupon->items_json, true );
			if ( is_array( $items ) && ! empty( $items ) ) {
				$context_id = ( 'membership' === $applies_to ) ? $plan_id : $course_id;
				if ( null !== $context_id && ! in_array( $context_id, $items, true ) ) {
					return [
						'valid'  => false,
						'reason' => __( 'This coupon does not apply to the selected item.', 'learnomy' ),
					];
				}
			}
		}

		// Per-user limit check.
		if ( null !== $user_id && (int) $coupon->per_user_limit > 0 ) {
			$uses = CouponUsage::get_usage_count_for_user( (int) $coupon->id, $user_id );
			if ( $uses >= (int) $coupon->per_user_limit ) {
				return [
					'valid'  => false,
					'reason' => __( 'You have already used this coupon the maximum number of times.', 'learnomy' ),
				];
			}
		}

		// Minimum purchase threshold. Only evaluated when the caller supplied
		// a cart total — pre-checkout lookups that don't know the price skip
		// this gate by design, and the checkout controller re-validates with
		// the real price before applying the discount.
		if ( null !== $price && ! is_null( $coupon->min_purchase ) && (float) $coupon->min_purchase > 0.0 ) {
			if ( $price < (float) $coupon->min_purchase ) {
				return [
					'valid'  => false,
					'reason' => sprintf(
						/* translators: %s: minimum purchase amount formatted as a currency-less number. */
						__( 'This coupon requires a minimum purchase of %s.', 'learnomy' ),
						number_format_i18n( (float) $coupon->min_purchase, 2 )
					),
				];
			}
		}

		// Compute discount against a zero base so the caller can use the coupon
		// object directly — actual price is applied at checkout.
		return [
			'valid'    => true,
			'discount' => 0.0, // Caller must pass price to calculate_discount().
			'coupon'   => $coupon,
		];
	}

	/**
	 * Record a redemption against both usage caps, atomically.
	 *
	 * This is the AUTHORITATIVE limit enforcement. validate() runs earlier for the
	 * cheap up-front UX check, but two concurrent checkouts can both pass that
	 * check, so the real cap enforcement has to happen here at write time:
	 *
	 *  1. Per-user cap: CouponUsage::record_within_user_limit() takes a
	 *     per-(coupon,user) advisory lock around its COUNT + INSERT, so concurrent
	 *     same-user redemptions can't both slip past `per_user_limit`.
	 *  2. Global cap: Coupon::claim_redemption() does an atomic conditional
	 *     increment, so `times_used` can never exceed `usage_limit`.
	 *
	 * The per-user record is taken first so the global counter is only bumped for
	 * a redemption that actually got logged — that keeps `times_used` and the
	 * usages table from drifting apart when a cap is hit under contention.
	 *
	 * Note on ordering vs payment: apply() runs AFTER the transaction is recorded
	 * (the buyer has paid), so a lost race here cannot un-charge anyone. What the
	 * atomic claims guarantee is that the COUNTERS stay truthful — never over the
	 * cap — which is what the usage limits exist to protect.
	 *
	 * @param int $coupon_id      Coupon id.
	 * @param int $user_id        Redeeming user.
	 * @param int $transaction_id Linked transaction id.
	 * @return bool True if the redemption was recorded within both caps; false if
	 *              a cap was already reached (the redemption was not double-counted).
	 */
	public function apply( int $coupon_id, int $user_id, int $transaction_id ): bool {
		$coupon = Coupon::find( $coupon_id );
		if ( ! $coupon ) {
			return false;
		}

		$per_user_limit = (int) ( $coupon->per_user_limit ?? 0 );

		$usage_id = CouponUsage::record_within_user_limit( $coupon_id, $user_id, $per_user_limit, $transaction_id );
		if ( $usage_id <= 0 ) {
			// Per-user cap already full (or lock failed closed). Do not bump the
			// global counter — nothing was logged for this user.
			return false;
		}

		// Per-user slot taken; now claim the global slot. If the global cap is
		// full, the usage row stays (the redemption did happen) but times_used is
		// not pushed past usage_limit.
		return Coupon::claim_redemption( $coupon_id );
	}

	public function calculate_discount( object $coupon, float $original_price ): float {
		if ( $original_price <= 0 ) {
			return 0.0;
		}

		$value = (float) $coupon->value;

		if ( 'percent' === $coupon->type ) {
			$discount = $original_price * ( $value / 100 );
		} else {
			// Fixed discount — can't exceed the original price.
			$discount = min( $value, $original_price );
		}

		// Discounts are always positive; never give money back via coupon rounding.
		return max( 0.0, round( $discount, 2 ) );
	}
}
