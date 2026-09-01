<?php
/**
 * Migration 3.14.0 — add `section_navigation` to `lrn_quizzes` (Basecamp #9993392864).
 *
 * The sectioned quiz template was strictly linear (finish section 1 before
 * section 2, no going back). This column adds a section-level navigation mode —
 * `linear` (default, current behaviour) or `free` (the candidate sees all
 * sections up front and chooses the order). It is the SECTION-level analogue of
 * the existing `navigation` column (which governs question order within a
 * section); the two are distinct and composable.
 *
 * Column-only, idempotent (guarded by an information_schema check), no new
 * table — so the table count is unchanged.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_14_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_quizzes';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				'section_navigation'
			)
		);

		if ( ! $exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				"ALTER TABLE `{$table}`
				 ADD COLUMN `section_navigation` varchar(20) NOT NULL DEFAULT 'linear' AFTER `navigation`"
			);
		}
	}
}
