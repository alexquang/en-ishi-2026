<?php
/**
 * Admin dashboard — pending instructor applications alert widget.
 *
 * Locals: $stats (assoc array of counts).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$pending = isset( $stats['pending_applications'] ) ? (int) $stats['pending_applications'] : 0;
if ( $pending <= 0 ) {
	return;
}
?>
<div class="lrn-admin-card lrn-admin-alert lrn-admin-alert--warn">
	<div>
		<h2 class="lrn-admin-card-heading lrn-admin-card-heading--sm">
			<?php printf( esc_html( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( 'Pending %s Applications', 'learnomy' ) ), esc_html( \Learnomy\instructor_label() ) ); ?>
		</h2>
		<p class="lrn-admin-card-body">
			<?php
			printf(
				esc_html(
					/* translators: %d: number of pending instructor applications. */
					_n( '%d application awaiting review.', '%d applications awaiting review.', $pending, 'learnomy' )
				),
				esc_html( number_format_i18n( $pending ) )
			);
			?>
		</p>
	</div>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-applications' ) ); ?>" class="lrn-admin-btn lrn-admin-btn--primary">
		<?php esc_html_e( 'Review Applications', 'learnomy' ); ?>
	</a>
</div>
