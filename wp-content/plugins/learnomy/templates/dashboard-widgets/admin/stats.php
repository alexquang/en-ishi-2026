<?php
/**
 * Admin dashboard — stat cards row.
 *
 * Locals: $stats (associative array of counts).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$stat_cards = array(
	array(
		'key'   => 'total_courses',
		'label' => __( 'Total Courses', 'learnomy' ),
		'icon'  => 'book-open',
	),
	array(
		'key'   => 'active_enrollments',
		'label' => __( 'Active Enrollments', 'learnomy' ),
		'icon'  => 'users',
	),
	array(
		'key'   => 'revenue_transactions',
		'label' => __( 'Transactions', 'learnomy' ),
		'icon'  => 'bar-chart-3',
	),
	array(
		'key'   => 'total_certificates',
		'label' => __( 'Certificates Issued', 'learnomy' ),
		'icon'  => 'award',
	),
	array(
		'key'   => 'pending_reviews',
		'label' => __( 'Pending Reviews', 'learnomy' ),
		'icon'  => 'star',
		'alert' => true,
	),
	array(
		'key'   => 'pending_applications',
		'label' => sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s Applications', 'learnomy' ), \Learnomy\instructor_label() ),
		'icon'  => 'clipboard-list',
		'alert' => true,
	),
);
?>
<div class="lrn-admin-stats">
	<?php
	foreach ( $stat_cards as $card ) :
		// Solo academy mode has no instructor marketplace — drop the
		// applications KPI so the dashboard doesn't imply open registration.
		if ( 'pending_applications' === $card['key'] && \Learnomy\solo_mode() ) {
			continue;
		}
		$count      = isset( $stats[ $card['key'] ] ) ? (int) $stats[ $card['key'] ] : 0;
		$is_alert   = ! empty( $card['alert'] ) && $count > 0;
		$icon_class = $is_alert ? 'lrn-admin-stat-card__icon lrn-admin-stat-card__icon--warn' : 'lrn-admin-stat-card__icon';
		?>
		<div class="lrn-admin-stat-card">
			<div class="<?php echo esc_attr( $icon_class ); ?>">
				<i data-lucide="<?php echo esc_attr( $card['icon'] ); ?>"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php echo esc_html( $card['label'] ); ?></span>
			</div>
		</div>
	<?php endforeach; ?>
</div>
