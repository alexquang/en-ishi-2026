<?php
/**
 * Migration 2.1.0 — Payments hardening: schema foundation (Phase 1).
 *
 * This migration is Phase 1 of the payments-hardening plan documented at
 * `plan/payments-hardening-v1.0.x.md`. It does three things in order, each
 * idempotent:
 *
 *   A. Create `lrn_membership_plan_providers` table — the central plan-to-
 *      provider-ID mapping table that replaces scattered wp_options.
 *
 *   B. Migrate settings keys from the old single-credential layout to the new
 *      dual (test/live) layout based on key-prefix detection. Old keys are
 *      intentionally preserved — Phase 2 adapters will start reading from the
 *      new keys; the old ones stay until a follow-up cleanup migration.
 *
 *   C. Seed the new table from any existing `learnomy_stripe_price_<plan_id>`
 *      and `learnomy_paypal_plan_<plan_id>` wp_options. Phase 2 adapters will
 *      switch to reading from the model; those options stay until cleanup.
 *
 * Why non-destructive
 * - Phase 2 is where adapters switch their reads to the new schema. Running
 *   Phase 1 + reverting Phase 2 must leave the site exactly as before.
 * - No old keys deleted, no old options deleted — every write in this
 *   migration is additive or idempotent.
 *
 * Idempotency
 * - Step A: uses `table_exists()` guard; `Schema::create_tables()` idempotency
 *   covers fresh installs that already have the table.
 * - Step B: checks whether new keys already exist before writing.
 * - Step C: calls `Plan_Provider::upsert()` which uses ON DUPLICATE KEY UPDATE.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

use Learnomy\DB\Schema;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\Plan_Provider;
use Learnomy\Security\Encrypted_Option;

class Migration_2_1_0 {

	public function up(): void {
		// Step C uses Plan_Provider::upsert() which calls Model::where() which
		// resolves Learnomy\table() from functions.php. The main bootstrap
		// requires that file before check_db_version() runs, but require it
		// again here as belt-and-suspenders in case someone manually invokes
		// the migration class outside the normal plugins_loaded chain.
		if ( ! function_exists( '\\Learnomy\\table' ) ) {
			require_once LEARNOMY_DIR . 'includes/functions.php';
		}

		$this->create_plan_providers_table();
		$this->migrate_settings_keys();
		$this->migrate_plan_provider_options();
	}

	// ── Step A — Create the new table ─────────────────────────────────────

	/**
	 * Create `lrn_membership_plan_providers` via Schema::create_tables().
	 *
	 * Schema::create_tables() runs every definition through dbDelta() which is
	 * inherently idempotent — it adds missing columns/indexes without touching
	 * existing data. Using the same Schema call that activation uses means the
	 * table definition stays in exactly one place.
	 *
	 * The `table_exists()` guard is an optimisation: skip the full dbDelta
	 * round-trip for the other 36 tables when only this one is new. On a
	 * fresh install the table already exists from activation, so this entire
	 * step is a fast no-op.
	 */
	private function create_plan_providers_table(): void {
		if ( $this->table_exists( $this->db()->prefix . 'lrn_membership_plan_providers' ) ) {
			return;
		}

		Schema::create_tables();
	}

	// ── Step B — Migrate settings keys ────────────────────────────────────

	/**
	 * Compute the new dual-credential settings structure from the existing
	 * single-credential flat options and merge it back into `learnomy_settings`.
	 *
	 * Detection strategy
	 * - Stripe mode: if the stored publishable key starts with `pk_test_` the
	 *   site was using test credentials; otherwise live.
	 * - PayPal mode: the existing `paypal_sandbox` boolean flag maps directly
	 *   to 'sandbox' | 'live'.
	 *
	 * Idempotency: new keys are only written when they are not already present.
	 * Re-running the migration on an already-migrated site is a no-op — the
	 * first truthy new key for each provider short-circuits the write.
	 */
	private function migrate_settings_keys(): void {
		$old = get_option( 'learnomy_settings', [] );

		if ( ! is_array( $old ) ) {
			$old = [];
		}

		$new = [];

		// ── Stripe ──────────────────────────────────────────────────────────

		$stripe_pub = $old['stripe_publishable_key'] ?? '';

		// Detect mode from key prefix. Keys starting with 'pk_test_' are
		// unambiguously test keys; anything else (pk_live_ or empty) defaults
		// to live so we do not silently promote an existing live credential
		// into the test slot.
		$stripe_mode = ( 0 === strpos( $stripe_pub, 'pk_test_' ) ) ? 'test' : 'live';

		$stripe_secret  = Encrypted_Option::get( 'learnomy_stripe_secret_key' );
		$stripe_webhook = Encrypted_Option::get( 'learnomy_stripe_webhook_secret' );

		// Only write if the destination key doesn't exist yet (idempotency).
		if ( 'test' === $stripe_mode ) {
			if ( ! isset( $old['stripe_test_publishable'] ) && '' !== $stripe_pub ) {
				$new['stripe_test_publishable'] = $stripe_pub;
			}
			if ( '' !== $stripe_secret ) {
				Encrypted_Option::set( 'learnomy_stripe_test_secret', $stripe_secret );
			}
			if ( '' !== $stripe_webhook ) {
				Encrypted_Option::set( 'learnomy_stripe_test_webhook_secret', $stripe_webhook );
			}
		} else {
			if ( ! isset( $old['stripe_live_publishable'] ) && '' !== $stripe_pub ) {
				$new['stripe_live_publishable'] = $stripe_pub;
			}
			if ( '' !== $stripe_secret ) {
				Encrypted_Option::set( 'learnomy_stripe_live_secret', $stripe_secret );
			}
			if ( '' !== $stripe_webhook ) {
				Encrypted_Option::set( 'learnomy_stripe_live_webhook_secret', $stripe_webhook );
			}
		}

		// Write the mode switch only if it is not already set so that an admin
		// who manually set stripe_mode in a prior session keeps their choice.
		if ( ! isset( $old['stripe_mode'] ) ) {
			$new['stripe_mode'] = $stripe_mode;
		}

		// ── PayPal ──────────────────────────────────────────────────────────

		$pp_sandbox = ! empty( $old['paypal_sandbox'] );
		$pp_mode    = $pp_sandbox ? 'sandbox' : 'live';

		$pp_client     = $old['paypal_client_id'] ?? '';
		$pp_secret     = Encrypted_Option::get( 'learnomy_paypal_secret' );
		$pp_webhook_id = $old['paypal_webhook_id'] ?? '';

		if ( 'sandbox' === $pp_mode ) {
			if ( ! isset( $old['paypal_sandbox_client'] ) && '' !== $pp_client ) {
				$new['paypal_sandbox_client'] = $pp_client;
			}
			if ( '' !== $pp_secret ) {
				Encrypted_Option::set( 'learnomy_paypal_sandbox_secret', $pp_secret );
			}
			if ( ! isset( $old['paypal_sandbox_webhook_id'] ) ) {
				$new['paypal_sandbox_webhook_id'] = $pp_webhook_id;
			}
		} else {
			if ( ! isset( $old['paypal_live_client'] ) && '' !== $pp_client ) {
				$new['paypal_live_client'] = $pp_client;
			}
			if ( '' !== $pp_secret ) {
				Encrypted_Option::set( 'learnomy_paypal_live_secret', $pp_secret );
			}
			if ( ! isset( $old['paypal_live_webhook_id'] ) ) {
				$new['paypal_live_webhook_id'] = $pp_webhook_id;
			}
		}

		if ( ! isset( $old['paypal_mode'] ) ) {
			$new['paypal_mode'] = $pp_mode;
		}

		// ── Merge and persist ─────────────────────────────────────────────

		if ( ! empty( $new ) ) {
			update_option( 'learnomy_settings', array_merge( $old, $new ) );
		}
	}

	// ── Step C — Migrate plan-to-provider options into the new table ───────

	/**
	 * Scan for any existing `learnomy_stripe_price_<plan_id>` and
	 * `learnomy_paypal_plan_<plan_id>` wp_options and upsert them into
	 * `lrn_membership_plan_providers`.
	 *
	 * The mode used for each provider is derived from the (now-migrated)
	 * `learnomy_settings` option so the seeded rows are consistent with the
	 * credential mode that was active at migration time.
	 *
	 * Old options are NOT deleted here — Phase 2 adapters will switch their
	 * reads to the model first, then a follow-up cleanup migration removes the
	 * redundant options once the adapters are deployed and green.
	 */
	private function migrate_plan_provider_options(): void {
		$settings    = get_option( 'learnomy_settings', [] );
		$stripe_mode = $settings['stripe_mode'] ?? 'live';
		$paypal_mode = $settings['paypal_mode'] ?? 'live';

		// Use where([]) to get all plans regardless of status so price IDs for
		// draft/inactive plans are also preserved.
		$plans = MembershipPlan::where( [] );

		foreach ( $plans as $plan ) {
			$plan_id = (int) $plan->id;

			$stripe_price = get_option( 'learnomy_stripe_price_' . $plan_id, '' );
			if ( '' !== $stripe_price ) {
				Plan_Provider::upsert( $plan_id, 'stripe', $stripe_mode, $stripe_price );
			}

			$pp_plan = get_option( 'learnomy_paypal_plan_' . $plan_id, '' );
			if ( '' !== $pp_plan ) {
				Plan_Provider::upsert( $plan_id, 'paypal', $paypal_mode, $pp_plan );
			}
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/** @return \wpdb */
	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Check whether a table physically exists in the current DB.
	 *
	 * Uses INFORMATION_SCHEMA (same approach as Migration_2_0_0) so the guard
	 * works on both MySQL and MariaDB without relying on SHOW TABLES behaviour
	 * differences.
	 */
	private function table_exists( string $table ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $this->db()->get_var(
			$this->db()->prepare(
				'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);

		return ! empty( $exists );
	}
}
