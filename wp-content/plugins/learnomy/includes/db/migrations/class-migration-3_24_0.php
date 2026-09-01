<?php
/**
 * Migration 3.24.0 — make the publish-status vocabulary uniform across every
 * Free content table.
 *
 * Learnomy's canonical publish status is `published` (one word, every content
 * entity). The WordPress-native `publish` is a foot-gun: a query using one word
 * silently excludes rows stored under the other. Migration 3.9.0 cleaned courses
 * and lessons, but the question bank was never swept — a live audit found the
 * `lrn_questions` table split `published` / `publish` (a large fraction of the
 * bank invisible to the canonical query the pool draw and admin bank use).
 *
 * This normalizes every Free content table in one pass. Courses/lessons/quizzes
 * are already clean (3.9.0 + the model guard), so those UPDATEs are no-ops; the
 * questions UPDATE is the one that does work. The model layer now heals
 * `publish` -> `published` on EVERY insert AND update
 * (Model::normalizes_publish_status), so no new drift can appear after this.
 *
 * Forward-only and idempotent: each UPDATE only touches `publish` rows.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_24_0 {

	public function up(): void {
		global $wpdb;

		foreach ( array( 'lrn_courses', 'lrn_lessons', 'lrn_quizzes', 'lrn_questions' ) as $table ) {
			$full = $wpdb->prefix . $table;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$full}` SET status = %s WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					'published',
					'publish'
				)
			);
		}
	}
}
