<?php
/**
 * Admin dashboard — System Health widget body.
 *
 * Three operator-facing signals at a glance: WP-Cron health, debug.log
 * size, and Action Scheduler queue depth. Each sub-stat colors itself
 * by severity (green / amber / red) so an operator scanning the
 * dashboard catches a stuck cron without opening Tools → Site Health.
 *
 * Ships its own .lrn-admin-card chrome to match the admin design
 * system (admin widgets use shell='none').
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// ── 1. WP-Cron health ─────────────────────────────────────────────
$cron_ready   = function_exists( 'wp_get_ready_cron_jobs' ) ? (array) wp_get_ready_cron_jobs() : array();
$cron_overdue = 0;
$cron_cutoff  = time() - ( 5 * MINUTE_IN_SECONDS );
foreach ( $cron_ready as $ts => $_jobs ) {
	if ( (int) $ts < $cron_cutoff ) {
		$cron_overdue += is_array( $_jobs ) ? count( $_jobs, COUNT_RECURSIVE ) : 1;
	}
}
$cron_state = $cron_overdue > 0 ? 'warn' : 'ok';
$cron_label = $cron_overdue > 0
	? sprintf(
		/* translators: %d: count of overdue cron jobs */
		_n( '%d overdue job', '%d overdue jobs', $cron_overdue, 'learnomy' ),
		$cron_overdue
	)
	: __( 'Healthy', 'learnomy' );

// ── 2. Debug log size ─────────────────────────────────────────────
$debug_log_path  = WP_CONTENT_DIR . '/debug.log';
$debug_log_bytes = file_exists( $debug_log_path ) ? (int) filesize( $debug_log_path ) : 0;
$debug_label     = $debug_log_bytes > 0 ? size_format( $debug_log_bytes ) : __( 'Empty', 'learnomy' );
// 50 MB threshold — anything past that is "fill your disk" territory.
$debug_state = 'ok';
if ( $debug_log_bytes > 50 * MB_IN_BYTES ) {
	$debug_state = 'danger';
} elseif ( $debug_log_bytes > 5 * MB_IN_BYTES ) {
	$debug_state = 'warn';
}

// ── 3. Action Scheduler queue depth ───────────────────────────────
$as_count = 0;
$as_state = 'ok';
$as_label = __( 'n/a', 'learnomy' );
if ( function_exists( 'as_get_scheduled_actions' ) ) {
	$as_count = (int) as_get_scheduled_actions(
		array(
			'status'   => 'pending',
			'per_page' => 1,
		),
		'count'
	);
	$as_label = number_format_i18n( $as_count );
	if ( $as_count > 1000 ) {
		$as_state = 'danger';
	} elseif ( $as_count > 200 ) {
		$as_state = 'warn';
	}
}

$state_to_class = static function ( string $state ): string {
	switch ( $state ) {
		case 'warn':
			return 'lrn-admin-stat-card__icon lrn-admin-stat-card__icon--warn';
		case 'danger':
			return 'lrn-admin-stat-card__icon lrn-admin-stat-card__icon--danger';
		default:
			return 'lrn-admin-stat-card__icon';
	}
};
?>
<div class="lrn-admin-card lrn-mb-6">
	<h2 class="lrn-admin-card-heading">
		<?php esc_html_e( 'System Health', 'learnomy' ); ?>
	</h2>
	<div class="lrn-admin-stats">
		<div class="lrn-admin-stat-card">
			<div class="<?php echo esc_attr( $state_to_class( $cron_state ) ); ?>">
				<i data-lucide="clock" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( $cron_label ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'WP-Cron', 'learnomy' ); ?></span>
			</div>
		</div>
		<div class="lrn-admin-stat-card">
			<div class="<?php echo esc_attr( $state_to_class( $debug_state ) ); ?>">
				<i data-lucide="file-text" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( $debug_label ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'Debug Log', 'learnomy' ); ?></span>
			</div>
		</div>
		<div class="lrn-admin-stat-card">
			<div class="<?php echo esc_attr( $state_to_class( $as_state ) ); ?>">
				<i data-lucide="layers" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( $as_label ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'Action Scheduler', 'learnomy' ); ?></span>
			</div>
		</div>
	</div>
	<div class="lrn-admin-actions-row lrn-mt-3">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-email-log' ) ); ?>" class="lrn-admin-btn">
			<i data-lucide="mail" aria-hidden="true"></i>
			<?php esc_html_e( 'Email Log', 'learnomy' ); ?>
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-webhook-events' ) ); ?>" class="lrn-admin-btn">
			<i data-lucide="webhook" aria-hidden="true"></i>
			<?php esc_html_e( 'Webhook Events', 'learnomy' ); ?>
		</a>
		<?php if ( function_exists( 'as_get_scheduled_actions' ) ) : ?>
			<a href="<?php echo esc_url( admin_url( 'tools.php?page=action-scheduler' ) ); ?>" class="lrn-admin-btn">
				<i data-lucide="list" aria-hidden="true"></i>
				<?php esc_html_e( 'Queue Detail', 'learnomy' ); ?>
			</a>
		<?php endif; ?>
	</div>
</div>
