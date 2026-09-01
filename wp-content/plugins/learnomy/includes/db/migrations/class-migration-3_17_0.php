<?php
/**
 * Migration 3.17.0 — let a learner enroll in more than one learning path.
 *
 * Path enrollments live in `lrn_enrollments` with `course_id = 0` and the real
 * path id in `path_id` (the convention `Path_Service::enroll()` writes). The
 * UNIQUE key was `(user_id, course_id, source)`, so a learner's SECOND free
 * path — another `(user, 0, 'free')` row — collided on that key: the INSERT hit
 * a duplicate-key error, the enroll call returned 0, and the raw
 * "Duplicate entry" block leaked into the REST response. A learner could join
 * exactly one free path, ever (Basecamp #10063893... release smoke E-row).
 *
 * Fix: fold `path_id` into the UNIQUE key so `(user, 0, free, path=10)` and
 * `(user, 0, free, path=11)` are distinct. `path_id` must be NOT NULL for that
 * to dedupe correctly — MySQL treats each NULL as distinct, which would let two
 * identical course enrollments through — so we first backfill the NULLs that
 * course enrollments carry to 0, then tighten the column.
 *
 * Forward-only and idempotent: re-running finds no NULL `path_id` rows, the
 * column already NOT NULL, and the key already path-aware (all guarded).
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_17_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_enrollments';

		// 1. Backfill: course enrollments never set path_id (NULL). Make them 0
		// so the tightened column + composite key dedupe them correctly.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "UPDATE `{$table}` SET path_id = 0 WHERE path_id IS NULL" );

		// 2. Tighten path_id to NOT NULL DEFAULT 0. Re-applying the same column
		// definition is a harmless no-op ALTER.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "ALTER TABLE `{$table}` MODIFY path_id bigint(20) unsigned NOT NULL DEFAULT 0" );

		// 3. Make the UNIQUE key path-aware. Inspect the current key columns so a
		// re-run (or a fresh install that already got the final Schema shape)
		// doesn't DROP a key that isn't there or already includes path_id.
		$key_cols = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM information_schema.STATISTICS
				 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'user_course_source'
				 ORDER BY SEQ_IN_INDEX",
				DB_NAME,
				$table
			)
		);

		if ( in_array( 'path_id', (array) $key_cols, true ) ) {
			return; // Already path-aware — nothing to do.
		}

		if ( ! empty( $key_cols ) ) {
			// Old key present without path_id — swap it in one ALTER so the table
			// is never left without its uniqueness guard.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				"ALTER TABLE `{$table}`
				   DROP INDEX user_course_source,
				   ADD UNIQUE KEY user_course_source (user_id, course_id, source, path_id)"
			);
			return;
		}

		// No such key at all (unexpected) — just add the correct one.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			"ALTER TABLE `{$table}`
			   ADD UNIQUE KEY user_course_source (user_id, course_id, source, path_id)"
		);
	}
}
