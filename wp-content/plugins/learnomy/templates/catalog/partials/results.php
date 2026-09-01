<?php
/**
 * Catalog results — the courses grid + pagination + empty state.
 *
 * Shared by every catalog layout variant (grid, sidebar, …) so the results
 * markup — and the Interactivity filter contract (`.lrn-courses-grid` +
 * `data-wp-class--lrn-list-layout`) — is single-sourced. Included from inside
 * the `data-wp-interactive="learnomy/catalog"` wrapper, so it inherits the
 * view-data scope ($courses, $total_pages, $page, $search, $current_cat, $sort)
 * supplied by Catalog_View_Data.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// Self-contained defensive fallbacks so the partial is safe for any variant.
$total_count           = isset( $total_count ) ? (int) $total_count : count( $courses );
$total_pages           = isset( $total_pages ) ? (int) $total_pages : 1;
$page                  = isset( $page ) ? (int) $page : 1;
$lrn_has_active_filter = ! empty( $search ) || ! empty( $current_cat ) || ( ! empty( $sort ) && 'latest' !== $sort );

// Site-wide column count for the grid (Catalog columns setting, default 3).
$lrn_catalog_columns = isset( $catalog_columns ) ? (int) $catalog_columns : 3;
if ( $lrn_catalog_columns < 2 || $lrn_catalog_columns > 5 ) {
	$lrn_catalog_columns = 3;
}
?>
<?php if ( $courses ) : ?>
	<ul class="lrn-grid lrn-courses-grid lrn-courses-grid--cols-<?php echo (int) $lrn_catalog_columns; ?>" data-wp-class--lrn-list-layout="state.isListView" role="list">
		<?php foreach ( $courses as $course ) : ?>
			<li role="listitem">
				<?php include \Learnomy\template_path( 'partials/course-card.php' ); ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php
	// Canonical paginated URLs: /courses/page/N/. Active filters (search /
	// category / sort) ride along as query args so paging inside a filtered
	// view keeps the filter applied.
	$base_args = array();
	if ( '' !== $search ) {
		$base_args['s'] = $search;
	}
	if ( '' !== $current_cat ) {
		$base_args['cat'] = $current_cat;
	}
	if ( '' !== $sort && 'latest' !== $sort ) {
		$base_args['sort'] = $sort;
	}
	$pagination_url  = static function ( int $n ) use ( $base_args ): string {
		$url = \Learnomy\route_url( 'catalog', $n > 1 ? array( 'paged' => $n ) : array() );
		return $base_args ? add_query_arg( $base_args, $url ) : $url;
	};
	$pagination_aria = __( 'Course catalog pages', 'learnomy' );
	include \Learnomy\template_path( 'partials/pagination.php' );
	?>

<?php elseif ( $lrn_has_active_filter ) : ?>
	<?php // A search / category / filter returned nothing — the user narrowed too far, not a broken site. ?>
	<div class="lrn-empty-state" role="status">
		<i data-lucide="search-x" class="lrn-empty-state__icon" aria-hidden="true"></i>
		<h2 class="lrn-empty-state__title">
			<?php
			/* translators: %s: plural label for courses (e.g. "Courses") */
			printf( esc_html__( 'No %s match your filters', 'learnomy' ), esc_html( \Learnomy\course_label( true ) ) );
			?>
		</h2>
		<p class="lrn-empty-state__desc">
			<?php esc_html_e( 'Try a different search term or clear the filters to see everything.', 'learnomy' ); ?>
		</p>
		<a href="<?php echo esc_url( \Learnomy\route_url( 'catalog' ) ); ?>" class="lrn-btn lrn-btn--secondary">
			<?php esc_html_e( 'Clear filters', 'learnomy' ); ?>
		</a>
	</div>
<?php else : ?>
	<?php // Genuinely empty catalog (fresh install / nothing published yet). Frame it as a welcoming first-run, not an error. Owners get a create CTA; visitors get a friendly "check back". ?>
	<div class="lrn-empty-state" role="status">
		<i data-lucide="graduation-cap" class="lrn-empty-state__icon" aria-hidden="true"></i>
		<h2 class="lrn-empty-state__title">
			<?php
			/* translators: %s: plural label for courses (e.g. "courses") */
			printf( esc_html__( 'No %s yet', 'learnomy' ), esc_html( \Learnomy\course_label( true ) ) );
			?>
		</h2>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<p class="lrn-empty-state__desc">
				<?php esc_html_e( 'Your catalog is ready. Publish your first course and it will appear here for students.', 'learnomy' ); ?>
			</p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-new-course' ) ); ?>" class="lrn-btn lrn-btn--primary">
				<i data-lucide="plus" aria-hidden="true"></i>
				<?php
				/* translators: %s: singular label for course (e.g. "course") */
				printf( esc_html__( 'Create your first %s', 'learnomy' ), esc_html( \Learnomy\course_label( false ) ) );
				?>
			</a>
		<?php else : ?>
			<p class="lrn-empty-state__desc">
				<?php esc_html_e( 'New courses are on the way. Check back soon.', 'learnomy' ); ?>
			</p>
		<?php endif; ?>
	</div>
<?php endif; ?>
