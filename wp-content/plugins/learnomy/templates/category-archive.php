<?php
/**
 * Category archive — courses filtered by a single category.
 *
 * Variables available:
 *   $route (string) 'category'
 *   $slug  (string) category slug
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

/*
 * $view_data variables (injected by Template_Loader via Category_Archive_View_Data):
 *   $category, $lrn_wishlist, $courses, $subcategories
 * Plus $slug from the route.
 */

// The not-found verdict is made by the view-data resolver and honoured by
// Template_Loader BEFORE any output, so this guard is a safety net only (a
// third party swapping the resolver via `learnomy_view_data_resolvers`). It
// routes through the one shared renderer instead of repeating the incantation.
if ( empty( $category ) ) {
	\Learnomy\Template_Loader::render_not_found();
	return;
}
?>
<div class="lrn-container lrn-category-archive">

	<?php
	// Root is the catalog for EVERY reader now — breadcrumb_root() no longer
	// swaps it for the account hub when someone is signed in (Basecamp
	// 10184419200). The Courses crumb that used to be appended for logged-in
	// readers would duplicate that root, so it is gone.
	$breadcrumb_items = [ \Learnomy\breadcrumb_root() ];

	// The parent chain, root first. Without it a child category rendered as
	// "Home > Courses > Typography" with Design missing, so a learner could see
	// where they were but not how they got there, and could not climb one
	// level. That matters more now that a parent listing rolls up its
	// descendants: the hierarchy is load-bearing, not decorative.
	foreach ( \Learnomy\Models\Category::ancestors( (int) $category->id ) as $lrn_ancestor ) {
		$breadcrumb_items[] = [
			'label' => $lrn_ancestor->name,
			'href'  => \Learnomy\route_url( 'category', [ 'slug' => $lrn_ancestor->slug ] ),
		];
	}

	$breadcrumb_items[] = [
		'label' => $category->name,
	];
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<header class="lrn-category-archive__header">
		<h1 class="lrn-category-archive__title"><?php echo esc_html( $category->name ); ?></h1>

		<?php if ( ! empty( $category->description ) ) : ?>
			<p class="lrn-category-archive__description"><?php echo esc_html( $category->description ); ?></p>
		<?php endif; ?>

		<p class="lrn-category-archive__count">
			<?php
			$lrn_cat_total = isset( $total_count ) ? (int) $total_count : count( $courses );
			/* translators: %d: number of courses in this category */
			echo esc_html( sprintf( _n( '%d course', '%d courses', $lrn_cat_total, 'learnomy' ), $lrn_cat_total ) );
			?>
		</p>
	</header>

	<!-- Sub-categories (if any) -->
	<?php if ( $subcategories ) : ?>
		<nav class="lrn-subcategory-list" aria-label="<?php esc_attr_e( 'Sub-categories', 'learnomy' ); ?>">
			<?php
			foreach ( $subcategories as $sub ) :
				$sub_url = \Learnomy\route_url( 'category', array( 'slug' => (string) $sub->slug ) );
				?>
				<a href="<?php echo esc_url( $sub_url ); ?>" class="lrn-btn lrn-btn--ghost lrn-btn--sm">
					<?php echo esc_html( $sub->name ); ?>
					<?php if ( (int) $sub->course_count > 0 ) : ?>
						<span class="lrn-badge lrn-badge--blue"><?php echo esc_html( (int) $sub->course_count ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>
	<?php endif; ?>

	<!-- Course grid -->
	<?php if ( $courses ) : ?>
		<div class="lrn-grid lrn-courses-grid" role="list">
			<?php foreach ( $courses as $course ) : ?>
				<div role="listitem">
					<?php include \Learnomy\template_path( 'partials/course-card.php' ); ?>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		// Canonical paginated URLs: /courses/category/:slug/page/N/.
		$lrn_cat_slug    = (string) ( $slug ?? $category->slug );
		$pagination_url  = static function ( int $n ) use ( $lrn_cat_slug ): string {
			return \Learnomy\route_url(
				'category',
				array_merge( array( 'slug' => $lrn_cat_slug ), $n > 1 ? array( 'paged' => $n ) : array() )
			);
		};
		$pagination_aria = sprintf( /* translators: %s: singular Category label */ __( '%s pages', 'learnomy' ), \Learnomy\category_label() );
		include \Learnomy\template_path( 'partials/pagination.php' );
	?>
	<?php else : ?>
		<div class="lrn-empty-state">
			<i data-lucide="graduation-cap" class="lrn-empty-state__icon" aria-hidden="true"></i>
			<h2 class="lrn-empty-state__title"><?php esc_html_e( 'No courses in this category yet', 'learnomy' ); ?></h2>
			<p class="lrn-empty-state__desc"><?php esc_html_e( 'No courses match yet. Browse all courses.', 'learnomy' ); ?></p>
			<a href="<?php echo esc_url( \Learnomy\route_url( 'catalog' ) ); ?>" class="lrn-btn lrn-btn--primary">
				<?php esc_html_e( 'Browse All Courses', 'learnomy' ); ?>
			</a>
		</div>
	<?php endif; ?>

</div>
