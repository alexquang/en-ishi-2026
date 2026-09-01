<?php
/**
 * Migration 3.25.0 — drop the dead denormalized instructor-stat columns.
 *
 * `lrn_instructors.courses_count`, `distinct_students` and `total_earned` were
 * denormalized stat columns that NOTHING maintained (the only writer,
 * Instructor::refresh_stats(), had zero callers; distinct_students was never
 * written at all) and NOTHING read — the admin Instructors list live-computes
 * course counts, and earnings read live via Commission::sum_for_instructor().
 * They sat at DEFAULT 0 forever, and a schema docblock invited a future dev to
 * ORDER BY them (rendering stale zeros). The denormalized-column drift audit
 * (Basecamp 10109357210) resolved this by removing them: instructor stats are
 * computed live, not denormalized. `avg_rating` (Pro-written, read live) stays.
 *
 * Forward-only and idempotent: each DROP is guarded by an information_schema
 * existence check, so re-running is a no-op and an install that never had the
 * columns is unaffected.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_25_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_instructors';

		foreach ( array( 'courses_count', 'distinct_students', 'total_earned' ) as $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					$table,
					$column
				)
			);
			if ( (int) $exists > 0 ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE `{$table}` DROP COLUMN `{$column}`" );
			}
		}
	}
}
