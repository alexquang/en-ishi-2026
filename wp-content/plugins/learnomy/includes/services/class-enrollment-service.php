<?php
/**
 * Enrollment business logic — controls who is in a course and what they can access.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Enrollment;
use Learnomy\Models\Course;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\Instructor;
use Learnomy\Models\Lesson;
use Learnomy\Models\Lesson_Unlock;
use Learnomy\Models\Progress;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\Transaction;
use Learnomy\Models\AuditLog;
use Learnomy\Permissions\Permission_Engine;
use function Learnomy\now;

class Enrollment_Service {

	/**
	 * Enroll a user in a course.
	 *
	 * The 4th argument was a `?int $plan_id` until PR-0 (2026-05-18) — it
	 * is now an options array so we can carry per-enrollment metadata
	 * without growing the positional signature past four args. Keys today:
	 *
	 *   - plan_id        (int)         membership plan that granted access
	 *   - subscription_id(int)         course-level recurring sub (PR-A1)
	 *   - expires_at     (string|null) MySQL datetime for fixed-term access (PR-A1)
	 *
	 * A backward-compatibility shim accepts the legacy positional
	 * `?int $plan_id` form. Callers passing the old shape get a
	 * `_doing_it_wrong` notice. Hard removal in v2.0.
	 *
	 * @param int                          $user_id   User id.
	 * @param int                          $course_id Course id.
	 * @param string                       $source    Enrollment source slug.
	 * @param array<string,mixed>|int|null $options   Options array, or legacy positional plan_id.
	 *
	 * @return int|\WP_Error Enrollment id on success, 0 on duplicate / pre-filter veto,
	 *                       WP_Error on validation failure.
	 */
	public function enroll( int $user_id, int $course_id, string $source = 'free', array|int|null $options = array() ): int|\WP_Error {
		// Backward-compat shim: callers passing the pre-PR-0 positional
		// `?int $plan_id` argument keep working. Migrate them in their
		// own PRs; the shim disappears in v2.0.
		if ( is_int( $options ) ) {
			_doing_it_wrong(
				__METHOD__,
				'Passing $plan_id as a positional 4th argument is deprecated. Pass an options array: enroll( $user_id, $course_id, $source, [ "plan_id" => $plan_id ] ).',
				'1.1.0'
			);
			$options = array( 'plan_id' => $options );
		} elseif ( null === $options ) {
			$options = array();
		}

		// Prerequisites must be met before any enrollment is created. This enforces
		// learning paths rather than letting students skip ahead. A refusal is a
		// WP_Error naming the missing courses — this used to be a bare `return 0`,
		// indistinguishable from a generic failure, which is how a paid checkout
		// could complete, say "Your enrollment is confirmed", and write nothing
		// (Basecamp 10155834186). Dangling prerequisite ids are skipped inside
		// unmet_prerequisites(), so a deleted course can no longer gate forever.
		$course_service = new Course_Service();
		$unmet          = $course_service->unmet_prerequisites( $user_id, $course_id );
		if ( array() !== $unmet ) {
			return new \WP_Error(
				'learnomy_prerequisites_not_met',
				sprintf(
					/* translators: %s: comma-separated list of course titles */
					__( 'Complete these courses first: %s.', 'learnomy' ),
					implode( ', ', wp_list_pluck( $unmet, 'title' ) )
				),
				array(
					'status'     => 403,
					'course_ids' => array_map( 'intval', wp_list_pluck( $unmet, 'id' ) ),
				)
			);
		}

		// Authoritative access-type guard: refuse to create an enrollment whose
		// `source` doesn't match the course's `access_type`. Pre-fix the public
		// REST endpoint accepted any source on any course — a student could
		// POST `source=free` on a paid course and bypass payment entirely. The
		// guard runs at the service layer so REST, AJAX, CLI and adapter
		// callers all share one chokepoint.
		$source_check = $this->validate_source_for_access_type( $user_id, $course_id, $source );
		if ( is_wp_error( $source_check ) ) {
			return $source_check;
		}

		// A student can hold a free enrollment AND a membership enrollment simultaneously,
		// but not two of the same source. The UNIQUE key on the table is
		// `(user_id, course_id, source)`, so for THIS source there is at most one
		// row already — look it up directly. An already-active row is an
		// idempotent re-call: return its id, not 0 (F-04.3 fix — callers can no
		// longer confuse "already enrolled" with "failed"; any non-positive
		// return is an actual failure or WP_Error).
		$existing = Enrollment::find_by_user_course_source( $user_id, $course_id, $source );
		if ( $existing && 'active' === ( $existing->status ?? '' ) ) {
			return (int) $existing->id;
		}

		// Only a PUBLISHED course accepts a NEW enrollment. The service
		// validated access_type and prerequisites but never the course's own
		// status, so a draft/pending/archived course could be enrolled into by
		// anyone who reached the id (Basecamp 10132224022 — and drafts WERE
		// reachable, 10132217693). Placed AFTER the already-active idempotent
		// return on purpose: a webhook retry for an existing enrollment must
		// keep returning its id even if the course was since unpublished
		// (enrolled students keep access — see Single_Course_View_Data).
		// Admins and the course's own instructors bypass for preview,
		// mirroring Quiz_Service::start_attempt()'s enrollment gate.
		$status_course = Course::find( $course_id );
		if ( ! $status_course ) {
			return new \WP_Error(
				'learnomy_course_not_found',
				__( 'Course not found.', 'learnomy' ),
				array( 'status' => 404 )
			);
		}
		// The gate applies to NEW enrollments only: any existing row for this
		// (user, course, source) — completed, cancelled, expired — means a
		// pre-existing customer relationship, and the re-call must follow the
		// status-specific handling below (completed short-circuits, cancelled
		// reactivates on a payment webhook; blocking a reactivation would be
		// money-taken-no-access). The 1.8.0 free-mode smoke caught the gap:
		// the active-only idempotent return above let a COMPLETED student's
		// re-call fall through to this gate and error on an unpublished
		// course.
		if ( null === $existing && 'published' !== (string) ( $status_course->status ?? 'draft' ) ) {
			$is_admin      = user_can( $user_id, 'manage_options' );
			$is_instructor = (int) ( $status_course->author_id ?? 0 ) === $user_id
				|| in_array(
					$user_id,
					array_map( 'intval', wp_list_pluck( CourseInstructor::get_for_course( $course_id ), 'user_id' ) ),
					true
				);
			if ( ! $is_admin && ! $is_instructor ) {
				return new \WP_Error(
					'learnomy_course_not_available',
					__( 'This course is not open for enrollment.', 'learnomy' ),
					array( 'status' => 403 )
				);
			}
		}

		// A suspended instructor takes no NEW business. Same placement and same
		// `null === $existing` condition as the published-course gate above, and
		// for the same reason: an existing student - active, completed or
		// cancelled-then-reactivated by a payment webhook - is a relationship the
		// owner did not pause, and blocking their re-call would be
		// money-taken-no-access. Suspension is about who may START learning here,
		// never about who already did (Basecamp 10203580675).
		//
		// The service layer is the chokepoint REST, AJAX, CLI and the payment
		// adapters all share, so the rule cannot be walked around by reaching a
		// different caller. The CTA is gated separately via
		// `learnomy_course_enroll_gate` so a buyer is told before pressing rather
		// than after.
		//
		// Suspension stops SELF-SERVE enrolment and nothing else. Every other
		// source carries an entitlement somebody already granted or paid for -
		// a completed purchase, a webhook fulfilling one, a membership, a space
		// seat, a cohort placement, a learning-path step, an owner enrolling by
		// hand - and refusing those is money-taken-no-access, the exact failure
		// the cohort gate's allowlist exists to avoid (10207848945). Expressed as
		// the blocked set rather than an allowed set on purpose: a source added
		// later is far more likely to be another entitlement than another
		// self-serve funnel, so the safe default is to let it through.
		//
		// The bypass below tests the ACTOR, not the person being enrolled.
		// `user_can( $user_id, ... )` asked whether the STUDENT was an
		// administrator, so an owner hand-enrolling somebody was refused while
		// an admin enrolling THEMSELF was allowed - the precise opposite of what
		// the comment promised, caught in QA on 10203580675.
		if ( null === $existing && Instructor::is_suspended( (int) ( $status_course->author_id ?? 0 ) ) ) {
			/**
			 * Enrollment sources a suspended instructor still blocks.
			 *
			 * @since 1.9.3
			 *
			 * @param string[] $sources   Blocked sources. Default: self-serve only.
			 * @param int      $course_id Course being enrolled into.
			 * @param int      $user_id   Learner being enrolled.
			 */
			$lrn_blocked_sources = (array) apply_filters(
				'learnomy_instructor_suspension_blocks_sources',
				array( 'free' ),
				$course_id,
				$user_id
			);

			$lrn_bypass = ! in_array( $source, $lrn_blocked_sources, true )
				// The acting user - an owner in wp-admin, or WP-CLI running as
				// an administrator - not the enrollee.
				|| current_user_can( 'manage_options' )
				// The instructor previewing their own course.
				|| (int) ( $status_course->author_id ?? 0 ) === $user_id;

			if ( ! $lrn_bypass ) {
				return new \WP_Error(
					'learnomy_instructor_suspended',
					__( 'This course is not accepting new enrollments right now.', 'learnomy' ),
					array( 'status' => 403 )
				);
			}
		}

		$can_enroll = apply_filters( 'learnomy_pre_enroll', true, $user_id, $course_id, $source );
		if ( is_wp_error( $can_enroll ) ) {
			return $can_enroll;
		}
		if ( ! $can_enroll ) {
			return 0;
		}

		$enrollment_data = array(
			'user_id'   => $user_id,
			'course_id' => $course_id,
			'source'    => $source,
		);

		// Carry the membership plan when present.
		if ( isset( $options['plan_id'] ) && null !== $options['plan_id'] ) {
			$enrollment_data['plan_id'] = (int) $options['plan_id'];
		}

		// A membership enrollment must record WHICH plan granted it. Callers that
		// know the plan pass it above; the ones that only know "this member is
		// entitled" — the public REST endpoint, the single-course Activate CTA in
		// the router, any Pro surface — used to drop it, so every self-serve
		// membership enrollment stored plan_id = NULL. The owner then could not
		// answer "why does this person have this course?", and a revocation had no
		// audit trail to check against (Basecamp 10134139611). Deriving it here, at
		// the one chokepoint, fixes every such caller at once and needs none of
		// them to change. An explicit caller value always wins; NULL survives when
		// nothing plan-shaped is responsible (a course-level subscription, or a Pro
		// extension granting access through the has_access filter), which is the
		// honest record rather than a guess.
		if ( 'membership' === $source && ! isset( $enrollment_data['plan_id'] ) ) {
			$matched_plan = ( new Subscription_Service() )->matched_plan_id( $user_id, $course_id );
			if ( null !== $matched_plan ) {
				$enrollment_data['plan_id'] = $matched_plan;
			}
		}

		// Fixed-term access: the row's expires_at column already exists
		// in the schema (Schema::create_tables) — PR-A1 wires the
		// caller-side computation via Course::compute_access_expiry().
		if ( isset( $options['expires_at'] ) && '' !== $options['expires_at'] ) {
			$enrollment_data['expires_at'] = (string) $options['expires_at'];
		}

		// Course-level recurring subscription link. The column was added in
		// Migration 2.3.0 (PR-A1). When a caller passes 'subscription_id'
		// we write it through so the billing page can join enrollment ↔
		// subscription ↔ transaction without a separate lookup.
		if ( isset( $options['subscription_id'] ) && null !== $options['subscription_id'] ) {
			$enrollment_data['subscription_id'] = (int) $options['subscription_id'];
		}

		// Auto-derive expires_at for fixed-term courses when the caller
		// hasn't supplied one explicitly. This keeps the adapter callsites
		// terse — they pass `expires_at` for explicit overrides; the
		// service handles the common case by reading access_duration_days
		// from the course row.
		if ( empty( $enrollment_data['expires_at'] ) ) {
			$course = Course::find( $course_id );
			if ( $course && Course::is_fixed_term( $course ) ) {
				$computed = Course::compute_access_expiry( $course );
				if ( $computed instanceof \DateTimeImmutable ) {
					$enrollment_data['expires_at'] = $computed->format( 'Y-m-d H:i:s' );
				}
			}
		}

		// Existing-row resolution. The UNIQUE (user_id, course_id, source) key
		// means a fresh INSERT for THIS source collides with any prior row and
		// fails silently (Basecamp #9961889384: a cancelled course subscription
		// left an `expired` enrollment row, so re-subscribe could never create a
		// new one and the re-subscribing user was charged but locked out). When a
		// terminal-state row exists, RESET it in place instead of inserting:
		//
		// - cancelled / expired → reactivate (status active, fresh period,
		// progress reset, completion/expiry cleared) and re-link the new
		// plan_id / subscription_id / expires_at from this enrollment.
		// - completed           → access + completion already exist; return the
		// row idempotently. Re-running a completed course is the `retake()`
		// path, which is gated on the per-course allow_retake setting — we
		// must not silently wipe a completion here.
		// - active is handled above (idempotent early-return).
		if ( $existing ) {
			$existing_status = (string) ( $existing->status ?? '' );

			if ( 'completed' === $existing_status ) {
				return (int) $existing->id;
			}

			$reactivated = Enrollment::reactivate(
				(int) $existing->id,
				isset( $enrollment_data['plan_id'] ) ? (int) $enrollment_data['plan_id'] : null,
				isset( $enrollment_data['subscription_id'] ) ? (int) $enrollment_data['subscription_id'] : null,
				isset( $enrollment_data['expires_at'] ) ? (string) $enrollment_data['expires_at'] : null
			);

			if ( ! $reactivated ) {
				return 0;
			}

			// Distinct-aware: only tick the counter when this reactivation is the
			// user's FIRST live row in the course. If they already hold another
			// active/completed row (e.g. a direct enrolment while this is the
			// Space seat), they were already counted — student_count tracks
			// distinct people, not enrolment rows (Basecamp 10109120366).
			if ( ! Enrollment::user_has_other_active_row( $user_id, $course_id, (int) $existing->id ) ) {
				Course::increment_student_count( $course_id, 1 );
			}

			do_action( 'learnomy_student_enrolled', (int) $existing->id, $user_id, $course_id, $source );

			return (int) $existing->id;
		}

		$enrollment_id = Enrollment::create( $enrollment_data );

		if ( ! $enrollment_id ) {
			return 0;
		}

		// Distinct-aware (see reactivate branch above): tick only when this new
		// row is the user's first live row in the course.
		if ( ! Enrollment::user_has_other_active_row( $user_id, $course_id, (int) $enrollment_id ) ) {
			Course::increment_student_count( $course_id, 1 );
		}

		do_action( 'learnomy_student_enrolled', $enrollment_id, $user_id, $course_id, $source );

		return $enrollment_id;
	}

	/**
	 * A completed payment did not result in a course enrollment.
	 *
	 * The customer was already charged (the transaction is recorded), so this
	 * MUST be loud, never silent: it writes to the audit log for support and
	 * fires `learnomy_purchase_enrollment_failed` so an integration that knows
	 * the reason can self-heal (e.g. the cohort extension placing the buyer) and
	 * a notifier can alert the site owner. Payment adapters call this from a
	 * single shared chokepoint so WooCommerce, Stripe and PayPal all behave the
	 * same way (Basecamp Card D — the WooCommerce fulfilment ignored the enroll()
	 * return, so a cohort-gated course left the buyer charged but locked out).
	 *
	 * The provider-specific surface (a WooCommerce order note, say) stays in the
	 * adapter; this method owns only the gateway-agnostic audit + hook.
	 *
	 * @param int           $user_id   Buyer user id.
	 * @param int           $course_id Course that could not be enrolled.
	 * @param int|\WP_Error $result    The enroll() return (non-positive int or WP_Error).
	 * @param string        $provider  Payment provider slug (woocommerce / stripe / paypal).
	 * @param array         $context   Provider-specific context (e.g. order_id) passed to the hook.
	 */
	public static function report_purchase_failure( int $user_id, int $course_id, $result, string $provider, array $context = array() ): void {
		$reason = is_wp_error( $result ) ? $result->get_error_message() : __( 'Enrollment could not be created.', 'learnomy' );
		$code   = is_wp_error( $result ) ? $result->get_error_code() : 'enroll_failed';

		AuditLog::log(
			$user_id,
			'enrollment',
			'purchase_enroll_failed',
			'course',
			$course_id,
			array_merge(
				array(
					'provider' => $provider,
					'code'     => $code,
					'reason'   => $reason,
				),
				$context
			)
		);

		/**
		 * Fires when a completed payment did not result in a course enrollment.
		 * Lets integrations self-heal (the cohort extension can place the buyer
		 * into an open cohort) or alert the site owner.
		 *
		 * @param int           $user_id   Buyer user id.
		 * @param int           $course_id Course id.
		 * @param int|\WP_Error $result    The enroll() failure return.
		 * @param string        $provider  Payment provider slug.
		 * @param array         $context   Provider-specific context.
		 */
		do_action( 'learnomy_purchase_enrollment_failed', $user_id, $course_id, $result, $provider, $context );
	}

	public function unenroll( int $enrollment_id ): bool {
		$enrollment = Enrollment::find( $enrollment_id );
		if ( ! $enrollment ) {
			return false;
		}

		// Only an enrollment that is currently in an active state
		// (active / completed) counts toward the course's student_count, so
		// only such a row should decrement it on cancel. Cancelling a row that
		// is already terminal (cancelled / expired) must not touch the counter
		// — otherwise re-running unenroll() would drift it downward / clamp to 0.
		$was_active = in_array( (string) $enrollment->status, array( 'active', 'completed' ), true );

		// Distinct-aware: only decrement when this row was live AND the user has
		// no OTHER live row in the course. A member who keeps a direct enrolment
		// after their Space seat is cancelled is still one student — the counter
		// tracks distinct people (Basecamp 10109120366). Read before the flip so
		// the "other row" probe still sees the true prior state.
		$last_live_row = $was_active
			&& ! Enrollment::user_has_other_active_row( (int) $enrollment->user_id, (int) $enrollment->course_id, $enrollment_id );

		$result = Enrollment::update(
			$enrollment_id,
			[
				'status' => 'cancelled',
			]
		);

		if ( $result ) {
			if ( $last_live_row ) {
				Course::increment_student_count( (int) $enrollment->course_id, -1 );
			}
			do_action( 'learnomy_student_unenrolled', $enrollment_id, (int) $enrollment->user_id, (int) $enrollment->course_id );
		}

		return $result;
	}

	/**
	 * The exact inverse of {@see unenroll()}: hand a revoked enrolment its
	 * access back, keeping everything the student earned.
	 *
	 * Deliberately NOT {@see Enrollment::reactivate()}. That method exists for a
	 * re-subscribe — it stamps a fresh `enrolled_at`, zeroes `progress_pct` and
	 * clears `completed_at`, because a new billing period is a new start. An
	 * owner un-doing a removal means the opposite: the student picks up exactly
	 * where they were, so this only flips `status`. A row that was finished
	 * comes back as `completed`, not `active`, or restoring would quietly
	 * un-complete a course and orphan its certificate.
	 *
	 * The student_count adjustment mirrors unenroll()'s, distinct-person aware:
	 * tick up only when this restore is the user's FIRST live row in the course.
	 *
	 * @param int $enrollment_id Row to restore.
	 * @return bool True when a revoked row was restored.
	 */
	public function restore_access( int $enrollment_id ): bool {
		$enrollment = Enrollment::find( $enrollment_id );
		if ( ! $enrollment ) {
			return false;
		}

		// Already live — nothing to restore. Idempotent so a double-click, or two
		// admins on the same row, cannot double-count the student.
		if ( in_array( (string) $enrollment->status, array( 'active', 'completed' ), true ) ) {
			return false;
		}

		$status = ! empty( $enrollment->completed_at ) ? 'completed' : 'active';

		// Read before the flip, so the probe still sees the true prior state.
		$first_live_row = ! Enrollment::user_has_other_active_row(
			(int) $enrollment->user_id,
			(int) $enrollment->course_id,
			$enrollment_id
		);

		$result = Enrollment::update( $enrollment_id, array( 'status' => $status ) );

		if ( $result ) {
			if ( $first_live_row ) {
				Course::increment_student_count( (int) $enrollment->course_id, 1 );
			}

			/**
			 * Fires when a previously revoked enrolment is given access back.
			 *
			 * Deliberately the same signature as `learnomy_student_enrolled` minus
			 * the source, so integrations that re-grant an external seat can listen
			 * for a restore without inferring it from a status diff.
			 *
			 * @param int $enrollment_id Restored row id.
			 * @param int $user_id       Student.
			 * @param int $course_id     Course.
			 */
			do_action( 'learnomy_student_access_restored', $enrollment_id, (int) $enrollment->user_id, (int) $enrollment->course_id );
		}

		return $result;
	}

	public function can_access( int $user_id, int $course_id ): bool {
		return Permission_Engine::can( $user_id, 'access_course', $course_id );
	}

	public function mark_completed( int $enrollment_id ): void {
		$enrollment = Enrollment::find( $enrollment_id );
		if ( ! $enrollment ) {
			return;
		}

		Enrollment::mark_completed( $enrollment_id );

		do_action( 'learnomy_course_completed', $enrollment_id, (int) $enrollment->user_id, (int) $enrollment->course_id );
	}

	/**
	 * Re-opens a completed course for another attempt.
	 *
	 * Gated on the per-course `allow_retake` setting — returns false when
	 * the instructor hasn't enabled retakes. When `reset_on_retake` is
	 * true the student's lesson progress + quiz attempts for the course
	 * are wiped so the curriculum starts fresh; when false the enrollment
	 * row is simply flipped back to `active` and existing progress is
	 * preserved.
	 *
	 * Fires `learnomy_course_retaken` so add-ons (webhook, notifications,
	 * audit log) can react.
	 */
	public function retake( int $user_id, int $course_id ): bool {
		$settings = Course::get_all_settings( $course_id );

		if ( empty( $settings['allow_retake'] ) ) {
			return false;
		}

		// `find_for_user_course` returns both active + completed rows — we
		// need the completed one to reopen. Active rows mean the student
		// hasn't actually finished yet; no retake is required.
		$enrollment = Enrollment::find_for_user_course( $user_id, $course_id );
		if ( ! $enrollment ) {
			return false;
		}

		$reset_progress = ! empty( $settings['reset_on_retake'] );
		$new_progress   = $reset_progress ? 0 : (int) ( $enrollment->progress_pct ?? 0 );

		if ( ! Enrollment::reopen( (int) $enrollment->id, $new_progress ) ) {
			return false;
		}

		if ( $reset_progress ) {
			Progress::delete_for_user_course( $user_id, $course_id );
			QuizAttempt::delete_for_user_course( $user_id, $course_id );
		}

		/**
		 * Fires after a student has successfully re-entered a course.
		 *
		 * @param int  $user_id
		 * @param int  $course_id
		 * @param bool $reset_progress Whether lesson progress + quiz attempts were wiped.
		 */
		do_action( 'learnomy_course_retaken', $user_id, $course_id, $reset_progress );

		return true;
	}

	/**
	 * Compute and persist per-student unlock rows for every relative-drip
	 * lesson in a course the student just enrolled in.
	 *
	 * Listens to `learnomy_student_enrolled`. For each lesson where
	 * `drip_mode = 'relative'` and `drip_offset_days IS NOT NULL`, inserts
	 * `(user_id, lesson_id, NOW() + INTERVAL drip_offset_days DAY)` into
	 * `lrn_lesson_unlocks`.
	 *
	 * Idempotent — uses INSERT IGNORE on the UNIQUE (user_id, lesson_id)
	 * constraint, so re-enrolment (membership renewal, manual re-enrol,
	 * cohort rebuild) is a safe no-op for existing rows. Lessons added to a
	 * course AFTER the student enrolled won't get unlock rows from this
	 * path; if that becomes a feature request the cron handler will need a
	 * back-fill step.
	 *
	 * The action signature includes `$source` but we don't branch on it —
	 * a relative drip applies regardless of how the student got into the
	 * course (free / purchase / membership / manual / webhook).
	 *
	 * @param int    $enrollment_id Enrolment row id (unused — kept for hook contract).
	 * @param int    $user_id       Student user id.
	 * @param int    $course_id     Course id the student just enrolled in.
	 * @param string $source        Enrolment source (unused).
	 */
	public static function compute_relative_unlocks( int $enrollment_id, int $user_id, int $course_id, string $source ): void {
		unset( $enrollment_id, $source );

		if ( $user_id <= 0 || $course_id <= 0 ) {
			return;
		}

		$lessons = Lesson::get_relative_drip_for_course( $course_id );
		if ( empty( $lessons ) ) {
			return;
		}

		// Anchor every unlock to the same UTC moment so a course with N
		// lessons doesn't drift as the loop runs. `time()` is already a UTC
		// epoch — wrap with gmdate() below to render the MySQL string.
		$now_utc_ts = time();

		foreach ( $lessons as $lesson ) {
			$offset_days = isset( $lesson->drip_offset_days ) ? (int) $lesson->drip_offset_days : 0;
			if ( $offset_days < 0 ) {
				$offset_days = 0;
			}
			$unlocks_at = gmdate( 'Y-m-d H:i:s', $now_utc_ts + ( $offset_days * DAY_IN_SECONDS ) );
			Lesson_Unlock::insert_ignore( $user_id, (int) $lesson->id, $unlocks_at );
		}
	}

	/**
	 * Refuse to enroll when the requested `source` doesn't match the course's
	 * `access_type` plus the corresponding proof (completed transaction for
	 * `purchase`, active subscription covering the course for `membership`).
	 *
	 * Source semantics:
	 *   - free        → only allowed when access_type='free'
	 *   - purchase    → only with a completed transaction for (user, course)
	 *   - membership  → only with an active subscription covering this course
	 *   - manual      → only when the caller is `manage_options` (admin-driven)
	 *   - webhook     → server-internal, only allowed when WP-CLI or adapter
	 *                   webhook context (we accept it because adapters create
	 *                   the transaction first, then call enroll() in the same
	 *                   request — the transaction proof check still applies)
	 *   - paid (legacy) → adapters historically passed this; treat as
	 *                   `purchase` and require the same transaction proof
	 *
	 * Returns WP_Error on any failure; null when the source is valid.
	 */
	private function validate_source_for_access_type( int $user_id, int $course_id, string $source ): ?\WP_Error {
		$course = Course::find( $course_id );
		if ( ! $course ) {
			return new \WP_Error(
				'learnomy_enroll_course_not_found',
				__( 'Course not found', 'learnomy' ),
				array( 'status' => 404 )
			);
		}

		$access_type = $course->access_type ?? 'free';

		// Admin override: site administrators can manually enroll any user in
		// any course regardless of access_type. This is how the demo seeder,
		// the `wp learnomy test` CLI fixtures, and the admin "Enroll user"
		// flow keep working. Guard with `manage_options` so a regular member
		// cannot escalate by passing source=manual.
		if ( 'manual' === $source ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new \WP_Error(
					'learnomy_enroll_manual_denied',
					__( 'Manual enrollments require administrator capability.', 'learnomy' ),
					array( 'status' => 403 )
				);
			}
			return null;
		}

		// Normalize the legacy 'paid' source string used by older adapter code.
		if ( 'paid' === $source ) {
			$source = 'purchase';
		}

		if ( 'free' === $source ) {
			// Zero-charge covers the paid-model course priced at zero — the
			// CTA already advertises it as free, so the guard must agree.
			if ( 'free' === $access_type || Course::is_zero_charge( $course ) ) {
				return null;
			}
			return new \WP_Error(
				'learnomy_enroll_payment_required',
				__( 'This course requires payment or an active membership. Please complete checkout first.', 'learnomy' ),
				array( 'status' => 402 )
			);
		}

		if ( 'purchase' === $source ) {
			$txn = Transaction::find_completed_for_user_course( $user_id, $course_id );
			if ( $txn ) {
				return null;
			}
			return new \WP_Error(
				'learnomy_enroll_transaction_missing',
				__( 'No completed payment was found for this course. Please complete checkout first.', 'learnomy' ),
				array( 'status' => 402 )
			);
		}

		if ( 'membership' === $source ) {
			$membership_service = new Subscription_Service();
			if ( $membership_service->has_access( $user_id, $course_id ) ) {
				return null;
			}
			return new \WP_Error(
				'learnomy_enroll_membership_required',
				__( 'No active membership covers this course. Please subscribe first.', 'learnomy' ),
				array( 'status' => 402 )
			);
		}

		// Course-level recurring subscription (PR-A1). Accepts a row when
		// the user has an active subscription targeting this specific
		// course in lrn_member_subscriptions.
		if ( 'subscription' === $source ) {
			if ( \Learnomy\Models\Subscription::find_active_for_user_course( $user_id, $course_id ) ) {
				return null;
			}
			return new \WP_Error(
				'learnomy_enroll_subscription_required',
				__( 'No active course subscription was found. Please subscribe first.', 'learnomy' ),
				array( 'status' => 402 )
			);
		}

		if ( 'webhook' === $source ) {
			// Webhook source is server-internal — only adapter webhook handlers
			// should pass this. We still demand a completed transaction so a
			// stolen webhook secret cannot enroll arbitrary users.
			$txn = Transaction::find_completed_for_user_course( $user_id, $course_id );
			if ( $txn ) {
				return null;
			}
			return new \WP_Error(
				'learnomy_enroll_webhook_no_transaction',
				__( 'Webhook enrollment requires a completed transaction.', 'learnomy' ),
				array( 'status' => 402 )
			);
		}

		// Pro-managed enrollment sources (e.g. 'cohort', 'space') are not
		// known to Free — Free does not own cohort/space membership logic.
		// Delegate the access decision to the owning extension via a filter
		// so Pro can authorize the source when (and only when) the user has
		// a genuine membership. Contract:
		// - return null  → access granted (extension validated it)
		// - return WP_Error → access denied with that error
		// - return the passed-through default (a sentinel) → no extension
		// claimed this source, fall through to the invalid-source error.
		// This keeps Free generic + lets Pro extend per the architecture rule
		// (Pro never modifies Free; it hooks Free's filters).
		$unclaimed    = new \stdClass();
		$pro_decision = apply_filters(
			'learnomy_validate_enrollment_source',
			$unclaimed,
			$source,
			$user_id,
			$course_id,
			$access_type
		);
		if ( $pro_decision instanceof \WP_Error ) {
			return $pro_decision;
		}
		if ( null === $pro_decision ) {
			return null;
		}

		return new \WP_Error(
			'learnomy_enroll_invalid_source',
			/* translators: %s: invalid source value */
			sprintf( __( 'Unrecognised enrollment source "%s".', 'learnomy' ), $source ),
			array( 'status' => 400 )
		);
	}
}

// Bind the per-student unlock computation to the canonical enrolment hook.
// Lives at file scope (not inside the class) so the binding fires the first
// time the autoloader pulls the service in. Matches the Drip_Cron pattern.
add_action(
	'learnomy_student_enrolled',
	array( \Learnomy\Services\Enrollment_Service::class, 'compute_relative_unlocks' ),
	10,
	4
);
