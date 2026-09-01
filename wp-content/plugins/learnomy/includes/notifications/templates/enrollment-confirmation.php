<?php
/**
 * Email template: enrollment confirmation.
 *
 * Available variables:
 *   $data['student_name']  string
 *   $data['course_title']  string
 *   $data['course_url']    string
 *   $data['action_url']    string   (alias for course_url)
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$site_name    = get_bloginfo( 'name' );
$student_name = $data['student_name'] ?? '';
$course_title = $data['course_title'] ?? '';
$course_url   = $data['course_url'] ?? $data['action_url'] ?? '';
?>
<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:32px 16px;">
	<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">

		<div style="background:#2563eb;padding:24px 32px;">
			<p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;"><?php echo esc_html( $site_name ); ?></p>
		</div>

		<div style="padding:32px;">
			<h1 style="margin:0 0 8px;font-size:22px;color:#111827;">
				<?php
				printf(
					/* translators: %s: course title */
					esc_html__( 'You are enrolled in %s.', 'learnomy' ),
					esc_html( $course_title )
				);
				?>
			</h1>

			<?php if ( $student_name ) : ?>
				<p style="margin:0 0 16px;font-size:15px;color:#374151;">
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
				<?php esc_html_e( 'Your enrollment is confirmed. You now have full access to all course materials. Start at your own pace and learn whenever it suits you.', 'learnomy' ); ?>
			</p>

			<?php if ( $course_url ) : ?>
				<p style="margin:0 0 24px;">
					<a href="<?php echo esc_url( $course_url ); ?>"
						style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-size:15px;font-weight:600;">
						<?php esc_html_e( 'Start Learning', 'learnomy' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<p style="margin:0;font-size:13px;color:#6b7280;">
				<?php esc_html_e( 'If you did not sign up for this course, you can ignore this email.', 'learnomy' ); ?>
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
