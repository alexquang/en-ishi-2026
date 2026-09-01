<?php
/**
 * Quiz — standalone assessment or course-attached evaluation.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Quiz extends Model {

	/**
	 * A course-attached quiz goes with its course (Course_Service::delete()
	 * runs every one through Quiz_Service::delete_quiz). Standalone quizzes
	 * carry course_id 0 and are never matched.
	 *
	 * `lesson_id` is KEEP deliberately: deleting a lesson does NOT delete the
	 * quiz attached to it today, and a quiz holds attempts. Declaring DELETE
	 * here would have changed that on every site at once, and let the repair
	 * pass destroy graded work.
	 *
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'course' => 'course_id',
		'lesson' => array(
			'column'    => 'lesson_id',
			'on_delete' => \Learnomy\DB\Relations::KEEP,
		),
	);


	/** Canonical publish-status vocabulary — one word across every content entity. */
	public const STATUS_PUBLISHED = 'published';
	public const STATUS_DRAFT     = 'draft';

	/** Opt into the base Model publish-status failsafe (heals `publish` -> `published`). */
	protected static function normalizes_publish_status(): bool {
		return true;
	}

	protected const BOOL_COLUMNS = [
		'shuffle_questions',
		'shuffle_answers',
	];

	protected const INT_COLUMNS = [
		'course_id',
		'section_id',
		'lesson_id',
		'time_limit_seconds',
		'attempt_limit',
		'attempt_cooldown_seconds',
		'max_failed_attempts',
		'passing_score',
		'author_id',
	];

	protected static function table_name(): string {
		return 'quizzes';
	}

	/**
	 * Course-level quizzes that belong to NO section (section_id NULL/0) and are
	 * not attached to a lesson. Sections are optional, so these ungrouped
	 * quizzes must render in the course builder alongside section-less lessons.
	 *
	 * @param int $course_id Course.
	 * @return object[] Ungrouped quiz rows, in id order.
	 */
	public static function get_section_less_for_course( int $course_id ): array {
		$sql = 'SELECT * FROM ' . static::table()
			. ' WHERE course_id = %d AND ( section_id IS NULL OR section_id = 0 )'
			. ' AND ( lesson_id IS NULL OR lesson_id = 0 ) ORDER BY id ASC';

		return static::db()->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			static::db()->prepare( $sql, $course_id )
		) ?: [];
	}

	/**
	 * Section-attached quizzes for a course, grouped by section_id. ONE query
	 * scoped by course_id for the whole course, replacing the admin builder's
	 * per-section `where(section_id)` loop (an N+1). Ordered so each section's
	 * quizzes read in id order, matching the previous per-section fetch.
	 *
	 * @param int $course_id Course.
	 * @return array<int, array<int, object>> section_id => quiz rows[]
	 */
	public static function for_course_by_section( int $course_id ): array {
		$sql = 'SELECT * FROM ' . static::table()
			. ' WHERE course_id = %d AND section_id > 0 ORDER BY section_id ASC, id ASC';

		$rows = static::db()->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			static::db()->prepare( $sql, $course_id )
		) ?: [];

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row->section_id ][] = $row;
		}
		return $map;
	}

	public static function create( array $quiz ): int {
		// Slug uniqueness scoped to the course (F-03.X — mirrors the
		// F-02.4 lesson fix). Before this, two courses could end up with
		// quizzes sharing the same slug after CSV imports or duplicate
		// seed passes — the router resolved by slug only, so the lesson
		// player could load paid quiz content from a different course
		// the buyer was enrolled in. UNIQUE (course_id, slug) added by
		// migration 2.7.0 enforces this at the DB layer; this loop keeps
		// create-time slug generation in lockstep.
		//
		// Derive a slug from the title when the caller didn't supply one.
		// Course Builder creates quizzes without an explicit slug, and a
		// row with an empty slug is unreachable on the front end even after
		// it is published (the router resolves by slug). Falling back to a
		// stable random token keeps a title-less quiz routable too.
		if ( empty( $quiz['slug'] ) ) {
			$derived      = sanitize_title( (string) ( $quiz['title'] ?? '' ) );
			$quiz['slug'] = '' !== $derived ? $derived : 'quiz-' . wp_generate_password( 8, false, false );
		}

		if ( ! empty( $quiz['slug'] ) ) {
			$course_id = (int) ( $quiz['course_id'] ?? 0 );
			$base      = $quiz['slug'];
			$i         = 1;
			if ( $course_id > 0 ) {
				while ( static::find_by_course_and_slug( $course_id, $quiz['slug'] ) ) {
					$quiz['slug'] = $base . '-' . ( $i++ );
				}
			} else {
				// Standalone quiz (course_id IS NULL/0) — uniqueness
				// among standalone quizzes only; the UNIQUE constraint
				// allows multiple NULL course_id rows so this loop
				// is the only thing enforcing standalone-slug
				// uniqueness.
				while ( static::find_standalone_by_slug( $quiz['slug'] ) ) {
					$quiz['slug'] = $base . '-' . ( $i++ );
				}
			}
		}

		return static::insert(
			array_merge(
				[
					'created_at' => now(),
					'updated_at' => now(),
				],
				$quiz
			)
		);
	}

	/**
	 * Look up a quiz by slug WITHOUT scoping to a course.
	 *
	 * DEPRECATED for course-attached quiz routes after F-03.X (2026-05-19).
	 * The /courses/<course-slug>/quiz/<quiz-slug>/ resolver MUST go
	 * through {@see find_by_course_and_slug()} — slug-only lookup serves
	 * the wrong course's quiz when slugs collide.
	 *
	 * Kept for standalone-quiz routing (/quiz/<slug>/) where slug
	 * uniqueness is enforced at create time, and for legacy CLI / admin
	 * search uses.
	 */
	public static function find_by_slug( string $slug ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE slug = %s LIMIT 1',
				$slug
			)
		) ?: null;
	}

	/**
	 * Look up a quiz scoped to a specific course. Prefer this over
	 * {@see find_by_slug()} for any course-attached URL — the schema's
	 * UNIQUE (course_id, slug) (added by Migration 2.7.0) makes the
	 * lookup unambiguous and prevents the F-03.X security bypass.
	 */
	public static function find_by_course_and_slug( int $course_id, string $slug ): ?object {
		if ( $course_id <= 0 || '' === $slug ) {
			return null;
		}
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE course_id = %d AND slug = %s LIMIT 1',
				$course_id,
				$slug
			)
		) ?: null;
	}

	/**
	 * The public URL for a quiz. **The single answer to "where does this
	 * quiz live", for every surface in both plugins.**
	 *
	 * There are two routes and picking the wrong one 404s, because
	 * `find_standalone_by_slug()` matches only `course_id` NULL-or-0 by
	 * design: a course quiz on `/quiz/<slug>/` is not found, and a
	 * standalone quiz on `/courses/<c>/quiz/<slug>/` is not found either.
	 * So the choice is not cosmetic, and it was being made independently in
	 * six places with four different rules:
	 *
	 *   - Resume::quiz_url() required `$quiz->course_id === $course->id` —
	 *     the strict, correct rule, and the one implemented here.
	 *   - The lesson player checked that the quiz had SOME course_id and
	 *     that a course was in hand, without checking they were the same.
	 *   - Account quiz results checked only that a course was in hand,
	 *     never looking at `course_id` at all.
	 *   - Pro's learning-path steps used the standalone route
	 *     unconditionally, so a required step pointing at a course quiz
	 *     linked an enrolled student straight to a 404.
	 *
	 * Callers that already hold the course pass it to save the lookup; it is
	 * used only when it really is the quiz's own course, so passing the
	 * wrong one degrades to a correct URL rather than a broken one. A quiz
	 * whose course row is gone falls back to standalone rather than
	 * building a link around a slug we do not have.
	 *
	 * @param object      $quiz   Quiz row (needs `slug`, `course_id`).
	 * @param object|null $course Optional course already in hand.
	 */
	public static function url( object $quiz, ?object $course = null ): string {
		$course_id = (int) ( $quiz->course_id ?? 0 );

		if ( $course_id > 0 ) {
			if ( ! $course || (int) ( $course->id ?? 0 ) !== $course_id ) {
				$course = Course::find( $course_id );
			}

			if ( $course && ! empty( $course->slug ) ) {
				return \Learnomy\route_url(
					'quiz',
					array(
						'slug'      => (string) $course->slug,
						'quiz_slug' => (string) $quiz->slug,
					)
				);
			}
		}

		return \Learnomy\route_url( 'standalone-quiz', array( 'slug' => (string) $quiz->slug ) );
	}

	/**
	 * Look up a standalone quiz (course_id IS NULL) by slug. Used by the
	 * /quiz/<slug>/ standalone route — DB constraint allows multiple
	 * NULL course_id rows so create-time uniqueness handles the clash.
	 */
	public static function find_standalone_by_slug( string $slug ): ?object {
		if ( '' === $slug ) {
			return null;
		}
		// A standalone quiz is one not attached to a course. Depending on the
		// creation path that's stored as NULL (editor) or 0 (CLI / some seeders),
		// so treat both as standalone — otherwise a course_id=0 quiz 404s on its
		// /quiz/<slug>/ route even though it is published.
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE ( course_id IS NULL OR course_id = 0 ) AND slug = %s LIMIT 1',
				$slug
			)
		) ?: null;
	}

	/**
	 * Quizzes for a course, published-only by default.
	 *
	 * The status guard lives HERE rather than at each call site, mirroring
	 * `Lesson::get_for_course()` (Basecamp 10124162733). Quizzes previously
	 * had no default guard at all, so every caller that forgot a status
	 * condition leaked draft quizzes to students — the course curriculum and
	 * the course-page content counts both did (Basecamp 10135041249).
	 *
	 * Pass `null` to opt back into every status. Only three callers should:
	 * the delete cascade (a draft quiz still has to be cleaned up), the
	 * editor-only outline endpoint, and Pro's course builder.
	 *
	 * @param int         $course_id Course.
	 * @param string|null $status    Status to filter on; null for every status.
	 * @return object[] Quiz rows in id order.
	 */
	public static function get_for_course( int $course_id, ?string $status = self::STATUS_PUBLISHED ): array {
		$where = [ 'course_id' => $course_id ];
		if ( null !== $status ) {
			$where['status'] = $status;
		}

		return static::where( $where, 'id', 'ASC' );
	}

	/**
	 * Quizzes attached to one section, published-only by default.
	 *
	 * The curriculum outline renders each section's quizzes under that
	 * section; without a status guard it listed drafts to students and
	 * logged-out visitors while draft lessons were correctly hidden
	 * (Basecamp 10135041249). Ordered by `created_at` to match the order the
	 * curriculum has always rendered them in.
	 *
	 * @param int         $section_id Section.
	 * @param string|null $status     Status to filter on; null for every status.
	 * @return object[] Quiz rows in creation order.
	 */
	public static function get_for_section( int $section_id, ?string $status = self::STATUS_PUBLISHED ): array {
		$where = [ 'section_id' => $section_id ];
		if ( null !== $status ) {
			$where['status'] = $status;
		}

		return static::where( $where, 'created_at', 'ASC' );
	}

	/**
	 * Batch version of {@see get_for_section()} — every section's quizzes in ONE
	 * query, returned as a `section_id => [quizzes]` map.
	 *
	 * The curriculum outline called get_for_section() from inside its sections
	 * loop, so a 30-section course paid 30 queries just to find its quizzes.
	 * Mirrors Lesson::get_for_sections() deliberately: same argument order, same
	 * map shape, sections with no quizzes absent (caller defaults []), and the
	 * same `created_at ASC` order get_for_section() has always returned, so the
	 * rendered order does not move.
	 *
	 * @param int[]       $section_ids
	 * @param string|null $status Status to filter on; null for every status.
	 * @return array<int,object[]>
	 */
	public static function get_for_sections( array $section_ids, ?string $status = self::STATUS_PUBLISHED ): array {
		$section_ids = array_values( array_unique( array_filter( array_map( 'intval', $section_ids ) ) ) );
		if ( ! $section_ids ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $section_ids ), '%d' ) );
		$status_sql   = null === $status ? '' : ' AND status = %s';
		$values       = $section_ids;
		if ( null !== $status ) {
			$values[] = $status;
		}

		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT * FROM ' . static::table() . " WHERE section_id IN ({$placeholders}){$status_sql} ORDER BY section_id ASC, created_at ASC, id ASC",
				...$values
			)
		) ?: [];

		$map = [];
		foreach ( $rows as $row ) {
			$map[ (int) $row->section_id ][] = $row;
		}

		return $map;
	}

	/**
	 * Split one section's quizzes into the ones bound to a lesson and the ones
	 * that belong at the end of the section.
	 *
	 * `lesson_id` is the author's placement decision, not a loose back-reference:
	 * the quiz editor's Lesson field offers "End of section" as its empty option,
	 * so leaving it blank means "module quiz" and picking a lesson means "this
	 * lesson's quiz". Every outline surface has to read it the same way, or the
	 * same quiz appears in a different place depending on which screen the
	 * student is looking at — the course page listed it at the section end while
	 * the player showed it inside the lesson (Basecamp 10181082400).
	 *
	 * A quiz whose lesson is not among the rendered lessons falls back to the
	 * section end rather than disappearing. That happens legitimately: the lesson
	 * is still a draft (students never receive it), or it was moved to another
	 * section and the quiz was not moved with it. Dropping the row instead would
	 * hide published, gradeable content with no trace of it anywhere.
	 *
	 * @param object[] $quizzes    Quiz rows for a single section.
	 * @param int[]    $lesson_ids Lesson ids actually being rendered in that section.
	 * @return array{by_lesson: array<int,object[]>, section_end: object[]}
	 */
	public static function split_by_lesson( array $quizzes, array $lesson_ids ): array {
		$rendered = array_flip( array_map( 'intval', $lesson_ids ) );

		$by_lesson   = [];
		$section_end = [];

		foreach ( $quizzes as $quiz ) {
			$lesson_id = (int) ( $quiz->lesson_id ?? 0 );

			if ( $lesson_id > 0 && isset( $rendered[ $lesson_id ] ) ) {
				$by_lesson[ $lesson_id ][] = $quiz;
				continue;
			}

			$section_end[] = $quiz;
		}

		return [
			'by_lesson'   => $by_lesson,
			'section_end' => $section_end,
		];
	}

	/**
	 * The attempt cap that actually applies to a quiz, given its mode.
	 *
	 * A PRACTICE quiz is for unlimited repetition — a student is meant to retake
	 * it as often as they like — so its stored `attempt_limit` (which every quiz
	 * carries a default of 1) does NOT apply. Only graded and survey quizzes
	 * enforce the cap. Returns 0 for "no limit", the codebase-wide sentinel the
	 * start gate, the start screen, and the submit response all treat as unlimited
	 * — so routing every one of them through this one helper keeps them from ever
	 * disagreeing about whether a retake is allowed (Basecamp 10096673115).
	 *
	 * @param object $quiz Quiz row (needs `mode` + `attempt_limit`).
	 * @return int Effective cap; 0 = unlimited.
	 */
	public static function effective_attempt_limit( object $quiz ): int {
		if ( 'practice' === (string) ( $quiz->mode ?? 'graded' ) ) {
			return 0;
		}
		return (int) ( $quiz->attempt_limit ?? 0 );
	}

	/** User meta holding a student's per-quiz extra-attempt grants (quiz_id => extra). */
	private const ATTEMPT_GRANTS_META = 'learnomy_quiz_attempt_grants';

	/**
	 * The attempt cap that applies to ONE student on this quiz.
	 *
	 * {@see effective_attempt_limit()} is the quiz's own rule; this is that rule
	 * plus whatever has been granted to this student, and it is what every gate
	 * and every "attempts remaining" readout must use. Unlimited (0) stays
	 * unlimited — there is nothing to extend.
	 *
	 * Until this existed the only way to let one student retake a capped quiz was
	 * the course-wide "Reset progress" button, which deletes every attempt AND
	 * all lesson progress for the whole course — unblocking one quiz cost the
	 * student their completion (Basecamp 10134140122).
	 *
	 * @param object $quiz    Quiz row (needs `mode` + `attempt_limit`).
	 * @param int    $user_id Student the cap applies to.
	 * @return int Effective cap for this student; 0 = unlimited.
	 */
	public static function effective_attempt_limit_for_user( object $quiz, int $user_id ): int {
		$limit = static::effective_attempt_limit( $quiz );

		if ( $limit > 0 ) {
			$limit += static::granted_attempts( $user_id, (int) $quiz->id );
		}

		/**
		 * Filter the number of attempts a student may take on a quiz.
		 *
		 * The seam for extending the cap by membership tier, cohort, or any other
		 * rule Free does not model — Pro's per-plan quiz allowances hook here.
		 * Return 0 for unlimited. Runs after per-user grants are added, so a
		 * subscriber sees (and can override) the granted total.
		 *
		 * @since 1.8.1
		 *
		 * @param int    $limit   Attempt cap; 0 = unlimited.
		 * @param object $quiz    Quiz row.
		 * @param int    $user_id Student the cap applies to.
		 */
		return max( 0, (int) apply_filters( 'learnomy_quiz_max_attempts', $limit, $quiz, $user_id ) );
	}

	/** Extra attempts granted to one student on one quiz (0 when none). */
	public static function granted_attempts( int $user_id, int $quiz_id ): int {
		$grants = get_user_meta( $user_id, self::ATTEMPT_GRANTS_META, true );

		return is_array( $grants ) ? max( 0, (int) ( $grants[ $quiz_id ] ?? 0 ) ) : 0;
	}

	/**
	 * Add to (or subtract from) a student's extra attempts on one quiz.
	 *
	 * Stored as a quiz_id => extra map in one user-meta row, so granting an
	 * attempt costs the student no progress and no other quiz is touched. The
	 * quiz key is dropped once the balance reaches zero, so absence always means
	 * "no grant" and the row cannot accumulate dead entries.
	 *
	 * @param int $user_id Student to grant to.
	 * @param int $quiz_id Quiz the grant applies to.
	 * @param int $delta   Attempts to add; negative removes.
	 * @return int The student's new grant total for this quiz.
	 */
	public static function grant_attempts( int $user_id, int $quiz_id, int $delta ): int {
		$grants = get_user_meta( $user_id, self::ATTEMPT_GRANTS_META, true );
		$grants = is_array( $grants ) ? $grants : [];

		$total = max( 0, (int) ( $grants[ $quiz_id ] ?? 0 ) + $delta );

		if ( $total > 0 ) {
			$grants[ $quiz_id ] = $total;
		} else {
			unset( $grants[ $quiz_id ] );
		}

		if ( empty( $grants ) ) {
			delete_user_meta( $user_id, self::ATTEMPT_GRANTS_META );
		} else {
			update_user_meta( $user_id, self::ATTEMPT_GRANTS_META, $grants );
		}

		return $total;
	}

	/**
	 * First published quiz attached to a lesson via `lrn_quizzes.lesson_id`,
	 * or null when the lesson has no quiz. A lesson is expected to carry at
	 * most one attached quiz; the LIMIT 1 + id ASC pick is deterministic in
	 * the rare data-corruption case where two rows match.
	 *
	 * Used by Single_Lesson_View_Data to expose the "Take Quiz" CTA on the
	 * lesson frontend without a second template-side DB hit.
	 */
	public static function get_for_lesson( int $lesson_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE lesson_id = %d AND status = 'published' ORDER BY id ASC LIMIT 1",
				$lesson_id
			)
		) ?: null;
	}

	/**
	 * Hydrate a SET of quizzes in one query — the batch form of {@see find()}.
	 *
	 * Exists for the grading-method pass predicate, which is asked about a whole
	 * category's or course's quizzes at once and must not issue a `find()` per
	 * quiz to read three columns.
	 *
	 * @param int[] $ids Quiz ids.
	 * @return object[] Quiz rows in id order; missing ids are simply absent.
	 */
	public static function find_many( array $ids ): array {
		$clean = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $clean ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $clean ), '%d' ) );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + a %d list built from the id count.
				'SELECT * FROM ' . static::table() . " WHERE id IN ({$placeholders}) ORDER BY id ASC",
				...$clean
			)
		) ?: [];
	}

	/**
	 * Every published quiz attached to a LESSON of this course, in one query.
	 *
	 * Some courses keep the final exam at lesson scope rather than course scope,
	 * so the course-completion gate has to look at both. It used to find the
	 * lesson-scoped ones by calling {@see get_for_lesson()} once per lesson — an
	 * N+1 that ran on every lesson completion and, once the course page started
	 * asking the same question, on every course-page view for a student who has
	 * finished the curriculum. A 100-lesson course meant 100 queries to answer
	 * "is there a quiz here". Joining through `lrn_lessons` on `course_id`
	 * answers it once.
	 *
	 * @param int $course_id Course whose lessons to scan.
	 * @return object[] Published quiz rows, lesson order then id order.
	 */
	public static function get_lesson_attached_for_course( int $course_id ): array {
		$lessons_table = \Learnomy\table( 'lessons' );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table names, no user input.
				'SELECT q.* FROM ' . static::table() . " q
				 INNER JOIN {$lessons_table} l ON l.id = q.lesson_id
				 WHERE l.course_id = %d AND q.status = 'published'
				 ORDER BY l.sort_order ASC, q.id ASC",
				$course_id
			)
		) ?: [];
	}

	/**
	 * Quizzes not attached to any course — used in question banks and practice
	 * sets. Capped (default 50) so a site with thousands of standalone quizzes
	 * never loads the whole set into memory. The course_id index keeps the scan
	 * bound; {@see count_standalone()} reports the true total for paging.
	 *
	 * @param int $limit  Max rows (default 50; 0 = all).
	 * @param int $offset Rows to skip when paginating.
	 * @return object[]
	 */
	public static function get_standalone( int $limit = 50, int $offset = 0 ): array {
		// NULL or 0 both mean "not attached" — see find_standalone_by_slug().
		$sql = 'SELECT * FROM ' . static::table() . ' WHERE course_id IS NULL OR course_id = 0 ORDER BY title ASC';

		if ( $limit > 0 ) {
			$sql = static::db()->prepare( $sql . ' LIMIT %d OFFSET %d', $limit, max( 0, $offset ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return static::db()->get_results( $sql ) ?: [];
	}

	/** Total standalone quizzes. Companion to {@see get_standalone()} for paging. */
	public static function count_standalone(): int {
		return (int) static::db()->get_var(
			'SELECT COUNT(*) FROM ' . static::table() . ' WHERE course_id IS NULL OR course_id = 0'
		);
	}

	/**
	 * Paginated quiz list with an optional tag filter. Base {@see Model::where()}
	 * can't express the lrn_tag_map JOIN the Quizzes-list Tag filter needs, so
	 * the admin list uses this (and {@see count_filtered()} for the total) when
	 * a tag is selected, falling back to plain where() otherwise.
	 *
	 * @param array<string,mixed>                   $where
	 * @param array{0:string,1:array<int,int>}|null $scope Instructor scope from
	 *        {@see \Learnomy\Permissions\Permission_Engine::instructor_scope_sql()};
	 *        null means no restriction. Never hand-roll it.
	 * @return object[]
	 */
	public static function list_filtered( array $where = [], string $orderby = 'created_at', string $order = 'DESC', int $limit = 20, int $offset = 0, int $tag_id = 0, ?array $scope = null ): array {
		global $wpdb;

		[ $join_sql, $conditions, $values ] = self::build_filter_sql( $where, $tag_id, $scope );
		$where_sql                          = ! empty( $conditions ) ? ' WHERE ' . implode( ' AND ', $conditions ) : '';

		$safe_orderby = (string) esc_sql( $orderby );
		$safe_order   = in_array( strtoupper( $order ), [ 'ASC', 'DESC' ], true ) ? strtoupper( $order ) : 'DESC';
		$limit        = max( 1, $limit );
		$offset       = max( 0, $offset );

		$sql = 'SELECT q.* FROM ' . static::table() . " q{$join_sql}{$where_sql} ORDER BY q.`{$safe_orderby}` {$safe_order} LIMIT %d OFFSET %d";

		$values[] = $limit;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are prefix-composed; values bound via prepare().
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );

		return $rows ?: [];
	}

	/**
	 * COUNT for the same filter set as {@see list_filtered()}, including the
	 * optional tag JOIN, so the paginated total stays accurate when filtering.
	 *
	 * @param array<string,mixed>                   $where
	 * @param array{0:string,1:array<int,int>}|null $scope See {@see list_filtered()}.
	 */
	public static function count_filtered( array $where = [], int $tag_id = 0, ?array $scope = null ): int {
		global $wpdb;

		[ $join_sql, $conditions, $values ] = self::build_filter_sql( $where, $tag_id, $scope );
		$where_sql                          = ! empty( $conditions ) ? ' WHERE ' . implode( ' AND ', $conditions ) : '';

		$sql = 'SELECT COUNT(DISTINCT q.id) FROM ' . static::table() . " q{$join_sql}{$where_sql}";

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = $wpdb->prepare( $sql, ...$values );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Compose the JOIN + WHERE fragments shared by the list + count queries.
	 * A tag filter INNER JOINs lrn_tag_map on (object_id, object_type='quiz',
	 * tag_id) — index-friendly via the map's (tag_id, object_type) key.
	 *
	 * @param array<string,mixed>                   $where
	 * @param array{0:string,1:array<int,int>}|null $scope See {@see list_filtered()}.
	 * @return array{0:string,1:string[],2:array<int,mixed>} [join_sql, conditions, values]
	 */
	private static function build_filter_sql( array $where, int $tag_id, ?array $scope = null ): array {
		global $wpdb;

		$conditions = [];
		$values     = [];
		$join_sql   = '';

		// JOIN value binds first because the JOIN clause precedes WHERE.
		if ( $tag_id > 0 ) {
			$map      = $wpdb->prefix . 'lrn_tag_map';
			$join_sql = " INNER JOIN {$map} tm ON tm.object_id = q.id AND tm.object_type = 'quiz' AND tm.tag_id = %d";
			$values[] = $tag_id;
		}

		foreach ( $where as $col => $val ) {
			$safe_col = (string) esc_sql( (string) $col );
			if ( is_int( $val ) ) {
				$conditions[] = "q.`{$safe_col}` = %d";
			} else {
				$conditions[] = "q.`{$safe_col}` = %s";
			}
			$values[] = $val;
		}

		// Instructor scope last, so its OR group is ANDed onto the filters
		// rather than widening them. `null` is no restriction (administrators)
		// and is NOT the same as an empty course list, which the builder
		// already expressed as "authored by me only".
		if ( is_array( $scope ) && isset( $scope[0], $scope[1] ) ) {
			$conditions[] = (string) $scope[0];
			$values       = array_merge( $values, array_values( (array) $scope[1] ) );
		}

		return [ $join_sql, $conditions, $values ];
	}
}
