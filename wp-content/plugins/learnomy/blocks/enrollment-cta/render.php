<?php
/**
 * Render: learnomy/enrollment-cta
 *
 * @package Learnomy
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Enrollment;
use Learnomy\Models\Course;

ob_start();

$course_id   = isset( $attributes['courseId'] ) ? absint( $attributes['courseId'] ) : 0;
$button_text = isset( $attributes['buttonText'] ) ? sanitize_text_field( $attributes['buttonText'] ) : __( 'Enroll Now', 'learnomy' );

if ( ! $course_id ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'Select the course this button enrolls visitors into.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

$course = Course::find( $course_id );
if ( ! $course ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'The selected course no longer exists. Pick another in the block settings.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

$user_id     = get_current_user_id();
$is_enrolled = $user_id ? Enrollment::is_enrolled( $user_id, $course_id ) : false;
?>
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-enrollment-cta' ) ); ?>>
	<?php if ( $is_enrolled ) : ?>
		<span class="lrn-badge lrn-badge--success lrn-enrollment-cta__enrolled">
			<?php esc_html_e( 'Already Enrolled', 'learnomy' ); ?>
		</span>
		<a href="<?php echo esc_url( \Learnomy\route_url( 'course', array( 'slug' => $course->slug ?? '' ) ) ); ?>" class="lrn-btn lrn-btn--primary">
			<?php esc_html_e( 'Continue learning', 'learnomy' ); ?>
		</a>
	<?php elseif ( $user_id ) : ?>
		<?php
		// Route through the Router's enroll handler (?lrn_action=enroll), the
		// same canonical path the single-course template uses. The handler
		// resolves free / paid / membership branches and redirects accordingly.
		// The old admin-post.php?action=learnomy_enroll target had no registered
		// handler, so the button silently did nothing.
		// route_url('enroll') is the canonical base for the enroll handler —
		// same as the single-course template and Enrollments controller use.
		// home_url() was missing the rewrite-rule slug, breaking on non-root installs.
		$enroll_url = add_query_arg(
			array(
				'lrn_action' => 'enroll',
				'course_id'  => $course_id,
				'_wpnonce'   => wp_create_nonce( 'lrn_enroll_' . $course_id ),
			),
			\Learnomy\route_url( 'enroll' )
		);
		?>
		<a href="<?php echo esc_url( $enroll_url ); ?>" class="lrn-btn lrn-btn--primary lrn-enrollment-cta__enroll">
			<?php echo esc_html( $button_text ); ?>
		</a>
	<?php else : ?>
		<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="lrn-btn lrn-btn--secondary">
			<?php esc_html_e( 'Log in to Enroll', 'learnomy' ); ?>
		</a>
	<?php endif; ?>
</div>
<?php
\Learnomy\Blocks::render_filtered( 'enrollment-cta', ob_get_clean(), $attributes );
