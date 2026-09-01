<?php
/**
 * Admin per-coupon detail.
 *
 * The coupon config plus its redemption list (who used it, on which
 * transaction, when) — turning the Coupons list's dead usage count into a
 * drillable record. Mirrors the other detail pages.
 *
 * @package Learnomy
 * @var object              $coupon             lrn_coupons row.
 * @var object[]            $usages             lrn_coupon_usages rows (newest first, current page).
 * @var int                 $usage_total        Total redemptions across all pages.
 * @var int                 $usage_total_pages  Total pagination pages.
 * @var int                 $usage_paged        Current page number.
 * @var int                 $usage_per_page     Rows per page.
 * @var array<int,\WP_User> $user_map           user_id → WP_User (batched for current page).
 * @var string              $currency           Currency code.
 */

defined( 'ABSPATH' ) || exit;

$list_url    = admin_url( 'admin.php?page=learnomy-coupons' );
$date_format = get_option( 'date_format' );
$status      = (string) ( $coupon->status ?? 'active' );
$limit_label = ! empty( $coupon->usage_limit )
	? sprintf( '%1$s / %2$s', number_format_i18n( (int) ( $coupon->times_used ?? 0 ) ), number_format_i18n( (int) $coupon->usage_limit ) )
	: sprintf( '%s / ∞', number_format_i18n( (int) ( $coupon->times_used ?? 0 ) ) );
$discount    = 'percent' === ( $coupon->type ?? '' )
	? number_format_i18n( (float) $coupon->value, 0 ) . '%'
	: $currency . ' ' . number_format_i18n( (float) $coupon->value, 2 );

$fmt = static function ( $dt ) use ( $date_format ) {
	return \Learnomy\date_label( $dt, '—', $date_format );
};
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="ticket-percent" class="lrn-page-header__icon"></i>
				<?php echo esc_html( $coupon->code ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a class="lrn-admin-btn" href="<?php echo esc_url( $list_url ); ?>">
				<i data-lucide="arrow-left"></i>
				<?php esc_html_e( 'All coupons', 'learnomy' ); ?>
			</a>
		</div>
	</div>

	<hr class="wp-header-end">

	<!-- Coupon config -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Coupon', 'learnomy' ); ?></h2>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Code', 'learnomy' ); ?></th>
				<td><code><?php echo esc_html( $coupon->code ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Discount', 'learnomy' ); ?></th>
				<td><?php echo esc_html( $discount ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Applies to', 'learnomy' ); ?></th>
				<td><?php echo esc_html( \Learnomy\Admin\UX::enum_label( (string) ( $coupon->applies_to ?? '' ), 'applies_to' ) ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<td><?php echo \Learnomy\Admin\UX::status_badge( $status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Usage', 'learnomy' ); ?></th>
				<td><?php echo esc_html( $limit_label ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Per-user limit', 'learnomy' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( (int) ( $coupon->per_user_limit ?? 1 ) ) ); ?></td>
			</tr>
			<?php if ( ! empty( $coupon->min_purchase ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Minimum purchase', 'learnomy' ); ?></th>
					<td><?php echo esc_html( $currency . ' ' . number_format_i18n( (float) $coupon->min_purchase, 2 ) ); ?></td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Active window', 'learnomy' ); ?></th>
				<td><?php echo esc_html( $fmt( $coupon->starts_at ?? '' ) . ' → ' . $fmt( $coupon->expires_at ?? '' ) ); ?></td>
			</tr>
		</table>
	</div>

	<!-- Redemptions -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<?php
			printf(
				/* translators: %s: redemption count */
				esc_html__( 'Redemptions (%s)', 'learnomy' ),
				esc_html( number_format_i18n( $usage_total ) )
			);
			?>
		</h2>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Customer', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Transaction', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $usages ) ) : ?>
					<tr><td colspan="3" class="lrn-muted"><?php esc_html_e( 'This coupon has not been redeemed yet.', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $usages as $lrn_use ) :
						$lrn_uid  = (int) ( $lrn_use->user_id ?? 0 );
						$lrn_user = $user_map[ $lrn_uid ] ?? null;
						?>
						<tr>
							<td>
								<?php if ( $lrn_user ) : ?>
									<a class="lrn-admin-table__primary" href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'page'    => 'learnomy-student-detail',
												'user_id' => $lrn_uid,
											),
											admin_url( 'admin.php' )
										)
									);
									?>
																				">
										<?php echo esc_html( $lrn_user->display_name ); ?>
									</a>
								<?php else : ?>
									<span class="lrn-muted"><?php esc_html_e( 'Unknown', 'learnomy' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $lrn_use->transaction_id ) ) : ?>
									<a href="
									<?php
									echo esc_url(
										add_query_arg(
											array(
												'page' => 'learnomy-transaction-detail',
												'transaction_id' => absint( $lrn_use->transaction_id ),
											),
											admin_url( 'admin.php' )
										)
									);
									?>
												">
										<?php printf( /* translators: %d: transaction ID */ esc_html__( '#%d', 'learnomy' ), absint( $lrn_use->transaction_id ) ); ?>
									</a>
								<?php else : ?>
									<span class="lrn-muted">—</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $fmt( $lrn_use->created_at ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>

		<?php
		$lrn_pagination = array(
			'current'  => (int) $usage_paged,
			'total'    => (int) $usage_total_pages,
			'results'  => (int) $usage_total,
			'per_page' => (int) $usage_per_page,
			'base_url' => add_query_arg(
				array(
					'page'      => 'learnomy-coupon-detail',
					'coupon_id' => absint( $coupon->id ),
				),
				admin_url( 'admin.php' )
			),
			'param'    => 'usage_paged',
		);
		require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
		?>
	</div>

</div><!-- /.learnomy-admin -->
