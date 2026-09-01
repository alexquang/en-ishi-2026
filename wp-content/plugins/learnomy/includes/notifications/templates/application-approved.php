<?php
/**
 * Email template: instructor application approved.
 *
 * Available variables:
 *   $data['student_name']  string  (applicant display name)
 *   $data['action_url']    string  (instructor dashboard URL)
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$site_name    = get_bloginfo( 'name' );
$student_name = $data['student_name'] ?? '';
$action_url   = $data['action_url'] ?? \Learnomy\route_url( 'instructor-dashboard' );
?>
<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:32px 16px;">
	<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">

		<div style="background:#059669;padding:24px 32px;">
			<p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;"><?php echo esc_html( $site_name ); ?></p>
		</div>

		<div style="padding:32px;text-align:center;">
			<div style="font-size:48px;margin:0 0 16px;">&#127881;</div>

			<h1 style="margin:0 0 12px;font-size:22px;color:#111827;">
				<?php esc_html_e( 'Your application was approved.', 'learnomy' ); ?>
			</h1>

			<?php if ( $student_name ) : ?>
				<p style="margin:0 0 12px;font-size:15px;color:#374151;">
					<?php
					printf(
						/* translators: %s: applicant display name */
						esc_html__( 'Welcome, %s.', 'learnomy' ),
						esc_html( $student_name )
					);
					?>
				</p>
			<?php endif; ?>

			<p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6;text-align:left;">
				<?php
				printf(
					/* translators: %s: site name */
					esc_html__( 'We are excited to have you as an instructor on %s. You can now access your instructor dashboard to create and publish courses.', 'learnomy' ),
					esc_html( get_bloginfo( 'name' ) )
				);
				?>
			</p>

			<p style="margin:0;">
				<a href="<?php echo esc_url( $action_url ); ?>"
					style="display:inline-block;background:#059669;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-size:15px;font-weight:600;">
					<?php esc_html_e( 'Go to Instructor Dashboard', 'learnomy' ); ?>
				</a>
			</p>
		</div>

		<div style="border-top:1px solid #e5e7eb;padding:16px 32px;background:#f9fafb;">
			<p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;">
				<?php echo esc_html( $site_name ); ?> &mdash;
				<?php esc_html_e( 'Online Learning Platform', 'learnomy' ); ?>
			</p>
		</div>

	</div>
</div>
