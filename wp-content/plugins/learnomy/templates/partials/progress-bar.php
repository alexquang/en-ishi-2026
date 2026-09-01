<?php
/**
 * Progress bar partial.
 *
 * Expects: $percent (int|float) — completion percentage 0–100.
 * Optional: $progress_show_label (bool, default true) — set false when the
 *           caller already prints the "% complete" text itself, so the bar
 *           doesn't render a second, duplicate label (Basecamp #9985320344).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$percent = isset( $percent ) ? (int) min( 100, max( 0, $percent ) ) : 0;
// Default to showing the label (most callers rely on it). A caller that
// already shows the percentage sets this false before the include; we read
// then unset it so the choice never leaks into a later include.
$progress_show_label = isset( $progress_show_label ) ? (bool) $progress_show_label : true;

/* translators: %d: completion percentage, 0–100 */
$progress_label = sprintf( __( '%d percent complete', 'learnomy' ), $percent );
?>
<div class="lrn-progress-bar" role="progressbar"
	aria-valuenow="<?php echo esc_attr( $percent ); ?>"
	aria-valuemin="0"
	aria-valuemax="100"
	aria-label="<?php echo esc_attr( $progress_label ); ?>">
	<div class="lrn-progress-bar__fill" style="width: <?php echo esc_attr( $percent ); ?>%;"></div>
</div>
<?php if ( $progress_show_label ) : ?>
	<span class="lrn-progress-bar__label" aria-hidden="true"><?php echo esc_html( $percent ); ?>% <?php esc_html_e( 'complete', 'learnomy' ); ?></span>
<?php endif; ?>
<?php unset( $progress_show_label ); ?>
