<?php
/**
 * Instructor Students roster — /instructor/students/
 *
 * List mode: a searchable, paginated roster of every student across the
 * instructor's courses, each row drillable to a per-student record.
 * Detail mode (?student=N): that student's enrolments in the instructor's
 * courses. Data comes from Instructor_Students_View_Data; this template does
 * zero DB work.
 *
 * View-data: $mode, $students, $total, $page, $per_page, $search, $base_url,
 *            $student.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$mode          = isset( $mode ) ? (string) $mode : 'list';
$base_url      = isset( $base_url ) ? (string) $base_url : \Learnomy\route_url( 'instructor-students' );
$students      = isset( $students ) && is_array( $students ) ? $students : array();
$student       = isset( $student ) && is_array( $student ) ? $student : null;
$search        = isset( $search ) ? (string) $search : '';
$total         = isset( $total ) ? (int) $total : 0;
$inactive_days = isset( $inactive_days ) ? (int) $inactive_days : 0;
$page          = isset( $page ) ? max( 1, (int) $page ) : 1;
$per_page      = isset( $per_page ) ? max( 1, (int) $per_page ) : 20;
?>
<main class="lrn-container lrn-dashboard lrn-instructor-section" id="lrn-instructor-students">

	<?php
	$breadcrumb_items = array(
		array(
			'label' => sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s Dashboard', 'learnomy' ), \Learnomy\instructor_label() ),
			'href'  => \Learnomy\route_url( 'instructor-dashboard' ),
			'icon'  => 'layout-dashboard',
		),
		array( 'label' => \Learnomy\student_label( true ) ),
	);
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<div class="lrn-account-layout">
		<?php
		$instructor_nav_active = 'instructor-students';
		require \Learnomy\template_path( 'partials/instructor-nav.php' );
		?>
		<div class="lrn-account-layout__content">

			<?php if ( 'detail' === $mode && $student ) : ?>

				<p class="lrn-account-back">
					<a href="<?php echo esc_url( $base_url ); ?>">&larr; <?php esc_html_e( 'All students', 'learnomy' ); ?></a>
				</p>

				<div class="lrn-student-detail__header lrn-card">
					<?php echo $student['avatar']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() is safe core output. ?>
					<div class="lrn-student-detail__identity">
						<h1 class="lrn-student-detail__name"><?php echo esc_html( (string) $student['name'] ); ?></h1>
						<p class="lrn-student-detail__email"><?php echo esc_html( (string) $student['email'] ); ?></p>
						<p class="lrn-student-detail__meta">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: enrolled course count, 2: completed count */
									__( '%1$d enrolled · %2$d completed', 'learnomy' ),
									(int) $student['enrolled'],
									(int) $student['completed']
								)
							);
							?>
						</p>
					</div>
				</div>

				<h2 class="lrn-section-title"><?php esc_html_e( 'Enrolled in your courses', 'learnomy' ); ?></h2>

				<?php if ( empty( $student['rows'] ) ) : ?>
					<div class="lrn-empty-state">
						<i data-lucide="book-open" class="lrn-empty-state__icon" aria-hidden="true"></i>
						<h3 class="lrn-empty-state__title"><?php esc_html_e( 'No enrollments in your courses', 'learnomy' ); ?></h3>
					</div>
				<?php else : ?>
					<div class="lrn-table-wrap">
						<table class="lrn-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Course', 'learnomy' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Progress', 'learnomy' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Enrolled', 'learnomy' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $student['rows'] as $row ) : ?>
									<tr>
										<td>
											<a href="<?php echo esc_url( (string) $row['course_url'] ); ?>"><?php echo esc_html( (string) $row['course_title'] ); ?></a>
										</td>
										<td><?php echo esc_html( (int) $row['progress'] . '%' ); ?></td>
										<td>
											<?php if ( $row['completed'] ) : ?>
												<span class="lrn-badge lrn-badge--green"><?php esc_html_e( 'Completed', 'learnomy' ); ?></span>
											<?php else : ?>
												<span class="lrn-badge"><?php esc_html_e( 'In progress', 'learnomy' ); ?></span>
											<?php endif; ?>
										</td>
										<td>
											<?php
											$ts = $row['enrolled_at'] ? strtotime( (string) $row['enrolled_at'] ) : 0;
											echo esc_html( $ts ? date_i18n( (string) get_option( 'date_format' ), $ts ) : '—' );
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

			<?php elseif ( 'denied' === $mode ) : ?>

				<?php
				$account_page = array(
					'icon'     => 'users',
					'title'    => \Learnomy\student_label( true ),
					'subtitle' => '',
				);
				require \Learnomy\template_path( 'partials/account-page-header.php' );
				?>
				<div class="lrn-empty-state">
					<i data-lucide="lock" class="lrn-empty-state__icon" aria-hidden="true"></i>
					<h3 class="lrn-empty-state__title"><?php printf( esc_html( /* translators: %s: singular label for a student (e.g. "Learner"). */ __( '%s not found', 'learnomy' ) ), esc_html( \Learnomy\student_label() ) ); ?></h3>
					<p class="lrn-empty-state__desc"><?php esc_html_e( 'That student is not enrolled in any of your courses.', 'learnomy' ); ?></p>
				</div>

			<?php else : ?>

				<?php
				$account_page = array(
					'icon'     => 'users',
					'title'    => \Learnomy\student_label( true ),
					'subtitle' => __( 'Everyone enrolled across your courses. Search and open any student to see their progress.', 'learnomy' ),
				);
				require \Learnomy\template_path( 'partials/account-page-header.php' );
				?>

				<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="lrn-table-search" role="search">
					<label class="screen-reader-text" for="lrn-students-search"><?php esc_html_e( 'Search students', 'learnomy' ); ?></label>
					<input type="search" id="lrn-students-search" name="s" value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( 'Search by name or email', 'learnomy' ); ?>" class="lrn-input">
					<?php if ( $inactive_days > 0 ) : ?>
						<?php // Keep the filter through a search submit — the form is GET, so an unrepresented param would be dropped. ?>
						<input type="hidden" name="inactive" value="<?php echo esc_attr( (string) $inactive_days ); ?>">
					<?php endif; ?>
					<button type="submit" class="lrn-btn lrn-btn--secondary lrn-btn--sm"><?php esc_html_e( 'Search', 'learnomy' ); ?></button>
				</form>

				<?php if ( $inactive_days > 0 ) : ?>
					<?php
					// Visible, clearable filter state (Basecamp 10127571047). The
					// Engagement Alerts widget links here with ?inactive=N; a
					// filtered list with no visible filter reads as "where did my
					// students go". Clearing = the same URL without the param
					// (search preserved).
					$lrn_clear_url = '' !== $search ? add_query_arg( 's', rawurlencode( $search ), $base_url ) : $base_url;
					?>
					<p class="lrn-table-filter-note">
						<span class="lrn-badge">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of days with no activity. */
									__( 'Filtered: inactive %d+ days', 'learnomy' ),
									(int) $inactive_days
								)
							);
							?>
						</span>
						<a class="lrn-table-filter-note__clear" href="<?php echo esc_url( $lrn_clear_url ); ?>">
							<?php esc_html_e( 'Clear filter', 'learnomy' ); ?>
						</a>
					</p>
				<?php endif; ?>

				<?php if ( empty( $students ) ) : ?>
					<div class="lrn-empty-state">
						<i data-lucide="users" class="lrn-empty-state__icon" aria-hidden="true"></i>
						<h3 class="lrn-empty-state__title">
							<?php
							if ( $inactive_days > 0 ) {
								// A filtered-empty list is GOOD news and must say so —
								// "No students yet" under an active inactivity filter
								// reads as data loss, not as everyone being engaged.
								echo esc_html(
									sprintf(
										/* translators: %d: number of days with no activity. */
										__( 'Nobody has been inactive for %d+ days. Your students are keeping up.', 'learnomy' ),
										(int) $inactive_days
									)
								);
							} else {
								echo '' !== $search ? esc_html__( 'No students match your search', 'learnomy' ) : esc_html__( 'No students yet', 'learnomy' );
							}
							?>
						</h3>
						<?php if ( '' === $search ) : ?>
							<p class="lrn-empty-state__desc"><?php esc_html_e( 'When learners enroll in your courses they will appear here.', 'learnomy' ); ?></p>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<div class="lrn-table-wrap">
						<table class="lrn-table">
							<thead>
								<tr>
									<th scope="col"><?php echo esc_html( \Learnomy\student_label() ); ?></th>
									<th scope="col"><?php esc_html_e( 'Enrolled', 'learnomy' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Completed', 'learnomy' ); ?></th>
									<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'learnomy' ); ?></span></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $students as $s ) : ?>
									<tr>
										<td>
											<a class="lrn-admin-identity" href="<?php echo esc_url( (string) $s['detail_url'] ); ?>">
												<?php echo $s['avatar']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() is safe core output. ?>
												<span class="lrn-admin-identity__text">
													<span class="lrn-admin-identity__name"><?php echo esc_html( (string) $s['name'] ); ?></span>
													<span class="lrn-admin-identity__sub"><?php echo esc_html( (string) $s['email'] ); ?></span>
												</span>
											</a>
										</td>
										<td><?php echo esc_html( (string) (int) $s['enrolled'] ); ?></td>
										<td><?php echo esc_html( (string) (int) $s['completed'] ); ?></td>
										<td>
											<a class="lrn-btn lrn-btn--secondary lrn-btn--sm" href="<?php echo esc_url( (string) $s['detail_url'] ); ?>">
												<?php esc_html_e( 'View', 'learnomy' ); ?>
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<?php
					$total_pages = (int) ceil( $total / $per_page );
					if ( $total_pages > 1 ) :
						$pagination_url  = static function ( int $n ) use ( $search, $inactive_days ): string {
							// Build /instructor/students/page/N/ through the registry so
							// the link matches the emitted rewrite rule (no ?paged= 404).
							$url = \Learnomy\route_url( 'instructor-students', $n > 1 ? array( 'paged' => $n ) : array() );
							if ( '' !== $search ) {
								$url = add_query_arg( 's', $search, $url );
							}
							if ( $inactive_days > 0 ) {
								// Page 2 of a filtered list must stay filtered — a
								// dropped param here silently doubles the roster.
								$url = add_query_arg( 'inactive', $inactive_days, $url );
							}
							return esc_url_raw( $url );
						};
						$pagination_aria = sprintf( /* translators: %s: plural label for students (e.g. "Learners"). */ __( '%s pages', 'learnomy' ), \Learnomy\student_label( true ) );
						require \Learnomy\template_path( 'partials/pagination.php' );
					endif;
					?>
				<?php endif; ?>

			<?php endif; ?>

			<?php
			// The `students` SECTION widgets — Student Q&A and its siblings.
			//
			// Four widgets register with `'section' => 'students'`, but this
			// route renders a bespoke template instead of the shared
			// instructor-section.php shell that its sibling sections
			// (instructor-courses, instructor-earnings) use — and only that
			// shell calls the widget registry. So the widgets were registered,
			// their preconditions met, their route live, and still nothing ever
			// drew them: an instructor had no way to reach the Q&A inbox
			// (Basecamp 10163002943).
			//
			// Rendered BELOW the roster, because the roster is what this page
			// is named for; the widgets are the signals attached to it.
			$lrn_ctx_route = 'instructor-students';
			require \Learnomy\template_path( 'partials/instructor-dashboard-ctx.php' );
			$lrn_widgets = \Learnomy\Services\Dashboard_Widget_Registry::for_surface(
				'instructor',
				$lrn_dashboard_ctx,
				'students'
			);
			foreach ( $lrn_widgets as $lrn_widget ) {
				\Learnomy\Services\Dashboard_Widget_Registry::render( $lrn_widget, $lrn_dashboard_ctx );
			}
			?>

		</div><!-- .lrn-account-layout__content -->
	</div><!-- .lrn-account-layout -->

</main>
