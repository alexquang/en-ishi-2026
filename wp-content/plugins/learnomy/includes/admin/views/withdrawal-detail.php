<?php
/**
 * Admin per-withdrawal detail.
 *
 * The payout record plus the commission lines it batches (each links to its
 * transaction). Reached from the Earnings withdrawals list; works for any
 * status so processed / rejected payouts stay inspectable. Mirrors the other
 * detail pages.
 *
 * @package Learnomy
 * @var object         $withdrawal  lrn_withdrawals row.
 * @var \WP_User|false $instructor  The instructor.
 * @var object[]       $commissions Commission rows batched into this withdrawal.
 * @var string         $currency    Currency code.
 */

defined( 'ABSPATH' ) || exit;

$list_url    = admin_url( 'admin.php?page=learnomy-earnings' );
$date_format = get_option( 'date_format' );
$wid         = (int) $withdrawal->id;
$status      = (string) ( $withdrawal->status ?? 'pending' );
$is_pending  = 'pending' === $status;

$fmt = static function ( $dt ) use ( $date_format ) {
	return \Learnomy\date_label( $dt, '—', $date_format );
};

$batched_total = 0.0;
foreach ( $commissions as $lrn_c ) {
	$batched_total += (float) ( $lrn_c->amount ?? 0 );
}
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="banknote" class="lrn-page-header__icon"></i>
				<?php
				/* translators: %d: withdrawal id */
				printf( esc_html__( 'Withdrawal #%d', 'learnomy' ), absint( $wid ) );
				?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a class="lrn-admin-btn" href="<?php echo esc_url( $list_url ); ?>">
				<i data-lucide="arrow-left"></i>
				<?php esc_html_e( 'Back to Earnings', 'learnomy' ); ?>
			</a>
			<?php if ( $is_pending ) : ?>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--primary"
						data-action="learnomy_approve_withdrawal"
						data-id="<?php echo absint( $wid ); ?>"
						data-id-field="withdrawal_id"
						data-confirm="<?php esc_attr_e( 'Approve this withdrawal?', 'learnomy' ); ?>">
					<i data-lucide="check"></i>
					<?php esc_html_e( 'Approve', 'learnomy' ); ?>
				</button>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--danger"
						data-action="learnomy_reject_withdrawal"
						data-id="<?php echo absint( $wid ); ?>"
						data-id-field="withdrawal_id"
						data-prompt="<?php esc_attr_e( 'Tell the instructor why this withdrawal was rejected. They will see this reason.', 'learnomy' ); ?>"
						data-prompt-label="<?php esc_attr_e( 'Rejection reason', 'learnomy' ); ?>">
					<i data-lucide="x"></i>
					<?php esc_html_e( 'Reject', 'learnomy' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>

	<hr class="wp-header-end">

	<!-- Payout overview -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Payout', 'learnomy' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Instructor', 'learnomy' ); ?></th>
				<td>
					<?php if ( $instructor ) : ?>
						<a href="
						<?php
						echo esc_url(
							add_query_arg(
								array(
									'page'          => 'learnomy-instructor-detail',
									'instructor_id' => absint( $instructor->ID ),
								),
								admin_url( 'admin.php' )
							)
						);
						?>
									">
							<?php echo esc_html( $instructor->display_name ); ?>
						</a>
						<div class="lrn-muted"><a href="mailto:<?php echo esc_attr( $instructor->user_email ); ?>"><?php echo esc_html( $instructor->user_email ); ?></a></div>
					<?php else : ?>
						<span class="lrn-muted"><?php esc_html_e( 'Unknown', 'learnomy' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
				<td><strong><?php echo esc_html( $currency . ' ' . number_format_i18n( (float) ( $withdrawal->amount ?? 0 ), 2 ) ); ?></strong></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<td><?php echo \Learnomy\Admin\UX::status_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Method', 'learnomy' ); ?></th>
				<td><?php echo esc_html( $withdrawal->method ?: '—' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Send payment to', 'learnomy' ); ?></th>
				<td>
					<?php if ( ! empty( $withdrawal->destination ) ) : ?>
						<?php // PayPal payout — the payee email the instructor gave on this request. ?>
						<code><?php echo esc_html( (string) $withdrawal->destination ); ?></code>
					<?php elseif ( 'manual' === ( $withdrawal->method ?? '' ) ) : ?>
						<span class="description"><?php esc_html_e( 'Arranged by email - contact the instructor for payment details.', 'learnomy' ); ?></span>
					<?php else : ?>
						<span class="description">&mdash;</span>
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( ! empty( $withdrawal->reference ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Reference', 'learnomy' ); ?></th>
					<td><code><?php echo esc_html( (string) $withdrawal->reference ); ?></code></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Requested', 'learnomy' ); ?></th>
				<td><?php echo esc_html( $fmt( $withdrawal->requested_at ?? '' ) ); ?></td>
			</tr>
			<?php if ( ! empty( $withdrawal->processed_at ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Processed', 'learnomy' ); ?></th>
					<td><?php echo esc_html( $fmt( $withdrawal->processed_at ) ); ?></td>
				</tr>
			<?php endif; ?>
			<?php if ( 'rejected' === $status && ! empty( $withdrawal->rejection_reason ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rejection reason', 'learnomy' ); ?></th>
					<td><?php echo esc_html( (string) $withdrawal->rejection_reason ); ?></td>
				</tr>
			<?php endif; ?>
		</table>
	</div>

	<!-- Batched commissions -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<?php
			printf(
				/* translators: 1: commission count, 2: total amount */
				esc_html__( 'Commissions in this payout (%1$s · %2$s)', 'learnomy' ),
				esc_html( number_format_i18n( count( $commissions ) ) ),
				esc_html( $currency . ' ' . number_format_i18n( $batched_total, 2 ) )
			);
			?>
		</h2>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Transaction', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Period', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Payout status', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $commissions ) ) : ?>
					<tr><td colspan="4" class="lrn-muted"><?php esc_html_e( 'No commission lines are linked to this withdrawal.', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $commissions as $lrn_comm ) : ?>
						<tr>
							<td>
								<?php if ( ! empty( $lrn_comm->transaction_id ) ) : ?>
									<a class="lrn-admin-table__primary" href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'page' => 'learnomy-transaction-detail',
												'transaction_id' => absint( $lrn_comm->transaction_id ),
											),
											admin_url( 'admin.php' )
										)
									);
									?>
																				">
										<?php printf( /* translators: %d: transaction ID */ esc_html__( '#%d', 'learnomy' ), absint( $lrn_comm->transaction_id ) ); ?>
									</a>
								<?php else : ?>
									<span class="lrn-muted">—</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $lrn_comm->payout_period ?: '—' ); ?></td>
							<td><?php echo esc_html( $currency . ' ' . number_format_i18n( (float) ( $lrn_comm->amount ?? 0 ), 2 ) ); ?></td>
							<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $lrn_comm->payout_status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>

</div><!-- /.learnomy-admin -->
