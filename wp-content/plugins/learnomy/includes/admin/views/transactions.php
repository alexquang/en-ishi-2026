<?php
/**
 * Admin transactions list view.
 *
 * Dedicated page to inspect every transaction (course purchases, membership
 * payments, renewals, refunds) with filter + search + per-row refund button.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\Transaction;
use Learnomy\Admin\UX;

$currency_default = get_option( 'learnomy_settings', array() )['currency'] ?? 'USD';

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$filter_status   = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$filter_provider = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
$search          = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$paged           = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
// phpcs:enable
$per_page = 25;

// All querying lives in the model (architecture rule: no raw $wpdb in views).
$result      = Transaction::admin_list(
	array(
		'status'   => $filter_status,
		'provider' => $filter_provider,
		'search'   => $search,
		'paged'    => $paged,
		'per_page' => $per_page,
	)
);
$rows        = $result['rows'];
$total       = $result['total'];
$total_pages = $result['total_pages'];

$index_refunds = array();
foreach ( $rows as $r ) {
	if ( 'refund' === $r->transaction_type && ! empty( $r->refund_of ) ) {
		$index_refunds[ (int) $r->refund_of ] = $r;
	}
}

// How much has already been refunded on each ORIGINAL on this page, fetched in ONE
// grouped query. Asking per row would be a query per row — the N+1 the big-site
// checklist exists to prevent.
$refunded_totals = Transaction::refunded_totals_for(
	array_map(
		static fn( $r ) => (int) $r->id,
		array_filter( $rows, static fn( $r ) => 'refund' !== $r->transaction_type )
	)
);

$base_url = remove_query_arg( array( 'paged' ) );

$status_options   = array(
	''                   => __( 'All statuses', 'learnomy' ),
	'completed'          => __( 'Completed', 'learnomy' ),
	'refunded'           => __( 'Refunded', 'learnomy' ),
	'partially_refunded' => __( 'Partially refunded', 'learnomy' ),
	'pending'            => __( 'Pending', 'learnomy' ),
	'failed'             => __( 'Failed', 'learnomy' ),
);
$provider_options = array(
	''            => __( 'All gateways', 'learnomy' ),
	'stripe'      => 'Stripe',
	'paypal'      => 'PayPal',
	'test'        => __( 'Test', 'learnomy' ),
	'woocommerce' => 'WooCommerce',
	'demo'        => __( 'Demo', 'learnomy' ),
);

// REST nonce + base URL + i18n are localised to window.lrnTransactionsAdmin
// via Admin::enqueue_assets() — see assets/js/admin/transactions.js.
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="credit-card" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php esc_html_e( 'Transactions', 'learnomy' ); ?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php
				printf(
					/* translators: %s: count */
					esc_html( _n( '%s transaction', '%s transactions', $total, 'learnomy' ) ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</p>
		</div>
	</div>

	<hr class="wp-header-end">

	<form method="get" class="lrn-admin-filters">
		<input type="hidden" name="page" value="learnomy-transactions">

		<select name="status" class="lrn-admin-select" aria-label="<?php esc_attr_e( 'Filter by status', 'learnomy' ); ?>">
			<?php foreach ( $status_options as $val => $label ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_status, $val ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<select name="provider" class="lrn-admin-select" aria-label="<?php esc_attr_e( 'Filter by payment provider', 'learnomy' ); ?>">
			<?php foreach ( $provider_options as $val => $label ) : ?>
				<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filter_provider, $val ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<input
			type="search"
			name="s" aria-label="<?php esc_attr_e( 'Search', 'learnomy' ); ?>"
			value="<?php echo esc_attr( $search ); ?>"
			class="lrn-admin-input"
			placeholder="<?php esc_attr_e( 'Email, name, order ID, provider txn', 'learnomy' ); ?>">

		<button type="submit" class="lrn-admin-btn"><?php esc_html_e( 'Filter', 'learnomy' ); ?></button>

		<?php if ( '' !== $filter_status || '' !== $filter_provider || '' !== $search ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-transactions' ) ); ?>" class="lrn-admin-btn lrn-admin-btn--ghost">
				<?php esc_html_e( 'Reset', 'learnomy' ); ?>
			</a>
		<?php endif; ?>
	</form>

	<div class="lrn-admin-table-wrap">
	<table class="lrn-admin-table lrn-txn-table">
		<thead>
			<tr>
				<th class="lrn-txn-col-id"><?php esc_html_e( 'ID', 'learnomy' ); ?></th>
				<th class="lrn-txn-col-customer"><?php esc_html_e( 'Customer', 'learnomy' ); ?></th>
				<th class="lrn-txn-col-item"><?php esc_html_e( 'Item', 'learnomy' ); ?></th>
				<th class="lrn-txn-col-amount"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
				<th class="lrn-txn-col-type"><?php esc_html_e( 'Type', 'learnomy' ); ?></th>
				<th class="lrn-txn-col-provider"><?php esc_html_e( 'Provider', 'learnomy' ); ?></th>
				<th class="lrn-txn-col-status"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<th class="lrn-txn-col-date"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
				<th class="lrn-txn-col-actions"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="9" class="lrn-admin-empty"><?php esc_html_e( 'No transactions match these filters.', 'learnomy' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $r ) : ?>
					<?php
					$item_title = '';
					if ( ! empty( $r->course_id ) ) {
						$c          = Course::find( (int) $r->course_id );
						$item_title = $c ? (string) $c->title : sprintf( '#%d', (int) $r->course_id );
					} elseif ( ! empty( $r->plan_id ) ) {
						$p          = MembershipPlan::find( (int) $r->plan_id );
						$item_title = $p ? (string) $p->title : sprintf( '#%d', (int) $r->plan_id );
					}
					$is_refund_row = ( 'refund' === $r->transaction_type );
					$has_refund    = isset( $index_refunds[ (int) $r->id ] );

					// Trust the stored status. This line used to force 'refunded' whenever a
					// paired refund row existed, which — once partial refunds landed — meant a
					// $25 refund on a $100 order displayed as fully "Refunded", telling the site
					// owner the whole order had been returned when it had not.
					//
					// The fallback below only fires for rows refunded BEFORE Refund_Service began
					// writing the status (those kept 'completed'): a row that has a refund but does
					// not say so itself.
					$row_status = (string) $r->status;
					if ( $has_refund && ! in_array( $row_status, array( 'refunded', 'partially_refunded' ), true ) ) {
						$row_status = 'refunded';
					}
					// What is still claimable on this sale. A partially-refunded order keeps
					// its Refund button: the old rule was "no refund row exists", which
					// meant a $25 refund on a $100 sale hid the button forever and stranded
					// the other $75.
					$remaining  = $is_refund_row
						? 0.0
						: Transaction::refundable_remaining( $r, $refunded_totals[ (int) $r->id ] ?? 0.0 );
					$refundable = ! $is_refund_row
						&& $remaining > 0.0
						&& in_array( (string) $r->status, array( 'completed', 'partially_refunded' ), true )
						&& in_array( (string) $r->payment_provider, array( 'stripe', 'paypal', 'test' ), true );
					$currency   = strtoupper( (string) ( $r->currency ?? $currency_default ) );
					?>
					<tr<?php echo $is_refund_row ? ' class="lrn-row-refund"' : ''; ?>>
						<td class="lrn-txn-col-id"><a class="lrn-admin-table__primary" href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-transaction-detail&transaction_id=' . absint( $r->id ) ) ); ?>">#<?php echo (int) $r->id; ?></a></td>
						<td class="lrn-txn-col-customer">
							<?php if ( $r->user_id ) : ?>
								<strong><?php echo esc_html( $r->user_name ?: '—' ); ?></strong><br>
								<small><?php echo esc_html( $r->user_email ?: '' ); ?></small>
							<?php else : ?>
								<em><?php esc_html_e( 'Unknown', 'learnomy' ); ?></em>
							<?php endif; ?>
						</td>
						<td class="lrn-txn-col-item">
							<?php echo esc_html( $item_title ?: __( 'Purchase', 'learnomy' ) ); ?>
							<?php if ( $r->provider_transaction_id ) : ?>
								<br><small class="lrn-muted"><code><?php echo esc_html( $r->provider_transaction_id ); ?></code></small>
							<?php endif; ?>
						</td>
						<td class="lrn-txn-col-amount">
							<strong><?php echo esc_html( \Learnomy\format_price( (float) $r->amount, $currency ) ); ?></strong>
							<?php $lrn_listed = Transaction::simulated_listed_price( $r ); ?>
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
						<td class="lrn-txn-col-type"><?php echo esc_html( \Learnomy\Admin\UX::enum_label( (string) $r->transaction_type, 'transaction_type' ) ); ?></td>
						<td class="lrn-txn-col-provider"><?php echo esc_html( \Learnomy\Admin\UX::enum_label( (string) $r->payment_provider, 'payment_provider' ) ); ?></td>
						<td class="lrn-txn-col-status">
							<?php
							// Canonical status pill via the shared admin UX helper.
							echo \Learnomy\Admin\UX::status_badge( (string) $row_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
						<td class="lrn-txn-col-date"><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $r->created_at ) ); ?></td>
						<td class="lrn-txn-col-actions">
							<?php
							if ( $refundable ) {
								$lrn_txn_actions = array(
									array(
										'label'   => __( 'Refund', 'learnomy' ),
										'primary' => true,
										'attrs'   => array(
											'class'       => 'lrn-txn-refund-btn',
											'data-txn-id' => (string) (int) $r->id,
											// The REMAINDER, not the original charge: on a partially-refunded
											// sale the dialog must not offer to hand back money that is
											// already gone.
											'data-txn-amount' => number_format( $remaining, 2, '.', '' ),
											'data-txn-currency' => $currency,
											'data-txn-customer' => $r->user_name ?: '',
											'data-txn-item' => $item_title ?: __( 'Purchase', 'learnomy' ),
										),
									),
								);
								echo UX::row_actions( $lrn_txn_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} elseif ( $is_refund_row && ! empty( $r->refund_of ) ) {
								// Not an action — informational metadata for refund rows.
								printf(
									'<span class="lrn-muted">%s</span>',
									esc_html(
										sprintf(
											/* translators: %d: original transaction id */
											__( 'Refund of #%d', 'learnomy' ),
											(int) $r->refund_of
										)
									)
								);
							} else {
								echo UX::row_actions( array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
	// Shared admin pagination — same partial every other Learnomy list uses
	// (replaces the raw paginate_links() this view used to hand-roll).
	if ( $total_pages > 1 ) {
		$lrn_pagination = array(
			'current'  => (int) $paged,
			'total'    => (int) $total_pages,
			'results'  => (int) $total,
			'per_page' => (int) $per_page,
			'base_url' => $base_url,
		);
		require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
	}
	?>
</div>

<?php
// JS for the refund button lives at assets/js/admin/transactions.js
// and is enqueued from Admin::enqueue_assets() against the
// `learnomy_page_learnomy-transactions` screen hook. REST URL,
// nonce, and i18n strings are localised via lrnTransactionsAdmin.
