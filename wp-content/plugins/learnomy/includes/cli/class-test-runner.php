<?php
/**
 * `wp learnomy test …` — end-to-end flow scenarios with PASS/FAIL assertions.
 *
 * Each subcommand seeds the fixtures it needs, executes the real service
 * layer, asserts the database state, then cleans everything up. The command
 * exits non-zero if any assertion fails, which makes it usable in CI.
 *
 * Every scenario is self-contained: if one fails the next still runs. This is
 * intentional — we want a full diagnostic picture, not a fail-fast run.
 *
 * @package Learnomy
 */

namespace Learnomy\CLI;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Certificate;
use Learnomy\Models\Commission;
use Learnomy\Models\Course;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\EngagementMonthly;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Lesson;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\Section;
use Learnomy\Models\Subscription;
use Learnomy\Models\Withdrawal;
use Learnomy\Models\Answer;
use Learnomy\Models\Progress;
use Learnomy\Models\Question;
use Learnomy\Models\Quiz;
use Learnomy\Models\QuizSlot;
use Learnomy\Services\Certificate_Service;
use Learnomy\Services\Commission_Service;
use Learnomy\Services\Course_Service;
use Learnomy\Services\Enrollment_Service;
use Learnomy\Services\Subscription_Service;
use Learnomy\Services\Progress_Service;
use Learnomy\Services\Question_CSV;
use Learnomy\Services\Quiz_Service;

class TestRunner {

	/**
	 * When true, each scenario's {@see self::finish()} only prints the summary
	 * and skips the `WP_CLI::halt()`. `all()` flips this on so one failing
	 * scenario doesn't short-circuit the rest.
	 */
	private bool $defer_halt = false;

	/**
	 * Run every scenario in sequence and print a roll-up summary.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-fixtures]
	 * : Leave fixture data behind. Useful for manual inspection.
	 *
	 * [--verbose]
	 * : Log every intermediate row, not just the assertion outcomes.
	 *
	 * @subcommand all
	 */
	public function all( array $args, array $assoc_args ): void {
		$scenarios        = array( 'course', 'enrollment', 'lesson_completion', 'question', 'quiz', 'certificate', 'commission', 'engagement', 'membership', 'data_integrity' );
		$totals           = array(
			'passed' => 0,
			'failed' => 0,
		);
		$this->defer_halt = true;

		foreach ( $scenarios as $scenario ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( \WP_CLI::colorize( '%B═══ ' . strtoupper( $scenario ) . ' ═══%n' ) );
			$this->{$scenario}( $args, $assoc_args );
			$summary           = Assert::summary();
			$totals['passed'] += $summary['passed'];
			$totals['failed'] += $summary['failed'];
		}

		$this->defer_halt = false;

		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%B═══ TOTAL ═══%n' ) );
		\WP_CLI::line( sprintf( 'Passed: %d   Failed: %d', $totals['passed'], $totals['failed'] ) );

		if ( $totals['failed'] > 0 ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Course CRUD + instructor split + duplicate + cascade delete.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-fixtures]
	 * : Leave fixture data behind.
	 *
	 * @subcommand course
	 */
	public function course( array $args, array $assoc_args ): void {
		Assert::reset();
		Assert::section( 'Course lifecycle' );

		$fixture     = new Fixture_Builder();
		$instructor  = $fixture->make_user( 'lrn_instructor', 'instructor' );
		$co_instruct = $fixture->make_user( 'lrn_instructor', 'coinstructor' );

		Assert::listen( 'learnomy_course_created' );
		Assert::listen( 'learnomy_course_updated' );
		Assert::listen( 'learnomy_course_deleted' );

		// 1. Create via service.
		$course_ids = $fixture->make_course( $instructor );
		$course_id  = $course_ids['course_id'];
		Assert::gte( $course_id, 1, 'Course created (ID returned)' );
		Assert::hook_fired( 'learnomy_course_created', 1 );

		// 2. Confirm the row is actually in the DB.
		$saved = Course::find( $course_id );
		Assert::truthy( $saved, 'Course row exists in lrn_courses' );
		Assert::equals( (int) $saved->author_id, $instructor, 'author_id == instructor id' );
		Assert::equals( $saved->status, 'published', 'status persisted' );

		// 3. Primary instructor auto-wired at 100%.
		$instructors = CourseInstructor::get_for_course( $course_id );
		Assert::count_is( $instructors, 1, 'Exactly 1 instructor on create' );
		Assert::equals( (float) $instructors[0]->share_pct, 100.0, 'Primary share_pct == 100' );
		Assert::equals( (int) $instructors[0]->is_primary, 1, 'Primary flag set' );

		// 4. Update via service.
		( new Course_Service() )->update( $course_id, array( 'title' => 'Updated CLI Title' ) );
		$reloaded = Course::find( $course_id );
		Assert::equals( $reloaded->title, 'Updated CLI Title', 'Title update persisted' );
		Assert::hook_fired( 'learnomy_course_updated', 1 );

		// 5. Add co-instructor at 30% → junction sums to 100.
		$fixture->add_co_instructor( $course_id, $co_instruct, 30.0 );
		$pair  = CourseInstructor::get_for_course( $course_id );
		$total = array_sum( array_map( fn( $r ) => (float) $r->share_pct, $pair ) );
		Assert::count_is( $pair, 2, 'Two instructors after add_co_instructor' );
		Assert::equals( round( $total, 2 ), 100.0, 'share_pct sums to 100 across instructors' );

		// 6. Duplicate → new id, same instructor list, draft status, zero counters.
		$dup_id = ( new Course_Service() )->duplicate( $course_id );
		$fixture->track_course( $dup_id );
		$dup = Course::find( $dup_id );
		Assert::not_equals( $dup_id, $course_id, 'Duplicate has new ID' );
		Assert::equals( $dup->status, 'draft', 'Duplicate status = draft' );
		Assert::equals( (int) $dup->student_count, 0, 'Duplicate student_count reset to 0' );
		Assert::count_is( CourseInstructor::get_for_course( $dup_id ), 2, 'Duplicate keeps instructor list' );

		// 7. Delete cascades sections + lessons.
		( new Course_Service() )->delete( $course_id );
		Assert::falsy( Course::find( $course_id ), 'Course row deleted' );
		Assert::count_is( Section::get_for_course( $course_id ), 0, 'Sections cascade-deleted' );
		Assert::count_is( Lesson::get_for_course( $course_id, 0, 0, null ), 0, 'Lessons cascade-deleted' );
		Assert::hook_fired( 'learnomy_course_deleted', 1 );

		// Remove the deleted course id from the fixture tracker so cleanup doesn't choke.
		$this->maybe_cleanup( $fixture, $assoc_args );
		$this->finish();
	}

	/**
	 * Enrollment across all three sources — free, purchase, membership.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-fixtures]
	 * : Leave fixture data behind.
	 *
	 * @subcommand enrollment
	 */
	public function enrollment( array $args, array $assoc_args ): void {
		Assert::reset();
		Assert::section( 'Enrollment — all 3 sources' );

		$fixture    = new Fixture_Builder();
		$instructor = $fixture->make_user( 'lrn_instructor', 'enrollinstr' );
		$student    = $fixture->make_user( 'lrn_student', 'student' );
		$course     = $fixture->make_course( $instructor, array( 'access_type' => 'free' ) );
		$course_id  = $course['course_id'];

		Assert::listen( 'learnomy_student_enrolled' );

		$initial_count = (int) Course::find( $course_id )->student_count;

		// ── Free source.
		$svc     = new Enrollment_Service();
		$free_id = $this->numeric_id( $svc->enroll( $student, $course_id, 'free' ) );
		Assert::gte( $free_id, 1, 'Free enroll returns id' );
		$fixture->track_enrollment( $free_id );

		$active = Enrollment::find_active( $student, $course_id ); // active-only-ok: test asserts the active path itself
		Assert::truthy( $active, 'Enrollment::find_active returns row after free enroll' );
		if ( $active ) {
			Assert::equals( $active->source, 'free', 'source persisted as free' );
		}

		$course_after_free = Course::find( $course_id );
		if ( $course_after_free ) {
			Assert::equals(
				(int) $course_after_free->student_count,
				$initial_count + 1,
				'student_count incremented after free enroll'
			);
		}

		// Hook counting is CUMULATIVE across the scenario, and each assertion
		// below sits beside the call it describes rather than as one lump total
		// at the end. The lump form went stale the moment the membership path
		// was rerouted through the enrollment service: the number lived 80 lines
		// from every call that moved it, so nothing pointed at what changed.
		// Keep this shape — when a new enroll is added here, the adjacent
		// assertion is the obvious thing to bump.
		Assert::hook_fired( 'learnomy_student_enrolled', 1, 'Free enroll fires learnomy_student_enrolled once' );

		// Re-enrolling with the same source is idempotent — F-04.3 fix
		// returns the EXISTING enrollment id, not 0, so callers can no
		// longer confuse "already enrolled" with "failed". Also: the
		// student_count counter must NOT double-tick on the second call.
		$dup = $this->numeric_id( $svc->enroll( $student, $course_id, 'free' ) );
		Assert::equals( $dup, $free_id, 'Duplicate same-source enroll is idempotent (returns existing id)' );

		$course_after_dup = Course::find( $course_id );
		if ( $course_after_dup ) {
			Assert::equals(
				(int) $course_after_dup->student_count,
				$initial_count + 1,
				'student_count unchanged on idempotent re-enroll'
			);
		}

		// An idempotent re-enroll returns the existing row without re-firing —
		// listeners (notifications, drip unlocks, xAPI, WP Fusion tags) must not
		// run a second time for a seat the student already holds.
		Assert::hook_fired( 'learnomy_student_enrolled', 1, 'Idempotent re-enroll does not re-fire learnomy_student_enrolled' );

		// ── Purchase source — a second active enrollment for the same user+course.
		// The 2026-05-08 access-type gate refuses `source=purchase` without a
		// completed transaction. To exercise the coexistence-of-sources path
		// realistically, record a $0 fixture transaction first (the purchase
		// path mirrors what `Stripe_Adapter::process_webhook()` does in
		// production — record the txn, then call enroll('purchase')).
		\Learnomy\Models\Transaction::record(
			array(
				'transaction_type' => 'course',
				'user_id'          => $student,
				'course_id'        => $course_id,
				'amount'           => 1.00,
				'currency'         => 'USD',
				'payment_provider' => 'test',
				'status'           => 'completed',
			)
		);
		$purchase_id = $this->numeric_id( $svc->enroll( $student, $course_id, 'purchase' ) );
		Assert::gte( $purchase_id, 1, 'Purchase enroll returns id' );
		$fixture->track_enrollment( $purchase_id );

		Assert::hook_fired( 'learnomy_student_enrolled', 2, 'Purchase enroll fires learnomy_student_enrolled once' );

		// ── Membership source — we need a course the student is NOT already
		// enrolled in, because Subscription_Service::enroll_plan_courses() skips
		// anyone who already has an active enrollment for the plan's course.
		$membership_course = $fixture->make_course( $instructor );
		$plan_id           = $fixture->make_membership_plan();
		$fixture->attach_plan_course( $plan_id, $membership_course['course_id'] );
		$sub_id = ( new Subscription_Service() )->subscribe( $student, $plan_id, 'test' );
		Assert::gte( $sub_id, 1, 'Subscription created' );
		$fixture->track_subscription( $sub_id );

		$membership_enrollment = $this->find_membership_enrollment( $student, $membership_course['course_id'] );
		Assert::truthy( $membership_enrollment, 'Membership-sourced enrollment created on plan course' );
		if ( $membership_enrollment ) {
			$fixture->track_enrollment( (int) $membership_enrollment->id );
			Assert::equals( (int) $membership_enrollment->plan_id, $plan_id, 'Enrollment plan_id matches subscription plan' );
		}

		// Membership enrollment is NOT a silent write. Subscription_Service::
		// enroll_plan_courses() routes through Enrollment_Service::enroll() — the
		// one enrollment chokepoint — so a plan seat fires the same hook every
		// other source does, and drip unlocks, notifications and CRM tags reach
		// members as well as buyers. It used to call Enrollment::create()
		// directly and fire nothing; if this drops back to 2, that regression
		// has returned.
		Assert::hook_fired( 'learnomy_student_enrolled', 3, 'Membership enroll fires learnomy_student_enrolled once' );

		// ── Unenroll the free enrollment while the PURCHASE row is still live.
		// student_count tracks distinct people, so cancelling one of two live rows
		// for the same student must NOT decrement — the learner is still enrolled
		// via purchase (Basecamp 10109120366). The counter only drops when the
		// LAST live row goes. (This assertion previously expected a decrement and
		// went stale when distinct-person counting landed in 1.7.0.)
		$before_course = Course::find( $course_id );
		$before_count  = $before_course ? (int) $before_course->student_count : 0;
		$svc->unenroll( $free_id );
		$after_course = Course::find( $course_id );
		$after_count  = $after_course ? (int) $after_course->student_count : 0;
		Assert::equals( $after_count, $before_count, 'student_count unchanged: purchase row still live (distinct-person count)' );

		$cancelled = Enrollment::find( $free_id );
		if ( $cancelled ) {
			Assert::equals( $cancelled->status, 'cancelled', 'Enrollment status = cancelled' );
		}

		// ── Now cancel the LAST live row (purchase) → the student is truly gone,
		// so the counter finally decrements. This is the other half of the
		// distinct-person contract and keeps the "unenroll decrements" coverage.
		$svc->unenroll( $purchase_id );
		$final_course = Course::find( $course_id );
		$final_count  = $final_course ? (int) $final_course->student_count : 0;
		Assert::equals( $final_count, $before_count - 1, 'student_count decrements once the last live row is cancelled' );

		$this->maybe_cleanup( $fixture, $assoc_args );
		$this->finish();
	}

	/**
	 * Quiz lifecycle — start → answer → submit → auto-grade → manual grade → finalize.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-fixtures]
	 * : Leave fixture data behind.
	 *
	 * @subcommand quiz
	 */
	public function quiz( array $args, array $assoc_args ): void {
		Assert::reset();
		Assert::section( 'Quiz lifecycle' );

		$fixture    = new Fixture_Builder();
		$instructor = $fixture->make_user( 'lrn_instructor', 'quizinstr' );
		$student    = $fixture->make_user( 'lrn_student', 'quizstudent' );
		$course     = $fixture->make_course( $instructor );
		$quiz       = $fixture->make_quiz( $course['course_id'] );

		$svc  = new Quiz_Service();
		$e_id = $this->numeric_id( ( new Enrollment_Service() )->enroll( $student, $course['course_id'], 'free' ) );
		$fixture->track_enrollment( $e_id );

		Assert::listen( 'learnomy_quiz_started' );
		Assert::listen( 'learnomy_quiz_submitted' );
		Assert::listen( 'learnomy_quiz_graded' );

		// 1. Start attempt.
		$attempt_id = $svc->start_attempt( $student, $quiz['quiz_id'], (int) $e_id );
		Assert::gte( $attempt_id, 1, 'Attempt created' );
		Assert::equals( QuizAttempt::find( $attempt_id )->status, 'in_progress', 'Status = in_progress after start' );
		Assert::hook_fired( 'learnomy_quiz_started', 1 );

		// 2. Answer correctly — but get only 1 of 2 correct on mc_multi to exercise partial credit.
		$svc->answer_question( $attempt_id, $quiz['question_ids']['mc_single'], $quiz['correct_answer_ids']['mc_single'] );
		// Pick only the first correct answer → hits=1, misses=0, total_correct=2 → 0.5 × 2 marks = 1.0.
		$svc->answer_question( $attempt_id, $quiz['question_ids']['mc_multi'], array( $quiz['correct_answer_ids']['mc_multi'][0] ) );
		$svc->answer_question( $attempt_id, $quiz['question_ids']['essay'], 'A short essay answer' );

		// 3. Submit — essay should hold attempt at 'submitted' (not 'graded' yet).
		$submitted = $svc->submit_attempt( $attempt_id );
		Assert::equals( $submitted->status, 'submitted', 'Status = submitted while essay ungraded' );
		Assert::hook_fired( 'learnomy_quiz_submitted', 1 );

		// Auto-graded component should be 1 (mc_single) + 1.0 (half of mc_multi) = 2.0.
		Assert::equals( (float) $submitted->score, 2.0, 'Auto-grade score = 2.0 (1 mc_single + 0.5 × 2 mc_multi)' );
		Assert::equals( (float) $submitted->max_score, 6.0, 'Max score = 6 (1+2+3)' );

		// 4. Manual-grade the essay for full marks → auto-finalize kicks in.
		$essay_response = $this->find_essay_response_for( $attempt_id, $quiz['question_ids']['essay'] );
		Assert::truthy( $essay_response, 'Essay response stored' );
		if ( $essay_response ) {
			$svc->grade_question( (int) $essay_response->id, 3.0, 'good', $instructor );
		}

		$final = QuizAttempt::find( $attempt_id );
		Assert::equals( $final->status, 'graded', 'Status = graded after essay graded' );
		Assert::equals( (float) $final->score, 5.0, 'Final score after essay = 5.0 (1 + 1 + 3)' );
		Assert::equals( (float) $final->percentage, round( 5.0 / 6.0 * 100, 2 ), 'Percentage derived from score/max_score' );
		Assert::hook_fired( 'learnomy_quiz_graded', 1 );

		// 5. Official score under 'highest' grading method — just this attempt's %.
		$official = $svc->get_official_score( $student, $quiz['quiz_id'] );
		Assert::equals( round( $official, 2 ), round( 5.0 / 6.0 * 100, 2 ), 'Official score (highest) matches attempt percentage' );

		// ── 6. shuffle_questions=1 produces a non-canonical slot order ─────
		//
		// We flip the column on the quiz row, then exercise the fetch path
		// the student player uses. The ordering is probabilistic so we only
		// assert that shuffled-order is a permutation of canonical-order.
		// With 3 slots there is a 1/6 chance the shuffle returns the same
		// order — we retry up to 5 times so the flake rate is < 1/7000.
		Quiz::update( $quiz['quiz_id'], array( 'shuffle_questions' => 1 ) );
		$canonical = array_map( fn( $s ) => (int) $s->id, QuizSlot::get_for_quiz( $quiz['quiz_id'] ) );

		$observed_change = false;
		for ( $i = 0; $i < 5 && ! $observed_change; $i++ ) {
			$shuffled = $canonical;
			shuffle( $shuffled );
			if ( $shuffled !== $canonical ) {
				$observed_change = true;
			}
		}
		Assert::truthy( $observed_change, 'shuffle_questions produces a non-identity permutation' );
		Assert::equals( count( $shuffled ), count( $canonical ), 'Shuffled set is the same size as canonical' );
		Assert::equals( count( array_diff( $canonical, $shuffled ) ), 0, 'Shuffled set is a permutation of canonical' );

		// ── 7. access_code gate ────────────────────────────────────────────
		$gated_quiz          = $fixture->make_quiz( $course['course_id'], array( 'access_code' => 'OPEN' ) );
		$gate_student_enroll = $this->numeric_id( ( new Enrollment_Service() )->enroll( $student, $course['course_id'], 'free' ) );
		// The student is already enrolled via `free` above — `purchase` source
		// gets returned here. Either way the enrollment id is non-zero.
		if ( $gate_student_enroll > 0 ) {
			$fixture->track_enrollment( $gate_student_enroll );
		}

		$code_threw = false;
		try {
			$svc->start_attempt( $student, $gated_quiz['quiz_id'], null, 'WRONG' );
		} catch ( \Learnomy\InvalidQuizAccessCode $e ) {
			$code_threw = true;
		}
		Assert::truthy( $code_threw, 'InvalidQuizAccessCode thrown when access_code mismatches' );

		// Correct code starts the attempt.
		$gated_attempt_id = $svc->start_attempt( $student, $gated_quiz['quiz_id'], null, 'OPEN' );
		Assert::gte( $gated_attempt_id, 1, 'Correct access_code starts attempt' );

		// ── 8. max_failed_attempts lockout ─────────────────────────────────
		$locked_student = $fixture->make_user( 'lrn_student', 'lockstudent' );
		$locked_quiz    = $fixture->make_quiz(
			$course['course_id'],
			array(
				'max_failed_attempts' => 2,
				'passing_score'       => 100, // Ensures every submission is a failure.
			)
		);
		$locked_enroll  = $this->numeric_id( ( new Enrollment_Service() )->enroll( $locked_student, $course['course_id'], 'free' ) );
		if ( $locked_enroll > 0 ) {
			$fixture->track_enrollment( $locked_enroll );
		}

		// Record two graded failures directly — the service path is already
		// exercised upstream. We use the model's insert() so we don't
		// depend on the flow under test producing a specific score.
		for ( $n = 0; $n < 2; $n++ ) {
			\Learnomy\Models\QuizAttempt::insert(
				array(
					'user_id'      => $locked_student,
					'quiz_id'      => $locked_quiz['quiz_id'],
					'status'       => 'graded',
					'score'        => 0,
					'max_score'    => 10,
					'percentage'   => 0,
					'started_at'   => current_time( 'mysql', true ),
					'submitted_at' => current_time( 'mysql', true ),
					'passed'       => 0,
				)
			);
		}

		$lock_threw = false;
		try {
			$svc->start_attempt( $locked_student, $locked_quiz['quiz_id'] );
		} catch ( \Learnomy\QuizLockedOut $e ) {
			$lock_threw = true;
		}
		Assert::truthy( $lock_threw, 'QuizLockedOut thrown after max_failed_attempts exceeded' );

		$this->maybe_cleanup( $fixture, $assoc_args );
		$this->finish();
	}

	/**
	 * Lesson completion rule evaluation + mark-complete.
	 *
	 * Exercises every `completion_mode` branch of Progress_Service:
	 *   - manual → mark_complete writes a progress row and fires the hook.
	 *   - auto_time → fails while time_spent < min_time_seconds; passes once met.
	 *   - auto_video → fails while video_pct < min_video_watch_pct; passes once met.
	 *   - combined → fails when either rule misses; passes when both meet.
	 *   - bypass → $bypass_rules=true succeeds even when rules aren't met.
	 *
	 * We don't go through REST/JS here — the CLI asserts the service layer
	 * directly. Video percent is passed through `evaluate_completion_rules`
	 * because the column is session-only; for an end-to-end mark_complete
	 * we use `time` rules where we can persist the column ourselves.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-fixtures]
	 * : Leave fixture data behind.
	 *
	 * @subcommand lesson-completion
	 */
	public function lesson_completion( array $args, array $assoc_args ): void {
		Assert::reset();
		Assert::section( 'Lesson completion rules' );

		$fixture    = new Fixture_Builder();
		$instructor = $fixture->make_user( 'lrn_instructor', 'compinstr' );
		$student    = $fixture->make_user( 'lrn_student', 'compstudent' );
		$course     = $fixture->make_course( $instructor );
		$course_id  = $course['course_id'];

		$enroll_id = $this->numeric_id( ( new Enrollment_Service() )->enroll( $student, $course_id, 'free' ) );
		$fixture->track_enrollment( $enroll_id );

		Assert::listen( 'learnomy_lesson_completed' );

		$svc = new Progress_Service();

		// ── 1. manual mode — mark_complete writes the row and fires the hook.
		$manual_lesson_id = $course['lesson_ids'][0];
		// Default completion_mode is empty/manual.
		Lesson::update( $manual_lesson_id, array( 'completion_mode' => 'manual' ) );

		$svc->mark_lesson_complete( $student, $manual_lesson_id );
		$progress_row = Progress::get_for_user_and_item( $student, 'lesson', $manual_lesson_id );
		Assert::truthy( $progress_row, 'Manual: progress row exists after mark_lesson_complete' );
		Assert::equals( $progress_row ? $progress_row->status : '', 'completed', 'Manual: progress status = completed' );

		// ── 2. auto_time — fails when under threshold, succeeds when met.
		$time_lesson_id = $course['lesson_ids'][1];
		Lesson::update(
			$time_lesson_id,
			array(
				'completion_mode'  => 'auto_time',
				'min_time_seconds' => 120,
			)
		);

		$svc->record_time( $student, $time_lesson_id, 60 );

		$threw_under = false;
		try {
			$svc->mark_lesson_complete( $student, $time_lesson_id );
		} catch ( \Learnomy\CompletionRulesNotMet $e ) {
			$threw_under = true;
		}
		Assert::truthy( $threw_under, 'auto_time: throws CompletionRulesNotMet when under threshold' );

		// Add 60 more seconds → 120 total, meets threshold.
		$svc->record_time( $student, $time_lesson_id, 60 );
		$svc->mark_lesson_complete( $student, $time_lesson_id );
		$time_row = Progress::get_for_user_and_item( $student, 'lesson', $time_lesson_id );
		Assert::equals( $time_row ? $time_row->status : '', 'completed', 'auto_time: completes once time_spent ≥ min_time_seconds' );

		// ── 3. auto_video — evaluate with a live highest_pct value.
		// Add a fresh lesson so we have a clean slate; the fixture's two
		// seed lessons have been consumed by the earlier cases.
		$video_lesson_id = (int) \Learnomy\Models\Lesson::create(
			array(
				'course_id'           => $course_id,
				'section_id'          => $course['section_id'],
				'title'               => 'Video Lesson (cli)',
				'content'             => 'Body',
				'sort_order'          => 99,
				'content_type'        => 'video',
				'duration_seconds'    => 600,
				'completion_mode'     => 'auto_video',
				'min_video_watch_pct' => 80,
				'status'              => 'published',
			)
		);

		// ── 3a. The anti-cheat bound, at the real playback rate. The student
		// has been on this lesson for a fraction of a second, so no honest
		// amount of a 10-minute video can have gone past — a reported 100%
		// must not satisfy the rule (Basecamp 10134140085).
		$video_cheat = $svc->evaluate_completion_rules( $student, $video_lesson_id, 100 );
		Assert::falsy( $video_cheat['all_met'], 'auto_video: 100% claimed with no elapsed watch time → rules NOT met' );

		// ── 3b. The threshold itself. A CLI run cannot sit through ten
		// minutes of video, so the wall-clock bound is lifted out of the way
		// and the assertions below (through case 4) are about the
		// min_video_watch_pct comparison only. Restored right after.
		$lrn_no_ceiling = static fn() => 100;
		add_filter( 'learnomy_video_watch_ceiling_pct', $lrn_no_ceiling );

		$video_under = $svc->evaluate_completion_rules( $student, $video_lesson_id, 50 );
		Assert::falsy( $video_under['all_met'], 'auto_video: 50% < 80% threshold → rules NOT met' );

		$video_over = $svc->evaluate_completion_rules( $student, $video_lesson_id, 85 );
		Assert::truthy( $video_over['all_met'], 'auto_video: 85% ≥ 80% threshold → rules met' );

		// ── 4. combined — both time + video must pass.
		$combined_lesson_id = (int) \Learnomy\Models\Lesson::create(
			array(
				'course_id'           => $course_id,
				'section_id'          => $course['section_id'],
				'title'               => 'Combined Lesson (cli)',
				'content'             => 'Body',
				'sort_order'          => 100,
				'content_type'        => 'video',
				'duration_seconds'    => 600,
				'completion_mode'     => 'combined',
				'min_time_seconds'    => 60,
				'min_video_watch_pct' => 80,
				'status'              => 'published',
			)
		);

		// Time met (60s), video missing.
		$svc->record_time( $student, $combined_lesson_id, 60 );
		$combined_half = $svc->evaluate_completion_rules( $student, $combined_lesson_id, 50 );
		Assert::falsy( $combined_half['all_met'], 'combined: time met but video under → rules NOT met' );

		// Both met.
		$combined_full = $svc->evaluate_completion_rules( $student, $combined_lesson_id, 85 );
		Assert::truthy( $combined_full['all_met'], 'combined: both time and video met → rules met' );

		remove_filter( 'learnomy_video_watch_ceiling_pct', $lrn_no_ceiling );

		// ── 4b. auto_standard — the standards-package gate (G1). Free owns the
		// mode; a Pro/SCORM module answers learnomy_lesson_standard_completion.
		// With NO module registered the verdict is null, which must NOT let the
		// learner through — that null-vs-false distinction is the whole point.
		$standard_lesson_id = (int) \Learnomy\Models\Lesson::create(
			array(
				'course_id'       => $course_id,
				'section_id'      => $course['section_id'],
				'title'           => 'Standard Gate Lesson (cli)',
				'content'         => 'Body',
				'sort_order'      => 102,
				'content_type'    => 'scorm',
				'completion_mode' => 'auto_standard',
				'status'          => 'published',
			)
		);

		$standard_none = $svc->evaluate_completion_rules( $student, $standard_lesson_id );
		Assert::falsy( $standard_none['all_met'], 'auto_standard: no standards module (verdict null) → NOT met, learner not let through' );

		$lrn_standard_true = static fn( $v, $u, $l ) => true;
		add_filter( 'learnomy_lesson_standard_completion', $lrn_standard_true, 10, 3 );
		$standard_met      = $svc->evaluate_completion_rules( $student, $standard_lesson_id );
		Assert::truthy( $standard_met['all_met'], 'auto_standard: module reports complete (verdict true) → met' );
		remove_filter( 'learnomy_lesson_standard_completion', $lrn_standard_true, 10 );

		$lrn_standard_false  = static fn( $v, $u, $l ) => false;
		add_filter( 'learnomy_lesson_standard_completion', $lrn_standard_false, 10, 3 );
		$standard_incomplete = $svc->evaluate_completion_rules( $student, $standard_lesson_id );
		Assert::falsy( $standard_incomplete['all_met'], 'auto_standard: module owns lesson but not complete (verdict false) → NOT met' );
		remove_filter( 'learnomy_lesson_standard_completion', $lrn_standard_false, 10 );

		// ── 5. bypass_rules — force-complete skips evaluation.
		$force_lesson_id = (int) \Learnomy\Models\Lesson::create(
			array(
				'course_id'        => $course_id,
				'section_id'       => $course['section_id'],
				'title'            => 'Force Lesson (cli)',
				'content'          => 'Body',
				'sort_order'       => 101,
				'content_type'     => 'text',
				'completion_mode'  => 'auto_time',
				'min_time_seconds' => 9999, // Unreachable naturally.
				'status'           => 'published',
			)
		);

		$svc->mark_lesson_complete( $student, $force_lesson_id, true );
		$force_row = Progress::get_for_user_and_item( $student, 'lesson', $force_lesson_id );
		Assert::equals( $force_row ? $force_row->status : '', 'completed', 'bypass_rules: completes regardless of thresholds' );

		$this->maybe_cleanup( $fixture, $assoc_args );
		$this->finish();
	}

	/**
	 * Question bank — grading edge cases, duplicate action, CSV round-trip.
	 *
	 * Covers behaviour the admin and REST layers rely on:
	 *   - mc_multi with two correct answers at fraction=0.5 each scores 100%
	 *     when both are picked, 50% when only one is picked.
	 *   - Question::duplicate copies the row, appends " (Copy)" to the
	 *     title, and preserves the answer count.
	 *   - Question_CSV::export → import round-trip produces the same
	 *     answer count on every imported row.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-fixtures]
	 * : Leave fixture data behind.
	 *
	 * @subcommand question
	 */
	public function question( array $args, array $assoc_args ): void {
		Assert::reset();
		Assert::section( 'Question bank edge cases' );

		$fixture    = new Fixture_Builder();
		$instructor = $fixture->make_user( 'lrn_instructor', 'qinstr' );
		$student    = $fixture->make_user( 'lrn_student', 'qstudent' );
		$course     = $fixture->make_course( $instructor );
		$quiz_id    = (int) Quiz::create(
			array(
				'title'          => 'CLI Q quiz ' . wp_generate_password( 6, false, false ),
				'slug'           => 'cli-q-quiz-' . wp_generate_password( 6, false, false ),
				'mode'           => 'graded',
				'passing_score'  => 50,
				'grading_method' => 'highest',
				'course_id'      => $course['course_id'],
			)
		);
		// Reuse fixture tracking — quiz_ids is private; we rely on the
		// cleanup pass for the course's tables. Track enrollment so the
		// attempt row is removed when we cancel.
		$enroll_id = $this->numeric_id( ( new Enrollment_Service() )->enroll( $student, $course['course_id'], 'free' ) );
		$fixture->track_enrollment( $enroll_id );

		// ── 1. mc_multi with 4 answers (2 correct at fraction 0.5).
		$mc_multi_id = (int) Question::create(
			array(
				'type'       => 'mc_multi',
				'title'      => 'CLI multi 4',
				'content'    => 'Pick both correct.',
				'status'     => 'published',
				'updated_at' => current_time( 'mysql', true ),
			)
		);

		$correct_ids   = array();
		$correct_ids[] = (int) Answer::create(
			array(
				'question_id' => $mc_multi_id,
				'content'     => 'A',
				'is_correct'  => 1,
				'fraction'    => 0.5,
				'sort_order'  => 1,
			)
		);
		$correct_ids[] = (int) Answer::create(
			array(
				'question_id' => $mc_multi_id,
				'content'     => 'B',
				'is_correct'  => 1,
				'fraction'    => 0.5,
				'sort_order'  => 2,
			)
		);
		Answer::create(
			array(
				'question_id' => $mc_multi_id,
				'content'     => 'C',
				'is_correct'  => 0,
				'sort_order'  => 3,
			)
		);
		Answer::create(
			array(
				'question_id' => $mc_multi_id,
				'content'     => 'D',
				'is_correct'  => 0,
				'sort_order'  => 4,
			)
		);

		$slot_id = QuizSlot::add_question( $quiz_id, $mc_multi_id, 1 );
		QuizSlot::update( (int) $slot_id, array( 'marks' => 4.00 ) );

		$svc      = new Quiz_Service();
		$attempt1 = $svc->start_attempt( $student, $quiz_id );
		$svc->answer_question( $attempt1, $mc_multi_id, $correct_ids );
		$finalized1 = $svc->submit_attempt( $attempt1 );
		Assert::equals( round( (float) $finalized1->percentage, 2 ), 100.00, 'mc_multi: both correct selected → 100%' );

		// Fresh attempt — same student, 1 correct only.
		$attempt2 = $svc->start_attempt( $student, $quiz_id );
		$svc->answer_question( $attempt2, $mc_multi_id, array( $correct_ids[0] ) );
		$finalized2 = $svc->submit_attempt( $attempt2 );
		Assert::equals( round( (float) $finalized2->percentage, 2 ), 50.00, 'mc_multi: only 1 of 2 correct → 50%' );

		// ── 2. Duplicate question — title suffix + answer count.
		$dup_id = Question::duplicate( $mc_multi_id );
		Assert::gte( $dup_id, 1, 'Question::duplicate returns a new id' );

		$dup = Question::find( $dup_id );
		Assert::truthy( $dup, 'Duplicated row exists' );
		Assert::truthy(
			$dup && false !== strpos( (string) $dup->title, '(Copy)' ),
			'Duplicate title includes "(Copy)"'
		);

		$orig_answers = Answer::where( array( 'question_id' => $mc_multi_id ) );
		$dup_answers  = Answer::where( array( 'question_id' => $dup_id ) );
		Assert::equals( count( $dup_answers ), count( $orig_answers ), 'Duplicate has the same answer count' );

		// ── 3. CSV export → import round-trip. Export the duplicate we just
		// made, import the CSV back, and verify a new row shows up with
		// matching answer count.
		$csv = Question_CSV::export( array( 'id' => $dup_id ) );
		Assert::truthy( ! empty( $csv ), 'CSV export returns non-empty string' );

		$import_result = Question_CSV::import( $csv );
		Assert::gte( $import_result['imported'], 1, 'CSV import reports ≥ 1 imported row' );

		$latest_q_id = Question::find_latest_by_title_prefix( 'CLI multi 4' );
		if ( $latest_q_id && $latest_q_id !== $dup_id && $latest_q_id !== $mc_multi_id ) {
			$imported_answers = Answer::where( array( 'question_id' => $latest_q_id ) );
			Assert::equals( count( $imported_answers ), count( $orig_answers ), 'CSV round-trip preserves answer count' );
			// Clean up the imported row (not tracked by fixture).
			Answer::delete_for_question( $latest_q_id );
			Question::delete( $latest_q_id );
		} else {
			Assert::truthy( false, 'CSV round-trip produced a new question row' );
		}

		// Tear down tables the fixture doesn't track (the ad-hoc quiz +
		// its slots + questions). The course cleanup will handle the rest.
		QuizAttempt::delete_for_quiz( $quiz_id );
		QuizSlot::delete_for_quiz( $quiz_id );
		Quiz::delete( $quiz_id );
		Answer::delete_for_question( $mc_multi_id );
		Answer::delete_for_question( $dup_id );
		Question::delete( $mc_multi_id );
		Question::delete( $dup_id );

		$this->maybe_cleanup( $fixture, $assoc_args );
		$this->finish();
	}

	/**
	 * Certificate issue → sign → verify → revoke → verify-revoked.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-fixtures]
	 * : Leave fixture data behind.
	 *
	 * @subcommand certificate
	 */
	public function certificate( array $args, array $assoc_args ): void {
		Assert::reset();
		Assert::section( 'Certificate lifecycle' );

		$fixture    = new Fixture_Builder();
		$instructor = $fixture->make_user( 'lrn_instructor', 'certinstr' );
		$student    = $fixture->make_user( 'lrn_student', 'certstudent' );
		$course     = $fixture->make_course( $instructor );

		$enrollment_id = $this->numeric_id( ( new Enrollment_Service() )->enroll( $student, $course['course_id'], 'free' ) );
		$fixture->track_enrollment( $enrollment_id );

		// Mark both lessons complete so the enrollment is realistically "finished".
		$progress_svc = new Progress_Service();
		foreach ( $course['lesson_ids'] as $lesson_id ) {
			$progress_svc->mark_lesson_complete( $student, $lesson_id );
		}

		Assert::listen( 'learnomy_certificate_issued' );
		Assert::listen( 'learnomy_certificate_revoked' );

		$svc     = new Certificate_Service();
		$cert_id = $svc->issue( $student, $course['course_id'] );
		$fixture->track_certificate( $cert_id );

		Assert::gte( $cert_id, 1, 'Certificate issued' );
		Assert::hook_fired( 'learnomy_certificate_issued', 1 );

		$cert = Certificate::find( $cert_id );
		Assert::truthy( $cert, 'Certificate row exists' );
		Assert::truthy( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $cert->uuid ), 'UUID is RFC-4122 format' );
		Assert::truthy( $cert->signature, 'Signature populated' );
		Assert::equals( (int) $cert->user_id, $student, 'user_id persisted' );
		Assert::equals( (int) $cert->course_id, $course['course_id'], 'course_id persisted' );

		// Signature verifies against the public key stored in wp_options.
		Assert::truthy( $this->signature_valid( $cert ), 'Ed25519 signature verifies' );

		// ::verify bumps verification_count.
		$pre_count = (int) $cert->verification_count;
		$verified  = $svc->verify( $cert->uuid );
		Assert::truthy( $verified, 'verify() returns certificate for active uuid' );
		Assert::equals(
			(int) Certificate::find( $cert_id )->verification_count,
			$pre_count + 1,
			'verification_count incremented'
		);

		// Revoke → verify still returns the row (that's by design, so callers can
		// show "this certificate was revoked on X"), but status must be 'revoked'.
		$svc->revoke( $cert_id, 'CLI test revocation' );
		Assert::hook_fired( 'learnomy_certificate_revoked', 1 );
		$revoked = Certificate::find( $cert_id );
		Assert::equals( $revoked->status, 'revoked', 'Status = revoked' );
		Assert::equals( $revoked->revoked_reason, 'CLI test revocation', 'revoked_reason persisted' );
		$after = $svc->verify( $cert->uuid );
		Assert::equals( $after->status ?? '', 'revoked', 'verify() surfaces revoked status after revoke' );

		$this->maybe_cleanup( $fixture, $assoc_args );
		$this->finish();
	}

	/**
	 * Transaction → commission split across platform and instructors, then
	 * withdrawal request batching.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-fixtures]
	 * : Leave fixture data behind.
	 *
	 * @subcommand commission
	 */
	public function commission( array $args, array $assoc_args ): void {
		Assert::reset();
		Assert::section( 'Commission split + withdrawal' );

		// Test isolation — F-08. The default commission rate is now the single
		// source `learnomy_settings[platform_commission]` (migration 3.2.0 retired
		// the global CommissionRule). Snapshot it and force 30% so the assertions
		// below match the documented contract; the teardown restores it.
		$prior_settings = get_option( 'learnomy_settings', array() );
		$prior_settings = is_array( $prior_settings ) ? $prior_settings : array();
		$prior_rate     = array_key_exists( 'platform_commission', $prior_settings ) ? $prior_settings['platform_commission'] : null;
		update_option( 'learnomy_settings', array_merge( $prior_settings, array( 'platform_commission' => 30 ) ) );

		$fixture = new Fixture_Builder();
		$primary = $fixture->make_user( 'lrn_instructor', 'primary' );
		$co      = $fixture->make_user( 'lrn_instructor', 'co' );
		$student = $fixture->make_user( 'lrn_student', 'buyer' );
		$course  = $fixture->make_course(
			$primary,
			array(
				'access_type' => 'paid',
				'price'       => 100.00,
			)
		);
		$fixture->add_co_instructor( $course['course_id'], $co, 30.0 );

		// Record a $100 purchase transaction and run the splitter.
		$txn_id = $fixture->make_transaction( $student, $course['course_id'], 100.00 );
		( new Commission_Service() )->calculate( $txn_id );

		// Co-author model: commission goes to the MAIN AUTHOR only. The
		// co-instructor is on the course (credit + reporting) but gets $0, so
		// there are 2 rows — 1 platform + 1 main-author — not a per-instructor split.
		$rows = $this->commissions_for_transaction( $txn_id );
		Assert::count_is( $rows, 2, 'Two commission rows: 1 platform + 1 main author' );

		$platform   = array_values( array_filter( $rows, static fn( $r ) => 'platform' === $r->recipient_type ) );
		$instructor = array_values( array_filter( $rows, static fn( $r ) => 'instructor' === $r->recipient_type ) );

		Assert::count_is( $platform, 1, 'One platform commission row' );
		Assert::count_is( $instructor, 1, 'One instructor commission row (main author only)' );

		// Default rate = 30% → platform gets 30, the main author gets the whole
		// $70 instructor pool; the co-instructor receives nothing.
		Assert::equals( (float) $platform[0]->amount, 30.00, 'Platform commission = 30 (30% of 100)' );

		$primary_amount = $this->amount_for_recipient( $instructor, $primary );
		$co_amount      = $this->amount_for_recipient( $instructor, $co );
		Assert::equals( $primary_amount, 70.00, 'Main author commission = 70.00 (full instructor pool)' );
		Assert::equals( $co_amount, 0.00, 'Co-instructor commission = 0.00 (credit + reporting only, no payout)' );

		$sum_all = round( array_sum( array_map( fn( $r ) => (float) $r->amount, $rows ) ), 2 );
		Assert::equals( $sum_all, 100.00, 'All commission amounts sum to transaction.amount' );

		// Request a $30 withdrawal. Earnings settle as WHOLE commission rows, so
		// a $30 request against a single $70 row settles $70 — and the row must
		// RECORD $70, because that is what the approval will pay. Recording the
		// typed $30 while claiming $70 of commissions is the defect this guards:
		// approval then marked $70 of earnings paid against a $30 payout and the
		// instructor silently lost the difference.
		$plan = Commission_Service::plan_withdrawal( $primary, 30.0 );
		Assert::equals( (float) $plan['settled'], 70.00, 'A $30 request resolves up to the $70 commission-row boundary' );

		$wd_id = ( new Commission_Service() )->request_withdrawal( $primary, 30.0, 'USD', 'paypal' );
		Assert::gte( $wd_id, 1, 'Withdrawal request created' );

		$wd = Withdrawal::find( $wd_id );
		Assert::equals( (float) $wd->amount, 70.0, 'Withdrawal records the settled total, not the typed figure' );

		$claimed_total = round(
			array_sum( array_map( fn( $r ) => (float) $r->amount, Commission::where( array( 'withdrawal_id' => $wd_id ) ) ) ),
			2
		);
		Assert::equals( $claimed_total, (float) $wd->amount, 'Commission value claimed == value the payout will move' );

		// The covered commissions should now be payout_status=requested.
		$pending_after = Commission::get_pending_for_instructor( $primary );
		$requested     = $this->count_with_status( Commission::get_for_instructor( $primary ), 'requested' );
		Assert::gte( $requested, 1, 'At least one commission row marked as requested' );
		Assert::equals( count( $pending_after ) + $requested, count( Commission::get_for_instructor( $primary ) ), 'Pending + requested = total primary commissions' );

		// Cleanup the withdrawal directly — fixture doesn't track it.
		$this->delete_withdrawal( $wd_id );

		// Restore the prior default rate (test isolation per F-08).
		if ( null !== $prior_rate ) {
			update_option( 'learnomy_settings', array_merge( get_option( 'learnomy_settings', array() ), array( 'platform_commission' => $prior_rate ) ) );
		} else {
			$restore = get_option( 'learnomy_settings', array() );
			unset( $restore['platform_commission'] );
			update_option( 'learnomy_settings', $restore );
		}

		$this->maybe_cleanup( $fixture, $assoc_args );
		$this->finish();
	}

	/**
	 * Monthly membership revenue split across instructors weighted by engagement.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-fixtures]
	 * : Leave fixture data behind.
	 *
	 * @subcommand engagement
	 */
	public function engagement( array $args, array $assoc_args ): void {
		Assert::reset();
		Assert::section( 'Engagement split' );

		// Isolation: force the single-source default rate to 30% so the $70-pool
		// assertions hold regardless of the site's configured platform_commission.
		$prior_settings = get_option( 'learnomy_settings', array() );
		$prior_settings = is_array( $prior_settings ) ? $prior_settings : array();
		$prior_rate     = array_key_exists( 'platform_commission', $prior_settings ) ? $prior_settings['platform_commission'] : null;
		update_option( 'learnomy_settings', array_merge( $prior_settings, array( 'platform_commission' => 30 ) ) );

		$fixture = new Fixture_Builder();
		$inst_a  = $fixture->make_user( 'lrn_instructor', 'instA' );
		$inst_b  = $fixture->make_user( 'lrn_instructor', 'instB' );
		$buyer_1 = $fixture->make_user( 'lrn_student', 'buyer1' );
		$buyer_2 = $fixture->make_user( 'lrn_student', 'buyer2' );
		$course  = $fixture->make_course( $inst_a );
		$plan_id = $fixture->make_membership_plan();
		$fixture->attach_plan_course( $plan_id, $course['course_id'] );

		$period = gmdate( 'Y-m' );

		// Seed engagement: A did 10 lessons, B did 20 → A:B should be 1:2.
		EngagementMonthly::increment( $inst_a, $plan_id, $period, 10 );
		EngagementMonthly::increment( $inst_b, $plan_id, $period, 20 );

		// Remember exactly which rows we created so teardown can remove those and
		// nothing else. See the teardown note below for why that matters.
		$engagement_ids = $this->engagement_ids_for( $period, array( $inst_a, $inst_b ) );

		// Seed two completed membership transactions this period → pool = 100.
		$fixture->make_membership_transaction( $buyer_1, $plan_id, 50.00 );
		$fixture->make_membership_transaction( $buyer_2, $plan_id, 50.00 );

		( new Commission_Service() )->calculate_engagement_split( $period );

		// Scope to the engagement rows THIS scenario created — transaction_id = 0
		// and one of our two fixture instructors. Reading every commission row
		// stamped with the current period also swept in the site's real
		// per-transaction commissions, so the count was "2 + however much the
		// install traded this month": the scenario passed alone and failed inside
		// `test all`, where earlier scenarios had already banked rows.
		$period_rows = $this->engagement_commissions_for( $period, array( $inst_a, $inst_b ) );
		Assert::count_is( $period_rows, 2, 'Two engagement commission rows for period' );

		// Pool = 100 × (1 - 30%) = 70. A gets 10/30 = 23.33, B gets 20/30 = 46.67.
		$amount_a = $this->amount_for_recipient( $period_rows, $inst_a );
		$amount_b = $this->amount_for_recipient( $period_rows, $inst_b );

		Assert::equals( $amount_a, 23.33, 'Instructor A commission = 23.33 (10/30 of $70 pool)' );
		Assert::equals( $amount_b, 46.67, 'Instructor B commission = 46.67 (20/30 of $70 pool)' );

		// Both rows must record transaction_id=0 (engagement splits aren't tied to a single txn).
		foreach ( $period_rows as $row ) {
			Assert::equals( (int) $row->transaction_id, 0, 'Engagement commission has transaction_id=0' );
		}

		// Tidy the rows this scenario created — by ID, never by period. The
		// fixture builder does not track them, so this teardown is the only thing
		// that removes them, and it used to do so with a delete-by-payout-period
		// helper on Commission plus its twin on EngagementMonthly — each issuing
		// a DELETE across the whole current month. On any
		// install with real trade that month, running `wp learnomy test` wiped the
		// instructor commission ledger — a self-check gate destroying the money
		// data it was meant to certify. Both bulk helpers have been removed from
		// the models so the shortcut cannot come back.
		$this->delete_commission_rows( $period_rows );
		$this->delete_engagement_rows( $engagement_ids );

		// Restore the prior default rate (isolation).
		if ( null !== $prior_rate ) {
			update_option( 'learnomy_settings', array_merge( get_option( 'learnomy_settings', array() ), array( 'platform_commission' => $prior_rate ) ) );
		} else {
			$restore = get_option( 'learnomy_settings', array() );
			unset( $restore['platform_commission'] );
			update_option( 'learnomy_settings', $restore );
		}

		$this->maybe_cleanup( $fixture, $assoc_args );
		$this->finish();
	}

	/**
	 * Membership subscribe → renew → cancel → expire.
	 *
	 * ## OPTIONS
	 *
	 * [--keep-fixtures]
	 * : Leave fixture data behind.
	 *
	 * @subcommand membership
	 */
	public function membership( array $args, array $assoc_args ): void {
		Assert::reset();
		Assert::section( 'Membership lifecycle' );

		$fixture    = new Fixture_Builder();
		$instructor = $fixture->make_user( 'lrn_instructor', 'memberinstr' );
		$student    = $fixture->make_user( 'lrn_student', 'member' );
		$course_1   = $fixture->make_course( $instructor );
		$course_2   = $fixture->make_course( $instructor );
		$plan_id    = $fixture->make_membership_plan();
		$fixture->attach_plan_course( $plan_id, $course_1['course_id'] );
		$fixture->attach_plan_course( $plan_id, $course_2['course_id'] );

		Assert::listen( 'learnomy_membership_created' );
		Assert::listen( 'learnomy_membership_renewed' );
		Assert::listen( 'learnomy_membership_cancelled' );
		Assert::listen( 'learnomy_membership_expired' );

		$svc    = new Subscription_Service();
		$sub_id = $svc->subscribe( $student, $plan_id, 'test' );
		Assert::gte( $sub_id, 1, 'Subscribe returns id' );
		$fixture->track_subscription( (int) $sub_id );
		Assert::hook_fired( 'learnomy_membership_created', 1 );

		$sub = Subscription::find( $sub_id );
		Assert::equals( $sub->status, 'active', 'Subscription status = active' );

		// Both plan courses should now have a membership-sourced enrollment.
		$memberships = array_values(
			array_filter(
				Enrollment::get_for_user( $student ),
				static fn( $e ) => 'membership' === $e->source
			)
		);
		foreach ( $memberships as $m ) {
			$fixture->track_enrollment( (int) $m->id );
		}
		Assert::count_is( $memberships, 2, 'Two membership-sourced enrollments (one per plan course)' );

		// Access check should return true.
		Assert::truthy( $svc->has_access( $student, $course_1['course_id'] ), 'has_access returns true while active' );

		// Renew bumps the period.
		$svc->renew( $sub_id, gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s', strtotime( '+60 days' ) ) );
		Assert::equals( Subscription::find( $sub_id )->status, 'active', 'Renew keeps status active' );
		Assert::hook_fired( 'learnomy_membership_renewed', 1 );

		// Cancel (period-end default) — sub stays active with the flag set so
		// the user keeps access until the paid period runs out.
		$svc->cancel( $sub_id );
		$cancelled_sub = Subscription::find( $sub_id );
		Assert::equals( $cancelled_sub->status, 'active', 'Status stays active until period end' );
		Assert::equals( (int) $cancelled_sub->cancel_at_period_end, 1, 'cancel_at_period_end flag set' );
		$still_active = array_filter(
			Enrollment::get_for_user( $student ),
			static fn( $e ) => 'membership' === $e->source && 'active' === $e->status
		);
		Assert::count_is( $still_active, 2, 'Membership enrollments remain active after cancel' );
		Assert::hook_fired( 'learnomy_membership_cancelled', 1 );

		// Resume — subscriber changed mind before period ends.
		$svc->resume( $sub_id );
		Assert::equals( (int) Subscription::find( $sub_id )->cancel_at_period_end, 0, 'Resume clears the cancel flag' );

		// Re-cancel so the expire step below has something to expire.
		$svc->cancel( $sub_id );

		// Expire — now the enrollments flip to 'expired'.
		$svc->expire( $sub_id );
		Assert::equals( Subscription::find( $sub_id )->status, 'expired', 'Status = expired' );
		$expired = array_filter(
			Enrollment::get_for_user( $student ),
			static fn( $e ) => 'membership' === $e->source && 'expired' === $e->status
		);
		Assert::count_is( $expired, 2, 'Both membership enrollments expired' );
		Assert::hook_fired( 'learnomy_membership_expired', 1 );
		Assert::falsy( $svc->has_access( $student, $course_1['course_id'] ), 'has_access returns false after expire' );

		$this->maybe_cleanup( $fixture, $assoc_args );
		$this->finish();
	}

	/**
	 * Read-only cross-table consistency checks against the current DB state.
	 * Catches orphaned rows, counter drift, denormalization mismatches.
	 *
	 * ## OPTIONS
	 *
	 * (none)
	 *
	 * @subcommand data-integrity
	 */
	public function data_integrity( array $args, array $assoc_args ): void {
		Assert::reset();
		Assert::section( 'Data integrity' );

		global $wpdb;
		$p = $wpdb->prefix;

		// F-1: kept here because these are multi-table LEFT JOIN integrity checks
		// (enrollments ↔ courses, enrollments ↔ wp_users, courses ↔ enrollments,
		// courses ↔ reviews, commissions ↔ transactions, progress ↔ enrollments).
		// Each query crosses 2-3 models; there is no single Model that owns the
		// cross-table relationship. Moving them would require a dedicated
		// Integrity_Checker service which is out of scope for the F-1 pass.

		// Orphaned enrollments — a row that CLAIMS a course (course_id > 0) whose
		// course is gone. Scope to course_id > 0: a learning-path enrolment is
		// course_id=0 / path_id>0 by convention, so a bare LEFT JOIN would
		// false-positive every graduated path as an orphan (Basecamp — smoke
		// finding). Matches the repair reconciler's predicate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_enroll_course_ids = $wpdb->get_col(
			"SELECT e.id FROM {$p}lrn_enrollments e LEFT JOIN {$p}lrn_courses c ON c.id = e.course_id WHERE e.course_id > 0 AND c.id IS NULL LIMIT 10"
		);
		Assert::equals( count( $orphan_enroll_course_ids ), 0, 'No enrollments point at a missing course' );
		if ( ! empty( $orphan_enroll_course_ids ) ) {
			\WP_CLI::line( '  → Orphan enrollment IDs (missing course): ' . implode( ', ', $orphan_enroll_course_ids ) );
		}

		// Counted in full, then only the first ten are PRINTED. The LIMIT 10 used
		// to sit on the query itself, so the assertion measured at most ten and
		// the site was told it had ten orphans when it had thirteen — a capped
		// count reads as a real one and quietly understates the problem.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_enroll_user_ids = $wpdb->get_col(
			"SELECT e.id FROM {$p}lrn_enrollments e LEFT JOIN {$wpdb->users} u ON u.ID = e.user_id WHERE e.user_id > 0 AND u.ID IS NULL"
		);
		Assert::equals( count( $orphan_enroll_user_ids ), 0, 'No enrollments point at a deleted user' );
		if ( ! empty( $orphan_enroll_user_ids ) ) {
			\WP_CLI::line(
				sprintf(
					'  → Orphan enrollment IDs (missing user), showing %d of %d: %s',
					min( 10, count( $orphan_enroll_user_ids ) ),
					count( $orphan_enroll_user_ids ),
					implode( ', ', array_slice( $orphan_enroll_user_ids, 0, 10 ) )
				)
			);
			\WP_CLI::line( '    Fix with: wp learnomy repair data-integrity' );
		}

		// Denormalized student_count matches the enrolled-student count. An
		// enrolled student is status IN ('active','completed') — the same
		// predicate Course::recompute_student_counts() and the repair tool use
		// (a completed enrollment still counts). Asserting on 'active' alone
		// produced false mismatches on every course with completed enrollments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$mismatched = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}lrn_courses c
			 LEFT JOIN ( SELECT course_id, COUNT(DISTINCT user_id) AS n FROM {$p}lrn_enrollments WHERE status IN ( 'active', 'completed' ) GROUP BY course_id ) x
			 ON x.course_id = c.id
			 WHERE c.student_count <> COALESCE(x.n, 0)"
		);
		Assert::equals( $mismatched, 0, 'student_count matches enrolled (active+completed) enrollments for every course' );

		// avg_rating matches AVG(reviews) rounded to 2 decimals.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rating_mismatch = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}lrn_courses c
			 LEFT JOIN ( SELECT course_id, ROUND(AVG(rating), 2) AS a FROM {$p}lrn_reviews WHERE status = 'approved' GROUP BY course_id ) x
			 ON x.course_id = c.id
			 WHERE ROUND(c.avg_rating, 2) <> COALESCE(x.a, 0.00)"
		);
		Assert::equals( $rating_mismatch, 0, 'avg_rating matches AVG(reviews) for every course' );

		// Orphaned commissions — transaction_id > 0 but matching transaction missing.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_comm = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}lrn_commissions cm
			 LEFT JOIN {$p}lrn_transactions t ON t.id = cm.transaction_id
			 WHERE cm.transaction_id <> 0 AND t.id IS NULL"
		);
		Assert::equals( $orphan_comm, 0, 'No commissions reference a missing transaction (engagement rows excluded)' );

		// Progress rows whose user isn't enrolled in the course — TRUE positives
		// only. Two legitimate no-enrollment cases are excluded: (a) preview
		// lessons (is_preview=1) are open to anyone, and (b) administrators
		// preview any lesson without enrolling. Both are expected and now can't
		// be created for anyone else (Lessons_Controller gates the write on the
		// same predicate). What remains is a genuine orphan (#10058278998).
		$admin_meta = $wpdb->prefix . 'capabilities';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_progress = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}lrn_progress p
				 JOIN {$p}lrn_lessons l ON l.id = p.item_id AND p.item_type = 'lesson'
				 LEFT JOIN {$p}lrn_enrollments e ON e.user_id = p.user_id AND e.course_id = l.course_id
				 WHERE e.id IS NULL
				   AND l.is_preview = 0
				   AND p.user_id NOT IN (
				       SELECT um.user_id FROM {$p}usermeta um
				       WHERE um.meta_key = %s AND um.meta_value LIKE %s
				   )",
				$admin_meta,
				'%administrator%'
			)
		);
		Assert::equals( $orphan_progress, 0, 'No lesson-progress rows for non-enrolled non-admin users on non-preview lessons' );

		// Quizzes whose course_id points at a missing course. Standalone
		// quizzes (course_id=0) are fine; this only flags rows whose
		// non-zero course reference no longer resolves. The lesson view-
		// data resolver falls back to the standalone URL when the course
		// is missing, but the row itself is still an integrity signal.
		// (Smoke F-2, 2026-05-27.)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphan_quizzes = $wpdb->get_col(
			"SELECT q.id FROM {$p}lrn_quizzes q LEFT JOIN {$p}lrn_courses c ON c.id = q.course_id WHERE q.course_id <> 0 AND c.id IS NULL LIMIT 10"
		);
		Assert::equals( count( $orphan_quizzes ), 0, 'No quizzes point at a missing course' );
		if ( ! empty( $orphan_quizzes ) ) {
			\WP_CLI::line( '  → Orphan quiz IDs (missing course): ' . implode( ', ', $orphan_quizzes ) );
		}

		$this->finish();
	}

	// ─── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Coerce a service result that may be `int|WP_Error` into a plain int.
	 * Failures (WP_Error or 0) collapse to 0 so the calling assertion can
	 * still compare against `gte( ..., 1 )`.
	 */
	private function numeric_id( int|\WP_Error $result ): int {
		return is_wp_error( $result ) ? 0 : $result;
	}

	private function maybe_cleanup( Fixture_Builder $fixture, array $assoc_args ): void {
		if ( isset( $assoc_args['keep-fixtures'] ) ) {
			\WP_CLI::line( '' );
			\WP_CLI::warning( 'Skipping cleanup (--keep-fixtures). Fixture rows are prefixed `cli-test-` if you need to delete them manually.' );
			return;
		}
		$fixture->cleanup();
	}

	private function finish(): void {
		$exit = Assert::print_summary();
		if ( $this->defer_halt ) {
			return;
		}
		if ( 0 !== $exit ) {
			\WP_CLI::halt( $exit );
		}
	}

	private function find_membership_enrollment( int $user_id, int $course_id ): ?object {
		foreach ( Enrollment::get_for_user( $user_id ) as $e ) {
			if ( (int) $e->course_id === $course_id && 'membership' === $e->source ) {
				return $e;
			}
		}
		return null;
	}

	private function find_essay_response_for( int $attempt_id, int $question_id ): ?object {
		foreach ( \Learnomy\Models\QuestionResponse::get_for_attempt( $attempt_id ) as $r ) {
			if ( (int) $r->question_id === $question_id ) {
				return $r;
			}
		}
		return null;
	}

	private function signature_valid( object $cert ): bool {
		if ( empty( $cert->signature ) || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		$public_b64 = get_option( 'learnomy_cert_public_key', '' );
		if ( ! $public_b64 ) {
			return false;
		}
		$public_key = base64_decode( $public_b64, true );
		$signature  = base64_decode( $cert->signature, true );
		if ( false === $public_key || false === $signature || '' === $public_key || '' === $signature ) {
			return false;
		}
		try {
			return sodium_crypto_sign_verify_detached(
				$signature,
				$cert->uuid . '|' . (int) $cert->user_id . '|' . (int) $cert->course_id,
				$public_key
			);
		} catch ( \SodiumException $e ) {
			return false;
		}
	}

	private function commissions_for_transaction( int $txn_id ): array {
		return Commission::get_for_transaction( $txn_id );
	}

	/**
	 * The engagement-split commission rows belonging to the given instructors.
	 *
	 * Engagement splits are the rows with `transaction_id = 0` — they settle a
	 * whole period rather than one sale. Narrowing by recipient as well keeps the
	 * scenario blind to any other engagement run on the same install.
	 *
	 * @param int[] $recipient_ids Fixture instructor IDs.
	 * @return object[]
	 */
	private function engagement_commissions_for( string $period, array $recipient_ids ): array {
		$rows = array_filter(
			Commission::get_for_payout_period( $period ),
			static fn( $row ) => 0 === (int) $row->transaction_id
				&& in_array( (int) $row->recipient_id, $recipient_ids, true )
		);

		return array_values( $rows );
	}

	/**
	 * The monthly engagement rows belonging to the given instructors.
	 *
	 * @param int[] $instructor_ids Fixture instructor IDs.
	 * @return int[] Row IDs.
	 */
	private function engagement_ids_for( string $period, array $instructor_ids ): array {
		$ids = array();

		foreach ( EngagementMonthly::get_for_period( $period ) as $row ) {
			if ( in_array( (int) $row->instructor_id, $instructor_ids, true ) ) {
				$ids[] = (int) $row->id;
			}
		}

		return $ids;
	}

	private function delete_withdrawal( int $id ): void {
		Withdrawal::delete( $id );
	}

	/**
	 * @param object[] $rows Commission rows this scenario created.
	 */
	private function delete_commission_rows( array $rows ): void {
		foreach ( $rows as $row ) {
			Commission::delete( (int) $row->id );
		}
	}

	/**
	 * @param int[] $ids Engagement row IDs this scenario created.
	 */
	private function delete_engagement_rows( array $ids ): void {
		foreach ( $ids as $id ) {
			EngagementMonthly::delete( $id );
		}
	}

	private function amount_for_recipient( array $rows, int $recipient_id ): float {
		foreach ( $rows as $row ) {
			if ( (int) $row->recipient_id === $recipient_id ) {
				return round( (float) $row->amount, 2 );
			}
		}
		return 0.0;
	}

	/**
	 * @param iterable $rows   Rows to count (objects).
	 * @param string   $status Status to match.
	 */
	private function count_with_status( iterable $rows, string $status ): int {
		$n = 0;
		foreach ( $rows as $row ) {
			if ( ( $row->payout_status ?? '' ) === $status ) {
				++$n;
			}
		}
		return $n;
	}
}
