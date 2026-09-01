<?php
/**
 * Account certificates — /account/certificates/
 *
 * The learner's earned certificates, in the shared account layout. Dedicated
 * home for the former dashboard "Your Certificates" widget (shown in the nav
 * only when the certificates module is enabled).
 *
 * View-data (from Account_Certificates_View_Data): $certificates, $certificate_titles.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$certificates = isset( $certificates ) && is_array( $certificates ) ? $certificates : array();
?>
<main class="lrn-container lrn-account-certificates" id="lrn-account-certificates">

	<?php
	$breadcrumb_items = \Learnomy\account_breadcrumb( __( 'Certificates', 'learnomy' ) );
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-account-layout">
		<?php
		$account_nav_active = 'account-certificates';
		require \Learnomy\template_path( 'partials/account-nav.php' );
		?>
		<div class="lrn-account-layout__content">

			<?php
			$account_page = array(
				'icon'     => 'award',
				'title'    => __( 'Certificates', 'learnomy' ),
				'subtitle' => __( 'Download or share the certificates you have earned.', 'learnomy' ),
			);
			require \Learnomy\template_path( 'partials/account-page-header.php' );

			// Reuse the certificate-cards partial (handles its own empty state).
			require \Learnomy\template_path( 'dashboard-widgets/student/certificates.php' );
			?>

		</div><!-- .lrn-account-layout__content -->
	</div><!-- .lrn-account-layout -->

</main>
