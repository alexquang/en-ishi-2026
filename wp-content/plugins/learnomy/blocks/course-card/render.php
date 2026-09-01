<?php
/**
 * Render: learnomy/course-card
 *
 * @package Learnomy
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;

ob_start();

$course_id = isset( $attributes['courseId'] ) ? absint( $attributes['courseId'] ) : 0;

if ( ! $course_id ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'Select a course to display in this card.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

$course = Course::find( $course_id );

if ( ! $course ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'The selected course no longer exists. Pick another in the block settings.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

// Render through the ONE canonical partial so this block matches the catalog
// card exactly — members-only/free/recurring pricing, badges, wishlist, hooks.
// Hand-rolling the card here previously mislabelled members-only courses "Free".
// attach_viewer_enrollment() supplies the enrolled state (Continue / Review)
// the same way the catalog does; it is a no-op query-wise when logged out.
$course       = Course::attach_viewer_enrollment( Course::attach_card_meta( array( $course ) ) )[0];
$lrn_wishlist = \Learnomy\wishlist_ids();
\Learnomy\enqueue_wishlist_assets();
?>
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-course-card-block' ) ); ?>>
	<?php require \Learnomy\template_path( 'partials/course-card.php' ); ?>
</div>
<?php
\Learnomy\Blocks::render_filtered( 'course-card', ob_get_clean(), $attributes );
