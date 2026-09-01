<?php
/**
 * Admin grading inbox — submitted quiz attempts awaiting manual grading.
 *
 * @package Learnomy
 * @var object[]              $pending        Queue rows (attempt_id, quiz_id, quiz_title, user_id, submitted_at, ungraded_count, graded_at, graded_count).
 * @var int                   $total          Total attempts in this view (scoped).
 * @var int                   $total_pages    Total pages.
 * @var int                   $paged          Current page.
 * @var int                   $per_page       Page size.
 * @var string                $status         'pending' or 'graded'.
 * @var int                   $course_id      Active course filter (0 = all).
 * @var array[]               $filter_courses Courses offered in the filter (id, title).
 * @var array<int,\WP_User|false> $students_map Pre-fetched students keyed by user id.
 */

defined( 'ABSPATH' ) || exit;

$base_url       = admin_url( 'admin.php?page=learnomy-grading' );
$pending        = isset( $pending ) && is_array( $pending ) ? $pending : array();
$students_map   = isset( $students_map ) && is_array( $students_map ) ? $students_map : array();
$filter_courses = isset( $filter_courses ) && is_array( $filter_courses ) ? $filter_courses : array();
$course_id      = isset( $course_id ) ? (int) $course_id : 0;
$status         = isset( $status ) ? (string) $status : 'pending';
$is_graded_view = 'graded' === $status;
$date_format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

// Every link on this page keeps the other filter's state, so switching tab does
// not silently drop the course the grader chose (and the reverse).
$lrn_view_url = static function ( string $for_status, int $for_course ) use ( $base_url ): string {
	$args = array();
	if ( 'pending' !== $for_status ) {
		$args['status'] = $for_status;
	}
	if ( $for_course > 0 ) {
		$args['course_id'] = $for_course;
	}
	return $args ? add_query_arg( $args, $base_url ) : $base_url;
};

$paged_base_url = $lrn_view_url( $status, $course_id );
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="clipboard-check" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Grading', 'learnomy' ); ?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php esc_html_e( 'Attempts waiting on manual grading (essay and other reviewed answers). Grade them so the student\'s result and certificate can finalize.', 'learnomy' ); ?>
			</p>
		</div>
	</div>

	<hr class="wp-header-end">

	<ul class="subsubsub">
		<li>
			<a href="<?php echo esc_url( $lrn_view_url( 'pending', $course_id ) ); ?>"<?php echo $is_graded_view ? '' : ' class="current" aria-current="page"'; ?>>
				<?php esc_html_e( 'Awaiting grading', 'learnomy' ); ?>
			</a> |
		</li>
		<li>
			<a href="<?php echo esc_url( $lrn_view_url( 'graded', $course_id ) ); ?>"<?php echo $is_graded_view ? ' class="current" aria-current="page"' : ''; ?>>
				<?php esc_html_e( 'Graded', 'learnomy' ); ?>
			</a>
		</li>
	</ul>

	<div class="lrn-admin-filters">
		<span class="lrn-admin-filter-count">
			<?php
			if ( $is_graded_view ) {
				printf(
					/* translators: %s: formatted count of graded attempts */
					esc_html( _n( '%s graded attempt', '%s graded attempts', (int) $total, 'learnomy' ) ),
					esc_html( number_format_i18n( (int) $total ) )
				);
			} else {
				printf(
					/* translators: %s: formatted count of attempts awaiting grading */
					esc_html( _n( '%s attempt awaiting grading', '%s attempts awaiting grading', (int) $total, 'learnomy' ) ),
					esc_html( number_format_i18n( (int) $total ) )
				);
			}
			?>
		</span>

		<?php if ( ! empty( $filter_courses ) ) : ?>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="lrn-admin-filter-form">
				<input type="hidden" name="page" value="learnomy-grading">
				<?php if ( $is_graded_view ) : ?>
					<input type="hidden" name="status" value="graded">
				<?php endif; ?>
				<label class="screen-reader-text" for="lrn-grading-course"><?php esc_html_e( 'Filter by course', 'learnomy' ); ?></label>
				<select name="course_id" id="lrn-grading-course">
					<option value="0"><?php esc_html_e( 'All courses', 'learnomy' ); ?></option>
					<?php foreach ( $filter_courses as $lrn_course ) : ?>
						<option value="<?php echo absint( $lrn_course['id'] ); ?>" <?php selected( $course_id, (int) $lrn_course['id'] ); ?>>
							<?php echo esc_html( (string) $lrn_course['title'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="lrn-admin-btn lrn-admin-btn--sm"><?php esc_html_e( 'Filter', 'learnomy' ); ?></button>
			</form>
		<?php endif; ?>
	</div>

	<div class="lrn-admin-table-wrap">
	<table class="lrn-admin-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Student', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Quiz', 'learnomy' ); ?></th>
				<th scope="col">
					<?php echo $is_graded_view ? esc_html__( 'Graded on', 'learnomy' ) : esc_html__( 'Submitted', 'learnomy' ); ?>
				</th>
				<th scope="col">
					<?php echo $is_graded_view ? esc_html__( 'Graded', 'learnomy' ) : esc_html__( 'Pending', 'learnomy' ); ?>
				</th>
				<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $pending ) ) : ?>
				<tr>
					<td colspan="6" class="lrn-no-items">
						<?php
						$lrn_empty_state = $is_graded_view
							? array(
								'icon'        => 'clipboard-check',
								'title'       => __( 'Nothing graded yet', 'learnomy' ),
								'description' => __( 'Attempts you have graded by hand appear here, so you can look back at a score or the feedback you left.', 'learnomy' ),
							)
							: array(
								'icon'        => 'clipboard-check',
								'title'       => __( 'Nothing to grade', 'learnomy' ),
								'description' => __( 'When a student submits a quiz containing essay or other manually-graded questions, the attempt appears here for review.', 'learnomy' ),
							);
						include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $pending as $lrn_row ) :
					$lrn_student   = $students_map[ (int) $lrn_row->user_id ] ?? false;
					$lrn_grade_url = add_query_arg(
						array(
							'page'    => 'learnomy-grading',
							'attempt' => (int) $lrn_row->attempt_id,
						),
						admin_url( 'admin.php' )
					);
					?>
					<tr>
						<td>
							<?php if ( $lrn_student ) : ?>
								<div class="lrn-user-cell">
									<?php echo get_avatar( $lrn_student->ID, 28, '', '', array( 'class' => 'lrn-avatar lrn-avatar--sm' ) ); ?>
									<span><?php echo esc_html( $lrn_student->display_name ); ?></span>
								</div>
							<?php else : ?>
								<span class="lrn-muted"><?php esc_html_e( 'Deleted user', 'learnomy' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $lrn_row->quiz_title ); ?></td>
						<td>
							<?php
							// The graded view is ordered by when it was graded, so it
							// shows that date — a column sorted by one value and
							// displaying another reads as broken sorting.
							$lrn_date = $is_graded_view
								? (string) ( $lrn_row->graded_at ?? '' )
								: (string) ( $lrn_row->submitted_at ?? '' );
							echo '' !== $lrn_date
								? esc_html( \Learnomy\date_label( $lrn_date, '—', $date_format ) )
								: '<span class="lrn-muted">&mdash;</span>';
							?>
						</td>
						<td>
							<?php
							$lrn_count = $is_graded_view
								? (int) ( $lrn_row->graded_count ?? 0 )
								: (int) ( $lrn_row->ungraded_count ?? 0 );
							printf(
								/* translators: %s: number of responses */
								esc_html( _n( '%s response', '%s responses', $lrn_count, 'learnomy' ) ),
								esc_html( number_format_i18n( $lrn_count ) )
							);
							?>
						</td>
						<td>
							<?php
							// A graded-view row can still owe work when only SOME of
							// its responses were graded by hand, so the badge reads
							// the row rather than the tab.
							$lrn_still_pending = (int) ( $lrn_row->ungraded_count ?? 0 ) > 0;
							$lrn_grade_badge   = $lrn_still_pending
								? \Learnomy\Admin\UX::status_badge( 'pending', __( 'Needs grading', 'learnomy' ) )
								: \Learnomy\Admin\UX::status_badge( 'active', __( 'Graded', 'learnomy' ) );
							echo $lrn_grade_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
						<td>
							<?php
							$lrn_grade_row_actions = array(
								array(
									'label'   => $lrn_still_pending ? __( 'Grade', 'learnomy' ) : __( 'Review', 'learnomy' ),
									'href'    => $lrn_grade_url,
									'icon'    => $lrn_still_pending ? 'pencil' : 'eye',
									'primary' => true,
								),
							);

							/**
							 * Row actions on the grading inbox.
							 *
							 * The seam exists because Pro's rubric-grading screen was
							 * registered as a HIDDEN page reachable only by hand-typing
							 * `admin.php?page=learnomy-rubric-grading&quiz_id=N`. Rubrics
							 * could be authored and then never used, because nothing in the
							 * product linked to the screen that uses them. Pro now attaches
							 * "Grade with rubric" here, next to the thing it is an
							 * alternative to.
							 *
							 * @param array  $actions Row actions (see UX::row_actions()).
							 * @param object $row     Pending row: attempt_id, quiz_id, quiz_title, user_id, ungraded_count.
							 */
							$lrn_grade_row_actions = (array) apply_filters(
								'learnomy_grading_row_actions',
								$lrn_grade_row_actions,
								$lrn_row
							);

							$lrn_grade_actions = \Learnomy\Admin\UX::row_actions( $lrn_grade_row_actions );
							echo $lrn_grade_actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
		'base_url' => $paged_base_url,
	);
	require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
	?>
</div>
