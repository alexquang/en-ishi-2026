<?php
/**
 * Tutor LMS migration source (read-only adapter).
 *
 * Reads Tutor LMS (CPTs courses/topics/lesson/tutor_quiz + the
 * wp_tutor_quiz_questions / wp_tutor_quiz_question_answers tables) and returns
 * normalized payloads. $wpdb is used to READ the foreign Tutor tables (no
 * Learnomy model exists for them); it never writes. Money/commerce fields
 * (price, product, monetization) are never read.
 *
 * Tutor's content model maps to Learnomy as:
 *   course (CPT courses) -> course
 *   topic  (CPT topics, post_parent=course) -> section
 *   lesson (CPT lesson, post_parent=topic; orphan fallback meta) -> lesson
 *   tutor_quiz (CPT, post_parent=topic) -> quiz (course = topic's parent)
 *   wp_tutor_quiz_questions row -> question (+ answers from the answers table)
 * Tutor has no sub-lesson concept, so this source offers no `sub_lesson`.
 *
 * Verified against Tutor LMS 3.9.12 + LRNFIX fixtures (see the build spec).
 *
 * @package Learnomy
 */

namespace Learnomy\Migration\Sources;

use Learnomy\Migration\Migration_Source;

defined( 'ABSPATH' ) || exit;

/**
 * Tutor LMS read-only migration source.
 */
class Tutor_Source implements Migration_Source {

	/** Tutor question_type -> Learnomy question type ('' = no clean target). */
	private const TYPE_MAP = array(
		'true_false'        => 'true_false',
		'single_choice'     => 'mc_single',
		'multiple_choice'   => 'mc_multi',
		'short_answer'      => 'short_answer',
		'open_ended'        => 'essay',
		'fill_in_the_blank' => 'fill_blank',
		'ordering'          => 'ordering',
		'matching'          => 'matching',
		'image_matching'    => '',  // Falls to the run fallback map (-> matching, image dropped).
		'image_answering'   => '',  // Falls to the run fallback map (-> short_answer).
		'h5p'               => '',  // No graded target -> skipped.
	);

	/** Tutor course text-block meta -> the section heading folded into the course content. */
	private const TEXT_BLOCKS = array(
		'_tutor_course_benefits'          => 'What you will learn',
		'_tutor_course_requirements'      => 'Requirements',
		'_tutor_course_target_audience'   => 'Target audience',
		'_tutor_course_material_includes' => 'Materials included',
	);

	/**
	 * Machine slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'tutor';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Tutor LMS';
	}

	/**
	 * True when Tutor LMS is present AND has at least one course.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return ( function_exists( 'tutor' ) || post_type_exists( 'courses' ) ) && $this->count( 'course' ) > 0;
	}

	/**
	 * Entities this source can provide.
	 *
	 * @return string[]
	 */
	public function supported_entities(): array {
		return array( 'category', 'course', 'section', 'lesson', 'quiz', 'question', 'enrollment', 'progress' );
	}

	/**
	 * Per-type taxonomies this source exposes (Tutor categorises courses only).
	 *
	 * @return string[]
	 */
	public function supported_taxonomies(): array {
		return array( 'course_category', 'course_tag' );
	}

	// ── Counts ────────────────────────────────────────────────────────────

	/**
	 * Total source rows for an entity.
	 *
	 * @param string $entity Entity key.
	 * @return int
	 */
	public function count( string $entity ): int {
		global $wpdb;
		switch ( $entity ) {
			case 'course':
				// Reason: read-only COUNT against the foreign Tutor posts table; status list is a hardcoded whitelist, no user input.
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='courses' AND post_status IN ('publish','draft','private','pending')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			case 'section':
				// Reason: read-only COUNT against the foreign Tutor posts table; no user input.
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='topics'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			case 'lesson':
				// Reason: read-only COUNT against the foreign Tutor posts table; no user input.
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='lesson' AND post_status IN ('publish','draft')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			case 'quiz':
				// Reason: read-only COUNT against the foreign Tutor posts table; no user input.
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tutor_quiz' AND post_status IN ('publish','draft')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			case 'question':
				// Reason: read-only COUNT against the foreign Tutor questions table; no user input.
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tutor_quiz_questions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			case 'category':
				return (int) wp_count_terms(
					array(
						'taxonomy'   => array( 'course-category' ),
						'hide_empty' => false,
					)
				);
			case 'enrollment':
				// tutor_enrolled posts: one per active (status=completed) enrollment.
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tutor_enrolled' AND post_status='completed'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			case 'progress':
				// Completed-lesson usermeta: _tutor_completed_lesson_id_<lessonId>.
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_tutor_completed_lesson_id\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
		}
		return 0;
	}

	// ── Read (paginated) ──────────────────────────────────────────────────

	/**
	 * Read a page of raw source rows (post IDs, term objects, or question rows).
	 *
	 * @param string $entity Entity key.
	 * @param int    $limit  Page size.
	 * @param int    $offset Page offset.
	 * @return array<int,mixed>
	 */
	public function read( string $entity, int $limit, int $offset ): array {
		global $wpdb;

		if ( 'category' === $entity ) {
			$terms = get_terms(
				array(
					'taxonomy'   => array( 'course-category' ),
					'hide_empty' => false,
					'number'     => $limit,
					'offset'     => $offset,
					'orderby'    => 'term_id',
				)
			);
			return is_array( $terms ) ? $terms : array();
		}

		if ( 'question' === $entity ) {
			// Reason: reads the foreign Tutor questions table read-only; limit/offset are prepared.
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tutor_quiz_questions ORDER BY question_id ASC LIMIT %d OFFSET %d", $limit, $offset )
			);
			return is_array( $rows ) ? $rows : array();
		}

		if ( 'enrollment' === $entity ) {
			// tutor_enrolled: post_author = user, post_parent = course.
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT ID, post_author, post_parent, post_date FROM {$wpdb->posts} WHERE post_type='tutor_enrolled' AND post_status='completed' ORDER BY ID ASC LIMIT %d OFFSET %d", $limit, $offset )
			);
			return is_array( $rows ) ? $rows : array();
		}

		if ( 'progress' === $entity ) {
			// _tutor_completed_lesson_id_<lessonId> usermeta, one per completed lesson.
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT user_id, meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE '\\_tutor_completed_lesson_id\\_%' ORDER BY umeta_id ASC LIMIT %d OFFSET %d", $limit, $offset ) // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- static meta_key prefix, no user input.
			);
			return is_array( $rows ) ? $rows : array();
		}

		$pt = array(
			'course'  => 'courses',
			'section' => 'topics',
			'lesson'  => 'lesson',
			'quiz'    => 'tutor_quiz',
		);
		if ( ! isset( $pt[ $entity ] ) ) {
			return array();
		}
		$status = 'course' === $entity ? "('publish','draft','private','pending')" : "('publish','draft')";
		// Reason: reads the foreign Tutor posts table read-only; $status is a hardcoded status whitelist (no user input), post_type/limit/offset are prepared.
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN {$status} ORDER BY ID ASC LIMIT %d OFFSET %d",
				$pt[ $entity ],
				$limit,
				$offset
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	// ── Map ─────────────────────────────────────────────────────────────────

	/**
	 * Map ONE raw row to the normalized payload for $entity.
	 *
	 * @param string $entity  Entity key.
	 * @param mixed  $row     Raw source row (post ID, term object, or question row).
	 * @param array  $options The run's selection/mapping spec.
	 * @return array|null
	 */
	public function map( string $entity, $row, array $options = array() ) {
		switch ( $entity ) {
			case 'category':
				return $this->map_category( $row );
			case 'course':
				return $this->map_course( (int) $row );
			case 'section':
				return $this->map_section( (int) $row );
			case 'lesson':
				return $this->map_lesson( (int) $row );
			case 'quiz':
				return $this->map_quiz( (int) $row );
			case 'question':
				return $this->map_question( $row, $options );
			case 'enrollment':
				return $this->map_enrollment( $row );
			case 'progress':
				return $this->map_progress( $row );
		}
		return null;
	}

	/**
	 * Map a tutor_enrolled post to a normalized enrollment (author=user,
	 * parent=course). Tutor has no separate course-completion timestamp on the
	 * enrolled post, so completion rides in via the progress tier.
	 *
	 * @param mixed $row Row with ->post_author, ->post_parent, ->post_date.
	 * @return array|null
	 */
	private function map_enrollment( $row ): ?array {
		if ( ! is_object( $row ) ) {
			return null;
		}
		$user_id   = (int) $row->post_author;
		$course_id = (int) $row->post_parent;
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return null;
		}
		return array(
			'ext_id'        => $user_id . ':' . $course_id,
			'user_id'       => $user_id,
			'course_ext_id' => $course_id,
			'enrolled_at'   => (string) ( $row->post_date ?? '' ),
		);
	}

	/**
	 * Map a Tutor completed-lesson usermeta row to a normalized progress row.
	 * meta_key is _tutor_completed_lesson_id_<lessonId>.
	 *
	 * @param mixed $row Row with ->user_id, ->meta_key.
	 * @return array|null
	 */
	private function map_progress( $row ): ?array {
		if ( ! is_object( $row ) ) {
			return null;
		}
		if ( ! preg_match( '/_tutor_completed_lesson_id_(\d+)$/', (string) $row->meta_key, $m ) ) {
			return null;
		}
		$user_id   = (int) $row->user_id;
		$lesson_id = (int) $m[1];
		if ( $user_id <= 0 || $lesson_id <= 0 ) {
			return null;
		}
		return array(
			'ext_id'        => $user_id . ':' . $lesson_id,
			'user_id'       => $user_id,
			'lesson_ext_id' => $lesson_id,
		);
	}

	/**
	 * Map a Tutor course category term to a normalized payload.
	 *
	 * @param mixed $term Term object.
	 * @return array|null
	 */
	private function map_category( $term ): ?array {
		if ( ! is_object( $term ) ) {
			return null;
		}
		return array(
			'ext_id'        => (int) $term->term_id,
			'taxonomy'      => (string) $term->taxonomy,
			'name'          => (string) $term->name,
			'slug'          => (string) $term->slug,
			'parent_ext_id' => (int) $term->parent ? (int) $term->parent : null,
		);
	}

	/**
	 * Map a Tutor course post to a normalized payload. The Tutor "benefits /
	 * requirements / target audience / materials" meta blocks are folded into
	 * the course content as titled sections so nothing is dropped (decision #3).
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	private function map_course( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$cat_terms = wp_get_post_terms( $id, 'course-category', array( 'fields' => 'all' ) );
		$cat_ids   = is_wp_error( $cat_terms ) ? array() : wp_list_pluck( $cat_terms, 'term_id' );
		$extra     = array();
		foreach ( array_slice( is_wp_error( $cat_terms ) ? array() : (array) $cat_terms, 1 ) as $t ) {
			$extra[] = $t->name;
		}
		$tag_terms = wp_get_post_terms( $id, 'course-tag', array( 'fields' => 'names' ) );

		$text_blocks = array();
		foreach ( self::TEXT_BLOCKS as $meta_key => $heading ) {
			$raw = (string) get_post_meta( $id, $meta_key, true );
			if ( '' !== trim( $raw ) ) {
				$text_blocks[ $heading ] = preg_split( '/\r\n|\r|\n/', $raw );
			}
		}

		// Tutor (Pro) stores course prerequisites as an array of course IDs.
		$pre            = get_post_meta( $id, '_tutor_course_prerequisites_ids', true );
		$prereq_ext_ids = is_array( $pre ) ? array_values( array_filter( array_map( 'intval', $pre ) ) ) : array();

		// Co-instructors: Tutor tags each assigned instructor's user with
		// _tutor_instructor_course_id = the course id (reverse lookup). Carry all
		// but the primary author.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$instructor_ids    = (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_tutor_instructor_course_id' AND meta_value = %d", $id ) );
		$co_instructor_ids = array_values( array_filter( array_map( 'intval', $instructor_ids ), static fn( $uid ) => $uid > 0 && $uid !== (int) $post->post_author ) );

		return array(
			'ext_id'                  => $id,
			'title'                   => $post->post_title,
			'slug'                    => $post->post_name,
			'description'             => $post->post_excerpt,
			'content'                 => $post->post_content,
			'status'                  => $post->post_status,
			'author_id'               => (int) $post->post_author,
			'thumbnail_url'           => (string) get_the_post_thumbnail_url( $id, 'full' ),
			'difficulty'              => (string) get_post_meta( $id, '_tutor_course_level', true ),
			'category_ext_ids'        => array_map( 'intval', (array) $cat_ids ),
			'primary_category_ext_id' => $cat_ids[0] ?? null,
			'extra_category_names'    => $extra,
			'tag_names'               => is_wp_error( $tag_terms ) ? array() : (array) $tag_terms,
			'text_blocks'             => $text_blocks,
			'prerequisite_ext_ids'    => $prereq_ext_ids,
			'co_instructor_ids'       => $co_instructor_ids,
			'settings'                => array(),
		);
	}

	/**
	 * Map a Tutor topic post (Learnomy section) to a normalized payload.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	private function map_section( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		return array(
			'ext_id'        => $id,
			'course_ext_id' => (int) $post->post_parent,
			'title'         => $post->post_title,
			'description'   => $post->post_content,
			'sort_order'    => (int) $post->menu_order,
		);
	}

	/**
	 * Map a Tutor lesson post to a normalized payload. A lesson is normally a
	 * child of a topic (post_parent), giving both its section and (via the
	 * topic's parent) its course; legacy/orphan lessons fall back to the
	 * `_tutor_course_id_for_lesson` meta with no section.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	private function map_lesson( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$section_ext_id = null;
		$course_ext_id  = 0;
		$parent         = (int) $post->post_parent;
		if ( $parent && 'topics' === get_post_type( $parent ) ) {
			$section_ext_id = $parent;
			$course_ext_id  = (int) ( get_post( $parent )->post_parent ?? 0 );
		} else {
			$course_ext_id = (int) get_post_meta( $id, '_tutor_course_id_for_lesson', true );
		}

		$video = $this->extract_video_url( $id );

		return array(
			'ext_id'         => $id,
			'course_ext_id'  => $course_ext_id,
			'section_ext_id' => $section_ext_id,
			'title'          => $post->post_title,
			'content_html'   => $post->post_content,
			'content_plain'  => wp_strip_all_tags( $post->post_content ),
			'content_type'   => $video ? 'video' : 'text',
			'video_url'      => $video,
			// Tutor marks a lesson as a course preview via `_is_preview` — mirror it
			// to lrn_lessons.is_preview so a preview lesson stays previewable.
			'is_preview'     => ( '1' === (string) get_post_meta( $id, '_is_preview', true ) ) ? 1 : 0,
			'sort_order'     => (int) $post->menu_order,
			'status'         => $post->post_status,
			'author_id'      => (int) $post->post_author,
			'tag_names'      => array(),
		);
	}

	/**
	 * Map a Tutor quiz post to a normalized payload. A quiz hangs off a topic,
	 * so its course is the topic's parent. Tutor attaches quizzes to topics,
	 * not lessons, so there is no lesson link.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	private function map_quiz( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$topic_id  = (int) $post->post_parent;
		$course_id = $topic_id ? (int) ( get_post( $topic_id )->post_parent ?? 0 ) : 0;

		// get_post_meta( …, true ) already returns the unserialized array for
		// tutor_quiz_option — DON'T cast to string first (that yields the literal
		// "Array", fails maybe_unserialize, and silently drops every quiz setting
		// to its default: passing 70, attempts/time/shuffle 0). Only unserialize a
		// raw serialized string, if one ever comes back instead of an array.
		$opt = get_post_meta( $id, 'tutor_quiz_option', true );
		if ( ! is_array( $opt ) ) {
			$opt = maybe_unserialize( (string) $opt );
		}
		$opt          = is_array( $opt ) ? $opt : array();
		$passing      = isset( $opt['passing_grade'] ) ? (int) $opt['passing_grade'] : 70;
		$attempts     = isset( $opt['attempts_allowed'] ) ? (int) $opt['attempts_allowed'] : 0;
		$shuffle      = ( ( $opt['questions_order'] ?? '' ) === 'rand' ) ? 1 : 0;
		$time_seconds = $this->quiz_time_seconds( $opt );

		return array(
			'ext_id'             => $id,
			'course_ext_id'      => $course_id,
			'lesson_ext_id'      => null,
			'title'              => $post->post_title,
			'mode'               => 'graded',
			'passing_score'      => $passing,
			'time_limit_seconds' => $time_seconds,
			'attempt_limit'      => $attempts,
			'shuffle_questions'  => $shuffle,
			'status'             => $post->post_status,
			'author_id'          => (int) $post->post_author,
			'tag_names'          => array(),
		);
	}

	/**
	 * Map a Tutor question row (from wp_tutor_quiz_questions) to a payload.
	 *
	 * @param mixed $row     Question row object.
	 * @param array $options The run's selection/mapping spec.
	 * @return array|null
	 */
	private function map_question( $row, array $options ): ?array {
		if ( ! is_object( $row ) ) {
			return null;
		}
		$orig_type = (string) $row->question_type;
		$type      = $this->resolve_type( $orig_type, $options );

		$base = array(
			'ext_id'      => (int) $row->question_id,
			'quiz_ext_id' => (int) $row->quiz_id,
			'orig_type'   => $orig_type,
			'title'       => (string) $row->question_title,
			'content'     => '' !== trim( (string) $row->question_description ) ? (string) $row->question_description : (string) $row->question_title,
			'explanation' => (string) $row->answer_explanation,
			'marks'       => (float) $row->question_mark,
			'sort_order'  => (int) $row->question_order,
		);

		if ( '' === $type ) {
			$base['skip'] = true;
			return $base;
		}
		$base['type'] = $type;
		// Surface lossy downgrades in the dry-run: a source type with no exact
		// Learnomy equivalent (not in TYPE_MAP) that the run's fallback map
		// rerouted to a different type (e.g. image_matching -> matching).
		if ( '' === ( self::TYPE_MAP[ $orig_type ] ?? '' ) ) {
			$base['note'] = sprintf( 'Question type "%s" has no exact Learnomy equivalent; imported as "%s".', $orig_type, $type );
		}
		$base['answers'] = $this->encode_answers( $type, $this->read_answers( (int) $row->question_id, $orig_type ) );

		return $base;
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Apply the run's fallback map, then the default TYPE_MAP.
	 *
	 * @param string $orig    Source question type.
	 * @param array  $options The run's selection/mapping spec.
	 * @return string Learnomy type, or '' to skip.
	 */
	private function resolve_type( string $orig, array $options ): string {
		$fallbacks = (array) ( $options['question_type_fallbacks'] ?? array() );
		if ( isset( $fallbacks[ $orig ] ) ) {
			$f = (string) $fallbacks[ $orig ];
			return 'skip' === $f ? '' : $f;
		}
		return self::TYPE_MAP[ $orig ] ?? '';
	}

	/**
	 * Read the answer rows for one question, filtered by BOTH the question id
	 * and its type (Tutor's answers table keys on belongs_question_id +
	 * belongs_question_type), ordered by answer_order.
	 *
	 * @param int    $question_id Tutor question id.
	 * @param string $type        Tutor question_type.
	 * @return array<int,object>
	 */
	private function read_answers( int $question_id, string $type ): array {
		global $wpdb;
		// Reason: reads the foreign Tutor answers table read-only; both filters are prepared.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}tutor_quiz_question_answers WHERE belongs_question_id=%d AND belongs_question_type=%s ORDER BY answer_order ASC",
				$question_id,
				$type
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Encode Tutor answer rows into Learnomy answer rows per target type.
	 *
	 * @param string $type    Learnomy question type.
	 * @param array  $rows    Tutor answer rows.
	 * @return array
	 */
	private function encode_answers( string $type, array $rows ): array {
		$answers = array();
		switch ( $type ) {
			case 'mc_single':
			case 'mc_multi':
			case 'true_false':
				$i = 1;
				foreach ( $rows as $r ) {
					$answers[] = array(
						'content'    => (string) $r->answer_title,
						'is_correct' => ! empty( $r->is_correct ) ? 1 : 0,
						'sort_order' => (int) $r->answer_order ? (int) $r->answer_order : $i,
					);
					++$i;
				}
				break;

			case 'short_answer':
				// Real Tutor short_answer stores no rows (manual). image_answering
				// downgraded here DOES carry an accepted answer in answer_title.
				$i = 1;
				foreach ( $rows as $r ) {
					$acc = trim( (string) $r->answer_title );
					if ( '' !== $acc ) {
						$answers[] = array(
							'content'    => $acc,
							'is_correct' => 1,
							'sort_order' => $i++,
						);
					}
				}
				break;

			case 'fill_blank':
				// Tutor stores the template (with {dash} markers) in answer_title
				// and the per-blank accepted values, in order, pipe-joined in
				// answer_two_gap_match. One accepted value per blank.
				$r     = $rows[0] ?? null;
				$gaps  = $r ? array_map( 'trim', explode( '|', (string) $r->answer_two_gap_match ) ) : array();
				$blank = 1;
				foreach ( $gaps as $gap ) {
					if ( '' !== $gap ) {
						$answers[] = array(
							'content'    => $gap,
							'is_correct' => 1,
							'group_id'   => 'blank_' . $blank,
							'sort_order' => 1,
						);
					}
					++$blank;
				}
				break;

			case 'ordering':
				// Correct order = answer_order ASC (already ordered by the query).
				$i = 1;
				foreach ( $rows as $r ) {
					$answers[] = array(
						'content'    => (string) $r->answer_title,
						'sort_order' => $i++,
					);
				}
				break;

			case 'matching':
				// Each Tutor row is one pair: answer_title=prompt,
				// answer_two_gap_match=match. (image_matching downgrades here too;
				// the image is dropped, the text label survives in two_gap_match.)
				$i = 1;
				foreach ( $rows as $r ) {
					$answers[] = array(
						'content'    => (string) $r->answer_title,
						'group_id'   => 'prompt',
						'sort_order' => $i,
					);
					$answers[] = array(
						'content'    => (string) $r->answer_two_gap_match,
						'group_id'   => 'match',
						'sort_order' => $i,
					);
					++$i;
				}
				break;

			case 'essay':
			default:
				// No answer rows.
				break;
		}
		return $answers;
	}

	/**
	 * Extract a single playable URL from Tutor's serialized `_video` meta.
	 * Tutor stores `source` plus a matching `source_<type>` value; for hosted
	 * media (html5) that value is an attachment id resolved to its URL.
	 *
	 * @param int $post_id Lesson post id.
	 * @return string URL, or '' when none/unsupported.
	 */
	private function extract_video_url( int $post_id ): string {
		// Same array-meta rule as tutor_quiz_option: get_post_meta returns the
		// unserialized `_video` array already; a (string) cast would make it
		// "Array" and silently drop every lesson's video URL.
		$video = get_post_meta( $post_id, '_video', true );
		if ( ! is_array( $video ) ) {
			$video = maybe_unserialize( (string) $video );
		}
		if ( ! is_array( $video ) ) {
			return '';
		}
		$source = (string) ( $video['source'] ?? '' );
		if ( '' === $source || '-1' === $source ) {
			return '';
		}
		$value = (string) ( $video[ 'source_' . $source ] ?? '' );
		if ( '' === $value ) {
			return '';
		}
		// Hosted upload stores an attachment id; everything else is already a URL.
		if ( in_array( $source, array( 'html5', 'wp_media' ), true ) && ctype_digit( $value ) ) {
			return (string) wp_get_attachment_url( (int) $value );
		}
		return $value;
	}

	/**
	 * Convert Tutor's `time_limit` quiz option to seconds.
	 *
	 * @param array $opt Decoded tutor_quiz_option.
	 * @return int
	 */
	private function quiz_time_seconds( array $opt ): int {
		$limit = $opt['time_limit'] ?? array();
		if ( ! is_array( $limit ) ) {
			return 0;
		}
		$value = (int) ( $limit['time_value'] ?? 0 );
		if ( $value <= 0 ) {
			return 0;
		}
		$unit = (string) ( $limit['time_type'] ?? 'minutes' );
		$mult = array(
			'seconds' => 1,
			'minutes' => 60,
			'hours'   => 3600,
			'days'    => 86400,
			'weeks'   => 604800,
		);
		return $value * ( $mult[ $unit ] ?? 60 );
	}
}
