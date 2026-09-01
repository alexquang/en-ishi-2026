<?php
/**
 * Admin dashboard — Email / Notification Log summary widget.
 *
 * Today + past-7-day counts plus the most-active notification type.
 * Helps an operator catch "we sent way more notifications than usual"
 * before it shows up as an inbox flood from users.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$summary = \Learnomy\Models\Notification::activity_summary();
$today   = (int) $summary['today'];
$week    = (int) $summary['week'];
if ( $week <= 0 ) {
	return;
}

$top_type  = (string) $summary['top_type'];
$top_count = (int) $summary['top_count'];
$top_label = $top_type ? ucwords( str_replace( array( '_', '-' ), ' ', $top_type ) ) : '';
?>
<div class="lrn-admin-card lrn-mb-6">
	<h2 class="lrn-admin-card-heading">
		<?php esc_html_e( 'Notification Activity', 'learnomy' ); ?>
	</h2>
	<div class="lrn-admin-stats">
		<div class="lrn-admin-stat-card">
			<div class="lrn-admin-stat-card__icon">
				<i data-lucide="send" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( number_format_i18n( $today ) ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'Sent Today', 'learnomy' ); ?></span>
			</div>
		</div>
		<div class="lrn-admin-stat-card">
			<div class="lrn-admin-stat-card__icon">
				<i data-lucide="calendar-clock" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( number_format_i18n( $week ) ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'Past 7 Days', 'learnomy' ); ?></span>
			</div>
		</div>
		<?php if ( $top_label && $top_count > 0 ) : ?>
			<div class="lrn-admin-stat-card">
				<div class="lrn-admin-stat-card__icon">
					<i data-lucide="trending-up" aria-hidden="true"></i>
				</div>
				<div class="lrn-admin-stat-card__body">
					<span class="lrn-admin-stat-card__number"><?php echo esc_html( $top_label ); ?></span>
					<span class="lrn-admin-stat-card__label">
						<?php
						printf(
							/* translators: %d: notification count for the most active type */
							esc_html( _n( 'Top type · %d sent', 'Top type · %d sent', $top_count, 'learnomy' ) ),
							(int) $top_count
						);
						?>
					</span>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-email-log' ) ); ?>" class="lrn-admin-btn">
		<?php esc_html_e( 'Open Email Log', 'learnomy' ); ?>
	</a>
</div>
