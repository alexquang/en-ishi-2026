<?php
/**
 * Admin courses list view.
 *
 * @package Learnomy
 * @var object[]          $courses     Paginated course rows.
 * @var int               $total       Total course count for active filter.
 * @var int               $total_pages Total number of pages.
 * @var int               $paged       Current page number.
 * @var string            $status_filter Active status filter slug.
 * @var string            $search      Current search string.
 * @var array             $course_authors Map of author_id => display_name (batched, no N+1).
 */

defined( 'ABSPATH' ) || exit;

$status_tabs = array(
	'all'            => __( 'All', 'learnomy' ),
	'draft'          => __( 'Draft', 'learnomy' ),
	'pending_review' => __( 'Pending Review', 'learnomy' ),
	'published'      => __( 'Published', 'learnomy' ),
	'archived'       => __( 'Archived', 'learnomy' ),
	'trashed'        => __( 'Trash', 'learnomy' ),
);

$lrn_in_trash = ( 'trashed' === $status_filter );

// Base URL carries the active filters so pagination + the status tabs keep
// them on the next page (big-site checklist #1 + #5). The status tabs replace
// only `status` via add_query_arg, so they inherit category/tag/search too.
$base_url           = admin_url( 'admin.php?page=learnomy-courses' );
$lrn_active_filters = array();
if ( ! empty( $category_filter ) ) {
	$lrn_active_filters['category_id'] = (int) $category_filter;
}
if ( ! empty( $tag_filter ) ) {
	$lrn_active_filters['tag_id'] = (int) $tag_filter;
}
if ( '' !== $search ) {
	$lrn_active_filters['s'] = $search;
}
if ( $lrn_active_filters ) {
	$base_url = add_query_arg( $lrn_active_filters, $base_url );
}

// Bulk-action wiring for the generic handler in list-page.js. The handler
// reads `data-list-resource` to pick the REST endpoint and
// `data-list-status-map` to map dropdown values to PATCH status payloads.
// Without these attributes the bulk dropdown rendered but did nothing —
// admins clicking Apply got silent no-ops on every list page that didn't
// have a page-specific JS handler.
$lrn_list_attrs = sprintf(
	' data-list-resource="%s" data-list-rest-base="%s" data-list-nonce="%s" data-list-status-map=\'%s\'',
	'courses',
	esc_attr( rest_url( 'learnomy/v1/' ) ),
	esc_attr( wp_create_nonce( 'wp_rest' ) ),
	esc_attr(
		wp_json_encode(
			array(
				// Bulk-action verb -> canonical status: the 'publish' key is the
				// dropdown action name, mapped to the 'published' status.
				'publish' => 'published', // publish-status-ok — action name, not a stored status.
				'draft'   => 'draft',
				'archive' => 'archived',
				'trash'   => 'trashed',
				'restore' => 'draft',
			)
		)
	)
);
?>
<div class="wrap learnomy-admin"<?php echo $lrn_list_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values escaped above. ?>>
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="book-open" class="lrn-page-header__icon"></i>
				<?php echo esc_html( \Learnomy\course_label( true ) ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-new-course' ) ); ?>" class="lrn-admin-btn lrn-admin-btn--primary">
				<i data-lucide="plus"></i> <?php esc_html_e( 'Add New', 'learnomy' ); ?>
			</a>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<!-- Filters -->
	<div class="lrn-admin-filters">
		<ul class="subsubsub">
			<?php
			$lrn_tab_keys = array_keys( $status_tabs );
			$lrn_last_tab = end( $lrn_tab_keys );
			foreach ( $status_tabs as $slug => $label ) :
				$url        = add_query_arg( 'status', $slug, $base_url );
				$is_current = ( $status_filter === $slug );
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"
						class="<?php echo esc_attr( $is_current ? 'current' : '' ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
					<?php echo $slug !== $lrn_last_tab ? ' |' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="lrn-search-form">
			<input type="hidden" name="page" value="learnomy-courses">
			<?php if ( 'all' !== $status_filter ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
			<?php endif; ?>
			<?php if ( ! empty( $all_categories ) ) : ?>
				<select name="category_id" aria-label="<?php esc_attr_e( 'Filter by category', 'learnomy' ); ?>">
					<option value=""><?php esc_html_e( 'All categories', 'learnomy' ); ?></option>
					<?php foreach ( $all_categories as $ac ) : ?>
						<option value="<?php echo absint( $ac->id ); ?>" <?php selected( (int) ( $category_filter ?? 0 ), (int) $ac->id ); ?>>
							<?php echo esc_html( $ac->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<?php if ( ! empty( $all_tags ) ) : ?>
				<select name="tag_id" aria-label="<?php esc_attr_e( 'Filter by tag', 'learnomy' ); ?>">
					<option value=""><?php esc_html_e( 'All tags', 'learnomy' ); ?></option>
					<?php foreach ( $all_tags as $at ) : ?>
						<option value="<?php echo absint( $at->id ); ?>" <?php selected( (int) ( $tag_filter ?? 0 ), (int) $at->id ); ?>>
							<?php echo esc_html( $at->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<input type="search" name="s" aria-label="<?php esc_attr_e( 'Search', 'learnomy' ); ?>" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search courses…', 'learnomy' ); ?>" class="regular-text">
			<button type="submit" class="lrn-admin-btn"><?php esc_html_e( 'Search', 'learnomy' ); ?></button>
		</form>
	</div>

	<!-- Courses table -->
	<div class="lrn-bulk-bar lrn-bulk-bar--hidden">
		<div class="lrn-bulk-bar__left">
			<span class="lrn-bulk-bar__count">0 selected</span>
			<select id="lrn-bulk-action">
				<option value=""><?php esc_html_e( 'Bulk Actions', 'learnomy' ); ?></option>
				<?php if ( $lrn_in_trash ) : ?>
					<option value="restore"><?php esc_html_e( 'Restore', 'learnomy' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete Permanently', 'learnomy' ); ?></option>
				<?php else : ?>
					<option value="publish"><?php esc_html_e( 'Publish', 'learnomy' ); ?></option>
					<option value="draft"><?php esc_html_e( 'Move to Draft', 'learnomy' ); ?></option>
					<option value="archive"><?php esc_html_e( 'Archive', 'learnomy' ); ?></option>
					<option value="trash"><?php esc_html_e( 'Move to Trash', 'learnomy' ); ?></option>
				<?php endif; ?>
			</select>
			<button type="button" id="lrn-bulk-apply" class="lrn-admin-btn lrn-admin-btn--sm">
				<?php esc_html_e( 'Apply', 'learnomy' ); ?>
			</button>
		</div>
	</div>
	<div class="lrn-admin-table-wrap">
	<table class="lrn-admin-table">
		<thead>
			<tr>
				<th class="lrn-col-check"><input type="checkbox" id="lrn-select-all"></th>
				<th scope="col"><?php esc_html_e( 'Title', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Instructor', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Students', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Rating', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Price', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Created', 'learnomy' ); ?></th>
				<th scope="col" class="lrn-col-actions"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $courses ) ) : ?>
				<tr>
					<td colspan="9" class="lrn-no-items">
						<?php
						$lrn_empty_state = array(
							'icon'        => 'book-open',
							'title'       => __( 'No courses yet', 'learnomy' ),
							'description' => __( 'Create your first course to start teaching.', 'learnomy' ),
							'cta'         => array(
								'url'   => admin_url( 'admin.php?page=learnomy-new-course' ),
								'label' => __( 'Add New Course', 'learnomy' ),
								'icon'  => 'plus',
							),
						);
						include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $courses as $course ) :
					// Author name from the batched $course_authors map (no per-row
					// get_userdata — big-site checklist #3, no N+1).
					$instructor_name = ( ! empty( $course->author_id ) && isset( $course_authors[ (int) $course->author_id ] ) )
						? $course_authors[ (int) $course->author_id ]
						: '';
					$price_label     = \Learnomy\Models\Course::is_free( $course )
						? __( 'Free', 'learnomy' )
						: \Learnomy\format_price( (float) $course->price );
					?>
					<tr>
						<td class="lrn-col-check"><input type="checkbox" class="lrn-row-check" value="<?php echo absint( $course->id ); ?>"></td>
						<td class="column-title">
							<?php if ( ! $lrn_in_trash && current_user_can( 'manage_options' ) ) : ?>
								<a class="lrn-admin-table__primary" href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-course-detail&course_id=' . absint( $course->id ) ) ); ?>">
									<strong><?php echo esc_html( $course->title ); ?></strong>
								</a>
							<?php else : ?>
								<strong><?php echo esc_html( $course->title ); ?></strong>
							<?php endif; ?>
							<?php
							if ( $lrn_in_trash ) {
								$lrn_row_actions = array(
									array(
										'label'   => __( 'Restore', 'learnomy' ),
										'icon'    => 'rotate-ccw',
										'primary' => true,
										'attrs'   => array(
											'class'       => 'status',
											'data-action' => 'learnomy_update_course_status',
											'data-id'     => absint( $course->id ),
											'data-id-field' => 'course_id',
											'data-status' => 'draft',
										),
									),
									array(
										'label'  => __( 'Delete Permanently', 'learnomy' ),
										'icon'   => 'trash-2',
										'danger' => true,
										// This carried ONLY a class. The generic dispatcher in
										// actions.js routes on `data-action`, and deliberately
										// ignores anything it does not recognise, so the button
										// rendered, took a click and did nothing at all — no
										// request, no modal, no console error (Basecamp
										// 10195351941). The DELETE /courses/{id} route and its
										// can_manage guard existed the whole time; only these
										// attributes were missing.
										'attrs'  => array(
											'class'        => 'delete',
											'data-action'  => 'learnomy_delete_course',
											'data-id'      => absint( $course->id ),
											'data-id-field' => 'course_id',
											'data-confirm' => sprintf(
												/* translators: %s: course title */
												__( 'Permanently delete "%s"? This cannot be undone, and it removes the course along with its lessons, quizzes and enrolment records.', 'learnomy' ),
												(string) $course->title
											),
										),
									),
								);
							} else {
								$lrn_row_actions = array(
									array(
										'label'   => __( 'Edit', 'learnomy' ),
										'href'    => admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . absint( $course->id ) ),
										'icon'    => 'pencil',
										'primary' => true,
										'attrs'   => array(
											'class' => 'edit',
										),
									),
									array(
										'label'  => __( 'View', 'learnomy' ),
										'href'   => \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) ),
										'icon'   => 'external-link',
										'target' => '_blank',
										'attrs'  => array(
											'class' => 'view',
											'rel'   => 'noopener',
										),
									),
									// Duplicate. The service, the REST route and the Ability
									// have existed since 1.0 — there was no button, so a site
									// owner could not clone a course without calling the API by
									// hand. Questions had this; courses did not.
									array(
										'label' => __( 'Duplicate', 'learnomy' ),
										'icon'  => 'copy',
										'attrs' => array(
											'class'       => 'lrn-action-link',
											'data-action' => 'learnomy_duplicate_course',
											'data-id'     => absint( $course->id ),
											'data-id-field' => 'course_id',
										),
									),
								);

								if ( 'pending_review' === $course->status && current_user_can( 'manage_options' ) ) {
									// Marketplace approval: Approve / Reject invoke the existing
									// learnomy/approve-course and learnomy/reject-course abilities
									// (via the core Abilities run REST endpoint — see
									// assets/js/admin/actions.js). Approve publishes the course;
									// Reject prompts for a reason and returns it to draft.
									$lrn_row_actions[] = array(
										'label'   => __( 'Approve', 'learnomy' ),
										'icon'    => 'check-circle',
										'primary' => true,
										'attrs'   => array(
											'class'        => 'status',
											'data-action'  => 'learnomy_approve_course',
											'data-id'      => absint( $course->id ),
											'data-id-field' => 'course_id',
											'data-confirm' => __( 'Approve and publish this course?', 'learnomy' ),
										),
									);
									$lrn_row_actions[] = array(
										'label'  => __( 'Reject', 'learnomy' ),
										'icon'   => 'x-circle',
										'danger' => true,
										'attrs'  => array(
											'class'       => 'status',
											'data-action' => 'learnomy_reject_course',
											'data-id'     => absint( $course->id ),
											'data-id-field' => 'course_id',
											'data-prompt' => __( 'Why is this course being rejected? It returns to draft and the reason is shared with the instructor.', 'learnomy' ),
											'data-prompt-label' => __( 'Rejection reason', 'learnomy' ),
										),
									);
								} elseif ( 'published' !== $course->status ) {
									$lrn_row_actions[] = array(
										'label' => __( 'Publish', 'learnomy' ),
										'icon'  => 'check-circle',
										'attrs' => array(
											'class'       => 'status',
											'data-action' => 'learnomy_update_course_status',
											'data-id'     => absint( $course->id ),
											'data-id-field' => 'course_id',
											'data-status' => 'published',
										),
									);
								} else {
									$lrn_row_actions[] = array(
										'label' => __( 'Draft', 'learnomy' ),
										'icon'  => 'file-text',
										'attrs' => array(
											'class'       => 'status',
											'data-action' => 'learnomy_update_course_status',
											'data-id'     => absint( $course->id ),
											'data-id-field' => 'course_id',
											'data-status' => 'draft',
										),
									);
								}

								$lrn_row_actions[] = array(
									'label'  => __( 'Trash', 'learnomy' ),
									'icon'   => 'trash-2',
									'danger' => true,
									'attrs'  => array(
										'class'         => 'status lrn-row-trash',
										'data-action'   => 'learnomy_update_course_status',
										'data-id'       => absint( $course->id ),
										'data-id-field' => 'course_id',
										'data-status'   => 'trashed',
									),
								);
							}
							?>
						</td>
						<td>
							<?php
							if ( '' !== $instructor_name ) {
								echo esc_html( $instructor_name );
							} else {
								// COURSE-01 backstop: a course whose instructor was
								// deleted before reassignment existed. Show a filterable
								// label (default: site name), never a bare "Unknown".
								$lrn_instructor_fallback = apply_filters( 'learnomy_unknown_instructor_label', get_bloginfo( 'name' ), $course );
								echo '<em>' . esc_html( (string) $lrn_instructor_fallback ) . '</em>';
							}
							?>
						</td>
						<td>
							<?php echo \Learnomy\Admin\UX::status_badge( (string) $course->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) $course->student_count ) ); ?></td>
						<td>
							<?php if ( $course->review_count > 0 ) : ?>
								<?php echo esc_html( number_format_i18n( (float) $course->avg_rating, 1 ) ); ?>&nbsp;&#9733;
							<?php else : ?>
								<span class="lrn-muted"><?php esc_html_e( 'No ratings', 'learnomy' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $price_label ); ?></td>
						<td><?php echo esc_html( \Learnomy\date_label( $course->created_at, '—', get_option( 'date_format' ) ) ); ?></td>
						<td class="lrn-row-actions lrn-col-actions">
							<?php
							echo \Learnomy\Admin\UX::row_actions( $lrn_row_actions, array( 'inline_max' => 2 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
