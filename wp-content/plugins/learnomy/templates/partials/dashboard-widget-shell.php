<?php
/**
 * Single shell partial used by both student + instructor dashboards.
 *
 * Locals (set by Dashboard_Widget_Registry::render):
 *   $lrn_widget  — validated widget definition array.
 *   $lrn_context — full dashboard context (user, view_data, surface, route).
 *
 * Shell modes:
 *   - 'card'   → wrapped in .lrn-card .lrn-dashboard__section-card (default)
 *   - 'naked'  → no wrapper around the rendered body
 *   - 'flush'  → card wrapper with zero padding (for tables / grids that
 *                already supply their own edge padding)
 *   - 'hero'   → full-bleed visual block, no heading wrapper (the
 *                continue-learning CTA, celebration banners)
 *   - 'none'   → no shell at all, body is emitted raw (stats row, breadcrumbs)
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$shell     = $lrn_widget['shell'] ?? 'card';
$title     = $lrn_widget['title'] ?? '';
$widget_id = (string) $lrn_widget['id'];
$body      = (string) call_user_func( $lrn_widget['render'], $lrn_context );

if ( '' === trim( $body ) ) {
	// A widget that rendered to nothing should not leave an empty heading
	// on screen. is_available() is the polite gate; this is the safety net.
	return;
}

if ( 'none' === $shell ) {
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- widget owns its escaping.
	return;
}

if ( 'hero' === $shell ) {
	?>
	<section class="lrn-dashboard__section lrn-dashboard__hero" data-widget-id="<?php echo esc_attr( $widget_id ); ?>">
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</section>
	<?php
	return;
}

$heading_id = 'lrn-widget-' . sanitize_html_class( $widget_id ) . '-heading';
?>
<section
	class="lrn-dashboard__section"
	data-widget-id="<?php echo esc_attr( $widget_id ); ?>"
	<?php if ( $title ) : ?>
		aria-labelledby="<?php echo esc_attr( $heading_id ); ?>"
	<?php endif; ?>
>
	<?php if ( $title ) : ?>
		<h2 id="<?php echo esc_attr( $heading_id ); ?>" class="lrn-dashboard__section-title">
			<?php echo esc_html( $title ); ?>
		</h2>
	<?php endif; ?>

	<?php
	$card_classes = 'lrn-card lrn-dashboard__section-card';
	if ( 'flush' === $shell ) {
		$card_classes .= ' lrn-dashboard__section-card--flush';
	}
	?>

	<?php if ( 'naked' === $shell ) : ?>
		<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php else : ?>
		<div class="<?php echo esc_attr( $card_classes ); ?>">
			<?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	<?php endif; ?>
</section>
