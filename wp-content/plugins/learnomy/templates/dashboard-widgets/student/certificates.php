<?php
/**
 * Earned certificates widget body. Renders one card per certificate.
 *
 * Locals: $certificates, $certificate_titles
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$certificate_titles = isset( $certificate_titles ) && is_array( $certificate_titles ) ? $certificate_titles : array();

if ( empty( $certificates ) ) {
	?>
	<div class="lrn-empty-state">
		<i data-lucide="award" class="lrn-empty-state__icon" aria-hidden="true"></i>
		<h3 class="lrn-empty-state__title"><?php esc_html_e( 'No certificates yet', 'learnomy' ); ?></h3>
		<p class="lrn-empty-state__desc"><?php esc_html_e( 'Complete a course to earn your first certificate.', 'learnomy' ); ?></p>
	</div>
	<?php
	return;
}

$site_name = get_bloginfo( 'name' );
?>
<div class="lrn-grid lrn-certificates-grid">
	<?php
	foreach ( $certificates as $cert ) :
		// The certificate names its own course, from the snapshot taken when it
		// was issued. Retiring or renaming a course used to relabel — or gut —
		// the learner's own credentials (Basecamp 10148480574); only a
		// certificate issued before snapshots existed, whose course row is also
		// gone, still has no name to print.
		$cert_title   = $certificate_titles[ (int) $cert->id ] ?? array();
		$is_archived  = empty( $cert_title['recorded'] );
		$course_title = (string) ( $cert_title['title'] ?? '' );
		$verify_url   = \Learnomy\route_url( 'certificate-verify', array( 'slug' => (string) $cert->uuid ) );
		$download_url = rest_url( 'learnomy/v1/certificates/' . urlencode( $cert->uuid ) . '/download' );
		$short_id     = strtoupper( substr( $cert->uuid, 0, 8 ) );
		$issued_ts    = strtotime( $cert->issued_at );
		$issued_date  = date_i18n( get_option( 'date_format' ), $issued_ts );
		$issue_year   = gmdate( 'Y', $issued_ts );
		$issue_month  = gmdate( 'm', $issued_ts );

		$linkedin_url = $is_archived ? '' : add_query_arg(
			array(
				'startTask'        => 'CERTIFICATION_NAME',
				'name'             => $course_title,
				'organizationName' => $site_name,
				'issueYear'        => $issue_year,
				'issueMonth'       => $issue_month,
				'certUrl'          => $verify_url,
				'certId'           => $short_id,
			),
			'https://www.linkedin.com/profile/add'
		);
		?>
		<div class="lrn-card lrn-certificate-card<?php echo $is_archived ? ' lrn-certificate-card--archived' : ''; ?>">
			<div class="lrn-certificate-card__header">
				<i data-lucide="award" class="lrn-certificate-card__icon" aria-hidden="true"></i>
				<div>
					<h3 class="lrn-certificate-card__title"><?php echo esc_html( $course_title ); ?></h3>
					<p class="lrn-certificate-card__meta">
						<?php echo esc_html( $issued_date ); ?>
						&middot;
						<code><?php echo esc_html( $short_id ); ?></code>
					</p>
				</div>
			</div>
			<div class="lrn-certificate-card__actions">
				<a href="<?php echo esc_url( $verify_url ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--sm">
					<i data-lucide="check-circle" aria-hidden="true"></i>
					<?php esc_html_e( 'Verify', 'learnomy' ); ?>
				</a>
				<?php if ( $linkedin_url ) : ?>
					<a href="<?php echo esc_url( $linkedin_url ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--sm" target="_blank" rel="noopener noreferrer">
						<i data-lucide="linkedin" aria-hidden="true"></i>
						<?php esc_html_e( 'Add to LinkedIn', 'learnomy' ); ?>
					</a>
				<?php endif; ?>
				<button type="button" class="lrn-btn lrn-btn--secondary lrn-btn--sm lrn-copy-link" data-url="<?php echo esc_url( $verify_url ); ?>">
					<i data-lucide="link" aria-hidden="true"></i>
					<?php esc_html_e( 'Share', 'learnomy' ); ?>
				</button>
				<a href="<?php echo esc_url( $download_url ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--sm" target="_blank" rel="noopener noreferrer">
					<i data-lucide="download" aria-hidden="true"></i>
					<?php esc_html_e( 'Download', 'learnomy' ); ?>
				</a>
			</div>
		</div>
	<?php endforeach; ?>
</div>
