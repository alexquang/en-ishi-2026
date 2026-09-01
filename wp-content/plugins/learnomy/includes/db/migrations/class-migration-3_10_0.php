<?php
/**
 * Migration 3.10.0 — course FULLTEXT search on a clean text projection (G16).
 *
 * `lrn_courses` FULLTEXT-indexed the rich `description` column (Editor.js JSON
 * historically, semantic HTML after the content cutover), so course search
 * matched block-JSON noise and, post-cutover, HTML tag names. Lessons already
 * avoid this via `content_plain`. This adds the mirror `description_plain`
 * projection, backfills it, and repoints the FULLTEXT index to it.
 *
 * Forward-only and idempotent: the column add, the backfill, and the index swap
 * each guard on current state, so a re-run (or a fresh install that already has
 * the new schema) is a harmless no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_10_0 {

	public function up(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'lrn_courses';

		// 1. Add the projection column if it isn't there yet.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_col = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $table . '` LIKE %s', 'description_plain' ) );
		if ( ! $has_col ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `description_plain` longtext NULL AFTER `description`" );
		}

		// 2. Backfill from the rich description (render → plain). Bounded loop;
		// courses number in the hundreds, not millions. Skip already-filled rows.
		$rows = $wpdb->get_results( "SELECT id, description FROM `{$table}` WHERE description IS NOT NULL AND description <> '' AND ( description_plain IS NULL OR description_plain = '' )" );
		foreach ( (array) $rows as $row ) {
			$plain = wp_strip_all_tags( \Learnomy\render_editorjs_content( (string) $row->description, '', 'plain' ) );
			$wpdb->update( $table, array( 'description_plain' => $plain ), array( 'id' => (int) $row->id ) );
		}

		// 3. Repoint the FULLTEXT index from (title, description) to
		// (title, description_plain). Drop the old definition first; both halves
		// guard on the live index so the swap is idempotent.
		$index = $wpdb->get_row( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'ft_title_description' AND Column_name = 'description'" );
		if ( $index ) {
			$wpdb->query( "ALTER TABLE `{$table}` DROP INDEX `ft_title_description`" );
		}
		$has_new = $wpdb->get_row( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'ft_title_description' AND Column_name = 'description_plain'" );
		if ( ! $has_new ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD FULLTEXT KEY `ft_title_description` (`title`, `description_plain`)" );
		}
		// phpcs:enable
	}
}
