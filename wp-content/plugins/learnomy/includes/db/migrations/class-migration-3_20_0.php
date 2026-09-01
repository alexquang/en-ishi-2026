<?php
/**
 * Migration 3.20.0 — backfill instructor profiles for course assignments.
 *
 * The admin Instructors list is driven by `lrn_instructors` (one profile row
 * per instructor). A profile row was only ever created on profile edit or when
 * an application was approved — NOT when a user was assigned to teach a course
 * via `lrn_course_instructors`. So a course author or co-instructor who never
 * went through the application flow (imported courses, hand-assigned
 * co-instructors, the site owner's own courses) taught real courses but never
 * appeared in the Instructors list ("No instructors yet" beside real
 * assignments). The go-forward fix (CourseInstructor::add_instructor now calls
 * Instructor::ensure_for_user) does not retroactively cover assignments that
 * already exist — this does.
 *
 * Forward-only and idempotent: inserts one `lrn_instructors` row per distinct
 * course-instructor user that is missing one (LEFT JOIN ... IS NULL), matching
 * the shape Instructor::ensure_for_user() writes. A re-run (or a clean site)
 * matches nothing and is a harmless no-op.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_20_0 {

	public function up(): void {
		global $wpdb;

		$instructors        = $wpdb->prefix . 'lrn_instructors';
		$course_instructors = $wpdb->prefix . 'lrn_course_instructors';
		$now                = gmdate( 'Y-m-d H:i:s' );

		// One profile row per distinct teaching user that lacks one. Only real
		// wp_users are included (JOIN wp_users) so a stale course-instructor row
		// pointing at a deleted user does not create an orphan profile.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO `{$instructors}` ( user_id, verification_status, created_at, updated_at )
				 SELECT DISTINCT ci.user_id, 'unverified', %s, %s
				 FROM `{$course_instructors}` ci
				 JOIN `{$wpdb->users}` u ON u.ID = ci.user_id
				 LEFT JOIN `{$instructors}` i ON i.user_id = ci.user_id
				 WHERE i.user_id IS NULL",
				$now,
				$now
			)
		);
	}
}
