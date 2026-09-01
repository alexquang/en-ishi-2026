<?php
/**
 * Migration 3.19.0 — record WHERE an instructor's payout should go.
 *
 * `lrn_withdrawals` stored the amount, the currency and the method, but never
 * the destination — so when an instructor requested a PayPal payout, the site
 * owner opened the request and had no idea which PayPal account to send it to.
 * The one path that DID know (Pro Stripe Connect) knew because Stripe holds the
 * bank details; every other install was left guessing or chasing the instructor
 * by email.
 *
 * `destination` closes that gap for the 90% of installs that don't run Stripe
 * Connect. It is purpose-bound and low-sensitivity by design: for a PayPal
 * payout it holds the payee email (a public "pay me here" identifier, the same
 * class of data as a mailing address) captured against THIS request; for a
 * manual payout it stays NULL because the instructor arranges payment with the
 * owner off-platform. No bank number, no IBAN, no vault — those belong in the
 * gateway that actually moves the money, never in our table.
 *
 * Forward-only + idempotent: adds the column only when missing, so a re-run — or
 * a fresh install where Schema already created it — is a harmless no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_19_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_withdrawals';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				'destination'
			)
		);

		if ( (int) $exists > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"ALTER TABLE `{$table}`
			 ADD COLUMN `destination` varchar(255) DEFAULT NULL
			 COMMENT 'Where a payout goes (e.g. PayPal payee email). NULL for manual/off-platform. Low-sensitivity, purpose-bound to this request — never bank/IBAN.'
			 AFTER `method`"
		);
	}
}
