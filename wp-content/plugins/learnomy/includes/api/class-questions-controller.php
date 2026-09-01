<?php
/**
 * Questions REST controller — question bank CRUD and per-question statistics.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\QuestionTypes;
use Learnomy\Models\Question;
use Learnomy\Models\Answer;
use Learnomy\Models\QuestionCategory;
use Learnomy\Models\QuestionResponse;
use Learnomy\Models\QuizSlot;
use Learnomy\Models\Tag;
use Learnomy\Services\Question_CSV;
use function Learnomy\now;

class Questions_Controller extends Base_Controller {

	private const DIFFICULTY_LEVELS = [ 'easy', 'medium', 'hard' ];

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'questions';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_questions' ],
					'permission_callback' => [ $this, 'can_read' ],
					'args'                => array_merge(
						$this->get_collection_params(),
						[
							'category_id' => [
								'type'              => 'integer',
								'sanitize_callback' => 'absint',
								'description'       => __( 'Filter by question category.', 'learnomy' ),
							],
							'difficulty'  => [
								// Accept both representations: legacy string enum
								// (easy/medium/hard) AND the canonical 1–5 integer
								// scale the admin picker JS sends. get_questions()
								// normalizes either onto the filter that hits the DB.
								// Matches the same union type used in question_args().
								'type' => [ 'string', 'integer' ],
							],
							'type'        => [
								'type' => 'string',
								'enum' => array_values( array_unique( array_merge( QuestionTypes::slugs(), [ 'multiple_choice', 'multiple_select' ] ) ) ),
							],
							'search'      => [
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
								'description'       => __( 'Free-text search across question title and body.', 'learnomy' ),
							],
						]
					),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_question' ],
					'permission_callback' => [ $this, 'can_write' ],
					'args'                => $this->question_args(),
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_question' ],
					'permission_callback' => [ $this, 'can_read' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_question' ],
					'permission_callback' => [ $this, 'can_manage' ],
					'args'                => array_merge(
						[
							'id' => [
								'type'     => 'integer',
								'required' => true,
							],
						],
						$this->question_args( false )
					),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_question' ],
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/statistics',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_statistics' ],
				'permission_callback' => [ $this, 'can_read' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		// Duplicate — clones a question row and its answers. Exposed as a POST
		// so clients don't need to rebuild the entire payload client-side.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/duplicate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'duplicate_question' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'id' => [
						'type'     => 'integer',
						'required' => true,
					],
				],
			]
		);

		// CSV export — streams the current bank as text/csv. Filters mirror
		// the collection endpoint so admins can export targeted slices.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'export_csv' ],
				'permission_callback' => [ $this, 'can_write' ],
				'args'                => [
					'category_id' => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'type'        => [
						'type' => 'string',
					],
					'difficulty'  => [
						'type' => 'integer',
					],
					'status'      => [
						'type' => 'string',
					],
				],
			]
		);

		// CSV import — accepts the raw CSV text in the `csv` param. Keeping
		// it as a text field (rather than multipart) lets non-browser clients
		// use the endpoint without extra ceremony.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/import',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'import_csv' ],
				'permission_callback' => [ $this, 'can_write' ],
				'args'                => [
					'csv' => [
						'type'        => 'string',
						'required'    => true,
						'description' => __( 'Raw CSV text following the Learnomy question bank schema.', 'learnomy' ),
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	public function get_questions( WP_REST_Request $request ): WP_REST_Response {
		$pagination  = $this->get_pagination( $request );
		$category_id = absint( $request->get_param( 'category_id' ) );
		$difficulty  = $request->get_param( 'difficulty' );
		$type        = sanitize_text_field( wp_unslash( $request->get_param( 'type' ) ?? '' ) );

		$conditions = [];
		if ( $category_id > 0 ) {
			$conditions['category_id'] = $category_id;
		}
		// The `difficulty` column stores 1-5. The picker JS sends ints; legacy
		// clients send easy/medium/hard. ONE normaliser converts both, and it
		// is the same one create/update uses -- when they were two separate
		// maps, "easy" was stored as 2 and searched for as 1, so filtering by
		// easy returned nothing at all.
		if ( null !== $difficulty && '' !== $difficulty ) {
			$conditions['difficulty'] = Question::normalize_difficulty( $difficulty );
		}
		// Accept legacy aliases (`multiple_choice`, `multiple_select`) by
		// normalizing to the canonical slug before hitting the DB. Storage
		// format is always the canonical slug.
		if ( $type ) {
			$normalized = QuestionTypes::normalize( $type );
			if ( in_array( $normalized, QuestionTypes::slugs(), true ) ) {
				$conditions['type'] = $normalized;
			}
		}

		$search = sanitize_text_field( wp_unslash( (string) ( $request->get_param( 'search' ) ?? '' ) ) );

		$limit  = $pagination['limit'];
		$offset = $pagination['offset'];

		// Honour the cursor this endpoint ALREADY advertises. `meta.cursor_next`
		// was being returned but never consumed, so a client that correctly
		// followed it looped on page 1 forever — worse than offering no cursor,
		// because the contract said it worked (Basecamp 10160235647). Offset
		// paging was fine and stays the default; the two are mutually exclusive.
		//
		// `id < N` is the right predicate here because the ORDER BY below is
		// `id DESC` — cursor column and sort key are the same, unlike the
		// catalog, which sorts by created_at and needed a composite keyset.
		if ( $pagination['after'] > 0 ) {
			$conditions['id__lt'] = (int) $pagination['after'];
			$offset               = 0;
		}

		// with_usage_counts() carries the same exact-match WHERE plus the
		// free-text search LIKE (title + body) the bank list already uses, so
		// the picker, the admin bank list, and this endpoint share one query
		// path — and the `search` param the admin picker has always sent (but
		// this endpoint silently ignored) now actually filters. limit+1 keeps
		// the existing cursor has_more behaviour.
		$questions = Question::with_usage_counts( $conditions, 'id', 'DESC', $limit + 1, $offset, 0, $search );
		$has_more  = count( $questions ) > $limit;

		if ( $has_more ) {
			array_pop( $questions );
		}

		// Enrich each row with its category name so a picker can show "Algebra"
		// instead of a bare id. One batched lookup over the page's category ids
		// (big-site-safe — no per-row query).
		$category_ids = array_values(
			array_unique(
				array_filter( array_map( static fn( $q ) => (int) ( $q->category_id ?? 0 ), $questions ) )
			)
		);
		if ( $category_ids ) {
			$category_names = [];
			foreach ( QuestionCategory::find_in( $category_ids ) as $category ) {
				$category_names[ (int) $category->id ] = $category->name;
			}
			foreach ( $questions as $question ) {
				$question->category_name = $category_names[ (int) ( $question->category_id ?? 0 ) ] ?? null;
			}
		}

		return $this->paginated_response( $questions, Question::count_filtered( $conditions, 0, $search ), 0, $has_more );
	}

	public function get_question( WP_REST_Request $request ): WP_REST_Response {
		$question = Question::find( (int) $request->get_param( 'id' ) );
		if ( ! $question ) {
			return $this->error_response( $this->not_found( 'Question' ) );
		}

		// Attach answers — instructors need these for editing; strip correct flags for safety
		// if this ever becomes accessible to students in the future.
		$answers = Answer::where( [ 'question_id' => (int) $question->id ] );

		return $this->single_response(
			[
				'question' => Question::to_typed_array( $question ),
				'answers'  => array_map( [ Answer::class, 'to_typed_array' ], $answers ),
			]
		);
	}

	public function create_question( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$data = $this->extract_question_fields( $request );
		if ( empty( $data['title'] ) ) {
			return $this->error_response( $this->validation_error( __( 'Question text is required.', 'learnomy' ) ) );
		}
		if ( empty( $data['type'] ) ) {
			return $this->error_response( $this->validation_error( __( 'Question type is required.', 'learnomy' ) ) );
		}

		$data['author_id'] = $user_id;

		$question_id = Question::create( $data );
		if ( ! $question_id ) {
			return $this->error_response( $this->validation_error( __( 'Could not save the question. Please try again.', 'learnomy' ) ) );
		}

		// Persist answers if supplied at creation time.
		$answers_param = $request->get_param( 'answers' );
		if ( is_array( $answers_param ) ) {
			$this->save_answers( $question_id, $answers_param );
		}

		// Inline quiz attach (Basecamp #9928064574) — fan out to QuizSlot.
		$quiz_ids_param = $request->get_param( 'quiz_ids' );
		if ( is_array( $quiz_ids_param ) ) {
			$this->sync_question_slots( $question_id, $quiz_ids_param );
		}

		$tag_ids_param = $request->get_param( 'tag_ids' );
		if ( is_array( $tag_ids_param ) ) {
			Tag::sync_object_tags( $question_id, 'question', $tag_ids_param );
		}

		$question = Question::find( $question_id );
		$answers  = Answer::where( [ 'question_id' => $question_id ] );

		$response = $this->single_response(
			[
				'question' => Question::to_typed_array( $question ),
				'answers'  => array_map( [ Answer::class, 'to_typed_array' ], $answers ),
			]
		);
		$response->set_status( 201 );

		return $response;
	}

	public function update_question( WP_REST_Request $request ): WP_REST_Response {
		$question = Question::find( (int) $request->get_param( 'id' ) );
		if ( ! $question ) {
			return $this->error_response( $this->not_found( 'Question' ) );
		}

		$data = $this->extract_question_fields( $request );
		// A tags-only (or answers-only / quiz-only) PATCH carries no column data
		// but is still a valid update.
		$has_side_updates = null !== $request->get_param( 'answers' )
			|| is_array( $request->get_param( 'tag_ids' ) )
			|| is_array( $request->get_param( 'quiz_ids' ) );
		if ( empty( $data ) && ! $has_side_updates ) {
			return $this->error_response( $this->validation_error( __( 'Nothing to update. No valid fields supplied.', 'learnomy' ) ) );
		}

		$answers_param = $request->get_param( 'answers' );

		// Bump the version for ANY change that alters what a learner was asked,
		// which includes replacing the answers.
		//
		// This used to live inside the `! empty( $data )` branch, so an
		// answers-only PATCH skipped it: the stem stayed put, the options were
		// swapped underneath it, and the version did not move. Every attempt
		// that recorded "version 3" then pointed at an answer set that no
		// longer existed, and nothing could tell whether a past response had
		// been marked against the old options or the new ones. The comment here
		// always said "so historical attempt responses stay interpretable" -
		// the intent was right, the placement was not. (Basecamp 10153961882)
		if ( ! empty( $data ) || is_array( $answers_param ) ) {
			Question::increment_version( (int) $question->id );
		}

		if ( ! empty( $data ) ) {
			Question::update( (int) $question->id, $data );
		}

		if ( is_array( $answers_param ) ) {
			// Full replace: bulk-delete then re-insert in one query rather than
			// looping per row. Empty-array payloads still work — they clear
			// answers (valid for essay questions that don't need answer rows).
			Answer::delete_for_question( (int) $question->id );
			$this->save_answers( (int) $question->id, $answers_param );
		}

		// Inline quiz attach (Basecamp #9928064574). Omitted param leaves
		// slots alone; explicit empty array detaches from every quiz.
		$quiz_ids_param = $request->get_param( 'quiz_ids' );
		if ( is_array( $quiz_ids_param ) ) {
			$this->sync_question_slots( (int) $question->id, $quiz_ids_param );
		}

		// Tags — omitted param leaves them alone; explicit empty array clears.
		$tag_ids_param = $request->get_param( 'tag_ids' );
		if ( is_array( $tag_ids_param ) ) {
			Tag::sync_object_tags( (int) $question->id, 'question', $tag_ids_param );
		}

		$updated_question = Question::find( (int) $question->id );
		$answers          = Answer::where( [ 'question_id' => (int) $question->id ] );

		return $this->single_response(
			[
				'question' => Question::to_typed_array( $updated_question ),
				'answers'  => array_map( [ Answer::class, 'to_typed_array' ], $answers ),
			]
		);
	}

	public function delete_question( WP_REST_Request $request ): WP_REST_Response {
		$question = Question::find( (int) $request->get_param( 'id' ) );
		if ( ! $question ) {
			return $this->error_response( $this->not_found( 'Question' ) );
		}

		$question_id = (int) $question->id;

		// The child-row cascade (responses -> slots -> answers -> parent) lives
		// in Question::delete() so EVERY caller gets it, not just this one —
		// this controller used to do it by hand, which is why the CSV tooling,
		// fixtures, the seeder and CLI all left answers behind.
		if ( ! Question::delete( $question_id ) ) {
			return $this->error_response( $this->validation_error( __( 'The question could not be deleted. Please try again.', 'learnomy' ) ) );
		}

		return $this->single_response(
			[
				'deleted' => true,
				'id'      => $question_id,
			]
		);
	}

	public function get_statistics( WP_REST_Request $request ): WP_REST_Response {
		$question = Question::find( (int) $request->get_param( 'id' ) );
		if ( ! $question ) {
			return $this->error_response( $this->not_found( 'Question' ) );
		}

		$question_id = (int) $question->id;
		$stats       = QuestionResponse::get_statistics( $question_id );

		$times_answered = $stats ? (int) $stats->times_answered : 0;
		$avg_score_pct  = $stats ? round( (float) $stats->avg_score_ratio * 100, 2 ) : 0.0;

		// Point-biserial discrimination: how well this question separates strong from weak students.
		// Requires at least 10 graded responses for a meaningful result.
		$discrimination = null;
		if ( $times_answered >= 10 ) {
			$discrimination = $this->calculate_discrimination( $question_id );
		}

		return $this->single_response(
			[
				'question_id'    => $question_id,
				'times_answered' => $times_answered,
				'avg_score_pct'  => $avg_score_pct,
				'discrimination' => $discrimination,
			]
		);
	}

	public function duplicate_question( WP_REST_Request $request ): WP_REST_Response {
		$source = Question::find( (int) $request->get_param( 'id' ) );
		if ( ! $source ) {
			return $this->error_response( $this->not_found( 'Question' ) );
		}

		$new_id = Question::duplicate( (int) $source->id );
		if ( ! $new_id ) {
			return $this->error_response( $this->validation_error( __( 'Could not duplicate the question.', 'learnomy' ) ) );
		}

		$question = Question::find( $new_id );
		$answers  = Answer::get_for_question( $new_id );

		$response = $this->single_response(
			[
				'question' => Question::to_typed_array( $question ),
				'answers'  => array_map( [ Answer::class, 'to_typed_array' ], $answers ),
			]
		);
		$response->set_status( 201 );

		return $response;
	}

	public function export_csv( WP_REST_Request $request ): WP_REST_Response {
		$filters = [];

		$category_id = absint( $request->get_param( 'category_id' ) );
		if ( $category_id > 0 ) {
			$filters['category_id'] = $category_id;
		}

		$type = sanitize_text_field( wp_unslash( $request->get_param( 'type' ) ?? '' ) );
		if ( $type ) {
			$normalized = QuestionTypes::normalize( $type );
			if ( in_array( $normalized, QuestionTypes::slugs(), true ) ) {
				$filters['type'] = $normalized;
			}
		}

		$difficulty = $request->get_param( 'difficulty' );
		if ( null !== $difficulty && '' !== $difficulty ) {
			$filters['difficulty'] = Question::normalize_difficulty( $difficulty );
		}

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( $status ) {
			$filters['status'] = $status;
		}

		$csv = Question_CSV::export( $filters );

		// Hand the caller a download-ready response. REST normally serializes
		// to JSON; a manual WP_REST_Response with the raw CSV body + explicit
		// content-type lets wp-admin's fetch+save-as flow work without proxy.
		$response = new WP_REST_Response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="questions-' . gmdate( 'Ymd' ) . '.csv"' );

		return $response;
	}

	public function import_csv( WP_REST_Request $request ): WP_REST_Response {
		$csv = (string) $request->get_param( 'csv' );
		if ( '' === trim( $csv ) ) {
			return $this->error_response( $this->validation_error( __( 'Please provide CSV content to import.', 'learnomy' ) ) );
		}

		$result = Question_CSV::import( $csv );

		return $this->single_response( $result );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function can_read(): bool|WP_Error {
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

		return true;
	}

	public function can_write( WP_REST_Request $request ): bool|WP_Error {
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

		// The question bank is author-scoped, but `quiz_ids` reaches OUT of it:
		// sync_question_slots() attaches the question into every quiz named
		// here. Each of those is a write into that quiz's course, so gate them
		// (Basecamp 10133286758).
		return $this->require_parent_ownership( [ 'quiz' => $this->requested_quiz_ids( $request ) ] );
	}

	public function can_manage( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// ORDER MATTERS, and it is the whole fix.
		//
		// This used to look the question up FIRST and answer 403 when it was
		// missing, so PATCH / DELETE / duplicate on a non-existent id told an
		// author "you do not have permission" for a row that was simply gone.
		// The author then hunts a permission problem that does not exist.
		//
		// The type-level entitlement is checked first instead. Someone allowed
		// to manage questions at all is entitled to learn that this one is not
		// there — that is a 404. Someone NOT allowed still gets 403 for both
		// cases, so a stranger cannot probe which ids exist by reading the
		// status code. Same rule WordPress core applies, and the same
		// indistinguishable-denial principle the instructor-profile route uses.
		//
		// Delegate to Permission_Engine — the canonical entry point. We gate on
		// manage_own_courses since no per-question ability exists; the
		// author_id guard below enforces ownership for non-admins.
		if ( ! $this->check_permission( 'manage_own_courses' ) ) {
			return $this->permission_error();
		}

		$question = Question::find( (int) $request->get_param( 'id' ) );
		if ( ! $question ) {
			return $this->not_found( 'Question' );
		}

		// Permission_Engine bypass — ownership predicate only, not an access gate.
		// check_permission() above already routed through Permission_Engine for
		// the capability check. This user_can() call is a role-membership test
		// (is the user an admin?) used solely to decide whether the author_id
		// ownership constraint applies. No WP cap check should be factored into
		// Permission_Engine here because the 'admin' role detection is a data
		// predicate, not a permission decision. See audit/abilities-coverage.md.
		if ( ! user_can( $user_id, 'manage_options' ) && (int) $question->author_id !== $user_id ) {
			return $this->permission_error();
		}

		// Authoring the question does not entitle the author to place it in, or
		// pull it out of, anyone else's quiz. See requested_quiz_ids().
		return $this->require_parent_ownership( [ 'quiz' => $this->requested_quiz_ids( $request ) ] );
	}

	/**
	 * The quizzes a write would actually MUTATE through `quiz_ids`.
	 *
	 * Because sync_question_slots() both attaches and detaches, the gated set is the
	 * symmetric difference between the question's current attachments and the
	 * requested ones: adding a question to a quiz and ripping one out of a quiz
	 * are both writes into that quiz's course. Ids present on BOTH sides are
	 * untouched by the sync and are deliberately not gated — otherwise an
	 * admin who placed an instructor's question into a shared quiz would freeze
	 * that instructor out of every later edit to their own bank item.
	 *
	 * @return int[]
	 */
	private function requested_quiz_ids( WP_REST_Request $request ): array {
		$requested = $request->get_param( 'quiz_ids' );
		if ( ! is_array( $requested ) ) {
			return [];
		}

		$target = array_values(
			array_unique(
				array_filter( array_map( 'intval', $requested ), static fn( int $id ): bool => $id > 0 )
			)
		);

		$question_id = (int) $request->get_param( 'id' );
		if ( ! $question_id ) {
			// Create — nothing is attached yet, so every target is an attach.
			return $target;
		}

		$current = array_map( 'intval', QuizSlot::get_quiz_ids_for_question( $question_id ) );

		return array_values(
			array_unique( array_merge( array_diff( $target, $current ), array_diff( $current, $target ) ) )
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function extract_question_fields( WP_REST_Request $request ): array {
		$data = [];

		// Accept `title` (the UI convention) or legacy `body` — both map to
		// the schema's `title` column.
		$title = $request->get_param( 'title' );
		if ( null === $title ) {
			$title = $request->get_param( 'body' );
		}
		if ( null !== $title ) {
			$data['title'] = wp_kses_post( wp_unslash( (string) $title ) );
		}

		$type = $request->get_param( 'type' );
		if ( null !== $type ) {
			// Normalize legacy aliases (`multiple_choice` → `mc_single`) before
			// whitelist validation so old clients keep working.
			$normalized = QuestionTypes::normalize( (string) $type );
			if ( in_array( $normalized, QuestionTypes::slugs(), true ) ) {
				$data['type'] = $normalized;
			}
		}

		// Difficulty accepts the legacy string enum ('easy'|'medium'|'hard')
		// or the schema's native numeric scale 1–5. UI uses 1–5 for finer
		// granularity; strings map onto midpoints (easy=2, medium=3, hard=4).
		$difficulty = $request->get_param( 'difficulty' );
		if ( null !== $difficulty && '' !== $difficulty ) {
			$data['difficulty'] = Question::normalize_difficulty( $difficulty );
		}

		$category_id = $request->get_param( 'category_id' );
		if ( null !== $category_id ) {
			$data['category_id'] = absint( $category_id ) ?: null;
		}

		$explanation = $request->get_param( 'explanation' );
		if ( null !== $explanation ) {
			$data['explanation'] = wp_kses_post( wp_unslash( $explanation ) );
		}

		$hint = $request->get_param( 'hint' );
		if ( null !== $hint ) {
			$data['hint'] = sanitize_text_field( wp_unslash( $hint ) );
		}

		// Optional passage / code snippet shown above the answers. Distinct
		// from `title` (the question prompt itself) — the schema stores it on
		// its own column so the layout can render them separately.
		$content = $request->get_param( 'content' );
		if ( null !== $content ) {
			// `content` is now fill_blank's token body only (the rich passage moved
			// to the dedicated `stem` column in 3.12.0). Plain wp_kses_post keeps
			// the {{blankN}} tokens intact.
			$data['content'] = wp_kses_post( wp_unslash( (string) $content ) );
		}

		// A3: the rich question stem (its own column) stores through the one content
		// engine — canonical HTML, round-trip-safe — for every question type.
		$stem = $request->get_param( 'stem' );
		if ( null !== $stem ) {
			// Do NOT wp_unslash() — REST JSON body params are not slashed, and
			// unslashing strips the \" escaping in the Editor.js stem, corrupting
			// the JSON. Same fix as the lessons `content` field.
			$data['stem'] = \Learnomy\prepare_question_stem( (string) $stem );
		}

		// Status drives whether the question shows up to non-author readers.
		// Allow-list against the schema's draft/published/archived enum so a
		// stray client value can't poison the column.
		$status = $request->get_param( 'status' );
		if ( null !== $status ) {
			$normalized = sanitize_key( wp_unslash( (string) $status ) );
			if ( in_array( $normalized, [ 'draft', 'published', 'archived' ], true ) ) {
				$data['status'] = $normalized;
			}
		}

		return $data;
	}

	/**
	 * Persists an array of answer objects for a question.
	 *
	 * Each answer: { content, is_correct, fraction?, feedback?, group_id?, sort_order? }
	 *
	 * Mirrors the AJAX path's normalization so REST writes preserve partial
	 * credit (`fraction`), per-row order (`sort_order` for ordering questions),
	 * and group tagging (`group_id` for matching / fill-blank). Skipping these
	 * silently degrades the question type — partial-credit math collapses to
	 * binary, and ordering/matching lose their answer-key positioning.
	 */
	private function save_answers( int $question_id, array $answers ): void {
		foreach ( $answers as $idx => $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['content'] ) ) {
				continue;
			}

			$is_correct = ! empty( $raw['is_correct'] ) ? 1 : 0;
			// Floor is -1.0, not 0.0. Negative marking is a real assessment
			// technique -- it is how a teacher stops a multiple-choice paper
			// rewarding a guess -- and clamping it to zero silently turned
			// "this option costs you a mark" into "this option is free",
			// changing the paper's meaning without telling the author.
			// (Basecamp 10153962110)
			//
			// -1.0 is a full penalty: the worst a single wrong option can do is
			// cancel a correct one. The ATTEMPT total is floored at zero
			// separately, so a learner can never finish a quiz owing marks.
			$fraction = isset( $raw['fraction'] )
				? max( -1.0, min( 1.0, (float) $raw['fraction'] ) )
				: ( $is_correct ? 1.0 : 0.0 );

			Answer::create(
				[
					'question_id' => $question_id,
					'content'     => wp_kses_post( wp_unslash( (string) $raw['content'] ) ),
					'is_correct'  => $is_correct,
					'fraction'    => $fraction,
					'feedback'    => isset( $raw['feedback'] ) ? sanitize_textarea_field( wp_unslash( (string) $raw['feedback'] ) ) : '',
					'group_id'    => isset( $raw['group_id'] ) ? sanitize_text_field( wp_unslash( (string) $raw['group_id'] ) ) : null,
					'sort_order'  => isset( $raw['sort_order'] ) ? (int) $raw['sort_order'] : (int) $idx,
				]
			);
		}
	}

	/**
	 * Reconcile the question's QuizSlot rows against the supplied quiz id list.
	 *
	 * The caller passes the desired target set; we INSERT new attachments and
	 * REMOVE ones that are no longer in the list. New slots land at the end of
	 * the target quiz (sort_order = current max + 1).
	 */
	private function sync_question_slots( int $question_id, array $quiz_ids ): void {
		$current = array_map( 'intval', QuizSlot::get_quiz_ids_for_question( $question_id ) );
		$target  = array_values(
			array_unique(
				array_filter( array_map( 'intval', $quiz_ids ), static fn( int $id ): bool => $id > 0 )
			)
		);

		foreach ( array_diff( $current, $target ) as $detach_id ) {
			QuizSlot::remove_question( (int) $detach_id, $question_id );
		}
		foreach ( array_diff( $target, $current ) as $attach_id ) {
			$existing  = QuizSlot::get_for_quiz( (int) $attach_id );
			$next_sort = empty( $existing )
				? 0
				: ( (int) max( array_map( static fn( $slot ): int => (int) $slot->sort_order, $existing ) ) + 1 );
			QuizSlot::add_question( (int) $attach_id, $question_id, $next_sort );
		}
	}

	/**
	 * Computes a simplified point-biserial discrimination coefficient.
	 * Returns a float in [-1, 1] where values above ~0.2 indicate a useful question.
	 */
	private function calculate_discrimination( int $question_id ): float {
		// Fetch each graded response alongside the overall attempt percentage.
		$rows = \Learnomy\Models\QuestionResponse::get_discrimination_data( $question_id );

		if ( count( $rows ) < 2 ) {
			return 0.0;
		}

		// Build arrays: item score ratio and total test score.
		$item_scores  = array_map( fn( $r ) => (float) $r->score / (float) $r->max_score, $rows );
		$total_scores = array_map( fn( $r ) => (float) $r->attempt_pct / 100, $rows );

		$n         = count( $rows );
		$mean_item = array_sum( $item_scores ) / $n;
		$mean_test = array_sum( $total_scores ) / $n;

		$numerator  = 0.0;
		$denom_item = 0.0;
		$denom_test = 0.0;

		for ( $i = 0; $i < $n; $i++ ) {
			$di          = $item_scores[ $i ] - $mean_item;
			$dt          = $total_scores[ $i ] - $mean_test;
			$numerator  += $di * $dt;
			$denom_item += $di * $di;
			$denom_test += $dt * $dt;
		}

		$denominator = sqrt( $denom_item * $denom_test );
		if ( $denominator < 0.0001 ) {
			return 0.0;
		}

		return round( $numerator / $denominator, 4 );
	}

	private function question_args( bool $body_required = true ): array {
		// Note: `$body_required` only affects `type` here. `title` and `body`
		// are deliberately both optional at the schema layer because clients
		// may send either one — extract_question_fields() falls back to `body`
		// when `title` is missing, and create_question() rejects with a clear
		// "Question text is required" message if both end up empty.
		return [
			// `title` is the canonical UI field name; `body` is kept as a legacy
			// alias for older REST clients (extract_question_fields normalises).
			'title'       => [
				'type'     => 'string',
				'required' => false,
			],
			'body'        => [
				'type'     => 'string',
				'required' => false,
			],
			'type'        => [
				'type'     => 'string',
				'required' => $body_required,
				// Accept canonical slugs plus legacy REST aliases (`multiple_choice`,
				// `multiple_select`) so older clients continue to validate. The
				// extract_question_fields() helper normalizes before persisting.
				'enum'     => array_values( array_unique( array_merge( QuestionTypes::slugs(), [ 'multiple_choice', 'multiple_select' ] ) ) ),
			],
			'difficulty'  => [
				// Accept the legacy string enum (easy/medium/hard) and the
				// canonical 1–5 numeric scale. extract_question_fields() maps
				// either onto the storage column.
				'type' => [ 'string', 'integer' ],
			],
			'category_id' => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'content'     => [
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			],
			// A3: rich stem (Editor.js JSON or HTML). Sanitised at the storage layer
			// by prepare_question_stem() -> Content_HTML (role-aware kses + canonicalize),
			// so no aggressive arg-level sanitize that would strip block JSON.
			'stem'        => [
				'type' => 'string',
			],
			'status'      => [
				'type' => 'string',
				'enum' => [ 'draft', 'published', 'archived' ],
			],
			'explanation' => [
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			],
			'hint'        => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'answers'     => [
				'type'        => 'array',
				'description' => __( 'Array of answer objects with content, is_correct, optional fraction (partial credit), feedback, group_id, sort_order.', 'learnomy' ),
			],
			'quiz_ids'    => [
				'type'        => 'array',
				'items'       => [ 'type' => 'integer' ],
				'description' => __( 'Quizzes the question should be attached to after save. Omit to leave existing attachments untouched; pass an empty array to detach from every quiz.', 'learnomy' ),
			],
		];
	}
}
