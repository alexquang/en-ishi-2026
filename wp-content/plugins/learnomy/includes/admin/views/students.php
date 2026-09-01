<?php
/**
 * Admin students list view.
 *
 * @package Learnomy
 * @var \WP_User[] $students    Students from WP_User_Query.
 * @var int        $total       Total student count.
 * @var int        $total_pages Total pages.
 * @var int        $paged       Current page.
 * @var string     $search      Current search string.
 */

defined( 'ABSPATH' ) || exit;

$base_url = admin_url( 'admin.php?page=learnomy-students' );

// Decorate the whole page in THREE batched queries instead of ~6 per student
// (the prior loop ran two Enrollment::count() calls + get_distinct_for_user +
// a Course::find per course — an N+1 that hit 100+ queries at 20 rows/page).
$lrn_student_ids = array_map( static fn( $s ) => (int) $s->ID, $students );
// $scope_course_ids, not null. Both helpers have ALWAYS taken this argument and
// this view has never passed it, so the roster was the right people with the
// wrong numbers: "61 courses" beside three course titles belonging to other
// teachers, and a completion percentage computed across all of them. The
// front-end twin (Instructor_Students_View_Data) passes it correctly, which is
// why /instructor/students/ read "Enrolled 1" for the same student this page
// showed as 61.
$lrn_enroll_counts = \Learnomy\Models\Enrollment::counts_for_users( $lrn_student_ids, $scope_course_ids );
if ( ! empty( $lrn_student_ids ) ) {
	// Prime the user-meta cache so the per-row learnomy_last_active reads hit
	// cache instead of issuing one query each.
	update_meta_cache( 'user', $lrn_student_ids );
}
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="graduation-cap" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Students', 'learnomy' ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a id="lrn-export-students" class="lrn-admin-btn"
				href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'learnomy/v1/admin/students/export' ) ) ); ?>">
				<i data-lucide="download"></i>
				<?php esc_html_e( 'Export CSV', 'learnomy' ); ?>
			</a>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<?php if ( ! empty( $course_row ) ) : ?>
		<div class="lrn-admin-filter-chip">
			<?php
			printf(
				/* translators: %s: course title */
				esc_html__( 'Showing students enrolled in %s.', 'learnomy' ),
				'<strong>' . esc_html( $course_row->title ) . '</strong>'
			);
			?>
			<a href="<?php echo esc_url( $base_url ); ?>" class="lrn-admin-filter-chip__clear">
				<?php esc_html_e( 'Show all students', 'learnomy' ); ?>
			</a>
		</div>
	<?php endif; ?>

	<!-- Search -->
	<div class="lrn-admin-filters">
		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="lrn-search-form">
			<input type="hidden" name="page" value="learnomy-students">
			<?php if ( ! empty( $course_row ) ) : ?>
				<input type="hidden" name="course_id" value="<?php echo absint( $course_row->id ); ?>">
			<?php endif; ?>
			<input type="search" name="s" aria-label="<?php esc_attr_e( 'Search', 'learnomy' ); ?>" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search by name or email…', 'learnomy' ); ?>" class="regular-text">
			<button type="submit" class="lrn-admin-btn lrn-admin-btn--primary"><?php esc_html_e( 'Search', 'learnomy' ); ?></button>
			<?php if ( $search ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'course_id', $course_id ?? 0, $base_url ) ); ?>" class="lrn-admin-btn">
					<?php esc_html_e( 'Clear', 'learnomy' ); ?>
				</a>
			<?php endif; ?>
		</form>
		<span class="lrn-admin-filter-count">
			<?php
			printf(
				/* translators: %s: formatted number */
				esc_html__( '%s students', 'learnomy' ),
				esc_html( number_format_i18n( $total ) )
			);
			?>
		</span>
	</div>

	<!-- Students table -->
	<div class="lrn-admin-table-wrap">
	<table class="lrn-admin-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Student', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Enrolled Courses', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Completion Rate', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Last Active', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Joined', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $students ) ) : ?>
				<tr>
					<td colspan="6" class="lrn-no-items">
						<?php
						$lrn_empty_state = array(
							'icon'        => 'users',
							'title'       => __( 'No students yet', 'learnomy' ),
							'description' => __( 'Students appear here as soon as anyone enrolls in a course on your site.', 'learnomy' ),
						);
						include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $students as $student ) :
					$enrolled_count  = (int) ( $lrn_enroll_counts[ $student->ID ]['enrolled'] ?? 0 );
					$completed_count = (int) ( $lrn_enroll_counts[ $student->ID ]['completed'] ?? 0 );
					$completion_rate = $enrolled_count > 0 ? round( ( $completed_count / $enrolled_count ) * 100 ) : 0;
					$last_active     = get_user_meta( $student->ID, 'learnomy_last_active', true );

					// Course titles used to be listed here (Basecamp #9925101779, "show
					// what the student actually bought, not just a number"). Reversed by
					// the owner on 2026-08-06: on real title lengths it made one student
					// several lines tall and cost the roster its scannability, which is
					// worth more than the preview. The count stays; Enrollments on the
					// detail page carries the full list (Basecamp 10171596010).
					$lrn_detail_url = admin_url( 'admin.php?page=learnomy-student-detail&user_id=' . absint( $student->ID ) );
					?>
					<tr>
						<td class="column-student">
							<div class="lrn-user-cell">
								<?php echo get_avatar( $student->ID, 32, '', '', array( 'class' => 'lrn-avatar lrn-avatar--sm' ) ); ?>
								<span class="lrn-user-cell__name lrn-user-cell__name--stacked">
									<a href="<?php echo esc_url( $lrn_detail_url ); ?>" class="lrn-admin-table__primary">
										<strong><?php echo esc_html( $student->display_name ); ?></strong>
									</a>
									<a href="mailto:<?php echo esc_attr( $student->user_email ); ?>" class="lrn-user-cell__email">
										<?php echo esc_html( $student->user_email ); ?>
									</a>
								</span>
							</div>
						</td>
						<td class="lrn-students-courses-cell">
							<?php if ( $enrolled_count > 0 ) : ?>
								<span class="lrn-muted lrn-text-sm">
									<?php
									printf(
										/* translators: %s: number of enrolled courses */
										esc_html( _n( '%s course', '%s courses', $enrolled_count, 'learnomy' ) ),
										esc_html( number_format_i18n( $enrolled_count ) )
									);
									?>
								</span>
								<?php
								/*
								 * The count, not the titles. This cell used to list up to
								 * three course titles plus a "+N more", which on real title
								 * lengths made one student several lines tall and pushed the
								 * roster off the screen. The count says the same thing, and
								 * the Enrollments section on the student detail page lists
								 * every course in full (Basecamp 10171596010).
								 */
								?>
							<?php else : ?>
								<span class="lrn-muted"><?php esc_html_e( 'No enrollments', 'learnomy' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<span class="lrn-progress-label"><?php echo esc_html( $completion_rate ); ?>%</span>
						</td>
						<td>
							<?php if ( $last_active ) : ?>
								<?php echo esc_html( date_i18n( get_option( 'date_format' ), (int) $last_active ) ); ?>
							<?php else : ?>
								<span class="lrn-muted"><?php esc_html_e( 'Never', 'learnomy' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( \Learnomy\date_label( $student->user_registered, '—', get_option( 'date_format' ) ) ); ?></td>
						<td>
							<?php
							$lrn_row_actions = array(
								array(
									'label'   => __( 'View', 'learnomy' ),
									'href'    => $lrn_detail_url,
									'icon'    => 'eye',
									'primary' => true,
								),
								array(
									'label'  => __( 'Remove', 'learnomy' ),
									'icon'   => 'trash-2',
									'danger' => true,
									'attrs'  => array(
										'class'         => 'lrn-action-link lrn-action-link--danger',
										'data-action'   => 'learnomy_remove_enrollment',
										'data-id'       => absint( $student->ID ),
										'data-id-field' => 'user_id',
										'data-confirm'  => __( 'Remove this student? Their course enrollments will be deleted and they will no longer appear in the Students list. The WordPress user account stays. They can re-enroll later.', 'learnomy' ),
									),
								),
							);
							echo \Learnomy\Admin\UX::row_actions( $lrn_row_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	</div>

	<?php
	$lrn_pagination = array(
		'current'  => (int) $paged,
		'total'    => (int) $total_pages,
		'results'  => (int) $total,
		'per_page' => isset( $per_page ) ? (int) $per_page : 20,
		'base_url' => $base_url,
	);
	require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
	?>

</div><!-- /.learnomy-admin -->
