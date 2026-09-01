<?php
/**
 * Dashboard home — compact course preview.
 *
 * A short, glanceable strip of the learner's in-progress courses (falling back
 * to most-recent enrollments) with a "View all" link to the dedicated
 * /account/courses/ section, which owns the full tabbed + paged list. Keeping
 * the home to a handful of cards is what lets the secondary widgets below
 * (announcements, recent activity, recommendations) stay visible instead of
 * being pushed under a 1000-row grid.
 *
 * Locals (from Student_Dashboard_View_Data): $continue_enrollments,
 * $enrollments, $enrollment_courses, $total_enrolled, $lrn_wishlist.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_preview_limit = 6;

// $continue_enrollments is the in-progress set already ordered most-recently-
// worked-on first by the view-data, which is what makes the heading below true
// on an account holding more courses than the strip can show. Falls back to the
// most recent enrollments so a learner who just enrolled still sees their courses.
$lrn_continue_rows = isset( $continue_enrollments ) && is_array( $continue_enrollments ) ? $continue_enrollments : array();
$lrn_preview_rows  = ! empty( $lrn_continue_rows ) ? $lrn_continue_rows : $enrollments;
$lrn_preview_rows  = is_array( $lrn_preview_rows ) ? array_slice( $lrn_preview_rows, 0, $lrn_preview_limit ) : array();

// "Continue" is a claim about ACTIVITY, not about holding an enrollment: a
// learner at 0% is in progress (Basecamp 10183993951) but has nothing to
// continue yet, and telling them to continue six courses they have never
// opened is the same over-claim in the other direction.
$lrn_has_activity = ! empty( $continue_has_activity );

$lrn_wishlist = isset( $lrn_wishlist ) && is_array( $lrn_wishlist ) ? $lrn_wishlist : array();
$lrn_total    = (int) ( $total_enrolled ?? 0 );
?>
<section class="lrn-courses-preview">
	<div class="lrn-section-head">
		<h2 class="lrn-section-head__title">
			<?php
			printf(
				esc_html(
					$lrn_has_activity
						/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
						? __( 'Continue your %s', 'learnomy' )
						/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
						: __( 'My %s', 'learnomy' )
				),
				esc_html( \Learnomy\course_label( true ) )
			);
			?>
		</h2>
		<?php if ( $lrn_total > 0 ) : ?>
			<a class="lrn-section-head__link" href="<?php echo esc_url( \Learnomy\route_url( 'account-courses' ) ); ?>">
				<?php
				printf(
					/* translators: %d: total number of enrolled courses */
					esc_html__( 'View all (%d)', 'learnomy' ),
					absint( $lrn_total )
				);
				?>
				<i data-lucide="arrow-right" aria-hidden="true"></i>
			</a>
		<?php endif; ?>
	</div>

	<?php if ( $lrn_preview_rows ) : ?>
		<div class="lrn-grid lrn-courses-grid">
			<?php
			foreach ( $lrn_preview_rows as $enrollment ) :
				$course = $enrollment_courses[ (int) $enrollment->course_id ] ?? null;
				if ( ! $course ) {
					continue;
				}
				include \Learnomy\template_path( 'partials/course-card.php' );
			endforeach;
			?>
		</div>
	<?php else : ?>
		<?php
		// Through the loader, not LEARNOMY_DIR: a child theme overriding this
		// partial must win, and a direct plugin path silently ignores it
		// (coding-rules Rule 11).
		$lrn_empty_state = \Learnomy\template_path( 'dashboard-widgets/student/partials/no-courses-empty-state.php' );
		if ( $lrn_empty_state ) {
			require $lrn_empty_state;
		}
		?>
	<?php endif; ?>
</section>
