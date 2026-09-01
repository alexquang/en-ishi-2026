<?php
/**
 * Render: learnomy/progress-bar
 *
 * @package Learnomy
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Services\Progress_Service;
use Learnomy\Models\Course;

ob_start();

$course_id = isset( $attributes['courseId'] ) ? absint( $attributes['courseId'] ) : 0;

if ( ! $course_id ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'Select a course to show the visitor\'s progress.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

$user_id = isset( $attributes['userId'] ) ? absint( $attributes['userId'] ) : 0;
if ( ! $user_id ) {
	$user_id = get_current_user_id();
}

if ( ! $user_id ) {
	ob_end_clean();
	return;
}

$course = Course::find( $course_id );
if ( ! $course ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'The selected course no longer exists. Pick another in the block settings.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

$service  = new Progress_Service();
$progress = $service->calculate_course_progress( $user_id, $course_id );
$progress = max( 0, min( 100, $progress ) );

$label = sprintf(
	/* translators: %d: completion percentage */
	__( '%d%% complete', 'learnomy' ),
	$progress
);
?>
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-progress-bar-block' ) ); ?>>
	<div class="lrn-progress-bar-block__header">
		<span class="lrn-progress-bar-block__course"><?php echo esc_html( $course->title ?? '' ); ?></span>
		<span class="lrn-progress-bar-block__pct"><?php echo esc_html( $progress . '%' ); ?></span>
	</div>
	<?php
	// Delegate to the canonical partial so all ARIA attributes, the fill
	// style, and the label text are identical across shortcodes + blocks.
	$percent = $progress;
	require \Learnomy\template_path( 'partials/progress-bar.php' );
	?>
</div>
<?php
\Learnomy\Blocks::render_filtered( 'progress-bar', ob_get_clean(), $attributes );
