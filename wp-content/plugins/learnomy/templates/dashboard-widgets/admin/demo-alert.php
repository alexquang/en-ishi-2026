<?php
/**
 * Admin dashboard — demo-data alert widget.
 *
 * Locals: $has_demo
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $has_demo ) ) {
	return;
}
?>
<div class="lrn-admin-card lrn-admin-alert lrn-mb-6">
	<div>
		<strong><?php esc_html_e( 'Demo content is active', 'learnomy' ); ?></strong>
		<span class="lrn-admin-alert__text">
			<?php esc_html_e( 'Sample courses, users, and enrollments are loaded. Remove when ready to go live.', 'learnomy' ); ?>
		</span>
	</div>
	<?php
	// No id, deliberately. The class is the JS contract (admin.js listens on
	// document for `.js-lrn-remove-demo`), so this button works no matter how
	// many surfaces render or when. It used to carry id `lrn-remove-demo-btn`,
	// which the onboarding guide also used — the duplicate id made
	// getElementById bind only the first, leaving this one inert
	// (Basecamp 10128600685). Never give a repeatable control an id.
	?>
	<button
		type="button"
		class="lrn-admin-btn lrn-admin-btn--danger js-lrn-remove-demo"
		data-nonce="<?php echo esc_attr( wp_create_nonce( 'learnomy_admin' ) ); ?>"
	><?php esc_html_e( 'Remove Demo Content', 'learnomy' ); ?></button>
</div>
