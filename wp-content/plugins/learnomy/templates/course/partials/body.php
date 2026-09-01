<?php
/**
 * Single course — main content column (shared partial).
 *
 * Extracted byte-identical from course/classic.php. Renders the full main
 * column for BOTH the enrolled (learning) view and the non-enrolled (sales)
 * view: curriculum, progress / completion, instructor, reviews, outcomes,
 * comments, Q&A. Preserves every existing include (curriculum.php,
 * curriculum-drawer.php, review-form.php, review-card.php,
 * course-completion.php, progress-bar.php).
 *
 * Inherits the single-course template scope ($course, $is_enrolled, $user_id,
 * $enrollment, $instructor, $sections, $reviews, $course_settings, ...). The
 * caller wraps this in its own <main> element so each variant can class it.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// Mobile curriculum drawer — a slide-up bottom sheet (toggle is
// CSS-shown only below 640px). Backed by the learnomy/curriculum
// Interactivity store; reuses the curriculum partial inside.
require \Learnomy\template_path( 'partials/curriculum-drawer.php' );
?>

<?php if ( $is_enrolled ) : ?>
	<!-- ========== ENROLLED VIEW ========== -->

	<?php
	// Surface B (W6) — read the full settings map once so the
	// welcome banner, announcements banner, and completion
	// screen below share a single read. Also handle the
	// "?lrn_dismiss_welcome=1" round-trip before the banner
	// checks its usermeta flag so a dismissal click on the same
	// request hides the banner immediately.
	// $course_settings is supplied by Single_Course_View_Data;
	// templates render with zero model logic per CLAUDE.md.
	$welcome_usermeta = 'learnomy_welcomed_' . (int) $course->id;
	$welcome_message  = isset( $course_settings['welcome_message'] ) ? (string) $course_settings['welcome_message'] : '';

	$welcome_dismissed_this_request = false;
	if (
		! empty( $_GET['lrn_dismiss_welcome'] )
		&& isset( $_GET['_wpnonce'] )
		&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'lrn_dismiss_welcome_' . (int) $course->id )
		// Capability gate: any logged-in subscriber dismissing their own
		// welcome banner — 'read' is WP's baseline cap for registered users.
		// Pairs with nonce + per-user meta scope so the action is bound
		// to the current user.
		&& current_user_can( 'read' )
	) {
		update_user_meta( $user_id, $welcome_usermeta, '1' );
		$welcome_dismissed_this_request = true;
	}

	$has_welcome_content = \Learnomy\editorjs_has_content( $welcome_message );
	$has_seen_welcome    = $has_welcome_content
		&& ( '1' === (string) get_user_meta( $user_id, $welcome_usermeta, true ) );
	$show_welcome        = $has_welcome_content && ! $has_seen_welcome && ! $welcome_dismissed_this_request;

	// Mark as seen on first render so subsequent reloads skip
	// the banner even without an explicit dismiss click.
	if ( $show_welcome ) {
		update_user_meta( $user_id, $welcome_usermeta, '1' );
	}

	// Announcements visibility (the row itself comes from $latest_announcement
	// in $view_data — Single_Course_View_Data already pre-fetched it).
	$show_announcements = ! empty( $course_settings['announcements_enabled'] );
	if ( ! $show_announcements ) {
		$latest_announcement = null;
	}

	// $is_course_complete is supplied by Single_Course_View_Data
	// (computed once, also used to gate the active-certificate
	// pre-fetch). Templates have zero logic per CLAUDE.md.
	?>

	<?php
	// Root per breadcrumb_root(); the Courses crumb is appended only for
	// logged-in readers because the anonymous root already IS the catalog.
	// (This is the enrolled body, so in practice the reader is logged in.)
	$breadcrumb_items   = [ \Learnomy\breadcrumb_root() ];
	$breadcrumb_items[] = [
		'label' => $course->title,
	];
	include \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-badge lrn-badge--green"><?php esc_html_e( 'Enrolled', 'learnomy' ); ?></div>
	<h1 class="lrn-single-course__title"><?php echo esc_html( $course->title ); ?></h1>

	<?php if ( $show_welcome ) : ?>
		<?php
		$dismiss_url = add_query_arg(
			array(
				'lrn_dismiss_welcome' => 1,
				'_wpnonce'            => wp_create_nonce( 'lrn_dismiss_welcome_' . (int) $course->id ),
			)
		);
		?>
		<aside class="lrn-banner lrn-banner--welcome" role="note" aria-labelledby="lrn-welcome-title" data-lrn-banner="welcome">
			<div class="lrn-banner__icon" aria-hidden="true">
				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
					<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>
				</svg>
			</div>
			<div class="lrn-banner__body">
				<h2 id="lrn-welcome-title" class="lrn-banner__title">
					<?php esc_html_e( 'Welcome', 'learnomy' ); ?>
				</h2>
				<div class="lrn-banner__message">
					<?php
					// Welcome message authored in the shared block editor — render
					// Editor.js JSON (and legacy HTML transparently). Escaping is
					// handled inside render_editorjs_content() via wp_kses_post.
					echo \Learnomy\render_editorjs_content( (string) $welcome_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>
			</div>
			<a href="<?php echo esc_url( $dismiss_url ); ?>" class="lrn-banner__dismiss" aria-label="<?php esc_attr_e( 'Dismiss welcome message', 'learnomy' ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
					<line x1="18" y1="6" x2="6" y2="18"></line>
					<line x1="6" y1="6" x2="18" y2="18"></line>
				</svg>
			</a>
		</aside>
	<?php endif; ?>

	<?php if ( $latest_announcement ) : ?>
		<aside class="lrn-banner lrn-banner--announcement" role="note" aria-labelledby="lrn-announcement-title" data-lrn-banner="announcement">
			<div class="lrn-banner__icon" aria-hidden="true">
				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
					<path d="m3 11 18-5v12L3 14v-3z"></path>
					<path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"></path>
				</svg>
			</div>
			<div class="lrn-banner__body">
				<h2 id="lrn-announcement-title" class="lrn-banner__title">
					<?php echo esc_html( (string) ( $latest_announcement->title ?? __( 'Course announcement', 'learnomy' ) ) ); ?>
				</h2>
				<div class="lrn-banner__message">
					<?php echo wp_kses_post( (string) ( $latest_announcement->message ?? '' ) ); ?>
				</div>
				<?php if ( ! empty( $latest_announcement->created_at ) ) : ?>
					<p class="lrn-banner__meta">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: date */
								__( 'Posted %s', 'learnomy' ),
								date_i18n( get_option( 'date_format' ), strtotime( (string) $latest_announcement->created_at ) )
							)
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</aside>
	<?php endif; ?>

	<?php if ( $is_course_complete ) : ?>
		<?php include \Learnomy\template_path( 'partials/course-completion.php' ); ?>
	<?php else : ?>
		<div class="lrn-single-course__progress">
			<div class="lrn-single-course__progress-header">
				<?php /* translators: %d: progress percentage */ ?>
			<span><?php echo esc_html( sprintf( __( '%d%% complete', 'learnomy' ), (int) $enrollment->progress_pct ) ); ?></span>
				<span class="lrn-text-muted"><?php echo esc_html( $lesson_count ); ?> <?php esc_html_e( 'lessons', 'learnomy' ); ?></span>
			</div>
			<?php
			$percent = (int) $enrollment->progress_pct;
			// The header above already prints "N% complete", so suppress the
			// bar's own label to avoid a duplicate (Basecamp #9985320344).
			$progress_show_label = false;
			include \Learnomy\template_path( 'partials/progress-bar.php' );
			?>
		</div>
	<?php endif; ?>

	<?php
	// Intro video — render for enrolled viewers too (Basecamp #9909700680
	// bounce 2026-05-27). The video was previously gated to the sales view
	// which surprised customers when admins / instructors / re-enrolled
	// students viewed the same URL and saw nothing. Same hero-layout skip
	// rule as the sales branch.
	include \Learnomy\template_path( 'course/partials/intro-video.php' );
	?>

	<?php
	// About This Course — the full description (Editor.js -> HTML). The enrolled
	// (learning) view previously showed no description at all: a student who
	// already enrolled could not re-read what the course covers. The sales view
	// already renders this (see the non-enrolled branch below); mirror it here,
	// above the curriculum, with the same markup so both views read identically.
	if ( ! empty( $course->description ) ) :
		$lrn_about_html = \Learnomy\render_editorjs_content( (string) $course->description );
		if ( '' !== trim( wp_strip_all_tags( $lrn_about_html ) ) ) :
			?>
			<section class="lrn-single-course__section" aria-labelledby="about-heading">
				<h2 id="about-heading"><?php esc_html_e( 'About This Course', 'learnomy' ); ?></h2>
				<div class="lrn-about__body">
					<?php echo $lrn_about_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_editorjs_content escapes via wp_kses_post internally. ?>
				</div>
			</section>
			<?php
		endif;
	endif;
	?>

	<section class="lrn-single-course__curriculum" aria-labelledby="curriculum-heading">
		<h2 id="curriculum-heading">
			<?php
			/* translators: %s: singular label for a course (e.g. "Course") */
			printf( esc_html__( '%s Content', 'learnomy' ), esc_html( \Learnomy\course_label() ) );
			?>
		</h2>
		<?php
		$course_id = (int) $course->id;
		include \Learnomy\template_path( 'partials/curriculum.php' );
		?>
	</section>

	<?php
	// Course comments — render only when the instructor has the
	// toggle on (per-course), and when the site-wide setting has
	// not disabled comments globally. Filterable for extensions.
	// Reuse the settings map already resolved at the top of the
	// enrolled view to avoid a second DB read.
	$lrn_course_settings     = $course_settings;
	$lrn_site_settings       = get_option( 'learnomy_settings', array() );
	$course_comments_on      = (bool) ( $lrn_course_settings['comments_enabled'] ?? true );
	$site_comments_on        = (bool) ( $lrn_site_settings['enable_course_comments'] ?? 1 );
	$course_comments_enabled = (bool) apply_filters(
		'learnomy_course_comments_enabled',
		$course_comments_on && $site_comments_on,
		$course
	);

	if ( $course_comments_enabled ) :
		// The course-comments front end (list + post form) ships in v1.1 via a
		// subscriber to learnomy_render_course_comments. Until one exists, render
		// NOTHING here — never a "Course Discussion" heading + placeholder the
		// student can't actually post into. Self-healing: the section appears the
		// moment a subscriber supplies real UI. (Lesson comments are a separate,
		// fully-wired feature and are unaffected.)
		ob_start();
		do_action( 'learnomy_render_course_comments', (int) $course->id, $course );
		$rendered = trim( (string) ob_get_clean() );

		if ( '' !== $rendered ) :
			$course_comment_count = (int) get_comments(
				array(
					'type'       => 'learnomy_course',
					'status'     => 'approve',
					'count'      => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded COUNT for a single course's comments, indexed by comment_post_ID + meta key.
					'meta_query' => array(
						array(
							'key'   => '_learnomy_course_id',
							'value' => (int) $course->id,
							'type'  => 'NUMERIC',
						),
					),
				)
			);
			?>
			<section class="lrn-single-course__comments lrn-card" aria-labelledby="course-comments-heading">
				<h2 id="course-comments-heading">
					<i data-lucide="message-square" aria-hidden="true"></i>
					<?php esc_html_e( 'Course Discussion', 'learnomy' ); ?>
					<span class="lrn-section-subtitle">
						<?php
						/* translators: %d: number of comments */
						echo esc_html( sprintf( _n( '%d comment', '%d comments', $course_comment_count, 'learnomy' ), $course_comment_count ) );
						?>
					</span>
				</h2>
				<?php echo $rendered; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hook output is the subscriber's responsibility. ?>
			</section>
		<?php endif; ?>
	<?php endif; ?>

	<!-- Write a review (J-01.20) — only renders when reviews are
		enabled on the course AND the buyer is enrolled. The
		partial renders either the form OR a read-only summary
		of the student's existing review. The sales-view branch
		keeps its own list-style "Student Reviews" section
		below; enrolled users get the write-or-posted state
		here so the action is reachable from the natural flow. -->
	<?php if ( ! empty( $can_user_review ) ) : ?>
		<section class="lrn-single-course__section" aria-labelledby="lrn-review-heading">
			<h2 id="lrn-review-heading"><?php esc_html_e( 'Your review', 'learnomy' ); ?></h2>
			<?php include \Learnomy\template_path( 'partials/review-form.php' ); ?>
		</section>
	<?php endif; ?>

<?php else : ?>
	<!-- ========== SALES VIEW ========== -->

	<?php
	// Surface A (W1) — wire 6 instructor-toggleable settings into the
	// sales page. $course_settings is supplied by
	// Single_Course_View_Data resolver (same map for both branches).

	// `learning_outcomes` is a repeater array from the settings JSON;
	// when populated it replaces the legacy `what_you_learn` column
	// string so instructors using the new editor get bullets while
	// older courses keep rendering the free-form HTML block.
	$lrn_outcomes = isset( $course_settings['learning_outcomes'] ) && is_array( $course_settings['learning_outcomes'] )
		? array_values( array_filter( array_map( 'strval', $course_settings['learning_outcomes'] ), static fn( $s ) => '' !== trim( $s ) ) )
		: [];
	?>

	<?php
	// Intro video (sales view) — shared partial; gates against the hero
	// layout itself so we don't double-render the trailer in the hero band.
	include \Learnomy\template_path( 'course/partials/intro-video.php' );
	?>

	<!-- About This Course — surface the description first so visitors read
		"what is this course" before bullet-style outcomes, audience, or
		curriculum. Previously rendered after Curriculum which buried the
		primary value prop. (Basecamp #9927294548.) -->
	<?php if ( ! empty( $course->description ) ) : ?>
		<section class="lrn-single-course__section" aria-labelledby="about-heading">
			<h2 id="about-heading"><?php esc_html_e( 'About This Course', 'learnomy' ); ?></h2>
			<div class="lrn-about__body">
				<?php
				// The admin course form saves the description as Editor.js JSON
				// (course-form.php Editor.js mount). Decode it to HTML here;
				// the renderer's compatibility branch passes legacy plain/HTML
				// descriptions through wp_kses_post unchanged. Echoing the raw
				// column printed the JSON blob on the page (Basecamp #9941131748).
				echo \Learnomy\render_editorjs_content( (string) $course->description ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_editorjs_content escapes via wp_kses_post internally.
				?>
			</div>
		</section>
	<?php endif; ?>

	<!-- What You Will Learn -->
	<?php if ( ! empty( $lrn_outcomes ) ) : ?>
		<section class="lrn-outcomes lrn-card" aria-labelledby="outcomes-heading">
			<h2 id="outcomes-heading" class="lrn-outcomes__heading"><?php esc_html_e( 'What You Will Learn', 'learnomy' ); ?></h2>
			<ul class="lrn-outcomes__list">
				<?php foreach ( $lrn_outcomes as $lrn_outcome ) : ?>
					<li><?php echo esc_html( $lrn_outcome ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php elseif ( ! empty( $course->what_you_learn ) ) : ?>
		<section class="lrn-outcomes lrn-card" aria-labelledby="outcomes-heading">
			<h2 id="outcomes-heading" class="lrn-outcomes__heading"><?php esc_html_e( 'What You Will Learn', 'learnomy' ); ?></h2>
			<div class="lrn-outcomes__grid">
				<?php echo wp_kses_post( $course->what_you_learn ); ?>
			</div>
		</section>
	<?php endif; ?>

	<!-- Who This Course Is For -->
	<?php if ( ! empty( $course_settings['target_audience'] ) ) : ?>
		<section class="lrn-single-course__section" aria-labelledby="audience-heading">
			<h2 id="audience-heading"><?php esc_html_e( 'Who This Course Is For', 'learnomy' ); ?></h2>
			<p><?php echo esc_html( $course_settings['target_audience'] ); ?></p>
		</section>
	<?php endif; ?>

	<!-- Prerequisites — stored as a JSON array of course IDs on
		lrn_courses.prerequisites. Decode here and render links to
		each required course so students can discover them. -->
	<?php // $prereq_courses is pre-resolved by Single_Course_View_Data. ?>
	<?php if ( ! empty( $prereq_courses ) ) : ?>
		<section class="lrn-single-course__section" aria-labelledby="prereq-heading">
			<h2 id="prereq-heading"><?php esc_html_e( 'Prerequisites', 'learnomy' ); ?></h2>
			<p class="lrn-section-subtitle"><?php esc_html_e( 'Complete these courses first to unlock enrollment:', 'learnomy' ); ?></p>
			<ul class="lrn-requirements">
				<?php foreach ( $prereq_courses as $pc ) : ?>
					<li>
						<a href="<?php echo esc_url( \Learnomy\route_url( 'course', array( 'slug' => (string) $pc->slug ) ) ); ?>">
							<?php echo esc_html( $pc->title ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<!-- Course Curriculum -->
	<section class="lrn-single-course__section" aria-labelledby="curriculum-heading">
		<h2 id="curriculum-heading">
			<?php
			/* translators: %s: singular label for a course (e.g. "Course") */
			printf( esc_html__( '%s Curriculum', 'learnomy' ), esc_html( \Learnomy\course_label() ) );
			?>
			<span class="lrn-section-subtitle">
				<?php
				$parts = [];
				if ( $section_count ) {
					/* translators: %d: number of sections */
					$parts[] = sprintf( _n( '%d section', '%d sections', $section_count, 'learnomy' ), $section_count );
				}
				// "0 lessons" is not a description of a curriculum, it is the
				// absence of one - and printed beside a price it reads as a
				// feature of what is being sold (Basecamp 10184419301). A count
				// of nothing is omitted here and answered by the empty state
				// below, which says what is actually true.
				//
				if ( $lesson_count > 0 ) {
					/* translators: %d: number of lessons */
					$parts[] = sprintf( _n( '%d lesson', '%d lessons', $lesson_count, 'learnomy' ), $lesson_count );
				}
				if ( $duration_label ) {
					$parts[] = $duration_label;
				}
				echo esc_html( implode( ' &bull; ', $parts ) );
				?>
			</span>
		</h2>
		<?php
		$course_id = (int) $course->id;
		// Sales view — pass 0 so the curriculum partial shows all
		// lessons as unlocked previews (unauthenticated state). Use a
		// distinct variable name to avoid shadowing the outer $user_id
		// supplied by Single_Course_View_Data for the enrolled branch.
		$lrn_sales_user_id = 0;
		$user_id           = $lrn_sales_user_id;
		if ( $lesson_count < 1 ) {
			// An empty curriculum states its own case. Rendering the outline
			// machinery over nothing produced a bare heading that read as a
			// broken page, on a course that is otherwise fully purchasable.
			printf(
				'<p class="lrn-empty-state">%s</p>',
				esc_html__( 'The curriculum for this course is being prepared and is not published yet.', 'learnomy' )
			);
		} else {
			include \Learnomy\template_path( 'partials/curriculum.php' );
		}
		// Restore $user_id in case later includes or hooks expect it.
		$user_id = isset( $lrn_sales_user_id ) ? $lrn_sales_user_id : 0;
		?>
	</section>

	<!-- Meet Your Instructor -->
	<?php if ( $instructor ) : ?>
		<section class="lrn-single-course__section" aria-labelledby="instructor-heading">
			<h2 id="instructor-heading"><?php printf( esc_html( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( 'Meet Your %s', 'learnomy' ) ), esc_html( \Learnomy\instructor_label() ) ); ?></h2>
			<div class="lrn-instructor-panel lrn-card">
				<div class="lrn-instructor-panel__header">
					<?php echo get_avatar( $instructor->ID, 80, '', '', [ 'class' => 'lrn-instructor-panel__avatar' ] ); ?>
					<div class="lrn-instructor-panel__info">
						<h3 class="lrn-instructor-panel__name">
							<a href="<?php echo esc_url( $instructor_url ); ?>"><?php echo esc_html( $instructor->display_name ); ?></a>
						</h3>
						<div class="lrn-instructor-panel__stats">
							<?php if ( $instructor_course_count > 0 ) : ?>
								<span>
									<i data-lucide="book-open" aria-hidden="true"></i>
									<?php
									/* translators: %d: number of courses */
									echo esc_html( sprintf( _n( '%d course', '%d courses', $instructor_course_count, 'learnomy' ), $instructor_course_count ) );
									?>
								</span>
							<?php endif; ?>
							<?php if ( $instructor_student_count > 0 ) : ?>
								<span>
									<i data-lucide="users" aria-hidden="true"></i>
									<?php
									/* translators: %s: number of students */
									printf( esc_html( _n( '%s student', '%s students', $instructor_student_count, 'learnomy' ) ), esc_html( number_format_i18n( $instructor_student_count ) ) );
									?>
								</span>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<?php if ( ! empty( $instructor->description ) ) : ?>
					<p class="lrn-instructor-panel__bio"><?php echo esc_html( wp_trim_words( $instructor->description, 60 ) ); ?></p>
				<?php endif; ?>
				<a href="<?php echo esc_url( $instructor_url ); ?>" class="lrn-btn lrn-btn--ghost lrn-btn--sm">
					<?php esc_html_e( 'View Full Profile', 'learnomy' ); ?>
				</a>
			</div>
		</section>
	<?php endif; ?>

	<!-- Q&A — renders only when an implementation is actually hooked. -->
	<?php
	// has_action guard (half-cooked audit F2, Basecamp 10132251781): with
	// qa_enabled on but zero subscribers, this rendered a "Questions &
	// Answers" heading over an empty slot — a blank section the student
	// reads as broken. Same self-healing contract as course comments
	// above: the section appears the moment an implementation hooks in.
	?>
	<?php if ( ! empty( $course_settings['qa_enabled'] ) && has_action( 'learnomy_course_qa_section' ) ) : ?>
		<section class="lrn-single-course__section lrn-single-course__qa" aria-labelledby="qa-heading">
			<h2 id="qa-heading"><?php esc_html_e( 'Questions & Answers', 'learnomy' ); ?></h2>
			<div class="lrn-course-qa">
				<?php
				/**
				 * Fires inside the course sales page Q&A section when
				 * `qa_enabled` is true. Pro + extensions attach here
				 * to render the thread UI; core ships an empty slot.
				 *
				 * @param object $course Course row.
				 */
				do_action( 'learnomy_course_qa_section', $course );
				?>
			</div>
		</section>
	<?php endif; ?>

	<!-- Student Reviews -->
	<?php if ( \Learnomy\Models\Course::reviews_enabled_for( (int) $course->id ) ) : ?>
		<section class="lrn-single-course__section" aria-labelledby="reviews-heading">
			<h2 id="reviews-heading"><?php printf( esc_html( /* translators: %s: singular label for a student (e.g. "Learner"). */ __( '%s Reviews', 'learnomy' ) ), esc_html( \Learnomy\student_label() ) ); ?></h2>

		<?php if ( 0 === $total_reviews ) : ?>
			<div class="lrn-empty-state lrn-empty-state--inline">
				<i data-lucide="star" class="lrn-empty-state__icon" aria-hidden="true"></i>
				<p class="lrn-empty-state__desc">
					<?php esc_html_e( 'Be the first to review this course.', 'learnomy' ); ?>
				</p>
				<p class="lrn-empty-state__sub lrn-text-muted">
					<?php
					echo esc_html(
						is_user_logged_in()
							? __( 'Enroll in this course to leave your review.', 'learnomy' )
							: __( 'Log in and enroll to leave your review.', 'learnomy' )
					);
					?>
				</p>
			</div>
	<?php else : ?>
		<?php if ( empty( $is_owner ) ) : ?>
			<?php // The course's own instructor replies to reviews, they don't leave one. ?>
			<p class="lrn-reviews__eligibility lrn-text-muted">
				<?php
				echo esc_html(
					is_user_logged_in()
						? __( 'Enroll in this course to leave your own review.', 'learnomy' )
						: __( 'Log in and enroll in this course to leave your own review.', 'learnomy' )
				);
				?>
			</p>
		<?php endif; ?>

		<!-- Rating Summary -->
		<?php
		// Average + count come from the SAME authoritative aggregate the
		// distribution is built from (Single_Course_View_Data), so the headline
		// stats always match the number of reviews you can actually page through
		// (Basecamp #9981647943). Fall back to the denormalized course columns
		// only if the view-data var isn't set (older template callers).
		$lrn_reviews_avg = isset( $reviews_avg_rating )
			? (float) $reviews_avg_rating
			: (float) $course->avg_rating;
		?>
		<div class="lrn-rating-summary lrn-card">
			<div class="lrn-rating-summary__left">
				<span class="lrn-rating-summary__avg"><?php echo esc_html( number_format_i18n( $lrn_reviews_avg, 1 ) ); ?></span>
				<div class="lrn-rating-summary__stars">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<span class="lrn-star <?php echo esc_attr( $i <= round( $lrn_reviews_avg ) ? 'lrn-star--filled' : 'lrn-star--empty' ); ?>" aria-hidden="true">&#9733;</span>
					<?php endfor; ?>
				</div>
				<span class="lrn-rating-summary__label">
					<?php
					printf(
						/* translators: %s: total number of reviews */
						esc_html( _n( '%s review', '%s reviews', (int) $total_reviews, 'learnomy' ) ),
						esc_html( number_format_i18n( (int) $total_reviews ) )
					);
					?>
				</span>
			</div>
			<div class="lrn-rating-summary__bars">
				<?php
				for ( $star = 5; $star >= 1; $star-- ) :
					$count = $rating_distribution[ $star ];
					$pct   = $total_reviews > 0 ? round( ( $count / $total_reviews ) * 100 ) : 0;
					?>
					<div class="lrn-rating-summary__bar-row" style="--bar-pct: <?php echo esc_attr( $pct ); ?>%">
						<span class="lrn-rating-summary__bar-label"><?php echo esc_html( $star ); ?> &#9733;</span>
						<div class="lrn-rating-summary__bar">
							<div class="lrn-rating-summary__bar-fill" aria-hidden="true"></div>
						</div>
						<span class="lrn-rating-summary__bar-pct"><?php echo esc_html( $pct ); ?>%</span>
					</div>
				<?php endfor; ?>
			</div>
		</div>

		<!-- Individual Reviews -->
		<?php
		// Batch-fetch all reviewer WP_User objects in one query so review-card.php
		// never calls get_userdata() per row. The map is keyed by user_id and
		// shared via a local variable that the include picks up via PHP's file
		// scope (same pattern as $GLOBALS used below for the reply flag).
		$lrn_reviewer_ids = array_filter( array_unique( array_map( fn( $r ) => (int) $r->user_id, $reviews ) ) );
		$reviewer_map     = array();
		if ( $lrn_reviewer_ids ) {
			foreach ( get_users(
				array(
					'include' => $lrn_reviewer_ids,
					'number'  => count( $lrn_reviewer_ids ),
				)
			) as $lrn_ru ) {
				$reviewer_map[ (int) $lrn_ru->ID ] = $lrn_ru;
			}
		}

		// Only the first page renders server-side; the rest load on demand into
		// this list via the reviews/page endpoint (Basecamp #9981647943).
		$lrn_loaded_reviews = count( $reviews );
		?>
		<div class="lrn-reviews-list" data-course-id="<?php echo esc_attr( (int) $course->id ); ?>" data-offset="<?php echo esc_attr( $lrn_loaded_reviews ); ?>" data-total="<?php echo esc_attr( (int) $total_reviews ); ?>">
			<?php
			// Expose the reply affordance to the review-card partial
			// without re-passing it through every include iteration.
			$GLOBALS['lrn_can_reply_reviews'] = ! empty( $can_reply_reviews );
			foreach ( $reviews as $review ) :
				include \Learnomy\template_path( 'partials/review-card.php' );
			endforeach;
			unset( $GLOBALS['lrn_can_reply_reviews'] );
			unset( $reviewer_map );
			?>
		</div>

		<?php if ( $lrn_loaded_reviews < (int) $total_reviews ) : ?>
			<div class="lrn-reviews-load-more-wrap">
				<button type="button" class="lrn-btn lrn-btn--secondary lrn-reviews-load-more"
					data-course-id="<?php echo esc_attr( (int) $course->id ); ?>">
					<?php esc_html_e( 'Load more reviews', 'learnomy' ); ?>
					<i data-lucide="chevron-down" aria-hidden="true"></i>
				</button>
			</div>
		<?php endif; ?>

	<?php endif; ?>
		</section>
	<?php endif; ?>

<?php endif; ?>

<?php
// Report affordance — a logged-in member can flag this course for a moderator.
// Sits at the foot of the body, shared by the enrolled + sales views. The
// report lands in the admin Reports queue. JS: assets/js/report.js.
if ( is_user_logged_in() ) :
	?>
	<div class="lrn-course-report">
		<?php \Learnomy\report_button( 'course', (int) $course->id, array( 'label' => __( 'Report this course', 'learnomy' ) ) ); ?>
	</div>
<?php endif; ?>
