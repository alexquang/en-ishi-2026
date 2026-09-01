<?php
/**
 * Shared premium pagination partial.
 *
 * Usage:
 *   <?php
 *   include learnomy_partial( 'pagination' );
 *   // or:
 *   $lrn_pagination = array(
 *       'current'  => (int) $paged,
 *       'total'    => (int) $total_pages,
 *       'results'  => (int) $total_results,  // optional — shows "X results"
 *       'per_page' => 20,                    // optional — shows "Showing 1–20"
 *       'base_url' => $base_url,             // optional — defaults to current admin URL
 *       'param'    => 'paged',               // optional — override query-arg name
 *   );
 *   include LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
 *
 * Visual rules:
 *   — One row: "Showing 1–20 of 345" on the left, numbered controls on the right.
 *   — Lucide chevrons for Prev/Next, not ascii `«` / `»`.
 *   — Current page pill filled with --lrn-accent, rest are ghost.
 *   — Ellipsis collapses long page lists (e.g. 1 … 4 5 [6] 7 8 … 50).
 *   — Matches the skeleton's radius / border / transition timings.
 *
 * Every list view (admin + frontend) should include this partial — no more
 * raw paginate_links() defaults, no ad-hoc pagination markup anywhere else.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

/**
 * @var array<string,mixed> $lrn_pagination
 */
$lrn_pagination = isset( $lrn_pagination ) && is_array( $lrn_pagination ) ? $lrn_pagination : array();

$current     = max( 1, (int) ( $lrn_pagination['current'] ?? 1 ) );
$total_pages = max( 1, (int) ( $lrn_pagination['total'] ?? 1 ) );
$results     = isset( $lrn_pagination['results'] ) ? max( 0, (int) $lrn_pagination['results'] ) : null;
$per_page    = isset( $lrn_pagination['per_page'] ) ? max( 1, (int) $lrn_pagination['per_page'] ) : 20;
$param       = ! empty( $lrn_pagination['param'] ) ? sanitize_key( (string) $lrn_pagination['param'] ) : 'paged';

// Default base URL to the current admin request minus any existing page param.
$base_url = $lrn_pagination['base_url'] ?? remove_query_arg( $param );

/**
 * Build the list of pages + ellipses to show. Pattern:
 *   [1] 2 3 4 5 … 50                      — early pages
 *   1 … 22 23 [24] 25 26 … 50              — middle
 *   1 … 46 47 48 49 [50]                   — late pages
 *
 * Always shows first + last page. Shows 2 neighbours either side of current.
 *
 * @return array<int,int|'…'>
 */
$build_page_list = static function ( int $current, int $total ): array {
	if ( $total <= 7 ) {
		return range( 1, $total );
	}

	$pages = array( 1 );

	$start = max( 2, $current - 2 );
	$end   = min( $total - 1, $current + 2 );

	if ( $start > 2 ) {
		$pages[] = '…';
	}

	for ( $i = $start; $i <= $end; $i++ ) {
		$pages[] = $i;
	}

	if ( $end < $total - 1 ) {
		$pages[] = '…';
	}

	$pages[] = $total;

	return $pages;
};

$pages = $build_page_list( $current, $total_pages );

// Lucide chevron icons — match the plugin-wide icon system.
$chevron_left  = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>';
$chevron_right = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>';

// Short range for "Showing X–Y of N".
$from = null === $results ? null : min( ( ( $current - 1 ) * $per_page ) + 1, $results );
$to   = null === $results ? null : min( $current * $per_page, $results );

// Hide the component entirely when there's nothing to page through — the
// empty state below a list is the correct UI here.
if ( $total_pages <= 1 && null === $results ) {
	return;
}
?>
<nav class="lrn-pagination" role="navigation" aria-label="<?php esc_attr_e( 'Pagination', 'learnomy' ); ?>">
	<?php if ( null !== $results ) : ?>
		<div class="lrn-pagination__status">
			<?php if ( $results > 0 ) : ?>
				<?php
				printf(
					/* translators: 1: first row number 2: last row number 3: total */
					esc_html__( 'Showing %1$d-%2$d of %3$d', 'learnomy' ),
					(int) $from,
					(int) $to,
					(int) $results
				);
				?>
			<?php else : ?>
				<?php esc_html_e( 'No results', 'learnomy' ); ?>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $total_pages > 1 ) : ?>
		<ul class="lrn-pagination__list">
			<li>
				<?php if ( $current > 1 ) : ?>
					<a class="lrn-pagination__btn lrn-pagination__btn--nav"
						href="<?php echo esc_url( add_query_arg( $param, $current - 1, $base_url ) ); ?>"
						rel="prev"
						aria-label="<?php esc_attr_e( 'Previous page', 'learnomy' ); ?>">
						<?php echo $chevron_left; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static icon SVG ?>
					</a>
				<?php else : ?>
					<span class="lrn-pagination__btn lrn-pagination__btn--nav lrn-pagination__btn--disabled"
							aria-disabled="true">
						<?php echo $chevron_left; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
				<?php endif; ?>
			</li>

			<?php foreach ( $pages as $page ) : ?>
				<li>
					<?php if ( '…' === $page ) : ?>
						<span class="lrn-pagination__ellipsis" aria-hidden="true">…</span>
					<?php elseif ( (int) $page === $current ) : ?>
						<span class="lrn-pagination__btn lrn-pagination__btn--current"
								aria-current="page"><?php echo esc_html( (string) $page ); ?></span>
					<?php else : ?>
						<a class="lrn-pagination__btn"
							href="<?php echo esc_url( add_query_arg( $param, (int) $page, $base_url ) ); ?>"
							aria-label="
							<?php
								/* translators: %d: page number */
								echo esc_attr( sprintf( __( 'Page %d', 'learnomy' ), (int) $page ) );
							?>
							"><?php echo esc_html( (string) $page ); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>

			<li>
				<?php if ( $current < $total_pages ) : ?>
					<a class="lrn-pagination__btn lrn-pagination__btn--nav"
						href="<?php echo esc_url( add_query_arg( $param, $current + 1, $base_url ) ); ?>"
						rel="next"
						aria-label="<?php esc_attr_e( 'Next page', 'learnomy' ); ?>">
						<?php echo $chevron_right; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</a>
				<?php else : ?>
					<span class="lrn-pagination__btn lrn-pagination__btn--nav lrn-pagination__btn--disabled"
							aria-disabled="true">
						<?php echo $chevron_right; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</span>
				<?php endif; ?>
			</li>
		</ul>
	<?php endif; ?>
</nav>
