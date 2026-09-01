<?php
/**
 * LifterLMS migration source (read-only adapter).
 *
 * Reads LifterLMS (course/section/lesson/llms_quiz/llms_question CPTs +
 * serialized `_llms_choice_*` choice meta) and returns normalized payloads.
 * Postmeta is read directly (read-only); the source is never written, and
 * money/commerce data (access plans, prices, orders) is never read.
 *
 * LifterLMS content model maps to Learnomy as:
 *   course (CPT course)   -> course
 *   section (_llms_parent_course, _llms_order) -> section
 *   lesson (_llms_parent_section + _llms_parent_course) -> lesson (flat; no sub)
 *   llms_quiz (_llms_lesson_id -> lesson -> course) -> quiz
 *   llms_question (_llms_parent_id -> quiz, _llms_choice_* choices) -> question
 *
 * Verified against LifterLMS 10.x + LRNFIX fixtures (see the build spec).
 *
 * @package Learnomy
 */

namespace Learnomy\Migration\Sources;

use Learnomy\Migration\Migration_Source;

defined( 'ABSPATH' ) || exit;

/**
 * LifterLMS read-only migration source.
 */
class Lifterlms_Source implements Migration_Source {

	/**
	 * Native LifterLMS question_type -> Learnomy type. Types needing the
	 * multi-choice flag (choice/picture_choice), the run fallback map
	 * (picture_reorder/code/upload/scale), or a skip (content) are resolved in
	 * {@see resolve_q_type()}, not here.
	 */
	private const TYPE_MAP = array(
		'true_false'   => 'true_false',
		'blank'        => 'fill_blank',
		'short_answer' => 'short_answer',
		'reorder'      => 'ordering',
		'long_answer'  => 'essay',
	);

	/**
	 * Machine slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'lifterlms';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'LifterLMS';
	}

	/**
	 * True when LifterLMS is present AND has at least one course.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return ( function_exists( 'llms' ) || post_type_exists( 'course' ) ) && $this->count( 'course' ) > 0;
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
	 * Per-type taxonomies this source exposes.
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
		$pt = array(
			'course'   => 'course',
			'section'  => 'section',
			'lesson'   => 'lesson',
			'quiz'     => 'llms_quiz',
			'question' => 'llms_question',
		);
		if ( isset( $pt[ $entity ] ) ) {
			$status = 'course' === $entity ? "('publish','draft','private','pending')" : "('publish','draft')";
			// Reason: read-only COUNT against the foreign LifterLMS posts table; $status is a hardcoded whitelist, post_type prepared.
			return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN {$status}",
					$pt[ $entity ]
				)
			);
		}
		if ( 'category' === $entity ) {
			return (int) wp_count_terms(
				array(
					'taxonomy'   => array( 'course_cat' ),
					'hide_empty' => false,
				)
			);
		}
		if ( 'enrollment' === $entity ) {
			// One row per enrolled status in the LifterLMS user-postmeta table.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lifterlms_user_postmeta WHERE meta_key='_status' AND meta_value='enrolled'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		if ( 'progress' === $entity ) {
			// _is_complete=yes spans lessons/sections/courses; non-lesson post_ids
			// simply don't resolve to a Learnomy lesson and are skipped on import.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lifterlms_user_postmeta WHERE meta_key='_is_complete' AND meta_value='yes'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return 0;
	}

	// ── Read (paginated) ──────────────────────────────────────────────────

	/**
	 * Read a page of raw source rows (post IDs or term objects).
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
					'taxonomy'   => array( 'course_cat' ),
					'hide_empty' => false,
					'number'     => $limit,
					'offset'     => $offset,
					'orderby'    => 'term_id',
				)
			);
			return is_array( $terms ) ? $terms : array();
		}

		if ( 'enrollment' === $entity ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT user_id, post_id, updated_date FROM {$wpdb->prefix}lifterlms_user_postmeta WHERE meta_key='_status' AND meta_value='enrolled' ORDER BY meta_id ASC LIMIT %d OFFSET %d", $limit, $offset )
			);
			return is_array( $rows ) ? $rows : array();
		}

		if ( 'progress' === $entity ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT user_id, post_id FROM {$wpdb->prefix}lifterlms_user_postmeta WHERE meta_key='_is_complete' AND meta_value='yes' ORDER BY meta_id ASC LIMIT %d OFFSET %d", $limit, $offset )
			);
			return is_array( $rows ) ? $rows : array();
		}

		$pt = array(
			'course'   => 'course',
			'section'  => 'section',
			'lesson'   => 'lesson',
			'quiz'     => 'llms_quiz',
			'question' => 'llms_question',
		);
		if ( ! isset( $pt[ $entity ] ) ) {
			return array();
		}
		$status = 'course' === $entity ? "('publish','draft','private','pending')" : "('publish','draft')";
		// Reason: reads the foreign LifterLMS posts table read-only; $status is a hardcoded status whitelist (no user input), post_type/limit/offset are prepared.
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
	 * @param mixed  $row     Raw source row (post ID or term object).
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
				return $this->map_question( (int) $row, $options );
			case 'enrollment':
				return $this->map_enrollment( $row );
			case 'progress':
				return $this->map_progress( $row );
		}
		return null;
	}

	/**
	 * Map a LifterLMS _status=enrolled user-postmeta row to a normalized
	 * enrollment (post_id = course). Course completion rides in via progress.
	 *
	 * @param mixed $row Row with ->user_id, ->post_id, ->updated_date.
	 * @return array|null
	 */
	private function map_enrollment( $row ): ?array {
		if ( ! is_object( $row ) ) {
			return null;
		}
		$user_id   = (int) $row->user_id;
		$course_id = (int) $row->post_id;
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return null;
		}
		return array(
			'ext_id'        => $user_id . ':' . $course_id,
			'user_id'       => $user_id,
			'course_ext_id' => $course_id,
			'enrolled_at'   => (string) ( $row->updated_date ?? '' ),
		);
	}

	/**
	 * Map a LifterLMS _is_complete=yes user-postmeta row to a normalized
	 * progress row. post_id may be a lesson, section, or course; only lessons
	 * resolve to a Learnomy lesson, the rest are skipped on import.
	 *
	 * @param mixed $row Row with ->user_id, ->post_id.
	 * @return array|null
	 */
	private function map_progress( $row ): ?array {
		if ( ! is_object( $row ) ) {
			return null;
		}
		$user_id   = (int) $row->user_id;
		$lesson_id = (int) $row->post_id;
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
	 * Map a LifterLMS course category term to a normalized payload.
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
	 * Map a LifterLMS course post to a normalized payload. course_difficulty
	 * becomes the difficulty; course_tag + course_track + any extra course_cat
	 * become course tags (decision #4); the primary course_cat is the category.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	private function map_course( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$cat_terms = wp_get_post_terms( $id, 'course_cat', array( 'fields' => 'all' ) );
		$cat_terms = is_wp_error( $cat_terms ) ? array() : $cat_terms;
		$cat_ids   = wp_list_pluck( $cat_terms, 'term_id' );
		$extra     = array();
		foreach ( array_slice( $cat_terms, 1 ) as $t ) {
			$extra[] = $t->name;
		}

		$tags = $this->term_names( $id, 'course_tag' );
		foreach ( $this->term_names( $id, 'course_track' ) as $track ) {
			$tags[] = $track;
		}

		$difficulty = $this->term_names( $id, 'course_difficulty' );

		$video = (string) get_post_meta( $id, '_llms_video_embed', true );

		// Co-instructors: LifterLMS stores the full instructor list (incl. the
		// author) in _llms_instructors as [{id,…},…]. Carry everyone but the
		// primary author as Learnomy co-instructors.
		$co_instructor_ids = array();
		$instructors       = get_post_meta( $id, '_llms_instructors', true );
		if ( is_array( $instructors ) ) {
			foreach ( $instructors as $ins ) {
				$iid = (int) ( is_array( $ins ) ? ( $ins['id'] ?? 0 ) : $ins );
				if ( $iid > 0 && $iid !== (int) $post->post_author ) {
					$co_instructor_ids[] = $iid;
				}
			}
		}

		// LifterLMS supports a single course prerequisite (a track prereq has no
		// Learnomy equivalent and is skipped). Only carry it when explicitly on.
		$prereq_ext_ids = array();
		if ( 'yes' === get_post_meta( $id, '_llms_has_prerequisite', true ) ) {
			$pre = (int) get_post_meta( $id, '_llms_prerequisite', true );
			if ( $pre > 0 ) {
				$prereq_ext_ids[] = $pre;
			}
		}

		return array(
			'ext_id'                  => $id,
			'title'                   => $post->post_title,
			'slug'                    => $post->post_name,
			'description'             => $post->post_excerpt,
			'content'                 => $post->post_content,
			'status'                  => $post->post_status,
			'author_id'               => (int) $post->post_author,
			'thumbnail_url'           => (string) get_the_post_thumbnail_url( $id, 'full' ),
			'difficulty'              => $difficulty[0] ?? '',
			'video_url'               => $video,
			'category_ext_ids'        => array_map( 'intval', (array) $cat_ids ),
			'primary_category_ext_id' => $cat_ids[0] ?? null,
			'extra_category_names'    => $extra,
			'tag_names'               => $tags,
			'prerequisite_ext_ids'    => $prereq_ext_ids,
			'co_instructor_ids'       => $co_instructor_ids,
			'settings'                => array(),
		);
	}

	/**
	 * Map a LifterLMS section post to a normalized payload.
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
			'course_ext_id' => (int) get_post_meta( $id, '_llms_parent_course', true ),
			'title'         => $post->post_title,
			'description'   => $post->post_content,
			'sort_order'    => (int) get_post_meta( $id, '_llms_order', true ),
		);
	}

	/**
	 * Map a LifterLMS lesson post to a normalized payload.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	private function map_lesson( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$section_id = (int) get_post_meta( $id, '_llms_parent_section', true );
		$video      = (string) get_post_meta( $id, '_llms_video_embed', true );
		$audio      = (string) get_post_meta( $id, '_llms_audio_embed', true );
		$media      = $video ? $video : $audio;
		$type       = $video ? 'video' : ( $audio ? 'audio' : 'text' );

		return array(
			'ext_id'         => $id,
			'course_ext_id'  => (int) get_post_meta( $id, '_llms_parent_course', true ),
			'section_ext_id' => $section_id ? $section_id : null,
			'title'          => $post->post_title,
			'content_html'   => $post->post_content,
			'content_plain'  => wp_strip_all_tags( $post->post_content ),
			'content_type'   => $type,
			'video_url'      => $media,
			// A LifterLMS "free" lesson is previewable before enrolment — mirror it
			// to lrn_lessons.is_preview so it is not silently locked on import.
			'is_preview'     => ( 'yes' === get_post_meta( $id, '_llms_free_lesson', true ) ) ? 1 : 0,
			'sort_order'     => (int) get_post_meta( $id, '_llms_order', true ),
			'status'         => $post->post_status,
			'author_id'      => (int) $post->post_author,
			'tag_names'      => array(),
		);
	}

	/**
	 * Map a LifterLMS quiz post to a normalized payload. The quiz links to its
	 * lesson via `_llms_lesson_id`; the course is that lesson's parent course.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	private function map_quiz( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$lesson_id = (int) get_post_meta( $id, '_llms_lesson_id', true );
		$course_id = $lesson_id ? (int) get_post_meta( $lesson_id, '_llms_parent_course', true ) : 0;
		$limit_att = 'yes' === get_post_meta( $id, '_llms_limit_attempts', true );

		return array(
			'ext_id'             => $id,
			'course_ext_id'      => $course_id,
			'lesson_ext_id'      => $lesson_id ? $lesson_id : null,
			'title'              => $post->post_title,
			'mode'               => 'graded',
			'passing_score'      => (int) ( get_post_meta( $id, '_llms_passing_percent', true ) ?: 70 ),
			'time_limit_seconds' => 'yes' === get_post_meta( $id, '_llms_limit_time', true ) ? ( (int) get_post_meta( $id, '_llms_time_limit', true ) * 60 ) : 0,
			'attempt_limit'      => $limit_att ? (int) get_post_meta( $id, '_llms_allowed_attempts', true ) : 0,
			'shuffle_questions'  => 'yes' === get_post_meta( $id, '_llms_random_questions', true ) ? 1 : 0,
			'status'             => $post->post_status,
			'author_id'          => (int) $post->post_author,
			'tag_names'          => array(),
		);
	}

	/**
	 * Map a LifterLMS question post to a normalized payload.
	 *
	 * @param int   $id      Post id.
	 * @param array $options The run's selection/mapping spec.
	 * @return array|null
	 */
	private function map_question( int $id, array $options ): ?array {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$orig_type       = (string) get_post_meta( $id, '_llms_question_type', true );
		$multi           = 'yes' === get_post_meta( $id, '_llms_multi_choices', true );
		[ $type, $note ] = $this->resolve_q_type( $orig_type, $multi, $options );

		$content = '' !== trim( (string) $post->post_content ) ? (string) $post->post_content : (string) $post->post_title;

		$base = array(
			'ext_id'      => $id,
			'quiz_ext_id' => (int) get_post_meta( $id, '_llms_parent_id', true ),
			'orig_type'   => $orig_type,
			'title'       => $post->post_title,
			'content'     => $content,
			'marks'       => (float) ( get_post_meta( $id, '_llms_points', true ) ?: 1 ),
			'sort_order'  => (int) $post->menu_order,
			'author_id'   => (int) $post->post_author,
		);

		if ( '' === $type ) {
			$base['skip'] = true;
			return $base;
		}
		$base['type'] = $type;
		if ( $note ) {
			$base['note'] = sprintf( 'Question type "%s" has no exact Learnomy equivalent; imported as "%s".', $orig_type, $type );
		}

		$choices = $this->read_choices( $id );
		if ( 'fill_blank' === $type ) {
			// LifterLMS marks the blank inline in the text with {{answer}};
			// strip the answer to leave a readable blank in the content.
			$base['content'] = (string) preg_replace( '/\{\{.*?\}\}/', '_____', $content );
			$base['answers'] = $this->encode_fill_blank( $content, $choices );
		} else {
			$base['answers'] = $this->encode_answers( $type, $choices );
		}

		return $base;
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	/**
	 * Resolve a LifterLMS question type (+ the multi-choice flag) to a Learnomy
	 * type. Returns [ learnomy_type, is_downgrade ]; '' type = skip.
	 *
	 * @param string $orig    LifterLMS question_type.
	 * @param bool   $multi   Whether the choice question accepts multiple answers.
	 * @param array  $options The run's selection/mapping spec.
	 * @return array{0:string,1:bool}
	 */
	private function resolve_q_type( string $orig, bool $multi, array $options ): array {
		$fallbacks = (array) ( $options['question_type_fallbacks'] ?? array() );

		// An explicit skip override always wins.
		if ( 'skip' === ( $fallbacks[ $orig ] ?? '' ) ) {
			return array( '', false );
		}
		// Choice questions become single/multi from their own flag (native).
		if ( 'choice' === $orig ) {
			return array( $multi ? 'mc_multi' : 'mc_single', false );
		}
		// Picture choice: text labels/URLs survive in the option content; image
		// widget is lost -> a downgrade. Still single/multi aware.
		if ( 'picture_choice' === $orig ) {
			return array( $multi ? 'mc_multi' : 'mc_single', true );
		}
		// Any other run-supplied fallback (picture_reorder, code, upload, …).
		if ( isset( $fallbacks[ $orig ] ) ) {
			return array( (string) $fallbacks[ $orig ], true );
		}
		// LifterLMS "content" questions are informational (no answer) -> skip.
		if ( 'content' === $orig ) {
			return array( '', false );
		}
		return array( self::TYPE_MAP[ $orig ] ?? '', false );
	}

	/**
	 * Read + normalize a LifterLMS question's choices (the serialized
	 * `_llms_choice_*` postmeta), ordered by marker (A, B, C…).
	 *
	 * @param int $question_id Question post id.
	 * @return array<int,array{content:string,correct:bool,marker:string}>
	 */
	private function read_choices( int $question_id ): array {
		$out = array();
		foreach ( get_post_meta( $question_id ) as $key => $values ) {
			if ( 0 !== strpos( (string) $key, '_llms_choice_' ) ) {
				continue;
			}
			$choice = maybe_unserialize( (string) ( $values[0] ?? '' ) );
			if ( ! is_array( $choice ) ) {
				continue;
			}
			$value = $choice['choice'] ?? '';
			// Image choices store choice as { id, src }; keep the URL.
			if ( is_array( $value ) ) {
				$value = (string) ( $value['src'] ?? '' );
			}
			$out[] = array(
				'content' => (string) $value,
				'correct' => ! empty( $choice['correct'] ),
				'marker'  => (string) ( $choice['marker'] ?? '' ),
			);
		}
		usort(
			$out,
			static fn( array $a, array $b ): int => strcmp( $a['marker'], $b['marker'] )
		);
		return $out;
	}

	/**
	 * Encode normalized choices into Learnomy answer rows per target type.
	 *
	 * @param string $type    Learnomy question type.
	 * @param array  $choices Normalized choices (marker-ordered).
	 * @return array
	 */
	private function encode_answers( string $type, array $choices ): array {
		$answers = array();
		switch ( $type ) {
			case 'mc_single':
			case 'mc_multi':
			case 'true_false':
				$i = 1;
				foreach ( $choices as $c ) {
					$answers[] = array(
						'content'    => $c['content'],
						'is_correct' => $c['correct'] ? 1 : 0,
						'sort_order' => $i++,
					);
				}
				break;

			case 'short_answer':
				// Accepted answers = the correct choices.
				$i = 1;
				foreach ( $choices as $c ) {
					if ( $c['correct'] && '' !== trim( $c['content'] ) ) {
						$answers[] = array(
							'content'    => $c['content'],
							'is_correct' => 1,
							'sort_order' => $i++,
						);
					}
				}
				break;

			case 'ordering':
				// reorder/picture_reorder: every choice is "correct"; the
				// correct sequence is the marker order (already sorted).
				$i = 1;
				foreach ( $choices as $c ) {
					$answers[] = array(
						'content'    => $c['content'],
						'sort_order' => $i++,
					);
				}
				break;

			case 'essay':
			default:
				break;
		}
		return $answers;
	}

	/**
	 * Encode a LifterLMS blank question into per-blank Learnomy answer rows.
	 * The number of blanks is the count of `{{…}}` markers in the text; the
	 * accepted values are the correct choices, paired to blanks in order (a
	 * single-blank question treats all correct choices as alternatives).
	 *
	 * @param string $text    The question text (with {{…}} markers).
	 * @param array  $choices Normalized choices (marker-ordered).
	 * @return array
	 */
	private function encode_fill_blank( string $text, array $choices ): array {
		$accepted = array();
		foreach ( $choices as $c ) {
			if ( $c['correct'] && '' !== trim( $c['content'] ) ) {
				$accepted[] = $c['content'];
			}
		}
		if ( empty( $accepted ) ) {
			return array();
		}
		$blanks  = preg_match_all( '/\{\{.*?\}\}/', $text );
		$answers = array();
		if ( $blanks <= 1 ) {
			// One blank: every accepted value is an alternative for it.
			$ord = 1;
			foreach ( $accepted as $value ) {
				$answers[] = array(
					'content'    => $value,
					'is_correct' => 1,
					'group_id'   => 'blank_1',
					'sort_order' => $ord++,
				);
			}
		} else {
			// N blanks: one accepted value per blank, in reading order.
			foreach ( $accepted as $i => $value ) {
				$answers[] = array(
					'content'    => $value,
					'is_correct' => 1,
					'group_id'   => 'blank_' . ( $i + 1 ),
					'sort_order' => 1,
				);
			}
		}
		return $answers;
	}

	/**
	 * Term names for a post in a taxonomy ([] on error).
	 *
	 * @param int    $post_id  Post id.
	 * @param string $taxonomy Taxonomy slug.
	 * @return string[]
	 */
	private function term_names( int $post_id, string $taxonomy ): array {
		$names = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'names' ) );
		return is_wp_error( $names ) ? array() : (array) $names;
	}
}
