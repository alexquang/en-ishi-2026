<?php
/**
 * Dashboard stat card partial.
 *
 * Expects:
 *   $icon  (string) — Lucide icon name, e.g. 'graduation-cap'
 *   $count (int|string) — the number or value to display prominently
 *   $label (string) — human-readable label below the count
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$icon  = isset( $icon ) ? $icon : 'bar-chart-3';
$count = isset( $count ) ? $count : 0;
$label = isset( $label ) ? $label : '';
?>
<div class="lrn-stat-card lrn-card"
	aria-label="<?php echo esc_attr( sprintf( '%1$s: %2$s', $label, $count ) ); ?>">
	<?php if ( $icon ) : ?>
		<i data-lucide="<?php echo esc_attr( $icon ); ?>" class="lrn-stat-card__icon" aria-hidden="true"></i>
	<?php endif; ?>
	<div class="lrn-stat-card__content">
		<strong class="lrn-stat-card__count"><?php echo esc_html( $count ); ?></strong>
		<span class="lrn-stat-card__label"><?php echo esc_html( $label ); ?></span>
	</div>
</div>
