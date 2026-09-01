<?php
/**
 * Migration 1.9.0 — Lesson drip schedule.
 *
 * Adds the `available_date` column to `lrn_lessons` so instructors can lock a
 * lesson behind a fixed calendar date. The column is nullable; NULL means the
 * lesson is available immediately. Non-admin / non-author users see the lesson
 * locked until the calendar passes the stored datetime (UTC).
 *
 * NOTE: Plan 06 originally targeted "1.5.0" but versions 1.5.0 through 1.8.0
 * are already shipped for unrelated schema work (settings_json, lesson
 * completion rules, lesson attachments, Pro-extension context columns on
 * enrollments). This migration therefore lands at 1.9.0 — the next available
 * slot — and is the real foothold for the lesson drip feature described in
 * `docs/plans/06-lesson-drip-schedule.md`.
 *
 * Idempotent: the ALTER guards with information_schema.COLUMNS so re-running
 * the migrator is a no-op once the column is in place.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_9_0 {

	public function up(): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'lrn_lessons';
		$column = 'available_date';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				$column
			)
		);

		if ( $exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"ALTER TABLE `{$table}` ADD COLUMN `available_date` timestamp NULL DEFAULT NULL COMMENT 'Drip schedule: lesson is locked for non-admin/non-author until this datetime (UTC). NULL = available immediately.' AFTER `sort_order`"
		);
	}
}
