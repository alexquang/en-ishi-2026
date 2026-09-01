<?php
/**
 * Migration 1.4.0 — Add membership plan columns and rename last_triggered on webhooks.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_4_0 {

	public function up(): void {
		global $wpdb;

		$plans_table    = $wpdb->prefix . 'lrn_membership_plans';
		$webhooks_table = $wpdb->prefix . 'lrn_webhooks';

		$columns_to_add = array(
			array(
				'table'  => $plans_table,
				'column' => 'features_json',
				'sql'    => 'ADD COLUMN features_json longtext DEFAULT NULL AFTER course_limit',
			),
			array(
				'table'  => $plans_table,
				'column' => 'is_featured',
				'sql'    => 'ADD COLUMN is_featured tinyint(1) NOT NULL DEFAULT 0 AFTER features_json',
			),
			array(
				'table'  => $plans_table,
				'column' => 'price_monthly',
				'sql'    => 'ADD COLUMN price_monthly decimal(10,2) DEFAULT NULL AFTER is_featured',
			),
			array(
				'table'  => $plans_table,
				'column' => 'price_yearly',
				'sql'    => 'ADD COLUMN price_yearly decimal(10,2) DEFAULT NULL AFTER price_monthly',
			),
			array(
				'table'  => $plans_table,
				'column' => 'thumbnail_url',
				'sql'    => 'ADD COLUMN thumbnail_url varchar(500) DEFAULT NULL AFTER price_yearly',
			),
		);

		foreach ( $columns_to_add as $col_def ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					DB_NAME,
					$col_def['table'],
					$col_def['column']
				)
			);

			if ( ! $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$col_def['table']}` {$col_def['sql']}" );
			}
		}

		// Rename last_triggered to last_triggered_at on webhooks table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$old_col = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$webhooks_table,
				'last_triggered'
			)
		);

		if ( $old_col ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$webhooks_table}` CHANGE COLUMN `last_triggered` `last_triggered_at` datetime DEFAULT NULL" );
		}
	}
}
