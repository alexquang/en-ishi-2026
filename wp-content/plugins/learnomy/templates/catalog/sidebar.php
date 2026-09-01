<?php
/**
 * Course catalog — SIDEBAR variant.
 *
 * A left filter rail (search + category facets + sort + a hookable facet
 * region) with the results grid on the right. Reuses the SAME Interactivity
 * wrapper, search/sort bindings, and shared results partial as the grid
 * variant, so the GET-param filter contract (s / cat / sort / lrn_page) and
 * `.lrn-courses-grid` + `data-wp-class--lrn-list-layout` are preserved.
 *
 * Category renders as a facet list of links (each sets ?cat=); a hidden cat
 * field keeps the active category through a no-JS search/sort submit.
 *
 * Variables: same as catalog/grid.php (injected by Catalog_View_Data).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$total_count   = isset( $total_count ) ? (int) $total_count : count( $courses );
$current_cat   = isset( $current_cat ) ? (string) $current_cat : '';
$lrn_catalogue = \Learnomy\route_url( 'catalog' );
?>
<main id="lrn-catalog-main" class="lrn-container lrn-catalog--sidebar" data-wp-interactive="learnomy/catalog" data-wp-init="callbacks.onInit">

	<?php require \Learnomy\template_path( 'catalog/partials/access-notice.php' ); ?>

	<?php
	// The catalog is the root of the course hierarchy and every category climbs
	// back to it, so it needs a positional signal of its own.
	//
	// This block is DUPLICATED in catalog/grid.php on purpose: the catalog has
	// two layouts chosen by the `catalog_layout` setting, and a change made to
	// only one silently applies to only some sites. I added it to grid.php
	// first, saw nothing render, and spent several minutes blaming the opcode
	// cache before noticing this site runs `sidebar`. If a third layout is ever
	// added, this is the third place to touch -- or extract all three into a
	// partial, which is the better answer once there are three.
	// No trail on the catalog itself. It IS the root now for every reader, not
	// just anonymous ones (breadcrumb_root() stopped re-rooting at the account
	// hub on sign-in — Basecamp 10184419200), and a root that points at itself
	// renders "Courses › Courses". A single unlinked self-crumb is chrome, not
	// orientation. Every page BELOW the catalog still carries the full trail,
	// which is what climbing back needs. Mirrored in grid.php.
	?>

	<header class="lrn-archive-header">
		<span class="lrn-archive-header__eyebrow"><?php esc_html_e( 'Learn', 'learnomy' ); ?></span>
		<h1 class="lrn-archive-header__title">
			<?php
			/* translators: %s: plural label for courses (e.g. "Courses") */
			printf( esc_html__( 'All %s', 'learnomy' ), esc_html( \Learnomy\course_label( true ) ) );
			?>
		</h1>
		<?php if ( $total_count > 0 ) : ?>
			<p class="lrn-archive-header__count" aria-live="polite" aria-atomic="true" id="lrn-catalog-count">
				<?php
				/* translators: %d: total number of courses matching the active filters */
				echo esc_html( sprintf( _n( '%d course available', '%d courses available', $total_count, 'learnomy' ), $total_count ) );
				?>
			</p>
		<?php endif; ?>
	</header>

	<button
		type="button"
		class="lrn-catalog-filters-toggle"
		data-wp-on--click="actions.toggleFilters"
		data-wp-bind--aria-expanded="state.filtersExpanded"
		aria-controls="lrn-catalog-filters"
	>
		<i data-lucide="sliders-horizontal" aria-hidden="true"></i>
		<?php esc_html_e( 'Filters', 'learnomy' ); ?>
	</button>

	<div class="lrn-catalog-layout" data-wp-class--lrn-filters-open="state.filtersExpanded">

		<aside class="lrn-catalog-filters" id="lrn-catalog-filters" aria-label="<?php esc_attr_e( 'Course filters', 'learnomy' ); ?>">
			<form method="get" action="" role="search" class="lrn-catalog-filters__form">
				<input type="hidden" name="cat" value="<?php echo esc_attr( $current_cat ); ?>">

				<div class="lrn-catalog-filters__group lrn-search-suggest-wrap">
					<label for="lrn-search" class="lrn-catalog-filters__heading"><?php esc_html_e( 'Search', 'learnomy' ); ?></label>
					<?php require \Learnomy\template_path( 'catalog/partials/search-field.php' ); ?>
				</div>

				<div class="lrn-catalog-filters__group">
					<h2 class="lrn-catalog-filters__heading"><?php esc_html_e( 'Categories', 'learnomy' ); ?></h2>
					<ul class="lrn-facet-list" role="list">
						<li>
							<a
								href="<?php echo esc_url( $lrn_catalogue ); ?>"
								class="lrn-facet<?php echo '' === $current_cat ? ' is-active' : ''; ?>"
								<?php echo '' === $current_cat ? 'aria-current="page"' : ''; ?>
							>
								<?php esc_html_e( 'All courses', 'learnomy' ); ?>
							</a>
						</li>
						<?php foreach ( $categories as $cat ) : ?>
							<?php $is_active = ( $current_cat === $cat->slug ); ?>
							<li>
								<a
									href="<?php echo esc_url( add_query_arg( 'cat', $cat->slug, $lrn_catalogue ) ); ?>"
									class="lrn-facet<?php echo $is_active ? ' is-active' : ''; ?>"
									<?php echo $is_active ? 'aria-current="page"' : ''; ?>
								>
									<?php echo esc_html( $cat->name ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>

				<div class="lrn-catalog-filters__group">
					<label for="lrn-sort" class="lrn-catalog-filters__heading"><?php esc_html_e( 'Sort by', 'learnomy' ); ?></label>
					<select id="lrn-sort" name="sort" class="lrn-select" data-wp-on--change="actions.setSort">
						<option value="latest" <?php selected( $sort, 'latest' ); ?>><?php esc_html_e( 'Newest First', 'learnomy' ); ?></option>
						<option value="popular" <?php selected( $sort, 'popular' ); ?>><?php esc_html_e( 'Most Popular', 'learnomy' ); ?></option>
						<option value="rating" <?php selected( $sort, 'rating' ); ?>><?php esc_html_e( 'Highest Rated', 'learnomy' ); ?></option>
						<option value="price_asc" <?php selected( $sort, 'price_asc' ); ?>><?php esc_html_e( 'Price: Low to High', 'learnomy' ); ?></option>
						<option value="price_desc" <?php selected( $sort, 'price_desc' ); ?>><?php esc_html_e( 'Price: High to Low', 'learnomy' ); ?></option>
					</select>
				</div>

				<?php
				/**
				 * Hookable facet region in the catalog sidebar. Extensions (or Pro)
				 * render extra filter facets here — e.g. price range, association,
				 * level. Placement hook only; no output by default.
				 *
				 * @param string $current_cat The active category slug.
				 */
				do_action( 'learnomy_catalog_filter_facets', $current_cat );
				?>

				<noscript>
					<button type="submit" class="lrn-btn lrn-btn--primary"><?php esc_html_e( 'Apply', 'learnomy' ); ?></button>
				</noscript>
			</form>
		</aside>

		<?php
		$results_per_page = isset( $per_page ) ? (int) $per_page : 12;
		$results_first    = $total_count > 0 ? ( ( $page - 1 ) * $results_per_page ) + 1 : 0;
		$results_last     = min( $page * $results_per_page, $total_count );
		?>
		<div class="lrn-catalog-results">
			<div class="lrn-catalog-results__bar">
				<?php if ( $total_count > 0 ) : ?>
					<p class="lrn-catalog-results__showing">
						<?php
						printf(
							/* translators: 1: first result index, 2: last result index, 3: total result count */
							esc_html__( 'Showing %1$d-%2$d of %3$d', 'learnomy' ),
							(int) $results_first,
							(int) $results_last,
							(int) $total_count
						);
						?>
					</p>
				<?php endif; ?>
				<div class="lrn-filter-bar__view-toggle">
					<button type="button" class="lrn-btn lrn-btn--ghost lrn-view-btn" aria-label="<?php esc_attr_e( 'Grid view', 'learnomy' ); ?>" data-wp-on--click="actions.setGridView" data-wp-class--is-active="state.isGridView">
						<i data-lucide="layout-grid" aria-hidden="true"></i>
					</button>
					<button type="button" class="lrn-btn lrn-btn--ghost lrn-view-btn" aria-label="<?php esc_attr_e( 'List view', 'learnomy' ); ?>" data-wp-on--click="actions.setListView" data-wp-class--is-active="state.isListView">
						<i data-lucide="list" aria-hidden="true"></i>
					</button>
				</div>
			</div>

			<?php require \Learnomy\template_path( 'catalog/partials/results.php' ); ?>
		</div>

	</div>

</main>
