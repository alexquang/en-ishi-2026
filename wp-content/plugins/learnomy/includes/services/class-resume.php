<?php
/**
 * Resume — the single source of truth for "where does this student continue?".
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Lesson;
use Learnomy\Models\Progress;
use Learnomy\Models\Quiz;

/**
 * Resolves the lesson/URL a student should resume a course at.
 *
 * Every "Continue" surface routes through here — the single-course CTA
 * (enroll card + sticky bar), the My Courses / my-progress rail, the progress
 * widget, and the dashboard resume hero — so they all deep-link to the SAME
 * place: the lesson the student was last on, not a different guess per surface.
 * Before this existed the CTA sent them to the first uncompleted lesson, the
 * rail/widget only sent them to the course landing page, and the dashboard hero
 * used yet another path.
 */
class Resume {

	/**
	 * The lesson a student should resume a course at, or null when the course
	 * is fully complete (the caller decides what a finished course does).
	 *
	 * Strategy:
	 *   1. The most-recently-accessed lesson that is NOT yet complete — exactly
	 *      where they left off, even if they jumped ahead in the curriculum.
	 *   2. Otherwise the first not-yet-complete lesson in curriculum order
	 *      (the earliest gap; also the natural "next" right after finishing one).
	 *   3. null when every lesson is complete.
	 *
	 * An anonymous / preview viewer (user_id 0) always gets the first lesson so
	 * the link stays navigable.
	 *
	 * @param int           $user_id       Viewer.
	 * @param object        $course        Course row (needs id).
	 * @param object[]|null $lessons       Pre-fetched top-level lessons in order; fetched if null.
	 * @param object[]|null $progress_rows Pre-fetched lesson progress rows; fetched if null.
	 */
	public static function lesson( int $user_id, object $course, ?array $lessons = null, ?array $progress_rows = null ): ?object {
		$lessons = $lessons ?? Lesson::get_for_course( (int) $course->id );
		if ( empty( $lessons ) ) {
			return null;
		}

		if ( $user_id <= 0 ) {
			return $lessons[0];
		}

		$progress_rows = $progress_rows ?? Progress::get_all_for_course( $user_id, (int) $course->id );

		$completed   = array();
		$accessed_at = array();
		foreach ( $progress_rows as $row ) {
			if ( 'lesson' !== ( $row->item_type ?? '' ) ) {
				continue;
			}
			$lid = (int) $row->item_id;
			if ( 'completed' === ( $row->status ?? '' ) ) {
				$completed[ $lid ] = true;
			}
			$accessed_at[ $lid ] = (string) ( $row->updated_at ?? '' );
		}

		// 1. Most-recently-accessed lesson that is not yet complete — resume in
		// place. Datetime strings compare lexicographically = chronologically.
		$resume  = null;
		$best_ts = '';
		foreach ( $lessons as $lesson ) {
			$lid = (int) $lesson->id;
			if ( isset( $completed[ $lid ] ) || ! isset( $accessed_at[ $lid ] ) ) {
				continue;
			}
			if ( $accessed_at[ $lid ] > $best_ts ) {
				$best_ts = $accessed_at[ $lid ];
				$resume  = $lesson;
			}
		}
		if ( $resume ) {
			return $resume;
		}

		// 2. First not-yet-complete lesson in curriculum order.
		foreach ( $lessons as $lesson ) {
			if ( ! isset( $completed[ (int) $lesson->id ] ) ) {
				return $lesson;
			}
		}

		// 3. Everything complete.
		return null;
	}

	/**
	 * The deep URL a student should continue a course at: the resume lesson, or
	 * — for a lessons_and_quiz course whose lessons are all done but whose quiz
	 * is not yet passed — the pending quiz (course-scoped or lesson-scoped, see
	 * {@see pending_quiz()}), else the course page as a safe, always-navigable
	 * fallback.
	 *
	 * @param int           $user_id Viewer.
	 * @param object        $course  Course row (needs id, slug, completion_type).
	 * @param object[]|null $lessons Pre-fetched top-level lessons (reused for the lesson lookup).
	 */
	public static function url( int $user_id, object $course, ?array $lessons = null ): string {
		$lessons = $lessons ?? Lesson::get_for_course( (int) $course->id );
		$lesson  = self::lesson( $user_id, $course, $lessons );

		if ( $lesson && ! empty( $lesson->slug ) ) {
			return \Learnomy\route_url(
				'lesson',
				array(
					'slug'        => (string) $course->slug,
					'lesson_slug' => (string) $lesson->slug,
				)
			);
		}

		// All lessons done but the course still gates completion on a quiz pass:
		// send them to the quiz instead of hiding the actual next step.
		$quiz = self::pending_quiz( $user_id, $course );
		if ( $quiz ) {
			return self::quiz_url( $course, $quiz );
		}

		return \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );
	}

	/**
	 * The exam a `lessons_and_quiz` course still gates completion on, for a
	 * student who has no lesson left to resume — or null when the course does
	 * not gate on a quiz, or has no published one.
	 *
	 * SCOPE MUST MATCH THE COMPLETION RULE. Progress_Service's
	 * course_has_passing_quiz_attempt() accepts a pass on a course-scoped quiz
	 * OR on one attached to a course lesson, because "some courses store the
	 * final exam at lesson scope, not course scope". This resolver only looked
	 * at the course-scoped ones, so a course whose exam lives on a lesson read
	 * (correctly) as incomplete while every Continue surface fell through to
	 * the course landing page — the same silent misroute as Basecamp
	 * 10134140444, on the shape that card did not test.
	 *
	 * "Pending" is the caller's context, not a claim this resolver makes: it is
	 * only reached once the course has been found incomplete, which for this
	 * completion type already means no passing attempt exists.
	 *
	 * @param int    $user_id Viewer (0 — anonymous — never has a pending exam).
	 * @param object $course  Course row (needs id, completion_type).
	 */
	public static function pending_quiz( int $user_id, object $course ): ?object {
		if ( $user_id <= 0 || 'lessons_and_quiz' !== (string) ( $course->completion_type ?? '' ) ) {
			return null;
		}

		$quizzes = Quiz::where(
			array(
				'course_id' => (int) $course->id,
				'status'    => 'published',
			),
			'created_at',
			'ASC',
			1
		);
		if ( ! empty( $quizzes[0]->slug ) ) {
			return $quizzes[0];
		}

		// Lesson-scoped exam. Already filtered to published by the model.
		foreach ( Quiz::get_lesson_attached_for_course( (int) $course->id ) as $quiz ) {
			if ( ! empty( $quiz->slug ) ) {
				return $quiz;
			}
		}

		return null;
	}

	/**
	 * Deep URL for a course exam. Kept as a named entry point for resume
	 * callers; the rule itself lives on `Quiz::url()`.
	 *
	 * This method used to own the correct implementation, and its docblock
	 * claimed the lesson player's CTA used "the same rule, kept identical".
	 * It did not — four surfaces had drifted to four different rules by the
	 * time anyone checked. Delegating is what makes that claim true.
	 */
	public static function quiz_url( object $course, object $quiz ): string {
		return Quiz::url( $quiz, $course );
	}
}
