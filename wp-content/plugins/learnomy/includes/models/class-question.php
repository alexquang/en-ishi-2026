<?php
/**
 * Question — versioned question bank entry shared across any quiz.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Question extends Model {

	/** Canonical publish-status vocabulary — one word across every content entity. */
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_DRAFT     = 'draft';

	/** Opt into the base Model publish-status failsafe (heals `publish` -> `published`). */
	protected static function normalizes_publish_status(): bool {
		return true;
	}

	protected const INT_COLUMNS = [
		'difficulty',
		'category_id',
		'version',
		'author_id',
	];

	protected static function table_name(): string {
		return 'questions';
	}

	/**
	 * Override base delete() to cascade a question's child rows.
	 *
	 * The schema declares no FK ON DELETE CASCADE, so every caller that hard-
	 * deletes a question must clear its answers, its recorded responses and any
	 * quiz slots pointing at it. The REST controller did all three by hand;
	 * nothing else did, so a question removed by the CSV tooling, a fixture
	 * builder, the demo seeder or CLI left its answers behind — 22 orphan answer
	 * rows across 12 vanished questions on the reference site. Centralising it
	 * here covers every caller, present and future, exactly as
	 * {@see Transaction::delete()} does for commission rows.
	 *
	 * Order mirrors Quiz_Service::delete_quiz() (responses -> slots -> answers ->
	 * parent): an orphaned ungraded essay response keeps count_ungraded() above
	 * zero forever, stranding the attempt at `submitted`, and an orphaned slot
	 * still adds its marks to auto_grade()'s map while the per-question guard
	 * skips scoring it — silently lowering attempt scores.
	 */
	public static function delete( int $id ): bool {
		QuestionResponse::delete_for_question( $id );
		QuizSlot::delete_for_question( $id );
		Answer::delete_for_question( $id );

		return parent::delete( $id );
	}

	/** version=1 on create — bump it via increment_version() when editing an in-use question. */
	public static function create( array $question ): int {
		return static::insert(
			array_merge(
				[
					'version'    => 1,
					'created_at' => now(),
				],
				static::normalise_fill_blank( $question )
			)
		);
	}

	/**
	 * Same normalisation on edit as on create.
	 *
	 * Question has no update() of its own and inherits Model's, so without this
	 * the fix would cover only newly-created questions — an author who typed
	 * underscores while EDITING would still save an unanswerable one, which is
	 * the more likely path once a question exists.
	 *
	 * An edit that does not send `type` cannot be judged, so the row's stored
	 * type is read first; skipping those would leave every partial update
	 * unnormalised.
	 *
	 * @param int   $id   Question id.
	 * @param array $data Fields being written.
	 * @return bool
	 */
	public static function update( int $id, array $data ): bool {
		if ( ! isset( $data['type'] ) ) {
			$row = static::find( $id );
			if ( $row && isset( $row->type ) ) {
				$data['type'] = (string) $row->type;
				$data         = static::normalise_fill_blank( $data );
				unset( $data['type'] );

				return parent::update( $id, $data );
			}
		}

		return parent::update( $id, static::normalise_fill_blank( $data ) );
	}

	/**
	 * Turn an author's underscores into the {{blankN}} tokens the player needs.
	 *
	 * A fill_blank question only renders inputs when its `content` carries
	 * `{{blank1}}`-style tokens: the API parses them out and the player draws one
	 * field per token. Nothing taught an author that. Writing the question the
	 * obvious way — "a list needs a unique ____ prop" — produced a question with
	 * NO inputs at all, which a student cannot answer and the pre-submit guard
	 * then reports as "1 unanswered". The shipped demo academy had exactly that
	 * (Basecamp 10171508719).
	 *
	 * Deriving beats rejecting here. A hard validation error on save would be a
	 * new failure mode on a path used by wp-admin, the Pro builder and the REST
	 * API — including two-step flows that create the question before its body —
	 * and it would still leave the author guessing the syntax. Converting is
	 * lossless and makes the obvious way the correct way.
	 *
	 * Deliberately conservative:
	 * - Only fill_blank rows are touched.
	 * - A body that ALREADY has tokens is returned untouched, so a question
	 *   authored correctly can never be rewritten.
	 * - Only runs of 3+ underscores convert, so prose containing snake_case or a
	 *   single stray underscore is left alone.
	 * - If the body has no underscores either, nothing is invented — that is an
	 *   incomplete question, not one we can guess at.
	 *
	 * Placed on the model rather than the REST controller because wp-admin, the
	 * abilities layer, the CLI fixtures and the seeder all write through here;
	 * putting it on one surface would leave the other four able to store the
	 * same broken row.
	 *
	 * @param array $question Row about to be written.
	 * @return array
	 */
	protected static function normalise_fill_blank( array $question ): array {
		if ( 'fill_blank' !== ( $question['type'] ?? '' ) ) {
			return $question;
		}

		$body = (string) ( $question['content'] ?? '' );

		// Author put the blanks in the title and left the body empty — the exact
		// shape the demo seeder shipped. Promote it so there is something to parse.
		if ( '' === trim( $body ) && preg_match( '/_{3,}/', (string) ( $question['title'] ?? '' ) ) ) {
			$body = (string) $question['title'];
		}

		if ( '' === trim( $body ) || preg_match( '/\{\{\s*blank\d+\s*\}\}/i', $body ) ) {
			return $question;
		}

		$n = 0;
		$converted = preg_replace_callback(
			'/_{3,}/',
			static function () use ( &$n ) {
				++$n;
				return '{{blank' . $n . '}}';
			},
			$body
		);

		if ( $n > 0 ) {
			$question['content'] = $converted;
		}

		return $question;
	}

	public static function get_by_category( int $category_id ): array {
		return static::where( [ 'category_id' => $category_id ], 'created_at', 'DESC' );
	}

	public static function get_by_type( string $type ): array {
		return static::where( [ 'type' => $type ], 'created_at', 'DESC' );
	}

	/**
	 * Random pool match — used by Pro's Advanced Quizzes pool feature.
	 *
	 * Generic enough to belong in Free's Question Model: any feature that
	 * needs "N random questions matching X criteria" (course recommender,
	 * adaptive testing, etc.) can use this. Centralising here means the
	 * complex query (BETWEEN + IN + ORDER BY RAND) stays in the Models
	 * layer per the layered-architecture rule.
	 *
	 * Note on scale: `ORDER BY RAND()` is O(N) on the questions table.
	 * Acceptable for typical question banks (≤ 10k entries). For 100k+
	 * question banks, a future optimisation can use offset-via-id-jump.
	 *
	 * @param array{category_ids?:int[],difficulty_min?:int,difficulty_max?:int,type?:string,status?:string} $filters
	 * @return int[] Matched question IDs.
	 */
	public static function pool_match( array $filters, int $limit ): array {
		$db = static::db();

		$where  = array();
		$params = array();

		$status   = $filters['status'] ?? self::STATUS_PUBLISHED;
		$where[]  = 'status = %s';
		$params[] = $status;

		$category_ids = array_filter( array_map( 'absint', (array) ( $filters['category_ids'] ?? [] ) ) );
		if ( ! empty( $category_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $category_ids ), '%d' ) );
			$where[]      = "category_id IN ({$placeholders})";
			$params       = array_merge( $params, $category_ids );
		}

		if ( isset( $filters['difficulty_min'] ) && isset( $filters['difficulty_max'] ) ) {
			$where[]  = 'difficulty BETWEEN %d AND %d';
			$params[] = max( 1, min( 5, (int) $filters['difficulty_min'] ) );
			$params[] = max( 1, min( 5, (int) $filters['difficulty_max'] ) );
		}

		if ( ! empty( $filters['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = (string) $filters['type'];
		}

		$where_sql = implode( ' AND ', $where );
		$params[]  = max( 1, $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = 'SELECT id FROM ' . static::table() . ' WHERE ' . $where_sql
			. ' ORDER BY RAND() LIMIT %d';

		$ids = $db->get_col( $db->prepare( $sql, ...$params ) );
		return array_map( 'absint', (array) $ids );
	}

	/**
	 * THE difficulty vocabulary. One map, one clamp, one fallback.
	 *
	 * `lrn_questions.difficulty` is a 1-5 integer scale. Three places converted
	 * to it and all three disagreed:
	 *
	 *   - REST create mapped easy=>2, medium=>3, hard=>4
	 *   - REST filter mapped easy=>1, medium=>3, hard=>5
	 *   - CSV import used `(int) $value ?: 3`, so a literal 0 became 3 while
	 *     the same 0 through REST became 1
	 *
	 * So a question created as "easy" (stored 2) was invisible to a filter for
	 * "easy" (searching 1), and only "medium" worked by coincidence -- both
	 * maps happened to say 3. Nobody notices a filter returning nothing;
	 * they assume the bank is empty. (Basecamp 10153962392, 10153963135)
	 *
	 * The named levels sit at the ENDS and the middle of the scale (1/3/5)
	 * rather than 2/3/4, so "easy" and "hard" mean what they say and the
	 * intermediate values 2 and 4 stay available to an author who wants them.
	 *
	 * @param mixed $value Raw difficulty: 1-5, or easy|medium|hard.
	 * @return int A valid 1-5 level. Out-of-range values clamp to the nearest
	 *             end; anything unrecognised becomes 3 (medium), the neutral
	 *             default -- never 1, which would silently mark every bad
	 *             import as the easiest question in the bank.
	 */
	public static function normalize_difficulty( $value ): int {
		if ( is_numeric( $value ) ) {
			return max( 1, min( 5, (int) $value ) );
		}

		$map = array(
			'easy'   => 1,
			'medium' => 3,
			'hard'   => 5,
		);

		$slug = is_string( $value ) ? strtolower( trim( $value ) ) : '';

		return $map[ $slug ] ?? 3;
	}

	/**
	 * Bumps version rather than editing in place so that historical attempt responses
	 * remain interpretable against the question text that was shown at the time.
	 */
	public static function increment_version( int $id ): bool {
		$question = static::find( $id );
		if ( ! $question ) {
			return false;
		}
		return static::update( $id, [ 'version' => (int) $question->version + 1 ] );
	}

	/**
	 * Clones a question row and its answer rows into a fresh record.
	 *
	 * The copy resets `version` to 1 and appends " (Copy)" to the title so
	 * the bank list visibly distinguishes it. Answers are reinserted with
	 * their correctness / fraction / feedback / group_id / sort_order
	 * preserved so grading remains identical.
	 *
	 * @param int $id Source question identifier.
	 * @return int    New question identifier (0 on failure).
	 */
	public static function duplicate( int $id ): int {
		$source = static::find( $id );
		if ( ! $source ) {
			return 0;
		}

		$new_id = static::create(
			[
				'type'        => $source->type ?? 'mc_single',
				/* translators: %s: original question title */
				'title'       => sprintf( __( '%s (Copy)', 'learnomy' ), $source->title ?? '' ),
				'content'     => $source->content ?? '',
				'stem'        => $source->stem ?? '',
				'explanation' => $source->explanation ?? '',
				'hint'        => $source->hint ?? '',
				'difficulty'  => (int) ( $source->difficulty ?? 3 ),
				'category_id' => $source->category_id ?? null,
				'status'      => $source->status ?? 'published',
				'author_id'   => get_current_user_id() ?: (int) ( $source->author_id ?? 0 ),
			]
		);

		if ( ! $new_id ) {
			return 0;
		}

		// Copy answer rows verbatim (minus the id, which auto-increments).
		$answers = Answer::get_for_question( $id );
		foreach ( (array) $answers as $answer ) {
			Answer::create(
				[
					'question_id' => $new_id,
					'content'     => $answer->content ?? '',
					'is_correct'  => (int) ( $answer->is_correct ?? 0 ),
					'fraction'    => (float) ( $answer->fraction ?? 0.0 ),
					'feedback'    => $answer->feedback ?? '',
					'group_id'    => $answer->group_id ?? null,
					'sort_order'  => (int) ( $answer->sort_order ?? 0 ),
				]
			);
		}

		return (int) $new_id;
	}

	/**
	 * Returns the id of the most recently inserted question whose title
	 * starts with $prefix, or null if none exists.
	 *
	 * Used by the CLI question-CSV round-trip test to locate the row that
	 * the CSV importer just created without relying on a fragile "last
	 * insert id" that may span several transactions.
	 */
	public static function find_latest_by_title_prefix( string $prefix ): ?int {
		$id = (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT id FROM ' . static::table() . ' WHERE title LIKE %s ORDER BY id DESC LIMIT 1',
				static::db()->esc_like( $prefix ) . '%'
			)
		);
		return $id > 0 ? $id : null;
	}

	/**
	 * Paginated questions list with a denormalized `use_count` field.
	 *
	 * Drives the admin Question Bank list table where the "times used"
	 * column counts how many quizzes reference each question. A correlated
	 * `SELECT COUNT(*)` subquery avoids the GROUP BY that a LEFT JOIN
	 * would otherwise force, keeping pagination arithmetic straightforward
	 * against very large banks.
	 *
	 * @param array<string,scalar> $where   Equality filters: column => value.
	 *                                      Same shape as Model::where() accepts.
	 * @param string               $orderby Column name (whitelisted on write,
	 *                                      escaped with esc_sql here).
	 * @param string               $order   ASC or DESC.
	 * @param int                  $limit   Rows to return.
	 * @param int                  $offset  Rows to skip.
	 * @return array<int,object>            Question rows with `use_count` attribute.
	 */
	public static function with_usage_counts( array $where = [], string $orderby = 'id', string $order = 'DESC', int $limit = 20, int $offset = 0, int $tag_id = 0, string $search = '' ): array {
		global $wpdb;

		$questions_table = static::table();
		$slots_table     = \Learnomy\table( 'quiz_slots' );

		[ $join_sql, $conditions, $values ] = self::build_filter_sql( $where, $tag_id, $search );

		$where_sql = ! empty( $conditions ) ? ' WHERE ' . implode( ' AND ', $conditions ) : '';

		// Whitelist the ORDER BY clause — the value lands in raw SQL.
		$safe_orderby = (string) esc_sql( $orderby );
		$safe_order   = in_array( strtoupper( $order ), [ 'ASC', 'DESC' ], true ) ? strtoupper( $order ) : 'DESC';

		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		// The subquery runs once per parent row and returns the slot count.
		// $slots_table and $questions_table are identifiers composed from
		// $wpdb->prefix — safe to interpolate.
		$sql = "SELECT q.*, (SELECT COUNT(*) FROM {$slots_table} s WHERE s.question_id = q.id) AS use_count"
			. " FROM {$questions_table} q"
			. $join_sql
			. $where_sql
			. " ORDER BY q.`{$safe_orderby}` {$safe_order}"
			. ' LIMIT %d OFFSET %d';

		$values[] = $limit;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is composed from whitelisted identifiers; every value is bound via prepare().
		$prepared = $wpdb->prepare( $sql, ...$values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $prepared );

		return $rows ?: [];
	}

	/**
	 * Distinct author ids that actually own at least one question.
	 *
	 * The Question Bank's Author filter used to source its options from a
	 * `WP_User_Query( capability__in => [ manage_options, edit_others_posts ] )`,
	 * i.e. "users who can edit posts" — a WP-core-role question that has nothing
	 * to do with who wrote the bank. `lrn_instructor` carries neither cap, so the
	 * dropdown listed administrators and editors only and silently omitted every
	 * instructor, who are the primary authors. Ask the bank instead: the author
	 * set IS `SELECT DISTINCT author_id`, and it can never disagree with itself.
	 *
	 * `author_id` is indexed (`KEY author_id`), so the DISTINCT is an index-only
	 * scan and stays cheap on a six-figure bank. Ordered by volume so the busiest
	 * authors sit at the top of the list, then capped — a filter bar is a
	 * shortcut, not a directory.
	 *
	 * @param int $limit Max authors to return.
	 * @return array<int,int> Author user ids, busiest first. Never contains 0.
	 */
	public static function author_ids( int $limit = 200 ): array {
		global $wpdb;

		$table = static::table();
		$limit = max( 1, $limit );

		$sql = "SELECT author_id FROM {$table}"
			. ' WHERE author_id > 0'
			. ' GROUP BY author_id'
			. ' ORDER BY COUNT(*) DESC, author_id ASC'
			. ' LIMIT %d';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is composed from $wpdb->prefix; the only value is bound.
		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $limit ) );

		return array_map( 'intval', $rows ?: [] );
	}

	/**
	 * COUNT for the same filter set as {@see with_usage_counts()}, including the
	 * optional tag JOIN. Base Model::count() can't express the JOIN, so the
	 * Question Bank list uses this for an accurate paginated total.
	 *
	 * @param array<string,mixed> $where
	 */
	public static function count_filtered( array $where = [], int $tag_id = 0, string $search = '' ): int {
		global $wpdb;

		$questions_table                    = static::table();
		[ $join_sql, $conditions, $values ] = self::build_filter_sql( $where, $tag_id, $search );
		$where_sql                          = ! empty( $conditions ) ? ' WHERE ' . implode( ' AND ', $conditions ) : '';

		$sql = "SELECT COUNT(DISTINCT q.id) FROM {$questions_table} q{$join_sql}{$where_sql}";

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, ...$values );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Compose the JOIN + WHERE fragments shared by the list + count queries.
	 * A tag filter INNER JOINs lrn_tag_map on (object_id, object_type='question',
	 * tag_id) — index-friendly via the map's (tag_id, object_type) key.
	 *
	 * @param array<string,mixed> $where
	 * @return array{0:string,1:string[],2:array<int,mixed>} [join_sql, conditions, values]
	 */
	private static function build_filter_sql( array $where, int $tag_id, string $search = '' ): array {
		global $wpdb;

		$conditions = [];
		$values     = [];
		$join_sql   = '';

		// Tag JOIN value is bound first because the JOIN clause precedes WHERE.
		if ( $tag_id > 0 ) {
			$map      = $wpdb->prefix . 'lrn_tag_map';
			$join_sql = " INNER JOIN {$map} tm ON tm.object_id = q.id AND tm.object_type = 'question' AND tm.tag_id = %d";
			$values[] = $tag_id;
		}

		foreach ( $where as $col => $val ) {
			// Same optional comparison suffix Model::where() understands, so a
			// keyset cursor can be expressed here too. Additive: a key with no
			// suffix stays an equality, so every existing caller is unchanged.
			$op = '=';
			if ( preg_match( '/^(?<column>.+)__(?<op>lt|lte|gt|gte|not)$/', (string) $col, $m ) ) {
				$col = $m['column'];
				$op  = [
					'lt'  => '<',
					'lte' => '<=',
					'gt'  => '>',
					'gte' => '>=',
					'not' => '!=',
				][ $m['op'] ];
			}

			// Scope each filter to the `q` alias so the correlated subquery
			// in the SELECT never collides with a same-named column on slots.
			$safe_col     = (string) esc_sql( (string) $col );
			$conditions[] = is_int( $val ) ? "q.`{$safe_col}` {$op} %d" : "q.`{$safe_col}` {$op} %s";
			$values[]     = $val;
		}

		// Free-text search across the question title + body. The question
		// prompt lives in `title`; `content` carries any longer body.
		if ( '' !== $search ) {
			$like         = '%' . $wpdb->esc_like( $search ) . '%';
			$conditions[] = '(q.title LIKE %s OR q.content LIKE %s)';
			$values[]     = $like;
			$values[]     = $like;
		}

		return [ $join_sql, $conditions, $values ];
	}
}
