<?php
/**
 * Shared "My Account" page header.
 *
 * Every account tab (My Courses, Order history, Memberships, …) opens with this
 * identical header so the hub looks consistent tab to tab — one canonical
 * `.lrn-page-header` (icon + title + optional subtitle + optional actions),
 * instead of each template hand-rolling its own heading.
 *
 * Expects in scope:
 *   $account_page (array) {
 *     'title'    => string  (required)
 *     'icon'     => string  Lucide icon name (optional)
 *     'subtitle' => string  (optional)
 *     'actions'  => string  trusted HTML for a right-aligned actions slot (optional)
 *   }
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_ap    = isset( $account_page ) && is_array( $account_page ) ? $account_page : array();
$lrn_title = (string) ( $lrn_ap['title'] ?? '' );
if ( '' === $lrn_title ) {
	return;
}
$lrn_icon     = (string) ( $lrn_ap['icon'] ?? '' );
$lrn_subtitle = (string) ( $lrn_ap['subtitle'] ?? '' );
$lrn_actions  = (string) ( $lrn_ap['actions'] ?? '' );
?>
<header class="lrn-page-header lrn-account-page-header">
	<div class="lrn-account-page-header__text">
		<h1 class="lrn-page-header__title">
			<?php if ( '' !== $lrn_icon ) : ?>
				<i data-lucide="<?php echo esc_attr( $lrn_icon ); ?>" class="lrn-page-header__icon" aria-hidden="true"></i>
			<?php endif; ?>
			<?php echo esc_html( $lrn_title ); ?>
		</h1>
		<?php if ( '' !== $lrn_subtitle ) : ?>
			<p class="lrn-page-header__subtitle"><?php echo esc_html( $lrn_subtitle ); ?></p>
		<?php endif; ?>
	</div>
	<?php if ( '' !== $lrn_actions ) : ?>
		<div class="lrn-account-page-header__actions"><?php echo wp_kses_post( $lrn_actions ); ?></div>
	<?php endif; ?>
</header>
