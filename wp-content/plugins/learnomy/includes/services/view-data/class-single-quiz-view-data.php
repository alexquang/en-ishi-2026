<?php
/**
 * View-data resolver for the single-quiz template.
 *
 * Pre-resolves the quiz row + the data needed to evaluate the pre-start
 * gates (cooldown, lockout, access code) in templates/single-quiz.php.
 *
 * The template still owns the gate decision tree because the branching
 * is presentation logic — but the model lookups it depended on now happen
 * here, so the template stays presentation-only.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Lesson;
use Learnomy\Models\Quiz;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\QuizSlot;

class Single_Quiz_View_Data {

	/**
	 * Resolve quiz + gate-evaluation data.
	 *
	 * @param array $data Route data — must include 'slug' (course quiz)
	 *                    or 'quiz_slug' (standalone quiz).
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		// Two route shapes feed this resolver:
		// courses/<course-slug>/quiz/<quiz-slug>/  -> $data has both slug + quiz_slug
		// quiz/<slug>/                              -> $data has slug only (standalone)
		//
		// The F-02.4 lesson-router lesson surfaced the same slug-only
		// resolution bug for quizzes (F-03.X). Scope course-attached
		// quiz lookups to the course in the URL; standalone quizzes
		// keep slug-only because Quiz::create() enforces standalone
		// uniqueness at insert time.
		$course_slug = (string) ( $data['slug'] ?? '' );
		$quiz_slug   = (string) ( $data['quiz_slug'] ?? '' );

		if ( '' !== $quiz_slug ) {
			// Course-attached route — resolve course first, then scope quiz.
			$course        = $course_slug ? Course::find_by_slug( $course_slug ) : null;
			$quiz          = $course ? Quiz::find_by_course_and_slug( (int) $course->id, $quiz_slug ) : null;
			$resolved_slug = $quiz_slug;
		} else {
			// Standalone-quiz route — slug is the quiz slug.
			$quiz          = $course_slug ? Quiz::find_standalone_by_slug( $course_slug ) : null;
			$resolved_slug = $course_slug;
			$course        = null;

			// A quiz that exists but belongs to a course is not "not found" —
			// it lives at /<base>/<course>/quiz/<quiz>/. Answering 404 for a
			// published quiz somebody can see in their dashboard is the site
			// contradicting itself, and it is what made the Quiz Scores widget's
			// Review link a dead end (Basecamp 10190381081, 10186056951).
			//
			// Redirect rather than render here: the course-scoped URL is the
			// canonical one, so serving the same quiz at two paths would split
			// it for search engines and for anything that compares URLs. 301,
			// because the mapping is permanent for as long as the quiz is
			// attached. Quiz::url() owns the destination, the same helper the
			// widget and Pro's path steps use.
			if ( ! $quiz && $course_slug ) {
				$attached = Quiz::find_by_slug( $course_slug );
				if ( $attached && (int) ( $attached->course_id ?? 0 ) > 0 ) {
					$target = Quiz::url( $attached );
					if ( $target ) {
						wp_safe_redirect( $target, 301 );
						exit;
					}
				}
			}
		}

		$out = array(
			'quiz'          => $quiz,
			'resolved_slug' => $resolved_slug,
		);

		if ( ! $quiz ) {
			// Unknown quiz slug: signal a real 404 (honoured by the template
			// loader before get_header), consistent with course/lesson/category.
			$out['not_found'] = true;
			return $out;
		}

		$user_id       = get_current_user_id();
		$is_privileged = $user_id && (
			user_can( $user_id, 'manage_options' )
			|| user_can( $user_id, 'learnomy_manage_own_courses' )
		);

		// A non-published quiz is a 404 for the public, exactly like a draft
		// course or lesson. This verdict lives HERE and not in the template
		// because the template body runs after the page header has flushed a
		// 200 and printed the draft quiz's title (Basecamp 10132809039) — the
		// gate rendered a 404-looking body under a 200 status while leaking the
		// title. Privileged viewers (admins / instructors) keep the preview.
		if ( 'published' !== (string) ( $quiz->status ?? 'draft' ) && ! $is_privileged ) {
			$out['not_found'] = true;
			return $out;
		}

		// Style-template ergonomics — pre-resolve everything every quiz/<style>.php
		// template needs so the style files have zero model logic. Without this,
		// each style template re-fetches the quiz, the parent course, the slot
		// count, etc. — duplicating ~25 lines per style.
		$slug          = $course_slug;
		$out['course'] = $course; // resolved above; null for standalone

		/*
		 * Where "back" goes, and what it is called.
		 *
		 * A quiz bound to a lesson is reached FROM that lesson — the player
		 * shows it as a LESSON QUIZ under the row — but every exit sent the
		 * student to course home instead, skipping the lesson they came from
		 * and making them find it again to carry on reading (Basecamp
		 * 10184407556). The binding is the author's own placement decision, so
		 * the return path should honour it.
		 *
		 * back_label ships beside back_url because the destination is no longer
		 * always the course: quiz-result-actions.php hardcoded "Back to
		 * {course label}", which would have been a lie the moment the URL
		 * changed underneath it.
		 *
		 * Falls back to the course (then the catalog) whenever the lesson does
		 * not resolve — a drafted or deleted lesson must not strand the student
		 * on a dead link.
		 */
		$out['back_url'] = $out['course']
			? \Learnomy\route_url( 'course', array( 'slug' => (string) $out['course']->slug ) )
			: \Learnomy\route_url( 'catalog' );
		// course_label( true ), not a hardcoded "Courses": a standalone quiz falls
		// back to the catalog, and a site that renamed Course to Program must
		// read "Back to Programs" there. The Labels ratchet caught this.
		$out['back_label'] = $out['course']
			? (string) $out['course']->title
			: \Learnomy\course_label( true );

		if ( ! empty( $quiz->lesson_id ) && $out['course'] ) {
			$lrn_bound_lesson = Lesson::find( (int) $quiz->lesson_id );
			if ( $lrn_bound_lesson && 'published' === (string) ( $lrn_bound_lesson->status ?? '' ) ) {
				// The arg is `lesson_slug`, not `lesson` — see the route registry
				// docblock in functions.php. Passing the wrong key builds
				// /courses/<course>/lesson/ with an empty trailing segment and no
				// error, which is why this was checked against the rendered URL
				// rather than the returned string.
				$out['back_url']   = \Learnomy\route_url(
					'lesson',
					array(
						'slug'        => (string) $out['course']->slug,
						'lesson_slug' => (string) $lrn_bound_lesson->slug,
					)
				);
				$out['back_label'] = (string) $lrn_bound_lesson->title;
			}
		}
		$out['time_limit_secs'] = (int) ( $quiz->time_limit_seconds ?? 0 );
		$out['time_limit_mins'] = $out['time_limit_secs'] > 0
			? (int) ceil( $out['time_limit_secs'] / 60 )
			: 0;
		$out['passing_pct']     = (int) ( $quiz->passing_score ?? 70 );
		// Effective cap: 0 (unlimited) for a practice quiz, so the start screen +
		// results screen offer unlimited retakes and never show "all attempts
		// used" on a quiz meant to be repeated. One shared source with the start
		// gate (Quiz::effective_attempt_limit_for_user) so they can't disagree —
		// including the per-user grants that gate honours, or a student handed an
		// extra attempt would still be told they had none left.
		$out['max_attempts']   = Quiz::effective_attempt_limit_for_user( $quiz, get_current_user_id() );
		$out['question_count'] = QuizSlot::count( array( 'quiz_id' => (int) $quiz->id ) );

		// Proctoring hint on the start screen so the student knows the rules
		// before they begin. Empty string when tab tracking is off.
		$out['proctoring_note'] = '';
		if ( \Learnomy\Services\Quiz_Defaults::is_tab_tracking_enabled() ) {
			$tab_limit = \Learnomy\Services\Quiz_Defaults::tab_switch_auto_submit_limit( $quiz );
			if ( $tab_limit > 0 ) {
				$out['proctoring_note'] = sprintf(
					/* translators: %d: number of tab switches. */
					_n(
						'Monitored: stay on this tab. Switching away %d time will submit your quiz automatically.',
						'Monitored: stay on this tab. Switching away %d times will submit your quiz automatically.',
						$tab_limit,
						'learnomy'
					),
					$tab_limit
				);
			} else {
				$out['proctoring_note'] = __( 'Monitored: switching browser tabs during this quiz is recorded.', 'learnomy' );
			}
		}

		// Next curriculum item — a course/section quiz advances through the
		// curriculum like a lesson, instead of dead-ending at course home. A
		// section quiz is the unit's end-of-section check, so "next" is the
		// first lesson of the FOLLOWING section; a lesson-attached quiz advances
		// to the lesson after it. Course-final + standalone quizzes have no next
		// (Back to Course / catalog stays the only action). The results template
		// shows "Continue" only when this is set AND the learner passed.
		$out['next_url']   = '';
		$out['next_label'] = __( 'Continue', 'learnomy' );
		if ( $out['course'] ) {
			$nav_course_id = (int) $out['course']->id;
			$quiz_section  = (int) ( $quiz->section_id ?? 0 );
			$next_lesson   = null;

			if ( $quiz_section > 0 ) {
				$after = false;
				foreach ( \Learnomy\Models\Section::get_for_course( $nav_course_id ) as $section ) {
					if ( $after ) {
						$section_lessons = Lesson::get_for_section( (int) $section->id );
						if ( ! empty( $section_lessons ) ) {
							$next_lesson = $section_lessons[0];
							break;
						}
						continue; // Empty section — keep scanning forward.
					}
					if ( (int) $section->id === $quiz_section ) {
						$after = true;
					}
				}
			} elseif ( ! empty( $quiz->lesson_id ) ) {
				$next_lesson = Lesson::find_next_after( (int) $quiz->lesson_id, $nav_course_id );
			}

			if ( $next_lesson ) {
				$out['next_url'] = \Learnomy\route_url(
					'lesson',
					array(
						'slug'        => (string) $out['course']->slug,
						'lesson_slug' => (string) $next_lesson->slug,
					)
				);
			}
		}

		// $user_id / $is_privileged were resolved above (the unpublished gate
		// needs them before anything else runs).
		$out['user_id']       = $user_id;
		$out['is_privileged'] = $is_privileged;

		// Enrollment gate (Basecamp #9927957987). The IDOR fix in PR #246
		// rejects `quiz/start` for non-enrolled callers on course-attached
		// quizzes — that's the right server contract. But the UI was
		// still showing the Start Quiz button, so a non-enrolled student
		// clicked it and got a 400 + console error. We now expose
		// `enroll_required` + `enroll_url` so the template renders an
		// "Enroll in <course> to take this quiz" CTA instead.
		//
		// Standalone quizzes (course_id=0 AND no lesson) stay open.
		// Lesson-attached quizzes resolve their gating course via the
		// lesson's `course_id` denormalized column.
		$gate_course_id = (int) ( $quiz->course_id ?? 0 );
		if ( 0 === $gate_course_id && ! empty( $quiz->lesson_id ) ) {
			$lesson = Lesson::find( (int) $quiz->lesson_id );
			if ( $lesson ) {
				$gate_course_id = (int) $lesson->course_id;
			}
		}

		$enroll_required = false;
		$enroll_url      = '';
		if ( $gate_course_id > 0 && ! $is_privileged ) {
			$enrolled = $user_id && Enrollment::is_enrolled( $user_id, $gate_course_id );
			if ( ! $enrolled ) {
				$enroll_required = true;
				$gating_course   = Course::find( $gate_course_id );
				if ( $gating_course ) {
					$enroll_url = \Learnomy\route_url( 'course', array( 'slug' => (string) $gating_course->slug ) );
				}
			}
		}
		$out['enroll_required'] = $enroll_required;
		$out['enroll_url']      = $enroll_url;

		// Privileged users bypass the gates entirely — no need to compute
		// gate state for them. The template handles the bypass too; this
		// just saves the round-trip to count_failed / find_latest.
		if ( $is_privileged || ! $user_id ) {
			$out['failed_count']       = 0;
			$out['latest_attempt']     = null;
			$out['code_verified_meta'] = false;
			// Preview/anon callers have no attempt cap — expose unlimited
			// remaining so the results screen offers "Try Again" instead of
			// falsely showing "You have used all attempts" (these keys were
			// previously unset on this early-return path, so the template read
			// them as 0 / exhausted for an admin or instructor previewing).
			$out['attempts_used']      = 0;
			$out['attempts_remaining'] = PHP_INT_MAX;
			return $out;
		}

		// Gate 5 inputs — graded failing attempts.
		$out['failed_count'] = QuizAttempt::count_failed( $user_id, (int) $quiz->id );

		// Try Again gate (Basecamp #9927957987 bounce 2026-05-27). The
		// results screen always rendered "Try Again", but clicking it on
		// a maxed-out quiz fired POST /quizzes/{id}/start which the server
		// 400's with "max attempts reached". Surface attempts_used +
		// attempts_remaining so the template can hide the button instead
		// of letting the click fail.
		$out['attempts_used']      = QuizAttempt::count_graded( $user_id, (int) $quiz->id );
		$out['attempts_remaining'] = $out['max_attempts'] > 0
			? max( 0, $out['max_attempts'] - $out['attempts_used'] )
			: PHP_INT_MAX; // 0 cap = unlimited; sentinel keeps template comparisons simple.

		// Gate 6 inputs — last submission for cooldown calculation.
		$out['latest_attempt'] = QuizAttempt::find_latest( $user_id, (int) $quiz->id );

		// Gate 7 inputs — has the access code been verified for this user.
		if ( ! empty( $quiz->access_code ) ) {
			$verified_key              = 'learnomy_quiz_' . (int) $quiz->id . '_code_verified';
			$out['code_verified_meta'] = (bool) (int) get_user_meta( $user_id, $verified_key, true );
		} else {
			$out['code_verified_meta'] = true; // No code → treated as verified.
		}

		return $out;
	}
}
