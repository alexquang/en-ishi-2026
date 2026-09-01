<?php
/**
 * Payment adapter registry — a single place to look up any configured gateway.
 *
 * Built-in adapters are registered on init(); third-party plugins can add their
 * own by hooking learnomy_register_payment_adapters and calling
 * Adapter_Registry::register( $their_adapter ).
 *
 * @package Learnomy
 */

namespace Learnomy\Adapters;

defined( 'ABSPATH' ) || exit;

class Adapter_Registry {

	/** @var Payment_Adapter[] Keyed by adapter ID. */
	private static array $adapters = [];

	/**
	 * Adds a gateway to the registry.
	 * If two adapters share an ID the later registration wins —
	 * this lets plugins override built-in gateways when needed.
	 *
	 * @param Payment_Adapter $adapter
	 */
	public static function register( Payment_Adapter $adapter ): void {
		self::$adapters[ $adapter->get_id() ] = $adapter;
	}

	/**
	 * Returns one adapter by its ID, or null when it hasn't been registered.
	 *
	 * @param string $id Gateway identifier.
	 * @return Payment_Adapter|null
	 */
	public static function get( string $id ): ?Payment_Adapter {
		return self::$adapters[ $id ] ?? null;
	}

	/**
	 * Returns every registered adapter whose is_available() returns true.
	 * These are the gateways that can actually process payments right now.
	 *
	 * @return Payment_Adapter[]
	 */
	public static function get_active(): array {
		return array_filter(
			self::$adapters,
			fn( Payment_Adapter $adapter ) => $adapter->is_available()
		);
	}

	/**
	 * The gateway a single-gateway checkout should use, or '' when the site
	 * cannot take money at all.
	 *
	 * Free's own checkout lets the buyer PICK from every active gateway. Some
	 * surfaces (Pro's Space Plans, seat packs) present one Pay button instead
	 * of a chooser, and each of them was resolving "which rail?" with its own
	 * inline loop. Three copies drifted: all three skipped the Test adapter, so
	 * a site running only the Test gateway - the state every demo, every QA
	 * walk and every pre-launch site is in - showed "Contact us to buy" while
	 * Free's course and membership checkout happily took the same test payment.
	 *
	 * Policy, in order: a real rail if one is configured, and the Test gateway
	 * only when it is the ONLY thing the owner has switched on. That keeps a
	 * live site from ever simulating a payment it could have really taken,
	 * without dead-ending a site that has deliberately enabled test payments.
	 *
	 * @return string Gateway id, or '' when nothing can take a payment.
	 */
	public static function default_gateway_id(): string {
		$active = self::get_active();
		if ( empty( $active ) ) {
			return '';
		}

		/**
		 * Preference order for surfaces that show one Pay button rather than a
		 * gateway chooser. Ids not listed are tried after these, in
		 * registration order; `test` is deliberately last.
		 *
		 * @param string[] $order Gateway ids, most preferred first.
		 */
		$preferred = (array) apply_filters(
			'learnomy_default_gateway_order',
			array( 'stripe', 'paypal', 'woocommerce', 'test' )
		);

		foreach ( $preferred as $id ) {
			if ( isset( $active[ $id ] ) ) {
				return (string) $id;
			}
		}

		// A third-party adapter registered through
		// `learnomy_register_payment_adapters` and absent from the preference
		// list still counts - never answer "" while a working rail exists.
		foreach ( $active as $id => $adapter ) {
			if ( '' !== (string) $id ) {
				return (string) $id;
			}
		}

		return '';
	}

	/**
	 * Returns all registered adapters regardless of configuration status.
	 * Useful for admin settings pages that need to show every option.
	 *
	 * @return Payment_Adapter[]
	 */
	public static function all(): array {
		return self::$adapters;
	}

	/**
	 * Normalise the `purchase_type` / `item_id` arg shape into the legacy
	 * `course_id` / `plan_id` keys that the existing per-adapter
	 * create_checkout / create_subscription param-builders expect.
	 *
	 * Also aliases `extra` → `extra_metadata` and `item_label` → `label`
	 * for adapters that read those legacy keys internally.
	 *
	 * Centralised here so every adapter that implements create_purchase_session
	 * can call this single method rather than duplicating the same 10 lines.
	 *
	 * @param array $args Raw args from Checkout_Controller or a Pro caller.
	 * @return array Normalised args (original array untouched, new copy returned).
	 */
	/**
	 * @param array                $args    Caller's purchase args.
	 * @param Payment_Adapter|null $adapter The adapter that will take the payment,
	 *                                      so tax ownership can be resolved. Passed
	 *                                      as an optional param rather than added to
	 *                                      the interface, so a third-party adapter
	 *                                      that predates this keeps working and is
	 *                                      simply treated as not handling tax.
	 */
	public static function normalize_purchase_args( array $args, $adapter = null ): array {
		$purchase_type = isset( $args['purchase_type'] ) ? sanitize_key( (string) $args['purchase_type'] ) : '';
		$item_id       = (int) ( $args['item_id'] ?? 0 );

		if ( 'course' === $purchase_type && $item_id ) {
			$args['course_id'] = $item_id;
		} elseif ( 'membership' === $purchase_type && $item_id ) {
			$args['plan_id'] = $item_id;
		}
		// External purchase_type (space, seat, gift, etc.) rides through
		// gateway metadata unchanged so the webhook can branch on it.

		// Carry `extra` onto the legacy `extra_metadata` key picked up by
		// apply_external_purchase_metadata() in the Stripe / PayPal adapters.
		if ( ! empty( $args['extra'] ) && empty( $args['extra_metadata'] ) ) {
			$args['extra_metadata'] = $args['extra'];
		}

		// Provide a human label when the caller used the new `item_label` key.
		if ( empty( $args['label'] ) && ! empty( $args['item_label'] ) ) {
			$args['label'] = (string) $args['item_label'];
		}

		// Tax goes on top of OUR price, here, once — before any gateway sees the
		// figure. A gateway is a rail: it reports whether money moved, and every
		// number it moves is ours. Which rail the buyer picked must not change
		// what the course costs.
		//
		// This lived inside each adapter and two of the four simply did not do
		// it, so with tax switched on the same course cost more through Stripe
		// and PayPal than through WooCommerce. That was found and fixed once
		// already, in PayPal alone, and the two remaining adapters kept the bug
		// for another two releases — because a per-adapter copy of a rule is
		// something a fifth gateway can forget just as easily.
		//
		// SUBSCRIPTIONS are deliberately excluded. Their tax has to RECUR, so it
		// is attached to the subscription itself (a Stripe TaxRate, PayPal's
		// plan_overrides.taxes) and applied by the gateway on every renewal.
		// Folding it into a one-off total here would tax the first payment and
		// silently drop it from every one after.
		//
		// And a STORE is not a rail. WooCommerce, EDD, SureCart and FluentCart
		// each run their own tax engine with its own rates, regions and display
		// rules, and our line item is taxable to them by default — so adding our
		// rate on top would charge the buyer twice. Those adapters say so by
		// declaring handles_tax(), and we hand them the net price to tax
		// themselves. A direct rail like Stripe or PayPal has no opinion about
		// tax, so the rate stays ours.
		$mode = (string) ( $args['mode'] ?? 'one_time' );
		if ( 'subscription' !== $mode && empty( $args['tax_applied'] ) ) {
			$subtotal  = round( (float) ( $args['amount'] ?? 0 ), 2 );
			$delegated = ( null !== $adapter )
				&& method_exists( $adapter, 'handles_tax' )
				&& $adapter->handles_tax();

			$args['subtotal']      = $subtotal;
			$args['tax']           = $delegated ? 0.0 : \Learnomy\tax_amount( $subtotal );
			$args['amount']        = round( $subtotal + (float) $args['tax'], 2 );
			$args['tax_delegated'] = $delegated;
			$args['tax_applied']   = true; // Idempotent: a second pass must not tax the tax.
		}

		return $args;
	}

	/**
	 * Registers the four built-in gateways, then fires an action so third-party
	 * plugins can register theirs before anything tries to process a payment.
	 */
	public static function init(): void {
		self::register( new Stripe_Adapter() );
		self::register( new PayPal_Adapter() );
		self::register( new WooCommerce_Adapter() );
		self::register( new Test_Adapter() );

		// Every completed payment calculates commissions — one listener here
		// so every adapter benefits without duplicating the call.
		add_action( 'learnomy_payment_completed', array( self::class, 'on_payment_completed' ), 10, 3 );

		/**
		 * Fires after the built-in gateways are registered.
		 * Use Adapter_Registry::register( $adapter ) inside this hook.
		 *
		 * @since 1.0.0
		 */
		do_action( 'learnomy_register_payment_adapters' );
	}

	/**
	 * Runs commission calculation for every completed payment. Adapters fire
	 * `learnomy_payment_completed` on success; the Commission_Service resolves
	 * the right rule (course → instructor → global) and writes platform +
	 * instructor rows to `lrn_commissions`.
	 *
	 * Scoped to `transaction_type = course` — membership commissions come from
	 * the monthly engagement-split cron, not per-payment.
	 *
	 * @param int    $transaction_id Completed transaction ID.
	 * @param int    $user_id        Buyer.
	 * @param string $provider       Adapter slug (stripe|paypal|test|wc).
	 */
	public static function on_payment_completed( int $transaction_id, int $user_id, string $provider ): void {
		unset( $user_id, $provider );

		$txn = \Learnomy\Models\Transaction::find( $transaction_id );
		if ( ! $txn || 'completed' !== $txn->status ) {
			return;
		}
		if ( 'course' !== ( $txn->transaction_type ?? '' ) ) {
			return;
		}
		if ( empty( $txn->course_id ) ) {
			return;
		}

		try {
			( new \Learnomy\Services\Commission_Service() )->calculate( $transaction_id );
		} catch ( \Throwable $e ) {
			// Don't let a commission calc failure break checkout.
			error_log( '[Learnomy] Commission calc failed for txn ' . $transaction_id . ': ' . $e->getMessage() );
		}
	}
}
