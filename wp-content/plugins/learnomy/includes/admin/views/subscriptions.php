<?php
/**
 * Admin membership subscriptions list view (Pattern B).
 *
 * Status vocabulary matches Subscription::get_active_for_user() expectations:
 *   trialing | active | past_due | cancelled | expired. Every row has two
 *   admin actions — Cancel (immediate via Subscription_Service) and Extend
 *   (set a new current_period_end via the admin AJAX handler).
 *
 * @package Learnomy
 * @var object[] $subscriptions Paginated subscription rows.
 * @var int      $total         Total for current filter.
 * @var int      $total_pages   Total pages.
 * @var int      $paged         Current page.
 * @var string   $status_filter Active status slug.
 * @var int      $plan_filter   Active plan filter (0 = all).
 * @var object[] $all_plans     All plans for the filter dropdown.
 * @var int      $per_page      Rows per page.
 * @var array<int,object> $plans_by_id plan_id => plan row for the current page (batched).
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Services\Subscription_Service;

$base_url = admin_url( 'admin.php?page=learnomy-subscriptions' );

// Filter dropdown labels — reuse the canonical human labels where possible,
// plus admin-only buckets (All, Cancel Pending) that the shared map doesn't
// need to know about.
$shared_labels = Subscription_Service::status_labels();
$statuses      = array(
	'all'            => __( 'All', 'learnomy' ),
	'trialing'       => $shared_labels['trialing'] ?? __( 'In trial', 'learnomy' ),
	'active'         => $shared_labels['active'] ?? __( 'Active', 'learnomy' ),
	'past_due'       => $shared_labels['past_due'] ?? __( 'Payment failed', 'learnomy' ),
	'cancel_pending' => __( 'Ending soon', 'learnomy' ),
	'cancelled'      => $shared_labels['cancelled'] ?? __( 'Cancelled', 'learnomy' ),
	'expired'        => $shared_labels['expired'] ?? __( 'Ended', 'learnomy' ),
);
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="users-round" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php esc_html_e( 'Subscriptions', 'learnomy' ); ?>
			</h1>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<div class="lrn-admin-filters">
		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="lrn-search-form">
			<input type="hidden" name="page" value="learnomy-subscriptions">

			<label for="lrn-sub-status-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'learnomy' ); ?></label>
			<select id="lrn-sub-status-filter" name="status">
				<?php foreach ( $statuses as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status_filter, $value ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="lrn-sub-plan-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by plan', 'learnomy' ); ?></label>
			<select id="lrn-sub-plan-filter" name="plan_id">
				<option value="0"><?php esc_html_e( 'All plans', 'learnomy' ); ?></option>
				<?php foreach ( $all_plans as $plan_option ) : ?>
					<option value="<?php echo esc_attr( (string) $plan_option->id ); ?>" <?php selected( (int) $plan_filter, (int) $plan_option->id ); ?>>
						<?php echo esc_html( $plan_option->title ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<button type="submit" class="lrn-admin-btn"><?php esc_html_e( 'Filter', 'learnomy' ); ?></button>
		</form>

		<?php
		// Direct download via the REST endpoint. WP REST honours `_wpnonce`
		// in the query string for cookie-auth nonce validation, so the link
		// can be a plain anchor without JS.
		$lrn_csv_subs_url = add_query_arg(
			array(
				'status'   => $status_filter,
				'plan_id'  => (int) $plan_filter,
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
			),
			rest_url( 'learnomy/v1/admin/memberships/export' )
		);
		?>
		<a class="lrn-admin-btn lrn-admin-btn--ghost lrn-csv-export" href="<?php echo esc_url( $lrn_csv_subs_url ); ?>">
			<i data-lucide="download" aria-hidden="true"></i>
			<?php esc_html_e( 'Export as CSV', 'learnomy' ); ?>
		</a>

		<span class="lrn-admin-filter-count">
			<?php
			printf(
				/* translators: %s: formatted number of subscriptions. */
				esc_html__( '%s subscriptions', 'learnomy' ),
				esc_html( number_format_i18n( (int) $total ) )
			);
			?>
		</span>
	</div>

	<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'User', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Plan', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Period', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Provider', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $subscriptions ) ) : ?>
					<tr>
						<td colspan="6" class="lrn-no-items">
							<?php
							$lrn_empty_state = array(
								'icon'        => 'repeat',
								'title'       => __( 'No subscriptions yet', 'learnomy' ),
								'description' => __( 'Active subscriptions appear here as soon as anyone signs up to a membership plan.', 'learnomy' ),
							);
							include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
							?>
						</td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $subscriptions as $sub ) :
						// User cache primed in the controller (cache_users), so this
						// get_userdata() is a cache hit — no per-row query. Plan comes
						// from the batched $plans_by_id map (one find_in() upfront).
						$user              = get_userdata( (int) $sub->user_id );
						$plan              = $plans_by_id[ (int) $sub->plan_id ] ?? null;
						$status            = $sub->status ?? 'active';
						$is_cancel_pending = ! empty( $sub->cancel_at_period_end ) && 'active' === $status;
						$status_slug       = $is_cancel_pending ? 'cancel_pending' : $status;
						$status_label      = Subscription_Service::status_label( $sub );
						$start             = \Learnomy\date_label( $sub->current_period_start, '—', get_option( 'date_format' ) );
						$end               = \Learnomy\date_label( $sub->current_period_end, '—', get_option( 'date_format' ) );
						?>
						<tr data-sub-id="<?php echo esc_attr( (string) $sub->id ); ?>">
							<td>
								<div class="lrn-user-cell">
									<?php if ( $user ) : ?>
										<?php echo get_avatar( $user->ID, 32, '', '', array( 'class' => 'lrn-avatar lrn-avatar--sm' ) ); ?>
										<span class="lrn-user-cell__name lrn-user-cell__name--stacked">
											<a class="lrn-admin-table__primary" href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-subscription-detail&subscription_id=' . absint( $sub->id ) ) ); ?>">
												<strong><?php echo esc_html( $user->display_name ); ?></strong>
											</a>
											<a class="lrn-user-cell__email" href="mailto:<?php echo esc_attr( $user->user_email ); ?>">
												<?php echo esc_html( $user->user_email ); ?>
											</a>
										</span>
									<?php else : ?>
										<em><?php esc_html_e( 'Unknown user', 'learnomy' ); ?></em>
									<?php endif; ?>
								</div>
							</td>
							<td><?php echo $plan ? esc_html( $plan->title ) : '<em>' . esc_html__( 'Deleted plan', 'learnomy' ) . '</em>'; ?></td>
							<td>
								<?php
								echo \Learnomy\Admin\UX::status_badge( (string) $status_slug, (string) $status_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</td>
							<td><?php echo esc_html( $start . ' → ' . $end ); ?></td>
							<td><?php echo esc_html( $sub->payment_provider ?? '—' ); ?></td>
							<td class="lrn-row-actions">
								<?php
								if ( in_array( $status, array( 'active', 'trialing', 'past_due' ), true ) ) {
									$lrn_sub_actions = array(
										array(
											'label'   => __( 'Extend', 'learnomy' ),
											'icon'    => 'calendar-clock',
											'primary' => true,
											'attrs'   => array(
												'class' => 'lrn-sub-extend',
												'data-sub-id' => (string) $sub->id,
												'data-period-end' => (string) ( $sub->current_period_end ?? '' ),
											),
										),
										array(
											'label'  => __( 'Cancel', 'learnomy' ),
											'icon'   => 'x-circle',
											'danger' => true,
											'attrs'  => array(
												'class' => 'lrn-sub-cancel',
												'data-sub-id' => (string) $sub->id,
											),
										),
									);
									echo \Learnomy\Admin\UX::row_actions( $lrn_sub_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								} else {
									?>
									<span class="lrn-muted"><?php esc_html_e( 'No actions', 'learnomy' ); ?></span>
									<?php
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<?php
	$lrn_pagination = array(
		'current'  => (int) $paged,
		'total'    => (int) $total_pages,
		'results'  => (int) $total,
		'per_page' => (int) $per_page,
		'base_url' => $base_url,
	);
	require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
	?>

	<!-- Extend modal -->
	<div class="lrn-modal-backdrop lrn-modal-backdrop--hidden" id="lrn-extend-modal" role="dialog" aria-modal="true" aria-labelledby="lrn-extend-title">
		<div class="lrn-modal">
			<h3 id="lrn-extend-title"><?php esc_html_e( 'Extend subscription', 'learnomy' ); ?></h3>
			<p class="lrn-modal__desc"><?php esc_html_e( 'Pick a new end date for the current billing period. Access continues until this date.', 'learnomy' ); ?></p>
			<div class="lrn-form-field">
				<label for="lrn-extend-date"><?php esc_html_e( 'New period end', 'learnomy' ); ?></label>
				<input type="date" id="lrn-extend-date" required>
			</div>
			<div class="lrn-form-actions">
				<button type="button" class="lrn-admin-btn lrn-admin-btn--ghost" id="lrn-extend-cancel">
					<?php esc_html_e( 'Cancel', 'learnomy' ); ?>
				</button>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-extend-confirm">
					<?php esc_html_e( 'Extend', 'learnomy' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>
