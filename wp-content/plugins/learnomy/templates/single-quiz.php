<?php
/**
 * Quiz template router — runs the pre-start gate (empty / access_code /
 * open_at / close_at / cooldown / lockout) first, then dispatches to the
 * appropriate template based on the quiz's display_template setting.
 *
 * Gate order is deliberate and mirrors Quiz_Service::start_attempt() so a
 * student can never see a template that the service would immediately
 * reject. Each state renders templates/partials/quiz-gate.php and returns;
 * a full pass falls through to templates/quiz/{slug}.php.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// $view_data variables (injected by Template_Loader via Single_Quiz_View_Data):
// $quiz, $resolved_slug, $user_id, $is_privileged, $failed_count,
// $latest_attempt, $code_verified_meta

// Missing quiz + unpublished-quiz-for-a-public-viewer are both decided by
// Single_Quiz_View_Data and honoured by Template_Loader BEFORE any output, so
// this template only ever runs for a quiz the viewer may see. The guard stays
// as a safety net for a third party that swaps the resolver via
// `learnomy_view_data_resolvers`; it routes through the one shared renderer
// rather than repeating the set_404 + status_header incantation.
if ( empty( $quiz ) || ( 'published' !== ( $quiz->status ?? 'draft' ) && ! $is_privileged ) ) {
	\Learnomy\Template_Loader::render_not_found();
	return;
}

// Gate 2 — a quiz with no questions is not startable by ANYONE, so unlike
// the student gates below this one applies to anonymous visitors and
// privileged previewers too. Without it the player rendered a blank pane
// with a live Submit that graded the empty sitting to 0% "you did not
// reach the passing score" (1.8.0 combo smoke). Mirrors the same guard at
// the top of Quiz_Service::start_attempt().
if ( 0 === (int) ( $question_count ?? 0 ) ) {
	$gate_state = 'empty';
	$gate_path  = \Learnomy\template_path( 'partials/quiz-gate.php' );
	if ( file_exists( $gate_path ) ) {
		include $gate_path;
	}
	return;
}

// Privileged users (admins/instructors) bypass the student-facing gates so
// they can preview the template without fighting the access code etc.
if ( ! $is_privileged && $user_id ) {
	$gate_state          = null;
	$opens_at_formatted  = '';
	$closed_at_formatted = '';
	$seconds_remaining   = 0;
	$max_failed          = 0;
	$gate_error          = '';

	// UTC, to match strtotime( $quiz->open_at/close_at ) below (which parses in
	// WP's UTC default) and Quiz_Service's own gate. current_time('timestamp')
	// returned a local-offset value, so on any non-UTC site the open/close gate
	// was wrong by the offset — and the cooldown check at line 80 already used
	// time(), so the template disagreed with itself.
	$now = time();

	// Gate 2b — the lesson this quiz is bound to is still locked for them.
	// Runs before the quiz's own schedule because it is the broader statement:
	// there is no point telling a student a quiz opens on Tuesday when they
	// cannot reach the lesson it belongs to at all. Same predicate the server
	// gate uses, so the page and Quiz_Service cannot disagree.
	if ( ! $gate_state && \Learnomy\Services\Quiz_Service::lesson_lock_applies( $quiz, (int) $user_id ) ) {
		$gate_state          = 'lesson_locked';
		$locked_lesson       = \Learnomy\Models\Lesson::find( (int) $quiz->lesson_id );
		$locked_lesson_title = $locked_lesson->title ?? '';
		$locked_course       = $locked_lesson ? \Learnomy\Models\Course::find( (int) $locked_lesson->course_id ) : null;
		$locked_course_url   = $locked_course
			? \Learnomy\route_url( 'course', array( 'slug' => (string) $locked_course->slug ) )
			: '';
	}

	// Gate 3 — open_at in the future.
	if ( ! $gate_state && ! empty( $quiz->open_at ) ) {
		$open_ts = strtotime( $quiz->open_at );
		if ( $open_ts && $open_ts > $now ) {
			$gate_state         = 'scheduled';
			$opens_at_formatted = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $open_ts );
		}
	}

	// Gate 4 — close_at in the past.
	if ( ! $gate_state && ! empty( $quiz->close_at ) ) {
		$close_ts = strtotime( $quiz->close_at );
		if ( $close_ts && $close_ts < $now ) {
			$gate_state          = 'closed';
			$closed_at_formatted = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) . ' T', $close_ts );
		}
	}

	// Gate 5 — max_failed_attempts reached (graded failing attempts only).
	$max_failed_setting = (int) ( $quiz->max_failed_attempts ?? 0 );
	if ( ! $gate_state && $max_failed_setting > 0 ) {
		if ( $failed_count >= $max_failed_setting ) {
			$gate_state = 'locked';
			$max_failed = $max_failed_setting;
		}
	}

	// Gate 6 — cooldown window active on last submission.
	$cooldown = (int) ( $quiz->attempt_cooldown_seconds ?? 0 );
	if ( ! $gate_state && $cooldown > 0 && $latest_attempt && ! empty( $latest_attempt->submitted_at ) ) {
		$elapsed = time() - strtotime( $latest_attempt->submitted_at );
		if ( $elapsed < $cooldown ) {
			$gate_state        = 'cooldown';
			$seconds_remaining = $cooldown - $elapsed;
		}
	}

	// Gate 7 — access code required + not yet validated by this user.
	if ( ! $gate_state && ! empty( $quiz->access_code ) && ! $code_verified_meta ) {
		$gate_state = 'code';
	}

	// Render the gate and stop before the display template kicks in.
	if ( $gate_state ) {
		$gate_path = \Learnomy\template_path( 'partials/quiz-gate.php' );
		if ( file_exists( $gate_path ) ) {
			include $gate_path;
		}
		return;
	}
}

$template_slug = $quiz->display_template ?? 'focused';

$allowed = apply_filters(
	'learnomy_quiz_templates',
	[
		'focused',
		'classic',
		'conversational',
		'sectioned',
		'review-board',
	]
);

if ( ! in_array( $template_slug, $allowed, true ) ) {
	$template_slug = 'focused';
}

// Pro template gating.
$is_pro         = apply_filters( 'learnomy_is_pro', false );
$free_templates = [ 'focused', 'classic' ];
if ( ! $is_pro && ! in_array( $template_slug, $free_templates, true ) ) {
	$template_slug = 'focused';
}

// Expose the resolved quiz so downstream templates don't redo find_by_slug().
$quiz_obj = $quiz;

/**
 * In-content edit affordance, mirroring the course/lesson before-content hooks.
 * The Admin_Bar renderer prints a frontend "Edit Quiz" bar here (editors only,
 * and only when a frontend quiz editor exists). One hook covers every quiz
 * style template since they all load through this wrapper.
 *
 * @param object $quiz The quiz being viewed.
 */
do_action( 'learnomy_quiz_before_content', $quiz );

// One resolver for the whole product: theme override then plugin default, via
// the same template_path()/locate() every other template uses — no second
// hand-rolled override lookup here. Falls back to the focused layout when the
// requested quiz style is unknown.
$resolved = \Learnomy\template_path( 'quiz/' . $template_slug . '.php' );
if ( '' === $resolved ) {
	$resolved = \Learnomy\template_path( 'quiz/focused.php' );
}
require $resolved;
