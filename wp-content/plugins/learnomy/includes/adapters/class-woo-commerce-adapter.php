<?php
/**
 * WooCommerce payment gateway adapter.
 *
 * WooCommerce acts as a pure payment gateway: it only collects the money.
 * Every Learnomy purchase type — course, membership, space, space seat, gift,
 * learning path — flows through ONE reusable hidden product whose line price and
 * name are set per checkout from a `learnomy_purchase` payload. On order
 * completion the adapter records the transaction and fires the gateway-agnostic
 * `learnomy_purchase_completed` / `learnomy_payment_completed` actions, which the
 * existing per-type fulfilment listeners (enrolment, subscription, spaces, gift,
 * path, commission) consume identically for every gateway.
 *
 * See plan/archive/2026-06-06-woocommerce-universal-gateway.md for the full design and
 * the contract any future 3rd-party gateway follows.
 *
 * @package Learnomy
 */

namespace Learnomy\Adapters;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Transaction;
use Learnomy\Services\Purchase_Recorder;
use Learnomy\Services\Enrollment_Service;
use Learnomy\Services\Subscription_Service;
use Learnomy\Services\Refund_Service;

class WooCommerce_Adapter implements Payment_Adapter {

	/** SKU + option key for the single reusable gateway line-item product. */
	private const PRODUCT_SKU    = 'learnomy-checkout-item';
	private const PRODUCT_OPTION = 'learnomy_wc_gateway_product';

	/** Cart/line-item key carrying the Learnomy purchase payload. */
	private const PAYLOAD_KEY = 'learnomy_purchase';

	public function __construct() {
		// Order lifecycle — fulfil on the first paid status. A virtual,
		// non-downloadable order lands in "processing" under most gateways and
		// only reaches "completed" when virtual+downloadable or marked by an
		// admin, so we hook BOTH and guard with an order meta flag so a
		// processing -> completed transition never double-fulfils.
		add_action( 'woocommerce_order_status_processing', [ $this, 'fulfill_order' ], 10, 1 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'fulfill_order' ], 10, 1 );
		add_action( 'woocommerce_order_refunded', [ $this, 'on_order_refunded' ], 10, 2 );

		// Recurring (WooCommerce Subscriptions) lifecycle -> Subscription_Service.
		// Only wired when the extension is present; resolved back to the Learnomy
		// subscription via the `_learnomy_subscription_id` meta stamped on the WC
		// subscription at first fulfilment.
		if ( $this->subscriptions_active() ) {
			add_action( 'woocommerce_subscription_status_cancelled', [ $this, 'on_subscription_cancelled' ], 10, 1 );
			add_action( 'woocommerce_subscription_status_expired', [ $this, 'on_subscription_expired' ], 10, 1 );
			add_action( 'woocommerce_subscription_status_active', [ $this, 'on_subscription_reactivated' ], 10, 1 );
			add_action( 'woocommerce_subscription_payment_failed', [ $this, 'on_subscription_payment_failed' ], 10, 1 );
		}

		// Dynamic pricing + labelling for our gateway line item. Scoped to cart
		// items carrying our payload, so other WC products are never touched.
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_dynamic_price' ], 20, 1 );
		add_filter( 'woocommerce_cart_item_name', [ $this, 'cart_item_label' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_thumbnail', [ $this, 'cart_item_thumbnail' ], 10, 3 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'cart_item_meta_lines' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'persist_line_item' ], 10, 4 );

		// Render the `Link` meta we stamp on each line as an actual link. Woo
		// hardcodes the item-name link to the (shared) product with no filter,
		// so this is where an order gets a route back to what was bought.
		add_filter( 'woocommerce_order_item_display_meta_value', [ $this, 'linkify_item_meta' ], 10, 2 );
	}

	public function get_id(): string {
		return 'woocommerce';
	}

	public function get_label(): string {
		return __( 'WooCommerce Checkout', 'learnomy' );
	}

	public function is_available(): bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Whether WooCommerce is taxing this sale, so Learnomy should not.
	 *
	 * A store is not a rail. Woo has a full tax engine — rates by country and
	 * state, tax classes, inclusive/exclusive display, exemptions — and our
	 * checkout line item is taxable to it by default. Adding Learnomy's flat
	 * rate on top would charge the buyer twice: once by us, once by Woo, on a
	 * total that already contained ours.
	 *
	 * But only when Woo is ACTUALLY calculating tax. "Enable taxes" is off by
	 * default in WooCommerce and plenty of stores never switch it on, so
	 * standing back unconditionally would mean a Woo buyer paid no tax at all
	 * while a Stripe buyer paid the owner's configured rate — the same parity
	 * bug from the other side, and a silent under-collection rather than a
	 * visible double charge.
	 *
	 * So: Woo taxes it when Woo is set up to, and we tax it when Woo is not.
	 * Either way the buyer is charged exactly once, and the owner's intent —
	 * "this sale is taxed" — survives whichever rail carries it.
	 */
	public function handles_tax(): bool {
		return 'yes' === get_option( 'woocommerce_calc_taxes' );
	}

	/**
	 * Universal entry point — builds a WooCommerce checkout for ANY purchase
	 * type. The checkout controller has already resolved the sale- and
	 * coupon-adjusted `amount`; we just collect it.
	 *
	 * @param array $args purchase_type, item_id, amount, label/item_label,
	 *                    currency, user_id, extra/extra_metadata.
	 * @return array ['redirect_url','session_id'] or [] to fall through.
	 */
	public function create_purchase_session( array $args ): array {
		if ( ! $this->is_available() || ! function_exists( 'WC' ) ) {
			return [];
		}

		// Same normaliser Stripe and PayPal use — it resolves the legacy arg
		// shapes AND puts Learnomy's tax on top of Learnomy's price, so the
		// total does not depend on which rail the buyer picked. This adapter
		// used to skip it and charged the pre-tax figure.
		$args = Adapter_Registry::normalize_purchase_args( $args, $this );

		// This runs inside the Learnomy REST checkout request, where WooCommerce
		// hasn't booted its frontend cart/session. Load them on demand so
		// add_to_cart() works; wc_load_cart() is a no-op when already loaded.
		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( null === WC()->cart ) {
			return [];
		}

		$purchase_type = isset( $args['purchase_type'] ) ? sanitize_key( (string) $args['purchase_type'] ) : '';
		$item_id       = (int) ( $args['item_id'] ?? $args['course_id'] ?? $args['plan_id'] ?? 0 );
		$amount        = (float) ( $args['amount'] ?? 0 );
		$buyer_id      = (int) ( $args['user_id'] ?? get_current_user_id() );

		// Legacy callers pass course_id / plan_id instead of purchase_type/item_id.
		if ( '' === $purchase_type ) {
			if ( ! empty( $args['plan_id'] ) ) {
				$purchase_type = 'membership';
			} elseif ( ! empty( $args['course_id'] ) ) {
				$purchase_type = 'course';
			}
		}

		if ( '' === $purchase_type || $amount <= 0 ) {
			return [];
		}

		// Recurring purchases (membership plans + recurring courses) bill through
		// WooCommerce Subscriptions via a period-keyed subscription product; one-
		// time purchases use the single generic product. When the entity is
		// recurring but WC Subscriptions isn't installed, fall through so another
		// gateway (Stripe/PayPal recurring) handles it.
		$schedule = ( 'subscription' === ( $args['mode'] ?? '' ) || $this->is_recurring_purchase( $purchase_type, $item_id ) )
			? $this->resolve_schedule( $purchase_type, $item_id )
			: null;

		if ( $schedule ) {
			// A recurring purchase through WooCommerce needs the WooCommerce
			// Subscriptions extension: without it there is no product type that
			// can bill again, so we would take one payment and silently never
			// charge a second. Returning an empty session is correct - but the
			// CALLER used to report only "the provider did not return a URL",
			// which tells the owner nothing about what to install. Say it once,
			// here, where the reason is known.
			if ( ! $this->subscriptions_active() ) {
				$this->unavailable_reason = __( 'Recurring payments through WooCommerce need the WooCommerce Subscriptions extension. Install and activate it, or choose a different payment gateway for this plan.', 'learnomy' );
				return [];
			}
			// The plan's trial has to reach WooCommerce, not just our own row.
			// Every caller already passes `trial_days` (Stripe has honoured it
			// since 10172704327); this adapter simply threw it away, so a buyer
			// promised "Nothing is charged today" was charged the full price on
			// day one (Basecamp 10184336183 / 10184381896).
			$product_id = $this->subscription_product(
				$schedule['period'],
				$schedule['interval'],
				max( 0, (int) ( $args['trial_days'] ?? 0 ) )
			);
		} else {
			$product_id = $this->gateway_product();
		}

		if ( ! $product_id ) {
			return [];
		}

		$label = (string) ( $args['label'] ?? $args['item_label'] ?? $this->default_label( $purchase_type, $item_id ) );
		$meta  = isset( $args['extra_metadata'] ) && is_array( $args['extra_metadata'] )
			? $args['extra_metadata']
			: ( isset( $args['extra'] ) && is_array( $args['extra'] ) ? $args['extra'] : [] );

		// Buyer-clarity fields: the featured image + a short detail line so the
		// WC cart / checkout shows exactly what's being bought instead of the
		// generic gateway product. The caller may pass them; otherwise resolve
		// from the entity (course/membership).
		$image = (string) ( $args['image'] ?? $this->purchase_image_url( $purchase_type, $item_id ) );
		$info  = (string) ( $args['info'] ?? $this->purchase_info( $purchase_type, $item_id ) );

		// One item per Learnomy checkout — clear so unrelated cart items don't
		// ride along into the order.
		WC()->cart->empty_cart();

		$cart_item_key = WC()->cart->add_to_cart(
			$product_id,
			1,
			0,
			[],
			[
				self::PAYLOAD_KEY => [
					'type'      => $purchase_type,
					'item_id'   => $item_id,
					'buyer'     => $buyer_id,
					'amount'    => round( $amount, 2 ),
					// The tax WE put on the price (0 when Woo is taxing instead).
					// Carried so fulfilment can keep it out of the commission base
					// — a split computed on a tax-inclusive total pays the platform
					// and the instructor out of money owed to the tax authority.
					'tax'       => round( (float) ( $args['tax'] ?? 0 ), 2 ),
					'label'     => $label,
					'image'     => $image,
					'info'      => $info,
					'recurring' => $schedule, // null for one-time; ['period','interval'] for recurring.
					'meta'      => $meta,
				],
			]
		);

		if ( ! $cart_item_key ) {
			return [];
		}

		return [
			'redirect_url' => wc_get_checkout_url(),
			'session_id'   => 'wc_' . $cart_item_key,
		];
	}

	/**
	 * Deprecated one-time entry point — delegates to create_purchase_session so
	 * existing course-checkout callers keep working.
	 *
	 * @param array $args Checkout parameters.
	 */
	public function create_checkout( array $args ): array {
		if ( empty( $args['purchase_type'] ) && ! empty( $args['course_id'] ) ) {
			$args['purchase_type'] = 'course';
			$args['item_id']       = (int) $args['course_id'];
		}
		return $this->create_purchase_session( $args );
	}

	/**
	 * Deprecated subscription entry point. We collect membership as a one-time
	 * WooCommerce order (recurring memberships use Stripe/PayPal recurring); this
	 * delegates so no caller breaks.
	 *
	 * @param array $args Subscription parameters.
	 */
	public function create_subscription( array $args ): array {
		if ( empty( $args['purchase_type'] ) && ! empty( $args['plan_id'] ) ) {
			$args['purchase_type'] = 'membership';
			$args['item_id']       = (int) $args['plan_id'];
		}
		return $this->create_purchase_session( $args );
	}

	/**
	 * Cancels a WC Subscription by internal ID (only used when WC Subscriptions
	 * is present; recurring memberships normally run on Stripe/PayPal).
	 */
	public function cancel_subscription( string $provider_sub_id ): bool {
		if ( ! function_exists( 'wcs_get_subscription' ) ) {
			return false;
		}

		$subscription = wcs_get_subscription( (int) $provider_sub_id );
		if ( ! $subscription ) {
			return false;
		}

		$subscription->update_status( 'cancelled' );
		return true;
	}

	/**
	 * WooCommerce reports order status through its own action hooks, not REST
	 * webhooks — handled in fulfill_order(). Intentionally a no-op.
	 */
	public function process_webhook( \WP_REST_Request $request ): void {
		unset( $request );
	}

	/**
	 * WooCommerce owns its post-purchase page, so the Learnomy thank-you surface
	 * never receives a WC session id. Returns 'unknown' to keep the contract
	 * honest.
	 */
	public function verify_payment_status( string $session_id ): string {
		unset( $session_id );
		return 'unknown';
	}

	/**
	 * Issues a WC refund against an order.
	 *
	 * @param string $transaction_id WC order ID stored at payment time.
	 * @param float  $amount         Amount to refund.
	 */
	public function refund( string $transaction_id, float $amount ): string {
		if ( ! function_exists( 'wc_create_refund' ) ) {
			return '';
		}

		$refund = wc_create_refund(
			[
				'order_id' => (int) $transaction_id,
				'amount'   => $amount,
				'reason'   => __( 'Refund requested via Learnomy.', 'learnomy' ),
			]
		);

		if ( is_wp_error( $refund ) || ! is_object( $refund ) ) {
			return '';
		}

		// Key the ledger row on the same handle the woocommerce_order_refunded
		// echo uses (`wc_refund_<id>`). wc_create_refund() fires that echo
		// synchronously, so without a matching key the admin action and the echo
		// would each record a row.
		return 'wc_refund_' . $refund->get_id();
	}

	// -------------------------------------------------------------------------
	// Cart hooks — dynamic price + label for the gateway line item
	// -------------------------------------------------------------------------

	/**
	 * Set the gateway line item's price to the Learnomy amount. Scoped to cart
	 * items carrying our payload; other products are untouched.
	 *
	 * @param \WC_Cart $cart Cart being totalled.
	 */
	public function apply_dynamic_price( $cart ): void {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item[ self::PAYLOAD_KEY ]['amount'] ) || ! isset( $cart_item['data'] ) ) {
				continue;
			}
			$cart_item['data']->set_price( (float) $cart_item[ self::PAYLOAD_KEY ]['amount'] );
		}
	}

	/**
	 * Show the human label (course / space / plan name) on the cart + checkout
	 * instead of the generic gateway product name.
	 *
	 * @param string $name      Default line name.
	 * @param array  $cart_item Cart item.
	 * @param string $cart_key  Cart item key.
	 */
	public function cart_item_label( $name, $cart_item, $cart_key ): string {
		unset( $cart_key );
		if ( ! empty( $cart_item[ self::PAYLOAD_KEY ]['label'] ) ) {
			return esc_html( (string) $cart_item[ self::PAYLOAD_KEY ]['label'] );
		}
		return (string) $name;
	}

	/**
	 * Show the purchased entity's featured image on the cart + checkout instead
	 * of the generic gateway product's placeholder, so the buyer sees what
	 * they're buying.
	 *
	 * @param string $thumbnail Default thumbnail HTML.
	 * @param array  $cart_item Cart item.
	 * @param string $cart_key  Cart item key.
	 */
	public function cart_item_thumbnail( $thumbnail, $cart_item, $cart_key ): string {
		unset( $cart_key );
		$url = $cart_item[ self::PAYLOAD_KEY ]['image'] ?? '';
		if ( '' === $url ) {
			return (string) $thumbnail;
		}
		$alt = (string) ( $cart_item[ self::PAYLOAD_KEY ]['label'] ?? '' );
		return sprintf(
			'<img src="%s" alt="%s" class="lrn-wc-thumb" loading="lazy" />',
			esc_url( $url ),
			esc_attr( $alt )
		);
	}

	/**
	 * Add a short detail line (e.g. "Course · by Instructor") under the item on
	 * the cart + checkout so the buyer has context beyond the title.
	 *
	 * @param array $item_data Existing item data rows.
	 * @param array $cart_item Cart item.
	 */
	public function cart_item_meta_lines( $item_data, $cart_item ): array {
		$item_data = is_array( $item_data ) ? $item_data : [];
		$info      = $cart_item[ self::PAYLOAD_KEY ]['info'] ?? '';
		if ( '' !== $info ) {
			$item_data[] = [
				'key'     => __( 'Details', 'learnomy' ),
				'value'   => esc_html( (string) $info ),
				'display' => '',
			];
		}
		return $item_data;
	}

	/**
	 * Persist the payload (and the label as the line name) onto the order line
	 * item so fulfill_order can fulfil from the order alone.
	 *
	 * @param \WC_Order_Item_Product $item      Order line item.
	 * @param string                 $cart_key  Cart item key.
	 * @param array                  $values    Cart item values.
	 * @param \WC_Order              $order     Order being created.
	 */
	public function persist_line_item( $item, $cart_key, $values, $order ): void {
		unset( $cart_key, $order );
		if ( empty( $values[ self::PAYLOAD_KEY ] ) || ! is_array( $values[ self::PAYLOAD_KEY ] ) ) {
			return;
		}
		$payload = $values[ self::PAYLOAD_KEY ];
		$item->add_meta_data( self::PAYLOAD_KEY, $payload, true );
		if ( ! empty( $payload['label'] ) ) {
			$item->set_name( (string) $payload['label'] );
		}

		// Say WHAT was bought, and link to it, on the order itself.
		//
		// Every Learnomy sale rides one reusable line-item product, so the order
		// screen showed the item title with nothing to say whether it was a
		// course, a membership or space seats — and WooCommerce links that title
		// to the shared product, so every order on the site led to the same
		// hidden "Learnomy Checkout" record. Woo hardcodes that link with no
		// filter, so the real destination goes on its own line.
		//
		// The description comes from Free's shared resolver rather than being
		// worked out here, so the next store adapter (EDD, SureCart, FluentCart)
		// gets identical order lines for free, and Pro's own types register
		// themselves through its filter instead of being hardcoded per gateway.
		$descriptor = \Learnomy\purchase_descriptor(
			(string) ( $payload['type'] ?? '' ),
			(int) ( $payload['item_id'] ?? 0 )
		);

		if ( ! empty( $descriptor['type_label'] ) ) {
			$item->add_meta_data( __( 'Type', 'learnomy' ), (string) $descriptor['type_label'], true );
		}
		if ( ! empty( $payload['info'] ) ) {
			$item->add_meta_data( __( 'Details', 'learnomy' ), (string) $payload['info'], true );
		}
		if ( ! empty( $descriptor['url'] ) ) {
			$item->add_meta_data( __( 'Link', 'learnomy' ), (string) $descriptor['url'], true );
		}
	}

	/**
	 * Render our `Link` order-item meta as a link rather than raw URL text.
	 *
	 * @param string $display_value Value WooCommerce is about to print.
	 * @param object $meta          The meta being displayed.
	 * @return string
	 */
	public function linkify_item_meta( $display_value, $meta ) {
		$key = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : '';
		if ( __( 'Link', 'learnomy' ) !== $key ) {
			return $display_value;
		}

		$url = trim( wp_strip_all_tags( (string) $display_value ) );
		if ( '' === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return $display_value;
		}

		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a>';
	}


	// -------------------------------------------------------------------------
	// Order hooks — fulfilment + refunds
	// -------------------------------------------------------------------------

	/**
	 * Fires when a WooCommerce order first reaches a paid status (processing or
	 * completed). Records a Learnomy transaction and fires the unified fulfilment
	 * actions for every Learnomy line item. Idempotent via an order meta flag, so
	 * a processing -> completed transition fulfils exactly once.
	 *
	 * @param int $order_id WC order ID.
	 */
	public function fulfill_order( int $order_id ): void {
		if ( get_post_meta( $order_id, '_learnomy_processed', true ) ) {
			return;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return;
		}

		$is_renewal = function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order_id );
		$buyer_id   = (int) $order->get_customer_id();
		$processed  = false;

		foreach ( $order->get_items() as $item ) {
			$payload = $item->get_meta( self::PAYLOAD_KEY );
			if ( ! is_array( $payload ) || empty( $payload['type'] ) ) {
				continue;
			}

			$type    = sanitize_key( (string) $payload['type'] );
			$item_id = (int) ( $payload['item_id'] ?? 0 );
			$amount  = (float) ( $payload['amount'] ?? 0 );
			$meta    = isset( $payload['meta'] ) && is_array( $payload['meta'] ) ? $payload['meta'] : [];
			$buyer   = $buyer_id ?: (int) ( $payload['buyer'] ?? 0 );

			if ( $buyer <= 0 ) {
				continue;
			}

			$course_id = 'course' === $type ? $item_id : 0;
			$plan_id   = 'membership' === $type ? $item_id : 0;

			// Record the tax that is INSIDE `amount`, because that is the only
			// thing the commission base may deduct. Gross minus tax is the split
			// base, so the two have to describe the same money.
			//
			// When WE taxed the sale the tax is baked into the line price, so it
			// is part of `amount` and must come out before the split — otherwise
			// the platform and the instructor are paid from money owed to the tax
			// authority. When WOO taxed it, Woo added its rate ON TOP and
			// `amount` is already net, so deducting `get_total_tax()` here would
			// subtract a tax the amount never contained and underpay the
			// instructor. Measured both ways on a $49 course: recording Woo's
			// 9.80 gave the instructor 13.72 where 17.15 was owed.
			//
			// Woo's own tax total is still on the order, which is where an
			// accountant looks for it; it is not ours to split or to deduct.
			$our_tax        = (float) ( $payload['tax'] ?? 0 );
			$meta_for_store = $meta;
			if ( $is_renewal ) {
				$meta_for_store['renewal'] = true;
			}

			// transaction_type stays the entity type (course / membership) so the
			// course-commission path fires on first payment AND every renewal.
			//
			// One recorder owns the row shape, the idempotency key and the
			// fulfilment events for every gateway — see Purchase_Recorder. This
			// adapter's job is only to say what WooCommerce reported.
			$txn_id = Purchase_Recorder::record(
				[
					'user_id'     => $buyer,
					'gateway'     => 'woocommerce',
					'provider_id' => (string) $order_id,
					'type'        => $type,
					'item_id'     => $item_id,
					'course_id'   => $course_id,
					'plan_id'     => $plan_id,
					'amount'      => $amount > 0 ? $amount : (float) $order->get_total(),
					'currency'    => $order->get_currency(),
					'tax'         => $our_tax,
					'meta'        => $meta_for_store,
					'ext'         => $meta,
				]
			);

			if ( $is_renewal ) {
				// Recurring renewal: extend the existing Learnomy subscription. Do
				// NOT re-subscribe / re-enrol. The actions below still fire so
				// course commission is paid each cycle.
				$this->renew_learnomy_subscription( $order );
			} elseif ( ! empty( $payload['recurring'] ) ) {
				// First payment of a recurring purchase: open the Learnomy
				// subscription and map it onto the WC subscription so later
				// renewals / cancellations resolve back to it.
				$sub_id = 0;
				if ( $plan_id ) {
					$sub_id = (int) ( new Subscription_Service() )->subscribe( $buyer, $plan_id, 'woocommerce' );
				} elseif ( $course_id ) {
					$sub_id = (int) ( new Subscription_Service() )->subscribe_to_course( $buyer, $course_id, 'woocommerce' );
				}
				if ( $sub_id > 0 ) {
					$this->map_wc_subscription( $order, $sub_id );
				}
			} else {
				// One-time: course enrols, membership (lifetime) subscribes.
				if ( $course_id ) {
					$enroll_result = ( new Enrollment_Service() )->enroll( $buyer, $course_id, 'purchase' );
					// The customer has paid: a non-positive int or a WP_Error means
					// they were charged but NOT enrolled (e.g. a pre-enrol gate
					// vetoed it). Never let that pass silently — surface it on the
					// order, in the audit log, and via an action so the site owner
					// can act and integrations (cohorts, etc.) can self-heal.
					if ( is_wp_error( $enroll_result ) || (int) $enroll_result <= 0 ) {
						$this->report_failed_enrollment( $order, $buyer, $course_id, $enroll_result );
					}
				}
				if ( $plan_id ) {
					( new Subscription_Service() )->subscribe( $buyer, $plan_id, 'woocommerce' );
				}
			}

			$processed = true;
		}

		if ( $processed ) {
			update_post_meta( $order_id, '_learnomy_processed', true );
		}
	}

	/**
	 * A paid order could not be turned into a course enrollment. The transaction
	 * is already recorded (the customer was charged), so the failure must be
	 * loud, never silent: it lands on the order timeline for the shop manager, in
	 * the Learnomy audit log for support, and on a hook so an integration that
	 * understands the reason (e.g. the cohort extension) can place the buyer
	 * automatically. Fulfilment still completes for the rest of the order.
	 *
	 * @param \WC_Order     $order     The paid order.
	 * @param int           $buyer     Buyer user id.
	 * @param int           $course_id Course that could not be enrolled.
	 * @param int|\WP_Error $result    The enroll() return (non-positive int or WP_Error).
	 */
	private function report_failed_enrollment( $order, int $buyer, int $course_id, $result ): void {
		$reason   = is_wp_error( $result ) ? $result->get_error_message() : __( 'Enrollment could not be created.', 'learnomy' );
		$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;

		// WooCommerce-specific surface: a note on the order timeline so the shop
		// manager sees it immediately on the order screen.
		if ( is_object( $order ) && method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: course id, 2: reason */
					__( 'Learnomy: payment received but the customer was not enrolled in course #%1$d. Reason: %2$s. Enroll them manually or resolve the blocker.', 'learnomy' ),
					$course_id,
					$reason
				)
			);
		}

		// Gateway-agnostic audit log + self-heal/alert hook (shared chokepoint so
		// Stripe and PayPal report failures identically).
		Enrollment_Service::report_purchase_failure(
			$buyer,
			$course_id,
			$result,
			'woocommerce',
			array( 'order_id' => $order_id )
		);
	}

	// -------------------------------------------------------------------------
	// WooCommerce Subscriptions lifecycle -> Subscription_Service
	// -------------------------------------------------------------------------

	/**
	 * Map a WC subscription (from its order) to the Learnomy subscription id so
	 * later renewal / cancellation hooks can resolve it.
	 *
	 * @param \WC_Order $order  Parent order that created the subscription.
	 * @param int       $sub_id Learnomy subscription id.
	 */
	private function map_wc_subscription( $order, int $sub_id ): void {
		foreach ( $this->wc_subscriptions_for_order( $order ) as $wc_sub ) {
			$wc_sub->update_meta_data( '_learnomy_subscription_id', $sub_id );
			$wc_sub->save();
		}
	}

	/**
	 * Extend the Learnomy subscription tied to a renewal order by one billing
	 * cycle. The new period starts where the current one ends (contiguous), and
	 * ends one billing interval later — or at the WC subscription's next_payment
	 * date when that's already further out (WC stays the billing authority).
	 *
	 * @param \WC_Order $order Renewal order.
	 */
	private function renew_learnomy_subscription( $order ): void {
		foreach ( $this->wc_subscriptions_for_order( $order ) as $wc_sub ) {
			$sub_id = (int) $wc_sub->get_meta( '_learnomy_subscription_id' );
			if ( $sub_id <= 0 ) {
				continue;
			}

			$sub         = \Learnomy\Models\Subscription::find( $sub_id );
			$current_end = $sub && ! empty( $sub->current_period_end ) ? (string) $sub->current_period_end : \Learnomy\now();
			$base_ts     = strtotime( $current_end ) ?: time();

			$period   = (string) $wc_sub->get_billing_period();   // day/week/month/year
			$interval = max( 1, (int) $wc_sub->get_billing_interval() );
			$computed = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $interval . ' ' . ( $period ?: 'month' ), $base_ts ) );

			$next_payment = (string) $wc_sub->get_date( 'next_payment' );
			$new_end      = ( $next_payment && strtotime( $next_payment ) > $base_ts ) ? $next_payment : $computed;

			( new Subscription_Service() )->renew( $sub_id, $current_end, $new_end );
		}
	}

	/** WC subscriptions related to an order (parent or renewal), or []. */
	private function wc_subscriptions_for_order( $order ): array {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return [];
		}
		return (array) wcs_get_subscriptions_for_order( $order, [ 'order_type' => 'any' ] );
	}

	/**
	 * Resolve the Learnomy subscription id stamped on a WC subscription.
	 *
	 * @param \WC_Subscription $wc_sub WC subscription.
	 */
	private function learnomy_subscription_id( $wc_sub ): int {
		return is_object( $wc_sub ) ? (int) $wc_sub->get_meta( '_learnomy_subscription_id' ) : 0;
	}

	/**
	 * WC subscription cancelled -> cancel the Learnomy subscription at period end.
	 *
	 * @param \WC_Subscription $wc_sub WC subscription.
	 */
	public function on_subscription_cancelled( $wc_sub ): void {
		$sub_id = $this->learnomy_subscription_id( $wc_sub );
		if ( $sub_id > 0 ) {
			( new Subscription_Service() )->cancel( $sub_id, false );
		}
	}

	/**
	 * WC subscription expired -> expire the Learnomy subscription.
	 *
	 * @param \WC_Subscription $wc_sub WC subscription.
	 */
	public function on_subscription_expired( $wc_sub ): void {
		$sub_id = $this->learnomy_subscription_id( $wc_sub );
		if ( $sub_id > 0 ) {
			( new Subscription_Service() )->expire( $sub_id );
		}
	}

	/**
	 * WC subscription payment failed -> mark the Learnomy subscription past due.
	 *
	 * @param \WC_Subscription $wc_sub WC subscription.
	 */
	public function on_subscription_payment_failed( $wc_sub ): void {
		$sub_id = $this->learnomy_subscription_id( $wc_sub );
		if ( $sub_id > 0 ) {
			( new Subscription_Service() )->mark_past_due( $sub_id, 'wc_subscription_payment_failed' );
		}
	}

	/**
	 * WC subscription reactivated (back to active) -> resume the Learnomy
	 * subscription. Guarded so the initial activation (which has no Learnomy
	 * subscription mapped yet) is a no-op.
	 *
	 * @param \WC_Subscription $wc_sub WC subscription.
	 */
	public function on_subscription_reactivated( $wc_sub ): void {
		$sub_id = $this->learnomy_subscription_id( $wc_sub );
		if ( $sub_id > 0 ) {
			( new Subscription_Service() )->resume( $sub_id );
		}
	}

	/**
	 * Reconcile a WooCommerce refund through the shared Refund_Service, which
	 * reverses the ledger and (for a full refund) revokes access. Idempotent
	 * against our own REST refund via the service's one-refund-per-original guard.
	 *
	 * @param int $order_id  Refunded WC order ID (our provider_transaction_id).
	 * @param int $refund_id WC refund post ID.
	 */
	public function on_order_refunded( int $order_id, int $refund_id ): void {
		$original = Transaction::find_by_provider_id( (string) $order_id );
		if ( ! $original || 'refunded' === ( $original->status ?? '' ) ) {
			return;
		}

		$amount = 0.0;
		if ( function_exists( 'wc_get_order' ) ) {
			$refund = wc_get_order( $refund_id );
			if ( $refund ) {
				$amount = abs( (float) $refund->get_amount() );
			}
		}
		if ( $amount <= 0 ) {
			$amount = (float) $original->amount;
		}

		( new Refund_Service() )->apply( $original, $amount, 'woocommerce', 'wc_refund_' . $refund_id );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Find-or-create the single reusable, hidden, virtual product every Learnomy
	 * checkout line uses. Recovered by SKU if the cached option goes stale.
	 *
	 * @return int WC product ID, or 0 when WooCommerce can't mint one.
	 */
	private function gateway_product(): int {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$product_id = (int) get_option( self::PRODUCT_OPTION, 0 );
		if ( $product_id && wc_get_product( $product_id ) ) {
			return $product_id;
		}

		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$by_sku = (int) wc_get_product_id_by_sku( self::PRODUCT_SKU );
			if ( $by_sku ) {
				update_option( self::PRODUCT_OPTION, $by_sku, false );
				return $by_sku;
			}
		}

		$product = new \WC_Product_Simple();
		$product->set_name( __( 'Learnomy Checkout', 'learnomy' ) );
		$product->set_sku( self::PRODUCT_SKU );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_regular_price( '0' );
		$product->set_price( '0' );

		$product_id = (int) $product->save();
		if ( $product_id ) {
			update_option( self::PRODUCT_OPTION, $product_id, false );
		}

		return $product_id;
	}

	// -------------------------------------------------------------------------
	// Recurring (WooCommerce Subscriptions)
	// -------------------------------------------------------------------------

	/** Whether the WooCommerce Subscriptions extension is available. */
	/**
	 * Why the last session could not be created, in the owner's language.
	 *
	 * An empty session is a legitimate answer ("I cannot take this payment"),
	 * but a bare empty array loses the ONE thing the owner needs: which of the
	 * several possible reasons it was. Set at the point the reason is known and
	 * read by the checkout controller.
	 *
	 * @var string
	 */
	private string $unavailable_reason = '';

	/** The reason the last create_purchase_session() returned nothing, if known. */
	public function last_unavailable_reason(): string {
		return $this->unavailable_reason;
	}

	private function subscriptions_active(): bool {
		return class_exists( 'WC_Subscriptions' ) && class_exists( 'WC_Product_Subscription' );
	}

	/**
	 * Whether a purchase recurs: any membership plan on a recurring billing
	 * interval, or a course whose pricing model is recurring.
	 *
	 * @param string $type    Purchase type.
	 * @param int    $item_id Entity ID.
	 */
	private function is_recurring_purchase( string $type, int $item_id ): bool {
		return null !== $this->resolve_schedule( $type, $item_id );
	}

	/**
	 * Resolve a recurring purchase to a WooCommerce billing schedule, or null for
	 * one-time. Maps the entity's interval string to a WC period + interval.
	 *
	 * @param string $type    Purchase type.
	 * @param int    $item_id Entity ID.
	 * @return array{period:string,interval:int}|null
	 */
	private function resolve_schedule( string $type, int $item_id ): ?array {
		if ( $item_id <= 0 ) {
			return null;
		}

		$raw = '';
		if ( 'membership' === $type ) {
			$plan = \Learnomy\Models\MembershipPlan::find( $item_id );
			$raw  = $plan ? (string) ( $plan->billing_interval ?? '' ) : '';
		} elseif ( 'course' === $type ) {
			$course = \Learnomy\Models\Course::find( $item_id );
			if ( $course && \Learnomy\Models\Course::is_recurring( $course ) ) {
				$raw = (string) ( $course->recurring_interval ?? 'month' );
			}
		}

		/**
		 * Schedule for a purchase type this adapter does not know natively.
		 *
		 * Spaces is the case that exposed the gap: a Space Plan billed monthly
		 * fell past both branches above, resolved to no schedule at all, and was
		 * therefore sold as a one-time simple product — the owner got a single
		 * charge, no WooCommerce subscription, and no renewal, while the plan
		 * and the buy page both said monthly (Basecamp 10184010338). Free cannot
		 * read a `lrn_pro_*` table, so Pro answers for its own purchase types
		 * through this seam rather than Free growing a branch per Pro product.
		 *
		 * @param string $raw     Interval string ('' when unresolved).
		 * @param string $type    Purchase type (e.g. 'space').
		 * @param int    $item_id Entity id.
		 */
		$raw = (string) apply_filters( 'learnomy_purchase_billing_interval', $raw, $type, $item_id );

		return $this->normalize_schedule( $raw );
	}

	/**
	 * Normalise an interval string ('monthly' / 'month' / 'yearly' / 'year' /
	 * 'weekly' / 'daily') to a WC period + interval. Returns null for non-
	 * recurring values (empty / 'lifetime' / 'one_time').
	 *
	 * @param string $raw Interval string.
	 * @return array{period:string,interval:int}|null
	 */
	private function normalize_schedule( string $raw ): ?array {
		$map = [
			'day'     => 'day',
			'daily'   => 'day',
			'week'    => 'week',
			'weekly'  => 'week',
			'month'   => 'month',
			'monthly' => 'month',
			'year'    => 'year',
			'yearly'  => 'year',
			'annual'  => 'year',
		];

		$key = strtolower( trim( $raw ) );
		if ( ! isset( $map[ $key ] ) ) {
			return null;
		}

		return [
			'period'   => $map[ $key ],
			'interval' => 1,
		];
	}

	/**
	 * Find-or-create a hidden, virtual WooCommerce Subscriptions product keyed by
	 * billing period + interval + trial length (e.g. one shared "monthly"
	 * product, one "monthly with a 14-day trial"). The recurring amount is set
	 * dynamically per checkout by apply_dynamic_price, which WooCommerce
	 * Subscriptions reads as the recurring total.
	 *
	 * The trial belongs in the KEY, not just in the meta. WooCommerce holds the
	 * trial on the product, and this product is deliberately shared across every
	 * plan on the same cadence — so writing a trial onto the shared monthly
	 * product would hand that trial to every other monthly plan on the site, and
	 * whichever plan checked out last would win. Keying by trial keeps the
	 * find-or-create pattern and gives each distinct trial length its own hidden
	 * product (Basecamp 10184336183).
	 *
	 * @param string $period     WC period (day/week/month/year).
	 * @param int    $interval   Billing interval.
	 * @param int    $trial_days Free-trial length in days; 0 for no trial.
	 * @return int Product ID, or 0.
	 */
	private function subscription_product( string $period, int $interval, int $trial_days = 0 ): int {
		if ( ! $this->subscriptions_active() ) {
			return 0;
		}

		$trial_days = max( 0, $trial_days );
		// Trial-free products keep their original SKU and option key so existing
		// sites keep the product they already have instead of orphaning it.
		$suffix      = $trial_days > 0 ? '-t' . $trial_days : '';
		$sku         = 'learnomy-sub-' . $period . '-' . $interval . $suffix;
		$option_key  = 'learnomy_wc_sub_product_' . $period . '_' . $interval . ( $trial_days > 0 ? '_t' . $trial_days : '' );
		$existing_id = (int) get_option( $option_key, 0 );

		if ( $existing_id && wc_get_product( $existing_id ) ) {
			return $existing_id;
		}
		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$by_sku = (int) wc_get_product_id_by_sku( $sku );
			if ( $by_sku ) {
				update_option( $option_key, $by_sku, false );
				return $by_sku;
			}
		}

		$product = new \WC_Product_Subscription();
		if ( $trial_days > 0 ) {
			$product->set_name(
				sprintf(
					/* translators: 1: billing interval, 2: billing period, 3: trial length in days */
					__( 'Learnomy Subscription (every %1$d %2$s, %3$d-day trial)', 'learnomy' ),
					$interval,
					$period,
					$trial_days
				)
			);
		} else {
			/* translators: 1: billing interval, 2: billing period */
			$product->set_name( sprintf( __( 'Learnomy Subscription (every %1$d %2$s)', 'learnomy' ), $interval, $period ) );
		}
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( true );
		$product->set_regular_price( '0' );
		$product->set_price( '0' );
		$product->update_meta_data( '_subscription_price', '0' );
		$product->update_meta_data( '_subscription_period', $period );
		$product->update_meta_data( '_subscription_period_interval', (string) $interval );
		$product->update_meta_data( '_subscription_length', '0' ); // Until cancelled.
		$product->update_meta_data( '_subscription_sign_up_fee', '0' );
		// Expressed in days regardless of the billing period: a 14-day trial on a
		// yearly plan is 14 days, not 14 years.
		$product->update_meta_data( '_subscription_trial_length', (string) $trial_days );
		$product->update_meta_data( '_subscription_trial_period', 'day' );

		$product_id = (int) $product->save();
		if ( $product_id ) {
			update_option( $option_key, $product_id, false );
		}

		return $product_id;
	}

	/**
	 * Human label for a checkout line when the caller didn't supply one.
	 *
	 * @param string $type    Purchase type.
	 * @param int    $item_id Entity ID.
	 */
	private function default_label( string $type, int $item_id ): string {
		if ( 'course' === $type && $item_id ) {
			$course = \Learnomy\Models\Course::find( $item_id );
			if ( $course ) {
				return (string) $course->title;
			}
		}
		if ( 'membership' === $type && $item_id ) {
			$plan = \Learnomy\Models\MembershipPlan::find( $item_id );
			if ( $plan ) {
				return (string) $plan->title;
			}
		}
		return __( 'Learnomy purchase', 'learnomy' );
	}

	/**
	 * Featured-image URL for a checkout line when the caller didn't supply one.
	 * Resolved for course + membership; other types should pass `image` in args.
	 *
	 * @param string $type    Purchase type.
	 * @param int    $item_id Entity ID.
	 */
	private function purchase_image_url( string $type, int $item_id ): string {
		if ( 'course' === $type && $item_id ) {
			$course = \Learnomy\Models\Course::find( $item_id );
			if ( $course ) {
				if ( ! empty( $course->thumbnail_url ) ) {
					return (string) $course->thumbnail_url;
				}
				if ( ! empty( $course->thumbnail_id ) ) {
					$url = wp_get_attachment_image_url( (int) $course->thumbnail_id, 'woocommerce_thumbnail' );
					if ( $url ) {
						return (string) $url;
					}
				}
			}
		}
		return '';
	}

	/**
	 * Short detail line shown under the title on the cart/checkout so the buyer
	 * has context (kind of purchase + author).
	 *
	 * @param string $type    Purchase type.
	 * @param int    $item_id Entity ID.
	 */
	private function purchase_info( string $type, int $item_id ): string {
		// Translated labels for the known purchase-type vocabulary; ucwords()
		// only as a last-resort fallback for an unknown type slug. This label is
		// shown to the buyer on the WooCommerce cart / checkout line.
		$kind_labels = array(
			'course'          => __( 'Course', 'learnomy' ),
			'membership'      => __( 'Membership', 'learnomy' ),
			'membership_plan' => __( 'Membership', 'learnomy' ),
			'subscription'    => __( 'Subscription', 'learnomy' ),
			'plan'            => __( 'Plan', 'learnomy' ),
			'bundle'          => __( 'Bundle', 'learnomy' ),
		);
		$kind        = $kind_labels[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );

		if ( 'course' === $type && $item_id ) {
			$course = \Learnomy\Models\Course::find( $item_id );
			if ( $course && ! empty( $course->author_id ) ) {
				$author = get_userdata( (int) $course->author_id );
				if ( $author ) {
					/* translators: 1: purchase kind (e.g. Course), 2: instructor name */
					return sprintf( __( '%1$s · by %2$s', 'learnomy' ), $kind, $author->display_name );
				}
			}
		}

		return $kind;
	}
}
