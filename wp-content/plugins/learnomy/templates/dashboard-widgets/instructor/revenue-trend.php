<?php
/**
 * Instructor revenue-trend widget body.
 *
 * Renders a 30-day daily-commission bar chart using only CSS — no
 * external charting library. The chart fills missing days with zero
 * so the timeline stays continuous; the tallest day sets the scale.
 *
 * Boundary: scoped to commissions where recipient_type='instructor' AND
 * recipient_id = current_user_id via Commission::daily_trend_for_instructor.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$trend = \Learnomy\Models\Commission::daily_trend_for_instructor( (int) $user_id, 30 );
if ( empty( $trend ) ) {
	return;
}

// Pad sparse days with zero so the bar chart timeline is continuous.
$days_filled = array();
$peak        = 0.0;
$total       = 0.0;
for ( $i = 29; $i >= 0; $i-- ) {
	$key                 = gmdate( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
	$value               = isset( $trend[ $key ] ) ? (float) $trend[ $key ] : 0.0;
	$days_filled[ $key ] = $value;
	if ( $value > $peak ) {
		$peak = $value;
	}
	$total += $value;
}

$settings        = (array) get_option( 'learnomy_settings', array() );
$currency_symbol = isset( $settings['currency_symbol'] ) && '' !== $settings['currency_symbol']
	? (string) $settings['currency_symbol']
	: '$';
?>
<div class="lrn-revenue-trend">
	<div class="lrn-revenue-trend__header">
		<div>
			<span class="lrn-revenue-trend__total">
				<?php echo esc_html( \Learnomy\format_price( (float) $total ) ); ?>
			</span>
			<span class="lrn-revenue-trend__total-label">
				<?php esc_html_e( '30-day total', 'learnomy' ); ?>
			</span>
		</div>
		<?php if ( $peak > 0 ) : ?>
			<span class="lrn-revenue-trend__peak">
				<?php
				printf(
					/* translators: %s: peak day amount */
					esc_html__( 'Peak: %s', 'learnomy' ),
					esc_html( \Learnomy\format_price( (float) $peak ) )
				);
				?>
			</span>
		<?php endif; ?>
	</div>

	<ol class="lrn-revenue-trend__bars" role="img"
		aria-label="<?php esc_attr_e( 'Daily commission earnings, last 30 days', 'learnomy' ); ?>">
		<?php
		foreach ( $days_filled as $day => $value ) :
			$pct       = $peak > 0 ? (int) round( ( $value / $peak ) * 100 ) : 0;
			$day_label = date_i18n( get_option( 'date_format' ), strtotime( $day ) );
			?>
			<li class="lrn-revenue-trend__day"
				title="<?php echo esc_attr( sprintf( /* translators: 1: date, 2: amount */ __( '%1$s: %2$s', 'learnomy' ), $day_label, \Learnomy\format_price( (float) $value ) ) ); ?>">
				<span class="lrn-revenue-trend__bar" style="--pct: <?php echo esc_attr( (string) max( 1, $pct ) ); ?>%"></span>
			</li>
		<?php endforeach; ?>
	</ol>

	<div class="lrn-revenue-trend__legend">
		<span><?php echo esc_html( date_i18n( 'M j', strtotime( '-29 days' ) ) ); ?></span>
		<span><?php echo esc_html( date_i18n( 'M j', time() ) ); ?></span>
	</div>
</div>
