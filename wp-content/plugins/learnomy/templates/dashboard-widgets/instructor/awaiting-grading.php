<?php
/**
 * Instructor "Awaiting Grading" widget body.
 *
 * Essay/manual quiz attempts on the instructor's courses still in the
 * `submitted` state (unscored). The count is resolved once in the instructor
 * view-data (pending_grading_count) and extracted here by partial().
 * [card 10062796121]
 *
 * The CTA points at the front-end grading queue, not wp-admin. Surfacing the
 * backlog on the dashboard and then sending the teacher into the WordPress
 * admin to act on it was the seam this widget left open (Basecamp 10132708064)
 * — the whole point of the teaching shell is that grading never leaves it.
 *
 * Locals: $pending_grading_count (from view_data).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_count = (int) ( $pending_grading_count ?? 0 );
if ( $lrn_count < 1 ) {
	return;
}
$lrn_grader_url = \Learnomy\route_url( 'instructor-grading' );
?>
<div class="lrn-dash-grading">
	<p class="lrn-dash-grading__count">
		<i data-lucide="clipboard-check" aria-hidden="true"></i>
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: number of quiz attempts awaiting grading. */
				_n(
					'%s essay attempt is waiting for your grade.',
					'%s essay attempts are waiting for your grade.',
					$lrn_count,
					'learnomy'
				),
				number_format_i18n( $lrn_count )
			)
		);
		?>
	</p>
	<a class="lrn-btn lrn-btn--primary lrn-btn--sm" href="<?php echo esc_url( $lrn_grader_url ); ?>">
		<?php esc_html_e( 'Open grading queue', 'learnomy' ); ?>
	</a>
</div>
