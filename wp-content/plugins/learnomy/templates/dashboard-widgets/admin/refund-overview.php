<?php
/**
 * Admin dashboard — Refund Overview widget.
 *
 * 30-day refund summary: count, total amount, and the most-recent 3.
 * There is no "pending refund" workflow in Free — refunds are an
 * instant admin action through Payments_Admin_Controller — so this
 * is an overview, not a queue.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$overview = \Learnomy\Models\Transaction::refund_overview( 30, 3 );
$count    = (int) $overview['count'];
if ( $count <= 0 ) {
	return;
}

$total           = (float) $overview['total'];
$recent          = (array) $overview['recent'];
$settings        = (array) get_option( 'learnomy_settings', array() );
$currency_symbol = isset( $settings['currency_symbol'] ) && '' !== $settings['currency_symbol']
	? (string) $settings['currency_symbol']
	: '$';
?>
<div class="lrn-admin-card lrn-mb-6">
	<h2 class="lrn-admin-card-heading">
		<?php esc_html_e( 'Refunds (Last 30 Days)', 'learnomy' ); ?>
	</h2>
	<div class="lrn-admin-stats">
		<div class="lrn-admin-stat-card">
			<div class="lrn-admin-stat-card__icon">
				<i data-lucide="receipt" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'Refunds Processed', 'learnomy' ); ?></span>
			</div>
		</div>
		<div class="lrn-admin-stat-card">
			<div class="lrn-admin-stat-card__icon lrn-admin-stat-card__icon--warn">
				<i data-lucide="undo-2" aria-hidden="true"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number">
					<?php echo esc_html( \Learnomy\format_price( (float) $total ) ); ?>
				</span>
				<span class="lrn-admin-stat-card__label"><?php esc_html_e( 'Refunded Amount', 'learnomy' ); ?></span>
			</div>
		</div>
	</div>

	<?php if ( ! empty( $recent ) ) : ?>
		<ul class="lrn-admin-feed">
			<?php
			foreach ( $recent as $row ) :
				$user = (int) $row->user_id > 0 ? get_user_by( 'id', (int) $row->user_id ) : null;
				$name = $user ? $user->display_name : __( 'Guest', 'learnomy' );
				$when = human_time_diff( strtotime( (string) $row->created_at ), time() );
				// Per-row currency, resolved by the formatter. This used to be
				// `$row->currency ?: $currency_symbol` — a CODE with a SYMBOL
				// fallback — concatenated before the number, so a row with a
				// currency rendered "USD49.00" and one without rendered "$49.00".
				$cur  = (string) ( $row->currency ?: '' );
				$link = (int) $row->refund_of > 0 ? admin_url( 'admin.php?page=learnomy-earnings&transaction_id=' . (int) $row->refund_of ) : '';
				?>
				<li class="lrn-admin-feed__item">
					<?php if ( $user ) : ?>
						<?php echo get_avatar( (int) $row->user_id, 28, '', $name, array( 'class' => 'lrn-admin-feed__avatar' ) ); ?>
					<?php else : ?>
						<i data-lucide="user" class="lrn-admin-feed__avatar lrn-admin-feed__avatar--placeholder" aria-hidden="true"></i>
					<?php endif; ?>
					<div class="lrn-admin-feed__body">
						<span class="lrn-admin-feed__title">
							<strong><?php echo esc_html( $name ); ?></strong>
							<?php esc_html_e( 'refunded', 'learnomy' ); ?>
						</span>
					</div>
					<span class="lrn-admin-feed__value">
						<?php echo esc_html( \Learnomy\format_price( (float) $row->amount, '' !== $cur ? $cur : null ) ); ?>
					</span>
					<time class="lrn-admin-feed__time" datetime="<?php echo esc_attr( (string) $row->created_at ); ?>">
						<?php
						/* translators: %s: time diff */
						echo esc_html( sprintf( __( '%s ago', 'learnomy' ), $when ) );
						?>
					</time>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-earnings&filter=refund' ) ); ?>" class="lrn-admin-btn">
		<?php esc_html_e( 'All refunds', 'learnomy' ); ?>
	</a>
</div>
