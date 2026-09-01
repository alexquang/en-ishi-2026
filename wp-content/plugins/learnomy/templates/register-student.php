<?php
/**
 * Student registration — public page for new student sign-ups.
 *
 * Variables available:
 *   $route (string) 'register-student'
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// Already logged in — honor ?redirect_to= if present so a customer
// who clicked Sign up to buy on a course but is already logged in
// lands on that course immediately rather than the dashboard.
// (J-01.6 fix. It said "partial" for two releases and meant it: the hidden
// form field carried the destination for a NEW account, while the "Log in"
// link in the footer threw it away and sent a returning student to the
// dashboard. All three paths — already logged in, register, log in — now
// honour it.)
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$lrn_redirect_to_raw = isset( $_GET['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) : '';
// phpcs:enable
$lrn_redirect_to = '' !== $lrn_redirect_to_raw ? wp_validate_redirect( $lrn_redirect_to_raw, \Learnomy\route_url( 'student-dashboard' ) ) : '';

// Logged-in users are redirected away on template_redirect (see Router::
// handle_request) — before any output — so the redirect is not repeated here
// where it would fire after wp_head() and trigger "headers already sent".
// $lrn_redirect_to stays computed above: the form below carries it as a hidden
// field so a buyer who clicked "Sign up to buy" returns to the course after
// creating the account.

// Note: this form posts JSON to /wp-json/learnomy/v1/auth/register via fetch().
// REST nonce-less endpoints rely on the controller's IP rate-limiter + honeypot
// for unauthenticated abuse defense; no `lrn_nonce` field is read server-side.
?>
<main class="lrn-container lrn-container--narrow lrn-register" id="lrn-register-main">

	<div class="lrn-register__card lrn-card">
		<header class="lrn-register__header">
			<h1 class="lrn-register__title">
			<?php
				/* translators: %s: singular label for a student (e.g. "Learner"). */
				printf( esc_html__( '%s registration', 'learnomy' ), esc_html( \Learnomy\student_label() ) );
			?>
				</h1>
			<p class="lrn-register__subtitle">
				<?php esc_html_e( 'Create your account to start learning today.', 'learnomy' ); ?>
			</p>
		</header>

		<div class="lrn-register__alert lrn-register__alert--error" id="lrn-register-error" role="alert" hidden></div>
		<div class="lrn-register__alert lrn-register__alert--success" id="lrn-register-success" role="status" hidden></div>

		<form class="lrn-register__form" id="lrn-register-form" novalidate>
			<input type="hidden" name="role" value="student">
			<?php // Honeypot — hidden from humans, visible to bots. Server rejects on non-empty value. ?>
			<div class="lrn-sr-only" aria-hidden="true">
				<label for="lrn-hp"><?php esc_html_e( 'Leave this field empty', 'learnomy' ); ?></label>
				<input type="text" id="lrn-hp" name="lrn_hp" tabindex="-1" autocomplete="off" value="">
			</div>
			<?php // J-01.6 fix — preserve ?redirect_to=<url> across the POST so a buyer who clicked Sign up to buy on a course lands back on that course after registering (the JS reads this and uses it as window.location.href on success). ?>
			<?php if ( '' !== $lrn_redirect_to ) : ?>
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $lrn_redirect_to ); ?>">
			<?php endif; ?>

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
						placeholder="<?php esc_attr_e( 'John', 'learnomy' ); ?>"
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
						placeholder="<?php esc_attr_e( 'Doe', 'learnomy' ); ?>"
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
					placeholder="<?php esc_attr_e( 'johndoe', 'learnomy' ); ?>"
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

			<?php
			// Terms-of-service agreement. Self-suppressing when the owner has
			// configured no legal page; the server applies the same rule.
			require \Learnomy\template_path( 'partials/register-consent.php' );
			?>
			<?php
			/**
			 * Renders extra registration fields (license number, member ID, consent
			 * flags…) without overriding this template. Consumers persist on WP core
			 * `user_register` (or `learnomy_account_fields_save` for later edits).
			 *
			 * @param int    $user_id Always 0 here — the account does not exist yet.
			 * @param string $context 'registration_student' — the form being rendered, so a
			 *                        field can be scoped to one signup form rather than both.
			 */
			do_action( 'learnomy_account_fields', 0, 'registration_student' );
			?>

			<?php
			/**
			 * Render an owner-supplied signup challenge (CAPTCHA widget, question,
			 * whatever the site chose) just above the submit button. Pair it with
			 * `learnomy_registration_challenge_verify`, which refuses the request
			 * server-side — a widget nobody checks on the server is decoration, not
			 * protection.
			 *
			 * The form posts JSON built from FormData, so any named input printed
			 * here is forwarded without further work.
			 *
			 * @param string $context The form being rendered.
			 */
			do_action( 'learnomy_registration_challenge', 'registration_student' );
			?>

			<button type="submit" class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-register__submit" id="lrn-register-submit">
				<?php esc_html_e( 'Register', 'learnomy' ); ?>
			</button>
		</form>

		<footer class="lrn-register__footer">
			<p>
				<?php
				// Send a returning student back to whatever they were trying to open,
				// exactly as the form above does. This link ignored $lrn_redirect_to
				// and always went to the dashboard, so the one visitor who arrives
				// here holding a destination — someone who clicked a locked lesson on
				// a course, hit "Sign up to join", then said they already have an
				// account — was the one who lost it, landing on their dashboard with
				// no trace of the course they wanted. Falls back to the dashboard when
				// there is no destination, which is the previous behaviour.
				$lrn_login_redirect = '' !== $lrn_redirect_to ? $lrn_redirect_to : \Learnomy\route_url( 'student-dashboard' );
				printf(
					/* translators: %s: login URL */
					esc_html__( 'Already have an account? %s', 'learnomy' ),
					'<a href="' . esc_url( wp_login_url( $lrn_login_redirect ) ) . '">' . esc_html__( 'Log in', 'learnomy' ) . ' &rarr;</a>'
				);
				?>
			</p>
			<?php if ( \Learnomy\instructor_registration_enabled() ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: instructor registration URL */
						esc_html__( 'Apply to teach instead: %s', 'learnomy' ),
						'<a href="' . esc_url( \Learnomy\route_url( 'register-instructor' ) ) . '">' . esc_html( sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( 'Register as %s', 'learnomy' ), \Learnomy\instructor_label() ) ) . ' &rarr;</a>'
					);
					?>
				</p>
			<?php endif; ?>
		</footer>
	</div>

</main>
