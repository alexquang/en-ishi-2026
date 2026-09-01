<?php
/**
 * Migration 3.31.0 — re-home quizzes stranded by a deleted section, and index
 * the two columns the content cascades read.
 *
 * Deleting a course section cascaded to its lessons but not to its quizzes
 * (Basecamp 10132809117): `Course_Service::delete_section_with_lessons()`
 * looped `Lesson::get_for_section()` and nothing else, so a quiz carrying that
 * `section_id` survived the delete, still published, pointing at a row that no
 * longer existed. That is fixed forward — a section's contents are now
 * enumerated once, in `Section::delete()` — but every site that has ever
 * deleted a section since the Course Builder shipped still holds the rows the
 * old cascade left behind.
 *
 * Those rows are not merely untidy, they are unreachable. The curriculum
 * outline buckets quizzes by section and renders them under the section they
 * name; with the section gone the bucket is never drawn, so the quiz appears in
 * no curriculum, no builder column, and no section list — while continuing to
 * hold its slots, its attempts, and its students' responses.
 *
 * WHY THIS RE-HOMES RATHER THAN DELETES
 *
 * Cascading forward is right: an author deleting a section can see the section's
 * contents and is choosing to remove them. None of that is true here. These
 * quizzes were orphaned by a bug, invisibly, possibly years ago, at a moment
 * when nobody was shown them and nobody consented to losing them — and they may
 * carry graded attempts, which is a student's record, not the plugin's to
 * discard on an upgrade. So the repair restores REACHABILITY, not the delete
 * that never ran: clearing the dangling `section_id` promotes the quiz to a
 * course-level quiz, which is exactly what a quiz with a course and no section
 * already means everywhere else in the schema. It then appears in the builder
 * and the outline, and the owner can look at it and decide. A destructive
 * repair would make the same call for them, silently, with no undo.
 *
 * Only quizzes whose COURSE still resolves are re-homed. A quiz whose course is
 * also gone has nowhere to be promoted to; it is left exactly as found rather
 * than deleted, for the reason above, and is reported by
 * `wp learnomy selfcheck` instead.
 *
 * The two indexes are the same fix seen from the write side. `lrn_quizzes` had
 * no key on `section_id` or `lesson_id`, yet both delete cascades now filter on
 * them: `Section::delete()` runs `WHERE section_id = ?` once per section and
 * `Lesson::delete()` runs `WHERE lesson_id = ?` once per lesson, so on a course
 * with 40 sections and a site with thousands of quizzes a single course
 * teardown meant a full table scan per section. `Quiz::get_for_section()` also
 * feeds the curriculum outline on the front end, which is a read path, not a
 * teardown.
 *
 * Forward-only and idempotent: the UPDATE matches only rows whose section is
 * missing, so a second run finds none; the index guard checks
 * INFORMATION_SCHEMA and does nothing on a fresh install whose DDL already
 * carries both keys.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_31_0 {

	/** Rows re-homed per statement, so a long history never locks the table. */
	private const BATCH_SIZE = 500;

	/** Safety stop, so a pathological data set can never spin here forever. */
	private const MAX_BATCHES = 2000;

	public function up(): void {
		// Index first: the repair's own lookup reads section_id, and every
		// cascade from here on depends on these two keys.
		$quizzes = $this->db()->prefix . 'lrn_quizzes';
		$this->add_index_if_missing( $quizzes, 'section_id', '(section_id)' );
		$this->add_index_if_missing( $quizzes, 'lesson_id', '(lesson_id)' );

		$this->rehome_stranded_quizzes();
	}

	/**
	 * Clear `section_id` on quizzes whose section no longer exists, so they
	 * become the course-level quizzes they now effectively are.
	 *
	 * Collected by id then updated by id list rather than run as one
	 * `UPDATE ... LEFT JOIN`: migrations run on a front-end request during
	 * upgrade, and the join form would hold locks across the whole quiz table
	 * for its duration.
	 */
	private function rehome_stranded_quizzes(): void {
		$quizzes  = $this->db()->prefix . 'lrn_quizzes';
		$sections = $this->db()->prefix . 'lrn_sections';
		$courses  = $this->db()->prefix . 'lrn_courses';

		$batches = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names only; no user input reaches this string.
			$ids = $this->db()->get_col(
				"SELECT q.id
				   FROM `{$quizzes}` q
				  WHERE q.section_id IS NOT NULL
				    AND q.section_id > 0
				    AND NOT EXISTS ( SELECT 1 FROM `{$sections}` s WHERE s.id = q.section_id )
				    AND EXISTS ( SELECT 1 FROM `{$courses}` c WHERE c.id = q.course_id )
				  LIMIT " . self::BATCH_SIZE
			);

			$found = count( $ids );
			if ( 0 === $found ) {
				return;
			}

			$placeholders = implode( ',', array_fill( 0, $found, '%d' ) );
			$this->db()->query(
				$this->db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a %d list built from the id count.
					"UPDATE `{$quizzes}` SET section_id = NULL WHERE id IN ({$placeholders})",
					...array_map( 'intval', $ids )
				)
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			++$batches;
		} while ( $found === self::BATCH_SIZE && $batches < self::MAX_BATCHES );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Add a non-unique index only when no index of that name already exists on
	 * the table. Guard runs against INFORMATION_SCHEMA so the migration is safe
	 * to re-run and safe on fresh installs whose DDL already carries the key.
	 *
	 * @param string $table      Fully-prefixed table name.
	 * @param string $index_name Index name to create / check.
	 * @param string $cols       Parenthesised column list, e.g. '(section_id)'.
	 */
	private function add_index_if_missing( string $table, string $index_name, string $cols ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query( "ALTER TABLE {$table} ADD INDEX {$index_name} {$cols}" );
	}
}
