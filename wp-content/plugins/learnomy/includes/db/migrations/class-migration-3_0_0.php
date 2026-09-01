<?php
/**
 * Migration 3.0.0 — Subscription pause + plan-change columns.
 *
 * Adds four columns to `lrn_member_subscriptions` so the row carries the
 * state needed for the new `Subscription_Service::pause()` and
 * `Subscription_Service::change_plan()` flows (called by the Stripe
 * `customer.subscription.updated` webhook handler in
 * `includes/adapters/class-stripe-adapter.php`):
 *
 *   - `paused_at`         — timestamp the row was paused; NULL when active.
 *   - `provider_price_id` — Stripe price_id / PayPal plan_id the provider
 *                           is currently billing against. Lets us detect a
 *                           customer-portal plan change without re-deriving
 *                           it from the plan table (which only carries the
 *                           configured price, not the live one).
 *   - `amount`            — last-known recurring amount in plan currency.
 *                           Populated by `change_plan()` so receipts and
 *                           the billing UI can read the active price even
 *                           after a Pro-side proration.
 *   - `billing_interval`  — last-known cadence (monthly / yearly / etc).
 *                           Same shape as `lrn_membership_plans.billing_interval`.
 *
 * Forward-only and idempotent: each ALTER is guarded by an INFORMATION_SCHEMA
 * existence check so re-running the migration is a no-op. Existing rows get
 * NULL — `pause()` and `change_plan()` are the only writers, so legacy rows
 * stay untouched until their next provider event lands.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_0_0 {

	public function up(): void {
		$this->add_column_if_missing(
			'lrn_member_subscriptions',
			'paused_at',
			"DATETIME DEFAULT NULL COMMENT 'Timestamp the row entered paused state. NULL when not paused.'",
			'cancelled_at'
		);
		$this->add_column_if_missing(
			'lrn_member_subscriptions',
			'provider_price_id',
			"VARCHAR(255) DEFAULT NULL COMMENT 'Stripe price_id / PayPal plan_id the provider is currently billing.'",
			'provider_subscription_id'
		);
		$this->add_column_if_missing(
			'lrn_member_subscriptions',
			'amount',
			"DECIMAL(10,2) DEFAULT NULL COMMENT 'Last-known recurring amount in plan currency.'",
			'provider_price_id'
		);
		$this->add_column_if_missing(
			'lrn_member_subscriptions',
			'billing_interval',
			"VARCHAR(20) DEFAULT NULL COMMENT 'Last-known billing cadence (monthly|yearly|weekly|daily).'",
			'amount'
		);
	}

	private function add_column_if_missing( string $table_suffix, string $column, string $definition, string $after_column ): void {
		$wpdb  = $this->db();
		$table = $wpdb->prefix . $table_suffix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);

		if ( $exists > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition} AFTER {$after_column}" );
	}

	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}
}
