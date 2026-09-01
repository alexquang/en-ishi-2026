<?php
/**
 * Demo data seeder — creates a realistic, premium 2-course LMS dataset for
 * evaluation and testing.
 *
 * Produces a fully-operational academy: 2 courses (paid + membership), 7 users
 * (instructors + students + applicant), a coupon, transactions (with one refund),
 * a live subscription, commission rows, Ed25519-signed certificates, reviews,
 * an unanswered lesson comment, and a pending instructor application.
 *
 * Every row created is tracked in the manifest so cleanup() can reverse the
 * entire seed precisely — no FK cascades, no orphans.
 *
 * @package Learnomy
 */

namespace Learnomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Answer;
use Learnomy\Models\Category;
use Learnomy\Models\Certificate;
use Learnomy\Models\CertificateTemplate;
use Learnomy\Models\Commission;
use Learnomy\Models\CommissionRule;
use Learnomy\Models\Coupon;
use Learnomy\Models\CouponUsage;
use Learnomy\Models\Course;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\Enrollment;
use Learnomy\Models\InstructorApplication;
use Learnomy\Models\Lesson;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\MembershipPlanCourse;
use Learnomy\Models\Progress;
use Learnomy\Models\Question;
use Learnomy\Models\QuestionCategory;
use Learnomy\Models\Webhook;
use Learnomy\Models\Webhook_Event;
use Learnomy\Models\Notification;
use Learnomy\Models\QuestionResponse;
use Learnomy\Models\Quiz;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\QuizSlot;
use Learnomy\Models\Review;
use Learnomy\Models\Section;
use Learnomy\Models\Subscription;
use Learnomy\Models\Withdrawal;
use Learnomy\Models\Tag;
use Learnomy\Models\Transaction;
use Learnomy\Services\Certificate_Service;
use Learnomy\Services\Commission_Service;
use Learnomy\Services\Course_Service;
use Learnomy\Services\Enrollment_Service;
use function Learnomy\now;

class Demo_Seeder {

	/**
	 * Return a MySQL datetime string for "N days ago" with a random hour jitter.
	 *
	 * Used to spread enrollments, purchases, reviews, and progress across the
	 * last ~60 days so admin charts show realistic curves, not a spike.
	 *
	 * @param int $days_ago      How many days in the past.
	 * @param int $hours_jitter  Extra +/- hours to randomise within the day.
	 * @return string MySQL datetime (UTC).
	 */
	private static function demo_date( int $days_ago, int $hours_jitter = 6 ): string {
		$ts      = time() - ( $days_ago * DAY_IN_SECONDS );
		$jitter  = $hours_jitter > 0 ? wp_rand( -1 * $hours_jitter, $hours_jitter ) * HOUR_IN_SECONDS : 0;
		$minutes = wp_rand( 0, 59 ) * MINUTE_IN_SECONDS;
		return gmdate( 'Y-m-d H:i:s', $ts + $jitter + $minutes );
	}

	/**
	 * Return a Picsum URL for a given seed.
	 *
	 * We deliberately store the URL on the course row rather than sideloading
	 * the image so cleanup doesn't have to delete attachments and the wizard
	 * success page renders fast even on first run.
	 *
	 * @param string $seed Stable seed string (e.g. course slug).
	 * @return string Picsum URL.
	 */
	private static function picsum_url( string $seed ): string {
		return sprintf( 'https://picsum.photos/seed/%s/800/450', rawurlencode( $seed ) );
	}

	/**
	 * Return a real, stable YouTube watch URL for a demo lesson.
	 *
	 * We rotate through a small pool of long-established public videos so
	 * Plyr / YouTube embed, oEmbed, and the thumbnail CDN all resolve cleanly
	 * on the lesson player — the previous `LEARNOMY_DEMO_N` placeholder slug
	 * produced 3× ytimg.com 404s plus an "Invalid video id" throw from the
	 * YouTube widget API every time a lesson mounted.
	 *
	 * The pool is classic, long-form, SFW content that YouTube is unlikely
	 * to pull, so seeded demo sites don't rot over time.
	 *
	 * @param int $index Lesson index used to pick a slot in the pool.
	 * @return string Full YouTube watch URL.
	 */
	private static function demo_video_url( int $index ): string {
		$ids  = array(
			// Big Buck Bunny (Blender Foundation) — neutral, professional, evergreen
			// demo clip. Reads as obvious placeholder content, never a meme, and the
			// official upload has been stable for well over a decade.
			'aqz-KE-bpKQ',
		);
		$slug = $ids[ $index % count( $ids ) ];
		return 'https://www.youtube.com/watch?v=' . $slug;
	}

	// ── Public entry points ────────────────────────────────────────────────

	/**
	 * Create the full demo dataset and return the manifest.
	 *
	 * On failure mid-seed we attempt to roll back anything already created so
	 * the site isn't left in a half-populated state.
	 *
	 * @param int $admin_id Current admin user ID, used as fallback author.
	 * @return array Manifest of every created ID, plus `_stats` + `_previews`.
	 */
	public static function seed( int $admin_id ): array {
		$manifest = self::new_manifest();

		try {
			// The demo showcases the full product, including commerce (it seeds
			// membership plans, a subscription, a commission rule, and a coupon).
			// Those modules are opt-in and off by default, and front-of-site
			// commerce routes now 404 when their module is disabled (route gate),
			// so enable the ones the demo populates — otherwise the seeded
			// membership/checkout pages would be unreachable. Idempotent; never
			// disables a module the owner already turned on.
			self::ensure_demo_modules();

			self::seed_users( $manifest );
			self::seed_taxonomy( $manifest );
			self::seed_commission_rule( $manifest );
			self::seed_certificate_template( $manifest );

			$instructors = self::resolve_instructors( $manifest );
			$students    = self::resolve_students( $manifest );

			// Courses.
			$react_preview   = self::seed_react_course( $manifest, $instructors['maya'], $manifest['category_ids']['web'] );
			$mindful_preview = self::seed_mindful_course( $manifest, $instructors['arjun'], $manifest['category_ids']['wellness'] );

			// Capstone: a categorized certification exam that showcases the full
			// quiz engine (multi-subject, grouped, review-board, timer, subject-
			// wise results). Attached to the web-dev course.
			$demo_student = ( is_array( $students ) && ! empty( $students ) ) ? (int) reset( $students ) : 0;
			self::seed_certification_exam( $manifest, (int) ( $react_preview['id'] ?? 0 ), $instructors['maya'], $demo_student );

			// Feature-showcase fixtures for the 1.4.0 docs (closed-pricing course +
			// one quiz per layout over a shared question pool with a rich stem).
			self::seed_feature_showcase( $manifest, $instructors['maya'] );

			// Let Pro extensions seed their own demo fixtures (e.g. a mock LTI
			// platform row) — Pro cannot be written from here (lrn_pro_* tables
			// are off-limits to Free). Pro tracks + tears down its own rows via
			// a recognizable marker and the cascade-cleanup hook.
			do_action( 'learnomy_demo_seed_pro', $manifest['course_ids'] ?? array() );

			// Activity-log admin surfaces (webhooks, webhook events, email log,
			// announcements) so those screens are never empty in a demo.
			self::seed_admin_activity( $manifest, $students );

			// A submitted essay attempt so the Grading inbox is non-empty.
			self::seed_grading_fixture( $manifest, $students );

			// Revenue & membership.
			self::seed_membership_plan( $manifest );
			self::seed_coupon( $manifest );
			self::seed_transactions_and_commissions( $manifest, $students );

			// Student activity.
			self::seed_enrollments_and_progress( $manifest, $students );
			self::seed_reviews( $manifest, $students );
			self::seed_lesson_comment( $manifest, $students );

			// Commerce edge-cases (withdrawal request + a paused subscription) so
			// those admin surfaces are non-empty. After commissions + subs exist.
			self::seed_commerce_edge_cases( $manifest );

			// Applications + wishlist.
			self::seed_instructor_application( $manifest );
			self::seed_wishlist( $manifest, $students );

			// Certificates — issued last so all progress/enrollment rows exist.
			self::issue_certificates( $manifest, $students );

			// Denormalised counters.
			self::refresh_course_counters( $manifest );

			// Response shape used by the wizard success step.
			$manifest['_stats']    = self::build_stats( $manifest );
			$manifest['_previews'] = array( $react_preview, $mindful_preview );

			// Flush rewrite rules so the seeded content is reachable at the URLs
			// the success step is about to link to.
			//
			// ensure_demo_modules() above can ENABLE modules, and a module's
			// routes only enter the rewrite table when the rules are rebuilt —
			// so on a site whose rules predate the seed, /courses/ fell through
			// to the blog index and the owner's first impression of the demo was
			// a 'nothing here' page until someone ran `wp rewrite flush`
			// (Basecamp 10184327445). Expensive, and correctly so: this runs
			// once per seed, which is a deliberate one-off admin action, not a
			// request path.
			flush_rewrite_rules( false );
		} catch ( \Throwable $e ) {
			// Best-effort rollback of partial state.
			self::cleanup( $manifest, $admin_id );
			// Surface the error for the wizard handler.
			$manifest = array(
				'_error' => $e->getMessage(),
			);
		}

		return $manifest;
	}

	/**
	 * Delete every row created by seed(), in reverse dependency order.
	 *
	 * Nothing here relies on FK cascades — every ID is walked explicitly.
	 *
	 * @param array $manifest   Manifest returned by seed().
	 * @param int   $reassign_to User ID to reassign any leftover content to.
	 */
	public static function cleanup( array $manifest, int $reassign_to = 0 ): void {
		global $wpdb;

		// ── Comments ─────────────────────────────────────────────────────
		foreach ( (array) ( $manifest['comment_ids'] ?? array() ) as $id ) {
			wp_delete_comment( (int) $id, true );
		}

		// ── Certificates ────────────────────────────────────────────────
		foreach ( (array) ( $manifest['certificate_ids'] ?? array() ) as $id ) {
			Certificate::delete( (int) $id );
		}

		// ── Reviews ─────────────────────────────────────────────────────
		foreach ( (array) ( $manifest['review_ids'] ?? array() ) as $id ) {
			Review::delete( (int) $id );
		}

		// ── Quiz attempts & responses ──────────────────────────────────
		if ( ! empty( $manifest['question_response_ids'] ) ) {
			foreach ( (array) $manifest['question_response_ids'] as $id ) {
				QuestionResponse::delete( (int) $id );
			}
		}
		foreach ( (array) ( $manifest['quiz_attempt_ids'] ?? array() ) as $id ) {
			QuizAttempt::delete( (int) $id );
		}

		// ── Progress ────────────────────────────────────────────────────
		foreach ( (array) ( $manifest['progress_ids'] ?? array() ) as $id ) {
			Progress::delete( (int) $id );
		}

		// ── Commissions (before transactions so FK lookups stay clean) ─
		foreach ( (array) ( $manifest['commission_row_ids'] ?? array() ) as $id ) {
			Commission::delete( (int) $id );
		}

		// ── Coupon usages ───────────────────────────────────────────────
		foreach ( (array) ( $manifest['coupon_usage_ids'] ?? array() ) as $id ) {
			CouponUsage::delete( (int) $id );
		}

		// ── Transactions (refunds first via parent_id walk — order is safe because we drop by id) ─
		foreach ( (array) ( $manifest['transaction_ids'] ?? array() ) as $id ) {
			Transaction::delete( (int) $id );
		}

		// ── Subscriptions ───────────────────────────────────────────────
		foreach ( (array) ( $manifest['subscription_ids'] ?? array() ) as $id ) {
			Subscription::delete( (int) $id );
		}

		// ── Enrollments ────────────────────────────────────────────────
		foreach ( (array) ( $manifest['enrollment_ids'] ?? array() ) as $id ) {
			Enrollment::delete( (int) $id );
		}

		// ── Quiz tree ──────────────────────────────────────────────────
		foreach ( (array) ( $manifest['quiz_slot_ids'] ?? array() ) as $id ) {
			QuizSlot::delete( (int) $id );
		}
		foreach ( (array) ( $manifest['answer_ids'] ?? array() ) as $id ) {
			Answer::delete( (int) $id );
		}
		foreach ( (array) ( $manifest['question_ids'] ?? array() ) as $id ) {
			Question::delete( (int) $id );
		}
		// Question categories seeded for the certification exam — removed after
		// their questions so nothing is left pointing at a gone category.
		foreach ( (array) ( $manifest['question_category_ids'] ?? array() ) as $id ) {
			QuestionCategory::delete( (int) $id );
		}
		foreach ( (array) ( $manifest['quiz_ids'] ?? array() ) as $id ) {
			Quiz::delete( (int) $id );
		}
		// Activity-log rows seeded for empty-state coverage.
		foreach ( (array) ( $manifest['webhook_ids'] ?? array() ) as $id ) {
			Webhook::delete( (int) $id );
		}
		foreach ( (array) ( $manifest['webhook_event_ids'] ?? array() ) as $id ) {
			Webhook_Event::delete( (int) $id );
		}
		foreach ( (array) ( $manifest['notification_ids'] ?? array() ) as $id ) {
			Notification::delete( (int) $id );
		}
		foreach ( (array) ( $manifest['withdrawal_ids'] ?? array() ) as $id ) {
			Withdrawal::delete( (int) $id );
		}

		// ── Course tree ────────────────────────────────────────────────
		foreach ( (array) ( $manifest['lesson_ids'] ?? array() ) as $id ) {
			Lesson::delete( (int) $id );
		}
		foreach ( (array) ( $manifest['section_ids'] ?? array() ) as $id ) {
			Section::delete( (int) $id );
		}
		// Route the course teardown through Course_Service::delete() so it
		// cascades the seeded enrollments + progress + quiz attempts (and fires
		// learnomy_course_deleted) instead of the bare base-model Course::delete()
		// that silently orphaned every seeded enrollment (Basecamp #10056265330).
		$lrn_course_service = new \Learnomy\Services\Course_Service();
		foreach ( (array) ( $manifest['course_ids'] ?? array() ) as $id ) {
			CourseInstructor::delete_for_course( (int) $id );
			$lrn_course_service->delete( (int) $id );
		}

		// ── Coupons ─────────────────────────────────────────────────────
		foreach ( (array) ( $manifest['coupon_ids'] ?? array() ) as $id ) {
			Coupon::delete( (int) $id );
		}

		// ── Membership plan ────────────────────────────────────────────
		foreach ( (array) ( $manifest['membership_ids'] ?? array() ) as $id ) {
			MembershipPlanCourse::delete_for_plan( (int) $id );
			MembershipPlan::delete( (int) $id );
		}

		// ── Instructor application ─────────────────────────────────────
		foreach ( (array) ( $manifest['instructor_application_ids'] ?? array() ) as $id ) {
			InstructorApplication::delete( (int) $id );
		}

		// ── Taxonomy ───────────────────────────────────────────────────
		foreach ( (array) ( $manifest['tag_ids'] ?? array() ) as $id ) {
			Tag::delete( (int) $id );
		}
		foreach ( (array) ( $manifest['category_ids'] ?? array() ) as $id ) {
			Category::delete( (int) $id );
		}

		// ── Commission rules ───────────────────────────────────────────
		foreach ( (array) ( $manifest['commission_ids'] ?? array() ) as $id ) {
			CommissionRule::delete( (int) $id );
		}

		// ── Certificate template ───────────────────────────────────────
		foreach ( (array) ( $manifest['certificate_template_ids'] ?? array() ) as $id ) {
			CertificateTemplate::delete( (int) $id );
		}

		// ── Wishlist user meta ─────────────────────────────────────────
		foreach ( (array) ( $manifest['wishlist_user_ids'] ?? array() ) as $uid ) {
			delete_user_meta( (int) $uid, 'learnomy_wishlist' );
		}

		// ── Attachments (legacy — unused in the new seeder but preserved) ─
		foreach ( (array) ( $manifest['attachment_ids'] ?? array() ) as $id ) {
			wp_delete_attachment( (int) $id, true );
		}

		// ── Cascade safety net ─────────────────────────────────────────
		// The manifest-based walk above deletes every row we recorded, but a
		// partial seed, a lost manifest, or a row created by a non-tracked
		// code path would leave orphans. Sweep every Learnomy table that keys
		// on user_id or course_id to guarantee the scope is gone. Bounded to
		// the demo's user_ids + course_ids so legitimate data is never touched.
		self::cascade_orphan_sweep(
			array_map( 'intval', (array) ( $manifest['user_ids'] ?? array() ) ),
			array_map( 'intval', (array) ( $manifest['course_ids'] ?? array() ) )
		);

		// ── Demo users — last, reassign any stray content to admin ─────
		//
		// wp_delete_user() lives in wp-admin/includes/user.php, which WordPress
		// does NOT load during a REST request — and Reset demo data reaches here
		// through the REST admin controller. Calling it there is a fatal, which
		// killed the request mid-cleanup: the owner got an EMPTY toast after
		// confirming a destructive action, and the demo data was left neither
		// removed nor recreated.
		//
		// Seeding never hit this because wp_insert_user() is pluggable and always
		// loaded; only the delete half lives behind wp-admin. Same require the
		// other two call sites already do (class-auth-controller.php:1247,
		// class-fixture-builder.php:485).
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$reassign = $reassign_to > 0 ? $reassign_to : 1;
		foreach ( (array) ( $manifest['user_ids'] ?? array() ) as $id ) {
			wp_delete_user( (int) $id, $reassign );
		}
	}

	/**
	 * Delete every Learnomy row that points at the given demo users or
	 * courses, regardless of whether its ID was in the manifest.
	 *
	 * This is the second pass of cleanup — the first pass walks manifest IDs
	 * (which gives us hook-firing model deletes and respects custom logic),
	 * and this pass picks up whatever that missed. Each query is bounded by
	 * an IN() clause against the demo's own user_ids + course_ids, so real
	 * data in adjacent rows is never at risk.
	 *
	 * Pro add-ons can hook `learnomy_demo_seeder_cascade_cleanup` to purge
	 * their own tables in the same scope.
	 *
	 * @param int[] $user_ids   Demo user IDs to purge.
	 * @param int[] $course_ids Demo course IDs to purge.
	 */
	private static function cascade_orphan_sweep( array $user_ids, array $course_ids ): void {
		global $wpdb;

		// F-1: kept here because this is a multi-table bulk sweep using IN()
		// clauses across 13 user-keyed tables and 9 course-keyed tables. The
		// whole operation is driven by dynamic arrays of user/course IDs and
		// must run atomically across tables. Splitting into 22+ individual
		// Model::delete_for_user/course() calls would work but each would issue
		// its own query, losing the bulk IN() efficiency that matters for large
		// demo datasets. The $table identifiers are composed from
		// $wpdb->prefix + a hardcoded short name — not user input.

		$user_ids   = array_values( array_filter( array_map( 'intval', $user_ids ) ) );
		$course_ids = array_values( array_filter( array_map( 'intval', $course_ids ) ) );

		if ( empty( $user_ids ) && empty( $course_ids ) ) {
			return;
		}

		// Tables keyed by user_id (single demo user_ids IN clause).
		$user_tables = array(
			'lrn_enrollments',
			'lrn_progress',
			'lrn_quiz_attempts',
			'lrn_certificates',
			'lrn_reviews',
			'lrn_coupon_usages',
			'lrn_devices',
			'lrn_notifications',
			'lrn_tokens',
			'lrn_instructor_applications',
			'lrn_transactions',
			'lrn_member_subscriptions',
			'lrn_course_instructors',
		);

		if ( ! empty( $user_ids ) ) {
			$user_placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

			// Question responses hang off quiz_attempts, not users directly —
			// purge them by subquery before we drop the attempts.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE r FROM {$wpdb->prefix}lrn_question_responses r
					 INNER JOIN {$wpdb->prefix}lrn_quiz_attempts a ON r.attempt_id = a.id
					 WHERE a.user_id IN ($user_placeholders)",
					...$user_ids
				)
			);

			foreach ( $user_tables as $short ) {
				$table = $wpdb->prefix . $short;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM `{$table}` WHERE user_id IN ($user_placeholders)",
						...$user_ids
					)
				);
			}

			// Instructor-scoped tables use different column names.
			foreach ( array( 'lrn_engagement_monthly', 'lrn_withdrawals' ) as $short ) {
				$table = $wpdb->prefix . $short;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM `{$table}` WHERE instructor_id IN ($user_placeholders)",
						...$user_ids
					)
				);
			}

			// Commissions: only instructor recipients are scoped by user_id
			// (platform rows are scoped via their transaction, deleted above).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}lrn_commissions
					 WHERE recipient_type = 'instructor' AND recipient_id IN ($user_placeholders)",
					...$user_ids
				)
			);
		}

		// Tables keyed by course_id.
		$course_tables = array(
			'lrn_enrollments',
			'lrn_certificates',
			'lrn_reviews',
			'lrn_course_instructors',
			'lrn_sections',
			'lrn_lessons',
			'lrn_quizzes',
			'lrn_transactions',
			'lrn_membership_plan_courses',
		);

		if ( ! empty( $course_ids ) ) {
			$course_placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );

			foreach ( $course_tables as $short ) {
				$table = $wpdb->prefix . $short;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM `{$table}` WHERE course_id IN ($course_placeholders)",
						...$course_ids
					)
				);
			}
		}

		/**
		 * Fires after the core cascade sweep so add-ons can purge their own
		 * tables in the same scope. Receives the demo user_ids and course_ids
		 * so extensions can safely DELETE WHERE user_id/course_id IN (...).
		 *
		 * @param int[] $user_ids   Demo user IDs being cleaned up.
		 * @param int[] $course_ids Demo course IDs being cleaned up.
		 */
		do_action( 'learnomy_demo_seeder_cascade_cleanup', $user_ids, $course_ids );
	}

	/**
	 * Rebuild a minimal manifest by scanning the DB for demo signatures, then
	 * run the standard cleanup() pass. Used when the real manifest option is
	 * missing — typically because a previous cleanup already ran, the option
	 * was deleted by migration, or seed() was called outside the wizard.
	 *
	 * Signatures:
	 *   - Users:      user_login LIKE 'lrn_demo_%'        OR email ends with '@demo.learnomy'
	 *   - Categories: meta_json contains '"demo": true'   (kept minimal — category orphans are harmless)
	 *   - Courses:    slug LIKE 'lrn-demo-%'
	 *   - Membership: name LIKE '%Demo%'                  (kept narrow — only the wizard plan matches)
	 *   - Coupons:    code LIKE 'DEMO%'
	 *
	 * Anything these signatures match is treated as demo-owned and fed
	 * through cleanup() + the cascade sweep. The sweep then purges every
	 * dependent row (enrollments, progress, transactions, commissions,
	 * reviews, certificates, subscriptions, instructor mappings) keyed on
	 * those user_ids + course_ids — regardless of whether it had a manifest
	 * entry.
	 *
	 * @param int $reassign_to User ID to reassign any leftover content to.
	 * @return int[] Deleted entity counts — `['users' => N, 'courses' => M, ...]`.
	 */
	public static function cleanup_by_signature( int $reassign_to = 0, bool $include_test_fixtures = false ): array {
		global $wpdb;

		// F-1: kept here because these are schema-scan lookup queries that use
		// LIKE / REGEXP patterns against wp_users (a WordPress core table, not
		// a Learnomy model) and cross-join lrn_courses ↔ wp_users to find
		// orphaned courses. No Model method covers "find users by login pattern"
		// or "find courses whose author row is gone" — these are site-health
		// scans, not entity lookups. Adding them to a Model would violate the
		// single-responsibility boundary of the Models layer.

		$manifest = self::new_manifest();

		// Demo users — stable signatures from every seeder version we've
		// shipped. Optionally also drop CLI-test fixtures so one command
		// can leave a perfectly clean slate for release testing.
		$user_where   = array();
		$user_where[] = "user_login LIKE 'lrn_demo_%'";          // current wizard seeder
		$user_where[] = "user_email LIKE '%@demo.learnomy'";     // current wizard seeder
		$user_where[] = "user_login LIKE 'demo_%'";              // legacy wizard seeder
		$user_where[] = "user_email LIKE '%@example.test'";      // QA + fixture emails
		if ( $include_test_fixtures ) {
			$user_where[] = "user_login LIKE 'cli-test-%'";      // CLI test runner residue (--no-cleanup)
			$user_where[] = "user_login LIKE 'qatest%'";
			$user_where[] = "user_login LIKE 'sq_%'";
			$user_where[] = "user_login LIKE 'sq%'";
			// Timestamped QA accounts: anything like cycle5_1234567890, instr_123, stud_123.
			$user_where[] = "user_login REGEXP '^(cycle|instr|stud)[0-9]*_[0-9]+$'";
		}
		$user_sql = "SELECT ID FROM {$wpdb->users} WHERE ID != 1 AND (" . implode( ' OR ', $user_where ) . ')';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$user_rows            = $wpdb->get_col( $user_sql );
		$manifest['user_ids'] = array_map( 'intval', (array) $user_rows );

		// Demo courses — cover both current (lrn-demo-*) and legacy (*-demo)
		// patterns. CLI-test courses only when fixtures flag is set.
		$course_where   = array();
		$course_where[] = "c.slug LIKE 'lrn-demo-%'";
		$course_where[] = "c.slug LIKE '%-demo'";
		$course_where[] = "c.slug = 'qa-test-course'";
		if ( $include_test_fixtures ) {
			$course_where[] = "c.slug LIKE 'cli-test-%'";
			// Courses whose author row is gone — always demo leftovers.
			$course_where[] = '(c.author_id > 0 AND u.ID IS NULL)';
		}
		$course_sql = "SELECT c.id FROM {$wpdb->prefix}lrn_courses c
		               LEFT JOIN {$wpdb->users} u ON c.author_id = u.ID
		               WHERE " . implode( ' OR ', $course_where );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$course_rows            = $wpdb->get_col( $course_sql );
		$manifest['course_ids'] = array_map( 'intval', (array) $course_rows );

		// Membership plans tied to the demo wizard.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$membership_rows = $wpdb->get_col(
			// `title`, not `name`: that column does not exist, so this query
			// errored ("Unknown column 'name' in 'where clause'"), returned an
			// empty set, and demo membership plans were never collected for
			// cleanup — surviving to collide on the next seed (Basecamp
			// 10163003101).
			"SELECT id FROM {$wpdb->prefix}lrn_membership_plans
			 WHERE title LIKE '%Demo%' OR slug LIKE 'lrn-demo-%'"
		);
		$manifest['membership_ids'] = array_map( 'intval', (array) $membership_rows );

		// Demo coupons — MANIFEST ONLY, deliberately no signature scan.
		//
		// This used to scan for `code LIKE 'DEMO%'`, which matched nothing ever:
		// the seeders write LAUNCH20, LAUNCH, BLACKFRIDAY30, STUDENT50, SUMMER20
		// and WELCOME10. The bucket was always empty, so no demo coupon was ever
		// collected here.
		//
		// It is NOT fixed by matching those codes instead. Unlike every other demo
		// entity — namespaced `lrn-demo-*` and unmistakable — a coupon code is an
		// ordinary marketing word, and a site owner may genuinely have their own
		// LAUNCH20. A signature scan cannot tell theirs from ours, and deleting on
		// that guess during "Reset demo data" would destroy a live promotion.
		//
		// Coupons the seeder created are recorded in `coupon_ids` at creation
		// (seed_coupon), which is exact, and the seeder now skips a code already
		// in use, so a lost manifest costs a stale demo coupon rather than a
		// duplicate-key error or somebody's real one.
		$manifest['coupon_ids'] = array_values( array_map( 'intval', (array) ( $manifest['coupon_ids'] ?? array() ) ) );

		// Demo taxonomy. The cleanup path already DELETES these buckets — this
		// scan simply never filled them, so on a signature-driven cleanup (the
		// path taken whenever the seed manifest is gone) every demo category,
		// tag and question-category survived and collided with the next seed.
		// That is the ~12 duplicate-key wpdb errors per reseed: noise plus
		// residue, not corruption (Basecamp 10163003101).
		//
		// Two slug conventions are in play because the seeders write both:
		// `lrn-demo-*` for course categories/tags, `demo-*` (and `demo-pmp-*`)
		// for question categories.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$category_rows            = $wpdb->get_col(
			"SELECT id FROM {$wpdb->prefix}lrn_categories
			 WHERE slug LIKE 'lrn-demo-%' OR slug LIKE 'demo-%'"
		);
		$manifest['category_ids'] = array_map( 'intval', (array) $category_rows );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$tag_rows            = $wpdb->get_col(
			"SELECT id FROM {$wpdb->prefix}lrn_tags
			 WHERE slug LIKE 'lrn-demo-%' OR slug LIKE 'demo-%'"
		);
		$manifest['tag_ids'] = array_map( 'intval', (array) $tag_rows );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$question_category_rows            = $wpdb->get_col(
			"SELECT id FROM {$wpdb->prefix}lrn_question_categories
			 WHERE slug LIKE 'lrn-demo-%' OR slug LIKE 'demo-%'"
		);
		$manifest['question_category_ids'] = array_map( 'intval', (array) $question_category_rows );

		$counts = array(
			'users'               => count( $manifest['user_ids'] ),
			'courses'             => count( $manifest['course_ids'] ),
			'memberships'         => count( $manifest['membership_ids'] ),
			'coupons'             => count( $manifest['coupon_ids'] ),
			'categories'          => count( $manifest['category_ids'] ),
			'tags'                => count( $manifest['tag_ids'] ),
			'question_categories' => count( $manifest['question_category_ids'] ),
		);

		// Run the normal cleanup path — it walks the synthesized manifest and
		// then the cascade sweep scrubs every dependent row by user_id/course_id.
		self::cleanup( $manifest, $reassign_to );

		return $counts;
	}

	// ── Manifest bootstrap ─────────────────────────────────────────────────

	/**
	 * Empty manifest with every key this seeder writes to.
	 *
	 * Keys are declared up-front so cleanup() never hits an undefined index
	 * and so a future developer can see at a glance what this seeder tracks.
	 */
	/**
	 * Enable the modules the demo data depends on (membership, commissions,
	 * coupons) on top of whatever is already enabled. Without this, the seeded
	 * commerce content has no reachable front-end because the route gate 404s a
	 * disabled module's pages. Additive + idempotent — re-running and a later
	 * cleanup never strip a module the owner enabled themselves.
	 */
	private static function ensure_demo_modules(): void {
		$enabled = get_option( 'learnomy_modules_enabled', array() );
		if ( ! is_array( $enabled ) || empty( $enabled ) ) {
			$enabled = \Learnomy\Modules\Module_Kernel::defaults();
		}
		$enabled = array_values(
			array_unique(
				array_merge( $enabled, array( 'membership', 'commissions', 'coupons' ) )
			)
		);
		update_option( 'learnomy_modules_enabled', $enabled );
	}

	private static function new_manifest(): array {
		return array(
			'user_ids'                   => array(),
			'category_ids'               => array(),
			'tag_ids'                    => array(),
			'course_ids'                 => array(),
			'course_instructor_pairs'    => array(),
			'section_ids'                => array(),
			'lesson_ids'                 => array(),
			'lesson_map'                 => array(), // course_key => [lesson_id,...]
			'quiz_ids'                   => array(),
			'question_ids'               => array(),
			'question_category_ids'      => array(),
			'answer_ids'                 => array(),
			'quiz_slot_ids'              => array(),
			'quiz_attempt_ids'           => array(),
			'question_response_ids'      => array(),
			'enrollment_ids'             => array(),
			'progress_ids'               => array(),
			'review_ids'                 => array(),
			'membership_ids'             => array(),
			'membership_plan_course_ids' => array(),
			'coupon_ids'                 => array(),
			'coupon_usage_ids'           => array(),
			'transaction_ids'            => array(),
			'subscription_ids'           => array(),
			'commission_ids'             => array(), // commission rule rows
			'commission_row_ids'         => array(), // per-transaction commission rows
			'certificate_ids'            => array(),
			'certificate_template_ids'   => array(),
			'instructor_application_ids' => array(),
			'wishlist_user_ids'          => array(),
			'comment_ids'                => array(),
			'lesson_attachment_ids'      => array(),
			'attachment_ids'             => array(),
		);
	}

	// ── Users ──────────────────────────────────────────────────────────────

	/**
	 * Create 2 instructors + 5 students + 1 pending applicant.
	 *
	 * All logins are prefixed lrn_demo_ and emails end in @demo.learnomy so
	 * they're trivial to identify and never collide with real registrations.
	 */
	private static function seed_users( array &$manifest ): void {
		$users = array(
			array(
				'login'       => 'lrn_demo_maya',
				'email'       => 'maya.chen@demo.learnomy',
				'first_name'  => 'Maya',
				'last_name'   => 'Chen',
				'display'     => 'Maya Chen',
				'role'        => 'lrn_instructor',
				'description' => 'Senior front-end engineer and React teacher. 8 years shipping production UIs at startups and agencies. Loves turning confusing concepts into bite-sized lessons.',
				'expertise'   => 'React, JavaScript, TypeScript, Front-end Architecture',
			),
			array(
				'login'       => 'lrn_demo_arjun',
				'email'       => 'arjun.patel@demo.learnomy',
				'first_name'  => 'Arjun',
				'last_name'   => 'Patel',
				'display'     => 'Arjun Patel',
				'role'        => 'lrn_instructor',
				'description' => 'Productivity coach and former ops lead. Helps knowledge workers build durable focus habits with cognitive-science-backed routines.',
				'expertise'   => 'Productivity, Habit Design, Mindfulness',
			),
			array(
				'login'      => 'lrn_demo_sofia',
				'email'      => 'sofia.reyes@demo.learnomy',
				'first_name' => 'Sofia',
				'last_name'  => 'Reyes',
				'display'    => 'Sofia Reyes',
				'role'       => 'lrn_student',
			),
			array(
				'login'      => 'lrn_demo_liam',
				'email'      => 'liam.okafor@demo.learnomy',
				'first_name' => 'Liam',
				'last_name'  => 'Okafor',
				'display'    => 'Liam Okafor',
				'role'       => 'lrn_student',
			),
			array(
				'login'      => 'lrn_demo_priya',
				'email'      => 'priya.sharma@demo.learnomy',
				'first_name' => 'Priya',
				'last_name'  => 'Sharma',
				'display'    => 'Priya Sharma',
				'role'       => 'lrn_student',
			),
			array(
				'login'      => 'lrn_demo_noah',
				'email'      => 'noah.bennett@demo.learnomy',
				'first_name' => 'Noah',
				'last_name'  => 'Bennett',
				'display'    => 'Noah Bennett',
				'role'       => 'lrn_student',
			),
			array(
				'login'      => 'lrn_demo_emma',
				'email'      => 'emma.lindqvist@demo.learnomy',
				'first_name' => 'Emma',
				'last_name'  => 'Lindqvist',
				'display'    => 'Emma Lindqvist',
				'role'       => 'lrn_student',
			),
			// Extra buyer for coupon transaction.
			array(
				'login'      => 'lrn_demo_wren',
				'email'      => 'wren.kato@demo.learnomy',
				'first_name' => 'Wren',
				'last_name'  => 'Kato',
				'display'    => 'Wren Kato',
				'role'       => 'lrn_student',
			),
			// Pending instructor applicant (subscriber until approved).
			array(
				'login'      => 'lrn_demo_tomas',
				'email'      => 'tomas.novak@demo.learnomy',
				'first_name' => 'Tomas',
				'last_name'  => 'Novak',
				'display'    => 'Tomas Novak',
				'role'       => 'subscriber',
			),
		);

		foreach ( $users as $u ) {
			if ( username_exists( $u['login'] ) ) {
				$existing = get_user_by( 'login', $u['login'] );
				if ( $existing ) {
					$manifest['user_ids'][ $u['login'] ] = (int) $existing->ID;
				}
				continue;
			}

			$user_id = wp_insert_user(
				array(
					'user_login'   => $u['login'],
					'user_email'   => $u['email'],
					'first_name'   => $u['first_name'],
					'last_name'    => $u['last_name'],
					'display_name' => $u['display'],
					'role'         => $u['role'],
					'user_pass'    => wp_generate_password( 16 ),
				)
			);

			if ( is_wp_error( $user_id ) ) {
				continue;
			}

			if ( ! empty( $u['description'] ) ) {
				wp_update_user(
					array(
						'ID'          => $user_id,
						'description' => $u['description'],
					)
				);
			}
			if ( ! empty( $u['expertise'] ) ) {
				update_user_meta( $user_id, 'learnomy_expertise', $u['expertise'] );
			}

			$manifest['user_ids'][ $u['login'] ] = (int) $user_id;
		}
	}

	// ── Taxonomy ───────────────────────────────────────────────────────────

	private static function seed_taxonomy( array &$manifest ): void {
		$categories = array(
			'web'      => array(
				'name' => 'Web Development',
				'slug' => 'lrn-demo-web-development',
			),
			'wellness' => array(
				'name' => 'Productivity & Wellness',
				'slug' => 'lrn-demo-productivity',
			),
		);

		foreach ( $categories as $key => $cat ) {
			$manifest['category_ids'][ $key ] = Category::insert(
				array(
					'name'       => $cat['name'],
					'slug'       => $cat['slug'],
					'type'       => 'course',
					'created_at' => now(),
				)
			);
		}

		$tags = array( 'beginner', 'intermediate', 'featured', 'new-release', 'best-seller' );
		foreach ( $tags as $tag_slug ) {
			$manifest['tag_ids'][ $tag_slug ] = Tag::insert(
				array(
					'name' => ucwords( str_replace( '-', ' ', $tag_slug ) ),
					'slug' => 'lrn-demo-' . $tag_slug,
					'type' => 'course',
				)
			);
		}
	}

	// ── Commission rule + Certificate template ─────────────────────────────

	private static function seed_commission_rule( array &$manifest ): void {
		if ( CommissionRule::get_global() ) {
			return;
		}

		$rule_id = CommissionRule::create(
			array(
				'rule_type'     => 'global',
				'entity_id'     => 0,
				'platform_rate' => 30.00,
			)
		);

		if ( $rule_id ) {
			$manifest['commission_ids'][] = (int) $rule_id;
		}
	}

	private static function seed_certificate_template( array &$manifest ): void {
		// Idempotent: reuse the demo template if a prior seed already made it, so
		// repeated demo installs never pile up duplicate certificate rows (the
		// old seeder created a fresh "Learnomy Default Certificate" every run).
		// Uses the current `elements` schema — the legacy `fields` schema this
		// method used before rendered as a blank canvas in the designer.
		$existing = CertificateTemplate::find_by_design_key( 'demo-default' );
		if ( $existing ) {
			$manifest['certificate_template_ids'][] = (int) $existing->id;
			return;
		}

		$template_id = CertificateTemplate::create(
			array(
				'title'         => 'Demo Certificate',
				'orientation'   => 'landscape',
				'status'        => 'active',
				'author_id'     => self::author_for(),
				'design_json'   => wp_json_encode(
					array(
						'orientation' => 'landscape',
						'background'  => array(
							'color' => '#ffffff',
							'image' => null,
						),
						'elements'    => array(
							array(
								'id'    => 'el_1',
								'type'  => 'text',
								'text'  => 'Certificate of Completion',
								'x'     => 50,
								'y'     => 22,
								'font'  => 'Georgia',
								'size'  => 30,
								'color' => '#1a1a1a',
								'bold'  => false,
								'align' => 'center',
							),
							array(
								'id'    => 'el_2',
								'type'  => 'merge_tag',
								'tag'   => '{student_name}',
								'x'     => 50,
								'y'     => 42,
								'font'  => 'Georgia',
								'size'  => 34,
								'color' => '#1a1a1a',
								'bold'  => true,
								'align' => 'center',
							),
							array(
								'id'    => 'el_3',
								'type'  => 'merge_tag',
								'tag'   => '{course_title}',
								'x'     => 50,
								'y'     => 58,
								'font'  => 'Georgia',
								'size'  => 20,
								'color' => '#333333',
								'bold'  => true,
								'align' => 'center',
							),
							array(
								'id'    => 'el_4',
								'type'  => 'merge_tag',
								'tag'   => '{completion_date}',
								'x'     => 50,
								'y'     => 72,
								'font'  => 'Georgia',
								'size'  => 13,
								'color' => '#666666',
								'bold'  => false,
								'align' => 'center',
							),
						),
					)
				),
				'triggers_json' => CertificateTemplate::encode_meta(
					array(
						'kind'         => 'course_completion',
						'design_key'   => 'demo-default',
						'source'       => 'custom',
						'availability' => CertificateTemplate::AVAILABILITY_ALL,
						'instructors'  => array(),
					)
				),
			)
		);

		if ( $template_id ) {
			$manifest['certificate_template_ids'][] = (int) $template_id;
		}
	}

	// ── Course 1: React Fundamentals (paid) ────────────────────────────────

	/**
	 * Seed the flagship paid course.
	 *
	 * 4 sections × 4 lessons = 16 lessons, mixed video/text with real Editor.js
	 * content blocks. Ends with a 6-question graded quiz at 70% pass mark.
	 *
	 * @return array Preview tuple for the wizard success step.
	 */
	private static function seed_react_course( array &$manifest, int $instructor_id, int $category_id ): array {
		$service   = new Course_Service();
		$slug      = 'lrn-demo-react-fundamentals';
		$thumb_url = self::picsum_url( 'react-fundamentals' );

		$course_id = $service->create(
			array(
				'title'            => 'React Fundamentals: Build Your First 3 Apps',
				'slug'             => $slug,
				'description'      => 'A hands-on introduction to React. You will build three real apps — a counter, a todo list, and a weather widget — while learning components, props, state, and hooks from the ground up.',
				'content'          => 'React is the most widely-used front-end library in the world. This course gets you productive with it fast, by grounding every concept in a small project you can actually ship.',
				'access_type'      => 'paid',
				'price'            => 49.00,
				'category_id'      => $category_id,
				'status'           => 'published',
				'completion_type'  => 'lessons_and_quiz',
				'duration_seconds' => 21600,
				'difficulty'       => 'intermediate',
				'thumbnail_url'    => $thumb_url,
			),
			$instructor_id
		);

		if ( ! $course_id ) {
			return array();
		}

		$manifest['course_ids']['react']       = (int) $course_id;
		$manifest['course_instructor_pairs'][] = array( (int) $course_id, $instructor_id );
		CourseInstructor::update_for_course( (int) $course_id, array( 'user_id' => $instructor_id ) );

		// Mirror the Picsum image onto `thumbnail_url` in case the Course_Service
		// doesn't forward it from create(). The sidebar preview resolves
		// `thumbnail_url` when no intro `video_url` is present.
		Course::update( (int) $course_id, array( 'thumbnail_url' => $thumb_url ) );

		$sections = array(
			array(
				'title'   => 'Getting Started with React',
				'lessons' => array(
					array( 'Why React and What You Will Build', 'video', 'In this opening lesson we set the stage for the whole course. You will learn what React is, why it has become the default way to build modern UIs, and preview the three small apps you will ship by the end: a counter, a todo list, and a weather widget. We also tour the mental model React wants you to adopt — UI as a function of state — and contrast it with jQuery-style DOM manipulation. By the end you will know exactly what to expect across the next 16 lessons and why each one matters.' ),
					array( 'Setting Up Vite + VS Code', 'video', 'We walk through installing Node.js (LTS), scaffolding a fresh project with `npm create vite@latest`, and configuring VS Code with the three extensions that actually pay off: ES7+ React snippets, Prettier, and the official ESLint plugin. You will run your first `npm run dev`, open `localhost:5173`, and make a live-reloading change to prove the loop works. Everything from this point forward runs on this setup.' ),
					array( 'Understanding JSX', 'text', 'JSX is the syntax that lets you write what looks like HTML inside your JavaScript. Under the hood every JSX expression compiles to a `React.createElement()` call. In this lesson we cover the four rules JSX enforces: one root element, camelCase attributes, expressions in curly braces, and self-closing tags. We also unpack three gotchas beginners hit the most — `class` vs `className`, conditional rendering with `&&`, and rendering lists with keys.' ),
					array( 'Your First Component', 'text', 'A component is just a function that returns JSX. In this lesson you refactor a static `<h1>` into a reusable `Welcome` component, pass a `name` prop to it, and render it three times with different values. We also cover the naming rule (PascalCase) that tells React the difference between a component and a plain HTML tag, and we peek at the browser devtools to see how your component appears in the React tree.' ),
				),
			),
			array(
				'title'   => 'Building Your First App — Counter',
				'lessons' => array(
					array( 'Introducing useState', 'video', '`useState` is the hook you will reach for 90% of the time. We build the counter app from scratch: a button that increments, a button that decrements, and a reset button. Along the way we cover why you never mutate state directly, how functional updates protect you from stale closures, and why the initial value you pass is only used on the first render.' ),
					array( 'Event Handlers and the onClick Pattern', 'text', 'Event handlers in React are camelCase props that take functions, not strings. In this lesson we build three variations of a click handler — inline arrow function, named function, and handler factory — and discuss the tradeoffs. You will also learn why you should almost never write `onClick={handler()}` (the classic trap) and how to pass arguments to handlers correctly.' ),
					array( 'Conditional Rendering', 'text', 'Learn the four idioms for showing or hiding JSX: ternary operators, logical AND, early returns, and a helper component. We apply each to the counter: hide the decrement button when the value is zero, show a "MAX" badge at 10, and render a different message when the count is negative. By the end you will have a clear heuristic for which pattern fits each situation.' ),
					array( 'Free Preview: Styling with CSS Modules', 'video', '(Free preview lesson) CSS Modules give you component-scoped styles with zero runtime. We convert the counter to use `Counter.module.css`, cover the naming convention, and discuss how Vite handles the bundling. We also compare CSS Modules to three alternatives — Tailwind, styled-components, and plain CSS — so you can pick what fits your next project. This is the second-last lesson in the section and one of the two free previews for this course.', true ),
				),
			),
			array(
				'title'   => 'State, Effects, and the Todo App',
				'lessons' => array(
					array( 'useState with Arrays and Objects', 'text', 'Managing lists and nested objects in state requires care. In this lesson we build the todo app\'s core — adding, removing, and toggling items — while learning why spread syntax is your best friend and why mutating an existing array in state never triggers a re-render. We also cover the common pitfall of using an index as a key and when it is actually safe.' ),
					array( 'The useEffect Hook', 'video', '`useEffect` lets you run side effects — fetches, subscriptions, DOM mutations — after a render. We use it to persist the todo list to `localStorage` so refreshes keep your items. We cover the three forms of the dependency array, the cleanup function (and why forgetting it causes memory leaks), and the difference between effects that run every render vs once vs when a value changes.' ),
					array( 'Lifting State Up', 'text', 'When two sibling components need the same data, the fix is to move state up to the nearest common parent and pass it down as props. We refactor the todo app so the stats bar ("3 of 5 done") can read from the same source of truth as the list. This is the single most common refactor you will do in a React app — worth internalising early.' ),
					array( 'Building a Controlled Form', 'video', 'Controlled components are inputs whose `value` is driven by state. We build the "add todo" form, handle `onChange`, clear the input on submit, and validate that an empty string can\'t be added. We also discuss when uncontrolled components (via `useRef`) are a better fit — mostly file inputs and integrations with non-React libraries.' ),
				),
			),
			array(
				'title'   => 'Fetching Data and the Weather Widget',
				'lessons' => array(
					array( 'Fetching with fetch() and useEffect', 'text', 'We build the weather widget by pulling live data from `open-meteo.com` — a free, no-API-key weather service perfect for demos. You will learn the standard "fetch in useEffect" pattern, why race conditions happen when the user types fast, and the one-line `AbortController` fix that prevents them.' ),
					array( 'Handling Loading and Error States', 'video', 'A real app has three render states: loading, success, and error. We introduce a small state machine with `useState` to track which is active and render the right UI for each. This is the pattern you will use everywhere — from authenticated dashboards to payment flows — and it is the difference between a toy app and a real one.' ),
					array( 'Extracting a Custom Hook', 'text', 'When two components need the same fetch logic, you extract it into a custom hook. We pull the weather-fetching code out of the component and into `useWeather(city)`. By the end you will understand the rules of hooks (call order, top level only), why hooks must start with `use`, and when writing a custom hook is overkill vs. genuinely useful.' ),
					array( 'Free Preview: Shipping Your Apps', 'video', '(Free preview lesson) We wrap with a 15-minute tour of deploying your three apps to Vercel in one click each, connecting them to a custom domain, and setting up a tiny GitHub Actions pipeline that re-deploys on every push. You will also get a copy of the deployment checklist Maya uses on every client project. This is one of the two free previews for this course.', true ),
				),
			),
		);

		$section_order = 1;
		foreach ( $sections as $section_data ) {
			$section_id                = Section::insert(
				array(
					'course_id'  => (int) $course_id,
					'title'      => $section_data['title'],
					'sort_order' => $section_order++,
					'created_at' => now(),
				)
			);
			$manifest['section_ids'][] = (int) $section_id;

			$lesson_order = 1;
			$total        = count( $section_data['lessons'] );
			foreach ( $section_data['lessons'] as $idx => $lesson ) {
				$title        = $lesson[0];
				$content_type = $lesson[1];
				$body         = $lesson[2];
				$is_preview   = ! empty( $lesson[3] );

				$video_url = null;
				if ( 'video' === $content_type ) {
					// Resolve to a real, long-stable YouTube ID so the lesson
					// player, oEmbed, and ytimg.com all load cleanly.
					$video_url = self::demo_video_url( count( $manifest['lesson_ids'] ) );
				}

				$editor_json = self::build_editor_json( $title, $body, $content_type, count( $manifest['lesson_ids'] ) );
				// Match the canonical store under the cutover (INV-9) so demo data
				// looks exactly like editor-authored content.
				if ( \Learnomy\Content\Content_HTML::storage_enabled() ) {
					$editor_json = \Learnomy\Content\Content_HTML::for_storage( $editor_json );
				}

				$lesson_id = Lesson::create(
					array(
						'course_id'        => (int) $course_id,
						'section_id'       => (int) $section_id,
						'author_id'        => $instructor_id,
						'title'            => $title,
						'slug'             => sanitize_title( $title ) . '-lrn-demo',
						'content'          => $editor_json,
						'content_type'     => $content_type,
						'video_url'        => $video_url,
						'duration_seconds' => 'video' === $content_type ? wp_rand( 480, 900 ) : wp_rand( 360, 600 ),
						'sort_order'       => $lesson_order++,
						'is_preview'       => $is_preview ? 1 : 0,
					)
				);

				$manifest['lesson_ids'][]          = (int) $lesson_id;
				$manifest['lesson_map']['react'][] = (int) $lesson_id;
				unset( $idx, $total );
			}
		}

		// Final exam quiz — 6 questions, 70% pass.
		$quiz_id = self::seed_quiz(
			$manifest,
			(int) $course_id,
			'React Fundamentals — Final Exam',
			'lrn-demo-react-final-exam',
			70,
			array(
				array(
					'text'    => 'Which hook is used to add local state to a functional component?',
					'type'    => 'mc_single',
					'options' => array(
						array( 'useEffect', false ),
						array( 'useState', true ),
						array( 'useMemo', false ),
						array( 'useReducer', false ),
					),
				),
				array(
					'text'    => 'JSX attributes use camelCase (e.g. className, onClick) rather than HTML lowercase.',
					'type'    => 'true_false',
					'options' => array(
						array( 'True', true ),
						array( 'False', false ),
					),
				),
				array(
					'text'    => 'Which prop React uses to identify items when rendering a list, preventing unnecessary re-renders?',
					'type'    => 'short_answer',
					'options' => array(
						array( 'key', true ),
					),
				),
				array(
					'text'    => 'What is the correct way to update state based on the previous value?',
					'type'    => 'mc_single',
					'options' => array(
						array( 'setCount(count + 1)', false ),
						array( 'setCount(prev => prev + 1)', true ),
						array( 'count = count + 1', false ),
						array( 'setCount.update(count + 1)', false ),
					),
				),
				array(
					'text'    => 'useEffect without a dependency array runs after every render.',
					'type'    => 'true_false',
					'options' => array(
						array( 'True', true ),
						array( 'False', false ),
					),
				),
				array(
					'text'    => 'Which hook lets you persist a value across renders without triggering a re-render when it changes?',
					'type'    => 'mc_single',
					'options' => array(
						array( 'useState', false ),
						array( 'useRef', true ),
						array( 'useEffect', false ),
						array( 'useContext', false ),
					),
				),
				// Multiple correct answers — exercises the mc_multi grader.
				array(
					'text'    => 'Which of these are React hooks? (Select all that apply.)',
					'type'    => 'mc_multi',
					'options' => array(
						array( 'useState', true ),
						array( 'useEffect', true ),
						array( 'useFetch', false ),
						array( 'useMemo', true ),
						array( 'componentDidMount', false ),
					),
				),
				// Fill-in-the-blank — one blank, two accepted spellings; the
				// third tuple element is the blank's group_id.
				array(
					'text'    => 'Complete the rule: a list rendered in React needs a unique ____ prop on each item.',
					'type'    => 'fill_blank',
					// The blank lives in `content` as a {{blankN}} token, and each
					// accepted answer's group_id must equal that token — the player
					// renders one input per parsed token and the grader matches on
					// the same key. This previously said group_id '1' with no
					// content at all, which rendered ZERO inputs.
					'content' => 'A list rendered in React needs a unique {{blank1}} prop on each item.',
					'options' => array(
						array( 'key', true, 'blank1' ),
						array( '"key"', true, 'blank1' ),
					),
				),
				// Essay — no answer rows; graded by hand in the queue.
				array(
					'text'    => 'In two or three sentences, explain when you would reach for useRef instead of useState.',
					'type'    => 'essay',
					'options' => array(),
				),
			),
			'Covers components, props, state, and hooks from all three apps you built. Six questions, 70% to pass, 30 minutes. Retakes are allowed, so treat the first attempt as practice if you need to.'
		);

		$manifest['quiz_ids']['react'] = $quiz_id;

		// PRACTICE mode, seeded because the demo previously had none. Every
		// seeded quiz was `graded`, so two of the three modes an owner can
		// choose had zero examples on any install - Practice's whole value
		// (unlimited retakes, does not gate completion) was invisible to
		// someone evaluating the product, and no browser walk could exercise a
		// mode no fixture used (Basecamp 10184407410).
		$manifest['quiz_ids']['react_practice'] = self::seed_quiz(
			$manifest,
			(int) $course_id,
			'React Practice: Hooks Drill',
			'lrn-demo-react-practice-drill',
			0,
			array(
				array(
					'text'    => 'Which hook stores state that survives re-renders?',
					'type'    => 'mc_single',
					'options' => array(
						array( 'useState', true ),
						array( 'useEffect', false ),
						array( 'useRef', false ),
					),
				),
				array(
					'text'    => 'A practice quiz can be retaken as many times as you like.',
					'type'    => 'true_false',
					'options' => array(
						array( 'True', true ),
						array( 'False', false ),
					),
				),
			),
			'Practise as often as you like. This one is not graded and does not count towards finishing the course.',
			array(
				'mode'               => 'practice',
				'time_limit_seconds' => 0,
				'attempt_limit'      => 0,
			)
		);

		// SURVEY mode - collects answers without grading and never gates
		// completion. Same reasoning as the practice quiz above: a mode nobody
		// can see is a mode nobody buys.
		$manifest['quiz_ids']['react_survey'] = self::seed_quiz(
			$manifest,
			(int) $course_id,
			'How was this course?',
			'lrn-demo-react-feedback-survey',
			0,
			array(
				array(
					'text'    => 'How would you rate the pace of this course?',
					'type'    => 'mc_single',
					'options' => array(
						array( 'Too slow', false ),
						array( 'About right', false ),
						array( 'Too fast', false ),
					),
				),
				array(
					'text'    => 'What would you like more of next time?',
					'type'    => 'short_answer',
					'options' => array(),
				),
			),
			'A couple of quick questions. There are no right answers and this is not marked.',
			array(
				'mode'               => 'survey',
				'time_limit_seconds' => 0,
			)
		);

		return array(
			'id'        => (int) $course_id,
			'title'     => 'React Fundamentals: Build Your First 3 Apps',
			'thumbnail' => $thumb_url,
			'price'     => 49.00,
		);
	}

	// ── Course 2: Mindful Productivity (membership-only) ───────────────────

	/**
	 * Seed the membership-only wellness course.
	 *
	 * 4 sections × 3 lessons = 12 lessons, 6-question completion check.
	 */
	private static function seed_mindful_course( array &$manifest, int $instructor_id, int $category_id ): array {
		$service   = new Course_Service();
		$slug      = 'lrn-demo-mindful-productivity';
		$thumb_url = self::picsum_url( 'mindful-productivity' );

		$course_id = $service->create(
			array(
				'title'            => 'Mindful Productivity: A 21-Day Habit Builder',
				'slug'             => $slug,
				'description'      => 'A gentle 21-day programme for knowledge workers who want fewer distractions and more meaningful output. No hustle, no hacks — just the four habits that move the needle.',
				'content'          => 'Included with Academy Pro. Small daily lessons you can finish in under 10 minutes.',
				'access_type'      => 'membership',
				'price'            => 0.00,
				'category_id'      => $category_id,
				'status'           => 'published',
				'completion_type'  => 'lessons_and_quiz',
				'duration_seconds' => 12600,
				'difficulty'       => 'beginner',
				'thumbnail_url'    => $thumb_url,
			),
			$instructor_id
		);

		if ( ! $course_id ) {
			return array();
		}

		$manifest['course_ids']['mindful']     = (int) $course_id;
		$manifest['course_instructor_pairs'][] = array( (int) $course_id, $instructor_id );
		CourseInstructor::update_for_course( (int) $course_id, array( 'user_id' => $instructor_id ) );
		Course::update( (int) $course_id, array( 'thumbnail_url' => $thumb_url ) );

		$sections = array(
			array(
				'title'   => 'Week 1 — Foundations',
				'lessons' => array(
					array( 'Why Habits Beat Willpower', 'text', 'Willpower is a finite resource. Habits are not. In this opening lesson we unpack the science behind automaticity — why behaviours that repeat in stable contexts eventually run on their own — and why designing for habits is the highest-leverage move a knowledge worker can make. You will also get the one-question journal prompt that sets up the rest of the 21 days.' ),
					array( 'The 4-Habit Productivity Stack', 'video', 'Across 20 years of coaching, Arjun has converged on four habits that matter more than the rest combined: deliberate capture, deep work blocks, a two-minute shutdown ritual, and a weekly review. This lesson previews all four and explains why the order matters — each one makes the next one easier.' ),
					array( 'Setting Your 21-Day Goal', 'text', 'You will spend the next three weeks practising one concrete behaviour. This lesson walks you through the worksheet for picking it: start small enough to never miss a day, make it observable, and anchor it to a cue that already exists in your routine. Example goals are included so you can pattern-match rather than invent from scratch.' ),
				),
			),
			array(
				'title'   => 'Week 2 — The Capture Habit',
				'lessons' => array(
					array( 'Your Second Brain in 10 Minutes', 'video', 'A working memory for knowledge workers fits in the palm of your hand. In this lesson we set up a minimal capture system (one notebook, one inbox) and cover the four rules that keep it alive: capture everything, review daily, process to zero weekly, and never store actionable items in your head.' ),
					array( 'Taming the Distraction Loop', 'text', 'Every notification is a small withdrawal from your attention budget. We do a 10-minute audit of your notifications across phone, laptop, and watch; switch off the ones that do not earn their place; and configure a single daily "triage window" for the rest. Expect your mornings to feel quieter within a week.' ),
					array( 'Free Preview: The Two-Minute Rule', 'text', '(Free preview lesson) If it takes less than two minutes, do it now. We break down David Allen\'s classic rule, show where it pays off (email, slack triage, filing), and more importantly where it backfires (anything that needs thinking). By the end you will have a simple heuristic for when to act and when to capture.', true ),
				),
			),
			array(
				'title'   => 'Week 3 — Deep Work Blocks',
				'lessons' => array(
					array( 'Designing Your 90-Minute Block', 'video', 'Your brain runs on 90-minute ultradian cycles. In this lesson we design a single deep-work block that fits yours: pick the time, pick the outcome, pick the environment, and pick the shutdown cue. The worksheet gives you a fill-in-the-blanks template to try tomorrow.' ),
					array( 'Handling Interruptions Gracefully', 'text', 'You will be interrupted — it is a meeting culture, not an office culture. This lesson teaches you the "parking lot" technique for handling mid-block interruptions without losing your train of thought, plus the two-sentence script that gets colleagues to hold a thought until after your block ends.' ),
					array( 'Measuring What Moved', 'text', 'At the end of every deep-work block, spend 90 seconds logging what actually moved forward. Over a month this log becomes the single best input to your self-review. We cover the 5-line format Arjun uses with coaching clients and how to tell signal from noise.' ),
				),
			),
			array(
				'title'   => 'Week 4 — Weekly Review Ritual',
				'lessons' => array(
					array( 'The 45-Minute Review', 'video', 'A weekly review is the lever that makes every other habit stick. We walk through the three-pass structure (clear, review, plan) and spend time on the one section most people skip: honestly reviewing what did not happen and deciding whether to reschedule or delete it.' ),
					array( 'Free Preview: Planning the Week Ahead', 'text', '(Free preview lesson) Once you know what happened, you plan what matters next. This lesson covers theme-based weeks (one theme per day), the "big three" framework (what are the three wins that would make this week great), and a 10-minute Friday-afternoon planning slot that means Monday starts calmly.', true ),
					array( 'Making the Habits Stick Past Day 21', 'text', 'The last lesson. We cover the three mistakes that kill new habits between day 22 and day 60 — drift, stack overload, and identity conflict — and the small adjustments that prevent each. You will also get the 90-day follow-up template so you can revisit this programme next quarter.' ),
				),
			),
		);

		$section_order = 1;
		foreach ( $sections as $section_data ) {
			$section_id                = Section::insert(
				array(
					'course_id'  => (int) $course_id,
					'title'      => $section_data['title'],
					'sort_order' => $section_order++,
					'created_at' => now(),
				)
			);
			$manifest['section_ids'][] = (int) $section_id;

			$lesson_order = 1;
			foreach ( $section_data['lessons'] as $lesson ) {
				$title        = $lesson[0];
				$content_type = $lesson[1];
				$body         = $lesson[2];
				$is_preview   = ! empty( $lesson[3] );

				$video_url = null;
				if ( 'video' === $content_type ) {
					$video_url = self::demo_video_url( count( $manifest['lesson_ids'] ) );
				}

				$editor_json = self::build_editor_json( $title, $body, $content_type, count( $manifest['lesson_ids'] ) );
				// Match the canonical store under the cutover (INV-9) so demo data
				// looks exactly like editor-authored content.
				if ( \Learnomy\Content\Content_HTML::storage_enabled() ) {
					$editor_json = \Learnomy\Content\Content_HTML::for_storage( $editor_json );
				}

				$lesson_id = Lesson::create(
					array(
						'course_id'        => (int) $course_id,
						'section_id'       => (int) $section_id,
						'author_id'        => $instructor_id,
						'title'            => $title,
						'slug'             => sanitize_title( $title ) . '-lrn-demo',
						'content'          => $editor_json,
						'content_type'     => $content_type,
						'video_url'        => $video_url,
						'duration_seconds' => 'video' === $content_type ? wp_rand( 420, 720 ) : wp_rand( 300, 540 ),
						'sort_order'       => $lesson_order++,
						'is_preview'       => $is_preview ? 1 : 0,
					)
				);

				$manifest['lesson_ids'][]            = (int) $lesson_id;
				$manifest['lesson_map']['mindful'][] = (int) $lesson_id;
			}
		}

		$quiz_id = self::seed_quiz(
			$manifest,
			(int) $course_id,
			'Mindful Productivity — Completion Check',
			'lrn-demo-mindful-completion-check',
			70,
			array(
				array(
					'text'    => 'Which four habits form the core of the productivity stack taught in this course?',
					'type'    => 'mc_single',
					'options' => array(
						array( 'Capture, deep work, shutdown ritual, weekly review', true ),
						array( 'Cold plunges, affirmations, journaling, fasting', false ),
						array( 'Time-blocking, Pomodoro, inbox zero, standing desk', false ),
						array( 'Planning, execution, delegation, automation', false ),
					),
				),
				array(
					'text'    => 'Willpower is a more reliable driver of long-term behaviour change than habit design.',
					'type'    => 'true_false',
					'options' => array(
						array( 'True', false ),
						array( 'False', true ),
					),
				),
				array(
					'text'    => 'What is the recommended length for a single focused deep-work block, based on ultradian rhythm research?',
					'type'    => 'mc_single',
					'options' => array(
						array( '15 minutes', false ),
						array( '45 minutes', false ),
						array( '90 minutes', true ),
						array( '3 hours', false ),
					),
				),
				array(
					'text'    => 'Name the two-minute rule in your own words — what should you do with tasks under that threshold?',
					'type'    => 'short_answer',
					'options' => array(
						array( 'do it now', true ),
					),
				),
				array(
					'text'    => 'A weekly review should include a pass of "what did not happen" and an explicit decision to reschedule or delete it.',
					'type'    => 'true_false',
					'options' => array(
						array( 'True', true ),
						array( 'False', false ),
					),
				),
				array(
					'text'    => 'Roughly how long does the course suggest a full weekly review should take?',
					'type'    => 'mc_single',
					'options' => array(
						array( '10 minutes', false ),
						array( '45 minutes', true ),
						array( '2 hours', false ),
						array( 'Half a day', false ),
					),
				),
			),
			'A short check on the four focus habits from the programme. Six questions on what you practised, no trick answers. Take it once you have finished the final week.'
		);

		$manifest['quiz_ids']['mindful'] = $quiz_id;

		return array(
			'id'        => (int) $course_id,
			'title'     => 'Mindful Productivity: A 21-Day Habit Builder',
			'thumbnail' => $thumb_url,
			'price'     => 0.00,
		);
	}

	// ── Quiz helper ────────────────────────────────────────────────────────

	/**
	 * Create a quiz with questions, answers, and slots. Handles mc_single,
	 * true_false, and short_answer types (the three used by this seeder).
	 */
	/**
	 * Who owns a seeded row.
	 *
	 * The seeder stamped `get_current_user_id()`, which is 0 whenever it runs
	 * without a logged-in user — WP-CLI, Action Scheduler, a provisioning
	 * script. That is how the demo site ended up with course exams and 354
	 * question-bank rows owned by nobody: the wp-admin lists scope by author,
	 * so an instructor opened the Question Bank and found it empty, and their
	 * own course's final exam missing from Quizzes (Basecamp 10184327422).
	 *
	 * A seeded exam's owner is not "whoever pressed Seed" — it is the
	 * instructor teaching the course it belongs to, which is knowable from the
	 * data and identical in every execution context. Only when there is no
	 * course (a site-level asset) does the acting user become the best answer,
	 * and an administrator is the last resort so the column is never 0.
	 *
	 * @param int $course_id Course the row belongs to, or 0 for site-level rows.
	 */
	private static function author_for( int $course_id = 0 ): int {
		if ( $course_id > 0 ) {
			$course = Course::find( $course_id );
			if ( $course && (int) ( $course->author_id ?? 0 ) > 0 ) {
				return (int) $course->author_id;
			}
		}

		$current = get_current_user_id();
		if ( $current > 0 ) {
			return $current;
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		return ! empty( $admins ) ? (int) $admins[0] : 0;
	}

	/**
	 * @param array<string,mixed> $overrides Column overrides merged over the
	 *        defaults - `mode`, `display_template`, `group_by_category` etc.
	 *        Exists so the demo can SHOW the modes the product supports: every
	 *        seeded quiz was `graded`, so Practice and Survey had zero examples
	 *        on any install and an owner evaluating the product could not see
	 *        what either does (Basecamp 10184407410).
	 */
	private static function seed_quiz( array &$manifest, int $course_id, string $title, string $slug, int $passing_score, array $questions, string $description = '', array $overrides = array() ): int {
		// Exams shuffle questions + answers (anti-cheat) so the demo exercises
		// that setting end-to-end; completion checks keep the authored order.
		// Slug-driven so no call site changes.
		$is_exam = ( false !== strpos( $slug, 'exam' ) || false !== strpos( $slug, 'final' ) );

		$quiz_id = Quiz::insert(
			array_merge(
				array(
					'course_id'          => $course_id,
					'title'              => $title,
					'slug'               => $slug,
					// The start screen leads with this; a blank one opens the quiz
					// on an empty page (Basecamp 10171508719, data half).
					'description'        => $description,
					'mode'               => 'graded',
					'passing_score'      => $passing_score,
					'time_limit_seconds' => 1800,
					'shuffle_questions'  => $is_exam ? 1 : 0,
					'shuffle_answers'    => $is_exam ? 1 : 0,
					'author_id'          => self::author_for( $course_id ),
					'status'             => 'published',
					'created_at'         => now(),
					'updated_at'         => now(),
				),
				$overrides
			)
		);

		$manifest['quiz_ids'][ 'raw_' . $course_id ] = (int) $quiz_id;

		$slot_order = 1;
		foreach ( $questions as $q_data ) {
			$q_type = $q_data['type'] ?? 'mc_single';

			/*
			 * `content` carries the {{blankN}} tokens a fill_blank question needs.
			 *
			 * The seeder only ever wrote `title`, so the demo fill-in-the-blank
			 * question shipped with `content` NULL and its blank typed as literal
			 * underscores in the title. Nothing parses those:
			 * Quizzes_Controller::parse_blank_tokens() regexes `content` for
			 * {{blankN}} and returns [] when there are none, and the player
			 * renders one input per returned key. No keys, no inputs — the
			 * question was UNANSWERABLE, and the pre-submit guard then reported
			 * "1 unanswered" for a question nobody could answer, costing the mark
			 * (Basecamp 10171508719).
			 *
			 * A new owner evaluating the product met this in the first exam they
			 * opened, and it was the only fill_blank question in the demo, so the
			 * type had no working example anywhere.
			 */
			$question_id                = Question::insert(
				array(
					'title'      => $q_data['text'],
					'content'    => $q_data['content'] ?? null,
					'type'       => $q_type,
					'difficulty' => 3,
					'version'    => 1,
					'author_id'  => self::author_for( $course_id ),
					'created_at' => now(),
					'updated_at' => now(),
				)
			);
			$manifest['question_ids'][] = (int) $question_id;

			// Essay questions are graded by hand — they carry no answer rows,
			// so an empty options list is valid and we simply skip the loop.
			$answer_order = 1;
			foreach ( (array) ( $q_data['options'] ?? array() ) as $option ) {
				$answer = array(
					'question_id' => (int) $question_id,
					'content'     => $option[0],
					'is_correct'  => $option[1] ? 1 : 0,
					'fraction'    => $option[1] ? 1.0000 : 0.0000,
					'sort_order'  => $answer_order++,
				);
				// Third tuple element = group_id. The grader groups fill_blank
				// answers by blank id (group_id) and reads short-answer
				// case-sensitivity from a `cs:1` prefix on the same column.
				if ( isset( $option[2] ) ) {
					$answer['group_id'] = (string) $option[2];
				}
				$answer_id                = Answer::insert( $answer );
				$manifest['answer_ids'][] = (int) $answer_id;
			}

			$slot_id                     = QuizSlot::insert(
				array(
					'quiz_id'     => (int) $quiz_id,
					'question_id' => (int) $question_id,
					'sort_order'  => $slot_order++,
					'marks'       => 1,
				)
			);
			$manifest['quiz_slot_ids'][] = (int) $slot_id;
		}

		return (int) $quiz_id;
	}

	/**
	 * Seed a categorized certification exam so the demo tells the full quiz
	 * story in one place: a multi-SUBJECT exam (each subject is a question
	 * category), grouped display, the review-board navigator with one-click
	 * jump, a timer + answer shuffle, and a pre-graded attempt whose results
	 * reveal the SUBJECT-WISE score breakdown. Attached to the web-dev course as
	 * its capstone certification — so an evaluator sees exactly what the quiz
	 * engine can do without hand-building anything.
	 *
	 * @param array<string,mixed> $manifest      Seed manifest (by ref).
	 * @param int                 $course_id     Course the exam belongs to.
	 * @param int                 $instructor_id Author.
	 * @param int                 $student_id    Student to seed a graded sample attempt for (0 = skip).
	 */
	private static function seed_certification_exam( array &$manifest, int $course_id, int $instructor_id, int $student_id ): void {
		if ( ! $course_id ) {
			return;
		}

		// Four subjects × five questions. Each inner row is [ prompt, [ [option, is_correct], ... ] ].
		$subjects = array(
			'JavaScript'       => array(
				array( 'What does the === operator compare?', array( array( 'Value and type', true ), array( 'Value only', false ), array( 'Reference only', false ) ) ),
				array( 'Which keyword declares a block-scoped variable?', array( array( 'let', true ), array( 'var', false ), array( 'global', false ) ) ),
				array( 'Array.prototype.map() returns…', array( array( 'A new array', true ), array( 'The array mutated in place', false ), array( 'undefined', false ) ) ),
				array( 'typeof null evaluates to…', array( array( '"object"', true ), array( '"null"', false ), array( '"undefined"', false ) ) ),
				array( 'A Promise settles as either resolved or…', array( array( 'Rejected', true ), array( 'Cancelled', false ), array( 'Paused', false ) ) ),
			),
			'React'            => array(
				array( 'A component must return a single root node or a…', array( array( 'Fragment', true ), array( 'Loop', false ), array( 'Promise', false ) ) ),
				array( 'Which hook holds local component state?', array( array( 'useState', true ), array( 'useEffect', false ), array( 'useMemo', false ) ) ),
				array( 'Props are…', array( array( 'Read-only inputs', true ), array( 'Mutable state', false ), array( 'Global variables', false ) ) ),
				array( 'Why do list items need a stable key?', array( array( 'So React can track identity', true ), array( 'To style them', false ), array( 'To sort them', false ) ) ),
				array( 'useEffect runs by default…', array( array( 'After every render', true ), array( 'Before the first render', false ), array( 'Never', false ) ) ),
			),
			'HTML & CSS'       => array(
				array( 'Which element is semantic for site navigation?', array( array( '<nav>', true ), array( '<div>', false ), array( '<span>', false ) ) ),
				array( 'In flexbox, the main axis is set by…', array( array( 'flex-direction', true ), array( 'justify-items', false ), array( 'align-content', false ) ) ),
				array( 'The rem unit is relative to…', array( array( 'The root font size', true ), array( 'The parent font size', false ), array( 'The viewport width', false ) ) ),
				array( 'Which selector has the highest specificity?', array( array( 'An ID (#id)', true ), array( 'A class (.class)', false ), array( 'An element (div)', false ) ) ),
				array( 'The alt attribute on <img> mainly provides…', array( array( 'Accessibility and a text fallback', true ), array( 'Styling', false ), array( 'Caching', false ) ) ),
			),
			'Web Fundamentals' => array(
				array( 'HTTP status 404 means…', array( array( 'Not Found', true ), array( 'Server Error', false ), array( 'Unauthorized', false ) ) ),
				array( 'Which HTTP method is idempotent?', array( array( 'GET', true ), array( 'POST', false ), array( 'PATCH', false ) ) ),
				array( 'HTTPS primarily adds…', array( array( 'Encryption in transit', true ), array( 'Faster DNS', false ), array( 'Larger cookies', false ) ) ),
				array( 'A CDN primarily improves…', array( array( 'Latency and load time', true ), array( 'Database size', false ), array( 'Code quality', false ) ) ),
				array( 'CORS controls…', array( array( 'Cross-origin requests', true ), array( 'CSS ordering', false ), array( 'Cookie expiry', false ) ) ),
			),
		);

		$exam_id = Quiz::insert(
			array(
				'course_id'          => $course_id,
				'title'              => 'Web Development Certification Exam',
				'slug'               => 'web-dev-certification-exam',
				'description'        => 'The certification exam for the Web Development track. Questions are grouped by subject and shuffled per attempt, and your results include subject-wise scores so you can see where to revise. 60 minutes, 70% to pass.',
				'mode'               => 'graded',
				'passing_score'      => 70,
				'time_limit_seconds' => 3600,
				'shuffle_questions'  => 1,
				'shuffle_answers'    => 1,
				'group_by_category'  => 1,
				'display_template'   => 'review-board',
				'author_id'          => $instructor_id,
				'status'             => 'published',
				'created_at'         => now(),
				'updated_at'         => now(),
			)
		);
		if ( ! $exam_id ) {
			return;
		}
		$manifest['quiz_ids']['certification_exam'] = (int) $exam_id;

		$sort = 1;
		foreach ( $subjects as $subject => $questions ) {
			$cat_id                              = QuestionCategory::create(
				array(
					'name'       => $subject,
					'slug'       => sanitize_title( 'demo-' . $subject ),
					'sort_order' => $sort,
				)
			);
			$manifest['question_category_ids'][] = (int) $cat_id;

			foreach ( $questions as $q ) {
				$qid                        = Question::insert(
					array(
						'title'       => $q[0],
						'type'        => 'mc_single',
						'category_id' => (int) $cat_id,
						'difficulty'  => 3,
						'version'     => 1,
						'author_id'   => $instructor_id,
						'created_at'  => now(),
						'updated_at'  => now(),
					)
				);
				$manifest['question_ids'][] = (int) $qid;

				$aorder = 1;
				foreach ( $q[1] as $opt ) {
					$aid                      = Answer::insert(
						array(
							'question_id' => (int) $qid,
							'content'     => $opt[0],
							'is_correct'  => $opt[1] ? 1 : 0,
							'fraction'    => $opt[1] ? 1.0000 : 0.0000,
							'sort_order'  => $aorder++,
						)
					);
					$manifest['answer_ids'][] = (int) $aid;
				}

				$slot_id                     = QuizSlot::insert(
					array(
						'quiz_id'     => (int) $exam_id,
						'question_id' => (int) $qid,
						'sort_order'  => $sort++,
						'marks'       => 1,
					)
				);
				$manifest['quiz_slot_ids'][] = (int) $slot_id;
			}
		}

		// A pre-graded sample attempt so the results screen shows the subject-wise
		// score breakdown immediately, without anyone having to sit the exam.
		if ( $student_id ) {
			self::seed_quiz_attempt( $manifest, $student_id, (int) $exam_id, 76.0, true, 3 );
		}
	}

	/**
	 * Build an Editor.js document for a lesson body. Every lesson stacks a
	 * header, an intro paragraph, an illustrative image, a takeaways list, and
	 * then either an embedded video (video lessons) or a code block (text
	 * lessons). The image + embedded video are what give the demo lessons their
	 * "stacked media" look in the player — the lesson's own `video_url` drives
	 * the top player, and the in-body embed shows a second, contextual clip.
	 *
	 * @param string $title        Lesson title, used as the header block.
	 * @param string $body         Intro paragraph text.
	 * @param string $content_type Lesson content type (video|text|pdf).
	 * @param int    $media_index  Stable index so the picsum image and the embedded
	 *                             video id stay deterministic across re-seeds.
	 * @return string Editor.js document JSON.
	 */
	private static function build_editor_json( string $title, string $body, string $content_type, int $media_index = 0 ): string {
		$blocks = array(
			array(
				'type' => 'header',
				'data' => array(
					'text'  => $title,
					'level' => 2,
				),
			),
			array(
				'type' => 'paragraph',
				'data' => array(
					'text' => $body,
				),
			),
			// Illustrative image — stacked into every lesson body.
			array(
				'type' => 'image',
				'data' => array(
					'file'    => array(
						'url' => self::picsum_url( 'lesson-' . $media_index . '-' . sanitize_title( $title ) ),
					),
					'caption' => $title,
				),
			),
			array(
				'type' => 'list',
				'data' => array(
					'style' => 'unordered',
					'items' => array(
						'What you will learn in this lesson',
						'Hands-on exercise you can try in under 10 minutes',
						'One common pitfall to avoid',
					),
				),
			),
		);

		if ( 'video' === $content_type ) {
			// Stack a second, in-body video so video lessons show both the
			// top player and an embedded clip. The renderer's `embed` case
			// needs the iframe-ready /embed/ URL (watch URLs are refused in an
			// iframe by YouTube's X-Frame-Options).
			$watch    = self::demo_video_url( $media_index + 1 );
			$video_id = (string) ( wp_parse_url( $watch, PHP_URL_QUERY ) ? substr( strstr( $watch, 'v=' ), 2 ) : '' );
			$blocks[] = array(
				'type' => 'embed',
				'data' => array(
					'service' => 'youtube',
					'source'  => $watch,
					'embed'   => 'https://www.youtube.com/embed/' . $video_id,
					'caption' => 'Watch: ' . $title,
				),
			);
		} else {
			$blocks[] = array(
				'type' => 'code',
				'data' => array(
					'code' => "// Example snippet\nconst demo = () => 'ready';\ndemo();",
				),
			);
		}

		return (string) wp_json_encode(
			array(
				'time'    => time() * 1000,
				'blocks'  => $blocks,
				'version' => '2.29.0',
			)
		);
	}

	// ── Feature showcase (1.4.0 docs seed data) ────────────────────────────

	/**
	 * Seed the demonstrable data the 1.4.0 feature docs need for their first-view
	 * screenshots. Covers the DB-seedable SEED specs: a Closed-pricing course
	 * (sell-externally model + CTA), and a quiz-format showcase course carrying
	 * one quiz per layout (sectioned, conversational, large focused exam) over a
	 * shared question pool with a rich-stem question. External-artifact fixtures
	 * (SCORM zip, QTI xml, H5P content, xAPI LRS, LTI platform) are handled
	 * separately since they need bundled sample packages.
	 *
	 * @param array<string,mixed> $manifest      Seed manifest (by ref).
	 * @param int                 $instructor_id Author.
	 */
	private static function seed_feature_showcase( array &$manifest, int $instructor_id ): void {
		$service    = new Course_Service();
		$web_cat_id = $manifest['category_ids']['web'] ?? 0;

		// 1. Closed-pricing course — sold on an external platform, access granted
		// here by webhook / CSV. Drives the closed-pricing, bulk-enrollment and
		// incoming-webhook docs (buy surfaces suppressed, CTA shown instead).
		$closed_id = $service->create(
			array(
				'title'           => 'Corporate Compliance Certification (Enterprise)',
				'slug'            => 'lrn-demo-closed-compliance',
				'description'     => 'A compliance certification fulfilled through the company procurement portal. There is no public checkout on this site: employees are granted access automatically after purchase, via a signed enrollment webhook or a bulk CSV upload.',
				'content'         => 'This course uses the Closed pricing model. It is not sold on-site; an external system grants access.',
				'access_type'     => 'closed',
				'pricing_model'   => 'closed',
				'category_id'     => $web_cat_id,
				'status'          => 'published',
				'completion_type' => 'all_lessons',
				'thumbnail_url'   => self::picsum_url( 'closed-compliance' ),
			),
			$instructor_id
		);
		if ( $closed_id ) {
			$manifest['course_ids']['closed']      = (int) $closed_id;
			$manifest['course_instructor_pairs'][] = array( (int) $closed_id, $instructor_id );
			CourseInstructor::update_for_course( (int) $closed_id, array( 'user_id' => $instructor_id ) );
			Course::update_settings(
				(int) $closed_id,
				array(
					'closed_cta_label' => 'Request Access',
					'closed_cta_url'   => 'https://portal.example.com/request-access',
				)
			);
			self::seed_showcase_lessons(
				$manifest,
				(int) $closed_id,
				$instructor_id,
				array(
					array( 'Why this certification matters', 'An overview of the compliance programme and how access is granted through your employer.' ),
					array( 'Core policies and procedures', 'The policies every employee must read and acknowledge before the assessment.' ),
					array( 'Handling data responsibly', 'Practical rules for data handling, incident reporting, and escalation.' ),
					array( 'Assessment and your certificate', 'How the final assessment works and how to download your certificate of completion.' ),
				)
			);
		}

		// 2. Quiz-format showcase — one course, one shared question pool, three
		// quizzes each on a different layout, so the layout / grouping /
		// large-exam docs all have live data to screenshot.
		self::seed_quiz_format_showcase( $manifest, $instructor_id, $web_cat_id );
	}

	/**
	 * A course carrying a shared question pool and one quiz per layout.
	 *
	 * @param array<string,mixed> $manifest      Seed manifest (by ref).
	 * @param int                 $instructor_id Author.
	 * @param int                 $category_id   Course category.
	 */
	private static function seed_quiz_format_showcase( array &$manifest, int $instructor_id, int $category_id ): void {
		$course_id = ( new Course_Service() )->create(
			array(
				'title'           => 'Project Management (PMP) Exam Prep',
				'slug'            => 'lrn-demo-cert-prep',
				'description'     => 'Practice for the Project Management Professional exam across planning, execution, and risk. Includes a sectioned mock exam, a conversational knowledge check, and a large timed exam with a question map and subject-wise scoring.',
				'content'         => 'Work through each quiz format and see subject-by-subject where you stand.',
				'access_type'     => 'free',
				'category_id'     => $category_id,
				'status'          => 'published',
				'completion_type' => 'lessons_and_quiz',
				'thumbnail_url'   => self::picsum_url( 'cert-prep' ),
			),
			$instructor_id
		);
		if ( ! $course_id ) {
			return;
		}
		$manifest['course_ids']['cert_prep']   = (int) $course_id;
		$manifest['course_instructor_pairs'][] = array( (int) $course_id, $instructor_id );
		CourseInstructor::update_for_course( (int) $course_id, array( 'user_id' => $instructor_id ) );
		self::seed_showcase_lessons(
			$manifest,
			(int) $course_id,
			$instructor_id,
			array(
				array( 'How the PMP exam is structured', 'The domains, question count, and timing you can expect on exam day.' ),
				array( 'Planning: scope, schedule, and cost', 'The core planning knowledge area and the artefacts it produces.' ),
				array( 'Execution and monitoring', 'Running the work and keeping it on track with earned value and change control.' ),
				array( 'Risk and stakeholder management', 'Identifying risks, planning responses, and keeping stakeholders engaged.' ),
			)
		);

		// Realistic PMP-style question bank across 3 subjects, so every quiz-layout
		// screenshot shows genuine exam content (not placeholders). Sectioned then
		// shows multiple sections, group-by-category shows 3 subject groups, and one
		// scenario question carries a rich stem for the rich-question-stems doc.
		// Each row: [ prompt (STEM:: prefix = scenario stem), [ [option, is_correct], ... ] ].
		$bank = array(
			'Project Planning'       => array(
				array( 'A work breakdown structure (WBS) decomposes a project into what?', array( array( 'Deliverables and work packages', 1 ), array( 'A list of project risks', 0 ), array( 'The monthly reporting calendar', 0 ) ) ),
				array( 'The critical path of a schedule is best described as which of the following?', array( array( 'The longest path of dependent activities', 1 ), array( 'The cheapest set of tasks', 0 ), array( 'The path that uses the most people', 0 ) ) ),
				array( 'A schedule milestone has what duration?', array( array( 'Zero duration', 1 ), array( 'The longest duration in the plan', 0 ), array( 'Exactly one week', 0 ) ) ),
				array( 'What does a project charter primarily do?', array( array( 'Authorize the project and name the sponsor', 1 ), array( 'List every individual task', 0 ), array( 'Report the monthly spend', 0 ) ) ),
				array( 'Planning poker is most commonly used to do what?', array( array( 'Estimate effort by team consensus', 1 ), array( 'Assign responsibility for defects', 0 ), array( 'Lock the final deadline', 0 ) ) ),
				array( 'STEM::A project is two weeks from its deadline. Testing has revealed a defect that takes one week to fix properly, or two days to patch by accepting known technical debt. The sponsor values the deadline but has also stressed quality throughout the project.', array( array( 'Raise a change request and present both options to the change control board', 1 ), array( 'Quietly ship the two-day patch to protect the date', 0 ), array( 'Move the deadline without informing the sponsor', 0 ) ) ),
			),
			'Execution & Monitoring' => array(
				array( 'In earned value, if EV is less than PV, the project is which of the following?', array( array( 'Behind schedule', 1 ), array( 'Under budget', 0 ), array( 'Over-resourced', 0 ) ) ),
				array( 'A change request should first be routed to whom?', array( array( 'The change control board', 1 ), array( 'The customer directly', 0 ), array( 'The external vendor', 0 ) ) ),
				array( 'The daily stand-up is a ceremony from which framework?', array( array( 'Scrum', 1 ), array( 'Waterfall', 0 ), array( 'Earned value management', 0 ) ) ),
				array( 'Scope creep refers to what?', array( array( 'Uncontrolled growth of project scope', 1 ), array( 'A planned reduction of scope', 0 ), array( 'A cost-estimation technique', 0 ) ) ),
				array( 'A burndown chart shows which of the following over time?', array( array( 'Work remaining', 1 ), array( 'Team salaries', 0 ), array( 'Risk severity', 0 ) ) ),
				array( 'Quality control primarily focuses on what?', array( array( 'Inspecting deliverables for defects', 1 ), array( 'Writing the project charter', 0 ), array( 'Recruiting the team', 0 ) ) ),
			),
			'Risk & Stakeholders'    => array(
				array( 'A risk register records which of the following?', array( array( 'Identified risks and their responses', 1 ), array( 'Only the project budget', 0 ), array( 'The team holiday calendar', 0 ) ) ),
				array( 'Accepting a risk means doing what?', array( array( 'Taking no proactive action but planning a reserve', 1 ), array( 'Removing the risk entirely', 0 ), array( 'Transferring it to a vendor', 0 ) ) ),
				array( 'A stakeholder with high power and high interest should be managed how?', array( array( 'Managed closely', 1 ), array( 'Ignored', 0 ), array( 'Monitored only', 0 ) ) ),
				array( 'What does a RACI chart clarify?', array( array( 'Roles and responsibilities', 1 ), array( 'The budget baseline', 0 ), array( 'The risk score', 0 ) ) ),
				array( 'Transferring a risk is typically done using what?', array( array( 'Insurance or a contract', 1 ), array( 'A daily stand-up', 0 ), array( 'A work breakdown structure', 0 ) ) ),
				array( 'Residual risk is best defined as which of the following?', array( array( 'The risk remaining after responses are applied', 1 ), array( 'The first risk identified', 0 ), array( 'The single largest risk', 0 ) ) ),
			),
		);

		$cat_ids  = array();
		$sort_cat = 1;
		foreach ( array_keys( $bank ) as $subject ) {
			$cat_ids[ $subject ]                 = (int) QuestionCategory::create(
				array(
					'name'       => $subject,
					'slug'       => sanitize_title( 'demo-pmp-' . $subject ),
					'sort_order' => $sort_cat++,
				)
			);
			$manifest['question_category_ids'][] = $cat_ids[ $subject ];
		}

		$slot_ids = array();
		foreach ( $bank as $subject => $questions ) {
			foreach ( $questions as $qrow ) {
				$prompt = (string) $qrow[0];
				$stem   = '';
				if ( 0 === strpos( $prompt, 'STEM::' ) ) {
					// Scenario question: the long scenario becomes the rich stem and
					// the actual ask stays short in the title.
					$stem   = '<p><strong>Scenario.</strong> ' . esc_html( substr( $prompt, 6 ) ) . '</p>';
					$prompt = 'Based on the scenario above, what is the best next step?';
				}
				$qid                        = Question::insert(
					array(
						'title'       => $prompt,
						'stem'        => $stem,
						'type'        => 'mc_single',
						'category_id' => $cat_ids[ $subject ],
						'difficulty'  => 3,
						'version'     => 1,
						'author_id'   => $instructor_id,
						'created_at'  => now(),
						'updated_at'  => now(),
					)
				);
				$manifest['question_ids'][] = (int) $qid;
				$order                      = 1;
				foreach ( (array) $qrow[1] as $opt ) {
					$manifest['answer_ids'][] = (int) Answer::insert(
						array(
							'question_id' => (int) $qid,
							'content'     => (string) $opt[0],
							'is_correct'  => (int) $opt[1],
							'fraction'    => $opt[1] ? 1.0000 : 0.0000,
							'sort_order'  => $order++,
						)
					);
				}
				$slot_ids[] = (int) $qid;
			}
		}

		// Three quizzes over the same pool, one per layout.
		$layouts = array(
			array(
				'title'    => 'Full Mock Exam (Sectioned)',
				'slug'     => 'lrn-demo-mock-sectioned',
				'template' => 'sectioned',
				'timed'    => 3600,
				'group'    => 0,
				'nav'      => 'linear',
				'desc'     => 'The full mock exam, delivered in sections just like the real one. Work through each section in order and submit when the last one is done. 60 minutes for the whole paper.',
			),
			array(
				'title'    => 'Quick Knowledge Check (Conversational)',
				'slug'     => 'lrn-demo-knowledge-check',
				'template' => 'conversational',
				'timed'    => 0,
				'group'    => 0,
				'nav'      => 'free',
				'desc'     => 'A quick, low-stakes check of the essentials. One question at a time, no timer, and you can move freely between questions before you submit.',
			),
			array(
				'title'    => 'Timed Certification Exam (Subject-grouped)',
				'slug'     => 'lrn-demo-timed-exam',
				'template' => 'review-board',
				'timed'    => 5400,
				'group'    => 1,
				'nav'      => 'free',
				'desc'     => 'The large timed exam with a question map and subject-wise scoring. Flag questions you are unsure about and return to them before time runs out. 90 minutes.',
			),
			// A STANDALONE practice paper: no course, practice mode, review
			// board, grouped by subject. Learnomy has supported this shape all
			// along - it is how a coaching or exam-prep site runs a multi-subject
			// mock outside any single course - and the demo had no example of it,
			// so owners never found the path (Basecamp 10184409626). It is also
			// the only standalone quiz and the only practice-mode quiz in the
			// showcase, so both routes finally have live data.
			array(
				'title'      => 'Practice Paper: All Subjects (untimed)',
				'slug'       => 'lrn-demo-practice-paper',
				'template'   => 'review-board',
				'timed'      => 0,
				'group'      => 1,
				'nav'        => 'free',
				'desc'       => 'A mixed practice paper drawing on every subject at once, the way a real mock sitting does. Untimed, unlimited attempts, and grouped so you can see which subjects need work.',
				'standalone' => true,
				'mode'       => 'practice',
			),
		);
		foreach ( $layouts as $layout ) {
			$quiz_id = Quiz::insert(
				array(
					// A standalone paper deliberately carries NO course: it is
					// reached at /quiz/{slug}/ rather than through a curriculum.
					'course_id'          => empty( $layout['standalone'] ) ? (int) $course_id : 0,
					'title'              => $layout['title'],
					'slug'               => $layout['slug'],
					'description'        => $layout['desc'],
					'mode'               => (string) ( $layout['mode'] ?? 'graded' ),
					'passing_score'      => 70,
					'time_limit_seconds' => (int) $layout['timed'],
					'shuffle_questions'  => 0,
					'shuffle_answers'    => 0,
					'group_by_category'  => (int) $layout['group'],
					'display_template'   => $layout['template'],
					'section_navigation' => $layout['nav'],
					'navigation'         => $layout['nav'],
					'author_id'          => $instructor_id,
					'status'             => 'published',
					'created_at'         => now(),
					'updated_at'         => now(),
				)
			);
			if ( ! $quiz_id ) {
				continue;
			}
			$manifest['quiz_ids'][ 'showcase_' . $layout['slug'] ] = (int) $quiz_id;
			$order = 1;
			foreach ( $slot_ids as $qid ) {
				$manifest['quiz_slot_ids'][] = (int) QuizSlot::insert(
					array(
						'quiz_id'     => (int) $quiz_id,
						'question_id' => (int) $qid,
						'sort_order'  => $order++,
						'marks'       => 1,
					)
				);
			}
		}
	}

	/**
	 * Seed a "Course Content" section with a few text lessons so a showcase
	 * course reads as a real, non-empty course (no "0 lessons" empty state in the
	 * sales screenshots). Lessons cascade-delete with the course on teardown.
	 *
	 * @param array<string,mixed>                 $manifest      Seed manifest (by ref).
	 * @param int                                 $course_id     Course to add content to.
	 * @param int                                 $instructor_id Author.
	 * @param array<int,array{0:string,1:string}> $lessons       Title + intro-body pairs.
	 */
	private static function seed_showcase_lessons( array &$manifest, int $course_id, int $instructor_id, array $lessons ): void {
		if ( ! $course_id || empty( $lessons ) ) {
			return;
		}
		$section_id                = (int) Section::create(
			array(
				'course_id'  => $course_id,
				'title'      => 'Course Content',
				'sort_order' => 1,
			)
		);
		$manifest['section_ids'][] = $section_id;

		$order = 1;
		foreach ( $lessons as $lesson ) {
			$title       = (string) $lesson[0];
			$body        = (string) $lesson[1];
			$editor_json = self::build_editor_json( $title, $body, 'text', count( $manifest['lesson_ids'] ?? array() ) );
			if ( \Learnomy\Content\Content_HTML::storage_enabled() ) {
				$editor_json = \Learnomy\Content\Content_HTML::for_storage( $editor_json );
			}
			$manifest['lesson_ids'][] = (int) Lesson::create(
				array(
					'course_id'        => $course_id,
					'section_id'       => $section_id,
					'author_id'        => $instructor_id,
					'title'            => $title,
					'slug'             => sanitize_title( $title ) . '-lrn-demo',
					'content'          => $editor_json,
					'content_type'     => 'text',
					'duration_seconds' => wp_rand( 300, 600 ),
					'sort_order'       => $order,
					'is_preview'       => 1 === $order ? 1 : 0,
				)
			);
			++$order;
		}
	}

	/**
	 * Seed the "activity log" admin surfaces so they never read as empty in a
	 * sales demo: outgoing Webhooks, inbound Webhook Events, the Email Log, and
	 * Announcements. (Non-empty-coverage audit, 2026-07-02.)
	 *
	 * @param array<string,mixed> $manifest Seed manifest (by ref).
	 * @param array<string,int>   $students Seeded student ids.
	 */
	private static function seed_admin_activity( array &$manifest, array $students ): void {
		// Outgoing webhooks (2; the free cap is 3, so stay under it).
		$webhooks = array(
			array(
				'url'               => 'https://hooks.zapier.com/hooks/catch/1837465/demo/',
				'events_json'       => (string) wp_json_encode( array( 'enrollment.completed', 'course.completed' ) ),
				'status'            => 'active',
				'last_triggered_at' => self::demo_date( 2 ),
				'last_status_code'  => 200,
			),
			array(
				'url'               => 'https://example.com/crm/learnomy-webhook',
				'events_json'       => (string) wp_json_encode( array( 'certificate.issued' ) ),
				'status'            => 'inactive',
				'last_triggered_at' => self::demo_date( 12 ),
				'last_status_code'  => 200,
			),
		);
		foreach ( $webhooks as $webhook ) {
			$webhook['secret'] = wp_generate_password( 32, false );
			$id                = Webhook::create( $webhook );
			if ( $id ) {
				$manifest['webhook_ids'][] = (int) $id;
			}
		}

		// Inbound payment webhook events (3, varied outcomes).
		$events = array(
			array(
				'provider'   => 'stripe',
				'event_type' => 'checkout.session.completed',
				'outcome'    => 'handled',
				'days'       => 3,
			),
			array(
				'provider'   => 'stripe',
				'event_type' => 'invoice.paid',
				'outcome'    => 'handled',
				'days'       => 8,
			),
			array(
				'provider'   => 'paypal',
				'event_type' => 'PAYMENT.SALE.COMPLETED',
				'outcome'    => 'duplicate',
				'days'       => 15,
			),
		);
		foreach ( $events as $i => $event ) {
			$ref = $event['provider'] . '_demo_' . ( $i + 1 );
			$id  = Webhook_Event::log(
				array(
					'provider'         => $event['provider'],
					'event_type'       => $event['event_type'],
					'event_id'         => $ref,
					'payload_json'     => (string) wp_json_encode(
						array(
							'id'   => $ref,
							'type' => $event['event_type'],
						)
					),
					'signature_status' => 'verified',
					'processed_at'     => self::demo_date( (int) $event['days'] ),
					'outcome'          => $event['outcome'],
					'error_message'    => null,
					'retry_count'      => 0,
				)
			);
			if ( $id ) {
				$manifest['webhook_event_ids'][] = (int) $id;
			}
		}

		// Email Log + Announcements (both are lrn_notifications rows).
		$student_ids = array_values( array_filter( array_map( 'intval', (array) $students ) ) );
		if ( empty( $student_ids ) ) {
			return;
		}
		$log = array(
			array(
				'type'         => 'course_enrollment',
				'title'        => 'Welcome to your new course',
				'email_status' => 'sent',
				'days'         => 5,
			),
			array(
				'type'         => 'lesson_completed',
				'title'        => 'Lesson completed',
				'email_status' => 'sent',
				'days'         => 4,
			),
			array(
				'type'         => 'certificate_issued',
				'title'        => 'Your certificate is ready',
				'email_status' => 'sent',
				'days'         => 3,
			),
			array(
				'type'         => 'quiz_graded',
				'title'        => 'Your quiz has been graded',
				'email_status' => 'skipped',
				'days'         => 2,
			),
		);
		foreach ( $log as $i => $note ) {
			$id = Notification::create(
				array(
					'user_id'      => $student_ids[ $i % count( $student_ids ) ],
					'type'         => $note['type'],
					'title'        => $note['title'],
					'message'      => $note['title'] . '.',
					'email_status' => $note['email_status'],
					'is_read'      => 1,
					'created_at'   => self::demo_date( (int) $note['days'] ),
				)
			);
			if ( $id ) {
				$manifest['notification_ids'][] = (int) $id;
			}
		}
		foreach ( array( 'New certification exams available', 'Scheduled maintenance this weekend' ) as $i => $title ) {
			$id = Notification::create(
				array(
					'user_id'    => $student_ids[ $i % count( $student_ids ) ],
					'type'       => 'announcement',
					'title'      => $title,
					'message'    => $title . '.',
					'created_at' => self::demo_date( 7 + $i ),
				)
			);
			if ( $id ) {
				$manifest['notification_ids'][] = (int) $id;
			}
		}
	}

	/**
	 * Commerce edge-cases so those admin surfaces are non-empty in a demo: one
	 * pending instructor withdrawal request (Earnings > Withdrawal Requests) and
	 * one paused subscription (Membership Analytics "Paused" + manage screen).
	 * Runs after commissions + subscriptions are seeded.
	 *
	 * @param array<string,mixed> $manifest Seed manifest (by ref).
	 */
	private static function seed_commerce_edge_cases( array &$manifest ): void {
		$instructor_id = (int) ( $manifest['course_instructor_pairs'][0][1] ?? 0 );
		if ( $instructor_id ) {
			$id = Withdrawal::insert(
				array(
					'instructor_id' => $instructor_id,
					'amount'        => 120.00,
					'currency'      => 'USD',
					'status'        => 'pending',
					'method'        => 'bank',
					'reference'     => 'lrn-demo-withdrawal',
					'requested_at'  => self::demo_date( 3 ),
				)
			);
			if ( $id ) {
				$manifest['withdrawal_ids'][] = (int) $id;
			}
		}

		// Flip one seeded subscription to paused so Membership Analytics "Paused"
		// + the manage screen are non-empty. The demo's subscriptions are created
		// through the service (not the tracked manifest path), so fall back to the
		// most recent row when the manifest has none.
		$sub_id = (int) ( $manifest['subscription_ids'][0] ?? 0 );
		if ( ! $sub_id ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$sub_id = (int) $wpdb->get_var( 'SELECT id FROM ' . \Learnomy\table( 'member_subscriptions' ) . ' ORDER BY id DESC LIMIT 1' );
		}
		if ( $sub_id ) {
			Subscription::update(
				$sub_id,
				array(
					'status'    => 'paused',
					'paused_at' => now(),
				)
			);
		}
	}

	/**
	 * Seed a short essay quiz with one submitted-but-ungraded attempt so the
	 * wp-admin Grading inbox (?page=learnomy-grading) is non-empty. The inbox
	 * lists question responses where graded_at IS NULL and the attempt is
	 * 'submitted'.
	 *
	 * @param array<string,mixed> $manifest Seed manifest (by ref).
	 * @param array<string,int>   $students Seeded student ids.
	 */
	private static function seed_grading_fixture( array &$manifest, array $students ): void {
		$course_id   = (int) ( $manifest['course_ids']['cert_prep'] ?? 0 );
		$student_ids = array_values( array_filter( array_map( 'intval', (array) $students ) ) );
		if ( ! $course_id || empty( $student_ids ) ) {
			return;
		}
		$author_id = (int) ( $manifest['course_instructor_pairs'][0][1] ?? 1 );

		$quiz_id = Quiz::insert(
			array(
				'course_id'        => $course_id,
				'title'            => 'Written Assessment',
				'slug'             => 'lrn-demo-written-assessment',
				'description'      => 'Two written questions graded by your instructor. Take your time and answer in full sentences; you will get written feedback with your grade rather than an instant score.',
				'mode'             => 'graded',
				'passing_score'    => 60,
				'display_template' => 'focused',
				'author_id'        => $author_id,
				'status'           => 'published',
				'created_at'       => now(),
				'updated_at'       => now(),
			)
		);
		if ( ! $quiz_id ) {
			return;
		}
		$manifest['quiz_ids']['grading'] = (int) $quiz_id;

		$question_id                 = Question::insert(
			array(
				'title'      => 'Describe how you would keep a project on schedule when a key risk materialises. Give a concrete example.',
				'type'       => 'essay',
				'difficulty' => 3,
				'version'    => 1,
				'author_id'  => $author_id,
				'created_at' => now(),
				'updated_at' => now(),
			)
		);
		$manifest['question_ids'][]  = (int) $question_id;
		$manifest['quiz_slot_ids'][] = (int) QuizSlot::insert(
			array(
				'quiz_id'     => (int) $quiz_id,
				'question_id' => (int) $question_id,
				'sort_order'  => 1,
				'marks'       => 10,
			)
		);

		// A submitted, not-yet-graded attempt + its essay response.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert(
			$wpdb->prefix . 'lrn_quiz_attempts',
			array(
				'quiz_id'            => (int) $quiz_id,
				'user_id'            => $student_ids[0],
				'status'             => 'submitted',
				'score'              => 0,
				'max_score'          => 10,
				'percentage'         => 0,
				'passed'             => 0,
				'started_at'         => self::demo_date( 2 ),
				'submitted_at'       => self::demo_date( 2 ),
				'graded_at'          => null,
				'time_spent_seconds' => 480,
			)
		);
		$attempt_id                     = (int) $wpdb->insert_id;
		$manifest['quiz_attempt_ids'][] = $attempt_id;
		if ( $attempt_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->insert(
				$wpdb->prefix . 'lrn_question_responses',
				array(
					'attempt_id'       => $attempt_id,
					'question_id'      => (int) $question_id,
					'question_version' => 1,
					'response_json'    => (string) wp_json_encode( array( 'text' => 'When a key risk materialises I re-baseline the critical path, pull a planned reserve, and communicate the impact to stakeholders early. For example, on a launch where a vendor slipped a week, we resequenced the independent workstreams and protected the go-live date.' ) ),
					'score'            => 0,
					'max_score'        => 10,
					'graded_at'        => null,
				)
			);
			$manifest['question_response_ids'][] = (int) $wpdb->insert_id;
		}
	}

	// ── Membership plan ────────────────────────────────────────────────────

	private static function seed_membership_plan( array &$manifest ): void {
		$plan_id = MembershipPlan::create(
			array(
				'title'            => 'Academy Pro',
				'slug'             => 'lrn-demo-academy-pro',
				'description'      => 'Unlimited access to every wellness and productivity course on the platform, including all future additions.',
				'price'            => 29.00,
				'currency'         => 'USD',
				'billing_interval' => 'monthly',
				'status'           => 'active',
				'sort_order'       => 1,
			)
		);

		if ( is_wp_error( $plan_id ) || ! $plan_id ) {
			return;
		}

		$manifest['membership_ids'][] = (int) $plan_id;

		// Attach the mindful-productivity course to the plan.
		$course_id = $manifest['course_ids']['mindful'] ?? 0;
		if ( $course_id ) {
			MembershipPlanCourse::insert(
				array(
					'plan_id'   => (int) $plan_id,
					'course_id' => (int) $course_id,
				)
			);
			$manifest['membership_plan_course_ids'][] = array( (int) $plan_id, (int) $course_id );
		}
	}

	// ── Coupon ─────────────────────────────────────────────────────────────

	private static function seed_coupon( array &$manifest ): void {
		$course_id = (int) ( $manifest['course_ids']['react'] ?? 0 );

		// `code` is UNIQUE, and this used to insert blind — so every reseed that
		// had not managed to remove the previous LAUNCH20 logged
		// "Duplicate entry 'LAUNCH20' for key 'wp_lrn_coupons.code'".
		//
		// Skipping rather than overwriting matters: the demo codes are ordinary
		// marketing words (LAUNCH20, WELCOME10, STUDENT50), so the row in the way
		// may well be the OWNER'S real promotion rather than a leftover of ours.
		// Reseeding demo content must not rewrite a live coupon's discount, limits
		// or expiry — leaving it alone costs one demo coupon and nothing else.
		global $wpdb;
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}lrn_coupons WHERE code = %s", 'LAUNCH20' ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $existing ) {
			return;
		}

		$coupon_id = Coupon::create(
			array(
				'code'           => 'LAUNCH20',
				'type'           => 'percent',
				'value'          => 20.00,
				'applies_to'     => 'course',
				'items_json'     => wp_json_encode( array( $course_id ) ),
				'usage_limit'    => 100,
				'per_user_limit' => 1,
				'starts_at'      => self::demo_date( 60 ),
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + ( 30 * DAY_IN_SECONDS ) ),
				'times_used'     => 1,
				'status'         => 'active',
				'created_at'     => self::demo_date( 60 ),
			)
		);

		if ( $coupon_id ) {
			$manifest['coupon_ids'][] = (int) $coupon_id;
		}
	}

	// ── Transactions + commissions ─────────────────────────────────────────

	/**
	 * Insert four course transactions (three completed + one refunded) plus
	 * two membership subscription payments. Each transaction triggers the
	 * Commission_Service so platform + instructor rows are created.
	 *
	 * The subscription row itself is created here (status=active) so Priya
	 * shows up in the Members admin UI.
	 */
	private static function seed_transactions_and_commissions( array &$manifest, array $students ): void {
		$react_id   = (int) ( $manifest['course_ids']['react'] ?? 0 );
		$mindful_id = (int) ( $manifest['course_ids']['mindful'] ?? 0 );
		$plan_id    = (int) ( $manifest['membership_ids'][0] ?? 0 );
		$coupon_id  = (int) ( $manifest['coupon_ids'][0] ?? 0 );
		$commission = new Commission_Service();

		if ( ! $react_id ) {
			return;
		}

		// T1 — Sofia buys React @ $49, 55 days ago.
		$t1 = Transaction::record(
			array(
				'transaction_type'        => 'course',
				'user_id'                 => $students['sofia'],
				'course_id'               => $react_id,
				'amount'                  => 49.00,
				'currency'                => 'USD',
				'payment_provider'        => 'demo',
				'provider_transaction_id' => 'demo_t1_' . wp_generate_password( 8, false ),
				'status'                  => 'completed',
				'created_at'              => self::demo_date( 55 ),
			)
		);
		if ( $t1 ) {
			$manifest['transaction_ids'][] = (int) $t1;
			self::record_commissions( $commission, $t1, $manifest );
		}

		// T2 — Liam buys React @ $49, 32 days ago.
		$t2 = Transaction::record(
			array(
				'transaction_type'        => 'course',
				'user_id'                 => $students['liam'],
				'course_id'               => $react_id,
				'amount'                  => 49.00,
				'currency'                => 'USD',
				'payment_provider'        => 'demo',
				'provider_transaction_id' => 'demo_t2_' . wp_generate_password( 8, false ),
				'status'                  => 'completed',
				'created_at'              => self::demo_date( 32 ),
			)
		);
		if ( $t2 ) {
			$manifest['transaction_ids'][] = (int) $t2;
			self::record_commissions( $commission, $t2, $manifest );
		}

		// T3 — Wren buys React with LAUNCH20 coupon @ $39.20, 14 days ago.
		$t3 = Transaction::record(
			array(
				'transaction_type'        => 'course',
				'user_id'                 => $students['wren'],
				'course_id'               => $react_id,
				'coupon_id'               => $coupon_id ?: null,
				'amount'                  => 39.20,
				'currency'                => 'USD',
				'payment_provider'        => 'demo',
				'provider_transaction_id' => 'demo_t3_' . wp_generate_password( 8, false ),
				'status'                  => 'completed',
				'created_at'              => self::demo_date( 14 ),
			)
		);
		if ( $t3 ) {
			$manifest['transaction_ids'][] = (int) $t3;
			self::record_commissions( $commission, $t3, $manifest );

			if ( $coupon_id ) {
				$usage_id = CouponUsage::record( (int) $coupon_id, $students['wren'], (int) $t3 );
				if ( $usage_id ) {
					$manifest['coupon_usage_ids'][] = (int) $usage_id;
				}
			}
		}

		// T4 — Emma buys React @ $49 (completed), then refunded via companion txn.
		$t4 = Transaction::record(
			array(
				'transaction_type'        => 'course',
				'user_id'                 => $students['emma'],
				'course_id'               => $react_id,
				'amount'                  => 49.00,
				'currency'                => 'USD',
				'payment_provider'        => 'demo',
				'provider_transaction_id' => 'demo_t4_' . wp_generate_password( 8, false ),
				'status'                  => 'refunded',
				'created_at'              => self::demo_date( 22 ),
			)
		);
		if ( $t4 ) {
			$manifest['transaction_ids'][] = (int) $t4;
			// Refund txn — negative amount, references parent via refund_of.
			$refund_id = Transaction::record(
				array(
					'transaction_type'        => 'refund',
					'user_id'                 => $students['emma'],
					'course_id'               => $react_id,
					'amount'                  => -49.00,
					'currency'                => 'USD',
					'payment_provider'        => 'demo',
					'provider_transaction_id' => 'demo_t4r_' . wp_generate_password( 8, false ),
					'status'                  => 'completed',
					'refund_of'               => (int) $t4,
					'created_at'              => self::demo_date( 20 ),
				)
			);
			if ( $refund_id ) {
				$manifest['transaction_ids'][] = (int) $refund_id;
			}
		}

		// Subscription — Priya on Academy Pro, active, renewed once.
		if ( $plan_id && ! empty( $students['priya'] ) ) {
			$sub_id = Subscription::create(
				array(
					'user_id'                  => $students['priya'],
					'plan_id'                  => $plan_id,
					'status'                   => 'active',
					'payment_provider'         => 'demo',
					'provider_subscription_id' => 'demo_sub_' . wp_generate_password( 10, false ),
					'current_period_start'     => self::demo_date( 10 ),
					'current_period_end'       => gmdate( 'Y-m-d H:i:s', time() + ( 20 * DAY_IN_SECONDS ) ),
					'created_at'               => self::demo_date( 40 ),
					'updated_at'               => now(),
				)
			);
			if ( $sub_id ) {
				$manifest['subscription_ids'][] = (int) $sub_id;

				// Initial subscription payment ($29, 40 days ago).
				$sub_t1 = Transaction::record(
					array(
						'transaction_type'        => 'membership',
						'user_id'                 => $students['priya'],
						'plan_id'                 => $plan_id,
						'subscription_id'         => (int) $sub_id,
						'amount'                  => 29.00,
						'currency'                => 'USD',
						'payment_provider'        => 'demo',
						'provider_transaction_id' => 'demo_sub1_' . wp_generate_password( 8, false ),
						'status'                  => 'completed',
						'created_at'              => self::demo_date( 40 ),
					)
				);
				if ( $sub_t1 ) {
					$manifest['transaction_ids'][] = (int) $sub_t1;
				}

				// Renewal ($29, 10 days ago).
				$sub_t2 = Transaction::record(
					array(
						'transaction_type'        => 'membership',
						'user_id'                 => $students['priya'],
						'plan_id'                 => $plan_id,
						'subscription_id'         => (int) $sub_id,
						'amount'                  => 29.00,
						'currency'                => 'USD',
						'payment_provider'        => 'demo',
						'provider_transaction_id' => 'demo_sub2_' . wp_generate_password( 8, false ),
						'status'                  => 'completed',
						'created_at'              => self::demo_date( 10 ),
					)
				);
				if ( $sub_t2 ) {
					$manifest['transaction_ids'][] = (int) $sub_t2;
				}
			}
		}
	}

	/**
	 * Fire Commission_Service::calculate and capture any commission rows it
	 * created into the manifest so cleanup can delete them individually.
	 */
	private static function record_commissions( Commission_Service $service, int $transaction_id, array &$manifest ): void {
		$service->calculate( $transaction_id );

		// Pick up whatever rows the service just inserted for this txn.
		$rows = Commission::get_for_transaction( $transaction_id );
		foreach ( $rows as $row ) {
			$manifest['commission_row_ids'][] = (int) $row->id;
		}
	}

	// ── Enrollments + progress ─────────────────────────────────────────────

	/**
	 * Enrol students, record lesson progress, and in some cases a quiz attempt
	 * so certificates can issue.
	 */
	private static function seed_enrollments_and_progress( array &$manifest, array $students ): void {
		$react_id   = (int) ( $manifest['course_ids']['react'] ?? 0 );
		$mindful_id = (int) ( $manifest['course_ids']['mindful'] ?? 0 );
		$plan_id    = (int) ( $manifest['membership_ids'][0] ?? 0 );

		$react_lessons   = $manifest['lesson_map']['react'] ?? array();
		$mindful_lessons = $manifest['lesson_map']['mindful'] ?? array();

		// Sofia — React, complete, quiz passed.
		if ( $react_id && ! empty( $students['sofia'] ) ) {
			$enroll_id = self::ensure_enrollment(
				array(
					'user_id'          => $students['sofia'],
					'course_id'        => $react_id,
					'source'           => 'purchase',
					'status'           => 'completed',
					'progress_pct'     => 100,
					'enrolled_at'      => self::demo_date( 55 ),
					'completed_at'     => self::demo_date( 5 ),
					'last_accessed_at' => self::demo_date( 5 ),
				)
			);
			if ( $enroll_id ) {
				$manifest['enrollment_ids'][] = $enroll_id;
				self::mark_lessons_complete( $manifest, $students['sofia'], $react_lessons, count( $react_lessons ), 5 );
				self::seed_quiz_attempt( $manifest, $students['sofia'], $manifest['quiz_ids']['react'] ?? 0, 92.0, true, 4 );
			}
		}

		// Liam — React, ~62% done, no quiz.
		if ( $react_id && ! empty( $students['liam'] ) ) {
			$enroll_id = self::ensure_enrollment(
				array(
					'user_id'          => $students['liam'],
					'course_id'        => $react_id,
					'source'           => 'purchase',
					'status'           => 'active',
					'progress_pct'     => 62,
					'enrolled_at'      => self::demo_date( 32 ),
					'last_accessed_at' => self::demo_date( 3 ),
				)
			);
			if ( $enroll_id ) {
				$manifest['enrollment_ids'][] = $enroll_id;
				self::mark_lessons_complete( $manifest, $students['liam'], $react_lessons, 10, 3 );
			}
		}

		// Wren — React (from T3 coupon buy), enrolled, 25%.
		if ( $react_id && ! empty( $students['wren'] ) ) {
			$enroll_id = self::ensure_enrollment(
				array(
					'user_id'          => $students['wren'],
					'course_id'        => $react_id,
					'source'           => 'purchase',
					'status'           => 'active',
					'progress_pct'     => 25,
					'enrolled_at'      => self::demo_date( 14 ),
					'last_accessed_at' => self::demo_date( 2 ),
				)
			);
			if ( $enroll_id ) {
				$manifest['enrollment_ids'][] = $enroll_id;
				self::mark_lessons_complete( $manifest, $students['wren'], $react_lessons, 4, 2 );
			}
		}

		// Priya — Mindful via membership, fully complete + passed quiz.
		if ( $mindful_id && ! empty( $students['priya'] ) ) {
			$enroll_id = self::ensure_enrollment(
				array(
					'user_id'          => $students['priya'],
					'course_id'        => $mindful_id,
					'plan_id'          => $plan_id,
					'source'           => 'membership',
					'status'           => 'completed',
					'progress_pct'     => 100,
					'enrolled_at'      => self::demo_date( 40 ),
					'completed_at'     => self::demo_date( 7 ),
					'last_accessed_at' => self::demo_date( 7 ),
				)
			);
			if ( $enroll_id ) {
				$manifest['enrollment_ids'][] = $enroll_id;
				self::mark_lessons_complete( $manifest, $students['priya'], $mindful_lessons, count( $mindful_lessons ), 7 );
				self::seed_quiz_attempt( $manifest, $students['priya'], $manifest['quiz_ids']['mindful'] ?? 0, 83.0, true, 6 );
			}
		}

		// Emma — Mindful via membership, barely started.
		if ( $mindful_id && ! empty( $students['emma'] ) ) {
			$enroll_id = self::ensure_enrollment(
				array(
					'user_id'          => $students['emma'],
					'course_id'        => $mindful_id,
					'plan_id'          => $plan_id,
					'source'           => 'membership',
					'status'           => 'active',
					'progress_pct'     => 17,
					'enrolled_at'      => self::demo_date( 30 ),
					'last_accessed_at' => self::demo_date( 25 ),
				)
			);
			if ( $enroll_id ) {
				$manifest['enrollment_ids'][] = $enroll_id;
				self::mark_lessons_complete( $manifest, $students['emma'], $mindful_lessons, 2, 25 );
			}
		}
	}

	/**
	 * Create an enrollment row if one does not exist for (user, course, source).
	 * Returns the row ID either way.
	 */
	private static function ensure_enrollment( array $data ): int {
		$existing = Enrollment::find_by_user_course_source(
			(int) $data['user_id'],
			(int) $data['course_id'],
			(string) $data['source']
		);
		if ( $existing ) {
			Enrollment::update( (int) $existing->id, $data );
			return (int) $existing->id;
		}
		return (int) Enrollment::create( $data );
	}

	private static function mark_lessons_complete( array &$manifest, int $user_id, array $lesson_ids, int $count, int $days_ago ): void {
		$count = min( $count, count( $lesson_ids ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$pid = Progress::create_or_update(
				$user_id,
				'lesson',
				(int) $lesson_ids[ $i ],
				array(
					'status'       => 'completed',
					'completed_at' => self::demo_date( max( $days_ago - $i, 1 ) ),
					'updated_at'   => self::demo_date( max( $days_ago - $i, 1 ) ),
				)
			);
			if ( $pid ) {
				$manifest['progress_ids'][] = (int) $pid;
			}
		}
	}

	/**
	 * Record a submitted quiz attempt directly in the attempts table.
	 *
	 * F-1: kept here because QuizAttempt::start() assumes a live-session
	 * flow (status=in_progress, started_at=now()). For a demo we need a
	 * completed-and-graded attempt with a historical timestamp. There is
	 * no Model method that creates a pre-graded attempt — adding one would
	 * be a special-case method that exists purely to serve this seeder,
	 * which would leak demo concerns into the Model layer.
	 */
	private static function seed_quiz_attempt( array &$manifest, int $user_id, int $quiz_id, float $percentage, bool $passed, int $days_ago ): void {
		global $wpdb;

		if ( ! $quiz_id ) {
			return;
		}

		$started   = self::demo_date( $days_ago );
		$submitted = self::demo_date( $days_ago );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'lrn_quiz_attempts',
			array(
				'quiz_id'            => $quiz_id,
				'user_id'            => $user_id,
				'status'             => 'graded',
				'score'              => $percentage,
				'max_score'          => 100,
				'percentage'         => $percentage,
				'passed'             => $passed ? 1 : 0,
				'started_at'         => $started,
				'submitted_at'       => $submitted,
				'graded_at'          => $submitted,
				'time_spent_seconds' => 720,
			)
		);

		$attempt_id = (int) $wpdb->insert_id;
		if ( $attempt_id ) {
			$manifest['quiz_attempt_ids'][] = $attempt_id;
		}
	}

	// ── Reviews ────────────────────────────────────────────────────────────

	private static function seed_reviews( array &$manifest, array $students ): void {
		$react_id   = (int) ( $manifest['course_ids']['react'] ?? 0 );
		$mindful_id = (int) ( $manifest['course_ids']['mindful'] ?? 0 );

		$reviews = array(
			// React — 4 reviews (5,5,4,5).
			array(
				'user_id'   => $students['sofia'] ?? 0,
				'course_id' => $react_id,
				'rating'    => 5,
				'title'     => 'Three working apps in two weeks',
				'content'   => 'I finished every project, and the weather widget is now a real thing on my portfolio. Maya\'s pacing is spot-on — never too slow, never a leap.',
				'days_ago'  => 6,
			),
			array(
				'user_id'   => $students['liam'] ?? 0,
				'course_id' => $react_id,
				'rating'    => 5,
				'title'     => 'Finally "got" hooks',
				'content'   => 'I\'ve started React courses three times before and always lost the thread at useEffect. The way this one walks you from useState into useEffect made the whole thing click.',
				'days_ago'  => 12,
			),
			array(
				'user_id'   => $students['wren'] ?? 0,
				'course_id' => $react_id,
				'rating'    => 4,
				'title'     => 'Great intro, wanted more routing',
				'content'   => 'Loved the projects, but I wish there was a bonus module on React Router. Everything else is exactly what a working engineer needs to start shipping.',
				'days_ago'  => 9,
			),
			array(
				'user_id'   => $students['emma'] ?? 0,
				'course_id' => $react_id,
				'rating'    => 5,
				'title'     => 'Worth every dollar',
				'content'   => 'I refunded at first because of an unrelated budget issue, but I kept watching the previews and came back. The 80% of the course that is free to preview already taught me more than a $200 bootcamp.',
				'days_ago'  => 18,
			),
			// Mindful — 3 reviews (5,5,4).
			array(
				'user_id'   => $students['priya'] ?? 0,
				'course_id' => $mindful_id,
				'rating'    => 5,
				'title'     => 'The weekly review alone is worth it',
				'content'   => 'I was sceptical of "mindfulness" courses — felt like fluff. This one is cognitive-science grounded and practical to the point. Week 4 rewired how I plan.',
				'days_ago'  => 8,
			),
			array(
				'user_id'   => $students['emma'] ?? 0,
				'course_id' => $mindful_id,
				'rating'    => 5,
				'title'     => 'Short lessons, real changes',
				'content'   => 'Ten-minute daily lessons meant I actually finished. My Slack notifications are finally triaged and my Friday afternoons belong to me.',
				'days_ago'  => 4,
			),
			array(
				'user_id'   => $students['sofia'] ?? 0,
				'course_id' => $mindful_id,
				'rating'    => 4,
				'title'     => 'Solid programme',
				'content'   => 'Nothing groundbreaking if you\'ve read Deep Work and GTD, but the way Arjun stitches the habits together is where the value sits.',
				'days_ago'  => 15,
			),
		);

		foreach ( $reviews as $r ) {
			if ( ! $r['course_id'] || ! $r['user_id'] ) {
				continue;
			}

			$review_id = Review::create(
				array(
					'user_id'    => (int) $r['user_id'],
					'course_id'  => (int) $r['course_id'],
					'rating'     => (int) $r['rating'],
					'title'      => $r['title'],
					'content'    => $r['content'],
					'status'     => 'approved',
					'created_at' => self::demo_date( (int) $r['days_ago'] ),
					'updated_at' => self::demo_date( (int) $r['days_ago'] ),
				)
			);
			if ( $review_id ) {
				$manifest['review_ids'][] = (int) $review_id;
			}
		}
	}

	// ── Lesson comment ─────────────────────────────────────────────────────

	/**
	 * Post an unanswered comment on Course 1 / Lesson 9 from Liam.
	 *
	 * Type and meta key come from Lesson_Comment, which owns the storage
	 * contract. This docblock used to assert `comment_type=lrn_lesson` and a
	 * `learnomy_lesson_id` meta key as fact, and the code below matched it —
	 * neither is what the reader looks for, so every comment this seeder wrote
	 * was invisible on the website and in the app while sitting in the database
	 * approved and correctly attributed (Basecamp 10183713116).
	 */
	private static function seed_lesson_comment( array &$manifest, array $students ): void {
		$lessons   = $manifest['lesson_map']['react'] ?? array();
		$lesson_id = (int) ( $lessons[8] ?? 0 ); // 0-indexed: lesson 9.
		$liam_id   = (int) ( $students['liam'] ?? 0 );

		if ( ! $lesson_id || ! $liam_id ) {
			return;
		}

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => 0,
				'comment_author'       => 'Liam Okafor',
				'comment_author_email' => 'liam.okafor@demo.learnomy',
				'comment_content'      => 'I hit a snag persisting the todo list to localStorage — items survive the refresh but the toggle state is reset. Any idea where I should start debugging?',
				'user_id'              => $liam_id,
				'comment_type'         => \Learnomy\Models\Lesson_Comment::COMMENT_TYPE,
				'comment_approved'     => 1,
				'comment_date'         => self::demo_date( 2 ),
				'comment_date_gmt'     => self::demo_date( 2 ),
			)
		);

		if ( $comment_id && ! is_wp_error( $comment_id ) ) {
			update_comment_meta( (int) $comment_id, \Learnomy\Models\Lesson_Comment::LESSON_META_KEY, $lesson_id );
			$manifest['comment_ids'][] = (int) $comment_id;
		}
	}

	// ── Instructor application + wishlist ──────────────────────────────────

	private static function seed_instructor_application( array &$manifest ): void {
		$tomas_id = (int) ( $manifest['user_ids']['lrn_demo_tomas'] ?? 0 );
		if ( ! $tomas_id ) {
			return;
		}

		$application_id = InstructorApplication::submit(
			$tomas_id,
			'Product designer turned educator. I have been running a weekly design-systems newsletter for three years and want to teach what I\'ve learned to founders.',
			'Figma, Design Systems, UX for early-stage founders',
			'Mentored 40+ founders via Design Buddies over 2 years. Ran three in-person workshops at Config 2024.',
			'A 6-lesson course called "Intro to Figma for Founders" — covering components, variants, auto-layout, and handing off to a dev, without the bloat of a full design curriculum.',
			'bank_transfer',
			''
		);

		if ( $application_id ) {
			$manifest['instructor_application_ids'][] = (int) $application_id;
		}
	}

	private static function seed_wishlist( array &$manifest, array $students ): void {
		$noah_id   = (int) ( $students['noah'] ?? 0 );
		$course_id = (int) ( $manifest['course_ids']['react'] ?? 0 );
		if ( ! $noah_id || ! $course_id ) {
			return;
		}

		// Store as a JSON string — the canonical wishlist format the controller
		// writes and every reader expects. (A raw array gets serialized by WP
		// and then json_decode()'d to a fatal — Basecamp #9981731506.)
		update_user_meta( $noah_id, 'learnomy_wishlist', wp_json_encode( array( $course_id ) ) );
		$manifest['wishlist_user_ids'][] = $noah_id;
	}

	// ── Certificates ───────────────────────────────────────────────────────

	/**
	 * Issue Ed25519-signed certificates via the service so signatures are real.
	 *
	 * Only students with completed enrollments get one (Sofia for React,
	 * Priya for Mindful).
	 */
	private static function issue_certificates( array &$manifest, array $students ): void {
		$service = new Certificate_Service();

		$pairs = array(
			array( $students['sofia'] ?? 0, $manifest['course_ids']['react'] ?? 0 ),
			array( $students['priya'] ?? 0, $manifest['course_ids']['mindful'] ?? 0 ),
		);

		foreach ( $pairs as $pair ) {
			$user_id   = (int) $pair[0];
			$course_id = (int) $pair[1];
			if ( ! $user_id || ! $course_id ) {
				continue;
			}
			$cert_id = $service->issue( $user_id, $course_id );
			if ( $cert_id ) {
				$manifest['certificate_ids'][] = (int) $cert_id;
			}
		}
	}

	// ── Denormalised counters ──────────────────────────────────────────────

	private static function refresh_course_counters( array $manifest ): void {
		foreach ( (array) ( $manifest['course_ids'] ?? array() ) as $course_id ) {
			Course::refresh_rating( (int) $course_id );
			// Student count is computed by Enrollment::create inside the service;
			// but because we bypassed the service for some rows, recompute now.
			Course::recompute_student_counts();
		}
	}

	// ── Stats for wizard response ──────────────────────────────────────────

	private static function build_stats( array $manifest ): array {
		$revenue = 0.0;
		if ( ! empty( $manifest['transaction_ids'] ) ) {
			$revenue = Transaction::sum_completed_for_ids( (array) $manifest['transaction_ids'] );
		}

		return array(
			'courses'      => count( (array) ( $manifest['course_ids'] ?? array() ) ),
			'students'     => 5,
			'revenue'      => round( $revenue, 2 ),
			'certificates' => count( (array) ( $manifest['certificate_ids'] ?? array() ) ),
		);
	}

	// ── Resolvers ──────────────────────────────────────────────────────────

	private static function resolve_instructors( array $manifest ): array {
		$maya  = (int) ( $manifest['user_ids']['lrn_demo_maya'] ?? 0 );
		$arjun = (int) ( $manifest['user_ids']['lrn_demo_arjun'] ?? 0 );

		if ( ! $maya || ! $arjun ) {
			$admins   = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
				)
			);
			$fallback = $admins ? (int) $admins[0]->ID : 1;
			$maya     = $maya ?: $fallback;
			$arjun    = $arjun ?: $fallback;
		}

		return array(
			'maya'  => $maya,
			'arjun' => $arjun,
		);
	}

	private static function resolve_students( array $manifest ): array {
		return array(
			'sofia' => (int) ( $manifest['user_ids']['lrn_demo_sofia'] ?? 0 ),
			'liam'  => (int) ( $manifest['user_ids']['lrn_demo_liam'] ?? 0 ),
			'priya' => (int) ( $manifest['user_ids']['lrn_demo_priya'] ?? 0 ),
			'noah'  => (int) ( $manifest['user_ids']['lrn_demo_noah'] ?? 0 ),
			'emma'  => (int) ( $manifest['user_ids']['lrn_demo_emma'] ?? 0 ),
			'wren'  => (int) ( $manifest['user_ids']['lrn_demo_wren'] ?? 0 ),
		);
	}
}
