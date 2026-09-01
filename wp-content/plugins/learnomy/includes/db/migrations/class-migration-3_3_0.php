<?php
/**
 * Migration 3.3.0 — link commissions to the withdrawal that claimed them.
 *
 * The manual withdrawal lifecycle (request -> admin approve/reject) had no way
 * to tell WHICH commissions a given withdrawal covered. `request_withdrawal`
 * flipped "just enough" pending commissions to `requested` but stored no
 * back-reference, so:
 *  - rejecting a withdrawal could not return those commissions to `pending`
 *    (the instructor's available balance vanished permanently), and
 *  - approving one could not mark them `paid` on a Free-only site (only Pro's
 *    Stripe-Connect auto-payout ever called Commission::mark_paid).
 *
 * Adds `lrn_commissions.withdrawal_id` so reject/approve can target the exact
 * rows. Forward-only and idempotent — re-running is a no-op once the column
 * exists. Existing rows default to 0; withdrawals created after this migration
 * carry the link.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_3_0 {

	public function up(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'lrn_commissions';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'withdrawal_id' ) );
		if ( $has_column ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN withdrawal_id bigint(20) unsigned NOT NULL DEFAULT 0 AFTER payout_status, ADD KEY withdrawal_id (withdrawal_id)" );
	}
}
