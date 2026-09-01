<?php
/**
 * Migration 3.12.0 — dedicated question `stem` column (A3).
 *
 * The rich question stem (an optional passage / code / image shown above the
 * answers) previously shared the `content` column with fill_blank's {{blankN}}
 * token body — a dual-use that made a rich editor impossible without breaking
 * fill_blank. This adds a dedicated `lrn_questions.stem` longtext column so:
 *   - `stem`    = the rich passage (any type), Editor.js-authored, HTML-stored.
 *   - `content` = fill_blank's token body only (unchanged).
 *
 * Backfill is forward-only and lossless: every NON-fill_blank question's existing
 * `content` (which could only have been a passage there) MOVES to `stem`, then
 * its `content` is cleared so the column has exactly one meaning going forward.
 * fill_blank rows are untouched (their content is the token body). Index/column +
 * data only — no new table, table count stays 40.
 *
 * Idempotent: the column add guards on current state; the backfill only runs on
 * rows whose content is non-empty and whose stem is still empty, so a re-run is a
 * no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_12_0 {

	public function up(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'lrn_questions';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $table . '` LIKE %s', 'stem' ) );
		if ( ! $has_col ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `stem` longtext NULL AFTER `content`" );
		}

		// Move every non-fill_blank passage from content -> stem (lossless), then
		// clear content for those rows so content means only "fill_blank tokens".
		$wpdb->query(
			"UPDATE `{$table}`
			    SET `stem` = `content`, `content` = ''
			  WHERE `type` <> 'fill_blank'
			    AND `content` IS NOT NULL
			    AND `content` <> ''
			    AND ( `stem` IS NULL OR `stem` = '' )"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
