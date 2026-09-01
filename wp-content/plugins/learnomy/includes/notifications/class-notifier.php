<?php
/**
 * Unified notification dispatcher for all Learnomy action hooks.
 *
 * Listens to plugin events and creates both in-app notifications and
 * email messages through the existing Notification_Service.
 *
 * @package Learnomy\Notifications
 */

namespace Learnomy\Notifications;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\Review;
use Learnomy\Models\Transaction;
use Learnomy\Models\Withdrawal;
use Learnomy\Services\Notification_Service;

class Notifier {

	private Notification_Service $notifications;

	public function __construct() {
		$this->notifications = new Notification_Service();

		// Apply the site owner's Sender Name / Sender Email choices (Settings ->
		// Emails) to every outgoing wp_mail() call — Learnomy notifications plus
		// WordPress core mail such as password reset. Priority 20 sits above WP
		// core's default (10) and below most third-party overrides (30+) so the
		// customer's UI choice wins by default while explicit integrations can
		// still take precedence. Wave 2B (2026-05-16) fix.
		add_filter( 'wp_mail_from', array( Notification_Service::class, 'filter_mail_from' ), 20 );
		add_filter( 'wp_mail_from_name', array( Notification_Service::class, 'filter_mail_from_name' ), 20 );

		// Course events — hooks fire with (entity_id, user_id, course_id/quiz_id).
		add_action( 'learnomy_student_enrolled', array( $this, 'on_enrolled' ), 10, 4 );
		add_action( 'learnomy_course_completed', array( $this, 'on_course_completed' ), 10, 3 );
		add_action( 'learnomy_certificate_issued', array( $this, 'on_certificate_issued' ), 10, 3 );

		// Lesson drip — fires once per (lesson, user) when a previously-locked
		// lesson becomes available. Plan 06 §9 / §10. Producer is `Drip_Cron`.
		add_action( 'learnomy_lesson_unlocked', array( $this, 'on_lesson_unlocked' ), 10, 3 );

		// Quiz events.
		add_action( 'learnomy_quiz_submitted', array( $this, 'on_quiz_submitted' ), 10, 3 );
		add_action( 'learnomy_quiz_graded', array( $this, 'on_quiz_graded' ), 10, 3 );

		// Instructor events.
		add_action( 'learnomy_instructor_application_submitted', array( $this, 'on_application_submitted' ), 10, 2 );
		add_action( 'learnomy_instructor_application_approved', array( $this, 'on_application_approved' ), 10, 2 );
		// 3 accepted args: the hook carries the reviewer's feedback as the third,
		// and the handler shows it to the applicant.
		add_action( 'learnomy_instructor_application_rejected', array( $this, 'on_application_rejected' ), 10, 3 );

		// Payment events.
		add_action( 'learnomy_payment_completed', array( $this, 'on_payment_completed' ), 10, 3 );
		add_action( 'learnomy_withdrawal_approved', array( $this, 'on_withdrawal_approved' ), 10, 2 );

		// The other three ends of the payout lifecycle. All three fired with
		// ZERO listeners until 2026-07-30: a rejected withdrawal told the
		// instructor nothing (their balance simply reappeared with no
		// explanation), and a Stripe transfer that succeeded or failed notified
		// nobody at all — the instructor could not tell a paid payout from a
		// stuck one, and the site owner never learned a transfer had bounced.
		add_action( 'learnomy_withdrawal_rejected', array( $this, 'on_withdrawal_rejected' ), 10, 3 );
		add_action( 'learnomy_withdrawal_paid', array( $this, 'on_withdrawal_paid' ), 10, 2 );
		add_action( 'learnomy_withdrawal_failed', array( $this, 'on_withdrawal_failed' ), 10, 2 );

		// Review events.
		add_action( 'learnomy_review_submitted', array( $this, 'on_review_submitted' ), 10, 3 );

		// Membership events. Canonical hook names per docs/MEMBERSHIP-PRO-PLAN.md §2.
		// Subscription_Service fires both `learnomy_subscription_*` (new) and
		// `learnomy_membership_*` (legacy) variants; we attach to the new names
		// so Pro and third-party extensions see a single coherent contract.
		// accepted_args includes the trailing context_type — 'plan' (a membership)
		// or 'course' (a course-level recurring sub). These hooks pass DIFFERENT
		// entity ids in arg 3 depending on that flag, so a listener that stops at
		// arg 3 reads a course_id as a plan_id and emails the wrong (or a generic)
		// name on a payment event. created/expired put context_type at arg 4;
		// cancelled at arg 5 (arg 4 is $immediate).
		add_action( 'learnomy_subscription_created', array( $this, 'on_membership_created' ), 10, 4 );
		add_action( 'learnomy_subscription_cancelled', array( $this, 'on_membership_cancelled' ), 10, 5 );
		add_action( 'learnomy_subscription_renewed', array( $this, 'on_membership_renewed' ), 10, 3 );
		add_action( 'learnomy_subscription_expired', array( $this, 'on_membership_expired' ), 10, 4 );
		add_action( 'learnomy_subscription_trial_ending', array( $this, 'on_subscription_trial_ending' ), 10, 4 );

		// Lesson comment events.
		add_action( 'learnomy_lesson_comment_posted', array( $this, 'on_lesson_comment' ), 10, 3 );
		add_action( 'learnomy_lesson_comment_replied', array( $this, 'on_lesson_comment_reply' ), 10, 4 );
	}

	/**
	 * Send a notification with its strings resolved in the RECIPIENT's locale
	 * (i18n R7). The $build closure returns the notification data array; it is
	 * invoked while the recipient's locale is active so every __()/sprintf in it
	 * resolves for the reader, not the site/actor. No-op switch when the
	 * recipient's locale already matches the current one.
	 *
	 * @param int      $recipient_id Target user ID.
	 * @param string   $type         Notification type key.
	 * @param callable $build        () => array  notification data.
	 */
	private function notify( int $recipient_id, string $type, callable $build ): void {
		$locale   = $recipient_id > 0 ? get_user_locale( $recipient_id ) : '';
		$switched = ( '' !== $locale && $locale !== determine_locale() ) ? switch_to_locale( $locale ) : false;
		try {
			$this->notifications->send( $recipient_id, $type, $build() );
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Has this recipient already been told about this withdrawal reaching this
	 * state?
	 *
	 * A withdrawal reaches each terminal state exactly once, but the hooks that
	 * announce it are public and can be re-fired — Pro\'s Stripe rail replaying
	 * `learnomy_withdrawal_paid` after a retry, a third-party integration, a
	 * webhook redelivery. The settlement write itself is already idempotent;
	 * this keeps the EMAIL idempotent too, so a retry never tells an instructor
	 * twice that they have been paid. Uses the existing per-object dedupe seam
	 * (`user_type_object` index), not a new one.
	 */
	private function already_notified( int $user_id, string $type, int $withdrawal_id ): bool {
		return $user_id > 0
			&& $withdrawal_id > 0
			&& \Learnomy\Models\Notification::exists_for_object( $user_id, $type, 'withdrawal', $withdrawal_id );
	}

	/**
	 * Fires when a student is enrolled in a course.
	 *
	 * @param int    $enrollment_id Enrollment ID.
	 * @param int    $user_id       Student user ID.
	 * @param int    $course_id     Course ID.
	 * @param string $source        Enrollment source.
	 */
	public function on_enrolled( int $enrollment_id, int $user_id, int $course_id, string $source = 'free' ): void {
		$course = Course::find( $course_id );
		if ( ! $course ) {
			return;
		}

		$user       = get_user_by( 'ID', $user_id );
		$course_url = \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );

		// Customer-mindset dedupe (Basecamp #9932113730): when the
		// enrollment is created by a paid checkout, `on_payment_completed`
		// is already sending the student a receipt that names the course
		// + links to "Start Learning". A separate "Welcome to X" email
		// fires within ~200ms and reads as a duplicate. Skip the student-
		// side enrollment email for `source=purchase` (and the legacy
		// alias `subscription`). Free / manual / membership-claim
		// enrollments still get the welcome email because no receipt
		// precedes them. Instructor notification below still fires
		// regardless of source.
		$skip_student_email = in_array( $source, array( 'purchase', 'subscription' ), true );

		if ( ! $skip_student_email ) {
			$this->notify(
				$user_id,
				'enrollment',
				fn() => array(
					/* translators: %s: course title */
					'title'        => sprintf( __( 'You are enrolled in %s.', 'learnomy' ), $course->title ),
					'message'      => sprintf(
						/* translators: %s: course title */
						__( 'Welcome. You are now enrolled in "%s". Start learning today.', 'learnomy' ),
						$course->title
					),
					'object_type'  => 'course',
					'object_id'    => $course_id,
					'action_url'   => $course_url,
					'action_label' => __( 'Start Learning', 'learnomy' ),
					'student_name' => $user ? $user->display_name : '',
					'course_title' => $course->title,
					'course_url'   => $course_url,
				)
			);
		}

		// Notify the course instructor(s) that a student joined. Covers
		// multiple instructors via the junction; falls back to the course
		// author when no junction rows exist. Skips a self-enrolling
		// instructor and de-dupes repeated ids.
		$student_name   = $user ? $user->display_name : __( 'A student', 'learnomy' );
		$instructor_ids = array();
		foreach ( CourseInstructor::get_for_course( $course_id ) as $row ) {
			$instructor_ids[] = (int) ( $row->user_id ?? 0 );
		}
		if ( empty( array_filter( $instructor_ids ) ) && ! empty( $course->author_id ) ) {
			$instructor_ids[] = (int) $course->author_id;
		}

		$notified = array();
		foreach ( $instructor_ids as $instructor_id ) {
			if ( $instructor_id < 1 || $instructor_id === $user_id || isset( $notified[ $instructor_id ] ) ) {
				continue;
			}
			$notified[ $instructor_id ] = true;

			$this->notify(
				$instructor_id,
				'instructor_new_enrollment',
				fn() => array(
					/* translators: %s: course title */
					'title'        => sprintf( __( 'New enrollment in %s', 'learnomy' ), $course->title ),
					'message'      => sprintf(
						/* translators: 1: student name, 2: course title */
						__( '%1$s just enrolled in your course "%2$s".', 'learnomy' ),
						$student_name,
						$course->title
					),
					'object_type'  => 'course',
					'object_id'    => $course_id,
					'action_url'   => $course_url,
					'action_label' => __( 'View Course', 'learnomy' ),
					'student_name' => $student_name,
					'course_title' => $course->title,
				)
			);
		}
	}

	/**
	 * Fires when a student completes a course.
	 *
	 * @param int $enrollment_id Enrollment ID.
	 * @param int $user_id       Student user ID.
	 * @param int $course_id     Course ID.
	 */
	public function on_course_completed( int $enrollment_id, int $user_id, int $course_id ): void {
		$course = Course::find( $course_id );
		if ( ! $course ) {
			return;
		}

		// Customer-mindset dedupe (Basecamp #9932113730): when the course
		// has cert auto-issue enabled, `learnomy_certificate_issued` is
		// about to fire in the same request. That listener sends a
		// "Your certificate for X is ready" email that already announces
		// completion + provides the cert link. Sending a separate
		// "You completed X" email here just clutters the student's inbox.
		// Cert email's copy was updated to lead with congrats so nothing
		// is lost.
		//
		// Ask the SAME question the issue path asks. This read the per-course
		// flag alone, while auto-issue fires on "per-course OR the site-wide
		// default" — so on the default configuration the dedupe returned false,
		// this email went out, and the certificate email went out straight
		// after it. Exactly the double-email that Basecamp 9932113730 removed,
		// silently back for every course inheriting the global (10171331421).
		if ( Course::certificates_enabled( $course_id ) ) {
			return;
		}

		$user = get_user_by( 'ID', $user_id );

		$this->notify(
			$user_id,
			'course_completed',
			fn() => array(
				/* translators: %s: course title */
				'title'        => sprintf( __( 'You completed %s.', 'learnomy' ), $course->title ),
				'message'      => sprintf(
					/* translators: %s: course title */
					__( 'Congratulations. You have successfully completed "%s".', 'learnomy' ),
					$course->title
				),
				'object_type'  => 'course',
				'object_id'    => $course_id,
				'action_url'   => \Learnomy\route_url( 'student-dashboard' ),
				'action_label' => __( 'View My Courses', 'learnomy' ),
				'student_name' => $user ? $user->display_name : '',
				'course_title' => $course->title,
			)
		);
	}

	/**
	 * Fires when a certificate is issued to a student.
	 *
	 * @param int $certificate_id Certificate ID.
	 * @param int $user_id        Student user ID.
	 * @param int $course_id      Course ID.
	 */
	public function on_certificate_issued( int $certificate_id, int $user_id, int $course_id ): void {
		$certificate = \Learnomy\Models\Certificate::find( $certificate_id );
		if ( ! $certificate ) {
			return;
		}
		$course = Course::find( (int) $certificate->course_id );
		if ( ! $course ) {
			return;
		}

		$user             = get_user_by( 'ID', $user_id );
		$verification_url = \Learnomy\route_url( 'certificate-verify', array( 'slug' => (string) $certificate->uuid ) );

		$this->notify(
			$user_id,
			'certificate_issued',
			fn() => array(
				/* translators: %s: course title */
				'title'            => sprintf( __( 'You completed %s. Your certificate is ready.', 'learnomy' ), $course->title ),
				'message'          => sprintf(
					/* translators: %s: course title */
					__( 'Congratulations on completing "%s". Your certificate has been issued and is ready to view.', 'learnomy' ),
					$course->title
				),
				'object_type'      => 'certificate',
				'object_id'        => (int) $certificate->id,
				'action_url'       => $verification_url,
				'action_label'     => __( 'View Certificate', 'learnomy' ),
				'student_name'     => $user ? $user->display_name : '',
				'course_title'     => $course->title,
				'certificate_uuid' => $certificate->uuid,
				'verification_url' => $verification_url,
			)
		);
	}

	/**
	 * Fires when a previously-locked lesson becomes available for a student.
	 *
	 * Producer: `Drip_Cron::process_window()` — runs hourly via Action Scheduler.
	 * Sends both an in-app notification row and an email through the standard
	 * `Notification_Service::send()` pipeline so the user can opt out per-type
	 * just like every other notification. Plan 06 §9 / §10.
	 *
	 * @param int $lesson_id Lesson that just unlocked.
	 * @param int $user_id   Student user ID.
	 * @param int $course_id Parent course ID.
	 */
	public function on_lesson_unlocked( int $lesson_id, int $user_id, int $course_id ): void {
		$lesson = \Learnomy\Models\Lesson::find( $lesson_id );
		if ( ! $lesson ) {
			return;
		}

		$course = Course::find( $course_id );
		if ( ! $course ) {
			return;
		}

		$user       = get_user_by( 'ID', $user_id );
		$lesson_url = \Learnomy\Models\Lesson::get_permalink( $lesson, $course );
		$action_url = $lesson_url ?: \Learnomy\route_url( 'student-dashboard' );

		$this->notify(
			$user_id,
			'lesson_unlocked',
			fn() => array(
				/* translators: %s: lesson title */
				'title'        => sprintf( __( 'A new lesson is available: %s', 'learnomy' ), $lesson->title ),
				'message'      => sprintf(
					/* translators: 1: lesson title, 2: course title */
					__( 'The lesson "%1$s" in "%2$s" is now unlocked. Continue your course.', 'learnomy' ),
					$lesson->title,
					$course->title
				),
				'object_type'  => 'lesson',
				'object_id'    => $lesson_id,
				'action_url'   => $action_url,
				'action_label' => __( 'Continue learning', 'learnomy' ),
				'student_name' => $user ? $user->display_name : '',
				'lesson_title' => $lesson->title,
				'course_title' => $course->title,
				'lesson_url'   => $action_url,
			)
		);
	}

	/**
	 * Fires when a student submits a quiz.
	 *
	 * @param int $attempt_id Quiz attempt ID.
	 * @param int $user_id    Student user ID.
	 * @param int $quiz_id    Quiz ID.
	 */
	public function on_quiz_submitted( int $attempt_id, int $user_id, int $quiz_id ): void {
		$attempt = \Learnomy\Models\QuizAttempt::find( $attempt_id );
		if ( ! $attempt ) {
			return;
		}
		$user = get_user_by( 'ID', $user_id );

		$this->notify(
			$user_id,
			'quiz_submitted',
			fn() => array(
				'title'        => __( 'Quiz submitted successfully', 'learnomy' ),
				'message'      => __( 'Your quiz has been submitted and is being graded.', 'learnomy' ),
				'object_type'  => 'quiz_attempt',
				'object_id'    => (int) $attempt->id,
				'student_name' => $user ? $user->display_name : '',
				'quiz_id'      => (int) $attempt->quiz_id,
			)
		);
	}

	/**
	 * Fires when a quiz attempt has been graded.
	 *
	 * @param int $attempt_id Quiz attempt ID.
	 * @param int $user_id    Student user ID.
	 * @param int $quiz_id    Quiz ID.
	 */
	public function on_quiz_graded( int $attempt_id, int $user_id, int $quiz_id ): void {
		$attempt = \Learnomy\Models\QuizAttempt::find( $attempt_id );
		if ( ! $attempt ) {
			return;
		}
		$user       = get_user_by( 'ID', $user_id );
		$percentage = (float) $attempt->percentage;
		$passed     = ! empty( $attempt->passed );

		$this->notify(
			$user_id,
			'quiz_result',
			fn() => array(
				'title'        => $passed
					? __( 'You passed the quiz.', 'learnomy' )
					: __( 'Quiz results are in', 'learnomy' ),
				'message'      => sprintf(
					/* translators: %s: quiz score percentage */
					__( 'Your score: %s%%', 'learnomy' ),
					number_format_i18n( $percentage, 0 )
				),
				'object_type'  => 'quiz_attempt',
				'object_id'    => (int) $attempt->id,
				'student_name' => $user ? $user->display_name : '',
				'quiz_id'      => (int) $attempt->quiz_id,
				'score'        => $percentage,
				'passed'       => $passed,
			)
		);
	}

	/**
	 * Fires when an instructor application is approved.
	 *
	 * @param int $user_id  Applicant user ID.
	 * @param int $app_id   Instructor application ID.
	 */
	/**
	 * Fires when a new instructor application is submitted. Emails the
	 * applicant (received / pending review) and the site admin (new
	 * application awaiting review).
	 *
	 * @param int $app_id  Instructor application ID.
	 * @param int $user_id Applicant user ID.
	 */
	public function on_application_submitted( int $app_id, int $user_id ): void {
		$user = get_user_by( 'ID', $user_id );

		// Applicant — confirm the application landed and is pending.
		$this->notify(
			$user_id,
			'application_submitted',
			fn() => array(
				'title'        => __( 'Your instructor application was received.', 'learnomy' ),
				'message'      => __( 'Thanks for applying. We will review your application and email you when there is an update.', 'learnomy' ),
				'object_type'  => 'instructor_application',
				'object_id'    => $app_id,
				'action_url'   => \Learnomy\route_url( 'student-dashboard' ),
				'action_label' => __( 'Go to My Courses', 'learnomy' ),
			)
		);

		// Site admin — a new application is waiting in the review queue.
		$admin = get_user_by( 'email', get_option( 'admin_email' ) );
		if ( ! $admin ) {
			$admin = get_user_by( 'ID', 1 );
		}
		if ( $admin && (int) $admin->ID !== $user_id ) {
			$this->notify(
				(int) $admin->ID,
				'admin_new_application',
				fn() => array(
					'title'          => __( 'New instructor application', 'learnomy' ),
					'message'        => sprintf(
						/* translators: %s: applicant display name */
						__( '%s applied to become an instructor and is awaiting review.', 'learnomy' ),
						$user ? $user->display_name : __( 'A user', 'learnomy' )
					),
					'object_type'    => 'instructor_application',
					'object_id'      => $app_id,
					'action_url'     => admin_url( 'admin.php?page=learnomy-applications' ),
					'action_label'   => __( 'Review Applications', 'learnomy' ),
					'applicant_name' => $user ? $user->display_name : '',
				)
			);
		}
	}

	/**
	 * Fires when an instructor application is approved.
	 *
	 * Parameter ORDER matters and matched the hook backwards until 2026-08-05:
	 * `learnomy_instructor_application_approved` fires ( $app_id, $user_id ),
	 * and this took ( $user_id, $app_id ), so the approval notice was addressed
	 * to the user whose ID happened to equal the application ID. Both are
	 * plain ints, so nothing errored — the approved instructor was simply never
	 * told, and on an install where those id ranges overlap a stranger was told
	 * they had become an instructor.
	 *
	 * @param int $app_id  Application row ID.
	 * @param int $user_id Applicant user ID.
	 */
	public function on_application_approved( int $app_id, int $user_id ): void {
		$user = get_user_by( 'ID', $user_id );

		$this->notify(
			$user_id,
			'application_approved',
			fn() => array(
				'title'        => __( 'Your instructor application was approved.', 'learnomy' ),
				'message'      => __( 'Welcome to the instructor team. You can now create and publish courses.', 'learnomy' ),
				'object_type'  => 'instructor_application',
				'object_id'    => $app_id,
				'action_url'   => \Learnomy\route_url( 'instructor-dashboard' ),
				'action_label' => __( 'Go to Instructor Dashboard', 'learnomy' ),
				'student_name' => $user ? $user->display_name : '',
			)
		);
	}

	/**
	 * Fires when an instructor application is rejected.
	 *
	 * This signature was wrong in two ways at once until 2026-08-05. The hook
	 * fires ( $app_id, $user_id, $feedback ); this declared ( $user_id, $app )
	 * with $app typed `object`. An int is never coerced to an object, so the
	 * handler threw a TypeError on EVERY rejection. The hook is fired after the
	 * row is written and before the controller returns, so the rejection was
	 * saved, the REST request then 500'd, and an admin who retried was told
	 * "This application has already been reviewed" — an action that worked,
	 * reported as failed, contradicting itself on the retry.
	 *
	 * The feedback now comes from the hook's third argument rather than a
	 * re-read of the row, so the binding must pass 3 accepted args.
	 *
	 * @param int    $app_id   Application row ID.
	 * @param int    $user_id  Applicant user ID.
	 * @param string $feedback Reviewer feedback shown to the applicant.
	 */
	public function on_application_rejected( int $app_id, int $user_id, string $feedback = '' ): void {
		$user = get_user_by( 'ID', $user_id );

		$this->notify(
			$user_id,
			'application_rejected',
			fn() => array(
				'title'            => __( 'Update on your instructor application', 'learnomy' ),
				'message'          => __( 'Your instructor application was not approved at this time. Please see the feedback below.', 'learnomy' ),
				'object_type'      => 'instructor_application',
				'object_id'        => $app_id,
				'student_name'     => $user ? $user->display_name : '',
				'rejection_reason' => $feedback,
			)
		);
	}

	/**
	 * Fires when a course payment is completed.
	 *
	 * @param int    $txn_id  Transaction ID.
	 * @param int    $user_id Buyer user ID.
	 * @param string $gateway Gateway identifier.
	 */
	public function on_payment_completed( int $txn_id, int $user_id, string $gateway = '' ): void {
		$transaction = Transaction::find( $txn_id );
		if ( ! $transaction ) {
			return;
		}

		$course = ! empty( $transaction->course_id ) ? Course::find( (int) $transaction->course_id ) : null;
		$plan   = ! empty( $transaction->plan_id )
			? \Learnomy\Models\MembershipPlan::find( (int) $transaction->plan_id )
			: null;
		$user   = get_user_by( 'ID', $user_id );
		$amount = number_format( (float) $transaction->amount, 2 );

		// Same defect as on_withdrawal_approved(): the subject and body built
		// their own money string as `currency . amount`, and the `?? '$'`
		// fallback reads as though the column held a symbol. It holds an ISO
		// code, so every buyer receipt said "Payment confirmed: USD35.00"
		// (Basecamp 10194371819). Formatted once here, through the canonical
		// helper, and reused by both.
		$formatted = \Learnomy\format_price(
			(float) $transaction->amount,
			strtoupper( (string) ( $transaction->currency ?? 'USD' ) )
		);

		$item_title = $course ? (string) $course->title : ( $plan ? (string) $plan->title : '' );

		$first_name = '';
		if ( $user ) {
			$first_name = (string) $user->first_name;
			if ( '' === $first_name ) {
				$first_name = (string) $user->display_name;
			}
		}

		$subject = '' !== $item_title
			/* translators: %s: course or plan title */
			? sprintf( __( 'Your purchase of %s', 'learnomy' ), $item_title )
			/* translators: %s: formatted amount, e.g. $35.00 */
			: sprintf( __( 'Payment confirmed: %s', 'learnomy' ), $formatted );

		$is_subscription = ! empty( $transaction->subscription_id )
			|| 'membership' === ( $transaction->transaction_type ?? '' )
			|| 'membership_renewal' === ( $transaction->transaction_type ?? '' );

		// SELL-02: a simulated Test-gateway order records $0 — "Your payment
		// of USD 0.00 was received" would misstate what happened. Say it was
		// a test order instead, on both the buyer and the admin email.
		$is_test_order = null !== Transaction::simulated_listed_price( $transaction );

		$action_url = $course
			? \Learnomy\route_url( 'course', array( 'slug' => (string) ( $course->slug ?? '' ) ) )
			: ( $is_subscription ? \Learnomy\route_url( 'membership-manage' ) : \Learnomy\route_url( 'account-billing' ) );

		$settings      = get_option( 'learnomy_settings', array() );
		$business_name = (string) ( $settings['business_name'] ?? '' );
		if ( '' === $business_name ) {
			$business_name = get_bloginfo( 'name' );
		}

		$this->notify(
			$user_id,
			'payment_receipt',
			fn() => array(
				'title'               => $subject,
				'message'             => ( function () use ( $is_test_order, $item_title, $formatted ) {
					if ( $is_test_order ) {
						return $item_title
							/* translators: %s: course or plan title */
							? sprintf( __( 'Your test order for "%s" is confirmed. No real payment was taken.', 'learnomy' ), $item_title )
							: __( 'Your test order is confirmed. No real payment was taken.', 'learnomy' );
					}
					return $item_title
						/* translators: 1: formatted amount, e.g. $35.00, 2: course or plan title */
						? sprintf( __( 'Your payment of %1$s for "%2$s" was received.', 'learnomy' ), $formatted, $item_title )
						/* translators: %s: formatted amount, e.g. $35.00 */
						: sprintf( __( 'Your payment of %s was received.', 'learnomy' ), $formatted );
				} )(),
				'object_type'         => 'transaction',
				'object_id'           => $txn_id,
				'txn_id'              => (int) $txn_id,
				'first_name'          => $first_name,
				'student_name'        => $user ? $user->display_name : '',
				'item_title'          => $item_title,
				'item_kind'           => $course ? 'course' : ( $plan ? 'plan' : '' ),
				'course_title'        => $course ? $course->title : '',
				'amount'              => $amount,
				'currency'            => $transaction->currency ?? 'USD',
				'payment_provider'    => (string) ( $transaction->payment_provider ?? $gateway ),
				'is_subscription'     => $is_subscription,
				'is_test_order'       => $is_test_order,
				'business_name'       => $business_name,
				'action_url'          => $action_url,
				'receipt_url'         => \Learnomy\route_url( 'account-billing-receipt', array( 'txn' => (int) $txn_id ) ),
				'account_billing_url' => \Learnomy\route_url( 'account-billing' ),
			)
		);

		// Notify the site admin of the sale. Renewals are skipped to avoid
		// recurring-charge noise; the buyer already received the receipt above.
		$is_renewal = 'membership_renewal' === ( $transaction->transaction_type ?? '' );
		if ( ! $is_renewal ) {
			$admin = get_user_by( 'email', get_option( 'admin_email' ) );
			if ( ! $admin ) {
				$admin = get_user_by( 'ID', 1 );
			}
			if ( $admin && (int) $admin->ID !== $user_id ) {
				$this->notify(
					(int) $admin->ID,
					'admin_payment_received',
					fn() => array(
						'title'        => '' !== $item_title
							/* translators: %s: course or plan title */
							? sprintf( __( 'New purchase: %s', 'learnomy' ), $item_title )
							: __( 'New payment received', 'learnomy' ),
						'message'      => $is_test_order
							? sprintf(
								/* translators: 1: customer name, 2: item title */
								__( '%1$s placed a test order for "%2$s". No real payment was taken.', 'learnomy' ),
								$user ? $user->display_name : __( 'A customer', 'learnomy' ),
								'' !== $item_title ? $item_title : __( 'a purchase', 'learnomy' )
							)
							: sprintf(
								/* translators: 1: customer name, 2: formatted price, 3: item title */
								__( '%1$s paid %2$s for "%3$s".', 'learnomy' ),
								$user ? $user->display_name : __( 'A customer', 'learnomy' ),
								// The third site of the same defect, and the one
								// the 10194371819 sweep missed: it read
								// `%2$s%3$s` with $transaction->currency fed in
								// as "2: currency symbol". The column holds an
								// ISO CODE, so the owner's own bell announced
								// "Sofia Reyes paid USD49.00" while the buyer,
								// from THIS method and $formatted eighty lines
								// up, was correctly told "USD 49.00". One
								// payment, two spellings, decided by which side
								// of the transaction you were on.
								$formatted,
								'' !== $item_title ? $item_title : __( 'a purchase', 'learnomy' )
							),
						'object_type'  => 'transaction',
						'object_id'    => $txn_id,
						'action_url'   => admin_url( 'admin.php?page=learnomy-earnings' ),
						'action_label' => __( 'View Earnings', 'learnomy' ),
					)
				);
			}
		}
	}

	/**
	 * Fires when a withdrawal request is approved.
	 *
	 * @param int    $user_id    Instructor user ID.
	 * @param object $withdrawal Withdrawal object (with amount, currency).
	 */
	public function on_withdrawal_approved( int $user_id, object $withdrawal ): void {
		if ( $this->already_notified( $user_id, 'withdrawal_approved', (int) $withdrawal->id ) ) {
			return;
		}

		$user     = get_user_by( 'ID', $user_id );
		$currency = strtoupper( (string) ( $withdrawal->currency ?? 'USD' ) );
		$amount   = (float) $withdrawal->amount;

		// Formatted through \Learnomy\format_price(), the same helper the
		// rejection notice below uses.
		//
		// This built the string by hand as `currency . number_format(amount)`,
		// with a `?? '$'` fallback that reads as though the column held a
		// SYMBOL. It holds an ISO CODE, so an approved withdrawal announced
		// itself as "Withdrawal of USD35.00 approved" while the rejection of
		// the same amount read "$35.00" — two formats for one figure, from two
		// methods twenty lines apart (Basecamp 10194371819). The fallback only
		// ever produced "$" on a row with no currency at all, which is why it
		// looked plausible in review.
		$formatted = \Learnomy\format_price( $amount, $currency );

		$this->notify(
			$user_id,
			'withdrawal_approved',
			fn() => array(
				/* translators: %s: formatted amount, e.g. $91.00 */
				'title'        => sprintf( __( 'Withdrawal of %s approved', 'learnomy' ), $formatted ),
				'message'      => sprintf(
					/* translators: 1: formatted amount, e.g. $91.00, 2: payment method */
					__( 'Your withdrawal of %1$s has been approved and will be processed to your %2$s account.', 'learnomy' ),
					$formatted,
					$withdrawal->method ?? __( 'payment', 'learnomy' )
				),
				'object_type'  => 'withdrawal',
				'object_id'    => (int) $withdrawal->id,
				'student_name' => $user ? $user->display_name : '',
				// The raw pieces stay unformatted for any consumer that renders
				// them itself (email templates, webhooks).
				'amount'       => number_format( $amount, 2 ),
				'currency'     => $currency,
				'method'       => $withdrawal->method ?? '',
			)
		);
	}

	/**
	 * Fires when an admin rejects a withdrawal request.
	 *
	 * The instructor's balance quietly comes back (the claimed commissions
	 * return to `pending`) and, until this listener existed, that was the ONLY
	 * signal they got: no email, no in-app row, no reason. The reason is
	 * required by the REST route, so there is always one to pass on.
	 *
	 * @param int    $withdrawal_id Withdrawal row id.
	 * @param int    $user_id       Instructor user ID.
	 * @param string $reason        Reason the admin gave; shown verbatim.
	 */
	public function on_withdrawal_rejected( int $withdrawal_id, int $user_id, string $reason = '' ): void {
		$withdrawal = Withdrawal::find( $withdrawal_id );
		if ( ! $withdrawal ) {
			return;
		}

		if ( $this->already_notified( $user_id, 'withdrawal_rejected', $withdrawal_id ) ) {
			return;
		}

		$user     = get_user_by( 'ID', $user_id );
		$currency = strtoupper( (string) ( $withdrawal->currency ?? 'USD' ) );
		$amount   = (float) $withdrawal->amount;

		$this->notify(
			$user_id,
			'withdrawal_rejected',
			fn() => array(
				'title'            => sprintf(
					/* translators: %s: formatted amount, e.g. $91.00 */
					__( 'Withdrawal of %s was not approved', 'learnomy' ),
					\Learnomy\format_price( $amount, $currency )
				),
				'message'          => '' !== $reason
					? sprintf(
						/* translators: 1: formatted amount, 2: reason given by the site owner */
						__( 'Your withdrawal request for %1$s was not approved. Reason: %2$s Your earnings are available to withdraw again.', 'learnomy' ),
						\Learnomy\format_price( $amount, $currency ),
						$reason
					)
					: sprintf(
						/* translators: %s: formatted amount */
						__( 'Your withdrawal request for %s was not approved. Your earnings are available to withdraw again.', 'learnomy' ),
						\Learnomy\format_price( $amount, $currency )
					),
				'object_type'      => 'withdrawal',
				'object_id'        => $withdrawal_id,
				'student_name'     => $user ? $user->display_name : '',
				'amount'           => number_format( $amount, 2 ),
				'currency'         => $currency,
				'rejection_reason' => $reason,
				'action_url'       => \Learnomy\route_url( 'instructor-dashboard' ),
				'action_label'     => __( 'View Earnings', 'learnomy' ),
			)
		);
	}

	/**
	 * Fires when a payout rail reports the money has actually moved (Pro's
	 * Stripe Connect transfer). Distinct from `withdrawal_approved`, which is
	 * the site owner saying "I will pay this" on the manual path — this one is
	 * "it is paid", and it carries the provider reference the instructor can
	 * quote if the funds do not show up.
	 *
	 * @param int    $withdrawal_id Withdrawal row id.
	 * @param string $reference     Provider transfer id.
	 */
	public function on_withdrawal_paid( int $withdrawal_id, string $reference = '' ): void {
		$withdrawal = Withdrawal::find( $withdrawal_id );
		if ( ! $withdrawal ) {
			return;
		}

		$user_id = (int) $withdrawal->instructor_id;
		if ( $this->already_notified( $user_id, 'withdrawal_paid', $withdrawal_id ) ) {
			return;
		}

		$user     = get_user_by( 'ID', $user_id );
		$currency = strtoupper( (string) ( $withdrawal->currency ?? 'USD' ) );
		$amount   = (float) $withdrawal->amount;

		$this->notify(
			$user_id,
			'withdrawal_paid',
			fn() => array(
				'title'        => sprintf(
					/* translators: %s: formatted amount */
					__( 'Payout of %s sent', 'learnomy' ),
					\Learnomy\format_price( $amount, $currency )
				),
				'message'      => sprintf(
					/* translators: %s: formatted amount */
					__( 'Your payout of %s has been sent. It usually lands in 1-3 business days.', 'learnomy' ),
					\Learnomy\format_price( $amount, $currency )
				),
				'object_type'  => 'withdrawal',
				'object_id'    => $withdrawal_id,
				'student_name' => $user ? $user->display_name : '',
				'amount'       => number_format( $amount, 2 ),
				'currency'     => $currency,
				'reference'    => $reference,
				'action_url'   => \Learnomy\route_url( 'instructor-dashboard' ),
				'action_label' => __( 'View Earnings', 'learnomy' ),
			)
		);
	}

	/**
	 * Fires when an automatic payout could not be sent.
	 *
	 * Two recipients, two different messages. The instructor is told their
	 * payout did not go through and that it is being handled — they cannot act
	 * on a gateway error string and should not be shown one. The site owner
	 * gets the provider's actual error, because they are the only person who
	 * can fix it. A failure notified to nobody (the state before this listener)
	 * left the request sitting in the admin queue looking like any other
	 * pending row.
	 *
	 * @param int    $withdrawal_id Withdrawal row id.
	 * @param string $error         Provider error message.
	 */
	public function on_withdrawal_failed( int $withdrawal_id, string $error = '' ): void {
		$withdrawal = Withdrawal::find( $withdrawal_id );
		if ( ! $withdrawal ) {
			return;
		}

		$user_id  = (int) $withdrawal->instructor_id;
		$user     = get_user_by( 'ID', $user_id );
		$currency = strtoupper( (string) ( $withdrawal->currency ?? 'USD' ) );
		$amount   = (float) $withdrawal->amount;

		if ( $this->already_notified( $user_id, 'withdrawal_failed', $withdrawal_id ) ) {
			return;
		}

		$this->notify(
			$user_id,
			'withdrawal_failed',
			fn() => array(
				'title'        => sprintf(
					/* translators: %s: formatted amount */
					__( 'Payout of %s could not be sent', 'learnomy' ),
					\Learnomy\format_price( $amount, $currency )
				),
				'message'      => sprintf(
					/* translators: %s: formatted amount */
					__( 'Your payout of %s could not be sent automatically. Your request is still open and the site owner has been notified. Check that your payout account is connected and able to receive transfers.', 'learnomy' ),
					\Learnomy\format_price( $amount, $currency )
				),
				'object_type'  => 'withdrawal',
				'object_id'    => $withdrawal_id,
				'student_name' => $user ? $user->display_name : '',
				'amount'       => number_format( $amount, 2 ),
				'currency'     => $currency,
				'action_url'   => \Learnomy\route_url( 'instructor-dashboard' ),
				'action_label' => __( 'View Earnings', 'learnomy' ),
			)
		);

		$admin = get_user_by( 'email', get_option( 'admin_email' ) );
		if ( ! $admin ) {
			$admin = get_user_by( 'ID', 1 );
		}
		if ( ! $admin || (int) $admin->ID === $user_id ) {
			return;
		}
		if ( $this->already_notified( (int) $admin->ID, 'admin_withdrawal_failed', $withdrawal_id ) ) {
			return;
		}

		$this->notify(
			(int) $admin->ID,
			'admin_withdrawal_failed',
			fn() => array(
				'title'        => sprintf(
					/* translators: %s: formatted amount */
					__( 'Automatic payout of %s failed', 'learnomy' ),
					\Learnomy\format_price( $amount, $currency )
				),
				'message'      => sprintf(
					/* translators: 1: instructor name, 2: formatted amount, 3: provider error message */
					__( 'The payout to %1$s for %2$s could not be sent: %3$s The request is still open, so it can be paid manually or retried.', 'learnomy' ),
					$user ? $user->display_name : __( 'an instructor', 'learnomy' ),
					\Learnomy\format_price( $amount, $currency ),
					'' !== $error ? $error : __( 'no error message was returned.', 'learnomy' )
				),
				'object_type'  => 'withdrawal',
				'object_id'    => $withdrawal_id,
				'amount'       => number_format( $amount, 2 ),
				'currency'     => $currency,
				'error'        => $error,
				'action_url'   => admin_url( 'admin.php?page=learnomy-withdrawal-detail&withdrawal_id=' . $withdrawal_id ),
				'action_label' => __( 'View Withdrawal', 'learnomy' ),
			)
		);
	}

	/**
	 * Fires when a student submits a review for a course.
	 * Notifies the course instructor.
	 *
	 * Producer (`Review_Service::submit`) fires this with
	 * `($review_id, $user_id, $course_id)` — three ints. The listener
	 * looks the review row up by id so we don't have to widen the action
	 * signature to pass the full object.
	 *
	 * @param int $review_id  Newly inserted review row ID.
	 * @param int $user_id    Reviewer user ID.
	 * @param int $course_id  Course the review was posted on.
	 */
	public function on_review_submitted( int $review_id, int $user_id, int $course_id ): void {
		$course = Course::find( $course_id );
		if ( ! $course ) {
			return;
		}

		$review = Review::find( $review_id );
		if ( ! $review ) {
			return;
		}

		$reviewer      = get_user_by( 'ID', $user_id );
		$reviewer_name = $reviewer ? $reviewer->display_name : __( 'A student', 'learnomy' );

		// Notify the course author (instructor).
		if ( ! empty( $course->author_id ) ) {
			$this->notify(
				(int) $course->author_id,
				'review_submitted',
				fn() => array(
					/* translators: %s: course title */
					'title'        => sprintf( __( 'New review for %s', 'learnomy' ), $course->title ),
					'message'      => sprintf(
						/* translators: 1: reviewer name, 2: course title, 3: rating */
						__( '%1$s left a %3$s-star review on "%2$s".', 'learnomy' ),
						$reviewer_name,
						$course->title,
						(int) $review->rating
					),
					'object_type'  => 'review',
					'object_id'    => (int) $review->id,
					'actor_id'     => $user_id,
					'course_title' => $course->title,
					'rating'       => (int) $review->rating,
				)
			);
		}
	}

	/**
	 * Fires when a membership subscription is created.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @param int $user_id         Member user ID.
	 * @param int $plan_id         Membership plan ID.
	 */
	/**
	 * Resolve a subscription's target title from its context.
	 *
	 * Arg 3 of the subscription_* hooks is a plan_id for a membership sub and a
	 * course_id for a course-level recurring sub. Looking it up in the wrong table
	 * is why a course subscriber was emailed a membership welcome naming a generic
	 * "your membership" (or, when a plan's id happened to equal the course id, a
	 * completely unrelated plan). Branch on the context flag.
	 *
	 * @param int    $target_id    plan_id ('plan') or course_id ('course').
	 * @param string $context_type 'plan' | 'course'.
	 * @return array{title:string,noun:string}
	 */
	private function resolve_subscription_target( int $target_id, string $context_type ): array {
		if ( 'course' === $context_type ) {
			$course = \Learnomy\Models\Course::find( $target_id );
			return array(
				'title' => $course->title ?? __( 'your course', 'learnomy' ),
				'noun'  => __( 'subscription', 'learnomy' ),
			);
		}

		$plan = \Learnomy\Models\MembershipPlan::find( $target_id );
		return array(
			'title' => $plan->title ?? __( 'your membership', 'learnomy' ),
			'noun'  => __( 'membership', 'learnomy' ),
		);
	}

	public function on_membership_created( int $subscription_id, int $user_id, int $target_id, string $context_type = 'plan' ): void {
		$target = $this->resolve_subscription_target( $target_id, $context_type );

		$this->notify(
			$user_id,
			'membership_created',
			fn() => array(
				/* translators: %s: plan or course title */
				'title'       => sprintf( __( 'Welcome to %s', 'learnomy' ), $target['title'] ),
				/* translators: 1: plan or course title, 2: "membership" or "subscription" */
				'message'     => sprintf( __( 'Your %1$s %2$s is now active.', 'learnomy' ), $target['title'], $target['noun'] ),
				'object_type' => 'membership',
				'object_id'   => $subscription_id,
				'plan_title'  => $target['title'],
				'manage_url'  => \Learnomy\route_url( 'membership-manage' ),
			)
		);
	}

	/**
	 * Fires when a subscription is cancelled. arg 4 is $immediate; arg 5 is the
	 * context flag ('plan' | 'course'). arg 3 is a plan_id or a course_id per that
	 * flag — resolve it in the right table so a cancelled course subscription is
	 * not mislabelled with a membership plan's name (or a generic one).
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param int    $user_id         Member user ID.
	 * @param int    $target_id       plan_id ('plan') or course_id ('course').
	 * @param bool   $immediate       Whether the cancellation is immediate.
	 * @param string $context_type    'plan' | 'course'.
	 */
	public function on_membership_cancelled( int $subscription_id, int $user_id, int $target_id, bool $immediate = false, string $context_type = 'plan' ): void {
		unset( $immediate );
		$subscription = \Learnomy\Models\Subscription::find( $subscription_id );
		$target       = $this->resolve_subscription_target( $target_id, $context_type );
		$plan_title   = $target['title'];
		$noun         = $target['noun'];
		$expiry_date  = ! empty( $subscription->expires_at ) ? wp_date( get_option( 'date_format' ), strtotime( $subscription->expires_at ) ) : '';

		$this->notify(
			$user_id,
			'membership_cancelled',
			fn() => array(
				/* translators: 1: plan or course title, 2: "membership" or "subscription" */
				'title'       => sprintf( __( 'Your %1$s %2$s has been cancelled.', 'learnomy' ), $plan_title, $noun ),
				/* translators: 1: plan or course title, 2: "membership" or "subscription" */
				'message'     => sprintf( __( 'Your %1$s %2$s has been cancelled.', 'learnomy' ), $plan_title, $noun ),
				'object_type' => 'membership',
				'object_id'   => $subscription_id,
				'plan_title'  => $plan_title,
				'expiry_date' => $expiry_date,
			)
		);
	}

	/**
	 * Fires when a membership subscription is renewed.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @param int $user_id         Member user ID.
	 * @param int $plan_id         Membership plan ID.
	 */
	public function on_membership_renewed( int $subscription_id, int $user_id, int $plan_id ): void {
		$subscription = \Learnomy\Models\Subscription::find( $subscription_id );
		$plan         = \Learnomy\Models\MembershipPlan::find( $plan_id );
		$plan_title   = $plan->title ?? '';
		$next_billing = ! empty( $subscription->next_billing_at ) ? wp_date( get_option( 'date_format' ), strtotime( $subscription->next_billing_at ) ) : '';

		$this->notify(
			$user_id,
			'membership_renewed',
			fn() => array(
				/* translators: %s: membership plan title */
				'title'        => sprintf( __( 'Your %s membership has been renewed', 'learnomy' ), $plan_title ),
				/* translators: %s: membership plan title */
				'message'      => sprintf( __( 'Your %s membership has been renewed successfully.', 'learnomy' ), $plan_title ),
				'object_type'  => 'membership',
				'object_id'    => $subscription_id,
				'plan_title'   => $plan_title,
				'next_billing' => $next_billing,
			)
		);
	}

	/**
	 * Fires when a subscription expires. arg 4 is the context flag ('plan' |
	 * 'course'); arg 3 is a plan_id or a course_id per that flag.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param int    $user_id         Member user ID.
	 * @param int    $target_id       plan_id ('plan') or course_id ('course').
	 * @param string $context_type    'plan' | 'course'.
	 */
	public function on_membership_expired( int $subscription_id, int $user_id, int $target_id, string $context_type = 'plan' ): void {
		$target     = $this->resolve_subscription_target( $target_id, $context_type );
		$plan_title = $target['title'];
		$noun       = $target['noun'];

		$this->notify(
			$user_id,
			'membership_expired',
			fn() => array(
				/* translators: 1: plan or course title, 2: "membership" or "subscription" */
				'title'       => sprintf( __( 'Your %1$s %2$s has expired', 'learnomy' ), $plan_title, $noun ),
				/* translators: 1: plan or course title, 2: "membership" or "subscription" */
				'message'     => sprintf( __( 'Your %1$s %2$s has expired. Resubscribe to regain access.', 'learnomy' ), $plan_title, $noun ),
				'object_type' => 'membership',
				'object_id'   => $subscription_id,
				'plan_title'  => $plan_title,
			)
		);
	}

	/**
	 * Fires ~72h before a trial converts — lets the member top up the payment
	 * method before the first real charge lands.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param int    $user_id         Member user ID.
	 * @param int    $plan_id         Membership plan ID.
	 * @param string $trial_ends_at   Raw MySQL datetime the trial closes.
	 */
	public function on_subscription_trial_ending( int $subscription_id, int $user_id, int $plan_id, string $trial_ends_at = '' ): void {
		$plan       = \Learnomy\Models\MembershipPlan::find( $plan_id );
		$plan_title = $plan->title ?? __( 'your membership', 'learnomy' );
		$end_date   = $trial_ends_at ? wp_date( get_option( 'date_format' ), strtotime( $trial_ends_at ) ) : '';

		$this->notify(
			$user_id,
			'membership_trial_ending',
			fn() => array(
				/* translators: %s: membership plan title */
				'title'         => sprintf( __( 'Your %s trial is ending soon', 'learnomy' ), $plan_title ),
				'message'       => $end_date
					/* translators: 1: membership plan title, 2: trial end date */
					? sprintf( __( 'Your %1$s trial ends on %2$s. Update your payment method now to avoid losing access.', 'learnomy' ), $plan_title, $end_date )
					/* translators: %s: membership plan title */
					: sprintf( __( 'Your %s trial is ending soon. Update your payment method now to avoid losing access.', 'learnomy' ), $plan_title ),
				'object_type'   => 'membership',
				'object_id'     => $subscription_id,
				'plan_title'    => $plan_title,
				'trial_ends_at' => $end_date,
				'manage_url'    => \Learnomy\route_url( 'membership-manage' ),
			)
		);
	}

	/**
	 * Fires when a student posts a comment on a lesson.
	 * Notifies the course instructor.
	 *
	 * @param int $comment_id Comment ID.
	 * @param int $lesson_id  Lesson ID.
	 * @param int $user_id    Commenter user ID.
	 */
	public function on_lesson_comment( int $comment_id, int $lesson_id, int $user_id ): void {
		$lesson = \Learnomy\Models\Lesson::find( $lesson_id );
		if ( ! $lesson ) {
			return;
		}

		$course = Course::find( (int) $lesson->course_id );
		if ( ! $course || empty( $course->author_id ) ) {
			return;
		}

		// Do not notify the instructor about their own comments.
		if ( (int) $course->author_id === $user_id ) {
			return;
		}

		$commenter = get_user_by( 'ID', $user_id );
		$name      = $commenter ? $commenter->display_name : __( 'A student', 'learnomy' );

		$this->notify(
			(int) $course->author_id,
			'lesson_comment',
			fn() => array(
				'title'       => sprintf(
					/* translators: 1: student name, 2: lesson title */
					__( '%1$s commented on %2$s', 'learnomy' ),
					$name,
					$lesson->title
				),
				'message'     => sprintf(
					/* translators: 1: student name, 2: lesson title, 3: course title */
					__( '%1$s left a comment on "%2$s" in %3$s.', 'learnomy' ),
					$name,
					$lesson->title,
					$course->title
				),
				'object_type' => 'lesson',
				'object_id'   => $lesson_id,
				'actor_id'    => $user_id,
			)
		);
	}

	/**
	 * Fires when someone replies to a lesson comment.
	 * Notifies the parent comment author.
	 *
	 * @param int $comment_id Reply comment ID.
	 * @param int $parent_id  Parent comment ID.
	 * @param int $lesson_id  Lesson ID.
	 * @param int $user_id    Replier user ID.
	 */
	public function on_lesson_comment_reply( int $comment_id, int $parent_id, int $lesson_id, int $user_id ): void {
		$parent = get_comment( $parent_id );
		if ( ! $parent || (int) $parent->user_id === $user_id ) {
			return;
		}

		$lesson  = \Learnomy\Models\Lesson::find( $lesson_id );
		$replier = get_user_by( 'ID', $user_id );
		$name    = $replier ? $replier->display_name : __( 'Someone', 'learnomy' );

		$this->notify(
			(int) $parent->user_id,
			'comment_reply',
			fn() => array(
				'title'       => sprintf(
					/* translators: %s: replier name */
					__( '%s replied to your comment', 'learnomy' ),
					$name
				),
				'message'     => sprintf(
					/* translators: 1: replier name, 2: lesson title */
					__( '%1$s replied to your comment on "%2$s".', 'learnomy' ),
					$name,
					$lesson ? $lesson->title : __( 'a lesson', 'learnomy' )
				),
				'object_type' => 'lesson',
				'object_id'   => $lesson_id,
				'actor_id'    => $user_id,
			)
		);
	}
}
