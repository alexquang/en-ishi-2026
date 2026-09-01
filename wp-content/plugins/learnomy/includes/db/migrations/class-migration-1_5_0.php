<?php
/**
 * Migration 1.5.0 — Add settings_json column to lrn_courses.
 *
 * Stores flexible course-level settings (toggles, text, JSON structures) that
 * don't need their own indexed column — engagement toggles, completion message,
 * SEO fields, retake behaviour, language, target audience, learning outcomes,
 * and so on. The column is nullable on existing rows and the Course model's
 * {@see Course::default_settings()} fills in the defaults at read time.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_5_0 {

	public function up(): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'lrn_courses';
		$column = 'settings_json';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				$column
			)
		);

		if ( ! $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` LONGTEXT DEFAULT NULL AFTER `instructors_json`" );
		}
	}
}
