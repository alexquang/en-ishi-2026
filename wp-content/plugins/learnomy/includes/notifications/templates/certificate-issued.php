<?php
/**
 * Email template: certificate issued.
 *
 * Available variables:
 *   $data['student_name']      string
 *   $data['course_title']      string
 *   $data['certificate_uuid']  string
 *   $data['verification_url']  string
 *   $data['action_url']        string  (alias for verification_url)
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$site_name        = get_bloginfo( 'name' );
$student_name     = $data['student_name'] ?? '';
$course_title     = $data['course_title'] ?? '';
$verification_url = $data['verification_url'] ?? $data['action_url'] ?? '';
?>
<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:32px 16px;">
	<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">

		<div style="background:#7c3aed;padding:24px 32px;">
			<p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;"><?php echo esc_html( $site_name ); ?></p>
		</div>

		<div style="padding:32px;">
			<h1 style="margin:0 0 16px;font-size:22px;color:#111827;">
				<?php esc_html_e( 'Your certificate is ready', 'learnomy' ); ?>
			</h1>

			<?php if ( $student_name ) : ?>
				<p style="margin:0 0 12px;font-size:15px;color:#374151;">
					<?php
					printf(
						/* translators: %s: student display name */
						esc_html__( 'Hi %s,', 'learnomy' ),
						esc_html( $student_name )
					);
					?>
				</p>
			<?php endif; ?>

			<p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6;">
				<?php
				if ( $course_title ) {
					printf(
						/* translators: %s: course title */
						esc_html__( 'Your certificate for "%s" has been issued. You can view and share it using the link below.', 'learnomy' ),
						esc_html( $course_title )
					);
				} else {
					esc_html_e( 'Your certificate has been issued. You can view and share it using the link below.', 'learnomy' );
				}
				?>
			</p>

			<?php if ( $verification_url ) : ?>
				<p style="margin:0 0 24px;">
					<a href="<?php echo esc_url( $verification_url ); ?>"
						style="display:inline-block;background:#7c3aed;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-size:15px;font-weight:600;">
						<?php esc_html_e( 'View My Certificate', 'learnomy' ); ?>
					</a>
				</p>

				<p style="margin:0;font-size:13px;color:#6b7280;">
					<?php esc_html_e( 'Verification link:', 'learnomy' ); ?>
					<a href="<?php echo esc_url( $verification_url ); ?>"
						style="color:#7c3aed;word-break:break-all;"><?php echo esc_url( $verification_url ); ?></a>
				</p>
			<?php endif; ?>
		</div>

		<div style="border-top:1px solid #e5e7eb;padding:16px 32px;background:#f9fafb;">
			<p style="margin:0;font-size:12px;color:#9ca3af;">
				<?php echo esc_html( $site_name ); ?> &mdash;
				<?php esc_html_e( 'Online Learning Platform', 'learnomy' ); ?>
			</p>
		</div>

	</div>
</div>
