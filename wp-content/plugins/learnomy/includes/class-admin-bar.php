<?php
/**
 * Front-end "Edit" shortcuts for the Learnomy content being viewed.
 *
 * Two distinct edit surfaces, with a deliberate split:
 *
 *  - The wp-admin admin-bar node (add_edit_node) ALWAYS links to the wp-admin
 *    editor. It is the backend staff toolbar — only useful to users who can
 *    reach wp-admin, and it can be hidden per role. It is the backend path.
 *  - The in-content edit bar (render_*_edit_bar) is the FRONTEND edit path. It
 *    appears only when a frontend editor actually exists — Pro's frontend
 *    builder overrides the learnomy_instructor_*_edit_url filters with a
 *    frontend route. When no frontend surface exists the in-content bar is
 *    hidden: the admin bar already carries the backend link, so we never
 *    duplicate a wp-admin link inside the page content.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Lesson;
use Learnomy\Models\Quiz;

class Admin_Bar {

	public function __construct() {
		add_action( 'admin_bar_menu', array( $this, 'add_edit_node' ), 80 );

		// In-content frontend edit affordance. Rendered via the
		// course/lesson/quiz "before content" hooks so it works across every
		// layout variant without per-template markup.
		add_action( 'learnomy_course_before_content', array( $this, 'render_course_edit_bar' ), 5 );
		add_action( 'learnomy_lesson_before_content', array( $this, 'render_lesson_edit_bar' ), 5, 2 );
		add_action( 'learnomy_quiz_before_content', array( $this, 'render_quiz_edit_bar' ), 5 );
	}

	/**
	 * In-content "Edit Course" bar. Frontend only — hidden when no frontend
	 * editor exists (the admin bar carries the backend link in that case).
	 *
	 * @param object $course Course row (passed by learnomy_course_before_content).
	 */
	public function render_course_edit_bar( $course ): void {
		if ( is_admin() || ! is_object( $course ) || ! $this->can_edit_course( $course ) ) {
			return;
		}
		$url = $this->frontend_course_edit_url( (int) $course->id );
		if ( '' === $url ) {
			return;
		}
		$this->print_edit_bar( __( 'Edit Course', 'learnomy' ), $url );
	}

	/**
	 * In-content "Edit Lesson" bar. Frontend only — hidden when no frontend
	 * editor exists.
	 *
	 * @param object $lesson Lesson row.
	 * @param object $course Parent course row.
	 */
	public function render_lesson_edit_bar( $lesson, $course ): void {
		if ( is_admin() || ! is_object( $lesson ) || ! is_object( $course ) || ! $this->can_edit_course( $course ) ) {
			return;
		}
		$url = $this->frontend_lesson_edit_url( (int) $course->id, (int) $lesson->id );
		if ( '' === $url ) {
			return;
		}
		$this->print_edit_bar( __( 'Edit Lesson', 'learnomy' ), $url );
	}

	/**
	 * In-content "Edit Quiz" bar. Frontend only — hidden when no frontend
	 * editor exists. A standalone quiz has no frontend route, so it stays hidden.
	 *
	 * @param object $quiz Quiz row (passed by learnomy_quiz_before_content).
	 */
	public function render_quiz_edit_bar( $quiz ): void {
		if ( is_admin() || ! is_object( $quiz ) || ! $this->can_edit_quiz( $quiz ) ) {
			return;
		}
		$url = $this->frontend_quiz_edit_url( (int) $quiz->id, (int) ( $quiz->course_id ?? 0 ) );
		if ( '' === $url ) {
			return;
		}
		$this->print_edit_bar( __( 'Edit Quiz', 'learnomy' ), $url );
	}

	/**
	 * Frontend course-editor URL, or '' when no frontend edit surface exists.
	 *
	 * The learnomy_instructor_course_edit_url filter defaults to the wp-admin
	 * editor; Pro's frontend builder overrides it with a frontend route. When
	 * the filter is unchanged (no Pro frontend surface) we return '' so the
	 * in-content bar is hidden — the admin bar already provides the backend link.
	 *
	 * @param int $course_id Course ID.
	 * @return string Frontend edit URL, or '' to hide the in-content bar.
	 */
	private function frontend_course_edit_url( int $course_id ): string {
		$backend = admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . $course_id );
		$url     = (string) apply_filters( 'learnomy_instructor_course_edit_url', $backend, $course_id );
		return $url === $backend ? '' : $url;
	}

	/**
	 * Frontend lesson-editor URL, or '' when no frontend edit surface exists.
	 *
	 * @param int $course_id Course ID.
	 * @param int $lesson_id Lesson ID.
	 * @return string Frontend edit URL, or '' to hide the in-content bar.
	 */
	private function frontend_lesson_edit_url( int $course_id, int $lesson_id ): string {
		$backend = admin_url( 'admin.php?page=learnomy-lesson-editor&course_id=' . $course_id . '&lesson_id=' . $lesson_id );
		$url     = (string) apply_filters( 'learnomy_instructor_lesson_edit_url', $backend, $course_id, $lesson_id );
		return $url === $backend ? '' : $url;
	}

	/**
	 * Frontend quiz-editor URL, or '' when no frontend edit surface exists.
	 * A standalone quiz (course_id 0) has no frontend route, so Pro leaves the
	 * filter at the wp-admin default and this returns '' (bar hidden).
	 *
	 * @param int $quiz_id   Quiz ID.
	 * @param int $course_id Parent course ID (0 for a standalone quiz).
	 * @return string Frontend edit URL, or '' to hide the in-content bar.
	 */
	private function frontend_quiz_edit_url( int $quiz_id, int $course_id ): string {
		$backend = admin_url( 'admin.php?page=learnomy-edit-quiz&quiz_id=' . $quiz_id );
		$url     = (string) apply_filters( 'learnomy_instructor_quiz_edit_url', $backend, $quiz_id, $course_id );
		return $url === $backend ? '' : $url;
	}

	private function print_edit_bar( string $label, string $url ): void {
		printf(
			'<div class="lrn-container lrn-admin-edit-bar"><a class="lrn-admin-edit-bar__btn" href="%s"><i data-lucide="square-pen" aria-hidden="true"></i><span>%s</span></a></div>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Add the contextual Edit node for the Learnomy entity being viewed. This
	 * node ALWAYS links to the wp-admin editor — it is the backend staff path.
	 * The frontend path is the in-content bar above.
	 *
	 * @param \WP_Admin_Bar $bar The admin bar instance.
	 */
	public function add_edit_node( \WP_Admin_Bar $bar ): void {
		// Front-end only — the wp-admin screens already have their own editors.
		if ( is_admin() || ! is_user_logged_in() ) {
			return;
		}

		switch ( (string) get_query_var( 'learnomy_route' ) ) {
			case 'course':
				$this->course_node( $bar );
				break;
			case 'lesson':
				$this->lesson_node( $bar );
				break;
			case 'quiz':
			case 'standalone-quiz':
				$this->quiz_node( $bar );
				break;
		}
	}

	private function course_node( \WP_Admin_Bar $bar ): void {
		$slug   = sanitize_text_field( (string) get_query_var( 'learnomy_slug' ) );
		$course = '' !== $slug ? Course::find_by_slug( $slug ) : null;
		if ( ! $course || ! $this->can_edit_course( $course ) ) {
			return;
		}
		$this->add_node(
			$bar,
			__( 'Edit Course', 'learnomy' ),
			admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . (int) $course->id )
		);
	}

	private function lesson_node( \WP_Admin_Bar $bar ): void {
		// Resolve the course first, then scope the lesson lookup to it — lesson
		// slugs are only unique within a course, so a bare find_by_slug() can
		// match a same-named lesson in a different course.
		$course_slug = sanitize_text_field( (string) get_query_var( 'learnomy_slug' ) );
		$lesson_slug = sanitize_text_field( (string) get_query_var( 'learnomy_lesson_slug' ) );
		$course      = '' !== $course_slug ? Course::find_by_slug( $course_slug ) : null;
		if ( ! $course || ! $this->can_edit_course( $course ) ) {
			return;
		}
		$lesson = '' !== $lesson_slug ? Lesson::find_by_course_and_slug( (int) $course->id, $lesson_slug ) : null;
		if ( ! $lesson ) {
			return;
		}
		$this->add_node(
			$bar,
			__( 'Edit Lesson', 'learnomy' ),
			admin_url(
				'admin.php?page=learnomy-lesson-editor&course_id=' . (int) $course->id
				. '&lesson_id=' . (int) $lesson->id
			)
		);
	}

	private function quiz_node( \WP_Admin_Bar $bar ): void {
		// Course-attached quizzes carry their slug in learnomy_quiz_slug;
		// standalone quizzes use learnomy_slug.
		$slug = 'standalone-quiz' === (string) get_query_var( 'learnomy_route' )
			? sanitize_text_field( (string) get_query_var( 'learnomy_slug' ) )
			: sanitize_text_field( (string) get_query_var( 'learnomy_quiz_slug' ) );
		$quiz = '' !== $slug ? Quiz::find_by_slug( $slug ) : null;
		if ( ! $quiz || ! $this->can_edit_quiz( $quiz ) ) {
			return;
		}
		$this->add_node(
			$bar,
			__( 'Edit Quiz', 'learnomy' ),
			admin_url( 'admin.php?page=learnomy-edit-quiz&quiz_id=' . (int) $quiz->id )
		);
	}

	/**
	 * Whether the current user may edit a course — site admins, or the course
	 * author. Lessons and quizzes inherit their parent course's permission.
	 *
	 * @param object $course Course row.
	 */
	private function can_edit_course( object $course ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return get_current_user_id() === (int) ( $course->author_id ?? 0 );
	}

	/**
	 * Whether the current user may edit a quiz. Course quizzes defer to the
	 * parent course's author; standalone quizzes are admin-only.
	 *
	 * @param object $quiz Quiz row.
	 */
	private function can_edit_quiz( object $quiz ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$course_id = (int) ( $quiz->course_id ?? 0 );
		if ( $course_id > 0 ) {
			$course = Course::find( $course_id );
			return $course ? $this->can_edit_course( $course ) : false;
		}
		return false;
	}

	private function add_node( \WP_Admin_Bar $bar, string $title, string $href ): void {
		$bar->add_node(
			array(
				'id'    => 'learnomy-edit',
				'title' => $title,
				'href'  => $href,
				'meta'  => array( 'title' => $title ),
			)
		);
	}
}
