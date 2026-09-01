<?php
/**
 * Instructor "Students at Risk" widget body.
 *
 * Boundary: only students enrolled in courses the current instructor
 * authors. Threshold: no progress event in 14+ days (or never started).
 * Surface up to 5; footer links to the admin students list with the
 * `inactive` filter pre-applied for the full drilldown.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$rows = \Learnomy\Models\Enrollment::at_risk_for_instructor( (int) $user_id, 14, 5 );
if ( empty( $rows ) ) {
	return;
}

// Batch-fetch course rows once (id => row) so the loop doesn't run a
// Course::find() per row (N+1).
$lrn_course_ids = array_values( array_unique( array_filter( array_map( static fn( $r ) => (int) ( $r->course_id ?? 0 ), $rows ) ) ) );
$lrn_courses    = ( $lrn_course_ids && class_exists( '\\Learnomy\\Models\\Course' ) ) ? \Learnomy\Models\Course::find_in( $lrn_course_ids ) : array();
?>
<ul class="lrn-at-risk-list">
	<?php
	foreach ( $rows as $row ) :
		$last     = ! empty( $row->last_activity ) ? strtotime( (string) $row->last_activity ) : 0;
		$enrolled = ! empty( $row->enrolled_at ) ? strtotime( (string) $row->enrolled_at ) : 0;
		// Show "never started" when no progress event AND the enrollment
		// itself is old enough to be a real signal (3+ days). New
		// enrollments aren't at-risk yet just because they haven't moved.
		$never_started = ! $last && $enrolled && ( time() - $enrolled ) > ( 3 * DAY_IN_SECONDS );
		$signal_label  = $last
			? sprintf(
				/* translators: %s: time diff */
				esc_html__( 'Last active %s ago', 'learnomy' ),
				esc_html( human_time_diff( $last, time() ) )
			)
			: ( $never_started ? esc_html__( 'Has not started yet', 'learnomy' ) : esc_html__( 'New enrollment', 'learnomy' ) );
		$course_link = ! empty( $row->course_id ) && isset( $lrn_courses[ (int) $row->course_id ] )
			? \Learnomy\route_url( 'course', array( 'slug' => (string) ( $lrn_courses[ (int) $row->course_id ]->slug ?? '' ) ) )
			: '#';
		?>
		<li class="lrn-at-risk-list__item">
			<?php echo get_avatar( (int) $row->user_id, 32, '', $row->student_display_name, array( 'class' => 'lrn-at-risk-list__avatar' ) ); ?>
			<div class="lrn-at-risk-list__body">
				<span class="lrn-at-risk-list__name"><?php echo esc_html( $row->student_display_name ); ?></span>
				<span class="lrn-at-risk-list__course">
					<?php if ( $row->course_title ) : ?>
						<a href="<?php echo esc_url( $course_link ); ?>"><?php echo esc_html( $row->course_title ); ?></a>
					<?php endif; ?>
				</span>
			</div>
			<span class="lrn-at-risk-list__signal"><?php echo $signal_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above. ?></span>
		</li>
	<?php endforeach; ?>
</ul>
<?php
// Carries the widget's OWN threshold (14), not the 7 the Engagement Alerts
// card uses. Both links now filter, but a drilldown that changed the threshold
// on the way would list a different set of people than the widget above it
// just named, which reads as a bug in the count (Basecamp 10127571047).
?>
<a href="<?php echo esc_url( add_query_arg( 'inactive', 14, \Learnomy\route_url( 'instructor-students' ) ) ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--sm">
	<?php esc_html_e( 'View all inactive students', 'learnomy' ); ?>
</a>
