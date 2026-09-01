<?php
/**
 * Single course — HERO / landing variant.
 *
 * A full-bleed media hero (course cover / trailer) carrying the title, rating,
 * price, and the Enroll CTA inside the hero itself (no separate side card).
 * Below the hero: a single-column body with a sticky in-page section nav
 * (Overview / Curriculum / Instructor / Reviews) that anchor-scrolls to the
 * matching sections rendered by the shared body partial.
 *
 * Reuses the shared partials (enroll-card.php for the CTA + price, body.php for
 * the content column) so the enrolled and non-enrolled branches stay in lockstep
 * with classic. Root class `.lrn-course--hero`.
 *
 * Variables: same scope as course/classic.php (injected by
 * Single_Course_View_Data).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// The not-found verdict is made by the view-data resolver and honoured by
// Template_Loader BEFORE any output, so this guard is a safety net only (a
// third party swapping the resolver via `learnomy_view_data_resolvers`). It
// routes through the one shared renderer instead of repeating the incantation.
if ( ! empty( $not_found ) ) {
	\Learnomy\Template_Loader::render_not_found();
	return;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$lrn_just_enrolled = $is_enrolled
	&& isset( $_GET['lrn_enrolled'] )
	&& '1' === sanitize_text_field( wp_unslash( $_GET['lrn_enrolled'] ) );
// phpcs:enable

// Hero media: prefer an intro trailer, fall back to the cover thumbnail.
$lrn_has_trailer    = ( '' !== ( $preview_video['provider'] ?? '' ) );
$lrn_has_hero_media = $lrn_has_trailer || ! empty( $thumbnail_url );
?>

<div class="lrn-course lrn-course--hero">

	<?php if ( $lrn_just_enrolled ) : ?>
		<div class="lrn-container">
			<div class="lrn-notice lrn-notice--success" role="status" aria-live="polite">
				<i data-lucide="check-circle" aria-hidden="true"></i>
				<span>
					<?php
					printf(
						/* translators: %s: course title */
						esc_html__( "You're enrolled in %s. Pick up where you left off any time.", 'learnomy' ),
						'<strong>' . esc_html( $course->title ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					);
					?>
				</span>
			</div>
		</div>
	<?php endif; ?>

	<!-- ================================================================
		FULL-BLEED MEDIA HERO (title + rating + price + CTA inside)
		================================================================ -->
	<header class="lrn-course-hero" data-has-media="<?php echo $lrn_has_hero_media ? 'true' : 'false'; ?>">
		<?php if ( ! empty( $thumbnail_url ) ) : ?>
			<div class="lrn-course-hero__bg" role="img" aria-label="<?php echo esc_attr( $course->title ); ?>">
				<img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="" loading="eager" class="lrn-course-hero__bg-img">
			</div>
		<?php else : ?>
			<span class="lrn-course-hero__initial" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $course->title ?: 'L', 0, 1 ) ) ); ?></span>
		<?php endif; ?>
		<div class="lrn-course-hero__overlay"></div>

		<div class="lrn-course-hero__inner lrn-container">
			<div class="lrn-course-hero__intro">
				<div class="lrn-course-hero__badges">
					<?php if ( $category ) : ?>
						<span class="lrn-badge lrn-badge--blue"><?php echo esc_html( $category->name ); ?></span>
					<?php endif; ?>
					<?php if ( $difficulty_label ) : ?>
						<span class="lrn-badge lrn-badge--outline"><?php echo esc_html( $difficulty_label ); ?></span>
					<?php endif; ?>
					<?php if ( $is_enrolled ) : ?>
						<span class="lrn-badge lrn-badge--green"><?php esc_html_e( 'Enrolled', 'learnomy' ); ?></span>
					<?php endif; ?>
				</div>

				<h1 class="lrn-course-hero__title"><?php echo esc_html( $course->title ); ?></h1>

				<?php if ( ! empty( $course->short_description ) ) : ?>
					<p class="lrn-course-hero__tagline"><?php echo esc_html( $course->short_description ); ?></p>
				<?php elseif ( ! empty( $course->description ) ) : ?>
					<p class="lrn-course-hero__tagline"><?php echo esc_html( wp_trim_words( \Learnomy\render_editorjs_content( (string) $course->description, '', 'plain' ), 30 ) ); ?></p>
				<?php endif; ?>

				<div class="lrn-course-hero__meta">
					<?php if ( $instructor ) : ?>
						<span class="lrn-course-hero__meta-item">
							<?php echo get_avatar( $instructor->ID, 24, '', '', [ 'class' => 'lrn-course-hero__meta-avatar' ] ); ?>
							<a href="<?php echo esc_url( $instructor_url ); ?>" class="lrn-course-hero__meta-link"><?php echo esc_html( $instructor->display_name ); ?></a>
						</span>
					<?php endif; ?>
					<?php if ( (float) $course->avg_rating > 0 ) : ?>
						<span class="lrn-course-hero__meta-item">
							<span class="lrn-course-hero__stars">&#9733; <?php echo esc_html( number_format_i18n( (float) $course->avg_rating, 1 ) ); ?></span>
							<span class="lrn-course-hero__meta-muted">(<?php echo esc_html( number_format_i18n( (int) $course->review_count ) ); ?>)</span>
						</span>
					<?php endif; ?>
					<?php
					$lrn_sc = (int) $course->student_count;
					if ( $lrn_sc > 0 ) :
						?>
						<span class="lrn-course-hero__meta-item">
							<i data-lucide="users" aria-hidden="true"></i>
							<?php
							printf(
								/* translators: %s: number of students */
								esc_html( _n( '%s student', '%s students', $lrn_sc, 'learnomy' ) ),
								esc_html( number_format_i18n( $lrn_sc ) )
							);
							?>
						</span>
					<?php endif; ?>
					<?php if ( $duration_label ) : ?>
						<span class="lrn-course-hero__meta-item">
							<i data-lucide="clock" aria-hidden="true"></i>
							<?php echo esc_html( $duration_label ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<!-- CTA card embedded in the hero — reuses the shared enroll-card markup. -->
			<div class="lrn-course-hero__cta lrn-card">
				<?php
				if ( $lrn_has_trailer ) {
					?>
					<div class="lrn-course-hero__trailer">
						<?php if ( 'youtube' === $preview_video['provider'] || 'vimeo' === $preview_video['provider'] ) : ?>
							<iframe src="<?php echo esc_url( $preview_video['embed_url'] ); ?>" class="lrn-course-preview-video" title="<?php echo esc_attr( $course->title ); ?>" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
						<?php elseif ( 'html5' === $preview_video['provider'] ) : ?>
							<video class="lrn-course-preview-video" playsinline controls preload="metadata"><source src="<?php echo esc_url( $preview_video['src'] ); ?>"></video>
						<?php endif; ?>
					</div>
					<?php
				}
				require \Learnomy\template_path( 'course/partials/enroll-card.php' );
				?>
			</div>
		</div>
	</header>

	<?php
	/**
	 * Placement region above the course body (sponsor / CTA / related).
	 *
	 * @param object $course Course row.
	 */
	do_action( 'learnomy_course_before_content', $course );
	?>

	<!-- ================================================================
		IN-PAGE SECTION NAV + SINGLE-COLUMN BODY
		================================================================ -->
	<div class="lrn-container lrn-single-course lrn-course-hero-body">
		<nav class="lrn-course-section-nav" aria-label="<?php esc_attr_e( 'On this page', 'learnomy' ); ?>">
			<ul class="lrn-course-section-nav__list">
				<li><a href="#about-heading" class="lrn-course-section-nav__link"><?php esc_html_e( 'About', 'learnomy' ); ?></a></li>
				<li><a href="#curriculum-heading" class="lrn-course-section-nav__link"><?php esc_html_e( 'Curriculum', 'learnomy' ); ?></a></li>
				<?php if ( $instructor ) : ?>
					<li><a href="#instructor-heading" class="lrn-course-section-nav__link"><?php echo esc_html( \Learnomy\instructor_label() ); ?></a></li>
				<?php endif; ?>
				<?php if ( \Learnomy\Models\Course::reviews_enabled_for( (int) $course->id ) ) : ?>
					<li><a href="#reviews-heading" class="lrn-course-section-nav__link"><?php esc_html_e( 'Reviews', 'learnomy' ); ?></a></li>
				<?php endif; ?>
			</ul>
		</nav>

		<main class="lrn-single-course__main lrn-course-hero-body__main">
			<?php require \Learnomy\template_path( 'course/partials/body.php' ); ?>
		</main>
	</div>

	<?php
	/**
	 * Placement region below the course body.
	 *
	 * @param object $course Course row.
	 */
	do_action( 'learnomy_course_after_content', $course );
	?>

</div>
