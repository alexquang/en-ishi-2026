<?php
/**
 * Migration 1.3.0 — Add thumbnail_url and short_description columns to lrn_courses.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_3_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_courses';

		// Add thumbnail_url column if it does not exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$col = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				'thumbnail_url'
			)
		);

		if ( ! $col ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN thumbnail_url varchar(500) DEFAULT NULL AFTER thumbnail_id" );
		}

		// Add short_description column if it does not exist.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$col2 = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				'short_description'
			)
		);

		if ( ! $col2 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN short_description text AFTER thumbnail_url" );
		}
	}
}
