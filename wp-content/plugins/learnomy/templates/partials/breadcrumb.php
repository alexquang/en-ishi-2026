<?php
/**
 * Breadcrumb partial.
 *
 * Renders an ordered-list breadcrumb. Mobile collapses to a single back-link
 * to the parent (last entry with an href) — handled in `breadcrumb.css`.
 *
 * Expects:
 *   $breadcrumb_items (array<int, array{label:string, href?:string|null, icon?:string|null}>)
 *     Each entry needs a label. Entries without href render as the current
 *     page (aria-current="page"). The last entry should always be the
 *     current page with no href.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$breadcrumb_items = isset( $breadcrumb_items ) && is_array( $breadcrumb_items ) ? $breadcrumb_items : [];
if ( empty( $breadcrumb_items ) ) {
	return;
}

// In the app WebView the native shell owns global navigation — drop any crumb
// that links to the site root so a tap can't route the WebView out of the
// app's content scope. Intra-content crumbs (course → category) stay.
if ( function_exists( '\\Learnomy\\is_app_request' ) && \Learnomy\is_app_request() ) {
	$lrn_home         = untrailingslashit( home_url( '/' ) );
	$breadcrumb_items = array_values(
		array_filter(
			$breadcrumb_items,
			static function ( $item ) use ( $lrn_home ): bool {
				$href = isset( $item['href'] ) ? untrailingslashit( (string) $item['href'] ) : '';
				return '' === $href || $href !== $lrn_home;
			}
		)
	);
	if ( empty( $breadcrumb_items ) ) {
		return;
	}
}

// Find the parent (last item with an href) — used for the mobile back-link.
//
// These locals are prefixed because a partial is `require`d into the CALLER's
// variable scope: a bare $total here silently overwrites the caller's own
// $total. That is not hypothetical — the instructor Reports page computed its
// pager from $total after including this partial and got the breadcrumb's item
// count (3) instead of its row total (52), so the pager vanished and pages 2+
// of every roster were unreachable. Keep every local in a shared partial
// prefixed.
$parent_index    = null;
$lrn_crumb_total = count( $breadcrumb_items );
for ( $i = $lrn_crumb_total - 2; $i >= 0; $i-- ) {
	if ( ! empty( $breadcrumb_items[ $i ]['href'] ) ) {
		$parent_index = $i;
		break;
	}
}
?>
<nav class="lrn-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'learnomy' ); ?>">
	<ol class="lrn-breadcrumb__list">
		<?php foreach ( $breadcrumb_items as $i => $item ) : ?>
			<?php
			$label = isset( $item['label'] ) ? (string) $item['label'] : '';
			$href  = isset( $item['href'] ) && '' !== $item['href'] ? (string) $item['href'] : null;
			$icon  = isset( $item['icon'] ) && '' !== $item['icon'] ? (string) $item['icon'] : null;
			if ( '' === $label ) {
				continue;
			}
			$item_classes = [ 'lrn-breadcrumb__item' ];
			if ( null !== $parent_index && $i === $parent_index ) {
				$item_classes[] = 'lrn-breadcrumb__item--parent';
			}
			?>
			<li class="<?php echo esc_attr( implode( ' ', $item_classes ) ); ?>">
				<?php if ( null !== $href ) : ?>
					<a class="lrn-breadcrumb__link" href="<?php echo esc_url( $href ); ?>">
						<?php if ( null !== $icon ) : ?>
							<i data-lucide="<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i>
						<?php endif; ?>
						<span class="lrn-breadcrumb__label"><?php echo esc_html( $label ); ?></span>
					</a>
				<?php else : ?>
					<span class="lrn-breadcrumb__current" aria-current="page">
						<?php if ( null !== $icon ) : ?>
							<i data-lucide="<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></i>
						<?php endif; ?>
						<span class="lrn-breadcrumb__label"><?php echo esc_html( $label ); ?></span>
					</span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</nav>
