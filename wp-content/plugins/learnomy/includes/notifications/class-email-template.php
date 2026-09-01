<?php
/**
 * Branded HTML email template renderer.
 *
 * Produces Stripe/Linear-quality transactional emails using inline CSS
 * that renders consistently across Gmail, Outlook, Apple Mail, and Yahoo.
 *
 * @package Learnomy\Notifications
 */

namespace Learnomy\Notifications;

defined( 'ABSPATH' ) || exit;

class Email_Template {

	/**
	 * Renders a complete branded HTML email.
	 *
	 * @param string $subject     Email subject (used in preheader).
	 * @param string $heading     Large heading inside the card.
	 * @param string $body_html   The message body — may contain HTML.
	 * @param string $cta_url     Optional CTA button URL.
	 * @param string $cta_text    Optional CTA button label.
	 * @param string $footer_note Optional extra line above the footer.
	 * @return string Full HTML document ready for wp_mail().
	 */
	public static function render( string $subject, string $heading, string $body_html, string $cta_url = '', string $cta_text = '', string $footer_note = '' ): string {
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$site_url  = esc_url( home_url( '/' ) );
		$logo_url  = self::get_logo_url();
		$year      = gmdate( 'Y' );
		// render() runs inside the recipient's locale (Notification_Service
		// switches to get_user_locale() before building the email, i18n R7), so
		// reflect that locale in the document <html lang> for screen readers and
		// email clients instead of a hardcoded "en".
		$lang_attr = esc_attr( str_replace( '_', '-', get_locale() ) );

		// Build the header: logo or site name.
		if ( $logo_url ) {
			$header_content = '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" style="max-height:40px;width:auto;display:block;" />';
		} else {
			$header_content = '<span style="font-size:20px;font-weight:700;color:#37352f;letter-spacing:-0.3px;">' . $site_name . '</span>';
		}

		// CTA button block.
		$cta_block = '';
		if ( $cta_url && $cta_text ) {
			$cta_block = '
				<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:32px auto 8px;">
					<tr>
						<td style="border-radius:8px;background-color:#2383e2;">
							<a href="' . esc_url( $cta_url ) . '" target="_blank" style="display:inline-block;padding:14px 32px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;mso-padding-alt:0;">' . esc_html( $cta_text ) . '</a>
						</td>
					</tr>
				</table>';
		}

		// Footer note.
		$footer_note_block = '';
		if ( $footer_note ) {
			$footer_note_block = '<p style="margin:0 0 16px;font-size:13px;line-height:20px;color:#6b6b6b;">' . wp_kses_post( $footer_note ) . '</p>';
		}

		// Assemble the full document.
		$html = '<!DOCTYPE html>
<html lang="' . $lang_attr . '" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta http-equiv="X-UA-Compatible" content="IE=edge" />
	<meta name="x-apple-disable-message-reformatting" />
	<meta name="color-scheme" content="light" />
	<meta name="supported-color-schemes" content="light" />
	<title>' . esc_html( $subject ) . '</title>
	<!--[if mso]>
	<noscript>
		<xml>
			<o:OfficeDocumentSettings>
				<o:PixelsPerInch>96</o:PixelsPerInch>
			</o:OfficeDocumentSettings>
		</xml>
	</noscript>
	<![endif]-->
	<style>
		body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
		table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
		img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
		body { margin: 0; padding: 0; width: 100% !important; }
		@media only screen and (max-width: 620px) {
			.email-container { width: 100% !important; max-width: 100% !important; }
			.email-padding { padding-left: 20px !important; padding-right: 20px !important; }
		}
	</style>
</head>
<body style="margin:0;padding:0;background-color:#f7f7f5;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">

	<!-- Preheader text (hidden, shows in email list preview) -->
	<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">
		' . esc_html( $subject ) . '
	</div>

	<!-- Outer wrapper -->
	<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#f7f7f5;">
		<tr>
			<td style="padding:40px 16px;">

				<!-- Email container -->
				<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="600" class="email-container" style="margin:0 auto;max-width:600px;">

					<!-- Accent bar -->
					<tr>
						<td style="height:4px;background:linear-gradient(90deg,#2383e2,#4ba3f5);border-radius:8px 8px 0 0;font-size:0;line-height:0;">
							&nbsp;
						</td>
					</tr>

					<!-- Header with logo -->
					<tr>
						<td class="email-padding" style="background-color:#ffffff;padding:28px 40px 0;">
							<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
								<tr>
									<td style="text-align:left;">
										' . $header_content . '
									</td>
								</tr>
							</table>
						</td>
					</tr>

					<!-- Main content card -->
					<tr>
						<td class="email-padding" style="background-color:#ffffff;padding:32px 40px 40px;">

							<!-- Heading -->
							<h1 style="margin:0 0 24px;font-size:22px;font-weight:700;line-height:30px;color:#37352f;letter-spacing:-0.3px;">
								' . esc_html( $heading ) . '
							</h1>

							<!-- Body -->
							<div style="margin:0 0 8px;font-size:15px;line-height:26px;color:#37352f;">
								' . wp_kses_post( $body_html ) . '
							</div>

							<!-- CTA -->
							' . $cta_block . '

						</td>
					</tr>

					<!-- Card bottom radius -->
					<tr>
						<td style="height:0;background-color:#ffffff;border-radius:0 0 8px 8px;font-size:0;line-height:0;">
							&nbsp;
						</td>
					</tr>

					<!-- Footer -->
					<tr>
						<td class="email-padding" style="padding:32px 40px 16px;">
							' . $footer_note_block . '
							<p style="margin:0 0 8px;font-size:13px;line-height:20px;color:#6b6b6b;">
								' . sprintf(
									/* translators: %s: linked site name */
								esc_html__( 'Sent from %s', 'learnomy' ),
								'<a href="' . $site_url . '" style="color:#2383e2;text-decoration:none;">' . $site_name . '</a>'
							) . '
							</p>
							<p style="margin:0 0 8px;font-size:12px;line-height:18px;color:#9b9b9b;">
								' . sprintf(
									/* translators: %s: year */
								esc_html__( '%1$s %2$s. All rights reserved.', 'learnomy' ),
								'&copy; ' . esc_html( $year ),
								$site_name
							) . '
							</p>
							<p style="margin:0;font-size:12px;line-height:18px;color:#9b9b9b;">
								<a href="' . esc_url( \Learnomy\route_url( 'student-dashboard' ) ) . '" style="color:#6b6b6b;text-decoration:underline;">' . esc_html__( 'Manage notification preferences', 'learnomy' ) . '</a>
							</p>
						</td>
					</tr>

				</table>
				<!-- /Email container -->

			</td>
		</tr>
	</table>
	<!-- /Outer wrapper -->

</body>
</html>';

		return $html;
	}

	/**
	 * Retrieves the site logo URL from the customizer, or returns empty string.
	 */
	private static function get_logo_url(): string {
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( ! $custom_logo_id ) {
			return '';
		}

		$image = wp_get_attachment_image_src( $custom_logo_id, 'medium' );
		return $image ? (string) $image[0] : '';
	}
}
