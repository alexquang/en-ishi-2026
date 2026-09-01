<?php
/**
 * Single course — SPLIT / magazine variant.
 *
 * An asymmetric editorial split header: an oversized title plus the key facts
 * on the left, the cover image on the right. Below it a two-column body with a
 * slim persistent right utility rail — a progress ring for enrolled learners
 * plus quick links, or the enroll card for the sales view.
 *
 * Reuses the shared body + enroll-card partials so the enrolled and
 * non-enrolled branches match classic. Root class `.lrn-course--split`.
 *
 * Variables: same scope as course/classic.php.
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

$lrn_progress_pct = ( $is_enrolled && isset( $enrollment->progress_pct ) ) ? (int) $enrollment->progress_pct : 0;
// SVG progress ring geometry — circumference for r=52 is 2*pi*r ≈ 326.726.
$lrn_ring_circ   = 326.726;
$lrn_ring_offset = $lrn_ring_circ - ( $lrn_ring_circ * $lrn_progress_pct / 100 );
?>

<div class="lrn-course lrn-course--split">

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
		EDITORIAL SPLIT HEADER (facts left, cover right)
		================================================================ -->
	<header class="lrn-course-split-head">
		<div class="lrn-container lrn-course-split-head__inner">
			<div class="lrn-course-split-head__text">
				<div class="lrn-course-split-head__badges">
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

				<h1 class="lrn-course-split-head__title"><?php echo esc_html( $course->title ); ?></h1>

				<?php if ( ! empty( $course->short_description ) ) : ?>
					<p class="lrn-course-split-head__tagline"><?php echo esc_html( $course->short_description ); ?></p>
				<?php endif; ?>

				<dl class="lrn-course-split-head__facts">
					<?php if ( $instructor ) : ?>
						<div class="lrn-course-split-head__fact">
							<dt><?php echo esc_html( \Learnomy\instructor_label() ); ?></dt>
							<dd><a href="<?php echo esc_url( $instructor_url ); ?>"><?php echo esc_html( $instructor->display_name ); ?></a></dd>
						</div>
					<?php endif; ?>
					<?php if ( (float) $course->avg_rating > 0 ) : ?>
						<div class="lrn-course-split-head__fact">
							<dt><?php esc_html_e( 'Rating', 'learnomy' ); ?></dt>
							<dd>&#9733; <?php echo esc_html( number_format_i18n( (float) $course->avg_rating, 1 ) ); ?> (<?php echo esc_html( number_format_i18n( (int) $course->review_count ) ); ?>)</dd>
						</div>
					<?php endif; ?>
					<div class="lrn-course-split-head__fact">
						<dt><?php echo esc_html( \Learnomy\student_label( true ) ); ?></dt>
						<dd><?php echo esc_html( number_format_i18n( (int) $course->student_count ) ); ?></dd>
					</div>
					<?php if ( $duration_label ) : ?>
						<div class="lrn-course-split-head__fact">
							<dt><?php esc_html_e( 'Length', 'learnomy' ); ?></dt>
							<dd><?php echo esc_html( $duration_label ); ?></dd>
						</div>
					<?php endif; ?>
				</dl>
			</div>

			<?php if ( ! empty( $thumbnail_url ) ) : ?>
				<div class="lrn-course-split-head__cover">
					<img src="<?php echo esc_url( $thumbnail_url ); ?>" alt="<?php echo esc_attr( $course->title ); ?>" loading="eager">
				</div>
			<?php endif; ?>
		</div>
	</header>

	<?php
	/**
	 * Placement region above the course body.
	 *
	 * @param object $course Course row.
	 */
	do_action( 'learnomy_course_before_content', $course );
	?>

	<!-- ================================================================
		TWO-COLUMN BODY + SLIM UTILITY RAIL
		================================================================ -->
	<div class="lrn-container lrn-single-course lrn-course-split-body">
		<main class="lrn-single-course__main lrn-course-split-body__main">
			<?php require \Learnomy\template_path( 'course/partials/body.php' ); ?>
		</main>

		<aside class="lrn-course-split-rail" aria-label="<?php esc_attr_e( 'Course tools', 'learnomy' ); ?>">
			<?php
			/**
			 * Top of the split utility rail (sponsor / CTA / related).
			 *
			 * @param object $course Course row.
			 */
			do_action( 'learnomy_course_sidebar_top', $course );
			?>

			<?php if ( $is_enrolled ) : ?>
				<div class="lrn-course-split-rail__progress lrn-card">
					<div class="lrn-course-ring" role="img" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percentage complete */ __( '%d percent complete', 'learnomy' ), $lrn_progress_pct ) ); ?>">
						<svg viewBox="0 0 120 120" class="lrn-course-ring__svg" aria-hidden="true" focusable="false">
							<circle class="lrn-course-ring__track" cx="60" cy="60" r="52" fill="none" stroke-width="8"></circle>
							<circle class="lrn-course-ring__fill" cx="60" cy="60" r="52" fill="none" stroke-width="8" stroke-linecap="round" stroke-dasharray="<?php echo esc_attr( (string) $lrn_ring_circ ); ?>" stroke-dashoffset="<?php echo esc_attr( (string) $lrn_ring_offset ); ?>"></circle>
						</svg>
						<span class="lrn-course-ring__label"><?php echo esc_html( $lrn_progress_pct . '%' ); ?></span>
					</div>
					<a href="<?php echo esc_url( $continue_url ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--full">
						<?php echo esc_html( 0 === $lrn_progress_pct ? __( 'Start course', 'learnomy' ) : __( 'Continue', 'learnomy' ) ); ?>
					</a>
				</div>

				<nav class="lrn-course-split-rail__links lrn-card" aria-label="<?php esc_attr_e( 'Quick links', 'learnomy' ); ?>">
					<a href="#about-heading"><i data-lucide="info" aria-hidden="true"></i> <?php esc_html_e( 'About', 'learnomy' ); ?></a>
					<a href="#curriculum-heading"><i data-lucide="list" aria-hidden="true"></i> <?php esc_html_e( 'Curriculum', 'learnomy' ); ?></a>
					<?php if ( $instructor ) : ?>
						<a href="#instructor-heading"><i data-lucide="user" aria-hidden="true"></i> <?php echo esc_html( \Learnomy\instructor_label() ); ?></a>
					<?php endif; ?>
					<a href="#reviews-heading"><i data-lucide="star" aria-hidden="true"></i> <?php esc_html_e( 'Reviews', 'learnomy' ); ?></a>
				</nav>
			<?php else : ?>
				<div class="lrn-course-split-rail__enroll lrn-card">
					<?php include \Learnomy\template_path( 'course/partials/enroll-card.php' ); ?>
				</div>
			<?php endif; ?>

			<?php
			/**
			 * Bottom of the split utility rail.
			 *
			 * @param object $course Course row.
			 */
			do_action( 'learnomy_course_sidebar_bottom', $course );
			?>
		</aside>
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
