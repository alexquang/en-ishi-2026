<?php
/**
 * Grading — the one reader of "what may this person grade" and "what does a
 * grader see on one attempt".
 *
 * Manual grading has three entry points (wp-admin `?page=learnomy-grading`, the
 * front-end teaching shell at /instructor/grading/, and REST
 * `GET /grading/pending` + `POST /attempts/{id}/grade`). Before this class the
 * ownership scope was open-coded in THREE places with the same body, and the
 * per-attempt view was assembled inline in `Admin::render_quiz_grading_detail()`
 * where no other surface could reuse it. Two copies of a scope rule is how a
 * front-end queue ends up showing an attempt the REST route would refuse to
 * grade, so the rule and the assembly live here and every surface asks.
 *
 * Persistence is NOT here: grading writes go through
 * {@see \Learnomy\Services\Quiz_Service::grade_question()} via the REST route.
 * This class is read-side only.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Answer;
use Learnomy\Models\Course;
use Learnomy\Models\Question;
use Learnomy\Models\QuestionResponse;
use Learnomy\Models\Quiz;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\QuizSlot;
use Learnomy\Permissions\Permission_Engine;
use Learnomy\QuestionTypes;

class Grading_Service {

	/** Queue statuses. `pending` = still owed a grade, `graded` = human-graded. */
	public const STATUS_PENDING = 'pending';
	public const STATUS_GRADED  = 'graded';

	/**
	 * Course ids whose attempts this user may grade.
	 *
	 * Delegates to Permission_Engine so the queue lists exactly what the write
	 * route would accept a grade for — admins get `null` (no restriction, which
	 * also covers standalone quizzes belonging to no course), everyone else gets
	 * the courses they own. An empty array means "nothing to grade" and the model
	 * layer reads it as such instead of running an invalid `IN ()`.
	 *
	 * @param int $user_id User asking.
	 * @return int[]|null Course ids, or null for no restriction.
	 */
	public static function scope_course_ids( int $user_id ): ?array {
		return Permission_Engine::gradable_course_ids( $user_id );
	}

	/**
	 * Narrow a grading scope to one course, refusing a course outside the scope.
	 *
	 * The course filter is a convenience, never a way to widen access: asking for
	 * a course the caller does not teach returns an empty array ("nothing"), not
	 * that course's queue.
	 *
	 * @param int        $course_id Requested course filter (0 = no filter).
	 * @param int[]|null $scope     The caller's grading scope from scope_course_ids().
	 * @return int[]|null Effective course ids for the queue query.
	 */
	public static function apply_course_filter( int $course_id, ?array $scope ): ?array {
		if ( $course_id <= 0 ) {
			return $scope;
		}

		if ( null === $scope ) {
			return array( $course_id );
		}

		return in_array( $course_id, array_map( 'intval', $scope ), true )
			? array( $course_id )
			: array();
	}

	/**
	 * Normalise a requested queue status to one this class knows.
	 *
	 * @param string $status Raw status.
	 */
	public static function normalize_status( string $status ): string {
		return self::STATUS_GRADED === $status ? self::STATUS_GRADED : self::STATUS_PENDING;
	}

	/**
	 * One page of the grading queue, plus its total, for either status.
	 *
	 * Both branches are a single grouped query + a single COUNT — no per-row
	 * work — so the queue holds at a backlog of thousands.
	 *
	 * @param int[]|null $course_ids Effective course scope (null = everything).
	 * @param string     $status     'pending' or 'graded'.
	 * @param int        $limit      Page size.
	 * @param int        $offset     Page offset.
	 * @return array{rows: object[], total: int}
	 */
	public static function queue( ?array $course_ids, string $status, int $limit, int $offset ): array {
		if ( self::STATUS_GRADED === self::normalize_status( $status ) ) {
			return array(
				'rows'  => QuestionResponse::get_recently_graded( $course_ids, $limit, $offset ),
				'total' => QuestionResponse::count_recently_graded( $course_ids ),
			);
		}

		return array(
			'rows'  => QuestionResponse::get_pending_grading( $course_ids, $limit, $offset ),
			'total' => QuestionResponse::count_pending_grading( $course_ids ),
		);
	}

	/**
	 * Maximum courses offered in the filter dropdown.
	 *
	 * A <select> is the wrong control past a few hundred options, and an
	 * unbounded `SELECT *` on a 5,000-course catalogue is the wrong query. Past
	 * this ceiling the REST `course_id` param is the escape hatch.
	 */
	private const FILTER_COURSE_LIMIT = 200;

	/**
	 * Courses to offer in a grading queue's course filter.
	 *
	 * Exactly the queue's own scope, so the dropdown can never name a course
	 * whose selection returns nothing. Admins get every course, capped.
	 *
	 * @param int        $user_id Viewer.
	 * @param int[]|null $scope   Pre-resolved scope, to avoid re-deriving it.
	 * @return array<int,array{id:int,title:string}>
	 */
	public static function filter_courses( int $user_id, ?array $scope = null ): array {
		if ( null === $scope ) {
			$scope = self::scope_course_ids( $user_id );
		}

		if ( is_array( $scope ) && empty( $scope ) ) {
			return array();
		}

		$courses = null === $scope
			? Course::where( array(), 'title', 'ASC', self::FILTER_COURSE_LIMIT )
			: Course::find_in( array_slice( $scope, 0, self::FILTER_COURSE_LIMIT ) );

		$out = array();
		foreach ( $courses as $course ) {
			$out[] = array(
				'id'    => (int) $course->id,
				'title' => (string) ( $course->title ?? '' ),
			);
		}

		usort( $out, static fn( $a, $b ) => strcasecmp( $a['title'], $b['title'] ) );

		return $out;
	}

	/**
	 * Everything a grader needs to grade ONE attempt, or null when they may not.
	 *
	 * The ownership gate is `Permission_Engine::can( …, 'grade_quiz', $quiz_id )`
	 * — the same decision `Quizzes_Controller::can_grade()` makes on the write
	 * route, so a surface can never render answers the API would refuse to
	 * accept a grade for.
	 *
	 * Every response is normalised into a plain array (prompt, readable answer,
	 * marks, current score) so a template renders it without touching a model.
	 *
	 * @param int $attempt_id Attempt to grade.
	 * @param int $user_id    Grader.
	 * @return array<string,mixed>|null Null when the attempt is missing or not
	 *                                  the caller's to grade.
	 */
	public static function attempt_detail( int $attempt_id, int $user_id ): ?array {
		$attempt = QuizAttempt::find( $attempt_id );
		$quiz    = $attempt ? Quiz::find( (int) $attempt->quiz_id ) : null;

		if ( ! $attempt || ! $quiz || ! Permission_Engine::can( $user_id, 'grade_quiz', (int) $quiz->id ) ) {
			return null;
		}

		$responses = QuestionResponse::get_for_attempt( $attempt_id );

		// Batch the questions + the paper's marks so the per-response loop reads
		// from maps instead of issuing a ::find() per row.
		$question_ids  = array_values( array_unique( array_map( static fn( $r ) => (int) $r->question_id, $responses ) ) );
		$questions_map = $question_ids ? Question::find_in( $question_ids ) : array();

		$marks_map = array();
		foreach ( QuizSlot::get_for_quiz( (int) $quiz->id ) as $slot ) {
			$marks_map[ (int) $slot->question_id ] = (float) $slot->marks;
		}

		$types = QuestionTypes::all();
		$rows  = array();
		$index = 0;

		foreach ( $responses as $response ) {
			++$index;
			$question_id = (int) $response->question_id;
			$question    = $questions_map[ $question_id ] ?? null;

			// Marks the score is bounded by. The response row now carries its own
			// max_score (written at answer time and re-stamped when graded), so an
			// essay is no longer "out of 0" — fall back to the paper's slot marks
			// for rows recorded before that was true, then to 1.
			$max = (float) ( $marks_map[ $question_id ] ?? 0 );
			if ( $max <= 0 ) {
				$max = (float) ( $response->max_score ?? 0 );
			}
			if ( $max <= 0 ) {
				$max = 1.0;
			}

			$type = $question ? (string) $question->type : 'essay';

			$rows[] = array(
				'id'          => (int) $response->id,
				'question_id' => $question_id,
				'index'       => $index,
				'prompt'      => $question
					? wp_strip_all_tags( (string) ( $question->title ?: $question->content ) )
					: __( '(question removed)', 'learnomy' ),
				'type'        => $type,
				'type_label'  => (string) ( $types[ $type ] ?? $type ),
				'answer_html' => self::render_answer( $response->response_json, self::answer_map( $question_id, null !== $question ), $question ? $type : '' ),
				'max'         => $max,
				'graded'      => ! empty( $response->graded_at ),
				'score'       => (float) ( $response->score ?? 0 ),
				'feedback'    => (string) ( $response->grader_feedback ?? '' ),
				// The raw rows travel with the normalised ones purely so the
				// `learnomy_quiz_grading_response_controls` seam keeps its exact
				// signature — Pro's rubric grader has always received the response
				// and question objects there, and both grading screens must fire
				// it identically. Templates read the flat fields above.
				'response'    => $response,
				'question'    => $question,
				'attempt'     => $attempt,
			);
		}

		$pending_count = count( array_filter( $rows, static fn( $r ) => ! $r['graded'] ) );

		// Integrity signals — advisory context shown beside the answers, never a
		// verdict. Same resolution the admin detail has always used.
		$tab_tracking       = Quiz_Defaults::is_tab_tracking_enabled();
		$tab_switches       = (int) ( $attempt->tab_switches ?? 0 );
		$tab_flag_threshold = (int) apply_filters( 'learnomy_tab_switch_flag_threshold', 3, $attempt, $quiz );
		$runner_state       = QuizAttempt::get_runner_state( $attempt_id );

		return array(
			'attempt'            => $attempt,
			'quiz'               => $quiz,
			'student'            => get_userdata( (int) $attempt->user_id ),
			'rows'               => $rows,
			'pending_count'      => $pending_count,
			'ip_address'         => trim( (string) ( $attempt->ip_address ?? '' ) ),
			'tab_tracking'       => $tab_tracking,
			'tab_switches'       => $tab_switches,
			'tab_flag_threshold' => $tab_flag_threshold,
			'tab_flagged'        => $tab_flag_threshold > 0 && $tab_switches >= $tab_flag_threshold,
			'exam_late'          => ! empty( $runner_state['late_submission'] ),
			'exam_late_minutes'  => (int) round( ( (int) ( $runner_state['seconds_over'] ?? 0 ) ) / 60 ),
		);
	}

	/**
	 * Option text keyed by answer-row id, so a choice answer renders as words
	 * rather than the row ids that are actually stored.
	 *
	 * Bounded by one question's options — this runs inside a single attempt's
	 * detail view, never inside a list.
	 *
	 * @param int  $question_id Question whose options to map.
	 * @param bool $exists      Whether the question still exists.
	 * @return array<string,string>
	 */
	private static function answer_map( int $question_id, bool $exists ): array {
		if ( ! $exists || $question_id <= 0 ) {
			return array();
		}

		$map = array();
		foreach ( Answer::get_for_question( $question_id ) as $answer ) {
			$map[ (string) $answer->id ] = (string) $answer->content;
		}

		return $map;
	}

	/**
	 * Render a stored answer as safe, human-readable HTML.
	 *
	 * The question TYPE decides how to read the column, because the storage
	 * shape does not: a choice answer is a JSON scalar on some rows and a JSON
	 * array on others (see QuestionResponse::selected_ids()). This method used
	 * to branch on the shape instead, returning early for `is_string()` before
	 * the option map was ever consulted — so the 640 of 888 `mc_single`
	 * responses stored as `"26531"` printed the id at the instructor, while the
	 * 246 stored as `[26613]` resolved correctly on the same screen
	 * (Basecamp 10184327418).
	 *
	 * Text types are never mapped, deliberately. Running a typed answer through
	 * an id map risks rewriting what the learner actually wrote, and fill_blank
	 * stores its typed text AS an array — the exact shape the old code treated
	 * as ids.
	 *
	 * @param string|null          $json       Raw response_json.
	 * @param array<string,string> $answer_map Answer-row id => option text.
	 * @param string               $type       Question type slug. Defaults to text
	 *                                         handling, which is the safe reading
	 *                                         for an unknown or removed question.
	 * @return string Safe HTML.
	 */
	public static function render_answer( ?string $json, array $answer_map = array(), string $type = '' ): string {
		if ( '' !== $type && QuestionTypes::selects_options( $type ) ) {
			$parts = array();
			foreach ( QuestionResponse::selected_ids( $json ) as $id ) {
				// An id with no option left — the author deleted or re-versioned
				// that choice — still shows the id, so the row reads as a stale
				// reference rather than silently blank.
				$parts[] = esc_html( $answer_map[ $id ] ?? $id );
			}

			return $parts ? implode( ', ', $parts ) : self::no_answer();
		}

		$decoded = json_decode( (string) $json, true );

		if ( is_string( $decoded ) ) {
			$trimmed = trim( $decoded );
			return '' === $trimmed ? self::no_answer() : nl2br( esc_html( $trimmed ) );
		}

		if ( is_array( $decoded ) ) {
			if ( empty( $decoded ) ) {
				return self::no_answer();
			}

			// fill_blank: one entry per blank, in reading order. Joined, never
			// mapped — these are the learner's words.
			$parts = array_map(
				static function ( $value ) {
					return is_scalar( $value )
						? nl2br( esc_html( trim( (string) $value ) ) )
						: esc_html( (string) wp_json_encode( $value ) );
				},
				$decoded
			);

			return implode( ', ', $parts );
		}

		return self::no_answer();
	}

	/** The one "student left this blank" string, so every surface says it the same way. */
	private static function no_answer(): string {
		return '<em class="lrn-muted">' . esc_html__( '(no answer submitted)', 'learnomy' ) . '</em>';
	}
}
