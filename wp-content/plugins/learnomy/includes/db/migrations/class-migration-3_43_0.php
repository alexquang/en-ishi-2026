<?php
/**
 * Migration 3.43.0 — hand orphaned courses back to the admin.
 *
 * A course stores `author_id` in its own table, which WordPress knows nothing
 * about. `User_Cleanup_Service::reassign_instructor_courses()` covers the normal
 * path — deleting a user through WordPress moves their courses to the admin —
 * but rows exist from deletions that never fired `deleted_user`: direct SQL, a
 * user removed while the plugin was inactive, or a cleanup that died part-way
 * (the demo reset did exactly that until the fatal was fixed in this release).
 * 14 such courses were measured on the QA sandbox.
 *
 * Those courses are invisible in any instructor-scoped list and their byline
 * renders nothing, so nobody finds them by accident — which is why they need
 * a one-time pass rather than waiting to be noticed.
 *
 * REASSIGNS rather than deletes. A course whose author row has gone is broken
 * data, but it is not necessarily demo data: it can be a real course with real
 * enrollments whose instructor was removed. Deleting on that signal would
 * destroy paid content; handing it to the admin loses nothing and makes it
 * manageable again.
 *
 * Idempotent — a second run finds no orphans and does nothing.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_43_0 {

	public function up(): void {
		if ( ! class_exists( '\Learnomy\Services\User_Cleanup_Service' ) ) {
			return;
		}

		$service  = new \Learnomy\Services\User_Cleanup_Service();
		$repaired = $service->repair_orphaned_authors();

		if ( $repaired > 0 ) {
			// Worth a line in the log: this silently changes who owns a course,
			// and an owner asking "why does the admin own this?" deserves an
			// answer that is findable.
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Learnomy 3.43.0: reassigned %d course(s) whose author row no longer existed to the site administrator.',
					$repaired
				)
			);
		}
	}
}
