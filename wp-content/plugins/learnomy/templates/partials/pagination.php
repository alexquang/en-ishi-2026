<?php
/**
 * Reusable archive pagination nav.
 *
 * Single-sourced so every paginated Learnomy archive (catalog, category, and
 * any Pro archive that opts in) renders the identical control — same markup,
 * same a11y, same look in a browser or an app webview. No surface invents its
 * own pager.
 *
 * Expected in scope (set by the including template):
 *   $page            (int)      current page, 1-based
 *   $total_pages     (int)      total page count
 *   $pagination_url  (callable) fn( int $n ): string — canonical URL for page N
 *   $pagination_aria (string)   optional aria-label for the <nav>
 *
 * Renders nothing when there is a single page.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$page        = isset( $page ) ? max( 1, (int) $page ) : 1;
$total_pages = isset( $total_pages ) ? (int) $total_pages : 1;

if ( $total_pages <= 1 || ! isset( $pagination_url ) || ! is_callable( $pagination_url ) ) {
	return;
}

$pagination_aria = isset( $pagination_aria ) ? (string) $pagination_aria : __( 'Pagination', 'learnomy' );

// Compact page-number window: 1 ... (page-1) [page] (page+1) ... last.
$window = array();
for ( $i = 1; $i <= $total_pages; $i++ ) {
	if ( 1 === $i || $i === $total_pages || ( $i >= $page - 1 && $i <= $page + 1 ) ) {
		$window[] = $i;
	} elseif ( '…' !== end( $window ) ) {
		$window[] = '…';
	}
}
?>
<nav class="lrn-pagination" aria-label="<?php echo esc_attr( $pagination_aria ); ?>">
	<div class="lrn-pagination__status" aria-live="polite">
		<?php
		/* translators: 1: current page, 2: total pages */
		echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'learnomy' ), $page, $total_pages ) );
		?>
	</div>
	<ul class="lrn-pagination__list" role="list">
		<?php
		$lrn_chevron_left  = '<svg class="lrn-pagination__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="15 18 9 12 15 6"></polyline></svg>';
		$lrn_chevron_right = '<svg class="lrn-pagination__chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="9 18 15 12 9 6"></polyline></svg>';
		// SVG markup is a fixed, author-controlled string (no user data) — safe to echo.
		?>
		<?php if ( $page > 1 ) : ?>
			<li>
				<a class="lrn-pagination__btn lrn-pagination__btn--nav"
					href="<?php echo esc_url( $pagination_url( $page - 1 ) ); ?>"
					rel="prev">
					<?php echo $lrn_chevron_left; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'Previous', 'learnomy' ); ?></span>
				</a>
			</li>
		<?php else : ?>
			<li>
				<span class="lrn-pagination__btn lrn-pagination__btn--nav lrn-pagination__btn--disabled" aria-disabled="true">
					<?php echo $lrn_chevron_left; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php esc_html_e( 'Previous', 'learnomy' ); ?></span>
				</span>
			</li>
		<?php endif; ?>

		<?php foreach ( $window as $entry ) : ?>
			<?php if ( '…' === $entry ) : ?>
				<li>
					<span class="lrn-pagination__ellipsis" aria-hidden="true">…</span>
				</li>
			<?php elseif ( (int) $entry === $page ) : ?>
				<li>
					<span class="lrn-pagination__btn lrn-pagination__btn--current" aria-current="page">
						<?php echo esc_html( (string) $entry ); ?>
					</span>
				</li>
			<?php else : ?>
				<li>
					<a class="lrn-pagination__btn" href="<?php echo esc_url( $pagination_url( (int) $entry ) ); ?>">
						<?php echo esc_html( (string) $entry ); ?>
					</a>
				</li>
			<?php endif; ?>
		<?php endforeach; ?>

		<?php if ( $page < $total_pages ) : ?>
			<li>
				<a class="lrn-pagination__btn lrn-pagination__btn--nav"
					href="<?php echo esc_url( $pagination_url( $page + 1 ) ); ?>"
					rel="next">
					<span><?php esc_html_e( 'Next', 'learnomy' ); ?></span>
					<?php echo $lrn_chevron_right; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</a>
			</li>
		<?php else : ?>
			<li>
				<span class="lrn-pagination__btn lrn-pagination__btn--nav lrn-pagination__btn--disabled" aria-disabled="true">
					<span><?php esc_html_e( 'Next', 'learnomy' ); ?></span>
					<?php echo $lrn_chevron_right; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
			</li>
		<?php endif; ?>
	</ul>
</nav>
