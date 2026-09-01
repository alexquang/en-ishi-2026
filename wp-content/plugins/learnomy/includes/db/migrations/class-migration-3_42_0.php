<?php
/**
 * Migration 3.42.0 — index `lrn_enrollments` by (course_id, progress_pct).
 *
 * The roster can now be ordered by progress, and the instructor screen asks for
 * exactly that ordering by default (lowest progress first — the students who
 * need attention). Nothing indexed `progress_pct`: the existing keys are
 * `course_status_enrolled (course_id,status,enrolled_at)` and friends, none of
 * which MySQL can use to satisfy `ORDER BY progress_pct`.
 *
 * Without this index the default roster view filesorts every enrollment on the
 * course before returning 30 rows. On the demo site that is invisible; on a
 * course with 5,000 students it is the difference between an index range scan
 * and sorting 5,000 rows on every page turn. Same index also serves
 * `count_completed_for_course()`, which counts `progress_pct >= 100` within a
 * course — a range on the second column after an equality on the first, which
 * is the shape this composite is built for.
 *
 * Column order matters: course_id first (always an equality) then progress_pct
 * (the sort/range). The reverse would not serve either query.
 *
 * Idempotent — re-running finds the key present and does nothing.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_42_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_enrollments';

		if ( $this->index_exists( $table, 'course_progress' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table}` ADD KEY `course_progress` (`course_id`,`progress_pct`)" );
	}

	/**
	 * @param string $table Prefixed table name.
	 * @param string $index Index name to look for.
	 */
	private function index_exists( string $table, string $index ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index )
		);

		return ! empty( $found );
	}
}
