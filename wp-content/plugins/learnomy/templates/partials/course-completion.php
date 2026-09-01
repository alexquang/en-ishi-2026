<?php
/**
 * Course completion screen — Surface B.
 *
 * Rendered from `templates/single-course.php` (enrolled branch) when the
 * student has finished every lesson in a course. Shows the instructor's
 * completion message, a certificate CTA (per-course template wins),
 * and a retake button when `allow_retake` is on.
 *
 * Required caller variables:
 *   $course           (object) lrn_courses row
 *   $course_settings  (array)  output of Course::get_all_settings()
 *   $enrollment       (object) enrollment row for the current user
 *   $user_id          (int)    current logged-in user id
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_completion_message = isset( $course_settings['completion_message'] )
	? (string) $course_settings['completion_message']
	: '';

// The SHARED definition, not the per-course flag alone. Reading the raw flag
// here is what hid the CTA on every course using the site-wide default
// (Basecamp 10171331421).
$lrn_cert_enabled = \Learnomy\Models\Course::certificates_enabled( (int) $course->id );
$lrn_allow_retake = ! empty( $course_settings['allow_retake'] );
$lrn_reset_retake = ! empty( $course_settings['reset_on_retake'] );

// $active_certificate is supplied by Single_Course_View_Data — pre-fetched
// only when (a) the course is actually complete AND (b) certificates are
// enabled, so the catalog/sales view stays DB-cheap. May be null when no
// certificate has been issued yet.
$lrn_active_cert = $active_certificate ?? null;
?>

<section class="lrn-course-completion lrn-card" aria-labelledby="lrn-completion-heading" data-course-id="<?php echo esc_attr( (int) $course->id ); ?>">
	<div class="lrn-course-completion__icon" aria-hidden="true">
		<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
			<circle cx="12" cy="8" r="6"></circle>
			<path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"></path>
		</svg>
	</div>

	<h2 id="lrn-completion-heading" class="lrn-course-completion__title">
		<?php esc_html_e( 'Course complete. Congratulations.', 'learnomy' ); ?>
	</h2>

	<?php if ( \Learnomy\editorjs_has_content( $lrn_completion_message ) ) : ?>
		<div class="lrn-course-completion__message">
			<?php
			// Completion message authored in the shared block editor — render
			// Editor.js JSON (and legacy HTML transparently). Escaping is handled
			// inside render_editorjs_content() via wp_kses_post.
			echo \Learnomy\render_editorjs_content( (string) $lrn_completion_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</div>
	<?php else : ?>
		<p class="lrn-course-completion__message">
			<?php esc_html_e( 'You have finished every lesson in this course. Celebrate the win and share your achievement.', 'learnomy' ); ?>
		</p>
	<?php endif; ?>

	<div class="lrn-course-completion__actions">
		<?php if ( $lrn_active_cert ) : ?>
				<a href="<?php echo esc_url( \Learnomy\route_url( 'certificate-verify', array( 'slug' => (string) $lrn_active_cert->uuid ) ) ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg">
					<i data-lucide="award" aria-hidden="true"></i>
					<?php esc_html_e( 'View Certificate', 'learnomy' ); ?>
				</a>
		<?php elseif ( $lrn_cert_enabled ) : ?>
			<a href="<?php echo esc_url( add_query_arg( 'view', 'certificates', \Learnomy\route_url( 'student-dashboard' ) ) ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg">
				<i data-lucide="award" aria-hidden="true"></i>
				<?php esc_html_e( 'Get Certificate', 'learnomy' ); ?>
			</a>
		<?php endif; ?>

		<?php if ( $lrn_allow_retake ) : ?>
			<form method="post" class="lrn-course-completion__retake-form" id="lrn-retake-form" data-course-id="<?php echo esc_attr( (int) $course->id ); ?>">
				<?php
				/*
				 * Nonce field kept for back-compat: any third-party JS that
				 * still POSTs to admin-ajax with action=learnomy_retake_course
				 * during the deprecation window will find this nonce. The
				 * inline script below has switched to the REST endpoint
				 * (POST /learnomy/v1/courses/{id}/retake) and uses wp_rest
				 * nonce instead.
				 */
				wp_nonce_field( 'learnomy_retake_course', 'lrn_retake_nonce' );
				?>
				<input type="hidden" name="course_id" value="<?php echo esc_attr( (int) $course->id ); ?>">
				<button type="submit" class="lrn-btn lrn-btn--ghost lrn-btn--lg" data-reset-progress="<?php echo esc_attr( $lrn_reset_retake ? '1' : '0' ); ?>">
					<i data-lucide="refresh-cw" aria-hidden="true"></i>
					<?php esc_html_e( 'Retake Course', 'learnomy' ); ?>
				</button>
				<?php if ( $lrn_reset_retake ) : ?>
					<p class="lrn-course-completion__retake-note">
						<?php esc_html_e( 'Retaking will clear your previous progress and quiz attempts.', 'learnomy' ); ?>
					</p>
				<?php else : ?>
					<p class="lrn-course-completion__retake-note">
						<?php esc_html_e( 'Your previous progress and quiz attempts will be preserved.', 'learnomy' ); ?>
					</p>
				<?php endif; ?>
			</form>
		<?php endif; ?>
	</div>

	<?php if ( $lrn_allow_retake ) : ?>
	<?php endif; ?>
</section>
