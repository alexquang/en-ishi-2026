<?php
/**
 * Lesson locked state — drip schedule countdown card.
 *
 * Rendered inside the lesson player's main content area when the current
 * viewer cannot yet access a drip-scheduled lesson. The surrounding
 * single-lesson.php template owns the sidebar and chrome; this partial
 * only renders the centered card with the unlock time and countdown.
 *
 * Required scope (provided by single-lesson.php via Single_Lesson_View_Data):
 *   $course         (object) Course row.
 *   $lesson         (object) Lesson row. May carry `drip_mode` and
 *                            `drip_offset_days` (Plan 11 — relative drip).
 *   $unlocks_at     (string) Raw UTC datetime — empty string when this is a
 *                            relative-drip lesson and the viewer has not
 *                            enrolled yet (no per-student unlock row exists).
 *   $unlocks_at_iso (string) ISO 8601 UTC datetime for the JS countdown.
 *                            Empty when there is no concrete unlock moment
 *                            (relative-drip + not-enrolled).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

/*
 * Drip mode controls the message we show. Three states:
 * 1. Fixed-date OR relative-drip-with-resolved-row → "Unlocks May 1"
 * 2. Relative-drip + viewer is not enrolled yet → "Unlocks N days into
 *    your enrollment" (no concrete date — the offset is shown).
 * 3. Anything else → generic "Locked".
 */
$lrn_drip_mode    = isset( $lesson->drip_mode ) ? (string) $lesson->drip_mode : 'fixed';
$lrn_offset_days  = isset( $lesson->drip_offset_days ) ? (int) $lesson->drip_offset_days : 0;
$lrn_is_relative  = ( 'relative' === $lrn_drip_mode );
$lrn_has_concrete = ! empty( $unlocks_at );
$lrn_show_offset  = ( $lrn_is_relative && ! $lrn_has_concrete && $lrn_offset_days > 0 );

// Format the unlock time in the site's date/time format. The stored value
// is UTC, so build a DateTimeImmutable in UTC and let wp_date() apply the
// site timezone for the human-readable label.
$lrn_unlock_label = '';
if ( $lrn_has_concrete ) {
	try {
		$lrn_unlock_dt    = new \DateTimeImmutable( $unlocks_at, new \DateTimeZone( 'UTC' ) );
		$lrn_unlock_label = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$lrn_unlock_dt->getTimestamp()
		);
	} catch ( \Exception $e ) {
		$lrn_unlock_label = '';
	}
}

// Server clock as ISO 8601 UTC so the JS ticker can measure drift between
// the browser clock and the server. Avoids "countdown jumps" when the
// student's machine is set to the wrong time.
$lrn_server_now_iso = gmdate( 'c' );

// Back-to-course URL — falls back to the catalog when the lesson is
// somehow detached from a course (defensive; the normal path always has
// a course). $course is provided by single-lesson.php scope.
$lrn_back_url = isset( $course ) && ! empty( $course->slug )
	? \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) )
	: \Learnomy\route_url( 'catalog' );
?>
<section class="lrn-lesson-locked"
	data-wp-interactive="learnomy/lesson-drip"
	<?php if ( ! empty( $unlocks_at_iso ) ) : ?>
		data-unlocks-at="<?php echo esc_attr( $unlocks_at_iso ); ?>"
	<?php endif; ?>
	data-server-now="<?php echo esc_attr( $lrn_server_now_iso ); ?>">

	<div class="lrn-lesson-locked__icon" aria-hidden="true">
		<i data-lucide="lock"></i>
	</div>

	<h1 class="lrn-lesson-locked__title">
		<?php echo esc_html( isset( $lesson ) && ! empty( $lesson->title ) ? $lesson->title : __( 'Lesson', 'learnomy' ) ); ?>
	</h1>

	<p class="lrn-lesson-locked__when">
		<?php
		if ( $lrn_unlock_label ) {
			printf(
				/* translators: %s: localised unlock date and time */
				esc_html__( 'This lesson unlocks on %s.', 'learnomy' ),
				esc_html( $lrn_unlock_label )
			);
		} elseif ( $lrn_show_offset ) {
			printf(
				esc_html(
					/* translators: %d: number of days after enrollment */
					_n(
						'This lesson unlocks %d day after you enroll.',
						'This lesson unlocks %d days after you enroll.',
						$lrn_offset_days,
						'learnomy'
					)
				),
				(int) $lrn_offset_days
			);
		} else {
			esc_html_e( 'This lesson is currently locked.', 'learnomy' );
		}
		?>
	</p>

	<?php if ( ! empty( $unlocks_at_iso ) ) : ?>
		<div class="lrn-countdown-chip"
			data-lrn-countdown
			data-unlocks-at="<?php echo esc_attr( $unlocks_at_iso ); ?>"
			role="timer"
			aria-live="polite">
			<?php esc_html_e( 'Calculating remaining time.', 'learnomy' ); ?>
		</div>
	<?php endif; ?>

	<p class="lrn-lesson-locked__back">
		<a href="<?php echo esc_url( $lrn_back_url ); ?>" class="lrn-btn lrn-btn--ghost">
			<i data-lucide="arrow-left" aria-hidden="true"></i>
			<?php esc_html_e( 'Back to Course', 'learnomy' ); ?>
		</a>
	</p>
</section>
