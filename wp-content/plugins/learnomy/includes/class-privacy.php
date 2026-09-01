<?php
/**
 * GDPR compliance — personal data export and erasure.
 *
 * Registers Learnomy's exporters and erasers with the WordPress
 * privacy tools so site owners can handle data subject requests
 * through the built-in Privacy > Personal Data admin screens.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\AuditLog;
use Learnomy\Models\Blocked_User;
use Learnomy\Models\Certificate;
use Learnomy\Models\CertificateTemplate;
use Learnomy\Models\Commission;
use Learnomy\Models\Course;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\CouponUsage;
use Learnomy\Models\Device;
use Learnomy\Models\EngagementMonthly;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Instructor;
use Learnomy\Models\InstructorApplication;
use Learnomy\Models\Lesson;
use Learnomy\Models\Lesson_Unlock;
use Learnomy\Models\Notification;
use Learnomy\Models\Progress;
use Learnomy\Models\Question;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\Quiz;
use Learnomy\Models\QuestionResponse;
use Learnomy\Models\Review;
use Learnomy\Models\Subscription;
use Learnomy\Models\Token;
use Learnomy\Models\Transaction;
use Learnomy\Models\Withdrawal;

class Privacy {

	/** Courses purged per background pass — a course cascade is heavy. */
	private const AUTHORED_BATCH = 10;

	/**
	 * Wall-clock seconds one authored-content pass may spend before it stops and
	 * re-queues. AUTHORED_BATCH bounds how many courses are FETCHED; this bounds
	 * how long they are allowed to take, which is the number that actually
	 * matters now the pass runs the full per-lesson cascade.
	 */
	private const AUTHORED_TIME_BUDGET = 20.0;

	/** Safety stop for a third-party eraser that never reports done. */
	private const MAX_ERASER_PAGES = 100;

	/** Rows one exporter page returns. WordPress re-calls until done=true. */
	private const PER_PAGE = 50;

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
		add_action( 'admin_init', array( $this, 'register_policy_content' ) );
		// Batched purge of an erased author's content (see erase_authored_content_batch).
		add_action( 'learnomy_erase_authored_content', array( $this, 'erase_authored_content_batch' ) );
	}

	/**
	 * Suggest privacy-policy text for WP's Privacy Policy Guide so a site's
	 * published policy discloses what Learnomy collects (card 10062746993).
	 */
	public function register_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p>' . __( 'This site uses Learnomy (a learning management system) which stores personal data about your learning activity:', 'learnomy' ) . '</p>'
			. '<ul>'
			. '<li>' . __( 'Course enrollments and lesson/quiz progress.', 'learnomy' ) . '</li>'
			. '<li>' . __( 'Quiz attempts and responses, including the IP address recorded at submission time for anti-cheat purposes.', 'learnomy' ) . '</li>'
			. '<li>' . __( 'Certificates earned and their public verification records.', 'learnomy' ) . '</li>'
			. '<li>' . __( 'Course reviews and ratings you submit.', 'learnomy' ) . '</li>'
			. '<li>' . __( 'Purchase and subscription transaction records (amounts, dates, and payment-provider reference IDs - never full card numbers).', 'learnomy' ) . '</li>'
			. '</ul>'
			. '<p>' . __( 'You can request an export or erasure of this data through the site\'s Tools → Export/Erase Personal Data screens.', 'learnomy' ) . '</p>';

		wp_add_privacy_policy_content( 'Learnomy', wp_kses_post( $content ) );
	}

	/**
	 * Every exporter Learnomy registers, and the tables each one discloses.
	 *
	 * DECLARATIVE AND LOAD-BEARING IN BOTH DIRECTIONS. register_exporters()
	 * builds the WordPress registry from this map, and
	 * `bin/check-privacy-completeness.php` reads the `tables` lists to prove that
	 * every user-keyed table we would ERASE is a table we also DISCLOSE. Because
	 * the map is what actually registers the exporter, a table cannot be claimed
	 * as covered without a callback that exists and runs — the two halves of the
	 * GDPR contract are stated once, in one place.
	 *
	 * WHY THIS EXISTS. Erasure had a completeness gate; export had none. Only one
	 * half could drift loudly, so it was the only half that stayed complete: a
	 * subject access request returned 5 groups while a deletion request removed 25
	 * tables, including IP addresses, user agents, a free-text instructor bio and
	 * every lesson the person had completed. Holding personal data we do not
	 * disclose is harder to defend than retaining it (Basecamp 10148718490).
	 *
	 * @return array<string,array{label:string,callback:string,tables:string[]}>
	 */
	public static function exporters(): array {
		return array(
			'learnomy-enrollments'   => array(
				'label'    => __( 'Learnomy Enrollments', 'learnomy' ),
				'callback' => 'export_enrollments',
				'tables'   => array( 'lrn_enrollments' ),
			),
			'learnomy-quiz-attempts' => array(
				'label'    => __( 'Learnomy Quiz Attempts', 'learnomy' ),
				'callback' => 'export_quiz_attempts',
				'tables'   => array( 'lrn_quiz_attempts', 'lrn_question_responses' ),
			),
			'learnomy-certificates'  => array(
				'label'    => __( 'Learnomy Certificates', 'learnomy' ),
				'callback' => 'export_certificates',
				'tables'   => array( 'lrn_certificates' ),
			),
			'learnomy-reviews'       => array(
				'label'    => __( 'Learnomy Reviews', 'learnomy' ),
				'callback' => 'export_reviews',
				'tables'   => array( 'lrn_reviews' ),
			),
			'learnomy-transactions'  => array(
				'label'    => __( 'Learnomy Transactions', 'learnomy' ),
				'callback' => 'export_transactions',
				'tables'   => array( 'lrn_transactions' ),
			),
			'learnomy-progress'      => array(
				'label'    => __( 'Learnomy Learning Progress', 'learnomy' ),
				'callback' => 'export_progress',
				'tables'   => array( 'lrn_progress', 'lrn_lesson_unlocks' ),
			),
			'learnomy-notifications' => array(
				'label'    => __( 'Learnomy Notifications', 'learnomy' ),
				'callback' => 'export_notifications',
				'tables'   => array( 'lrn_notifications' ),
			),
			'learnomy-sessions'      => array(
				'label'    => __( 'Learnomy Sign-ins & Devices', 'learnomy' ),
				'callback' => 'export_sessions',
				'tables'   => array( 'lrn_tokens', 'lrn_devices' ),
			),
			'learnomy-activity-log'  => array(
				'label'    => __( 'Learnomy Account Activity Log', 'learnomy' ),
				'callback' => 'export_activity_log',
				'tables'   => array( 'lrn_audit_log' ),
			),
			'learnomy-blocked'       => array(
				'label'    => __( 'Learnomy Blocked Members', 'learnomy' ),
				'callback' => 'export_blocked_members',
				'tables'   => array( 'lrn_blocked_users' ),
			),
			'learnomy-instructor'    => array(
				'label'    => __( 'Learnomy Instructor Profile', 'learnomy' ),
				'callback' => 'export_instructor_profile',
				'tables'   => array( 'lrn_instructors', 'lrn_instructor_applications', 'lrn_course_instructors', 'lrn_engagement_monthly' ),
			),
			'learnomy-billing'       => array(
				'label'    => __( 'Learnomy Subscriptions & Coupons', 'learnomy' ),
				'callback' => 'export_billing',
				'tables'   => array( 'lrn_member_subscriptions', 'lrn_coupon_usages' ),
			),
			'learnomy-earnings'      => array(
				'label'    => __( 'Learnomy Earnings & Payouts', 'learnomy' ),
				'callback' => 'export_earnings',
				'tables'   => array( 'lrn_commissions', 'lrn_withdrawals' ),
			),
			'learnomy-authored'      => array(
				'label'    => __( 'Learnomy Authored Content', 'learnomy' ),
				'callback' => 'export_authored_content',
				'tables'   => array( 'lrn_courses', 'lrn_lessons', 'lrn_quizzes', 'lrn_questions', 'lrn_certificate_templates' ),
			),
			// User-meta backed, so `tables` is empty — the completeness gate
			// discovers user-keyed TABLES and would never have flagged this
			// one. Included anyway because the principle the gate encodes
			// applies regardless of where a value is stored: what we hold about
			// a person, we disclose to them on request. This is also the
			// site owner's own "show me the proof" surface — running an export
			// for a member returns the timestamp, the documents they were
			// shown and the IP the acceptance came from.
			'learnomy-consent'       => array(
				'label'    => __( 'Learnomy Terms Acceptance', 'learnomy' ),
				'callback' => 'export_consent',
				'tables'   => array(),
			),
		);
	}

	/**
	 * User-keyed tables deliberately NOT disclosed in a subject access export,
	 * each with the reason a regulator would be given.
	 *
	 * The escape hatch, and the only way a table may skip export while still
	 * having an eraser. Keep it empty if you can: "we hold this but will not show
	 * it to you" needs a better answer than "nobody wrote the exporter", which is
	 * how the gap this map exists to close came about in the first place.
	 *
	 * @return array<string,string>
	 */
	public static function not_exported_tables(): array {
		return array(
			// (none today — every user-keyed Free table is disclosed. Column-level
			// withholding, where it applies, is recorded in withheld_columns().)
		);
	}

	/**
	 * Columns held back from an export that otherwise covers their table, with
	 * the reason. An export is handed to the data subject, so it must contain
	 * their data and ONLY their data — no secret that would let a leaked export
	 * be replayed against the site, and no third party's personal data carried in
	 * on the subject's row (GDPR Art. 15(4): the copy must not adversely affect
	 * the rights and freedoms of others).
	 *
	 * Declarative so the withholding decisions are auditable in one place rather
	 * than buried as silent omissions in fourteen exporter methods.
	 *
	 * @return array<string,string>
	 */
	public static function withheld_columns(): array {
		return array(
			'lrn_tokens.token_hash'                   => 'Session credential. Disclosing it would let anyone holding the export file authenticate as the subject; the fact of the session, its IP address, user agent and dates are all disclosed.',
			'lrn_devices.device_token'                => 'Push credential for the subject\'s handset, masked to the last four characters. The full value in a downloadable file would let a third party push messages to their device.',
			'lrn_instructor_applications.payout_details_encrypted' => 'Encrypted bank/payee details. Decrypting them into a plain-text export file creates a new exposure of the most sensitive field we hold; the payout METHOD is disclosed, and the details themselves are visible to the subject in their own account screen.',
			'lrn_instructor_applications.reviewed_by' => 'The reviewing administrator\'s user ID — another person\'s data, not the applicant\'s. The review outcome and date are disclosed.',
			'lrn_audit_log.metadata_json'             => 'Free-form operational context about the object acted on, not about the actor. On an administrator\'s rows it routinely contains OTHER members\' personal data, so it cannot be handed to the subject. The action, object and timestamp are disclosed.',
			'lrn_blocked_users.blocked_id'            => 'Only blocks the subject MADE are disclosed (which they can already see in their own block list). Blocks OTHER members made against them are those members\' data, and telling someone who has blocked them is exactly the harm Art. 15(4) protects against.',
			'lrn_notifications.actor_id'              => 'The other member who triggered the notification. The message text the subject already received is disclosed in full; the numeric link to that third party is not.',
			'lrn_question_responses.grader_id'        => 'The instructor who marked the answer — another person\'s data. The mark and the feedback they wrote about the subject\'s answer are both disclosed.',
		);
	}

	public function register_exporters( array $exporters ): array {
		foreach ( self::exporters() as $key => $exporter ) {
			$exporters[ $key ] = array(
				'exporter_friendly_name' => $exporter['label'],
				'callback'               => array( $this, $exporter['callback'] ),
			);
		}

		return $exporters;
	}

	public function register_erasers( array $erasers ): array {
		$erasers['learnomy-enrollments']   = array(
			'eraser_friendly_name' => __( 'Learnomy Enrollments & Progress', 'learnomy' ),
			'callback'             => array( $this, 'erase_enrollments' ),
		);
		$erasers['learnomy-quiz-attempts'] = array(
			'eraser_friendly_name' => __( 'Learnomy Quiz Attempts', 'learnomy' ),
			'callback'             => array( $this, 'erase_quiz_attempts' ),
		);
		$erasers['learnomy-certificates']  = array(
			'eraser_friendly_name' => __( 'Learnomy Certificates', 'learnomy' ),
			'callback'             => array( $this, 'erase_certificates' ),
		);
		$erasers['learnomy-reviews']       = array(
			'eraser_friendly_name' => __( 'Learnomy Reviews', 'learnomy' ),
			'callback'             => array( $this, 'erase_reviews' ),
		);
		$erasers['learnomy-transactions']  = array(
			'eraser_friendly_name' => __( 'Learnomy Transaction Records', 'learnomy' ),
			'callback'             => array( $this, 'erase_transactions' ),
		);
		// Device tokens + in-app notifications are user-keyed PII that the mobile
		// app writes (push registration) and the notifier fills. They were NOT
		// covered by the original 5 erasers, so a "delete my data" left live
		// push tokens and notification text behind (mobile-API contract WI-7).
		$erasers['learnomy-app-data'] = array(
			'eraser_friendly_name' => __( 'Learnomy App Devices & Notifications', 'learnomy' ),
			'callback'             => array( $this, 'erase_app_data' ),
		);

		return $erasers;
	}

	/**
	 * User-keyed tables holding the data subject's OWN personal data, which a
	 * GDPR Art. 17 erase must DELETE. Table (unprefixed) => user column.
	 *
	 * Declarative on purpose: `bin/check-privacy-completeness.php` reads this map
	 * (plus retained_tables()) and fails CI when a new user-keyed table appears in
	 * the schema without being classified here — the erasure coverage cannot
	 * silently regrow a hole (Basecamp 10109167870). The same gate demands an
	 * EXPORT verdict for the same table, so the two halves cannot drift apart
	 * again (Basecamp 10148718490).
	 *
	 * @return array<string,string>
	 */
	public static function learner_data_tables(): array {
		return array(
			'lrn_devices'                 => 'user_id', // Push tokens.
			'lrn_notifications'           => 'user_id', // In-app messages.
			'lrn_progress'                => 'user_id', // Lesson/course progress.
			'lrn_tokens'                  => 'user_id', // JWT rows incl. ip_address / user_agent.
			'lrn_lesson_unlocks'          => 'user_id',
			'lrn_coupon_usages'           => 'user_id',
			'lrn_instructor_applications' => 'user_id', // Free-text bio + payout details.
			'lrn_instructors'             => 'user_id', // Instructor profile.
			'lrn_course_instructors'      => 'user_id', // Teaching assignment (junction; the course itself is untouched).
			'lrn_blocked_users'           => 'blocker_id', // Blocks the person made; the blocked_id side is cleared explicitly in erase().
		);
	}

	/**
	 * User-keyed tables whose ROW must survive an erase for a legal duty, with the
	 * person unlinked (column set to 0). Financial records carry a tax/accounting
	 * retention duty; the audit log is retained on a security basis. Neither can
	 * identify the data subject afterwards. Table (unprefixed) => user column.
	 *
	 * @return array<string,string>
	 */
	public static function retained_tables(): array {
		return array(
			'lrn_member_subscriptions' => 'user_id',
			'lrn_commissions'          => 'recipient_id',
			'lrn_withdrawals'          => 'instructor_id',
			'lrn_engagement_monthly'   => 'instructor_id', // Feeds commission splits.
			'lrn_audit_log'            => 'user_id',       // Security/forensic record.
		);
	}

	/**
	 * Tables where the user column records AUTHORSHIP of site content, not the
	 * data subject's own personal data. Table (unprefixed) => author column.
	 *
	 * These must NOT be deleted on erasure. An instructor's courses and lessons
	 * are the site owner's catalogue and every enrolled student's access — wiping
	 * them to satisfy one erasure request would destroy other people's data and
	 * the owner's business. WordPress core has the same problem with posts and
	 * solves it by REASSIGNING authored content to another user; we do the same.
	 * The author link (the only personal datum here) is what gets removed.
	 *
	 * @return array<string,string>
	 */
	public static function authored_content_tables(): array {
		return array(
			'lrn_courses'               => 'author_id',
			'lrn_lessons'               => 'author_id',
			'lrn_questions'             => 'author_id',
			'lrn_quizzes'               => 'author_id',
			'lrn_certificate_templates' => 'author_id',
		);
	}

	/**
	 * Who authored content is reassigned to on erasure. Defaults to the site's
	 * first administrator (WordPress's own reassign-on-delete model), so courses
	 * keep working and students keep access. Filterable for owners who want a
	 * dedicated "Former instructor" account instead.
	 */
	public static function content_reassign_target(): int {
		$admins  = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);
		$default = ! empty( $admins ) ? (int) $admins[0] : 0;

		/**
		 * User ID that authored content is reassigned to during a GDPR erase.
		 *
		 * @param int $user_id Default: the site's first administrator.
		 */
		return (int) apply_filters( 'learnomy_privacy_content_reassign_to', $default );
	}

	/**
	 * Purge one batch of an erased author's content, then re-queue itself until
	 * nothing is left — the "delete in steps, leave no trace" path.
	 *
	 * Deleting a course is not one row: it cascades to its sections, lessons,
	 * quizzes, reviews, enrollments and certificates. An instructor with a large
	 * catalogue (hundreds of courses, thousands of lessons) cannot be purged inside
	 * the erasure request without timing out and leaving a half-deleted trail, so
	 * the work runs AUTHORED_BATCH courses per Action Scheduler pass and re-queues
	 * while more remain. Idempotent: each pass re-reads what is still owned, so a
	 * retried or duplicated job simply finds less to do (Basecamp 10109167870).
	 *
	 * @param int $user_id The erased author.
	 */
	public function erase_authored_content_batch( $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		global $wpdb;
		$batch = (int) apply_filters( 'learnomy_privacy_authored_batch', self::AUTHORED_BATCH );
		$batch = max( 1, $batch );

		/**
		 * Seconds one authored-content pass may run before stopping and
		 * re-queueing. Raise it on a host with a generous max_execution_time;
		 * lower it on shared hosting that kills long Action Scheduler passes.
		 *
		 * @param float $seconds Default: 20.0.
		 */
		$deadline = microtime( true ) + max( 1.0, (float) apply_filters( 'learnomy_privacy_authored_time_budget', self::AUTHORED_TIME_BUDGET ) );

		$course_ids = \Learnomy\Models\Course::authored_ids( $user_id, $batch );
		$courses    = new \Learnomy\Services\Course_Service();

		foreach ( $course_ids as $course_id ) {
			/**
			 * Fires before an erased author's course is removed.
			 *
			 * This is NOT the general "clean up your course-keyed rows" seam —
			 * `learnomy_course_deleted` is, and it fires from the
			 * Course_Service::delete() call directly below, so it covers the
			 * erasure path as well as the REST and admin ones. Listening here
			 * for ordinary cleanup would only duplicate that (Basecamp
			 * 10148716553).
			 *
			 * What this event is for is the difference in INTENT. An ordinary
			 * delete may legitimately preserve learner-authored work — Pro's
			 * assignments keep a graded submission when its lesson goes — but an
			 * erasure has already promised the site owner that nothing is left.
			 * A listener here purges what it would otherwise have kept, and it
			 * runs FIRST, while the curriculum still exists, so it can still
			 * enumerate what belongs to the course.
			 *
			 * @param int $course_id Course being purged.
			 * @param int $user_id   The erased author.
			 */
			do_action( 'learnomy_erase_authored_course', $course_id, $user_id );

			// ONE chokepoint for course removal. Course_Service::delete() takes
			// enrollments, reviews, certificates, the instructor junction, the
			// course tag links, course-attached quizzes (via the quiz cascade),
			// and every section AND orphan lesson through Lesson_Service::delete()
			// — so `learnomy_lesson_deleted` fires per lesson and the Free
			// dependents (attachments, unlocks, progress, tag map) plus every Pro
			// table hanging off that action are cleaned. It then fires
			// `learnomy_course_deleted`.
			//
			// This used to be a hand-rolled six-call cascade ending in the bare
			// model `Course::delete()`, with `Lesson::delete_for_course()` (a
			// plain table DELETE) standing in for the lesson cascade. On the
			// ERASURE path that meant progress rows, attachments, unlocks and
			// Pro notes/revisions — revisions hold lesson CONTENT — survived a
			// request the site owner had just been told succeeded
			// (Basecamp 10148252148).
			$courses->delete( $course_id );

			// Stop on the wall clock, not just the row count: one pass now runs
			// the full per-lesson cascade, so ten large courses is a very
			// different amount of work from ten small ones. Re-queueing is free
			// — the next pass re-reads what is still owned.
			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		// Standalone authored rows not owned by a course (question bank, quizzes,
		// certificate designs). Bounded per pass for the same timeout reason.
		foreach ( array( 'lrn_questions', 'lrn_quizzes', 'lrn_certificate_templates' ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}{$table} WHERE author_id = %d LIMIT %d", $user_id, $batch * 50 ) );
		}

		// Anything left? Re-queue. Re-reading is what makes this idempotent.
		if ( self::authored_content_count( $user_id ) > 0 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'learnomy_erase_authored_content', array( 'user_id' => $user_id ), 'learnomy' );
		}
	}

	/**
	 * How many rows the person still owns as an AUTHOR, across every authored
	 * table.
	 *
	 * One helper so the two decisions that read this number — "is there anything
	 * to queue?" (erase_app_data) and "is there anything left to do?"
	 * (erase_authored_content_batch) — can never disagree. They were two copies
	 * of the same loop, and only one of them existed, so the eraser told every
	 * data subject their authored content was "being permanently deleted in the
	 * background" even when they had never authored anything.
	 *
	 * @param int $user_id Author.
	 */
	private static function authored_content_count( int $user_id ): int {
		global $wpdb;

		$count = (int) \Learnomy\Models\Course::count( array( 'author_id' => $user_id ) );
		foreach ( array( 'lrn_questions', 'lrn_quizzes', 'lrn_certificate_templates' ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE author_id = %d", $user_id ) );
		}

		return $count;
	}

	/**
	 * Erase the member's app-owned and learner-owned user-keyed data, unlink (but
	 * keep) the records carrying a legal retention duty, and apply the authored-
	 * content policy. Hard-delete for personal data: a push token, a notification,
	 * a progress row are pure PII with no statistical value to retain.
	 *
	 * @param string $email Data-subject email.
	 * @param int    $page  Eraser page (single pass — the deletes are bounded).
	 */
	public function erase_app_data( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id = (int) $user->ID;
		global $wpdb;

		$removed = 0;
		foreach ( self::learner_data_tables() as $table => $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$removed += (int) $wpdb->delete( $wpdb->prefix . $table, array( $column => $user_id ), array( '%d' ) );
		}

		// lrn_blocked_users is keyed by TWO user columns. The loop above cleared the
		// blocker_id side (blocks this person made); clear the symmetric blocked_id
		// side (blocks OTHERS made against them) so no row references the erased
		// person — leaving no trace, and honouring an erasure just like the rest.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$removed += (int) $wpdb->delete( $wpdb->prefix . 'lrn_blocked_users', array( 'blocked_id' => $user_id ), array( '%d' ) );

		/**
		 * What happens to content the data subject authored.
		 *
		 * 'delete'   (default) — purge it, leaving no trace of them. Respects the
		 *                        person's wish to be fully removed. Runs as a
		 *                        BATCHED background job: one course cascades to its
		 *                        sections, lessons, quizzes, reviews, enrollments
		 *                        and certificates, so an author with a large
		 *                        catalogue would blow the request timeout inline.
		 * 'reassign'           — transfer authorship to the site administrator
		 *                        (WordPress's reassign-on-delete model), keeping
		 *                        courses alive for already-enrolled students.
		 * 'unpublish'          — keep the rows but take the content offline.
		 *
		 * NOTE for site owners choosing 'delete': the purge removes OTHER students'
		 * enrollments, progress and certificates for those courses, because they
		 * cannot survive their course. If your instructor agreement licenses the
		 * content to the site, 'reassign' is the option that honours the learners'
		 * purchase.
		 *
		 * @param string $policy 'delete' | 'reassign' | 'unpublish'.
		 */
		$authored_policy = (string) apply_filters( 'learnomy_privacy_authored_content_erasure', 'delete' );

		$reassigned = 0;
		$queued     = false;

		if ( 'delete' === $authored_policy ) {
			// Queue rather than delete inline — see the batch handler. Only when
			// they actually authored something: the message below is shown to the
			// data subject as their compliance record, and a student who never
			// created a course must not be told content of theirs is being purged
			// (nor cost the site an Action Scheduler job that finds nothing).
			if ( self::authored_content_count( $user_id ) < 1 ) {
				$queued = false;
			} elseif ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'learnomy_erase_authored_content', array( 'user_id' => $user_id ), 'learnomy' );
				$queued = true;
			} else {
				// No Action Scheduler (shouldn't happen — Free bundles it): fall
				// back to a bounded inline pass so data is not silently left behind.
				$this->erase_authored_content_batch( $user_id );
				$queued = true;
			}
		} elseif ( 'unpublish' === $authored_policy ) {
			foreach ( array( 'lrn_courses', 'lrn_lessons', 'lrn_quizzes' ) as $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$reassigned += (int) $wpdb->update( $wpdb->prefix . $table, array( 'status' => 'draft' ), array( 'author_id' => $user_id ), array( '%s' ), array( '%d' ) );
			}
		} else {
			$new_owner = self::content_reassign_target();
			foreach ( self::authored_content_tables() as $table => $column ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$reassigned += (int) $wpdb->update( $wpdb->prefix . $table, array( $column => $new_owner ), array( $column => $user_id ), array( '%d' ), array( '%d' ) );
			}
		}

		$anonymized = 0;
		foreach ( self::retained_tables() as $table => $column ) {
			// Financial records are RETAINED (tax/accounting duty) with the person
			// unlinked — the row survives for the books, the data subject does not.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$anonymized += (int) $wpdb->update( $wpdb->prefix . $table, array( $column => 0 ), array( $column => $user_id ), array( '%d' ), array( '%d' ) );
		}

		/**
		 * Fires during a member data-erase so Pro extensions can wipe their own
		 * user-keyed app data (notes, bookmarks, assignment submissions, space
		 * memberships) in the same pass — Pro's own Privacy class also registers
		 * erasers, this hook is the shared cleanup seam.
		 *
		 * @param int $user_id The data subject.
		 */
		do_action( 'learnomy_erase_app_data', $user_id );

		// Report retention HONESTLY. WordPress shows items_retained to the site
		// owner as their compliance record, so claiming "nothing retained" while
		// rows survive is the failure mode this pass exists to fix.
		$messages = array();
		if ( $anonymized > 0 ) {
			$messages[] = __( 'Financial and audit records were kept to meet accounting and security retention duties, with the personal data unlinked so they no longer identify you.', 'learnomy' );
		}
		if ( $queued ) {
			$messages[] = __( 'Content you authored is being permanently deleted in the background; nothing you created is kept.', 'learnomy' );
		}
		if ( $reassigned > 0 ) {
			$messages[] = __( 'Content you authored was transferred to the site administrator so enrolled students keep access; your authorship link was removed.', 'learnomy' );
		}

		return array(
			'items_removed'  => $removed > 0 || $anonymized > 0 || $reassigned > 0 || $queued,
			// Reassigned/unpublished content SURVIVES (with the person unlinked), so
			// that is a retention. A queued delete keeps nothing, so it is not.
			'items_retained' => $anonymized > 0 || $reassigned > 0,
			'messages'       => $messages,
			'done'           => true,
		);
	}

	/**
	 * Run EVERY eraser registered on `wp_privacy_personal_data_erasers` for one
	 * email address, paging each until it reports done.
	 *
	 * This is the single chokepoint for "erase this person's data" outside the
	 * wp-admin Tools → Erase Personal Data screen. Anything that erases a person
	 * — today the self-service account-delete endpoint — calls this instead of
	 * hand-rolling a list of tables, because a hand-rolled list is a second
	 * source of truth that drifts: `User_Cleanup_Service::purge_user_data()`
	 * drifted exactly that way and left push tokens, notifications, lesson
	 * unlocks, instructor applications and every Pro table behind while the
	 * endpoint reported success (Basecamp 10134140053).
	 *
	 * Runs inline and to completion rather than aborting on a time budget: a
	 * half-run erasure that still reports success is the bug this exists to fix.
	 * Erasers are idempotent by contract, so a request that dies on the PHP
	 * timeout leaves the WP user row intact and the student can simply retry —
	 * a slow failure, never a silent one.
	 *
	 * MUST be called BEFORE the WP user row is deleted: every eraser resolves
	 * its subject with `get_user_by( 'email' )` and no-ops once the row is gone.
	 *
	 * @param string $email Data-subject email.
	 * @return array{erasers:int,items_removed:bool,items_retained:bool,messages:string[]}
	 */
	public static function run_registered_erasers( string $email ): array {
		// Reading WordPress core's own eraser registry, exactly as core's
		// wp_ajax_wp_privacy_erase_personal_data() does — not declaring a hook of
		// our own, so the plugin-prefix rule does not apply.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
		if ( ! is_array( $erasers ) ) {
			$erasers = array();
		}

		$ran      = 0;
		$removed  = false;
		$retained = false;
		$messages = array();

		foreach ( $erasers as $eraser ) {
			$callback = is_array( $eraser ) && isset( $eraser['callback'] ) ? $eraser['callback'] : null;
			if ( ! is_callable( $callback ) ) {
				continue;
			}

			++$ran;

			$page = 1;
			do {
				$response = call_user_func( $callback, $email, $page );
				if ( ! is_array( $response ) ) {
					break;
				}

				$removed  = $removed || ! empty( $response['items_removed'] );
				$retained = $retained || ! empty( $response['items_retained'] );
				if ( ! empty( $response['messages'] ) && is_array( $response['messages'] ) ) {
					$messages = array_merge( $messages, $response['messages'] );
				}

				$done = ! empty( $response['done'] );
				++$page;
			} while ( ! $done && $page <= self::MAX_ERASER_PAGES );
		}

		return array(
			'erasers'        => $ran,
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => array_values( array_unique( $messages ) ),
		);
	}

	// ── Exporters ─────────────────────────────────────────────────────────────

	/**
	 * Page one exporter across a SEQUENCE of tables.
	 *
	 * WordPress calls an exporter with $page = 1, 2, 3… and stops the moment it
	 * returns done = true, so a group built from several tables has to present
	 * them as one continuous, stably-ordered stream. This walks the specs in
	 * order, skips whole tables that fall before the requested page, and reports
	 * done only once the last table is exhausted.
	 *
	 * Every multi-table exporter below goes through here, so "the export quietly
	 * stopped at row 50" — already fixed once, per exporter, by hand — cannot come
	 * back one exporter at a time.
	 *
	 * Each spec is:
	 *   model  — a Learnomy model class (reads go through models, never raw SQL).
	 *   column — the user column on that table.
	 *   order  — column to sort by; must exist (junction tables have no `id`).
	 *   group  — export group id, shared by every spec in one document section.
	 *   label  — human group label.
	 *   prefix — item id prefix, unique per table so two rows never collide.
	 *   row    — callable( object $row ): array<int,array{name:string,value:mixed}>.
	 *
	 * @param int                     $user_id Data subject.
	 * @param array<int,array<mixed>> $specs  Tables to walk, in document order.
	 * @param int                     $page    WordPress exporter page.
	 */
	private function export_sequence( int $user_id, array $specs, int $page ): array {
		$skip      = ( max( 1, $page ) - 1 ) * self::PER_PAGE;
		$remaining = self::PER_PAGE;
		$items     = array();
		$more      = false;

		foreach ( $specs as $spec ) {
			$model = $spec['model'];
			$total = (int) $model::count( array( $spec['column'] => $user_id ) );

			// Whole table lies before this page — step over it without querying rows.
			if ( $skip >= $total ) {
				$skip -= $total;
				continue;
			}

			// This page is full but rows remain: tell WordPress to come back.
			if ( $remaining <= 0 ) {
				$more = true;
				break;
			}

			$rows = $model::where( array( $spec['column'] => $user_id ), $spec['order'], 'ASC', $remaining, $skip );
			$skip = 0;

			// Optional batch-resolve of the titles this page's rows reference, so a
			// row formatter can name a lesson or course without one query per row.
			if ( isset( $spec['prime'] ) && $rows ) {
				$spec['prime']( $rows );
			}

			foreach ( $rows as $row ) {
				$items[] = array(
					'group_id'    => $spec['group'],
					'group_label' => $spec['label'],
					'item_id'     => $spec['prefix'] . '-' . ( $row->id ?? md5( wp_json_encode( $row ) ) ),
					'data'        => $spec['row']( $row ),
				);
			}

			$remaining -= count( $rows );
		}

		return array(
			'data' => $items,
			'done' => ! $more,
		);
	}

	/**
	 * Course title for an id, or an honest placeholder. Every exporter that names
	 * a course goes through here so a deleted course reads the same everywhere.
	 */
	private static function course_title( int $course_id ): string {
		$course = $course_id > 0 ? \Learnomy\Models\Course::find( $course_id ) : null;

		return $course ? esc_html( (string) $course->title ) : __( '(deleted course)', 'learnomy' );
	}

	public function export_enrollments( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// WordPress calls this exporter repeatedly with $page=1,2,3… until
		// we return done=true. Paging here keeps memory bounded for power
		// users with thousands of enrollments.
		$per_page = 50;
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$rows     = Enrollment::get_for_user( (int) $user->ID, $per_page, $offset );
		$items    = array();

		foreach ( $rows as $row ) {
			$course  = \Learnomy\Models\Course::find( (int) $row->course_id );
			$items[] = array(
				'group_id'    => 'learnomy-enrollments',
				'group_label' => __( 'Course Enrollments', 'learnomy' ),
				'item_id'     => 'enrollment-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Course', 'learnomy' ),
						'value' => $course ? esc_html( $course->title ) : __( '(deleted course)', 'learnomy' ),
					),
					array(
						'name'  => __( 'Enrolled on', 'learnomy' ),
						'value' => esc_html( $row->enrolled_at ),
					),
					array(
						'name'  => __( 'Status', 'learnomy' ),
						'value' => esc_html( $row->status ),
					),
					array(
						'name'  => __( 'Progress', 'learnomy' ),
						'value' => esc_html( $row->progress_pct ) . '%',
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => count( $rows ) < $per_page,
		);
	}

	public function export_quiz_attempts( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// Page through every attempt — WordPress re-calls with $page=1,2,3…
		// until done=true. Returning done=true after a single capped fetch
		// silently dropped a user's data past row 50 (incomplete GDPR export).
		$per_page = 50;
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$rows     = QuizAttempt::get_for_user( (int) $user->ID, $per_page, $offset );
		$done     = count( $rows ) < $per_page;
		$items    = array();

		// The answers THEMSELVES are the personal data here — a short-answer or
		// essay response is free text the person wrote, and the eraser deletes it.
		// Batch-read them for this page rather than one query per attempt.
		$responses = QuestionResponse::get_for_attempts( array_map( static fn( $a ): int => (int) $a->id, $rows ) );

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'learnomy-quiz-attempts',
				'group_label' => __( 'Quiz Attempts', 'learnomy' ),
				'item_id'     => 'quiz-attempt-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Quiz ID', 'learnomy' ),
						'value' => (int) $row->quiz_id,
					),
					array(
						'name'  => __( 'Score', 'learnomy' ),
						'value' => esc_html( $row->percentage ) . '%',
					),
					array(
						'name'  => __( 'Status', 'learnomy' ),
						'value' => esc_html( $row->status ),
					),
					array(
						'name'  => __( 'Date', 'learnomy' ),
						'value' => esc_html( $row->started_at ),
					),
				),
			);

			foreach ( $responses[ (int) $row->id ] ?? array() as $response ) {
				$items[] = array(
					'group_id'    => 'learnomy-quiz-attempts',
					'group_label' => __( 'Quiz Attempts', 'learnomy' ),
					'item_id'     => 'quiz-answer-' . $response->id,
					'data'        => array(
						array(
							'name'  => __( 'Answer to question', 'learnomy' ),
							'value' => (int) $response->question_id,
						),
						array(
							'name'  => __( 'Your answer', 'learnomy' ),
							'value' => esc_html( wp_strip_all_tags( (string) ( $response->response_json ?? '' ) ) ),
						),
						array(
							'name'  => __( 'Marks', 'learnomy' ),
							'value' => esc_html( (string) $response->score ) . ' / ' . esc_html( (string) $response->max_score ),
						),
						array(
							'name'  => __( 'Feedback given', 'learnomy' ),
							'value' => esc_html( wp_strip_all_tags( (string) ( $response->grader_feedback ?? '' ) ) ),
						),
						array(
							'name'  => __( 'Time spent (seconds)', 'learnomy' ),
							'value' => (int) $response->time_spent_seconds,
						),
					),
				);
			}
		}

		return array(
			'data' => $items,
			'done' => $done,
		);
	}

	public function export_certificates( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$per_page = 50;
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$rows     = Certificate::get_for_user( (int) $user->ID, $per_page, $offset );
		$done     = count( $rows ) < $per_page;
		$items    = array();

		foreach ( $rows as $row ) {
			$course  = \Learnomy\Models\Course::find( (int) $row->course_id );
			$items[] = array(
				'group_id'    => 'learnomy-certificates',
				'group_label' => __( 'Certificates', 'learnomy' ),
				'item_id'     => 'certificate-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Course', 'learnomy' ),
						'value' => $course ? esc_html( $course->title ) : __( '(deleted course)', 'learnomy' ),
					),
					array(
						'name'  => __( 'Certificate ID', 'learnomy' ),
						'value' => esc_html( $row->uuid ),
					),
					array(
						'name'  => __( 'Issued on', 'learnomy' ),
						'value' => esc_html( $row->issued_at ),
					),
					array(
						'name'  => __( 'Status', 'learnomy' ),
						'value' => esc_html( $row->status ),
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => $done,
		);
	}

	/**
	 * The member's terms-of-service acceptance: when, what and from where.
	 *
	 * Single-page by construction — there is exactly one acceptance per account
	 * (recorded at registration), so `done` is always true.
	 */
	public function export_consent( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$consent = \Learnomy\Services\Consent_Service::get( (int) $user->ID );
		if ( null === $consent ) {
			// Accounts created before the consent gate existed, or on a site
			// with no legal pages configured, genuinely have no record. Return
			// nothing rather than inventing one.
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$documents = array();
		foreach ( $consent['documents'] as $kind => $url ) {
			$documents[] = $kind . ': ' . $url;
		}

		return array(
			'data' => array(
				array(
					'group_id'    => 'learnomy-consent',
					'group_label' => __( 'Terms Acceptance', 'learnomy' ),
					'item_id'     => 'consent-' . (int) $user->ID,
					'data'        => array(
						array(
							'name'  => __( 'Accepted on (UTC)', 'learnomy' ),
							'value' => esc_html( $consent['accepted_at'] ),
						),
						array(
							'name'  => __( 'Documents accepted', 'learnomy' ),
							'value' => esc_html( implode( ' | ', $documents ) ),
						),
						array(
							'name'  => __( 'IP address', 'learnomy' ),
							'value' => esc_html( $consent['ip'] ),
						),
					),
				),
			),
			'done' => true,
		);
	}

	public function export_reviews( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$per_page = 50;
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$rows     = Review::where( array( 'user_id' => (int) $user->ID ), 'created_at', 'DESC', $per_page, $offset );
		$done     = count( $rows ) < $per_page;
		$items    = array();

		foreach ( $rows as $row ) {
			$course  = \Learnomy\Models\Course::find( (int) $row->course_id );
			$items[] = array(
				'group_id'    => 'learnomy-reviews',
				'group_label' => __( 'Course Reviews', 'learnomy' ),
				'item_id'     => 'review-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Course', 'learnomy' ),
						'value' => $course ? esc_html( $course->title ) : __( '(deleted course)', 'learnomy' ),
					),
					array(
						'name'  => __( 'Rating', 'learnomy' ),
						'value' => (int) $row->rating . '/5',
					),
					array(
						'name'  => __( 'Review', 'learnomy' ),
						'value' => esc_html( wp_strip_all_tags( $row->content ?? '' ) ),
					),
					array(
						'name'  => __( 'Date', 'learnomy' ),
						'value' => esc_html( $row->created_at ),
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => $done,
		);
	}

	public function export_transactions( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$per_page = 50;
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;
		$rows     = Transaction::get_for_user( (int) $user->ID, $per_page, $offset );
		$done     = count( $rows ) < $per_page;
		$items    = array();

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'    => 'learnomy-transactions',
				'group_label' => __( 'Transactions', 'learnomy' ),
				'item_id'     => 'transaction-' . $row->id,
				'data'        => array(
					array(
						'name'  => __( 'Amount', 'learnomy' ),
						'value' => \Learnomy\format_price( (float) $row->amount, $row->currency ),
					),
					array(
						'name'  => __( 'Type', 'learnomy' ),
						'value' => esc_html( $row->transaction_type ),
					),
					array(
						'name'  => __( 'Status', 'learnomy' ),
						'value' => esc_html( $row->status ),
					),
					array(
						'name'  => __( 'Date', 'learnomy' ),
						'value' => esc_html( $row->created_at ),
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => $done,
		);
	}

	/**
	 * Lesson and course progress, plus the drip unlocks scheduled for them.
	 *
	 * `lrn_progress` is the single largest record of what a person did on the
	 * site — every lesson opened, how long they spent on it, how much of each
	 * video they watched. It was erased on request and never disclosed.
	 */
	public function export_progress( string $email, int $page = 1 ): array {
		$user_id = self::subject_id( $email );
		if ( $user_id < 1 ) {
			return self::empty_export();
		}

		$label = __( 'Learning Progress', 'learnomy' );

		return $this->export_sequence(
			$user_id,
			array(
				array(
					'model'  => Progress::class,
					'column' => 'user_id',
					'order'  => 'id',
					'group'  => 'learnomy-progress',
					'label'  => $label,
					'prefix' => 'progress',
					'prime'  => static function ( array $rows ): void {
						$lesson_ids = array();
						foreach ( $rows as $row ) {
							if ( 'lesson' === $row->item_type ) {
								$lesson_ids[] = (int) $row->item_id;
							}
						}
						if ( $lesson_ids ) {
							// One query for the page's lesson titles instead of one per row.
							Lesson::prime_find_cache( Lesson::find_in( $lesson_ids ) );
						}
					},
					'row'    => static function ( object $row ): array {
						$lesson = 'lesson' === $row->item_type ? Lesson::find( (int) $row->item_id ) : null;

						return array(
							array(
								'name'  => __( 'Item', 'learnomy' ),
								'value' => $lesson
									? esc_html( (string) $lesson->title )
									: sprintf(
										/* translators: 1: item type such as lesson or course, 2: numeric item id */
										__( '%1$s #%2$d', 'learnomy' ),
										esc_html( (string) $row->item_type ),
										(int) $row->item_id
									),
							),
							array(
								'name'  => __( 'Status', 'learnomy' ),
								'value' => esc_html( (string) $row->status ),
							),
							array(
								'name'  => __( 'Time spent (seconds)', 'learnomy' ),
								'value' => (int) $row->time_spent_seconds,
							),
							array(
								'name'  => __( 'Video watched', 'learnomy' ),
								'value' => (int) $row->video_watch_pct . '%',
							),
							array(
								'name'  => __( 'Completed on', 'learnomy' ),
								'value' => esc_html( (string) ( $row->completed_at ?? '' ) ),
							),
							array(
								'name'  => __( 'Last updated', 'learnomy' ),
								'value' => esc_html( (string) $row->updated_at ),
							),
						);
					},
				),
				array(
					'model'  => Lesson_Unlock::class,
					'column' => 'user_id',
					'order'  => 'id',
					'group'  => 'learnomy-progress',
					'label'  => $label,
					'prefix' => 'lesson-unlock',
					'prime'  => static function ( array $rows ): void {
						Lesson::prime_find_cache( Lesson::find_in( array_map( static fn( $r ): int => (int) $r->lesson_id, $rows ) ) );
					},
					'row'    => static function ( object $row ): array {
						$lesson = Lesson::find( (int) $row->lesson_id );

						return array(
							array(
								'name'  => __( 'Scheduled lesson', 'learnomy' ),
								'value' => $lesson ? esc_html( (string) $lesson->title ) : sprintf( '#%d', (int) $row->lesson_id ),
							),
							array(
								'name'  => __( 'Unlocks on', 'learnomy' ),
								'value' => esc_html( (string) $row->unlocks_at ),
							),
							array(
								'name'  => __( 'Scheduled on', 'learnomy' ),
								'value' => esc_html( (string) $row->created_at ),
							),
						);
					},
				),
			),
			$page
		);
	}

	/**
	 * In-app notifications the person received. The message text is disclosed in
	 * full because they already received it; the numeric actor link is withheld
	 * (see withheld_columns()).
	 */
	public function export_notifications( string $email, int $page = 1 ): array {
		$user_id = self::subject_id( $email );
		if ( $user_id < 1 ) {
			return self::empty_export();
		}

		return $this->export_sequence(
			$user_id,
			array(
				array(
					'model'  => Notification::class,
					'column' => 'user_id',
					'order'  => 'id',
					'group'  => 'learnomy-notifications',
					'label'  => __( 'Notifications', 'learnomy' ),
					'prefix' => 'notification',
					'row'    => static fn( object $row ): array => array(
						array(
							'name'  => __( 'Subject', 'learnomy' ),
							'value' => esc_html( (string) ( $row->title ?? '' ) ),
						),
						array(
							'name'  => __( 'Message', 'learnomy' ),
							'value' => esc_html( wp_strip_all_tags( (string) $row->message ) ),
						),
						array(
							'name'  => __( 'Type', 'learnomy' ),
							'value' => esc_html( (string) $row->type ),
						),
						array(
							'name'  => __( 'Read', 'learnomy' ),
							'value' => $row->is_read ? __( 'Yes', 'learnomy' ) : __( 'No', 'learnomy' ),
						),
						array(
							'name'  => __( 'Received on', 'learnomy' ),
							'value' => esc_html( (string) $row->created_at ),
						),
					),
				),
			),
			$page
		);
	}

	/**
	 * Sign-in sessions and registered devices.
	 *
	 * The most surprising thing we hold: every API session row carries the IP
	 * address and browser user agent captured when it was issued. That is squarely
	 * personal data under Recital 30 and it was erased on request while never
	 * being disclosed. The session credential itself (`token_hash`) and the full
	 * push token are withheld — see withheld_columns().
	 */
	public function export_sessions( string $email, int $page = 1 ): array {
		$user_id = self::subject_id( $email );
		if ( $user_id < 1 ) {
			return self::empty_export();
		}

		$label = __( 'Sign-ins and Devices', 'learnomy' );

		return $this->export_sequence(
			$user_id,
			array(
				array(
					'model'  => Token::class,
					'column' => 'user_id',
					'order'  => 'id',
					'group'  => 'learnomy-sessions',
					'label'  => $label,
					'prefix' => 'session',
					'row'    => static fn( object $row ): array => array(
						array(
							'name'  => __( 'IP address', 'learnomy' ),
							'value' => esc_html( (string) ( $row->ip_address ?? '' ) ),
						),
						array(
							'name'  => __( 'Browser or app', 'learnomy' ),
							'value' => esc_html( (string) ( $row->user_agent ?? '' ) ),
						),
						array(
							'name'  => __( 'Signed in on', 'learnomy' ),
							'value' => esc_html( (string) $row->created_at ),
						),
						array(
							'name'  => __( 'Expires on', 'learnomy' ),
							'value' => esc_html( (string) $row->expires_at ),
						),
						array(
							'name'  => __( 'Signed out on', 'learnomy' ),
							'value' => esc_html( (string) ( $row->revoked_at ?? '' ) ),
						),
					),
				),
				array(
					'model'  => Device::class,
					'column' => 'user_id',
					'order'  => 'id',
					'group'  => 'learnomy-sessions',
					'label'  => $label,
					'prefix' => 'device',
					'row'    => static fn( object $row ): array => array(
						array(
							'name'  => __( 'Platform', 'learnomy' ),
							'value' => esc_html( (string) $row->platform ),
						),
						array(
							'name'  => __( 'Push token', 'learnomy' ),
							// Masked on purpose: the full value is a credential that
							// would let anyone holding this file push to the handset.
							'value' => sprintf(
								/* translators: %s: last four characters of a device push token */
								__( 'Registered, ending %s', 'learnomy' ),
								esc_html( substr( (string) $row->device_token, -4 ) )
							),
						),
						array(
							'name'  => __( 'Registered on', 'learnomy' ),
							'value' => esc_html( (string) $row->created_at ),
						),
					),
				),
			),
			$page
		);
	}

	/**
	 * The security/audit trail of actions the person took.
	 *
	 * Retained after erasure on a security basis, which makes it exactly the kind
	 * of record a subject access request must still disclose while the person
	 * exists. `metadata_json` is withheld — see withheld_columns().
	 */
	public function export_activity_log( string $email, int $page = 1 ): array {
		$user_id = self::subject_id( $email );
		if ( $user_id < 1 ) {
			return self::empty_export();
		}

		return $this->export_sequence(
			$user_id,
			array(
				array(
					'model'  => AuditLog::class,
					'column' => 'user_id',
					'order'  => 'id',
					'group'  => 'learnomy-activity-log',
					'label'  => __( 'Account Activity Log', 'learnomy' ),
					'prefix' => 'activity',
					'row'    => static fn( object $row ): array => array(
						array(
							'name'  => __( 'Action', 'learnomy' ),
							'value' => esc_html( (string) $row->action ),
						),
						array(
							'name'  => __( 'Item', 'learnomy' ),
							'value' => esc_html( (string) $row->object_type ) . ' #' . (int) $row->object_id,
						),
						array(
							'name'  => __( 'IP address', 'learnomy' ),
							'value' => esc_html( (string) ( $row->ip_address ?? '' ) ),
						),
						array(
							'name'  => __( 'Recorded on', 'learnomy' ),
							'value' => esc_html( (string) $row->created_at ),
						),
					),
				),
			),
			$page
		);
	}

	/**
	 * Members the person chose to block.
	 *
	 * ONE SIDE ONLY. Blocks they made are their own action and already visible in
	 * their block list. Blocks other members made AGAINST them are those members'
	 * data, and disclosing them is the harm Art. 15(4) exists to prevent — so the
	 * eraser clears both sides of this table and the exporter discloses one.
	 */
	public function export_blocked_members( string $email, int $page = 1 ): array {
		$user_id = self::subject_id( $email );
		if ( $user_id < 1 ) {
			return self::empty_export();
		}

		return $this->export_sequence(
			$user_id,
			array(
				array(
					'model'  => Blocked_User::class,
					'column' => 'blocker_id',
					'order'  => 'id',
					'group'  => 'learnomy-blocked',
					'label'  => __( 'Blocked Members', 'learnomy' ),
					'prefix' => 'block',
					'row'    => static function ( object $row ): array {
						$blocked = get_userdata( (int) $row->blocked_id );

						return array(
							array(
								'name'  => __( 'Blocked member', 'learnomy' ),
								'value' => $blocked ? esc_html( $blocked->display_name ) : __( '(deleted member)', 'learnomy' ),
							),
							array(
								'name'  => __( 'Blocked on', 'learnomy' ),
								'value' => esc_html( (string) $row->created_at ),
							),
						);
					},
				),
			),
			$page
		);
	}

	/**
	 * Instructor identity: the public profile, the application that created it
	 * (free-text bio, expertise, teaching history, course pitch), the courses they
	 * are assigned to teach and their revenue-share, and the monthly engagement
	 * counts that feed membership commission splits.
	 */
	public function export_instructor_profile( string $email, int $page = 1 ): array {
		$user_id = self::subject_id( $email );
		if ( $user_id < 1 ) {
			return self::empty_export();
		}

		$label = __( 'Instructor Profile', 'learnomy' );

		return $this->export_sequence(
			$user_id,
			array(
				array(
					'model'  => Instructor::class,
					'column' => 'user_id',
					'order'  => 'id',
					'group'  => 'learnomy-instructor',
					'label'  => $label,
					'prefix' => 'instructor-profile',
					'row'    => static fn( object $row ): array => array(
						array(
							'name'  => __( 'Headline', 'learnomy' ),
							'value' => esc_html( (string) ( $row->headline ?? '' ) ),
						),
						array(
							'name'  => __( 'Biography', 'learnomy' ),
							'value' => esc_html( wp_strip_all_tags( (string) ( $row->bio ?? '' ) ) ),
						),
						array(
							'name'  => __( 'Expertise', 'learnomy' ),
							'value' => esc_html( (string) ( $row->expertise ?? '' ) ),
						),
						array(
							'name'  => __( 'Location', 'learnomy' ),
							'value' => esc_html( (string) ( $row->location ?? '' ) ),
						),
						array(
							'name'  => __( 'Website', 'learnomy' ),
							'value' => esc_html( (string) ( $row->website_url ?? '' ) ),
						),
						array(
							'name'  => __( 'Social profiles', 'learnomy' ),
							'value' => esc_html( trim( (string) ( $row->twitter_handle ?? '' ) . ' ' . (string) ( $row->linkedin_url ?? '' ) ) ),
						),
						array(
							'name'  => __( 'Verification status', 'learnomy' ),
							'value' => esc_html( (string) $row->verification_status ),
						),
						array(
							'name'  => __( 'Profile created on', 'learnomy' ),
							'value' => esc_html( (string) $row->created_at ),
						),
					),
				),
				array(
					'model'  => InstructorApplication::class,
					'column' => 'user_id',
					'order'  => 'id',
					'group'  => 'learnomy-instructor',
					'label'  => $label,
					'prefix' => 'instructor-application',
					'row'    => static fn( object $row ): array => array(
						array(
							'name'  => __( 'Biography', 'learnomy' ),
							'value' => esc_html( wp_strip_all_tags( (string) ( $row->bio ?? '' ) ) ),
						),
						array(
							'name'  => __( 'Expertise', 'learnomy' ),
							'value' => esc_html( (string) ( $row->expertise ?? '' ) ),
						),
						array(
							'name'  => __( 'Teaching experience', 'learnomy' ),
							'value' => esc_html( (string) ( $row->teaching_experience ?? '' ) ),
						),
						array(
							'name'  => __( 'Course idea', 'learnomy' ),
							'value' => esc_html( wp_strip_all_tags( (string) ( $row->course_idea ?? '' ) ) ),
						),
						array(
							'name'  => __( 'Payout method', 'learnomy' ),
							// The payout DETAILS are withheld — see withheld_columns().
							'value' => esc_html( (string) ( $row->payout_method ?? '' ) ),
						),
						array(
							'name'  => __( 'Status', 'learnomy' ),
							'value' => esc_html( (string) $row->status ),
						),
						array(
							'name'  => __( 'Reason given', 'learnomy' ),
							'value' => esc_html( wp_strip_all_tags( (string) ( $row->rejection_reason ?? '' ) ) ),
						),
						array(
							'name'  => __( 'Applied on', 'learnomy' ),
							'value' => esc_html( (string) $row->created_at ),
						),
						array(
							'name'  => __( 'Reviewed on', 'learnomy' ),
							'value' => esc_html( (string) ( $row->reviewed_at ?? '' ) ),
						),
					),
				),
				array(
					'model'  => CourseInstructor::class,
					'column' => 'user_id',
					// This junction has no `id` — its key is (course_id, user_id).
					'order'  => 'added_at',
					'group'  => 'learnomy-instructor',
					'label'  => $label,
					'prefix' => 'teaching-assignment',
					'row'    => static fn( object $row ): array => array(
						array(
							'name'  => __( 'Teaching', 'learnomy' ),
							'value' => self::course_title( (int) $row->course_id ),
						),
						array(
							'name'  => __( 'Revenue share', 'learnomy' ),
							'value' => esc_html( (string) $row->share_pct ) . '%',
						),
						array(
							'name'  => __( 'Lead instructor', 'learnomy' ),
							'value' => $row->is_primary ? __( 'Yes', 'learnomy' ) : __( 'No', 'learnomy' ),
						),
						array(
							'name'  => __( 'Assigned on', 'learnomy' ),
							'value' => esc_html( (string) $row->added_at ),
						),
					),
				),
				array(
					'model'  => EngagementMonthly::class,
					'column' => 'instructor_id',
					'order'  => 'id',
					'group'  => 'learnomy-instructor',
					'label'  => $label,
					'prefix' => 'engagement',
					'row'    => static fn( object $row ): array => array(
						array(
							'name'  => __( 'Month', 'learnomy' ),
							'value' => esc_html( (string) $row->period ),
						),
						array(
							'name'  => __( 'Lessons consumed by members', 'learnomy' ),
							'value' => (int) $row->lesson_count,
						),
					),
				),
			),
			$page
		);
	}

	/**
	 * Subscriptions and coupon use.
	 *
	 * `lrn_member_subscriptions` is a retained_tables() row: it survives an
	 * erasure with the person unlinked, for the same accounting duty as a
	 * transaction. Data we keep on a legal basis is still data we hold, so it is
	 * disclosed here while the person exists.
	 */
	public function export_billing( string $email, int $page = 1 ): array {
		$user_id = self::subject_id( $email );
		if ( $user_id < 1 ) {
			return self::empty_export();
		}

		$label = __( 'Subscriptions and Coupons', 'learnomy' );

		return $this->export_sequence(
			$user_id,
			array(
				array(
					'model'  => Subscription::class,
					'column' => 'user_id',
					'order'  => 'id',
					'group'  => 'learnomy-billing',
					'label'  => $label,
					'prefix' => 'subscription',
					'row'    => static fn( object $row ): array => array(
						array(
							'name'  => __( 'Amount', 'learnomy' ),
							'value' => null === $row->amount ? '' : \Learnomy\format_price( (float) $row->amount ),
						),
						array(
							'name'  => __( 'Billing interval', 'learnomy' ),
							'value' => esc_html( (string) ( $row->billing_interval ?? '' ) ),
						),
						array(
							'name'  => __( 'Status', 'learnomy' ),
							'value' => esc_html( (string) $row->status ),
						),
						array(
							'name'  => __( 'Current period', 'learnomy' ),
							'value' => esc_html( (string) ( $row->current_period_start ?? '' ) . ' - ' . (string) ( $row->current_period_end ?? '' ) ),
						),
						array(
							'name'  => __( 'Trial ends', 'learnomy' ),
							'value' => esc_html( (string) ( $row->trial_ends_at ?? '' ) ),
						),
						array(
							'name'  => __( 'Cancelled on', 'learnomy' ),
							'value' => esc_html( (string) ( $row->cancelled_at ?? '' ) ),
						),
						array(
							'name'  => __( 'Started on', 'learnomy' ),
							'value' => esc_html( (string) $row->created_at ),
						),
					),
				),
				array(
					'model'  => CouponUsage::class,
					'column' => 'user_id',
					'order'  => 'id',
					'group'  => 'learnomy-billing',
					'label'  => $label,
					'prefix' => 'coupon-use',
					'row'    => static function ( object $row ): array {
						$coupon = \Learnomy\Models\Coupon::find( (int) $row->coupon_id );

						return array(
							array(
								'name'  => __( 'Coupon', 'learnomy' ),
								'value' => $coupon ? esc_html( (string) $coupon->code ) : __( '(deleted coupon)', 'learnomy' ),
							),
							array(
								'name'  => __( 'Used on', 'learnomy' ),
								'value' => esc_html( (string) $row->created_at ),
							),
						);
					},
				),
			),
			$page
		);
	}

	/**
	 * Money the site owes or has paid the person as an instructor.
	 *
	 * Both tables are retained_tables() rows — kept after erasure for the
	 * accounting duty, so they are disclosed while the person exists.
	 * `destination` is the payee identifier they themselves supplied, so it is
	 * their data and is disclosed.
	 */
	public function export_earnings( string $email, int $page = 1 ): array {
		$user_id = self::subject_id( $email );
		if ( $user_id < 1 ) {
			return self::empty_export();
		}

		$label = __( 'Earnings and Payouts', 'learnomy' );

		return $this->export_sequence(
			$user_id,
			array(
				array(
					'model'  => Commission::class,
					'column' => 'recipient_id',
					'order'  => 'id',
					'group'  => 'learnomy-earnings',
					'label'  => $label,
					'prefix' => 'commission',
					'row'    => static fn( object $row ): array => array(
						array(
							'name'  => __( 'Amount earned', 'learnomy' ),
							'value' => \Learnomy\format_price( (float) $row->amount, $row->currency ),
						),
						array(
							'name'  => __( 'Rate applied', 'learnomy' ),
							'value' => esc_html( (string) $row->rate_applied ) . '%',
						),
						array(
							'name'  => __( 'Payout status', 'learnomy' ),
							'value' => esc_html( (string) $row->payout_status ),
						),
						array(
							'name'  => __( 'Paid on', 'learnomy' ),
							'value' => esc_html( (string) ( $row->paid_at ?? '' ) ),
						),
						array(
							'name'  => __( 'Earned on', 'learnomy' ),
							'value' => esc_html( (string) $row->created_at ),
						),
					),
				),
				array(
					'model'  => Withdrawal::class,
					'column' => 'instructor_id',
					'order'  => 'id',
					'group'  => 'learnomy-earnings',
					'label'  => $label,
					'prefix' => 'withdrawal',
					'row'    => static fn( object $row ): array => array(
						array(
							'name'  => __( 'Amount requested', 'learnomy' ),
							'value' => \Learnomy\format_price( (float) $row->amount, $row->currency ),
						),
						array(
							'name'  => __( 'Method', 'learnomy' ),
							'value' => esc_html( (string) ( $row->method ?? '' ) ),
						),
						array(
							'name'  => __( 'Paid to', 'learnomy' ),
							'value' => esc_html( (string) ( $row->destination ?? '' ) ),
						),
						array(
							'name'  => __( 'Status', 'learnomy' ),
							'value' => esc_html( (string) $row->status ),
						),
						array(
							'name'  => __( 'Reason given', 'learnomy' ),
							'value' => esc_html( (string) ( $row->rejection_reason ?? '' ) ),
						),
						array(
							'name'  => __( 'Requested on', 'learnomy' ),
							'value' => esc_html( (string) $row->requested_at ),
						),
						array(
							'name'  => __( 'Processed on', 'learnomy' ),
							'value' => esc_html( (string) ( $row->processed_at ?? '' ) ),
						),
					),
				),
			),
			$page
		);
	}

	/**
	 * An INVENTORY of the content the person authored — not a copy of it.
	 *
	 * The personal datum in an authored_content_tables() row is the authorship
	 * link: the fact that this person created this course on this date. The course
	 * BODY is the site owner's catalogue and other students' curriculum, and it is
	 * already fully available to its author through the instructor dashboard and
	 * the REST API. Disclosing title, type, status and date answers "what do you
	 * hold that is about me?" without shipping the site's catalogue in a personal
	 * data file.
	 */
	public function export_authored_content( string $email, int $page = 1 ): array {
		$user_id = self::subject_id( $email );
		if ( $user_id < 1 ) {
			return self::empty_export();
		}

		$label = __( 'Authored Content', 'learnomy' );
		$specs = array();

		$authored = array(
			array( Course::class, 'course', __( 'Course', 'learnomy' ) ),
			array( Lesson::class, 'lesson', __( 'Lesson', 'learnomy' ) ),
			array( Quiz::class, 'quiz', __( 'Quiz', 'learnomy' ) ),
			array( Question::class, 'question', __( 'Question', 'learnomy' ) ),
			array( CertificateTemplate::class, 'certificate-template', __( 'Certificate design', 'learnomy' ) ),
		);

		foreach ( $authored as list( $model, $prefix, $type_label ) ) {
			$specs[] = array(
				'model'  => $model,
				'column' => 'author_id',
				'order'  => 'id',
				'group'  => 'learnomy-authored',
				'label'  => $label,
				'prefix' => $prefix,
				'row'    => static fn( object $row ): array => array(
					array(
						'name'  => __( 'Type', 'learnomy' ),
						'value' => $type_label,
					),
					array(
						'name'  => __( 'Title', 'learnomy' ),
						'value' => esc_html( (string) $row->title ),
					),
					array(
						'name'  => __( 'Status', 'learnomy' ),
						'value' => esc_html( (string) $row->status ),
					),
					array(
						'name'  => __( 'Created on', 'learnomy' ),
						'value' => esc_html( (string) $row->created_at ),
					),
				),
			);
		}

		return $this->export_sequence( $user_id, $specs, $page );
	}

	/**
	 * Resolve the data subject, or 0 when the address matches no account.
	 * Every exporter opens with this, so "unknown email" behaves identically
	 * across all of them.
	 */
	private static function subject_id( string $email ): int {
		$user = get_user_by( 'email', $email );

		return $user ? (int) $user->ID : 0;
	}

	/** The WordPress exporter envelope for "nothing to disclose". */
	private static function empty_export(): array {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	// ── Erasers ───────────────────────────────────────────────────────────────

	public function erase_enrollments( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id = (int) $user->ID;

		// Delete progress records tied to this user's lessons.
		Progress::delete_for_user( $user_id );

		// Delete enrollments.
		$deleted = Enrollment::delete_for_user( $user_id );

		return array(
			'items_removed'  => $deleted > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	public function erase_quiz_attempts( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id = (int) $user->ID;

		// Count first: the report below is the site owner's AND the data
		// subject's compliance record, and "quiz scores were retained" told to
		// someone who never took a quiz is as wrong as staying silent about a
		// real retention.
		$total = QuizAttempt::count( array( 'user_id' => $user_id ) );
		if ( $total < 1 ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// Delete responses for EVERY attempt, not just the first page.
		// get_for_user() defaults to a 50-row limit; paging through all of them
		// is required or a power user's question_responses (free-text essay /
		// short answers = personal data) survive a "completed" erasure. The
		// attempt rows are not deleted here (anonymized below), so offset paging
		// is stable across the loop.
		$batch  = 500;
		$offset = 0;
		do {
			$attempts      = QuizAttempt::get_for_user( $user_id, $batch, $offset );
			$attempt_count = count( $attempts );
			$attempt_ids   = array_map( fn( $a ) => (int) $a->id, $attempts );
			if ( $attempt_ids ) {
				QuestionResponse::delete_for_attempts( $attempt_ids );
			}
			$offset += $batch;
		} while ( $attempt_count === $batch );

		// Anonymize the attempt rows: zero out user_id, clear IP (covers all rows).
		QuizAttempt::anonymize_for_user( $user_id );

		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => array( __( 'Quiz attempt scores retained for statistical reporting with personal data removed.', 'learnomy' ) ),
			'done'           => true,
		);
	}

	public function erase_certificates( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id = (int) $user->ID;

		/**
		 * How a certificate is erased for a GDPR request.
		 *
		 * 'delete'    (default) — remove the rows outright; /verify/{uuid} 404s.
		 *                         The person asked to be forgotten, so the
		 *                         credential and its public proof go with them.
		 * 'anonymize'           — keep the issuance record with the person
		 *                         stripped out (user link + signature cleared),
		 *                         for an owner who must evidence that a
		 *                         certificate was issued without holding the
		 *                         holder's identity.
		 *
		 * Previously this eraser only called revoke(), which flips `status` but
		 * leaves the uuid, signature and user link in place — the holder stayed
		 * identifiable and /verify/{uuid} still resolved — while reporting
		 * items_retained = false. That combination (data kept + "nothing kept"
		 * reported to the site owner) is the part that actually breaks Art. 17
		 * (Basecamp 10109167870).
		 *
		 * @param string $policy 'delete' | 'anonymize'.
		 */
		$policy = (string) apply_filters( 'learnomy_privacy_certificate_erasure', 'delete' );

		if ( 'anonymize' === $policy ) {
			$affected = Certificate::anonymize_for_user( $user_id );

			return array(
				'items_removed'  => $affected > 0,
				'items_retained' => $affected > 0,
				'messages'       => $affected > 0
					? array( __( 'Certificate issuance records were kept for the site owner\'s records with all personal data removed; they can no longer identify or verify the holder.', 'learnomy' ) )
					: array(),
				'done'           => true,
			);
		}

		$deleted = Certificate::delete_for_user( $user_id );

		return array(
			'items_removed'  => $deleted > 0,
			'items_retained' => false,
			'messages'       => $deleted > 0
				? array( __( 'Certificates were deleted; their public verification links no longer resolve.', 'learnomy' ) )
				: array(),
			'done'           => true,
		);
	}

	public function erase_reviews( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id = (int) $user->ID;

		// Count first — see erase_quiz_attempts(): report only what happened.
		$total = Review::count( array( 'user_id' => $user_id ) );
		if ( $total < 1 ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// Anonymize: keep the rating for course stats, wipe identifying text.
		Review::anonymize_for_user( $user_id );

		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => array( __( 'Review ratings retained for course statistics with personal content removed.', 'learnomy' ) ),
			'done'           => true,
		);
	}

	public function erase_transactions( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$user_id = (int) $user->ID;

		// Count first — see erase_quiz_attempts(): report only what happened.
		$total = Transaction::count( array( 'user_id' => $user_id ) );
		if ( $total < 1 ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		// Retain financial record for accounting; remove the user link and provider ID.
		Transaction::anonymize_for_user( $user_id );

		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => array( __( 'Transaction financial records retained for accounting purposes with personal data removed.', 'learnomy' ) ),
			'done'           => true,
		);
	}
}
