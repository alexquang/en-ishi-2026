<?php
/**
 * LearnDash migration source (read-only adapter).
 *
 * Reads LearnDash (sfwd-* CPTs + ProQuiz tables) and returns normalized payloads.
 * $wpdb is used to READ the foreign LearnDash tables (no Learnomy model exists for
 * them); it never writes. Money/access fields are never read.
 *
 * Verified against LearnDash 5.1.4 + LRNFIX fixtures (see the build spec).
 *
 * @package Learnomy
 */

namespace Learnomy\Migration\Sources;

use Learnomy\Migration\Migration_Source;

defined( 'ABSPATH' ) || exit;

/**
 * LearnDash read-only migration source.
 */
class Learndash_Source implements Migration_Source {

	/** LearnDash ProQuiz answer_type -> Learnomy question type. */
	private const TYPE_MAP = array(
		'single'             => 'mc_single',
		'multiple'           => 'mc_multi',
		'free_answer'        => 'short_answer',
		'cloze_answer'       => 'fill_blank',
		'essay'              => 'essay',
		'sort_answer'        => 'ordering',
		'matrix_sort_answer' => 'matching',
		'assessment_answer'  => '',   // Likert/survey — no Learnomy target.
	);

	/** Per-course lesson-id => section ext_id map, memoised across map() calls. */
	private array $section_cache = array();

	/**
	 * Machine slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return 'learndash';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'LearnDash';
	}

	/**
	 * True when LearnDash is present AND has at least one course.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return post_type_exists( 'sfwd-courses' ) && $this->count( 'course' ) > 0;
	}

	/**
	 * Entities this source can provide.
	 *
	 * @return string[]
	 */
	public function supported_entities(): array {
		return array( 'category', 'course', 'section', 'lesson', 'sub_lesson', 'quiz', 'question', 'enrollment', 'progress' );
	}

	/**
	 * Per-type taxonomies this source exposes.
	 *
	 * @return string[]
	 */
	public function supported_taxonomies(): array {
		return array( 'course_category', 'course_tag', 'lesson_tag', 'quiz_tag', 'question_category' );
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
				// Reason: read-only COUNT against the foreign LearnDash posts table; status list is a hardcoded whitelist, no user input.
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='sfwd-courses' AND post_status IN ('publish','draft','private','pending')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			case 'section':
				// Section headings live as a JSON array in each course's
				// `course_sections` meta (not posts) — count the heading entries
				// across all courses.
				return count( $this->all_sections() );
			case 'lesson':
				// Reason: read-only COUNT; same status whitelist as courses so draft/
				// private/pending lessons are not silently excluded from migration.
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='sfwd-lessons' AND post_status IN ('publish','draft','private','pending')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			case 'sub_lesson':
				// Reason: read-only COUNT; include draft/private/pending (was publish-only).
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='sfwd-topic' AND post_status IN ('publish','draft','private','pending')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			case 'quiz':
				// Reason: read-only COUNT; include draft/private/pending (was publish-only).
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='sfwd-quiz' AND post_status IN ('publish','draft','private','pending')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			case 'question':
				// Reason: read-only COUNT; include draft/private/pending (was publish-only).
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='sfwd-question' AND post_status IN ('publish','draft','private','pending')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			case 'category':
				$cat_taxes = $this->category_taxonomies();
				if ( empty( $cat_taxes ) ) {
					return 0;
				}
				$cat_count = wp_count_terms(
					array(
						'taxonomy'   => $cat_taxes,
						'hide_empty' => false,
					)
				);
				return is_wp_error( $cat_count ) ? 0 : (int) $cat_count;
			case 'enrollment':
				// One row per user-course access grant (usermeta course_<id>_access_from).
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE 'course\\_%\\_access_from'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
			case 'progress':
				// LearnDash creates the activity table lazily — a site with courses
				// but no recorded activity yet won't have it, so guard against a raw
				// "table doesn't exist" DB error during the count.
				if ( ! $this->activity_table_exists() ) {
					return 0;
				}
				// One row per completed lesson/topic in the activity log.
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}learndash_user_activity WHERE activity_type IN ('lesson','topic') AND activity_status=1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		return 0;
	}

	// ── Read (paginated) ──────────────────────────────────────────────────

	/**
	 * Read a page of raw source rows (post IDs, or term objects for categories).
	 *
	 * @param string $entity Entity key.
	 * @param int    $limit  Page size.
	 * @param int    $offset Page offset.
	 * @return array<int,mixed>
	 */
	public function read( string $entity, int $limit, int $offset ): array {
		global $wpdb;
		$pt = array(
			'course'     => 'sfwd-courses',
			'lesson'     => 'sfwd-lessons',
			'sub_lesson' => 'sfwd-topic',
			'quiz'       => 'sfwd-quiz',
			'question'   => 'sfwd-question',
		);

		if ( 'category' === $entity ) {
			$cat_taxes = $this->category_taxonomies();
			if ( empty( $cat_taxes ) ) {
				return array();
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $cat_taxes,
					'hide_empty' => false,
					'number'     => $limit,
					'offset'     => $offset,
					'orderby'    => 'term_id',
				)
			);
			return is_array( $terms ) ? $terms : array();
		}

		if ( 'enrollment' === $entity ) {
			// Reason: read-only paginated scan of access-grant usermeta; limit/offset prepared.
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE 'course\\_%\\_access_from' ORDER BY umeta_id ASC LIMIT %d OFFSET %d", $limit, $offset ) // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery -- static meta_key prefix, no user input.
			);
			return is_array( $rows ) ? $rows : array();
		}
		if ( 'progress' === $entity ) {
			if ( ! $this->activity_table_exists() ) {
				return array();
			}
			// Reason: read-only paginated scan of the activity log; limit/offset prepared.
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT user_id, post_id FROM {$wpdb->prefix}learndash_user_activity WHERE activity_type IN ('lesson','topic') AND activity_status=1 ORDER BY activity_id ASC LIMIT %d OFFSET %d", $limit, $offset )
			);
			return is_array( $rows ) ? $rows : array();
		}

		if ( 'section' === $entity ) {
			return array_slice( $this->all_sections(), $offset, $limit );
		}

		if ( ! isset( $pt[ $entity ] ) ) {
			return array();
		}
		// Same status whitelist for every post-type entity (was publish-only for
		// non-course): draft/private/pending lessons/topics/quizzes must migrate.
		$status = "('publish','draft','private','pending')";
		// Reason: reads the foreign LearnDash posts table read-only; $status is a hardcoded status whitelist (no user input), post_type/limit/offset are prepared.
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
				return $this->map_section( $row );
			case 'lesson':
				return $this->map_lesson( (int) $row, false );
			case 'sub_lesson':
				return $this->map_lesson( (int) $row, true );
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
	 * Map a LearnDash access-grant usermeta row to a normalized enrollment.
	 * meta_key is course_<id>_access_from; the course completion timestamp lives
	 * in the course_completed_<id> usermeta.
	 *
	 * @param mixed $row Row with ->user_id, ->meta_key, ->meta_value.
	 * @return array|null
	 */
	private function map_enrollment( $row ): ?array {
		if ( ! is_object( $row ) ) {
			return null;
		}
		if ( ! preg_match( '/^course_(\d+)_access_from$/', (string) $row->meta_key, $m ) ) {
			return null;
		}
		$user_id   = (int) $row->user_id;
		$course_id = (int) $m[1];
		$completed = get_user_meta( $user_id, 'course_completed_' . $course_id, true );

		return array(
			'ext_id'        => $user_id . ':' . $course_id,
			'user_id'       => $user_id,
			'course_ext_id' => $course_id,
			'enrolled_at'   => (string) $row->meta_value,
			'completed_at'  => $completed ? (string) $completed : null,
		);
	}

	/**
	 * Map a LearnDash completed-lesson activity row to a normalized progress row.
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
	 * The LearnDash category taxonomies that are actually registered.
	 *
	 * `ld_quiz_category` / `ld_question_category` are ProQuiz custom-table
	 * categories on many installs, NOT WordPress taxonomies — passing them to
	 * get_terms()/wp_count_terms() makes the WHOLE call return WP_Error
	 * (`invalid_taxonomy`), which previously both emitted a PHP warning (the
	 * `(int) WP_Error` = 1 bogus count) and made the category read return empty,
	 * silently dropping every course/lesson category. Filter to the registered
	 * subset so the call always succeeds.
	 *
	 * @return string[] Registered category taxonomy names.
	 */
	private function category_taxonomies(): array {
		return array_values(
			array_filter(
				array( 'ld_course_category', 'ld_lesson_category', 'ld_quiz_category', 'ld_question_category' ),
				'taxonomy_exists'
			)
		);
	}

	/**
	 * Every LearnDash section heading across all courses, flattened to a list of
	 * read()-able rows. Sections are not posts — they live as a JSON array in each
	 * course's `course_sections` meta ({ID, post_title, order, type}). Each row
	 * carries its course so map_section() can resolve the parent.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function all_sections(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			"SELECT p.ID AS course_id, pm.meta_value AS json
			 FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'course_sections'
			 WHERE p.post_type = 'sfwd-courses' AND pm.meta_value <> '' AND pm.meta_value <> '[]'
			 ORDER BY p.ID ASC"
		);
		$out  = array();
		foreach ( $rows as $row ) {
			$decoded = json_decode( (string) $row->json, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $section ) {
				if ( ! is_array( $section ) || ( $section['type'] ?? '' ) !== 'section-heading' ) {
					continue;
				}
				$out[] = array(
					'course_ext_id' => (int) $row->course_id,
					'section_id'    => (int) ( $section['ID'] ?? 0 ),
					'title'         => (string) ( $section['post_title'] ?? '' ),
					'order'         => (int) ( $section['order'] ?? 0 ),
				);
			}
		}
		return $out;
	}

	/**
	 * Map a LearnDash section-heading row to a normalized section payload. The
	 * ext_id is `<courseId>:<sectionId>` because a section heading's own ID is
	 * only unique within its course.
	 *
	 * @param mixed $row Section row from {@see all_sections()}.
	 * @return array|null
	 */
	private function map_section( $row ): ?array {
		if ( ! is_array( $row ) || empty( $row['course_ext_id'] ) || empty( $row['section_id'] ) ) {
			return null;
		}
		return array(
			'ext_id'        => $row['course_ext_id'] . ':' . $row['section_id'],
			'course_ext_id' => (int) $row['course_ext_id'],
			'title'         => (string) $row['title'],
			'description'   => '',
			'sort_order'    => (int) $row['order'],
		);
	}

	/**
	 * lesson-post-id => section ext_id (`<courseId>:<sectionId>`) for one course.
	 *
	 * LearnDash's `course_sections` `order` is the index into the course's ordered
	 * lesson list where the heading sits; a lesson at index i belongs to the
	 * heading with the largest `order` <= i. The ordered list comes from
	 * `learndash_course_get_lessons()` (the canonical API — the raw ld_course_steps
	 * meta is computed lazily and often empty). Memoised per course.
	 *
	 * @param int $course_id Course post id.
	 * @return array<int,string> lesson id => section ext_id.
	 */
	private function lesson_section_map( int $course_id ): array {
		if ( isset( $this->section_cache[ $course_id ] ) ) {
			return $this->section_cache[ $course_id ];
		}

		$headings = array();
		foreach ( $this->all_sections() as $section ) {
			if ( (int) $section['course_ext_id'] === $course_id ) {
				$headings[] = array(
					'ext'   => $course_id . ':' . $section['section_id'],
					'order' => (int) $section['order'],
				);
			}
		}
		usort( $headings, static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] );

		$map = array();
		if ( ! empty( $headings ) && function_exists( 'learndash_course_get_lessons' ) ) {
			$lessons = (array) learndash_course_get_lessons( $course_id );
			foreach ( array_values( $lessons ) as $i => $lesson ) {
				$lesson_id = is_object( $lesson ) ? (int) ( $lesson->ID ?? 0 ) : (int) $lesson;
				if ( $lesson_id <= 0 ) {
					continue;
				}
				$section_ext = null;
				foreach ( $headings as $heading ) {
					if ( $heading['order'] <= $i ) {
						$section_ext = $heading['ext'];
					}
				}
				if ( null !== $section_ext ) {
					$map[ $lesson_id ] = $section_ext;
				}
			}
		}

		$this->section_cache[ $course_id ] = $map;
		return $map;
	}

	/**
	 * Whether the LearnDash user-activity table exists. LearnDash creates it
	 * lazily (on first recorded activity / a data upgrade), so a site with courses
	 * but no activity yet won't have it — querying it would emit a raw DB error.
	 *
	 * @return bool
	 */
	private function activity_table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'learndash_user_activity';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Decode one of the per-CPT serialized settings arrays (_sfwd-courses, …).
	 *
	 * @param int    $post_id   Post id.
	 * @param string $post_type LearnDash CPT (e.g. sfwd-courses).
	 * @param string $key       Settings key (without the post-type prefix).
	 * @param mixed  $fallback  Value returned when the key is absent.
	 * @return mixed
	 */
	private function setting( int $post_id, string $post_type, string $key, $fallback = '' ) {
		// get_post_meta( …, true ) already returns the unserialized array for these
		// `_sfwd-*` settings metas — DON'T cast to string first (that yields the
		// literal "Array", which then fails maybe_unserialize and silently drops
		// every settings field: lesson_video_url, passingpercentage, the
		// course/lesson/quiz association fallbacks). Only unserialize the rare case
		// where a raw serialized string comes back instead of an array.
		$arr = get_post_meta( $post_id, '_' . $post_type, true );
		if ( ! is_array( $arr ) ) {
			$arr = maybe_unserialize( (string) $arr );
		}
		if ( ! is_array( $arr ) ) {
			return $fallback;
		}
		return $arr[ $post_type . '_' . $key ] ?? $fallback;
	}

	/**
	 * Map a LearnDash category term to a normalized payload.
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
	 * Map a LearnDash course post to a normalized payload.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	private function map_course( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$cat_terms = wp_get_post_terms( $id, 'ld_course_category', array( 'fields' => 'all' ) );
		$cat_ids   = is_wp_error( $cat_terms ) ? array() : wp_list_pluck( $cat_terms, 'term_id' );
		$extra     = array();
		foreach ( array_slice( (array) $cat_terms, 1 ) as $t ) {
			$extra[] = $t->name;
		}
		$tag_terms = wp_get_post_terms( $id, 'ld_course_tag', array( 'fields' => 'names' ) );

		// Course prerequisites (other course IDs the student must COMPLETE first).
		// Use LearnDash's own validated getter (returns existing course IDs only),
		// gated on the enabled flag so a disabled-but-stored list isn't imported.
		$prereq_ext_ids = array();
		if ( function_exists( 'learndash_get_course_prerequisite' )
			&& function_exists( 'learndash_get_setting' )
			&& 'on' === (string) learndash_get_setting( $id, 'course_prerequisite_enabled' ) ) {
			$prereq_ext_ids = array_values( array_map( 'intval', (array) learndash_get_course_prerequisite( $id ) ) );
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
			'category_ext_ids'        => array_map( 'intval', (array) $cat_ids ),
			'primary_category_ext_id' => $cat_ids[0] ?? null,
			'extra_category_names'    => $extra,
			'tag_names'               => is_wp_error( $tag_terms ) ? array() : (array) $tag_terms,
			'prerequisite_ext_ids'    => $prereq_ext_ids,
			'settings'                => array(),
		);
	}

	/**
	 * Map a LearnDash lesson/topic post to a normalized payload.
	 *
	 * @param int  $id       Post id.
	 * @param bool $is_topic Whether this is a topic (sub-lesson).
	 * @return array|null
	 */
	private function map_lesson( int $id, bool $is_topic ): ?array {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$pt          = $is_topic ? 'sfwd-topic' : 'sfwd-lessons';
		$meta_course = get_post_meta( $id, 'course_id', true );
		$course_id   = (int) ( $meta_course ? $meta_course : $this->setting( $id, $pt, 'course' ) );
		// Read the video from the post's OWN settings array ($pt), not always
		// 'sfwd-lessons' — a topic stores its video in _sfwd-topic, so the old
		// hardcoded 'sfwd-lessons' meant every topic video was silently dropped.
		$video   = (string) $this->setting( $id, $pt, 'lesson_video_url' );
		$tax_tag = $is_topic ? 'ld_topic_tag' : 'ld_lesson_tag';
		$tags    = wp_get_post_terms( $id, $tax_tag, array( 'fields' => 'names' ) );

		// Section association: LearnDash groups lessons under "section heading"
		// entries (course_sections JSON). A lesson belongs to the heading that
		// precedes it in the course's ordered lesson list; a topic inherits its
		// parent lesson's section.
		$section_anchor = $is_topic ? (int) get_post_meta( $id, 'lesson_id', true ) : $id;
		$section_ext_id = $course_id ? ( $this->lesson_section_map( $course_id )[ $section_anchor ] ?? null ) : null;

		// LearnDash "sample lesson" = a free preview lesson (visible before
		// enrolment). Learnomy mirrors this as lrn_lessons.is_preview — carry it
		// so a deliberately-free lesson does not silently become locked content.
		$is_preview = ( 'on' === (string) $this->setting( $id, $pt, 'sample_lesson' ) ) ? 1 : 0;

		// LearnDash "Lesson Timer" (forced_lesson_time, stored in seconds) is a
		// minimum-time-before-complete gate — the exact semantics of Learnomy's
		// lrn_lessons.min_time_seconds. (NOT duration_seconds, which is content
		// length.) Only lessons carry the timer; topics ignore the setting.
		$min_time = $is_topic ? 0 : (int) $this->setting( $id, 'sfwd-lessons', 'forced_lesson_time' );

		$payload = array(
			'ext_id'           => $id,
			'course_ext_id'    => $course_id,
			'title'            => $post->post_title,
			'content_html'     => $post->post_content,
			'content_plain'    => wp_strip_all_tags( $post->post_content ),
			'content_type'     => $video ? 'video' : 'text',
			'video_url'        => $video,
			'is_preview'       => $is_preview,
			'min_time_seconds' => $min_time,
			'section_ext_id'   => $section_ext_id,
			'sort_order'       => (int) $post->menu_order,
			'status'           => $post->post_status,
			'author_id'        => (int) $post->post_author,
			'tag_names'        => is_wp_error( $tags ) ? array() : (array) $tags,
		);
		if ( $is_topic ) {
			$payload['parent_ext_id'] = (int) get_post_meta( $id, 'lesson_id', true );
		}
		return $payload;
	}

	/**
	 * Map a LearnDash quiz post to a normalized payload.
	 *
	 * @param int $id Post id.
	 * @return array|null
	 */
	private function map_quiz( int $id ): ?array {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$meta_course = get_post_meta( $id, 'course_id', true );
		$meta_lesson = get_post_meta( $id, 'lesson_id', true );
		$course_id   = (int) ( $meta_course ? $meta_course : $this->setting( $id, 'sfwd-quiz', 'course' ) );
		$lesson_id   = (int) ( $meta_lesson ? $meta_lesson : $this->setting( $id, 'sfwd-quiz', 'lesson' ) );
		$pro_id      = (int) get_post_meta( $id, 'quiz_pro_id', true );

		// LearnDash keeps the pass mark on the quiz post's _sfwd-quiz settings
		// (sfwd-quiz_passingpercentage), NOT on the ProQuiz master row — so read
		// it from there and only fall back to 70 when it is absent or invalid.
		$passing = (int) $this->setting( $id, 'sfwd-quiz', 'passingpercentage', 70 );
		if ( $passing < 1 || $passing > 100 ) {
			$passing = 70;
		}
		$time    = 0;
		$shuffle = 0;
		if ( $pro_id ) {
			global $wpdb;
			// Reason: reads the foreign LearnDash ProQuiz master table read-only; id is prepared.
			$master = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}learndash_pro_quiz_master WHERE id=%d", $pro_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $master ) {
				$time    = (int) ( $master->time_limit ?? 0 );
				$shuffle = (int) ( $master->question_random ?? 0 );
			}
		}

		// LearnDash "Repeats" caps how many times a quiz may be taken (empty =
		// unlimited). Learnomy mirrors this as lrn_quizzes.attempt_limit
		// (0 = unlimited) — carry it so a capped quiz is not silently re-openable.
		$attempt_limit = (int) $this->setting( $id, 'sfwd-quiz', 'repeats', 0 );

		return array(
			'ext_id'             => $id,
			'course_ext_id'      => $course_id,
			'lesson_ext_id'      => $lesson_id ? $lesson_id : null,
			'title'              => $post->post_title,
			'mode'               => 'graded',
			'passing_score'      => $passing,
			'time_limit_seconds' => $time,
			'shuffle_questions'  => $shuffle,
			'attempt_limit'      => $attempt_limit,
			'status'             => $post->post_status,
			'author_id'          => (int) $post->post_author,
			'tag_names'          => array(),
		);
	}

	/**
	 * Map a LearnDash question post to a normalized payload.
	 *
	 * @param int   $id      Post id.
	 * @param array $options The run's selection/mapping spec.
	 * @return array|null
	 */
	private function map_question( int $id, array $options ): ?array {
		global $wpdb;
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}
		$pro_id    = (int) get_post_meta( $id, 'question_pro_id', true );
		$meta_quiz = get_post_meta( $id, 'quiz_id', true );
		$quiz_id   = (int) ( $meta_quiz ? $meta_quiz : $this->setting( $id, 'sfwd-question', 'quiz' ) );

		// Reason: reads the foreign LearnDash ProQuiz question table read-only; id is prepared.
		$pq = $pro_id
			? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}learndash_pro_quiz_question WHERE id=%d", $pro_id ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			: null;

		$orig_type = $pq ? (string) $pq->answer_type : 'single';
		$type      = $this->resolve_type( $orig_type, $options );

		$base = array(
			'ext_id'      => $id,
			'quiz_ext_id' => $quiz_id,
			'orig_type'   => $orig_type,
			'title'       => $post->post_title,
			'content'     => $pq ? (string) $pq->question : $post->post_content,
			'explanation' => $pq ? (string) $pq->correct_msg : '',
			'hint'        => $pq ? (string) $pq->tip_msg : '',
			'marks'       => $pq ? (float) $pq->points : 1.0,
			'sort_order'  => $pq ? (int) $pq->sort : (int) $post->menu_order,
			'author_id'   => (int) $post->post_author,
		);

		if ( '' === $type ) {
			$base['skip'] = true;
			return $base;
		}
		$base['type'] = $type;
		// Surface lossy downgrades in the dry-run: a source type with no exact
		// Learnomy equivalent (not in TYPE_MAP) that the run's fallback map
		// rerouted to a different type.
		if ( '' === ( self::TYPE_MAP[ $orig_type ] ?? '' ) ) {
			$base['note'] = sprintf( 'Question type "%s" has no exact Learnomy equivalent; imported as "%s".', $orig_type, $type );
		}

		// Build Learnomy answer rows from the decoded ProQuiz answer_data.
		$objects         = $pq ? $this->decode_answer_data( (string) $pq->answer_data ) : array();
		$base['answers'] = $this->encode_answers( $type, $objects );

		return $base;
	}

	/**
	 * Apply the run's fallback map, then the default TYPE_MAP.
	 *
	 * @param string $orig    Source answer type.
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
	 * Decode ProQuiz answer_data (serialized WpProQuiz_Model_AnswerTypes objects)
	 * with no LearnDash dependency: unserialize to __PHP_Incomplete_Class, cast to
	 * array, strip the NUL-mangled protected-property prefixes.
	 *
	 * @param string $blob Serialized ProQuiz answer_data.
	 * @return array<int,array> each: _answer,_correct(bool),_points(float),_sortString
	 */
	private function decode_answer_data( string $blob ): array {
		if ( '' === $blob ) {
			return array();
		}
		// Reason: allowed_classes=false prevents object instantiation; we only read the resulting __PHP_Incomplete_Class arrays.
		$raw = unserialize( $blob, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $obj ) {
			$clean = array();
			foreach ( (array) $obj as $k => $v ) {
				$k = preg_replace( '/^\x00\*\x00/', '', (string) $k );      // Strip protected prefix.
				$k = preg_replace( '/^\x00[^\x00]+\x00/', '', (string) $k ); // Strip private prefix.
				if ( '__PHP_Incomplete_Class_Name' === $k ) {
					continue;
				}
				$clean[ $k ] = $v;
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * Encode decoded ProQuiz objects into Learnomy answer rows per target type.
	 *
	 * @param string $type    Learnomy question type.
	 * @param array  $objects Decoded ProQuiz answer objects.
	 * @return array
	 */
	private function encode_answers( string $type, array $objects ): array {
		$answers = array();
		switch ( $type ) {
			case 'mc_single':
			case 'mc_multi':
				$i = 1;
				foreach ( $objects as $o ) {
					$answers[] = array(
						'content'    => (string) ( $o['_answer'] ?? '' ),
						'is_correct' => ! empty( $o['_correct'] ) ? 1 : 0,
						'sort_order' => $i++,
					);
				}
				break;

			case 'short_answer':
				// free_answer: _answer is a newline-joined accepted list.
				$o = $objects[0] ?? array();
				foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $o['_answer'] ?? '' ) ) as $i => $acc ) {
					$acc = trim( $acc );
					if ( '' !== $acc ) {
						$answers[] = array(
							'content'    => $acc,
							'is_correct' => 1,
							'sort_order' => $i + 1,
						);
					}
				}
				break;

			case 'fill_blank':
				// cloze_answer: one template string with {correct|alt|alt} tokens.
				$tpl   = (string) ( ( $objects[0]['_answer'] ?? '' ) );
				$blank = 1;
				if ( preg_match_all( '/\{([^}]*)\}/', $tpl, $m ) ) {
					foreach ( $m[1] as $token ) {
						$alts = array_filter( array_map( 'trim', explode( '|', $token ) ) );
						$ord  = 1;
						foreach ( $alts as $alt ) {
							$answers[] = array(
								'content'    => $alt,
								'is_correct' => 1,
								'group_id'   => 'blank_' . $blank,
								'sort_order' => $ord++,
							);
						}
						++$blank;
					}
				}
				break;

			case 'ordering':
				// sort_answer: correct order = the stored array order.
				$i = 1;
				foreach ( $objects as $o ) {
					$answers[] = array(
						'content'    => (string) ( $o['_answer'] ?? '' ),
						'sort_order' => $i++,
					);
				}
				break;

			case 'matching':
				// For matrix_sort_answer the _sortString is the prompt criterion and _answer is the draggable match.
				$i = 1;
				foreach ( $objects as $o ) {
					$answers[] = array(
						'content'    => (string) ( $o['_sortString'] ?? '' ),
						'group_id'   => 'prompt',
						'sort_order' => $i,
					);
					$answers[] = array(
						'content'    => (string) ( $o['_answer'] ?? '' ),
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
}
