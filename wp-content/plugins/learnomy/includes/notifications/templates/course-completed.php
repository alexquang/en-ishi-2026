<?php
/**
 * Email template: course completion congratulations.
 *
 * Available variables:
 *   $data['student_name']  string
 *   $data['course_title']  string
 *   $data['action_url']    string  (account courses URL — /account/courses/)
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$site_name    = get_bloginfo( 'name' );
$student_name = $data['student_name'] ?? '';
$course_title = $data['course_title'] ?? '';
$action_url   = $data['action_url'] ?? '';
?>
<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:32px 16px;">
	<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">

		<div style="background:#059669;padding:24px 32px;">
			<p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;"><?php echo esc_html( $site_name ); ?></p>
		</div>

		<div style="padding:32px;text-align:center;">
			<div style="font-size:48px;margin:0 0 16px;">&#127942;</div>

			<h1 style="margin:0 0 8px;font-size:24px;color:#111827;">
				<?php esc_html_e( 'Course completed', 'learnomy' ); ?>
			</h1>

			<?php if ( $student_name ) : ?>
				<p style="margin:0 0 12px;font-size:15px;color:#374151;">
					<?php
					printf(
						/* translators: %s: student display name */
						esc_html__( 'Congratulations, %s.', 'learnomy' ),
						esc_html( $student_name )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $course_title ) : ?>
				<p style="margin:0 0 24px;font-size:15px;color:#374151;line-height:1.6;">
					<?php
					printf(
						/* translators: %s: course title */
						esc_html__( 'You have successfully completed "%s". This is a big achievement. Well done!', 'learnomy' ),
						esc_html( $course_title )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $action_url ) : ?>
				<p style="margin:0 0 8px;">
					<a href="<?php echo esc_url( $action_url ); ?>"
						style="display:inline-block;background:#059669;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-size:15px;font-weight:600;">
						<?php esc_html_e( 'View My Courses', 'learnomy' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>

		<div style="border-top:1px solid #e5e7eb;padding:16px 32px;background:#f9fafb;">
			<p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;">
				<?php echo esc_html( $site_name ); ?> &mdash;
				<?php esc_html_e( 'Online Learning Platform', 'learnomy' ); ?>
			</p>
		</div>

	</div>
</div>
