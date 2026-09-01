<?php
/**
 * Migration 2.6.0 — Lesson slug per-course uniqueness (F-02.4 fix).
 *
 * Pre-2.6.0 `lrn_lessons` had no UNIQUE constraint on (course_id, slug).
 * Two courses could end up with lessons sharing the same slug after a
 * CSV import, theme demo seed, or independently-authored common
 * foundation titles ("Welcome", "Introduction", "Getting Started").
 *
 * The router resolved /courses/<course-slug>/lesson/<lesson-slug>/ by
 * lesson-slug ONLY, so the wrong course's lesson rendered when slugs
 * collided. View-data validated enrollment against the URL's course
 * but loaded content from a different one — a security bypass on
 * paid content when the colliding lesson was in a different course.
 *
 * This migration:
 *   1. Detects rows with duplicate (course_id, slug) — should be zero
 *      under correct seed paths but real-world data may have them.
 *   2. For each duplicate, appends `-{id}` to the slug to make it
 *      unique. The lowest-id row keeps the original slug.
 *   3. Adds UNIQUE KEY (course_id, slug) so future inserts can never
 *      create a collision.
 *
 * Idempotent — re-runs on a clean schema do nothing.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_6_0 {

	public function up(): void {
		$this->backfill_lesson_slug_collisions();
		$this->add_unique_course_slug_index();
	}

	/**
	 * Walk every (course_id, slug) pair with more than one row. Keep the
	 * lowest-id row's slug as canonical; append `-{id}` to every other.
	 *
	 * The fix is in two steps to avoid violating the UNIQUE constraint
	 * we're about to add: first rewrite slugs, then add the index.
	 */
	private function backfill_lesson_slug_collisions(): void {
		$table = $this->db()->prefix . 'lrn_lessons';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dupes = $this->db()->get_results(
			"SELECT course_id, slug, COUNT(*) AS n
			FROM {$table}
			GROUP BY course_id, slug
			HAVING n > 1"
		);
		// phpcs:enable

		if ( empty( $dupes ) ) {
			return;
		}

		foreach ( $dupes as $dupe ) {
			// Pull every offending row in id-ascending order so the
			// lowest-id keeps the original slug.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

	private function add_unique_course_slug_index(): void {
		$table = $this->db()->prefix . 'lrn_lessons';
		$this->add_unique_index_if_missing( $table, 'uniq_course_slug', '(course_id, slug)' );
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
