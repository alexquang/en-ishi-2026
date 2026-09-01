<?php
/**
 * Earnings widget body.
 *
 * Locals: $available_to_withdraw, $this_month_earnings, $total_revenue, $withdraw_min
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_available = (float) ( $available_to_withdraw ?? 0 );
$lrn_min       = (float) ( $withdraw_min ?? 0 );
// A payout integration (e.g. Pro Stripe Connect) can block withdrawals until the
// instructor has connected a payout account. Default empty = no block (Free
// behaviour unchanged when no integration is active).
$lrn_block_reason = (string) apply_filters( 'learnomy_instructor_withdraw_block_reason', '', get_current_user_id() );
// Below the minimum the withdrawal request would be rejected server-side, so
// the button is a dead control. Disable it and say why instead.
$lrn_can_withdraw = $lrn_available > 0 && $lrn_available >= $lrn_min && '' === $lrn_block_reason;
?>
<div class="lrn-earnings-summary">
	<div class="lrn-earnings-summary__item">
		<span class="lrn-earnings-summary__label"><?php esc_html_e( 'Available to Withdraw', 'learnomy' ); ?></span>
		<span class="lrn-earnings-summary__value"
			aria-label="<?php echo esc_attr( sprintf( /* translators: %s: formatted price, e.g. $91.00 */ __( '%s available to withdraw', 'learnomy' ), \Learnomy\format_price( (float) $available_to_withdraw ) ) ); ?>">
			<?php echo esc_html( \Learnomy\format_price( (float) $available_to_withdraw ) ); ?>
		</span>
	</div>
	<div class="lrn-earnings-summary__item">
		<span class="lrn-earnings-summary__label"><?php esc_html_e( 'This Month', 'learnomy' ); ?></span>
		<span class="lrn-earnings-summary__value"
			aria-label="<?php echo esc_attr( sprintf( /* translators: %s: formatted price, e.g. $91.00 */ __( '%s earned this month', 'learnomy' ), \Learnomy\format_price( (float) $this_month_earnings ) ) ); ?>">
			<?php echo esc_html( \Learnomy\format_price( (float) $this_month_earnings ) ); ?>
		</span>
	</div>
	<div class="lrn-earnings-summary__item">
		<span class="lrn-earnings-summary__label"><?php esc_html_e( 'Lifetime', 'learnomy' ); ?></span>
		<span class="lrn-earnings-summary__value"
			aria-label="<?php echo esc_attr( sprintf( /* translators: %s: formatted price, e.g. $91.00 */ __( '%s lifetime earnings', 'learnomy' ), \Learnomy\format_price( (float) $total_revenue ) ) ); ?>">
			<?php echo esc_html( \Learnomy\format_price( (float) $total_revenue ) ); ?>
		</span>
	</div>
</div>
<?php
/**
 * Payout-account setup slot. A payout integration (Pro Stripe Connect) renders
 * the instructor's connection status + a "Connect payout account" CTA here, so
 * the seller can set up where the money lands without ever leaving the front
 * end. No-op when no integration is active.
 *
 * @param int $instructor_id The current instructor.
 */
do_action( 'learnomy_instructor_payout_setup', get_current_user_id() );
?>
<button
	type="button"
	class="lrn-btn lrn-btn--primary lrn-btn--sm"
	data-action="request-withdrawal"
	data-available="<?php echo esc_attr( (string) $lrn_available ); ?>"
	data-min="<?php echo esc_attr( (string) $lrn_min ); ?>"
	<?php disabled( ! $lrn_can_withdraw ); ?>
>
	<?php esc_html_e( 'Request Withdrawal', 'learnomy' ); ?>
</button>
<?php if ( '' !== $lrn_block_reason ) : ?>
	<p class="lrn-earnings-summary__hint description"><?php echo esc_html( $lrn_block_reason ); ?></p>
<?php elseif ( ! $lrn_can_withdraw && $lrn_min > 0 ) : ?>
	<p class="lrn-earnings-summary__hint description">
		<?php
		/* translators: %s: formatted minimum withdrawal amount, e.g. $25.00 */
		echo esc_html( sprintf( __( 'Minimum %s to withdraw.', 'learnomy' ), \Learnomy\format_price( (float) $lrn_min ) ) );
		?>
	</p>
<?php endif; ?>
