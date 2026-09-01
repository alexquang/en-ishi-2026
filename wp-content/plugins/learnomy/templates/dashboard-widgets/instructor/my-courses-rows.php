<?php
/**
 * "My Courses" table rows — one <tr> per course.
 *
 * Shared by the My Courses widget (initial server render) AND the REST
 * endpoint that pages the list (GET courses/instructor-page), so the row markup
 * lives in exactly one place — no row HTML in JS, matching the account-courses
 * pattern.
 *
 * Locals: $my_courses (object[])
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

foreach ( $my_courses as $course ) :
	$edit_url    = apply_filters( 'learnomy_instructor_course_edit_url', admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . (int) $course->id ), (int) $course->id );
	$view_url    = \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );
	$status_map  = array(
		'published' => 'lrn-badge--green',
		'draft'     => 'lrn-badge--amber',
		'archived'  => 'lrn-badge--red',
	);
	$badge_class = $status_map[ $course->status ] ?? 'lrn-badge--blue';
	?>
	<tr>
		<td><strong><?php echo esc_html( $course->title ); ?></strong></td>
		<td><span class="lrn-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( ucfirst( $course->status ) ); ?></span></td>
		<td><?php echo esc_html( number_format_i18n( (int) $course->student_count ) ); ?></td>
		<?php
		/*
		 * Rating and Revenue cells removed with their headers (Basecamp
		 * 10171602987). Keep this row's column count identical to
		 * my-courses.php: this partial is also rendered standalone by
		 * Courses_Controller for AJAX refresh, so a mismatch shows up only
		 * after an in-place update, which is the hardest kind to notice.
		 */
		?>
		<td class="lrn-table__actions">
			<?php
			$action_icon  = 'pencil';
			$action_label = __( 'Edit', 'learnomy' );
			$action_title = null;
			$action_href  = $edit_url;
			$action_aria  = sprintf( /* translators: %s: course title */ __( 'Edit %s', 'learnomy' ), $course->title );
			$action_class = '';
			$action_attrs = array();
			include \Learnomy\template_path( 'partials/lrn-action.php' );

			$action_icon  = 'eye';
			$action_label = __( 'View', 'learnomy' );
			$action_title = null;
			$action_href  = $view_url;
			$action_aria  = sprintf( /* translators: %s: course title */ __( 'View %s', 'learnomy' ), $course->title );
			$action_class = '';
			$action_attrs = array(
				'target' => '_blank',
				'rel'    => 'noopener',
			);
			include \Learnomy\template_path( 'partials/lrn-action.php' );
			?>
		</td>
	</tr>
	<?php
endforeach;
