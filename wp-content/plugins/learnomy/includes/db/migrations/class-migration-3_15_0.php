<?php
/**
 * Migration 3.15.0 — purge non-enrolled lesson-progress orphans (Basecamp #10058227697... i.e. #10058278998).
 *
 * A shipped, REST-exposed write path — the `learnomy/complete-lesson` Ability
 * (and its sibling `learnomy/restart-course`) — gated only on the context-free
 * `learn` cap, so a non-enrolled, non-admin student could write a completed
 * `lrn_progress` row for a lesson they can't access. That hole is now closed:
 * both abilities' permission_callbacks gate on `access_course` for the lesson's
 * course, mirroring the `is_enrolled_student` REST controllers.
 *
 * This migration cleans up the rows that already drifted before the gate
 * existed. A row is a genuine orphan only when the user has NO legitimate access
 * to the course by ANY means — so we resolve each candidate through
 * `Permission_Engine::can( 'access_course' )`, the exact runtime definition
 * (admin OR enrolled OR active membership). That guarantees we never delete an
 * admin preview or a member's real progress (a plan course added after subscribe
 * can grant access without a per-course enrollment row). Preview lessons are
 * excluded too (open to anyone).
 *
 * Forward-only + idempotent: only genuine no-access orphans are removed, so a
 * re-run / clean site is a harmless no-op. The candidate set is tiny (orphans),
 * so the per-row access check is negligible.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

use Learnomy\Permissions\Permission_Engine;

class Migration_3_15_0 {

	public function up(): void {
		global $wpdb;

		// Guard: if the permission layer isn't loaded at migration time, do
		// nothing rather than risk deleting a row we can't authorize. The
		// assertion still flags it and a later run cleans it.
		if ( ! class_exists( Permission_Engine::class ) ) {
			return;
		}

		$progress    = $wpdb->prefix . 'lrn_progress';
		$lessons     = $wpdb->prefix . 'lrn_lessons';
		$enrollments = $wpdb->prefix . 'lrn_enrollments';

		// Candidate orphans: lesson-progress rows on a non-preview lesson whose
		// (user, course) pair has no enrollment row. Bounded — this is the drift
		// set, not the full table.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.user_id, l.course_id
				 FROM `{$progress}` p
				 JOIN `{$lessons}` l ON l.id = p.item_id AND p.item_type = %s
				 LEFT JOIN `{$enrollments}` e ON e.user_id = p.user_id AND e.course_id = l.course_id
				 WHERE e.id IS NULL AND l.is_preview = 0",
				'lesson'
			)
		);

		if ( empty( $candidates ) ) {
			return;
		}

		$delete_ids = array();
		foreach ( $candidates as $row ) {
			// Keep the row if the user legitimately has access by any means an
			// enrollment row wouldn't capture (admin, or active membership that
			// covers the course). Only a true no-access orphan is purged.
			if ( Permission_Engine::can( (int) $row->user_id, 'access_course', (int) $row->course_id ) ) {
				continue;
			}
			$delete_ids[] = (int) $row->id;
		}

		if ( empty( $delete_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $delete_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$progress}` WHERE id IN ({$placeholders})",
				...$delete_ids
			)
		);
	}
}
