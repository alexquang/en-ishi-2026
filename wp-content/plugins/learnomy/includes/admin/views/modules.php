<?php
/**
 * Modules admin page — site owner enables / disables Learnomy features.
 *
 * Single page where Free + Pro both contribute via the
 * `learnomy_modules_catalog` filter.
 *
 * @package Learnomy\Admin
 */

defined( 'ABSPATH' ) || exit;

$kernel  = \Learnomy\Modules\Module_Kernel::instance();
$catalog = \Learnomy\Modules\Module_Kernel::catalog();
$enabled = $kernel->get_enabled();

// Pro extensions use a separate enabled-state storage; build a lookup
// so the toggle reflects current state for `pro:<id>` rows in the catalog.
$pro_enabled = (array) get_option( 'learnomy_pro_extensions', array() );

// Plan-08: when Pro plugin isn't installed, surface a one-time dismissible
// notice pointing operators at Learnomy → Pro Features so they can discover
// what Pro adds. Per-user dismissal — admins shouldn't have to dismiss for
// the whole site.
$pro_plugin_installed   = \Learnomy\Admin\Pro_Catalog::pro_plugin_active();
$show_pro_notice        = ! $pro_plugin_installed
	&& ! get_user_meta( get_current_user_id(), 'learnomy_pro_modules_notice_dismissed', true );
$pro_features_admin_url = admin_url( 'admin.php?page=learnomy-pro-features' );

/**
 * Determine if a catalog row is currently enabled, accounting for the
 * `pro:` namespace prefix used by Pro extensions.
 */
$is_row_enabled = function ( string $slug, array $module ) use ( $kernel, $pro_enabled ): bool {
	if ( ! empty( $module['is_pro'] ) && ! empty( $module['pro_id'] ) ) {
		return in_array( $module['pro_id'], $pro_enabled, true );
	}
	return $kernel->is_enabled( $slug );
};

// Group by category for grid layout.
$category_labels = array(
	'core'         => __( 'Core', 'learnomy' ),
	'content'      => __( 'Content', 'learnomy' ),
	'student'      => __( 'Student experience', 'learnomy' ),
	'commerce'     => __( 'Commerce', 'learnomy' ),
	'community'    => __( 'Community', 'learnomy' ),
	'integrations' => __( 'Integrations', 'learnomy' ),
	'standards'    => __( 'Standards & interop', 'learnomy' ),
	'reporting'    => __( 'Reporting', 'learnomy' ),
	'operations'   => __( 'Operations', 'learnomy' ),
);

$grouped = array();
foreach ( $catalog as $slug => $module ) {
	$cat                      = $module['category'] ?? 'operations';
	$grouped[ $cat ][ $slug ] = $module;
}

// Render in a stable order so Core appears first. MUST list every category that
// $category_labels defines, or a module in an omitted category is grouped but
// never rendered — which silently hid the Standards (SCORM/LTI/QTI/xAPI/H5P) and
// Reporting (Analytics/User Journey/Gradebook) modules, including the 1.8.0
// Gradebook and SCORM completion gate, with no discovery or toggle path.
$ordered_cats = array( 'core', 'content', 'student', 'commerce', 'community', 'integrations', 'standards', 'reporting', 'operations' );
?>
<div class="wrap learnomy-admin lrn-modules-page">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="toggle-right" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php esc_html_e( 'Modules', 'learnomy' ); ?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php esc_html_e( 'Enable only the features you need. Disabled modules are hidden from the admin menu and don\'t register their REST routes or hooks. You can enable more modules anytime as your site grows.', 'learnomy' ); ?>
			</p>
		</div>
	</div>

	<hr class="wp-header-end">

	<?php if ( $show_pro_notice ) : ?>
		<div class="notice notice-info is-dismissible lrn-modules-pro-notice" data-lrn-pro-notice="1">
			<p>
				<?php
				printf(
					wp_kses(
						/* translators: %s: link to the Pro Features catalog admin page */
						__( 'Learnomy Pro provides 16 additional modules including analytics, cohorts, and learning paths. %s', 'learnomy' ),
						array( 'a' => array( 'href' => array() ) )
					),
					'<a href="' . esc_url( $pro_features_admin_url ) . '"><strong>' . esc_html__( 'View the Pro Features catalog.', 'learnomy' ) . '</strong></a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php foreach ( $ordered_cats as $cat ) : ?>
		<?php
		if ( empty( $grouped[ $cat ] ) ) :
			continue;
		endif;
		?>

		<h2 class="lrn-modules-category">
			<?php echo esc_html( $category_labels[ $cat ] ?? ucfirst( $cat ) ); ?>
		</h2>

		<div class="lrn-modules-grid">
			<?php foreach ( $grouped[ $cat ] as $slug => $module ) : ?>
				<?php
				$is_required = ! empty( $module['required'] );
				$is_pro      = ! empty( $module['is_pro'] );
				$is_beta     = ( $module['status'] ?? '' ) === 'beta';

				// A Pro-provided module can only be toggled when the Pro plugin
				// is active. When Pro is absent the row still shows (so the owner
				// discovers the feature exists) but the toggle is locked off —
				// offering a live toggle that can't do anything is exactly the
				// "toggle blind" trap this page fixes.
				$pro_unavailable = $is_pro && ! $pro_plugin_installed;

				// Never render a Pro-unavailable module as enabled: without Pro
				// loaded its feature code isn't running regardless of any stale
				// stored state.
				$is_enabled = ! $pro_unavailable && $is_row_enabled( $slug, $module );

				// The toggle is locked for required modules and for Pro modules
				// when Pro is inactive.
				$toggle_locked = $is_required || $pro_unavailable;

				$surfaces = ( isset( $module['surfaces'] ) && is_array( $module['surfaces'] ) )
					? $module['surfaces']
					: array();

				// What this module still owes people. Shown on a module that is
				// already OFF so the state stays honest after the fact — an owner
				// who disabled memberships last month should still see that eleven
				// people are being billed. (The same list is what the toggle's
				// confirm dialog shows before the switch flips.) Only computed for
				// a disabled row: on an enabled module there is nothing to warn
				// about, and the queries are not worth running.
				$live_commitments = ( ! $is_enabled && ! $pro_unavailable )
					? \Learnomy\Modules\Module_Kernel::disable_impact( $slug )
					: array();

				$card_classes = array( 'lrn-module-card' );
				if ( $is_enabled ) {
					$card_classes[] = 'lrn-module-card--enabled';
				}
				if ( $is_required ) {
					$card_classes[] = 'lrn-module-card--required';
				}
				if ( $pro_unavailable ) {
					$card_classes[] = 'lrn-module-card--pro-locked';
				}
				?>
				<div class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>">
					<div class="lrn-module-card__head">
						<div class="lrn-module-card__body">
							<h3 class="lrn-module-card__title">
								<?php echo esc_html( $module['name'] ); ?>
								<?php if ( $is_pro ) : ?>
									<a
										href="<?php echo esc_url( $pro_features_admin_url ); ?>"
										title="<?php esc_attr_e( 'Available with Learnomy Pro. View the feature catalog.', 'learnomy' ); ?>"
										class="lrn-module-card__pill lrn-module-card__pill--pro"
									><?php esc_html_e( 'PRO', 'learnomy' ); ?></a>
								<?php endif; ?>
								<?php if ( $is_beta ) : ?>
									<span class="lrn-module-card__pill lrn-module-card__pill--beta"><?php esc_html_e( 'BETA', 'learnomy' ); ?></span>
								<?php endif; ?>
							</h3>
						</div>
						<label class="lrn-toggle lrn-module-card__toggle" for="lrn-module-<?php echo esc_attr( $slug ); ?>">
							<input
								type="checkbox"
								id="lrn-module-<?php echo esc_attr( $slug ); ?>"
								class="lrn-module-toggle"
								data-module="<?php echo esc_attr( $slug ); ?>"
								data-module-name="<?php echo esc_attr( $module['name'] ); ?>"
								<?php checked( $is_enabled ); ?>
								<?php disabled( $toggle_locked ); ?>
								<?php if ( $pro_unavailable ) : ?>
									aria-describedby="lrn-module-pro-note-<?php echo esc_attr( $slug ); ?>"
								<?php endif; ?>
							>
							<span class="lrn-toggle__track"><span class="lrn-toggle__thumb"></span></span>
						</label>
					</div>
					<p class="lrn-module-card__desc">
						<?php echo esc_html( $module['description'] ); ?>
					</p>
					<?php if ( $surfaces ) : ?>
						<div class="lrn-module-card__surfaces">
							<p class="lrn-module-card__surfaces-label">
								<i data-lucide="layers" class="lrn-module-card__surfaces-icon" aria-hidden="true"></i>
								<?php esc_html_e( 'Turns these surfaces on or off:', 'learnomy' ); ?>
							</p>
							<ul class="lrn-module-card__surfaces-list">
								<?php foreach ( $surfaces as $surface ) : ?>
									<li><?php echo esc_html( $surface ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
					<?php if ( $live_commitments ) : ?>
						<div class="lrn-module-card__commitments">
							<p class="lrn-module-card__commitments-label">
								<i data-lucide="alert-triangle" class="lrn-module-card__commitments-icon" aria-hidden="true"></i>
								<?php esc_html_e( 'Switched off, but still owed:', 'learnomy' ); ?>
							</p>
							<ul class="lrn-module-card__commitments-list">
								<?php foreach ( $live_commitments as $commitment ) : ?>
									<li><?php echo esc_html( $commitment['message'] ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
					<?php if ( $is_required ) : ?>
						<p class="lrn-module-card__required-note">
							<?php esc_html_e( 'Required. Cannot be disabled.', 'learnomy' ); ?>
						</p>
					<?php elseif ( $pro_unavailable ) : ?>
						<p class="lrn-module-card__required-note" id="lrn-module-pro-note-<?php echo esc_attr( $slug ); ?>">
							<?php esc_html_e( 'Requires Learnomy Pro. Install and activate Pro to enable this module.', 'learnomy' ); ?>
						</p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endforeach; ?>

</div>

