<?php
/**
 * Lesson player — FOCUS variant (immersive, distraction-free, default).
 *
 * The default lesson layout: a collapsible curriculum sidebar + a main
 * content column, rendered without the theme header/footer (the Template
 * Loader wraps this in the distraction-free shell). This file owns the
 * not-found / access-gate / admin-preview branches + the completion
 * hydration, then recomposes the shared partials:
 *   - partials/player-content.php — the interactive wrapper (sidebar +
 *     content + completion + resources + prev/next + comments). It in turn
 *     includes partials/curriculum-sidebar.php.
 *
 * The arrangement reproduces the original single-lesson template byte-for-byte.
 *
 * Variables available:
 *   $route       (string) 'lesson'
 *   $slug        (string) course slug
 *   $lesson_slug (string) lesson slug
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// Templates render outside any namespace context. Bare references like
// `Lesson::get_topics()` would fatal with "Class 'Lesson' not found".
// All model classes used below are imported here so the template stays
// readable. partials/curriculum.php uses the same pattern.
use Learnomy\Models\Course;
use Learnomy\Models\Lesson;

// $view_data variables (injected by Template_Loader via Single_Lesson_View_Data):
// $not_found, $course, $lesson, $user_id, $is_admin, $is_instructor,
// $is_enrolled, $can_access, $enrollment, $progress, $is_completed,
// $sections, $all_lessons, $completed_ids, $progress_pct,
// $current_section_id, $prev_lesson, $next_lesson, $prev_url, $next_url,
// $video_provider, $video_embed_id, $course_settings, $require_sequential,
// $locked_lesson_ids, $section_lessons_map, $lesson_attachments,
// $locked, $unlocks_at, $unlocks_at_iso

// The not-found verdict is made by the view-data resolver and honoured by
// Template_Loader BEFORE any output, so this guard is a safety net only (a
// third party swapping the resolver via `learnomy_view_data_resolvers`). It
// routes through the one shared renderer instead of repeating the incantation.
if ( ! empty( $not_found ) ) {
	\Learnomy\Template_Loader::render_not_found();
	return;
}

// Show access gate for non-enrolled, non-admin, non-instructor users.
if ( ! $can_access ) :
	$course_url  = \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );
	$catalog_url = \Learnomy\route_url( 'catalog' );

	// Gate copy is filterable so an integration can replace the default
	// "Enrollment Required" message — e.g. an enrolled member who must first
	// complete a profile field. A requirement reason (from
	// learnomy_lesson_access_requirements, surfaced as $access_requirement)
	// pre-fills the copy; the learnomy_lesson_access_gate filter is the final say.
	$lrn_gate        = array(
		'title'     => __( 'Enrollment Required', 'learnomy' ),
		/* translators: %s: course title */
		'message'   => sprintf( __( 'You need to enroll in "%s" to access this lesson.', 'learnomy' ), $course->title ),
		'cta_label' => __( 'View Course & Enroll', 'learnomy' ),
		'cta_url'   => $course_url,
	);
	$lrn_requirement = isset( $access_requirement ) && is_array( $access_requirement ) ? $access_requirement : array();
	if ( ! empty( $lrn_requirement ) ) {
		$lrn_gate = array_merge(
			$lrn_gate,
			array_filter(
				array(
					'title'     => (string) ( $lrn_requirement['title'] ?? '' ),
					'message'   => (string) ( $lrn_requirement['message'] ?? '' ),
					'cta_label' => (string) ( $lrn_requirement['cta_label'] ?? '' ),
					'cta_url'   => (string) ( $lrn_requirement['cta_url'] ?? '' ),
				),
				static fn( string $v ): bool => '' !== $v
			)
		);
	}
	/**
	 * Filter the lesson access-gate copy (title / message / cta_label / cta_url).
	 *
	 * @param array  $gate   Gate copy.
	 * @param object $lesson The lesson being gated.
	 * @param object $course Its course.
	 * @param int    $user_id The current viewer.
	 */
	$lrn_gate = (array) apply_filters( 'learnomy_lesson_access_gate', $lrn_gate, $lesson, $course, get_current_user_id() );
	?>
	<div class="lrn-access-gate-header">
		<a href="<?php echo esc_url( $catalog_url ); ?>" class="lrn-access-gate-header__brand">
			<?php echo esc_html( get_bloginfo( 'name' ) ); ?>
		</a>
		<nav class="lrn-access-gate-header__nav">
			<a href="<?php echo esc_url( $catalog_url ); ?>"><?php echo esc_html( \Learnomy\course_label( true ) ); ?></a>
			<a href="<?php echo esc_url( $course_url ); ?>"><?php echo esc_html( $course->title ); ?></a>
		</nav>
	</div>
	<main class="lrn-container lrn-access-gate" id="lrn-lesson-content">
		<div class="lrn-empty-state">
			<i data-lucide="lock" class="lrn-empty-state__icon" aria-hidden="true"></i>
			<h1 class="lrn-empty-state__title"><?php echo esc_html( (string) $lrn_gate['title'] ); ?></h1>
			<p class="lrn-empty-state__desc"><?php echo esc_html( (string) $lrn_gate['message'] ); ?></p>
			<?php if ( '' !== (string) $lrn_gate['cta_url'] && '' !== (string) $lrn_gate['cta_label'] ) : ?>
				<a href="<?php echo esc_url( (string) $lrn_gate['cta_url'] ); ?>" class="lrn-btn lrn-btn--primary">
					<?php echo esc_html( (string) $lrn_gate['cta_label'] ); ?>
				</a>
			<?php endif; ?>
			<?php if ( ! is_user_logged_in() ) : ?>
				<?php
				// An already-enrolled student who is simply logged out was being
				// steered toward re-enrolling. Offer a log-in path back to this
				// exact lesson instead.
				$lrn_lesson_login = wp_login_url(
					\Learnomy\route_url(
						'lesson',
						array(
							'slug'        => (string) $course->slug,
							'lesson_slug' => (string) ( $lesson->slug ?? '' ),
						)
					)
				);
				?>
				<p class="lrn-empty-state__alt">
					<?php
					printf(
						/* translators: %s: log in link */
						esc_html__( 'Already enrolled? %s', 'learnomy' ),
						'<a href="' . esc_url( $lrn_lesson_login ) . '">' . esc_html__( 'Log in', 'learnomy' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	</main>
	<?php
	return;
endif;

// $progress, $is_completed, $sections, $all_lessons, $completed_ids,
// $progress_pct, $current_section_id, $prev_lesson, $next_lesson,
// $prev_url, $next_url, $video_provider, $video_embed_id,
// $course_settings, $require_sequential, $locked_lesson_ids all come
// from Single_Lesson_View_Data (no duplicate computation here).

// Watch-before-complete threshold. Filterable so courses or site settings
// can override. 0 = no requirement. Value is a percentage (0-100).
$require_watch_pct = (int) apply_filters(
	'learnomy_lesson_require_watch_percent',
	0,
	$lesson,
	$course
);

// Initial completion-status hydration.
//
// We evaluate the per-lesson completion rules server-side so the progress
// strip renders correctly on first paint (no "manual → strip flashes" jank).
// The Interactivity store then reconciles with /completion-status on mount
// and every 10s. Non-enrolled previewers fall back to an empty manual shape.
$completion_status = array(
	'lesson_id'       => (int) $lesson->id,
	'completion_mode' => 'manual',
	'requirements'    => array(),
	'all_met'         => true,
	'locks_next'      => false,
	'is_completed'    => $is_completed,
	'video_pct'       => 0,
);

if ( $is_enrolled && $user_id ) {
	$progress_service  = new \Learnomy\Services\Progress_Service();
	$completion_status = $progress_service->evaluate_completion_rules( $user_id, (int) $lesson->id );
}

// Decorate requirements with a UI percent so the <progress> bar has a single
// bound value. Mirrors the JS decoration in progress-view.js.
$ui_requirements = array();
foreach ( $completion_status['requirements'] as $req ) {
	$ui_percent = ! empty( $req['met'] ) ? 100 : 0;
	if ( 'time' === ( $req['kind'] ?? '' ) && ! empty( $req['min_seconds'] ) ) {
		$ui_percent = min( 100, (int) round( ( ( $req['current_seconds'] ?? 0 ) / $req['min_seconds'] ) * 100 ) );
	} elseif ( 'video' === ( $req['kind'] ?? '' ) && ! empty( $req['min_pct'] ) ) {
		$ui_percent = min( 100, (int) round( ( ( $req['current_pct'] ?? 0 ) / $req['min_pct'] ) * 100 ) );
	}
	$req['uiPercent']  = $ui_percent;
	$ui_requirements[] = $req;
}

$completion_mode        = (string) $completion_status['completion_mode'];
$strip_visible          = ( 'manual' !== $completion_mode ) && ! empty( $ui_requirements );
$completion_all_met     = (bool) $completion_status['all_met'];
$completion_enforcement = isset( $completion_status['enforcement'] ) ? (string) $completion_status['enforcement'] : 'strict';

// Interactivity data-wp-context seed — the Mark Complete button reads this
// synchronously so it can render disabled on first paint for auto modes.
$progress_context = array(
	'lessonId'        => (int) $lesson->id,
	'courseId'        => (int) $course->id,
	'completionMode'  => $completion_mode,
	'enforcement'     => isset( $completion_status['enforcement'] ) ? (string) $completion_status['enforcement'] : 'strict',
	'requirements'    => $ui_requirements,
	'allMet'          => $completion_all_met,
	'lessonCompleted' => (bool) $completion_status['is_completed'],
	'locksNext'       => (bool) $completion_status['locks_next'],
);
?>
<?php
/**
 * Placement region above the lesson content (admin edit bar, sponsor, etc.).
 * Mirrors the embedded layout so the hook fires on both lesson variants.
 *
 * @param object $lesson Lesson row.
 * @param object $course Parent course row.
 */
do_action( 'learnomy_lesson_before_content', $lesson, $course );

// Admin / instructor preview notice — rendered inside the player wrapper
// (via $show_admin_preview) so it lines up with the lesson body column
// instead of floating at the page-level container width. The free-floating
// version was misaligned against the lesson content below it on every
// non-default container width (Basecamp #9925339019).
$show_admin_preview = ( $is_admin || $is_instructor ) && ! $is_enrolled;
?>

<?php require \Learnomy\template_path( 'lesson/partials/player-content.php' ); ?>
