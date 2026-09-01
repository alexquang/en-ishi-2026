<?php
/**
 * Admin per-transaction detail.
 *
 * Surfaces what the ledger row hides — the commission split, the coupon
 * applied, the linked course/plan/enrollment/subscription, the refund
 * relationship, and the raw gateway metadata — plus the refund action.
 * Reached from the Transactions list (#id links here). Mirrors the other
 * detail pages.
 *
 * @package Learnomy
 * @var object        $transaction lrn_transactions row.
 * @var \WP_User|false $user       The customer.
 * @var object|null   $course      Linked course (or null).
 * @var object|null   $plan        Linked membership plan (or null).
 * @var object|null   $coupon      Applied coupon (or null).
 * @var object[]      $commissions Commission split rows for this transaction.
 * @var int           $refund_id   Refund transaction id (0 if none).
 * @var object|null   $original    Original transaction when this row is a refund.
 * @var string        $currency    Currency code.
 * @var bool          $refundable  Whether a refund action should show.
 */

defined( 'ABSPATH' ) || exit;

$list_url    = admin_url( 'admin.php?page=learnomy-transactions' );
$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$txn_id      = (int) $transaction->id;
$amount      = (float) ( $transaction->amount ?? 0 );
$is_refund   = 'refund' === ( $transaction->transaction_type ?? '' );

if ( $course ) {
	$item_title = (string) $course->title;
} elseif ( $plan ) {
	$item_title = (string) $plan->title;
} else {
	$item_title = __( 'Purchase', 'learnomy' );
}

$fmt = static function ( $dt ) use ( $date_format ) {
	return ! empty( $dt ) ? mysql2date( $date_format, (string) $dt ) : '—';
};

// Pretty-print metadata_json for the read-only block.
$metadata_pretty = '';
if ( ! empty( $transaction->metadata_json ) ) {
	$decoded         = json_decode( (string) $transaction->metadata_json, true );
	$metadata_pretty = ( null !== $decoded )
		? (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		: (string) $transaction->metadata_json;
}
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="receipt" class="lrn-page-header__icon"></i>
				<?php
				/* translators: %d: transaction id */
				printf( esc_html__( 'Transaction #%d', 'learnomy' ), absint( $txn_id ) );
				?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a class="lrn-admin-btn" href="<?php echo esc_url( $list_url ); ?>">
				<i data-lucide="arrow-left"></i>
				<?php esc_html_e( 'All transactions', 'learnomy' ); ?>
			</a>
			<?php
			/**
			 * Pro extension point for per-transaction actions in the header.
			 *
			 * @param int $transaction_id The transaction ID.
			 */
			do_action( 'learnomy_admin_transaction_detail_actions', $txn_id );
			?>
			<?php if ( $refundable ) : ?>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--danger lrn-txn-refund-btn"
						data-txn-id="<?php echo absint( $txn_id ); ?>"
						data-txn-amount="<?php echo esc_attr( number_format( $amount, 2, '.', '' ) ); ?>"
						data-txn-currency="<?php echo esc_attr( $currency ); ?>"
						data-txn-customer="<?php echo esc_attr( $user ? $user->display_name : '' ); ?>"
						data-txn-item="<?php echo esc_attr( $item_title ); ?>">
					<i data-lucide="rotate-ccw"></i>
					<?php esc_html_e( 'Refund', 'learnomy' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>

	<hr class="wp-header-end">

	<div class="lrn-admin-grid lrn-admin-grid--2">

		<!-- Transaction overview -->
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Transaction', 'learnomy' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
					<td>
						<strong><?php echo esc_html( \Learnomy\format_price( $amount, $currency ) ); ?></strong>
						<?php $lrn_listed = \Learnomy\Models\Transaction::simulated_listed_price( $transaction ); ?>
						<?php if ( null !== $lrn_listed ) : ?>
							<br><small class="lrn-muted">
								<?php
								if ( $lrn_listed > 0 ) {
									/* translators: %s: the price the buyer was shown at checkout */
									echo esc_html( sprintf( __( 'Test order, no charge (listed %s)', 'learnomy' ), \Learnomy\format_price( $lrn_listed, $currency ) ) );
								} else {
									esc_html_e( 'Test order, no charge', 'learnomy' );
								}
								?>
							</small>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Type', 'learnomy' ); ?></th>
					<td><?php echo esc_html( \Learnomy\Admin\UX::enum_label( (string) ( $transaction->transaction_type ?? '' ), 'transaction_type' ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<td>
						<?php
						echo \Learnomy\Admin\UX::status_badge( $refund_id ? 'refunded' : (string) ( $transaction->status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Provider', 'learnomy' ); ?></th>
					<td><?php echo esc_html( ! empty( $transaction->payment_provider ) ? \Learnomy\Admin\UX::enum_label( (string) $transaction->payment_provider, 'payment_provider' ) : '—' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Gateway reference', 'learnomy' ); ?></th>
					<td><code><?php echo esc_html( $transaction->provider_transaction_id ?: '—' ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
					<td><?php echo esc_html( $fmt( $transaction->created_at ?? '' ) ); ?></td>
				</tr>
				<?php if ( $is_refund && $original ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Refund of', 'learnomy' ); ?></th>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-transaction-detail&transaction_id=' . absint( $original->id ) ) ); ?>">
								<?php printf( /* translators: %d: transaction ID */ esc_html__( 'Transaction #%d', 'learnomy' ), absint( $original->id ) ); ?>
							</a>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ( $refund_id ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Refunded by', 'learnomy' ); ?></th>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-transaction-detail&transaction_id=' . absint( $refund_id ) ) ); ?>">
								<?php printf( /* translators: %d: transaction ID */ esc_html__( 'Transaction #%d', 'learnomy' ), absint( $refund_id ) ); ?>
							</a>
						</td>
					</tr>
				<?php endif; ?>
			</table>
		</div>

		<!-- Parties & links -->
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Customer & items', 'learnomy' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Customer', 'learnomy' ); ?></th>
					<td>
						<?php if ( $user ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-student-detail&user_id=' . absint( $user->ID ) ) ); ?>">
								<?php echo esc_html( $user->display_name ); ?>
							</a>
							<div class="lrn-muted"><a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></div>
						<?php else : ?>
							<span class="lrn-muted"><?php esc_html_e( 'Unknown', 'learnomy' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Item', 'learnomy' ); ?></th>
					<td>
						<?php if ( $course ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-course-detail&course_id=' . absint( $course->id ) ) ); ?>"><?php echo esc_html( $course->title ); ?></a>
						<?php elseif ( $plan ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-membership-edit&plan_id=' . absint( $plan->id ) ) ); ?>"><?php echo esc_html( $plan->title ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $item_title ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( ! empty( $transaction->subscription_id ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Subscription', 'learnomy' ); ?></th>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-subscription-detail&subscription_id=' . absint( $transaction->subscription_id ) ) ); ?>">
								<?php printf( /* translators: %d: subscription ID */ esc_html__( 'Subscription #%d', 'learnomy' ), absint( $transaction->subscription_id ) ); ?>
							</a>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ( $coupon ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Coupon', 'learnomy' ); ?></th>
						<td>
							<code><?php echo esc_html( (string) $coupon->code ); ?></code>
							<span class="lrn-muted">
								<?php
								echo 'percent' === ( $coupon->type ?? '' )
									? esc_html( number_format_i18n( (float) $coupon->value, 0 ) . '% off' )
									: esc_html( \Learnomy\format_price( (float) $coupon->value, $currency ) . ' off' );
								?>
							</span>
						</td>
					</tr>
				<?php endif; ?>
			</table>
		</div>
	</div>

	<!-- Commission split -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Commission split', 'learnomy' ); ?></h2>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Recipient', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rate', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Payout status', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $commissions ) ) : ?>
					<tr><td colspan="4" class="lrn-muted"><?php esc_html_e( 'No commission records for this transaction.', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $commissions as $lrn_comm ) :
						$rtype = (string) ( $lrn_comm->recipient_type ?? '' );
						if ( 'platform' === $rtype ) {
							$recipient = __( 'Platform', 'learnomy' );
						} else {
							$ruser     = get_userdata( (int) ( $lrn_comm->recipient_id ?? 0 ) );
							$recipient = $ruser ? $ruser->display_name : sprintf( '#%d', (int) ( $lrn_comm->recipient_id ?? 0 ) );
						}
						?>
						<tr>
							<td><?php echo esc_html( $recipient ); ?> <span class="lrn-muted">(<?php echo esc_html( $rtype ); ?>)</span></td>
							<td><?php echo esc_html( \Learnomy\format_price( (float) ( $lrn_comm->amount ?? 0 ), $currency ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) ( $lrn_comm->rate_applied ?? 0 ), 2 ) . '%' ); ?></td>
							<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $lrn_comm->payout_status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>

	<!-- Raw gateway metadata -->
	<?php if ( '' !== $metadata_pretty ) : ?>
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Gateway metadata', 'learnomy' ); ?></h2>
			<pre class="lrn-code-block"><?php echo esc_html( $metadata_pretty ); ?></pre>
		</div>
	<?php endif; ?>

</div><!-- /.learnomy-admin -->
