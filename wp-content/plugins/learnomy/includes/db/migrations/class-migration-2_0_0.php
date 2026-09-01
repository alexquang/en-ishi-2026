<?php
/**
 * Migration 2.0.0 — Drip schedule extensions (relative drip + section drip).
 *
 * Layers three pieces of new state on top of the fixed-date drip shipped in
 * 1.9.0 (Plan 06):
 *
 *   1. `lrn_lessons.drip_mode`        ENUM('fixed','relative') NOT NULL DEFAULT 'fixed'
 *   2. `lrn_lessons.drip_offset_days` INT UNSIGNED NULL
 *   3. `lrn_sections.available_date`  TIMESTAMP NULL
 *   4. NEW table `lrn_lesson_unlocks` — per-(user, lesson) computed unlock
 *      rows for relative-drip lessons. Populated by the
 *      `learnomy_student_enrolled` listener at enrollment time, then scanned
 *      hourly by `Drip_Cron::process_window()`.
 *
 * Why these four together
 * - `drip_mode` distinguishes "unlock on this calendar date" (existing
 *   1.9.0 behaviour) from "unlock N days after each student enrols" (new
 *   cohort-paced behaviour). The single-column flag keeps the gate logic
 *   trivial: `drip_mode = 'relative'` means the gate consults
 *   `lrn_lesson_unlocks`; everything else falls back to `available_date`.
 * - `drip_offset_days` is only meaningful when `drip_mode = 'relative'`. We
 *   store the instructor's input ("7 days after enrollment") on the lesson
 *   row so re-enrolment recomputes from the latest offset rather than the
 *   value frozen at the original enrolment.
 * - `lrn_sections.available_date` enables section-level drip — locking an
 *   entire section in one click. The gate consults this column only when
 *   the lesson row's own `available_date` is NULL, so per-lesson overrides
 *   continue to win (Plan 11 §"Drip resolution priority" rule 4).
 * - `lrn_lesson_unlocks` materialises the per-student schedule. Storing the
 *   resolved datetime once at enrolment time means the gate can answer
 *   "is this lesson available right now?" with a single indexed lookup
 *   instead of recomputing from the enrolment row + offset on every page
 *   load. The UNIQUE (user_id, lesson_id) constraint makes the
 *   re-enrolment path naturally idempotent via INSERT IGNORE.
 *
 * Idempotent
 * - Each ALTER guards with INFORMATION_SCHEMA.COLUMNS so re-running the
 *   migrator after a partial failure (or when a fresh install picked up the
 *   columns from `Schema::create_tables()`) is a no-op.
 * - The CREATE TABLE uses `IF NOT EXISTS` for the same reason — fresh
 *   installs already created the table from `Schema::create_tables()`, so
 *   the migrator only fires on upgrade.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_0_0 {

	public function up(): void {
		$this->add_lessons_drip_mode_column();
		$this->add_lessons_drip_offset_days_column();
		$this->add_sections_available_date_column();
		$this->create_lesson_unlocks_table();
	}

	/**
	 * Add `drip_mode` ENUM('fixed','relative') NOT NULL DEFAULT 'fixed' to
	 * `lrn_lessons`, immediately after the existing `available_date` column so
	 * the drip-related state stays grouped on the row layout.
	 */
	private function add_lessons_drip_mode_column(): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'lrn_lessons';
		$column = 'drip_mode';

		if ( $this->column_exists( $table, $column ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			"ALTER TABLE `{$table}` ADD COLUMN `drip_mode` ENUM('fixed','relative') NOT NULL DEFAULT 'fixed' COMMENT 'Drip schedule mode. fixed = use available_date (calendar). relative = use drip_offset_days + per-student lrn_lesson_unlocks rows.' AFTER `available_date`"
		);
	}

	/**
	 * Add `drip_offset_days` INT UNSIGNED NULL to `lrn_lessons`. Only meaningful
	 * when `drip_mode = 'relative'`; NULL otherwise.
	 */
	private function add_lessons_drip_offset_days_column(): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'lrn_lessons';
		$column = 'drip_offset_days';

		if ( $this->column_exists( $table, $column ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			"ALTER TABLE `{$table}` ADD COLUMN `drip_offset_days` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Days after enrollment when the lesson unlocks. Only used when drip_mode = relative.' AFTER `drip_mode`"
		);
	}

	/**
	 * Add `available_date` TIMESTAMP NULL to `lrn_sections` so an instructor
	 * can lock an entire section behind a calendar date in one click. The
	 * Lesson_Service gate consults this column only when the lesson row's own
	 * available_date is NULL — per-lesson overrides win.
	 */
	private function add_sections_available_date_column(): void {
		global $wpdb;

		$table  = $wpdb->prefix . 'lrn_sections';
		$column = 'available_date';

		if ( $this->column_exists( $table, $column ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			"ALTER TABLE `{$table}` ADD COLUMN `available_date` TIMESTAMP NULL DEFAULT NULL COMMENT 'Section-level drip schedule. Lessons in this section are locked until this UTC datetime when their own available_date is NULL.' AFTER `sort_order`"
		);
	}

	/**
	 * Create `lrn_lesson_unlocks` — per-(user, lesson) computed unlock rows
	 * for relative-drip lessons.
	 *
	 * Indexes
	 * - UNIQUE (user_id, lesson_id) — drives INSERT IGNORE idempotency in the
	 *   enrolment listener and lets the gate answer the per-student lookup
	 *   with one indexed read.
	 * - KEY (unlocks_at) — the drip cron scans rows whose `unlocks_at`
	 *   recently crossed NOW(); the secondary index keeps that scan bounded
	 *   even on a 100k-student site.
	 */
	private function create_lesson_unlocks_table(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_lesson_unlocks';

		if ( $this->table_exists( $table ) ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id BIGINT UNSIGNED NOT NULL,
				lesson_id BIGINT UNSIGNED NOT NULL,
				unlocks_at TIMESTAMP NOT NULL,
				created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				UNIQUE KEY ux_user_lesson (user_id, lesson_id),
				KEY idx_unlocks_at (unlocks_at)
			) ENGINE=InnoDB {$charset_collate}"
		);
	}

	/**
	 * Check whether a given column exists on a table.
	 *
	 * Uses INFORMATION_SCHEMA so we don't accidentally re-add a column that
	 * was already shipped on a fresh install via Schema::create_tables().
	 */
	private function column_exists( string $table, string $column ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				$column
			)
		);

		return ! empty( $exists );
	}

	/**
	 * Check whether a given table exists in the current schema.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
				DB_NAME,
				$table
			)
		);

		return ! empty( $exists );
	}
}
