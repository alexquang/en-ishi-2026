<?php
/**
 * Render: learnomy/course-grid
 *
 * Shows courses in a grid from one of three sources the site owner picks in the
 * editor: specific hand-picked courses (`ids`), a category (`category` + `count`),
 * or the latest published courses (`count`). `columns` controls the grid width.
 *
 * @package Learnomy
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;

ob_start();

$source   = isset( $attributes['source'] ) ? sanitize_key( $attributes['source'] ) : 'latest';
$columns  = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 3;
$columns  = max( 1, min( 6, $columns ) );
$count    = isset( $attributes['count'] ) ? absint( $attributes['count'] ) : 6;
$count    = max( 1, min( 24, $count ) );
$category = isset( $attributes['category'] ) ? absint( $attributes['category'] ) : 0;
$ids_raw  = isset( $attributes['ids'] ) ? sanitize_text_field( $attributes['ids'] ) : '';

// Private-academy gate: on a "require login for all content" site, an anonymous
// viewer must not get the course list through a block either. Same gate the
// /courses/ archive and the course sitemap enforce.
$lrn_gate_notice = \Learnomy\course_listing_gate_notice();
if ( null !== $lrn_gate_notice ) {
	?>
	<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
	<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-course-grid' ) ); ?>>
		<?php echo wp_kses_post( $lrn_gate_notice ); ?>
	</div>
	<?php
	\Learnomy\Blocks::render_filtered( 'course-grid', ob_get_clean(), $attributes );
	return;
}

$course_ids = array_values( array_filter( array_map( 'absint', explode( ',', $ids_raw ) ) ) );

// Resolve courses by the chosen source. The `ids` branch also covers legacy
// blocks saved before `source` existed (source defaults to 'latest' but ids
// are set), so existing hand-picked grids keep working unchanged.
if ( 'category' === $source ) {
	$courses = $category ? Course::get_published_by_category( $category, $count ) : array();
} elseif ( 'ids' === $source || ( 'latest' === $source && ! empty( $course_ids ) ) ) {
	$courses = ! empty( $course_ids ) ? Course::find_published_in( $course_ids ) : array();
	// Hand-picked ids bypass the catalog query, so the visibility rule has to be
	// applied to the rows. A private course hand-picked into a public grid would
	// otherwise advertise its title and price and link to its own 404.
	// find_published_in() is an explicit-id hydrator by design (membership and
	// space entitlements need it ungated), so the gate belongs on this surface.
	$courses = array_values(
		array_filter(
			$courses,
			static fn( $lrn_course ) => ! Course::is_hidden_from_public( $lrn_course )
		)
	);
	// Preserve the author's hand-picked order.
	if ( $courses ) {
		$by_id = array();
		foreach ( $courses as $c ) {
			$by_id[ (int) $c->id ] = $c;
		}
		$ordered = array();
		foreach ( $course_ids as $cid ) {
			if ( isset( $by_id[ $cid ] ) ) {
				$ordered[] = $by_id[ $cid ];
			}
		}
		$courses = $ordered;
	}
} else {
	$courses = Course::list_published( 1, $count );
}

if ( empty( $courses ) ) {
	?>
	<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
	<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-course-grid' ) ); ?>>
		<p class="lrn-empty-state"><?php esc_html_e( 'No courses to display.', 'learnomy' ); ?></p>
	</div>
	<?php
	\Learnomy\Blocks::render_filtered( 'course-grid', ob_get_clean(), $attributes );
	return;
}

// Batch-resolve category + instructor for the whole grid (2 queries) and the
// viewer's wishlist once, then render every card through the ONE canonical
// partial — same markup, members-only/free/recurring logic, badges, and hooks
// as the catalog.
$courses      = Course::attach_card_meta( $courses );
// One query resolves the viewer's own enrollment for the whole grid, so an
// enrolled student sees Continue / Review rather than the price. Logged-out
// visitors cost nothing extra.
$courses      = Course::attach_viewer_enrollment( $courses );
$lrn_wishlist = \Learnomy\wishlist_ids();
\Learnomy\enqueue_wishlist_assets();
$col_class = 'lrn-grid--cols-' . $columns;
?>
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-course-grid lrn-container' ) ); ?>>
	<div class="lrn-grid <?php echo esc_attr( $col_class ); ?>">
		<?php
		foreach ( $courses as $course ) {
			include \Learnomy\template_path( 'partials/course-card.php' );
		}
		?>
	</div>
</div>
<?php
\Learnomy\Blocks::render_filtered( 'course-grid', ob_get_clean(), $attributes );
