<?php
/**
 * Engagement alerts widget body.
 *
 * Locals: $inactive_7, $inactive_14
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( (int) $inactive_7 <= 0 ) {
	return;
}
?>
<div class="lrn-engagement-alerts">
	<div class="lrn-alert-card">
		<i data-lucide="alert-triangle" class="lrn-alert-card__icon" aria-hidden="true"></i>
		<div class="lrn-alert-card__body">
			<p class="lrn-alert-card__title">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of inactive students. */
						_n( '%d student inactive for 7+ days', '%d students inactive for 7+ days', (int) $inactive_7, 'learnomy' ),
						(int) $inactive_7
					)
				);
				?>
			</p>
			<?php if ( (int) $inactive_14 > 0 ) : ?>
				<p class="lrn-alert-card__subtitle">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of students inactive 14+ days. */
							_n( '%d student inactive for 14+ days', '%d students inactive for 14+ days', (int) $inactive_14, 'learnomy' ),
							(int) $inactive_14
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
		// Carry the SAME window the headline states (7 days) into the roster,
		// which filters by the SAME canonical query the count above ran
		// (Enrollment::inactive_pairs_sql) — so the number on this card and
		// the people on the next screen can never disagree. This link used
		// to land on the unfiltered roster: "3 students inactive" followed
		// by a list of everyone (Basecamp 10127571047).
		?>
		<a href="<?php echo esc_url( add_query_arg( 'inactive', 7, \Learnomy\route_url( 'instructor-students' ) ) ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--sm">
			<?php esc_html_e( 'View Details', 'learnomy' ); ?>
		</a>
	</div>
</div>
