<?php
/**
 * Learning Standards hub — the single map of every learning-standard
 * integration (xAPI, SCORM, LTI, H5P, QTI) with its status and the one place
 * it is configured. Rendered at the top of the Settings → Learning Standards
 * section so an owner finds and configures all five from one spot instead of
 * the five scattered screens they used to live on.
 *
 * The catalogue is the single shared source: Pro's standard modules refine
 * each row's `status`, `url`, and `cta` through the `learnomy_learning_standards`
 * filter, so the hub can never drift from where a standard is actually set.
 *
 * @package Learnomy\Admin
 */

defined( 'ABSPATH' ) || exit;

$lrn_pro_features_url = admin_url( 'admin.php?page=learnomy-pro-features' );
$lrn_import_url       = admin_url( 'admin.php?page=learnomy-import' );

/**
 * The canonical learning-standards catalogue. Each entry is one standard the
 * academy can connect. Pro's standard modules refine `status` (to `active`),
 * `url`, and `cta` for the ones they implement so this map links straight to
 * the live setting.
 *
 * @since 1.4.1
 *
 * @param array<string,array<string,string>> $standards Keyed by standard slug.
 */
$lrn_standards = apply_filters(
	'learnomy_learning_standards',
	array(
		'xapi'  => array(
			'name'    => __( 'xAPI', 'learnomy' ),
			'icon'    => 'activity',
			'summary' => __( 'Send activity statements to your Learning Record Store (LRS).', 'learnomy' ),
			'status'  => 'available',
			'url'     => $lrn_pro_features_url,
			'cta'     => __( 'Set up in Pro Extensions', 'learnomy' ),
		),
		'scorm' => array(
			'name'    => __( 'SCORM', 'learnomy' ),
			'icon'    => 'package',
			'summary' => __( 'Play SCORM 1.2 and 2004 packages as lessons.', 'learnomy' ),
			'status'  => 'available',
			'url'     => $lrn_pro_features_url,
			'cta'     => __( 'Set up in Pro Extensions', 'learnomy' ),
		),
		'lti'   => array(
			'name'    => __( 'LTI', 'learnomy' ),
			'icon'    => 'plug-zap',
			'summary' => __( 'Connect external LTI tools and learning platforms.', 'learnomy' ),
			'status'  => 'available',
			'url'     => $lrn_pro_features_url,
			'cta'     => __( 'Set up in Pro Extensions', 'learnomy' ),
		),
		'h5p'   => array(
			'name'    => __( 'H5P', 'learnomy' ),
			'icon'    => 'puzzle',
			'summary' => __( 'Embed interactive H5P content inside lessons.', 'learnomy' ),
			'status'  => 'available',
			'url'     => $lrn_pro_features_url,
			'cta'     => __( 'Set up in Pro Extensions', 'learnomy' ),
		),
		'qti'   => array(
			'name'    => __( 'QTI', 'learnomy' ),
			'icon'    => 'file-code',
			'summary' => __( 'Import and export question banks as QTI.', 'learnomy' ),
			'status'  => 'available',
			'url'     => $lrn_import_url,
			'cta'     => __( 'Open Import', 'learnomy' ),
		),
	)
);

if ( ! is_array( $lrn_standards ) || empty( $lrn_standards ) ) {
	return;
}
?>
<div class="lrn-standards-grid">
	<?php
	foreach ( $lrn_standards as $lrn_std_key => $lrn_std ) :
		$lrn_std_name     = isset( $lrn_std['name'] ) ? (string) $lrn_std['name'] : ucfirst( (string) $lrn_std_key );
		$lrn_std_icon     = isset( $lrn_std['icon'] ) ? (string) $lrn_std['icon'] : 'plug';
		$lrn_std_summary  = isset( $lrn_std['summary'] ) ? (string) $lrn_std['summary'] : '';
		$lrn_std_status   = ( isset( $lrn_std['status'] ) && 'active' === $lrn_std['status'] ) ? 'active' : 'available';
		$lrn_std_url      = isset( $lrn_std['url'] ) ? (string) $lrn_std['url'] : $lrn_pro_features_url;
		$lrn_std_cta      = isset( $lrn_std['cta'] ) ? (string) $lrn_std['cta'] : __( 'Configure', 'learnomy' );
		$lrn_status_label = ( 'active' === $lrn_std_status ) ? __( 'Active', 'learnomy' ) : __( 'Available', 'learnomy' );
		?>
		<a class="lrn-standard-card" href="<?php echo esc_url( $lrn_std_url ); ?>">
			<span class="lrn-standard-card__icon"><i data-lucide="<?php echo esc_attr( $lrn_std_icon ); ?>" aria-hidden="true"></i></span>
			<span class="lrn-standard-card__body">
				<span class="lrn-standard-card__head">
					<span class="lrn-standard-card__name"><?php echo esc_html( $lrn_std_name ); ?></span>
					<span class="lrn-standard-card__badge lrn-standard-card__badge--<?php echo esc_attr( $lrn_std_status ); ?>"><?php echo esc_html( $lrn_status_label ); ?></span>
				</span>
				<?php if ( '' !== $lrn_std_summary ) : ?>
					<span class="lrn-standard-card__summary"><?php echo esc_html( $lrn_std_summary ); ?></span>
				<?php endif; ?>
				<span class="lrn-standard-card__cta"><?php echo esc_html( $lrn_std_cta ); ?> <i data-lucide="arrow-right" aria-hidden="true"></i></span>
			</span>
		</a>
	<?php endforeach; ?>
</div>
