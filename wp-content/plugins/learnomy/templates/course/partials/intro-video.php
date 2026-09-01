<?php
/**
 * Course intro video — shared partial.
 *
 * Used by both the sales and enrolled branches of the single-course body
 * (Basecamp #9909700680). Originally only rendered for non-enrolled
 * visitors which surprised customers — once a site owner sets an intro
 * video URL they expect to see it on the frontend regardless of who is
 * viewing. We still skip the body block on the `hero` layout because the
 * hero band renders its own trailer (avoiding duplicate playback).
 *
 * Variables in scope: $course, $preview_video, $course_layout.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_active_layout = $course_layout ?? ( get_option( 'learnomy_settings', array() )['course_layout'] ?? 'classic' );
$lrn_show_trailer  = ( 'hero' !== $lrn_active_layout )
	&& isset( $preview_video )
	&& '' !== ( $preview_video['provider'] ?? '' )
	// A non-enrolled visitor already gets the playable preview in the sidebar
	// enroll-card (its empty-$has_access sales branch), so rendering it in the body
	// too was the duplicate (Basecamp 10127929979). Skip the body trailer for them;
	// enrolled viewers have no sidebar preview, so they still see the intro here —
	// the intro stays visible to everyone, in exactly one place per layout.
	&& ! empty( $has_access ?? false );

if ( ! $lrn_show_trailer ) {
	return;
}
?>
<section class="lrn-single-course__section lrn-course-intro-video" aria-label="<?php esc_attr_e( 'Course intro video', 'learnomy' ); ?>">
	<div class="lrn-course-intro-video__frame">
		<?php if ( 'youtube' === $preview_video['provider'] || 'vimeo' === $preview_video['provider'] ) : ?>
			<iframe
				src="<?php echo esc_url( $preview_video['embed_url'] ); ?>"
				class="lrn-course-preview-video"
				title="<?php echo esc_attr( $course->title ); ?>"
				loading="lazy"
				allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
				allowfullscreen
			></iframe>
		<?php elseif ( 'html5' === $preview_video['provider'] ) : ?>
			<video class="lrn-course-preview-video" playsinline controls preload="metadata">
				<source src="<?php echo esc_url( $preview_video['src'] ); ?>">
			</video>
		<?php endif; ?>
	</div>
</section>
