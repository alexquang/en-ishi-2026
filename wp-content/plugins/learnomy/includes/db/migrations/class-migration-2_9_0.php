<?php
/**
 * Migration 2.9.0 — Completion enforcement override columns.
 *
 * Adds `completion_enforcement VARCHAR(20) NULL DEFAULT NULL` to both
 * `lrn_courses` and `lrn_lessons`. The column carries the per-row override
 * of the site-wide completion-rule strictness setting:
 *
 *   - NULL      → inherit from the parent layer (lesson → course → site).
 *   - 'strict'  → require every active rule met before Complete fires.
 *   - 'advisory'→ render the requirements strip but enable Complete anyway.
 *   - 'off'     → no requirements, Complete button always enabled.
 *
 * Resolution order (see Progress_Service::resolve_enforcement()):
 *   site setting (default 'strict')
 *     ← course.completion_enforcement (non-NULL wins)
 *       ← lesson.completion_enforcement (non-NULL wins)
 *         ← role bypass (admin OR course instructor → 'off')
 *           ← learnomy_completion_enforcement filter (always wins)
 *
 * Forward-only and idempotent: each ALTER is guarded by an existence check
 * so re-running the migration is a no-op. Existing rows get NULL — current
 * 'strict' behavior is preserved on upgrade.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_9_0 {

	public function up(): void {
		$this->add_courses_enforcement_column();
		$this->add_lessons_enforcement_column();
	}

	private function add_courses_enforcement_column(): void {
		$this->add_column_if_missing(
			'lrn_courses',
			'completion_enforcement',
			"VARCHAR(20) DEFAULT NULL COMMENT 'Per-course override of completion-rule strictness. NULL=inherit from site setting.'",
			'completion_value'
		);
	}

	private function add_lessons_enforcement_column(): void {
		$this->add_column_if_missing(
			'lrn_lessons',
			'completion_enforcement',
			"VARCHAR(20) DEFAULT NULL COMMENT 'Per-lesson override of completion-rule strictness. NULL=inherit from course/site setting.'",
			'lock_next_until_complete'
		);
	}

	private function add_column_if_missing( string $table_suffix, string $column, string $definition, string $after_column ): void {
		$wpdb  = $this->db();
		$table = $wpdb->prefix . $table_suffix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		);

		if ( $exists > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition} AFTER {$after_column}" );
	}

	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}
}
