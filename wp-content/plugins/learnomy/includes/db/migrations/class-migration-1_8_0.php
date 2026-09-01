<?php
/**
 * Migration 1.8.0 — Add Pro-extension context columns to enrollments.
 *
 * Pro extensions (Learning Paths, Cohorts, Spaces) need to scope a
 * student's enrollment to a path / cohort / space without creating
 * separate junction tables that fragment progress queries.
 *
 * Three nullable bigint columns + indexes are added to lrn_enrollments.
 * They are core schema columns so Free's Enrollment_Service can always
 * read/write them — Pro extensions just populate them when their flow
 * runs. Free queries that ignore these columns continue to work
 * unchanged.
 *
 * Idempotent — each ALTER guards with information_schema.COLUMNS.
 *
 * Plan 00 §1.5 D11 + §7 Phase 0.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_8_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_enrollments';

		$columns = array(
			'path_id'   => 'bigint(20) unsigned DEFAULT NULL AFTER plan_id',
			'cohort_id' => 'bigint(20) unsigned DEFAULT NULL AFTER path_id',
			'space_id'  => 'bigint(20) unsigned DEFAULT NULL AFTER cohort_id',
		);

		foreach ( $columns as $column => $definition ) {
			$exists = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT COLUMN_NAME FROM information_schema.COLUMNS
					 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					DB_NAME,
					$table,
					$column
				)
			);
			if ( empty( $exists ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
			}
		}

		// Add per-column indexes if missing.
		$indexes = array( 'path_id', 'cohort_id', 'space_id' );
		foreach ( $indexes as $index ) {
			$existing = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT INDEX_NAME FROM information_schema.STATISTICS
					 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s',
					DB_NAME,
					$table,
					$index
				)
			);
			if ( empty( $existing ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE {$table} ADD KEY {$index} ({$index})" );
			}
		}
	}
}
