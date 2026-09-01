<?php
/**
 * View-data resolver for the student quiz-results section
 * (/account/quiz-results/).
 *
 * Two modes from one route: the paginated list of the learner's own quiz
 * attempts, and — when `?attempt=N` is present and the attempt belongs to the
 * current user — the per-attempt review (what they answered, what was right).
 * The review is built by the single shared Quiz_Service::build_attempt_review()
 * so it matches the live results screen exactly (no parallel review logic).
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\QuizAttempt;
use Learnomy\Models\Quiz;
use Learnomy\Models\Course;
use Learnomy\Services\Quiz_Service;

class Account_Quiz_Results_View_Data {

	private const PER_PAGE = 20;

	/**
	 * @param array $data Route data (unused).
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		unset( $data );

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return self::empty_state();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET navigation, no state change.
		$attempt_id = isset( $_GET['attempt'] ) ? absint( wp_unslash( $_GET['attempt'] ) ) : 0;
		$page       = \Learnomy\current_page(); // registry canonical page var (lrn_page), covers /page/N/ + legacy ?lrn_page=
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Detail mode — a single attempt's review, gated to the owner (admins
		// may view for support; the instructor drill-down is a separate Pro
		// surface).
		if ( $attempt_id ) {
			return self::resolve_review( $attempt_id, $user_id );
		}

		// List mode — paginated attempt history, newest first.
		$offset = ( $page - 1 ) * self::PER_PAGE;
		$total  = QuizAttempt::count_for_user( $user_id );
		$rows   = QuizAttempt::get_for_user( $user_id, self::PER_PAGE, $offset );

		// Batch the quiz + course lookups the rows need (no per-row N+1).
		$quiz_ids   = array_values( array_unique( array_map( static fn( $r ) => (int) $r->quiz_id, $rows ) ) );
		$quiz_by_id = array();
		$course_ids = array();
		foreach ( $quiz_ids ? Quiz::find_in( $quiz_ids ) : array() as $quiz ) {
			$quiz_by_id[ (int) $quiz->id ] = $quiz;
			$cid                           = (int) ( $quiz->course_id ?? 0 );
			if ( $cid ) {
				$course_ids[ $cid ] = true;
			}
		}
		$course_by_id = array();
		foreach ( $course_ids ? Course::find_in( array_keys( $course_ids ) ) : array() as $course ) {
			$course_by_id[ (int) $course->id ] = $course;
		}

		$attempts = array();
		foreach ( $rows as $row ) {
			$quiz   = $quiz_by_id[ (int) $row->quiz_id ] ?? null;
			$course = $quiz ? ( $course_by_id[ (int) ( $quiz->course_id ?? 0 ) ] ?? null ) : null;
			$status = (string) ( $row->status ?? '' );

			// A never-submitted attempt is in progress, not a finished failure.
			// Give it a Resume link (the player resumes the open attempt) and
			// keep it out of score/pass-fail rendering.
			$is_in_progress = ( 'in_progress' === $status );
			$resume_url     = '';
			if ( $is_in_progress && $quiz ) {
				// This used to branch on "is there a course in hand", without
				// consulting the quiz's own course_id at all — so a standalone
				// quiz attempted alongside a course resolved to a course-scoped
				// URL that 404s.
				$resume_url = Quiz::url( $quiz, $course );
			}

			$attempts[] = array(
				'id'             => (int) $row->id,
				'quiz_title'     => $quiz ? ( $quiz->title ?? __( 'Quiz', 'learnomy' ) ) : __( '(deleted quiz)', 'learnomy' ),
				'course_title'   => $course ? (string) ( $course->title ?? '' ) : '',
				'status'         => $status,
				'percentage'     => (float) ( $row->percentage ?? 0 ),
				'passed'         => ! empty( $row->passed ),
				'date'           => $row->submitted_at ?: $row->started_at,
				'is_in_progress' => $is_in_progress,
				'resume_url'     => $resume_url,
				'reviewable'     => in_array( $status, array( 'graded', 'submitted' ), true ),
				'review_url'     => add_query_arg( 'attempt', (int) $row->id, \Learnomy\route_url( 'account-quiz-results' ) ),
			);
		}

		return array(
			'attempts' => $attempts,
			'total'    => (int) $total,
			'page'     => $page,
			'per_page' => self::PER_PAGE,
			'base_url' => \Learnomy\route_url( 'account-quiz-results' ),
			'review'   => null,
		);
	}

	/**
	 * Build the review payload for a single attempt, owner/admin-gated.
	 */
	private static function resolve_review( int $attempt_id, int $user_id ): array {
		$attempt  = QuizAttempt::find( $attempt_id );
		$is_owner = $attempt && (int) $attempt->user_id === $user_id;
		$is_admin = user_can( $user_id, 'manage_options' );

		if ( ! $attempt || ( ! $is_owner && ! $is_admin ) ) {
			$out                  = self::empty_state();
			$out['review_denied'] = true;
			return $out;
		}

		$quiz   = Quiz::find( (int) $attempt->quiz_id );
		$course = $quiz && ! empty( $quiz->course_id ) ? Course::find( (int) $quiz->course_id ) : null;

		// The learner is revisiting a finished attempt, so the full review is the
		// default — but the quiz's own reveal rule still applies. Resolved
		// through the shared helper rather than restated here: this surface
		// special-cased only 'never' and then passed a hardcoded 'after_submit',
		// which handed the complete answer key to a student who had FAILED an
		// after_pass exam (Basecamp 10184327412).
		$lrn_quiz_service = new Quiz_Service();
		$feedback_timing  = $lrn_quiz_service->resolve_feedback_timing(
			$attempt,
			(string) ( $quiz->feedback_timing ?? 'after_submit' )
		);
		$no_feedback      = ! Quiz_Service::timing_reveals_review( $feedback_timing );
		$rows             = $no_feedback ? array() : $lrn_quiz_service->build_attempt_review( $attempt, $feedback_timing );

		$out           = self::empty_state();
		$out['review'] = array(
			'attempt'      => QuizAttempt::to_typed_array( $attempt ),
			'quiz_title'   => $quiz ? (string) ( $quiz->title ?? __( 'Quiz', 'learnomy' ) ) : __( 'Quiz', 'learnomy' ),
			'course_title' => $course ? (string) ( $course->title ?? '' ) : '',
			'rows'         => $rows,
			'groups'       => Quiz_Service::group_attempt_review( $rows, $quiz ),
			'no_feedback'  => $no_feedback,
			'back_url'     => \Learnomy\route_url( 'account-quiz-results' ),
		);
		return $out;
	}


	/**
	 * Default empty shape so the template can read every key without guards.
	 */
	private static function empty_state(): array {
		return array(
			'attempts' => array(),
			'total'    => 0,
			'page'     => 1,
			'per_page' => self::PER_PAGE,
			'base_url' => \Learnomy\route_url( 'account-quiz-results' ),
			'review'   => null,
		);
	}
}
