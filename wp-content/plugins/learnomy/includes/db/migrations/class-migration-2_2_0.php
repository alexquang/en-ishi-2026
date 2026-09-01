<?php
/**
 * Migration 2.2.0 — Payments hardening: webhook event log table (Phase 5).
 *
 * Creates `lrn_webhook_events` — the permanent audit surface for every
 * inbound Stripe and PayPal webhook. The table stores the raw payload so
 * events can be replayed from the admin viewer without waiting for the
 * provider's retry schedule.
 *
 * Idempotent: guarded by a `SHOW TABLES LIKE` check so running the
 * migration twice on an already-upgraded site is a no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

use Learnomy\DB\Schema;

class Migration_2_2_0 {

	public function up(): void {
		$this->create_webhook_events_table();
	}

	// ── Step A — Create the webhook_events table ──────────────────────────

	/**
	 * Create `lrn_webhook_events` via Schema::create_tables().
	 *
	 * Uses the same pattern as Migration_2_1_0: the `table_exists()` guard
	 * skips the full dbDelta round-trip when the table already exists.
	 * On a fresh install, activation calls Schema::create_tables() first, so
	 * the table already exists and this becomes a fast no-op.
	 */
	private function create_webhook_events_table(): void {
		if ( $this->table_exists( $this->db()->prefix . 'lrn_webhook_events' ) ) {
			return;
		}

		Schema::create_tables();
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
	 * Uses INFORMATION_SCHEMA so the guard works on both MySQL and MariaDB.
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
