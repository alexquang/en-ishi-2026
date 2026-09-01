<?php
/**
 * Quizzes REST controller — quiz CRUD, attempt lifecycle, grading.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\Models\Quiz;
use Learnomy\Models\Tag;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\QuizSlot;
use Learnomy\Models\Question;
use Learnomy\Models\QuestionCategory;
use Learnomy\Models\Answer;
use Learnomy\Models\QuestionResponse;
use Learnomy\Models\Course;
use Learnomy\Services\Grading_Service;
use Learnomy\Services\Quiz_Service;
use Learnomy\Services\Quiz_Defaults;
use function Learnomy\now;

class Quizzes_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'quizzes';
	}

	public function register_routes(): void {
		// Collection: list + create.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_quizzes' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => array_merge(
						$this->get_collection_params(),
						[
							'course_id' => [
								'type'              => 'integer',
								'description'       => __( 'Filter by course ID.', 'learnomy' ),
								'sanitize_callback' => 'absint',
							],
							'mode'      => [
								'type' => 'string',
								'enum' => [ 'graded', 'practice', 'survey' ],
							],
						]
					),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_quiz' ],
					'permission_callback' => [ $this, 'can_create' ],
					'args'                => $this->quiz_args(),
				],
			]
		);

		// Single quiz: read, update, delete.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_quiz' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_quiz' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => array_merge(
						[
							'id' => [
								'type'     => 'integer',
								'required' => true,
							],
						],
						$this->quiz_args( false )
					),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_quiz' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);

		// Verify a quiz access code without starting an attempt — drives the
		// pre-start gate in templates/partials/quiz-gate.php. Success stores a
		// usermeta flag so the gate can fall through on subsequent page loads.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/verify-code',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'verify_access_code' ],
				'permission_callback' => [ $this, 'can_take_quiz' ],
				'args'                => [
					'id'          => [
						'type'     => 'integer',
						'required' => true,
					],
					'access_code' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Start a new attempt.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/start',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'start_attempt' ],
				'permission_callback' => [ $this, 'can_take_quiz' ],
				'args'                => [
					'id'            => [
						'type'     => 'integer',
						'required' => true,
					],
					'enrollment_id' => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'access_code'   => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Optional quiz access code. Required when the quiz was configured with one.', 'learnomy' ),
					],
				],
			]
		);

		// Answer a question within an active attempt.
		register_rest_route(
			$this->namespace,
			'/attempts/(?P<attempt_id>[\d]+)/answer',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'answer_question' ],
				'permission_callback' => [ $this, 'can_act_on_attempt' ],
				'args'                => [
					'attempt_id'  => [
						'type'     => 'integer',
						'required' => true,
					],
					'question_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'response'    => [ 'required' => true ],
				],
			]
		);

		// A4: persist the exam-runner resume state (current question position +
		// flagged-for-review ids) into the attempt's saved_state_json. Lightweight,
		// fire-and-forget from the player on navigation / flag toggle.
		register_rest_route(
			$this->namespace,
			'/attempts/(?P<attempt_id>[\d]+)/state',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'record_attempt_state' ],
				'permission_callback' => [ $this, 'can_act_on_attempt' ],
				'args'                => [
					'attempt_id'    => [
						'type'     => 'integer',
						'required' => true,
					],
					'current_index' => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'flagged'       => [
						'type'  => 'array',
						'items' => [ 'type' => 'integer' ],
					],
					'remaining'     => [
						// Seconds left on the client clock — stored so the
						// pause_on_resume policy can continue from here.
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'answers'       => [
						// In-progress answer map { questionId: value }. Stored for
						// resume only; the authoritative grading input is the submit
						// body, so this is never the scoring source.
						'type' => 'object',
					],
				],
			]
		);

		// Submit attempt for grading.
		register_rest_route(
			$this->namespace,
			'/attempts/(?P<attempt_id>[\d]+)/submit',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'submit_attempt' ],
				'permission_callback' => [ $this, 'can_act_on_attempt' ],
				'args'                => [
					'attempt_id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		// Get attempt details + all responses.
		register_rest_route(
			$this->namespace,
			'/attempts/(?P<attempt_id>[\d]+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_attempt' ],
				'permission_callback' => [ $this, 'can_view_attempt' ],
				'args'                => [
					'attempt_id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		// GET /my/quiz-attempts — the CURRENT USER's own attempts, across every
		// course. The per-quiz route below is can_manage (instructor/admin) and
		// needs a quiz_id, so a student had no way to see their own history:
		// they could sit a quiz and then had nowhere to see that they had
		// (Basecamp 10198896121). Separate route rather than relaxing the
		// permission on the per-quiz one, because "my own attempts" and "every
		// student's attempts at this quiz" are different questions with
		// different answers about who may ask them.
		register_rest_route(
			$this->namespace,
			'/my/quiz-attempts',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_my_quiz_attempts' ],
				'permission_callback' => static fn() => is_user_logged_in(),
				'args'                => [
					'page'     => [
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					],
					'per_page' => [
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					],
				],
			]
		);

		// List all attempts for a quiz (instructor/admin only).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/attempts',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_quiz_attempts' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => array_merge(
					$this->get_collection_params(),
					[
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					]
				),
			]
		);

		// Quiz performance statistics (instructor/admin).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/statistics',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_statistics' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		// Record tab-switch events for anti-cheat tracking.
		register_rest_route(
			$this->namespace,
			'/quiz-attempts/(?P<id>[\d]+)/tab-switch',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'record_tab_switch' ],
				'permission_callback' => fn() => is_user_logged_in(),
				'args'                => [
					'id'    => [
						'type'     => 'integer',
						'required' => true,
					],
					'count' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// Manual grading for essay responses.
		register_rest_route(
			$this->namespace,
			'/attempts/(?P<attempt_id>[\d]+)/grade',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'grade_attempt' ],
				'permission_callback' => [ $this, 'can_grade' ],
				'args'                => [
					'attempt_id'  => [
						'type'     => 'integer',
						'required' => true,
					],
					'response_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'score'       => [
						'type'     => 'number',
						'required' => true,
						'minimum'  => 0,
					],
					'feedback'    => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
			]
		);

		// GET /grading/pending — the grading queue, scoped to the courses the
		// caller may grade (admins see all). The app's Teach tab, the wp-admin
		// inbox and the front-end /instructor/grading/ page all read the same
		// Grading_Service. No content-editing surface here — grading only.
		//
		// `status` and `course_id` are additive and both default to the original
		// behaviour, so an app pinned to the old contract is unaffected.
		register_rest_route(
			$this->namespace,
			'/grading/pending',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'pending_grading' ],
				'permission_callback' => [ $this, 'can_view_grading_queue' ],
				'args'                => [
					'status'    => [
						'type'    => 'string',
						'enum'    => [ Grading_Service::STATUS_PENDING, Grading_Service::STATUS_GRADED ],
						'default' => Grading_Service::STATUS_PENDING,
					],
					'course_id' => [
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					],
					'limit'     => [
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					],
					'offset'    => [
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					],
				],
			]
		);

		// /quizzes/{id}/questions — list / add-and-attach / detach questions.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/questions',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_quiz_questions' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'attach_quiz_question' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);

		// Slot drag-drop reorder — drives the HTML5 DnD in the admin quiz
		// editor. Accepts a flat ordered array of slot IDs; positions are
		// derived from index. QuizSlot::reorder() does a small per-row update
		// loop which is fine at realistic slot counts (<50).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/slots/reorder',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'reorder_slots' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'id'    => [
						'type'     => 'integer',
						'required' => true,
					],
					'order' => [
						'type'     => 'array',
						'required' => true,
						'items'    => [ 'type' => 'integer' ],
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/questions/(?P<question_id>[\d]+)',
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'detach_quiz_question' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'id'          => [
						'type'     => 'integer',
						'required' => true,
					],
					'question_id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		// Inline single-slot marks update — drives the blur handler in the
		// admin quiz editor so instructors get immediate feedback when adjusting
		// scoring, without sending the whole form. Bulk slot marks live on
		// PATCH /quizzes/{id} via the slot_marks param; this is the per-row
		// counterpart for inline edits.
		register_rest_route(
			$this->namespace,
			'/quiz-slots/(?P<id>[\d]+)',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_quiz_slot' ],
				'permission_callback' => [ $this, 'can_manage_slot' ],
				'args'                => [
					'id'    => [
						'type'     => 'integer',
						'required' => true,
					],
					'marks' => [
						'type'     => 'number',
						'required' => true,
						'minimum'  => 0,
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Quiz-questions junction
	// -------------------------------------------------------------------------

	public function list_quiz_questions( WP_REST_Request $request ): WP_REST_Response {
		$quiz_id = (int) $request->get_param( 'id' );
		if ( ! Quiz::find( $quiz_id ) ) {
			return $this->error_response( $this->not_found( 'Quiz' ) );
		}

		$slots = QuizSlot::get_for_quiz( $quiz_id );

		// Batch-load questions + answers in two queries instead of 2N
		// (Basecamp #9927991339). A 50-question quiz drops from ~101 DB
		// round-trips to 3 — slots + questions IN(...) + answers IN(...).
		$question_ids = array();
		foreach ( $slots as $slot ) {
			$question_ids[] = (int) $slot->question_id;
		}
		$questions = \Learnomy\Models\Question::find_in( $question_ids );
		$answers   = \Learnomy\Models\Answer::get_for_questions( $question_ids );

		$items = array();
		foreach ( $slots as $slot ) {
			$qid      = (int) $slot->question_id;
			$question = $questions[ $qid ] ?? null;
			if ( ! $question ) {
				continue;
			}
			$items[] = array(
				'slot_id'     => (int) $slot->id,
				'question_id' => $qid,
				'sort_order'  => (int) $slot->sort_order,
				'marks'       => (float) $slot->marks,
				'question'    => \Learnomy\Models\Question::to_typed_array( $question ),
				'answers'     => array_map( [ \Learnomy\Models\Answer::class, 'to_typed_array' ], $answers[ $qid ] ?? array() ),
			);
		}

		return $this->single_response( array( 'questions' => $items ) );
	}

	/**
	 * Create a fresh question AND attach it to this quiz. Accepts the same
	 * payload as POST /questions plus optional marks. We chose create-and-
	 * attach as the single path because building a question, saving it,
	 * then separately attaching is an extra round-trip the frontend shouldn't
	 * have to orchestrate.
	 */
	public function attach_quiz_question( WP_REST_Request $request ): WP_REST_Response {
		$quiz_id = (int) $request->get_param( 'id' );
		if ( ! Quiz::find( $quiz_id ) ) {
			return $this->error_response( $this->not_found( 'Quiz' ) );
		}

		$existing_id = absint( $request->get_param( 'question_id' ) );
		if ( $existing_id ) {
			// Attach mode — question already exists, just link to the quiz.
			if ( ! \Learnomy\Models\Question::find( $existing_id ) ) {
				return $this->error_response( $this->not_found( 'Question' ) );
			}
			$question_id = $existing_id;
		} else {
			// Create mode — build a new question from the payload.
			$lrn_q_type = sanitize_key( (string) $request->get_param( 'type' ) );
			$data       = array(
				'type'        => $lrn_q_type,
				'title'       => sanitize_text_field( (string) $request->get_param( 'title' ) ),
				// content = fill_blank token body (plain); the rich passage lives in
				// the dedicated `stem` column below.
				'content'     => wp_kses_post( (string) $request->get_param( 'content' ) ),
				// A3: rich stem stored through the one content engine (all types).
				'stem'        => \Learnomy\prepare_question_stem( wp_unslash( (string) $request->get_param( 'stem' ) ) ),
				'explanation' => sanitize_textarea_field( (string) $request->get_param( 'explanation' ) ),
				'difficulty'  => max( 1, min( 5, absint( $request->get_param( 'difficulty' ) ) ?: 3 ) ),
				'status'      => 'published',
				'author_id'   => get_current_user_id(),
			);
			if ( empty( $data['title'] ) ) {
				return $this->error_response(
					$this->validation_error( __( 'Question text is required.', 'learnomy' ) )
				);
			}
			$question_id = \Learnomy\Models\Question::create( $data );
			if ( ! $question_id ) {
				return $this->error_response( $this->validation_error( __( 'Could not create the question.', 'learnomy' ) ) );
			}

			// Save answers if provided.
			$answers = $request->get_param( 'answers' );
			if ( is_array( $answers ) ) {
				$this->save_question_answers( $question_id, $answers );
			}
		}

		// Attach via QuizSlot — use the next sort_order so rows don't collide.
		$existing_slots = QuizSlot::get_for_quiz( $quiz_id );
		$next_order     = count( $existing_slots ) + 1;
		$marks          = max( 0.0, (float) $request->get_param( 'marks' ) );
		if ( $marks <= 0 ) {
			$marks = 1.0;
		}

		$slot_id = QuizSlot::add_question( $quiz_id, $question_id, $next_order );
		if ( $slot_id && $marks !== 1.0 ) {
			QuizSlot::update( (int) $slot_id, array( 'marks' => $marks ) );
		}

		$listing = $this->list_quiz_questions( $request );
		return $listing;
	}

	public function detach_quiz_question( WP_REST_Request $request ): WP_REST_Response {
		$quiz_id     = (int) $request->get_param( 'id' );
		$question_id = (int) $request->get_param( 'question_id' );

		QuizSlot::remove_question( $quiz_id, $question_id );

		return $this->list_quiz_questions( $request );
	}

	/**
	 * Persist a single slot's marks override.
	 *
	 * The bulk counterpart lives on `PATCH /quizzes/{id}` via the slot_marks
	 * map — this endpoint exists for the inline blur handler so the editor can
	 * write one row at a time without bouncing the whole form payload.
	 */
	public function update_quiz_slot( WP_REST_Request $request ): WP_REST_Response {
		$slot_id = (int) $request->get_param( 'id' );
		$slot    = QuizSlot::find( $slot_id );
		if ( ! $slot ) {
			return $this->error_response( $this->not_found( 'Slot' ) );
		}

		// decimal(5,2) shape — clamp + round so a malformed float can't trip
		// MySQL truncation. Same range as the bulk apply_slot_marks() helper.
		$marks   = (float) $request->get_param( 'marks' );
		$clamped = round( max( 0.0, min( 999.99, $marks ) ), 2 );

		QuizSlot::update( $slot_id, [ 'marks' => $clamped ] );

		return $this->single_response(
			[
				'slot_id' => $slot_id,
				'marks'   => $clamped,
			]
		);
	}

	/**
	 * Persist a new slot order produced by drag-drop in the admin quiz editor.
	 *
	 * `order` is a flat array of slot IDs in the new visual order. Positions
	 * are 1-based (QuizSlot::reorder bumps array index + 1) which matches the
	 * convention used at create-time (attach_quiz_question assigns
	 * count+1 for each new slot).
	 */
	public function reorder_slots( WP_REST_Request $request ): WP_REST_Response {
		$quiz_id = (int) $request->get_param( 'id' );
		$quiz    = Quiz::find( $quiz_id );
		if ( ! $quiz ) {
			return $this->error_response( $this->not_found( 'Quiz' ) );
		}

		$order = $request->get_param( 'order' );
		if ( ! is_array( $order ) || empty( $order ) ) {
			return $this->error_response(
				$this->validation_error( __( 'A non-empty array of slot IDs is required.', 'learnomy' ) )
			);
		}

		// Make sure every slot id actually belongs to the requested quiz —
		// otherwise a malicious caller could reorder another quiz's slots.
		$existing       = QuizSlot::get_for_quiz( $quiz_id );
		$valid_slot_ids = array_map( fn( $s ) => (int) $s->id, $existing );

		$clean_order = array_values(
			array_filter(
				array_map( 'absint', $order ),
				static fn( $id ) => in_array( $id, $valid_slot_ids, true )
			)
		);

		if ( empty( $clean_order ) ) {
			return $this->error_response(
				$this->validation_error( __( 'No valid slot IDs provided for reorder.', 'learnomy' ) )
			);
		}

		QuizSlot::reorder( $clean_order );

		do_action( 'learnomy_quiz_slots_reordered', $quiz_id, $clean_order );

		return $this->single_response(
			[
				'reordered' => true,
				'order'     => $clean_order,
			]
		);
	}

	/**
	 * Wipe + re-insert answer rows for a question. Called during create;
	 * the Questions controller has its own save path for updates so we
	 * don't duplicate logic.
	 *
	 * @param int                            $question_id Question the answers belong to.
	 * @param array<int,array<string,mixed>> $answers
	 */
	private function save_question_answers( int $question_id, array $answers ): void {
		foreach ( $answers as $order => $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}
			\Learnomy\Models\Answer::create(
				array(
					'question_id' => $question_id,
					'content'     => wp_kses_post( (string) ( $answer['content'] ?? '' ) ),
					'is_correct'  => ! empty( $answer['is_correct'] ) ? 1 : 0,
					'fraction'    => isset( $answer['fraction'] ) ? (float) $answer['fraction'] : ( ! empty( $answer['is_correct'] ) ? 1.0 : 0.0 ),
					'feedback'    => sanitize_textarea_field( (string) ( $answer['feedback'] ?? '' ) ),
					'group_id'    => sanitize_key( (string) ( $answer['group_id'] ?? '' ) ),
					'sort_order'  => (int) $order + 1,
				)
			);
		}
	}

	/**
	 * Parse {{blank1}}, {{blank2}} tokens out of a fill-in-the-blank question
	 * body, returning the de-duplicated token keys in document order. The keys
	 * match the `group_id` each accepted answer is stored under, which is also
	 * the key the grader expects in the submitted answer map.
	 *
	 * DUPLICATE NOTE: The same regex lives in assets/js/admin/question-editor.js
	 * as parseBlanks(). If the token format ever changes (e.g. [[blank1]]), both
	 * locations must be updated together. The canonical definition of the format
	 * is the PHP side; the JS side is the authoring-time mirror. A future
	 * refactor could move this into Quiz_Service so the controller does not hold
	 * parsing logic.
	 *
	 * @param string $content The question body.
	 * @return array<int,string> Ordered, unique blank keys (e.g. ['blank1','blank2']).
	 */
	private function parse_blank_tokens( string $content ): array {
		if ( '' === $content ) {
			return [];
		}

		if ( ! preg_match_all( '/\{\{\s*(blank[0-9]+)\s*\}\}/i', $content, $matches ) ) {
			return [];
		}

		$keys = [];
		foreach ( $matches[1] as $token ) {
			$key = strtolower( $token );
			if ( ! in_array( $key, $keys, true ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	// -------------------------------------------------------------------------
	// Quiz CRUD
	// -------------------------------------------------------------------------

	public function get_quizzes( WP_REST_Request $request ): WP_REST_Response {
		$user_id    = get_current_user_id();
		$pagination = $this->get_pagination( $request );
		$limit      = $pagination['limit'];
		$after      = $pagination['after'];

		$course_id = absint( $request->get_param( 'course_id' ) );
		$mode      = sanitize_text_field( wp_unslash( $request->get_param( 'mode' ) ?? '' ) );

		$conditions = [];

		// Students only see published quizzes; instructors and admins see everything.
		$is_privileged = $user_id && ( user_can( $user_id, 'learnomy_manage_own_courses' ) || user_can( $user_id, 'manage_options' ) );
		if ( ! $is_privileged ) {
			$conditions['status'] = 'published';
		}

		if ( $course_id > 0 ) {
			$conditions['course_id'] = $course_id;
		}

		if ( $mode && in_array( $mode, [ 'graded', 'practice', 'survey' ], true ) ) {
			$conditions['mode'] = $mode;
		}

		// Pagination is REAL now. `$after` was read at the top of this method and
		// then thrown away, and the offset was hardcoded 0, so every request
		// returned the same first page — 85 quizzes on this install, of which
		// only 5 were ever reachable, while meta advertised a cursor_next that
		// did nothing (Basecamp 10160235647).
		//
		// The cursor is valid here because the ORDER BY is `id DESC`: the sort
		// key and the cursor column are the same, unlike the catalog, which
		// sorted by created_at and needed a composite keyset.
		$offset = 0;
		if ( $after > 0 ) {
			$conditions['id__lt'] = $after;
		} else {
			$offset = max( 0, (int) $pagination['offset'] );
		}

		$quizzes  = Quiz::where( $conditions, 'id', 'DESC', $limit + 1, $offset );
		$has_more = count( $quizzes ) > $limit;

		if ( $has_more ) {
			array_pop( $quizzes );
		}

		if ( $after > 0 ) {
			$quizzes = array_values( array_filter( $quizzes, fn( $q ) => (int) $q->id < $after ) );
		}

		// Dual-format read (R1/INV-8): list rows keep `description` as Editor.js
		// JSON (+ description_html) whatever the row stores, matching get_quiz.
		$quizzes = \Learnomy\Content\Content_HTML::normalize_rows( $quizzes, 'description' );

		// Cursor-based: limit+1 fetch above sets $has_more explicitly.
		return $this->paginated_response( $quizzes, Quiz::count( $conditions ), 0, $has_more );
	}

	public function get_quiz( WP_REST_Request $request ): WP_REST_Response {
		$quiz = Quiz::find( (int) $request->get_param( 'id' ) );
		if ( ! $quiz ) {
			return $this->error_response( $this->not_found( 'Quiz' ) );
		}

		$user_id = get_current_user_id();
		if ( 'published' !== $quiz->status ) {
			if ( ! $user_id || ! $this->check_permission( 'manage_course', (int) $quiz->course_id ) ) {
				return $this->error_response( $this->not_found( 'Quiz' ) );
			}
		}

		$etag         = $this->generate_etag( $quiz );
		$not_modified = $this->maybe_not_modified( $etag, $request );
		if ( $not_modified ) {
			return $not_modified;
		}

		$quiz_arr = $this->filter_fields( Quiz::to_typed_array( $quiz ) ?? [], $request );
		// Question count for the app's pre-attempt screen ("18 questions"). Cheap
		// COUNT — no rows loaded — so the intro can set expectations before start.
		$quiz_arr['question_count'] = QuizSlot::count( [ 'quiz_id' => (int) $quiz->id ] );
		// Dual-format read (INV-8): description always returns Editor.js JSON.
		if ( isset( $quiz_arr['description'] ) && is_string( $quiz_arr['description'] ) ) {
			$raw                          = $quiz_arr['description'];
			$quiz_arr['description']      = \Learnomy\Content\Content_HTML::for_editor( $raw );  // Editor.js JSON — the app today.
			$quiz_arr['description_html'] = \Learnomy\Content\Content_HTML::for_storage( $raw );  // Canonical HTML — Phase 6 opt-in.
		}
		$response = $this->single_response(
			$quiz_arr,
			[ 'self' => [ [ 'href' => rest_url( $this->namespace . '/quizzes/' . $quiz->id ) ] ] ]
		);

		return $this->add_etag( $response, $etag );
	}

	public function create_quiz( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$data = $this->extract_quiz_fields( $request, true );
		if ( empty( $data['title'] ) ) {
			return $this->error_response( $this->validation_error( __( 'A quiz title is required.', 'learnomy' ) ) );
		}

		$data['author_id'] = $user_id;
		$data['status']    = $data['status'] ?? 'draft';

		// Seed any column the caller didn't supply from the admin "Quiz
		// Settings" defaults. Explicit request values win — see
		// Quiz_Defaults::apply_seed_defaults() for the per-field mapping.
		// Runs after extract so the seeded values share the same shape as
		// caller-supplied ones (column names, not REST field names).
		$data = Quiz_Defaults::apply_seed_defaults( $data );

		$quiz_id = Quiz::create( $data );

		if ( ! $quiz_id ) {
			return $this->error_response( $this->validation_error( __( 'Could not save the quiz. Please try again.', 'learnomy' ) ) );
		}

		$tag_ids_param = $request->get_param( 'tag_ids' );
		if ( is_array( $tag_ids_param ) ) {
			Tag::sync_object_tags( $quiz_id, 'quiz', $tag_ids_param );
		}

		do_action( 'learnomy_quiz_created', $quiz_id, $data );
		/**
		 * Fires after a quiz is created or updated via REST, carrying the request,
		 * so an integration can persist its own quiz fields (rendered via
		 * learnomy_quiz_editor_fields).
		 *
		 * @param int             $quiz_id The saved quiz.
		 * @param WP_REST_Request $request The create/update request.
		 */
		do_action( 'learnomy_quiz_saved', $quiz_id, $request );

		$response = $this->single_response( Quiz::to_typed_array( Quiz::find( $quiz_id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_quiz( WP_REST_Request $request ): WP_REST_Response {
		$quiz = Quiz::find( (int) $request->get_param( 'id' ) );
		if ( ! $quiz ) {
			return $this->error_response( $this->not_found( 'Quiz' ) );
		}

		$data       = $this->extract_quiz_fields( $request, false );
		$slot_marks = $request->get_param( 'slot_marks' );
		$tag_ids    = $request->get_param( 'tag_ids' );

		if ( empty( $data ) && ! is_array( $slot_marks ) && ! is_array( $tag_ids ) ) {
			return $this->error_response( $this->validation_error( __( 'No valid fields were provided for update.', 'learnomy' ) ) );
		}

		if ( ! empty( $data ) ) {
			$data['updated_at'] = now();
			Quiz::update( (int) $quiz->id, $data );
		}

		if ( is_array( $tag_ids ) ) {
			Tag::sync_object_tags( (int) $quiz->id, 'quiz', $tag_ids );
		}

		// Optional per-slot marks update — each entry goes through QuizSlot so
		// we never write to lrn_quiz_slots from the controller directly.
		if ( is_array( $slot_marks ) ) {
			$this->apply_slot_marks( (int) $quiz->id, $slot_marks );
		}

		do_action( 'learnomy_quiz_updated', (int) $quiz->id, $data );
		/** Documented in create_quiz(). */
		do_action( 'learnomy_quiz_saved', (int) $quiz->id, $request );

		return $this->single_response( Quiz::to_typed_array( Quiz::find( (int) $quiz->id ) ) );
	}

	/**
	 * Validate + persist per-slot marks overrides.
	 *
	 * The payload is `[ slot_id => marks ]`. Slots not belonging to this quiz
	 * are silently skipped — they can't be trusted even if the client sends
	 * them. Marks are clamped to decimal(5,2) shape so malformed floats do
	 * not trip the DB layer.
	 *
	 * @param int                     $quiz_id Quiz whose slots are being re-marked.
	 * @param array<int|string,mixed> $slot_marks
	 */
	private function apply_slot_marks( int $quiz_id, array $slot_marks ): void {
		$valid_ids = array_map(
			fn( $slot ) => (int) $slot->id,
			QuizSlot::get_for_quiz( $quiz_id )
		);

		foreach ( $slot_marks as $slot_id => $marks ) {
			$slot_id = (int) $slot_id;
			if ( ! in_array( $slot_id, $valid_ids, true ) ) {
				continue;
			}
			$clamped = max( 0.0, min( 999.99, (float) $marks ) );
			QuizSlot::update( $slot_id, [ 'marks' => round( $clamped, 2 ) ] );
		}
	}

	public function delete_quiz( WP_REST_Request $request ): WP_REST_Response {
		$quiz = Quiz::find( (int) $request->get_param( 'id' ) );
		if ( ! $quiz ) {
			return $this->error_response( $this->not_found( 'Quiz' ) );
		}

		// Cascade through the service so slots, attempts, and responses are
		// removed too — a bare Quiz::delete() orphans all of them.
		if ( ! ( new Quiz_Service() )->delete_quiz( (int) $quiz->id ) ) {
			return $this->error_response( $this->validation_error( __( 'The quiz could not be deleted. Please try again.', 'learnomy' ) ) );
		}

		return $this->single_response(
			[
				'deleted' => true,
				'id'      => (int) $quiz->id,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Attempt lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Verify a quiz access code without consuming an attempt.
	 *
	 * Drives the pre-start gate in templates/partials/quiz-gate.php. Using
	 * hash_equals() to avoid the timing-attack side channel a naive `===`
	 * comparison would leak. On success we persist a per-quiz usermeta flag
	 * so the gate can fall through on the next page load — the student
	 * doesn't have to re-enter the code every time they reload mid-attempt.
	 */
	public function verify_access_code( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$quiz = Quiz::find( (int) $request->get_param( 'id' ) );
		if ( ! $quiz ) {
			return $this->error_response( $this->not_found( 'Quiz' ) );
		}

		// No code set → treat as verified so the gate never re-renders.
		if ( empty( $quiz->access_code ) ) {
			return $this->single_response( [ 'verified' => true ] );
		}

		$submitted = (string) $request->get_param( 'access_code' );
		if ( ! hash_equals( (string) $quiz->access_code, $submitted ) ) {
			$error = new WP_Error(
				'invalid_access_code',
				__( 'The access code you entered is incorrect.', 'learnomy' ),
				[ 'status' => 403 ]
			);
			return $this->error_response( $error );
		}

		update_user_meta( $user_id, 'learnomy_quiz_' . (int) $quiz->id . '_code_verified', 1 );

		return $this->single_response( [ 'verified' => true ] );
	}

	public function start_attempt( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$rate_check = $this->check_rate_limit( $user_id, 'take_quiz' );
		if ( is_wp_error( $rate_check ) ) {
			return $this->error_response( $rate_check );
		}

		$quiz = Quiz::find( (int) $request->get_param( 'id' ) );
		if ( ! $quiz ) {
			return $this->error_response( $this->not_found( 'Quiz' ) );
		}

		$enrollment_id = absint( $request->get_param( 'enrollment_id' ) ) ?: null;
		$access_code   = $request->get_param( 'access_code' );
		$access_code   = null === $access_code ? null : sanitize_text_field( (string) $access_code );

		try {
			$service    = new Quiz_Service();
			$attempt_id = $service->start_attempt( $user_id, (int) $quiz->id, $enrollment_id, $access_code );
		} catch ( \Learnomy\InvalidQuizAccessCode $e ) {
			// Wrong code → 403 with a discriminable error code so the client
			// can prompt for the correct code without parsing message text.
			$error = new WP_Error(
				'invalid_access_code',
				$e->getMessage() ?: __( 'The access code you entered is incorrect.', 'learnomy' ),
				[ 'status' => 403 ]
			);
			return $this->error_response( $error );
		} catch ( \Learnomy\QuizCooldownActive $e ) {
			// Too-many-requests fits the semantic of "wait then retry".
			$error = new WP_Error(
				'quiz_cooldown_active',
				$e->getMessage(),
				[
					'status'            => 429,
					'seconds_remaining' => $e->get_seconds_remaining(),
				]
			);
			return $this->error_response( $error );
		} catch ( \Learnomy\QuizLockedOut $e ) {
			// 423 Locked communicates that resource access is intentionally
			// closed — only an instructor reset can reopen the quiz.
			$error = new WP_Error(
				'quiz_locked_out',
				$e->getMessage(),
				[
					'status'          => 423,
					'failed_attempts' => $e->get_failed_attempts(),
				]
			);
			return $this->error_response( $error );
		} catch ( \Learnomy\QuizAttemptLimitReached $e ) {
			// 422: the request is well-formed, the student has simply spent every
			// attempt. Distinct from cooldown (429, wait) and lockout (423, ask an
			// instructor) so the player can retire "Try Again" instead of guessing
			// from the message.
			$error = new WP_Error(
				'quiz_attempt_limit_reached',
				$e->getMessage(),
				[
					'status'        => 422,
					'attempt_limit' => $e->get_attempt_limit(),
				]
			);
			return $this->error_response( $error );
		} catch ( \RuntimeException $e ) {
			return $this->error_response( $this->validation_error( $e->getMessage() ) );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error_response( $this->not_found( 'Quiz' ) );
		}

		$attempt = QuizAttempt::find( $attempt_id );

		// ── A4: the paper is a fact about the ATTEMPT, decided once at start ──
		// Read the frozen runner state BEFORE building anything, because it
		// decides WHICH questions get built, not merely what order they end up
		// in. A resumed attempt is served the paper it froze; only a fresh
		// attempt resolves slots and lets Pro replace the set. Re-resolving on
		// resume re-rolls Pro's random pool (`ORDER BY RAND()` in
		// Pool_Service::resolve_pool) and hands the student a completely
		// different paper, orphaning every answer already saved — the freeze
		// used to run after the swap and could only reorder what it was given
		// (Basecamp 10132809157).
		$runner_state = QuizAttempt::get_runner_state( (int) $attempt->id );
		$frozen_order = array_values( array_filter( array_map( 'intval', (array) ( $runner_state['frozen_order'] ?? [] ) ) ) );
		$is_resume    = ! empty( $frozen_order );

		$questions_data  = [];
		$questions       = [];
		$marks_by_qid    = [];
		$prior_responses = [];

		if ( $is_resume ) {
			// Rehydrate exactly the frozen paper, in the frozen order. find_in()
			// applies no status filter, so a question unpublished mid-attempt
			// still comes back — the student was served it, so they keep it. A
			// question DELETED mid-attempt has no row left to render and drops
			// out; scoring skips the same missing row rather than charging the
			// student for it (see Quiz_Service::auto_grade).
			$q_map = Question::find_in( $frozen_order );
			foreach ( $frozen_order as $frozen_qid ) {
				if ( isset( $q_map[ $frozen_qid ] ) ) {
					$questions[] = $q_map[ $frozen_qid ];
				}
			}

			// What each question is worth was frozen with the paper. Never
			// re-derive it from today's slots: a pooled question has no slot row
			// at all, and an instructor re-weighting the quiz mid-attempt must
			// not change what this sitting is out of. Same map grading reads
			// (Quiz_Service::marks_for_attempt).
			foreach ( (array) ( $runner_state['frozen_marks'] ?? [] ) as $frozen_qid => $frozen_mark ) {
				$marks_by_qid[ (int) $frozen_qid ] = (float) $frozen_mark;
			}
		} else {
			// Fresh start: resolve the slot questions into objects + a
			// per-question marks map, then let Pro filter/replace the set via
			// learnomy_pro_quiz_filter_questions (advanced-quizzes hooks this
			// for question pools + conditional logic). A fresh attempt has no
			// responses yet, so conditional rules pass through; the pool handler
			// swaps in its randomly drawn questions. With no Pro handler the set
			// returns unchanged, so default quizzes are unaffected.
			// Pool-injected questions inherit a default mark of 1.0.
			// Batch-load every slot question in one query (was a Question::find
			// per slot — N queries on every quiz start). find_in() returns
			// [id => row]; we iterate $slots to preserve slot order.
			$slots = QuizSlot::get_for_quiz( (int) $quiz->id );
			$q_map = Question::find_in( array_map( static fn( $s ) => (int) $s->question_id, $slots ) );
			foreach ( $slots as $slot ) {
				$qid = (int) $slot->question_id;
				if ( ! isset( $q_map[ $qid ] ) ) {
					continue;
				}
				$marks_by_qid[ $qid ] = (float) $slot->marks;
				$questions[]          = $q_map[ $qid ];
			}

			/**
			 * Filter the question objects a quiz attempt presents. Receives the
			 * Question row objects, the quiz id, and the attempt's current
			 * responses (empty on a fresh start). Pro returns the (possibly
			 * replaced or filtered) set.
			 *
			 * Start-time only. The result is frozen onto the attempt below and
			 * replayed verbatim on every resume, so a handler that draws a
			 * random set draws it once per attempt, not once per request.
			 */
			$questions = (array) apply_filters( 'learnomy_pro_quiz_filter_questions', $questions, (int) $quiz->id, [] );

			// A fresh build for an attempt that already holds answers means the
			// attempt predates the freeze; keep those answers on the paper.
			$prior_responses = QuestionResponse::get_for_attempt( (int) $attempt->id );
			$questions       = $this->recover_pre_freeze_answers( $prior_responses, $questions );

			/**
			 * Filter what each question on THIS paper is worth: question id => marks.
			 *
			 * The twin of `learnomy_pro_quiz_filter_questions` — that filter decides
			 * WHICH questions the paper holds, this one decides what they are worth,
			 * and it runs immediately after so a handler that replaced the set can
			 * price the set it produced. The result is frozen onto the attempt below
			 * and is the map grading reads back
			 * ({@see \Learnomy\Services\Quiz_Service::marks_for_attempt()}).
			 *
			 * It exists because the slot-derived map handed in here cannot price a
			 * question the quiz does not own. Pro's random pool draws from the whole
			 * question bank, and a drawn question has no slot row, so it fell through
			 * to the 1.0 default below — except when it HAPPENED to also sit in a
			 * slot of this quiz, where it silently inherited that slot's marks. Two
			 * students sitting the same pooled quiz were then marked out of different
			 * denominators, decided by which questions the shuffle drew.
			 *
			 * Start-time only, exactly like its twin: a handler must not price a
			 * paper differently on resume, or an in-flight sitting would be
			 * re-weighted under the student.
			 *
			 * @param array<int,float> $marks_by_qid Marks keyed by question id, from this quiz's slots.
			 * @param object[]         $questions    The final question set for this paper.
			 * @param int              $quiz_id      Quiz being started.
			 */
			$marks_by_qid = $this->normalize_marks_map(
				apply_filters( 'learnomy_pro_quiz_filter_marks', $marks_by_qid, $questions, (int) $quiz->id )
			);
		}

		// Batch-load answers for the final (possibly pool-swapped) question set in
		// ONE query, grouped by question_id — replaces a per-question
		// Answer::get_for_question() call inside the render loop below.
		$answers_by_qid = Answer::get_for_questions(
			array_map( static fn( $q ) => (int) $q->id, array_filter( $questions, 'is_object' ) )
		);

		// Batch-load category names for the question set in ONE query (A2 — the
		// player groups by category and the results screen scores per category).
		// [category_id => name]; uncategorised (0) falls back to "General" client-side.
		$category_ids   = array_values(
			array_unique(
				array_filter(
					array_map( static fn( $q ) => is_object( $q ) ? (int) ( $q->category_id ?? 0 ) : 0, $questions )
				)
			)
		);
		$category_names = [];
		foreach ( $category_ids ? QuestionCategory::find_in( $category_ids ) : [] as $cat ) {
			$category_names[ (int) $cat->id ] = (string) $cat->name;
		}

		foreach ( $questions as $q ) {
			if ( ! is_object( $q ) || empty( $q->id ) ) {
				continue;
			}

			$answers_data = [];
			if ( in_array( $q->type, [ 'mc_single', 'mc_multi', 'true_false' ], true ) ) {
				$answers_raw = $answers_by_qid[ (int) $q->id ] ?? [];
				foreach ( $answers_raw as $a ) {
					$answer_item = [
						'id'      => (int) $a->id,
						'content' => $a->content ?? '',
					];

					// Shuffle answers if quiz setting is enabled.
					$answers_data[] = $answer_item;
				}
			} elseif ( 'ordering' === $q->type ) {
				// Ordering: expose items stripped of sort_order metadata. The
				// correct order lives in sort_order ASC — we deliberately
				// shuffle before emitting so the student sees them scrambled.
				$answers_raw = $answers_by_qid[ (int) $q->id ] ?? [];
				foreach ( $answers_raw as $a ) {
					$answers_data[] = [
						'id'      => (int) $a->id,
						'content' => $a->content ?? '',
					];
				}
				// Always shuffle ordering items so the starting order is never the correct order.
				if ( count( $answers_data ) > 1 ) {
					shuffle( $answers_data );
				}
			} elseif ( 'matching' === $q->type ) {
				// Matching: split by group_id = prompt|match (see Pro grader
				// class-grader.php::grade_matching). Correct pairing is by
				// sort_order. Right column ("match") is shuffled so the
				// pairing is not trivially implied by row order.
				$answers_raw = $answers_by_qid[ (int) $q->id ] ?? [];
				$prompts     = [];
				$matches     = [];
				foreach ( $answers_raw as $a ) {
					if ( 'prompt' === ( $a->group_id ?? '' ) ) {
						$prompts[] = [
							'id'      => (int) $a->id,
							'content' => $a->content ?? '',
							'side'    => 'left',
						];
					} elseif ( 'match' === ( $a->group_id ?? '' ) ) {
						$matches[] = [
							'id'      => (int) $a->id,
							'content' => $a->content ?? '',
							'side'    => 'right',
						];
					}
				}
				if ( count( $matches ) > 1 ) {
					shuffle( $matches );
				}
				// Emit left column first, then right column. Client splits
				// back by `side`.
				$answers_data = array_merge( $prompts, $matches );
			}

			// Only shuffle for simple MC-style types. Ordering handles its own
			// shuffle above; matching shuffles only the right column above.
			if (
				! empty( $quiz->shuffle_answers )
				&& count( $answers_data ) > 1
				&& in_array( $q->type, [ 'mc_single', 'mc_multi', 'true_false' ], true )
			) {
				shuffle( $answers_data );
			}

			$q_category_id = (int) ( $q->category_id ?? 0 );

			$question_payload = [
				'id'            => (int) $q->id,
				'title'         => $q->title ?? '',
				'type'          => $q->type ?? 'mc_single',
				'hint'          => $q->hint ?? null,
				'marks'         => $marks_by_qid[ (int) $q->id ] ?? 1.0,
				'answers'       => $answers_data,
				'category_id'   => $q_category_id,
				'category_name' => $category_names[ $q_category_id ] ?? '',
			];

			// Fill-in-the-blank: the question body holds {{blank1}}, {{blank2}}
			// tokens and each accepted answer is grouped by group_id = that
			// token. Emit the body + the ordered list of blank keys so the
			// player can render one input per blank and submit a map keyed by
			// the same group_id that Quiz_Service::score_fill_blank() expects.
			if ( 'fill_blank' === $q->type ) {
				$raw_content                = (string) ( $q->content ?? '' );
				$question_payload['blanks'] = $this->parse_blank_tokens( $raw_content );
				// Replace the {{blankN}} tokens with a visible underscore run so
				// the prompt reads "The capital of _____ is _____." instead of
				// leaking the raw token syntax; the answer inputs render below.
				$question_payload['content'] = preg_replace( '/\{\{\s*blank[0-9]+\s*\}\}/i', '_____', $raw_content );
			}

			// A3: the rich stem (dedicated `stem` column) — an optional passage /
			// code / image shown above the answers, for EVERY question type
			// (fill_blank included). Rendered through the one content engine (HTML /
			// Editor.js JSON / legacy plain). Empty when the question has no stem.
			$raw_stem                     = (string) ( $q->stem ?? '' );
			$question_payload['stemHtml'] = '' !== trim( $raw_stem )
				? \Learnomy\render_editorjs_content( $raw_stem, '', 'html' )
				: '';

			$questions_data[] = $question_payload;
		}

		// Shuffle questions if quiz setting is enabled. Never on resume: the
		// order the student was already shown is the frozen order, and it is
		// already the order $questions_data was built in.
		if ( ! $is_resume && ! empty( $quiz->shuffle_questions ) && count( $questions_data ) > 1 ) {
			shuffle( $questions_data );
		}

		// A2: when grouping by category, order questions so each category is
		// contiguous (a stable sort by category name preserves the prior order —
		// shuffled or slot order — WITHIN each category). The player then renders
		// one heading per category run. Uncategorised sinks to the end under
		// "General" (empty name sorts last via the \xff sentinel). Skipped on
		// resume for the same reason as the shuffle: re-sorting a frozen paper
		// would move questions under the student mid-sitting if a question were
		// recategorised, and the grouping is already baked into frozen_order.
		$group_by_category = ! empty( $quiz->group_by_category );
		if ( ! $is_resume && $group_by_category && count( $questions_data ) > 1 ) {
			$ordered = array_values( $questions_data );
			usort(
				$ordered,
				static function ( $a, $b ): int {
					$an = '' !== ( $a['category_name'] ?? '' ) ? $a['category_name'] : "\xff";
					$bn = '' !== ( $b['category_name'] ?? '' ) ? $b['category_name'] : "\xff";
					return strcasecmp( $an, $bn );
				}
			);
			$questions_data = $ordered;
		}

		// ── A4: freeze-on-start / restore-on-resume ───────────────────────────
		// All exam-runner state lives in the attempt's saved_state_json (reserved
		// longtext, no schema change). A fresh attempt has none → freeze the
		// just-built question set + order + a wall-clock deadline. A resumed
		// in_progress attempt already has it → the set and order were restored
		// from it above (that is WHY it is read before the questions are built);
		// what is left here is the answer-option order and the REMAINING time,
		// so a learner can neither re-roll the random set nor win back time by
		// reopening the quiz (the LearnDash resume rule / the Tutor wall-clock fix).
		$time_limit_sec = $quiz->time_limit_seconds ? (int) $quiz->time_limit_seconds : null;

		if ( $is_resume ) {
			// Restore the frozen per-question answer-option order so a resumed
			// attempt shows options in the SAME order the student first saw
			// (shuffle_answers re-rolls the order per start otherwise). BC #10056240987.
			$frozen_options = (array) ( $runner_state['frozen_options'] ?? [] );
			if ( $frozen_options ) {
				foreach ( $questions_data as $qi => $qd ) {
					$opt_order = $frozen_options[ (int) $qd['id'] ] ?? null;
					if ( empty( $opt_order ) || empty( $qd['answers'] ) ) {
						continue;
					}
					$by_aid = [];
					foreach ( $qd['answers'] as $a ) {
						$by_aid[ (int) ( $a['id'] ?? 0 ) ] = $a;
					}
					$reordered = [];
					foreach ( (array) $opt_order as $aid ) {
						if ( isset( $by_aid[ (int) $aid ] ) ) {
							$reordered[] = $by_aid[ (int) $aid ];
							unset( $by_aid[ (int) $aid ] );
						}
					}
					// Append any options not in the frozen list (question edited
					// mid-attempt) so nothing silently disappears.
					foreach ( $by_aid as $a ) {
						$reordered[] = $a;
					}
					if ( $reordered ) {
						$questions_data[ $qi ]['answers'] = $reordered;
					}
				}
			}

			// Timer resume policy (filterable). Default 'wall_clock': the deadline
			// is fixed at start, so resume returns the REMAINING wall-clock time — a
			// real exam can't be paused by reopening it. A site can opt a practice
			// quiz into 'pause_on_resume' so the clock stops while away and continues
			// from where it left off.
			$resume_policy = (string) apply_filters( 'learnomy_quiz_timer_resume_policy', 'wall_clock', $quiz, $attempt );
			if ( 'pause_on_resume' === $resume_policy && null !== $time_limit_sec && isset( $runner_state['remaining'] ) ) {
				$time_limit_sec = max( 0, (int) $runner_state['remaining'] );
				// Re-anchor the deadline so the now-running clock stays consistent.
				QuizAttempt::save_runner_state(
					(int) $attempt->id,
					[ 'deadline' => $time_limit_sec ? gmdate( 'c', time() + $time_limit_sec ) : '' ]
				);
			} elseif ( ! empty( $runner_state['deadline'] ) ) {
				$time_limit_sec = max( 0, strtotime( (string) $runner_state['deadline'] ) - time() );
			}
		} else {
			// Fresh start: freeze the question order, what each question is worth,
			// the per-question answer-option order (so shuffle_answers doesn't
			// re-roll on resume, BC #10056240987), and the wall-clock deadline.
			$frozen_options = [];
			$frozen_marks   = [];
			foreach ( $questions_data as $qd ) {
				$frozen_marks[ (int) $qd['id'] ] = (float) ( $qd['marks'] ?? 1.0 );
				if ( ! empty( $qd['answers'] ) && is_array( $qd['answers'] ) ) {
					$frozen_options[ (int) $qd['id'] ] = array_map(
						static fn( $a ) => (int) ( $a['id'] ?? 0 ),
						$qd['answers']
					);
				}
			}
			QuizAttempt::save_runner_state(
				(int) $attempt->id,
				[
					'frozen_order'   => array_map( static fn( $qd ) => (int) $qd['id'], $questions_data ),
					// The marks this sitting is out of, question by question. Grading
					// reads THIS rather than re-deriving from the quiz, so the paper the
					// student sat is the paper they are marked on: a Pro random pool
					// draws questions that have no slot row at all, and editing a quiz
					// after the fact must not silently re-weight a finished attempt.
					'frozen_marks'   => $frozen_marks,
					'frozen_options' => $frozen_options,
					'deadline'       => $time_limit_sec ? gmdate( 'c', time() + $time_limit_sec ) : '',
					'current_index'  => 0,
					'flagged'        => [],
				]
			);
		}

		// On resume, surface saved answers so the player pre-fills. The live
		// in-progress map lives in saved_state_json (written by /attempts/{id}/state
		// as the learner answers — works for every feedback timing). Fall back to
		// per-question responses for older attempts / per-question-feedback quizzes.
		//
		// Answers already recorded are the test for "coming back to a sitting",
		// not the frozen paper: a pre-freeze attempt is being frozen for the
		// first time on THIS request, so it takes the fresh-start branch above
		// while still being a resume to the student in front of it. Keying the
		// hydration off the frozen paper alone showed that student their own
		// answers as blank, once, on the first load after the upgrade.
		$saved_answers = [];
		if ( ! empty( $runner_state['answers'] ) && is_array( $runner_state['answers'] ) ) {
			$saved_answers = $runner_state['answers'];
		} elseif ( $is_resume || $prior_responses ) {
			$responses = $prior_responses ?: QuestionResponse::get_for_attempt( (int) $attempt->id );
			foreach ( $responses as $saved_resp ) {
				$saved_answers[ (int) $saved_resp->question_id ] = json_decode( (string) ( $saved_resp->response_json ?? '' ), true );
			}
		}

		$player_context = [
			'attempt_id'            => (int) $attempt->id,
			'status'                => $attempt->status,
			'quiz_id'               => (int) $quiz->id,
			'time_limit'            => $time_limit_sec,
			'navigation'            => $quiz->navigation ?? 'free',
			'section_navigation'    => $quiz->section_navigation ?? 'linear',
			'questions_per_section' => max( 1, (int) ( $quiz->questions_per_section ?? 10 ) ),
			'feedback_timing'       => $quiz->feedback_timing ?? 'after_submit',
			'group_by_category'     => $group_by_category,
			// Proctoring: auto-submit after N tab switches (0 = off). Only
			// meaningful when tab tracking is on; the server re-checks on each
			// recorded switch, so this is the player's early signal.
			'tab_switch_limit'      => \Learnomy\Services\Quiz_Defaults::is_tab_tracking_enabled()
				? \Learnomy\Services\Quiz_Defaults::tab_switch_auto_submit_limit( $quiz )
				: 0,
			'questions'             => $questions_data,
			// A4 resume payload — the player hydrates these when is_resume is
			// true, so it reports what the STUDENT is doing (picking a sitting
			// back up), which includes the one-off pre-freeze attempt that only
			// gets its paper frozen on this very request.
			'is_resume'             => $is_resume || (bool) $saved_answers,
			'current_index'         => (int) ( $runner_state['current_index'] ?? 0 ),
			'flagged'               => array_values( array_map( 'intval', (array) ( $runner_state['flagged'] ?? [] ) ) ),
			'saved_answers'         => (object) $saved_answers,
		];

		/**
		 * Filter the quiz player context payload sent to the client on quiz start.
		 *
		 * Pro extensions (advanced-quizzes) use this to inject per-slot timer data
		 * and other per-question metadata without modifying this controller.
		 *
		 * @since 1.0.0
		 * @param array $player_context The full start-attempt payload.
		 * @param int   $quiz_id        The quiz being started.
		 * @param int   $attempt_id     The new attempt ID.
		 */
		$player_context = (array) apply_filters( 'learnomy_quiz_player_context', $player_context, (int) $quiz->id, (int) $attempt->id );

		$response = $this->single_response( $player_context );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Fold already-answered questions back into a paper being built for the
	 * first time, so an attempt that predates the freeze keeps its answers.
	 *
	 * Only reachable on the fresh-start branch, which an in-progress attempt
	 * takes exactly once: when it was started before frozen_order existed (or
	 * before this fix, when a resumed pool attempt re-rolled and overwrote it).
	 * Such an attempt has responses but no record of the paper they belong to,
	 * and rebuilding it from a random pool draws a different set — the very
	 * orphaning the freeze exists to prevent, one last time on the upgrade.
	 *
	 * Recovered questions go to the front and the fresh draw is trimmed to keep
	 * the paper its configured length. The result is then frozen by the caller,
	 * so this runs at most once per attempt, ever.
	 *
	 * A genuinely new attempt has no responses, so this returns the set
	 * untouched.
	 *
	 * @param array $prior_responses Response rows already recorded for the attempt.
	 * @param array $questions       Freshly resolved Question rows.
	 * @return array Question rows, with any orphaned answered question restored.
	 */
	private function recover_pre_freeze_answers( array $prior_responses, array $questions ): array {
		$answered = [];
		foreach ( $prior_responses as $prior ) {
			$answered[] = (int) $prior->question_id;
		}
		if ( ! $answered ) {
			return $questions;
		}

		$built    = array_map( static fn( $q ) => (int) $q->id, array_filter( $questions, 'is_object' ) );
		$orphaned = array_values( array_diff( array_unique( $answered ), $built ) );
		if ( ! $orphaned ) {
			return $questions;
		}

		$orphan_map = Question::find_in( $orphaned );
		$recovered  = [];
		foreach ( $orphaned as $orphan_id ) {
			if ( isset( $orphan_map[ $orphan_id ] ) ) {
				$recovered[] = $orphan_map[ $orphan_id ];
			}
		}
		if ( ! $recovered ) {
			return $questions;
		}

		$keep = max( 0, count( $questions ) - count( $recovered ) );

		return array_merge( $recovered, array_slice( array_values( $questions ), 0, $keep ) );
	}

	/**
	 * Coerce a filtered marks map back into the shape the freeze stores:
	 * `[ int question id => positive float ]`.
	 *
	 * A negative entry is dropped, so the question falls through to the same 1.0
	 * default the payload and
	 * {@see \Learnomy\Services\Quiz_Service::marks_for_question()} apply: every
	 * downstream consumer multiplies by this number, and a negative would let a
	 * paper's total shrink below what the student can earn.
	 *
	 * Zero is deliberately KEPT. A slot may be set to 0 marks through the quiz
	 * editor, which is a real instructor choice ("this one doesn't count"), and
	 * this guard exists to reject a malformed map — not to overrule a weight Free
	 * already accepts. The pool never sends one: `Pool_Service::sanitise_marks()`
	 * has its own floor.
	 *
	 * @param mixed $marks Whatever the filter returned.
	 * @return array<int,float>
	 */
	private function normalize_marks_map( $marks ): array {
		$clean = [];
		foreach ( (array) $marks as $question_id => $value ) {
			$question_id = (int) $question_id;
			$value       = (float) $value;
			if ( $question_id > 0 && $value >= 0 ) {
				$clean[ $question_id ] = $value;
			}
		}
		return $clean;
	}

	public function answer_question( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$attempt = QuizAttempt::find( (int) $request->get_param( 'attempt_id' ) );
		if ( ! $attempt ) {
			return $this->error_response( $this->not_found( 'Attempt' ) );
		}

		$question_id  = absint( $request->get_param( 'question_id' ) );
		$raw_response = $request->get_param( 'response' );

		// Sanitize the response value — it can be a scalar or an array of answer IDs.
		if ( is_array( $raw_response ) ) {
			$response_data = array_map( 'sanitize_text_field', $raw_response );
		} else {
			$response_data = sanitize_text_field( wp_unslash( (string) $raw_response ) );
		}

		try {
			$service = new Quiz_Service();
			$service->answer_question( (int) $attempt->id, $question_id, $response_data );
		} catch ( \RuntimeException $e ) {
			return $this->error_response( $this->validation_error( $e->getMessage() ) );
		}

		// Per-question feedback — served for `immediate` (full reveal) and
		// `after_question` (correctness boolean only). The latter deliberately
		// omits correct_ids + explanation so the student sees ✓/✗ inline
		// without learning the full solution before submit.
		$feedback_data   = null;
		$quiz            = Quiz::find( (int) $attempt->quiz_id );
		$feedback_timing = $quiz ? ( $quiz->feedback_timing ?? 'after_submit' ) : 'after_submit';

		if ( in_array( $feedback_timing, [ 'immediate', 'after_question' ], true ) ) {
			$question = Question::find( $question_id );
			if ( $question && in_array( $question->type, [ 'mc_single', 'mc_multi', 'true_false', 'short_answer', 'fill_blank' ], true ) ) {
				// Delegate the correctness decision to the service so that partial-credit
				// MC questions (custom fractions set via the editor's fraction slider) are
				// reported correctly. The old inline array_intersect only checked is_correct
				// flags and would mark a 0.3-fraction distractor as "correct".
				$is_correct  = $service->is_response_correct( $question_id, $question->type, $response_data );
				$correct_ids = [];

				// For the `immediate` reveal we also need the canonical correct answer IDs
				// so the player can highlight them. Text-answer types have no IDs to show.
				if ( 'immediate' === $feedback_timing && ! in_array( $question->type, [ 'short_answer', 'fill_blank' ], true ) ) {
					$correct_answers = Answer::where(
						[
							'question_id' => $question_id,
							'is_correct'  => 1,
						]
					);
					$correct_ids     = array_map( fn( $a ) => (int) $a->id, $correct_answers );
				}

				$feedback_data = [ 'correct' => $is_correct ];
				if ( 'immediate' === $feedback_timing ) {
					$feedback_data['correct_ids'] = $correct_ids;
					$feedback_data['explanation'] = $question->explanation ?? '';
				}
			}
		}

		return $this->single_response(
			[
				'attempt_id'  => (int) $attempt->id,
				'question_id' => $question_id,
				'recorded'    => true,
				'feedback'    => $feedback_data,
			]
		);
	}

	public function submit_attempt( WP_REST_Request $request ): WP_REST_Response {
		$attempt = QuizAttempt::find( (int) $request->get_param( 'attempt_id' ) );
		if ( ! $attempt ) {
			return $this->error_response( $this->not_found( 'Attempt' ) );
		}

		// A4: advisory late-submission flag. If a timed attempt is submitted well
		// past its frozen deadline (beyond a grace window for autosubmit / network
		// lag), record that fact in saved_state_json so the instructor sees it on
		// review. Advisory only — we never void answers (matches the tab-switch
		// stance); a normal autosubmit fires AT the deadline and stays inside grace.
		$submit_runner_state = QuizAttempt::get_runner_state( (int) $attempt->id );
		if ( ! empty( $submit_runner_state['deadline'] ) ) {
			$seconds_over = time() - strtotime( (string) $submit_runner_state['deadline'] );
			$grace        = (int) apply_filters( 'learnomy_quiz_submit_grace_seconds', 60, $attempt );
			if ( $seconds_over > $grace ) {
				QuizAttempt::save_runner_state(
					(int) $attempt->id,
					[
						'late_submission' => true,
						'seconds_over'    => $seconds_over,
					]
				);
			}
		}

		// Accept batch answers — record all answers before grading.
		$answers = $request->get_param( 'answers' );
		if ( is_array( $answers ) && ! empty( $answers ) ) {
			$service = new Quiz_Service();
			foreach ( $answers as $question_id => $response_data ) {
				$question_id = absint( $question_id );
				if ( ! $question_id ) {
					continue;
				}
				if ( is_array( $response_data ) ) {
					$response_data = array_map( 'sanitize_text_field', $response_data );
				} else {
					$response_data = sanitize_text_field( wp_unslash( (string) $response_data ) );
				}
				try {
					$service->answer_question( (int) $attempt->id, $question_id, $response_data );
				} catch ( \RuntimeException $e ) {
					// Skip individual answer errors — continue recording the rest.
					continue;
				}
			}
		}

		try {
			$service        = $service ?? new Quiz_Service();
			$graded_attempt = $service->submit_attempt( (int) $attempt->id );
		} catch ( \RuntimeException $e ) {
			return $this->error_response( $this->validation_error( $e->getMessage() ) );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error_response( $this->not_found( 'Attempt' ) );
		}

		// Feedback timing drives how much of the graded payload we expose to
		// the student. Five values with distinct shapes:
		// immediate / after_submit → full review with correct answers + explanations.
		// after_question           → correctness per question, no correct answers or explanations.
		// after_attempt            → score + pass/fail only; no per-question data.
		// never                    → { submitted: true } only — used for surveys.
		$quiz            = Quiz::find( (int) $attempt->quiz_id );
		$feedback_timing = $quiz ? ( $quiz->feedback_timing ?? 'after_submit' ) : 'after_submit';

		// How many attempts the student has left AFTER this submission, on the
		// same count_graded() basis the start gate and the start screen use — so
		// the client can retire a stale "Try Again" instead of re-POSTing /start
		// into a 400 (Basecamp 10096673115). null = unlimited.
		$attempts_remaining = null;
		$attempt_limit      = $quiz ? Quiz::effective_attempt_limit_for_user( $quiz, (int) $attempt->user_id ) : 0;
		if ( $attempt_limit > 0 ) {
			$used               = QuizAttempt::count_graded( (int) $attempt->user_id, (int) $attempt->quiz_id );
			$attempts_remaining = max( 0, $attempt_limit - $used );
		}

		// 'after_pass' is the real pass-gated reveal behind the "Only after
		// passing" option: students who passed get the full review, everyone
		// else gets score + pass/fail only. Resolve it to a concrete shape here
		// so the rest of the pipeline stays as-is.
		//
		// This asks the STUDENT-level question, not `attempt.passed` (which is
		// this one sitting's own result). Once a student has passed the quiz
		// they have already been shown the answers, so withholding them on a
		// later attempt reveals nothing and just reads as a bug to the student.
		// Whether they have passed is exactly what the quiz's grading method
		// decides, so the reveal follows it.
		$feedback_timing = $service->resolve_feedback_timing( $attempt, $feedback_timing );

		if ( 'never' === $feedback_timing ) {
			return $this->single_response( [ 'submitted' => true ] );
		}

		// The certificate the student is holding RIGHT NOW, after grading. It has
		// to be resolved here rather than server-rendered with the page, because
		// the results screen is only ever reached by an in-page submit and the
		// certificate may not have existed when the page loaded: passing a
		// course-final quiz completes the course inside this very request
		// (Quiz_Service::refresh_course_after_attempt -> learnomy_course_completed
		// -> auto-issue). A page-render copy would be a second definition that is
		// stale exactly at the moment it matters (Basecamp 10171377745).
		$certificate = $this->certificate_cta( $attempt, $quiz );

		if ( 'after_attempt' === $feedback_timing ) {
			return $this->single_response(
				[
					'submitted'          => true,
					'attempt_id'         => (int) $graded_attempt->id,
					'score'              => (float) ( $graded_attempt->score ?? 0 ),
					'max_score'          => (float) ( $graded_attempt->max_score ?? 0 ),
					'percentage'         => (float) ( $graded_attempt->percentage ?? 0 ),
					'passed'             => (bool) ( $graded_attempt->passed ?? false ),
					'attempts_remaining' => $attempts_remaining,
					'certificate'        => $certificate,
				]
			);
		}

		// Per-question review — built by the single shared builder in
		// Quiz_Service (also used by the student attempt-review page + Pro's
		// instructor drill-down) so the "what did I answer / what was right"
		// shape lives in exactly one place.
		$review = $service->build_attempt_review( $attempt, $feedback_timing );

		// $graded_attempt is a raw DB row whose values are strings. The
		// front-end reads `passed` as a boolean (`!! result.passed`), and in JS
		// `!! "0"` is TRUE — so a string `passed` would tell every student "You
		// passed!" regardless of result. QuizAttempt::to_typed_array() casts
		// passed (bool) + score/max_score/percentage (float) from the model's
		// own type map, so the fix lives once in the model, not per response.
		$attempt_data                       = QuizAttempt::to_typed_array( $graded_attempt ) ?? [];
		$attempt_data['review']             = $review;
		$attempt_data['feedback_timing']    = $feedback_timing;
		$attempt_data['attempts_remaining'] = $attempts_remaining;
		$attempt_data                       = $this->maybe_attach_category_breakdown( $attempt_data, $quiz, (int) $attempt->id );
		$attempt_data['certificate']        = $certificate;

		return $this->single_response( $attempt_data );
	}

	/**
	 * The certificate action for the results screen, or null when there is
	 * nothing earned to link to.
	 *
	 * Deliberately keyed on an ACTIVE certificate existing, not on whether the
	 * course has certificates enabled. Two reasons:
	 *
	 * 1. Holding one is the only fact that matters to the student. Gating the
	 *    LOOK-UP behind the enabled flag is what hid the completion-screen CTA
	 *    from a student who had already earned hers (Basecamp 10171331421);
	 *    the rule settled there is look up unconditionally, gate only issuing.
	 * 2. The inverse — offering "Get Certificate" whenever the flag is on —
	 *    would send a student who has merely passed a mid-course section quiz
	 *    to an empty certificates list. A CTA that dead-ends is worse than no
	 *    CTA.
	 *
	 * A standalone quiz has no course and therefore never has a certificate.
	 *
	 * @param object      $attempt The attempt row (pre-grading; carries user_id).
	 * @param object|null $quiz    The quiz row, or null when it could not be loaded.
	 * @return array{url:string,uuid:string}|null
	 */
	private function certificate_cta( object $attempt, ?object $quiz ): ?array {
		if ( ! $quiz ) {
			return null;
		}

		// A lesson-attached quiz gates on the lesson's denormalized course_id,
		// the same resolution the enrollment gate above uses.
		$course_id = (int) ( $quiz->course_id ?? 0 );
		if ( 0 === $course_id && ! empty( $quiz->lesson_id ) ) {
			$lesson    = \Learnomy\Models\Lesson::find( (int) $quiz->lesson_id );
			$course_id = $lesson ? (int) ( $lesson->course_id ?? 0 ) : 0;
		}

		if ( 0 === $course_id ) {
			return null;
		}

		$certificate = \Learnomy\Models\Certificate::find_active_for_user_course(
			(int) $attempt->user_id,
			$course_id
		);

		if ( ! $certificate ) {
			return null;
		}

		return [
			'uuid' => (string) $certificate->uuid,
			'url'  => \Learnomy\route_url( 'certificate-verify', [ 'slug' => (string) $certificate->uuid ] ),
		];
	}

	public function get_attempt( WP_REST_Request $request ): WP_REST_Response {
		$attempt = QuizAttempt::find( (int) $request->get_param( 'attempt_id' ) );
		if ( ! $attempt ) {
			return $this->error_response( $this->not_found( 'Attempt' ) );
		}

		// Read an attempt through the SAME typed, feedback-gated pipeline the
		// submit endpoint uses, instead of dumping raw string-typed response
		// rows. Two reasons this matters:
		// 1. Type fidelity — the attempt is cast via to_typed_array (passed is
		// a real bool, scores are floats), and the per-question payload comes
		// from build_attempt_review (the one canonical shaper), not raw DB rows.
		// 2. Feedback gate — a student reading their OWN past attempt must not
		// see per-question scoring/answers the quiz withholds (never /
		// after_attempt / after_pass). Staff (quiz-course instructor or admin)
		// always see the full review for grading.
		$service      = new Quiz_Service();
		$attempt_data = QuizAttempt::to_typed_array( $attempt ) ?? array();
		$is_staff     = $this->check_permission( 'grade_quiz', (int) $attempt->quiz_id );
		$quiz         = Quiz::find( (int) $attempt->quiz_id );

		if ( $is_staff ) {
			$attempt_data['feedback_timing'] = 'after_submit';
			$attempt_data['review']          = $service->build_attempt_review( $attempt, 'after_submit' );
			$attempt_data                    = $this->maybe_attach_category_breakdown( $attempt_data, $quiz, (int) $attempt->id );
			return $this->single_response( $attempt_data );
		}

		$feedback_timing = $quiz ? ( $quiz->feedback_timing ?? 'after_submit' ) : 'after_submit';

		// 'after_pass' resolves to a concrete shape: passers get the full review,
		// everyone else gets score + pass/fail only (mirrors submit_attempt).
		// Student-level and grading-method aware for the same reason it is there.
		$feedback_timing = $service->resolve_feedback_timing( $attempt, $feedback_timing );

		$attempt_data['feedback_timing'] = $feedback_timing;

		// never / after_attempt withhold all per-question data; the other timings
		// expose the (reveal-gated) per-question review built by the shared builder.
		if ( Quiz_Service::timing_reveals_review( $feedback_timing ) ) {
			$attempt_data['review'] = $service->build_attempt_review( $attempt, $feedback_timing );
			$attempt_data           = $this->maybe_attach_category_breakdown( $attempt_data, $quiz, (int) $attempt->id );
		}

		return $this->single_response( $attempt_data );
	}

	/**
	 * Attach the per-category score breakdown (A2) when the quiz groups by
	 * category. Gated to callers that already see the per-question review, since
	 * a per-category score reveals correctness. Each row is normalised to
	 * { category_id, label, earned, possible, answered, percentage }; the
	 * uncategorised bucket (category_id 0 / blank name) is labelled "General".
	 *
	 * @param array       $attempt_data The response payload so far.
	 * @param object|null $quiz         The attempt's quiz.
	 * @param int         $attempt_id   The attempt id.
	 * @return array
	 */
	private function maybe_attach_category_breakdown( array $attempt_data, ?object $quiz, int $attempt_id ): array {
		if ( ! $quiz || empty( $quiz->group_by_category ) ) {
			return $attempt_data;
		}

		$rows = QuestionResponse::score_by_category( $attempt_id );
		if ( empty( $rows ) ) {
			return $attempt_data;
		}

		$attempt_data['category_breakdown'] = array_map(
			static function ( $row ): array {
				$possible = (float) $row->possible;
				$earned   = (float) $row->earned;
				$name     = trim( (string) $row->category_name );
				return array(
					'category_id' => (int) $row->category_id,
					'label'       => '' !== $name ? $name : __( 'General', 'learnomy' ),
					'earned'      => $earned,
					'possible'    => $possible,
					'answered'    => (int) $row->answered,
					'percentage'  => $possible > 0 ? round( ( $earned / $possible ) * 100, 1 ) : 0.0,
				);
			},
			$rows
		);

		return $attempt_data;
	}

	public function get_quiz_attempts( WP_REST_Request $request ): WP_REST_Response {
		$quiz = Quiz::find( (int) $request->get_param( 'id' ) );
		if ( ! $quiz ) {
			return $this->error_response( $this->not_found( 'Quiz' ) );
		}

		$pagination = $this->get_pagination( $request );
		$attempts   = QuizAttempt::where(
			[ 'quiz_id' => (int) $quiz->id ],
			'started_at',
			'DESC',
			$pagination['limit'],
			$pagination['offset']
		);

		// Cast each row through to_typed_array() so `passed` is a real boolean
		// (BOOL_COLUMNS), matching the submit/grade endpoints. Without this the
		// list endpoint leaks `"passed":"0"` as a truthy string and the client
		// shows a failed attempt as passed (the D.quiz-passed-real-bool class).
		$attempts = array_map( [ QuizAttempt::class, 'to_typed_array' ], $attempts );

		return $this->paginated_response(
			$attempts,
			QuizAttempt::count( [ 'quiz_id' => (int) $quiz->id ] ),
			(int) $pagination['offset']
		);
	}

	public function get_statistics( WP_REST_Request $request ): WP_REST_Response {
		$quiz = Quiz::find( (int) $request->get_param( 'id' ) );
		if ( ! $quiz ) {
			return $this->error_response( $this->not_found( 'Quiz' ) );
		}

		$quiz_id = (int) $quiz->id;
		$stats   = QuizAttempt::get_statistics( $quiz_id );

		$attempt_count = $stats ? (int) $stats->attempt_count : 0;
		$avg_score     = $stats ? round( (float) $stats->avg_score, 2 ) : 0.0;
		$pass_rate     = $attempt_count > 0
			? round( ( (int) $stats->pass_count / $attempt_count ) * 100, 2 )
			: 0.0;

		return $this->single_response(
			[
				'quiz_id'       => $quiz_id,
				'attempt_count' => $attempt_count,
				'avg_score'     => $avg_score,
				'pass_rate'     => $pass_rate,
			]
		);
	}

	public function grade_attempt( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$attempt = QuizAttempt::find( (int) $request->get_param( 'attempt_id' ) );
		if ( ! $attempt ) {
			return $this->error_response( $this->not_found( 'Attempt' ) );
		}

		$response_id = absint( $request->get_param( 'response_id' ) );
		$score       = (float) $request->get_param( 'score' );
		$feedback    = sanitize_textarea_field( wp_unslash( $request->get_param( 'feedback' ) ?? '' ) );

		// Ownership: the response must belong to the attempt the caller is
		// authorised to grade. can_grade() gates on attempt_id (and its quiz),
		// but grade_question() takes a raw response_id — without this tie an
		// instructor could pass a response_id from another quiz they don't own
		// and grade it.
		$response = QuestionResponse::find( $response_id );
		if ( ! $response || (int) $response->attempt_id !== (int) $attempt->id ) {
			return $this->error_response( $this->not_found( 'Response' ) );
		}

		$service = new Quiz_Service();

		// Refuse a score above what the question is worth, naming the maximum.
		//
		// The args schema can express `minimum: 0` but not the maximum, because
		// the ceiling is per-response (`max_score` on the row), so it has to be
		// checked here. Same source the service clamps against, so the route and
		// the service can never disagree about what the question is worth.
		//
		// REJECT rather than clamp. Quiz_Service::grade_question() does clamp, so
		// nothing was ever over-scored and an attempt could not exceed 100% - but
		// this route then echoed back the REQUESTED score, so a client that sent
		// 15 against a max of 10 was told "graded, score 15" while 10 was stored.
		// Silently recording a different grade than the one the response confirms
		// is the confusing part, whichever direction it goes (Basecamp
		// 10198909091). The admin grading UI already refuses out-of-range scores
		// and names the maximum; this brings the API in line with it.
		$max = (float) ( $response->max_score ?? 0 );
		if ( $max <= 0 ) {
			$max = $service->marks_for_question( (int) $response->attempt_id, (int) $response->question_id );
		}

		if ( $max > 0 && $score > $max ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_score_above_max',
					sprintf(
						/* translators: 1: submitted score, 2: maximum marks for the question */
						__( 'Score %1$s is above the maximum for this question. Enter a score between 0 and %2$s.', 'learnomy' ),
						(string) $score,
						(string) $max
					),
					[ 'status' => 400 ]
				)
			);
		}

		try {
			$service->grade_question( $response_id, $score, $feedback, $user_id );
		} catch ( \InvalidArgumentException $e ) {
			return $this->error_response( $this->not_found( 'Response' ) );
		}

		// Report what was STORED, re-read rather than echoed. A response that
		// repeats the request cannot tell a client its value was adjusted, which
		// is how the mismatch above stayed invisible.
		$stored = QuestionResponse::find( $response_id );

		return $this->single_response(
			[
				'graded'      => true,
				'response_id' => $response_id,
				'score'       => (float) ( $stored->score ?? $score ),
				'max_score'   => (float) ( $stored->max_score ?? $max ),
			]
		);
	}

	/**
	 * GET /my/quiz-attempts — the caller's own attempts, newest first.
	 *
	 * Rows carry the quiz and course TITLES so a client can label them without
	 * a join per row. The titles are batched (two queries for the page, not two
	 * per attempt) because this list is unbounded: a learner accumulates
	 * attempts forever and a per-row lookup would degrade with their history.
	 *
	 * Status is the attempt's own lifecycle - in_progress, submitted, graded -
	 * with score/percentage/passed carried through so a graded row needs no
	 * follow-up call to render its result.
	 */
	public function get_my_quiz_attempts( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();

		$per_page = max( 1, min( 100, (int) ( $request->get_param( 'per_page' ) ?: 20 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total    = QuizAttempt::count_for_user( $user_id );
		$attempts = QuizAttempt::get_for_user( $user_id, $per_page, $offset );

		// Batch the labels. Quizzes first, then the courses those quizzes
		// belong to - a quiz row carries course_id, so the second batch needs
		// the first.
		$quiz_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $a ) => (int) $a->quiz_id,
						$attempts
					)
				)
			)
		);
		$quizzes  = $quiz_ids ? Quiz::find_in( $quiz_ids ) : [];

		$course_ids = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $q ) => (int) ( $q->course_id ?? 0 ),
						$quizzes
					)
				)
			)
		);
		$courses    = $course_ids ? Course::find_in( $course_ids ) : [];

		$rows = [];
		foreach ( $attempts as $attempt ) {
			$quiz      = $quizzes[ (int) $attempt->quiz_id ] ?? null;
			$course_id = $quiz ? (int) ( $quiz->course_id ?? 0 ) : 0;
			$course    = $course_id ? ( $courses[ $course_id ] ?? null ) : null;

			$rows[] = [
				'id'           => (int) $attempt->id,
				'quiz_id'      => (int) $attempt->quiz_id,
				// An empty title rather than a missing key: a quiz deleted after
				// the attempt was sat leaves the attempt standing (it is the
				// record of work done), and the client still has a row to render.
				'quiz_title'   => $quiz->title ?? '',
				'course_id'    => $course_id,
				'course_title' => $course->title ?? '',
				'status'       => (string) $attempt->status,
				'started_at'   => $attempt->started_at ?? null,
				'submitted_at' => $attempt->submitted_at ?? null,
				'graded_at'    => $attempt->graded_at ?? null,
				'score'        => isset( $attempt->score ) ? (float) $attempt->score : null,
				'max_score'    => isset( $attempt->max_score ) ? (float) $attempt->max_score : null,
				'percentage'   => isset( $attempt->percentage ) ? (float) $attempt->percentage : null,
				'passed'       => isset( $attempt->passed ) ? (bool) $attempt->passed : null,
			];
		}

		return $this->paginated_response(
			$rows,
			$total,
			$offset,
			( $offset + count( $rows ) ) < $total
		);
	}

	/**
	 * Record a tab-switch event on a quiz attempt for anti-cheat tracking.
	 *
	 * Only the attempt owner can update their own attempt. The counter is
	 * incremented server-side per request — the body's `count` parameter
	 * (if present) is ignored. This stops a student from POSTing
	 * `{count: 0}` on every keystroke to zero out the column.
	 */
	public function record_tab_switch( WP_REST_Request $request ): WP_REST_Response {
		$attempt_id = (int) $request['id'];
		$user_id    = get_current_user_id();

		$attempt = QuizAttempt::find( $attempt_id );
		if ( ! $attempt || (int) $attempt->user_id !== $user_id ) {
			return $this->error_response( $this->not_found( 'Attempt' ) );
		}

		// Global anti-cheat gate — the admin Quiz Settings tab exposes a
		// "Track Tab Switches" toggle. When it's off we deliberately drop the
		// payload on the floor so the column stays at its default of 0.
		// Returning 200 (with recorded: false) instead of an error keeps the
		// front-end fire-and-forget code path quiet — the recorder still
		// fires from the quiz player, the server just ignores it.
		if ( ! Quiz_Defaults::is_tab_tracking_enabled() ) {
			return $this->single_response( [ 'recorded' => false ] );
		}

		$new_count = (int) ( $attempt->tab_switches ?? 0 ) + 1;
		QuizAttempt::update( $attempt_id, [ 'tab_switches' => $new_count ] );

		// Proctoring auto-submit: once the student exceeds the configured limit
		// (0 = off), signal the player to finalize the attempt. Server-side so
		// the player can't be trusted to enforce it alone.
		$quiz        = isset( $attempt->quiz_id ) ? Quiz::find( (int) $attempt->quiz_id ) : null;
		$limit       = Quiz_Defaults::tab_switch_auto_submit_limit( $quiz );
		$auto_submit = $limit > 0 && $new_count >= $limit;

		return $this->single_response(
			[
				'recorded'     => true,
				'tab_switches' => $new_count,
				'auto_submit'  => $auto_submit,
			]
		);
	}

	/**
	 * A4: persist exam-runner resume state (current question index + flagged ids)
	 * into the attempt's saved_state_json. Fire-and-forget from the player on
	 * navigation / flag toggle; only the owning learner can write their attempt.
	 */
	public function record_attempt_state( WP_REST_Request $request ): WP_REST_Response {
		$attempt_id = (int) $request->get_param( 'attempt_id' );
		$attempt    = QuizAttempt::find( $attempt_id );
		if ( ! $attempt || (int) $attempt->user_id !== get_current_user_id() ) {
			return $this->error_response( $this->not_found( 'Attempt' ) );
		}

		$patch = [];
		if ( null !== $request->get_param( 'current_index' ) ) {
			$patch['current_index'] = absint( $request->get_param( 'current_index' ) );
		}
		if ( null !== $request->get_param( 'flagged' ) ) {
			$patch['flagged'] = array_values( array_unique( array_map( 'absint', (array) $request->get_param( 'flagged' ) ) ) );
		}
		if ( null !== $request->get_param( 'remaining' ) ) {
			$patch['remaining'] = absint( $request->get_param( 'remaining' ) );
		}
		$answers = $request->get_param( 'answers' );
		if ( null !== $answers ) {
			// Stored for resume pre-fill only (never the grading source). Recursively
			// sanitize scalar leaves; preserve the {qid: value|array|map} shape.
			$patch['answers'] = $this->sanitize_runner_answers( (array) $answers );
		}

		if ( $patch ) {
			QuizAttempt::save_runner_state( $attempt_id, $patch );
		}

		return $this->single_response( [ 'saved' => true ] );
	}

	/**
	 * Recursively sanitize a stored in-progress answer map. Scalar leaves are
	 * text-sanitized; arrays/maps keep their shape (mc_multi arrays, fill_blank
	 * group maps). Resume pre-fill only — never the grading source.
	 *
	 * @param array<mixed> $answers
	 * @return array<mixed>
	 */
	private function sanitize_runner_answers( array $answers ): array {
		$clean = [];
		foreach ( $answers as $key => $value ) {
			$clean_key = is_int( $key ) ? $key : sanitize_text_field( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $clean_key ] = $this->sanitize_runner_answers( $value );
			} elseif ( is_scalar( $value ) ) {
				$clean[ $clean_key ] = sanitize_text_field( (string) $value );
			}
			// null / unexpected object leaves are dropped.
		}
		return $clean;
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * A quiz is created INTO a course, so the capability alone cannot decide
	 * this. `manage_own_courses` says the caller may author quizzes; the
	 * placement params say WHERE, and each one has to belong to a course they
	 * manage. Without that second half an instructor could POST a quiz onto any
	 * course on the site by naming its id — a horizontal escalation between
	 * tenants (Basecamp 10133286758). A quiz with no placement at all is a
	 * standalone quiz and still passes on the capability alone.
	 */
	public function can_create( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'manage_own_courses' resolves to learnomy_manage_own_courses;
		// admins pass via Permission_Engine's manage_options short-circuit.
		if ( ! $this->check_permission( 'manage_own_courses' ) ) {
			return $this->permission_error();
		}

		return $this->require_parent_ownership(
			[
				'course'  => (int) $request->get_param( 'course_id' ),
				'section' => (int) $request->get_param( 'section_id' ),
				'lesson'  => (int) $request->get_param( 'lesson_id' ),
			]
		);
	}

	public function can_manage( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$quiz = Quiz::find( (int) $request->get_param( 'id' ) );
		if ( ! $quiz ) {
			return $this->permission_error();
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'grade_quiz' maps to learnomy_grade in CAP_MAP and then checks
		// is_instructor_of_quiz_course(), which covers both authorship and
		// admin short-circuit. Admins pass via manage_options at line 33.
		if ( ! $this->check_permission( 'grade_quiz', (int) $quiz->id ) ) {
			return $this->permission_error();
		}

		// Owning the quiz WHERE IT IS says nothing about where it is being
		// moved TO. extract_quiz_fields() writes course_id / section_id /
		// lesson_id straight from the body, so a PATCH that re-parents must
		// clear the destination too — otherwise "edit my own quiz" doubles as
		// "inject a quiz into someone else's course".
		return $this->require_parent_ownership(
			[
				'course'  => (int) $request->get_param( 'course_id' ),
				'section' => (int) $request->get_param( 'section_id' ),
				'lesson'  => (int) $request->get_param( 'lesson_id' ),
			]
		);
	}

	/**
	 * Slot-scoped manage check — resolves the parent quiz from the slot row
	 * and defers to can_manage()'s logic so the rule stays in one place.
	 */
	public function can_manage_slot( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$slot = QuizSlot::find( (int) $request->get_param( 'id' ) );
		if ( ! $slot ) {
			return $this->permission_error();
		}

		$quiz = Quiz::find( (int) $slot->quiz_id );
		if ( ! $quiz ) {
			return $this->permission_error();
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'grade_quiz' maps to learnomy_grade in CAP_MAP and then checks
		// is_instructor_of_quiz_course(); admins pass via manage_options
		// short-circuit at line 33. Slot permission mirrors quiz permission.
		if ( ! $this->check_permission( 'grade_quiz', (int) $quiz->id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	public function can_take_quiz(): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'take_quiz' resolves to learnomy_take_quiz (the student cap);
		// admins pass via Permission_Engine's manage_options short-circuit.
		if ( ! $this->check_permission( 'take_quiz' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	public function can_act_on_attempt( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$attempt = QuizAttempt::find( (int) $request->get_param( 'attempt_id' ) );
		if ( ! $attempt ) {
			return $this->permission_error();
		}

		if ( (int) $attempt->user_id !== $user_id ) {
			return $this->permission_error();
		}

		return true;
	}

	public function can_view_attempt( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$attempt = QuizAttempt::find( (int) $request->get_param( 'attempt_id' ) );
		if ( ! $attempt ) {
			return $this->permission_error();
		}

		// Own attempt — student viewing their own result.
		if ( (int) $attempt->user_id === $user_id ) {
			return true;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'grade_quiz' covers instructors of the quiz's course and admins
		// (manage_options short-circuit at line 33 of Permission_Engine).
		if ( $this->check_permission( 'grade_quiz', (int) $attempt->quiz_id ) ) {
			return true;
		}

		return $this->permission_error();
	}

	/**
	 * Course scope for the grading queue. One reader — Grading_Service — shared
	 * with the wp-admin inbox and the front-end teaching shell, so the app, the
	 * backend and the site all agree on what is pending AND on who may grade it.
	 */
	private function grading_scope_course_ids(): ?array {
		return Grading_Service::scope_course_ids( get_current_user_id() );
	}

	/** Only people who can actually grade something + admins may read the queue. */
	public function can_view_grading_queue(): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$scope = Grading_Service::scope_course_ids( $user_id );

		return ( is_array( $scope ) && empty( $scope ) ) ? $this->permission_error() : true;
	}

	/**
	 * GET /grading/pending — the grading queue, scoped to the courses the caller
	 * may actually grade (admins: all). Enriched with course title + student name
	 * for the app's grading inbox; `ungraded_count` drives the per-row badge.
	 *
	 * `status=graded` returns the same shape for work already graded by a human,
	 * which is what lets a client show a teacher what they finished, not only
	 * what they owe. `course_id` narrows to one course and can never widen the
	 * scope — an unowned course returns nothing.
	 */
	public function pending_grading( WP_REST_Request $request ): WP_REST_Response {
		$scope      = $this->grading_scope_course_ids();
		$status     = Grading_Service::normalize_status( (string) ( $request->get_param( 'status' ) ?? '' ) );
		$effective  = Grading_Service::apply_course_filter( (int) $request->get_param( 'course_id' ), $scope );
		$pagination = $this->get_pagination( $request );
		// Ask for the sources BEFORE fetching, because whether anything else
		// contributes decides how this queue must page.
		//
		// With no extension listening, Free pages its own rows in SQL as it
		// always has. With one listening, Free has to over-fetch from 0 exactly
		// like the sources do: a row from another source can sort ahead of a
		// quiz row, so page 2 of the merged list is not page 2 of either source.
		// Paging Free's query while merging unpaged extension rows silently
		// dropped rows off the end of later pages - the merged slice ran past
		// what had been fetched.
		$lrn_fetch   = (int) $pagination['offset'] + (int) $pagination['limit'];
		$lrn_sources = (array) apply_filters(
			'learnomy_grading_queue_sources',
			array(),
			$effective,
			$status,
			$lrn_fetch
		);

		$queue = $lrn_sources
			? Grading_Service::queue( $effective, $status, $lrn_fetch, 0 )
			: Grading_Service::queue( $effective, $status, $pagination['limit'], $pagination['offset'] );
		$rows  = $queue['rows'];
		$total = $queue['total'];

		// Batch enrich — course titles in one query; student names per row (page
		// is small, capped by the pagination limit).
		$course_ids = array_filter( array_map( static fn( $r ) => (int) $r->course_id, $rows ) );
		$courses    = $course_ids ? Course::find_in( $course_ids ) : [];
		$items      = array_map(
			function ( $row ) use ( $courses ) {
				$student = get_userdata( (int) $row->user_id );
				$course  = $courses[ (int) $row->course_id ] ?? null;
				return [
					// Present on every row whatever produced it, so a client can
					// tell what it is looking at and which grade route to call.
					'type'           => 'quiz',
					'item_id'        => (int) $row->attempt_id,
					'attempt_id'     => (int) $row->attempt_id,
					'quiz_id'        => (int) $row->quiz_id,
					'quiz_title'     => (string) $row->quiz_title,
					'course_id'      => (int) $row->course_id,
					'course_title'   => $course->title ?? '',
					'student_id'     => (int) $row->user_id,
					'student_name'   => $student ? $student->display_name : '',
					'submitted_at'   => $row->submitted_at,
					'ungraded_count' => (int) ( $row->ungraded_count ?? 0 ),
					'graded_count'   => (int) ( $row->graded_count ?? 0 ),
					'graded_at'      => $row->graded_at ?? null,
				];
			},
			$rows
		);

		// One inbox, not one per feature.
		//
		// This queue was quiz-only, so an instructor could grade a quiz essay in
		// the app and had no way to even FIND an assignment waiting on them -
		// every assignment route needs an id, and nothing listed the work
		// (Basecamp 10199129310). Two endpoints would have meant two inboxes and
		// an instructor deciding which one to check.
		//
		// Free ships the seam, Pro fills it: Free must never assume Pro exists,
		// and with no extension listening this filter returns nothing and the
		// response is exactly what it was.
		//
		// A source hands back rows ALREADY in the response shape plus a `type`,
		// and its own total. Merging happens here rather than in SQL because the
		// rows live in different tables in different plugins - a UNION would
		// couple Free to a Pro table, which is the one thing the seam exists to
		// avoid.
		foreach ( $lrn_sources as $lrn_source ) {
			if ( ! isset( $lrn_source['rows'] ) || ! is_array( $lrn_source['rows'] ) ) {
				continue;
			}
			$items  = array_merge( $items, $lrn_source['rows'] );
			$total += (int) ( $lrn_source['total'] ?? 0 );
		}

		if ( $lrn_sources ) {
			// Newest first across every source, so the merged order is stable
			// and does not depend on which plugin answered first. Ties break on
			// type then id so the sort is total - two rows submitted in the same
			// second must not swap places between requests, or pagination
			// silently drops or repeats one.
			usort(
				$items,
				static function ( $a, $b ) {
					$cmp = strcmp( (string) ( $b['submitted_at'] ?? '' ), (string) ( $a['submitted_at'] ?? '' ) );
					if ( 0 !== $cmp ) {
						return $cmp;
					}
					$cmp = strcmp( (string) ( $a['type'] ?? '' ), (string) ( $b['type'] ?? '' ) );
					if ( 0 !== $cmp ) {
						return $cmp;
					}
					return (int) ( $a['item_id'] ?? 0 ) <=> (int) ( $b['item_id'] ?? 0 );
				}
			);

			$items = array_slice( $items, (int) $pagination['offset'], (int) $pagination['limit'] );
		}

		return $this->paginated_response( $items, $total, (int) $pagination['offset'] );
	}

	public function can_grade( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$attempt = QuizAttempt::find( (int) $request->get_param( 'attempt_id' ) );
		if ( ! $attempt ) {
			return $this->permission_error();
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'grade_quiz' covers instructors and admins (manage_options
		// short-circuit at line 33 of Permission_Engine).
		if ( ! $this->check_permission( 'grade_quiz', (int) $attempt->quiz_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function extract_quiz_fields( WP_REST_Request $request, bool $creating ): array {
		$data = [];

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$data['title'] = sanitize_text_field( wp_unslash( $title ) );
		}

		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			// HTML-first storage cutover (ON by default, kill-switch). Editor posts Editor.js JSON;
			// for_storage() canonicalises to HTML via the one shared converter
			// (INV-9). Off → store as before. Reads stay dual-format (INV-8).
			$raw                 = wp_unslash( (string) $description );
			$data['description'] = \Learnomy\Content\Content_HTML::storage_enabled()
				? \Learnomy\Content\Content_HTML::for_storage( $raw )
				: wp_kses_post( $raw );
		}

		$mode = $request->get_param( 'mode' );
		if ( null !== $mode && in_array( $mode, [ 'graded', 'practice', 'survey' ], true ) ) {
			$data['mode'] = $mode;
		}

		$grading_method = $request->get_param( 'grading_method' );
		if ( null !== $grading_method ) {
			// `last` is a deprecated alias kept for legacy clients — normalize
			// to `latest` before the whitelist check so storage stays canonical.
			if ( 'last' === $grading_method ) {
				$grading_method = 'latest';
			}
			if ( in_array( $grading_method, [ 'highest', 'latest', 'average', 'first' ], true ) ) {
				$data['grading_method'] = $grading_method;
			}
		}

		$passing_score = $request->get_param( 'passing_score' );
		if ( null !== $passing_score ) {
			$data['passing_score'] = max( 0, min( 100, (float) $passing_score ) );
		}

		$attempt_limit = $request->get_param( 'attempt_limit' );
		if ( null !== $attempt_limit ) {
			$val                   = absint( $attempt_limit );
			$data['attempt_limit'] = $val > 0 ? $val : null;
		}

		// REST field name is `time_limit` (seconds); the underlying column is
		// `time_limit_seconds`. Map it so writes actually land. Zero or empty
		// means "no limit" (NULL column value).
		$time_limit = $request->get_param( 'time_limit' );
		if ( null === $time_limit ) {
			$time_limit = $request->get_param( 'time_limit_seconds' );
		}
		if ( null !== $time_limit ) {
			$val                        = absint( $time_limit );
			$data['time_limit_seconds'] = $val > 0 ? $val : null;
		}

		$status = $request->get_param( 'status' );
		if ( null !== $status && in_array( $status, [ 'draft', 'published', 'archived' ], true ) ) {
			$data['status'] = $status;
		}

		$course_id = $request->get_param( 'course_id' );
		if ( null !== $course_id ) {
			$data['course_id'] = absint( $course_id ) ?: null;
		}

		// section_id + lesson_id pin a quiz to its place in a course outline.
		// Both are optional; an empty string clears the column to NULL so the
		// quiz can be promoted to standalone via the same form.
		$section_id = $request->get_param( 'section_id' );
		if ( null !== $section_id ) {
			$data['section_id'] = absint( $section_id ) ?: null;
		}

		$lesson_id = $request->get_param( 'lesson_id' );
		if ( null !== $lesson_id ) {
			$data['lesson_id'] = absint( $lesson_id ) ?: null;
		}

		$slug = $request->get_param( 'slug' );
		if ( null !== $slug ) {
			$data['slug'] = sanitize_title( wp_unslash( $slug ) );
		}

		$display_template = $request->get_param( 'display_template' );
		if ( null !== $display_template ) {
			$allowed                  = [ 'focused', 'classic', 'conversational', 'sectioned', 'review-board' ];
			$data['display_template'] = in_array( $display_template, $allowed, true ) ? $display_template : 'focused';
		}

		// DEPRECATED — each display template hardcodes its nav style (focused =
		// one-at-a-time, classic = all-at-once, sectioned = paginated, etc.),
		// which makes the `navigation` toggle redundant at runtime. Column is
		// kept for schema stability + admin-form backward compat; runtime
		// quiz-view.js ignores it. Remove via migration in a future release.
		$navigation = $request->get_param( 'navigation' );
		if ( null !== $navigation && in_array( $navigation, [ 'free', 'sequential' ], true ) ) {
			$data['navigation'] = $navigation;
		}

		// Section-level navigation for the sectioned template — the analogue of
		// `navigation` at the section level (BC #9993392864).
		$section_navigation = $request->get_param( 'section_navigation' );
		if ( null !== $section_navigation && in_array( $section_navigation, [ 'linear', 'free' ], true ) ) {
			$data['section_navigation'] = $section_navigation;
		}

		// Sectioned template: section size. The client chunked 10-at-a-time
		// with no server say until 3.45.0 (Basecamp 10171811590). Clamped to
		// a sane band rather than rejected - an out-of-range number from an
		// API client becomes the nearest legal size.
		$questions_per_section = $request->get_param( 'questions_per_section' );
		if ( null !== $questions_per_section && '' !== $questions_per_section ) {
			$data['questions_per_section'] = max( 1, min( 100, (int) $questions_per_section ) );
		}

		$feedback_timing = $request->get_param( 'feedback_timing' );
		if ( null !== $feedback_timing && in_array( $feedback_timing, [ 'after_submit', 'immediate', 'after_attempt', 'after_question', 'after_pass', 'never' ], true ) ) {
			$data['feedback_timing'] = $feedback_timing;
		}

		// Experience / access — the remaining schema columns.
		$shuffle_questions = $request->get_param( 'shuffle_questions' );
		if ( null !== $shuffle_questions ) {
			$data['shuffle_questions'] = $shuffle_questions ? 1 : 0;
		}

		$shuffle_answers = $request->get_param( 'shuffle_answers' );
		if ( null !== $shuffle_answers ) {
			$data['shuffle_answers'] = $shuffle_answers ? 1 : 0;
		}

		$group_by_category = $request->get_param( 'group_by_category' );
		if ( null !== $group_by_category ) {
			$data['group_by_category'] = $group_by_category ? 1 : 0;
		}

		$access_code = $request->get_param( 'access_code' );
		if ( null !== $access_code ) {
			$data['access_code'] = sanitize_text_field( wp_unslash( (string) $access_code ) );
		}

		// open_at / close_at arrive as a datetime-local wall-clock in the SITE
		// timezone (the admin's academy time); storage + the gate are UTC, so
		// convert here — the inverse of datetime_local_input() the editor uses to
		// render the field. Without this, "2pm" was stored as 2pm UTC and the
		// quiz opened/closed off by the site offset on any non-UTC install.
		$open_at = $request->get_param( 'open_at' );
		if ( null !== $open_at ) {
			$data['open_at'] = \Learnomy\datetime_input_to_utc( sanitize_text_field( (string) $open_at ) );
		}

		$close_at = $request->get_param( 'close_at' );
		if ( null !== $close_at ) {
			$data['close_at'] = \Learnomy\datetime_input_to_utc( sanitize_text_field( (string) $close_at ) );
		}

		// Cooldown + lockout (schema columns exist from Wave 1 migration 1.6.0).
		$cooldown = $request->get_param( 'attempt_cooldown_seconds' );
		if ( null !== $cooldown ) {
			$data['attempt_cooldown_seconds'] = max( 0, absint( $cooldown ) );
		}

		$max_failed = $request->get_param( 'max_failed_attempts' );
		if ( null !== $max_failed ) {
			// tinyint unsigned in schema — cap at 255 so we never trip a truncation.
			$data['max_failed_attempts'] = max( 0, min( 255, absint( $max_failed ) ) );
		}

		return $data;
	}

	private function quiz_args( bool $title_required = true ): array {
		return [
			'title'                    => [
				'type'              => 'string',
				'required'          => $title_required,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'description'              => [ 'type' => 'string' ],
			'mode'                     => [
				'type' => 'string',
				'enum' => [ 'graded', 'practice', 'survey' ],
			],
			'grading_method'           => [
				'type'        => 'string',
				// `last` is a deprecated alias for `latest` — kept in the enum
				// so legacy clients still validate. Normalized server-side.
				'enum'        => [ 'highest', 'latest', 'last', 'average', 'first' ],
				'description' => __( 'How the student\'s official score is calculated across attempts. `last` is a deprecated alias for `latest`.', 'learnomy' ),
			],
			'feedback_timing'          => [
				'type'        => 'string',
				'enum'        => [ 'after_submit', 'after_question', 'never', 'immediate', 'after_attempt', 'after_pass' ],
				'description' => __( 'When to show feedback to the student after answering.', 'learnomy' ),
			],
			'passing_score'            => [
				'type'    => 'number',
				'minimum' => 0,
				'maximum' => 100,
			],
			'attempt_limit'            => [
				'type'    => 'integer',
				'minimum' => 0,
			],
			'time_limit'               => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Time limit in minutes. 0 means no limit.', 'learnomy' ),
			],
			'status'                   => [
				'type' => 'string',
				'enum' => [ 'draft', 'published', 'archived' ],
			],
			'course_id'                => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'section_id'               => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'lesson_id'                => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'slug'                     => [
				// No sanitize_callback — WP REST passes (value, request, param)
				// to it, and sanitize_title() interprets the 2nd arg as a
				// fallback title, which crashes when the slug is empty (the
				// fallback becomes the WP_REST_Request object). The controller
				// already sanitizes via single-arg sanitize_title() inside
				// extract_quiz_fields(), so this layer is redundant anyway.
				'type' => 'string',
			],
			'attempt_cooldown_seconds' => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Seconds a student must wait after submitting before they can start a fresh attempt.', 'learnomy' ),
			],
			'max_failed_attempts'      => [
				'type'        => 'integer',
				'minimum'     => 0,
				'maximum'     => 255,
				'description' => __( 'How many graded failing attempts lock the student out. 0 = no ceiling.', 'learnomy' ),
			],
			'access_code'              => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'open_at'                  => [ 'type' => 'string' ],
			'close_at'                 => [ 'type' => 'string' ],
			'display_template'         => [
				'type' => 'string',
				'enum' => [ 'focused', 'classic', 'conversational', 'sectioned', 'review-board' ],
			],
			'navigation'               => [
				'type' => 'string',
				'enum' => [ 'free', 'sequential' ],
			],
			'section_navigation'       => [
				'type' => 'string',
				'enum' => [ 'linear', 'free' ],
			],
			'shuffle_questions'        => $this->bool_arg(),
			'shuffle_answers'          => $this->bool_arg(),
			'group_by_category'        => $this->bool_arg(),
			'slot_marks'               => [
				'type'        => 'object',
				'description' => __( 'Optional map of slot_id > marks to update alongside the quiz.', 'learnomy' ),
			],
		];
	}
}
