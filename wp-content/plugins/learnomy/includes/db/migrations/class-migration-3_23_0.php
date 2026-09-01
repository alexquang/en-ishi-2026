<?php
/**
 * Migration 3.23.0 — quiz open_at/close_at converted to UTC.
 *
 * The quiz schedule fields were stored as the admin's SITE-timezone wall-clock
 * (the editor sent the datetime-local value raw), while the open/close gate reads
 * them as UTC. On any non-UTC install a "2pm" schedule therefore opened/closed
 * the quiz off by the site offset. The save path now converts site-local -> UTC
 * (Learnomy\datetime_input_to_utc); this migration brings EXISTING rows onto the
 * same footing so they don't have to be re-saved by hand.
 *
 * Each set value is converted from the site timezone to UTC exactly once via
 * get_gmt_from_date(). On a UTC site that is a no-op, so this only shifts data on
 * non-UTC installs. It is a timezone conversion, so it is NOT idempotent — the
 * Migrator's version gate guarantees it runs a single time (3.22.0 -> 3.23.0).
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_23_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_quizzes';

		foreach ( array( 'open_at', 'close_at' ) as $col ) {
			// $col is a fixed whitelist value, safe to interpolate.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			// open_at/close_at are DATETIME NULL: an unset value is NULL, never ''
			// (comparing a DATETIME against '' throws "Incorrect DATETIME value"
			// under strict SQL mode and would fail the whole scan).
			$rows = $wpdb->get_results(
				"SELECT id, `{$col}` AS val FROM `{$table}`
				 WHERE `{$col}` IS NOT NULL AND `{$col}` <> '0000-00-00 00:00:00'"
			);

			if ( empty( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$utc = get_gmt_from_date( (string) $row->val );
				if ( ! $utc ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array( $col => $utc ),
					array( 'id' => (int) $row->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
	}
}
