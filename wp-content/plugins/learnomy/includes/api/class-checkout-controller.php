<?php
/**
 * Checkout REST controller — processes payments via registered adapters.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\Models\Course;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\Transaction;
use Learnomy\Adapters\Adapter_Registry;
use Learnomy\Services\Coupon_Service;
use Learnomy\Services\Enrollment_Service;
use Learnomy\Services\Subscription_Service;

class Checkout_Controller extends Base_Controller {

	protected $rest_base = 'checkout';

	public function register_routes(): void {
		// Unified checkout — works with any active gateway.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'process_checkout' ],
					'permission_callback' => [ $this, 'can_checkout' ],
					'args'                => [
						// Optional: a zero-total order (100% coupon, $0 intro
						// price) completes without any provider, so a site with
						// no gateway configured must still be able to POST here
						// (Basecamp 10172705555). Non-zero totals validate the
						// named gateway inside process_checkout().
						'gateway'     => [
							'type'    => 'string',
							'default' => '',
						],
						'course_id'   => [
							'type'    => 'integer',
							'default' => 0,
						],
						'plan_id'     => [
							'type'    => 'integer',
							'default' => 0,
						],
						'coupon_code' => [
							'type'    => 'string',
							'default' => '',
						],
					],
				],
			]
		);

		// Subscription creation for membership plans.
		//
		// `billing_cycle` and `coupon_code` are BOTH part of the price the buyer
		// agreed to. The route used to declare neither: the checkout JS has always
		// sent `coupon_code` here and the pricing card has always linked with a
		// `billing` term, and both were dropped on the floor — so a yearly
		// selection was billed monthly (Basecamp 10143220483) and a valid coupon
		// was billed at full price (Basecamp 10143220397).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/subscribe',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'process_subscribe' ],
					'permission_callback' => [ $this, 'can_checkout' ],
					'args'                => [
						// Optional, exactly as on the one-time route above: a $0
						// recurring tier subscribes without any provider, so a
						// site with no gateway configured must still be able to
						// POST here. Leaving it `required` rejected the request
						// at the schema layer, before the callback could reach
						// its zero-total branch (Basecamp 10184336186).
						// Non-zero totals validate the named gateway inside
						// process_subscribe().
						'gateway'       => [
							'type'    => 'string',
							'default' => '',
						],
						'plan_id'       => [
							'type'     => 'integer',
							'required' => true,
						],
						'billing_cycle' => [
							'type'    => 'string',
							'enum'    => [ '', 'monthly', 'yearly' ],
							'default' => '',
						],
						'coupon_code'   => [
							'type'    => 'string',
							'default' => '',
						],
					],
				],
			]
		);

		// Webhook ingestion — public, signature-verified per adapter.
		// Canonical path is the top-level /webhooks/{gateway} (webhooks are not
		// checkout-specific — Stripe/PayPal also send subscription + refund
		// events). The /checkout/webhooks/{gateway} path is kept as a
		// back-compat alias so webhook URLs already configured in the Stripe /
		// PayPal dashboards keep delivering. Both hit the same handler.
		$webhook_route = [
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'process_webhook' ],
				'permission_callback' => '__return_true',
			],
		];
		register_rest_route( $this->namespace, '/webhooks/(?P<gateway>[a-z]+)', $webhook_route );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/webhooks/(?P<gateway>[a-z]+)', $webhook_route );

		// Legacy test endpoint — delegates to unified checkout.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/test',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'process_test' ],
					'permission_callback' => [ $this, 'can_checkout' ],
					'args'                => [
						'course_id'   => [
							'type'    => 'integer',
							'default' => 0,
						],
						'plan_id'     => [
							'type'    => 'integer',
							'default' => 0,
						],
						'coupon_code' => [
							'type'    => 'string',
							'default' => '',
						],
					],
				],
			]
		);
	}

	public function can_checkout( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		return true;
	}

	/**
	 * Unified checkout — resolves the gateway from the request and builds
	 * a checkout session on the provider's side.
	 */
	public function process_checkout( WP_REST_Request $request ): WP_REST_Response {
		$checkout_args = $this->resolve_checkout_args( $request );
		if ( $checkout_args instanceof WP_REST_Response ) {
			return $checkout_args;
		}

		// Already-enrolled guard: a buyer who already owns this course must not be
		// able to start a SECOND paid gateway session for it (sibling of the
		// subscription double-charge #9992172604). Course purchases only; free
		// orders fall through (enrollment is idempotent).
		$co_course = (int) ( $checkout_args['course_id'] ?? 0 );
		$co_user   = (int) ( $checkout_args['user_id'] ?? 0 );
		if ( $co_course > 0 && $co_user > 0 && \Learnomy\Models\Enrollment::is_enrolled( $co_user, $co_course ) ) {
			return $this->error_response(
				$this->validation_error( __( 'You are already enrolled in this course.', 'learnomy' ) )
			);
		}

		// Prerequisite guard, BEFORE any money moves. Enrollment_Service refuses
		// an enrollment whose prerequisites are unmet, so selling the course
		// first means taking money for an enrollment that will not be created —
		// which is exactly what happened when this guard did not exist
		// (Basecamp 10155834186). Same decider as the enroll gate
		// (unmet_prerequisites), so checkout and enrollment can never disagree.
		if ( $co_course > 0 && $co_user > 0 ) {
			$unmet = ( new \Learnomy\Services\Course_Service() )->unmet_prerequisites( $co_user, $co_course );
			if ( array() !== $unmet ) {
				return $this->error_response(
					new \WP_Error(
						'learnomy_prerequisites_not_met',
						sprintf(
							/* translators: %s: comma-separated list of course titles */
							__( 'Complete these courses first: %s.', 'learnomy' ),
							implode( ', ', wp_list_pluck( $unmet, 'title' ) )
						),
						array( 'status' => 403 )
					)
				);
			}
		}

		// Free order — a 100% coupon (or a fully-discounted price) zeroed the
		// total. No payment gateway can create a $0 charge: Stripe/PayPal reject
		// the session and the buyer is never enrolled (Basecamp #9941278467).
		// Enroll / subscribe directly and skip the provider entirely.
		//
		// This runs BEFORE the gateway is even resolved, deliberately. It used
		// to sit after the availability check, so a site with no gateway
		// configured could never complete a $0 order — the request had to name
		// an available gateway it would never use, and the internal-training
		// deployment (all commerce off) was stuck at "Payment gateway is not
		// available" (Basecamp 10172705555). A zero-total order needs no
		// provider, so no provider may gate it.
		if ( (float) $checkout_args['amount'] <= 0.0 ) {
			return $this->complete_free_order( $checkout_args );
		}

		// Money is moving from here on — now the gateway matters.
		$gateway_id = sanitize_key( $request->get_param( 'gateway' ) );
		$adapter    = Adapter_Registry::get( $gateway_id );

		if ( ! $adapter || ! $adapter->is_available() ) {
			return $this->error_response(
				$this->validation_error(
					/* translators: %s: gateway identifier */
					sprintf( __( 'Payment gateway "%s" is not available.', 'learnomy' ), $gateway_id )
				)
			);
		}

		// Unified entry point: the dispatcher translates the legacy
		// course_id / plan_id arg shape into the purchase_type / item_id
		// shape for us. The mode was resolved by resolve_checkout_args() —
		// 'subscription' when the course itself is recurring-priced. This
		// line used to force 'one_time' unconditionally, which sold a
		// recurring course as lifetime access at one period's price on any
		// adapter that trusted it (Basecamp 10155999596).
		$checkout_args['mode'] = (string) ( $checkout_args['mode'] ?? 'one_time' );
		$result                = $adapter->create_purchase_session( $checkout_args );

		// Stripe returns a client_secret (on-site embedded checkout); PayPal /
		// WooCommerce / Test return a redirect_url. Either is a valid start.
		if ( empty( $result['client_secret'] ) && empty( $result['redirect_url'] ) ) {
			return $this->error_response(
				$this->validation_error( __( 'The payment provider did not return a checkout URL.', 'learnomy' ) )
			);
		}

		return $this->single_response(
			[
				'client_secret' => $result['client_secret'] ?? '',
				'redirect_url'  => $result['redirect_url'] ?? '',
				'session_id'    => $result['session_id'] ?? '',
				'gateway'       => $gateway_id,
				// The billing identity this order will be recorded against -
				// the profile as just saved, which Purchase_Recorder freezes
				// onto the transaction. Echoed so a client can SEE which
				// address won instead of discovering it on the invoice
				// (Basecamp 10171481920's observability half).
				'billing'       => \Learnomy\Services\Billing_Profile::get( (int) get_current_user_id() ),
			],
			[],
			201
		);
	}

	/**
	 * Creates a recurring subscription via the specified gateway.
	 */
	public function process_subscribe( WP_REST_Request $request ): WP_REST_Response {
		$plan_id = absint( $request->get_param( 'plan_id' ) );
		$plan    = MembershipPlan::find( $plan_id );

		if ( ! $plan || 'active' !== ( $plan->status ?? 'inactive' ) ) {
			return $this->error_response( $this->not_found( 'Membership plan' ) );
		}

		// Guard against double billing: a repeated subscribe call must never
		// create a second concurrent membership subscription. Consider only
		// plan (membership) subscriptions, not course-level recurring subs
		// (those carry course_id with plan_id NULL and are unrelated to a plan
		// purchase). Same plan already active -> reject; a different membership
		// plan already active -> send them to billing to change plans rather
		// than start a parallel subscription.
		$active_plan_subs = array_filter(
			\Learnomy\Models\Subscription::get_active_for_user( get_current_user_id() ),
			static fn( $sub ) => null !== ( $sub->plan_id ?? null )
		);
		foreach ( $active_plan_subs as $active_sub ) {
			if ( (int) $active_sub->plan_id === $plan_id ) {
				return $this->error_response(
					$this->validation_error( __( 'You already have an active subscription to this plan.', 'learnomy' ) )
				);
			}
		}
		if ( ! empty( $active_plan_subs ) ) {
			return $this->error_response(
				$this->validation_error( __( 'You already have an active membership. Manage or change your plan from the billing page.', 'learnomy' ) )
			);
		}

		// ONE pricing engine, shared with one-time checkout. It resolves the
		// cycle the buyer picked, prices the plan for that cycle, charges in the
		// PLAN's currency when it sets one, and refuses a coupon on a recurring
		// purchase rather than quoting a discount we would not charge.
		$checkout_args = $this->resolve_checkout_args( $request, 'subscription' );
		if ( $checkout_args instanceof WP_REST_Response ) {
			return $checkout_args;
		}

		// Free tier — a $0 recurring plan. Mirrors the one-time path exactly,
		// including its ordering: this runs BEFORE the gateway is resolved,
		// because no provider can open a $0 session and so no provider may gate
		// it. process_checkout() got that treatment on 10172705555 and its
		// recurring twin never did, so a free membership tier was unclaimable on
		// a site with no gateway configured — the buyer was told "Payment
		// gateway is not available" for a plan that costs nothing, and the plan
		// card routes EVERY plan through here with no separate free-claim CTA
		// (Basecamp 10184336186).
		//
		// complete_free_order() already handles the membership case: it calls
		// Subscription_Service::subscribe( ..., 'free' ) whenever plan_id is
		// set, records the transaction as a membership and fires
		// learnomy_purchase_completed. Reused rather than reimplemented so the
		// free path cannot drift between the two entry points.
		if ( (float) $checkout_args['amount'] <= 0.0 ) {
			return $this->complete_free_order( $checkout_args );
		}

		// Money is moving from here on — now the gateway matters.
		$gateway_id = sanitize_key( $request->get_param( 'gateway' ) );
		$adapter    = Adapter_Registry::get( $gateway_id );

		if ( ! $adapter || ! $adapter->is_available() ) {
			return $this->error_response(
				$this->validation_error(
					/* translators: %s: gateway identifier */
					sprintf( __( 'Payment gateway "%s" is not available.', 'learnomy' ), $gateway_id )
				)
			);
		}

		$agreed_cycle = (string) ( $checkout_args['billing_cycle'] ?? '' );

		$result = $adapter->create_purchase_session(
			[
				'user_id'        => (int) $checkout_args['user_id'],
				'purchase_type'  => 'membership',
				'item_id'        => $plan_id,
				'plan_id'        => $plan_id, // Legacy alias — read by metadata builder.
				'mode'           => 'subscription',
				'amount'         => (float) $checkout_args['amount'],
				'currency'       => (string) $checkout_args['currency'],
				// The agreed term travels WITH the charge. `interval` is what the
				// gateways speak; `billing_cycle` is what our own subscription row
				// records. Both leave here together so the provider's cadence and
				// our recorded cadence cannot drift.
				'billing_cycle'  => $agreed_cycle,
				'interval'       => 'yearly' === $agreed_cycle ? 'year' : 'month',
				'interval_count' => (int) ( $checkout_args['interval_count'] ?? 1 ),
				'return_url'     => $checkout_args['return_url'],
				'cancel_url'     => $checkout_args['cancel_url'],
			]
		);

		// Stripe returns a client_secret (on-site embedded checkout); PayPal
		// returns a redirect_url. Either is a valid subscription start.
		if ( empty( $result['client_secret'] ) && empty( $result['redirect_url'] ) ) {
			// Prefer the adapter's own reason when it has one. "The payment
			// provider did not return a subscription URL" is true and useless:
			// it sent an owner whose WooCommerce lacks the Subscriptions
			// extension looking for a network fault, when the answer was one
			// sentence away in the adapter that already knew.
			$why = method_exists( $adapter, 'last_unavailable_reason' )
				? (string) $adapter->last_unavailable_reason()
				: '';

			return $this->error_response(
				$this->validation_error(
					'' !== $why
						? $why
						: __( 'The payment provider did not return a subscription URL.', 'learnomy' )
				)
			);
		}

		return $this->single_response(
			[
				'client_secret' => $result['client_secret'] ?? '',
				'redirect_url'  => $result['redirect_url'] ?? '',
				'session_id'    => $result['session_id'] ?? '',
				'gateway'       => $gateway_id,
			],
			[],
			201
		);
	}

	/**
	 * Receives and delegates incoming payment webhooks.
	 * Each adapter verifies the signature internally.
	 */
	public function process_webhook( WP_REST_Request $request ): WP_REST_Response {
		$gateway_id = sanitize_key( $request->get_param( 'gateway' ) );
		$adapter    = Adapter_Registry::get( $gateway_id );

		if ( ! $adapter ) {
			return new WP_REST_Response( null, 404 );
		}

		try {
			$adapter->process_webhook( $request );
		} catch ( \InvalidArgumentException $e ) {
			// Client errors (bad signature, malformed payload): return 400 so the
			// payment provider STOPS retrying. Stripe retries 5xx responses
			// indefinitely; 4xx tells it to give up. The exception message is
			// safe to surface as the error code — we throw it ourselves from
			// the adapter, never from untrusted input.
			return new WP_REST_Response(
				array(
					'error' => array(
						'code'    => $e->getMessage(),
						'message' => 'Webhook rejected.',
					),
				),
				400
			);
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'error' => 'Webhook processing failed.' ), 500 );
		}

		return new WP_REST_Response( null, 200 );
	}

	/**
	 * Legacy test gateway endpoint — delegates to the unified handler.
	 */
	public function process_test( WP_REST_Request $request ): WP_REST_Response {
		$request->set_param( 'gateway', 'test' );
		return $this->process_checkout( $request );
	}

	// -------------------------------------------------------------------------
	// Shared helpers
	// -------------------------------------------------------------------------

	/**
	 * THE pricing engine — resolves amount, currency, billing term, coupon and
	 * return URLs for every checkout the plugin performs, one-time and recurring.
	 *
	 * `process_subscribe()` used to compute its own price inline instead of
	 * calling this, which made it a second, impoverished pricing engine in the
	 * same file: it knew nothing about coupons, nothing about the billing cycle
	 * the buyer picked, and nothing about the zero-total path. All three
	 * symptoms in the 2026-07 money cluster (yearly billed monthly, coupon
	 * ignored, terms never recorded) were that one fact. Money standard §3 —
	 * all pricing math lives in ONE place — now holds literally.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string          $mode    'one_time' or 'subscription'. Recurring
	 *                                 checkouts price a billing cycle and refuse
	 *                                 coupons (see below).
	 * @return array|WP_REST_Response Checkout args array, or an error response.
	 */
	private function resolve_checkout_args( WP_REST_Request $request, string $mode = 'one_time' ) {
		$user_id   = get_current_user_id();
		$course_id = absint( $request->get_param( 'course_id' ) );
		$plan_id   = absint( $request->get_param( 'plan_id' ) );

		// Keep the billing details the buyer just typed.
		//
		// This is the whole point of collecting them ourselves: the address
		// belongs to the MEMBER, so the next purchase — through any gateway —
		// starts filled in instead of asking again. Saved here, at the one point
		// every gateway passes through, rather than in an adapter, because a
		// per-gateway copy is how it would end up saved for card buyers only.
		//
		// Stored before the payment rather than after it on purpose: a buyer
		// whose card is declined has still told us their address, and making
		// them retype it on the retry is the exact friction being removed.
		$billing = $request->get_param( 'billing' );
		if ( ! is_array( $billing ) ) {
			// Accept flat top-level billing_* keys too. Only the NESTED shape
			// was consumed; a client sending billing_city etc. at the top
			// level was silently ignored and then billed the stored profile -
			// which read exactly like the discarded-address bug this path had
			// already fixed (Basecamp 10171481920, the QA repro's likely
			// payload shape).
			$billing = array();
			foreach ( $request->get_params() as $lrn_bkey => $lrn_bval ) {
				if ( 0 === strpos( (string) $lrn_bkey, 'billing_' ) && is_scalar( $lrn_bval ) ) {
					$billing[ $lrn_bkey ] = (string) $lrn_bval;
				}
			}
		}
		if ( $user_id > 0 && ! empty( $billing ) ) {
			\Learnomy\Services\Billing_Profile::save( $user_id, $billing );
		}

		// Site currency by default — correct for a course (courses have no currency
		// column). Overridden below once we know we are charging a PLAN, which may
		// set its own. Both branches go through the one resolver so a third checkout
		// path cannot reintroduce the bug by reading the option directly.
		$currency = \Learnomy\charge_currency();

		$amount    = 0;
		$coupon_id = null;
		$gateway   = sanitize_key( (string) $request->get_param( 'gateway' ) );

		// Billing term. Only meaningful for a plan; a course purchase has none.
		// Defaults resolve to the plan's own interval.
		$billing_cycle  = sanitize_key( (string) $request->get_param( 'billing_cycle' ) );
		$interval_count = 1;

		if ( $course_id ) {
			$course = Course::find( $course_id );
			if ( ! $course || 'published' !== ( $course->status ?? 'draft' ) ) {
				return $this->error_response( $this->not_found( 'Course' ) );
			}
			// Closed courses are not sold on-site — access is granted externally
			// (webhook / manual / CSV), so there is no on-site checkout path.
			if ( Course::is_closed( $course ) ) {
				return $this->error_response(
					$this->validation_error( __( 'This course is not available for purchase on this site.', 'learnomy' ) )
				);
			}

			// A recurring-priced course is a SUBSCRIPTION sale: the charge is
			// recurring_amount per recurring_interval, not the one-time price
			// column, and the mode must say so. This controller used to stamp
			// mode='one_time' unconditionally — Stripe/PayPal/Woo self-correct
			// by re-checking Course::is_recurring() in their adapters, but the
			// Test adapter trusted the mode and booked a one-time sale of
			// lifetime access at one month's price (Basecamp 10155999596).
			// Resolve the truth ONCE here so no adapter needs its own copy.
			if ( Course::is_recurring( $course ) ) {
				$mode          = 'subscription';
				$amount        = (float) ( $course->recurring_amount ?? 0 );
				$billing_cycle = sanitize_key( (string) ( $course->recurring_interval ?? 'monthly' ) );
			} else {
				$amount = (float) ( $course->sale_price ?? 0 ) > 0
					? (float) $course->sale_price
					: (float) ( $course->price ?? 0 );
			}
		} elseif ( $plan_id ) {
			$plan = MembershipPlan::find( $plan_id );
			if ( ! $plan || 'active' !== ( $plan->status ?? 'inactive' ) ) {
				return $this->error_response( $this->not_found( 'Membership plan' ) );
			}
			// One resolver decides what a plan costs on a given cycle — the same
			// one the pricing card and the checkout page render from, so the
			// figure on screen and the figure charged come from one line of code.
			// The old `price ?? price_monthly` here read as dual-price-aware and
			// was not: `price` is NOT NULL DEFAULT 0.00, so the coalesce could
			// never fall through and `price_yearly` was unreachable.
			$terms          = MembershipPlan::price_for_cycle( $plan, $billing_cycle );
			$amount         = (float) $terms['amount'];
			$billing_cycle  = (string) $terms['cycle'];
			$interval_count = (int) $terms['interval_count'];

			// A plan may charge in its own currency. Resolve it AFTER the plan is
			// loaded — the default above is the site currency, which is correct for a
			// course purchase (courses have no currency column) but wrong for a plan
			// that sets one.
			$currency = \Learnomy\charge_currency( $plan );
		} else {
			return $this->error_response(
				$this->validation_error( __( 'A course_id or plan_id is required.', 'learnomy' ) )
			);
		}

		// Every gateway gets the same post-purchase landing. The thank-you
		// view-data resolves the right transaction from the session id, so
		// future adapters don't have to reinvent return-URL handling.
		$return_url = self::thank_you_url(
			$gateway,
			array(
				'course_id' => $course_id ?: null,
				'plan_id'   => $plan_id ?: null,
			)
		);
		$cancel_url = self::cancelled_url(
			array(
				'course_id' => $course_id ?: null,
				'plan_id'   => $plan_id ?: null,
			)
		);

		// Apply coupon discount if provided.
		$coupon_code = sanitize_text_field( $request->get_param( 'coupon_code' ) );
		if ( $coupon_code ) {
			// RECURRING CHECKOUT REFUSES COUPONS — deliberately, and loudly.
			//
			// `lrn_coupons` has no duration column: a Learnomy coupon is a
			// one-off discount on one purchase. Discounting only the first
			// charge of a subscription would trade today's bug (shown a
			// discount, charged full price) for a worse one — charged the
			// discount once, then full price at renewal, with the mismatch
			// living in provider-side state we cannot unwind. A genuine
			// recurring discount needs Stripe coupons / promotion codes and
			// a duration concept on our own coupon model; that is its own
			// piece of work, not a side effect of this fix.
			//
			// Until then the member is told plainly, at the moment of payment,
			// instead of being shown a total we will not charge.
			if ( 'subscription' === $mode ) {
				return $this->error_response(
					new WP_Error(
						'learnomy_coupon_not_supported_on_subscription',
						__( 'Coupons cannot be applied to a recurring purchase. Remove the coupon to continue.', 'learnomy' ),
						[ 'status' => 422 ]
					)
				);
			}

			$coupon_service = new Coupon_Service();
			$validation     = $coupon_service->validate( $coupon_code, $course_id ?: null, $plan_id ?: null, $user_id, (float) $amount );

			if ( ! empty( $validation['valid'] ) ) {
				$discount  = $coupon_service->calculate_discount( $validation['coupon'], $amount );
				$amount    = max( 0, $amount - $discount );
				$coupon_id = (int) $validation['coupon']->id;
			}
		}

		/*
		 * Required billing details are actually required.
		 *
		 * `Billing_Profile::missing_required()` has existed since the billing
		 * profile shipped, documented as "empty when the profile can be charged",
		 * and NOTHING CALLED IT. The checkout template marks six fields `required`,
		 * but they sit outside any <form>, so the browser never validates them
		 * either: a buyer could complete a paid order with every field blank and
		 * the transaction stored a billing snapshot containing only their email
		 * (Basecamp 10167587258). Reproduced 2026-08-05 on the Test gateway.
		 *
		 * Validated here rather than in a gateway, for the same reason the profile
		 * is SAVED here: this is the one point every checkout passes through, so a
		 * per-adapter copy would enforce it for card buyers only. It also covers
		 * the mobile app and any other REST client for free.
		 *
		 * Read back from the STORED profile rather than the request payload, so a
		 * member who filled this in on a previous purchase is not asked again by a
		 * client that sent no `billing` block.
		 *
		 * Only when money changes hands. A free enrollment has no invoice, no tax
		 * position and no gateway, so demanding a postal address for it would be
		 * friction with nothing behind it.
		 */
		if ( $amount > 0 ) {
			$missing = \Learnomy\Services\Billing_Profile::missing_required(
				\Learnomy\Services\Billing_Profile::get( $user_id )
			);
			if ( $missing ) {
				return $this->error_response(
					new WP_Error(
						'learnomy_billing_details_required',
						__( 'Add your billing details to continue.', 'learnomy' ),
						[
							'status' => 422,
							'fields' => array_values( $missing ),
						]
					)
				);
			}
		}

		return [
			'user_id'        => $user_id,
			'course_id'      => $course_id,
			'plan_id'        => $plan_id,
			'amount'         => $amount,
			'currency'       => $currency,
			'coupon_id'      => $coupon_id,
			// The resolved truth: 'subscription' for a plan OR a
			// recurring-priced course. process_checkout() must not overwrite
			// it (Basecamp 10155999596).
			'mode'           => $mode,
			'billing_cycle'  => $billing_cycle,
			'interval_count' => $interval_count,
			'return_url'     => $return_url,
			'cancel_url'     => $cancel_url,
		];
	}

	/**
	 * Complete a zero-total order without a payment gateway.
	 *
	 * Records a completed $0 transaction, enrolls (course) or subscribes
	 * (membership), applies the coupon usage, fires the canonical
	 * `learnomy_purchase_completed` action, and returns the redirect the buyer
	 * lands on — mirroring the Test adapter's purchase path so every downstream
	 * listener behaves identically. Reached only when a coupon/discount zeroed
	 * the amount (Basecamp #9941278467).
	 *
	 * @param array $args Resolved checkout args from resolve_checkout_args().
	 */
	private function complete_free_order( array $args ): WP_REST_Response {
		$user_id   = (int) $args['user_id'];
		$course_id = (int) ( $args['course_id'] ?? 0 );
		$plan_id   = (int) ( $args['plan_id'] ?? 0 );
		$coupon_id = (int) ( $args['coupon_id'] ?? 0 );

		$subscription_id = $plan_id
			? ( new Subscription_Service() )->subscribe( $user_id, $plan_id, 'free' )
			: null;

		$txn_id = Transaction::record(
			array(
				'user_id'                 => $user_id,
				'course_id'               => $course_id ?: null,
				'plan_id'                 => $plan_id ?: null,
				'subscription_id'         => $subscription_id ?: null,
				'coupon_id'               => $coupon_id ?: null,
				'amount'                  => 0.0,
				'currency'                => strtoupper( (string) ( $args['currency'] ?? 'USD' ) ),
				'status'                  => 'completed',
				'transaction_type'        => $plan_id ? 'membership' : 'course',
				'payment_provider'        => 'free',
				'provider_transaction_id' => 'free_' . wp_generate_uuid4(),
			)
		);

		if ( $course_id ) {
			// Same failure contract as the paid adapters: the free order is
			// already recorded as a completed transaction above, so an enroll
			// refusal here is money-path-completed-no-access and must reach
			// the audit log + self-heal hook (Basecamp 10155834186).
			$enroll_result = ( new Enrollment_Service() )->enroll( $user_id, $course_id, 'purchase' );
			if ( is_wp_error( $enroll_result ) || (int) $enroll_result <= 0 ) {
				Enrollment_Service::report_purchase_failure(
					$user_id,
					$course_id,
					$enroll_result,
					'free',
					array( 'transaction_id' => (int) $txn_id )
				);
			}
		}

		if ( $coupon_id && $txn_id ) {
			( new Coupon_Service() )->apply( $coupon_id, $user_id, (int) $txn_id );
		}

		do_action(
			'learnomy_purchase_completed',
			$txn_id,
			$user_id,
			$plan_id ? 'membership' : 'course',
			$plan_id ?: $course_id,
			array(),
			'free'
		);

		// Land the buyer where the free-enrollment button already sends them:
		// the course (now enrolled) or their dashboard for a membership.
		if ( $course_id ) {
			$course   = Course::find( $course_id );
			$redirect = ( $course && ! empty( $course->slug ) )
				? \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) )
				: \Learnomy\route_url( 'student-dashboard' );
		} else {
			$redirect = \Learnomy\route_url( 'student-dashboard' );
		}

		return $this->single_response(
			array(
				'redirect_url' => $redirect,
				'session_id'   => 'free_' . $txn_id,
				'gateway'      => 'free',
				'free'         => true,
			),
			array(),
			201
		);
	}

	/**
	 * Build the canonical post-purchase return URL the buyer lands on after
	 * the gateway redirects them back.
	 *
	 * Resolved through the Route Registry (`enroll-thank-you`) so a slug
	 * change in settings updates the rewrite rule AND this return URL together.
	 * The thank-you page reads `?session=` to find the right transaction.
	 * Stripe substitutes its own `{CHECKOUT_SESSION_ID}` placeholder before
	 * redirecting the buyer — every other adapter appends a session identifier
	 * from the provider-specific flow.
	 *
	 * @param string $gateway_id Adapter id ('stripe' | 'paypal' | 'test' | 'woocommerce').
	 * @param array  $context    Extra params to append (course_id, plan_id, etc).
	 * @return string Absolute URL.
	 */
	public static function thank_you_url( string $gateway_id, array $context = array() ): string {
		// Route Registry is the single URL source — resolves through the
		// declarative slug so a white-label rename moves the rewrite rule AND
		// the return URL baked into the Stripe/PayPal session together.
		$base = \Learnomy\route_url( 'enroll-thank-you' );

		$args = array( 'gateway' => $gateway_id );

		if ( ! empty( $context['course_id'] ) ) {
			$args['course_id'] = (int) $context['course_id'];
		}
		if ( ! empty( $context['plan_id'] ) ) {
			$args['plan_id'] = (int) $context['plan_id'];
		}

		// Anything else the caller passed rides along. Free knows about courses
		// and plans; Pro sells space plans and seat packs and identifies them
		// with its own keys. Dropping them meant a school that had just paid
		// $199 for ten seats landed on a thank-you page with no idea what had
		// been bought, offering "My courses" and "Browse Courses" — the generic
		// no-context fallback. Pro reads its key back off the URL and decorates
		// the page through `learnomy_checkout_thank_you_view_data`.
		//
		// Scalars only, sanitized, never overriding the keys above: this value
		// ends up inside a gateway return_url, so it must not become an
		// arbitrary payload channel.
		foreach ( $context as $context_key => $context_value ) {
			$context_key = sanitize_key( (string) $context_key );
			if ( '' === $context_key || isset( $args[ $context_key ] ) || ! is_scalar( $context_value ) ) {
				continue;
			}
			$args[ $context_key ] = sanitize_text_field( (string) $context_value );
		}

		// Stripe's on-page Payment Element appends `?payment_intent=pi_…&
		// redirect_status=…` to this URL itself after confirmation, so we add no
		// session placeholder here (it was a hosted-Checkout artifact that
		// Payment Element never substitutes, which left the thank-you page
		// polling a literal "CHECKOUT_SESSION_ID" forever). The view-data
		// resolver reads payment_intent / PayPal token / Test session id.
		return add_query_arg( $args, $base );
	}

	/**
	 * Build the canonical cancel-flow URL the buyer lands on when they
	 * abandon at the gateway.
	 *
	 * Resolved through the Route Registry (`enroll-cancelled`) so a slug
	 * change in settings updates the rewrite rule AND this URL together.
	 *
	 * @param array $context course_id and/or plan_id, used by the cancelled
	 *                       page to offer a "Try again" CTA pointed at
	 *                       /enroll/?course_id=N or ?plan_id=N.
	 * @return string Absolute URL.
	 */
	public static function cancelled_url( array $context = array() ): string {
		// Route Registry is the single URL source — keeps the cancelled-flow
		// redirect in sync with the rewrite rule on any slug rename.
		$base = \Learnomy\route_url( 'enroll-cancelled' );

		$args = array();
		if ( ! empty( $context['course_id'] ) ) {
			$args['course_id'] = (int) $context['course_id'];
		}
		if ( ! empty( $context['plan_id'] ) ) {
			$args['plan_id'] = (int) $context['plan_id'];
		}

		return $args ? add_query_arg( $args, $base ) : $base;
	}
}
