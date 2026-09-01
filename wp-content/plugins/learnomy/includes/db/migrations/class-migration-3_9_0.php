<?php
/**
 * Migration 3.9.0 — normalize the WordPress-native `publish` status to the
 * plugin's `published` vocabulary on courses and lessons.
 *
 * The admin "Published" tab queries `WHERE status = 'published'`, so any row
 * carrying the WordPress-native `status = 'publish'` (which an older code path,
 * an import, or hand-edited SQL could have written) was silently excluded from
 * the Published tab — the count read low and the rows only showed under "All".
 * QA verified real `publish` rows on a sandbox (Published tab showed 33 of 39
 * courses; 5 lessons likewise hidden).
 *
 * The model layer now normalizes `publish` -> `published` on write
 * (Course::create / Lesson::create), so no new drift can appear. This migration
 * cleans up rows that already drifted before that guard existed.
 *
 * Forward-only and idempotent: the UPDATE only touches `publish` rows, so a
 * re-run (or a site that never had any) is a harmless no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_9_0 {

	public function up(): void {
		global $wpdb;

		foreach ( array( 'lrn_courses', 'lrn_lessons' ) as $table ) {
			$full = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$full}` SET status = %s WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'published',
					'publish'
				)
			);
		}
	}
}
