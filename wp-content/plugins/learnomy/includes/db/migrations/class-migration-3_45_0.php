<?php
/**
 * Migration 3.45.0 — per-quiz section size for the sectioned template.
 *
 * The sectioned quiz client chunked questions 10-at-a-time with a hardcoded
 * constant and the server never sent a value, so no instructor could change
 * it (Basecamp 10171811590). Adds `questions_per_section` beside
 * `section_navigation`, defaulting to the 10 every existing quiz was already
 * getting — behaviour changes for nobody until an instructor edits it.
 *
 * Forward-only and idempotent: guarded on the column not existing.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_45_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_quizzes';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				'questions_per_section'
			)
		);
		if ( $exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN questions_per_section smallint unsigned NOT NULL DEFAULT 10 AFTER section_navigation" );
	}
}
