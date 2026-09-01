<?php
/**
 * Admin dashboard — Completion donut + Top Courses table (two-up).
 *
 * Both populate via admin JS reading learnomyDashboard.chartData.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="lrn-charts-grid lrn-mb-6">
	<!-- Course Completion Donut -->
	<div class="lrn-admin-card lrn-chart-card">
		<div class="lrn-chart-card__header">
			<h2 class="lrn-admin-card-heading"><?php esc_html_e( 'Course Completion Rate', 'learnomy' ); ?></h2>
		</div>
		<div id="lrn-chart-completion" class="lrn-chart-container lrn-chart-container--donut"></div>
	</div>

	<!-- Top Courses Table -->
	<div class="lrn-admin-card lrn-chart-card">
		<div class="lrn-chart-card__header">
			<h2 class="lrn-admin-card-heading"><?php esc_html_e( 'Top Courses', 'learnomy' ); ?></h2>
		</div>
		<?php // Scroll wrapper, not .lrn-admin-table-wrap: this table is already inside a card. ?>
		<div class="lrn-admin-table-scroll">
		<table class="lrn-top-courses-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Course', 'learnomy' ); ?></th>
					<th><?php echo esc_html( \Learnomy\student_label( true ) ); ?></th>
					<th><?php esc_html_e( 'Revenue', 'learnomy' ); ?></th>
					<th><?php esc_html_e( 'Completion', 'learnomy' ); ?></th>
					<th><?php esc_html_e( 'Trend', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody id="lrn-top-courses-body">
				<!-- Populated by JS -->
			</tbody>
		</table>
		</div>
	</div>
</div>
