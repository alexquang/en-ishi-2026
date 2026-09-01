<?php
/**
 * Migration 3.6.0 — Re-assert lesson/quiz per-course slug uniqueness in DDL.
 *
 * Migrations 2.6.0 (F-02.4) and 2.7.0 (F-03.X) added UNIQUE KEY
 * uniq_course_slug (course_id, slug) to lrn_lessons and lrn_quizzes
 * via ALTER TABLE to stop the router from resolving a slug-only URL to
 * the wrong course's content — a paid-content security bypass.
 *
 * Those ALTERs only ran on the upgrade path. The canonical DDL in
 * Schema::create_tables() never carried the indexes, so any site that
 * installed fresh at >= 2.6.0 / 2.7.0 never got them and stayed exposed
 * to the bypass. The DDL is now fixed in this same release; this
 * migration closes the gap for already-installed sites whose tables were
 * built from the old DDL.
 *
 * This migration:
 *   1. De-duplicates any existing colliding (course_id, slug) rows on
 *      both tables (lowest-id keeps the slug; the rest get `-{id}`),
 *      scoping quizzes to course_id IS NOT NULL since standalone quizzes
 *      are intentionally unconstrained by the composite UNIQUE.
 *   2. Adds UNIQUE KEY uniq_course_slug (course_id, slug) to each table
 *      only if it is missing.
 *
 * Idempotent and forward-only — re-runs on a schema that already has the
 * index (the common case post-2.6.0/2.7.0) do nothing.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_6_0 {

	public function up(): void {
		$this->backfill_slug_collisions( 'lrn_lessons', false );
		$this->add_unique_index_if_missing( $this->db()->prefix . 'lrn_lessons', 'uniq_course_slug', '(course_id, slug)' );

		$this->backfill_slug_collisions( 'lrn_quizzes', true );
		$this->add_unique_index_if_missing( $this->db()->prefix . 'lrn_quizzes', 'uniq_course_slug', '(course_id, slug)' );
	}

	/**
	 * Rename colliding slugs to (slug)-(id) for every row past the first
	 * within a (course_id, slug) group. Must run before the UNIQUE index
	 * is added so the ALTER cannot fail on pre-existing duplicates.
	 *
	 * @param string $table_base       Unprefixed table name.
	 * @param bool   $skip_null_course When true, only rows with a non-NULL
	 *                                 course_id are considered (standalone
	 *                                 quizzes are intentionally exempt).
	 */
	private function backfill_slug_collisions( string $table_base, bool $skip_null_course ): void {
		$table  = $this->db()->prefix . $table_base;
		$filter = $skip_null_course ? 'WHERE course_id IS NOT NULL' : '';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dupes = $this->db()->get_results(
			"SELECT course_id, slug, COUNT(*) AS n
			FROM {$table}
			{$filter}
			GROUP BY course_id, slug
			HAVING n > 1"
		);
		// phpcs:enable

		if ( empty( $dupes ) ) {
			return;
		}

		foreach ( $dupes as $dupe ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $this->db()->get_results(
				$this->db()->prepare(
					"SELECT id FROM {$table} WHERE course_id = %d AND slug = %s ORDER BY id ASC",
					(int) $dupe->course_id,
					(string) $dupe->slug
				)
			);

			$first = true;
			foreach ( $rows as $row ) {
				if ( $first ) {
					$first = false;
					continue;
				}
				$new_slug = (string) $dupe->slug . '-' . (int) $row->id;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->db()->update(
					$table,
					array( 'slug' => $new_slug ),
					array( 'id' => (int) $row->id )
				);
			}
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	private function add_unique_index_if_missing( string $table, string $index_name, string $cols ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $this->db()->get_var(
			$this->db()->prepare(
				'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
				DB_NAME,
				$table,
				$index_name
			)
		);
		if ( $exists ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query( "ALTER TABLE {$table} ADD UNIQUE KEY {$index_name} {$cols}" );
	}
}
