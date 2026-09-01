<?php
/**
 * Admin dashboard — View Frontend shortcuts widget body.
 *
 * Ships its own `.lrn-admin-card` chrome.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="lrn-admin-card lrn-mb-6">
	<h2 class="lrn-admin-card-heading">
		<?php esc_html_e( 'View Frontend', 'learnomy' ); ?>
	</h2>
	<div class="lrn-admin-actions-row">
		<a href="<?php echo esc_url( \Learnomy\route_url( 'catalog' ) ); ?>" target="_blank" class="lrn-admin-btn">
			<i data-lucide="grid-2x2"></i>
			<?php esc_html_e( 'Course Catalog', 'learnomy' ); ?>
		</a>
		<a href="<?php echo esc_url( \Learnomy\route_url( 'student-dashboard' ) ); ?>" target="_blank" class="lrn-admin-btn">
			<i data-lucide="gauge"></i>
			<?php printf( esc_html( /* translators: %s: singular label for a student (e.g. "Learner"). */ __( '%s Dashboard', 'learnomy' ) ), esc_html( \Learnomy\student_label() ) ); ?>
		</a>
		<a href="<?php echo esc_url( \Learnomy\route_url( 'instructor-dashboard' ) ); ?>" target="_blank" class="lrn-admin-btn">
			<i data-lucide="briefcase"></i>
			<?php printf( esc_html( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s Dashboard', 'learnomy' ) ), esc_html( \Learnomy\instructor_label() ) ); ?>
		</a>
		<a href="<?php echo esc_url( \Learnomy\route_url( 'membership-pricing' ) ); ?>" target="_blank" class="lrn-admin-btn">
			<i data-lucide="ticket"></i>
			<?php esc_html_e( 'Membership', 'learnomy' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-settings#pages' ) ); ?>" class="lrn-admin-btn">
			<i data-lucide="file-text"></i>
			<?php esc_html_e( 'All Pages', 'learnomy' ); ?>
		</a>
	</div>
</div>
