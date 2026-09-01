<?php
/**
 * Integrations admin tab — Academy Stack companions.
 *
 * One card per Companion_Registry entry: status badge (Connected / Installed,
 * activate / Not installed) + the matching action (one-click free install,
 * activate, or upgrade to Pro). No data is created here — the screen reflects
 * registry status and triggers installs through Companion_Installer.
 *
 * @package Learnomy\Admin
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Integrations\Companion_Registry;

$lrn_companions = Companion_Registry::all();

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only redirect-back status flags, no state change.
$lrn_install_state = isset( $_GET['lrn_install'] ) ? sanitize_key( wp_unslash( $_GET['lrn_install'] ) ) : '';
$lrn_install_msg   = isset( $_GET['lrn_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['lrn_msg'] ) ) : '';
$lrn_install_done  = isset( $_GET['lrn_done'] ) ? sanitize_key( wp_unslash( $_GET['lrn_done'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap learnomy-admin lrn-integrations-page">

	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="blocks" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php
				// Heading = this page's own sidebar label, so a rename moves both
				// (Basecamp 10184393141). The fallback MATCHES that label rather
				// than restating the old heading: it only applies when the page has
				// no sidebar row, and a stale literal here made source reads report
				// the bug as unfixed even though the rendered H1 was correct.
				echo esc_html( \Learnomy\Admin\UX::page_title( __( 'Companion Plugins', 'learnomy' ) ) );
				?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php esc_html_e( 'Extend your academy with the Wbcom stack. Each plugin works on its own. Installing one here does not tie it to Learnomy.', 'learnomy' ); ?>
			</p>
		</div>
	</div>
	<hr class="wp-header-end">

	<?php if ( 'ok' === $lrn_install_state ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Integration installed and activated.', 'learnomy' ); ?></p>
		</div>
	<?php elseif ( 'error' === $lrn_install_state && '' !== $lrn_install_msg ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $lrn_install_msg ); ?></p>
		</div>
	<?php endif; ?>

	<div class="lrn-integrations-grid">
		<?php
		foreach ( $lrn_companions as $lrn_slug => $lrn_c ) :
			$lrn_status  = Companion_Registry::status( $lrn_slug );
			$lrn_label   = (string) ( $lrn_c['label'] ?? $lrn_slug );
			$lrn_why     = (string) ( $lrn_c['why'] ?? '' );
			$lrn_unlocks = (string) ( $lrn_c['unlocks'] ?? '' );
			$lrn_store   = (string) ( $lrn_c['store_url'] ?? '' );
			$lrn_has_pro = ! empty( $lrn_c['pro']['item_id'] );

			// Status badge variant + label.
			if ( 'active' === $lrn_status ) {
				$lrn_badge_class = 'lrn-status-badge lrn-status-badge--success';
				$lrn_badge_label = __( 'Connected', 'learnomy' );
			} elseif ( 'installed_inactive' === $lrn_status ) {
				$lrn_badge_class = 'lrn-status-badge lrn-status-badge--warn';
				$lrn_badge_label = __( 'Installed, activate', 'learnomy' );
			} else {
				$lrn_badge_class = 'lrn-status-badge lrn-status-badge--muted';
				$lrn_badge_label = __( 'Not installed', 'learnomy' );
			}
			?>
			<div class="lrn-integration-card">
				<div class="lrn-integration-card__head">
					<h2 class="lrn-integration-card__title"><?php echo esc_html( $lrn_label ); ?></h2>
					<span class="<?php echo esc_attr( $lrn_badge_class ); ?>"><?php echo esc_html( $lrn_badge_label ); ?></span>
				</div>

				<?php if ( '' !== $lrn_why ) : ?>
					<p class="lrn-integration-card__why"><?php echo esc_html( $lrn_why ); ?></p>
				<?php endif; ?>

				<?php if ( 'active' === $lrn_status && '' !== $lrn_unlocks ) : ?>
					<p class="lrn-integration-card__unlocks">
						<i data-lucide="check-circle-2" aria-hidden="true"></i>
						<?php echo esc_html( $lrn_unlocks ); ?>
					</p>
				<?php endif; ?>

				<div class="lrn-integration-card__actions">
					<?php if ( 'active' === $lrn_status ) : ?>
						<span class="lrn-admin-btn lrn-admin-btn--ghost" aria-disabled="true">
							<i data-lucide="check" aria-hidden="true"></i> <?php esc_html_e( 'Connected', 'learnomy' ); ?>
						</span>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="learnomy_install_companion">
							<input type="hidden" name="companion" value="<?php echo esc_attr( $lrn_slug ); ?>">
							<input type="hidden" name="tier" value="free">
							<?php wp_nonce_field( 'learnomy_install_companion_' . $lrn_slug ); ?>
							<button type="submit" class="lrn-admin-btn lrn-admin-btn--primary">
								<?php
								echo 'installed_inactive' === $lrn_status
									? esc_html__( 'Activate', 'learnomy' )
									: esc_html__( 'Install free', 'learnomy' );
								?>
							</button>
						</form>
					<?php endif; ?>

					<?php if ( $lrn_has_pro && '' !== $lrn_store ) : ?>
						<a href="<?php echo esc_url( $lrn_store ); ?>" target="_blank" rel="noopener noreferrer" class="lrn-admin-btn lrn-admin-btn--ghost">
							<?php esc_html_e( 'Upgrade to Pro', 'learnomy' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>

	<p class="lrn-integrations-footnote description">
		<?php esc_html_e( 'These plugins are standalone Wbcom products. Learnomy detects them and lights up the matching features when present.', 'learnomy' ); ?>
	</p>
</div>
