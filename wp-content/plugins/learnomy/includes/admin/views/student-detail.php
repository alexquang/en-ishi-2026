<?php
/**
 * Admin per-student detail view.
 *
 * Every Learnomy-specific record for one learner, drilled into from the
 * Students list. Mirrors the instructor-detail layout so the two read as one
 * product.
 *
 * @package Learnomy
 * @var \WP_User    $user            The student.
 * @var object[]    $enrollments     One row per enrolled course (lrn_enrollments).
 * @var array<int,string> $course_map course_id → title (batched).
 * @var object[]    $certificates    Earned certificates.
 * @var object[]    $attempts        Quiz attempts.
 * @var array<int,string> $quiz_map  quiz_id → title (batched).
 * @var object[]    $transactions    Payment history.
 * @var int         $enrolled_count  Distinct enrolled courses.
 * @var int         $completed_count Completed courses.
 * @var float       $total_spent     Lifetime completed spend (administrators only).
 * @var bool        $lrn_sees_whole_record True for an administrator; false when the
 *                                        record is narrowed to the viewer's courses.
 * @var string|int  $last_active     Last-active timestamp (user meta) or ''.
 * @var string      $currency        Store currency code.
 */

defined( 'ABSPATH' ) || exit;

$list_url        = admin_url( 'admin.php?page=learnomy-students' );
$edit_user_url   = get_edit_user_link( (int) $user->ID );
$completion_rate = $enrolled_count > 0 ? (int) round( ( $completed_count / $enrolled_count ) * 100 ) : 0;
$date_format     = get_option( 'date_format' );

// Management controls (grant/remove/reset) are admin-only; a non-admin
// instructor reaches this page scoped to their own students but sees it
// read-only.
$can_manage    = current_user_can( 'manage_options' );
$grant_courses = $grant_courses ?? array();

/**
 * Resolve a course title from the batched map, falling back to a stable
 * "#id" label so a deleted course never renders a blank cell.
 */
$lrn_course_title = static function ( $course_id ) use ( $course_map ) {
	$course_id = (int) $course_id;
	return $course_map[ $course_id ] ?? sprintf( '#%d', $course_id );
};
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<?php echo get_avatar( (int) $user->ID, 32, '', '', array( 'class' => 'lrn-avatar lrn-avatar--sm' ) ); ?>
				<?php echo esc_html( $user->display_name ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a class="lrn-admin-btn" href="<?php echo esc_url( $list_url ); ?>">
				<i data-lucide="arrow-left"></i>
				<?php esc_html_e( 'All students', 'learnomy' ); ?>
			</a>
			<?php if ( $edit_user_url ) : ?>
				<a class="lrn-admin-btn" href="<?php echo esc_url( $edit_user_url ); ?>">
					<i data-lucide="user-cog"></i>
					<?php esc_html_e( 'Edit WordPress user', 'learnomy' ); ?>
				</a>
			<?php endif; ?>
			<?php
			/**
			 * Pro extension point for per-student actions (e.g. "Add to Space"
			 * when the Spaces module is active). Renders inside the detail
			 * page header action cluster.
			 *
			 * @param int $student_id The student's user ID.
			 */
			do_action( 'learnomy_admin_student_detail_actions', (int) $user->ID );
			?>
			<?php if ( $can_manage ) : ?>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--danger"
						data-action="learnomy_remove_enrollment"
						data-user-id="<?php echo absint( $user->ID ); ?>"
						data-confirm="<?php esc_attr_e( 'Remove this student? Every course enrollment will be deleted and they will no longer appear in the Students list. The WordPress user account stays.', 'learnomy' ); ?>">
					<i data-lucide="user-minus"></i>
					<?php esc_html_e( 'Remove student', 'learnomy' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>

	<hr class="wp-header-end">

	<div class="lrn-admin-grid lrn-admin-grid--2">

		<!-- Profile -->
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Profile', 'learnomy' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Email', 'learnomy' ); ?></th>
					<td><a href="mailto:<?php echo esc_attr( $user->user_email ); ?>"><?php echo esc_html( $user->user_email ); ?></a></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Username', 'learnomy' ); ?></th>
					<td><?php echo esc_html( $user->user_login ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Joined', 'learnomy' ); ?></th>
					<td><?php echo esc_html( \Learnomy\date_label( $user->user_registered ?? null, '—', $date_format ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Last active', 'learnomy' ); ?></th>
					<td>
						<?php
						echo $last_active
							? esc_html( date_i18n( $date_format, (int) $last_active ) )
							: '<span class="lrn-muted">' . esc_html__( 'Never', 'learnomy' ) . '</span>';
						?>
					</td>
				</tr>
				<?php
				// Custom account fields (site-owner-defined) captured for this member.
				foreach ( ( new \Learnomy\Services\Custom_Fields() )->definitions() as $lrn_cf ) :
					$lrn_cf_val = (string) get_user_meta( (int) $user->ID, \Learnomy\Services\Custom_Fields::META_PREFIX . $lrn_cf['key'], true );
					if ( 'checkbox' === $lrn_cf['type'] ) {
						$lrn_cf_val = '' !== $lrn_cf_val ? __( 'Yes', 'learnomy' ) : __( 'No', 'learnomy' );
					}
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $lrn_cf['label'] ); ?></th>
						<td>
							<?php
							echo '' !== $lrn_cf_val
								? esc_html( $lrn_cf_val )
								: '<span class="lrn-muted">-</span>';
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</div>

		<!-- Learning summary -->
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Learning summary', 'learnomy' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Courses enrolled', 'learnomy' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( $enrolled_count ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Courses completed', 'learnomy' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: 1: completed count, 2: completion rate % */
							esc_html__( '%1$s (%2$s%%)', 'learnomy' ),
							esc_html( number_format_i18n( $completed_count ) ),
							esc_html( number_format_i18n( $completion_rate ) )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Certificates earned', 'learnomy' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( count( $certificates ) ) ); ?></td>
				</tr>
				<?php if ( $lrn_sees_whole_record ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Lifetime spend', 'learnomy' ); ?></th>
						<td><?php echo esc_html( $currency . ' ' . number_format_i18n( $total_spent, 2 ) ); ?></td>
					</tr>
				<?php endif; ?>
			</table>
		</div>
	</div>

	<!-- Grant course access (admin only) -->
	<?php if ( $can_manage ) : ?>
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Grant course access', 'learnomy' ); ?></h2>
			<p class="lrn-muted"><?php esc_html_e( 'Manually enroll this student in a course (source: manual). Manual grants bypass payment, so this works for paid and membership courses too.', 'learnomy' ); ?></p>
			<?php if ( empty( $grant_courses ) ) : ?>
				<p class="lrn-muted"><?php esc_html_e( 'The student is already enrolled in every published course.', 'learnomy' ); ?></p>
			<?php else : ?>
				<div class="lrn-grant-access" id="lrn-grant-access" data-user-id="<?php echo absint( $user->ID ); ?>">
					<label for="lrn-grant-course" class="screen-reader-text"><?php esc_html_e( 'Course', 'learnomy' ); ?></label>
					<select id="lrn-grant-course" class="regular-text">
						<option value=""><?php esc_html_e( 'Select a course…', 'learnomy' ); ?></option>
						<?php foreach ( $grant_courses as $lrn_grant_course ) : ?>
							<option value="<?php echo absint( $lrn_grant_course->id ); ?>"><?php echo esc_html( $lrn_grant_course->title ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-grant-access-btn">
						<i data-lucide="plus"></i>
						<?php esc_html_e( 'Grant access', 'learnomy' ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<!-- Enrollments -->
	<?php
	$lrn_enr_cols = $can_manage ? 7 : 6;
	// The table lists every enrolment INCLUDING the ones whose access was
	// removed, so its heading counts rows shown; the "Courses enrolled" summary
	// above counts live access only. Removing access moves that number and
	// flips this row's badge — the two together are how the teacher sees the
	// action land.
	$lrn_revoked_count = count( $enrollments ) - (int) $enrolled_count;
	?>
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<?php
			printf(
				/* translators: %s: number of enrollment rows listed */
				esc_html__( 'Course enrollments (%s)', 'learnomy' ),
				esc_html( number_format_i18n( count( $enrollments ) ) )
			);
			?>
		</h2>
		<?php if ( $lrn_revoked_count > 0 ) : ?>
			<p class="lrn-muted">
				<?php
				printf(
					/* translators: %s: number of enrollments whose access was removed or expired */
					esc_html(
						_n(
							'%s of these no longer has access. The record is kept for reporting and refunds, and can be restored.',
							'%s of these no longer have access. The records are kept for reporting and refunds, and can be restored.',
							$lrn_revoked_count,
							'learnomy'
						)
					),
					esc_html( number_format_i18n( $lrn_revoked_count ) )
				);
				?>
			</p>
		<?php endif; ?>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Course', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Progress', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Source', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Enrolled', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Completed', 'learnomy' ); ?></th>
					<?php if ( $can_manage ) : ?>
						<th scope="col" class="lrn-col-actions"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $enrollments ) ) : ?>
					<tr><td colspan="<?php echo absint( $lrn_enr_cols ); ?>" class="lrn-muted"><?php esc_html_e( 'Not enrolled in any course yet.', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $enrollments as $lrn_enr ) :
						// A row the student can no longer open. It stays listed —
						// history matters for refunds and reporting — but it reads
						// as revoked and its primary action becomes Restore.
						$lrn_is_live = in_array( (string) ( $lrn_enr->status ?? '' ), array( 'active', 'completed' ), true );
						?>
						<tr<?php echo $lrn_is_live ? '' : ' class="lrn-row--revoked"'; ?>>
							<td>
								<a class="lrn-admin-table__primary" href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . absint( $lrn_enr->course_id ) ) ); ?>">
									<?php echo esc_html( $lrn_course_title( $lrn_enr->course_id ) ); ?>
								</a>
							</td>
							<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $lrn_enr->status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $lrn_enr->progress_pct ?? 0 ) ) . '%' ); ?></td>
							<td><span class="lrn-muted"><?php echo esc_html( (string) ( $lrn_enr->source ?? '' ) ); ?></span></td>
							<td><?php echo esc_html( \Learnomy\date_label( $lrn_enr->enrolled_at ?? null, '—', $date_format ) ); ?></td>
							<td>
								<?php
								// `! empty()` lets the MySQL zero-date through, and 398 progress
								// rows hold it - it would render "November 30, -0001" as a
								// completion date (Basecamp 10184459033).
								$lrn_completed = \Learnomy\date_label( $lrn_enr->completed_at ?? null, '', $date_format );
								echo '' !== $lrn_completed
									? esc_html( $lrn_completed )
									: '<span class="lrn-muted">—</span>';
								?>
							</td>
							<?php if ( $can_manage ) : ?>
								<td class="lrn-row-actions lrn-col-actions">
									<?php
									if ( $lrn_is_live ) {
										// Removing access CANCELS the enrolment; it does not delete
										// the record and it does not wipe progress. The confirm used
										// to promise a deletion that never happened, which is half of
										// why the action read as "nothing happened".
										$lrn_enr_actions = array(
											array(
												'label' => __( 'Reset progress', 'learnomy' ),
												'icon'  => 'rotate-ccw',
												'attrs' => array(
													'class'          => 'lrn-action-link',
													'data-action'    => 'learnomy_admin_reset_course',
													'data-user-id'   => absint( $user->ID ),
													'data-course-id' => absint( $lrn_enr->course_id ),
													'data-confirm'   => __( 'Reset this student\'s progress and quiz attempts for this course? They keep access but start from the beginning.', 'learnomy' ),
												),
											),
											array(
												'label'  => __( 'Remove access', 'learnomy' ),
												'icon'   => 'trash-2',
												'danger' => true,
												'attrs'  => array(
													'class'              => 'lrn-action-link lrn-action-link--danger',
													'data-action'        => 'learnomy_remove_enrollment',
													'data-enrollment-id' => absint( $lrn_enr->id ),
													'data-confirm'       => __( 'Remove this student\'s access to this course? The course disappears from their dashboard. Their progress and the enrollment record are kept, so you can restore access later.', 'learnomy' ),
												),
											),
										);
									} else {
										// POST enrollments/{id}/restore — the mirror of the DELETE
										// that revoked it. Not the "Grant course access" picker
										// above: granting runs a fresh enrollment, which resets
										// progress. Restoring keeps it.
										$lrn_enr_actions = array(
											array(
												'label'   => __( 'Restore access', 'learnomy' ),
												'icon'    => 'rotate-ccw',
												'primary' => true,
												'attrs'   => array(
													'class'              => 'lrn-action-link',
													'data-action'        => 'learnomy_admin_restore_enrollment',
													'data-enrollment-id' => absint( $lrn_enr->id ),
													'data-confirm'       => __( 'Give this student access to this course again? They resume with the progress they already had.', 'learnomy' ),
												),
											),
										);
									}

									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::render_action() escapes every label, href and attribute; the statement spans lines so a single-line ignore never covered it.
									echo \Learnomy\Admin\UX::row_actions( $lrn_enr_actions );
									// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
									?>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>

	<!-- Certificates -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<?php
			printf(
				/* translators: %s: certificate count */
				esc_html__( 'Certificates (%s)', 'learnomy' ),
				esc_html( number_format_i18n( count( $certificates ) ) )
			);
			?>
		</h2>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Course', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Issued', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Verify', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $certificates ) ) : ?>
					<tr><td colspan="4" class="lrn-muted"><?php esc_html_e( 'No certificates earned yet.', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $certificates as $lrn_cert ) : ?>
						<tr>
							<td><?php echo esc_html( $lrn_course_title( $lrn_cert->course_id ?? 0 ) ); ?></td>
							<td><?php echo esc_html( \Learnomy\date_label( $lrn_cert->issued_at ?? null, '—', $date_format ) ); ?></td>
							<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $lrn_cert->status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td>
								<?php if ( ! empty( $lrn_cert->uuid ) ) : ?>
									<a href="<?php echo esc_url( \Learnomy\route_url( 'certificate-verify', array( 'slug' => (string) $lrn_cert->uuid ) ) ); ?>" target="_blank" rel="noopener">
										<?php esc_html_e( 'View', 'learnomy' ); ?>
									</a>
								<?php else : ?>
									<span class="lrn-muted">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>

	<!-- Quiz attempts -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<?php
			printf(
				/* translators: %s: quiz attempt count */
				esc_html__( 'Quiz attempts (%s)', 'learnomy' ),
				esc_html( number_format_i18n( count( $attempts ) ) )
			);
			?>
		</h2>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Quiz', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Score', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Taken', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $attempts ) ) : ?>
					<tr><td colspan="5" class="lrn-muted"><?php esc_html_e( 'No quiz attempts yet', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $attempts as $lrn_att ) : ?>
						<?php $lrn_att_date = $lrn_att->submitted_at ?? $lrn_att->started_at ?? ''; ?>
						<tr>
							<td><?php echo esc_html( $quiz_map[ (int) ( $lrn_att->quiz_id ?? 0 ) ] ?? sprintf( '#%d', (int) ( $lrn_att->quiz_id ?? 0 ) ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (float) ( $lrn_att->percentage ?? 0 ), 1 ) . '%' ); ?></td>
							<td>
								<?php
								echo (int) ( $lrn_att->passed ?? 0 ) === 1
									? '<span class="lrn-status-badge lrn-status-badge--success">' . esc_html__( 'Passed', 'learnomy' ) . '</span>'
									: '<span class="lrn-status-badge lrn-status-badge--muted">' . esc_html__( 'Not passed', 'learnomy' ) . '</span>';
								?>
							</td>
							<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $lrn_att->status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo esc_html( \Learnomy\date_label( $lrn_att_date ?? null, '—', $date_format ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>

	<!-- Quiz attempt limits -->
	<?php if ( ! empty( $quiz_attempt_limits ) ) : ?>
		<?php
		// The Actions column belongs to whoever can grant on at least ONE row.
		// An instructor sees the whole table (the page already scopes them to
		// students they share a course with) but only gets buttons on quizzes in
		// their own courses, so the column has to be decided per page and the
		// buttons per row.
		$lrn_can_grant_any = false;
		foreach ( $quiz_attempt_limits as $lrn_cap ) {
			if ( ! empty( $lrn_cap->can_grant ) ) {
				$lrn_can_grant_any = true;
				break;
			}
		}
		?>
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Quiz attempt limits', 'learnomy' ); ?></h2>
			<p class="lrn-muted">
				<?php esc_html_e( 'Quizzes that cap how many times this student may attempt them. Granting an extra attempt affects this student and this quiz only - their progress, scores and certificates are untouched.', 'learnomy' ); ?>
			</p>
			<div class="lrn-admin-table-wrap">
			<table class="lrn-admin-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Quiz', 'learnomy' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Attempts used', 'learnomy' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Granted', 'learnomy' ); ?></th>
						<?php if ( $lrn_can_grant_any ) : ?>
							<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $quiz_attempt_limits as $lrn_cap ) : ?>
						<tr>
							<td><?php echo esc_html( $lrn_cap->title ); ?></td>
							<td>
								<?php
								printf(
									/* translators: 1: attempts used, 2: attempts allowed */
									esc_html__( '%1$s of %2$s', 'learnomy' ),
									esc_html( number_format_i18n( $lrn_cap->used ) ),
									esc_html( number_format_i18n( $lrn_cap->limit ) )
								);
								?>
							</td>
							<td>
								<?php
								echo $lrn_cap->granted > 0
									? esc_html( sprintf( '+%s', number_format_i18n( $lrn_cap->granted ) ) )
									: '<span class="lrn-muted">—</span>';
								?>
							</td>
							<?php if ( $lrn_can_grant_any ) : ?>
								<td class="lrn-row-actions lrn-col-actions">
									<?php
									if ( empty( $lrn_cap->can_grant ) ) {
										// The row is visible to every viewer of this page, but the
										// action belongs to whoever may grade this quiz. Keep the
										// cell so the table stays aligned.
										echo '<span class="lrn-muted">&mdash;</span>';
									} else {
										$lrn_cap_actions = array(
											array(
												'label' => __( 'Grant 1 attempt', 'learnomy' ),
												'icon'  => 'plus-circle',
												'attrs' => array(
													'class' => 'lrn-action-link',
													'data-action' => 'learnomy_admin_grant_quiz_attempt',
													'data-user-id' => absint( $user->ID ),
													'data-quiz-id' => absint( $lrn_cap->quiz_id ),
													'data-attempts' => '1',
												),
											),
										);
										if ( $lrn_cap->granted > 0 ) {
											$lrn_cap_actions[] = array(
												'label' => __( 'Take back 1 attempt', 'learnomy' ),
												'icon'  => 'minus-circle',
												'attrs' => array(
													'class' => 'lrn-action-link',
													'data-action' => 'learnomy_admin_grant_quiz_attempt',
													'data-user-id' => absint( $user->ID ),
													'data-quiz-id' => absint( $lrn_cap->quiz_id ),
													'data-attempts' => '-1',
												),
											);
										}
										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::row_actions() escapes every label, href and attribute.
										echo \Learnomy\Admin\UX::row_actions( $lrn_cap_actions );
									}
									?>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		</div>
	<?php endif; ?>

	<?php
	// Payments — administrators only. Hidden rather than rendered empty: a
	// teacher shown "Payments (0)" would reasonably conclude the student has
	// never paid for anything, which is a different false statement from the
	// leak this replaced. What a student spent is not a teaching question.
	//
	// A PHP comment, not an HTML one: reasoning about who may see what has no
	// business being served to the browser.
	?>
	<?php if ( $lrn_sees_whole_record ) : ?>
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<?php
			printf(
				/* translators: %s: payment count */
				esc_html__( 'Payments (%s)', 'learnomy' ),
				esc_html( number_format_i18n( count( $transactions ) ) )
			);
			?>
		</h2>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Provider', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $transactions ) ) : ?>
					<tr><td colspan="5" class="lrn-muted"><?php esc_html_e( 'No payments on record.', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $transactions as $lrn_txn ) : ?>
						<tr>
							<td><?php echo esc_html( \Learnomy\date_label( $lrn_txn->created_at ?? null, '—', $date_format ) ); ?></td>
							<td><?php echo esc_html( str_replace( '_', ' ', (string) ( $lrn_txn->transaction_type ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( strtoupper( (string) ( $lrn_txn->currency ?? $currency ) ) . ' ' . number_format_i18n( (float) ( $lrn_txn->amount ?? 0 ), 2 ) ); ?></td>
							<td><span class="lrn-muted"><?php echo esc_html( (string) ( $lrn_txn->payment_provider ?? '—' ) ); ?></span></td>
							<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $lrn_txn->status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>
	<?php endif; ?>

</div><!-- /.learnomy-admin -->
