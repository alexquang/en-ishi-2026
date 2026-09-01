<?php
/**
 * Payment settings resolver.
 *
 * Single source of truth for "is gateway X configured?" + "what is the public
 * credential for gateway X in the current mode?" lookups. View-data,
 * template-loader, onboarding-state and any other consumer that needs to
 * reason about gateway availability without invoking the adapters themselves
 * delegates here.
 *
 * Why a dedicated class
 * - Before 2026-05-16 each consumer rolled its own read against
 *   `learnomy_settings[stripe_secret_key]` / `[paypal_client_id]`. Phase 1 of
 *   the payments-hardening sweep (2026-05-13) split those flat keys into
 *   mode-aware variants (`stripe_test_secret`, `stripe_test_publishable`,
 *   `paypal_sandbox_client`, etc.) — but the consumers were never updated.
 *   Result: even with Stripe fully configured in test mode the checkout
 *   said "No payment methods are currently available" because
 *   `stripe_secret_key` was empty by design.
 * - Centralising the lookup here means a future credential schema change is
 *   one file, not nine. The Stripe + PayPal adapters keep their own
 *   `secret_key()` / `webhook_secret()` private helpers; we don't duplicate
 *   that logic — we mirror the same mode-aware pattern for the *public*
 *   credentials and the "is configured" check.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Security\Encrypted_Option;

class Payment_Settings {

	/**
	 * Whether Stripe is fully configured for the current mode.
	 *
	 * "Configured" means: the admin checked the `enable_stripe` toggle AND a
	 * secret key exists for the active mode. The publishable key is checked
	 * separately on the consumer side because the secret is the strict
	 * blocker — without it server-side API calls fail; the publishable is
	 * only needed when we want to render Stripe Elements in the browser.
	 *
	 * Mirrors `Stripe_Adapter::is_available()` so the answer the checkout
	 * page sees is always the same answer the adapter would give.
	 */
	public static function stripe_enabled(): bool {
		$settings = get_option( 'learnomy_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['enable_stripe'] ) ) {
			return false;
		}
		return '' !== self::stripe_secret();
	}

	/**
	 * Stripe publishable key for the active mode.
	 *
	 * Falls back to the legacy flat `stripe_publishable_key` for sites that
	 * configured Stripe before the Phase 1 migration and haven't re-saved
	 * settings since. Same fallback shape used by `Stripe_Adapter::secret_key()`.
	 */
	public static function stripe_publishable(): string {
		$settings = get_option( 'learnomy_settings', array() );
		if ( ! is_array( $settings ) ) {
			return '';
		}
		$mode = isset( $settings['stripe_mode'] ) ? (string) $settings['stripe_mode'] : 'test';
		$key  = (string) ( $settings[ 'stripe_' . $mode . '_publishable' ] ?? '' );
		if ( '' === $key ) {
			$key = (string) ( $settings['stripe_publishable_key'] ?? '' );
		}
		return $key;
	}

	/**
	 * Stripe secret key for the active mode. Encrypted at rest.
	 *
	 * Kept package-public so the resolver can verify "fully configured"
	 * without forcing consumers to import the adapter just for a boolean.
	 */
	public static function stripe_secret(): string {
		$settings = get_option( 'learnomy_settings', array() );
		$mode     = is_array( $settings ) && isset( $settings['stripe_mode'] )
			? (string) $settings['stripe_mode']
			: 'test';

		$secret = Encrypted_Option::get( 'learnomy_stripe_' . $mode . '_secret' );
		if ( '' === $secret ) {
			$secret = Encrypted_Option::get( 'learnomy_stripe_secret_key' );
		}
		return $secret;
	}

	/**
	 * Whether PayPal is fully configured for the current mode.
	 *
	 * "Configured" requires the `enable_paypal` toggle AND both client + secret
	 * for the active mode (sandbox vs live). Mirrors `PayPal_Adapter::is_available()`.
	 */
	public static function paypal_enabled(): bool {
		$settings = get_option( 'learnomy_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['enable_paypal'] ) ) {
			return false;
		}
		return '' !== self::paypal_client_id() && '' !== self::paypal_secret();
	}

	/**
	 * PayPal client ID for the active mode (`paypal_sandbox_client` /
	 * `paypal_live_client`). Falls back to the legacy flat `paypal_client_id`.
	 */
	public static function paypal_client_id(): string {
		$settings = get_option( 'learnomy_settings', array() );
		if ( ! is_array( $settings ) ) {
			return '';
		}
		$mode = isset( $settings['paypal_mode'] ) ? (string) $settings['paypal_mode'] : 'sandbox';
		$key  = 'sandbox' === $mode ? 'paypal_sandbox_client' : 'paypal_live_client';

		$client = (string) ( $settings[ $key ] ?? '' );
		if ( '' === $client ) {
			$client = (string) ( $settings['paypal_client_id'] ?? '' );
		}
		return $client;
	}

	/**
	 * PayPal secret for the active mode. Encrypted at rest.
	 *
	 * Falls back to the legacy `learnomy_paypal_secret` option for sites
	 * configured before Phase 1.
	 */
	public static function paypal_secret(): string {
		$settings = get_option( 'learnomy_settings', array() );
		$mode     = is_array( $settings ) && isset( $settings['paypal_mode'] )
			? (string) $settings['paypal_mode']
			: 'sandbox';

		$key    = 'sandbox' === $mode ? 'learnomy_paypal_sandbox_secret' : 'learnomy_paypal_live_secret';
		$secret = Encrypted_Option::get( $key );
		if ( '' === $secret ) {
			$secret = Encrypted_Option::get( 'learnomy_paypal_secret' );
		}
		return $secret;
	}

	/**
	 * Whether WooCommerce is wired in as a payment route.
	 *
	 * Requires the plugin itself to be loaded AND the admin toggle. We can't
	 * accept payments through Woo if Woo isn't running, no matter what the
	 * setting says — `class_exists( 'WooCommerce' )` is the live check.
	 * Mirrors the gate used by `Checkout_View_Data` and `Template_Loader`.
	 */
	public static function woocommerce_enabled(): bool {
		$settings = get_option( 'learnomy_settings', array() );
		if ( ! is_array( $settings ) || empty( $settings['enable_woocommerce'] ) ) {
			return false;
		}
		return class_exists( 'WooCommerce' );
	}

	/**
	 * True when at least one real-money gateway is fully configured.
	 *
	 * "Real-money" deliberately excludes the test/sandbox-only adapter
	 * (`enable_test_gateway`) because the SS-3 publish-time warning exists
	 * to flag installs where a real customer cannot actually pay. A site
	 * running only the test gateway in production is still a dead-end for
	 * real shoppers and should be warned. Site owners who legitimately want
	 * to keep test mode on during early QA can dismiss the notice.
	 *
	 * Mirrors `Onboarding_State::is_payment_gateway_configured()` — the
	 * two helpers must stay in lockstep. If the gateway list expands
	 * (Razorpay, Paddle, etc.) update both call sites in the same commit.
	 */
	public static function has_any_gateway(): bool {
		return self::stripe_enabled()
			|| self::paypal_enabled()
			|| self::woocommerce_enabled();
	}

	/**
	 * Whether the simulated Test gateway is enabled.
	 *
	 * The Test adapter takes no real money — it lets an owner walk the full
	 * buy -> enrol -> access journey on staging without a live gateway. It is a
	 * legitimate state (WooCommerce ships the same "test/sandbox" idea), so we
	 * never block it; we only make it unmistakable (see the test-mode notice)
	 * and keep its $0 orders out of real revenue.
	 */
	public static function test_gateway_enabled(): bool {
		$settings = get_option( 'learnomy_settings', array() );
		return is_array( $settings ) && ! empty( $settings['enable_test_gateway'] );
	}

	/**
	 * Can a customer actually complete a purchase right now — through ANY
	 * gateway, live OR test?
	 *
	 * This is the shared "is it purchasable" predicate. The publish-time
	 * warning, the buy CTA, and the checkout all reason from THIS answer so
	 * they can never disagree (the SELL-02 bug was three divergent checks: the
	 * warning read `has_any_gateway()` — test excluded — and said "cannot
	 * purchase" while the checkout accepted a Test order).
	 */
	public static function is_purchasable(): bool {
		return self::has_any_gateway() || self::test_gateway_enabled();
	}

	/**
	 * True when the ONLY way to pay is the simulated Test gateway (no live
	 * gateway configured). Drives the single "test payment mode is on" notice.
	 */
	public static function test_mode_only(): bool {
		return self::test_gateway_enabled() && ! self::has_any_gateway();
	}
}
