<?php
/**
 * Migration 1.6.0 — Content completeness sprint: lesson completion rules,
 * quiz retry controls, question hints, and per-lesson attachments table.
 *
 * NOTE: the content-completeness sprint plan originally called this "1.3.0",
 * but migrations 1.3.0 through 1.5.0 already shipped for other concerns
 * (thumbnail_url / short_description, membership plans, settings_json).
 * This migration therefore sits at 1.6.0 — the next available slot — and
 * is the real landing place for the lesson / quiz / question schema work
 * described in `audit/architecture/LESSON-COMPLETION-RULES-SPEC.md` and
 * `audit/build-logs/SPRINT-CONTENT-COMPLETENESS.md`.
 *
 * All ALTERs guard with information_schema.COLUMNS and the new table uses
 * CREATE TABLE IF NOT EXISTS, so re-running `wp learnomy db migrate` is a
 * no-op once the schema has caught up.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_6_0 {

	public function up(): void {
		$this->add_lesson_columns();
		$this->add_quiz_columns();
		$this->add_question_columns();
		$this->create_lesson_attachments_table();
	}

	/**
	 * lrn_lessons — completion rules + per-lesson comment override.
	 */
	private function add_lesson_columns(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_lessons';

		$columns = array(
			array(
				'column' => 'completion_mode',
				'sql'    => "ADD COLUMN `completion_mode` varchar(20) NOT NULL DEFAULT 'manual' AFTER `duration_seconds`",
			),
			array(
				'column' => 'min_time_seconds',
				'sql'    => 'ADD COLUMN `min_time_seconds` int unsigned NOT NULL DEFAULT 0 AFTER `completion_mode`',
			),
			array(
				'column' => 'min_video_watch_pct',
				'sql'    => 'ADD COLUMN `min_video_watch_pct` tinyint unsigned NOT NULL DEFAULT 0 AFTER `min_time_seconds`',
			),
			array(
				'column' => 'require_scroll_end',
				'sql'    => 'ADD COLUMN `require_scroll_end` tinyint(1) NOT NULL DEFAULT 0 AFTER `min_video_watch_pct`',
			),
			array(
				'column' => 'require_quiz_pass',
				'sql'    => 'ADD COLUMN `require_quiz_pass` tinyint(1) NOT NULL DEFAULT 0 AFTER `require_scroll_end`',
			),
			array(
				'column' => 'lock_next_until_complete',
				'sql'    => 'ADD COLUMN `lock_next_until_complete` tinyint(1) NOT NULL DEFAULT 0 AFTER `require_quiz_pass`',
			),
			array(
				// NULL = inherit from course.
				'column' => 'comments_enabled',
				'sql'    => 'ADD COLUMN `comments_enabled` tinyint(1) DEFAULT NULL AFTER `lock_next_until_complete`',
			),
		);

		$this->add_columns_if_missing( $table, $columns );
	}

	/**
	 * lrn_quizzes — retry cooldown + lockout after N failures.
	 */
	private function add_quiz_columns(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_quizzes';

		$columns = array(
			array(
				'column' => 'attempt_cooldown_seconds',
				'sql'    => 'ADD COLUMN `attempt_cooldown_seconds` int unsigned NOT NULL DEFAULT 0 AFTER `attempt_limit`',
			),
			array(
				// 0 = unlimited.
				'column' => 'max_failed_attempts',
				'sql'    => 'ADD COLUMN `max_failed_attempts` tinyint unsigned NOT NULL DEFAULT 0 AFTER `attempt_cooldown_seconds`',
			),
		);

		$this->add_columns_if_missing( $table, $columns );
	}

	/**
	 * lrn_questions — optional hint shown to the learner.
	 */
	private function add_question_columns(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_questions';

		$columns = array(
			array(
				'column' => 'hint',
				'sql'    => 'ADD COLUMN `hint` text NULL DEFAULT NULL AFTER `explanation`',
			),
		);

		$this->add_columns_if_missing( $table, $columns );
	}

	/**
	 * lrn_lesson_attachments — multi-file resources per lesson. Stores the
	 * WP attachment id plus denormalised filename / mime / size so list
	 * views can render without another call to core.
	 */
	private function create_lesson_attachments_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'lrn_lesson_attachments';
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS `{$table}` (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lesson_id bigint(20) unsigned NOT NULL DEFAULT 0,
  media_id bigint(20) unsigned NOT NULL DEFAULT 0,
  filename varchar(255) NOT NULL DEFAULT '',
  mime_type varchar(100) NOT NULL DEFAULT '',
  size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY lesson_id (lesson_id,sort_order)
) ENGINE=InnoDB {$charset_collate}"
		);
	}

	/**
	 * Helper: iterate a column spec and ALTER ... ADD COLUMN only when the
	 * column is missing. Keeps every migration call idempotent.
	 *
	 * @param string                                       $table   Fully-qualified table name.
	 * @param array<int, array{column:string, sql:string}> $columns List of columns to add.
	 */
	private function add_columns_if_missing( string $table, array $columns ): void {
		global $wpdb;

		foreach ( $columns as $col_def ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					DB_NAME,
					$table,
					$col_def['column']
				)
			);

			if ( ! $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$table}` {$col_def['sql']}" );
			}
		}
	}
}
