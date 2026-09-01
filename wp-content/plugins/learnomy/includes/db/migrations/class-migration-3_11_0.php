<?php
/**
 * Migration 3.11.0 — quiz "group questions by category" toggle (A2).
 *
 * Adds `lrn_quizzes.group_by_category` (tinyint(1), default 0), mirroring the
 * existing `shuffle_questions` / `shuffle_answers` column convention. When on,
 * the quiz player groups questions under their category heading and the results
 * screen shows a per-category score breakdown. Index/column-only — no new table,
 * so the table count is unchanged.
 *
 * Forward-only and idempotent: the column add guards on current state, so a
 * re-run (or a fresh install that already has the column) is a harmless no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_11_0 {

	public function up(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'lrn_quizzes';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $table . '` LIKE %s', 'group_by_category' ) );
		if ( ! $has_col ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `group_by_category` tinyint(1) NOT NULL DEFAULT 0 AFTER `shuffle_answers`" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
