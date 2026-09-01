<?php
/**
 * Admin membership plans list view (Pattern B).
 *
 * Data layer:
 *   - Plans come from MembershipPlan::where() so we can filter by status.
 *   - Course + active-subscriber counts are batched in the controller
 *     (one GROUP BY query each, keyed by plan_id) and passed in as
 *     $course_counts / $subscriber_counts. The view reads the maps; it runs
 *     no per-row queries.
 *
 * @package Learnomy
 * @var object[] $plans       Paginated plan rows.
 * @var int      $total       Total plan count for current filter.
 * @var int      $total_pages Total pages.
 * @var int      $paged       Current page.
 * @var string   $status_filter Active status filter slug (all | active | inactive).
 * @var string   $search      Search query.
 * @var int      $per_page    Rows per page.
 * @var array<int,int> $course_counts     plan_id => course count (batched, missing = 0).
 * @var array<int,int> $subscriber_counts plan_id => active subscriber count (batched, missing = 0).
 */

defined( 'ABSPATH' ) || exit;

$base_url = admin_url( 'admin.php?page=learnomy-memberships' );
$settings = get_option( 'learnomy_settings', array() );
$currency = ( is_array( $settings ) && ! empty( $settings['currency'] ) ) ? (string) $settings['currency'] : 'USD';

$status_tabs = array(
	'all'      => __( 'All', 'learnomy' ),
	'active'   => __( 'Active', 'learnomy' ),
	'inactive' => __( 'Inactive', 'learnomy' ),
);

$interval_labels = array(
	'daily'   => __( 'day', 'learnomy' ),
	'weekly'  => __( 'week', 'learnomy' ),
	'monthly' => __( 'month', 'learnomy' ),
	'yearly'  => __( 'year', 'learnomy' ),
);

// Show the template picker when the site has zero plans — only on the
// default view (no filter/search active) so the admin can still reach
// an empty filtered list without bypassing the normal list UI.
$show_template_picker = empty( $plans )
	&& 'all' === $status_filter
	&& '' === $search
	&& (int) $total === 0;

$base_edit_url = admin_url( 'admin.php?page=learnomy-membership-edit' );
$templates     = array(
	'basic_monthly'  => array(
		'title'    => __( 'Monthly Basic', 'learnomy' ),
		'summary'  => __( '$9 per month · 14-day trial · 5 courses', 'learnomy' ),
		'body'     => __( 'A lightweight starter plan with a low price point and a generous trial so new subscribers can take it for a spin.', 'learnomy' ),
		'icon'     => 'zap',
		'gradient' => 'blue',
	),
	'yearly_premium' => array(
		'title'    => __( 'Yearly Premium', 'learnomy' ),
		'summary'  => __( '$79 per year · no trial · unlimited courses', 'learnomy' ),
		'body'     => __( 'Best value for committed learners. Featured on your pricing page by default.', 'learnomy' ),
		'icon'     => 'crown',
		'gradient' => 'amber',
	),
	'free_trial'     => array(
		'title'    => __( 'Free Trial', 'learnomy' ),
		'summary'  => __( '14 days free, then $29/month', 'learnomy' ),
		'body'     => __( 'Low-friction onboarding. Great for high-ticket content or cohort-based courses.', 'learnomy' ),
		'icon'     => 'gift',
		'gradient' => 'green',
	),
	'custom'         => array(
		'title'    => __( 'Custom', 'learnomy' ),
		'summary'  => __( 'Start from a blank form', 'learnomy' ),
		'body'     => __( 'Build from scratch when none of the presets match what you need.', 'learnomy' ),
		'icon'     => 'sparkles',
		'gradient' => 'neutral',
	),
);
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="badge-dollar-sign" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php esc_html_e( 'Membership Plans', 'learnomy' ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-membership-edit' ) ); ?>" class="lrn-admin-btn lrn-admin-btn--primary">
				<i data-lucide="plus" aria-hidden="true"></i>
				<?php esc_html_e( 'Add New', 'learnomy' ); ?>
			</a>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<?php
	if ( $show_template_picker ) :
		// Plan-08: show a Pro pointer above the template picker when Pro
		// isn't installed. Surfaces Memberships Pro features (plan changes,
		// pause/resume, fair credit, refunds) without an external CTA.
		$feature_slug  = 'membership-pro';
		$intro_message = __( 'Plan changes, fair credit, and pause/resume are available with Learnomy Pro.', 'learnomy' );
		include \Learnomy\template_path( 'partials/admin-empty-state-pro.php' );
		unset( $feature_slug, $intro_message );
		?>
		<!-- Empty state — template picker. Only shown when the site has no plans yet. -->
		<section class="lrn-plan-picker" aria-labelledby="lrn-plan-picker-title">
			<header class="lrn-plan-picker__header">
				<h2 id="lrn-plan-picker-title" class="lrn-plan-picker__title">
					<?php esc_html_e( 'Start with a template', 'learnomy' ); ?>
				</h2>
				<p class="lrn-plan-picker__desc">
					<?php esc_html_e( 'Pick a preset to pre-fill the plan editor. You can change every field before saving.', 'learnomy' ); ?>
				</p>
			</header>
			<div class="lrn-plan-picker__grid">
				<?php
				foreach ( $templates as $slug => $tpl ) :
					$href = 'custom' === $slug
						? $base_edit_url
						: add_query_arg( array( 'template' => $slug ), $base_edit_url );
					?>
					<a class="lrn-plan-picker__card lrn-plan-picker__card--<?php echo esc_attr( $tpl['gradient'] ); ?>"
						href="<?php echo esc_url( $href ); ?>">
						<span class="lrn-plan-picker__icon" aria-hidden="true">
							<i data-lucide="<?php echo esc_attr( $tpl['icon'] ); ?>"></i>
						</span>
						<span class="lrn-plan-picker__name"><?php echo esc_html( $tpl['title'] ); ?></span>
						<span class="lrn-plan-picker__summary"><?php echo esc_html( $tpl['summary'] ); ?></span>
						<span class="lrn-plan-picker__body"><?php echo esc_html( $tpl['body'] ); ?></span>
						<span class="lrn-plan-picker__cta">
							<?php esc_html_e( 'Use this template', 'learnomy' ); ?>
							<i data-lucide="arrow-right" aria-hidden="true"></i>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
		</section>
	<?php else : ?>

	<!-- Filters -->
	<div class="lrn-admin-filters">
		<ul class="subsubsub">
			<?php
			foreach ( $status_tabs as $slug => $label ) :
				$url        = add_query_arg( array( 'status' => $slug ), $base_url );
				$is_current = ( $status_filter === $slug );
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"
						class="<?php echo esc_attr( $is_current ? 'current' : '' ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
					<?php echo 'inactive' !== $slug ? ' |' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="lrn-search-form">
			<input type="hidden" name="page" value="learnomy-memberships">
			<?php if ( 'all' !== $status_filter ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
			<?php endif; ?>
			<input type="search" name="s" aria-label="<?php esc_attr_e( 'Search', 'learnomy' ); ?>" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search plans…', 'learnomy' ); ?>" class="regular-text">
			<button type="submit" class="lrn-admin-btn"><?php esc_html_e( 'Search', 'learnomy' ); ?></button>
		</form>

		<a class="lrn-admin-btn lrn-admin-btn--ghost lrn-csv-export"
			href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'learnomy/v1/admin/membership-plans/export' ) ) ); ?>">
			<i data-lucide="download" aria-hidden="true"></i>
			<?php esc_html_e( 'Export as CSV', 'learnomy' ); ?>
		</a>
	</div>

	<!-- Plans table -->
	<div class="lrn-bulk-bar lrn-bulk-bar--hidden">
		<div class="lrn-bulk-bar__left">
			<span class="lrn-bulk-bar__count">0 selected</span>
			<select id="lrn-bulk-action">
				<option value=""><?php esc_html_e( 'Bulk Actions', 'learnomy' ); ?></option>
				<option value="activate"><?php esc_html_e( 'Activate', 'learnomy' ); ?></option>
				<option value="deactivate"><?php esc_html_e( 'Deactivate', 'learnomy' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete', 'learnomy' ); ?></option>
			</select>
			<button type="button" id="lrn-bulk-apply" class="lrn-admin-btn lrn-admin-btn--sm">
				<?php esc_html_e( 'Apply', 'learnomy' ); ?>
			</button>
		</div>
	</div>
	<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table" id="lrn-memberships-table">
			<thead>
				<tr>
					<th class="lrn-col-check"><input type="checkbox" id="lrn-select-all" aria-label="<?php esc_attr_e( 'Select all', 'learnomy' ); ?>"></th>
					<th scope="col"><?php esc_html_e( 'Title', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Price', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Trial', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Courses', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Subscribers', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $plans ) ) : ?>
					<tr>
						<td colspan="8" class="lrn-no-items">
							<?php
							$lrn_empty_state = array(
								'icon'        => 'badge-check',
								'title'       => __( 'No plans yet', 'learnomy' ),
								'description' => __( 'Create a membership plan to offer subscriptions that bundle access to multiple courses.', 'learnomy' ),
							);
							include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
							?>
						</td>
					</tr>
				<?php else : ?>
					<?php
					foreach ( $plans as $plan ) :
						$plan_id = (int) $plan->id;
						// Counts are batched in the controller (one GROUP BY query
						// each) and passed in keyed by plan_id; default to 0 for
						// plans absent from the map.
						$course_count     = (int) ( $course_counts[ $plan_id ] ?? 0 );
						$subscriber_count = (int) ( $subscriber_counts[ $plan_id ] ?? 0 );

						$interval_key   = $plan->billing_interval ?? 'monthly';
						$interval_label = $interval_labels[ $interval_key ] ?? $interval_key;
						$interval_count = max( 1, (int) ( $plan->interval_count ?? 1 ) );
						$price_currency = $plan->currency ?? $currency;

						if ( ! empty( $plan->price_monthly ) || ! empty( $plan->price_yearly ) ) {
							$price_label = sprintf(
								/* translators: 1: currency code 2: monthly price 3: yearly price */
								__( '%1$s %2$s/mo · %3$s/yr', 'learnomy' ),
								esc_html( $price_currency ),
								number_format_i18n( (float) $plan->price_monthly, 2 ),
								number_format_i18n( (float) $plan->price_yearly, 2 )
							);
						} else {
							if ( 1 === $interval_count ) {
								$price_label = sprintf(
									/* translators: 1: currency code 2: price 3: interval unit (day/week/month/year) */
									__( '%1$s %2$s / %3$s', 'learnomy' ),
									esc_html( $price_currency ),
									number_format_i18n( (float) $plan->price, 2 ),
									$interval_label
								);
							} else {
								$price_label = sprintf(
									/* translators: 1: currency code 2: price 3: interval count 4: interval unit (day/week/month/year) */
									__( '%1$s %2$s / %3$d %4$ss', 'learnomy' ),
									esc_html( $price_currency ),
									number_format_i18n( (float) $plan->price, 2 ),
									$interval_count,
									$interval_label
								);
							}
						}

						$status       = $plan->status ?? 'active';
						$status_slug  = 'active' === $status ? 'active' : 'inactive';
						$status_label = 'active' === $status ? __( 'Active', 'learnomy' ) : __( 'Inactive', 'learnomy' );

						$edit_url        = add_query_arg(
							array(
								'page'    => 'learnomy-membership-edit',
								'plan_id' => $plan_id,
							),
							admin_url( 'admin.php' )
						);
						$subscribers_url = add_query_arg(
							array(
								'page'    => 'learnomy-subscriptions',
								'plan_id' => $plan_id,
							),
							admin_url( 'admin.php' )
						);
						?>
						<tr data-plan-id="<?php echo esc_attr( (string) $plan_id ); ?>">
							<td class="lrn-col-check"><input type="checkbox" class="lrn-row-check" value="<?php echo esc_attr( (string) $plan_id ); ?>"></td>
							<td class="column-title">
								<?php if ( ! empty( $plan->thumbnail_url ) ) : ?>
									<img src="<?php echo esc_url( $plan->thumbnail_url ); ?>" alt="" class="lrn-plan-thumb" width="32" height="32">
								<?php endif; ?>
								<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $plan->title ); ?></a></strong>
								<?php if ( ! empty( $plan->is_featured ) ) : ?>
									<span class="lrn-status-badge lrn-status-badge--featured"><?php esc_html_e( 'Featured', 'learnomy' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $price_label ); ?></td>
							<td>
								<?php
								$trial_days = (int) ( $plan->trial_days ?? 0 );
								if ( $trial_days > 0 ) {
									printf(
										/* translators: %d: number of trial days */
										esc_html( _n( '%d day', '%d days', $trial_days, 'learnomy' ) ),
										(int) $trial_days
									);
								} else {
									echo '<span class="lrn-muted">' . esc_html__( 'None', 'learnomy' ) . '</span>';
								}
								?>
							</td>
							<td><?php echo esc_html( number_format_i18n( $course_count ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $subscriber_count ) ); ?></td>
							<td>
								<button type="button" class="lrn-status-badge lrn-status-badge--<?php echo esc_attr( \Learnomy\Admin\UX::status_variant( (string) $status_slug ) ); ?> lrn-toggle-status"
										data-current="<?php echo esc_attr( $status ); ?>"
										title="<?php esc_attr_e( 'Click to toggle status', 'learnomy' ); ?>">
									<?php echo esc_html( $status_label ); ?>
								</button>
							</td>
							<td class="lrn-row-actions">
								<?php
								// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::render_action() escapes every label, href and attribute; the statement spans lines so a single-line ignore never covered it.
								echo \Learnomy\Admin\UX::row_actions(
									array(
										array(
											'label'   => __( 'Edit', 'learnomy' ),
											'href'    => $edit_url,
											'icon'    => 'pencil',
											'primary' => true,
										),
										array(
											'label' => __( 'Subscribers', 'learnomy' ),
											'href'  => $subscribers_url,
											'icon'  => 'users',
										),
										array(
											'label'  => __( 'Delete', 'learnomy' ),
											'icon'   => 'trash-2',
											'danger' => true,
											// Preserve the JS hook the delete handler binds to.
											'attrs'  => array( 'class' => 'lrn-delete-plan' ),
										),
									)
								);
								// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
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
		include LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
		?>
	<?php endif; ?>
</div>
