<?php
/**
 * Admin per-subscription detail (management view).
 *
 * The full record for one membership subscription plus the admin actions the
 * list can't hold: cancel at period end vs immediately, resume, extend. Reached
 * from the Subscriptions list (subscriber name links here). Mirrors the
 * student / instructor / course detail layout.
 *
 * @package Learnomy
 * @var object        $subscription lrn_member_subscriptions row.
 * @var \WP_User|false $user        The subscriber.
 * @var object|null   $plan         The membership plan (or null if deleted).
 * @var object[]      $transactions Renewal / payment history for this subscription.
 * @var string        $currency     Store currency code.
 */

defined( 'ABSPATH' ) || exit;

$list_url     = admin_url( 'admin.php?page=learnomy-subscriptions' );
$date_format  = get_option( 'date_format' );
$sub_id       = (int) $subscription->id;
$status       = $subscription->status ?? 'active';
$cancel_pend  = ! empty( $subscription->cancel_at_period_end ) && 'active' === $status;
$status_label = \Learnomy\Services\Subscription_Service::status_label( $subscription );

$can_cancel     = in_array( $status, array( 'active', 'trialing', 'past_due' ), true );
$can_period_end = 'active' === $status && ! $cancel_pend;
$can_resume     = $cancel_pend || in_array( $status, array( 'paused', 'past_due' ), true );
$can_extend     = in_array( $status, array( 'active', 'trialing', 'past_due' ), true );

$fmt = static function ( $dt ) use ( $date_format ) {
	return \Learnomy\date_label( $dt, '—', $date_format );
};

$extend_value = '';
if ( ! empty( $subscription->current_period_end ) ) {
	$ts = strtotime( (string) $subscription->current_period_end );
	if ( $ts ) {
		$extend_value = date_i18n( 'Y-m-d\TH:i', $ts );
	}
}
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="repeat" class="lrn-page-header__icon"></i>
				<?php echo esc_html( $user ? $user->display_name : __( 'Subscription', 'learnomy' ) ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a class="lrn-admin-btn" href="<?php echo esc_url( $list_url ); ?>">
				<i data-lucide="arrow-left"></i>
				<?php esc_html_e( 'All subscriptions', 'learnomy' ); ?>
			</a>
			<?php
			/**
			 * Pro extension point for per-subscription actions in the header.
			 *
			 * @param int $subscription_id The subscription ID.
			 */
			do_action( 'learnomy_admin_subscription_detail_actions', $sub_id );
			?>
			<?php if ( $can_resume ) : ?>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--primary"
						data-action="learnomy_admin_resume_subscription"
						data-subscription-id="<?php echo absint( $sub_id ); ?>"
						data-confirm="<?php esc_attr_e( 'Resume this subscription?', 'learnomy' ); ?>">
					<i data-lucide="play"></i>
					<?php esc_html_e( 'Resume', 'learnomy' ); ?>
				</button>
			<?php endif; ?>
			<?php if ( $can_period_end ) : ?>
				<button type="button" class="lrn-admin-btn"
						data-action="learnomy_admin_cancel_subscription"
						data-subscription-id="<?php echo absint( $sub_id ); ?>"
						data-at-period-end="1"
						data-confirm="<?php esc_attr_e( 'Cancel at period end? The member keeps access until the current period finishes, then it will not renew.', 'learnomy' ); ?>">
					<i data-lucide="calendar-x"></i>
					<?php esc_html_e( 'Cancel at period end', 'learnomy' ); ?>
				</button>
			<?php endif; ?>
			<?php if ( $can_cancel ) : ?>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--danger"
						data-action="learnomy_admin_cancel_subscription"
						data-subscription-id="<?php echo absint( $sub_id ); ?>"
						data-at-period-end="0"
						data-confirm="<?php esc_attr_e( 'Cancel immediately? Access is revoked now.', 'learnomy' ); ?>">
					<i data-lucide="x-circle"></i>
					<?php esc_html_e( 'Cancel now', 'learnomy' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>

	<hr class="wp-header-end">

	<div class="lrn-admin-grid lrn-admin-grid--2">

		<!-- Subscription overview -->
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Subscription', 'learnomy' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<td><?php echo \Learnomy\Admin\UX::status_badge( $cancel_pend ? 'cancel_pending' : (string) $status, (string) $status_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Plan', 'learnomy' ); ?></th>
					<td>
						<?php if ( $plan ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-membership-edit&plan_id=' . absint( $plan->id ) ) ); ?>">
								<?php echo esc_html( $plan->title ); ?>
							</a>
						<?php else : ?>
							<span class="lrn-muted"><?php esc_html_e( 'Deleted plan', 'learnomy' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
					<td>
						<?php
						echo esc_html(
							null !== $subscription->amount
								? $currency . ' ' . number_format_i18n( (float) $subscription->amount, 2 ) . ' / ' . ( $subscription->billing_interval ?? '' )
								: '—'
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Current period', 'learnomy' ); ?></th>
					<td><?php echo esc_html( $fmt( $subscription->current_period_start ) . ' → ' . $fmt( $subscription->current_period_end ) ); ?></td>
				</tr>
				<?php if ( ! empty( $subscription->trial_ends_at ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Trial ends', 'learnomy' ); ?></th>
						<td><?php echo esc_html( $fmt( $subscription->trial_ends_at ) ); ?></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cancels at period end', 'learnomy' ); ?></th>
					<td>
						<?php
						// Route through the shared badge helper — hand-writing
						// `lrn-status-badge--<variant>` here is what let the variant
						// drift away from the vocabulary in UX::status_variants().
						echo $cancel_pend
							? \Learnomy\Admin\UX::status_badge( 'cancel_pending', __( 'Yes', 'learnomy' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							: '<span class="lrn-muted">' . esc_html__( 'No', 'learnomy' ) . '</span>';
						?>
					</td>
				</tr>
				<?php if ( ! empty( $subscription->cancelled_at ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cancelled at', 'learnomy' ); ?></th>
						<td><?php echo esc_html( $fmt( $subscription->cancelled_at ) ); ?></td>
					</tr>
				<?php endif; ?>
			</table>
		</div>

		<!-- Subscriber + provider -->
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Subscriber & provider', 'learnomy' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Subscriber', 'learnomy' ); ?></th>
					<td>
						<?php if ( $user ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-student-detail&user_id=' . absint( $user->ID ) ) ); ?>">
								<?php echo esc_html( $user->display_name ); ?>
							</a>
							<div class="lrn-muted"><a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></div>
						<?php else : ?>
							<span class="lrn-muted"><?php esc_html_e( 'Unknown user', 'learnomy' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Provider', 'learnomy' ); ?></th>
					<td><?php echo esc_html( $subscription->payment_provider ?: '—' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Provider subscription ID', 'learnomy' ); ?></th>
					<td><code><?php echo esc_html( $subscription->provider_subscription_id ?: '—' ); ?></code></td>
				</tr>
				<?php if ( ! empty( $subscription->provider_price_id ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Provider price ID', 'learnomy' ); ?></th>
						<td><code><?php echo esc_html( $subscription->provider_price_id ); ?></code></td>
					</tr>
				<?php endif; ?>
			</table>

			<?php if ( $can_extend ) : ?>
				<div id="lrn-sub-extend" class="lrn-grant-access" data-subscription-id="<?php echo absint( $sub_id ); ?>">
					<label for="lrn-sub-extend-date" class="screen-reader-text"><?php esc_html_e( 'New period end', 'learnomy' ); ?></label>
					<input type="datetime-local" id="lrn-sub-extend-date" class="regular-text" value="<?php echo esc_attr( $extend_value ); ?>">
					<button type="button" class="lrn-admin-btn" id="lrn-sub-extend-btn">
						<i data-lucide="calendar-clock"></i>
						<?php esc_html_e( 'Extend period', 'learnomy' ); ?>
					</button>
				</div>
				<p class="description"><?php esc_html_e( 'Move the current period end forward (e.g. a goodwill extension). Must be a future date.', 'learnomy' ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<!-- Payments / renewals -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<?php
			printf(
				/* translators: %s: payment count */
				esc_html__( 'Payments (%s)', 'learnomy' ),
				esc_html( number_format_i18n( count( $transactions ) ) )
			);
			?>
		</h2>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $transactions ) ) : ?>
					<tr><td colspan="4" class="lrn-muted"><?php esc_html_e( 'No payments on record for this subscription.', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $transactions as $lrn_txn ) : ?>
						<tr>
							<td><?php echo esc_html( $fmt( $lrn_txn->created_at ?? '' ) ); ?></td>
							<td><?php echo esc_html( str_replace( '_', ' ', (string) ( $lrn_txn->transaction_type ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( strtoupper( (string) ( $lrn_txn->currency ?? $currency ) ) . ' ' . number_format_i18n( (float) ( $lrn_txn->amount ?? 0 ), 2 ) ); ?></td>
							<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $lrn_txn->status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>

</div><!-- /.learnomy-admin -->
