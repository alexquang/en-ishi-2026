<?php
/**
 * Recent enrollments widget body.
 *
 * Locals: $recent_enrollments
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $recent_enrollments ) ) {
	return;
}
?>
<ul class="lrn-activity-list">
	<?php
	foreach ( $recent_enrollments as $enr ) :
		$student = get_userdata( (int) $enr->user_id );
		if ( ! $student ) {
			continue;
		}
		?>
		<li class="lrn-activity-list__item">
			<?php echo get_avatar( (int) $enr->user_id, 32, '', $student->display_name, array( 'class' => 'lrn-activity-list__avatar' ) ); ?>
			<span class="lrn-activity-list__text">
				<strong><?php echo esc_html( $student->display_name ); ?></strong>
				<?php esc_html_e( 'enrolled in', 'learnomy' ); ?>
				<em><?php echo esc_html( $enr->course_title ); ?></em>
			</span>
			<time class="lrn-activity-list__time" datetime="<?php echo esc_attr( $enr->enrolled_at ); ?>">
				<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $enr->enrolled_at ) ) ); ?>
			</time>
		</li>
	<?php endforeach; ?>
</ul>
