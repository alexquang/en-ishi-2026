<?php
/**
 * Admin dashboard — Enrollment + Revenue trend charts (two-up).
 *
 * Charts render via ApexCharts wired up in admin JS that reads
 * learnomyDashboard.chartData.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="lrn-charts-grid lrn-mb-6">
	<!-- Enrollment Trend -->
	<div class="lrn-admin-card lrn-chart-card">
		<div class="lrn-chart-card__header">
			<h2 class="lrn-admin-card-heading"><?php esc_html_e( 'Enrollment Trends', 'learnomy' ); ?></h2>
			<div class="lrn-period-selector" data-chart="enrollments">
				<button type="button" class="lrn-period-btn" data-period="7"><?php esc_html_e( '7d', 'learnomy' ); ?></button>
				<button type="button" class="lrn-period-btn lrn-period-btn--active" data-period="30"><?php esc_html_e( '30d', 'learnomy' ); ?></button>
				<button type="button" class="lrn-period-btn" data-period="90"><?php esc_html_e( '90d', 'learnomy' ); ?></button>
			</div>
		</div>
		<div id="lrn-chart-enrollments" class="lrn-chart-container"></div>
	</div>

	<!-- Revenue Overview -->
	<div class="lrn-admin-card lrn-chart-card">
		<div class="lrn-chart-card__header">
			<h2 class="lrn-admin-card-heading">
				<?php esc_html_e( 'Revenue', 'learnomy' ); ?>
				<span id="lrn-revenue-total" class="lrn-chart-card__total"></span>
			</h2>
			<div class="lrn-period-selector" data-chart="revenue">
				<button type="button" class="lrn-period-btn" data-period="7"><?php esc_html_e( '7d', 'learnomy' ); ?></button>
				<button type="button" class="lrn-period-btn lrn-period-btn--active" data-period="30"><?php esc_html_e( '30d', 'learnomy' ); ?></button>
				<button type="button" class="lrn-period-btn" data-period="90"><?php esc_html_e( '90d', 'learnomy' ); ?></button>
			</div>
		</div>
		<div id="lrn-chart-revenue" class="lrn-chart-container"></div>
	</div>
</div>
