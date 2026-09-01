<?php
/**
 * Admin dashboard view — registry-driven.
 *
 * The page chrome (wrap + page-header) stays here as a primitive; every
 * section below is contributed via the `learnomy_dashboard_widgets`
 * filter on the 'admin' surface. Free first-party widgets live in
 * Free_Dashboard_Widgets::admin_widgets(); Pro and any third party use
 * the same filter — no privileged in-template path.
 *
 * @package Learnomy
 * @var array<string, int> $stats      Counts from Admin::gather_dashboard_stats()
 * @var array              $chart_data Wired into the admin dashboard JS
 * @var bool               $has_demo   Demo data manifest flag
 */

defined( 'ABSPATH' ) || exit;

// Demo "present" check — either the manifest option exists or DB still
// carries signature-matched demo rows.
$demo_seeded = ! empty( get_option( 'learnomy_demo_data' ) );
if ( ! $demo_seeded ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$signature_hit = (int) $GLOBALS['wpdb']->get_var(
		"SELECT 1 FROM {$GLOBALS['wpdb']->prefix}lrn_courses WHERE slug LIKE 'lrn-demo-%' LIMIT 1"
	);
	$demo_seeded   = 1 === $signature_hit;
}

// Render the surface that matches the role, not always 'admin'.
//
// This page is registered with the INSTRUCTOR capability
// (learnomy_manage_own_courses), so an instructor can open it — but it used to
// build the 'admin' surface unconditionally. That handed instructors the site
// owner's dashboard: quick-actions to Certificates and Settings, system health
// links to the Email Log and Webhook Events, refund and transaction links to
// Earnings, pending applications. Every one of those targets is registered
// manage_options, so every one answered "Sorry, you are not allowed to access
// this page" (Basecamp 10082715994).
//
// Hiding those widgets would have left an instructor staring at a near-empty
// page. The right answer is that a full instructor dashboard already exists —
// My Courses, Awaiting Grading, Students at Risk, Engagement, Earnings — and is
// what the front-end instructor dashboard renders. So we resolve the same
// view-data through the same resolver and the same shared context partial
// (templates/partials/instructor-dashboard-ctx.php), and hand the instructor a
// dashboard that is actually theirs and actually works.
$lrn_is_site_owner = current_user_can( 'manage_options' );

if ( $lrn_is_site_owner ) {
	$lrn_dashboard_ctx = array(
		'surface'   => 'admin',
		'route'     => 'admin-dashboard',
		'user_id'   => get_current_user_id(),
		'user'      => wp_get_current_user(),
		'view_data' => array(
			'stats'                => $stats,
			'chart_data'           => $chart_data,
			'has_demo'             => $has_demo,
			'demo_seeded'          => $demo_seeded,
			'onboarding_resolved'  => \Learnomy\Admin\Onboarding_State::resolve(),
			'onboarding_dismissed' => (bool) get_option( 'learnomy_onboarding_dismissed', false ),
		),
	);
} else {
	// Same resolver the front-end instructor dashboard uses — one data path, so
	// the two surfaces cannot drift.
	$lrn_instructor_data = \Learnomy\Services\View_Data\Instructor_Dashboard_View_Data::resolve( array() );
	// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- the shared ctx partial reads these from scope, exactly as the front-end template does.
	extract( $lrn_instructor_data, EXTR_SKIP );

	$lrn_ctx_route = 'admin-dashboard';
	require \Learnomy\template_path( 'partials/instructor-dashboard-ctx.php' );
}

$lrn_admin_ctx = $lrn_dashboard_ctx;

// Instructors get the 'overview' section only — the same slice the front-end
// instructor dashboard renders. Without a section every section is emitted at
// once, which duplicates the widgets that appear in both the overview and a
// detail section (Students at Risk, Recent Enrollments) and turns the page into
// one long scroll. The detail sections have their own homes: the Learnomy
// submenu (Courses / Students / Grading / …) is the wp-admin equivalent of the
// front-end's rail.
$lrn_widgets = $lrn_is_site_owner
	? \Learnomy\Services\Dashboard_Widget_Registry::for_surface( 'admin', $lrn_admin_ctx )
	: \Learnomy\Services\Dashboard_Widget_Registry::for_surface( 'instructor', $lrn_admin_ctx, 'overview' );
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="layout-dashboard" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Dashboard', 'learnomy' ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a href="<?php echo esc_url( \Learnomy\route_url( 'catalog' ) ); ?>" target="_blank" class="lrn-admin-btn">
				<i data-lucide="external-link"></i>
				<?php esc_html_e( 'View Frontend', 'learnomy' ); ?>
			</a>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this point
	// instead of overlapping the page header.
	?>
	<hr class="wp-header-end">

	<?php
	foreach ( $lrn_widgets as $lrn_widget ) {
		\Learnomy\Services\Dashboard_Widget_Registry::render( $lrn_widget, $lrn_admin_ctx );
	}

	// Back-compat tail-hook — modules that registered against
	// `learnomy_admin_dashboard_after_stats` before the widget API
	// existed keep working. New modules should ship a registered widget
	// instead.
	//
	// Site owners only. The hook is admin-by-contract — its name says so and it
	// is handed $stats, the site-wide admin counts — and everything on it assumes
	// a site owner is reading. Pro's listener renders the LICENSE status
	// (Licensed / Unlicensed, extensions enabled), which is the site owner's
	// business and not an instructor's; firing it here is how that badge showed up
	// on an instructor's dashboard.
	if ( $lrn_is_site_owner ) {
		do_action( 'learnomy_admin_dashboard_after_stats', $stats );
	}
	?>
</div>
