<?php
/**
 * Lesson comments — query helper over the WordPress comments table.
 *
 * Lesson comments are stored as WP comments with `comment_type='learnomy'`,
 * `comment_post_ID=0`, and `comment_meta._learnomy_lesson_id` linking back
 * to the lesson. See includes/api/class-comments-controller.php.
 *
 * This class is the only place outside the API controller where queries
 * against that storage live — keeps the architecture rule ("no raw
 * $wpdb outside includes/db/ and includes/models/") honest even though
 * the underlying table is wp_comments, not a Learnomy `lrn_*` table.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

class Lesson_Comment {

	/**
	 * The storage contract, in one place.
	 *
	 * A lesson comment is identified by BOTH of these together: the reader
	 * filters on the comment type AND the lesson meta key, so a row that gets
	 * either one wrong is invisible on every surface at once. That is not
	 * hypothetical — the demo seeder wrote `lrn_lesson` + `learnomy_lesson_id`
	 * (no underscore) while the API wrote and read `learnomy` +
	 * `_learnomy_lesson_id`, and every comment it created was unreadable by the
	 * website, the app and this model alike. Its docblock even asserted the
	 * wrong pair as fact, which is how it survived review (Basecamp
	 * 10183713116).
	 *
	 * Anything that writes or reads a lesson comment references these
	 * constants. Two near-identical strings spread across three files is a
	 * contract nobody can see, and it drifted the moment someone wrote a
	 * fourth producer from memory.
	 */
	public const COMMENT_TYPE = 'learnomy';

	public const LESSON_META_KEY = '_learnomy_lesson_id';

	/**
	 * Top-level student comments on lessons inside courses the given
	 * instructor authors, where the instructor hasn't replied yet.
	 *
	 * "Unanswered" definition: the comment is top-level (`comment_parent=0`)
	 * AND there is no child comment whose `user_id` equals the instructor.
	 * Replies from other students don't count as "answered".
	 *
	 * Returns enriched objects ready for the Q&A inbox widget. One SQL
	 * gets the candidate comment ids; a follow-up loads the comment rows
	 * via `get_comment()` so threaded meta + author resolution stays in
	 * WP's cache.
	 *
	 * @return object[] Each: { comment_id, lesson_id, lesson_title, course_id,
	 *                          course_title, author_id, author_name, content, comment_date }.
	 */
	public static function pending_for_instructor( int $instructor_id, int $limit = 5 ): array {
		if ( $instructor_id <= 0 ) {
			return array();
		}
		if ( ! class_exists( '\\Learnomy\\Models\\CourseInstructor' ) ) {
			return array();
		}

		// Free is the source of truth for instructor ↔ course mapping.
		$ci_rows    = CourseInstructor::get_for_user( $instructor_id );
		$course_ids = array_map( static fn( $r ) => (int) $r->course_id, $ci_rows );
		if ( empty( $course_ids ) ) {
			return array();
		}

		// Pull the lesson ids that belong to the instructor's courses
		// via the lessons model so we don't cross-join wp_comments with
		// lrn_lessons here.
		global $wpdb;
		$lessons_table  = $wpdb->prefix . 'lrn_lessons';
		$c_placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lesson_id_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, course_id FROM {$lessons_table} WHERE course_id IN ($c_placeholders)",
				...$course_ids
			)
		);
		if ( empty( $lesson_id_rows ) ) {
			return array();
		}
		$lesson_ids  = array();
		$lesson_meta = array();
		foreach ( $lesson_id_rows as $row ) {
			$lesson_ids[]                  = (int) $row->id;
			$lesson_meta[ (int) $row->id ] = $row;
		}

		// Pending top-level lesson comments — NOT authored by the
		// instructor AND not yet replied to by the instructor.
		$l_placeholders = implode( ',', array_fill( 0, count( $lesson_ids ), '%d' ) );
		$prepare_args   = array_merge( $lesson_ids, array( $instructor_id, $instructor_id, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.comment_ID, c.comment_author, c.comment_content, c.comment_date, c.user_id,
				        CAST( cm.meta_value AS UNSIGNED ) AS lesson_id
				 FROM {$wpdb->comments} c
				 INNER JOIN {$wpdb->commentmeta} cm
				   ON cm.comment_id = c.comment_ID
				   AND cm.meta_key = '" . self::LESSON_META_KEY . "'
				 WHERE c.comment_type = '" . self::COMMENT_TYPE . "'
				   AND c.comment_parent = 0
				   AND c.comment_approved = '1'
				   AND CAST( cm.meta_value AS UNSIGNED ) IN ($l_placeholders)
				   AND c.user_id != %d
				   AND NOT EXISTS (
				       SELECT 1 FROM {$wpdb->comments} reply
				       WHERE reply.comment_parent = c.comment_ID
				         AND reply.user_id = %d
				   )
				 ORDER BY c.comment_date DESC
				 LIMIT %d",
				...$prepare_args
			)
		);
		if ( empty( $rows ) ) {
			return array();
		}

		// Hydrate course titles via Free's Course model (A6 — Pro never
		// reads Free tables directly, but this IS Free so we use the
		// model API anyway for consistency).
		$course_titles = array();
		foreach ( $course_ids as $cid ) {
			$course = Course::find( $cid );
			if ( $course ) {
				$course_titles[ $cid ] = (string) $course->title;
			}
		}

		$out = array();
		foreach ( $rows as $row ) {
			$lesson      = $lesson_meta[ (int) $row->lesson_id ] ?? null;
			$author_name = (string) $row->comment_author;
			if ( ! $author_name && (int) $row->user_id > 0 ) {
				$u           = get_user_by( 'id', (int) $row->user_id );
				$author_name = $u ? $u->display_name : __( 'Student', 'learnomy' );
			}
			$out[] = (object) array(
				'comment_id'   => (int) $row->comment_ID,
				'lesson_id'    => (int) $row->lesson_id,
				'lesson_title' => $lesson ? (string) $lesson->title : __( 'Unknown lesson', 'learnomy' ),
				'course_id'    => $lesson ? (int) $lesson->course_id : 0,
				'course_title' => $lesson ? ( $course_titles[ (int) $lesson->course_id ] ?? '' ) : '',
				'author_id'    => (int) $row->user_id,
				'author_name'  => $author_name,
				'content'      => (string) $row->comment_content,
				'comment_date' => (string) $row->comment_date,
			);
		}
		return $out;
	}
}
