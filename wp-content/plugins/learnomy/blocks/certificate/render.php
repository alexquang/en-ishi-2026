<?php
/**
 * Render: learnomy/certificate
 *
 * @package Learnomy
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Certificate;
use Learnomy\Models\Course;

ob_start();

$uuid = isset( $attributes['uuid'] ) ? sanitize_text_field( $attributes['uuid'] ) : '';

if ( ! $uuid ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'Enter a certificate ID (UUID) to display its verified details.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

$certificate = Certificate::verify( $uuid );

if ( ! $certificate ) {
	?>
	<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
	<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-certificate lrn-certificate--invalid lrn-card' ) ); ?>>
		<p class="lrn-certificate__status lrn-certificate__status--invalid">
			<?php esc_html_e( 'This certificate could not be verified.', 'learnomy' ); ?>
		</p>
	</div>
	<?php
	\Learnomy\Blocks::render_filtered( 'certificate', ob_get_clean(), $attributes );
	return;
}

$is_active = ( 'active' === ( $certificate->status ?? '' ) );

$recipient = get_userdata( (int) ( $certificate->user_id ?? 0 ) );
$name      = $recipient ? esc_html( $recipient->display_name ) : esc_html__( 'Unknown', 'learnomy' );

$course       = $certificate->course_id ? Course::find( (int) $certificate->course_id ) : null;
$course_title = $course ? esc_html( $course->title ) : '';

$issued_date = '';
if ( ! empty( $certificate->issued_at ) ) {
	$ts          = strtotime( $certificate->issued_at );
	$issued_date = $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
}
?>
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-certificate lrn-card' . ( $is_active ? '' : ' lrn-certificate--revoked' ) ) ); ?>>
	<?php if ( $is_active ) : ?>
		<div class="lrn-certificate__badge" aria-label="<?php esc_attr_e( 'Verified certificate', 'learnomy' ); ?>">
			<span class="lrn-badge lrn-badge--success"><?php esc_html_e( 'Verified', 'learnomy' ); ?></span>
		</div>
	<?php else : ?>
		<div class="lrn-certificate__badge">
			<span class="lrn-badge lrn-badge--warning"><?php esc_html_e( 'Revoked', 'learnomy' ); ?></span>
		</div>
	<?php endif; ?>
	<div class="lrn-certificate__details">
		<p class="lrn-certificate__recipient">
			<strong><?php esc_html_e( 'Issued to:', 'learnomy' ); ?></strong> <?php echo esc_html( $name ); ?>
		</p>
		<?php if ( $course_title ) : ?>
			<p class="lrn-certificate__course">
				<strong><?php esc_html_e( 'Course:', 'learnomy' ); ?></strong> <?php echo esc_html( $course_title ); ?>
			</p>
		<?php endif; ?>
		<?php if ( $issued_date ) : ?>
			<p class="lrn-certificate__date">
				<strong><?php esc_html_e( 'Issued on:', 'learnomy' ); ?></strong> <?php echo esc_html( $issued_date ); ?>
			</p>
		<?php endif; ?>
		<p class="lrn-certificate__uuid">
			<small><?php esc_html_e( 'Verification ID:', 'learnomy' ); ?> <?php echo esc_html( $uuid ); ?></small>
		</p>
	</div>
</div>
<?php
\Learnomy\Blocks::render_filtered( 'certificate', ob_get_clean(), $attributes );
