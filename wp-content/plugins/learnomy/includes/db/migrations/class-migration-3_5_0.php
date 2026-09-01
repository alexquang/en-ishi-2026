<?php
/**
 * Migration 3.5.0 — purge orphaned quiz data.
 *
 * Until the REST `delete_quiz` endpoint cascaded (see
 * {@see \Learnomy\Services\Quiz_Service::delete_quiz()}), deleting a quiz dropped
 * only the `lrn_quizzes` row and left its slots, attempts, and responses behind.
 * This one-time sweep removes rows whose parent no longer exists, plus slot rows
 * with `quiz_id = 0` (demo-seeder artifacts) — both covered by the
 * quiz-no-longer-exists join.
 *
 * Forward-only and idempotent (re-running deletes nothing once clean).
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_5_0 {

	public function up(): void {
		global $wpdb;
		$quizzes   = $wpdb->prefix . 'lrn_quizzes';
		$attempts  = $wpdb->prefix . 'lrn_quiz_attempts';
		$slots     = $wpdb->prefix . 'lrn_quiz_slots';
		$responses = $wpdb->prefix . 'lrn_question_responses';

		// Responses whose attempt's quiz is gone — delete BEFORE the attempts so
		// the join still resolves.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE r FROM `{$responses}` r INNER JOIN `{$attempts}` a ON r.attempt_id = a.id LEFT JOIN `{$quizzes}` q ON a.quiz_id = q.id WHERE q.id IS NULL" );

		// Responses whose attempt row no longer exists at all.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE r FROM `{$responses}` r LEFT JOIN `{$attempts}` a ON r.attempt_id = a.id WHERE a.id IS NULL" );

		// Attempts whose quiz is gone.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE a FROM `{$attempts}` a LEFT JOIN `{$quizzes}` q ON a.quiz_id = q.id WHERE q.id IS NULL" );

		// Slots whose quiz is gone (covers quiz_id = 0 seeder artifacts).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE s FROM `{$slots}` s LEFT JOIN `{$quizzes}` q ON s.quiz_id = q.id WHERE q.id IS NULL" );
	}
}
