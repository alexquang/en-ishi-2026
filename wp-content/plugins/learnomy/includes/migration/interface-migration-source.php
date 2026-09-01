<?php
/**
 * Migration source contract.
 *
 * Every source module (LearnDash, Tutor, LifterLMS, …) implements this. A module
 * is READ-ONLY on the source: it reads the source plugin's data and returns
 * normalized payloads. It NEVER writes to the source tables and NEVER reads
 * money/commerce data. The Import_Service consumes the normalized payloads and
 * writes them through Learnomy's own services/models (no direct DB).
 *
 * Normalized payload shapes (the contract the Import_Service expects from map()):
 *
 *  course      => [ ext_id, title, slug, description, content, status, author_id,
 *                   thumbnail_url, video_url, difficulty, duration_seconds,
 *                   category_ext_ids[], primary_category_ext_id, tag_names[],
 *                   text_blocks{ heading => lines[] }, settings{} ]
 *  section     => [ ext_id, course_ext_id, title, description, sort_order ]
 *  lesson      => [ ext_id, course_ext_id, section_ext_id?, parent_ext_id?,
 *                   title, content_html, content_plain, content_type, video_url,
 *                   duration_seconds, sort_order, drip{}, tag_names[] ]
 *                 (a sub-lesson is a lesson with parent_ext_id set)
 *  quiz        => [ ext_id, course_ext_id, lesson_ext_id?, title, description,
 *                   mode, passing_score, time_limit_seconds, attempt_limit,
 *                   shuffle_questions, status, tag_names[] ]
 *  question    => [ ext_id, quiz_ext_id, type (Learnomy type), orig_type,
 *                   title, content, explanation, hint, marks, sort_order,
 *                   category_name?, tag_names[], answers[], skip(bool), note? ]
 *                 answers[] => [ content, is_correct, fraction, group_id, sort_order ]
 *  category    => [ ext_id, taxonomy, name, slug, parent_ext_id ]   (course taxonomy)
 *  enrollment  => [ ext_id, course_ext_id, user_email/user_login, enrolled_at, completed_at? ]
 *  progress    => [ user_email/user_login, course_ext_id, lesson_ext_ids[], completed(bool) ]
 *
 * @package Learnomy
 */

namespace Learnomy\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Contract every migration source module implements.
 */
interface Migration_Source {

	/**
	 * Machine slug, e.g. 'learndash'.
	 *
	 * @return string
	 */
	public function slug(): string;

	/**
	 * Human label, e.g. 'LearnDash'.
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * True when the source plugin is present AND has importable data.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Entities this source can provide, from the canonical set:
	 * course, section, lesson, sub_lesson, quiz, question, category,
	 * enrollment, progress.
	 *
	 * @return string[]
	 */
	public function supported_entities(): array;

	/**
	 * Per-type taxonomies this source exposes (Learnomy keys), e.g.
	 * [ 'course_category', 'course_tag', 'lesson_tag', 'quiz_tag', 'question_category', 'question_tag' ].
	 *
	 * @return string[]
	 */
	public function supported_taxonomies(): array;

	/**
	 * Total source rows for an entity (for the dry-run + batching).
	 *
	 * @param string $entity Entity key.
	 * @return int
	 */
	public function count( string $entity ): int;

	/**
	 * Read a page of raw source rows for an entity.
	 *
	 * @param string $entity Entity key.
	 * @param int    $limit  Page size.
	 * @param int    $offset Page offset.
	 * @return array<int,mixed> raw rows/objects (passed back to map()).
	 */
	public function read( string $entity, int $limit, int $offset ): array;

	/**
	 * Map ONE raw row to the normalized payload for $entity (see file docblock).
	 * Returns null to drop the row.
	 *
	 * @param string $entity  Entity key.
	 * @param mixed  $row     Raw source row/object.
	 * @param array  $options The run's selection/mapping spec (fallbacks, etc.).
	 * @return array|null
	 */
	public function map( string $entity, $row, array $options = array() );
}
