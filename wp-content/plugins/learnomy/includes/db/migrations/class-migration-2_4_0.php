<?php
/**
 * Migration 2.4.0 — Cancellation feedback table (PR-D).
 *
 * Adds `lrn_cancellation_feedback` so the new save-the-customer cancel
 * flow can record (a) the reason the buyer is leaving, (b) any
 * free-text feedback, and (c) whether they accepted a retention offer
 * before confirming. Aggregated server-side for the admin churn-reason
 * dashboard.
 *
 * The table is created by `Schema::create_tables()` via `dbDelta()`. This
 * migration is intentionally near-empty — its purpose is to bump the
 * stored db_version so the create_tables() pass runs on plugins_loaded
 * for existing installs. Re-running is a no-op (dbDelta handles
 * idempotency).
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

use Learnomy\DB\Schema;

class Migration_2_4_0 {

	public function up(): void {
		Schema::create_tables();
	}
}
