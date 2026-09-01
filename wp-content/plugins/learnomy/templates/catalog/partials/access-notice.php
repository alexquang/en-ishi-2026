<?php
/**
 * Catalog access-denied notice.
 *
 * Surfaces a reason when the visitor was redirected to the catalog after being
 * denied access (e.g. the Pro front-end builder bounces an instructor who
 * opened another instructor's course with ?access=not_your_course). The param
 * was produced but never read before, so the visitor just landed here with no
 * explanation. Messages are a filterable map so any module can register its
 * own reason key. Shared by every catalog layout variant.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET navigation hint, no state change.
$lrn_access_reason = isset( $_GET['access'] ) ? sanitize_key( wp_unslash( $_GET['access'] ) ) : '';
if ( '' === $lrn_access_reason ) {
	return;
}

$lrn_access_messages = apply_filters(
	'learnomy_catalog_access_notices',
	array(
		'not_your_course' => __( 'That course belongs to another instructor, so it could not be opened.', 'learnomy' ),
	)
);

$lrn_access_notice = (string) ( $lrn_access_messages[ $lrn_access_reason ] ?? '' );
if ( '' === $lrn_access_notice ) {
	return;
}
?>
<div class="lrn-banner lrn-banner--warning" role="status">
	<?php echo esc_html( $lrn_access_notice ); ?>
</div>
