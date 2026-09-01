<?php
/**
 * "You have no courses yet" empty state for the student dashboard.
 *
 * Lived twice, verbatim, in enrollments.php and courses-preview.php. Both said
 * "courses" in three places regardless of the site's Labels setting, so an
 * owner who renamed Course to Program had to find and fix the same three
 * strings in two files — which is how the Labels feature came to be half
 * applied in the first place (Basecamp 10168014231).
 *
 * Every noun here resolves through course_label(), so the copy follows whatever
 * the owner called the thing.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_courses_label = \Learnomy\course_label( true );
?>
<div class="lrn-empty-state">
	<i data-lucide="graduation-cap" class="lrn-empty-state__icon" aria-hidden="true"></i>
	<h3 class="lrn-empty-state__title">
		<?php
		printf(
			/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
			esc_html__( 'No %s yet', 'learnomy' ),
			esc_html( $lrn_courses_label )
		);
		?>
	</h3>
	<p class="lrn-empty-state__desc">
		<?php
		printf(
			/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
			esc_html__( 'Browse %s to start learning today.', 'learnomy' ),
			esc_html( $lrn_courses_label )
		);
		?>
	</p>
	<a href="<?php echo esc_url( \Learnomy\route_url( 'catalog' ) ); ?>" class="lrn-btn lrn-btn--primary">
		<?php
		printf(
			/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
			esc_html__( 'Explore %s', 'learnomy' ),
			esc_html( $lrn_courses_label )
		);
		?>
	</a>
</div>
