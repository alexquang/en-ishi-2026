<?php
/**
 * Admin per-course overview (management view).
 *
 * The course's Learnomy data in one place — enrolled students, reviews,
 * payments, and a summary — plus admin actions (publish toggle, enroll a
 * student, per-student reset / remove). Distinct from the content editor
 * (edit-course) and the curriculum builder. Mirrors the student / instructor
 * detail layout so the three read as one product.
 *
 * @package Learnomy
 * @var object              $course        lrn_courses row.
 * @var object[]            $enrollments   Enrolled rows (lrn_enrollments).
 * @var object[]            $reviews       Approved reviews.
 * @var object[]            $transactions  Recent payments.
 * @var array<int,\WP_User> $user_map      user_id → WP_User (batched).
 * @var int                 $enrolled_total Total enrollments.
 * @var int                 $active_total  Active enrollments.
 * @var int                 $txn_total     Total transactions.
 * @var float               $revenue       Completed revenue over fetched txns.
 * @var string              $currency      Store currency code.
 */

defined( 'ABSPATH' ) || exit;

$date_format  = get_option( 'date_format' );
$is_published = 'published' === ( $course->status ?? '' );
$price_label  = \Learnomy\Models\Course::is_free( $course )
	? __( 'Free', 'learnomy' )
	: $currency . ' ' . number_format_i18n( (float) ( $course->price ?? 0 ), 2 );

/** Resolve a display name from the batched user map. */
$lrn_user_name = static function ( $user_id ) use ( $user_map ) {
	$user_id = (int) $user_id;
	return isset( $user_map[ $user_id ] ) ? $user_map[ $user_id ]->display_name : sprintf( '#%d', $user_id );
};

// Instructors & commission — site-owner-only course management, relocated here
// from the edit-course form to keep that page short. Saved over a partial
// PATCH /courses/{id} (course-detail.js), which reuses the controller's roster
// sync + commission resolution — no revenue logic is duplicated here.
$lrn_is_site_admin  = current_user_can( 'manage_options' );
$lrn_settings_opt   = get_option( 'learnomy_settings', array() );
$lrn_multi_instr    = \Learnomy\multi_instructor_enabled();
$lrn_commissions_on = \Learnomy\Modules\Module_Kernel::instance()->is_enabled( 'commissions' );
$lrn_show_instr     = $lrn_is_site_admin && ( $lrn_multi_instr || $lrn_commissions_on );

$lrn_instructors  = array();
$lrn_instr_map    = array();
$lrn_author_id    = (int) ( $course->author_id ?? 0 );
$lrn_comm_rate    = null;
$lrn_comm_inherit = 0.0;
if ( $lrn_show_instr ) {
	if ( $lrn_multi_instr ) {
		$lrn_instructors = get_users(
			array(
				'role__in' => array( 'lrn_instructor', 'administrator' ),
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'number'   => 200,
			)
		);
		foreach ( \Learnomy\Models\CourseInstructor::get_for_course( (int) $course->id ) as $lrn_ci ) {
			$lrn_instr_map[ (int) $lrn_ci->user_id ] = (float) $lrn_ci->share_pct;
		}
	}
	if ( $lrn_commissions_on ) {
		$lrn_comm_rule    = \Learnomy\Models\CommissionRule::get_for_entity_exact( 'course', (int) $course->id );
		$lrn_comm_rate    = $lrn_comm_rule ? (float) $lrn_comm_rule->platform_rate : null;
		$lrn_comm_inherit = (float) \Learnomy\Services\Commission_Service::resolve_platform_rate( 0, $lrn_author_id );
	}
}
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="book-open" class="lrn-page-header__icon"></i>
				<?php echo esc_html( $course->title ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<?php
			// Shared header toolbar (All courses / Edit course / Curriculum /
			// View course) — same partial the edit form reuses so the two
			// screens never drift. See partials/course-header-toolbar.php.
			$lrn_header_screen = 'detail';
			require LEARNOMY_DIR . 'includes/admin/views/partials/course-header-toolbar.php';

			/**
			 * Pro extension point for per-course actions in the overview header.
			 *
			 * @param int $course_id The course ID.
			 */
			do_action( 'learnomy_admin_course_detail_actions', (int) $course->id );
			?>
			<button type="button" class="lrn-admin-btn <?php echo $is_published ? '' : 'lrn-admin-btn--primary'; ?>"
					data-action="learnomy_update_course_status"
					data-course-id="<?php echo absint( $course->id ); ?>"
					data-status="<?php echo $is_published ? 'draft' : 'published'; ?>"
					data-confirm="<?php echo esc_attr( $is_published ? __( 'Move this course to draft? It will no longer be visible to students.', 'learnomy' ) : __( 'Publish this course?', 'learnomy' ) ); ?>">
				<i data-lucide="<?php echo $is_published ? 'eye-off' : 'send'; ?>"></i>
				<?php echo $is_published ? esc_html__( 'Move to draft', 'learnomy' ) : esc_html__( 'Publish', 'learnomy' ); ?>
			</button>
		</div>
	</div>

	<hr class="wp-header-end">

	<div class="lrn-admin-grid lrn-admin-grid--2">

		<!-- Summary -->
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Overview', 'learnomy' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $course->status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Price', 'learnomy' ); ?></th>
					<td><?php echo esc_html( $price_label ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Students enrolled', 'learnomy' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: 1: total enrolled, 2: active count */
							esc_html__( '%1$s (%2$s active)', 'learnomy' ),
							esc_html( number_format_i18n( $enrolled_total ) ),
							esc_html( number_format_i18n( $active_total ) )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rating', 'learnomy' ); ?></th>
					<td>
						<?php
						$lrn_rating = (float) ( $course->avg_rating ?? 0 );
						$lrn_rcount = (int) ( $course->review_count ?? 0 );
						if ( $lrn_rcount > 0 ) {
							printf(
								/* translators: 1: average rating, 2: review count */
								esc_html__( '%1$s stars (%2$s reviews)', 'learnomy' ),
								esc_html( number_format_i18n( $lrn_rating, 1 ) ),
								esc_html( number_format_i18n( $lrn_rcount ) )
							);
						} else {
							echo '<span class="lrn-muted">' . esc_html__( 'No reviews yet', 'learnomy' ) . '</span>';
						}
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Revenue', 'learnomy' ); ?></th>
					<td>
						<?php echo esc_html( $currency . ' ' . number_format_i18n( $revenue, 2 ) ); ?>
						<?php if ( $txn_total > count( $transactions ) ) : ?>
							<span class="lrn-muted"><?php esc_html_e( '(recent transactions)', 'learnomy' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>
		</div>

		<?php
		// Dead-course safety net: a Closed course with no students yet was likely
		// set up and walked away from (§9.4). Point the owner at the three ways in.
		if ( \Learnomy\Models\Course::is_closed( $course ) && 0 === (int) $enrolled_total ) :
			?>
			<div class="notice notice-warning inline lrn-closed-status-banner">
				<p>
					<strong><?php esc_html_e( 'This course is set to external (closed) access but has no students yet.', 'learnomy' ); ?></strong>
					<?php esc_html_e( 'Students get in when you grant access: add them by email or upload a CSV below, or connect an external system on the Webhooks screen.', 'learnomy' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-webhooks#lrn-incoming-enrollment' ) ); ?>"><?php esc_html_e( 'Connect a system', 'learnomy' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<!-- Enroll a student -->
		<div class="lrn-admin-card">
			<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Enroll a student', 'learnomy' ); ?></h2>
			<p class="lrn-muted"><?php esc_html_e( 'Manually enroll a student by email or username (source: manual). Manual grants bypass payment.', 'learnomy' ); ?></p>
			<div class="lrn-grant-access" id="lrn-course-enroll" data-course-id="<?php echo absint( $course->id ); ?>">
				<label for="lrn-course-enroll-user" class="screen-reader-text"><?php esc_html_e( 'Student email or username', 'learnomy' ); ?></label>
				<input type="text" id="lrn-course-enroll-user" class="regular-text"
						placeholder="<?php esc_attr_e( 'Email or username…', 'learnomy' ); ?>">
				<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-course-enroll-btn">
					<i data-lucide="user-plus"></i>
					<?php esc_html_e( 'Enroll', 'learnomy' ); ?>
				</button>
			</div>

			<details class="lrn-bulk-enroll" id="lrn-course-bulk-enroll" data-course-id="<?php echo absint( $course->id ); ?>">
				<summary><?php esc_html_e( 'Bulk enroll from a list (CSV / emails)', 'learnomy' ); ?></summary>
				<p class="lrn-muted"><?php esc_html_e( 'Paste or upload one email per line (or comma-separated). New emails get an account + a set-password invite. Already-enrolled students are skipped.', 'learnomy' ); ?></p>
				<label for="lrn-bulk-enroll-file" class="lrn-admin-btn lrn-admin-btn--ghost lrn-admin-btn--sm">
					<i data-lucide="upload"></i> <?php esc_html_e( 'Choose CSV', 'learnomy' ); ?>
					<input type="file" id="lrn-bulk-enroll-file" accept=".csv,text/csv,text/plain" hidden>
				</label>
				<textarea id="lrn-bulk-enroll-emails" class="large-text code" rows="5"
						placeholder="student1@example.com&#10;student2@example.com"></textarea>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-bulk-enroll-btn">
					<i data-lucide="users"></i> <?php esc_html_e( 'Enroll everyone', 'learnomy' ); ?>
				</button>
				<div id="lrn-bulk-enroll-result" class="lrn-bulk-enroll__result" role="status" hidden></div>
			</details>
		</div>
	</div>

	<?php if ( $lrn_show_instr ) : ?>
	<!-- Instructors & commission (site-owner only) -->
	<div class="lrn-admin-card" id="lrn-course-instructors" data-course-id="<?php echo absint( $course->id ); ?>">
		<h2 class="lrn-admin-card__title"><?php esc_html_e( 'Instructors & commission', 'learnomy' ); ?></h2>
		<p class="lrn-muted"><?php esc_html_e( 'Assign the lead instructor and co-instructors, and set this course\'s platform commission. Commission from sales goes to the primary instructor.', 'learnomy' ); ?></p>
		<table class="form-table">
			<?php if ( $lrn_multi_instr && ! empty( $lrn_instructors ) ) : ?>
			<tr>
				<th scope="row"><label for="lrn-cd-primary"><?php esc_html_e( 'Primary instructor', 'learnomy' ); ?></label></th>
				<td>
					<select id="lrn-cd-primary" class="regular-text">
						<?php foreach ( $lrn_instructors as $lrn_pi ) : ?>
							<option value="<?php echo esc_attr( (string) (int) $lrn_pi->ID ); ?>" <?php selected( (int) $lrn_pi->ID, $lrn_author_id ); ?>><?php echo esc_html( $lrn_pi->display_name ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'The lead instructor shown on the course and paid commission.', 'learnomy' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Co-instructors', 'learnomy' ); ?></th>
				<td>
					<ul class="lrn-instructor-rows">
						<?php
						foreach ( $lrn_instructors as $lrn_inst ) :
							$lrn_iid     = (int) $lrn_inst->ID;
							$lrn_is_prim = $lrn_iid === $lrn_author_id;
							?>
							<li class="lrn-instructor-row">
								<label class="lrn-checkbox-label">
									<input type="checkbox" name="cd_instructors[]" value="<?php echo esc_attr( (string) $lrn_iid ); ?>" <?php checked( isset( $lrn_instr_map[ $lrn_iid ] ) || $lrn_is_prim ); ?> <?php echo $lrn_is_prim ? 'disabled data-primary="1"' : ''; ?>>
									<span class="lrn-instructor-row__name"><?php echo esc_html( $lrn_inst->display_name ); ?></span>
									<?php if ( $lrn_is_prim ) : ?>
										<span class="lrn-status-badge lrn-status-badge--success"><?php esc_html_e( 'Primary (gets commission)', 'learnomy' ); ?></span>
									<?php else : ?>
										<span class="lrn-status-badge"><?php esc_html_e( 'Co-instructor', 'learnomy' ); ?></span>
									<?php endif; ?>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( 200 === count( $lrn_instructors ) ) : ?>
						<p class="lrn-muted lrn-text-sm"><?php esc_html_e( 'Showing first 200 instructors. Use the Instructors screen to find others.', 'learnomy' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php endif; ?>
			<?php if ( $lrn_commissions_on ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Platform commission', 'learnomy' ); ?></th>
				<td>
					<?php
					// Read-only here, on purpose. The rate is EDITED on the course
					// editor beside the price it is a percentage of; showing it a
					// second time as an input gave the same money value two owners
					// on two screens, which is exactly how the Earnings screen and
					// this one ended up disagreeing about whether the number means
					// the platform's cut or the instructor's. One editable control,
					// one meaning; this row exists so an owner reviewing who gets
					// paid can see the rate without leaving the page.
					if ( null !== $lrn_comm_rate ) {
						printf(
							/* translators: %s: the per-course platform rate, e.g. 40.00 */
							esc_html__( '%s%% for this course', 'learnomy' ),
							esc_html( number_format_i18n( $lrn_comm_rate, 2 ) )
						);
					} else {
						printf(
							/* translators: %s: the inherited platform rate, e.g. 30.00 */
							esc_html__( '%s%% (inherited)', 'learnomy' ),
							esc_html( number_format_i18n( $lrn_comm_inherit, 2 ) )
						);
					}
					?>
					<p class="description">
						<?php esc_html_e( 'The platform keeps this percentage; the instructor gets the rest.', 'learnomy' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . (int) $course->id ) ); ?>#lrn-course-commission">
							<?php esc_html_e( 'Change it on the course editor.', 'learnomy' ); ?>
						</a>
					</p>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-cd-save-instructors"><i data-lucide="save"></i> <?php esc_html_e( 'Save', 'learnomy' ); ?></button>
	</div>
	<?php endif; ?>

	<!-- Enrolled students -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<?php
			printf(
				/* translators: %s: enrolled student count */
				esc_html__( 'Enrolled students (%s)', 'learnomy' ),
				esc_html( number_format_i18n( $enrolled_total ) )
			);
			?>
		</h2>
		<?php
		// The heading counts PEOPLE (count_distinct_students), this table lists
		// enrollment RECORDS, and a learner can hold several for one course —
		// a free grant plus a membership, say. Each row keeps its own
		// remove/restore action, so the table cannot collapse to one row per
		// person without taking away the ability to revoke a single grant.
		// Say so instead, or the two numbers read as a bug (Basecamp
		// 10167508313).
		$lrn_grant_rows     = count( $enrollments );
		$lrn_people_on_page = count( array_unique( array_map( static fn( $e ) => (int) $e->user_id, $enrollments ) ) );
		?>
		<?php if ( $lrn_grant_rows > $lrn_people_on_page ) : ?>
			<p class="lrn-muted">
				<?php
				printf(
					/* translators: 1: number of enrollment rows shown, 2: number of distinct people those rows belong to */
					esc_html__( 'Showing %1$s enrollment records for %2$s students. A learner holding more than one grant, such as a purchase and a membership, appears once per grant so each can be managed separately.', 'learnomy' ),
					esc_html( number_format_i18n( $lrn_grant_rows ) ),
					esc_html( number_format_i18n( $lrn_people_on_page ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( $enrolled_total > $lrn_people_on_page ) : ?>
			<p class="lrn-muted">
				<?php
				printf(
					/* translators: %s: number shown */
					esc_html__( 'Showing the most recent %s. Use the Students list to filter the full roster.', 'learnomy' ),
					esc_html( number_format_i18n( $lrn_people_on_page ) )
				);
				?>
			</p>
		<?php endif; ?>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Student', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Progress', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Source', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Enrolled', 'learnomy' ); ?></th>
					<th scope="col" class="lrn-col-actions"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $enrollments ) ) : ?>
					<tr><td colspan="6" class="lrn-muted"><?php esc_html_e( 'No students enrolled yet.', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php
					foreach ( $enrollments as $lrn_enr ) :
						$lrn_uid    = (int) $lrn_enr->user_id;
						$lrn_user   = $user_map[ $lrn_uid ] ?? null;
						$lrn_detail = admin_url( 'admin.php?page=learnomy-student-detail&user_id=' . $lrn_uid );
						// This roster is deliberately "everyone ever enrolled", so a
						// removed student stays listed. The row has to READ revoked
						// and offer the undo, or Remove access looks like a no-op.
						$lrn_is_live = in_array( (string) ( $lrn_enr->status ?? '' ), array( 'active', 'completed' ), true );
						?>
						<tr<?php echo $lrn_is_live ? '' : ' class="lrn-row--revoked"'; ?>>
							<td>
								<div class="lrn-user-cell">
									<?php echo get_avatar( $lrn_uid, 32, '', '', array( 'class' => 'lrn-avatar lrn-avatar--sm' ) ); ?>
									<span class="lrn-user-cell__name lrn-user-cell__name--stacked">
										<a class="lrn-admin-table__primary" href="<?php echo esc_url( $lrn_detail ); ?>">
											<strong><?php echo esc_html( $lrn_user_name( $lrn_uid ) ); ?></strong>
										</a>
										<?php if ( $lrn_user ) : ?>
											<a class="lrn-user-cell__email" href="mailto:<?php echo esc_attr( $lrn_user->user_email ); ?>"><?php echo esc_html( $lrn_user->user_email ); ?></a>
										<?php endif; ?>
									</span>
								</div>
							</td>
							<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $lrn_enr->status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $lrn_enr->progress_pct ?? 0 ) ) . '%' ); ?></td>
							<td>
								<?php
								// Same vocabulary as the grant chips on the course-edit
								// panel — this column used to print the raw column value,
								// so one screen said "learning_path" while the other said
								// "Learning path" for the same fact.
								$lrn_grant_sources = array_filter( array( (string) ( $lrn_enr->source ?? '' ) ) );
								require __DIR__ . '/partials/enrollment-grants.php';
								?>
							</td>
							<td><?php echo esc_html( \Learnomy\date_label( $lrn_enr->enrolled_at, '—', $date_format ) ); ?></td>
							<td class="lrn-row-actions lrn-col-actions">
								<?php
								if ( $lrn_is_live ) {
									$lrn_enr_actions = array(
										array(
											'label' => __( 'Reset progress', 'learnomy' ),
											'icon'  => 'rotate-ccw',
											'attrs' => array(
												'class' => 'lrn-action-link',
												'data-action' => 'learnomy_admin_reset_course',
												'data-user-id' => $lrn_uid,
												'data-course-id' => absint( $course->id ),
												'data-confirm' => __( 'Reset this student\'s progress and quiz attempts for this course?', 'learnomy' ),
											),
										),
										array(
											'label'  => __( 'Remove access', 'learnomy' ),
											'icon'   => 'trash-2',
											'danger' => true,
											'attrs'  => array(
												'class' => 'lrn-action-link lrn-action-link--danger',
												'data-action' => 'learnomy_remove_enrollment',
												'data-enrollment-id' => absint( $lrn_enr->id ),
												// Says what actually happens: a cancel, not a delete.
												'data-confirm' => __( 'Remove this student\'s access to this course? The course disappears from their dashboard. Their progress and the enrollment record are kept, so you can restore access later.', 'learnomy' ),
											),
										),
									);
								} else {
									$lrn_enr_actions = array(
										array(
											'label'   => __( 'Restore access', 'learnomy' ),
											'icon'    => 'rotate-ccw',
											'primary' => true,
											'attrs'   => array(
												'class' => 'lrn-action-link',
												'data-action' => 'learnomy_admin_restore_enrollment',
												'data-enrollment-id' => absint( $lrn_enr->id ),
												'data-confirm' => __( 'Give this student access to this course again? They resume with the progress they already had.', 'learnomy' ),
											),
										),
									);
								}

								// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::render_action() escapes every label, href and attribute; the statement spans lines so a single-line ignore never covered it.
								echo \Learnomy\Admin\UX::row_actions( $lrn_enr_actions );
								// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>

	<!-- Reviews -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<?php
			printf(
				/* translators: %s: review count */
				esc_html__( 'Reviews (%s)', 'learnomy' ),
				esc_html( number_format_i18n( (int) ( $course->review_count ?? 0 ) ) )
			);
			?>
		</h2>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Student', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rating', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Review', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $reviews ) ) : ?>
					<tr><td colspan="4" class="lrn-muted"><?php esc_html_e( 'No approved reviews yet.', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $reviews as $lrn_rev ) : ?>
						<tr>
							<td><?php echo esc_html( $lrn_user_name( $lrn_rev->user_id ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) ( $lrn_rev->rating ?? 0 ) ) . ' ★' ); ?></td>
							<td>
								<?php if ( ! empty( $lrn_rev->title ) ) : ?>
									<strong><?php echo esc_html( (string) $lrn_rev->title ); ?></strong>
								<?php endif; ?>
								<span class="lrn-muted"><?php echo esc_html( wp_trim_words( (string) ( $lrn_rev->content ?? '' ), 20, '…' ) ); ?></span>
							</td>
							<td><?php echo esc_html( \Learnomy\date_label( $lrn_rev->created_at, '—', $date_format ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>

	<!-- Payments -->
	<div class="lrn-admin-card">
		<h2 class="lrn-admin-card__title">
			<?php
			printf(
				/* translators: %s: transaction count */
				esc_html__( 'Payments (%s)', 'learnomy' ),
				esc_html( number_format_i18n( $txn_total ) )
			);
			?>
		</h2>
		<div class="lrn-admin-table-wrap">
		<table class="lrn-admin-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Student', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Amount', 'learnomy' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $transactions ) ) : ?>
					<tr><td colspan="4" class="lrn-muted"><?php esc_html_e( 'No payments on record.', 'learnomy' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $transactions as $lrn_txn ) : ?>
						<tr>
							<td><?php echo esc_html( \Learnomy\date_label( $lrn_txn->created_at, '—', $date_format ) ); ?></td>
							<td><?php echo esc_html( $lrn_user_name( $lrn_txn->user_id ?? 0 ) ); ?></td>
							<td><?php echo esc_html( strtoupper( (string) ( $lrn_txn->currency ?? $currency ) ) . ' ' . number_format_i18n( (float) ( $lrn_txn->amount ?? 0 ), 2 ) ); ?></td>
							<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $lrn_txn->status ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
	</div>

	<?php
	/**
	 * Render full-width course-management panels on the course-detail hub.
	 *
	 * Integrations (e.g. WP Fusion CRM tags) add their own `.lrn-admin-card`
	 * here instead of crowding the edit-course sidebar — this page is full
	 * width and shares the admin-card vocabulary, so panels read as native.
	 *
	 * @param int $course_id The course being viewed.
	 */

	/*
	 * The same Community control the course editor, the course builder, a
	 * cohort and a Learnomy Space render. BEFORE the panels: this is the
	 * decision, and a product panel below it is the advanced follow-up
	 * (pointing at a community that already exists).
	 *
	 * Without it this screen carried only the product panel, whose copy sends
	 * the owner to a switch that was not on the page.
	 */
	\Learnomy\Services\Object_Integration_Registry::render( 'course', (int) $course->id );

	do_action( 'learnomy_admin_course_detail_panels', (int) $course->id );
	?>

</div><!-- /.learnomy-admin -->
