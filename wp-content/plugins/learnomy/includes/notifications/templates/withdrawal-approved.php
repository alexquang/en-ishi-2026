<?php
/**
 * Email template: withdrawal approved.
 *
 * Available variables:
 *   $data['student_name']  string  (instructor display name)
 *   $data['amount']        string  formatted, e.g. "250.00"
 *   $data['currency']      string  e.g. "USD"
 *   $data['method']        string  payout method label (optional)
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$site_name    = get_bloginfo( 'name' );
$student_name = $data['student_name'] ?? '';
$amount       = $data['amount'] ?? '0.00';
$currency     = $data['currency'] ?? 'USD';
$method       = $data['method'] ?? '';
?>
<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:32px 16px;">
	<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">

		<div style="background:#0891b2;padding:24px 32px;">
			<p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;"><?php echo esc_html( $site_name ); ?></p>
		</div>

		<div style="padding:32px;">
			<h1 style="margin:0 0 16px;font-size:22px;color:#111827;">
				<?php esc_html_e( 'Withdrawal approved', 'learnomy' ); ?>
			</h1>

			<?php if ( $student_name ) : ?>
				<p style="margin:0 0 12px;font-size:15px;color:#374151;">
					<?php
					printf(
						/* translators: %s: instructor display name */
						esc_html__( 'Hi %s,', 'learnomy' ),
						esc_html( $student_name )
					);
					?>
				</p>
			<?php endif; ?>

			<p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6;">
				<?php esc_html_e( 'Your withdrawal request has been approved and is being processed.', 'learnomy' ); ?>
			</p>

			<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:20px;margin:0 0 24px;">
				<table style="width:100%;border-collapse:collapse;">
					<tr>
						<td style="padding:6px 0;font-size:14px;color:#6b7280;"><?php esc_html_e( 'Amount', 'learnomy' ); ?></td>
						<td style="padding:6px 0;font-size:18px;color:#111827;text-align:right;font-weight:700;"><?php echo esc_html( $currency . ' ' . $amount ); ?></td>
					</tr>
					<?php if ( $method ) : ?>
						<tr>
							<td style="padding:6px 0;font-size:14px;color:#6b7280;"><?php esc_html_e( 'Payout method', 'learnomy' ); ?></td>
							<td style="padding:6px 0;font-size:14px;color:#111827;text-align:right;"><?php echo esc_html( $method ); ?></td>
						</tr>
					<?php endif; ?>
				</table>
			</div>

			<p style="margin:0;font-size:14px;color:#6b7280;line-height:1.6;">
				<?php esc_html_e( 'Funds typically arrive within 3-5 business days depending on your payment provider.', 'learnomy' ); ?>
			</p>
		</div>

		<div style="border-top:1px solid #e5e7eb;padding:16px 32px;background:#f9fafb;">
			<p style="margin:0;font-size:12px;color:#9ca3af;">
				<?php echo esc_html( $site_name ); ?> &mdash;
				<?php esc_html_e( 'Online Learning Platform', 'learnomy' ); ?>
			</p>
		</div>

	</div>
</div>
