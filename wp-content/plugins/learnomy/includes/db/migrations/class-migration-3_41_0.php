<?php
/**
 * Migration 3.41.0 — per-course SEO overrides on `lrn_courses`.
 *
 * Until now a course's search snippet was DERIVED and nothing else: the
 * description came from the excerpt, the share image from the thumbnail, and
 * there was no way to keep a single course out of the index. A site owner who
 * bought an LMS to sell courses could get a description in search, but never
 * THEIR description.
 *
 * This matters more here than it would on a post type. Courses live in a custom
 * table, so Yoast and RankMath cannot see them at all — and `should_yield()`
 * deliberately does NOT yield on our own routes, because we are the only one
 * who knows what those pages are. There is therefore no other plugin a site
 * owner can install to fix this: the override IS the control surface.
 *
 * Columns, and why these shapes:
 *
 *   seo_title         Overrides the <title> and og:title.
 *   meta_description  varchar(320), deliberately wider than the 155-char cap
 *                     `truncate_description()` applies to DERIVED text. A cap
 *                     exists to stop machine-generated copy running long; an
 *                     owner who typed 200 characters meant them, and cutting
 *                     their sentence mid-word is worse than letting a search
 *                     engine elide the tail. Derived text stays capped.
 *   og_image_id       Mirrors the `thumbnail_id` / `thumbnail_url` PAIR the
 *   og_image_url      table already uses, so the resolution order stays one
 *                     idiom: a direct URL wins, then a media-library id.
 *                     Courses seeded with an external image have no attachment
 *                     id, which is exactly why the pair exists.
 *   meta_robots       Emits a real <meta name="robots"> when set. Free text is
 *                     not accepted; the read path validates against a known set.
 *
 * All nullable with no default, so every existing row keeps deriving exactly as
 * it does today — NULL means "no override", which is not the same as an empty
 * string an owner cleared on purpose.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_41_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_courses';

		$columns = array(
			'seo_title'        => "varchar(255) DEFAULT NULL COMMENT 'Overrides the derived <title> and og:title.'",
			'meta_description' => "varchar(320) DEFAULT NULL COMMENT 'Overrides the derived meta description. Wider than the 155-char derived cap on purpose.'",
			'og_image_id'      => "bigint(20) unsigned DEFAULT NULL COMMENT 'Media-library id for the share image, used when og_image_url is empty.'",
			'og_image_url'     => "varchar(500) DEFAULT NULL COMMENT 'Direct share-image URL. Wins over og_image_id, mirroring thumbnail_url.'",
			'meta_robots'      => "varchar(64) DEFAULT NULL COMMENT 'Robots directive, e.g. noindex or noindex,nofollow. Validated on read.'",
		);

		$missing = array();
		foreach ( $columns as $column => $definition ) {
			if ( ! $this->column_exists( $table, $column ) ) {
				$missing[] = "ADD COLUMN `{$column}` {$definition}";
			}
		}

		if ( empty( $missing ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` " . implode( ', ', $missing ) );
	}

	/**
	 * @param string $table  Prefixed table name.
	 * @param string $column Column to look for.
	 */
	private function column_exists( string $table, string $column ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column )
		);

		return ! empty( $found );
	}
}
