<?php
/**
 * Minimal fixture builder for Learnomy CLI test scenarios.
 *
 * Each scenario constructs a builder, asks it for the fixtures it needs, runs
 * the flow under test, then calls {@see self::cleanup()} so the database is
 * left exactly as it was. Everything created here is prefixed `cli-test-`
 * (users + slugs + titles) so it is obviously test data if cleanup ever fails.
 *
 * @package Learnomy
 */

namespace Learnomy\CLI;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Answer;
use Learnomy\Models\Certificate;
use Learnomy\Models\Commission;
use Learnomy\Models\Course;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Lesson;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\MembershipPlanCourse;
use Learnomy\Models\Progress;
use Learnomy\Models\Question;
use Learnomy\Models\QuestionResponse;
use Learnomy\Models\Quiz;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\QuizSlot;
use Learnomy\Models\Section;
use Learnomy\Models\Subscription;
use Learnomy\Models\Transaction;
use Learnomy\Services\Course_Service;

class Fixture_Builder {

	/** @var int[] */
	private array $user_ids = [];
	/** @var int[] */
	private array $course_ids = [];
	/** @var int[] */
	private array $quiz_ids = [];
	/** @var int[] */
	private array $question_ids = [];
	/** @var int[] */
	private array $plan_ids = [];
	/** @var int[] */
	private array $transaction_ids = [];
	/** @var int[] */
	private array $enrollment_ids = [];
	/** @var int[] */
	private array $subscription_ids = [];
	/** @var int[] */
	private array $certificate_ids = [];

	/**
	 * Create a test user. Emails/logins are randomised so repeat runs don't
	 * collide even if a previous cleanup was interrupted.
	 *
	 * @param string $role Learnomy role slug (e.g. `lrn_student`, `lrn_instructor`, or `administrator`).
	 *
	 * @throws \RuntimeException When the user could not be created.
	 */
	public function make_user( string $role = 'lrn_student', string $label = 'student' ): int {
		$suffix  = strtolower( wp_generate_password( 6, false, false ) );
		$login   = 'cli-test-' . $label . '-' . $suffix;
		$email   = $login . '@example.test';
		$user_id = wp_create_user( $login, wp_generate_password( 20 ), $email );
		if ( is_wp_error( $user_id ) ) {
			throw new \RuntimeException(
				esc_html( 'Fixture_Builder: could not create user — ' . $user_id->get_error_message() )
			);
		}

		$user = get_user_by( 'id', $user_id );
		if ( $user && $role ) {
			$user->set_role( $role );
		}
		update_user_meta( $user_id, 'first_name', 'CliTest' );
		update_user_meta( $user_id, 'last_name', ucfirst( $label ) );
		update_user_meta( $user_id, 'learnomy_cli_fixture', 1 );

		$this->user_ids[] = (int) $user_id;
		return (int) $user_id;
	}

	/**
	 * Build a course via Course_Service so the instructor junction row and
	 * `learnomy_course_created` hook fire exactly the way the real flow does.
	 * Adds one section and two published lessons so progress/completion tests
	 * have something meaningful to iterate over.
	 *
	 * @param int                 $author_id Course author.
	 * @param array<string,mixed> $overrides Any Course table columns to override.
	 * @return array{course_id:int,section_id:int,lesson_ids:int[]}
	 *
	 * @throws \RuntimeException When Course_Service::create returns 0.
	 */
	public function make_course( int $author_id, array $overrides = [] ): array {
		$suffix    = strtolower( wp_generate_password( 6, false, false ) );
		$defaults  = [
			'title'       => 'CLI Test Course ' . $suffix,
			'slug'        => 'cli-test-course-' . $suffix,
			'status'      => 'published',
			'access_type' => 'free',
			'price'       => 0.00,
		];
		$data      = array_merge( $defaults, $overrides );
		$course_id = ( new Course_Service() )->create( $data, $author_id );
		if ( ! $course_id ) {
			throw new \RuntimeException( 'Fixture_Builder: Course_Service::create returned 0.' );
		}
		$this->course_ids[] = $course_id;

		// Cleanup deletes by course_id, so individual section/lesson IDs don't
		// need their own trackers — we only pass them back to the caller.
		$section_id = Section::create(
			[
				'course_id'  => $course_id,
				'title'      => 'Section 1',
				'sort_order' => 1,
			]
		);

		$lesson_ids = [];
		for ( $i = 1; $i <= 2; $i++ ) {
			$lesson_id    = Lesson::create(
				[
					'course_id'    => $course_id,
					'section_id'   => $section_id,
					'title'        => 'Lesson ' . $i . ' (' . $suffix . ')',
					'content'      => 'Body of lesson ' . $i,
					'sort_order'   => $i,
					'content_type' => 'text',
					'status'       => 'published',
				]
			);
			$lesson_ids[] = (int) $lesson_id;
		}

		return [
			'course_id'  => (int) $course_id,
			'section_id' => (int) $section_id,
			'lesson_ids' => $lesson_ids,
		];
	}

	/**
	 * Attach a second instructor to an existing course. Also lowers the primary
	 * instructor's share so the junction still sums to 100% — makes downstream
	 * commission-split assertions meaningful.
	 */
	public function add_co_instructor( int $course_id, int $user_id, float $share_pct ): void {
		$primary_share = max( 0.0, 100.0 - $share_pct );

		foreach ( CourseInstructor::get_for_course( $course_id ) as $inst ) {
			if ( 1 === (int) $inst->is_primary ) {
				CourseInstructor::remove_instructor( $course_id, (int) $inst->user_id );
				CourseInstructor::add_instructor( $course_id, (int) $inst->user_id, $primary_share, true );
				break;
			}
		}

		CourseInstructor::add_instructor( $course_id, $user_id, $share_pct, false );
	}

	/**
	 * Build a graded quiz with three slots: one MC single, one MC multi, one essay.
	 *
	 * `correct_answer_ids` is a heterogeneous map: the single-choice entry is an
	 * int (the one correct answer ID) and the multi-select entry is an int[]
	 * (both correct answer IDs). Callers pattern-match on question type.
	 *
	 * @return array{
	 *   quiz_id: int,
	 *   question_ids: array{mc_single:int, mc_multi:int, essay:int},
	 *   slot_marks: array{mc_single:float, mc_multi:float, essay:float},
	 *   correct_answer_ids: array{mc_single:int, mc_multi:int[]}
	 * }
	 *
	 * @throws \RuntimeException When Quiz::create returns 0.
	 */
	public function make_quiz( ?int $course_id = null, array $overrides = [] ): array {
		$suffix = strtolower( wp_generate_password( 6, false, false ) );

		// A fixture with no author is not a realistic fixture: every wp-admin
		// list scopes an instructor to content they authored or that sits on a
		// course they manage, so an author-less quiz is invisible to the very
		// person a test is pretending to be (Basecamp 10184327422). The course's
		// own author is the right owner, and it is knowable here.
		$author_id = 0;
		if ( $course_id ) {
			$course    = Course::find( (int) $course_id );
			$author_id = (int) ( $course->author_id ?? 0 );
		}
		if ( $author_id <= 0 ) {
			$author_id = get_current_user_id();
		}

		$data = array_merge(
			[
				'slug'           => 'cli-test-quiz-' . $suffix,
				'title'          => 'CLI Test Quiz ' . $suffix,
				'mode'           => 'graded',
				'passing_score'  => 60,
				'attempt_limit'  => 0,
				'grading_method' => 'highest',
				'course_id'      => $course_id,
				'author_id'      => $author_id,
			],
			$overrides
		);

		$quiz_id = Quiz::create( $data );
		if ( $quiz_id <= 0 ) {
			throw new \RuntimeException( 'Fixture_Builder: Quiz::create returned 0.' );
		}
		$this->quiz_ids[] = (int) $quiz_id;

		// Question 1 — single-choice, worth 1 mark.
		$mc_single_id         = Question::create(
			[
				'type'       => 'mc_single',
				'title'      => 'CLI MC single',
				'content'    => 'Pick the only correct option.',
				'status'     => 'published',
				'author_id'  => $author_id,
				'updated_at' => current_time( 'mysql', true ),
			]
		);
		$this->question_ids[] = (int) $mc_single_id;
		$correct_single_id    = (int) Answer::create(
			[
				'question_id' => $mc_single_id,
				'content'     => 'Correct answer',
				'is_correct'  => 1,
				'sort_order'  => 1,
			]
		);
		Answer::create(
			[
				'question_id' => $mc_single_id,
				'content'     => 'Wrong answer',
				'is_correct'  => 0,
				'sort_order'  => 2,
			]
		);
		QuizSlot::add_question( $quiz_id, $mc_single_id, 1 );

		// Question 2 — multi-select, worth 2 marks, two correct answers.
		$mc_multi_id          = Question::create(
			[
				'type'       => 'mc_multi',
				'title'      => 'CLI MC multi',
				'content'    => 'Pick all correct options.',
				'status'     => 'published',
				'author_id'  => $author_id,
				'updated_at' => current_time( 'mysql', true ),
			]
		);
		$this->question_ids[] = (int) $mc_multi_id;
		$correct_multi_ids    = [];
		$correct_multi_ids[]  = (int) Answer::create(
			[
				'question_id' => $mc_multi_id,
				'content'     => 'Multi correct A',
				'is_correct'  => 1,
				'sort_order'  => 1,
			]
		);
		$correct_multi_ids[]  = (int) Answer::create(
			[
				'question_id' => $mc_multi_id,
				'content'     => 'Multi correct B',
				'is_correct'  => 1,
				'sort_order'  => 2,
			]
		);
		Answer::create(
			[
				'question_id' => $mc_multi_id,
				'content'     => 'Multi wrong',
				'is_correct'  => 0,
				'sort_order'  => 3,
			]
		);
		$multi_slot_id = QuizSlot::add_question( $quiz_id, $mc_multi_id, 2 );
		QuizSlot::update( (int) $multi_slot_id, [ 'marks' => 2.00 ] );

		// Question 3 — essay. Never auto-gradeable, blocks attempt finalisation until graded.
		$essay_id             = Question::create(
			[
				'type'       => 'essay',
				'title'      => 'CLI essay',
				'content'    => 'Write a few sentences.',
				'status'     => 'published',
				'author_id'  => $author_id,
				'updated_at' => current_time( 'mysql', true ),
			]
		);
		$this->question_ids[] = (int) $essay_id;
		$essay_slot_id        = QuizSlot::add_question( $quiz_id, $essay_id, 3 );
		QuizSlot::update( (int) $essay_slot_id, [ 'marks' => 3.00 ] );

		return [
			'quiz_id'            => (int) $quiz_id,
			'question_ids'       => [
				'mc_single' => (int) $mc_single_id,
				'mc_multi'  => (int) $mc_multi_id,
				'essay'     => (int) $essay_id,
			],
			'slot_marks'         => [
				'mc_single' => 1.00,
				'mc_multi'  => 2.00,
				'essay'     => 3.00,
			],
			'correct_answer_ids' => [
				'mc_single' => $correct_single_id,
				'mc_multi'  => $correct_multi_ids,
			],
		];
	}

	public function make_membership_plan( array $overrides = [] ): int {
		$suffix = strtolower( wp_generate_password( 6, false, false ) );
		// Schema column is `title`, not `name`. We also send `updated_at` because
		// `dbDelta` marks it NOT NULL with no default (zero-date) and strict-mode
		// MySQL rejects the implicit default.
		$data = array_merge(
			[
				'title'            => 'CLI Test Plan ' . $suffix,
				'slug'             => 'cli-test-plan-' . $suffix,
				'price'            => 19.99,
				'billing_interval' => 'monthly',
				'status'           => 'active',
				'sort_order'       => 1,
				'updated_at'       => current_time( 'mysql', true ),
			],
			$overrides
		);

		$plan_id = MembershipPlan::create( $data );
		if ( is_wp_error( $plan_id ) ) {
			throw new \RuntimeException(
				esc_html( 'Fixture_Builder: MembershipPlan::create failed — ' . $plan_id->get_error_message() )
			);
		}
		if ( ! $plan_id ) {
			throw new \RuntimeException( 'Fixture_Builder: MembershipPlan::create returned 0.' );
		}

		$this->plan_ids[] = (int) $plan_id;
		return (int) $plan_id;
	}

	public function attach_plan_course( int $plan_id, int $course_id ): void {
		MembershipPlanCourse::add_course( $plan_id, $course_id );
	}

	public function make_transaction( int $user_id, int $course_id, float $amount, string $type = 'purchase' ): int {
		$txn_id = Transaction::record(
			[
				'transaction_type'        => $type,
				'user_id'                 => $user_id,
				'course_id'               => $course_id,
				'amount'                  => $amount,
				'currency'                => 'USD',
				'payment_provider'        => 'test',
				'provider_transaction_id' => 'cli-txn-' . wp_generate_password( 10, false, false ),
				'status'                  => 'completed',
			]
		);
		if ( ! $txn_id ) {
			throw new \RuntimeException( 'Fixture_Builder: Transaction::record returned 0.' );
		}

		$this->transaction_ids[] = (int) $txn_id;
		return (int) $txn_id;
	}

	/**
	 * Record a membership-type transaction. Unlike {@see self::make_transaction()}
	 * this requires a plan_id rather than a course_id and sets type=membership —
	 * the engagement-split scenario needs transactions the Commission_Service
	 * will pick up under its membership branch.
	 *
	 * @throws \RuntimeException When Transaction::record returns 0.
	 */
	public function make_membership_transaction( int $user_id, int $plan_id, float $amount ): int {
		$txn_id = Transaction::record(
			[
				'transaction_type'        => 'membership',
				'user_id'                 => $user_id,
				'plan_id'                 => $plan_id,
				'amount'                  => $amount,
				'currency'                => 'USD',
				'payment_provider'        => 'test',
				'provider_transaction_id' => 'cli-mem-' . wp_generate_password( 10, false, false ),
				'status'                  => 'completed',
			]
		);
		if ( ! $txn_id ) {
			throw new \RuntimeException( 'Fixture_Builder: membership Transaction::record returned 0.' );
		}
		$this->transaction_ids[] = (int) $txn_id;
		return (int) $txn_id;
	}

	/**
	 * Register transaction IDs that were created outside the builder so cleanup
	 * still removes them and their commission rows.
	 */
	public function track_transactions( int ...$ids ): void {
		foreach ( $ids as $id ) {
			$this->transaction_ids[] = $id;
		}
	}

	/**
	 * Track enrollment/subscription/certificate rows created by the flow under
	 * test so cleanup can delete them even though the builder didn't create them.
	 */
	public function track_enrollment( int $id ): void {
		$this->enrollment_ids[] = $id;
	}

	public function track_subscription( int $id ): void {
		$this->subscription_ids[] = $id;
	}

	public function track_certificate( int $id ): void {
		$this->certificate_ids[] = $id;
	}

	public function track_course( int $id ): void {
		$this->course_ids[] = $id;
	}

	/**
	 * Remove everything the builder has recorded. Order matters: we delete
	 * leaves before roots so foreign keys / unique constraints never block.
	 */
	public function cleanup(): void {
		// Commissions then transactions — commission rows reference transaction rows.
		foreach ( $this->transaction_ids as $id ) {
			Commission::delete_for_transaction( $id );
			Transaction::delete( $id );
		}

		foreach ( $this->subscription_ids as $id ) {
			Subscription::delete( $id );
		}

		foreach ( $this->plan_ids as $id ) {
			MembershipPlanCourse::delete_for_plan( $id );
			MembershipPlan::delete( $id );
		}

		foreach ( $this->certificate_ids as $id ) {
			Certificate::delete( $id );
		}

		foreach ( $this->enrollment_ids as $id ) {
			Enrollment::delete( $id );
		}

		// Quizzes — wipe responses, attempts, slots, then the quiz row itself.
		foreach ( $this->quiz_ids as $quiz_id ) {
			$attempt_ids = QuizAttempt::get_ids_for_quiz( $quiz_id );
			if ( ! empty( $attempt_ids ) ) {
				QuestionResponse::delete_for_attempts( $attempt_ids );
			}
			QuizAttempt::delete_for_quiz( $quiz_id );
			QuizSlot::delete_for_quiz( $quiz_id );
			Quiz::delete( $quiz_id );
		}

		foreach ( $this->question_ids as $qid ) {
			Answer::delete_for_question( $qid );
			Question::delete( $qid );
		}

		// Progress rows for test users only — delete by user so we never touch
		// unrelated data even if a test user somehow ended up with progress on
		// a non-fixture course.
		foreach ( $this->user_ids as $uid ) {
			Progress::delete_for_user( $uid );
		}

		// Courses — through the single course-delete chokepoint, so teardown
		// runs the SAME cascade production does (per-lesson, hook-firing) rather
		// than a parallel hand-rolled list. The old list dropped lesson rows
		// with a bare table DELETE and left their attachments, unlocks, progress
		// and Pro child rows behind — which is how this install accumulated
		// lessons pointing at course ids that no longer exist.
		$course_service = new Course_Service();
		foreach ( $this->course_ids as $course_id ) {
			$course_service->delete( $course_id );
		}

		// Finally, remove users created for the test.
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}
		foreach ( $this->user_ids as $uid ) {
			wp_delete_user( $uid );
		}

		// Reset all trackers so the same builder instance can be reused.
		$this->user_ids         = [];
		$this->course_ids       = [];
		$this->quiz_ids         = [];
		$this->question_ids     = [];
		$this->plan_ids         = [];
		$this->transaction_ids  = [];
		$this->enrollment_ids   = [];
		$this->subscription_ids = [];
		$this->certificate_ids  = [];
	}
}
