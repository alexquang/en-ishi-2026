<?php
/**
 * Email template: instructor application not approved.
 *
 * Available variables:
 *   $data['student_name']     string  (applicant display name)
 *   $data['rejection_reason'] string  (optional feedback from reviewer)
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$site_name        = get_bloginfo( 'name' );
$student_name     = $data['student_name'] ?? '';
$rejection_reason = ! empty( $data['rejection_reason'] )
	? wp_kses_post( $data['rejection_reason'] )
	: '';
?>
<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:32px 16px;">
	<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">

		<div style="background:#4b5563;padding:24px 32px;">
			<p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;"><?php echo esc_html( $site_name ); ?></p>
		</div>

		<div style="padding:32px;">
			<h1 style="margin:0 0 16px;font-size:22px;color:#111827;">
				<?php esc_html_e( 'Update on your instructor application', 'learnomy' ); ?>
			</h1>

			<?php if ( $student_name ) : ?>
				<p style="margin:0 0 12px;font-size:15px;color:#374151;">
					<?php
					printf(
						/* translators: %s: applicant display name */
						esc_html__( 'Hi %s,', 'learnomy' ),
						esc_html( $student_name )
					);
					?>
				</p>
			<?php endif; ?>

			<p style="margin:0 0 16px;font-size:15px;color:#374151;line-height:1.6;">
				<?php
				printf(
					/* translators: %s: site name */
					esc_html__( 'Thank you for your interest in teaching on %s. After reviewing your application, we are unable to approve it at this time.', 'learnomy' ),
					esc_html( get_bloginfo( 'name' ) )
				);
				?>
			</p>

			<?php if ( $rejection_reason ) : ?>
				<div style="background:#fef2f2;border-left:4px solid #dc2626;padding:16px;margin:0 0 16px;border-radius:0 4px 4px 0;">
					<p style="margin:0 0 4px;font-size:13px;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:0.05em;">
						<?php esc_html_e( 'Feedback', 'learnomy' ); ?>
					</p>
					<p style="margin:0;font-size:14px;color:#374151;line-height:1.6;">
						<?php echo wp_kses_post( $rejection_reason ); ?>
					</p>
				</div>
			<?php endif; ?>

			<p style="margin:0;font-size:14px;color:#6b7280;line-height:1.6;">
				<?php esc_html_e( 'You are welcome to reapply in the future. If you have questions, please contact our support team.', 'learnomy' ); ?>
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
