<?php
/**
 * WP-CLI commands for Learnomy.
 *
 * Registers as: wp learnomy <subcommand>
 *
 * @package Learnomy
 */

namespace Learnomy\CLI;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Certificate;
use Learnomy\Models\Lesson;
use Learnomy\Models\Commission;
use Learnomy\Models\Transaction;
use Learnomy\Services\Enrollment_Service;
use Learnomy\Services\Progress_Service;
use Learnomy\Services\User_Cleanup_Service;
use Learnomy\Admin\Ajax\Demo_Seeder;

if ( defined( 'WP_CLI' ) && \WP_CLI ) {
	// Nested command groups live in their own files (one class per file, like
	// inspect/test/repair). WP-CLI's @subcommand annotation only accepts a
	// single token, so a multi-word annotation such as "course list" never
	// produces a reachable `wp learnomy course list`. Registering each group as
	// its own command class makes the second word resolve as a real subcommand.
	require_once __DIR__ . '/class-course-command.php';
	require_once __DIR__ . '/class-certificate-command.php';
	require_once __DIR__ . '/class-membership-command.php';
	require_once __DIR__ . '/class-db-command.php';
	require_once __DIR__ . '/class-migrate-command.php';

	// The model-site seeder lives under bin/ (dev/demo tool, not a runtime
	// class). Require it so its class loads, then register the command HERE
	// (after the parent `learnomy` command below) — registering it from inside
	// the seeder file raced with class load order and silently dropped the
	// subcommand. Registration belongs with the other command registrations.
	// bin/ is EXCLUDED from the distributed zip, so both the require and the
	// command registration are guarded — a dist install simply has no
	// seed-model-site command instead of fataling every WP-CLI invocation.
	$lrn_model_seeder = LEARNOMY_DIR . 'bin/seed-model-site.php';
	if ( is_readable( $lrn_model_seeder ) ) {
		require_once $lrn_model_seeder;
	}

	// Same arrangement, same reason: bin/ is stripped from the distributed zip,
	// so a dist install simply has no seed-usecase command rather than fataling.
	$lrn_usecase_seeder = LEARNOMY_DIR . 'bin/use-case-seeder.php';
	if ( is_readable( $lrn_usecase_seeder ) ) {
		require_once $lrn_usecase_seeder;
	}

	\WP_CLI::add_command( 'learnomy', CLI::class );
	if ( class_exists( '\Learnomy\Bin\Model_Site_Seeder' ) ) {
		\WP_CLI::add_command( 'learnomy seed-model-site', \Learnomy\Bin\Model_Site_Seeder::class );
	}
	if ( class_exists( '\Learnomy\Bin\UseCase\Use_Case_Command' ) ) {
		\WP_CLI::add_command( 'learnomy seed-usecase', \Learnomy\Bin\UseCase\Use_Case_Command::class );
	}
	\WP_CLI::add_command( 'learnomy inspect', Inspect::class );
	\WP_CLI::add_command( 'learnomy test', TestRunner::class );
	\WP_CLI::add_command( 'learnomy repair', Repair::class );
	\WP_CLI::add_command( 'learnomy content', Content_Command::class );
	\WP_CLI::add_command( 'learnomy selfcheck', SelfCheck::class );
	\WP_CLI::add_command( 'learnomy module', Module::class );

	\WP_CLI::add_command( 'learnomy course', Course_Command::class );
	\WP_CLI::add_command( 'learnomy certificate', Certificate_Command::class );
	\WP_CLI::add_command( 'learnomy membership', Membership_Command::class );
	\WP_CLI::add_command( 'learnomy db', DB_Command::class );
	\WP_CLI::add_command( 'learnomy migrate', Migrate_Command::class );

	/**
	 * Fires after built-in CLI commands are registered.
	 *
	 * Add-ons can use this to register additional WP-CLI subcommands
	 * under the `wp learnomy` namespace.
	 */
	do_action( 'learnomy_cli_commands' );
}

/**
 * Manage the Learnomy LMS from the command line.
 */
class CLI {

	// -------------------------------------------------------------------------
	// Enrollment commands
	// -------------------------------------------------------------------------

	/**
	 * Enroll a user in a course.
	 *
	 * ## OPTIONS
	 *
	 * --user-id=<id>
	 * : WordPress user ID. (Cannot use --user; that is a reserved WP-CLI global.)
	 *
	 * --course=<id>
	 * : Course ID.
	 *
	 * [--source=<source>]
	 * : Enrollment source. Default: manual.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy enroll --user-id=7 --course=12
	 *     wp learnomy enroll --user-id=7 --course=12 --source=gift
	 */
	public function enroll( array $args, array $assoc_args ): void {
		$user_id   = absint( $assoc_args['user-id'] ?? $assoc_args['user'] ?? 0 );
		$course_id = absint( $assoc_args['course'] ?? 0 );
		$source    = sanitize_text_field( $assoc_args['source'] ?? 'manual' );

		if ( ! $user_id || ! $course_id ) {
			\WP_CLI::error( __( '--user-id and --course are required.', 'learnomy' ) );
		}

		$service       = new Enrollment_Service();
		$enrollment_id = $service->enroll( $user_id, $course_id, $source );

		// enroll() returns int|WP_Error. A WP_Error is a truthy object, so it must
		// be caught before the `! $enrollment_id` check — otherwise it slips
		// through and the success sprintf('%1$d', $enrollment_id) below fatals on
		// "Object of class WP_Error could not be converted to int".
		if ( is_wp_error( $enrollment_id ) ) {
			\WP_CLI::error( $enrollment_id->get_error_message() );
		}

		if ( ! $enrollment_id ) {
			\WP_CLI::error( __( 'Enrollment failed. User may already be enrolled or prerequisites are not met.', 'learnomy' ) );
		}

		\WP_CLI::success(
			/* translators: 1: enrollment ID 2: user ID 3: course ID */
			sprintf( __( 'Enrolled (enrollment ID %1$d, user %2$d > course %3$d).', 'learnomy' ), $enrollment_id, $user_id, $course_id )
		);
	}

	/**
	 * Move lesson handouts that are still on a public uploads path into the
	 * private store, and report the server's own blocking status.
	 *
	 * Runs automatically once per site through Action Scheduler. This command is
	 * for the owner who wants to see the result now, or to re-run it after
	 * resolving a file that was skipped for being shared with a thumbnail.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would move without moving anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy protect-attachments --dry-run
	 *     wp learnomy protect-attachments
	 *
	 * @subcommand protect-attachments
	 */
	public function protect_attachments( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$result  = \Learnomy\Services\Protected_Uploads::sweep( $dry_run );

		\WP_CLI::log(
			sprintf(
				/* translators: 1: total attachments 2: number moved 3: number already private 4: number skipped */
				__( '%1$d handouts: %2$d moved, %3$d already private, %4$d skipped.', 'learnomy' ),
				(int) $result['total'],
				(int) $result['protected'],
				(int) $result['already'],
				count( $result['skipped'] )
			)
		);

		foreach ( $result['skipped'] as $media_id => $reason ) {
			/* translators: 1: media ID 2: reason */
			\WP_CLI::warning( sprintf( __( 'Media %1$d skipped: %2$s', 'learnomy' ), (int) $media_id, $reason ) );
		}

		$status = \Learnomy\Services\Protected_Uploads::probe();
		if ( 'blocked' === $status ) {
			\WP_CLI::success( __( 'The web server also refuses direct requests to the private folder.', 'learnomy' ) );
			return;
		}

		\WP_CLI::warning( __( 'The web server did not refuse a direct request to the private folder (normal on nginx). Add:', 'learnomy' ) );
		\WP_CLI::log( \Learnomy\Services\Protected_Uploads::nginx_snippet() );
	}

	/**
	 * Remove a user's enrollment from a course.
	 *
	 * ## OPTIONS
	 *
	 * --user-id=<id>
	 * : WordPress user ID. (Cannot use --user; that is a reserved WP-CLI global.)
	 *
	 * --course=<id>
	 * : Course ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy unenroll --user-id=7 --course=12
	 */
	public function unenroll( array $args, array $assoc_args ): void {
		$user_id   = absint( $assoc_args['user-id'] ?? $assoc_args['user'] ?? 0 );
		$course_id = absint( $assoc_args['course'] ?? 0 );

		if ( ! $user_id || ! $course_id ) {
			\WP_CLI::error( __( '--user-id and --course are required.', 'learnomy' ) );
		}

		$enrollment = Enrollment::find_for_user_course( $user_id, $course_id );
		if ( ! $enrollment ) {
			\WP_CLI::error(
				/* translators: 1: user ID 2: course ID */
				sprintf( __( 'No active enrollment found for user %1$d in course %2$d.', 'learnomy' ), $user_id, $course_id )
			);
		}

		$service = new Enrollment_Service();
		if ( ! $service->unenroll( (int) $enrollment->id ) ) {
			\WP_CLI::error( __( 'Unenrollment failed.', 'learnomy' ) );
		}

		\WP_CLI::success(
			/* translators: 1: user ID 2: course ID */
			sprintf( __( 'User %1$d unenrolled from course %2$d.', 'learnomy' ), $user_id, $course_id )
		);
	}

	// -------------------------------------------------------------------------
	// Progress
	// -------------------------------------------------------------------------

	/**
	 * Show a user's progress in a course.
	 *
	 * ## OPTIONS
	 *
	 * --user-id=<id>
	 * : WordPress user ID. (Cannot use --user; that is a reserved WP-CLI global.)
	 *
	 * --course=<id>
	 * : Course ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy progress --user-id=7 --course=12
	 */
	public function progress( array $args, array $assoc_args ): void {
		$user_id   = absint( $assoc_args['user-id'] ?? $assoc_args['user'] ?? 0 );
		$course_id = absint( $assoc_args['course'] ?? 0 );

		if ( ! $user_id || ! $course_id ) {
			\WP_CLI::error( __( '--user-id and --course are required.', 'learnomy' ) );
		}

		$course = Course::find( $course_id );
		if ( ! $course ) {
			\WP_CLI::error(
				/* translators: %d: course ID */
				sprintf( __( 'Course %d not found.', 'learnomy' ), $course_id )
			);
		}

		$service     = new Progress_Service();
		$pct         = $service->calculate_course_progress( $user_id, $course_id );
		$enrollment  = Enrollment::find_for_user_course( $user_id, $course_id );
		$enrolled_at = $enrollment ? $enrollment->enrolled_at : __( 'not enrolled', 'learnomy' );

		// Derive completed/total from the enrollment progress and percentage to
		// avoid a second round trip — Progress_Service already did the counting.
		$all_lessons = \Learnomy\Models\Lesson::get_for_course( $course_id );
		$total       = count( $all_lessons );
		$completed   = $total > 0 ? (int) round( $pct * $total / 100 ) : 0;

		\WP_CLI::line( __( 'Course      : ', 'learnomy' ) . $course->title );
		\WP_CLI::line( __( 'Progress    : ', 'learnomy' ) . $pct . '%' );
		\WP_CLI::line( __( 'Lessons     : ', 'learnomy' ) . $completed . ' / ' . $total . ' completed' );
		\WP_CLI::line( __( 'Enrolled at : ', 'learnomy' ) . $enrolled_at );
	}

	// -------------------------------------------------------------------------
	// Earnings
	// -------------------------------------------------------------------------

	/**
	 * Display earnings summary for an instructor.
	 *
	 * ## OPTIONS
	 *
	 * --user-id=<id>
	 * : Instructor user ID. (Cannot use --user; that is a reserved WP-CLI global.)
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy earnings --user-id=5
	 */
	public function earnings( array $args, array $assoc_args ): void {
		$user_id = absint( $assoc_args['user-id'] ?? $assoc_args['user'] ?? 0 );
		if ( ! $user_id ) {
			\WP_CLI::error( __( '--user-id is required.', 'learnomy' ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			\WP_CLI::error(
				/* translators: %d: user ID */
				sprintf( __( 'User %d not found.', 'learnomy' ), $user_id )
			);
		}

		$all = Commission::get_for_instructor( $user_id );

		$now        = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$this_month = $now->format( 'Y-m' );
		$last_month = ( clone $now )->modify( 'first day of last month' )->format( 'Y-m' );

		$lifetime = 0.0;
		$this_m   = 0.0;
		$last_m   = 0.0;
		$pending  = 0.0;

		foreach ( $all as $commission ) {
			$amount = (float) $commission->amount;
			$month  = substr( $commission->created_at, 0, 7 );
			$status = $commission->payout_status ?? 'pending';

			$lifetime += $amount;

			if ( $month === $this_month ) {
				$this_m += $amount;
			} elseif ( $month === $last_month ) {
				$last_m += $amount;
			}

			if ( 'pending' === $status ) {
				$pending += $amount;
			}
		}

		\WP_CLI::line( __( 'Instructor  : ', 'learnomy' ) . $user->display_name . ' (ID ' . $user_id . ')' );
		\WP_CLI::line( __( 'This month  : $', 'learnomy' ) . number_format( $this_m, 2 ) );
		\WP_CLI::line( __( 'Last month  : $', 'learnomy' ) . number_format( $last_m, 2 ) );
		\WP_CLI::line( __( 'Lifetime    : $', 'learnomy' ) . number_format( $lifetime, 2 ) );
		\WP_CLI::line( __( 'Pending     : $', 'learnomy' ) . number_format( $pending, 2 ) );
	}

	// -------------------------------------------------------------------------
	// Export
	// -------------------------------------------------------------------------

	/**
	 * Export platform data as CSV.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : What to export. Accepts: courses, students, earnings.
	 *
	 * [--file=<path>]
	 * : Write CSV to this file path instead of stdout.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy export courses
	 *     wp learnomy export students --file=/tmp/students.csv
	 *     wp learnomy export earnings --file=/tmp/earnings.csv
	 */
	public function export( array $args, array $assoc_args ): void {
		$type = sanitize_text_field( $args[0] ?? '' );
		$file = $assoc_args['file'] ?? '';

		switch ( $type ) {
			case 'courses':
				$rows    = $this->build_courses_export();
				$headers = [ 'id', 'title', 'status', 'price', 'students', 'rating', 'created_at' ];
				break;

			case 'students':
				$rows    = $this->build_students_export();
				$headers = [ 'user_id', 'name', 'email', 'enrolled_courses', 'completion_rate' ];
				break;

			case 'earnings':
				$rows    = $this->build_earnings_export();
				$headers = [ 'instructor_id', 'name', 'this_month', 'last_month', 'lifetime' ];
				break;

			default:
				\WP_CLI::error(
					/* translators: %s: invalid type provided */
					sprintf( __( 'Unknown export type "%s". Use: courses, students, earnings.', 'learnomy' ), $type )
				);
				return;
		}

		$csv = $this->to_csv( $headers, $rows );

		if ( $file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( false === file_put_contents( $file, $csv ) ) {
				\WP_CLI::error(
					/* translators: %s: file path */
					sprintf( __( 'Could not write to %s. Check the path and permissions.', 'learnomy' ), $file )
				);
			}
			\WP_CLI::success(
				/* translators: %s: file path */
				sprintf( __( 'Exported to %s.', 'learnomy' ), $file )
			);
		} else {
			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	// -------------------------------------------------------------------------
	// Stats
	// -------------------------------------------------------------------------

	/**
	 * Show overall platform statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy stats
	 */
	public function stats( array $args, array $assoc_args ): void {
		// Course counts per status.
		$course_counts = Course::count_by_status();
		$total_courses = array_sum( array_column( $course_counts, 'n' ) );

		// Student count — distinct users ever enrolled (all statuses, all courses).
		$total_students = Enrollment::count_distinct_students( array(), array() );

		// Enrollment counts per status.
		$enrollment_counts = Enrollment::count_by_status();

		// Revenue — sum of completed transactions.
		$revenue = Transaction::total_completed_revenue();

		// Certificates issued.
		$total_certs = Certificate::count_active();

		// Avg completion rate — mean of progress_pct across active enrollments.
		$avg_completion = Enrollment::avg_completion();

		\WP_CLI::line( '' );
		\WP_CLI::line( '=== Learnomy Platform Stats ===' );
		\WP_CLI::line( '' );
		\WP_CLI::line( sprintf( '%-28s %s', __( 'Total courses:', 'learnomy' ), $total_courses ) );

		foreach ( $course_counts as $row ) {
			\WP_CLI::line( sprintf( '  %-26s %s', ucfirst( $row->status ) . ':', $row->n ) );
		}

		\WP_CLI::line( sprintf( '%-28s %s', __( 'Total students:', 'learnomy' ), $total_students ) );
		\WP_CLI::line( sprintf( '%-28s %s', __( 'Total enrollments:', 'learnomy' ), array_sum( array_column( $enrollment_counts, 'n' ) ) ) );

		foreach ( $enrollment_counts as $row ) {
			\WP_CLI::line( sprintf( '  %-26s %s', ucfirst( $row->status ) . ':', $row->n ) );
		}

		\WP_CLI::line( sprintf( '%-28s $%s', __( 'Total revenue:', 'learnomy' ), number_format( $revenue, 2 ) ) );
		\WP_CLI::line( sprintf( '%-28s %s', __( 'Certificates issued:', 'learnomy' ), $total_certs ) );
		\WP_CLI::line( sprintf( '%-28s %s%%', __( 'Avg completion rate:', 'learnomy' ), number_format( $avg_completion, 1 ) ) );
		\WP_CLI::line( '' );
	}

	// -------------------------------------------------------------------------
	// Demo data
	// -------------------------------------------------------------------------

	/**
	 * Seed demo courses, students, quizzes, enrollments, and reviews.
	 *
	 * Creates three courses (React, Python, Marketing), five demo users,
	 * enrollments with realistic progress, reviews, a membership plan,
	 * and a commission rule. Stores the manifest in learnomy_demo_data
	 * so seed-cleanup can reverse everything precisely.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation when demo data already exists.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy seed
	 *     wp learnomy seed --yes
	 *
	 * @subcommand seed
	 */
	public function seed( array $args, array $assoc_args ): void {
		$existing = get_option( 'learnomy_demo_data' );

		if ( $existing ) {
			\WP_CLI::confirm(
				__( 'Demo data already exists. Running seed again will create a second set. Continue?', 'learnomy' ),
				$assoc_args
			);
		}

		$admin_id = get_current_user_id() ?: 1;

		\WP_CLI::log( __( 'Creating demo data…', 'learnomy' ) );

		$manifest = Demo_Seeder::seed( $admin_id );

		if ( empty( $manifest ) ) {
			\WP_CLI::error( __( 'Demo data could not be created. Check the error log for details.', 'learnomy' ) );
		}

		update_option( 'learnomy_demo_data', $manifest, false );

		$course_count = count( array_filter( $manifest['course_ids'] ?? array() ) );
		$user_count   = count( array_filter( $manifest['user_ids'] ?? array() ) );

		\WP_CLI::success(
			sprintf(
				/* translators: 1: course count 2: user count */
				__( 'Demo data created: %1$d courses, %2$d users, enrollments, quizzes, and reviews.', 'learnomy' ),
				$course_count,
				$user_count
			)
		);
	}

	/**
	 * Remove all demo data created by the seed command.
	 *
	 * Reads the manifest from the learnomy_demo_data option and deletes every
	 * row in dependency order so no orphaned records are left behind.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * [--include-test-fixtures]
	 * : Also purge CLI test runner residue (cli-test-* users and courses)
	 * and legacy QA accounts. Use this for a release-ready clean slate.
	 *
	 * [--all]
	 * : Alias for --include-test-fixtures that also runs signature cleanup
	 * even when a manifest is present — wipes everything the plugin could
	 * have created as demo or test data.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy seed-cleanup
	 *     wp learnomy seed-cleanup --yes
	 *     wp learnomy seed-cleanup --yes --include-test-fixtures
	 *     wp learnomy seed-cleanup --yes --all
	 *
	 * @subcommand seed-cleanup
	 */
	public function seed_cleanup( array $args, array $assoc_args ): void {
		$manifest          = get_option( 'learnomy_demo_data' );
		$include_fixtures  = isset( $assoc_args['include-test-fixtures'] ) || isset( $assoc_args['all'] );
		$run_signature_too = isset( $assoc_args['all'] );

		\WP_CLI::confirm(
			__( 'This will delete all demo courses, users, enrollments, quizzes, and reviews. Continue?', 'learnomy' ),
			$assoc_args
		);

		if ( ! empty( $manifest ) ) {
			Demo_Seeder::cleanup( $manifest );
			delete_option( 'learnomy_demo_data' );
			if ( ! $run_signature_too ) {
				\WP_CLI::success( __( 'Demo data removed (manifest-based).', 'learnomy' ) );
				return;
			}
			\WP_CLI::log( __( 'Manifest cleanup done. Running signature sweep for leftovers.', 'learnomy' ) );
		}

		// No manifest — fall back to signature-based discovery. Scrubs anything
		// that was seeded at any point but lost its manifest (e.g. DB imported,
		// seed ran outside the wizard, manifest option deleted).
		$counts = Demo_Seeder::cleanup_by_signature( 0, $include_fixtures );

		$total = array_sum( $counts );
		if ( 0 === $total ) {
			\WP_CLI::warning( __( 'No demo data detected by signature. Nothing to remove.', 'learnomy' ) );
			return;
		}

		\WP_CLI::success(
			sprintf(
				/* translators: 1: users, 2: courses, 3: memberships, 4: coupons. */
				__( 'Demo data removed (signature-based): %1$d users, %2$d courses, %3$d memberships, %4$d coupons + cascading rows.', 'learnomy' ),
				$counts['users'],
				$counts['courses'],
				$counts['memberships'],
				$counts['coupons']
			)
		);
	}

	// -------------------------------------------------------------------------
	// Cleanup commands
	// -------------------------------------------------------------------------

	/**
	 * Remove orphaned LMS rows that reference deleted WP users.
	 *
	 * Sub-commands:
	 *
	 *   orphan-certs       — Delete certificate rows whose user_id no longer
	 *                        exists in wp_users.
	 *   orphan-enrollments — Delete enrollment rows whose user_id no longer
	 *                        exists in wp_users.
	 *   orphan-commissions — Delete commission rows whose parent transaction is
	 *                        gone. Engagement splits (transaction_id = 0) settle
	 *                        a period rather than a sale and are never orphans.
	 *
	 * All three clear rows created before the `deleted_user` hook purged a
	 * departing user's LMS data (CF-005 / F-DB-01), and all three are safe to run
	 * on a live site. `wp learnomy test data-integrity` asserts that none of them
	 * exist; only certificates had a remedy, so an install carrying the other two
	 * had a permanently red gate and no supported way to clear it.
	 *
	 * ## OPTIONS
	 *
	 * <task>
	 * : Which cleanup to run: orphan-certs, orphan-enrollments, orphan-commissions.
	 *
	 * [--dry-run]
	 * : Report the count without deleting anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy cleanup orphan-certs
	 *     wp learnomy cleanup orphan-certs --dry-run
	 *     wp learnomy cleanup orphan-enrollments --dry-run
	 *     wp learnomy cleanup orphan-commissions
	 *
	 * @subcommand cleanup
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		$task    = sanitize_text_field( $args[0] ?? '' );
		$dry_run = isset( $assoc_args['dry-run'] );

		$service = new User_Cleanup_Service();

		// Each task pairs the model's orphan lookup with the service's deleter.
		// The lookups own their cross-table JOINs so raw $wpdb stays inside
		// includes/models/.
		$tasks = array(
			'orphan-certs'       => array(
				'find'   => static fn(): array => \Learnomy\Models\Certificate::get_orphan_ids(),
				'delete' => static fn(): int => $service->delete_orphan_certificates(),
				'label'  => __( 'certificate', 'learnomy' ),
			),
			'orphan-enrollments' => array(
				'find'   => static fn(): array => \Learnomy\Models\Enrollment::get_orphan_ids(),
				'delete' => static fn(): int => $service->delete_orphan_enrollments(),
				'label'  => __( 'enrollment', 'learnomy' ),
			),
			'orphan-commissions' => array(
				'find'   => static fn(): array => \Learnomy\Models\Commission::get_orphan_ids(),
				'delete' => static fn(): int => $service->delete_orphan_commissions(),
				'label'  => __( 'commission', 'learnomy' ),
			),
		);

		if ( ! isset( $tasks[ $task ] ) ) {
			\WP_CLI::error(
				sprintf(
					/* translators: 1: unsupported task name, 2: comma-separated list of supported task names */
					__( 'Unknown cleanup task "%1$s". Supported: %2$s.', 'learnomy' ),
					$task,
					implode( ', ', array_keys( $tasks ) )
				)
			);
		}

		$count = count( ( $tasks[ $task ]['find'] )() );
		$label = $tasks[ $task ]['label'];

		if ( 0 === $count ) {
			\WP_CLI::success(
				sprintf(
					/* translators: %s: row type, e.g. "certificate" */
					__( 'No orphaned %s rows found.', 'learnomy' ),
					$label
				)
			);
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::log(
				sprintf(
					/* translators: 1: number of orphan rows found, 2: row type, e.g. "certificate" */
					__( 'Dry run: %1$d orphaned %2$s row(s) would be deleted.', 'learnomy' ),
					$count,
					$label
				)
			);
			return;
		}

		$deleted = ( $tasks[ $task ]['delete'] )();

		\WP_CLI::success(
			sprintf(
				/* translators: 1: number of rows deleted, 2: row type, e.g. "certificate" */
				__( 'Deleted %1$d orphaned %2$s row(s).', 'learnomy' ),
				$deleted,
				$label
			)
		);
	}

	// -------------------------------------------------------------------------
	// Lesson drip cron
	// -------------------------------------------------------------------------

	/**
	 * Run or preview the lesson drip cron synchronously.
	 *
	 * The default subcommand (`process`) executes the same window the hourly
	 * Action Scheduler job would, useful for verifying the wiring without
	 * waiting for the next tick. The `preview` subcommand reports which
	 * lessons would unlock for which students, without firing any hooks.
	 *
	 * ## OPTIONS
	 *
	 * [<subcommand>]
	 * : One of: process, preview. Defaults to process.
	 *
	 * [--course=<id>]
	 * : Limit a `preview` run to a single course. Required for preview.
	 *
	 * [--hours=<int>]
	 * : Window size for `process` in hours. Defaults to 24 for parity with
	 *   the cron's first-run fallback.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy drip process
	 *     wp learnomy drip process --hours=1
	 *     wp learnomy drip preview --course=42
	 *
	 * @subcommand drip
	 */
	public function drip( array $args, array $assoc_args ): void {
		$sub = isset( $args[0] ) ? sanitize_text_field( $args[0] ) : 'process';

		if ( 'preview' === $sub ) {
			$this->drip_preview( $assoc_args );
			return;
		}

		if ( 'process' !== $sub ) {
			\WP_CLI::error(
				/* translators: %s: subcommand name */
				sprintf( __( 'Unknown drip subcommand: %s. Use process or preview.', 'learnomy' ), $sub )
			);
		}

		$hours    = isset( $assoc_args['hours'] ) ? max( 1, (int) $assoc_args['hours'] ) : 24;
		$from_utc = gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) );
		$to_utc   = current_time( 'mysql', true );

		$fired = \Learnomy\Cron\Drip_Cron::process_window( $from_utc, $to_utc );

		\WP_CLI::success(
			sprintf(
				/* translators: 1: number of unlock events fired, 2: window in hours */
				__( 'Drip cron processed. Fired %1$d unlock event(s) across the last %2$d hour(s).', 'learnomy' ),
				(int) $fired,
				$hours
			)
		);
	}

	/**
	 * Dry-run helper for `wp learnomy drip preview --course=<id>`.
	 *
	 * Lists drip-locked lessons in the course (those with a non-null
	 * `available_date` still in the future) along with the count of enrolled
	 * students who would receive an unlock event when the date passes.
	 */
	private function drip_preview( array $assoc_args ): void {
		$course_id = isset( $assoc_args['course'] ) ? (int) $assoc_args['course'] : 0;
		if ( $course_id <= 0 ) {
			\WP_CLI::error( __( 'Provide --course=<id> for preview.', 'learnomy' ) );
		}

		$course = Course::find( $course_id );
		if ( ! $course ) {
			\WP_CLI::error(
				/* translators: %d: course ID */
				sprintf( __( 'Course %d not found.', 'learnomy' ), $course_id )
			);
		}

		$lessons = Lesson::get_drip_scheduled_for_course( $course_id );

		if ( empty( $lessons ) ) {
			\WP_CLI::warning(
				/* translators: %s: course title */
				sprintf( __( 'No drip-scheduled lessons found in "%s".', 'learnomy' ), $course->title )
			);
			return;
		}

		$student_count = Enrollment::count_active_for_course( $course_id );

		$rows = array();
		foreach ( $lessons as $lesson ) {
			$rows[] = array(
				'lesson_id'      => (int) $lesson->id,
				'title'          => (string) $lesson->title,
				'available_date' => (string) $lesson->available_date,
				'students'       => $student_count,
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'lesson_id', 'title', 'available_date', 'students' ) );
		\WP_CLI::log(
			sprintf(
				/* translators: 1: lesson count, 2: student count, 3: course title */
				__( 'Preview: %1$d drip lesson(s) would notify %2$d student(s) in "%3$s" when their date passes.', 'learnomy' ),
				count( $lessons ),
				$student_count,
				$course->title
			)
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Build rows for the courses CSV export.
	 */
	private function build_courses_export(): array {
		$courses = Course::where( [], 'created_at', 'DESC' );
		$rows    = [];

		foreach ( $courses as $course ) {
			$rows[] = [
				'id'         => $course->id,
				'title'      => $course->title,
				'status'     => $course->status,
				'price'      => $course->price,
				'students'   => $course->student_count,
				'rating'     => $course->avg_rating,
				'created_at' => $course->created_at,
			];
		}

		return $rows;
	}

	/**
	 * Build rows for the students CSV export.
	 */
	private function build_students_export(): array {
		$rows   = [];
		$batch  = 2000;
		$offset = 0;

		// Page through the per-student summary so a large roster is exported in
		// bounded batches instead of one unbounded GROUP BY result set
		// (Basecamp #9961949958).
		do {
			$results    = Enrollment::get_student_summary( $batch, $offset );
			$page_count = count( $results );
			foreach ( $results as $row ) {
				$user = get_userdata( (int) $row->user_id );
				if ( ! $user ) {
					continue;
				}
				$rows[] = [
					'user_id'          => $row->user_id,
					'name'             => $user->display_name,
					'email'            => $user->user_email,
					'enrolled_courses' => $row->enrolled_courses,
					'completion_rate'  => $row->completion_rate . '%',
				];
			}
			$offset += $batch;
		} while ( $page_count === $batch );

		return $rows;
	}

	/**
	 * Build rows for the earnings CSV export.
	 */
	private function build_earnings_export(): array {
		$now        = new \DateTime( 'now', new \DateTimeZone( 'UTC' ) );
		$this_month = $now->format( 'Y-m' );
		$last_month = ( clone $now )->modify( 'first day of last month' )->format( 'Y-m' );

		$results = Commission::get_instructor_earnings_export( $this_month, $last_month );

		$rows = [];
		foreach ( $results as $row ) {
			$user = get_userdata( (int) $row->recipient_id );
			$name = $user ? $user->display_name : __( 'Unknown', 'learnomy' );

			$rows[] = [
				'instructor_id' => $row->recipient_id,
				'name'          => $name,
				'this_month'    => number_format( (float) $row->this_month, 2 ),
				'last_month'    => number_format( (float) $row->last_month, 2 ),
				'lifetime'      => number_format( (float) $row->lifetime, 2 ),
			];
		}

		return $rows;
	}

	/**
	 * Render an array of associative rows as a CSV string.
	 *
	 * Thin wrapper over the shared {@see Csv_Formatter} so CLI and REST
	 * share one implementation.
	 *
	 * @param string[] $headers Column names in output order.
	 * @param array    $rows    Associative row arrays.
	 */
	private function to_csv( array $headers, array $rows ): string {
		return \Learnomy\API\Csv_Formatter::to_string( $headers, $rows );
	}
}
