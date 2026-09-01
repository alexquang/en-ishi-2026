<?php
/**
 * Email template: payment receipt.
 *
 * Available variables (provided by Notifier::on_payment_completed):
 *   $data['first_name']           string
 *   $data['student_name']         string
 *   $data['item_title']           string   course or plan title
 *   $data['item_kind']            string   'course' | 'plan' | ''
 *   $data['course_title']         string   back-compat alias for item_title
 *   $data['txn_id']               int
 *   $data['amount']               string   formatted, e.g. "49.00"
 *   $data['currency']             string   e.g. "USD"
 *   $data['payment_provider']     string   e.g. "stripe"
 *   $data['is_subscription']      bool
 *   $data['is_test_order']        bool     SELL-02 simulated Test-gateway order (recorded at $0)
 *   $data['business_name']        string
 *   $data['action_url']           string   primary CTA target
 *   $data['receipt_url']          string   /account/billing/{id}/receipt/
 *   $data['account_billing_url']  string
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$business_name = $data['business_name'] ?? get_bloginfo( 'name' );
$first_name    = $data['first_name'] ?? ( $data['student_name'] ?? '' );
$item_title    = $data['item_title'] ?? ( $data['course_title'] ?? '' );
$item_kind     = $data['item_kind'] ?? '';
$txn_id        = isset( $data['txn_id'] ) ? (int) $data['txn_id'] : 0;
$amount        = $data['amount'] ?? '0.00';
$currency      = $data['currency'] ?? 'USD';
$provider      = ucfirst( (string) ( $data['payment_provider'] ?? '' ) );
$is_sub        = ! empty( $data['is_subscription'] );
$is_test_order = ! empty( $data['is_test_order'] );
$action_url    = $data['action_url'] ?? home_url( '/' );
$receipt_url   = $data['receipt_url'] ?? '';
$billing_url   = $data['account_billing_url'] ?? '';
$date_str      = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );

$primary_label = $is_sub
	? __( 'Manage subscription', 'learnomy' )
	: ( 'course' === $item_kind ? __( 'Start course', 'learnomy' ) : __( 'View billing', 'learnomy' ) );
?>
<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:32px 16px;">
	<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.08);">

		<div style="padding:24px 32px 0;">
			<p style="margin:0;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.04em;color:#6b7280;">
				<?php echo esc_html( $business_name ); ?>
			</p>
		</div>

		<div style="padding:24px 32px 32px;">
			<h1 style="margin:0 0 16px;font-size:22px;color:#111827;line-height:1.3;">
				<?php
				if ( $item_title ) {
					/* translators: %s: course or plan title */
					printf( esc_html__( 'Thanks for your purchase of %s', 'learnomy' ), esc_html( $item_title ) );
				} else {
					esc_html_e( 'Payment confirmed', 'learnomy' );
				}
				?>
			</h1>

			<?php if ( '' !== $first_name ) : ?>
				<p style="margin:0 0 12px;font-size:15px;color:#374151;">
					<?php
					printf(
						/* translators: %s: first name */
						esc_html__( 'Hi %s,', 'learnomy' ),
						esc_html( $first_name )
					);
					?>
				</p>
			<?php endif; ?>

			<p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6;">
				<?php
				if ( $is_test_order ) {
					esc_html_e( 'This was a test order placed in test payment mode. No real payment was taken, and your enrollment is active. Details below.', 'learnomy' );
				} else {
					esc_html_e( 'Your payment has been received and your enrollment is active. Receipt details below.', 'learnomy' );
				}
				?>
			</p>

			<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin:0 0 24px;">
				<table style="width:100%;border-collapse:collapse;font-size:14px;">
					<?php if ( $item_title ) : ?>
						<tr>
							<td style="padding:6px 0;color:#6b7280;width:40%;"><?php echo 'course' === $item_kind ? esc_html__( 'Course', 'learnomy' ) : esc_html__( 'Plan', 'learnomy' ); ?></td>
							<td style="padding:6px 0;color:#111827;text-align:right;font-weight:600;"><?php echo esc_html( $item_title ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<td style="padding:6px 0;color:#6b7280;"><?php esc_html_e( 'Amount paid', 'learnomy' ); ?></td>
						<td style="padding:6px 0;color:#111827;text-align:right;font-weight:700;font-size:16px;">
							<?php
							if ( $is_test_order ) {
								esc_html_e( 'Test order, no charge', 'learnomy' );
							} else {
								echo esc_html( $currency . ' ' . $amount );
							}
							?>
						</td>
					</tr>
					<?php if ( $provider ) : ?>
						<tr>
							<td style="padding:6px 0;color:#6b7280;"><?php esc_html_e( 'Payment', 'learnomy' ); ?></td>
							<td style="padding:6px 0;color:#111827;text-align:right;"><?php echo esc_html( $provider ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<td style="padding:6px 0;color:#6b7280;"><?php esc_html_e( 'Date', 'learnomy' ); ?></td>
						<td style="padding:6px 0;color:#111827;text-align:right;"><?php echo esc_html( $date_str ); ?></td>
					</tr>
					<?php if ( $txn_id ) : ?>
						<tr>
							<td style="padding:6px 0;color:#6b7280;"><?php esc_html_e( 'Order ID', 'learnomy' ); ?></td>
							<td style="padding:6px 0;color:#111827;text-align:right;">#<?php echo (int) $txn_id; ?></td>
						</tr>
					<?php endif; ?>
				</table>
			</div>

			<p style="margin:0 0 24px;">
				<a href="<?php echo esc_url( $action_url ); ?>"
					style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-size:15px;font-weight:600;">
					<?php echo esc_html( $primary_label ); ?>
				</a>
			</p>

			<?php if ( $receipt_url || $billing_url ) : ?>
				<p style="margin:0 0 16px;font-size:13px;color:#6b7280;">
					<?php if ( $receipt_url ) : ?>
						<a href="<?php echo esc_url( $receipt_url ); ?>" style="color:#2563eb;text-decoration:underline;">
							<?php esc_html_e( 'Download receipt (PDF)', 'learnomy' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $receipt_url && $billing_url ) : ?>
						&nbsp;&middot;&nbsp;
					<?php endif; ?>
					<?php if ( $billing_url ) : ?>
						<a href="<?php echo esc_url( $billing_url ); ?>" style="color:#2563eb;text-decoration:underline;">
							<?php esc_html_e( 'View all orders', 'learnomy' ); ?>
						</a>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( $is_sub && $billing_url ) : ?>
				<p style="margin:0;font-size:13px;color:#6b7280;line-height:1.5;">
					<?php
					printf(
						/* translators: %s: link to billing page */
						esc_html__( 'This subscription renews automatically. Cancel anytime at %s.', 'learnomy' ),
						'<a href="' . esc_url( $billing_url ) . '" style="color:#2563eb;text-decoration:underline;">' . esc_html__( 'your billing page', 'learnomy' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL escaped above, anchor text below.
					);
					?>
				</p>
			<?php endif; ?>
		</div>

		<div style="border-top:1px solid #e5e7eb;padding:16px 32px;background:#f9fafb;">
			<p style="margin:0;font-size:12px;color:#6b7280;line-height:1.5;">
				<?php esc_html_e( 'Need help? Reply to this email.', 'learnomy' ); ?>
				<br>
				<span style="color:#9ca3af;"><?php echo esc_html( $business_name ); ?></span>
			</p>
		</div>

	</div>
</div>
