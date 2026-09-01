<?php
/**
 * Progress business logic — tracks per-lesson completion and rolls it up to the course level.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\CompletionRulesNotMet;
use Learnomy\LessonNotReachable;
use Learnomy\Models\Course;
use Learnomy\Models\Progress;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Lesson;
use Learnomy\Models\Quiz;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\Certificate;
use function Learnomy\table;
use function Learnomy\now;

class Progress_Service {

	/**
	 * Mark a lesson complete, enforcing per-lesson completion rules first.
	 *
	 * Rules are evaluated against the student's current progress. If any rule
	 * fails a {@see CompletionRulesNotMet} exception is thrown carrying the
	 * structured status — the REST controller maps this to HTTP 422 so the
	 * UI can render a "X of Y requirements met" toast.
	 *
	 * Admin and instructor force-complete flows (see bulk grading, manual
	 * override) pass `$bypass_rules = true` to skip evaluation.
	 *
	 * CF-008: $time_spent_seconds is the client-reported session time (seconds)
	 * sent alongside the Mark Complete click. When > 0 it is folded into the
	 * progress upsert so a student who completed a lesson in a single session
	 * without triggering the /progress heartbeat (e.g. short lessons, offline
	 * mode) still gets their time recorded. Zero means "client did not supply
	 * it" — in that case the stored time_spent_seconds is left unchanged because
	 * Progress::create_or_update() only overwrites the column when the field is
	 * present in $fields.
	 *
	 * @throws LessonNotReachable    When the lesson has not unlocked for this
	 *                               student yet — drip date, or a sequence lock.
	 *                               Checked BEFORE rules: a shut lesson has no
	 *                               requirements the student could satisfy today.
	 * @throws CompletionRulesNotMet When rules aren't satisfied.
	 */
	public function mark_lesson_complete( int $user_id, int $lesson_id, bool $bypass_rules = false, int $time_spent_seconds = 0, array $client_signals = [] ): void {
		$lesson = Lesson::find( $lesson_id );
		if ( ! $lesson ) {
			return;
		}

		if ( ! $bypass_rules ) {
			// Reachability BEFORE rules, because they answer different questions
			// and this one is not the student's to satisfy. Completion rules ask
			// "have you done the work"; this asks "is the lesson even open yet".
			//
			// It has to live here rather than in each controller. The rule used to
			// exist only as a redirect in Single_Lesson_View_Data, which made the
			// page render the sole guard: POSTing to /lessons/{id}/complete
			// completed a drip-locked lesson with a 200, and /progress/sync did the
			// same from an offline batch (Basecamp 10143220028, 10134139/10134140167).
			// Every current and future writer inherits the gate from this line.
			//
			// $bypass_rules skips it on purpose — admin/instructor force-complete
			// and bulk grading are exactly the cases that must override a lock.
			if ( ! Lesson_Service::is_reachable_by( $lesson, $user_id ) ) {
				$reason = Lesson_Service::is_row_available_to( $lesson, $user_id ) ? 'sequence' : 'drip';
				throw new LessonNotReachable( $lesson_id, $reason ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}

			// The client's live watch % rides in on $client_signals; the rule
			// evaluator also reads the persisted high-water mark, so the
			// auto_video rule is satisfiable from either source.
			$video_pct = (int) ( $client_signals['video_pct'] ?? 0 );
			$status    = $this->evaluate_completion_rules( $user_id, $lesson_id, $video_pct, $client_signals );
			if ( ! $status['all_met'] ) {
				// $status is a structured payload consumed by the REST controller — never echoed.
				throw new CompletionRulesNotMet( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}

		$progress_fields = [
			'status'       => 'completed',
			'completed_at' => now(),
		];

		// CF-008: merge client-reported time into the upsert so a single-session
		// completion doesn't lose the time data. The ON DUPLICATE KEY handler in
		// Progress::create_or_update() adds this to any seconds already stored by
		// the /progress heartbeat — it is not a replace but an accumulation.
		if ( $time_spent_seconds > 0 ) {
			$progress_fields['time_spent_seconds'] = $time_spent_seconds;
		}

		Progress::create_or_update(
			$user_id,
			'lesson',
			$lesson_id,
			$progress_fields
		);

		$course_id = (int) $lesson->course_id;
		$progress  = $this->calculate_course_progress( $user_id, $course_id );

		// Every enrollment row for this (user, course) carries the number, not
		// just the one this call happened to resolve — see
		// Enrollment::set_progress_for_user_course().
		Enrollment::set_progress_for_user_course( $user_id, $course_id, $progress );

		$enrollment = Enrollment::find_active( $user_id, $course_id ); // active-only-ok: transition/progress path genuinely wants the in-progress row
		if ( $enrollment && $this->course_completion_satisfied( $user_id, $course_id, $progress ) ) {
			$enrollment_service = new Enrollment_Service();
			$enrollment_service->mark_completed( (int) $enrollment->id );
		}

		do_action( 'learnomy_lesson_completed', $user_id, $lesson_id, $course_id );
	}

	public function record_time( int $user_id, int $lesson_id, int $seconds ): void {
		if ( $seconds <= 0 ) {
			return;
		}

		Progress::record_time( $user_id, $lesson_id, $seconds );

		// Bug fix B15 (PRODUCTION-READINESS.md): touch enrollment.last_accessed_at
		// so the Resume button on the student dashboard reflects real activity,
		// not just demo-seeder timestamps. The lesson player's JS heartbeat fires
		// record_time() every 10s of active view, making this the right hook
		// point — only fires when the student is actually engaged with a lesson.
		$lesson = Lesson::find( $lesson_id );
		if ( $lesson ) {
			$enrollment = Enrollment::find_active( $user_id, (int) $lesson->course_id ); // active-only-ok: touches last_accessed for an in-progress learner
			if ( $enrollment ) {
				Enrollment::update(
					(int) $enrollment->id,
					array( 'last_accessed_at' => now() )
				);
			}
		}
	}

	/**
	 * Evaluate the per-lesson completion rules against a student's progress.
	 *
	 * Returns a status payload that drives the student-facing progress strip
	 * (one line per active rule with current vs. target numbers) AND the
	 * 422 response when `mark_lesson_complete()` rejects an early click.
	 *
	 * `completion_mode` values (see LESSON-COMPLETION-RULES-SPEC.md):
	 *   - manual            no requirements, just the click
	 *   - auto_time         requires N seconds on page
	 *   - auto_video        requires N% of video watched
	 *   - auto_scroll       requires scroll-to-end
	 *   - auto_quiz_pass    requires attached quiz to be passed
	 *   - auto_combined     any combination of the above, each active rule must pass
	 *
	 * @return array{
	 *     lesson_id:int,
	 *     completion_mode:string,
	 *     requirements:array<int,array<string,mixed>>,
	 *     all_met:bool,
	 *     locks_next:bool,
	 *     is_completed:bool,
	 *     video_pct:int
	 * }
	 */
	public function evaluate_completion_rules( int $user_id, int $lesson_id, int $video_pct = 0, array $client_signals = [] ): array {
		$lesson = Lesson::find( $lesson_id );
		if ( ! $lesson ) {
			return [
				'lesson_id'       => $lesson_id,
				'completion_mode' => 'manual',
				'requirements'    => [],
				'all_met'         => true,
				'locks_next'      => false,
				'is_completed'    => false,
				'video_pct'       => 0,
			];
		}

		$mode        = isset( $lesson->completion_mode ) && $lesson->completion_mode
			? (string) $lesson->completion_mode
			: 'manual';
		$min_time    = isset( $lesson->min_time_seconds ) ? (int) $lesson->min_time_seconds : 0;
		$min_video   = isset( $lesson->min_video_watch_pct ) ? (int) $lesson->min_video_watch_pct : 0;
		$need_scroll = ! empty( $lesson->require_scroll_end );
		$need_quiz   = ! empty( $lesson->require_quiz_pass );
		$locks_next  = ! empty( $lesson->lock_next_until_complete );

		// A MediaShield protected video plays in its own player and emits no
		// watch-percentage events, so a video-watch requirement could never be
		// satisfied. Treat the rule as inactive for this content type — mirrors
		// the editor, which hides the rule — so a lesson switched from video to
		// mediashield (carrying a stale min_video_watch_pct) stays completable
		// via its other rules and the mark-complete action.
		if ( isset( $lesson->content_type ) && 'mediashield' === $lesson->content_type ) {
			$min_video = 0;
		}

		$progress_row = Progress::get_for_user_and_item( $user_id, 'lesson', $lesson_id );
		$is_completed = $progress_row && 'completed' === $progress_row->status;
		$current_time = $progress_row ? (int) ( $progress_row->time_spent_seconds ?? 0 ) : 0;
		// Watch % has two sources: the mark persisted by the /progress heartbeat,
		// and the live reading the caller attached to THIS request. Taking the
		// higher of the two unconditionally is what let a lesson be completed
		// with zero seconds watched: the /complete POST carried video_pct=100,
		// nothing corroborated it, and the row was written `completed` with
		// video_watch_pct still 0 (Basecamp 10134140085).
		//
		// So when the number actually gates something, both sources are bounded
		// by the watch time the server has OBSERVED — see
		// observed_watch_ceiling_pct(). A request cannot vouch for itself: with
		// no ceiling available the persisted mark is all the server will stand
		// behind. When no video rule is active the number is display-only and
		// the live value is used as-is.
		$stored_pct  = $progress_row ? (int) ( $progress_row->video_watch_pct ?? 0 ) : 0;
		$live_pct    = max( 0, min( 100, (int) $video_pct ) );
		$current_pct = max( 0, min( 100, $stored_pct ) );

		if ( $min_video > 0 ) {
			$watch_ceiling = $this->observed_watch_ceiling_pct( $lesson, $progress_row );
			if ( null !== $watch_ceiling ) {
				$current_pct = min( max( $current_pct, $live_pct ), $watch_ceiling );
			}
		} else {
			$current_pct = max( $current_pct, $live_pct );
		}

		$requirements = [];

		// Which rules are active for the chosen mode.
		$active = [
			'time'      => false,
			'video'     => false,
			'scroll'    => false,
			'quiz_pass' => false,
			'standard'  => false,
		];

		switch ( $mode ) {
			case 'auto_time':
				$active['time'] = $min_time > 0;
				break;
			case 'auto_video':
				$active['video'] = $min_video > 0;
				break;
			case 'auto_scroll':
				$active['scroll'] = true;
				break;
			case 'auto_quiz_pass':
				$active['quiz_pass'] = true;
				break;
			case 'auto_standard':
				$active['standard'] = true;
				break;
			case 'auto_combined':
			case 'combined':
				$active['time']      = $min_time > 0;
				$active['video']     = $min_video > 0;
				$active['scroll']    = $need_scroll;
				$active['quiz_pass'] = $need_quiz;
				break;
			case 'manual':
			default:
				// No active rules — click-only.
				break;
		}

		if ( $active['time'] ) {
			$met            = $current_time >= $min_time;
			$requirements[] = [
				'kind'            => 'time',
				'min_seconds'     => $min_time,
				'current_seconds' => $current_time,
				'remaining'       => max( 0, $min_time - $current_time ),
				'met'             => $met,
				'label'           => sprintf(
					/* translators: 1: elapsed time e.g. 0:30, 2: required time e.g. 2:00 */
					__( 'Spend time on this lesson: %1$s / %2$s', 'learnomy' ),
					$this->format_duration( $current_time ),
					$this->format_duration( $min_time )
				),
			];
		}

		if ( $active['video'] ) {
			$met            = $current_pct >= $min_video;
			$requirements[] = [
				'kind'          => 'video',
				'min_pct'       => $min_video,
				'current_pct'   => $current_pct,
				'remaining_pct' => max( 0, $min_video - $current_pct ),
				'met'           => $met,
				'label'         => sprintf(
					/* translators: 1: current watch percent, 2: required watch percent */
					__( 'Watch %2$d%% of the video: %1$d%% / %2$d%%', 'learnomy' ),
					$current_pct,
					$min_video
				),
			];
		}

		if ( $active['scroll'] ) {
			// Scroll completion is enforced client-side (the lesson player's
			// progress-view module fires only after the student reaches the
			// bottom of the document). The server cannot observe scroll
			// directly, so it trusts the `scrolled_to_end` signal that the
			// frontend includes on the /complete POST. The old gate at
			// `progress_row->completed_at` was circular — it required the
			// lesson to be complete before scroll could be marked met, which
			// meant the rule could never satisfy itself. See Basecamp
			// #9925249672.
			$scroll_met     = ( $progress_row && ! empty( $progress_row->completed_at ) )
				|| ! empty( $client_signals['scrolled_to_end'] );
			$requirements[] = [
				'kind'  => 'scroll',
				'met'   => (bool) $scroll_met,
				'label' => __( 'Scroll to the end of the lesson', 'learnomy' ),
			];
		}

		if ( $active['quiz_pass'] ) {
			$quiz = $this->find_attached_quiz( $lesson_id );
			if ( $quiz ) {
				// THE RATCHET, at lesson scope: a lesson the student has already
				// completed stays completed. `$all_met` is short-circuited by
				// `$is_completed` further down either way, but the requirement's
				// own flag drives the progress strip — without this the student
				// reads "Pass the attached quiz" as unmet on a lesson they have
				// finished, which is the revocation showing up in the UI even
				// though the gate itself no longer moves.
				$passed         = $is_completed || $this->has_passed_quiz( $user_id, $quiz );
				$requirements[] = [
					'kind'    => 'quiz_pass',
					'quiz_id' => (int) $quiz->id,
					'met'     => $passed,
					'label'   => __( 'Pass the attached quiz', 'learnomy' ),
				];
			} else {
				// Rule is active but no quiz is actually attached — treat as auto-met.
				$requirements[] = [
					'kind'  => 'quiz_pass',
					'met'   => true,
					'label' => __( 'Pass the attached quiz', 'learnomy' ),
				];
			}
		}

		if ( $active['standard'] ) {
			// The standard's own engine (SCORM/cmi5/LTI) owns this verdict. Free
			// cannot read lrn_pro_* — Pro answers the filter. A null answer means
			// no standard module claims this lesson (module off, or the lesson's
			// content type changed), in which case the rule cannot be satisfied
			// and the learner is told to contact the instructor rather than being
			// silently let through.
			$verdict = apply_filters( 'learnomy_lesson_standard_completion', null, $user_id, $lesson );
			$met     = ( true === $verdict );

			$requirements[] = [
				'kind'  => 'standard',
				'met'   => $met,
				'label' => ( null === $verdict )
					? __( 'This lesson is not reporting completion. Contact your instructor.', 'learnomy' )
					: __( 'Finish the course content until it reports completion', 'learnomy' ),
			];
		}

		$all_met = true;
		foreach ( $requirements as $req ) {
			if ( empty( $req['met'] ) ) {
				$all_met = false;
				break;
			}
		}

		// Manual mode has zero requirements — all_met is trivially true so the
		// click still fires. `is_completed` short-circuits re-evaluation once
		// the student has already crossed the line.
		if ( 'manual' === $mode || $is_completed ) {
			$all_met = true;
		}

		// Resolve the per-context enforcement mode (site → course → lesson →
		// role bypass → filter) and apply its semantics before publishing the
		// status. This lets a site owner ship strict rules by default while
		// individual courses or lessons go advisory or off.
		$enforcement = $this->resolve_enforcement( $user_id, $lesson );

		// auto_standard is a compliance gate, not a nudge. advisory/off relax the
		// other modes; they must never relax this one or the SCORM bypass returns.
		// This guard MUST sit here, where enforcement mutates $all_met — not at
		// the $status build below, by which point off has already emptied
		// $requirements and set $all_met=true, so a later label change would leave
		// the bypass wide open while looking correct in review.
		$is_standard_gate = ( 'auto_standard' === $mode );

		if ( 'off' === $enforcement && ! $is_standard_gate ) {
			// Drop the requirements entirely — no strip rendered, button enabled.
			$requirements = [];
			$all_met      = true;
		} elseif ( 'advisory' === $enforcement && ! $is_standard_gate ) {
			// Show the strip so the student sees what they're skipping, but the
			// button stays enabled. Honest signal, soft enforcement.
			$all_met = true;
		}

		if ( $is_standard_gate ) {
			// Reassigned BEFORE $status is built below, so the strip and the REST
			// payload both report strict rather than the site/course default.
			$enforcement = 'strict';
		}

		/**
		 * Filters the evaluated completion status for a lesson.
		 *
		 * Pro extensions (content-drip, etc.) can append extra requirements or
		 * override `all_met` — e.g. blocking completion until an unlock date.
		 *
		 * @param array  $status  Current status payload.
		 * @param int    $user_id Student user ID.
		 * @param object $lesson  Lesson row.
		 */
		$status = [
			'lesson_id'       => $lesson_id,
			'completion_mode' => $mode,
			'enforcement'     => $enforcement,
			'requirements'    => $requirements,
			'all_met'         => $all_met,
			'locks_next'      => $locks_next,
			'is_completed'    => (bool) $is_completed,
			'video_pct'       => $current_pct,
		];

		return (array) apply_filters( 'learnomy_lesson_completion_rules', $status, $user_id, $lesson );
	}

	/**
	 * Resolve the active completion-enforcement mode for a (user, lesson) pair.
	 *
	 * Five layers, lowest precedence first:
	 *
	 *   1. Site-wide setting (`learnomy_settings['completion_enforcement']`,
	 *      default 'strict').
	 *   2. Course-level override (`lrn_courses.completion_enforcement`,
	 *      non-NULL wins).
	 *   3. Lesson-level override (`lrn_lessons.completion_enforcement`,
	 *      non-NULL wins).
	 *   4. Role bypass — admin and the course's primary instructor are
	 *      always treated as 'off' so they can preview / review content
	 *      without sitting through every time-/video-watch rule.
	 *   5. `learnomy_completion_enforcement` filter — always wins. Lets
	 *      Pro extensions (or per-site code) layer arbitrary policy.
	 *
	 * @return string One of 'strict', 'advisory', 'off'.
	 */
	public function resolve_enforcement( int $user_id, object $lesson ): string {
		$valid_modes = [ 'strict', 'advisory', 'off' ];

		// Layer 1: site setting.
		$settings = (array) get_option( 'learnomy_settings', [] );
		$mode     = isset( $settings['completion_enforcement'] ) && in_array( $settings['completion_enforcement'], $valid_modes, true )
			? (string) $settings['completion_enforcement']
			: 'strict';

		// Layer 2: course override.
		if ( ! empty( $lesson->course_id ) ) {
			$course = Course::find( (int) $lesson->course_id );
			if ( $course && ! empty( $course->completion_enforcement ) && in_array( $course->completion_enforcement, $valid_modes, true ) ) {
				$mode = (string) $course->completion_enforcement;
			}
		}

		// Layer 3: lesson override.
		if ( ! empty( $lesson->completion_enforcement ) && in_array( $lesson->completion_enforcement, $valid_modes, true ) ) {
			$mode = (string) $lesson->completion_enforcement;
		}

		// Layer 4: role bypass. Admins and the course's own instructor short-
		// circuit to 'off' so they can step through content quickly. This is
		// the same path that backs admin "preview as student" workflows.
		if ( $this->user_can_bypass_enforcement( $user_id, $lesson ) ) {
			$mode = 'off';
		}

		/**
		 * Layer 5: filter. Last word on the resolved enforcement mode. Return
		 * one of 'strict', 'advisory', 'off' — any other string falls back to
		 * the resolved value.
		 *
		 * @param string $mode    Resolved mode after layers 1-4.
		 * @param int    $user_id Student user ID.
		 * @param object $lesson  Lesson row.
		 */
		$filtered = (string) apply_filters( 'learnomy_completion_enforcement', $mode, $user_id, $lesson );

		return in_array( $filtered, $valid_modes, true ) ? $filtered : $mode;
	}

	private function user_can_bypass_enforcement( int $user_id, object $lesson ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		// Admins always.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		// Course's primary instructor — author_id on lrn_courses.
		if ( ! empty( $lesson->course_id ) ) {
			$course = Course::find( (int) $lesson->course_id );
			if ( $course && (int) $course->author_id === $user_id ) {
				return true;
			}
		}

		return false;
	}

	public function restart_course( int $user_id, int $course_id ): void {
		// Reset every lesson progress row for this course.
		Progress::reset_for_course( $user_id, $course_id );

		// Roll the enrollment back to a fresh in-progress state.
		// reset_for_user_course() sets status='active' + progress_pct=0 +
		// completed_at=NULL in one write. restart is almost always run on a
		// COMPLETED course, which find_active() could not see — so the old
		// find-then-update touched nothing (or, if active, left status alone),
		// stranding the row at status='completed', progress 0: an impossible
		// state that never returned to active. [find_active class 10108551275]
		Enrollment::reset_for_user_course( $user_id, $course_id );

		// Remove quiz attempts attached to this course's quizzes.
		QuizAttempt::delete_for_course( $user_id, $course_id );

		// Revoke any active certificate so a re-earned one can be issued fresh.
		$certs = Certificate::where(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
				'status'    => 'active',
			]
		);
		foreach ( $certs as $cert ) {
			Certificate::revoke( (int) $cert->id );
		}

		do_action( 'learnomy_course_restarted', $user_id, $course_id );
	}

	public function calculate_course_progress( int $user_id, int $course_id ): int {
		$all_lessons = Lesson::get_for_course( $course_id );
		$total       = count( $all_lessons );

		$progress_rows = Progress::get_all_for_course( $user_id, $course_id );
		$completed     = count( array_filter( $progress_rows, fn( $p ) => 'completed' === $p->status ) );

		/*
		 * On a `lessons_and_quiz` course the quiz is a required step, so it
		 * counts as one — otherwise a student who finished every lesson and
		 * failed the quiz read 100% on a course that cannot complete, with
		 * nothing on screen explaining the gap (BC #10100663377). Only this
		 * completion type gates on a quiz; every other type keeps counting
		 * lessons alone, so their numbers do not move.
		 */
		$course = Course::find( $course_id );
		if ( $course && 'lessons_and_quiz' === ( $course->completion_type ?? '' ) ) {
			++$total;
			if ( $this->course_has_passing_quiz_attempt( $user_id, $course_id ) ) {
				++$completed;
			}
		}

		if ( 0 === $total ) {
			return 0;
		}

		$percentage = (int) round( ( $completed / $total ) * 100 );

		/*
		 * round() reaches 100 while a step is still outstanding once a course
		 * has enough steps (199/200 = 99.5 -> 100), and `all_lessons` completes
		 * on a bare `100 === $progress`. Never report a full bar until every
		 * step is actually done.
		 */
		if ( $completed < $total && $percentage >= 100 ) {
			$percentage = 99;
		}

		return $percentage;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * MM:SS formatter for the progress strip. Minutes can exceed 59 for very
	 * long lessons — we don't roll into hours because the UI is already tight.
	 */
	private function format_duration( int $seconds ): string {
		$seconds  = max( 0, $seconds );
		$minutes  = intdiv( $seconds, 60 );
		$residual = $seconds % 60;
		return sprintf( '%d:%02d', $minutes, $residual );
	}

	/**
	 * Look up the quiz attached to a lesson via `lrn_quizzes.lesson_id`.
	 * Returns the first active quiz — a lesson should have at most one.
	 */
	private function find_attached_quiz( int $lesson_id ): ?object {
		$rows = Quiz::where( [ 'lesson_id' => $lesson_id ], 'id', 'ASC', 1 );
		return $rows ? $rows[0] : null;
	}

	/**
	 * The highest watch percentage the server is willing to credit, given how
	 * long it has actually observed this student on this lesson.
	 *
	 * "Watched 90% of the video" is a claim about time. The only part of that
	 * claim the server can check on its own is the wall clock: at most
	 * `elapsed x max_playback_rate` seconds of media can have gone past since
	 * the student's progress row was created. Dividing that by the lesson's
	 * length gives a ceiling that a scrubbed-to-the-end player, or a hand-rolled
	 * POST, cannot climb over — completing a "must watch" lesson costs real time
	 * again instead of one click.
	 *
	 * The multiplier is the fastest speed our player offers (Plyr tops out at
	 * 2x), so a student who legitimately watches at double speed is never held
	 * back; `learnomy_video_max_playback_rate` widens it for a site running a
	 * different player.
	 *
	 * Honest about what it does NOT do:
	 *   - It returns null when the lesson has no `duration_seconds` — the ONLY
	 *     unknowable case. A percentage of an unknown length cannot be
	 *     converted to seconds, so there is nothing to bound; the caller then
	 *     trusts only the persisted mark. Set the lesson duration to get the
	 *     check.
	 *   - The window starts at the progress row, so a student who opened the
	 *     lesson yesterday has a day of credit. That is intended — they DID
	 *     have the time. It buys minutes, not certainty.
	 *   - A scripted client that drip-feeds heartbeats over the video's real
	 *     length still gets through. Nothing server-side can tell that apart
	 *     from watching, and by then the time has been spent anyway.
	 *
	 * @param object      $lesson       Lesson row (reads duration_seconds).
	 * @param object|null $progress_row The student's row for this lesson.
	 * @return int|null Ceiling percentage 0-100, or null when unknowable.
	 */
	private function observed_watch_ceiling_pct( object $lesson, ?object $progress_row ): ?int {
		$duration = isset( $lesson->duration_seconds ) ? (int) $lesson->duration_seconds : 0;
		if ( $duration <= 0 ) {
			return null;
		}

		// Progress timestamps are written by \Learnomy\now(), which is UTC.
		// NO row at all means the server has observed nothing — a ceiling of
		// zero, not an unknown one. That is the case the card's spoofed
		// /complete hit: a claim of 100% from a student the server has never
		// seen open the lesson.
		$started = ( $progress_row && ! empty( $progress_row->created_at ) )
			? strtotime( (string) $progress_row->created_at . ' UTC' )
			: 0;

		/**
		 * Fastest playback rate a student could plausibly have used. The bundled
		 * player offers up to 2x; raise it for a site that swaps in a faster one.
		 *
		 * @param float  $rate   Maximum playback rate.
		 * @param object $lesson Lesson being evaluated.
		 */
		$max_rate = (float) apply_filters( 'learnomy_video_max_playback_rate', 2.0, $lesson );
		$max_rate = max( 1.0, $max_rate );

		$observed = $started ? max( 0, time() - $started ) : 0;
		$ceiling  = (int) min( 100, floor( ( $observed * $max_rate / $duration ) * 100 ) );

		/**
		 * Last word on the watch ceiling. The escape hatch for a site whose
		 * player, hosting or content makes the wall-clock bound wrong — return
		 * 100 to credit whatever the client reports, as before this check
		 * existed.
		 *
		 * @param int         $ceiling      Ceiling percentage 0-100.
		 * @param object      $lesson       Lesson being evaluated.
		 * @param object|null $progress_row The student's row for this lesson.
		 */
		return (int) apply_filters( 'learnomy_video_watch_ceiling_pct', $ceiling, $lesson, $progress_row );
	}

	/**
	 * Has the student passed this quiz, as the teacher defined "passed"?
	 *
	 * The verdict is the quiz's own `grading_method` applied to the student's
	 * attempts — not "did any sitting ever clear the bar", which is what this
	 * asked until 1.8.1 and which contradicted the setting the teacher chose. On
	 * an `average`-graded quiz with sittings 40 / 100 / 40 the official score is
	 * 60; against a pass mark of 70 that is a fail, and the course used to issue
	 * a certificate off the 100 anyway. `average` is chosen precisely to stop one
	 * lucky sitting counting, so honouring it is the whole point of the setting.
	 *
	 * Practice / survey quizzes bypass the pass mark entirely — a practice run is
	 * not an exam and a survey has no right answer, so neither may hold a course
	 * shut. That policy lives here, not in the grading-method predicate, because
	 * it is a completion-gate rule rather than a grading rule.
	 */
	private function has_passed_quiz( int $user_id, object $quiz ): bool {
		return $this->passed_quizzes( $user_id, [ $quiz ] )[ (int) $quiz->id ] ?? false;
	}

	/**
	 * {@see has_passed_quiz()} for a SET of quiz rows, resolving every graded
	 * quiz in one batch.
	 *
	 * The course gate asks about every quiz in the course and runs on every
	 * lesson completion, so the per-quiz form was an N+1 on the plugin's hottest
	 * write path — and the method-aware predicate reads more per quiz than the
	 * old score scan did, which would have made it worse. Batching turns
	 * "one query per quiz" into one query for all of them.
	 *
	 * @param int      $user_id Student.
	 * @param object[] $quizzes Quiz rows.
	 * @return array<int, bool> quiz_id => passed.
	 */
	private function passed_quizzes( int $user_id, array $quizzes ): array {
		$verdicts = [];
		$graded   = [];

		foreach ( $quizzes as $quiz ) {
			$quiz_id = (int) ( $quiz->id ?? 0 );
			if ( $quiz_id <= 0 ) {
				continue;
			}
			if ( ! empty( $quiz->mode ) && in_array( (string) $quiz->mode, [ 'practice', 'survey' ], true ) ) {
				$verdicts[ $quiz_id ] = true;
				continue;
			}
			$graded[ $quiz_id ] = $quiz;
		}

		if ( ! empty( $graded ) ) {
			$verdicts += ( new Quiz_Service() )->passed_by_grading_method( $user_id, $graded );
		}

		return $verdicts;
	}

	/**
	 * Course-level completion gate. Returns true only when EVERY rule for the
	 * course's `completion_type` is satisfied — including quiz pass for the
	 * `lessons_and_quiz` and `lessons_and_quiz` types.
	 *
	 * The lesson-percentage check happens upstream (`calculate_course_progress`
	 * must have hit 100 before this is called). This method adds the
	 * type-specific gate that the percentage doesn't capture.
	 *
	 * Without this gate, a `lessons_and_quiz` course issued the certificate
	 * the moment all lessons hit `progress.status='completed'`, regardless
	 * of whether the student passed (or even attempted) the attached quiz.
	 */
	/**
	 * Re-evaluate course completion for a user. Called from two upstream
	 * paths: lesson-complete (via mark_lesson_complete) and quiz-submit
	 * (via Quiz_Service::submit). Idempotent — if the enrollment is
	 * already completed, the second call is a no-op.
	 *
	 * J-03.3 — before this helper, quiz pass on a lessons_and_quiz course
	 * left the enrollment stuck at progress_pct=100 / completed_at=NULL
	 * because only lesson-complete re-checked completion. Now both
	 * upstream paths converge here.
	 *
	 * @return bool True when the call resulted in the enrollment being
	 *              marked completed; false otherwise.
	 */
	public function maybe_complete_course( int $user_id, int $course_id ): bool {
		$enrollment = Enrollment::find_active( $user_id, $course_id ); // active-only-ok: transition/progress path genuinely wants the in-progress row
		if ( ! $enrollment ) {
			return false;
		}
		// THE RATCHET on the write path: an already-completed enrollment is left
		// exactly as it is. This method only ever GRANTS completion — there is no
		// branch that clears completed_at or moves the status back — so a rule
		// change can never un-complete anyone through here. See
		// completion_already_granted() for the read-side half.
		if ( ! empty( $enrollment->completed_at ) || 'completed' === ( $enrollment->status ?? '' ) ) {
			return false;
		}

		$progress = $this->calculate_course_progress( $user_id, $course_id );
		Enrollment::set_progress_for_user_course( $user_id, $course_id, $progress );

		if ( ! $this->course_completion_satisfied( $user_id, $course_id, $progress ) ) {
			return false;
		}

		$enrollment_service = new Enrollment_Service();
		$enrollment_service->mark_completed( (int) $enrollment->id );
		return true;
	}

	/**
	 * Read-only twin of {@see maybe_complete_course()}: does this student
	 * currently satisfy the course's completion rule?
	 *
	 * Answers the question WITHOUT touching the enrollment row, for surfaces
	 * that must decide what to render before (or instead of) the write path
	 * running — the single-course view-data being the first. That view used to
	 * carry its own rule ("count completed lesson-progress rows >= lesson
	 * count"), which is not the same question: on a `lessons_and_quiz` course it
	 * declared a student complete with the final exam still unattempted, and the
	 * Continue CTA then routed them to My Courses instead of the pending quiz
	 * (Basecamp 10134140444). One rule, one place.
	 *
	 * Note `manual` courses always answer false here — by design, completion is
	 * the admin's explicit flip, which callers read off `enrollment.status`.
	 */
	public function is_course_complete( int $user_id, int $course_id ): bool {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return false;
		}

		// THE RATCHET — see completion_already_granted(). Asked BEFORE the rule,
		// so tightening the rule can never take a finished course back off a
		// student who already finished it.
		if ( $this->completion_already_granted( $user_id, $course_id ) ) {
			return true;
		}

		return $this->course_completion_satisfied(
			$user_id,
			$course_id,
			$this->calculate_course_progress( $user_id, $course_id )
		);
	}

	/**
	 * THE RATCHET: completion only ever moves forward, it is never revoked.
	 *
	 * A completed enrollment and an issued certificate are GRANTS — things the
	 * student was told they had earned. When the rule that produced them changes
	 * (as it did in 1.8.1, when the quiz gate started honouring the teacher's
	 * `grading_method` instead of accepting any lucky sitting), the new rule
	 * applies to who finishes NEXT. It does not reach back and un-graduate people.
	 *
	 * This is a deliberate trade, made in the teacher's favour: it leaves a small
	 * number of historical over-grants standing. A teacher would be far angrier
	 * about certificates vanishing from students who earned them under the rules
	 * as they were told them — and would have no way to explain it to those
	 * students — than about a handful of old passes that would not be granted
	 * today. Correcting a specific historical grant stays a deliberate act: an
	 * admin can revoke the certificate or reset the enrollment.
	 *
	 * It is a real check on the real grants, not an implicit side effect of some
	 * caller happening to look at `enrollment.status` first, because every one of
	 * those callers is one refactor away from not doing that any more.
	 */
	private function completion_already_granted( int $user_id, int $course_id ): bool {
		// find_for_user_course, NOT find_active — a completed enrollment is
		// exactly the row find_active() excludes, and it is the row that matters
		// here.
		$enrollment = Enrollment::find_for_user_course( $user_id, $course_id );
		if ( $enrollment && Enrollment::is_completed( $enrollment ) ) {
			return true;
		}

		// A certificate can outlive the enrollment row that produced it (source
		// change, re-enrollment), and it is the artefact the student actually
		// holds, so it grants on its own.
		return null !== Certificate::find_active_for_user_course( $user_id, $course_id );
	}

	/**
	 * Decides whether the student's current progress + auxiliary gates
	 * (quiz pass, etc.) satisfy this course's completion rule.
	 *
	 * `all_lessons` and `lessons_and_quiz` both require 100 progress; the
	 * latter ALSO needs a passing quiz — "passing" as the quiz's own
	 * `grading_method` defines it, see has_passed_quiz(). `percentage` honours the
	 * course's `completion_value` (default 100) so an 80%-threshold course
	 * fires completion at the 80th lesson, not the last. `manual` never
	 * auto-completes — the admin/instructor flips it explicitly.
	 *
	 * Pre-fix this helper ignored `completion_value` for the percentage
	 * type, leaving callers to gate on a hard `100 === $progress` check
	 * that the percentage path could never reach.
	 */
	private function course_completion_satisfied( int $user_id, int $course_id, int $progress ): bool {
		$course = Course::find( $course_id );
		if ( ! $course ) {
			return false;
		}

		$type = $course->completion_type ?? 'all_lessons';

		switch ( $type ) {
			case 'all_lessons':
				return 100 === $progress;

			case 'percentage':
				$threshold = (int) ( $course->completion_value ?? 100 );
				$threshold = max( 1, min( 100, $threshold ) );
				return $progress >= $threshold;

			case 'lessons_and_quiz':
				return 100 === $progress && $this->course_has_passing_quiz_attempt( $user_id, $course_id );

			case 'manual':
				// Admins / instructors mark completion explicitly — the
				// auto-completion path must NEVER fire on a manual course.
				return false;

			default:
				return false;
		}
	}

	/**
	 * True when the student has PASSED any quiz scoped to this course — either
	 * directly via `quiz.course_id` or attached to one of the course's lessons
	 * via `quiz.lesson_id`. "Passed" is the quiz's own grading method's verdict,
	 * see {@see has_passed_quiz()}.
	 *
	 * Practice / survey quizzes don't gate completion (per `has_passed_quiz`
	 * convention).
	 */
	private function course_has_passing_quiz_attempt( int $user_id, int $course_id ): bool {
		$memo_key = $user_id . ':' . $course_id;
		if ( isset( $this->quiz_gate_memo[ $memo_key ] ) ) {
			return $this->quiz_gate_memo[ $memo_key ];
		}

		// Course-scoped quizzes, plus those attached to this course's lessons
		// (some courses store the final exam at lesson scope, not course scope).
		// A quiz carrying both a course_id and a lesson_id appears in both lists;
		// passed_quizzes() keys by quiz id, so it is evaluated once.
		$quizzes = array_merge(
			Quiz::where( [ 'course_id' => $course_id ], 'id', 'ASC' ),
			Quiz::get_lesson_attached_for_course( $course_id )
		);

		// One batched verdict for the whole course. The old form asked per quiz
		// and short-circuited on the first pass, which read as cheaper but was a
		// query per quiz whenever the passing one was not first — and with the
		// grading-method predicate it would have been several.
		$passed = in_array( true, $this->passed_quizzes( $user_id, $quizzes ), true );

		$this->quiz_gate_memo[ $memo_key ] = $passed;
		return $passed;
	}

	/**
	 * Per-instance memo for {@see course_has_passing_quiz_attempt()}.
	 *
	 * `maybe_complete_course()` asks the same question twice for one student —
	 * once inside `calculate_course_progress()` (the quiz is a counted step on a
	 * `lessons_and_quiz` course) and once in `course_completion_satisfied()` —
	 * and the answer cannot change between them.
	 *
	 * Deliberately instance-scoped, not a static or a transient: every caller
	 * builds a fresh `new Progress_Service()`, so the memo lives for one
	 * evaluation and a quiz submitted later in the same request is never
	 * answered from a stale verdict.
	 *
	 * @var array<string, bool> "userId:courseId" => passed.
	 */
	private array $quiz_gate_memo = [];

	/** Enrolled users recomputed per background pass — bounded so a big course paginates. */
	private const PROGRESS_RECOMPUTE_BATCH = 200;

	/**
	 * Recompute the denormalised `enrollment.progress_pct` for a page of a
	 * course's enrolled students, and return the keyset cursor for the next page
	 * (0 when done).
	 *
	 * WHY THIS EXISTS. `progress_pct` (completed lessons / total) is only
	 * restamped when a student completes a lesson. When the lesson SET changes
	 * WITHOUT a completion — an instructor adds or removes a lesson, or flips a
	 * course to `lessons_and_quiz` — every stored value silently goes stale, so
	 * the ~20 surfaces that read the column (course cards, dashboard hero,
	 * widgets, admin, Pro cohorts/paths) drift out of sync with the lesson
	 * player, which computes live. Rather than convert 20 readers to live
	 * computation (and add a rule so no new reader forgets), we keep the column
	 * ACCURATE at the source — the same approach the codebase uses for
	 * `student_count` (Basecamp 10109156594).
	 *
	 * Uses `calculate_course_progress()` per user — the SAME method the player
	 * and completion path use — so the recomputed column can never disagree with
	 * the live bar. That is per-user (the `lessons_and_quiz` gate needs a
	 * per-user quiz check that raw SQL can't safely express), so it runs paged as
	 * an Action Scheduler job on the rare curriculum-edit event.
	 *
	 * @param int $course_id     Course whose enrolments to refresh.
	 * @param int $after_user_id Keyset cursor — process users with id greater than this.
	 * @param int $limit         Page size.
	 * @return int The last user_id processed (next cursor), or 0 when the page
	 *             was the last one.
	 */
	public function recompute_progress_for_course( int $course_id, int $after_user_id = 0, int $limit = self::PROGRESS_RECOMPUTE_BATCH ): int {
		$limit = max( 1, $limit );
		// enrolled_user_ids() is active+completed by definition — exactly the set
		// whose progress bar is shown. Keyset-paged by user_id via $after.
		$user_ids = Enrollment::enrolled_user_ids( array( $course_id ), $after_user_id > 0 ? $after_user_id : null, $limit );
		if ( empty( $user_ids ) ) {
			return 0;
		}

		foreach ( $user_ids as $user_id ) {
			// Same writer as the live path: all of the student's rows for this
			// course, not the one a per-caller "canonical row" rule picked. The
			// statement is a no-op when the value already matches, so the batch
			// still writes nothing when nothing drifted.
			Enrollment::set_progress_for_user_course( $user_id, $course_id, $this->calculate_course_progress( $user_id, $course_id ) );
		}

		// A short page means we've reached the end — no empty follow-up pass.
		return count( $user_ids ) < $limit ? 0 : (int) end( $user_ids );
	}
}
