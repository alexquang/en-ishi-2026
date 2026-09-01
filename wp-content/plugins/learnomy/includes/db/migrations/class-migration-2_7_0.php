<?php
/**
 * Migration 2.7.0 — Quiz slug per-course uniqueness (F-03.X fix).
 *
 * Mirrors the F-02.4 lesson-router fix (Migration 2.6.0) for the
 * quizzes table. Two courses could end up with quizzes sharing the
 * same slug after a CSV import, theme demo seed, or duplicate seeder
 * pass. The router resolved /courses/<course-slug>/quiz/<quiz-slug>/
 * by quiz-slug only, so the wrong course's quiz could load — and a
 * user enrolled in course A could view paid quiz content from
 * course B if the slugs collided.
 *
 * This migration:
 *   1. Walks every (course_id, slug) pair with more than one row
 *      WHERE course_id IS NOT NULL. The lowest-id row keeps the
 *      original slug; every other gets `-{id}` appended.
 *   2. Adds UNIQUE KEY (course_id, slug) on lrn_quizzes.
 *      Standalone quizzes (course_id IS NULL) are NOT constrained
 *      by this UNIQUE — MySQL allows multiple NULL values in
 *      composite UNIQUE indexes — so standalone-slug uniqueness
 *      stays enforced in Quiz::create() at insert time.
 *
 * Idempotent — re-runs on a clean schema do nothing.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_7_0 {

	public function up(): void {
		$this->backfill_quiz_slug_collisions();
		$this->add_unique_course_slug_index();
	}

	/**
	 * Renames colliding quiz slugs to (slug)-(id) for every row past
	 * the first within a (course_id, slug) group, scoped to rows
	 * where course_id IS NOT NULL (standalone quizzes are not the
	 * problem this migration solves).
	 */
	private function backfill_quiz_slug_collisions(): void {
		$table = $this->db()->prefix . 'lrn_quizzes';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dupes = $this->db()->get_results(
			"SELECT course_id, slug, COUNT(*) AS n
			FROM {$table}
			WHERE course_id IS NOT NULL
			GROUP BY course_id, slug
			HAVING n > 1"
		);
		// phpcs:enable

		if ( empty( $dupes ) ) {
			return;
		}

		foreach ( $dupes as $dupe ) {
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
		$table = $this->db()->prefix . 'lrn_quizzes';
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
