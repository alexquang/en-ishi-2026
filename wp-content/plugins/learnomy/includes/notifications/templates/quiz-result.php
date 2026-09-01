<?php
/**
 * Email template: quiz result.
 *
 * Available variables:
 *   $data['student_name']  string
 *   $data['score']         float   percentage (0–100)
 *   $data['passed']        bool
 *   $data['action_url']    string  (optional link to attempt review)
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$site_name    = get_bloginfo( 'name' );
$student_name = $data['student_name'] ?? '';
$score        = isset( $data['score'] ) ? (float) $data['score'] : 0.0;
$passed       = ! empty( $data['passed'] );
$action_url   = $data['action_url'] ?? '';

$badge_bg    = $passed ? '#059669' : '#dc2626';
$badge_label = $passed ? __( 'Passed', 'learnomy' ) : __( 'Not passed', 'learnomy' );
?>
<div style="font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;padding:32px 16px;">
	<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;">

		<div style="background:#1e40af;padding:24px 32px;">
			<p style="margin:0;font-size:20px;font-weight:700;color:#ffffff;"><?php echo esc_html( $site_name ); ?></p>
		</div>

		<div style="padding:32px;">
			<h1 style="margin:0 0 16px;font-size:22px;color:#111827;">
				<?php esc_html_e( 'Your quiz results are in', 'learnomy' ); ?>
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

			<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:20px;margin:0 0 24px;text-align:center;">
				<p style="margin:0 0 8px;font-size:40px;font-weight:700;color:#111827;">
					<?php echo esc_html( number_format_i18n( $score, 0 ) ); ?>%
				</p>
				<span style="display:inline-block;background:<?php echo esc_attr( $badge_bg ); ?>;color:#ffffff;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:600;">
					<?php echo esc_html( $badge_label ); ?>
				</span>
			</div>

			<?php if ( $action_url ) : ?>
				<p style="margin:0 0 8px;">
					<a href="<?php echo esc_url( $action_url ); ?>"
						style="display:inline-block;background:#1e40af;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:6px;font-size:15px;font-weight:600;">
						<?php esc_html_e( 'Review My Answers', 'learnomy' ); ?>
					</a>
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
