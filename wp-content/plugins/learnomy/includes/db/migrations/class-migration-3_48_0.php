<?php
/**
 * Migration 3.48.0 — add `status` to `lrn_instructors`.
 *
 * Backs the owner-facing Suspend / Resume action on the Instructors list
 * (Basecamp 10203580675). The card asked for a "Remove" mirroring the one on
 * Students, but the two lists are not the same shape: the Students list is
 * DERIVED from enrollments, so deleting them genuinely ends the relationship,
 * whereas `lrn_instructors` is a PROFILE row (headline, bio, socials,
 * verification, rating) and courses point at `wp_users.ID` via `author_id`,
 * never at this table. Deleting the row would therefore destroy the profile
 * irreversibly AND leave the person authoring live courses with students
 * enrolled — removed from the list while not removed as an instructor.
 *
 * Suspension is the reversible operation an owner actually needs, and it
 * matches the Suspend / Resume pattern Spaces already ships for members.
 *
 * A separate column from `verification_status` on purpose: verification is a
 * claim about identity, status is whether the owner currently lets them take
 * new business. The two vary independently.
 *
 * Existing rows default to 'active', so no site changes behaviour on upgrade.
 * Forward-only and idempotent — the column check makes a re-run a no-op, and
 * `Schema::create_tables()` does not run on upgrade (only on activation), which
 * is why the ALTER has to live here rather than in the CREATE TABLE alone.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_48_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_instructors';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				'status'
			)
		);

		if ( (int) $exists > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"ALTER TABLE `{$table}`
			 ADD COLUMN `status` varchar(20) NOT NULL DEFAULT 'active'
			 COMMENT 'active|suspended. Suspended hides the instructor from the public directory and refuses NEW enrollments in their courses. Existing students keep full access and earnings stay payable.'
			 AFTER `verification_status`,
			 ADD KEY `status` (`status`)"
		);
	}
}
