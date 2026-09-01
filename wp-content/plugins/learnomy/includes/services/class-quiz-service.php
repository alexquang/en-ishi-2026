<?php
/**
 * Quiz Service — orchestrates attempt lifecycle and grading.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\DB\Relations;

use Learnomy\Models\Quiz;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\QuizSlot;
use Learnomy\Models\Question;
use Learnomy\Models\Answer;
use Learnomy\Models\QuestionCategory;
use Learnomy\Models\QuestionResponse;
use Learnomy\Models\Enrollment;
use Learnomy\QuestionTypes;
use function Learnomy\now;

class Quiz_Service {

	/**
	 * Begin a new quiz attempt (or resume an existing in-progress sitting).
	 *
	 * Enforcement order is deliberate — access code first so a locked-out
	 * student gets the access-code error if they also guessed the code wrong,
	 * then time window, then cooldown + lockout + attempt-limit. The last
	 * three rules each map to distinct HTTP responses (429 / 423 / 422) so
	 * the REST layer can discriminate without inspecting message strings.
	 *
	 * @throws \InvalidArgumentException        When the quiz does not exist.
	 * @throws \RuntimeException                When the quiz is closed, not yet open, not accepting answers, or the student is not enrolled.
	 * @throws \Learnomy\InvalidQuizAccessCode  When the supplied access code is wrong.
	 * @throws \Learnomy\QuizCooldownActive     When the per-attempt cooldown has not elapsed (REST maps this to 429).
	 * @throws \Learnomy\QuizLockedOut          When repeated failures locked the quiz (REST maps this to 423).
	 * @throws \Learnomy\QuizAttemptLimitReached When the student has used every attempt they are allowed (REST maps this to 422).
	 */
	public function start_attempt( int $user_id, int $quiz_id, ?int $enrollment_id = null, ?string $access_code = null ): int {
		$quiz = Quiz::find( $quiz_id );
		if ( ! $quiz ) {
			throw new \InvalidArgumentException( esc_html__( 'Quiz not found.', 'learnomy' ) );
		}

		// No questions, no attempt — for anyone, including privileged preview.
		// An empty quiz used to sail through every gate below: the attempt row
		// was created, the player rendered a blank pane with a live Submit, and
		// grading scored the empty sitting 0% "You did not reach the passing
		// score" (1.8.0 combo smoke, quiz-zero-questions-submittable). The
		// template mirrors this as its 'empty' gate state.
		if ( 0 === QuizSlot::count( [ 'quiz_id' => $quiz_id ] ) ) {
			throw new \RuntimeException( esc_html__( 'This quiz has no questions yet.', 'learnomy' ) );
		}

		// Course-attached quiz: require active enrollment so paid-course
		// quiz content can't be accessed by any logged-in user. Standalone
		// quizzes (course_id=0) intentionally stay open — instructors use
		// them for top-of-funnel pre-tests. A `lesson_id`-attached quiz is
		// also course-scoped (the lesson belongs to a course), so resolve
		// the course via the lesson when course_id is empty.
		$gate_course_id = (int) ( $quiz->course_id ?? 0 );
		if ( 0 === $gate_course_id && ! empty( $quiz->lesson_id ) ) {
			$lesson = \Learnomy\Models\Lesson::find( (int) $quiz->lesson_id );
			if ( $lesson ) {
				$gate_course_id = (int) $lesson->course_id;
			}
		}
		if ( $gate_course_id > 0 && ! Enrollment::is_enrolled( $user_id, $gate_course_id ) ) {
			// Preview parity with the lesson player: a site admin or the course's
			// own instructor can open the quiz without enrolling, so they can
			// test what students will see (see Single_Lesson_View_Data, where
			// $can_access = enrolled || admin || instructor). Without this, an
			// instructor could author a quiz but never preview it.
			$gate_course   = \Learnomy\Models\Course::find( $gate_course_id );
			$is_admin      = user_can( $user_id, 'manage_options' );
			$is_instructor = $gate_course && (int) ( $gate_course->author_id ?? 0 ) === $user_id;
			if ( ! $is_admin && ! $is_instructor ) {
				throw new \RuntimeException( esc_html__( 'You must be enrolled in this course to attempt this quiz.', 'learnomy' ) );
			}
		}

		// A quiz bound to a lesson is only as open as that lesson. Without this
		// the drip schedule protected the lesson and handed its content over
		// anyway: the quiz sat in the outline as a live link, started cleanly,
		// and served questions drawn from material the student could not yet
		// read — 146 days early on the reproduction (Basecamp 10181609428).
		// A recorded, gradeable attempt for an untaught lesson followed it into
		// the gradebook. Only lesson-BOUND quizzes inherit; an end-of-section or
		// course-level quiz has no lesson to take a schedule from.
		if ( self::lesson_lock_applies( $quiz, $user_id ) ) {
			throw new \RuntimeException( esc_html__( 'This quiz unlocks with its lesson, which is not available yet.', 'learnomy' ) );
		}

		// Access code — hash_equals avoids timing-attack side channels.
		if ( ! empty( $quiz->access_code ) && ! hash_equals( (string) $quiz->access_code, (string) $access_code ) ) {
			throw new \Learnomy\InvalidQuizAccessCode( esc_html__( 'Incorrect access code.', 'learnomy' ) );
		}

		// Check quiz window.
		$now = time();
		if ( ! empty( $quiz->open_at ) && strtotime( $quiz->open_at ) > $now ) {
			throw new \RuntimeException( esc_html__( 'This quiz is not open yet.', 'learnomy' ) );
		}
		if ( ! empty( $quiz->close_at ) && strtotime( $quiz->close_at ) < $now ) {
			throw new \RuntimeException( esc_html__( 'This quiz has closed.', 'learnomy' ) );
		}

		// Cooldown — elapsed time since the most recent submission.
		$cooldown = (int) ( $quiz->attempt_cooldown_seconds ?? 0 );
		if ( $cooldown > 0 ) {
			$last = QuizAttempt::find_latest( $user_id, $quiz_id );
			if ( $last && ! empty( $last->submitted_at ) ) {
				$elapsed = $now - strtotime( $last->submitted_at );
				if ( $elapsed < $cooldown ) {
					// Argument is an integer seconds-remaining value — not a string to be echoed.
					throw new \Learnomy\QuizCooldownActive( $cooldown - $elapsed ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
			}
		}

		// Lockout — max_failed_attempts is a ceiling on graded failures only, and
		// only for quizzes that enforce a cap at all. A PRACTICE quiz is for
		// unlimited repetition, so a failing streak must never lock the student
		// out of practising.
		$max_failed = 'practice' === (string) ( $quiz->mode ?? 'graded' ) ? 0 : (int) ( $quiz->max_failed_attempts ?? 0 );
		if ( $max_failed > 0 ) {
			$failed = QuizAttempt::count_failed( $user_id, $quiz_id );
			if ( $failed >= $max_failed ) {
				// Argument is an integer failed-count — not a string to be echoed.
				throw new \Learnomy\QuizLockedOut( $failed ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}

		// Enforce attempt limit. Two rules make the gate agree with what the
		// student is shown:
		// - Quiz::effective_attempt_limit() returns 0 (unlimited) for a PRACTICE
		// quiz, so its default attempt_limit of 1 never blocks a retake — a
		// practice quiz is meant to be repeated. Only graded/survey enforce it.
		// - Count COMPLETED attempts (submitted + graded), the exact basis
		// QuizAttempt::count_graded() gives the start screen via
		// Single_Quiz_View_Data, so the gate and the "attempts remaining" the
		// student sees can never disagree. Counting ALL rows (the old
		// behaviour) double-counted the in-progress sitting this very call is
		// about to resume at get_active() below — making that resume
		// unreachable at the limit — and 400'd a student whose only "extra"
		// attempt was a submitted-but-not-yet-graded one the screen still
		// showed as available (Basecamp 10096673115).
		// - The cap is resolved PER STUDENT, so an instructor's grant of one more
		// attempt (and any Pro rule on learnomy_quiz_max_attempts) lifts the gate
		// for that student alone.
		$attempt_limit = Quiz::effective_attempt_limit_for_user( $quiz, $user_id );
		if ( $attempt_limit > 0 ) {
			$past = QuizAttempt::count_graded( $user_id, $quiz_id );
			if ( $past >= $attempt_limit ) {
				// Argument is an integer attempt cap — not a string to be echoed.
				throw new \Learnomy\QuizAttemptLimitReached( $attempt_limit ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}

		// Resume an existing open sitting instead of blocking.
		$active = QuizAttempt::get_active( $user_id, $quiz_id );
		if ( $active ) {
			return (int) $active->id;
		}

		$attempt_id = QuizAttempt::start( $user_id, $quiz_id );

		// Attach enrollment if provided — record it now so grading has the context.
		if ( $enrollment_id ) {
			QuizAttempt::update( $attempt_id, [ 'enrollment_id' => $enrollment_id ] );
		}

		do_action( 'learnomy_quiz_started', $attempt_id, $user_id, $quiz_id );

		return $attempt_id;
	}

	public function answer_question( int $attempt_id, int $question_id, mixed $response ): void {
		$attempt = QuizAttempt::find( $attempt_id );
		if ( ! $attempt || 'in_progress' !== $attempt->status ) {
			throw new \RuntimeException( esc_html__( 'This attempt is not accepting answers.', 'learnomy' ) );
		}

		// Stamp the question version the student actually answered, so a later
		// edit (which bumps the version) doesn't misattribute this response to
		// the new wording. Defaults to 1 when the question can't be loaded.
		$question = Question::find( $question_id );

		QuestionResponse::record(
			$attempt_id,
			$question_id,
			[
				'response_json'    => wp_json_encode( $response ),
				'question_version' => $question ? (int) $question->version : 1,
				// What this question is worth, recorded WITH the answer. The row
				// used to learn its denominator only from whoever graded it, and
				// only the types Free can grade itself ever got one — so an essay
				// sat at max_score = 0.00 from the moment it was written, and
				// every consumer downstream (the student's review, per-question
				// statistics, Pro's rubric grader, which multiplies a correct
				// fraction by this number) had nothing to work from and produced
				// a zero (Basecamp 10132809181). The paper decides the marks, not
				// the grader, so they are stamped here for every question type.
				'max_score'        => $this->marks_for_question( $attempt, $question_id ),
			]
		);

		// Per-question correctness is known immediately only for Free's objective
		// types (they carry an answer key). Essay/manual and Pro filter-graded
		// types (matching/ordering/numeric/math/calculated) are scored later at
		// submit, so report null here and let learnomy_quiz_graded carry their
		// outcome. Scored on a normalised 1.0 scale — scale-invariant for
		// full-credit detection, so the slot's mark weight isn't needed. This is a
		// pure read: no grade is persisted at answer time.
		$is_correct = null;
		if ( $question && $this->is_free_auto_gradeable( (string) $question->type ) ) {
			$is_correct = $this->score_response( (string) $question->type, $response, $question_id, 1.0 ) >= 1.0 - 0.0001;
		}

		/**
		 * Fires once per answered question, immediately after the response row is
		 * persisted and before the attempt is submitted/graded. The xAPI
		 * extension listens here to emit a granular ADL `answered` statement.
		 *
		 * @since 1.3.1
		 *
		 * @param int       $question_id The answered question id.
		 * @param mixed     $response    The raw learner response (decoded) as recorded.
		 * @param bool|null $is_correct  Full-credit correctness for objective types;
		 *                               null when correctness is deferred to grading
		 *                               (essay/manual, Pro filter-graded types).
		 * @param int       $attempt_id  The quiz attempt id.
		 */
		do_action( 'learnomy_quiz_question_answered', $question_id, $response, $is_correct, $attempt_id );
	}

	public function submit_attempt( int $attempt_id ): object {
		$attempt = QuizAttempt::find( $attempt_id );
		if ( ! $attempt ) {
			throw new \InvalidArgumentException( esc_html__( 'Attempt not found.', 'learnomy' ) );
		}
		if ( 'in_progress' !== $attempt->status ) {
			throw new \RuntimeException( esc_html__( 'Attempt is not in progress.', 'learnomy' ) );
		}

		$quiz      = Quiz::find( (int) $attempt->quiz_id );
		$is_survey = $quiz && 'survey' === (string) ( $quiz->mode ?? 'graded' );

		if ( $is_survey ) {
			// Survey mode collects responses without grading — no score, no
			// pass/fail. The attempt is complete the moment it's submitted.
			$score      = 0.0;
			$max_score  = 0.0;
			$has_essay  = false;
			$percentage = 0.00;
		} else {
			$grades     = $this->auto_grade( $attempt_id );
			$score      = $grades['score'];
			$max_score  = $grades['max_score'];
			$has_essay  = $grades['has_ungraded_essay'];
			$percentage = $max_score > 0 ? round( ( $score / $max_score ) * 100, 2 ) : 0.00;
		}

		$pass_score = (float) ( $quiz->passing_score ?? 70 );

		/**
		 * The pass mark a quiz is graded against, in percent.
		 *
		 * @since 1.0.0
		 *
		 * @param float $pass_score The quiz's `passing_score` (default 70).
		 * @param int   $attempt_id The attempt being graded, or 0 when the mark
		 *                          is resolved for the quiz rather than for one
		 *                          sitting (see student_passed_by_grading_method()).
		 * @param int   $quiz_id    The quiz.
		 */
		$pass_score = (float) apply_filters( 'learnomy_quiz_pass_score', $pass_score, $attempt_id, (int) $attempt->quiz_id );

		// If any essay questions exist, hold at 'submitted' until manual grading completes.
		$status = $has_essay ? 'submitted' : 'graded';

		// `passed` is a property of THIS SITTING: did this attempt's own
		// percentage clear the bar. It is deliberately NOT grading-method aware —
		// the grading method answers a different question ("which attempt counts
		// toward the student's record"), and letting it write this column made
		// every attempt of a student whose best had cleared the bar read
		// passed=1, including a 0% one, which is what the quiz statistics then
		// aggregated (Basecamp 10132808925). The student-level, method-aware
		// answer lives in student_passed_by_grading_method().
		if ( $is_survey ) {
			// A survey is always "satisfied" once submitted — it must never gate
			// course completion on a pass mark.
			$passed = true;
		} else {
			$passed = $percentage >= $pass_score;
		}

		$scores = [
			'status'     => $status,
			'score'      => $score,
			'max_score'  => $max_score,
			'percentage' => $percentage,
			'passed'     => $passed ? 1 : 0,
		];

		// Record elapsed time from the server clock (started_at -> now) rather
		// than trusting the client to accumulate and submit it — the player
		// never sent it, so attempts stored time_spent_seconds = 0.
		if ( ! empty( $attempt->started_at ) ) {
			$elapsed                      = time() - (int) strtotime( (string) $attempt->started_at );
			$scores['time_spent_seconds'] = max( 0, $elapsed );
		}

		if ( 'graded' === $status ) {
			$scores['graded_at'] = now();
		}

		QuizAttempt::submit( $attempt_id, $scores );

		// Reload so the returned object reflects the final state.
		$attempt = QuizAttempt::find( $attempt_id );

		do_action( 'learnomy_quiz_submitted', $attempt_id, (int) $attempt->user_id, (int) $attempt->quiz_id );

		// J-03.3 — re-evaluate course completion on quiz pass. lessons_and_quiz
		// courses otherwise leave enrollment.completed_at NULL even after the
		// student finishes every lesson AND passes the final quiz. Only fires
		// for fully-graded attempts here; essay / manual-review attempts hold at
		// 'submitted' and complete later via maybe_finalize_attempt(), which
		// calls the same helper.
		if ( 'graded' === $status ) {
			$this->refresh_course_after_attempt( $attempt, (bool) $passed );
		}

		return $attempt;
	}

	public function grade_question( int $response_id, float $score, string $feedback, int $grader_id ): void {
		$response = QuestionResponse::find( $response_id );
		if ( ! $response ) {
			throw new \InvalidArgumentException( esc_html__( 'Response not found.', 'learnomy' ) );
		}

		// Clamp the manual score to the question's max marks. The REST route only
		// enforced a lower bound (minimum 0); without an upper bound a grader (or
		// a crafted request) could award more than the question is worth and push
		// the attempt percentage past 100.
		//
		// The row carries its own max_score from the moment it is written
		// ({@see answer_question()}); marks_for_question() re-derives it from the
		// paper for any row recorded before that was true. It replaced a slot-only
		// lookup open-coded here, which was a second, weaker source than
		// marks_for_attempt() — it could not see a Pro random pool's frozen marks
		// at all, and it re-weighted a finished attempt whenever the quiz was
		// edited afterwards.
		$max = (float) ( $response->max_score ?? 0 );
		if ( $max <= 0 ) {
			$max = $this->marks_for_question( (int) $response->attempt_id, (int) $response->question_id );
		}
		// Allow a negative question score through: negative marking is only
		// meaningful if the penalty reaches the attempt total. The ATTEMPT sum
		// is floored at zero where it is computed, so a learner can never
		// finish owing marks -- but within the paper, a penalised question has
		// to be able to cancel a correct one. (Basecamp 10153962110)
		$score = max( -$max, min( $score, $max ) );

		QuestionResponse::update(
			$response_id,
			[
				'score'           => $score,
				// Persist the mark the score was clamped against. Nothing wrote
				// max_score on a manually graded row before — auto_grade() only
				// stamps the types it can score itself — so a graded essay showed
				// the student "2/0" on their review, and every essay dropped out of
				// the per-question statistics, which filter on max_score > 0.
				'max_score'       => $max,
				'grader_id'       => $grader_id,
				'grader_feedback' => $feedback,
				'graded_at'       => now(),
			]
		);

		// Auto-finalize the attempt if every response now has a graded_at timestamp.
		$this->maybe_finalize_attempt( (int) $response->attempt_id );
	}

	/**
	 * Delete a quiz and everything that hangs off it — responses, attempts, and
	 * slots — so removing a quiz never orphans child rows. The REST delete used
	 * to drop only the quiz row. Same cascade order the fixture builder and the
	 * privacy eraser use: responses -> attempts -> slots -> quiz.
	 *
	 * Fires `learnomy_quiz_deleted` on success. Quizzes were the one content
	 * entity with no deletion event: courses and lessons both had one, so an
	 * extension keying rows to a quiz id (a drip rule with item_type='quiz', an
	 * advanced-quiz config row) had nothing to listen to and its rows outlived
	 * the quiz. Free must not clean those up itself — it may not name a Pro
	 * table (Rule 9) — so the seam is the fix, and it matters more now that a
	 * section delete cascades into quizzes as well.
	 *
	 * @param int $quiz_id Quiz to delete.
	 * @return bool Whether the quiz row itself was removed.
	 */
	public function delete_quiz( int $quiz_id ): bool {
		// Read the parent ids BEFORE the row goes, so the event payload can
		// still say which course the quiz belonged to.
		$quiz      = Quiz::find( $quiz_id );
		$course_id = $quiz ? (int) ( $quiz->course_id ?? 0 ) : 0;

		/**
		 * Fires BEFORE a quiz's cascade runs, while its slots, attempts and
		 * responses still exist.
		 *
		 * `learnomy_quiz_deleted` is the event for anything keyed to the QUIZ
		 * id. This one exists for the rows keyed to a quiz's CHILDREN — a slot
		 * id, an attempt id — which no listener can enumerate once the cascade
		 * below has run. Pro's per-slot quiz timers are stored against the slot
		 * id, so without this seam they could only ever be orphaned.
		 *
		 * Same ordering contract as `learnomy_erase_authored_course`: fired
		 * first so a listener can still read the curriculum it needs.
		 *
		 * @param int $quiz_id   Quiz about to be deleted.
		 * @param int $course_id Course the quiz belongs to (0 if standalone).
		 */
		do_action( 'learnomy_quiz_deleting', $quiz_id, $course_id );

		$attempt_ids = QuizAttempt::get_ids_for_quiz( $quiz_id );

		if ( ! empty( $attempt_ids ) ) {
			QuestionResponse::delete_for_attempts( $attempt_ids );
		}

		QuizAttempt::delete_for_quiz( $quiz_id );
		QuizSlot::delete_for_quiz( $quiz_id );

		// Quiz tag links. The quiz editor writes them via the same
		// sync_object_tags() chokepoint courses and lessons use, and both of
		// those clear theirs on delete — the quiz branch never did, so every
		// deleted quiz left `lrn_tag_map` rows behind, still counting toward
		// each tag's denormalised usage_count. An empty set clears the rows AND
		// recomputes those counts.
		\Learnomy\Models\Tag::sync_object_tags( $quiz_id, 'quiz', array() );

		$deleted = Quiz::delete( $quiz_id );

		if ( $deleted ) {
			/**
			 * Fires after a quiz and its attempts, responses and slots are gone.
			 *
			 * Fires on every quiz-removal path — the REST delete, the lesson
			 * cascade, the section cascade and the course teardown all route
			 * through this method.
			 *
			 * @param int $quiz_id   Quiz row id that was deleted.
			 * @param int $course_id Course the quiz belonged to (0 if standalone).
			 */
			do_action( 'learnomy_quiz_deleted', $quiz_id, $course_id );
		}

		return $deleted;
	}

	/**
	 * The paper an attempt sat: question_id => marks, in the order it was served.
	 *
	 * Frozen into the attempt's runner state at start, because neither the
	 * response rows nor the quiz's current slots can answer the question on
	 * their own. Responses only cover what the student got round to answering.
	 * Slots are the quiz as it stands NOW — they miss a Pro random pool
	 * entirely (drawn questions have no slot row) and they re-weight a finished
	 * attempt if an instructor edits the quiz afterwards.
	 *
	 * Attempts started before the freeze existed have no record of their paper,
	 * so $whole_paper decides how to stand in for one — see the parameter.
	 *
	 * (Card 10119135805 plans to promote this from the state blob to a
	 * `frozen_slot_order` column; the contract is the same either way.)
	 *
	 * Public because it is the ONE answer to "what is this sitting worth", and
	 * Pro's advanced-quizzes grader needs the same answer Free grades against.
	 * Pro used to derive its own map from `lrn_quiz_slots` alone, which is the
	 * weaker source this method exists to replace — it cannot see frozen marks,
	 * so a random-pool question fell back to 1.0 while Free counted its real
	 * weight in the denominator, and editing a quiz silently re-weighted the Pro
	 * question types in a finished attempt. Extend Free's authority; never
	 * re-derive a second one.
	 *
	 * @param object $attempt     Attempt row.
	 * @param bool   $whole_paper Only consulted for a pre-freeze attempt. True
	 *                            reconstructs from the quiz's slots as it stands
	 *                            today, because grading has to produce SOME total.
	 *                            False falls back to what the student answered:
	 *                            a review must not list questions we cannot show
	 *                            they were served, or every past result would
	 *                            sprout "wrong" answers for questions added to
	 *                            the quiz after they sat it.
	 * @return array<int,float> Marks keyed by question id.
	 */
	public function marks_for_attempt( object $attempt, bool $whole_paper ): array {
		$state = QuizAttempt::get_runner_state( (int) $attempt->id );

		if ( ! empty( $state['frozen_marks'] ) && is_array( $state['frozen_marks'] ) ) {
			$marks = array();
			foreach ( $state['frozen_marks'] as $question_id => $value ) {
				$marks[ (int) $question_id ] = (float) $value;
			}
			return $marks;
		}

		$slot_marks = array();
		foreach ( QuizSlot::get_for_quiz( (int) $attempt->quiz_id ) as $slot ) {
			$slot_marks[ (int) $slot->question_id ] = (float) $slot->marks;
		}

		if ( $whole_paper ) {
			return $slot_marks;
		}

		$marks = array();
		foreach ( QuestionResponse::get_for_attempt( (int) $attempt->id ) as $response ) {
			$question_id           = (int) $response->question_id;
			$marks[ $question_id ] = $slot_marks[ $question_id ] ?? 1.0;
		}
		return $marks;
	}

	/**
	 * What ONE question of a sitting is worth — the single-question read of
	 * {@see marks_for_attempt()}, for the paths that hold a response rather than
	 * a whole attempt: recording an answer, clamping a manual grade, and Pro's
	 * rubric grader.
	 *
	 * Never returns 0. A question the paper cannot price is worth 1 mark, which
	 * is the same default the freeze itself applies when a slot carries no marks
	 * and the same default marks_for_attempt() applies to a response with no
	 * slot. Returning 0 would be the defect this method was written to end:
	 * every downstream consumer multiplies by this number, so a zero denominator
	 * silently converts a correct answer into no marks at all
	 * (Basecamp 10132809181). "Unknown" and "worthless" are not the same answer.
	 *
	 * Note this is deliberately the ONLY place that fallback is chosen for a
	 * single question — callers must not re-implement it.
	 *
	 * @param object|int $attempt     Attempt row, or its id.
	 * @param int        $question_id Question to price.
	 * @return float Marks the question is worth in this sitting; never 0.
	 */
	public function marks_for_question( object|int $attempt, int $question_id ): float {
		$attempt = is_object( $attempt ) ? $attempt : QuizAttempt::find( $attempt );
		if ( ! $attempt ) {
			return 1.0;
		}

		$marks = $this->marks_for_attempt( $attempt, true )[ $question_id ] ?? 0.0;

		return $marks > 0 ? $marks : 1.0;
	}

	/**
	 * Score an attempt against the paper it was served.
	 *
	 * The QUIZ decides the denominator, not the student. The loop walks every
	 * question the sitting was worth ({@see marks_for_attempt()}) and looks up the
	 * response for each, so a question left unanswered still contributes its
	 * marks to `max_score` and earns nothing. Iterating the response rows
	 * instead meant an unanswered question simply left the exam: a student who
	 * answered 1 of 2 questions and ran out of time was graded 1/1 = 100%
	 * (Basecamp 10134140104). It also made the totals a function of how many
	 * rows happened to exist, which is how duplicate rows inflated a 4/4 quiz
	 * to 8/8 (Basecamp 10134140396, fixed at the source in
	 * {@see QuestionResponse::record()}).
	 *
	 * Questions whose row is gone are skipped rather than charged to the
	 * student — a deleted question is not marks they failed to earn.
	 *
	 * @param int $attempt_id Attempt to score.
	 * @return array{score:float,max_score:float,has_ungraded_essay:bool}
	 */
	public function auto_grade( int $attempt_id ): array {
		$attempt   = QuizAttempt::find( $attempt_id );
		$marks_map = $this->marks_for_attempt( $attempt, true );

		// The attempt's current answers, indexed by question so an unanswered
		// question resolves to null instead of dropping out of the loop.
		$responses_by_q = [];
		foreach ( QuestionResponse::get_for_attempt( $attempt_id ) as $response ) {
			$responses_by_q[ (int) $response->question_id ] = $response;
		}

		$total_score        = 0.0;
		$total_max          = 0.0;
		$has_ungraded_essay = false;

		// Batch-load questions + their answers up front so the loop issues no
		// per-question queries (was Question::find + Answer::get_for_question per
		// response — N+N for an N-question quiz). Pre-fetched answers are threaded
		// into the scorers below.
		$question_ids    = array_keys( $marks_map );
		$questions_by_id = $question_ids ? Question::find_in( $question_ids ) : array();
		$answers_by_q    = $question_ids ? Answer::get_for_questions( $question_ids ) : array();

		foreach ( $marks_map as $question_id => $max_marks ) {
			$question = $questions_by_id[ $question_id ] ?? null;

			if ( ! $question ) {
				continue;
			}

			$total_max += $max_marks;

			$response = $responses_by_q[ $question_id ] ?? null;
			if ( ! $response ) {
				// Unanswered: the marks are already in the denominator, and there
				// is no row to grade. Nothing pends manual review either — a blank
				// essay must not hold the attempt open forever.
				continue;
			}

			// Heal the row's denominator against the paper, whoever ends up
			// grading it. Rows recorded before answer_question() started stamping
			// max_score carry 0.00, and a row can also be re-priced by the freeze
			// on a resumed attempt. Only the auto-gradeable branch below used to
			// write this, so anything needing a human — or an extension — kept a
			// zero denominator and multiplied its correct fraction by it
			// (Basecamp 10132809181). Guarded so the common case (already
			// correct) costs no write.
			if ( abs( (float) $response->max_score - $max_marks ) > 0.0001 ) {
				QuestionResponse::update( (int) $response->id, [ 'max_score' => $max_marks ] );
				$response->max_score = $max_marks;
			}

			$type           = $question->type;
			$auto_gradeable = $this->is_free_auto_gradeable( $type );

			if ( ! $auto_gradeable ) {
				// Essay / manual review, and the types Pro grades through the
				// learnomy_quiz_auto_grade_result filter below: contribute max to
				// total_max but score nothing until they are graded.
				if ( empty( $response->graded_at ) ) {
					$has_ungraded_essay = true;
				} else {
					$total_score += (float) $response->score;
				}
				continue;
			}

			// An instructor override WINS over the computed score.
			//
			// This branch used to recompute every auto-gradeable answer and write
			// the result back, unconditionally. So an instructor correcting a
			// mis-keyed or ambiguous multiple-choice question got a success
			// response, the row kept their grader_id, and the score was silently
			// replaced with the machine's on the next finalize - the override
			// looked accepted and did nothing (Basecamp 10198940016).
			//
			// grader_id is the discriminator, and it exists for exactly this: it
			// is written only by grade_question(), never by auto-grading. A row
			// carrying one is a HUMAN judgement about this specific answer, and a
			// human who has looked at the answer knows something the answer key
			// does not.
			//
			// The regrade still happens for every row nobody has touched, so
			// fixing the answer key and re-running still corrects the cohort -
			// it just stops trampling the individual corrections made along the
			// way.
			if ( ! empty( $response->grader_id ) ) {
				$total_score += (float) $response->score;
				continue;
			}

			$given  = json_decode( $response->response_json ?? '', true );
			$earned = $this->score_response( $type, $given, $question_id, $max_marks, $answers_by_q[ $question_id ] ?? array() );

			QuestionResponse::update(
				(int) $response->id,
				[
					'score'     => $earned,
					'graded_at' => now(),
				]
			);

			$total_score += $earned;
		}

		// THE floor, and the reason a negative fraction is safe to allow at all.
		//
		// Individual questions may score below zero -- that is what negative
		// marking is for -- but an ATTEMPT must never come out negative. A
		// learner finishing a paper owing marks is not a grade, it is a
		// spreadsheet error wearing one: it makes percentages meaningless,
		// breaks any pass calculation that assumes 0..max, and would show a
		// student "-12%" on their transcript.
		//
		// Without this line the per-question penalties added up unbounded.
		// (Basecamp 10153962110)
		$total_score = max( 0.0, $total_score );

		$result = [
			'score'              => round( $total_score, 2 ),
			'max_score'          => round( $total_max, 2 ),
			'has_ungraded_essay' => $has_ungraded_essay,
		];

		$result = apply_filters( 'learnomy_quiz_auto_grade_result', $result, $attempt_id );

		// Re-derive the "needs manual grading" flag from the source of truth
		// AFTER extensions grade their own types. The loop above latches
		// $has_ungraded_essay before the filter runs, so a type that Free
		// can't score but an extension can (Pro `matching`/`ordering`, which
		// stamp graded_at via the filter) would otherwise hold a fully
		// auto-graded attempt at 'submitted' forever — completion and the
		// certificate never fire. count_ungraded() counts responses with a
		// NULL graded_at, so only genuinely human-graded work (real essays)
		// keeps the attempt open. Extension-agnostic: works for any future
		// filter-graded type without touching this method again.
		$result['has_ungraded_essay'] = QuestionResponse::count_ungraded( $attempt_id ) > 0;

		return $result;
	}

	/**
	 * Get the official score for a student on a quiz, respecting the grading method.
	 *
	 * The grading method determines which attempt(s) count toward the student's
	 * final score: highest, latest, average, or first completed attempt.
	 *
	 * `last` is accepted as a deprecated alias for `latest` — normalized here
	 * so rows saved by older admin code continue to resolve correctly.
	 */
	public function get_official_score( int $user_id, int $quiz_id ): float {
		$quiz = Quiz::find( $quiz_id );
		if ( ! $quiz ) {
			return 0.0;
		}

		return $this->select_official_score(
			$this->normalize_grading_method( $quiz->grading_method ?? 'highest' ),
			QuizAttempt::get_scores( $user_id, $quiz_id )
		);
	}

	/**
	 * The official score a grading method selects out of a chronological score
	 * list. THE one definition of what each method means.
	 *
	 * It is deliberately pure (no DB, no quiz row) so both readers — the
	 * gradebook's {@see get_official_score()} and the pass predicate
	 * {@see passed_by_grading_method()} — resolve `average` the same way. Two
	 * copies of this switch is exactly how a course gate and a grade report end
	 * up telling a student two different things about the same attempts.
	 *
	 * @param string  $method Normalized grading method.
	 * @param float[] $scores Chronological percentages; empty means no attempt.
	 */
	private function select_official_score( string $method, array $scores ): float {
		if ( empty( $scores ) ) {
			return 0.0;
		}

		$scores = array_map( 'floatval', array_values( $scores ) );

		switch ( $method ) {
			case 'highest':
				return max( $scores );

			case 'latest':
				return end( $scores );

			case 'average':
				return round( array_sum( $scores ) / count( $scores ), 2 );

			case 'first':
				return $scores[0];

			default:
				return end( $scores );
		}
	}

	/**
	 * Normalize legacy `last` → canonical `latest` grading method.
	 *
	 * Older quiz rows may have `last` persisted in the column; callers go
	 * through this helper so the service reads every stored value the same way.
	 */
	private function normalize_grading_method( string $method ): string {
		return 'last' === $method ? 'latest' : $method;
	}

	/**
	 * Has this STUDENT passed this quiz, per the quiz's configured grading
	 * method (highest / latest / first / average)?
	 *
	 * The student-level counterpart to `lrn_quiz_attempts.passed`, which is a
	 * per-SITTING fact and must stay one. This is the only place the grading
	 * method decides a pass/fail verdict: it takes the official score the method
	 * selects across every finished attempt and compares it to the quiz's
	 * (filtered) passing score.
	 *
	 * This logic used to live in `passed_with_method()`, which was called at
	 * WRITE time and stamped the student-level verdict onto the individual
	 * attempt row — so with grading_method='highest' a 0% sitting was stored as
	 * passed=1 once the student's best had cleared the bar, and quiz statistics
	 * reported a 100% pass rate over attempts that had plainly failed
	 * (Basecamp 10132808925). Reading it here instead of writing it there keeps
	 * both answers available and neither one lying.
	 *
	 * Because it reads the stored attempts, callers must invoke it AFTER the
	 * attempt they care about has been persisted — which is what removed the
	 * old "exclude the in-flight attempt, then add its fresh percentage back"
	 * compensation the write-time version needed.
	 *
	 * Since 1.8.1 this is ALSO the course-completion gate (via
	 * Progress_Service::has_passed_quiz()). It used to be a second opinion: the
	 * gate accepted any sitting that ever cleared the bar, so a quiz graded by
	 * `average` with sittings 40 / 100 / 40 stood at an official 60 against a
	 * pass mark of 70 — a fail — while the certificate was issued off the 100.
	 * A teacher who chooses `average` has said precisely that one lucky sitting
	 * must not count. One question, one answer.
	 *
	 * @param int $user_id Student.
	 * @param int $quiz_id Quiz.
	 * @return bool True when the student's official score meets the pass mark.
	 */
	public function student_passed_by_grading_method( int $user_id, int $quiz_id ): bool {
		$quiz = Quiz::find( $quiz_id );
		if ( ! $quiz ) {
			return false;
		}

		return $this->passed_by_grading_method( $user_id, [ $quiz ] )[ $quiz_id ] ?? false;
	}

	/**
	 * {@see student_passed_by_grading_method()} for a SET of quizzes, reading
	 * every attempt in ONE query.
	 *
	 * Takes hydrated quiz ROWS rather than ids because every caller already has
	 * them (the course gate lists the course's quizzes to find them at all), and
	 * re-fetching per id would put back the per-quiz query this exists to remove.
	 * Callers holding only ids hydrate with {@see Quiz::find_many()}.
	 *
	 * Practice quizzes are graded normally here; the "a practice run is not an
	 * exam" bypass belongs to the completion gate, which owns that policy — see
	 * Progress_Service::passed_quizzes().
	 *
	 * @param int      $user_id Student.
	 * @param object[] $quizzes Quiz rows (reads id, mode, grading_method, passing_score).
	 * @return array<int, bool> quiz_id => passed.
	 */
	public function passed_by_grading_method( int $user_id, array $quizzes ): array {
		$by_id = [];
		foreach ( $quizzes as $quiz ) {
			$quiz_id = (int) ( $quiz->id ?? 0 );
			if ( $quiz_id > 0 ) {
				$by_id[ $quiz_id ] = $quiz;
			}
		}

		if ( empty( $by_id ) ) {
			return [];
		}

		$scores_by_quiz = QuizAttempt::get_scores_by_quiz( $user_id, array_keys( $by_id ) );

		$verdicts = [];
		foreach ( $by_id as $quiz_id => $quiz ) {
			$scores = $scores_by_quiz[ $quiz_id ] ?? [];

			// No finished sitting is a fail for every mode, surveys included —
			// there is nothing to have satisfied.
			if ( empty( $scores ) ) {
				$verdicts[ $quiz_id ] = false;
				continue;
			}

			// A survey has no pass mark; submitting it is the whole requirement.
			if ( 'survey' === (string) ( $quiz->mode ?? 'graded' ) ) {
				$verdicts[ $quiz_id ] = true;
				continue;
			}

			$pass_score = (float) ( $quiz->passing_score ?? 70 );

			/** This filter is documented in includes/services/class-quiz-service.php */
			$pass_score = (float) apply_filters( 'learnomy_quiz_pass_score', $pass_score, 0, $quiz_id );

			$method = $this->normalize_grading_method( (string) ( $quiz->grading_method ?? 'highest' ) );

			$verdicts[ $quiz_id ] = $this->select_official_score( $method, $scores ) >= $pass_score;
		}

		return $verdicts;
	}

	/**
	 * Fire the attempt's pass/fail outcome, then re-evaluate the course after an
	 * attempt is finalised: refresh the stored progress and complete the course
	 * (idempotently) when its rule is satisfied. Progress_Service re-runs
	 * `learnomy_course_completed` -> auto-issue certificate. Called from BOTH the
	 * auto-submit path and the manual-grade finaliser so a quiz passed only after
	 * manual grading (essay / manual-review short answer) still completes the
	 * course and issues the certificate.
	 *
	 * Runs on a fail as well as a pass — see the note at the recompute below.
	 */
	private function refresh_course_after_attempt( object $attempt, bool $passed ): void {
		$quiz = Quiz::find( (int) $attempt->quiz_id );

		// Quiz outcome for integrations (e.g. a CRM tag on pass / fail). Surveys
		// have no pass/fail so they are skipped. This is the shared finaliser for
		// both the auto-grade submit path and the manual-grade finaliser
		// (maybe_finalize_attempt), so the outcome fires exactly once per attempt.
		if ( $quiz && 'survey' !== (string) ( $quiz->mode ?? 'graded' ) ) {
			$attempt_id = (int) $attempt->id;
			$user_id    = (int) $attempt->user_id;
			$quiz_id    = (int) $attempt->quiz_id;
			$score      = (float) ( $attempt->percentage ?? 0 );
			if ( $passed ) {
				/**
				 * Fires when a quiz attempt is finally graded as passed.
				 *
				 * @param int   $attempt_id Attempt id.
				 * @param int   $user_id    Student.
				 * @param int   $quiz_id    Quiz.
				 * @param float $score      Percentage score (0-100).
				 */
				do_action( 'learnomy_quiz_passed', $attempt_id, $user_id, $quiz_id, $score );
			} else {
				/**
				 * Fires when a quiz attempt is finally graded as failed.
				 *
				 * @param int   $attempt_id Attempt id.
				 * @param int   $user_id    Student.
				 * @param int   $quiz_id    Quiz.
				 * @param float $score      Percentage score (0-100).
				 */
				do_action( 'learnomy_quiz_failed', $attempt_id, $user_id, $quiz_id, $score );
			}
		}

		/*
		 * Recompute on a fail too, not just a pass. On a `lessons_and_quiz`
		 * course the quiz is one of the counted steps, so the outcome of ANY
		 * graded attempt moves the stored percentage — and a student who
		 * finished the lessons then failed has no lesson event left to trigger
		 * a refresh, so an early return here left them reading a stale figure
		 * indefinitely. maybe_complete_course() re-checks the course's own rule
		 * before completing anything, so a failed attempt still cannot complete
		 * a course; it only refreshes progress_pct.
		 */
		if ( $quiz && ! empty( $quiz->course_id ) ) {
			( new Progress_Service() )->maybe_complete_course(
				(int) $attempt->user_id,
				(int) $quiz->course_id
			);
		}
	}

	/**
	 * Whether a response earns full credit for an auto-gradeable question.
	 *
	 * Used by per-question (immediate / after_question) feedback so free-text
	 * questions (short_answer / fill_blank) are graded against their accepted
	 * answers via the same scoring path as final submit — answer-ID matching is
	 * meaningless for free text and always reported them as incorrect.
	 *
	 * @param int    $question_id Question being answered.
	 * @param string $type        Question type.
	 * @param mixed  $given       The student's raw response.
	 */
	public function is_response_correct( int $question_id, string $type, mixed $given ): bool {
		// Normalise max marks to 1 so a fully-correct answer scores exactly 1.0
		// (fill_blank awards partial credit, so "correct" = every blank right).
		$score = $this->score_response( $type, $given, $question_id, 1.0 );
		return $score >= 1.0 - 0.0001;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Whether Free can score this question type synchronously — i.e. it has an
	 * objective answer key — as opposed to essay/manual or Pro filter-graded
	 * types (matching/ordering/numeric/math/calculated) that are scored later.
	 *
	 * Single source of truth for both auto_grade()'s "score now vs wait for
	 * manual grading" branch and answer_question()'s immediate per-question
	 * correctness for the xAPI `answered` seam.
	 */
	private function is_free_auto_gradeable( string $type ): bool {
		return in_array(
			$type,
			[ 'mc_single', 'mc_multi', 'multiple_choice', 'multiple_select', 'true_false', 'short_answer', 'fill_blank' ],
			true
		);
	}

	// Routes a single response to the appropriate scoring algorithm.
	// $answers (optional) = the question's pre-fetched answer rows. Passed by
	// auto_grade()'s batch loop to avoid a per-question query; null (the
	// immediate-feedback path) falls back to querying inside each scorer.
	private function score_response( string $type, mixed $given, int $question_id, float $max_marks, ?array $answers = null ): float {
		switch ( $type ) {
			case 'mc_single':
			case 'multiple_choice':
			case 'true_false':
				return $this->score_mc_single( $given, $question_id, $max_marks, $answers );

			case 'mc_multi':
			case 'multiple_select':
				return $this->score_mc_multi( $given, $question_id, $max_marks, $answers );

			case 'short_answer':
				return $this->score_short_answer( $given, $question_id, $max_marks, $answers );

			case 'fill_blank':
				return $this->score_fill_blank( $given, $question_id, $max_marks, $answers );
		}

		return 0.0;
	}

	// MC single / true-false: full marks if the chosen answer_id is the correct one.
	/**
	 * True when the author set a per-answer partial fraction (strictly between 0
	 * and 1) on this question — i.e. the editor's fraction slider was used. Only
	 * then do we score by fraction; otherwise the data is plain is_correct flags
	 * and we use the binary / net-hits model so legacy questions are unaffected.
	 *
	 * @param object[] $answers
	 */
	private function uses_custom_fractions( array $answers ): bool {
		foreach ( $answers as $answer ) {
			$fraction = (float) $answer->fraction;
			if ( $fraction > 0.0 && $fraction < 1.0 ) {
				return true;
			}
		}
		return false;
	}

	// Single choice: honour the chosen answer's partial fraction when the author
	// set one (the editor's slider); otherwise full marks for the correct answer,
	// zero otherwise.
	private function score_mc_single( mixed $given, int $question_id, float $max_marks, ?array $answers = null ): float {
		$chosen_id = is_array( $given ) ? (int) ( $given[0] ?? 0 ) : (int) $given;
		if ( $chosen_id <= 0 ) {
			return 0.0;
		}
		$answers = $answers ?? Answer::get_for_question( $question_id );
		$custom  = $this->uses_custom_fractions( $answers );
		foreach ( $answers as $answer ) {
			if ( (int) $answer->id !== $chosen_id ) {
				continue;
			}
			if ( $custom ) {
				// Floor -1.0, matching what the author may store. Clamping to
				// 0.0 here would silently discard a negative-marking scheme the
				// author had deliberately set up: the penalty would survive in
				// the answers table and do nothing at marking time, which is
				// the worst of both (it looks configured and is not).
				// (Basecamp 10153962110)
				return round( max( -1.0, min( 1.0, (float) $answer->fraction ) ) * $max_marks, 4 );
			}
			return 1 === (int) $answer->is_correct ? $max_marks : 0.0;
		}
		return 0.0;
	}

	// MC multi: when per-answer fractions are set (the slider), score = sum of the
	// selected answers' fractions × max_marks, clamped [0, max] — so non-uniform
	// weights and distractor penalties are honoured. Otherwise fall back to the
	// net-hits model: (correct hits − wrong hits) / total_correct × max_marks.
	private function score_mc_multi( mixed $given, int $question_id, float $max_marks, ?array $answers = null ): float {
		$chosen_ids = array_map( 'intval', (array) $given );
		if ( empty( $chosen_ids ) ) {
			return 0.0;
		}

		$answers = $answers ?? Answer::get_for_question( $question_id );

		if ( $this->uses_custom_fractions( $answers ) ) {
			$fraction_by_id = array();
			foreach ( $answers as $answer ) {
				$fraction_by_id[ (int) $answer->id ] = (float) $answer->fraction;
			}
			$sum = 0.0;
			foreach ( $chosen_ids as $chosen_id ) {
				$sum += $fraction_by_id[ $chosen_id ] ?? 0.0;
			}
			// Same floor as the single-choice branch. A learner who ticks three
			// penalty options and one right one should land below zero on THIS
			// question -- that is the point of negative marking -- while the
			// attempt total is floored at zero separately, so nobody finishes a
			// quiz owing marks.
			return round( max( -1.0, min( 1.0, $sum ) ) * $max_marks, 4 );
		}

		// Binary data — net-hits partial credit (a wrong selection cancels a hit).
		$correct_ids = array_map( fn( $a ) => (int) $a->id, array_filter( $answers, fn( $a ) => 1 === (int) $a->is_correct ) );
		if ( empty( $correct_ids ) ) {
			return 0.0;
		}
		$hits   = count( array_intersect( $chosen_ids, $correct_ids ) );
		$misses = count( array_diff( $chosen_ids, $correct_ids ) );
		$net    = max( 0, $hits - $misses );

		return round( ( $net / count( $correct_ids ) ) * $max_marks, 4 );
	}

	// Short answer: match the student's input against each accepted answer after
	// normalising trivial input differences that should never cost a mark —
	// internal whitespace runs, smart quotes / dashes, non-breaking spaces (all
	// common from mobile keyboards + copy-paste). Numbers compare by value, so
	// `5` == `5.0` == `5.00`. The per-answer "Case-sensitive" toggle (stored in
	// `group_id` as `cs:1`/`cs:0` by question-editor.js) is honoured: a
	// case-sensitive answer must match case exactly; others (and legacy
	// empty/NULL group_id rows) match case-insensitively. This does NOT do
	// fuzzy/"contains"/wildcard matching — that guesses, which is wrong for
	// both false positives and negatives; the manual-review fallback is the
	// right home for genuinely subjective answers.
	private function score_short_answer( mixed $given, int $question_id, float $max_marks, ?array $answers = null ): float {
		$correct_answers = null !== $answers
			? array_values( array_filter( $answers, static fn( $a ) => 1 === (int) $a->is_correct ) )
			: Answer::get_correct_for_question( $question_id );
		if ( empty( $correct_answers ) ) {
			return 0.0;
		}

		$given_norm = $this->normalize_short_answer( (string) $given );

		foreach ( $correct_answers as $answer ) {
			$accepted_norm  = $this->normalize_short_answer( (string) $answer->content );
			$case_sensitive = 0 === strpos( (string) ( $answer->group_id ?? '' ), 'cs:1' );

			// Numeric equivalence — only when BOTH sides are numbers, so
			// "5 apples" still falls through to a string comparison.
			if ( is_numeric( $given_norm ) && is_numeric( $accepted_norm ) ) {
				if ( abs( (float) $given_norm - (float) $accepted_norm ) < 0.0000001 ) {
					return $max_marks;
				}
				continue;
			}

			if ( $case_sensitive ) {
				if ( $given_norm === $accepted_norm ) {
					return $max_marks;
				}
			} elseif ( 0 === strcasecmp( $given_norm, $accepted_norm ) ) {
				return $max_marks;
			}
		}

		return 0.0;
	}

	// Normalise a short-answer string for comparison: fold smart punctuation to
	// ASCII, collapse internal whitespace, trim. Case is left intact so the
	// case-sensitive path can still distinguish it. Applied identically to the
	// student input and each accepted answer.
	private function normalize_short_answer( string $value ): string {
		$value = strtr(
			$value,
			[
				"\xE2\x80\x98" => "'",  // ' left single quote
				"\xE2\x80\x99" => "'",  // ' right single quote / apostrophe
				"\xE2\x80\x9C" => '"',  // " left double quote
				"\xE2\x80\x9D" => '"',  // " right double quote
				"\xE2\x80\x93" => '-',  // – en dash
				"\xE2\x80\x94" => '-',  // — em dash
				"\xC2\xA0"     => ' ',  // non-breaking space
			]
		);

		// Collapse any run of whitespace to a single space, then trim.
		$value = preg_replace( '/\s+/u', ' ', $value );

		return trim( (string) $value );
	}

	// Fill-in-the-blank: $given is an array keyed by blank index — OR a bare
	// string, which is what the single-blank widget submits.
	// Each blank is checked case-insensitively against answers sharing the same group_id.
	// Score = (blanks correct / total blanks) × max_marks.
	private function score_fill_blank( mixed $given, int $question_id, float $max_marks, ?array $answers = null ): float {
		$all_answers = $answers ?? Answer::get_for_question( $question_id );
		if ( empty( $all_answers ) ) {
			return 0.0;
		}

		// Group answers by group_id (blank identifier).
		$groups = [];
		foreach ( $all_answers as $answer ) {
			if ( ! (int) $answer->is_correct ) {
				continue;
			}
			$key              = $answer->group_id ?? '0';
			$groups[ $key ][] = $this->normalize_short_answer( (string) $answer->content );
		}

		if ( empty( $groups ) ) {
			return 0.0;
		}

		// A ONE-blank question is submitted as a bare string, not a map, so this
		// used to fall through to an empty array and score every correct answer
		// 0.00 while multi-blank questions graded fine (Basecamp 10163002876).
		// Bind the scalar to the only blank there is; with more than one blank a
		// scalar is genuinely unattributable, so it stays a miss.
		if ( is_array( $given ) ) {
			$given_map = $given;
		} elseif ( is_scalar( $given ) && 1 === count( $groups ) ) {
			$given_map = [ (string) array_key_first( $groups ) => (string) $given ];
		} else {
			$given_map = [];
		}

		$total_blanks = count( $groups );
		$correct      = 0;

		foreach ( $groups as $blank_key => $accepted_values ) {
			$student_answer = $this->normalize_short_answer( (string) ( $given_map[ $blank_key ] ?? '' ) );
			foreach ( $accepted_values as $accepted ) {
				if ( 0 === strcasecmp( $student_answer, $accepted ) ) {
					++$correct;
					break;
				}
			}
		}

		return round( ( $correct / $total_blanks ) * $max_marks, 4 );
	}

	// Checks whether every response for the attempt has been graded; if so, finalizes.
	private function maybe_finalize_attempt( int $attempt_id ): void {
		$ungraded = QuestionResponse::count_ungraded( $attempt_id );

		if ( 0 === $ungraded ) {
			$grades = $this->auto_grade( $attempt_id );

			$attempt    = QuizAttempt::find( $attempt_id );
			$quiz       = Quiz::find( (int) $attempt->quiz_id );
			$percentage = $grades['max_score'] > 0
				? round( ( $grades['score'] / $grades['max_score'] ) * 100, 2 )
				: 0.00;
			$pass_score = (float) ( $quiz->passing_score ?? 70 );
			$pass_score = apply_filters( 'learnomy_quiz_pass_score', $pass_score, $attempt_id, (int) $attempt->quiz_id );

			// Same per-sitting verdict as the auto-submit path: did THIS attempt
			// clear the bar. The grading method is not consulted here — see
			// student_passed_by_grading_method() for the student-level answer.
			$passed = $percentage >= $pass_score;

			QuizAttempt::update(
				$attempt_id,
				[
					'status'     => 'graded',
					'score'      => $grades['score'],
					'max_score'  => $grades['max_score'],
					'percentage' => $percentage,
					'passed'     => $passed ? 1 : 0,
					'graded_at'  => now(),
				]
			);

			do_action( 'learnomy_quiz_graded', $attempt_id, (int) $attempt->user_id, (int) $attempt->quiz_id );

			// Manual grading just finalised the attempt — re-evaluate course
			// completion here too, so an essay / manual-review quiz the student
			// passes after grading completes the course and issues the
			// certificate. The auto-submit path does the same; both go through
			// refresh_course_after_attempt(). (Audit blocker, 2026-06-04.)
			$this->refresh_course_after_attempt( $attempt, $passed );
		}
	}

	/**
	 * Build the per-question review for a graded/submitted attempt.
	 *
	 * Single source of truth for the "what did I answer / what was right" review,
	 * shared by: the quiz-submit REST response (live results screen), the
	 * student's persistent attempt review (/account/quiz-results/), and Pro's
	 * instructor drill-down. One row per question of the paper the student sat
	 * ({@see marks_for_attempt()}) — the same basis {@see auto_grade()} scores on, so
	 * the review always accounts for exactly the marks in the score. A question
	 * the student never answered is listed with nothing selected rather than
	 * omitted, which is what left a student looking at "50%" beside a single
	 * reviewed question (Basecamp 10134140104).
	 *
	 * Row shape: question_id, response_id (0 when unanswered), title, type,
	 * correct, pending, given (text reply), answers[] (options with
	 * selected/correct flags), and — when $feedback_timing reveals solutions —
	 * score/max_score/explanation.
	 *
	 * @param object $attempt         Attempt row.
	 * @param string $feedback_timing Quiz feedback_timing; gates solution reveal.
	 * @return array<int, array<string, mixed>>
	 */
	/**
	 * Resolve a quiz's feedback_timing into the concrete reveal for ONE attempt.
	 *
	 * `after_pass` is the only value that depends on the viewer: passers have
	 * already been shown the answers, so withholding them later reads as a bug,
	 * while a student who has NOT passed must not see the key before their
	 * retake. Everything else passes through unchanged.
	 *
	 * This exists because the rule was written three times and two copies were
	 * wrong. The live results controller resolved `after_pass` correctly; the
	 * account review and the PDF export each special-cased only `never` and then
	 * handed `build_attempt_review()` a hardcoded 'after_submit' — so a student
	 * who failed an after_pass exam could open their attempt, or export the PDF,
	 * and read the complete answer key (Basecamp 10184327412). A PDF is the
	 * worst place to leak it: it outlives the session and travels.
	 *
	 * @param object $attempt         Attempt row (carries user_id + quiz_id).
	 * @param string $feedback_timing The quiz's configured value.
	 * @return string Concrete timing: never | after_attempt | after_submit.
	 */
	public function resolve_feedback_timing( object $attempt, string $feedback_timing ): string {
		if ( 'after_pass' !== $feedback_timing ) {
			return $feedback_timing;
		}

		// Student-level and grading-method aware: whether they have passed is
		// exactly what the grading method decides, so the reveal follows it.
		return $this->student_passed_by_grading_method( (int) $attempt->user_id, (int) $attempt->quiz_id )
			? 'after_submit'
			: 'after_attempt';
	}

	/**
	 * Whether a resolved timing may show per-question data at all.
	 *
	 * The companion to {@see resolve_feedback_timing()}, and it has to be shared
	 * for the same reason: resolving the timing answers "which rule applies to
	 * this attempt", NOT "may this student see the questions". Two withholding
	 * values answer the second question — 'never' and 'after_attempt' — and a
	 * consumer that checks only 'never' hands the review to a student the
	 * resolver just placed behind the gate.
	 *
	 * That is exactly what shipped for Basecamp 10184327412. The resolver was
	 * extracted and all the comments were updated, but the account page and the
	 * PDF export kept their `'never' === $timing` gate, so a FAILED student on an
	 * after_pass quiz resolved to 'after_attempt' and still received every
	 * question, every option, their own marked answer and a per-category
	 * "N of M correct" tally. The per-option solution flag was suppressed, which
	 * is why it read as fixed — but any category scoring full marks confirms the
	 * student's own answer as correct, so the key is derivable, and the PDF
	 * carries it out of the session. The live controller had the pair right and
	 * the two persistent surfaces did not; one predicate is the only way that
	 * stays true.
	 *
	 * @param string $feedback_timing Timing already resolved for this attempt.
	 * @return bool True when per-question review may be built.
	 */
	public static function timing_reveals_review( string $feedback_timing ): bool {
		return ! in_array( $feedback_timing, array( 'never', 'after_attempt' ), true );
	}

	/**
	 * Whether a quiz is held shut by the lesson it is bound to.
	 *
	 * ONE predicate behind the server gate in {@see start_attempt()}, the quiz
	 * page's gate state and the padlock the outlines draw on the row. They have
	 * to agree: a padlock on a row a student can still reach by URL is theatre,
	 * and a gate with no padlock is a dead end they only discover after
	 * clicking. Both were true before this shipped.
	 *
	 * Only lesson-BOUND quizzes inherit a lock. An end-of-section or
	 * course-level quiz has no lesson to take a schedule from, and a quiz whose
	 * bound lesson has since been deleted stays open rather than becoming
	 * permanently unreachable.
	 *
	 * @param object $quiz    Quiz row.
	 * @param int    $user_id Viewer; 0 for anonymous.
	 * @return bool True when the bound lesson is locked for this user.
	 */
	public static function lesson_lock_applies( object $quiz, int $user_id ): bool {
		$lesson_id = (int) ( $quiz->lesson_id ?? 0 );
		if ( $lesson_id <= 0 ) {
			return false;
		}

		/**
		 * Whether a quiz inherits the lock state of the lesson it is bound to.
		 *
		 * Default true: a site owner who drips a lesson expects its quiz to wait
		 * with it. Filter to false to restore the pre-1.9.2 behaviour, where a
		 * bound quiz was reachable while its lesson was still locked — a site
		 * that deliberately uses quizzes as pre-tests for upcoming lessons is
		 * the case this exists for.
		 *
		 * @param bool   $inherits Whether the lock is inherited.
		 * @param object $quiz     Quiz row.
		 * @param int    $user_id  Viewer.
		 */
		if ( ! apply_filters( 'learnomy_quiz_inherits_lesson_lock', true, $quiz, $user_id ) ) {
			return false;
		}

		$lesson = \Learnomy\Models\Lesson::find( $lesson_id );
		if ( ! $lesson ) {
			return false;
		}

		return ! \Learnomy\Services\Lesson_Service::is_row_open_to( $lesson, $user_id );
	}

	/**
	 * Group attempt-review rows by the quiz's own structure — ONE grouping
	 * shared by the account page, the live results screen's persistent twin
	 * and the PDF export, so a 300-question attempt reads the same way on
	 * every surface.
	 *
	 * Sectioned quizzes chunk by questions_per_section (slot order IS the
	 * section order); everything else groups by question category when at
	 * least two named categories exist. Below that, one unlabeled group the
	 * consumers render headerless.
	 *
	 * @param array       $rows Rows from build_attempt_review().
	 * @param object|null $quiz The quiz row.
	 * @return array<int,array{label:string,summary:string,rows:array}>
	 */
	public static function group_attempt_review( array $rows, ?object $quiz ): array {
		if ( ! $rows ) {
			return array();
		}

		$summary = static function ( array $group_rows ): string {
			$correct = count( array_filter( $group_rows, static fn( $r ) => ! empty( $r['correct'] ) ) );
			/* translators: 1: correct answers in the group, 2: questions in the group */
			return sprintf( __( '%1$d of %2$d correct', 'learnomy' ), $correct, count( $group_rows ) );
		};

		if ( $quiz && 'sectioned' === (string) ( $quiz->display_template ?? '' ) ) {
			$per    = max( 1, (int) ( $quiz->questions_per_section ?? 10 ) );
			$groups = array();
			foreach ( array_chunk( $rows, $per ) as $i => $chunk ) {
				$groups[] = array(
					/* translators: %d: section number */
					'label'   => sprintf( __( 'Section %d', 'learnomy' ), $i + 1 ),
					'summary' => $summary( $chunk ),
					'rows'    => $chunk,
				);
			}
			return count( $groups ) > 1 ? $groups : array(
				array(
					'label'   => '',
					'summary' => '',
					'rows'    => $rows,
				),
			);
		}

		$groups = array();
		foreach ( $rows as $row ) {
			$label = (string) ( $row['category'] ?? '' );
			if ( ! isset( $groups[ $label ] ) ) {
				$groups[ $label ] = array(
					'label'   => $label,
					'summary' => '',
					'rows'    => array(),
				);
			}
			$groups[ $label ]['rows'][] = $row;
		}

		$named = count( array_filter( array_keys( $groups ), static fn( $l ) => '' !== $l ) );
		if ( $named < 2 ) {
			return array(
				array(
					'label'   => '',
					'summary' => '',
					'rows'    => $rows,
				),
			);
		}

		$out = array();
		foreach ( $groups as $group ) {
			$group['summary'] = $summary( $group['rows'] );
			$out[]            = $group;
		}
		return $out;
	}

	public function build_attempt_review( object $attempt, string $feedback_timing = 'after_submit' ): array {
		$marks_map = $this->marks_for_attempt( $attempt, false );
		if ( empty( $marks_map ) ) {
			return array();
		}

		$responses_by_q = array();
		foreach ( QuestionResponse::get_for_attempt( (int) $attempt->id ) as $response ) {
			$responses_by_q[ (int) $response->question_id ] = $response;
		}

		// Batch every question + option in one query each so the loop stays flat
		// (it ran Question::find() per row) and the option list is never empty
		// (submit_attempt's inline build referenced an undefined $answers_by_qid,
		// silently dropping per-option detail — fixed here by sourcing from this
		// single builder).
		$question_ids    = array_keys( $marks_map );
		$questions_by_id = Question::find_in( $question_ids );
		$answers_by_qid  = Answer::get_for_questions( $question_ids );

		// Category names, batched once, so a large review can GROUP by them —
		// a 300-question exam reviewed as one flat list is unusable, and the
		// quiz's own structure (sections, categories) is how it stays
		// navigable (Basecamp 10180442623 follow-up).
		$category_ids = array();
		foreach ( $questions_by_id as $q_row ) {
			if ( ! empty( $q_row->category_id ) ) {
				$category_ids[] = (int) $q_row->category_id;
			}
		}
		$categories_by_id = array();
		foreach ( QuestionCategory::find_in( array_unique( $category_ids ) ) as $cat ) {
			$categories_by_id[ (int) $cat->id ] = (string) $cat->name;
		}

		$reveal = in_array( $feedback_timing, array( 'immediate', 'after_submit' ), true );

		$review = array();
		foreach ( $marks_map as $question_id => $max_marks ) {
			$question = $questions_by_id[ $question_id ] ?? null;
			if ( ! $question ) {
				continue;
			}

			// An unanswered question reviews as an empty response: nothing
			// selected, no marks earned, out of what it was worth.
			$response = $responses_by_q[ $question_id ] ?? (object) array(
				'id'              => 0,
				'response_json'   => '',
				'score'           => 0,
				'max_score'       => $max_marks,
				'graded_at'       => null,
				'grader_feedback' => '',
				// True when a PERSON set this row's score, not the auto-grader.
				// grader_id is written only by grade_question(), so it is the
				// discriminator - and now that a manual grade survives regrading
				// (Basecamp 10198940016), a student can be looking at a mark the
				// machine would have given differently. Saying so is the honest
				// half of letting the override stick.
				'graded_by_instructor' => false,
			);

			$given       = json_decode( $response->response_json ?? '', true );
			$answers_raw = $answers_by_qid[ (int) $question->id ] ?? array();

			// Which options this response selected, read through the one decoder
			// that knows response_json holds a scalar on some rows and an array
			// on others. This surface tolerated both by hand — which is why the
			// student's review was right while the instructor's grader printed
			// raw ids off the same column (Basecamp 10184327418).
			$selected_ids = QuestionResponse::selected_ids( $response->response_json ?? '' );

			$answer_review = array();
			foreach ( $answers_raw as $answer ) {
				$is_selected = in_array( (string) $answer->id, $selected_ids, true );

				$row = array(
					'id'       => (int) $answer->id,
					'content'  => $answer->content ?? '',
					'selected' => $is_selected,
				);
				// `wrong` and `icon` are DERIVED HERE, not in the template.
				// The review templates used to bind
				// `context.item.selected && !context.item.correct` and lucide
				// ternaries — but Interactivity API directive values are state
				// REFERENCES, not JS expressions, so every one of those
				// bindings silently never applied: the student's wrong pick
				// rendered indistinguishable from an option they never chose,
				// and every answer icon fell back to one glyph. Same directive
				// lesson as the sectioned-quiz i18n fix: compute in one place,
				// bind a plain path.
				if ( $reveal ) {
					$row['correct']  = (bool) $answer->is_correct;
					$row['feedback'] = $answer->feedback ?? '';
					$row['wrong']    = $is_selected && ! (bool) $answer->is_correct;
					$row['icon']     = $answer->is_correct ? 'check' : ( $is_selected ? 'x' : 'circle' );
				} else {
					// Solutions hidden: mark the pick neutrally — an 'x' here
					// would leak that it was wrong.
					$row['icon'] = $is_selected ? 'circle-dot' : 'circle';
				}
				$answer_review[] = $row;
			}

			$is_essay = in_array( $question->type, array( 'essay' ), true );
			// One vocabulary for "the response IS the learner's text", shared with
			// the grading renderer, so a type added through
			// `learnomy_question_types` cannot be text on one screen and options
			// on the other.
			$is_text_response = ! QuestionTypes::selects_options( (string) $question->type );

			$given_display = '';
			if ( is_string( $given ) ) {
				$given_display = $given;
			} elseif ( is_array( $given ) && ! empty( $given ) ) {
				// fill_blank stores one entry per blank — join in reading order.
				$given_display = implode( ', ', array_map( 'strval', $given ) );
			}

			$review_row = array(
				'question_id' => (int) $question->id,
				'response_id' => (int) $response->id,
				'title'       => $question->title ?? '',
				'type'        => $question->type ?? 'mc_single',
				'correct'     => ! $is_essay && (float) $response->score >= (float) $response->max_score,
				// Only a submitted essay waits on a grader. A blank one is simply
				// unearned — auto_grade() does not hold the attempt open for it
				// either, so the review must not promise a grade that never comes.
				'pending'     => $is_essay && (int) $response->id > 0 && empty( $response->graded_at ),
				'given'       => $is_text_response ? $given_display : null,
				'answers'     => $answer_review,
			);
			// Derived for the same reason as the per-answer icon: the header
			// glyph ternary in the templates never evaluated.
			$review_row['status_icon'] = $review_row['correct']
				? 'check-circle'
				: ( $review_row['pending'] ? 'clock' : 'x-circle' );

			// Grouping key for large reviews. '' when the question has no
			// category — the client falls back to a flat (or section) view.
			$review_row['category'] = $categories_by_id[ (int) ( $question->category_id ?? 0 ) ] ?? '';

			if ( $reveal ) {
				$review_row['score']       = (float) $response->score;
				$review_row['max_score']   = (float) $response->max_score;
				$review_row['explanation'] = $question->explanation ?? '';
			}

			// The instructor's per-response feedback on a graded essay/short-answer.
			// Saved by grade_question() but previously never surfaced, so a student
			// saw their score with no way to read the comments (Basecamp 10127363217).
			// Shown once the response is graded, independent of the solution-reveal
			// gate — it is the student's own feedback, not answer solutions.
			// Flagged for EVERY question type, not just the ones that always need
			// a human. An overridden multiple-choice answer is exactly the case
			// where a student would otherwise see a corrected mark with nothing
			// explaining it.
			if ( ! empty( $response->grader_id ) && ! empty( $response->graded_at ) ) {
				$review_row['graded_by_instructor'] = true;
			}

			$grader_feedback = (string) ( $response->grader_feedback ?? '' );
			if ( '' !== $grader_feedback && ! empty( $response->graded_at ) ) {
				$review_row['grader_feedback'] = $grader_feedback;
			}

			$review[] = $review_row;
		}

		return $review;
	}
}
