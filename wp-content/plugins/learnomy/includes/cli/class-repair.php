<?php
/**
 * `wp learnomy repair` — one-shot integrity cleaners for the data states
 * that the cascade-on-delete refactor (2026-05-26) prevents going forward
 * but cannot retroactively fix.
 *
 * Use this after upgrading from any pre-2.3.0 release. Subsequent inserts
 * + deletes maintain integrity automatically via the new model-level
 * cascade overrides.
 *
 * Subcommands:
 *   wp learnomy repair data-integrity [--dry-run]
 *   wp learnomy repair student-count  [--dry-run]
 *   wp learnomy repair lesson-content [--dry-run]
 *
 * @package Learnomy
 */

namespace Learnomy\CLI;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Lesson;

use function Learnomy\html_to_editorjs_document;

class Repair {

	/**
	 * Repair the four data-integrity drift modes that the cascade refactor
	 * fixes prospectively. Idempotent — re-running on a clean DB is a no-op.
	 *
	 * Targets:
	 *   1. Drifted `student_count` on `lrn_courses` → recomputed from
	 *      active enrollments via `Course::recompute_student_counts()`.
	 *   2. Orphan enrollments whose `course_id` no longer exists in
	 *      `lrn_courses` → deleted through `Enrollment::delete()` so
	 *      progress + quiz attempts also clear.
	 *   3. Orphan commission rows whose `transaction_id` no longer exists
	 *      in `lrn_transactions` → deleted in bulk.
	 *   4. Orphan progress rows where the user has no enrollment on the
	 *      lesson's parent course → deleted in bulk.
	 *   5. Orphan co-instructor rows whose `course_id` no longer exists in
	 *      `lrn_courses` → deleted in bulk, so the Instructors roster stops
	 *      counting courses that are gone.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Print the action plan without writing any changes.
	 *
	 * @subcommand data-integrity
	 */
	public function data_integrity( array $args, array $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		global $wpdb;
		$p = $wpdb->prefix;

		\WP_CLI::line( $dry_run ? 'DRY RUN — no changes will be made' : 'Repair: data-integrity' );
		\WP_CLI::line( str_repeat( '-', 60 ) );

		// 1. Orphan enrollments — delete first so the student_count
		// recompute below sees the post-cleanup state.
		//
		// SCOPE TO COURSE ENROLMENTS (course_id > 0). A learning-path enrolment is
		// stored with course_id=0 / path_id>0 (the documented Pro convention), so
		// it has no matching course row and a bare LEFT JOIN would flag it as an
		// orphan — and this branch DELETES orphans, so the reconciler would wipe
		// every graduated/active path enrolment (Basecamp — smoke finding). Only a
		// row that CLAIMS a course (course_id > 0) whose course is gone is a true
		// orphan.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_enroll_ids   = $wpdb->get_col(
			"SELECT e.id FROM {$p}lrn_enrollments e LEFT JOIN {$p}lrn_courses c ON c.id = e.course_id WHERE e.course_id > 0 AND c.id IS NULL"
		);
		$orphan_enroll_count = count( $orphan_enroll_ids );
		\WP_CLI::line( sprintf( '%-50s %4d', 'Orphan enrollments (missing course)', $orphan_enroll_count ) );
		if ( ! $dry_run ) {
			foreach ( $orphan_enroll_ids as $id ) {
				Enrollment::delete( (int) $id );
			}
		}

		// 1b. Orphan enrollments whose USER is gone.
		//
		// The integrity check in class-test-runner.php has always asserted "No
		// enrollments point at a deleted user", and this command had no branch
		// for it — so a site was told it had a problem with no command able to
		// resolve it, which is the worst of both (Basecamp 10194260596).
		//
		// Normal user deletion does not produce these: User_Cleanup_Service
		// hooks `deleted_user` and removes the enrolments (verified — deleting
		// a user with an active enrolment leaves zero rows behind). They come
		// from paths that bypass that hook, which is mostly direct SQL: seeders,
		// imports, a `wp db query`, or a migration that removed users in bulk.
		//
		// Scoped to user_id > 0 for the same reason the course branch is scoped
		// to course_id > 0: a row that never claimed a user is not a row whose
		// user vanished, and this branch DELETES what it finds.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_enroll_user_ids   = $wpdb->get_col(
			"SELECT e.id FROM {$p}lrn_enrollments e LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id WHERE e.user_id > 0 AND u.ID IS NULL"
		);
		$orphan_enroll_user_count = count( $orphan_enroll_user_ids );
		\WP_CLI::line( sprintf( '%-50s %4d', 'Orphan enrollments (deleted user)', $orphan_enroll_user_count ) );
		if ( ! $dry_run ) {
			foreach ( $orphan_enroll_user_ids as $id ) {
				Enrollment::delete( (int) $id );
			}
		}

		// 2. Orphan commissions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_comm_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}lrn_commissions cm
			 LEFT JOIN {$p}lrn_transactions t ON t.id = cm.transaction_id
			 WHERE cm.transaction_id <> 0 AND t.id IS NULL"
		);
		\WP_CLI::line( sprintf( '%-50s %4d', 'Orphan commissions (missing transaction)', $orphan_comm_count ) );
		if ( ! $dry_run && $orphan_comm_count > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"DELETE cm FROM {$p}lrn_commissions cm
				 LEFT JOIN {$p}lrn_transactions t ON t.id = cm.transaction_id
				 WHERE cm.transaction_id <> 0 AND t.id IS NULL"
			);
		}

		// 3. Orphan progress rows — users with no enrollment on the
		// lesson's parent course.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_progress_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}lrn_progress p
			 JOIN {$p}lrn_lessons l ON l.id = p.item_id AND p.item_type = 'lesson'
			 LEFT JOIN {$p}lrn_enrollments e ON e.user_id = p.user_id AND e.course_id = l.course_id
			 WHERE e.id IS NULL"
		);
		\WP_CLI::line( sprintf( '%-50s %4d', 'Orphan progress rows (no enrollment)', $orphan_progress_count ) );
		if ( ! $dry_run && $orphan_progress_count > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"DELETE p FROM {$p}lrn_progress p
				 JOIN {$p}lrn_lessons l ON l.id = p.item_id AND p.item_type = 'lesson'
				 LEFT JOIN {$p}lrn_enrollments e ON e.user_id = p.user_id AND e.course_id = l.course_id
				 WHERE e.id IS NULL"
			);
		}

		// 3b. Orphan co-instructor rows — a teaching assignment pointing at a
		// course that no longer exists.
		//
		// Course_Service::delete_course() has cleared these since 10148252148,
		// so nothing produces them today. They are residue on any site that
		// deleted courses before that guard, or through a path that never went
		// near the service - a seeder, an import, a `wp db query`. Left behind
		// they are not cosmetic: Course::course_counts_for_instructors() UNIONs
		// this table with author_id rows, so the admin Instructors roster counts
		// the ghosts. A QA install showed "Courses: 286" for one instructor when
		// the whole courses table held 14 rows (Basecamp 10207832866).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_ci_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}lrn_course_instructors ci
			 LEFT JOIN {$p}lrn_courses c ON c.id = ci.course_id
			 WHERE c.id IS NULL"
		);
		\WP_CLI::line( sprintf( '%-50s %4d', 'Orphan co-instructor rows (missing course)', $orphan_ci_count ) );
		if ( ! $dry_run && $orphan_ci_count > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"DELETE ci FROM {$p}lrn_course_instructors ci
				 LEFT JOIN {$p}lrn_courses c ON c.id = ci.course_id
				 WHERE c.id IS NULL"
			);
		}

		// 4. Orphan quizzes — quiz row whose course_id points at a
		// missing course. Delete the row + its dependent attempts /
		// slots; the parent course is gone, the quiz is functionally
		// dead, and re-setting `course_id` to 0 would collide with
		// the UNIQUE (course_id, slug) constraint when multiple
		// standalone quizzes share a slug.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_quiz_ids      = (array) $wpdb->get_col(
			"SELECT q.id FROM {$p}lrn_quizzes q
			 LEFT JOIN {$p}lrn_courses c ON c.id = q.course_id
			 WHERE q.course_id <> 0 AND c.id IS NULL"
		);
		$orphan_quizzes_count = count( $orphan_quiz_ids );
		\WP_CLI::line( sprintf( '%-50s %4d', 'Orphan quizzes (missing course)', $orphan_quizzes_count ) );
		if ( ! $dry_run && $orphan_quizzes_count > 0 ) {
			foreach ( $orphan_quiz_ids as $qid ) {
				$qid = (int) $qid;
				// Clear dependents first so the quiz delete doesn't
				// trip FK / cache invalidation on stale rows.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( $p . 'lrn_quiz_slots', array( 'quiz_id' => $qid ), array( '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( $p . 'lrn_quiz_attempts', array( 'quiz_id' => $qid ), array( '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->delete( $p . 'lrn_quizzes', array( 'id' => $qid ), array( '%d' ) );
			}
		}

		// 5. student_count drift — recompute denormalized counter on
		// every course. Cheap (single subquery UPDATE). The canonical
		// "enrolled student" predicate is status IN ('active','completed')
		// (a completed enrollment is still a student; completion never
		// decrements the live counter), so the drift detector must use the
		// same set as Course::recompute_student_counts().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$drift_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}lrn_courses c
			 LEFT JOIN ( SELECT course_id, COUNT(DISTINCT user_id) AS n FROM {$p}lrn_enrollments WHERE status IN ( 'active', 'completed' ) GROUP BY course_id ) x
			 ON x.course_id = c.id
			 WHERE c.student_count <> COALESCE(x.n, 0)"
		);
		\WP_CLI::line( sprintf( '%-50s %4d', 'Courses with drifted student_count', $drift_count ) );
		if ( ! $dry_run && $drift_count > 0 ) {
			Course::recompute_student_counts();
		}

		// 6. progress_pct drift — the denormalized enrollment.progress_pct is only
		// restamped on lesson completion, so a curriculum edit (lesson added /
		// removed) after a student progressed leaves it stale, and every surface
		// reading the column disagrees with the live player bar (Basecamp
		// 10109156594). Reconcile by recomputing every course that has enrolments,
		// via the SAME Progress_Service method the live bar uses. The recompute
		// only WRITES a row whose stored value actually differs from live, so
		// clean courses cost reads only. Detecting exact drift up front would
		// mean an O(enrolments x lessons) query — cheaper to just recompute.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$progress_course_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				"SELECT DISTINCT course_id FROM {$p}lrn_enrollments WHERE course_id > 0 AND status IN ( 'active', 'completed' )"
			)
		);
		\WP_CLI::line( sprintf( '%-50s %4d', 'Courses to reconcile progress_pct', count( $progress_course_ids ) ) );
		if ( ! $dry_run && ! empty( $progress_course_ids ) ) {
			$service = new \Learnomy\Services\Progress_Service();
			foreach ( $progress_course_ids as $course_id ) {
				$after = 0;
				do {
					$after = $service->recompute_progress_for_course( $course_id, $after );
				} while ( $after > 0 );
			}
		}

		// 7. coupon times_used drift — the increment-only counter gates the usage
		// cap, so a voided/refunded usage or a GDPR-erased user's deleted usage
		// rows leave it too high and can prematurely lock the coupon. Reconcile
		// against the real COUNT(lrn_coupon_usages) (denormalized-column audit).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$coupon_drift = array_map(
			'intval',
			(array) $wpdb->get_col(
				"SELECT c.id FROM {$p}lrn_coupons c
				 LEFT JOIN ( SELECT coupon_id, COUNT(*) AS n FROM {$p}lrn_coupon_usages GROUP BY coupon_id ) u
				   ON u.coupon_id = c.id
				 WHERE c.times_used <> COALESCE(u.n, 0)"
			)
		);
		\WP_CLI::line( sprintf( '%-50s %4d', 'Coupons with drifted times_used', count( $coupon_drift ) ) );
		if ( ! $dry_run && ! empty( $coupon_drift ) ) {
			foreach ( $coupon_drift as $coupon_id ) {
				\Learnomy\Models\Coupon::recompute_times_used( $coupon_id );
			}
		}

		$relation_purged = 0;

		// Orphans derived from the relation registry rather than a hand-written
		// list. Every declared reference is checked, so a new table is covered
		// the moment it declares — which is the whole point of declaring.
		foreach ( \Learnomy\DB\Relations::orphans() as $orphan ) {
			// Reported in words, not table names. The person running this is a
			// site owner deciding whether their data is in trouble, and
			// "lrn_pro_space_group_courses" tells them nothing they can act on.
			// The table name is still recoverable from --debug if a developer
			// needs it; the default output answers the owner's actual question,
			// which is what is broken and how much of it.
			$label = ucfirst(
				trim(
					str_replace(
						'_',
						' ',
						preg_replace( '/^' . preg_quote( $p, '/' ) . '(lrn_pro_|lrn_)/', '', $orphan['table'] )
					)
				)
			);

			\WP_CLI::line(
				sprintf(
					'%-50s %4d',
					sprintf(
						/* translators: 1: human table label, 2: parent entity name */
						__( '%1$s with a deleted %2$s', 'learnomy' ),
						$label,
						$orphan['entity']
					),
					$orphan['count']
				)
			);
		}
		if ( ! $dry_run ) {
			$relation_purged = \Learnomy\DB\Relations::purge_orphans();
		}

		$extra_repaired = $this->run_registered_tasks( $dry_run );

		\WP_CLI::line( str_repeat( '-', 60 ) );

		if ( $dry_run ) {
			\WP_CLI::success( 'Dry run complete. Re-run without --dry-run to apply.' );
			return;
		}

		\WP_CLI::success(
			sprintf(
				'Repaired: %d enrollments, %d commissions, %d progress, %d quizzes, %d counters, %d from extensions.',
				$orphan_enroll_count,
				$orphan_comm_count,
				$orphan_progress_count,
				$orphan_quizzes_count,
				$drift_count,
				$extra_repaired
			)
		);
	}

	/**
	 * Run integrity tasks contributed by extensions.
	 *
	 * Free cannot check a table it must not know about. Pro owns
	 * `lrn_pro_*`, and Rule 9 forbids Free from referencing those — so
	 * without a seam here, a Pro-side orphan has nowhere to be repaired from,
	 * and the site owner is left running a command that silently covers half
	 * their data. That is exactly the shape of the bug this seam was built
	 * for: deleting a course used to leave its rows behind in
	 * `lrn_pro_learning_path_steps`, and the path then advertised those steps
	 * as "This course is no longer available" on a page still taking money.
	 * The cascade is fixed going forward; rows written before it are what this
	 * cleans.
	 *
	 * A task is an array:
	 *
	 *     array(
	 *         'label'  => 'Orphan path steps (missing course)',  // <= 50 chars
	 *         'count'  => fn(): int => …,   // how many are wrong, ALWAYS called
	 *         'repair' => fn(): int => …,   // fix them, return rows; dry-run skips
	 *     )
	 *
	 * `count` runs in both modes so `--dry-run` reports honestly. `repair`
	 * runs only for real, and its return value feeds the summary line.
	 *
	 * A task that throws is reported and skipped rather than aborting the run:
	 * a broken extension must not stop an owner repairing their own data.
	 *
	 * @param bool $dry_run Whether this is a reporting-only pass.
	 * @return int Total rows repaired by extensions (0 on a dry run).
	 */
	private function run_registered_tasks( bool $dry_run ): int {
		/**
		 * Filters the extension-contributed data-integrity tasks.
		 *
		 * @param array $tasks   List of task arrays (label, count, repair).
		 * @param bool  $dry_run Whether this pass will write.
		 */
		$tasks = (array) apply_filters( 'learnomy_repair_data_integrity_tasks', array(), $dry_run );

		$repaired = 0;

		foreach ( $tasks as $task ) {
			$label = isset( $task['label'] ) ? (string) $task['label'] : '';
			if ( '' === $label || ! isset( $task['count'] ) || ! is_callable( $task['count'] ) ) {
				continue;
			}

			try {
				$found = (int) call_user_func( $task['count'] );
			} catch ( \Throwable $e ) {
				\WP_CLI::warning( sprintf( '%s — count failed: %s', $label, $e->getMessage() ) );
				continue;
			}

			\WP_CLI::line( sprintf( '%-50s %4d', $label, $found ) );

			if ( $dry_run || $found <= 0 || ! isset( $task['repair'] ) || ! is_callable( $task['repair'] ) ) {
				continue;
			}

			try {
				$repaired += (int) call_user_func( $task['repair'] );
			} catch ( \Throwable $e ) {
				\WP_CLI::warning( sprintf( '%s — repair failed: %s', $label, $e->getMessage() ) );
			}
		}

		return $repaired;
	}

	/**
	 * Recompute the denormalized `lrn_courses.student_count` from the true
	 * active enrollment count for every course.
	 *
	 * Use this to correct sites that drifted on pre-fix releases, where
	 * cancel / expire transitions flipped an enrollment to a terminal state
	 * without decrementing the counter, so the displayed student total crept
	 * upward over subscription churn. The fix makes the increment / decrement
	 * paths symmetric going forward; this command realigns the historical
	 * drift in one pass.
	 *
	 * The true count is `COUNT(DISTINCT user_id) WHERE status IN ('active','completed')`
	 * per course — the same distinct predicate the live counter and
	 * `Course::recompute_student_counts()` use. It MUST be distinct, not
	 * `COUNT(*)`: `lrn_enrollments` is UNIQUE on (user_id, course_id, source),
	 * so one person holding both a `free` and a `membership` row on the same
	 * course is ONE student — a `COUNT(*)` detector would report that course as
	 * perpetually drifted against the distinct value the write actually stores
	 * (a completed enrollment is still a student of the course). Cheap: a single
	 * subquery UPDATE.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List the drifted courses and the correction without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy repair student-count --dry-run
	 *     wp learnomy repair student-count
	 *
	 * @subcommand student-count
	 */
	public function student_count( array $args, array $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		global $wpdb;
		$p = $wpdb->prefix;

		\WP_CLI::line( $dry_run ? 'DRY RUN — no changes will be made' : 'Repair: student-count' );
		\WP_CLI::line( str_repeat( '-', 60 ) );

		// Courses whose stored counter disagrees with the true active count.
		// NOTE: `STORED` is a reserved word in MariaDB 10.6+/MySQL 8 (generated
		// columns), so the stored-value alias is `stored_count`, not `stored`,
		// to avoid a 1064 syntax error.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$drifted = (array) $wpdb->get_results(
			"SELECT c.id, c.title, c.student_count AS stored_count, COALESCE(x.n, 0) AS actual_count
			 FROM {$p}lrn_courses c
			 LEFT JOIN (
				SELECT course_id, COUNT(DISTINCT user_id) AS n
				FROM {$p}lrn_enrollments
				WHERE status IN ( 'active', 'completed' )
				GROUP BY course_id
			 ) x ON x.course_id = c.id
			 WHERE c.student_count <> COALESCE(x.n, 0)
			 ORDER BY ABS( c.student_count - COALESCE(x.n, 0) ) DESC"
		);

		if ( empty( $drifted ) ) {
			\WP_CLI::success( 'No drifted courses. Every student_count already matches its active enrollment count.' );
			return;
		}

		\WP_CLI::line( sprintf( '%-8s %-32s %8s %8s', 'ID', 'Course', 'Stored', 'Actual' ) );
		foreach ( $drifted as $row ) {
			\WP_CLI::line(
				sprintf(
					'%-8d %-32s %8d %8d',
					(int) $row->id,
					mb_substr( (string) $row->title, 0, 32 ),
					(int) $row->stored_count,
					(int) $row->actual_count
				)
			);
		}

		\WP_CLI::line( str_repeat( '-', 60 ) );

		if ( $dry_run ) {
			\WP_CLI::success(
				sprintf( '%d course(s) would be corrected. Re-run without --dry-run to apply.', count( $drifted ) )
			);
			return;
		}

		Course::recompute_student_counts();

		\WP_CLI::success( sprintf( 'Recomputed student_count on %d drifted course(s).', count( $drifted ) ) );
	}

	/**
	 * Wrap legacy raw-HTML lesson bodies in an Editor.js document.
	 *
	 * Learnomy stores `lrn_lessons.content` as an Editor.js block document. A
	 * lesson imported from LearnDash/LifterLMS/Tutor before the importer fix
	 * (or authored by a theme override) holds raw `post_content` HTML, which
	 * `json_decode()`s to null — so the wp-admin block editor shows a blank body
	 * and any save overwrites it. The importer now wraps new imports in a `raw`
	 * block; this realigns sites that already imported.
	 *
	 * Each non-Editor.js body is wrapped via {@see \Learnomy\html_to_editorjs_document()}
	 * — the same converter the importer uses, so the result is identical to a
	 * fresh import: editable in the block editor (bundled RawTool) and rendered
	 * by the renderer's `case 'raw'` branch with shortcodes + embed iframes
	 * intact. `content_plain` (the FULLTEXT search column) is untouched.
	 *
	 * Idempotent + big-site-safe: candidates are filtered to non-empty,
	 * non-JSON bodies in SQL and processed in pages, so a re-run on a clean DB
	 * is a no-op and a 100k-lesson site never loads every row at once.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Count the lessons that would be converted without writing anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy repair lesson-content --dry-run
	 *     wp learnomy repair lesson-content
	 *
	 * @subcommand lesson-content
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Associative args (`dry-run`).
	 */
	public function lesson_content( array $args, array $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		global $wpdb;
		$table = $wpdb->prefix . 'lrn_lessons';

		// This legacy repair wraps raw HTML bodies INTO Editor.js JSON — the
		// pre-cutover direction. Under the HTML-first cutover (ON by default) that
		// is backwards: it would re-wrap migrated HTML into JSON. Only meaningful
		// on a kill-switched (JSON-storage) site. Abort otherwise; use
		// `wp learnomy content migrate` for the HTML-first direction.
		if ( \Learnomy\Content\Content_HTML::storage_enabled() ) {
			\WP_CLI::warning( 'Skipped: the HTML-first content cutover is active (learnomy_store_content_as_html). This command converts the opposite direction. Use `wp learnomy content migrate --to-html` instead.' );
			return;
		}

		\WP_CLI::line( $dry_run ? 'DRY RUN — no changes will be made' : 'Repair: lesson-content' );
		\WP_CLI::line( str_repeat( '-', 60 ) );

		// Candidates: a non-empty body that is not already an Editor.js document.
		// A real Editor.js document is a JSON object, so it starts with '{'; raw
		// HTML never does. The `LEFT(TRIM(content),1) <> '{'` test is wildcard-free
		// (no LIKE) so it needs no placeholder. html_to_editorjs_document()
		// re-confirms per row and leaves any true document untouched, so the SQL
		// pre-filter is just a cheap narrower, not the correctness boundary.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE content <> '' AND LEFT(TRIM(content),1) <> '{'"
		);

		\WP_CLI::line( sprintf( '%-50s %4d', 'Lessons with legacy HTML content', $total ) );

		if ( 0 === $total ) {
			\WP_CLI::success( 'No legacy lesson content. Every lesson body is already an Editor.js document.' );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::line( str_repeat( '-', 60 ) );
			\WP_CLI::success( sprintf( '%d lesson(s) would be converted. Re-run without --dry-run to apply.', $total ) );
			return;
		}

		$converted = 0;
		$page_size = 500;
		// OFFSET stays 0: each pass converts a page, the converted rows drop out
		// of the candidate filter, so the next page of un-converted rows shifts
		// into the first window. A wrap that somehow no-ops still advances via the
		// safety counter below so the loop can't spin forever.
		$guard   = 0;
		$fetched = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id, content FROM {$table} WHERE content <> '' AND LEFT(TRIM(content),1) <> '{' ORDER BY id ASC LIMIT %d",
					$page_size
				)
			);
			$fetched = count( $rows );
			if ( 0 === $fetched ) {
				break;
			}
			foreach ( $rows as $row ) {
				$doc = html_to_editorjs_document( (string) $row->content );
				// Already-document guard: if the converter returned the body
				// unchanged it isn't legacy HTML — skip so we never loop on it.
				if ( $doc === (string) $row->content || '' === $doc ) {
					continue;
				}
				Lesson::update( (int) $row->id, array( 'content' => $doc ) );
				++$converted;
			}
			++$guard;
		} while ( $fetched === $page_size && $guard < 10000 );

		\WP_CLI::line( str_repeat( '-', 60 ) );
		\WP_CLI::success( sprintf( 'Wrapped %d legacy lesson body(ies) in an Editor.js document.', $converted ) );
	}
}
