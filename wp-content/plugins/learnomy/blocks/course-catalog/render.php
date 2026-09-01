<?php
/**
 * Render: learnomy/course-catalog
 *
 * @package Learnomy
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Category;

ob_start();

$limit    = isset( $attributes['limit'] ) ? absint( $attributes['limit'] ) : 12;
$limit    = max( 1, min( 100, $limit ) );
$columns  = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 3;
$columns  = max( 1, min( 6, $columns ) );
$category = isset( $attributes['category'] ) ? sanitize_text_field( $attributes['category'] ) : '';

// Private-academy gate: on a "require login for all content" site, an anonymous
// viewer must not get the course list through a block either. Same gate the
// /courses/ archive and the course sitemap enforce.
$lrn_gate_notice = \Learnomy\course_listing_gate_notice();
if ( null !== $lrn_gate_notice ) {
	?>
	<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
	<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-course-catalog' ) ); ?>>
		<?php echo wp_kses_post( $lrn_gate_notice ); ?>
	</div>
	<?php
	\Learnomy\Blocks::render_filtered( 'course-catalog', ob_get_clean(), $attributes );
	return;
}

$courses = Course::list_published( 1, $limit );

if ( $category ) {
	$cat_obj = Category::find_by_slug( $category );
	if ( $cat_obj ) {
		$courses = Course::get_published_by_category( (int) $cat_obj->id, $limit );
	}
}

if ( empty( $courses ) ) {
	?>
	<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
	<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-course-catalog' ) ); ?>>
		<p class="lrn-empty-state"><?php esc_html_e( 'No courses are available yet.', 'learnomy' ); ?></p>
	</div>
	<?php
	\Learnomy\Blocks::render_filtered( 'course-catalog', ob_get_clean(), $attributes );
	return;
}

// Batch-resolve category + instructor (2 queries) + the viewer's wishlist once,
// then render every card through the ONE canonical partial — identical markup,
// members-only/free/recurring logic, badges, and hooks as the catalog. The
// hand-rolled card here previously mislabelled members-only courses as "Free".
$courses = Course::attach_card_meta( $courses );
// One query resolves the viewer's own enrollment for the whole grid, so an
// enrolled student sees Continue / Review rather than the price. Logged-out
// visitors cost nothing extra.
$courses      = Course::attach_viewer_enrollment( $courses );
$lrn_wishlist = \Learnomy\wishlist_ids();
// Wishlist assets arrive with the block's `course-card` surface (see
// Blocks::BLOCK_SURFACES) — the same seam the sibling card blocks and the
// [learnomy_course*] shortcodes use, so no surface can be missed one at a time.
$col_class = 'lrn-grid--cols-' . $columns;
?>
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-course-catalog lrn-container' ) ); ?>>
	<div class="lrn-grid <?php echo esc_attr( $col_class ); ?>">
		<?php
		foreach ( $courses as $course ) {
			include \Learnomy\template_path( 'partials/course-card.php' );
		}
		?>
	</div>
</div>
<?php
\Learnomy\Blocks::render_filtered( 'course-catalog', ob_get_clean(), $attributes );
