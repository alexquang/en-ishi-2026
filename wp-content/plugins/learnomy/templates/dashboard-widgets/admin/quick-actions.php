<?php
/**
 * Admin dashboard — Quick Actions widget body.
 *
 * Ships its own `.lrn-admin-card` chrome because the admin design system
 * differs from the frontend `.lrn-card` shell.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="lrn-admin-card lrn-mb-6">
	<h2 class="lrn-admin-card-heading">
		<?php esc_html_e( 'Quick Actions', 'learnomy' ); ?>
	</h2>
	<div class="lrn-admin-actions-row">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-courses' ) ); ?>" class="lrn-admin-btn lrn-admin-btn--primary">
			<?php esc_html_e( 'Manage Courses', 'learnomy' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-students' ) ); ?>" class="lrn-admin-btn">
			<?php printf( esc_html( /* translators: %s: plural label for students (e.g. "Learners"). */ __( 'View %s', 'learnomy' ) ), esc_html( \Learnomy\student_label( true ) ) ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-questions' ) ); ?>" class="lrn-admin-btn">
			<?php esc_html_e( 'Question Bank', 'learnomy' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-certificates' ) ); ?>" class="lrn-admin-btn">
			<?php esc_html_e( 'Certificates', 'learnomy' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-settings' ) ); ?>" class="lrn-admin-btn">
			<?php esc_html_e( 'Settings', 'learnomy' ); ?>
		</a>
	</div>
</div>
