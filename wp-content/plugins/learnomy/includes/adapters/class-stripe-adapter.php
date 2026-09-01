<?php
/**
 * Stripe payment gateway adapter.
 *
 * Uses the Stripe REST API directly via WordPress HTTP functions — no SDK
 * dependency required. Secret key and webhook secret are read from
 * learnomy_settings so they're configurable from the plugin settings page.
 *
 * @package Learnomy
 */

namespace Learnomy\Adapters;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Transaction;
use Learnomy\Models\Subscription;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Webhook_Event;
use Learnomy\Services\Coupon_Service;
use Learnomy\Services\Enrollment_Service;
use Learnomy\Services\Subscription_Service;
use Learnomy\Services\Refund_Service;
use Learnomy\Services\Purchase_Recorder;

class Stripe_Adapter implements Payment_Adapter {

	const API_BASE = 'https://api.stripe.com/v1';

	public function get_id(): string {
		return 'stripe';
	}

	public function get_label(): string {
		return __( 'Credit / Debit Card (Stripe)', 'learnomy' );
	}

	public function is_available(): bool {
		$settings = get_option( 'learnomy_settings', [] );

		// enable_stripe toggle must be on. Admins who haven't saved since the
		// Phase 1 migration will have it off by default — explicit opt-in required.
		if ( empty( $settings['enable_stripe'] ) ) {
			return false;
		}

		$mode   = $settings['stripe_mode'] ?? 'test';
		$secret = \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_' . $mode . '_secret' );

		// Fallback to the legacy single-secret option for sites that configured
		// Stripe before Phase 1 and haven't re-saved since the migration.
		if ( '' === $secret ) {
			$secret = \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_secret_key' );
		}

		return '' !== $secret;
	}

	/**
	 * Opens a Stripe Checkout Session for a course purchase.
	 *
	 * Routes by the course's pricing model:
	 *   - `recurring`  → subscription mode using a cached Stripe Price
	 *                    (lazy-created on first purchase).
	 *   - `fixed_term` → payment mode with access_duration_days metadata
	 *                    so the webhook handler sets enrollment.expires_at.
	 *   - all others   → payment mode, lifetime access.
	 *
	 * Plan checkouts (plan_id without course_id) still route through
	 * create_subscription() / payment mode; this method is course-only.
	 */
	/**
	 * Unified one-time + subscription entry point. Routes by `mode` and
	 * normalizes the new purchase_type/item_id arg shape into the legacy
	 * course_id/plan_id shape the existing create_checkout /
	 * create_subscription methods expect.
	 *
	 * Adding a new product type means picking a `purchase_type` label and
	 * writing a `learnomy_purchase_completed` listener — no Stripe-side
	 * code changes required.
	 *
	 * @since 2.3.0
	 */
	public function create_purchase_session( array $args ): array {
		$mode = (string) ( $args['mode'] ?? 'one_time' );

		// Translate purchase_type/item_id → legacy course_id/plan_id; alias
		// extra → extra_metadata and item_label → label. Shared across all
		// adapters via Adapter_Registry::normalize_purchase_args().
		$args = Adapter_Registry::normalize_purchase_args( $args, $this );

		return 'subscription' === $mode
			? $this->create_subscription( $args )
			: $this->create_checkout( $args );
	}

	public function create_checkout( array $args ): array {
		$course_id = (int) ( $args['course_id'] ?? 0 );
		$course    = $course_id ? Course::find( $course_id ) : null;

		// Recurring course → subscription-mode (incomplete Subscription).
		if ( $course && Course::is_recurring( $course ) ) {
			return $this->create_course_subscription_checkout( $course, $args );
		}

		// One-time purchase → PaymentIntent. The card is collected on-page by
		// Stripe Payment Element (themed to the site), so we hand the browser a
		// client_secret instead of a hosted/embedded session. The webhook
		// (payment_intent.succeeded) provisions enrollment from this metadata.
		// Price, tax and total all arrive already worked out — see
		// Adapter_Registry::normalize_purchase_args(). This adapter used to add
		// the tax itself, which is how two of the four gateways ended up
		// charging a different total for the same course.
		$currency = strtolower( (string) ( $args['currency'] ?? 'usd' ) );
		$subtotal = (float) ( $args['subtotal'] ?? $args['amount'] ?? 0 );
		$tax      = (float) ( $args['tax'] ?? 0 );
		$total    = round( (float) ( $args['amount'] ?? 0 ), 2 );

		$params = [
			'amount'                             => \Learnomy\to_minor_unit( $total, $currency ),
			'currency'                           => $currency,
			'description'                        => $this->resolve_line_item_name( $args ),
			'automatic_payment_methods[enabled]' => 'true',
			'metadata[learnomy_user_id]'         => $args['user_id'] ?? 0,
			'metadata[learnomy_course_id]'       => $course_id,
			'metadata[learnomy_plan_id]'         => $args['plan_id'] ?? 0,
			// coupon_id rides along in metadata so the webhook can record usage
			// after payment succeeds (see handle_payment_intent_succeeded()).
			'metadata[learnomy_coupon_id]'       => $args['coupon_id'] ?? 0,
			'metadata[learnomy_tax]'             => number_format( $tax, 2, '.', '' ),
		];

		// Fixed-term courses pass the access window so the webhook can
		// stamp enrollment.expires_at without re-reading the course row.
		if ( $course && Course::is_fixed_term( $course ) ) {
			$days = isset( $course->access_duration_days ) ? (int) $course->access_duration_days : 0;
			if ( $days > 0 ) {
				$params['metadata[learnomy_access_duration_days]'] = $days;
			}
		}

		$this->apply_external_purchase_metadata( $params, $args );

		$intent = $this->api_post( '/payment_intents', $params );
		if ( is_wp_error( $intent ) || empty( $intent->client_secret ) ) {
			return [];
		}

		return [
			'client_secret'     => (string) $intent->client_secret,
			'payment_intent_id' => (string) $intent->id,
			'subtotal'          => $subtotal,
			'tax'               => $tax,
			'total'             => $total,
		];
	}

	/**
	 * Course-level subscription checkout. Lazy-creates the Stripe Price on
	 * first purchase and caches the ID on the course row, then opens an
	 * incomplete Subscription whose first-invoice PaymentIntent the browser
	 * confirms with Payment Element.
	 */
	private function create_course_subscription_checkout( object $course, array $args ): array {
		$amount = Course::recurring_amount( $course );
		if ( null === $amount || $amount <= 0 ) {
			return array();
		}

		$meta = array(
			'learnomy_user_id'   => (int) ( $args['user_id'] ?? 0 ),
			'learnomy_course_id' => (int) $course->id,
			'learnomy_plan_id'   => 0,
			'learnomy_coupon_id' => (int) ( $args['coupon_id'] ?? 0 ),
		);

		// Same rule as memberships: the course row is the price, read fresh on
		// every checkout and sent inline. Nothing stored, so editing a recurring
		// course's price takes effect on the next sale instead of billing the
		// figure that happened to be set the first time anyone bought it.
		$result = $this->open_subscription_intent(
			'',
			(int) ( $args['user_id'] ?? 0 ),
			$meta,
			array_merge(
				$args,
				array(
					'amount'        => $amount,
					'interval'      => Course::recurring_interval( $course ) ?? 'month',
					'label'         => (string) ( $course->title ?? '' ),
					'purchase_type' => 'course',
					'item_id'       => (int) $course->id,
					'plan_id'       => 0,
				)
			)
		);

		return $this->subscription_result( $result );
	}






	/**
	 * Opens a Stripe Checkout Session in subscription mode. Resolves the Stripe
	 * Price ID from (in order) the caller, the plan-provider mapping, the legacy
	 * option, a lazy-price cache, and finally lazy-creates one from the amount +
	 * interval so no recurring product ever needs a manual Price-ID paste.
	 */
	public function create_subscription( array $args ): array {
		$plan_id  = (int) ( $args['plan_id'] ?? 0 );
		$settings = get_option( 'learnomy_settings', [] );
		$mode     = is_array( $settings ) ? ( (string) ( $settings['stripe_mode'] ?? 'test' ) ) : 'test';

		// The plan's trial goes to Stripe too. Our own subscription row already
		// recorded `trialing` + trial_ends_at (Subscription_Service), but the
		// Stripe subscription was created without a trial — so the member's
		// page said "trial" while Stripe billed them from day one. Same class
		// as the spaces trial gap (Basecamp 10172704327). Callers that resolve
		// the trial themselves (Pro extensions) pass trial_days explicitly and
		// win; this only fills the gap for the membership path.
		if ( ! isset( $args['trial_days'] ) && $plan_id ) {
			$plan_row           = \Learnomy\Models\MembershipPlan::find( $plan_id );
			$args['trial_days'] = (int) ( $plan_row->trial_days ?? 0 );
		}

		// Pro extensions (Spaces, future product types) drive subscription
		// checkout by passing an explicit price_id rather than going
		// through the lrn_membership_plan_providers lookup. Honour the
		// caller-supplied value first.
		$price_id = (string) ( $args['price_id'] ?? '' );

		// Stored Price ids are deliberately NOT consulted. A Stripe Price has the
		// amount baked into it, so any id we keep is a second copy of the price —
		// and `lrn_membership_plan_providers` was keyed on (plan, gateway, mode)
		// with no amount and no interval, so it could not even represent a price
		// change or a second cadence. It served the old figure after an edit and
		// a monthly Price to a yearly signup. Both stop existing as failure modes
		// once nothing is stored: open_subscription_intent() reads the amount off
		// our own row and sends it inline, every time.
		//
		// Legacy rows are left on disk rather than read, so an install that
		// upgrades mid-life cannot be charged from one.

		$meta = array(
			'learnomy_user_id'       => (int) ( $args['user_id'] ?? 0 ),
			'learnomy_plan_id'       => $plan_id,
			// The term the buyer agreed to, carried on the Stripe subscription
			// so the webhook that creates our row minutes later still knows it.
			// Without this the cycle dies at the REST boundary and every
			// Stripe subscription is recorded on the plan's default interval —
			// the yearly-billed-monthly bug, one layer down (Basecamp 10143220483).
			'learnomy_billing_cycle' => (string) ( $args['billing_cycle'] ?? '' ),
		);

		$result = $this->open_subscription_intent( $price_id, (int) ( $args['user_id'] ?? 0 ), $meta, $args );

		return $this->subscription_result( $result );
	}

	/**
	 * The recurring terms to bill, read from our own row and sent inline.
	 *
	 * Stripe will not take a bare amount on a subscription the way it does on a
	 * PaymentIntent — it wants a Price. `price_data` lets us hand it the figure
	 * at checkout time and have it mint the Price itself, which keeps our row
	 * the single source of truth for money and leaves nothing to go stale.
	 *
	 * The one thing that must pre-exist is a PRODUCT, because `product_data` is
	 * rejected on subscription items. That is fine: a Product carries a NAME and
	 * no money, so the only identifier we cache cannot cause a wrong charge.
	 * Renaming a plan can never mis-bill anyone.
	 *
	 * Public because Pro needs the SAME builder. Membership Pro's proration
	 * changes the price on an existing subscription, and if it built its own
	 * price params the upgrade path and the checkout path could disagree about
	 * what a plan costs — which is the whole class of bug this replaced. One
	 * builder, both callers.
	 *
	 * @param array $args create_purchase_session args.
	 * @return array<string,mixed> price_data params, or empty when unpriceable.
	 */
	public function subscription_price_terms( array $args ): array {
		$plan_id  = (int) ( $args['plan_id'] ?? 0 );
		$amount   = isset( $args['amount'] ) ? (float) $args['amount'] : 0.0;
		$currency = strtolower( (string) ( $args['currency'] ?? 'usd' ) );
		$interval = (string) ( $args['interval'] ?? '' );
		$label    = (string) ( $args['label'] ?? $args['item_label'] ?? '' );

		// Fill gaps from the plan row when only a plan_id was passed.
		if ( $plan_id && ( $amount <= 0 || '' === $interval || '' === $label ) ) {
			$plan = \Learnomy\Models\MembershipPlan::find( $plan_id );
			if ( $plan ) {
				if ( $amount <= 0 ) {
					$amount = (float) ( $plan->price ?? 0 );
				}
				if ( '' === $interval ) {
					$bi       = (string) ( $plan->billing_interval ?? 'monthly' );
					$interval = ( 'yearly' === $bi || 'year' === $bi ) ? 'year' : 'month';
				}
				if ( '' === $label ) {
					$label = (string) ( $plan->title ?? '' );
				}
			}
		}

		$interval = ( 'year' === $interval || 'yearly' === $interval ) ? 'year' : 'month';
		if ( $amount <= 0 ) {
			return array();
		}
		if ( '' === $label ) {
			$label = __( 'Membership', 'learnomy' );
		}

		$ptype      = sanitize_key( (string) ( $args['purchase_type'] ?? ( $plan_id ? 'membership' : 'item' ) ) );
		$item_id    = $plan_id ?: (int) ( $args['item_id'] ?? 0 );
		$product_id = $this->ensure_product( $label, $ptype, $item_id );
		if ( '' === $product_id ) {
			return array();
		}

		return array(
			'items[0][price_data][unit_amount]'         => \Learnomy\to_minor_unit( $amount, $currency ),
			'items[0][price_data][currency]'            => $currency,
			'items[0][price_data][recurring][interval]' => $interval,
			'items[0][price_data][product]'             => $product_id,
		);
	}

	/**
	 * The Stripe Product a recurring item bills against, created once and reused.
	 *
	 * Cached per (type, item, mode). Deliberately NOT per amount: a Product has
	 * no price on it, so unlike a Price it cannot drift out of step with what we
	 * charge. If the id is stale (the account was swapped, the product deleted)
	 * Stripe answers `resource_missing` and we mint a fresh one.
	 *
	 * @param string $label   Human name shown on the Stripe invoice.
	 * @param string $ptype   Purchase type ('membership', 'course', …).
	 * @param int    $item_id Plan or course id.
	 * @return string Product id, or '' when it could not be created.
	 */
	private function ensure_product( string $label, string $ptype, int $item_id ): string {
		$settings  = get_option( 'learnomy_settings', array() );
		$mode      = is_array( $settings ) ? ( (string) ( $settings['stripe_mode'] ?? 'test' ) ) : 'test';
		$cache_key = 'learnomy_stripe_product_' . $ptype . '_' . $item_id . '_' . $mode;

		$cached = (string) get_option( $cache_key, '' );
		if ( '' !== $cached ) {
			$existing = $this->api_request( 'GET', '/products/' . rawurlencode( $cached ), array() );
			if ( ! is_wp_error( $existing ) && ! empty( $existing->id ) && empty( $existing->deleted ) ) {
				return $cached;
			}
			delete_option( $cache_key );
		}

		$product = $this->api_post( '/products', array( 'name' => $label ) );
		if ( is_wp_error( $product ) || empty( $product->id ) ) {
			return '';
		}

		update_option( $cache_key, (string) $product->id, false );
		return (string) $product->id;
	}

	/**
	 * Open an incomplete Stripe Subscription and return the first invoice's
	 * PaymentIntent client_secret for Payment Element to confirm on-page.
	 * Returns `error_code` so the caller can self-heal a missing Price.
	 *
	 * @param string              $price_id Stripe recurring Price ID.
	 * @param int                 $user_id  Buyer's WP user id (for the Customer).
	 * @param array<string,mixed> $meta    Subscription metadata (learnomy_* keys).
	 * @param array               $args     create_purchase_session args.
	 * @return array{client_secret?:string,subscription_id?:string,error_code?:string}
	 */
	private function open_subscription_intent( string $price_id, int $user_id, array $meta, array $args ): array {
		$customer_id = $this->ensure_customer( $user_id, $args );
		if ( '' === $customer_id ) {
			return array();
		}

		$params = array(
			'customer'         => $customer_id,
			'payment_behavior' => 'default_incomplete',
			'payment_settings[save_default_payment_method]' => 'on_subscription',
			// BOTH expansions, because the field we need moved between API
			// versions (Stripe_Payload::subscription_client_secret reads either).
			// Stripe ignores an expand path it does not recognise instead of
			// erroring, so sending both is safe in both directions and sending
			// only the wrong one is silent — which is exactly how every Stripe
			// subscription purchase came to fail with "the payment provider did
			// not return a subscription URL" while a real incomplete
			// Subscription, Customer and open Invoice existed at Stripe.
			'expand[0]'        => 'latest_invoice.payment_intent',
			'expand[1]'        => 'latest_invoice.confirmation_secret',
			// A trialing subscription's first invoice is $0, so there is no
			// PaymentIntent to confirm — Stripe collects the card through the
			// subscription's pending SetupIntent instead. Expanded always
			// (ignored when absent), read as the secret's last fallback.
			'expand[2]'        => 'pending_setup_intent',
		);

		// Trial: the plan's trial_days arrives via create_purchase_session args
		// (spaces + memberships both pass it). Stripe starts the subscription
		// as `trialing` and bills the first period when the trial ends. Without
		// this the trial existed only on OUR row — the owner configured "free
		// first month" and Stripe billed from day one (Basecamp 10172704327).
		$trial_days = (int) ( $args['trial_days'] ?? 0 );
		if ( $trial_days > 0 ) {
			$params['trial_period_days'] = $trial_days;
		}
		// What to bill, and how much.
		//
		// A configured Price id is an explicit choice by the owner (the plan
		// editor's "Stripe Price ID" field) or by a Pro extension driving its own
		// product, so it is honoured verbatim. Otherwise we do what every other
		// gateway does and what our own one-time path already does: READ THE
		// PRICE OFF OUR OWN ROW AND SEND IT.
		//
		// This is why a stored Price id is not the default. Stripe's Price is a
		// separate object with the amount baked in, so caching one means caching
		// money — and the moment an owner edited a plan, the page showed the new
		// figure while Stripe kept charging the old one, on every sale, silently.
		// Sending the amount inline makes that impossible by construction: there
		// is no second copy of the price to disagree with the first.
		//
		// Existing subscribers are unaffected, which is also the correct
		// behaviour: their subscription references the Price minted when they
		// signed up, so a price change applies to new sales and never silently
		// re-prices someone who already agreed to a figure.
		if ( '' !== $price_id ) {
			$params['items[0][price]'] = $price_id;
		} else {
			$terms = $this->subscription_price_terms( $args );
			if ( empty( $terms ) ) {
				return array();
			}
			$params += $terms;
		}

		foreach ( $meta as $meta_key => $meta_value ) {
			$params[ 'metadata[' . $meta_key . ']' ] = $meta_value;
		}

		// Carry purchase_type + extra metadata for external subscriptions
		// (Spaces, etc.) onto the subscription so the first-invoice webhook can
		// fulfil them.
		$this->apply_external_purchase_metadata( $params, $args );

		// Uniform tax as a Stripe TaxRate on the subscription's line.
		$tax_rate_id = $this->ensure_uniform_tax_rate();
		if ( '' !== $tax_rate_id ) {
			$params['default_tax_rates[0]'] = $tax_rate_id;
		}

		$sub = $this->api_post( '/subscriptions', $params );

		if ( is_object( $sub ) && isset( $sub->error ) ) {
			return array( 'error_code' => (string) ( $sub->error->code ?? '' ) );
		}
		if ( is_wp_error( $sub ) || empty( $sub->id ) ) {
			return array();
		}

		$client_secret = Stripe_Payload::subscription_client_secret( $sub );

		if ( '' === $client_secret ) {
			// Never fail silently here: a Subscription, a Customer and an open
			// Invoice now exist at Stripe, so "no secret" is not a harmless
			// no-op — it is a live incomplete subscription the site cannot see.
			// Say which subscription, so an owner can find and void it.
			error_log(  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Learnomy Stripe: subscription %s was created but returned no client_secret. '
					. 'Neither latest_invoice.confirmation_secret nor latest_invoice.payment_intent was present — '
					. 'check the API version pinned to this account. The subscription is INCOMPLETE at Stripe and should be voided.',
					(string) $sub->id
				)
			);
			return array();
		}

		return array(
			'client_secret'   => (string) $client_secret,
			'subscription_id' => (string) $sub->id,
		);
	}

	/**
	 * Normalise an open_subscription_intent() result to the public shape.
	 *
	 * @param array $result Raw intent result.
	 * @return array{client_secret?:string,subscription_id?:string}
	 */
	private function subscription_result( array $result ): array {
		return isset( $result['client_secret'] )
			? array(
				'client_secret'   => $result['client_secret'],
				'subscription_id' => $result['subscription_id'] ?? '',
			)
			: array();
	}

	/**
	 * Resolve (and cache per mode) the Stripe Customer for a buyer. Stripe
	 * subscriptions require a Customer; we reuse one per WP user so a returning
	 * member doesn't spawn duplicates. Guests (user_id 0) get an ad-hoc customer.
	 *
	 * @param int   $user_id Buyer's WP user id (0 for guest).
	 * @param array $args    create_purchase_session args (may carry an email).
	 * @return string Stripe Customer ID, or '' on failure.
	 */
	private function ensure_customer( int $user_id, array $args = array() ): string {
		$settings = get_option( 'learnomy_settings', array() );
		$mode     = is_array( $settings ) ? ( (string) ( $settings['stripe_mode'] ?? 'test' ) ) : 'test';

		if ( $user_id <= 0 ) {
			$email = sanitize_email( (string) ( $args['email'] ?? '' ) );
			$cust  = $this->api_post( '/customers', array_filter( array( 'email' => $email ) ) );
			return ( is_object( $cust ) && ! empty( $cust->id ) ) ? (string) $cust->id : '';
		}

		$meta_key = 'learnomy_stripe_customer_' . $mode;
		$stored   = (string) get_user_meta( $user_id, $meta_key, true );
		if ( '' !== $stored ) {
			return $stored;
		}

		$user = get_userdata( $user_id );
		$cust = $this->api_post(
			'/customers',
			array(
				'email'                      => $user ? $user->user_email : '',
				'name'                       => $user ? $user->display_name : '',
				'metadata[learnomy_user_id]' => $user_id,
			)
		);
		if ( is_wp_error( $cust ) || empty( $cust->id ) ) {
			return '';
		}

		update_user_meta( $user_id, $meta_key, (string) $cust->id );
		return (string) $cust->id;
	}


	/**
	 * The configured uniform tax rate (percent), or 0 when tax is off. This is
	 * the "level 1" flat rate for basic compliance; per-region tax belongs in
	 * the WooCommerce gateway.
	 *
	 * @return float Percentage in [0, 100].
	 */
	private function uniform_tax_rate(): float {
		// Delegates to the ONE reader. This used to own the logic privately, which is
		// exactly how the PayPal adapter shipped with no tax at all.
		return \Learnomy\tax_rate();
	}


	/**
	 * Resolve (and cache per mode + rate) a Stripe TaxRate object for the
	 * uniform rate, so subscriptions itemise tax on Stripe's invoices. Returns
	 * '' when tax is off or the API call fails.
	 *
	 * @return string Stripe TaxRate ID (txr_*), or ''.
	 */
	private function ensure_uniform_tax_rate(): string {
		$rate = $this->uniform_tax_rate();
		if ( $rate <= 0 ) {
			return '';
		}

		$settings = get_option( 'learnomy_settings', array() );
		$mode     = is_array( $settings ) ? (string) ( $settings['stripe_mode'] ?? 'test' ) : 'test';
		$label    = ( is_array( $settings ) && ! empty( $settings['tax_label'] ) ) ? (string) $settings['tax_label'] : __( 'Tax', 'learnomy' );

		$cache_key = 'learnomy_stripe_taxrate_' . $mode . '_' . str_replace( '.', '_', (string) $rate );
		$cached    = (string) get_option( $cache_key, '' );
		if ( '' !== $cached ) {
			return $cached;
		}

		$txr = $this->api_post(
			'/tax_rates',
			array(
				'display_name' => $label,
				'percentage'   => $rate,
				'inclusive'    => 'false',
			)
		);
		if ( is_wp_error( $txr ) || empty( $txr->id ) ) {
			return '';
		}

		update_option( $cache_key, (string) $txr->id, false );
		return (string) $txr->id;
	}

	/**
	 * Cancels a subscription at the end of the current billing period.
	 * Stripe marks it as cancel_at_period_end by default; we force immediate
	 * cancellation here because Learnomy manages access windows itself.
	 */
	public function cancel_subscription( string $provider_sub_id ): bool {
		$result = $this->api_request( 'DELETE', '/subscriptions/' . sanitize_text_field( $provider_sub_id ), [] );
		return ! is_wp_error( $result ) && isset( $result->status );
	}

	/**
	 * Creates a Stripe Customer Portal session for the customer attached to a
	 * given subscription. The portal lets the buyer update their payment
	 * method, view Stripe-side invoice history, change billing address, and
	 * cancel their subscription — all hosted by Stripe so we never touch
	 * card data.
	 *
	 * Two API roundtrips: GET the subscription to extract the customer ID, then
	 * POST a billing_portal/sessions request. The customer ID is not persisted
	 * on the WP user; resolving it on demand keeps the data path simple at the
	 * cost of one extra GET per portal-open click (negligible for a button).
	 *
	 * Returns the hosted portal URL on success, or WP_Error if the subscription
	 * is unknown, has no customer attached, or the portal request fails. Common
	 * failure when the portal hasn't been configured in the Stripe Dashboard
	 * (Settings → Billing → Customer portal); error message points the operator
	 * at the dashboard.
	 *
	 * **Every one of these WP_Errors carries a `status`.** They are all
	 * anticipated conditions — Stripe keys not entered, our row missing its
	 * Stripe id, the portal not switched on in the dashboard — and without a
	 * status `Base_Controller::error_response()` falls back to 500, so a site
	 * that simply has not finished its Stripe setup reads to monitoring as a
	 * server crash. 503 means "this site is not set up to do that (yet)"; 422
	 * means "the record we hold cannot produce a portal session".
	 *
	 * @return string|\WP_Error Hosted portal URL on success.
	 */
	public function create_billing_portal_session( string $provider_sub_id, string $return_url ) {
		if ( '' === $this->secret_key() ) {
			return new \WP_Error(
				'learnomy_stripe_not_configured',
				__( 'Stripe is not configured.', 'learnomy' ),
				[ 'status' => 503 ]
			);
		}

		$sub_id = sanitize_text_field( $provider_sub_id );
		if ( '' === $sub_id ) {
			return new \WP_Error(
				'learnomy_invalid_subscription',
				__( 'Subscription is missing a Stripe identifier.', 'learnomy' ),
				[ 'status' => 422 ]
			);
		}

		$subscription = $this->api_request( 'GET', '/subscriptions/' . rawurlencode( $sub_id ), [] );
		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}
		if ( ! is_object( $subscription ) || empty( $subscription->customer ) ) {
			return new \WP_Error(
				'learnomy_stripe_customer_missing',
				__( 'Stripe did not return a customer for this subscription.', 'learnomy' ),
				[ 'status' => 422 ]
			);
		}

		$session = $this->api_post(
			'/billing_portal/sessions',
			[
				'customer'   => (string) $subscription->customer,
				'return_url' => esc_url_raw( $return_url ),
			]
		);

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( ! is_object( $session ) || empty( $session->url ) ) {
			$detail = is_object( $session ) && ! empty( $session->error->message )
				? (string) $session->error->message
				: __( 'Stripe rejected the billing portal request. Configure the portal in your Stripe Dashboard (Settings > Billing > Customer portal) and try again.', 'learnomy' );
			return new \WP_Error( 'learnomy_stripe_portal_unavailable', $detail, [ 'status' => 503 ] );
		}

		return (string) $session->url;
	}

	/**
	 * Processes inbound Stripe webhooks.
	 *
	 * Every webhook is logged to lrn_webhook_events BEFORE processing so the
	 * admin has a permanent audit trail and can replay failed events. The
	 * log row is written with outcome='pending' and updated to the real
	 * outcome ('handled', 'rejected', 'error') after the switch runs.
	 *
	 * Idempotency: if log() returns 0 the UNIQUE constraint fired — we have
	 * seen this event_id before. Increment retry_count and return early.
	 *
	 * Signature is verified AFTER logging so invalid attempts are also
	 * recorded (signature_status='invalid'). This lets ops audit replay
	 * attacks without digging through server logs.
	 *
	 * Handles: checkout.session.completed, checkout.session.async_payment_succeeded,
	 *          checkout.session.async_payment_failed, payment_intent.succeeded,
	 *          invoice.payment_succeeded, invoice.payment_failed,
	 *          customer.subscription.deleted, customer.subscription.updated,
	 *          charge.refunded. Anything else is logged 'ignored'.
	 *
	 * @param \WP_REST_Request $request Inbound Stripe webhook request.
	 * @throws \InvalidArgumentException When the signature is invalid or the payload is malformed.
	 */
	// phpcs:ignore Squiz.Commenting.FunctionCommentThrowTag.WrongNumber -- both throws are the same exception type; two identical @throws tags dedupe to one.
	public function process_webhook( \WP_REST_Request $request ): void {
		$payload    = $request->get_body();
		$sig_header = $request->get_header( 'stripe-signature' );
		$wh_secret  = $this->webhook_secret();

		// Determine signature status and parse the event body before logging.
		// We wrap this in a try/catch so a malformed payload still gets logged.
		$sig_status = 'missing';
		$event      = null;
		$event_id   = '';
		$event_type = '';

		try {
			if ( ! empty( $wh_secret ) ) {
				$sig_status = $this->verify_stripe_signature( $payload, $sig_header ?? '', $wh_secret )
					? 'valid'
					: 'invalid';
			}
			$event      = json_decode( (string) $payload );
			$event_id   = isset( $event->id ) ? (string) $event->id : '';
			$event_type = isset( $event->type ) ? (string) $event->type : '';
		} catch ( \Throwable $e ) {
			// Unparseable payload — treat as an invalid signature. The
			// pre-initialised event/id/type defaults stand so the delivery is
			// still logged below with sig_status='invalid'.
			$sig_status = 'invalid';
		}

		// Log the event row up front so every delivery is on record.
		$log_id = Webhook_Event::log(
			[
				'provider'         => 'stripe',
				'event_type'       => $event_type,
				'event_id'         => $event_id,
				'payload_json'     => $payload,
				'signature_status' => $sig_status,
				'outcome'          => 'pending',
				'error_message'    => null,
			]
		);

		// log() returns 0 on UNIQUE violation — provider already processed this event.
		// Only ack a duplicate that ALSO carries a valid signature: that's a
		// genuine Stripe re-delivery and 200 stops the retries. A collision with
		// an invalid/missing signature (including the empty event_id of a
		// malformed `{}` body) must NOT 200 — fall through to the signature
		// rejection below so the route returns a consistent 400 every time
		// rather than a stale-200 on the second identical unsigned probe.
		if ( 0 === $log_id ) {
			Webhook_Event::increment_retry_by_event_id( 'stripe', $event_id );
			if ( 'valid' === $sig_status ) {
				// Duplicate delivery — safe to ack with 200; no double-processing.
				status_header( 200 );
				return;
			}
		}

		// Reject events with an invalid or missing signature AFTER logging.
		// We still have a record of the attempt; the adapter did not act on it.
		if ( 'valid' !== $sig_status ) {
			Webhook_Event::update_outcome( $log_id, 'rejected', 'signature_invalid' );
			// status_header() inside a REST callback is silently overridden by the
			// REST server when the callback returns a 2xx WP_REST_Response. Throw so
			// the checkout controller can translate to HTTP 400 explicitly.
			// Stripe retries 5xx indefinitely but stops on 4xx.
			throw new \InvalidArgumentException( 'webhook_signature_invalid' );
		}

		if ( ! $event || empty( $event->type ) ) {
			Webhook_Event::update_outcome( $log_id, 'rejected', 'payload_malformed' );
			throw new \InvalidArgumentException( 'webhook_payload_malformed' );
		}

		// Dispatch and update the outcome row to reflect what happened.
		try {
			switch ( $event->type ) {
				case 'checkout.session.completed':
					$this->handle_checkout_completed( $event->data->object );
					break;

				/*
				 * Delayed payment methods - SEPA Direct Debit, Bacs, BECS,
				 * boleto, OXXO, Konbini - do not settle at checkout. Stripe
				 * sends `completed` immediately with payment_status='unpaid',
				 * then this event days later when the funds actually arrive.
				 *
				 * Without this case the customer paid and got nothing: the
				 * `completed` handler correctly refuses an unpaid session, and
				 * the event that says the money landed reached no handler at
				 * all. Worse, the switch had no default, so the webhook log
				 * recorded it as 'handled'.
				 *
				 * The session object on this event carries payment_status='paid',
				 * so the ordinary handler is the right one - it re-checks the
				 * status itself and is idempotent on the session id, which also
				 * makes a same-request `completed` + `async_succeeded` pair safe.
				 */
				case 'checkout.session.async_payment_succeeded':
					$this->handle_checkout_completed( $event->data->object );
					break;

				/*
				 * The other half. A delayed payment that never clears ends here,
				 * and this IS a genuine failure - unlike the 'unpaid' seen at
				 * completed time, which only means "not yet".
				 */
				case 'checkout.session.async_payment_failed':
					do_action(
						'learnomy_payment_failed',
						(string) ( $event->data->object->id ?? '' ),
						'stripe',
						'async_payment_failed'
					);
					break;

				case 'payment_intent.succeeded':
					$this->handle_payment_intent_succeeded( $event->data->object );
					break;

				case 'invoice.payment_succeeded':
					$this->handle_invoice_paid( $event->data->object );
					break;

				case 'customer.subscription.deleted':
					$this->handle_subscription_cancelled( $event->data->object );
					break;

				case 'invoice.payment_failed':
					$this->handle_invoice_payment_failed( $event->data->object );
					break;

				case 'customer.subscription.updated':
					$this->handle_subscription_updated( $event->data->object, (string) ( $event->id ?? '' ) );
					break;

				case 'charge.refunded':
					$this->handle_charge_refunded( $event->data->object );
					break;

				/*
				 * Anything we do not act on is recorded as ignored, not handled.
				 *
				 * There was no default here, so every unsubscribed or newly
				 * added event type fell through and was logged 'handled' - the
				 * log asserting we processed something we had never seen. That
				 * is how async_payment_succeeded stayed invisible: an owner
				 * checking the webhook log after a customer complained would
				 * have found a green row.
				 */
				default:
					Webhook_Event::update_outcome( $log_id, 'ignored', 'no handler for ' . $event->type );
					return;
			}

			Webhook_Event::update_outcome( $log_id, 'handled' );
		} catch ( \Throwable $e ) {
			Webhook_Event::update_outcome( $log_id, 'error', $e->getMessage() );
			// Re-throw so the REST controller can return a non-200 status.
			throw $e;
		}

		/**
		 * Fires for every verified Stripe webhook event, after the core
		 * handlers above have run. Pro extensions (e.g. Membership_Pro's
		 * Dunning_Service) listen to this to handle subscription events
		 * that Free doesn't care about — invoice.payment_failed,
		 * charge.refunded, etc.
		 *
		 * @since 1.0.0
		 *
		 * @param string $type  Stripe event type (e.g. `invoice.payment_failed`).
		 * @param object $event Full decoded Stripe event payload.
		 */
		do_action( 'learnomy_stripe_webhook_event', (string) $event->type, $event );

		status_header( 200 );
	}

	/**
	 * Issues a refund against a Stripe PaymentIntent or Charge ID.
	 *
	 * On success, also writes a reverse transaction row (with refund_of
	 * set to the original txn id) and revokes the user's enrollment when
	 * the refund is full. Per Plan 00 §1.5 D12: full refund revokes,
	 * partial refund records only.
	 *
	 * Bug fix B2 (PRODUCTION-READINESS.md): previously this method called
	 * the Stripe API and returned bool with zero local side effects, so
	 * refunded customers kept course access and the ledger had no
	 * reverse entry.
	 */
	public function refund( string $transaction_id, float $amount ): string {
		$intent_id = sanitize_text_field( $transaction_id );

		// Convert the refund amount to the CHARGE's own minor unit. A zero-decimal
		// currency (JPY/KRW/…) must not be multiplied by 100 or we over-refund 100x,
		// exactly as the charge side would over-charge. The PaymentIntent is the
		// source of truth for the currency; fall back to USD (×100) only if it
		// can't be read, matching Stripe's default assumption.
		$intent   = $this->api_get( '/payment_intents/' . rawurlencode( $intent_id ) );
		$currency = ( ! is_wp_error( $intent ) && ! empty( $intent->currency ) ) ? (string) $intent->currency : 'usd';

		$params = [
			'payment_intent' => $intent_id,
			'amount'         => \Learnomy\to_minor_unit( $amount, $currency ),
		];

		$result = $this->api_post( '/refunds', $params );

		// The adapter's only job is the gateway call. The ledger reversal and
		// access revocation are gateway-agnostic and run in Refund_Service —
		// invoked by the admin controller (which already holds the original
		// transaction) and by the charge.refunded webhook for dashboard
		// refunds. Keeping it out of here means all four gateways behave the
		// same and the logic lives in one place.
		//
		// Return Stripe's refund id (re_*) so the admin ledger row is keyed on
		// the SAME identifier the charge.refunded echo reconciles against — the
		// empty id the admin path used to pass is what let the echo double-record.
		if ( is_wp_error( $result ) || ! isset( $result->id ) ) {
			return '';
		}
		return (string) $result->id;
	}

	/**
	 * Looks up a Stripe Checkout Session by ID and returns its payment status.
	 *
	 * Maps Stripe's payment_status field to the gateway-agnostic contract
	 * defined in Payment_Adapter::verify_payment_status. Stripe documents
	 * three states on Checkout Sessions:
	 *   - 'paid'                 → 'paid'
	 *   - 'unpaid'               → 'failed' (3DS denied, card declined)
	 *   - 'no_payment_required'  → 'paid' (free / 100%-off coupon)
	 *
	 * The thank-you page calls this when the local lrn_transactions row is
	 * missing (webhook delivery is async). One bounded API call, no retries
	 * inside the adapter — the page itself controls polling cadence.
	 *
	 * @param string $session_id Stripe Checkout Session ID (cs_test_* or cs_live_*).
	 * @return string 'paid' | 'pending' | 'failed' | 'unknown'.
	 */
	public function verify_payment_status( string $session_id ): string {
		if ( '' === $session_id ) {
			return 'unknown';
		}

		// Strip the literal Stripe placeholder — if Stripe didn't substitute
		// {CHECKOUT_SESSION_ID} for the real session id, we have nothing real
		// to look up. The thank-you view treats 'unknown' as the polling state.
		if ( false !== strpos( $session_id, 'CHECKOUT_SESSION_ID' ) ) {
			return 'unknown';
		}

		// No secret key configured — adapter not really available.
		if ( '' === $this->secret_key() ) {
			return 'unknown';
		}

		// On-page Payment Element returns a PaymentIntent id (pi_*), not a
		// Checkout Session — look it up against /payment_intents instead.
		if ( str_starts_with( $session_id, 'pi_' ) ) {
			$intent = $this->api_request( 'GET', '/payment_intents/' . rawurlencode( $session_id ), array() );
			if ( is_wp_error( $intent ) || ! is_object( $intent ) || empty( $intent->id ) ) {
				return 'unknown';
			}
			switch ( (string) ( $intent->status ?? '' ) ) {
				case 'succeeded':
					return 'paid';
				case 'canceled':
					return 'failed';
				default:
					return 'pending';
			}
		}

		$session = $this->api_request( 'GET', '/checkout/sessions/' . rawurlencode( $session_id ), array() );

		if ( is_wp_error( $session ) || ! is_object( $session ) || empty( $session->id ) ) {
			return 'unknown';
		}

		$payment_status = isset( $session->payment_status ) ? (string) $session->payment_status : '';

		switch ( $payment_status ) {
			case 'paid':
			case 'no_payment_required':
				return 'paid';
			case 'unpaid':
				// Distinguish "still processing" from "denied": Stripe sets
				// session.status='open' while the buyer is mid-flow, and
				// status='complete' once the session terminates (regardless
				// of whether payment succeeded). open + unpaid = pending.
				$session_status = isset( $session->status ) ? (string) $session->status : '';
				return 'open' === $session_status ? 'pending' : 'failed';
			default:
				return 'pending';
		}
	}

	/**
	 * Fulfill an on-page (Payment Element) purchase from its PaymentIntent id
	 * WITHOUT waiting for the webhook.
	 *
	 * The webhook (payment_intent.succeeded) is the normal fulfilment path, but
	 * it is the ONLY one — so any install where the webhook is missing,
	 * misconfigured, or temporarily failing takes the customer's money and
	 * delivers nothing (no transaction, no enrollment), while the thank-you
	 * page polls forever. This reconciler closes that gap: it fetches the
	 * intent, and if Stripe reports it succeeded, runs the SAME
	 * handle_payment_intent_succeeded() the webhook would (idempotent via
	 * Transaction::find_by_provider_id — safe to call alongside a webhook that
	 * later arrives). Returns the resolved state: paid / pending / failed /
	 * unknown.
	 */
	public function reconcile_payment_intent( string $intent_id ): string {
		$intent_id = trim( $intent_id );
		if ( '' === $intent_id || ! str_starts_with( $intent_id, 'pi_' ) || '' === $this->secret_key() ) {
			return 'unknown';
		}

		$intent = $this->api_request( 'GET', '/payment_intents/' . rawurlencode( $intent_id ), array() );
		if ( is_wp_error( $intent ) || ! is_object( $intent ) || empty( $intent->id ) ) {
			return 'unknown';
		}

		switch ( (string) ( $intent->status ?? '' ) ) {
			case 'succeeded':
				// Idempotent: returns early if a row already exists (webhook won
				// the race) and only provisions when learnomy_* metadata is
				// present, so a non-Learnomy intent is a no-op.
				$this->handle_payment_intent_succeeded( $intent );
				return 'paid';
			case 'canceled':
				return 'failed';
			default:
				return 'pending';
		}
	}

	/**
	 * Backstop sweep: reconcile recently-succeeded PaymentIntents that never
	 * got a local transaction (the buyer closed the tab before returning to the
	 * thank-you page, so the synchronous reconciler never ran, and the webhook
	 * is down). Lists the most recent intents within the window and fulfils any
	 * succeeded, Learnomy-tagged, not-yet-recorded ones. Returns the count
	 * fulfilled. Scheduled hourly by Cron::reconcile_payments().
	 */
	public function reconcile_recent( int $window_seconds = 172800, int $limit = 100 ): int {
		if ( '' === $this->secret_key() ) {
			return 0;
		}

		$since    = time() - max( 3600, $window_seconds );
		$fulfiled = 0;
		$path     = '/payment_intents?limit=' . min( 100, max( 1, $limit ) )
			. '&created[gte]=' . $since;

		$resp = $this->api_request( 'GET', $path, array() );
		if ( is_wp_error( $resp ) || ! is_object( $resp ) || empty( $resp->data ) || ! is_array( $resp->data ) ) {
			return 0;
		}

		foreach ( $resp->data as $intent ) {
			if ( ! is_object( $intent ) || 'succeeded' !== (string) ( $intent->status ?? '' ) ) {
				continue;
			}
			// Only ours, and only if not already recorded.
			$meta = $intent->metadata ?? null;
			if ( ! is_object( $meta ) || empty( $meta->learnomy_user_id ) ) {
				continue;
			}
			if ( Transaction::find_by_provider_id( (string) $intent->id ) ) {
				continue;
			}
			$this->handle_payment_intent_succeeded( $intent );
			++$fulfiled;
		}

		return $fulfiled;
	}

	// -------------------------------------------------------------------------
	// Webhook event handlers
	// -------------------------------------------------------------------------

	private function handle_checkout_completed( object $session ): void {
		$user_id   = (int) ( $session->metadata->learnomy_user_id ?? 0 );
		$course_id = (int) ( $session->metadata->learnomy_course_id ?? 0 );
		$plan_id   = (int) ( $session->metadata->learnomy_plan_id ?? 0 );
		$coupon_id = (int) ( $session->metadata->learnomy_coupon_id ?? 0 );
		$amount    = \Learnomy\from_minor_unit( $session->amount_total ?? 0, $session->currency ?? 'usd' );
		// Read the tax HERE, not further down. It used to be computed after the
		// external-purchase branch had already returned, so a space or gift sale
		// recorded no tax and the commission engine split it as revenue — the
		// same defect fixed separately in WooCommerce and PayPal. One read,
		// before either branch.
		$tax      = \Learnomy\from_minor_unit( $session->total_details->amount_tax ?? 0, $session->currency ?? 'usd' );
		$currency = strtoupper( $session->currency ?? 'USD' );

		if ( ! $user_id ) {
			return;
		}

		// Bug fix B1 (PRODUCTION-READINESS.md): Stripe fires
		// `checkout.session.completed` for any terminal state, including
		// `unpaid` (3DS denied, payment failed). Without this guard we
		// were creating enrollments for unpaid sessions — students got
		// free access while no money was collected.
		//
		// Possible payment_status values (Stripe docs):
		// - 'paid'     → money landed; safe to enroll
		// - 'unpaid'   → session completed but payment failed (3DS denied, card declined)
		// - 'no_payment_required' → free / 100% coupon → safe to enroll
		$payment_status = $session->payment_status ?? 'unpaid';
		if ( ! in_array( $payment_status, array( 'paid', 'no_payment_required' ), true ) ) {
			do_action( 'learnomy_payment_failed', $session->id, 'stripe', $payment_status );
			return;
		}

		// Avoid double-processing if the webhook fires more than once.
		// For subscription-mode sessions, also guard against re-creating the
		// subscription row — idempotency check runs on both the transaction
		// record (one-time) and the subscription row (recurring).
		if ( Transaction::find_by_provider_id( $session->id ) ) {
			return;
		}

		// --- External-purchase branch (gift seats, etc.) ---
		// A paid checkout an extension flagged via create_checkout()'s
		// purchase_type — not a course or membership-plan purchase. Record the
		// payment, then hand the context to the extension through
		// learnomy_external_purchase_completed. No enrollment/subscription is
		// created here; the extension owns the fulfilment.
		$purchase_type = sanitize_key( (string) ( $session->metadata->learnomy_purchase_type ?? '' ) );
		if ( '' !== $purchase_type ) {
			$ext = array();
			foreach ( (array) $session->metadata as $meta_key => $meta_value ) {
				if ( str_starts_with( (string) $meta_key, 'learnomy_ext_' ) ) {
					$ext[ substr( (string) $meta_key, 13 ) ] = (string) $meta_value;
				}
			}

			// For subscription-mode external purchases (Spaces recurring,
			// future bundle types), the provider's subscription ID is the
			// only durable handle for renewal / cancel webhooks. Surface it
			// to the listener through $ext so the extension can persist it.
			if ( 'subscription' === ( $session->mode ?? '' ) && ! empty( $session->subscription ) ) {
				$ext['provider_subscription_id'] = (string) $session->subscription;
			}

			Purchase_Recorder::record(
				array(
					'user_id'     => $user_id,
					'gateway'     => 'stripe',
					'provider_id' => (string) $session->id,
					'type'        => $purchase_type,
					'amount'      => $amount,
					'currency'    => $currency,
					'tax'         => $tax,
					'ext'         => $ext,
				)
			);
			return;
		}

		// --- Subscription-mode branch (runs BEFORE Transaction::record) ---
		// Bug fix S2 (payments-hardening-v1.0.x.md): checkout.session.completed
		// for mode='subscription' must create the lrn_member_subscriptions row.
		// Previously the row was never created, so the first invoice.payment_succeeded
		// renewal called find_by_provider_id() → null → returned early, silently
		// dropping every renewal forever.
		//
		// Order matters: create the subscription FIRST so the resulting ID can
		// be written into the transaction row's subscription_id column.
		//
		// PR-A1: subscription mode now covers BOTH plan-targeted and
		// course-targeted recurring subs. Plan subs go through
		// Subscription_Service::subscribe(); course subs (course_id set,
		// plan_id zero) go through subscribe_to_course() which creates the
		// matching enrollment row with subscription_id back-reference.
		$subscription_id = null;
		if ( 'subscription' === ( $session->mode ?? '' ) ) {
			$provider_sub_id = (string) ( $session->subscription ?? '' );

			if ( $provider_sub_id ) {
				$existing = Subscription::find_by_provider_id( $provider_sub_id );
				if ( $existing ) {
					$subscription_id = (int) $existing->id;
				} elseif ( $plan_id ) {
					$subscription_id = ( new Subscription_Service() )->subscribe(
						$user_id,
						$plan_id,
						'stripe',
						$provider_sub_id,
						// The cycle the buyer picked, round-tripped through Stripe
						// metadata. Empty (older sessions) falls back to the plan's
						// own interval — the pre-fix behaviour, never a wrong one.
						(string) ( $session->metadata->learnomy_billing_cycle ?? '' )
					) ?: null;
				} elseif ( $course_id ) {
					$subscription_id = ( new Subscription_Service() )->subscribe_to_course(
						$user_id,
						$course_id,
						'stripe',
						$provider_sub_id
					) ?: null;
				}
			}
		}

		// Resolve transaction_type for the new flavors. A course-recurring
		// initial charge is `subscription_course`; plan recurring stays
		// `membership`; one-time/fixed-term courses stay `course`.
		$txn_type = 'course';
		if ( $plan_id ) {
			$txn_type = 'membership';
		} elseif ( $course_id && 'subscription' === ( $session->mode ?? '' ) ) {
			$txn_type = 'subscription_course';
		}

		// Record any tax Stripe collected (Stripe Tax) so the commission engine
		// can set it aside before the split. Zero unless the store enabled tax.

		// Stored as $txn_type (which distinguishes a recurring course) but
		// ANNOUNCED as course/membership, because listeners branch on the latter.
		$txn_id = Purchase_Recorder::record(
			[
				'user_id'         => $user_id,
				'gateway'         => 'stripe',
				'provider_id'     => (string) $session->id,
				'type'            => $txn_type,
				'action_type'     => $plan_id ? 'membership' : 'course',
				'action_item_id'  => $plan_id ? $plan_id : $course_id,
				'course_id'       => $course_id,
				'plan_id'         => $plan_id,
				'subscription_id' => (int) $subscription_id,
				'coupon_id'       => $coupon_id,
				'amount'          => $amount,
				'currency'        => $currency,
				'tax'             => $tax,
			]
		);

		// --- One-time purchase branch ---
		// Course enrollment for direct (non-membership, non-subscription)
		// paid purchases. Subscription enrollments (membership plans AND
		// course-level recurring) are created inside the matching
		// Subscription_Service::subscribe*() call above.
		if ( $course_id && 'payment' === ( $session->mode ?? 'payment' ) ) {
			$course        = Course::find( $course_id );
			$enrollment_id = ( new Enrollment_Service() )->enroll( $user_id, $course_id, 'purchase' );

			if ( is_wp_error( $enrollment_id ) || (int) $enrollment_id <= 0 ) {
				// Customer was charged but not enrolled (e.g. a pre-enrol gate
				// vetoed it). Never silent — audit + self-heal/alert hook.
				Enrollment_Service::report_purchase_failure(
					$user_id,
					$course_id,
					$enrollment_id,
					'stripe',
					array( 'session_id' => (string) ( $session->id ?? '' ) )
				);
			} else {
				// Fixed-term courses: stamp expires_at from session metadata
				// (set by create_checkout()) so a re-buy after expiry has the
				// freshest window. Enrollment_Service::enroll() already auto-
				// derives this from the course row, but the metadata path
				// honours an admin override if the course's stored
				// access_duration_days changed mid-checkout.
				$override_days = (int) ( $session->metadata->learnomy_access_duration_days ?? 0 );
				if ( $course && Course::is_fixed_term( $course ) && $override_days > 0 ) {
					$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $override_days * DAY_IN_SECONDS ) );
					Enrollment::update( (int) $enrollment_id, array( 'expires_at' => $expires_at ) );
				}
			}
		}

		if ( $coupon_id && $txn_id ) {
			( new Coupon_Service() )->apply( $coupon_id, $user_id, (int) $txn_id );
		}

		// Unified canonical action: course OR membership initial purchase.
		// purchase_type is the high-level kind, item_id is whichever entity
		// was bought (course_id for course / subscription_course, plan_id
		// for membership). Listeners hooking learnomy_purchase_completed see
		// every payment regardless of product type.
	}

	private function handle_invoice_paid( object $invoice ): void {
		$provider_sub_id = Stripe_Payload::invoice_subscription_id( $invoice );
		if ( '' === $provider_sub_id ) {
			return;
		}

		/**
		 * Generic subscription-renewed event. Fires for EVERY recurring
		 * invoice paid, regardless of whether the subscription is a
		 * membership plan, a course recurring sub, a Pro Space, or any
		 * future product type. Pro extensions listen here to extend their
		 * own period rows without needing to be present in
		 * lrn_member_subscriptions.
		 *
		 * @since 2.4.0
		 *
		 * @param string $provider_sub_id Stripe subscription ID.
		 * @param object $invoice         Full Stripe invoice payload.
		 * @param string $gateway         Always 'stripe' here.
		 */
		do_action( 'learnomy_subscription_renewed_raw', (string) $provider_sub_id, $invoice, 'stripe' );

		$sub = Subscription::find_by_provider_id( $provider_sub_id );
		if ( ! $sub ) {
			// First invoice of a Payment-Element subscription: there is no
			// checkout.session.completed event in this flow, so the very first
			// paid invoice is what creates the subscription row + enrollment.
			// Subsequent invoices (renewals) find the row and fall through to the
			// renewal path below.
			if ( 'subscription_create' === (string) ( $invoice->billing_reason ?? '' ) ) {
				$this->provision_initial_subscription( $provider_sub_id, $invoice );
				return;
			}

			// Genuinely orphaned (out-of-order delivery, or a sub we don't own).
			// Stripe will re-deliver, or the admin can replay from the log.
			do_action( 'learnomy_stripe_invoice_orphan', $provider_sub_id, $invoice );
			return;
		}

		// Do not renew a subscription that has already been cancelled or expired
		// — a stale invoice.payment_succeeded can fire after customer.subscription.deleted
		// if Stripe's delivery order is reversed.
		$live_statuses = array( 'active', 'trialing', 'past_due' );
		if ( ! in_array( $sub->status, $live_statuses, true ) ) {
			return;
		}

		// The period the member just BOUGHT, which is not the invoice-level one
		// (see Stripe_Payload::invoice_billed_period — that pair is the period
		// which just ended, so renewing from it expired the member the instant
		// they paid). Fall back to a month forward only if the payload carries
		// no usable period at all; never renew to a point in the past.
		$billed       = Stripe_Payload::invoice_billed_period( $invoice );
		$period_start = $billed['start'] > 0 ? $billed['start'] : time();
		$period_end   = $billed['end'] > $period_start ? $billed['end'] : ( $period_start + 30 * DAY_IN_SECONDS );

		$membership_service = new Subscription_Service();
		$membership_service->renew(
			(int) $sub->id,
			gmdate( 'Y-m-d H:i:s', $period_start ),
			gmdate( 'Y-m-d H:i:s', $period_end )
		);

		// Record a renewal transaction so the ledger stays complete. The first
		// invoice is already recorded as transaction_type='membership' by
		// handle_checkout_completed(); subsequent invoices are 'membership_renewal'.
		$renewal_tax = \Learnomy\from_minor_unit( Stripe_Payload::invoice_tax( $invoice ), $invoice->currency ?? 'usd' );

		Purchase_Recorder::record(
			[
				'user_id'     => (int) $sub->user_id,
				'gateway'     => 'stripe',
				'provider_id' => (string) ( $invoice->id ?? '' ),
				'type'        => 'membership_renewal',
				// Ledger only. The period was already extended by
				// Subscription_Service::renew() above and access never lapsed, so
				// announcing this as a purchase would re-run provisioning every
				// cycle. This site fired no actions before the migration either.
				'fulfil'      => false,
				'plan_id'     => (int) $sub->plan_id,
				'amount'      => \Learnomy\from_minor_unit( $invoice->amount_paid ?? 0, $invoice->currency ?? 'usd' ),
				'currency'    => strtoupper( $invoice->currency ?? 'USD' ),
				'tax'         => $renewal_tax,
			]
		);

		do_action( 'learnomy_membership_invoice_paid', (int) $sub->id, $provider_sub_id );
	}

	/**
	 * Provision a one-time purchase from a succeeded PaymentIntent. This is the
	 * Payment Element equivalent of the one-time + external branches of
	 * handle_checkout_completed(): record the transaction, enroll the buyer (or
	 * hand an external purchase to its extension), apply the coupon, and fire
	 * the unified purchase actions. Idempotent on the PaymentIntent id.
	 *
	 * Subscriptions never reach here — their first charge provisions through
	 * provision_initial_subscription() on invoice.payment_succeeded.
	 *
	 * @param object $intent Stripe PaymentIntent payload.
	 */
	private function handle_payment_intent_succeeded( object $intent ): void {
		$meta = $intent->metadata ?? null;
		if ( ! is_object( $meta ) ) {
			return; // Not one of ours (no learnomy_* metadata).
		}

		$user_id   = (int) ( $meta->learnomy_user_id ?? 0 );
		$course_id = (int) ( $meta->learnomy_course_id ?? 0 );
		$plan_id   = (int) ( $meta->learnomy_plan_id ?? 0 );
		$coupon_id = (int) ( $meta->learnomy_coupon_id ?? 0 );
		$amount    = \Learnomy\from_minor_unit( $intent->amount ?? 0, $intent->currency ?? 'usd' );
		$currency  = strtoupper( (string) ( $intent->currency ?? 'usd' ) );
		$tax       = (float) ( $meta->learnomy_tax ?? 0 );

		if ( ! $user_id ) {
			return;
		}

		// Idempotency — the PaymentIntent id is the durable handle.
		if ( Transaction::find_by_provider_id( (string) $intent->id ) ) {
			return;
		}

		// --- External-purchase branch (gift seats, space one-time, etc.) ---
		// Only for purchase types that are NOT a course or membership-plan buy;
		// `membership`/`course` ride through as a normalization hint but must
		// provision through the dedicated branches below, not the extension hook.
		$purchase_type = sanitize_key( (string) ( $meta->learnomy_purchase_type ?? '' ) );
		if ( '' !== $purchase_type && ! in_array( $purchase_type, array( 'membership', 'course' ), true ) ) {
			$ext = array();
			foreach ( (array) $meta as $meta_key => $meta_value ) {
				if ( str_starts_with( (string) $meta_key, 'learnomy_ext_' ) ) {
					$ext[ substr( (string) $meta_key, 13 ) ] = (string) $meta_value;
				}
			}

			Purchase_Recorder::record(
				array(
					'user_id'     => $user_id,
					'gateway'     => 'stripe',
					'provider_id' => (string) $intent->id,
					'type'        => $purchase_type,
					'amount'      => $amount,
					'currency'    => $currency,
					'tax'         => $tax,
					'ext'         => $ext,
				)
			);

			return;
		}

		$txn_id = Purchase_Recorder::record(
			array(
				'user_id'     => $user_id,
				'gateway'     => 'stripe',
				'provider_id' => (string) $intent->id,
				'type'        => 'course',
				'course_id'   => $course_id,
				'plan_id'     => $plan_id,
				'coupon_id'   => $coupon_id,
				'amount'      => $amount,
				'currency'    => $currency,
				'tax'         => $tax,
			)
		);

		if ( $course_id ) {
			$course        = Course::find( $course_id );
			$enrollment_id = ( new Enrollment_Service() )->enroll( $user_id, $course_id, 'purchase' );

			if ( is_wp_error( $enrollment_id ) || (int) $enrollment_id <= 0 ) {
				Enrollment_Service::report_purchase_failure(
					$user_id,
					$course_id,
					$enrollment_id,
					'stripe',
					array( 'payment_intent' => (string) ( $intent->id ?? '' ) )
				);
			} else {
				$override_days = (int) ( $meta->learnomy_access_duration_days ?? 0 );
				if ( $course && Course::is_fixed_term( $course ) && $override_days > 0 ) {
					$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( $override_days * DAY_IN_SECONDS ) );
					Enrollment::update( (int) $enrollment_id, array( 'expires_at' => $expires_at ) );
				}
			}
		}

		if ( $coupon_id && $txn_id ) {
			( new Coupon_Service() )->apply( $coupon_id, $user_id, (int) $txn_id );
		}
	}

	/**
	 * Provision a subscription from its first paid invoice (Payment Element flow,
	 * billing_reason=subscription_create). Creates the lrn_member_subscriptions
	 * row + enrollment from the subscription's metadata, records the first
	 * transaction, and fires the unified purchase actions. Renewals are handled
	 * by handle_invoice_paid()'s renewal path once this row exists.
	 *
	 * @param string $provider_sub_id Stripe subscription id.
	 * @param object $invoice         Stripe invoice payload.
	 */
	private function provision_initial_subscription( string $provider_sub_id, object $invoice ): void {
		// Subscription metadata rides on the invoice; fall back to a direct
		// fetch if Stripe didn't expand it.
		$meta = Stripe_Payload::invoice_subscription_metadata( $invoice );
		if ( ! is_object( $meta ) ) {
			$sub_obj = $this->api_request( 'GET', '/subscriptions/' . rawurlencode( $provider_sub_id ), array() );
			$meta    = is_object( $sub_obj ) ? ( $sub_obj->metadata ?? null ) : null;
		}
		if ( ! is_object( $meta ) ) {
			do_action( 'learnomy_stripe_invoice_orphan', $provider_sub_id, $invoice );
			return;
		}

		$user_id   = (int) ( $meta->learnomy_user_id ?? 0 );
		$plan_id   = (int) ( $meta->learnomy_plan_id ?? 0 );
		$course_id = (int) ( $meta->learnomy_course_id ?? 0 );
		$amount    = \Learnomy\from_minor_unit( $invoice->amount_paid ?? 0, $invoice->currency ?? 'usd' );
		$currency  = strtoupper( (string) ( $invoice->currency ?? 'usd' ) );
		$tax       = \Learnomy\from_minor_unit( Stripe_Payload::invoice_tax( $invoice ), $invoice->currency ?? 'usd' );

		if ( ! $user_id ) {
			return;
		}
		if ( Transaction::find_by_provider_id( (string) ( $invoice->id ?? '' ) ) ) {
			return; // Already provisioned.
		}

		// --- External subscription branch (Spaces recurring, etc.) ---
		// `membership`/`course` carry purchase_type only as a normalization hint;
		// they provision through the plan/course branch below, not the hook.
		$purchase_type = sanitize_key( (string) ( $meta->learnomy_purchase_type ?? '' ) );
		if ( '' !== $purchase_type && ! in_array( $purchase_type, array( 'membership', 'course' ), true ) ) {
			$ext = array( 'provider_subscription_id' => $provider_sub_id );
			foreach ( (array) $meta as $meta_key => $meta_value ) {
				if ( str_starts_with( (string) $meta_key, 'learnomy_ext_' ) ) {
					$ext[ substr( (string) $meta_key, 13 ) ] = (string) $meta_value;
				}
			}

			Purchase_Recorder::record(
				array(
					'user_id'     => $user_id,
					'gateway'     => 'stripe',
					'provider_id' => (string) ( $invoice->id ?? '' ),
					'type'        => $purchase_type,
					'amount'      => $amount,
					'currency'    => $currency,
					'tax'         => $tax,
					'ext'         => $ext,
				)
			);

			return;
		}

		// --- Plan / course subscription ---
		$subscription_id = null;
		if ( $plan_id ) {
			// Pass the agreed CYCLE, not the invoice total. The invoice answers
			// "what was collected this period" (tax included) and is already
			// recorded on the transaction row; `subscriptions.amount` answers
			// "what does this member pay per period", which is the plan price for
			// the cycle they chose. Conflating the two would write a tax-inclusive
			// figure into the recurring-price column.
			$subscription_id = ( new Subscription_Service() )->subscribe(
				$user_id,
				$plan_id,
				'stripe',
				$provider_sub_id,
				(string) ( $meta->learnomy_billing_cycle ?? '' )
			) ?: null;
		} elseif ( $course_id ) {
			$subscription_id = ( new Subscription_Service() )->subscribe_to_course( $user_id, $course_id, 'stripe', $provider_sub_id ) ?: null;
		}

		$txn_type = $plan_id ? 'membership' : 'subscription_course';

		Purchase_Recorder::record(
			array(
				'user_id'         => $user_id,
				'gateway'         => 'stripe',
				'provider_id'     => (string) ( $invoice->id ?? '' ),
				'type'            => $txn_type,
				'action_type'     => $plan_id ? 'membership' : 'course',
				'action_item_id'  => $plan_id ? $plan_id : $course_id,
				'course_id'       => $course_id,
				'plan_id'         => $plan_id,
				'subscription_id' => (int) $subscription_id,
				'amount'          => $amount,
				'currency'        => $currency,
				'tax'             => $tax,
			)
		);
	}

	private function handle_subscription_cancelled( object $stripe_sub ): void {
		/**
		 * Generic subscription-cancelled event. Fires for EVERY cancelled
		 * Stripe subscription, regardless of product type. Pro extensions
		 * (Spaces, future bundle types) hook this to update their own
		 * subscription state.
		 *
		 * @since 2.4.0
		 *
		 * @param string $provider_sub_id Stripe subscription ID.
		 * @param object $stripe_sub      Full Stripe subscription payload.
		 * @param string $gateway         Always 'stripe' here.
		 */
		do_action( 'learnomy_subscription_cancelled_raw', (string) $stripe_sub->id, $stripe_sub, 'stripe' );

		$sub = Subscription::find_by_provider_id( $stripe_sub->id );
		if ( ! $sub ) {
			return;
		}

		// Per Part C of payments-hardening-v1.0.x.md: cancel gracefully with
		// cancel_at_period_end semantics so the user retains access until their
		// paid period ends. Do NOT call expire() here — that immediately revokes
		// enrollments. The daily subscription sweep cron calls expire() when
		// current_period_end passes.
		//
		// Pass $immediate=false (default) so Subscription_Service::cancel() calls
		// Subscription::flag_cancel_at_period_end() rather than Subscription::cancel(),
		// which preserves access. The learnomy_subscription_cancelled action fires
		// inside cancel() so listeners (notifications, audit log, Pro dunning) react.
		( new Subscription_Service() )->cancel( (int) $sub->id, false );
	}

	/**
	 * Dispatcher for `invoice.payment_failed` — Stripe fires this on each
	 * failed renewal attempt, then escalates to `customer.subscription.deleted`
	 * if dunning exhausts. We fire the generic event so Pro extensions
	 * can mark their subs past_due / start their own dunning UX, and
	 * Membership_Pro's existing dunning service stays in business by
	 * listening to the same event.
	 *
	 * @since 2.4.0
	 */
	private function handle_invoice_payment_failed( object $invoice ): void {
		$provider_sub_id = Stripe_Payload::invoice_subscription_id( $invoice );
		if ( '' === $provider_sub_id ) {
			return;
		}

		// Move the local subscription to past_due through the service so the
		// state transition + the `learnomy_subscription_past_due` hook (dunning,
		// admin "Payment failed" state, emails) fire on EVERY install. Woo already
		// does this (on_subscription_payment_failed); without it a Free-only Stripe
		// site left a failing sub 'active' with no dunning while Woo marked it
		// past_due for the same event — a gateway-parity gap. The _raw action below
		// stays for Pro listeners that want the full invoice payload.
		$sub = Subscription::find_by_provider_id( (string) $provider_sub_id );
		if ( $sub ) {
			( new Subscription_Service() )->mark_past_due( (int) $sub->id, 'stripe_payment_failed' );
		}

		/**
		 * Generic subscription-payment-failed event. Fires for EVERY
		 * failed renewal invoice regardless of product type.
		 *
		 * @since 2.4.0
		 *
		 * @param string $provider_sub_id Stripe subscription ID.
		 * @param object $invoice         Full Stripe invoice payload.
		 * @param string $gateway         Always 'stripe' here.
		 */
		do_action( 'learnomy_subscription_payment_failed_raw', (string) $provider_sub_id, $invoice, 'stripe' );
	}

	/**
	 * Handles `customer.subscription.updated` — fires when Stripe-side state
	 * transitions on a subscription: past_due→active recovery, pause/resume,
	 * plan changes, and cancel-at-period-end scheduling. Without this handler
	 * none of those state changes sync back to the local row.
	 *
	 * Mirrors PayPal's BILLING.SUBSCRIPTION.UPDATED handler. All four branches
	 * (resume, cancel-at-period-end, pause, plan-change) now have first-party
	 * Free handling; Pro extensions still hook the closing action for
	 * proration bookkeeping and customer-notification logic that lives
	 * outside the recurring-billing core.
	 *
	 * Idempotent: each branch no-ops when local state already matches.
	 *
	 * @since 2.4.0
	 *
	 * @param object $subscription Full Stripe subscription payload.
	 * @param string $event_id     Stripe event ID — propagated into the service
	 *                            calls as diagnostic context so Pro listeners
	 *                            can deduplicate downstream side effects.
	 */
	private function handle_subscription_updated( object $subscription, string $event_id = '' ): void {
		$provider_sub_id = isset( $subscription->id ) ? (string) $subscription->id : '';
		if ( '' === $provider_sub_id ) {
			return;
		}

		$membership = Subscription::find_by_provider_id( $provider_sub_id );
		if ( ! $membership ) {
			return;
		}

		$stripe_status        = isset( $subscription->status ) ? (string) $subscription->status : '';
		$cancel_at_period_end = ! empty( $subscription->cancel_at_period_end );
		$membership_service   = new Subscription_Service();
		$service_context      = [
			'source'   => 'stripe_webhook',
			'event_id' => $event_id,
		];

		// past_due → active recovery. Stripe fires this after a successful
		// dunning retry; local row needs to flip back to active so access
		// gates stop showing the "payment failed" UX.
		if ( 'active' === $stripe_status && 'past_due' === $membership->status ) {
			$membership_service->resume( (int) $membership->id );
		}

		// Cancel-at-period-end scheduling. Stripe sets the flag when the
		// customer clicks "Cancel" in the billing portal but keeps access
		// through current_period_end. Mirror the flag locally so our cron
		// sweep + UI both reflect the scheduled cancellation. Idempotent:
		// flag_cancel_at_period_end() is a simple write — re-applying when
		// already set is a no-op.
		if ( $cancel_at_period_end && empty( $membership->cancel_at_period_end ) ) {
			Subscription::flag_cancel_at_period_end( (int) $membership->id );
		}

		// Paused state. Stripe ships subscriptions into `paused` after the
		// customer or merchant pauses billing from the portal. The webhook
		// arrives FROM Stripe — pause() only mirrors the state locally,
		// never re-issues a pause request to Stripe.
		if ( 'paused' === $stripe_status && 'paused' !== (string) ( $membership->status ?? '' ) ) {
			$membership_service->pause( $membership, $service_context );
		}

		// Plan / price change. Stripe portal upgrades / downgrades land here
		// with the new price ID in `items.data[0].price.id`. change_plan()
		// is idempotent on matching IDs, so we can call it unconditionally
		// when we have a price to compare.
		$stripe_price = $this->extract_subscription_price( $subscription );
		if ( null !== $stripe_price ) {
			$local_price_id = (string) ( $membership->provider_price_id ?? '' );
			if ( $stripe_price['price_id'] !== $local_price_id ) {
				$membership_service->change_plan(
					$membership,
					$stripe_price['price_id'],
					$stripe_price['amount'],
					$stripe_price['interval'],
					$service_context
				);
			}
		}

		/**
		 * Stripe customer.subscription.updated event. Fires for every
		 * verified update regardless of which branch (if any) handled it.
		 * Pro extensions hook this for proration on plan changes, pause /
		 * resume bookkeeping, and dunning UX updates. Mirrors the PayPal
		 * equivalent `learnomy_paypal_subscription_updated`.
		 *
		 * @since 2.4.0
		 *
		 * @param object $subscription Full Stripe subscription payload.
		 * @param object $membership   Local lrn_member_subscriptions row.
		 */
		do_action( 'learnomy_stripe_subscription_updated', $subscription, $membership );
	}

	/**
	 * Pull the current price tuple from a Stripe subscription payload.
	 *
	 * Stripe nests the active price under `items.data[0].price`. Stripe
	 * subscriptions can carry multiple items in theory, but the Free
	 * billing model is one-price-per-subscription so we read the first
	 * entry only. Amount is converted from Stripe's smallest-currency
	 * unit (cents for USD/EUR/GBP) to the major unit our `amount` column
	 * stores.
	 *
	 * @param object $subscription Stripe subscription payload.
	 * @return array{price_id:string,amount:float|null,interval:string|null}|null
	 *               Tuple when a price is present, null otherwise.
	 */
	private function extract_subscription_price( object $subscription ): ?array {
		$items = isset( $subscription->items->data ) && is_array( $subscription->items->data )
			? $subscription->items->data
			: [];
		if ( empty( $items ) ) {
			return null;
		}

		$price = $items[0]->price ?? null;
		if ( ! is_object( $price ) ) {
			return null;
		}

		$price_id = isset( $price->id ) ? (string) $price->id : '';
		if ( '' === $price_id ) {
			return null;
		}

		$amount = null;
		if ( isset( $price->unit_amount ) && null !== $price->unit_amount ) {
			$amount = \Learnomy\from_minor_unit( $price->unit_amount, $price->currency ?? 'usd' );
		}

		$interval = null;
		if ( isset( $price->recurring->interval ) && '' !== $price->recurring->interval ) {
			$interval = (string) $price->recurring->interval;
		}

		return [
			'price_id' => $price_id,
			'amount'   => $amount,
			'interval' => $interval,
		];
	}

	// -------------------------------------------------------------------------
	// Stripe API helpers
	// -------------------------------------------------------------------------

	/**
	 * Verifies a Stripe webhook signature using the timing-safe comparison
	 * described in the Stripe docs. No external library needed.
	 */
	private function verify_stripe_signature( string $payload, string $sig_header, string $secret ): bool {
		if ( empty( $secret ) || empty( $sig_header ) ) {
			return false;
		}

		// Parse t= and v1= values from the signature header.
		$parts = [];
		foreach ( explode( ',', $sig_header ) as $chunk ) {
			[ $k, $v ]             = array_pad( explode( '=', $chunk, 2 ), 2, '' );
			$parts[ trim( $k ) ][] = trim( $v );
		}

		$timestamp  = (int) ( $parts['t'][0] ?? 0 );
		$signatures = $parts['v1'] ?? [];

		if ( ! $timestamp || empty( $signatures ) ) {
			return false;
		}

		// Reject if the webhook is more than 5 minutes old.
		if ( abs( time() - $timestamp ) > 300 ) {
			return false;
		}

		$signed_payload = $timestamp . '.' . $payload;
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

		foreach ( $signatures as $v ) {
			if ( hash_equals( $expected, $v ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reconciles a Stripe dashboard / API refund into Learnomy.
	 *
	 * Stripe sends `charge.refunded` whenever a charge is refunded — including
	 * the echo of an admin-initiated refund, which Refund_Service no-ops via
	 * its one-refund-per-original guard. Course and membership purchases store
	 * the Checkout Session id (cs_*) as their provider_transaction_id, so we
	 * resolve the charge's payment_intent back to its session before matching.
	 *
	 * @param object|null $charge The Stripe charge object from the event.
	 */
	private function handle_charge_refunded( $charge ): void {
		if ( ! is_object( $charge ) ) {
			return;
		}

		$payment_intent = (string) ( $charge->payment_intent ?? '' );
		$original       = null;

		if ( '' !== $payment_intent ) {
			$session_id = $this->resolve_session_from_payment_intent( $payment_intent );
			if ( '' !== $session_id ) {
				$original = Transaction::find_by_provider_id( $session_id );
			}
			// Some rows (external-purchase subscription mode) store the
			// payment_intent directly — fall back to a direct match.
			if ( ! $original ) {
				$original = Transaction::find_by_provider_id( $payment_intent );
			}
			// A subscription charge belongs to an INVOICE, and that invoice id is
			// what the subscription's transaction is keyed on — neither a session
			// nor the payment intent. Without this branch, refunding any
			// membership left the row 'completed' forever: the money went back,
			// the member kept access, and the ledger disagreed with Stripe.
			// Stripe no longer links a charge or intent to its invoice in either
			// direction, so the invoice has to be found from the customer's
			// recent invoices by matching the payment.
			if ( ! $original ) {
				$invoice_id = $this->resolve_invoice_from_payment_intent(
					$payment_intent,
					(string) ( $charge->customer ?? '' )
				);
				if ( '' !== $invoice_id ) {
					$original = Transaction::find_by_provider_id( $invoice_id );
				}
			}
		}

		if ( ! $original || 'refunded' === ( $original->status ?? '' ) ) {
			return;
		}

		// Stripe reports amount_refunded as the charge's CUMULATIVE refunded
		// total, not this refund's delta. Reconcile against it so an admin refund
		// already on the ledger isn't double-recorded, and a dashboard refund
		// lands exactly its unrecorded portion. The dedup id is the refund's own
		// re_* (refunds.data[0]) — the charge id was never a refund identifier,
		// which is half of why the echo used to double-record.
		$cumulative = isset( $charge->amount_refunded )
			? \Learnomy\from_minor_unit( $charge->amount_refunded, $charge->currency ?? 'usd' )
			: (float) $original->amount;

		// Current API versions do not include `refunds` on a charge unless it is
		// expanded, and a webhook payload never is — so this arrives empty and
		// the dedup key has to be looked up. One bounded call, on the refund
		// path only. Losing the key silently is what let refunds double-record.
		$refund_id = Stripe_Payload::charge_refund_id( $charge );
		if ( '' === $refund_id && ! empty( $charge->id ) ) {
			$refund_id = $this->latest_refund_id_for_charge( (string) $charge->id );
		}

		( new Refund_Service() )->reconcile_cumulative( $original, $cumulative, 'stripe', $refund_id );
	}

	/**
	 * The invoice a PaymentIntent paid, or '' when it paid no invoice.
	 *
	 * Current Stripe API versions removed every direct link between these two:
	 * `charge.invoice`, `payment_intent.invoice` and `invoice.charge` are all
	 * gone, and `/v1/invoice_payments` accepts no payment_intent filter. What
	 * remains is `invoice.payments`, expandable, which carries the intent — so
	 * the link is only walkable invoice-first. We scan the customer's recent
	 * invoices for the one whose payment matches.
	 *
	 * Bounded to the customer and to one page: a refund arrives for a charge
	 * that was paid recently, so if it is not in the last few invoices it is not
	 * a subscription invoice of ours.
	 *
	 * @param string $payment_intent The pi_* identifier from the charge.
	 * @param string $customer       The cus_* the charge belongs to.
	 * @return string Invoice id, or '' when no invoice matches.
	 */
	private function resolve_invoice_from_payment_intent( string $payment_intent, string $customer ): string {
		if ( '' === $payment_intent || '' === $customer ) {
			return '';
		}

		$response = $this->api_request(
			'GET',
			'/invoices?limit=20&expand[0]=data.payments&customer=' . rawurlencode( $customer ),
			array()
		);

		foreach ( (array) ( $response->data ?? array() ) as $invoice ) {
			foreach ( (array) ( $invoice->payments->data ?? array() ) as $payment ) {
				if ( $payment_intent === ( $payment->payment->payment_intent ?? '' ) ) {
					return (string) ( $invoice->id ?? '' );
				}
			}
		}

		return '';
	}

	/**
	 * The most recent refund on a charge, looked up directly.
	 *
	 * Used when the webhook payload does not carry `refunds` (it does not on
	 * current API versions). Stripe lists refunds newest first, so limit=1 is
	 * the refund that triggered this event.
	 *
	 * @param string $charge_id Stripe charge id.
	 * @return string Refund id, or '' when none is found.
	 */
	private function latest_refund_id_for_charge( string $charge_id ): string {
		$response = $this->api_request( 'GET', '/refunds?limit=1&charge=' . rawurlencode( $charge_id ), array() );

		return (string) ( $response->data[0]->id ?? '' );
	}

	/**
	 * Resolves a payment_intent (pi_*) to the Checkout Session id (cs_*) that
	 * created it, or '' when no session matches. Stripe's sessions list
	 * endpoint accepts a payment_intent filter.
	 *
	 * @param string $payment_intent The pi_* identifier.
	 * @return string The cs_* session id, or '' on miss.
	 */
	private function resolve_session_from_payment_intent( string $payment_intent ): string {
		$result = $this->api_get( '/checkout/sessions?payment_intent=' . rawurlencode( $payment_intent ) . '&limit=1' );
		if ( is_wp_error( $result ) || empty( $result->data[0]->id ) ) {
			return '';
		}

		return (string) $result->data[0]->id;
	}

	private function api_get( string $path ): mixed {
		return $this->api_request( 'GET', $path, [] );
	}

	private function api_post( string $path, array $params ): mixed {
		return $this->api_request( 'POST', $path, $params );
	}

	private function api_request( string $method, string $path, array $params ): mixed {
		$url  = self::API_BASE . $path;
		$args = [
			'method'  => $method,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->secret_key(),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'timeout' => 20,
		];

		if ( ! empty( $params ) ) {
			$args['body'] = http_build_query( $params );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( '[Learnomy] Stripe ' . $method . ' ' . $path . ' transport error: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			// WP's transport WP_Error (http_request_failed, curl timeouts, DNS)
			// carries no `status`, so anything that surfaces it through the REST
			// envelope becomes a 500 — "we crashed" — when the truth is "we could
			// not reach Stripe". 502 says that, and says it to monitoring too.
			// Only stamped when absent, so a caller that already classified the
			// failure keeps its own status.
			$data = $response->get_error_data();
			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				$response->add_data(
					array_merge( is_array( $data ) ? $data : [], [ 'status' => 502 ] )
				);
			}

			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ) );

		// Surface Stripe's own error so a failed checkout/subscription session
		// is diagnosable instead of bubbling up as a silent "could not start a
		// checkout session". Stripe returns { error: { message, type, code } }.
		if ( is_object( $decoded ) && isset( $decoded->error ) ) {
			$err = $decoded->error;
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'[Learnomy] Stripe %s %s failed: %s (type: %s, code: %s)',
					$method,
					$path,
					is_object( $err ) ? (string) ( $err->message ?? 'unknown' ) : (string) $err,
					is_object( $err ) ? (string) ( $err->type ?? '' ) : '',
					is_object( $err ) ? (string) ( $err->code ?? '' ) : ''
				)
			);
		}

		return $decoded;
	}

	/**
	 * Returns the active Stripe secret key based on the current mode setting.
	 *
	 * Publicly exposed so Pro extensions can authenticate their own Stripe
	 * API calls (e.g. Membership_Pro's Stripe_Proration). Falls back to the
	 * legacy single-key option for sites that haven't re-saved since Phase 1.
	 * Returns an empty string when no key is configured — callers must guard.
	 */
	public function secret_key(): string {
		$settings = get_option( 'learnomy_settings', [] );
		$mode     = $settings['stripe_mode'] ?? 'test';
		$key      = \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_' . $mode . '_secret' );

		if ( '' === $key ) {
			// Legacy fallback for sites configured before Phase 1 migration.
			$key = \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_secret_key' );
		}

		return $key;
	}

	/**
	 * Returns the active Stripe webhook signing secret based on the current mode.
	 * Falls back to the legacy option name for backward compatibility.
	 */
	public function webhook_secret(): string {
		$settings = get_option( 'learnomy_settings', [] );
		$mode     = $settings['stripe_mode'] ?? 'test';
		$secret   = \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_' . $mode . '_webhook_secret' );

		if ( '' === $secret ) {
			$secret = \Learnomy\Security\Encrypted_Option::get( 'learnomy_stripe_webhook_secret' );
		}

		return $secret;
	}

	/**
	 * Stamp external-purchase context onto a checkout session's metadata.
	 *
	 * An external purchase is any paid checkout that is not a course or
	 * membership-plan purchase — an extension (e.g. gift seats) sets
	 * `purchase_type` plus a flat `extra_metadata` map, both of which ride
	 * through Stripe metadata so `handle_checkout_completed()` can fire
	 * `learnomy_external_purchase_completed` for the extension to act on.
	 *
	 * @param array<string,mixed> $params Stripe session params, by reference.
	 * @param array<string,mixed> $args   create_checkout() args.
	 */
	private function apply_external_purchase_metadata( array &$params, array $args ): void {
		$purchase_type = isset( $args['purchase_type'] ) ? sanitize_key( (string) $args['purchase_type'] ) : '';
		if ( '' === $purchase_type ) {
			return;
		}
		$params['metadata[learnomy_purchase_type]'] = $purchase_type;
		foreach ( (array) ( $args['extra_metadata'] ?? array() ) as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$params[ 'metadata[learnomy_ext_' . sanitize_key( (string) $key ) . ']' ] = (string) $value;
			}
		}
	}

	private function resolve_line_item_name( array $args ): string {
		if ( ! empty( $args['label'] ) ) {
			return (string) $args['label'];
		}
		if ( ! empty( $args['course_id'] ) ) {
			$course = \Learnomy\Models\Course::find( (int) $args['course_id'] );
			if ( $course && ! empty( $course->title ) ) {
				return $course->title;
			}
		}
		return __( 'Course Purchase', 'learnomy' );
	}
}
