<?php
/**
 * Become an instructor — application form or status page.
 *
 * Auth redirect is handled by Template_Loader.
 *
 * Variables available:
 *   $route (string) 'become-instructor'
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// $view_data variables (injected by Template_Loader via Become_Instructor_View_Data):
// $user_id, $user, $is_instructor, $existing_apps, $latest_app, $app_status, $nonce
//
// The "already an instructor" bounce happens in Router::handle_request (on
// template_redirect, before any output) so the Location header is still
// mutable. The earlier inline redirect here fired after wp_head() and
// produced "headers already sent" warnings, leaving instructors on an
// empty page when they revisited /become-instructor/.
?>
<main class="lrn-container lrn-become-instructor" role="main">

	<header class="lrn-become-instructor__header">
		<h1>
		<?php
			/* translators: %s: singular label for an instructor (e.g. "Coach"). */
			printf( esc_html__( 'Apply as %s', 'learnomy' ), esc_html( \Learnomy\instructor_label() ) );
		?>
			</h1>
		<p class="lrn-become-instructor__subtitle">
			<?php esc_html_e( 'Share your knowledge and earn by teaching on our platform.', 'learnomy' ); ?>
		</p>
	</header>

	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- informational flash from the router.
	// `lrn_error`, not `error`. A bare `error` never reached this template on
	// any install we can observe: measured on this stack, `?error=cooldown` and
	// `?error=missing_fields` both arrive with the key ALREADY stripped from
	// $_GET, on every URL including the site root, while a neighbouring
	// `?foo=bar` survives. `error` is a name WordPress, hosts and security
	// layers all treat as theirs, so every message this block can produce -
	// missing fields, save failed, too long, rate limited - has been silently
	// swallowed since the form shipped, not just the cooldown one the card
	// reports. Namespacing the param takes us out of that fight for good.
	$flash_error = isset( $_GET['lrn_error'] ) ? sanitize_key( wp_unslash( $_GET['lrn_error'] ) ) : '';
	if ( $flash_error ) :
		// Every code the router can redirect with needs an entry here. Three
		// were missing, so `cooldown`, `rate_limited` and `too_long` all fell
		// through to "Application could not be submitted." - which tells an
		// applicant nothing about what to change or how long to wait.
		$error_messages = array(
			'missing_fields' => __( 'Please fill in all required fields before submitting.', 'learnomy' ),
			'save_failed'    => __( 'Something went wrong saving your application. Please try again.', 'learnomy' ),
			'cooldown'       => __( 'You just sent an application. Give it a minute, then try again.', 'learnomy' ),
			'rate_limited'   => __( 'That is a lot of attempts in a short time. Please wait a few minutes and try again.', 'learnomy' ),
			'too_long'       => __( 'One of your answers is too long. Please shorten it and submit again.', 'learnomy' ),
		);
		$error_msg      = $error_messages[ $flash_error ] ?? __( 'Application could not be submitted.', 'learnomy' );
		?>
		<div class="lrn-notice lrn-notice--error" role="alert">
			<i data-lucide="alert-triangle" aria-hidden="true"></i>
			<?php echo esc_html( $error_msg ); ?>
		</div>
	<?php endif; ?>

	<?php if ( 'pending' === $app_status ) : ?>
		<!-- Application already submitted -->
		<div class="lrn-become-instructor__status lrn-card">
			<i data-lucide="clock" class="lrn-become-instructor__status-icon" aria-hidden="true"></i>
			<h2><?php esc_html_e( 'Application Under Review', 'learnomy' ); ?></h2>
			<p>
				<?php esc_html_e( 'Your application has been received and is being reviewed by our team. We will email you as soon as a decision is made, usually within 2-3 business days.', 'learnomy' ); ?>
			</p>
			<?php if ( $latest_app ) : ?>
				<p class="lrn-become-instructor__submitted-date">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: application submission date. */
							__( 'Submitted on %s', 'learnomy' ),
							date_i18n( get_option( 'date_format' ), strtotime( $latest_app->created_at ) )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>

	<?php elseif ( 'rejected' === $app_status ) : ?>
		<!-- Rejected — allow re-application -->
		<div class="lrn-become-instructor__status lrn-card lrn-become-instructor__status--rejected">
			<i data-lucide="alert-triangle" class="lrn-become-instructor__status-icon" aria-hidden="true"></i>
			<h2><?php esc_html_e( 'Application Not Approved', 'learnomy' ); ?></h2>
			<?php if ( ! empty( $latest_app->rejection_reason ) ) : ?>
				<p><?php echo esc_html( $latest_app->rejection_reason ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Your previous application was not approved. You are welcome to apply again.', 'learnomy' ); ?></p>
			<?php endif; ?>
		</div>
		<!-- Fall through to show the form again -->
		<?php // (form rendered below) ?>
		<?php $show_form = true; ?>

	<?php else : ?>
		<?php $show_form = true; ?>
	<?php endif; ?>

	<?php if ( ! empty( $show_form ) ) : ?>
		<!-- Live region announces server-side validation errors to screen readers. -->
		<div id="lrn-instructor-form-errors" role="alert" aria-live="assertive" class="lrn-sr-only"></div>

		<form class="lrn-become-instructor__form lrn-card" method="post" action=""
			aria-describedby="lrn-instructor-form-errors"
			data-wp-interactive="learnomy/instructor-application"
			data-wp-on--submit="actions.onSubmit">
			<?php wp_nonce_field( 'lrn_instructor_apply', 'lrn_nonce' ); ?>
			<input type="hidden" name="lrn_action" value="instructor_apply">
			<?php // Honeypot — hidden from humans, visible to bots. Server rejects on non-empty value. ?>
			<div class="lrn-sr-only" aria-hidden="true">
				<label for="lrn-hp"><?php esc_html_e( 'Leave this field empty', 'learnomy' ); ?></label>
				<input type="text" id="lrn-hp" name="lrn_hp" tabindex="-1" autocomplete="off" value="">
			</div>

			<div class="lrn-form-group">
				<label for="lrn-bio" class="lrn-form-label">
					<?php esc_html_e( 'About You', 'learnomy' ); ?> <span aria-hidden="true">*</span>
				</label>
				<textarea id="lrn-bio" name="bio" class="lrn-textarea" rows="5"
					required aria-required="true"
					placeholder="<?php esc_attr_e( 'Tell us about your background and professional experience.', 'learnomy' ); ?>"><?php echo esc_textarea( $latest_app->bio ?? '' ); ?></textarea>
			</div>

			<div class="lrn-form-group">
				<label for="lrn-expertise" class="lrn-form-label">
					<?php esc_html_e( 'Areas of Expertise', 'learnomy' ); ?> <span aria-hidden="true">*</span>
				</label>
				<input id="lrn-expertise" name="expertise" type="text" class="lrn-input"
					required aria-required="true"
					placeholder="<?php esc_attr_e( 'e.g. Web Development, Data Science, Design', 'learnomy' ); ?>"
					value="<?php echo esc_attr( $latest_app->expertise ?? '' ); ?>">
				<p class="lrn-form-hint"><?php esc_html_e( 'Separate multiple areas with commas.', 'learnomy' ); ?></p>
			</div>

			<div class="lrn-form-group">
				<label for="lrn-teaching-experience" class="lrn-form-label">
					<?php esc_html_e( 'Teaching Experience', 'learnomy' ); ?>
				</label>
				<textarea id="lrn-teaching-experience" name="teaching_experience" class="lrn-textarea" rows="4"
					placeholder="<?php esc_attr_e( 'List any teaching experience you have, online, in person, or informal.', 'learnomy' ); ?>"><?php echo esc_textarea( $latest_app->teaching_experience ?? '' ); ?></textarea>
			</div>

			<div class="lrn-form-group">
				<label for="lrn-course-idea" class="lrn-form-label">
					<?php esc_html_e( 'Course Idea', 'learnomy' ); ?> <span aria-hidden="true">*</span>
				</label>
				<textarea id="lrn-course-idea" name="course_idea" class="lrn-textarea" rows="4"
					required aria-required="true"
					placeholder="<?php esc_attr_e( 'Describe the first course you would like to create: the topic, audience, and what students will learn.', 'learnomy' ); ?>"><?php echo esc_textarea( $latest_app->course_idea ?? '' ); ?></textarea>
			</div>

			<button type="submit" class="lrn-btn lrn-btn--primary" aria-busy="false">
				<?php esc_html_e( 'Submit Application', 'learnomy' ); ?>
			</button>
		</form>
	<?php endif; ?>

</main><!-- .lrn-become-instructor -->
