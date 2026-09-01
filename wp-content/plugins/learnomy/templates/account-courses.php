<?php
/**
 * My Courses — /account/courses/
 *
 * The dedicated, full course list: All / In Progress / Completed / Wishlist,
 * each paged 40 at a time with a "Load more" button (REST). Lives in the shared
 * account layout (vertical nav + content) so it stays linked to the rest of the
 * hub, while the dashboard home (/account/) keeps a lean overview.
 *
 * View-data (from Account_Courses_View_Data): $enrollments,
 * $in_progress_enrollments, $completed_enrollments, $wishlist_courses,
 * $enrollment_courses, $lrn_wishlist, $total_enrolled, $total_in_progress,
 * $total_completed, $total_wishlist, $courses_has_more, $courses_per_page.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
<main class="lrn-container lrn-account-courses" id="lrn-account-courses">

	<?php
	$breadcrumb_items = \Learnomy\account_breadcrumb( \Learnomy\course_label( true ) );
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-account-layout">
		<?php
		$account_nav_active = 'account-courses';
		require \Learnomy\template_path( 'partials/account-nav.php' );
		?>
		<div class="lrn-account-layout__content">

			<?php
			$account_page = array(
				'icon'     => 'graduation-cap',
				'title'    => sprintf( /* translators: %s: plural Course label */ __( 'My %s', 'learnomy' ), \Learnomy\course_label( true ) ),
				'subtitle' => __( 'Everything you are enrolled in, completed, or saved for later.', 'learnomy' ),
			);
			require \Learnomy\template_path( 'partials/account-page-header.php' );

			// Shared tabbed grid — All / In Progress / Completed / Wishlist, plus
			// any tabs Pro modules register. Paged 40 per tab with Load more.
			require \Learnomy\template_path( 'dashboard-widgets/student/enrollments.php' );
			?>

		</div><!-- .lrn-account-layout__content -->
	</div><!-- .lrn-account-layout -->

</main>
