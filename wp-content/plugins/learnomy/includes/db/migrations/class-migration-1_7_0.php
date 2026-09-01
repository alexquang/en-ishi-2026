<?php
/**
 * Migration 1.7.0 — Membership sprint: add trial + cancel-at-period-end
 * fields to subscriptions.
 *
 * The plans table already carries `trial_days`. This migration records the
 * per-subscription end of a trial ( `trial_ends_at` ) so the service layer
 * can flip status from 'trialing' to 'active' deterministically, and adds
 * `cancel_at_period_end` so a user-initiated cancel can preserve access
 * until the paid-for period elapses.
 *
 * Both ALTERs guard with information_schema.COLUMNS so this migration is
 * idempotent.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_7_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_member_subscriptions';

		$trial_col = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				'trial_ends_at'
			)
		);
		if ( empty( $trial_col ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN trial_ends_at datetime DEFAULT NULL AFTER current_period_end" );
		}

		$cancel_col = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				'cancel_at_period_end'
			)
		);
		if ( empty( $cancel_col ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0 AFTER cancelled_at" );
		}
	}
}
