<?php
/**
 * View-data resolver for the post-purchase thank-you page.
 *
 * Gateway-agnostic. Reads `?session=` (or PayPal's `?token=` for orders)
 * and resolves the transaction. If the local lrn_transactions row hasn't
 * landed yet (webhook delivery is async), one bounded adapter call to
 * `verify_payment_status()` decides whether to render the paid view, the
 * pending polling view, or the failed retry view.
 *
 * Templates receive a flat $view_data array — zero model / adapter calls
 * from the template, per the view-data pattern.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Adapters\Adapter_Registry;
use Learnomy\Models\Course;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\Subscription;
use Learnomy\Models\Transaction;

class Checkout_Thank_You_View_Data {

	/**
	 * Render states the template understands.
	 */
	private const STATE_PAID    = 'paid';
	private const STATE_PENDING = 'pending';
	private const STATE_FAILED  = 'failed';

	/**
	 * How many of a plan's bundled courses the thank-you page lists inline.
	 *
	 * Six, not twelve like the manage page: this is a "here is your first
	 * click" moment, not a library. Plans bundling sixty courses would bury the
	 * CTAs under a wall of cards, so the rest are reached through "+N more".
	 */
	private const PLAN_COURSES_PREVIEW = 6;

	/**
	 * Resolve all data needed by templates/checkout-thank-you.php.
	 *
	 * @param array $data Route data (typically just 'route' => 'enroll-thank-you').
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		unset( $data );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only landing page populated by gateway redirect.
		$session_id = isset( $_GET['session'] ) ? sanitize_text_field( wp_unslash( $_GET['session'] ) ) : '';
		// Drop the un-substituted hosted-checkout placeholder. Stripe only swaps
		// {CHECKOUT_SESSION_ID} for hosted Checkout; the on-page Payment Element
		// flow leaves it literal and returns the PaymentIntent id instead.
		if ( 'CHECKOUT_SESSION_ID' === $session_id || '{CHECKOUT_SESSION_ID}' === $session_id ) {
			$session_id = '';
		}
		// Stripe Payment Element appends `?payment_intent=pi_…&redirect_status=…`
		// to the return URL — that id is how we find the transaction.
		if ( '' === $session_id && isset( $_GET['payment_intent'] ) ) {
			$session_id = sanitize_text_field( wp_unslash( $_GET['payment_intent'] ) );
		}
		// PayPal's hosted checkout appends `?token=ORDERID&PayerID=…` on
		// approval. Use token as a fallback when we don't have a session id.
		if ( '' === $session_id && isset( $_GET['token'] ) ) {
			$session_id = sanitize_text_field( wp_unslash( $_GET['token'] ) );
		}
		// How many times this page has already re-rendered itself waiting for
		// fulfilment. Carried in the URL because the holding state refreshes
		// through <meta>, so there is no client-side counter to keep.
		$poll_attempt    = isset( $_GET['lrn_poll'] ) ? min( 9999, absint( $_GET['lrn_poll'] ) ) : 0;
		$redirect_status = isset( $_GET['redirect_status'] ) ? sanitize_key( wp_unslash( $_GET['redirect_status'] ) ) : '';
		$gateway         = isset( $_GET['gateway'] ) ? sanitize_key( wp_unslash( $_GET['gateway'] ) ) : '';
		$course_id       = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
		$plan_id         = isset( $_GET['plan_id'] ) ? absint( $_GET['plan_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$user_id = get_current_user_id();

		// Settings + currency for the price line.
		$settings = get_option( 'learnomy_settings', array() );
		$currency = strtoupper( (string) ( $settings['currency'] ?? 'USD' ) );

		// 0) Self-heal a confirmed-on-page Stripe purchase whose webhook hasn't
		// fulfilled it. The webhook is the normal path but the ONLY one — if it
		// is down/misconfigured, the card was charged and nothing was delivered
		// while this page polls forever. Reconcile the PaymentIntent now
		// (idempotent: a later webhook is a no-op). The reconciler records the
		// transaction keyed on the pi_* id, so the lookup just below then finds
		// it and the normal PAID branch runs. Bounded: one API call per load,
		// only when we have no local row yet for this id.
		if (
			'succeeded' === $redirect_status
			&& '' !== $session_id
			&& str_starts_with( $session_id, 'pi_' )
			&& ! Transaction::find_by_provider_id( $session_id )
		) {
			$stripe = Adapter_Registry::get( $gateway ?: 'stripe' );
			if ( $stripe && method_exists( $stripe, 'reconcile_payment_intent' ) ) {
				$stripe->reconcile_payment_intent( $session_id );
			}
		}

		// 1) Try to find the transaction by provider session id.
		$transaction = null;
		if ( '' !== $session_id ) {
			$transaction = Transaction::find_by_provider_id( $session_id );
		}

		// Subscriptions return the PaymentIntent id on the URL, but the
		// first-invoice transaction is keyed on the invoice id — so the direct
		// id match misses. When the card was just confirmed on-page
		// (redirect_status=succeeded), fall back to this buyer's most recent
		// completed transaction for the same product so the success page
		// resolves instead of polling forever.
		if ( ! $transaction && 'succeeded' === $redirect_status && $user_id ) {
			$conds = array(
				'user_id' => $user_id,
				'status'  => 'completed',
			);
			if ( $plan_id ) {
				$conds['plan_id'] = $plan_id;
			} elseif ( $course_id ) {
				$conds['course_id'] = $course_id;
			}
			$recent = Transaction::where( $conds, 'id', 'DESC', 1 );
			if ( ! empty( $recent[0] ) ) {
				$transaction = $recent[0];
			}
		}

		// 2) Decide the render state.
		$state           = self::STATE_PENDING;
		$polling_seconds = self::resolve_polling_interval();

		if ( $transaction ) {
			// Map transaction.status to render state. Webhook handlers write
			// 'completed' for a happy payment, 'refunded' for refunds, and
			// the gateway-specific failure paths can write 'failed'.
			$txn_status = (string) ( $transaction->status ?? '' );

			if ( 'completed' === $txn_status ) {
				$state = self::STATE_PAID;
			} elseif ( 'failed' === $txn_status ) {
				$state = self::STATE_FAILED;
			} else {
				$state = self::STATE_PENDING;
			}

			// Make sure the URL context follows the transaction even if the
			// query string was stripped along the way.
			if ( ! $course_id && ! empty( $transaction->course_id ) ) {
				$course_id = (int) $transaction->course_id;
			}
			if ( ! $plan_id && ! empty( $transaction->plan_id ) ) {
				$plan_id = (int) $transaction->plan_id;
			}
		} elseif ( 'succeeded' === $redirect_status ) {
			// Payment Element already confirmed the card on the page; the
			// webhook will write the transaction momentarily. Show the holding
			// state and let the page poll until the row lands — no adapter call
			// (a PaymentIntent id is not a Checkout Session, so a session
			// lookup would 404 every poll).
			$state = self::STATE_PENDING;
		} else {
			// 3) No local row — ask the adapter whether the provider has
			// already accepted payment. One bounded API call per page load.
			$state = self::query_adapter_status( $gateway, $session_id );
		}

		// 3b) Bound the holding state. Each refresh carries the attempt number
		// forward, so elapsed time is (attempts x interval) with no server-side
		// state to keep. Once we are past the limit the template stops emitting
		// the refresh and tells the buyer where they stand instead of spinning.
		$polling_waited    = $poll_attempt * $polling_seconds;
		$polling_exhausted = ( self::STATE_PENDING === $state ) && $polling_waited >= self::resolve_polling_limit();
		$polling_next_url  = add_query_arg( 'lrn_poll', $poll_attempt + 1 );

		// 4) Resolve the course or plan so the order summary has something
		// to render. We pull from the transaction first (canonical) and
		// fall back to query-string context so the pending state still
		// shows the buyer what they paid for.
		$course = null;
		if ( $course_id ) {
			$course = Course::find( $course_id );
		}

		$plan = null;
		if ( $plan_id ) {
			$plan = MembershipPlan::find( $plan_id );
		}

		// 5) What a plan buyer actually bought — the courses their new
		// membership unlocks. Resolved BEFORE the CTAs because the primary CTA
		// depends on how many there are (one course goes straight in; several
		// go to My Courses). Same model resolver the manage page uses, so the
		// two screens can never list a plan's contents differently.
		$included_courses = $plan_id
			? MembershipPlan::preview_courses( $plan_id, self::PLAN_COURSES_PREVIEW )
			: array(
				'courses' => array(),
				'total'   => 0,
			);

		// 6) CTAs depend on what was bought.
		[ $primary_cta_url, $primary_cta_label, $secondary_cta_url, $secondary_cta_label ] = self::resolve_ctas(
			$state,
			$course,
			$plan,
			$course_id,
			$plan_id,
			$included_courses
		);

		// 7) Buyer email for the "we emailed your receipt" line.
		$email_sent_to = '';
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$email_sent_to = (string) $user->user_email;
			}
		}

		// 8) Receipt link — point to the membership-manage invoice list for
		// subscriptions, or to the dashboard for one-time purchases. Either
		// way the buyer can find proof of payment later.
		$receipt_link = $plan_id
			? \Learnomy\route_url( 'membership-manage' )
			: \Learnomy\route_url( 'student-dashboard' );

		// 9) Gateway display label.
		$gateway_label = self::resolve_gateway_label( $gateway, $transaction );

		// The shared glyph map. Five view-data classes each carried their own
		// six-entry copy, so the same price rendered differently depending on
		// which screen you were on, and a site whose currency was in one copy
		// but not another saw a symbol on checkout and a bare code on billing.
		$currency_symbols = \Learnomy\currency_symbols();
		if ( $transaction && ! empty( $transaction->currency ) ) {
			$currency = strtoupper( (string) $transaction->currency );
		}
		$currency_symbol = $currency_symbols[ $currency ] ?? ( $currency . ' ' );

		// 11) Subscription metadata (next billing date, plan period) when
		// this is a membership purchase. Read straight from the
		// Subscription model; templates don't run queries.
		$subscription = null;
		if ( $transaction && ! empty( $transaction->subscription_id ) ) {
			$subscription = Subscription::find( (int) $transaction->subscription_id );
		}

		$view_data = array(
			'state'               => $state,
			'session_id'          => $session_id,
			'gateway'             => $gateway,
			'gateway_label'       => $gateway_label,
			'transaction'         => $transaction,
			'course'              => $course,
			'plan'                => $plan,
			'subscription'        => $subscription,
			'is_plan_purchase'    => (bool) $plan_id,
			'is_course_purchase'  => (bool) $course_id && ! $plan_id,
			// The courses this membership just unlocked: up to
			// PLAN_COURSES_PREVIEW rows to render, plus the true total so the
			// template can offer "+N more" instead of implying the plan holds
			// only what fits on screen. Empty for a course purchase.
			'included_courses'    => $included_courses['courses'],
			'included_total'      => (int) $included_courses['total'],
			'currency'            => $currency,
			'currency_symbol'     => $currency_symbol,
			'amount'              => $transaction ? (float) $transaction->amount : 0.0,
			// SELL-02: a simulated Test-gateway order (recorded at $0). The
			// template shows "no charge" instead of a price so the receipt is
			// honest — no more "$129.00 paid via Test gateway".
			'is_test'             => (bool) ( $transaction && 'test' === (string) ( $transaction->payment_provider ?? '' ) ),
			'email_sent_to'       => $email_sent_to,
			'receipt_link'        => $receipt_link,
			'primary_cta_url'     => $primary_cta_url,
			'primary_cta_label'   => $primary_cta_label,
			'secondary_cta_url'   => $secondary_cta_url,
			'secondary_cta_label' => $secondary_cta_label,
			'polling_seconds'     => $polling_seconds,
			'polling_exhausted'   => $polling_exhausted,
			'polling_next_url'    => $polling_next_url,
			'polling_waited'      => $polling_waited,
			'retry_url'           => self::resolve_retry_url( $course_id, $plan_id ),
		);

		/**
		 * Filter the thank-you view data before it reaches the template.
		 *
		 * Pro membership extensions and analytics modules use this to
		 * decorate the page with upsell offers, conversion pixels, or
		 * lifecycle metadata. Free passes a vanilla payload; Pro can add
		 * keys without core ever knowing.
		 *
		 * @since 2.2.0
		 *
		 * @param array         $view_data   Resolved view data.
		 * @param object|null   $transaction Local transaction row (null when webhook race).
		 * @param int           $user_id     Current user.
		 */
		return (array) apply_filters( 'learnomy_checkout_thank_you_view_data', $view_data, $transaction, $user_id );
	}

	/**
	 * Polite holding-state polling interval (seconds). Filterable so test
	 * harnesses can drop it to 1 without touching the template.
	 */
	private static function resolve_polling_interval(): int {
		$seconds = (int) apply_filters( 'learnomy_thank_you_polling_seconds', 3 );
		// Clamp — too fast hammers the adapter, too slow looks broken.
		return max( 1, min( 60, $seconds ) );
	}

	/**
	 * How long the holding state may keep refreshing before it stops and says
	 * something (seconds).
	 *
	 * The holding state used to refresh unconditionally, forever. When
	 * fulfilment genuinely could not arrive — a webhook endpoint that was never
	 * configured, a provider outage, or a payload our handler could not read —
	 * the buyer sat on a spinner for as long as they were willing to, having
	 * been charged, with no instruction and nothing to quote to support. A
	 * screen that cannot make progress must say so.
	 *
	 * Ninety seconds is far longer than a healthy webhook round trip and short
	 * enough that nobody is left guessing.
	 */
	private static function resolve_polling_limit(): int {
		$seconds = (int) apply_filters( 'learnomy_thank_you_polling_limit_seconds', 90 );
		return max( 5, min( 600, $seconds ) );
	}

	/**
	 * Ask the adapter what the gateway thinks of this session. Bounded
	 * single call, falls back to 'pending' if the adapter doesn't exist or
	 * can't answer.
	 */
	private static function query_adapter_status( string $gateway, string $session_id ): string {
		if ( '' === $session_id ) {
			return self::STATE_PENDING;
		}

		// When the gateway is unspecified (e.g. PayPal redirect without a
		// `gateway` param), try every adapter that recognises the session
		// shape. In practice we always pass `gateway=` on the return URL,
		// so this is a defensive fallback.
		$gateways = $gateway ? array( $gateway ) : array( 'stripe', 'paypal', 'test' );

		foreach ( $gateways as $gateway_id ) {
			$adapter = Adapter_Registry::get( $gateway_id );
			if ( ! $adapter ) {
				continue;
			}

			$status = $adapter->verify_payment_status( $session_id );

			switch ( $status ) {
				case 'paid':
					return self::STATE_PAID;
				case 'failed':
					return self::STATE_FAILED;
				case 'pending':
					return self::STATE_PENDING;
				case 'unknown':
				default:
					// Try the next adapter on multi-gateway fallback,
					// otherwise default to polling — the webhook may still
					// land in the next refresh cycle.
					continue 2;
			}
		}

		return self::STATE_PENDING;
	}

	/**
	 * Build the (primary, secondary) CTA pair for the resolved state.
	 *
	 * @return array{0: string, 1: string, 2: string, 3: string} URL+label tuples for [primary, secondary].
	 */
	private static function resolve_ctas( string $state, ?object $course, ?object $plan, int $course_id, int $plan_id, array $included ): array {
		// Every URL below comes from the Route Registry. This method used to
		// build the catalog link as home_url( '/' . $settings['base_slug'] . '/' )
		// in four places — a parallel URL builder for a route that already has a
		// name, which is exactly what the registry exists to stop.
		$catalog_url = \Learnomy\route_url( 'catalog' );

		// Failure: the buyer needs a retry path, full stop.
		if ( self::STATE_FAILED === $state ) {
			return array(
				self::resolve_retry_url( $course_id, $plan_id ),
				__( 'Try payment again', 'learnomy' ),
				$catalog_url,
				__( 'Browse Courses', 'learnomy' ),
			);
		}

		// Plan purchase. The buyer just paid for a bundle; the one thing they
		// want is a way INTO it. "Manage Membership" answers a question nobody
		// asks thirty seconds after paying, so it moves to secondary and the
		// primary becomes the shortest path to the thing they bought:
		//
		// exactly one bundled course -> straight into that course
		// several bundled courses    -> My Courses, where all of them now sit
		// (Subscription_Service has already
		// enrolled them, source=membership)
		// none bundled, but a
		// "pick any N" allowance     -> the catalog, to spend it
		// nothing resolvable         -> Manage Membership, as before
		if ( $plan_id ) {
			$courses = $included['courses'] ?? array();
			$total   = (int) ( $included['total'] ?? 0 );

			if ( 1 === $total && ! empty( $courses[0]->slug ) ) {
				return array(
					\Learnomy\route_url( 'course', array( 'slug' => (string) $courses[0]->slug ) ),
					/* translators: %s: course title */
					sprintf( __( 'Start %s', 'learnomy' ), (string) ( $courses[0]->title ?? '' ) ),
					\Learnomy\route_url( 'membership-manage' ),
					__( 'Manage Membership', 'learnomy' ),
				);
			}

			if ( $total > 1 ) {
				return array(
					\Learnomy\route_url( 'account-courses' ),
					__( 'Start learning', 'learnomy' ),
					\Learnomy\route_url( 'membership-manage' ),
					__( 'Manage Membership', 'learnomy' ),
				);
			}

			if ( $plan && (int) ( $plan->course_limit ?? 0 ) > 0 ) {
				return array(
					$catalog_url,
					__( 'Choose your courses', 'learnomy' ),
					\Learnomy\route_url( 'membership-manage' ),
					__( 'Manage Membership', 'learnomy' ),
				);
			}

			return array(
				\Learnomy\route_url( 'membership-manage' ),
				__( 'Manage Membership', 'learnomy' ),
				$catalog_url,
				__( 'Browse Courses', 'learnomy' ),
			);
		}

		// Course purchase: start the course right now.
		if ( $course ) {
			return array(
				\Learnomy\route_url( 'course', array( 'slug' => (string) ( $course->slug ?? '' ) ) ),
				__( 'Start course', 'learnomy' ),
				\Learnomy\route_url( 'account-courses' ),
				__( 'My courses', 'learnomy' ),
			);
		}

		// Generic fallback — pending state with no resolved object.
		return array(
			\Learnomy\route_url( 'account-courses' ),
			__( 'My courses', 'learnomy' ),
			$catalog_url,
			__( 'Browse Courses', 'learnomy' ),
		);
	}

	/**
	 * Build the "try again" URL pointing back at the checkout source.
	 */
	private static function resolve_retry_url( int $course_id, int $plan_id ): string {
		$base = \Learnomy\route_url( 'enroll' );
		if ( $course_id ) {
			return add_query_arg( 'course_id', $course_id, $base );
		}
		if ( $plan_id ) {
			return add_query_arg( 'plan_id', $plan_id, $base );
		}
		return $base;
	}

	/**
	 * Human-readable gateway label. Falls back to the transaction's
	 * payment_provider column when the query string didn't carry one
	 * (older PayPal redirects, third-party integrations).
	 */
	private static function resolve_gateway_label( string $gateway, ?object $transaction ): string {
		if ( '' === $gateway && $transaction && ! empty( $transaction->payment_provider ) ) {
			$gateway = (string) $transaction->payment_provider;
		}

		switch ( $gateway ) {
			case 'stripe':
				return __( 'Card (Stripe)', 'learnomy' );
			case 'paypal':
				return __( 'PayPal', 'learnomy' );
			case 'woocommerce':
				return __( 'WooCommerce', 'learnomy' );
			case 'test':
				return __( 'Test gateway', 'learnomy' );
			default:
				return $gateway ? ucfirst( $gateway ) : __( 'Payment provider', 'learnomy' );
		}
	}
}
