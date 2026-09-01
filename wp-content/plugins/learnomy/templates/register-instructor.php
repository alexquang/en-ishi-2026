<?php
/**
 * Instructor registration — public page for new instructor applications.
 *
 * Creates a WP user with `lrn_student` role and submits an instructor
 * application to `lrn_instructor_applications`. Admin reviews and promotes
 * from Learnomy > Applications.
 *
 * Variables available:
 *   $route (string) 'register-instructor'
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// Logged-in users are redirected away on template_redirect (see Router::
// handle_request) — before any output — so the redirect is not repeated here
// where it would fire after wp_head() and trigger "headers already sent".

// REST /auth/register is nonce-less for anonymous sign-ups; abuse defense is
// IP rate-limit + honeypot in the controller.
?>
<div class="lrn-container lrn-container--narrow lrn-register lrn-register--instructor">

	<div class="lrn-register__card lrn-card">
		<header class="lrn-register__header">
			<h1 class="lrn-register__title">
			<?php
				/* translators: %s: singular label for an instructor (e.g. "Coach"). */
				printf( esc_html__( '%s application', 'learnomy' ), esc_html( \Learnomy\instructor_label() ) );
			?>
				</h1>
			<p class="lrn-register__subtitle">
				<?php esc_html_e( 'Share your knowledge and earn by teaching on our platform.', 'learnomy' ); ?>
			</p>
		</header>

		<div class="lrn-register__alert lrn-register__alert--error" id="lrn-register-error" role="alert" hidden></div>
		<div class="lrn-register__alert lrn-register__alert--success" id="lrn-register-success" role="status" hidden></div>

		<form class="lrn-register__form" id="lrn-register-form" novalidate>
			<input type="hidden" name="role" value="instructor">
			<?php // Honeypot — hidden from humans, visible to bots. Server rejects on non-empty value. ?>
			<div class="lrn-sr-only" aria-hidden="true">
				<label for="lrn-hp"><?php esc_html_e( 'Leave this field empty', 'learnomy' ); ?></label>
				<input type="text" id="lrn-hp" name="lrn_hp" tabindex="-1" autocomplete="off" value="">
			</div>

			<!-- Account details -->
			<fieldset class="lrn-register__fieldset">
				<legend class="lrn-register__legend"><?php esc_html_e( 'Account Details', 'learnomy' ); ?></legend>

				<div class="lrn-register__row">
					<div class="lrn-form-group">
						<label for="lrn-first-name" class="lrn-form-label">
							<?php esc_html_e( 'First Name', 'learnomy' ); ?> <span aria-hidden="true">*</span>
						</label>
						<input
							id="lrn-first-name"
							name="first_name"
							type="text"
							class="lrn-input"
							required
							autocomplete="given-name"
							placeholder="<?php esc_attr_e( 'Jane', 'learnomy' ); ?>"
							maxlength="100"
						>
						<span class="lrn-form-error" data-field="first_name"></span>
					</div>

					<div class="lrn-form-group">
						<label for="lrn-last-name" class="lrn-form-label">
							<?php esc_html_e( 'Last Name', 'learnomy' ); ?> <span aria-hidden="true">*</span>
						</label>
						<input
							id="lrn-last-name"
							name="last_name"
							type="text"
							class="lrn-input"
							required
							autocomplete="family-name"
							placeholder="<?php esc_attr_e( 'Smith', 'learnomy' ); ?>"
							maxlength="100"
						>
						<span class="lrn-form-error" data-field="last_name"></span>
					</div>
				</div>

				<div class="lrn-form-group">
					<label for="lrn-email" class="lrn-form-label">
						<?php esc_html_e( 'Email', 'learnomy' ); ?> <span aria-hidden="true">*</span>
					</label>
					<input
						id="lrn-email"
						name="email"
						type="email"
						class="lrn-input"
						required
						autocomplete="email"
						placeholder="<?php esc_attr_e( 'you@example.com', 'learnomy' ); ?>"
					>
					<span class="lrn-form-error" data-field="email"></span>
				</div>

				<div class="lrn-form-group">
					<label for="lrn-username" class="lrn-form-label">
						<?php esc_html_e( 'Username', 'learnomy' ); ?> <span aria-hidden="true">*</span>
					</label>
					<input
						id="lrn-username"
						name="username"
						type="text"
						class="lrn-input"
						required
						autocomplete="username"
						placeholder="<?php esc_attr_e( 'janesmith', 'learnomy' ); ?>"
					>
					<span class="lrn-form-error" data-field="username"></span>
				</div>

				<div class="lrn-form-group">
					<label for="lrn-password" class="lrn-form-label">
						<?php esc_html_e( 'Password', 'learnomy' ); ?> <span aria-hidden="true">*</span>
					</label>
					<input
						id="lrn-password"
						name="password"
						type="password"
						class="lrn-input"
						required
						autocomplete="new-password"
						minlength="8"
						placeholder="<?php esc_attr_e( 'Min. 8 characters', 'learnomy' ); ?>"
					>
					<span class="lrn-form-error" data-field="password"></span>
				</div>

				<div class="lrn-form-group">
					<label for="lrn-password-confirm" class="lrn-form-label">
						<?php esc_html_e( 'Confirm Password', 'learnomy' ); ?> <span aria-hidden="true">*</span>
					</label>
					<input
						id="lrn-password-confirm"
						name="password_confirm"
						type="password"
						class="lrn-input"
						required
						autocomplete="new-password"
						placeholder="<?php esc_attr_e( 'Re-enter your password', 'learnomy' ); ?>"
					>
					<span class="lrn-form-error" data-field="password_confirm"></span>
				</div>
			</fieldset>

			<!-- Teaching profile -->
			<fieldset class="lrn-register__fieldset">
				<legend class="lrn-register__legend"><?php esc_html_e( 'Teaching Profile', 'learnomy' ); ?></legend>

				<div class="lrn-form-group">
					<label for="lrn-bio" class="lrn-form-label">
						<?php esc_html_e( 'Bio / Expertise', 'learnomy' ); ?> <span aria-hidden="true">*</span>
					</label>
					<textarea
						id="lrn-bio"
						name="bio"
						class="lrn-textarea"
						rows="4"
						required
						placeholder="<?php esc_attr_e( 'Tell us about your background and professional experience…', 'learnomy' ); ?>"
					></textarea>
					<span class="lrn-form-error" data-field="bio"></span>
				</div>

				<div class="lrn-form-group">
					<label for="lrn-teaching-experience" class="lrn-form-label">
						<?php esc_html_e( 'Teaching Experience', 'learnomy' ); ?>
					</label>
					<textarea
						id="lrn-teaching-experience"
						name="teaching_experience"
						class="lrn-textarea"
						rows="3"
						placeholder="<?php esc_attr_e( 'List any teaching experience you have, online, in person, or informal.', 'learnomy' ); ?>"
					></textarea>
					<span class="lrn-form-error" data-field="teaching_experience"></span>
				</div>

				<div class="lrn-form-group">
					<label for="lrn-course-idea" class="lrn-form-label">
						<?php esc_html_e( 'Sample Course Idea', 'learnomy' ); ?> <span aria-hidden="true">*</span>
					</label>
					<textarea
						id="lrn-course-idea"
						name="course_idea"
						class="lrn-textarea"
						rows="3"
						required
						placeholder="<?php esc_attr_e( 'Describe the first course you would like to create. Include the topic, audience, and what students will learn.', 'learnomy' ); ?>"
					></textarea>
					<span class="lrn-form-error" data-field="course_idea"></span>
				</div>
			</fieldset>

			<?php
			// Terms-of-service agreement. Self-suppressing when the owner has
			// configured no legal page; the server applies the same rule.
			require \Learnomy\template_path( 'partials/register-consent.php' );
			?>
			<?php
			// Payout-details fieldset removed 2026-07-12 (seam P1-5/V3). It collected
			// a PayPal email / bank name / account number, AES-encrypted them, and
			// stored them in user meta that NOTHING ever read (there was no decrypt
			// counterpart and no instructor payout surface, despite the form
			// promising one). Storing financial PII for zero purpose is liability,
			// not a feature. Instructor payouts run through Pro's Stripe Connect
			// (its own onboarding), so nothing needs this. Re-add the collection
			// only alongside a real read/display surface + a decrypt path.

			/*
			 * Renders extra registration fields without overriding this template.
			 * Consumers persist on WP core `user_register` (or
			 * `learnomy_account_fields_save` for later edits).
			 * $user_id is always 0 here — the account does not exist yet.
			 */
			do_action( 'learnomy_account_fields', 0, 'registration_instructor' );
			?>

				<?php
				/**
				 * Render an owner-supplied signup challenge (CAPTCHA widget,
				 * question, whatever the site chose) just above the submit
				 * button. Pair it with `learnomy_registration_challenge_verify`,
				 * which refuses the request server-side — a widget nobody checks
				 * on the server is decoration, not protection.
				 *
				 * The form posts JSON built from FormData, so any named input
				 * printed here is forwarded without further work.
				 *
				 * @param string $context The form being rendered.
				 */
				do_action( 'learnomy_registration_challenge', 'registration_instructor' );
				?>

			<button type="submit" class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-register__submit" id="lrn-register-submit">
				<?php esc_html_e( 'Submit Application', 'learnomy' ); ?>
			</button>
		</form>

		<footer class="lrn-register__footer">
			<p>
				<?php
				printf(
					/* translators: %s: login URL */
					esc_html__( 'Already have an account? %s', 'learnomy' ),
					'<a href="' . esc_url( wp_login_url( \Learnomy\route_url( 'student-dashboard' ) ) ) . '">' . esc_html__( 'Log in', 'learnomy' ) . ' &rarr;</a>'
				);
				?>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: student registration URL */
					esc_html__( 'Just want to learn? %s', 'learnomy' ),
					'<a href="' . esc_url( \Learnomy\route_url( 'register-student' ) ) . '">' . esc_html( sprintf( /* translators: %s: singular label for a student (e.g. "Learner"). */ __( 'Register as %s', 'learnomy' ), \Learnomy\student_label() ) ) . ' &rarr;</a>'
				);
				?>
			</p>
		</footer>
	</div>

</div>
