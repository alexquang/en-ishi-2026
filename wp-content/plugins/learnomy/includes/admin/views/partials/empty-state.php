<?php
/**
 * Shared admin empty-state partial.
 *
 * Drop-in for any admin list view that needs to show "no rows yet" copy.
 * Renders a Lucide icon, a sentence-case title, a one-line description, and
 * an optional primary CTA. Loaded styling lives in `assets/css/learnomy.css`
 * (`.lrn-empty-state` + `__icon` / `__title` / `__desc` / `__cta`).
 *
 * Usage:
 *
 *   $lrn_empty_state = array(
 *       'icon'        => 'inbox',
 *       'title'       => __( 'No items yet', 'learnomy' ),
 *       'description' => __( 'Create your first item to get started.', 'learnomy' ),
 *       'cta'         => array(  // optional
 *           'url'   => admin_url( 'admin.php?page=…' ),
 *           'label' => __( 'Add New', 'learnomy' ),
 *           'icon'  => 'plus',   // optional
 *       ),
 *   );
 *   include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

/**
 * @var array<string,mixed> $lrn_empty_state
 */
$lrn_empty_state = isset( $lrn_empty_state ) && is_array( $lrn_empty_state ) ? $lrn_empty_state : array();

$icon  = (string) ( $lrn_empty_state['icon'] ?? 'inbox' );
$title = (string) ( $lrn_empty_state['title'] ?? __( 'Nothing here yet', 'learnomy' ) );
$desc  = (string) ( $lrn_empty_state['description'] ?? '' );
$cta   = isset( $lrn_empty_state['cta'] ) && is_array( $lrn_empty_state['cta'] ) ? $lrn_empty_state['cta'] : null;
?>
<div class="lrn-empty-state">
	<i data-lucide="<?php echo esc_attr( $icon ); ?>" class="lrn-empty-state__icon" aria-hidden="true"></i>
	<h3 class="lrn-empty-state__title"><?php echo esc_html( $title ); ?></h3>
	<?php if ( '' !== $desc ) : ?>
		<p class="lrn-empty-state__desc"><?php echo esc_html( $desc ); ?></p>
	<?php endif; ?>

	<?php if ( $cta && ! empty( $cta['url'] ) && ! empty( $cta['label'] ) ) : ?>
		<a href="<?php echo esc_url( (string) $cta['url'] ); ?>" class="lrn-admin-btn lrn-admin-btn--primary">
			<?php if ( ! empty( $cta['icon'] ) ) : ?>
				<i data-lucide="<?php echo esc_attr( (string) $cta['icon'] ); ?>" aria-hidden="true"></i>
			<?php endif; ?>
			<?php echo esc_html( (string) $cta['label'] ); ?>
		</a>
	<?php endif; ?>
</div>
