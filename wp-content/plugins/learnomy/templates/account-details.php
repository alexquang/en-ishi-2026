<?php
/**
 * Account details — /account/details/
 *
 * Self-service edit of the customer's name, email, and password. Saves via REST
 * (POST learnomy/v1/auth/account) from account-details.js. Email + password
 * changes require the current password; an email change is confirmed via a link
 * sent to the new address before it switches.
 *
 * View-data (from Account_Details_View_Data): $display_name, $first_name,
 * $last_name, $email, $pending_email, $rest_url, $rest_nonce.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$display_name  = isset( $display_name ) ? (string) $display_name : '';
$first_name    = isset( $first_name ) ? (string) $first_name : '';
$last_name     = isset( $last_name ) ? (string) $last_name : '';
$email         = isset( $email ) ? (string) $email : '';
$pending_email = isset( $pending_email ) ? (string) $pending_email : '';

// One-shot flash from the email-confirmation link handler (Router).
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
$email_confirm = isset( $_GET['email_confirm'] ) ? sanitize_key( wp_unslash( $_GET['email_confirm'] ) ) : '';
?>
<main class="lrn-container lrn-account-details" id="lrn-account-details">

	<?php
	$breadcrumb_items = \Learnomy\account_breadcrumb( __( 'Account details', 'learnomy' ) );
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-account-layout">
		<?php
		$account_nav_active = 'account-details';
		require \Learnomy\template_path( 'partials/account-nav.php' );
		?>
		<div class="lrn-account-layout__content">

			<?php
			$account_page = array(
				'icon'     => 'settings',
				'title'    => __( 'Account details', 'learnomy' ),
				'subtitle' => __( 'Update your name, email, and password.', 'learnomy' ),
			);
			require \Learnomy\template_path( 'partials/account-page-header.php' );
			?>

			<?php if ( 'success' === $email_confirm ) : ?>
				<div class="lrn-banner lrn-banner--success" role="status">
					<?php esc_html_e( 'Your email address has been updated.', 'learnomy' ); ?>
				</div>
			<?php elseif ( 'invalid' === $email_confirm ) : ?>
				<div class="lrn-banner lrn-banner--error" role="alert">
					<?php esc_html_e( 'That email confirmation link is invalid or has expired.', 'learnomy' ); ?>
				</div>
			<?php elseif ( 'taken' === $email_confirm ) : ?>
				<div class="lrn-banner lrn-banner--error" role="alert">
					<?php esc_html_e( 'That email address is already in use by another account.', 'learnomy' ); ?>
				</div>
			<?php endif; ?>

			<form class="lrn-account-form" id="lrn-account-form" novalidate>
				<div class="lrn-account-form__status" role="alert" aria-live="assertive" hidden></div>

				<section class="lrn-card lrn-account-form__section">
					<h2 class="lrn-account-form__legend"><?php esc_html_e( 'Profile', 'learnomy' ); ?></h2>

					<?php
					$lrn_avatar_url      = isset( $avatar_url ) ? (string) $avatar_url : '';
					$lrn_avatar_endpoint = isset( $avatar_endpoint ) ? (string) $avatar_endpoint : '';
					$lrn_has_avatar      = ! empty( $has_custom_avatar );
					?>
					<div class="lrn-avatar-field"
						data-lrn-avatar
						data-endpoint="<?php echo esc_url( $lrn_avatar_endpoint ); ?>"
						data-nonce="<?php echo esc_attr( $rest_nonce ?? '' ); ?>">
						<div class="lrn-avatar-field__preview">
							<img class="lrn-avatar-field__img avatar" src="<?php echo esc_url( $lrn_avatar_url ); ?>"
								width="96" height="96" alt="<?php esc_attr_e( 'Your profile photo', 'learnomy' ); ?>"
								data-avatar-preview>
						</div>
						<div class="lrn-avatar-field__controls">
							<div class="lrn-avatar-field__buttons">
								<button type="button" class="lrn-btn lrn-btn--secondary" data-avatar-choose>
									<?php esc_html_e( 'Change photo', 'learnomy' ); ?>
								</button>
								<button type="button" class="lrn-btn lrn-btn--ghost" data-avatar-remove <?php disabled( ! $lrn_has_avatar ); ?>>
									<?php esc_html_e( 'Remove', 'learnomy' ); ?>
								</button>
							</div>
							<p class="lrn-field__hint"><?php esc_html_e( 'Upload a square photo (JPG, PNG, WebP or GIF, max 3 MB). You can drag and zoom to crop.', 'learnomy' ); ?></p>
							<span class="lrn-avatar-field__status" role="status" aria-live="polite" hidden></span>
						</div>
						<input type="file" accept="image/jpeg,image/png,image/webp,image/gif" hidden data-avatar-input>
					</div>

					<p class="lrn-field">
						<label class="lrn-field__label" for="lrn-acc-display"><?php esc_html_e( 'Display name', 'learnomy' ); ?></label>
						<input class="lrn-field__input" type="text" id="lrn-acc-display" name="display_name" value="<?php echo esc_attr( $display_name ); ?>" autocomplete="nickname">
					</p>
					<div class="lrn-account-form__row">
						<p class="lrn-field">
							<label class="lrn-field__label" for="lrn-acc-first"><?php esc_html_e( 'First name', 'learnomy' ); ?></label>
							<input class="lrn-field__input" type="text" id="lrn-acc-first" name="first_name" value="<?php echo esc_attr( $first_name ); ?>" autocomplete="given-name">
						</p>
						<p class="lrn-field">
							<label class="lrn-field__label" for="lrn-acc-last"><?php esc_html_e( 'Last name', 'learnomy' ); ?></label>
							<input class="lrn-field__input" type="text" id="lrn-acc-last" name="last_name" value="<?php echo esc_attr( $last_name ); ?>" autocomplete="family-name">
						</p>
					</div>
				</section>

				<section class="lrn-card lrn-account-form__section">
					<h2 class="lrn-account-form__legend"><?php esc_html_e( 'Email address', 'learnomy' ); ?></h2>
					<?php if ( '' !== $pending_email ) : ?>
						<div class="lrn-banner lrn-banner--info" role="status">
							<?php
							printf(
								/* translators: %s: pending email address */
								esc_html__( 'A confirmation link was sent to %s. Your email changes once you open it.', 'learnomy' ),
								'<strong>' . esc_html( $pending_email ) . '</strong>'
							);
							?>
						</div>
					<?php endif; ?>
					<p class="lrn-field">
						<label class="lrn-field__label" for="lrn-acc-email"><?php esc_html_e( 'Email', 'learnomy' ); ?></label>
						<input class="lrn-field__input" type="email" id="lrn-acc-email" name="email" value="<?php echo esc_attr( $email ); ?>" autocomplete="email">
						<span class="lrn-field__hint"><?php esc_html_e( 'Changing this sends a confirmation link to the new address. Requires your current password below.', 'learnomy' ); ?></span>
					</p>
				</section>

				<section class="lrn-card lrn-account-form__section">
					<h2 class="lrn-account-form__legend"><?php esc_html_e( 'Password', 'learnomy' ); ?></h2>
					<p class="lrn-field">
						<label class="lrn-field__label" for="lrn-acc-newpw"><?php esc_html_e( 'New password', 'learnomy' ); ?></label>
						<input class="lrn-field__input" type="password" id="lrn-acc-newpw" name="new_password" autocomplete="new-password">
						<span class="lrn-field__hint"><?php esc_html_e( 'Leave blank to keep your current password. Minimum 8 characters.', 'learnomy' ); ?></span>
					</p>
					<p class="lrn-field">
						<label class="lrn-field__label" for="lrn-acc-curpw"><?php esc_html_e( 'Current password', 'learnomy' ); ?></label>
						<input class="lrn-field__input" type="password" id="lrn-acc-curpw" name="current_password" autocomplete="current-password">
						<span class="lrn-field__hint"><?php esc_html_e( 'Required only when changing your email or password.', 'learnomy' ); ?></span>
					</p>
				</section>

				<?php
				/**
				 * Renders extra account fields so plugins can add custom profile
				 * inputs (license number + expiry, member IDs, consent flags…)
				 * without overriding this template. Persist on the matching
				 * `learnomy_account_fields_save` action; storage lives in the
				 * consumer plugin's user meta.
				 *
				 * @param int $user_id The current account being edited.
				 */
				do_action( 'learnomy_account_fields', get_current_user_id(), 'account' );
				?>

				<div class="lrn-account-form__actions">
					<button type="submit" class="lrn-btn lrn-btn--primary" data-default-label="<?php esc_attr_e( 'Save Changes', 'learnomy' ); ?>">
						<?php esc_html_e( 'Save Changes', 'learnomy' ); ?>
					</button>
				</div>
			</form>

			<?php
			// Notification preferences — the learner's email opt-outs. Each toggle
			// saves on change via /auth/me/notifications. Transactional emails
			// (receipts, security) are intentionally not listed: they always send.
			$lrn_np      = isset( $notification_prefs ) && is_array( $notification_prefs ) ? $notification_prefs : array();
			$lrn_np_rows = array(
				'email_enrolled'    => __( 'Enrollment and welcome emails', 'learnomy' ),
				'email_completed'   => __( 'Course progress, completions, and certificates', 'learnomy' ),
				'email_new_content' => __( 'Comments, replies, and new reviews', 'learnomy' ),
				'push_reminders'    => __( 'Push notifications on your mobile device', 'learnomy' ),
			);
			?>
			<section class="lrn-card lrn-account-notifications"
				data-lrn-notifications
				data-endpoint="<?php echo esc_url( $notifications_url ?? '' ); ?>"
				data-nonce="<?php echo esc_attr( $rest_nonce ?? '' ); ?>">
				<h2 class="lrn-account-notifications__title"><?php esc_html_e( 'Notifications', 'learnomy' ); ?></h2>
				<p class="lrn-account-notifications__desc"><?php esc_html_e( 'Choose which emails you receive, and whether the mobile app sends push notifications. Receipts and account-security messages are always sent.', 'learnomy' ); ?></p>
				<div class="lrn-account-notifications__status" role="status" aria-live="polite" hidden></div>
				<?php foreach ( $lrn_np_rows as $lrn_np_key => $lrn_np_label ) : ?>
					<label class="lrn-account-notifications__row">
						<input type="checkbox" data-pref="<?php echo esc_attr( $lrn_np_key ); ?>" <?php checked( ! empty( $lrn_np[ $lrn_np_key ] ) ); ?>>
						<span class="lrn-account-notifications__label"><?php echo esc_html( $lrn_np_label ); ?></span>
					</label>
				<?php endforeach; ?>
			</section>

			<?php
			$lrn_blocked_members = isset( $blocked_members ) && is_array( $blocked_members ) ? $blocked_members : array();
			if ( ! empty( $lrn_blocked_members ) ) :
				?>
				<section class="lrn-card lrn-account-blocked" data-lrn-block-rest="<?php echo esc_url( $block_rest_url ?? '' ); ?>">
					<h2 class="lrn-account-blocked__title"><?php esc_html_e( 'Blocked members', 'learnomy' ); ?></h2>
					<p class="lrn-account-blocked__desc"><?php esc_html_e( 'You will not see comments or reviews from these members. Unblock to see their content again.', 'learnomy' ); ?></p>
					<ul class="lrn-account-blocked__list">
						<?php foreach ( $lrn_blocked_members as $lrn_bm ) : ?>
							<li class="lrn-account-blocked__row" data-blocked-id="<?php echo esc_attr( (int) $lrn_bm['id'] ); ?>">
								<img class="lrn-account-blocked__avatar" src="<?php echo esc_url( $lrn_bm['avatar'] ); ?>" alt="" width="40" height="40" loading="lazy">
								<span class="lrn-account-blocked__name"><?php echo esc_html( $lrn_bm['name'] ); ?></span>
								<button type="button" class="lrn-btn lrn-btn--ghost lrn-btn--sm lrn-unblock-btn"
									data-user-id="<?php echo esc_attr( (int) $lrn_bm['id'] ); ?>"
									data-user-name="<?php echo esc_attr( $lrn_bm['name'] ); ?>">
									<?php esc_html_e( 'Unblock', 'learnomy' ); ?>
								</button>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<section class="lrn-card lrn-account-delete-zone"
				data-lrn-delete-account
				data-endpoint="<?php echo esc_url( $delete_account_url ?? '' ); ?>"
				data-nonce="<?php echo esc_attr( $rest_nonce ?? '' ); ?>">
				<h2 class="lrn-account-delete-zone__title"><?php esc_html_e( 'Delete account', 'learnomy' ); ?></h2>
				<p class="lrn-account-delete-zone__desc"><?php esc_html_e( 'Permanently delete your account and all associated data. This action cannot be undone.', 'learnomy' ); ?></p>
				<div class="lrn-account-delete-zone__status" role="alert" aria-live="assertive" hidden></div>
				<button type="button" class="lrn-btn lrn-btn--danger" id="lrn-delete-account-btn">
					<?php esc_html_e( 'Delete my account', 'learnomy' ); ?>
				</button>
			</section>

		</div><!-- .lrn-account-layout__content -->
	</div><!-- .lrn-account-layout -->

</main>
