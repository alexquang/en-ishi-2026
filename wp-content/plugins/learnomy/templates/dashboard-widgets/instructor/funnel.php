<?php
/**
 * Completion funnel widget body. The widget's is_available() already
 * gates on funnel_enrolled > 0 — this partial decides between the
 * rendered bars and the "no downstream activity yet" empty state.
 *
 * Locals: $funnel_enrolled, $funnel_started, $funnel_halfway, $funnel_completed,
 *         $funnel_started_pct, $funnel_halfway_pct, $funnel_completed_pct
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$funnel_has_activity = ( (int) $funnel_started + (int) $funnel_halfway + (int) $funnel_completed ) > 0;

if ( ! $funnel_has_activity ) {
	?>
	<div class="lrn-empty-state lrn-funnel-empty">
		<p class="lrn-empty-state__desc">
			<?php esc_html_e( 'No data yet. The completion funnel appears once students start courses.', 'learnomy' ); ?>
		</p>
	</div>
	<?php
	return;
}
?>
<div class="lrn-funnel">
	<div class="lrn-funnel__bar lrn-funnel__bar--full">
		<?php
		/* translators: %d: number of enrolled students */
		echo esc_html( sprintf( __( 'Enrolled: %d', 'learnomy' ), (int) $funnel_enrolled ) );
		?>
	</div>
	<div class="lrn-funnel__bar" style="--pct: <?php echo esc_attr( (string) $funnel_started_pct ); ?>%">
		<?php
		/* translators: 1: number of students who started, 2: percentage */
		echo esc_html( sprintf( __( 'Started: %1$d (%2$d%%)', 'learnomy' ), (int) $funnel_started, (int) $funnel_started_pct ) );
		?>
	</div>
	<div class="lrn-funnel__bar" style="--pct: <?php echo esc_attr( (string) $funnel_halfway_pct ); ?>%">
		<?php
		/* translators: 1: number of students 50%+ complete, 2: percentage */
		echo esc_html( sprintf( __( '50%%+ Complete: %1$d (%2$d%%)', 'learnomy' ), (int) $funnel_halfway, (int) $funnel_halfway_pct ) );
		?>
	</div>
	<div class="lrn-funnel__bar" style="--pct: <?php echo esc_attr( (string) $funnel_completed_pct ); ?>%">
		<?php
		/* translators: 1: number of students who completed, 2: percentage */
		echo esc_html( sprintf( __( 'Completed: %1$d (%2$d%%)', 'learnomy' ), (int) $funnel_completed, (int) $funnel_completed_pct ) );
		?>
	</div>
</div>
