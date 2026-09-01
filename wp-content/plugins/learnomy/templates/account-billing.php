<?php
/**
 * Customer billing dashboard — /account/billing/
 *
 * Three sections, regardless of customer type (course-only / member /
 * recurring-course / mixed):
 *   1. Active subscriptions
 *   2. Time-limited access (fixed-term enrollments)
 *   3. Order history
 *
 * Data resolved by `Account_Billing_View_Data`. Template performs zero
 * DB work, zero service calls, only formatting + escaping.
 *
 * Variables available (extracted from $view_data):
 *   $current_user        WP_User
 *   $subscription_views  array
 *   $time_limited        array
 *   $transaction_views   array
 *   $currency_symbol     string
 *   $billing_url         string
 *   $browse_courses_url  string
 *   $membership_url      string
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.PHP.DontExtract.extract_extract -- locals come from Template_Loader extract().

$subscription_views = isset( $subscription_views ) && is_array( $subscription_views ) ? $subscription_views : array();
$time_limited       = isset( $time_limited ) && is_array( $time_limited ) ? $time_limited : array();
$transaction_views  = isset( $transaction_views ) && is_array( $transaction_views ) ? $transaction_views : array();
$currency_symbol    = isset( $currency_symbol ) ? (string) $currency_symbol : '$';
$browse_courses_url = isset( $browse_courses_url ) ? (string) $browse_courses_url : \Learnomy\route_url( 'catalog' );
$membership_url     = isset( $membership_url ) ? (string) $membership_url : \Learnomy\route_url( 'membership-pricing' );

$date_format = get_option( 'date_format' );

$fmt_amount = static function ( float $amount, string $currency ): string {
	return \Learnomy\format_price( $amount );
};

$status_badge_class = static function ( string $status ): string {
	return 'lrn-status-badge lrn-status-badge--' . sanitize_html_class( $status );
};

?>
<main class="lrn-container lrn-account-billing" id="lrn-account-billing">

	<?php
	$breadcrumb_items = \Learnomy\account_breadcrumb( __( 'Billing & orders', 'learnomy' ) );
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-account-layout">
		<?php
		$account_nav_active = 'account-billing';
		require \Learnomy\template_path( 'partials/account-nav.php' );
		?>
		<div class="lrn-account-layout__content">

	<?php
	$account_page = array(
		'icon'     => 'receipt',
		'title'    => __( 'Billing & orders', 'learnomy' ),
		'subtitle' => __( 'Your subscriptions, access, and order history in one place.', 'learnomy' ),
	);
	require \Learnomy\template_path( 'partials/account-page-header.php' );
	?>

	<!-- ──────────── 0. Billing address ──────────── -->
	<?php
	/*
	 * The member's own copy of the address checkout freezes onto every order.
	 *
	 * Checkout used to be the ONLY writer anywhere in the plugin pair, so once
	 * it began refusing incomplete details a member who mistyped could correct
	 * it only by buying something else (Basecamp 10171338907). The page that
	 * says "Billing & orders" is where someone looks for this, and it had the
	 * orders and not the billing.
	 *
	 * Same fields partial checkout renders, so the required set cannot drift
	 * from what Billing_Profile::missing_required() enforces at payment.
	 */
	$lrn_bill      = $billing_profile ?? array();
	$lrn_countries = $billing_countries ?? array();
	?>
	<section class="lrn-billing-section" aria-labelledby="lrn-section-address">
		<h2 id="lrn-section-address" class="lrn-billing-section__title">
			<?php esc_html_e( 'Billing address', 'learnomy' ); ?>
		</h2>
		<p class="lrn-billing-section__intro">
			<?php esc_html_e( 'Used on your receipts and invoices. Checkout fills this in for you the first time, and changing it here never alters an order you have already placed.', 'learnomy' ); ?>
		</p>

		<?php if ( ! empty( $billing_missing ) ) : ?>
			<p class="lrn-notice lrn-notice--info" role="status">
				<?php esc_html_e( 'Add your address here to make your next checkout quicker.', 'learnomy' ); ?>
			</p>
		<?php endif; ?>

		<form id="lrn-billing-address-form" class="lrn-checkout__billing">
			<?php require \Learnomy\template_path( 'partials/billing-fields.php' ); ?>
			<p class="lrn-billing-address-form__actions">
				<button type="submit" class="lrn-btn lrn-btn--primary">
					<?php esc_html_e( 'Save address', 'learnomy' ); ?>
				</button>
				<span class="lrn-billing-address-form__status" role="status" aria-live="polite"></span>
			</p>
		</form>
	</section>

	<!-- ──────────── 1. Active subscriptions (membership module, or a live one) ──────────── -->
	<?php if ( \Learnomy\module_enabled_for_user( 'membership' ) ) : ?>
	<section class="lrn-billing-section" aria-labelledby="lrn-section-subs">
		<h2 id="lrn-section-subs" class="lrn-billing-section__title">
			<?php esc_html_e( 'Active subscriptions', 'learnomy' ); ?>
		</h2>

		<?php if ( empty( $subscription_views ) ) : ?>
			<div class="lrn-card lrn-empty">
				<i data-lucide="repeat" class="lrn-empty__icon" aria-hidden="true"></i>
				<p class="lrn-empty__text"><?php esc_html_e( 'You have no active subscriptions.', 'learnomy' ); ?></p>
				<a href="<?php echo esc_url( $membership_url ); ?>" class="lrn-btn lrn-btn--ghost lrn-btn--sm">
					<?php esc_html_e( 'Browse plans', 'learnomy' ); ?>
					<i data-lucide="arrow-right" aria-hidden="true"></i>
				</a>
			</div>
		<?php else : ?>
			<div class="lrn-billing-grid">
				<?php foreach ( $subscription_views as $sv ) : ?>
					<article class="lrn-card lrn-billing-sub-card">
						<header class="lrn-billing-sub-card__head">
							<div>
								<h3 class="lrn-billing-sub-card__title"><?php echo esc_html( $sv['title'] ); ?></h3>
								<span class="lrn-billing-sub-card__kind">
									<?php
									echo 'course' === $sv['kind']
										? esc_html__( 'Course subscription', 'learnomy' )
										: esc_html__( 'Membership plan', 'learnomy' );
									?>
								</span>
							</div>
							<span class="<?php echo esc_attr( $status_badge_class( $sv['status'] ) ); ?>">
								<?php echo esc_html( \Learnomy\Admin\UX::status_label( (string) $sv['status'] ) ); ?>
							</span>
						</header>

						<dl class="lrn-billing-sub-card__meta">
							<?php if ( ! empty( $sv['current_period_end'] ) ) : ?>
								<div>
									<dt>
									<?php
									echo \Learnomy\subscription_ends_at_period_end( $sv )
										? esc_html__( 'Ends', 'learnomy' )
										: esc_html__( 'Renews', 'learnomy' );
									?>
								</dt>
									<dd><?php echo esc_html( mysql2date( $date_format, $sv['current_period_end'] ) ); ?></dd>
								</div>
							<?php endif; ?>
							<?php if ( $sv['next_payment_amount'] > 0 ) : ?>
								<div>
									<dt><?php esc_html_e( 'Next payment', 'learnomy' ); ?></dt>
									<dd><?php echo esc_html( $fmt_amount( $sv['next_payment_amount'], '' ) ); ?></dd>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $sv['payment_provider'] ) ) : ?>
								<div>
									<dt><?php esc_html_e( 'Billed via', 'learnomy' ); ?></dt>
									<dd><?php echo esc_html( \Learnomy\Admin\UX::enum_label( (string) $sv['payment_provider'], 'payment_provider' ) ); ?></dd>
								</div>
							<?php endif; ?>
						</dl>

						<?php if ( ! empty( $sv['cancel_at_period_end'] ) ) : ?>
							<p class="lrn-billing-sub-card__note">
								<i data-lucide="info" aria-hidden="true"></i>
								<?php esc_html_e( 'Ends at the current period. Access continues until then.', 'learnomy' ); ?>
							</p>
						<?php endif; ?>

						<div class="lrn-billing-sub-card__actions">
							<a href="<?php echo esc_url( $sv['manage_url'] ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--sm">
								<i data-lucide="settings" aria-hidden="true"></i>
								<?php esc_html_e( 'Manage', 'learnomy' ); ?>
							</a>
							<?php if ( 'stripe' === ( $sv['payment_provider'] ?? '' ) ) : ?>
								<button
									type="button"
									class="lrn-btn lrn-btn--ghost lrn-btn--sm"
									data-lrn-portal-btn
									data-subscription-id="<?php echo esc_attr( (string) ( $sv['id'] ?? '' ) ); ?>"
								>
									<i data-lucide="credit-card" aria-hidden="true"></i>
									<?php esc_html_e( 'Manage payment method', 'learnomy' ); ?>
								</button>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php endif; // membership module ?>

	<!-- ──────────── 2. Time-limited access ──────────── -->
	<?php if ( ! empty( $time_limited ) ) : ?>
	<section class="lrn-billing-section" aria-labelledby="lrn-section-time-limited">
		<h2 id="lrn-section-time-limited" class="lrn-billing-section__title">
			<?php esc_html_e( 'Time-limited access', 'learnomy' ); ?>
		</h2>
		<div class="lrn-billing-grid">
			<?php foreach ( $time_limited as $tl ) : ?>
				<article class="lrn-card lrn-billing-tl-card<?php echo $tl['is_expired'] ? ' lrn-billing-tl-card--expired' : ''; ?>">
					<header class="lrn-billing-tl-card__head">
						<a href="<?php echo esc_url( $tl['course_url'] ); ?>">
							<h3 class="lrn-billing-tl-card__title"><?php echo esc_html( $tl['course_title'] ); ?></h3>
						</a>
						<?php if ( $tl['is_expired'] ) : ?>
							<span class="lrn-status-badge lrn-status-badge--expired"><?php esc_html_e( 'Expired', 'learnomy' ); ?></span>
						<?php elseif ( $tl['days_remaining'] <= 7 ) : ?>
							<span class="lrn-status-badge lrn-status-badge--warning">
								<?php
								/* translators: %d: days remaining */
								echo esc_html( sprintf( _n( '%d day left', '%d days left', $tl['days_remaining'], 'learnomy' ), $tl['days_remaining'] ) );
								?>
							</span>
						<?php else : ?>
							<span class="lrn-status-badge lrn-status-badge--active">
								<?php
								/* translators: %d: days remaining */
								echo esc_html( sprintf( __( '%d days left', 'learnomy' ), $tl['days_remaining'] ) );
								?>
							</span>
						<?php endif; ?>
					</header>

					<p class="lrn-billing-tl-card__meta">
						<?php
						/* translators: %s: expiry date */
						echo esc_html( sprintf( __( 'Access until %s', 'learnomy' ), mysql2date( $date_format, $tl['expires_at'] ) ) );
						?>
					</p>

					<div class="lrn-billing-tl-card__actions">
						<?php if ( $tl['is_expired'] ) : ?>
							<a href="<?php echo esc_url( $tl['renew_url'] ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--sm">
								<i data-lucide="refresh-cw" aria-hidden="true"></i>
								<?php esc_html_e( 'Renew access', 'learnomy' ); ?>
							</a>
						<?php else : ?>
							<a href="<?php echo esc_url( $tl['course_url'] ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--sm">
								<i data-lucide="play" aria-hidden="true"></i>
								<?php esc_html_e( 'Continue', 'learnomy' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	</section>
	<?php endif; ?>

	<!-- ──────────── 3. Order history ──────────── -->
	<section class="lrn-billing-section" aria-labelledby="lrn-section-orders">
		<h2 id="lrn-section-orders" class="lrn-billing-section__title">
			<?php esc_html_e( 'Order history', 'learnomy' ); ?>
		</h2>

		<?php if ( empty( $transaction_views ) ) : ?>
			<div class="lrn-card lrn-empty">
				<i data-lucide="shopping-bag" class="lrn-empty__icon" aria-hidden="true"></i>
				<p class="lrn-empty__text"><?php esc_html_e( "You haven't purchased anything yet.", 'learnomy' ); ?></p>
				<a href="<?php echo esc_url( $browse_courses_url ); ?>" class="lrn-btn lrn-btn--ghost lrn-btn--sm">
					<?php esc_html_e( 'Browse Courses', 'learnomy' ); ?>
					<i data-lucide="arrow-right" aria-hidden="true"></i>
				</a>
			</div>
		<?php else : ?>
			<div class="lrn-card lrn-card--padded">
				<table class="lrn-billing-orders">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
							<th><?php esc_html_e( 'Item', 'learnomy' ); ?></th>
							<th class="lrn-align-right"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
							<th><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
							<th class="lrn-align-right"><?php esc_html_e( 'Receipt', 'learnomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $transaction_views as $tv ) : ?>
							<tr<?php echo $tv['refund'] ? ' class="lrn-billing-orders__row--refunded"' : ''; ?>>
								<td><?php echo esc_html( mysql2date( $date_format, $tv['created_at'] ) ); ?></td>
								<td>
									<?php if ( ! empty( $tv['start_url'] ) ) : ?>
										<a href="<?php echo esc_url( $tv['start_url'] ); ?>"><?php echo esc_html( $tv['item_title'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $tv['item_title'] ); ?>
									<?php endif; ?>
									<small class="lrn-muted">
										<?php echo esc_html( \Learnomy\Admin\UX::enum_label( (string) $tv['transaction_type'], 'transaction_type' ) ); ?>
										<?php if ( ! empty( $tv['payment_provider'] ) ) : ?>
											&middot; <?php echo esc_html( \Learnomy\Admin\UX::enum_label( (string) $tv['payment_provider'], 'payment_provider' ) ); ?>
										<?php endif; ?>
									</small>
								</td>
								<td class="lrn-align-right">
									<span<?php echo $tv['refund'] ? ' class="lrn-strike"' : ''; ?>>
										<?php echo esc_html( $fmt_amount( $tv['amount'], $tv['currency'] ) ); ?>
									</span>
									<?php if ( ! empty( $tv['is_test_order'] ) ) : ?>
										<small class="lrn-muted lrn-block">
											<?php esc_html_e( 'Test order, no charge', 'learnomy' ); ?>
										</small>
									<?php endif; ?>
									<?php if ( $tv['refund'] ) : ?>
										<small class="lrn-muted lrn-block">
											<?php
											/* translators: %s: refund date */
											echo esc_html( sprintf( __( 'Refunded %s', 'learnomy' ), mysql2date( $date_format, $tv['refund']['created_at'] ) ) );
											?>
										</small>
									<?php endif; ?>
								</td>
								<td>
									<span class="<?php echo esc_attr( $status_badge_class( $tv['refund'] ? 'refunded' : $tv['status'] ) ); ?>">
										<?php echo esc_html( \Learnomy\Admin\UX::status_label( $tv['refund'] ? 'refunded' : (string) $tv['status'] ) ); ?>
									</span>
								</td>
								<td class="lrn-align-right">
									<a href="<?php echo esc_url( $tv['receipt_url'] ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--sm" target="_blank" rel="noopener">
										<i data-lucide="download" aria-hidden="true"></i>
										<?php esc_html_e( 'Receipt', 'learnomy' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>

	<?php
	/**
	 * Fires after the billing page renders. Pro extensions hook here for
	 * analytics, upsell offers, etc.
	 */
	do_action( 'learnomy_account_billing_rendered', get_current_user_id() );
	?>

		</div><!-- .lrn-account-layout__content -->
	</div><!-- .lrn-account-layout -->

</main>
