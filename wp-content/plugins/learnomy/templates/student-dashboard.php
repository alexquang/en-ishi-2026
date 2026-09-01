<?php
/**
 * Student dashboard — header, stats, then a registry-driven widget loop.
 *
 * Every section below the stats row is contributed via the
 * `learnomy_dashboard_widgets` filter (see
 * includes/services/class-dashboard-widget-registry.php). Free's first-
 * party widgets are registered the same way Pro extensions register
 * theirs — this template has zero hardcoded sections.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// $view_data variables (injected by Template_Loader via Student_Dashboard_View_Data):
// $user_id, $user, $enrollments, $active_enrollments, $completed_enrollments,
// $in_progress_enrollments, $continue_enrollments, $celebrate_course, $certificates, $certificate_titles,
// $wishlist_courses, $enrollment_courses, $recent_attempts, $avg_quiz_score,
// $attempt_quizzes, $membership_sub, $membership_plan, $announcements,
// $announcement_courses, $announcement_actors, $recent_progress, $progress_lessons,
// $total_enrolled, $total_completed, $total_in_progress, $total_wishlist,
// $resume_target

$lrn_dashboard_ctx = array(
	'surface'   => 'student',
	'route'     => 'student-dashboard',
	'user_id'   => (int) $user_id,
	'user'      => $user,
	'view_data' => array(
		'enrollments'             => $enrollments,
		'active_enrollments'      => $active_enrollments,
		'completed_enrollments'   => $completed_enrollments,
		'in_progress_enrollments' => $in_progress_enrollments,
		'continue_enrollments'    => $continue_enrollments ?? $in_progress_enrollments,
		'continue_has_activity'   => $continue_has_activity ?? false,
		'celebrate_course'        => $celebrate_course,
		'certificates'            => $certificates,
		'certificate_titles'      => $certificate_titles,
		'wishlist_courses'        => $wishlist_courses,
		'lrn_wishlist'            => $lrn_wishlist,
		'enrollment_courses'      => $enrollment_courses,
		'recent_attempts'         => $recent_attempts,
		'avg_quiz_score'          => $avg_quiz_score,
		'attempt_quizzes'         => $attempt_quizzes,
		'membership_sub'          => $membership_sub,
		'membership_plan'         => $membership_plan,
		'announcements'           => $announcements,
		'announcement_courses'    => $announcement_courses,
		'announcement_actors'     => $announcement_actors,
		'recent_progress'         => $recent_progress,
		'progress_lessons'        => $progress_lessons,
		'total_enrolled'          => $total_enrolled,
		'total_completed'         => $total_completed,
		'total_in_progress'       => $total_in_progress,
		'total_wishlist'          => $total_wishlist,
		'resume_target'           => $resume_target ?? null,
	),
);

$lrn_widgets = \Learnomy\Services\Dashboard_Widget_Registry::for_surface( 'student', $lrn_dashboard_ctx );
?>
<main class="lrn-container lrn-dashboard"
	id="lrn-dashboard-main"
	<?php if ( $celebrate_course ) : ?>
		data-celebrate="<?php echo esc_attr( $celebrate_course ); ?>"
	<?php endif; ?>
>

	<?php
	$breadcrumb_items = \Learnomy\account_breadcrumb();
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-account-layout">
		<?php
		$account_nav_active = 'student-dashboard';
		require \Learnomy\template_path( 'partials/account-nav.php' );
		?>
		<div class="lrn-account-layout__content">

	<?php
	// One-shot welcome banner — router sets ?registered=1 after granting the
	// lrn_student role to a logged-in non-student (Basecamp #9924598683).
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! empty( $_GET['registered'] ) ) :
		?>
		<div class="lrn-banner lrn-banner--success" role="status">
			<strong><?php esc_html_e( 'You are now registered as a student.', 'learnomy' ); ?></strong>
			<?php esc_html_e( 'Browse your courses below or pick something new from the catalog.', 'learnomy' ); ?>
		</div>
	<?php endif; ?>

	<?php
	$account_page = array(
		'icon'     => 'graduation-cap',
		/* translators: %s: student display name */
		'title'    => sprintf( __( 'Welcome back, %s', 'learnomy' ), $user->display_name ),
		'subtitle' => __( 'Here is your learning progress at a glance.', 'learnomy' ),
	);
	require \Learnomy\template_path( 'partials/account-page-header.php' );
	?>

	<div class="lrn-dashboard__stats lrn-stats-row">
		<?php
		$stats = array(
			array(
				'icon'  => 'graduation-cap',
				'count' => $total_enrolled,
				/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
				'label' => sprintf( __( '%s Enrolled', 'learnomy' ), \Learnomy\course_label( true ) ),
			),
			array(
				'icon'  => 'clock',
				'count' => $total_in_progress,
				'label' => __( 'In Progress', 'learnomy' ),
			),
			array(
				'icon'  => 'check-circle',
				'count' => $total_completed,
				'label' => __( 'Completed', 'learnomy' ),
			),
			array(
				'icon'  => 'bar-chart-3',
				// A dash, not "0%", when nothing has been sat yet - matching the
				// no-data convention the account and membership screens use.
				'count' => null === $avg_quiz_score ? '—' : $avg_quiz_score . '%',
				'label' => __( 'Avg Quiz Score', 'learnomy' ),
			),
		);
		foreach ( $stats as $stat ) :
			$icon  = $stat['icon'];
			$count = $stat['count'];
			$label = $stat['label'];
			include \Learnomy\template_path( 'partials/stat-card.php' );
		endforeach;
		?>
	</div>

	<?php
	// Widget loop — every section below the stats row is a registered
	// widget. Free first-party + Pro modules + any third party use the
	// same filter (`learnomy_dashboard_widgets`), so feature-off paths
	// silently drop widgets and nothing is hardcoded here.
	foreach ( $lrn_widgets as $lrn_widget ) {
		\Learnomy\Services\Dashboard_Widget_Registry::render( $lrn_widget, $lrn_dashboard_ctx );
	}
	?>

		</div><!-- .lrn-account-layout__content -->
	</div><!-- .lrn-account-layout -->

</main>
