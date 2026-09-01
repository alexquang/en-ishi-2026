<?php
/**
 * Instructor dashboard — header, stats, then a registry-driven widget loop.
 *
 * Mirrors the student template: every section below the stats row is
 * contributed via the `learnomy_dashboard_widgets` filter. No hardcoded
 * sections.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// $view_data variables (injected by Template_Loader via Instructor_Dashboard_View_Data):
// $user_id, $user, $is_instructor, $my_courses, $my_course_total,
// $my_courses_overflow, $viewing_all_courses, $published_count,
// $total_students, $total_revenue, $available_to_withdraw,
// $this_month_earnings, $recent_enrollments, $enrollment_students,
// $pending_reviews, $review_users, $review_courses, $funnel_*

if ( ! $is_instructor ) {
	// One copy of this page, shared with the five instructor SECTION routes,
	// which used to 404 rather than say any of it. The partial's header explains
	// why that mattered a long way beyond this template.
	include \Learnomy\Template_Loader::locate( 'partials/instructor-access-gate.php' );
	return;
}

// Engagement alerts (inactive_7 / inactive_14) now come from the view-data
// resolver (Enrollment::count_inactive_students*), extracted into scope by the
// Template Loader — no longer hardcoded here, so the Engagement Alerts widget
// can actually populate. Default to 0 only if the resolver didn't provide them.
$inactive_7  = $inactive_7 ?? 0;
$inactive_14 = $inactive_14 ?? 0;

$create_url = apply_filters( 'learnomy_instructor_create_course_url', admin_url( 'admin.php?page=learnomy-new-course' ) );

$lrn_ctx_route = 'instructor-dashboard';
require \Learnomy\template_path( 'partials/instructor-dashboard-ctx.php' );

// The dashboard home now shows only the 'overview' section. The heavier blocks
// — My Courses, Students, Earnings — live on their own rail sections so this
// page stays a lean overview instead of one long scroll.
$lrn_widgets = \Learnomy\Services\Dashboard_Widget_Registry::for_surface( 'instructor', $lrn_dashboard_ctx, 'overview' );
?>
<main class="lrn-container lrn-dashboard" id="lrn-instructor-dashboard">

	<?php
	// Root per breadcrumb_root( 'account' ) — this page is login-gated, so in practice
	// the trail reads Account › Instructor Dashboard.
	$breadcrumb_items = array(
		\Learnomy\breadcrumb_root( 'account' ),
		array(
			'label' => sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s Dashboard', 'learnomy' ), \Learnomy\instructor_label() ),
		),
	);
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-account-layout">
		<?php
		$instructor_nav_active = 'instructor-dashboard';
		require \Learnomy\template_path( 'partials/instructor-nav.php' );
		?>
		<div class="lrn-account-layout__content">

	<?php if ( $viewing_all_courses ) : ?>
		<div class="lrn-notice lrn-notice--info" role="status">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
			<span>
				<?php
				printf(
					/* translators: %s: link to the admin courses list */
					esc_html__( 'You have not authored any courses, so this dashboard is showing site-wide data for all %s.', 'learnomy' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=learnomy-courses' ) ) . '">' . esc_html__( 'courses in admin', 'learnomy' ) . '</a>'
				);
				?>
			</span>
		</div>
	<?php endif; ?>

	<header class="lrn-dashboard__header">
		<div class="lrn-dashboard__welcome">
			<?php echo get_avatar( $user_id, 64, '', $user->display_name, array( 'class' => 'lrn-dashboard__avatar' ) ); ?>
			<div>
				<h1 class="lrn-dashboard__greeting">
					<?php
					/* translators: %s: instructor display name */
					echo esc_html( sprintf( __( 'Hello, %s', 'learnomy' ), $user->display_name ) );
					?>
				</h1>
				<p class="lrn-dashboard__subtitle">
					<?php
					echo esc_html(
						$viewing_all_courses
							? sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s Dashboard (Admin View)', 'learnomy' ), \Learnomy\instructor_label() )
							: sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s Dashboard', 'learnomy' ), \Learnomy\instructor_label() )
					);
					?>
				</p>
			</div>
		</div>
		<div class="lrn-dashboard__actions">
			<?php
			$action_icon    = 'plus-circle';
			$action_label   = __( 'Create', 'learnomy' );
			$action_title   = __( 'Create New Course', 'learnomy' );
			$action_href    = $create_url;
			$action_aria    = __( 'Create New Course', 'learnomy' );
			$action_variant = 'primary';
			$action_class   = '';
			$action_attrs   = array();
			require \Learnomy\template_path( 'partials/lrn-action.php' );
			$action_variant = null;

			$action_icon  = 'book-open';
			$action_label = __( 'Catalog', 'learnomy' );
			$action_title = null;
			$action_href  = \Learnomy\route_url( 'catalog' );
			$action_aria  = __( 'View course catalog', 'learnomy' );
			$action_class = '';
			$action_attrs = array();
			require \Learnomy\template_path( 'partials/lrn-action.php' );
			?>
		</div>
	</header>

	<div class="lrn-dashboard__stats lrn-stats-row">
		<?php
		$stats = array(
			array(
				'icon'  => 'book-open',
				'count' => $my_course_total ?? count( $my_courses ),
				'label' => __( 'Total Courses', 'learnomy' ),
			),
			array(
				'icon'  => 'users',
				'count' => $total_students,
				'label' => sprintf( /* translators: %s: plural label for students (e.g. "Learners"). */ __( 'Total %s', 'learnomy' ), \Learnomy\student_label( true ) ),
			),
			array(
				'icon'  => 'trending-up',
				'count' => $published_count ?? 0,
				'label' => __( 'Published', 'learnomy' ),
			),
		);
		// Show the Revenue stat only for instructors who participate in revenue
		// (effective share > 0). Same filter that gates the Earnings rail, so the
		// overview and the rail stay in lockstep.
		$lrn_show_earnings = (bool) apply_filters(
			'learnomy_show_instructor_earnings',
			\Learnomy\Services\Commission_Service::instructor_participates_in_revenue( get_current_user_id() ),
			get_current_user_id()
		);
		if ( $lrn_show_earnings ) {
			$stats[] = array(
				'icon'  => 'banknote',
				// Formatted through the canonical helper. This built the string as
				// a translatable '$%s', which hardcodes the dollar sign on every
				// site and puts a currency glyph inside a string a translator may
				// legitimately change. It also cast to (int), so a revenue of
				// 1,234.56 displayed as $1,234.
				'count' => \Learnomy\format_price( (float) $total_revenue ),
				'label' => __( 'Revenue', 'learnomy' ),
			);
		}
		foreach ( $stats as $stat ) :
			$icon  = $stat['icon'];
			$count = $stat['count'];
			$label = $stat['label'];
			include \Learnomy\template_path( 'partials/stat-card.php' );
		endforeach;
		?>
	</div>

	<?php
	foreach ( $lrn_widgets as $lrn_widget ) {
		\Learnomy\Services\Dashboard_Widget_Registry::render( $lrn_widget, $lrn_dashboard_ctx );
	}
	?>

		</div><!-- .lrn-account-layout__content -->
	</div><!-- .lrn-account-layout -->

</main>
