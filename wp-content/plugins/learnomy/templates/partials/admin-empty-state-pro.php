<?php
/**
 * Pro feature pointer card — used as an empty-state hint on Free admin
 * list pages where related functionality lives in a Pro extension.
 *
 * The partial is intentionally informational. It points the operator at the
 * Pro Features admin page so they can see *what* the Pro extension does,
 * but it does not link off-site or include any acquisition CTA. Plan-09
 * will add release-time links once Pro ships.
 *
 * Required scope before include():
 *   $feature_slug (string)        — Pro feature slug from data/pro-features.json
 *   $intro_message (string|null)  — optional one-line lead-in shown above the card
 *
 * Renders nothing if:
 *   - the feature slug is unknown to the catalog, OR
 *   - the Pro plugin is already installed (the operator has the real
 *     thing, no point pointing at a snapshot).
 *
 * @package Learnomy\Admin
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Admin\Pro_Catalog;

if ( Pro_Catalog::pro_plugin_active() ) {
	return;
}

// Site owners only. This block is a Pro upsell whose only action is "View in the
// Pro Features catalog" — a page registered manage_options. It is injected into
// the LESSON EDITOR, which instructors use, so an instructor was shown a pitch
// for a product they cannot buy, behind a button that answered "Sorry, you are
// not allowed to access this page" (Basecamp 10082715894). Buying Pro is the site
// owner's decision; an instructor has nothing to do with this.
if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$feature_slug = isset( $feature_slug ) ? (string) $feature_slug : '';
$feature      = $feature_slug ? Pro_Catalog::find( $feature_slug ) : null;

if ( null === $feature ) {
	return;
}

$intro_message = isset( $intro_message ) && '' !== $intro_message ? (string) $intro_message : '';
$pro_url       = admin_url( 'admin.php?page=learnomy-pro-features' );
$status_label  = 'stable' === $feature['status']
	? __( 'Stable', 'learnomy' )
	: __( 'Beta', 'learnomy' );
?>
<div class="lrn-empty-state-pro" style="margin: 16px 0; padding: 16px 18px; background: #fafbfc; border: 1px solid #dcdcde; border-radius: 6px;">
	<?php if ( '' !== $intro_message ) : ?>
		<p style="margin: 0 0 6px; font-weight: 600; color: #1d2327;">
			<?php echo esc_html( $intro_message ); ?>
		</p>
	<?php endif; ?>
	<p style="margin: 0 0 8px; color: #50575e; line-height: 1.5;">
		<strong><?php echo esc_html( $feature['name'] ); ?></strong>
		<span style="display: inline-block; margin-left: 6px; padding: 1px 6px; font-size: 10px; background: #f0c33c; color: #000; border-radius: 3px; text-transform: uppercase; vertical-align: middle;"><?php echo esc_html_x( 'PRO', 'product tier badge', 'learnomy' ); ?></span>
		<span style="display: inline-block; margin-left: 4px; padding: 1px 6px; font-size: 10px; background: <?php echo 'stable' === $feature['status'] ? '#cfe9d5' : '#fce7c2'; ?>; color: #1d2327; border-radius: 3px; text-transform: uppercase; vertical-align: middle;"><?php echo esc_html( $status_label ); ?></span>.
		<?php echo esc_html( $feature['description'] ); ?>
	</p>
	<p style="margin: 0;">
		<a href="<?php echo esc_url( $pro_url ); ?>" class="button button-secondary">
			<?php esc_html_e( 'View in the Pro Features catalog', 'learnomy' ); ?>
		</a>
	</p>
</div>
