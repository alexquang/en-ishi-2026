<?php
/**
 * Public certificate verification page.
 *
 * No authentication required — anyone with the UUID can verify.
 *
 * Variables available:
 *   $route (string) 'certificate-verify'
 *   $slug  (string) certificate UUID from the URL
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// $view_data variables (injected by Template_Loader via Certificate_Verify_View_Data):
// $certificate, $is_valid, $is_revoked
// When $is_valid is true, also: $student, $student_name, $course, $course_name,
// $course_recorded, $course_note, $issuer_name, $issued_date, $short_id,
// $template, $certificate_html, $qr_src, $pdf_available, $pdf_url, $html_dl_url
?>
<main class="lrn-container lrn-certificate-verify" role="main">

	<?php
	// Root per breadcrumb_root(): an anonymous verifier (an employer checking
	// a certificate) anchors to the catalog, a logged-in reader to Account.
	$breadcrumb_items = [
		\Learnomy\breadcrumb_root(),
		[
			'label' => __( 'Verify Certificate', 'learnomy' ),
		],
	];
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<header class="lrn-certificate-verify__header">
		<h1><?php esc_html_e( 'Certificate Verification', 'learnomy' ); ?></h1>
		<p class="lrn-certificate-verify__subtitle">
			<?php esc_html_e( 'Confirm the authenticity of a Learnomy certificate.', 'learnomy' ); ?>
		</p>
	</header>

	<?php if ( $is_valid ) : ?>
		<?php
		/**
		 * Fires in the verify page head region for a valid certificate.
		 * Extensions use this to inject discovery links (e.g. Open Badges).
		 *
		 * @param object $certificate The verified certificate row.
		 */
		do_action( 'learnomy_certificate_verify_head', $certificate );
		?>
		<!-- Rendered certificate preview (scaled to fit the page) -->
		<div class="lrn-certificate-verify__preview" data-cert-preview>
			<?php
			// Render the certificate inside a fully sandboxed iframe. The cert
			// HTML is a complete print document whose stylesheet sets global
			// `html, body { width: 297mm }`, a `*` reset and an `@page` rule.
			// Previously the template echoed that <style> + the body markup
			// inline, so those global selectors leaked onto the verify page and
			// squeezed the entire Reign layout to 297mm, shifting it left
			// (Basecamp #9976411641). An iframe isolates ANY cert CSS — the
			// default renderer or a custom design template — with zero leakage.
			// certificate-verify-view.js scales the native-size frame to fit
			// this container's width. sandbox="" blocks scripts (the cert is
			// static HTML) while still allowing its images/fonts to load.
			?>
			<iframe
				class="lrn-certificate-verify__preview-frame"
				title="<?php esc_attr_e( 'Certificate preview', 'learnomy' ); ?>"
				sandbox=""
				loading="lazy"
				srcdoc="<?php echo esc_attr( $certificate_html ); ?>"
			></iframe>
		</div>

		<!-- Download and print actions -->
		<div class="lrn-certificate-verify__download-actions">
			<?php
			if ( $pdf_available ) :
				$action_icon    = 'file-text';
				$action_label   = __( 'Download', 'learnomy' );
				$action_title   = null;
				$action_href    = $pdf_url;
				$action_aria    = __( 'Download certificate as PDF', 'learnomy' );
				$action_variant = 'primary';
				$action_class   = '';
				$action_attrs   = [
					'target' => '_blank',
					'rel'    => 'noopener noreferrer',
				];
				include \Learnomy\template_path( 'partials/lrn-action.php' );
				$action_variant = null;
			endif;

			$action_icon  = 'printer';
			$action_label = __( 'Print', 'learnomy' );
			$action_title = null;
			$action_href  = $html_dl_url;
			$action_aria  = __( 'Print certificate', 'learnomy' );
			$action_class = '';
			$action_attrs = [
				'target'              => '_blank',
				'rel'                 => 'noopener noreferrer',
				'data-wp-interactive' => 'learnomy/certificate-verify',
				'data-wp-on--click'   => 'actions.printCert',
			];
			include \Learnomy\template_path( 'partials/lrn-action.php' );
			?>
		</div>

		<div class="lrn-certificate-verify__result lrn-card lrn-certificate-verify__result--valid"
			role="status"
			aria-label="<?php esc_attr_e( 'Certificate status: Valid', 'learnomy' ); ?>">
			<div class="lrn-certificate-verify__status">
				<i data-lucide="check-circle" aria-hidden="true"></i>
				<strong><?php esc_html_e( 'Certificate Verified', 'learnomy' ); ?></strong>
			</div>

			<dl class="lrn-certificate-verify__details">
				<div class="lrn-certificate-verify__detail-row">
					<dt><?php esc_html_e( 'Recipient', 'learnomy' ); ?></dt>
					<dd><?php echo esc_html( $student_name ); ?></dd>
				</div>
				<div class="lrn-certificate-verify__detail-row">
					<dt><?php esc_html_e( 'Course', 'learnomy' ); ?></dt>
					<dd>
						<?php echo esc_html( $course_name ); ?>
						<?php if ( ! empty( $course_note ) ) : ?>
							<span class="lrn-certificate-verify__note"><?php echo esc_html( $course_note ); ?></span>
						<?php endif; ?>
					</dd>
				</div>
				<div class="lrn-certificate-verify__detail-row">
					<dt><?php esc_html_e( 'Issued By', 'learnomy' ); ?></dt>
					<dd><?php echo esc_html( $issuer_name ); ?></dd>
				</div>
				<div class="lrn-certificate-verify__detail-row">
					<dt><?php esc_html_e( 'Issued On', 'learnomy' ); ?></dt>
					<dd><time datetime="<?php echo esc_attr( $certificate->issued_at ); ?>"><?php echo esc_html( $issued_date ); ?></time></dd>
				</div>
				<div class="lrn-certificate-verify__detail-row">
					<dt><?php esc_html_e( 'Credential ID', 'learnomy' ); ?></dt>
					<dd><code><?php echo esc_html( $short_id ); ?></code></dd>
				</div>
				<div class="lrn-certificate-verify__detail-row">
					<dt><?php esc_html_e( 'Status', 'learnomy' ); ?></dt>
					<dd><span class="lrn-badge lrn-badge--green"><?php esc_html_e( 'Valid', 'learnomy' ); ?></span></dd>
				</div>
			</dl>

			<!-- QR code generated locally (see Certificate_Renderer::qr_data_uri). -->
			<?php if ( ! empty( $qr_src ) ) : ?>
				<div class="lrn-certificate-verify__qr" aria-label="<?php esc_attr_e( 'QR code for this certificate', 'learnomy' ); ?>">
					<img src="<?php echo esc_attr( $qr_src ); ?>"
						alt="<?php esc_attr_e( 'QR code linking to this certificate verification page', 'learnomy' ); ?>"
						width="120" height="120" loading="lazy">
				</div>
			<?php endif; ?>

			<?php
			$verify_page_url = \Learnomy\route_url( 'certificate-verify', array( 'slug' => (string) $certificate->uuid ) );
			$download_url    = rest_url( 'learnomy/v1/certificates/' . urlencode( $certificate->uuid ) . '/download' );
			$issued_ts       = strtotime( $certificate->issued_at );

			// LinkedIn wants a certification NAME. When the course name was never
			// recorded and the course row is gone, $course_name is the sentence the
			// document prints, not a title — posting that to a public profile would
			// be wrong, so the button is withheld rather than filled with prose.
			$linkedin_url = ! $course_recorded ? '' : add_query_arg(
				[
					'startTask'        => 'CERTIFICATION_NAME',
					'name'             => $course_name,
					'organizationName' => $issuer_name,
					'issueYear'        => gmdate( 'Y', $issued_ts ),
					'issueMonth'       => gmdate( 'm', $issued_ts ),
					'certUrl'          => $verify_page_url,
					'certId'           => $short_id,
				],
				'https://www.linkedin.com/profile/add'
			);
			?>
			<div class="lrn-certificate-verify__share">
				<h3><?php esc_html_e( 'Share this certificate', 'learnomy' ); ?></h3>
				<div class="lrn-certificate-verify__share-buttons">
					<?php
					if ( $linkedin_url ) {
						$action_icon  = 'linkedin';
						$action_label = __( 'Share', 'learnomy' );
						$action_title = null;
						$action_href  = $linkedin_url;
						$action_aria  = __( 'Add certificate to LinkedIn profile', 'learnomy' );
						$action_class = '';
						$action_attrs = [
							'target' => '_blank',
							'rel'    => 'noopener noreferrer',
						];
						include \Learnomy\template_path( 'partials/lrn-action.php' );
					}

					$action_icon  = 'link';
					$action_label = __( 'Copy', 'learnomy' );
					$action_title = null;
					$action_href  = null;
					$action_aria  = __( 'Copy verification link', 'learnomy' );
					$action_class = 'lrn-copy-link';
					$action_attrs = [
						'data-wp-interactive' => 'learnomy/certificate-verify',
						'data-wp-on--click'   => 'actions.copyLink',
						'data-wp-context'     => wp_json_encode(
							[
								'url'         => $verify_page_url,
								'copiedLabel' => __( 'Copied.', 'learnomy' ),
								'failedLabel' => __( 'Press Ctrl/Cmd+C to copy.', 'learnomy' ),
							]
						),
					];
					include \Learnomy\template_path( 'partials/lrn-action.php' );

					$action_icon  = 'download';
					$action_label = __( 'Download', 'learnomy' );
					$action_title = null;
					$action_href  = $pdf_available ? $pdf_url : $html_dl_url;
					$action_aria  = __( 'Download certificate', 'learnomy' );
					$action_class = '';
					$action_attrs = [
						'target' => '_blank',
						'rel'    => 'noopener noreferrer',
					];
					include \Learnomy\template_path( 'partials/lrn-action.php' );
					?>
				</div>
			</div>
		</div>
		<!-- Copy-link handled by learnomy/certificate-verify IAPI store. -->

		<?php
		/**
		 * Fires after the verified certificate result block. Extensions use
		 * this to render extra verification details (blockchain anchor,
		 * LinkedIn add button, Open Badge link).
		 *
		 * @param object $certificate The verified certificate row.
		 */
		do_action( 'learnomy_certificate_verify_after', $certificate );
		?>

	<?php elseif ( $is_revoked ) : ?>
		<div class="lrn-certificate-verify__result lrn-card lrn-certificate-verify__result--revoked"
			role="status"
			aria-label="<?php esc_attr_e( 'Certificate status: Revoked', 'learnomy' ); ?>">
			<i data-lucide="x-circle" aria-hidden="true"></i>
			<h2><?php esc_html_e( 'Certificate Revoked', 'learnomy' ); ?></h2>
			<p><?php esc_html_e( 'This certificate has been revoked and is no longer valid.', 'learnomy' ); ?></p>
			<?php
			// A revoked certificate is still a real record, and the person reading
			// this page needs to know WHICH credential was revoked and WHEN. The
			// card shipped showing only the generic sentence above, while
			// revoked_at sat populated in the database (Basecamp 10134139845 /
			// 10134139810) — so an employer could not tell whether the revocation
			// predated the candidate's claim.
			$lrn_revoked_name  = $lrn_recipient_name ?? '';
			$lrn_revoked_title = $lrn_course_title ?? '';
			$lrn_revoked_ts    = ! empty( $certificate->revoked_at ) ? strtotime( (string) $certificate->revoked_at ) : 0;
			?>
			<?php if ( $lrn_revoked_name || $lrn_revoked_title || $lrn_revoked_ts > 0 ) : ?>
				<dl class="lrn-certificate-verify__meta">
					<?php if ( $lrn_revoked_name ) : ?>
						<dt><?php esc_html_e( 'Issued to', 'learnomy' ); ?></dt>
						<dd><?php echo esc_html( $lrn_revoked_name ); ?></dd>
					<?php endif; ?>
					<?php if ( $lrn_revoked_title ) : ?>
						<dt><?php esc_html_e( 'Course', 'learnomy' ); ?></dt>
						<dd><?php echo esc_html( $lrn_revoked_title ); ?></dd>
					<?php endif; ?>
					<?php if ( $lrn_revoked_ts > 0 ) : ?>
						<dt><?php esc_html_e( 'Revoked on', 'learnomy' ); ?></dt>
						<dd><?php echo esc_html( date_i18n( get_option( 'date_format' ), $lrn_revoked_ts ) ); ?></dd>
					<?php endif; ?>
				</dl>
			<?php endif; ?>
		</div>

	<?php elseif ( ! empty( $is_tampered ) ) : ?>
		<div class="lrn-certificate-verify__result lrn-card lrn-certificate-verify__result--invalid"
			role="status"
			aria-label="<?php esc_attr_e( 'Certificate status: Signature could not be verified', 'learnomy' ); ?>">
			<i data-lucide="shield-alert" aria-hidden="true"></i>
			<h2><?php esc_html_e( 'Signature Could Not Be Verified', 'learnomy' ); ?></h2>
			<p><?php esc_html_e( 'This certificate record exists, but its security signature does not match. It may have been altered. Contact the issuer to confirm.', 'learnomy' ); ?></p>
		</div>

	<?php elseif ( $slug ) : ?>
		<div class="lrn-certificate-verify__result lrn-card lrn-certificate-verify__result--invalid"
			role="status"
			aria-label="<?php esc_attr_e( 'Certificate status: Not found', 'learnomy' ); ?>">
			<i data-lucide="alert-triangle" aria-hidden="true"></i>
			<h2><?php esc_html_e( 'Certificate Not Found', 'learnomy' ); ?></h2>
			<p><?php esc_html_e( 'No certificate matches this ID. Double-check the URL and try again.', 'learnomy' ); ?></p>
		</div>

	<?php else : ?>
		<div class="lrn-certificate-verify__form lrn-card">
			<p><?php esc_html_e( 'Enter a credential ID or use the URL from the certificate to verify it.', 'learnomy' ); ?></p>
			<form method="get" action="">
				<label for="lrn-cert-id" class="screen-reader-text"><?php esc_html_e( 'Certificate ID', 'learnomy' ); ?></label>
				<input id="lrn-cert-id" type="text" name="id" class="lrn-input"
					placeholder="<?php esc_attr_e( 'e.g. A1B2C3D4-…', 'learnomy' ); ?>">
				<button type="submit" class="lrn-btn lrn-btn--primary">
					<?php esc_html_e( 'Verify', 'learnomy' ); ?>
				</button>
			</form>
		</div>
	<?php endif; ?>

</main><!-- .lrn-certificate-verify -->
