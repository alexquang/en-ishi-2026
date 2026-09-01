<?php
/**
 * Course catalog — GRID variant (default).
 *
 * Header + horizontal filter bar, then the shared results partial. The
 * full-width responsive card grid is the long-standing default layout.
 *
 * Variables (injected by Template_Loader::render via Catalog_View_Data):
 *   $courses, $categories, $search, $current_cat, $sort, $total_count,
 *   $total_pages, $page, $lrn_wishlist, $per_page.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// Header needs the count before the shared results partial runs.
$total_count = isset( $total_count ) ? (int) $total_count : count( $courses );
?>
<main id="lrn-catalog-main" class="lrn-container" data-wp-interactive="learnomy/catalog" data-wp-init="callbacks.onInit">

	<?php require \Learnomy\template_path( 'catalog/partials/access-notice.php' ); ?>

	<?php
	// No trail on the catalog itself. It IS the root now for every reader, not
	// just anonymous ones (breadcrumb_root() stopped re-rooting at the account
	// hub on sign-in — Basecamp 10184419200), and a root that points at itself
	// renders "Courses › Courses". A single unlinked self-crumb is chrome, not
	// orientation. Every page BELOW the catalog still carries the full trail,
	// which is what climbing back needs. Mirrored in sidebar.php.
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
			<p
				class="lrn-archive-header__count"
				aria-live="polite"
				aria-atomic="true"
				id="lrn-catalog-count"
			>
				<?php
				/* translators: %d: total number of courses matching the active filters */
				echo esc_html( sprintf( _n( '%d course available', '%d courses available', $total_count, 'learnomy' ), $total_count ) );
				?>
			</p>
		<?php endif; ?>
	</header>

	<nav class="lrn-filter-bar" aria-label="<?php esc_attr_e( 'Course filters', 'learnomy' ); ?>">
		<form method="get" action="" role="search" class="lrn-filter-bar__form">
		<div class="lrn-filter-bar__search lrn-search-suggest-wrap">
			<label for="lrn-search" class="screen-reader-text"><?php esc_html_e( 'Search courses', 'learnomy' ); ?></label>
			<?php require \Learnomy\template_path( 'catalog/partials/search-field.php' ); ?>
			<?php
			/*
			 * A visible way to run the search.
			 *
			 * Typing deliberately does NOT filter as you type: a debounced
			 * reload used to fire mid-word and eat characters, so it was
			 * removed (Basecamp 10042899710). Enter submits this form and the
			 * server returns the filtered grid, which works correctly.
			 *
			 * But this layout shipped with no submit control at all - its only
			 * two buttons are the view toggles - so Enter was the sole
			 * affordance and nothing on screen said so. A visitor types, waits,
			 * sees the full catalogue and concludes search is broken. That is
			 * exactly how Basecamp 10171341067 was filed.
			 *
			 * The sidebar layout has always had its "Apply" submit; this one
			 * simply never got one.
			 */
			?>
		</div>

		<button type="submit" class="lrn-btn lrn-btn--primary lrn-filter-bar__submit">
			<?php esc_html_e( 'Search', 'learnomy' ); ?>
		</button>

		<div class="lrn-filter-bar__category">
			<label for="lrn-category" class="screen-reader-text"><?php esc_html_e( 'Filter by category', 'learnomy' ); ?></label>
			<select id="lrn-category" name="cat" class="lrn-select" data-wp-on--change="actions.setCategory">
				<option value=""><?php esc_html_e( 'All Categories', 'learnomy' ); ?></option>
				<?php foreach ( $categories as $cat ) : ?>
					<option value="<?php echo esc_attr( $cat->slug ); ?>" <?php selected( $current_cat, $cat->slug ); ?>>
						<?php echo esc_html( $cat->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="lrn-filter-bar__sort">
			<label for="lrn-sort" class="screen-reader-text"><?php esc_html_e( 'Sort by', 'learnomy' ); ?></label>
			<select id="lrn-sort" name="sort" class="lrn-select" data-wp-on--change="actions.setSort">
				<option value="latest" <?php selected( $sort, 'latest' ); ?>><?php esc_html_e( 'Newest First', 'learnomy' ); ?></option>
				<option value="popular" <?php selected( $sort, 'popular' ); ?>><?php esc_html_e( 'Most Popular', 'learnomy' ); ?></option>
				<option value="rating" <?php selected( $sort, 'rating' ); ?>><?php esc_html_e( 'Highest Rated', 'learnomy' ); ?></option>
				<option value="price_asc" <?php selected( $sort, 'price_asc' ); ?>><?php esc_html_e( 'Price: Low to High', 'learnomy' ); ?></option>
				<option value="price_desc" <?php selected( $sort, 'price_desc' ); ?>><?php esc_html_e( 'Price: High to Low', 'learnomy' ); ?></option>
			</select>
		</div>

		<div class="lrn-filter-bar__view-toggle">
			<button type="button" class="lrn-btn lrn-btn--ghost lrn-view-btn" aria-label="<?php esc_attr_e( 'Grid view', 'learnomy' ); ?>" data-wp-on--click="actions.setGridView" data-wp-class--is-active="state.isGridView">
				<i data-lucide="layout-grid" aria-hidden="true"></i>
			</button>
			<button type="button" class="lrn-btn lrn-btn--ghost lrn-view-btn" aria-label="<?php esc_attr_e( 'List view', 'learnomy' ); ?>" data-wp-on--click="actions.setListView" data-wp-class--is-active="state.isListView">
				<i data-lucide="list" aria-hidden="true"></i>
			</button>
		</div>
		</form>
	</nav>

	<?php require \Learnomy\template_path( 'catalog/partials/results.php' ); ?>

</main>
