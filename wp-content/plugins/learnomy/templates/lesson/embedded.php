<?php
/**
 * Lesson — EMBEDDED variant: a purpose-built TWO-COLUMN sidebar layout.
 *
 * This is NOT the collapsible-toggle player (that is the FOCUS variant). It is
 * a wide main reading column on the left + a PERSISTENT (never collapsing)
 * right rail with the course-lessons navigation. It declares chrome=theme, so
 * the Template Loader wraps it in get_header() / get_footer() automatically —
 * this file emits NO <html> / wp_head; it renders inside the active theme.
 *
 * Main column order: pre-roll surface → video (reused media block) → hidden
 * pause surface → below-video surface → lesson meta (breadcrumb, title,
 * instructor, duration / release / section) → Editor.js body → mark-complete
 * (reused completion block) → completion surface → prev/next nav.
 *
 * Right rail: sidebar-ad surface → "Course Lessons" persistent nav (sections →
 * lessons → topics with done / active / locked states + duration).
 *
 * Media + completion are byte-shared with the FOCUS player via the extracted
 * partials/media.php + partials/completion.php so the Plyr bindings and the
 * `learnomy/progress` store keep working identically.
 *
 * Variables available (injected by Template_Loader via Single_Lesson_View_Data):
 *   $route, $slug, $lesson_slug, $not_found, $course, $lesson, $user_id,
 *   $is_admin, $is_instructor, $is_enrolled, $can_access, $enrollment,
 *   $progress, $is_completed, $sections, $all_lessons, $completed_ids,
 *   $progress_pct, $current_section_id, $prev_lesson, $next_lesson, $prev_url,
 *   $next_url, $video_provider, $video_embed_id, $course_settings,
 *   $require_sequential, $locked_lesson_ids, $section_lessons_map,
 *   $lesson_topics_map, $lesson_attachments, $locked, $unlocks_at,
 *   $unlocks_at_iso, $instructor, $instructor_url, $category, $category_url,
 *   $section_name, $duration_label, $release_label, $lesson_total, $lesson_done
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// Bare `Lesson::get_topics()` references in the sidebar nav resolve via this
// import — `use` statements do not carry over into the included partials, and
// this template renders the curriculum nav inline (not via the collapsible
// curriculum-sidebar.php), so it needs the import itself.
use Learnomy\Models\Lesson;

// The not-found verdict is made by the view-data resolver and honoured by
// Template_Loader BEFORE any output, so this guard is a safety net only (a
// third party swapping the resolver via `learnomy_view_data_resolvers`). It
// routes through the one shared renderer instead of repeating the incantation.
if ( ! empty( $not_found ) ) {
	\Learnomy\Template_Loader::render_not_found();
	return;
}

// Access gate for non-enrolled, non-admin, non-instructor users. Rendered
// inside the theme (no standalone brand header — the theme already provides
// the site chrome), so this is the in-theme mirror of the FOCUS gate.
if ( ! $can_access ) :
	$course_url = \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );

	// Filterable gate copy — in-theme mirror of the FOCUS gate. A requirement
	// reason (learnomy_lesson_access_requirements / _course_access_requirements,
	// surfaced as $access_requirement) pre-fills the copy so an enrolled member
	// who must complete a profile field sees the right prompt, not "Enroll".
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
	/** Documented in templates/lesson/focus.php. */
	$lrn_gate = (array) apply_filters( 'learnomy_lesson_access_gate', $lrn_gate, $lesson, $course, get_current_user_id() );
	?>
	<main class="lrn-container lrn-access-gate lrn-lesson--embedded" id="lrn-lesson-content">
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
				// An already-enrolled student who is logged out should be offered
				// a log-in path back to this lesson, not steered toward re-enrolling.
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

// Completion-status hydration — identical to the FOCUS variant. The completion
// partial reads $require_watch_pct / $strip_visible / $ui_requirements /
// $completion_all_met on first paint, so they must be computed before it runs.
$require_watch_pct = (int) apply_filters(
	'learnomy_lesson_require_watch_percent',
	0,
	$lesson,
	$course
);

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

// Prev/next lock state — mirrors the player's nav gate.
$locks_next     = ! empty( $completion_status['locks_next'] ) && empty( $completion_status['is_completed'] );
$bypass_locks   = $is_admin || $is_instructor;
$next_is_locked = $locks_next && ! $bypass_locks;
?>

<div class="lrn-container lrn-lesson lrn-lesson--embedded">

	<?php
	// Admin / instructor preview notice — Basecamp #9925339019. Rendered
	// inside the embedded wrap (above) so it inherits the lesson layout's
	// width and aligns with the content column below.
	if ( ( $is_admin || $is_instructor ) && ! $is_enrolled ) :
		?>
		<div class="lrn-admin-preview-bar">
			<i data-lucide="eye" aria-hidden="true"></i>
			<?php if ( $is_admin ) : ?>
				<?php printf( esc_html( /* translators: %s: singular label for a student (e.g. "Learner"). */ __( 'Admin preview. You are viewing this lesson without enrollment. %s data is not affected.', 'learnomy' ) ), esc_html( \Learnomy\student_label() ) ); ?>
			<?php else : ?>
				<?php printf( esc_html( /* translators: 1: singular instructor label, 2: plural student label. */ __( '%1$s preview. This is your course. %2$s see the same content.', 'learnomy' ) ), esc_html( \Learnomy\instructor_label() ), esc_html( \Learnomy\student_label( true ) ) ); ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php
	/**
	 * Placement region above the embedded lesson layout. Zero output unless
	 * a listener is attached.
	 *
	 * @param object $lesson Current lesson row.
	 * @param object $course Parent course row.
	 */
	do_action( 'learnomy_lesson_before_content', $lesson, $course );
	?>

	<div class="lrn-lesson-layout">

		<!-- Main reading column -->
		<main class="lrn-lesson-main" id="lrn-lesson-content"
				aria-label="<?php esc_attr_e( 'Lesson content', 'learnomy' ); ?>"
				data-wp-interactive="learnomy/progress"
				data-wp-context='<?php echo esc_attr( wp_json_encode( $progress_context ) ); ?>'
				data-wp-init="callbacks.onInit">

			<?php
			// Drip schedule — locked lesson card stops the rest of the column.
			if ( ! empty( $locked ) ) :
				$lrn_locked_partial = \Learnomy\template_path( 'partials/lesson-locked.php' );
				if ( file_exists( $lrn_locked_partial ) ) {
					include $lrn_locked_partial;
				}
				?>
			</main>
		</div><!-- .lrn-lesson-layout -->
				<?php
				do_action( 'learnomy_lesson_after_content', $lesson, $course );
				?>
	</div><!-- .lrn-lesson--embedded -->
				<?php
				return;
			endif;
			?>

			<?php
			// Surface A — pre-roll sponsor surface, before the video. Only
			// paints when a listener emits content (buffered wrapper).
			$lrn_surface_hook  = 'learnomy_lesson_preroll';
			$lrn_surface_class = 'lrn-lesson-preroll-surface';
			require \Learnomy\template_path( 'lesson/partials/surface.php' );

			// Video / media block — reused verbatim from the player so Plyr +
			// watch-tracking behave identically. Sets $content_type/$media_url.
			require \Learnomy\template_path( 'lesson/partials/media.php' );
			?>

			<?php
			/**
			 * Surface C — pause overlay. Always rendered, but hidden; the JS
			 * reveal on video pause is a follow-up. Hidden means it never
			 * paints, so an empty wrapper is harmless. Zero visible output.
			 *
			 * @param object $lesson Current lesson row.
			 */
			?>
			<div class="lrn-lesson-pause-surface" hidden>
				<?php do_action( 'learnomy_lesson_pause', $lesson ); ?>
			</div>

			<?php
			// Surface D — below-video sponsor banner, directly under the media.
			// Only paints when a listener emits content.
			$lrn_surface_hook  = 'learnomy_lesson_below_video';
			$lrn_surface_class = 'lrn-lesson-below-video-surface';
			require \Learnomy\template_path( 'lesson/partials/surface.php' );
			?>

			<!-- Lesson meta -->
			<header class="lrn-lesson-meta">
				<?php if ( $category && $category_url ) : ?>
					<nav class="lrn-lesson-meta__breadcrumb" aria-label="<?php esc_attr_e( 'Lesson breadcrumb', 'learnomy' ); ?>">
						<a href="<?php echo esc_url( $category_url ); ?>"><?php echo esc_html( $category->name ?? $category->title ?? '' ); ?></a>
						<?php if ( '' !== $section_name ) : ?>
							<span class="lrn-lesson-meta__crumb-sep" aria-hidden="true">/</span>
							<span class="lrn-lesson-meta__crumb-current"><?php echo esc_html( $section_name ); ?></span>
						<?php endif; ?>
					</nav>
				<?php elseif ( '' !== $section_name ) : ?>
					<nav class="lrn-lesson-meta__breadcrumb" aria-label="<?php esc_attr_e( 'Lesson breadcrumb', 'learnomy' ); ?>">
						<span class="lrn-lesson-meta__crumb-current"><?php echo esc_html( $section_name ); ?></span>
					</nav>
				<?php endif; ?>

				<h1 class="lrn-lesson-meta__title"><?php echo esc_html( $lesson->title ); ?></h1>

				<?php if ( $instructor || '' !== $duration_label || '' !== $release_label || '' !== $section_name ) : ?>
					<div class="lrn-lesson-meta__byline">
						<?php if ( $instructor ) : ?>
							<span class="lrn-lesson-meta__instructor">
								<?php echo get_avatar( (int) $instructor->ID, 28, '', '', array( 'class' => 'lrn-lesson-meta__avatar' ) ); ?>
								<span class="lrn-lesson-meta__instructor-text">
									<?php esc_html_e( 'Taught by', 'learnomy' ); ?>
									<?php if ( $instructor_url ) : ?>
										<a href="<?php echo esc_url( $instructor_url ); ?>" class="lrn-lesson-meta__instructor-name"><?php echo esc_html( $instructor->display_name ); ?></a>
									<?php else : ?>
										<span class="lrn-lesson-meta__instructor-name"><?php echo esc_html( $instructor->display_name ); ?></span>
									<?php endif; ?>
								</span>
							</span>
						<?php endif; ?>
						<?php if ( '' !== $duration_label ) : ?>
							<span class="lrn-lesson-meta__fact">
								<i data-lucide="clock" aria-hidden="true"></i>
								<span><?php echo esc_html( $duration_label ); ?></span>
							</span>
						<?php endif; ?>
						<?php if ( '' !== $release_label ) : ?>
							<span class="lrn-lesson-meta__fact">
								<i data-lucide="calendar" aria-hidden="true"></i>
								<span><?php echo esc_html( $release_label ); ?></span>
							</span>
						<?php endif; ?>
						<?php if ( '' !== $section_name ) : ?>
							<span class="lrn-lesson-meta__fact">
								<i data-lucide="layers" aria-hidden="true"></i>
								<span><?php echo esc_html( $section_name ); ?></span>
							</span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</header>

			<?php if ( ! empty( $lesson->content ) ) : ?>
				<div class="lrn-player-content__body">
					<?php echo \Learnomy\render_editorjs_content( $lesson->content, $lesson->title ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_editorjs_content uses wp_kses_post internally. ?>
				</div>
			<?php elseif ( 'text' === $content_type ) : ?>
				<div class="lrn-player-content__body lrn-player-content__body--empty">
					<div class="lrn-empty-state">
						<?php if ( $is_admin || $is_instructor ) : ?>
							<svg class="lrn-empty-state__icon" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" x2="8" y1="13" y2="13"/><line x1="16" x2="8" y1="17" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
							<p class="lrn-empty-state__title"><?php esc_html_e( 'No lesson content yet', 'learnomy' ); ?></p>
							<p class="lrn-empty-state__desc"><?php esc_html_e( 'Open the lesson editor to add content.', 'learnomy' ); ?></p>
						<?php else : ?>
							<svg class="lrn-empty-state__icon" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
							<p class="lrn-empty-state__title"><?php esc_html_e( 'Content coming soon', 'learnomy' ); ?></p>
							<p class="lrn-empty-state__desc"><?php esc_html_e( 'Your instructor has not added content to this lesson yet. Check back later.', 'learnomy' ); ?></p>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php
			/**
			 * Allow Pro modules to render auxiliary tools alongside the lesson
			 * (e.g. notes panel, bookmark toggle).
			 *
			 * @param object $lesson      Lesson row.
			 * @param bool   $is_enrolled True when the current viewer has an active enrollment.
			 */
			do_action( 'learnomy_lesson_player_aside', $lesson, $is_enrolled );
			?>

			<?php
			// Mark-complete + completion strip + toast — reused verbatim from
			// the player so the `learnomy/progress` store bindings are identical.
			require \Learnomy\template_path( 'lesson/partials/completion.php' );
			?>

			<?php
			// Surface F — post-roll / completion sponsor surface, after the
			// mark-complete control. Only paints when a listener emits content.
			$lrn_surface_hook  = 'learnomy_lesson_completion';
			$lrn_surface_class = 'lrn-lesson-completion-surface';
			require \Learnomy\template_path( 'lesson/partials/surface.php' );
			?>

			<?php
			// Resources — downloadable attachments. Reused from the player.
			if ( ! empty( $lesson_attachments ) ) :
				?>
				<section class="lrn-lesson-resources" aria-labelledby="lrn-lesson-resources-heading">
					<h3 id="lrn-lesson-resources-heading" class="lrn-lesson-resources__heading">
						<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 17.93 8.8l-8.58 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
						<?php esc_html_e( 'Resources', 'learnomy' ); ?>
					</h3>
					<ul class="lrn-lesson-resources__list">
						<?php
						foreach ( $lesson_attachments as $attachment ) :
							// Gated delivery route, resolved by Single_Lesson_View_Data.
							$attachment_url = (string) ( $attachment->url ?? '' );
							if ( '' === $attachment_url ) {
								continue;
							}
							?>
							<li class="lrn-lesson-resources__item">
								<a class="lrn-lesson-resources__link"
									href="<?php echo esc_url( $attachment_url ); ?>"
									download="<?php echo esc_attr( $attachment->filename ); ?>"
									rel="noopener">
									<svg class="lrn-lesson-resources__icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 17.93 8.8l-8.58 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
									<span class="lrn-lesson-resources__name"><?php echo esc_html( $attachment->filename ); ?></span>
									<?php if ( ! empty( $attachment->size_bytes ) ) : ?>
										<span class="lrn-lesson-resources__size"><?php echo esc_html( size_format( (int) $attachment->size_bytes ) ); ?></span>
									<?php endif; ?>
									<svg class="lrn-lesson-resources__download" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $attached_quiz ) ) : ?>
				<aside class="lrn-lesson-quiz-cta" aria-label="<?php esc_attr_e( 'Lesson quiz', 'learnomy' ); ?>">
					<div class="lrn-lesson-quiz-cta__body">
						<p class="lrn-lesson-quiz-cta__label"><?php esc_html_e( 'Lesson quiz', 'learnomy' ); ?></p>
						<h3 class="lrn-lesson-quiz-cta__title"><?php echo esc_html( $attached_quiz->title ); ?></h3>
					</div>
					<a class="lrn-btn lrn-btn--primary lrn-lesson-quiz-cta__cta" href="<?php echo esc_url( $attached_quiz_url ); ?>">
						<?php esc_html_e( 'Take quiz', 'learnomy' ); ?>
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
					</a>
				</aside>
			<?php endif; ?>

			<!-- Prev / next lesson navigation -->
			<nav class="lrn-lesson-nav" aria-label="<?php esc_attr_e( 'Lesson navigation', 'learnomy' ); ?>">
				<?php if ( $prev_url ) : ?>
					<a href="<?php echo esc_url( $prev_url ); ?>" class="lrn-lesson-nav__prev">
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
						<span>
							<small><?php esc_html_e( 'Previous', 'learnomy' ); ?></small>
							<strong><?php echo esc_html( $prev_lesson->title ); ?></strong>
						</span>
					</a>
				<?php else : ?>
					<span class="lrn-lesson-nav__spacer"></span>
				<?php endif; ?>

				<?php if ( $next_url && $next_is_locked ) : ?>
					<button type="button"
							class="lrn-lesson-nav__next is-locked"
							disabled
							aria-disabled="true"
							title="<?php esc_attr_e( 'Complete this lesson first.', 'learnomy' ); ?>">
						<span>
							<small><?php esc_html_e( 'Next', 'learnomy' ); ?></small>
							<strong><?php echo esc_html( $next_lesson->title ); ?></strong>
						</span>
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
					</button>
				<?php elseif ( $next_url ) : ?>
					<a href="<?php echo esc_url( $next_url ); ?>" class="lrn-lesson-nav__next">
						<span>
							<small><?php esc_html_e( 'Next', 'learnomy' ); ?></small>
							<strong><?php echo esc_html( $next_lesson->title ); ?></strong>
						</span>
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
					</a>
				<?php elseif ( $next_is_locked ) : ?>
					<button type="button"
							class="lrn-lesson-nav__next is-locked"
							disabled
							aria-disabled="true"
							title="<?php esc_attr_e( 'Complete this lesson first.', 'learnomy' ); ?>">
						<span>
							<small><?php esc_html_e( 'Finished', 'learnomy' ); ?></small>
							<?php esc_html_e( 'Back to Course', 'learnomy' ); ?>
						</span>
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
					</button>
				<?php else : ?>
					<a href="<?php echo esc_url( \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) ) ); ?>" class="lrn-lesson-nav__next">
						<span>
							<small><?php esc_html_e( 'Finished', 'learnomy' ); ?></small>
							<?php esc_html_e( 'Back to Course', 'learnomy' ); ?>
						</span>
						<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
					</a>
				<?php endif; ?>
			</nav>

		</main><!-- .lrn-lesson-main -->

		<!-- Persistent right rail — course navigation -->
		<aside class="lrn-lesson-side" aria-label="<?php esc_attr_e( 'Course navigation', 'learnomy' ); ?>">

			<?php
			// Surface E — sidebar sponsor widget, top of the right rail. Only
			// paints when a listener emits content.
			$lrn_surface_hook  = 'learnomy_lesson_sidebar_ad';
			$lrn_surface_class = 'lrn-lesson-sidebar-ad-surface';
			require \Learnomy\template_path( 'lesson/partials/surface.php' );
			?>

			<div class="lrn-lesson-side__nav">
				<div class="lrn-lesson-side__header">
					<a href="<?php echo esc_url( \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) ) ); ?>" class="lrn-lesson-side__title">
						<?php esc_html_e( 'Course Lessons', 'learnomy' ); ?>
					</a>
					<?php if ( $user_id && $lesson_total > 0 ) : ?>
						<span class="lrn-lesson-side__progress">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: completed lessons, 2: total lessons */
									__( '%1$d/%2$d complete', 'learnomy' ),
									$lesson_done,
									$lesson_total
								)
							);
							?>
						</span>
					<?php endif; ?>

					<?php
					/**
					 * Top of the lesson curriculum sidebar (e.g. resume CTA).
					 * Kept alongside the new sidebar-ad surface for parity with
					 * the focus player's curriculum sidebar hooks.
					 *
					 * @param object $lesson Current lesson row.
					 * @param object $course Parent course row.
					 */
					do_action( 'learnomy_lesson_sidebar_top', $lesson, $course );
					?>
				</div>

				<nav class="lrn-lesson-side__list" aria-label="<?php esc_attr_e( 'Course curriculum', 'learnomy' ); ?>">
					<?php if ( ! empty( $sections ) ) : ?>
						<?php
						$lrn_lesson_number = 0;
						// Prepend an "Ungrouped" pseudo-section (id=0, populated in
						// the view-data) so section-less lessons are navigable
						// alongside grouped ones. Local copy leaves $sections intact
						// (Basecamp #10009374408).
						$lrn_side_sections = $sections;
						if ( ! empty( $section_lessons_map[0] ) ) {
							array_unshift(
								$lrn_side_sections,
								(object) array(
									'id'    => 0,
									'title' => __( 'Ungrouped', 'learnomy' ),
								)
							);
						}
						foreach ( $lrn_side_sections as $section ) :
							$section_lessons = $section_lessons_map[ (int) $section->id ] ?? array();
							?>
							<div class="lrn-lesson-side__section">
								<p class="lrn-lesson-side__section-name"><?php echo esc_html( $section->title ); ?></p>
								<ul class="lrn-lesson-side__items">
									<?php
									foreach ( $section_lessons as $sl ) :
										++$lrn_lesson_number;
										$sl_url     = \Learnomy\route_url(
											'lesson',
											array(
												'slug' => (string) $course->slug,
												'lesson_slug' => (string) $sl->slug,
											)
										);
										$sl_done    = isset( $completed_ids[ (int) $sl->id ] );
										$sl_current = ( (int) $sl->id === (int) $lesson->id );
										$sl_drip    = ! empty( $sl->drip_locked );
										$sl_locked  = ( isset( $locked_lesson_ids[ (int) $sl->id ] ) || $sl_drip ) && ! $sl_current;
										include \Learnomy\template_path( 'lesson/partials/side-nav-item.php' );

										// Topics nested under the lesson.
										$topics = $lesson_topics_map[ (int) $sl->id ] ?? Lesson::get_topics( (int) $sl->id );
										if ( $topics ) :
											foreach ( $topics as $topic ) :
												$sl_url     = \Learnomy\route_url(
													'lesson',
													array(
														'slug' => (string) $course->slug,
														'lesson_slug' => (string) $topic->slug,
													)
												);
												$sl_done    = isset( $completed_ids[ (int) $topic->id ] );
												$sl_current = ( (int) $topic->id === (int) $lesson->id );
												$sl_drip    = ! empty( $topic->drip_locked );
												$sl_locked  = ( isset( $locked_lesson_ids[ (int) $topic->id ] ) || $sl_drip ) && ! $sl_current;
												$sl         = $topic;
												$lrn_is_sub = true;
												include \Learnomy\template_path( 'lesson/partials/side-nav-item.php' );
												$lrn_is_sub = false;
											endforeach;
										endif;
									endforeach;
									?>
								</ul>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<ul class="lrn-lesson-side__items lrn-lesson-side__items--flat">
							<?php
							$lrn_lesson_number = 0;
							foreach ( $all_lessons as $sl ) :
								++$lrn_lesson_number;
								$sl_url     = \Learnomy\route_url(
									'lesson',
									array(
										'slug'        => (string) $course->slug,
										'lesson_slug' => (string) $sl->slug,
									)
								);
								$sl_done    = isset( $completed_ids[ (int) $sl->id ] );
								$sl_current = ( (int) $sl->id === (int) $lesson->id );
								$sl_drip    = ! empty( $sl->drip_locked );
								$sl_locked  = ( isset( $locked_lesson_ids[ (int) $sl->id ] ) || $sl_drip ) && ! $sl_current;
								include \Learnomy\template_path( 'lesson/partials/side-nav-item.php' );
							endforeach;
							?>
						</ul>
					<?php endif; ?>
				</nav>

				<?php
				// Progress belongs to a viewer who HAS an enrollment to make it
				// against. A free-preview lesson is reachable with no account at
				// all, and "0% complete" there described a tracker that was not
				// tracking anything to someone who had nothing to track
				// (Basecamp 10143833815). In-theme mirror of the FOCUS footer.
				?>
				<div class="lrn-lesson-side__footer">
					<?php if ( ! empty( $is_enrolled ) ) : ?>
						<div class="lrn-progress-bar">
							<div class="lrn-progress-bar__fill" style="width: <?php echo esc_attr( (string) $progress_pct ); ?>%"></div>
						</div>
					<?php endif; ?>
					<span class="lrn-lesson-side__footer-text">
						<?php
						if ( ! empty( $is_enrolled ) ) {
							/* translators: %d: progress percentage */
							echo esc_html( sprintf( __( '%d%% complete', 'learnomy' ), $progress_pct ) );
						} else {
							printf(
								'<a href="%1$s">%2$s</a>',
								esc_url( \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) ) ),
								esc_html__( 'Enroll to track your progress', 'learnomy' )
							);
						}

						/**
						 * Bottom of the lesson curriculum sidebar.
						 *
						 * @param object $lesson Current lesson row.
						 * @param object $course Parent course row.
						 */
						do_action( 'learnomy_lesson_sidebar_bottom', $lesson, $course );
						?>
					</span>
				</div>
			</div>
		</aside><!-- .lrn-lesson-side -->

	</div><!-- .lrn-lesson-layout -->

	<?php
	do_action( 'learnomy_lesson_after_content', $lesson, $course );
	?>

</div><!-- .lrn-lesson--embedded -->
