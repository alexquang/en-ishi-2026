<?php
/**
 * Migration 3.13.0 — purge pre-existing orphan rows (Basecamp #10056265330).
 *
 * The go-forward cascade on course delete (Course_Service::delete) is verified,
 * and the demo-seeder teardown now routes through it too — so no NEW orphans
 * appear. This migration cleans up rows that already drifted before those
 * guards existed (bare Course::delete() on the seeder path, hand-edited SQL,
 * imports), which the go-forward fix does not retroactively cover:
 *
 *   1. `lrn_enrollments` whose course is gone — including the `course_id = 0`
 *      case (a course deleted without cascade left the enrollment pointing at
 *      nothing). These rows inflate student counts and surface dead courses in
 *      "My Courses".
 *   2. `lrn_progress` course-level rows for a course that no longer exists.
 *   3. `lrn_commissions` pointing at a transaction that was hard-deleted.
 *
 * Forward-only and idempotent: each DELETE only matches rows whose parent is
 * genuinely missing, so a re-run (or a clean site) is a harmless no-op. Uses
 * LEFT JOIN ... IS NULL rather than NOT IN (subquery) so it stays fast on a
 * large table.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_13_0 {

	public function up(): void {
		global $wpdb;

		$enrollments = $wpdb->prefix . 'lrn_enrollments';
		$courses     = $wpdb->prefix . 'lrn_courses';
		$progress    = $wpdb->prefix . 'lrn_progress';
		$commissions = $wpdb->prefix . 'lrn_commissions';
		$txns        = $wpdb->prefix . 'lrn_transactions';

		// 1. Orphan enrollments — course_id = 0, or the course row is gone.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE e FROM `{$enrollments}` e
			 LEFT JOIN `{$courses}` c ON e.course_id = c.id
			 WHERE e.course_id = 0 OR c.id IS NULL"
		);

		// 2. Orphan course-level progress rows for a deleted course.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE p FROM `{$progress}` p
				 LEFT JOIN `{$courses}` c ON p.item_id = c.id
				 WHERE p.item_type = %s AND c.id IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'course'
			)
		);

		// 3. Orphan commissions whose transaction was hard-deleted.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"DELETE cm FROM `{$commissions}` cm
			 LEFT JOIN `{$txns}` t ON cm.transaction_id = t.id
			 WHERE cm.transaction_id IS NOT NULL AND t.id IS NULL"
		);
	}
}
