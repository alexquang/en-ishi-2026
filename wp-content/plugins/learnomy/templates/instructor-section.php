<?php
/**
 * Generic instructor section page (My Courses / Students / Earnings …).
 *
 * One template for every instructor rail section: it reads the current route,
 * reuses the SAME Instructor_Dashboard_View_Data resolver + the shared context
 * partial, and renders only that section's widgets in the teaching shell. The
 * long single dashboard is thus split across rail sections with zero duplicated
 * data logic and zero new widgets — the same registry, filtered by section.
 *
 * View-data: provided by Instructor_Dashboard_View_Data (same as the dashboard).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_route = (string) get_query_var( 'learnomy_route' );

$lrn_sections = array(
	'instructor-courses'  => array(
		'section'  => 'courses',
		'icon'     => 'graduation-cap',
		/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
		'title'    => sprintf( __( 'My %s', 'learnomy' ), \Learnomy\course_label( true ) ),
		// Says what the table now shows. It promised "and revenue" while the
		// Revenue column was a hardcoded N/A, and kept promising it after the
		// column was removed (Basecamp 10171602987). Earnings owns money.
		'subtitle' => __( 'Your courses, their status, and how many students are in each.', 'learnomy' ),
		'empty'    => __( 'You have not created any courses yet.', 'learnomy' ),
	),
	'instructor-students' => array(
		'section'  => 'students',
		'icon'     => 'users',
		'title'    => \Learnomy\student_label( true ),
		'subtitle' => __( 'Who needs attention: at-risk learners, Q&A, reviews, and recent enrollments.', 'learnomy' ),
		'empty'    => __( 'No student activity to show yet.', 'learnomy' ),
	),
	'instructor-earnings' => array(
		'section'  => 'earnings',
		'icon'     => 'wallet',
		'title'    => __( 'Earnings', 'learnomy' ),
		'subtitle' => __( 'Your revenue, available balance, payouts, and trend.', 'learnomy' ),
		'empty'    => __( 'No earnings to show yet.', 'learnomy' ),
	),
);

// Hide the Earnings rail for instructors with no revenue share — a wall of
// $0.00 reads as broken or as a payout dispute. Filterable per user so
// integrations can override (e.g. show during onboarding, hide for a cohort).
$lrn_show_earnings = (bool) apply_filters(
	'learnomy_show_instructor_earnings',
	\Learnomy\Services\Commission_Service::instructor_participates_in_revenue( get_current_user_id() ),
	get_current_user_id()
);
if ( ! $lrn_show_earnings ) {
	unset( $lrn_sections['instructor-earnings'] );
}

/**
 * Filter the instructor rail sections so a Pro extension can register its own
 * section (route key => [section, icon, title, subtitle, empty]) and have it
 * render in this shared shell — e.g. Cohorts. Keyed by the route name; the
 * 'section' value is what Dashboard_Widget_Registry filters widgets by.
 *
 * @param array<string,array> $sections Route key => section config.
 */
$lrn_sections = (array) apply_filters( 'learnomy_instructor_sections', $lrn_sections );

$lrn_sec = $lrn_sections[ $lrn_route ] ?? array(
	'section'  => 'overview',
	'icon'     => 'layout-dashboard',
	'title'    => \Learnomy\instructor_label(),
	'subtitle' => '',
	'empty'    => __( 'Nothing to show yet.', 'learnomy' ),
);

$lrn_ctx_route = $lrn_route;
require \Learnomy\template_path( 'partials/instructor-dashboard-ctx.php' );
$lrn_widgets = \Learnomy\Services\Dashboard_Widget_Registry::for_surface( 'instructor', $lrn_dashboard_ctx, $lrn_sec['section'] );
?>
<main class="lrn-container lrn-dashboard lrn-instructor-section" id="lrn-instructor-section">

	<?php
	$breadcrumb_items = array(
		array(
			'label' => sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s Dashboard', 'learnomy' ), \Learnomy\instructor_label() ),
			'href'  => \Learnomy\route_url( 'instructor-dashboard' ),
			'icon'  => 'layout-dashboard',
		),
		array( 'label' => $lrn_sec['title'] ),
	);
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-account-layout">
		<?php
		$instructor_nav_active = $lrn_route;
		require \Learnomy\template_path( 'partials/instructor-nav.php' );
		?>
		<div class="lrn-account-layout__content">

			<?php
			$account_page = array(
				'icon'     => $lrn_sec['icon'],
				'title'    => $lrn_sec['title'],
				'subtitle' => $lrn_sec['subtitle'],
			);

			/*
			 * Create Course on the My Courses page (Basecamp 10171356663).
			 *
			 * The page did have a create affordance, but only in the EMPTY
			 * state - once the instructor owned one course the table replaced
			 * it and the only remaining Create was on the dashboard header, a
			 * page away. So it read as absent to everyone except a brand-new
			 * instructor, and anyone testing on a fresh account would have seen
			 * the button and called the card false.
			 *
			 * The header partial has always had an actions slot; this page just
			 * never filled it.
			 *
			 * The URL comes from the dashboard ctx, which resolves
			 * `learnomy_instructor_create_course_url` - so Free lands on the
			 * wp-admin editor and a site running Pro's front-end builder lands
			 * on /instructor/create-course/. Hardcoding either destination
			 * strands the other configuration. Reusing the ctx value adds no
			 * fourth copy of that filter call; there are already three.
			 */
			if ( 'instructor-courses' === $lrn_route ) {
				$lrn_create_url = (string) ( $lrn_dashboard_ctx['view_data']['create_url'] ?? '' );
				if ( '' !== $lrn_create_url ) {
					$account_page['actions'] = sprintf(
						'<a class="lrn-btn lrn-btn--primary" href="%s">%s</a>',
						esc_url( $lrn_create_url ),
						esc_html(
							sprintf(
								/* translators: %s: singular Course label */
								__( 'Create %s', 'learnomy' ),
								\Learnomy\course_label()
							)
						)
					);
				}
			}

			require \Learnomy\template_path( 'partials/account-page-header.php' );

			if ( empty( $lrn_widgets ) ) :
				?>
				<div class="lrn-empty-state">
					<i data-lucide="<?php echo esc_attr( (string) $lrn_sec['icon'] ); ?>" class="lrn-empty-state__icon" aria-hidden="true"></i>
					<h3 class="lrn-empty-state__title"><?php echo esc_html( (string) $lrn_sec['empty'] ); ?></h3>
				</div>
				<?php
			else :
				foreach ( $lrn_widgets as $lrn_widget ) {
					// On a dedicated section page the page header already states the
					// section title, so a widget whose heading repeats it would read
					// as a double heading ("My Courses" over "My Courses"). Drop the
					// duplicate widget heading; distinct widget titles in the same
					// section (e.g. "Revenue Trend" under Earnings) are kept as
					// sub-headings.
					if ( isset( $lrn_widget['title'] ) && $lrn_widget['title'] === $lrn_sec['title'] ) {
						$lrn_widget['title'] = '';
					}
					\Learnomy\Services\Dashboard_Widget_Registry::render( $lrn_widget, $lrn_dashboard_ctx );
				}
			endif;
			?>

		</div><!-- .lrn-account-layout__content -->
	</div><!-- .lrn-account-layout -->

</main>
