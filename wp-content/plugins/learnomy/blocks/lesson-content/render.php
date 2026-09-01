<?php
/**
 * Render: learnomy/lesson-content
 *
 * @package Learnomy
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Lesson;

ob_start();

$lesson_id = isset( $attributes['lessonId'] ) ? absint( $attributes['lessonId'] ) : 0;

if ( ! $lesson_id ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'Select a lesson to display its content.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

$lesson = Lesson::find( $lesson_id );

if ( ! $lesson ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'The selected lesson no longer exists. Pick another in the block settings.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

// Entitlement gate. This block can be placed on ANY page — a marketing page, a
// landing page, a post — so it cannot assume the visitor arrived through the
// lesson player's own checks. Without this, an anonymous visitor received the
// full body of a paid lesson: 505 characters of a $49 course, with no enrol or
// login prompt anywhere (Basecamp 10143219688, reproduced over HTTP with no
// cookies).
//
// Lesson_Service::user_can_read_body() is the single owner of this decision and
// mirrors the lesson player exactly. Do NOT swap it for
// user_can_access_lesson()/is_available_to() — those answer only the drip
// question and a guest passes them on any lesson without an available_date.
$lrn_can_read = \Learnomy\Services\Lesson_Service::user_can_read_body( $lesson, get_current_user_id() );

$lesson_title = $lesson->title ?? '';
$title        = esc_html( $lesson_title );
$raw          = $lrn_can_read ? ( $lesson->content ?? '' ) : '';
// Pass the lesson title so a leading header block that repeats it is dropped —
// the block already renders the title as its own heading above the body.
$content = \Learnomy\render_editorjs_content( $raw, $lesson_title );
?>
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-lesson-content lrn-card' ) ); ?>>
	<?php if ( $title ) : ?>
		<h2 class="lrn-lesson-content__title"><?php echo esc_html( $title ); ?></h2>
	<?php endif; ?>
	<?php if ( ! $lrn_can_read ) : ?>
		<?php
		// Locked. Distinct from "no content yet" on purpose: an owner placing this
		// block on a marketing page needs to see that it is gated, and a visitor
		// needs a way forward rather than an empty card that reads as broken.
		$lrn_course_row = \Learnomy\Models\Course::find( (int) ( $lesson->course_id ?? 0 ) );
		$lrn_course_url = $lrn_course_row && ! empty( $lrn_course_row->slug )
			? \Learnomy\route_url( 'course', array( 'slug' => $lrn_course_row->slug ) )
			: '';
		?>
		<p class="lrn-empty-state lrn-lesson-content__locked">
			<?php esc_html_e( 'This lesson is part of a course. Enroll to read it.', 'learnomy' ); ?>
		</p>
		<?php if ( $lrn_course_url ) : ?>
			<p class="lrn-lesson-content__locked-cta">
				<a class="lrn-btn lrn-btn--primary" href="<?php echo esc_url( $lrn_course_url ); ?>">
					<?php esc_html_e( 'View the course', 'learnomy' ); ?>
				</a>
			</p>
		<?php endif; ?>
	<?php elseif ( $content ) : ?>
		<div class="lrn-lesson-content__body">
			<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	<?php else : ?>
		<p class="lrn-empty-state"><?php esc_html_e( 'This lesson has no content yet.', 'learnomy' ); ?></p>
	<?php endif; ?>
</div>
<?php
\Learnomy\Blocks::render_filtered( 'lesson-content', ob_get_clean(), $attributes );
