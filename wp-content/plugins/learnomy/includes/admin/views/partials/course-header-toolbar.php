<?php
/**
 * Shared course header action toolbar.
 *
 * Rendered inside `.lrn-page-header__actions` on both the course detail hub
 * and the course edit form so the two screens share one set of navigation
 * buttons instead of each hand-rolling its own. The caller owns the wrapping
 * `.lrn-page-header__actions` element and may append screen-specific actions
 * (the detail hub adds the Pro hook + status toggle) after this include.
 *
 * The whole point of the shared partial: from the edit form you land back on
 * the course detail page in one click, without bouncing through All courses.
 *
 * @package Learnomy
 *
 * @var object|null $course             Course row when one is in context.
 * @var string      $lrn_header_screen  Current screen: 'detail' or 'edit'.
 */

defined( 'ABSPATH' ) || exit;

$lrn_screen = isset( $lrn_header_screen ) && 'edit' === $lrn_header_screen ? 'edit' : 'detail';
$lrn_course = ( isset( $course ) && is_object( $course ) ) ? $course : null;
$lrn_cid    = $lrn_course ? absint( $lrn_course->id ?? 0 ) : 0;
$lrn_slug   = $lrn_course ? (string) ( $lrn_course->slug ?? '' ) : '';

// "All courses" is role-aware: the wp-admin list is gated by manage_options,
// so an authoring instructor goes to their frontend dashboard instead of an
// inaccessible page. See Basecamp #9919741517.
$lrn_list_url = current_user_can( 'manage_options' )
	? admin_url( 'admin.php?page=learnomy-courses' )
	: \Learnomy\route_url( 'instructor-dashboard' );

$lrn_detail_url  = admin_url( 'admin.php?page=learnomy-course-detail&course_id=' . $lrn_cid );
$lrn_edit_url    = admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . $lrn_cid );
$lrn_builder_url = admin_url( 'admin.php?page=learnomy-course-builder&course_id=' . $lrn_cid );
$lrn_view_url    = '' !== $lrn_slug
	? \Learnomy\route_url( 'course', array( 'slug' => $lrn_slug ) )
	: '';
?>
<a class="lrn-admin-btn" href="<?php echo esc_url( $lrn_list_url ); ?>">
	<i data-lucide="arrow-left"></i>
	<?php esc_html_e( 'All courses', 'learnomy' ); ?>
</a>

<?php if ( $lrn_cid ) : ?>
	<?php
	// "Back to Course" targets learnomy-course-detail, which is registered
	// manage_options — so for an instructor it was a button that answered "Sorry,
	// you are not allowed to access this page" (Basecamp 10082715994). Same class
	// of bug the "All courses" link above already fixed (#9919741517).
	//
	// Nothing is lost by hiding it: the instructor still has Curriculum (which
	// they hold the cap for) and View Course, which between them cover the same
	// "get me back to this course" intent. A button that 403s is worse than no
	// button.
	?>
	<?php if ( 'edit' === $lrn_screen && current_user_can( 'manage_options' ) ) : ?>
		<a class="lrn-admin-btn" href="<?php echo esc_url( $lrn_detail_url ); ?>">
			<i data-lucide="book-open"></i>
			<?php esc_html_e( 'Back to Course', 'learnomy' ); ?>
		</a>
	<?php elseif ( 'edit' !== $lrn_screen ) : ?>
		<a class="lrn-admin-btn" href="<?php echo esc_url( $lrn_edit_url ); ?>">
			<i data-lucide="pencil"></i>
			<?php esc_html_e( 'Edit Course', 'learnomy' ); ?>
		</a>
	<?php endif; ?>

	<a class="lrn-admin-btn" href="<?php echo esc_url( $lrn_builder_url ); ?>">
		<i data-lucide="list-tree"></i>
		<?php esc_html_e( 'Curriculum', 'learnomy' ); ?>
	</a>

	<?php if ( $lrn_view_url ) : ?>
		<a class="lrn-admin-btn" href="<?php echo esc_url( $lrn_view_url ); ?>" target="_blank" rel="noopener">
			<i data-lucide="external-link"></i>
			<?php esc_html_e( 'View Course', 'learnomy' ); ?>
		</a>
	<?php endif; ?>
<?php endif; ?>
