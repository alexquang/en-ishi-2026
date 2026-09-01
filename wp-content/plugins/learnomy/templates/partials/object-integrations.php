<?php
/**
 * The "Community" control, shared by every object that can have one.
 *
 * Rendered identically on the course editor, the course builder, a cohort and
 * a Learnomy Space, so an owner learns one control rather than each product's
 * habits on each screen.
 *
 * THE WORDING LIVES HERE, not in the providers. It reads the same whichever
 * product is installed, so the docs and a support answer are the same
 * sentence on every site. It deliberately avoids "space": three products
 * already disagree about that word, and one of the objects this renders on is
 * itself called a Learnomy Space.
 *
 * The description answers the two questions owners actually ask - who gets in,
 * and what happens if I turn it off. The second matters because the honest
 * answer is "it hides, it does not delete", and saying so is what stops an
 * owner being afraid to touch it.
 *
 * Locals (set by Object_Integration_Registry::render):
 *   $lrn_provider — the single winning provider.
 *   $lrn_type     — object type: course | cohort | space.
 *   $lrn_id       — object id.
 *
 * NO <form> here. This renders inside whatever form the host screen already
 * has, and HTML forbids nested forms - the browser drops the inner one and
 * re-parents its children, silently turning a save button into a submit for
 * the outer form. The toggle posts to the REST route on change instead.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_oi_input = 'lrn-oi-' . sanitize_html_class( (string) $lrn_provider['id'] ) . '-' . (int) $lrn_id;
$lrn_oi_on    = (bool) call_user_func( $lrn_provider['is_enabled'] );

// A provider may offer a way IN to the room it made. Optional, and only shown
// once there is something to open - the old per-product panels carried this and
// losing it would have made the unified control strictly worse than what it
// replaces. Still no product wording: the link just says View.
$lrn_oi_url = isset( $lrn_provider['url'] ) && is_callable( $lrn_provider['url'] )
	? (string) call_user_func( $lrn_provider['url'] )
	: '';
?>
<div class="lrn-object-integrations postbox" data-lrn-object-type="<?php echo esc_attr( $lrn_type ); ?>" data-lrn-object-id="<?php echo esc_attr( (string) $lrn_id ); ?>">
	<h3 class="lrn-object-integrations__title"><?php esc_html_e( 'Community', 'learnomy' ); ?></h3>

	<label class="lrn-toggle" for="<?php echo esc_attr( $lrn_oi_input ); ?>">
		<input
			type="checkbox"
			id="<?php echo esc_attr( $lrn_oi_input ); ?>"
			data-lrn-object-integration="<?php echo esc_attr( (string) $lrn_provider['id'] ); ?>"
			<?php checked( $lrn_oi_on ); ?>
		>
		<span class="lrn-toggle__track"><span class="lrn-toggle__thumb"></span></span>
		<span class="lrn-toggle__label"><?php esc_html_e( 'Members get a place to talk', 'learnomy' ); ?></span>
	</label>

	<p class="description">
		<?php esc_html_e( 'People are added automatically as they join. Turning this off hides it; nothing posted is deleted.', 'learnomy' ); ?>
	</p>

	<?php if ( $lrn_oi_on && '' !== $lrn_oi_url ) : ?>
		<p class="lrn-object-integrations__link">
			<a href="<?php echo esc_url( $lrn_oi_url ); ?>" target="_blank" rel="noopener">
				<?php esc_html_e( 'View', 'learnomy' ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
