<?php
/**
 * Admin lessons list view.
 *
 * @package Learnomy
 * @var object[] $lessons       Paginated top-level lesson rows.
 * @var array    $sub_lessons   Keyed by parent lesson ID, each an array of child lesson rows.
 * @var int      $total         Total lesson count for active filter.
 * @var int      $total_pages   Total number of pages.
 * @var int      $paged         Current page number.
 * @var string   $status_filter Active status filter slug.
 * @var string   $search        Current search string.
 * @var int      $course_filter Active course_id filter (0 = all).
 * @var object[] $all_courses   Courses for the filter dropdown.
 * @var int      $tag_filter    Active tag_id filter (0 = all).
 * @var object[] $all_tags      Tags for the filter dropdown.
 * @var array    $courses_map   Map of course_id => course row (batched, no N+1).
 * @var array    $sections_map  Map of section_id => section row (batched).
 */

defined( 'ABSPATH' ) || exit;

$status_tabs = array(
	'all'       => __( 'All', 'learnomy' ),
	'draft'     => __( 'Draft', 'learnomy' ),
	'published' => __( 'Published', 'learnomy' ),
);

$base_url = admin_url( 'admin.php?page=learnomy-lessons' );

/**
 * Build a filter-preserving URL for status tabs so switching tabs doesn't
 * drop the active course filter.
 */
$preserved_url = function ( string $status ) use ( $base_url, $course_filter, $tag_filter ): string {
	$url = add_query_arg( 'status', $status, $base_url );
	if ( $course_filter > 0 ) {
		$url = add_query_arg( 'course_id', $course_filter, $url );
	}
	if ( $tag_filter > 0 ) {
		$url = add_query_arg( 'tag_id', $tag_filter, $url );
	}
	return $url;
};

// Bulk-action wiring — generic handler in list-page.js. Lessons only
// support `delete`; the handler issues DELETE /lessons/{id} per row.
$lrn_list_attrs = sprintf(
	' data-list-resource="%s" data-list-rest-base="%s" data-list-nonce="%s"',
	'lessons',
	esc_attr( rest_url( 'learnomy/v1/' ) ),
	esc_attr( wp_create_nonce( 'wp_rest' ) )
);
?>
<div class="wrap learnomy-admin"<?php echo $lrn_list_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values escaped above. ?>>
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="book-text" class="lrn-page-header__icon"></i>
				<?php echo esc_html( \Learnomy\lesson_label( true ) ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-lesson-editor' ) ); ?>" class="lrn-admin-btn lrn-admin-btn--primary">
				<?php /* translators: %s: singular label for a lesson (e.g. "Lesson") */ ?>
				<i data-lucide="plus"></i> <?php printf( esc_html__( 'Add New %s', 'learnomy' ), esc_html( \Learnomy\lesson_label() ) ); ?>
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
			foreach ( $status_tabs as $slug => $label ) :
				$url        = $preserved_url( $slug );
				$is_current = ( $status_filter === $slug );
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"
						class="<?php echo esc_attr( $is_current ? 'current' : '' ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
					<?php echo 'published' !== $slug ? ' |' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="lrn-search-form">
			<input type="hidden" name="page" value="learnomy-lessons">
			<?php if ( 'all' !== $status_filter ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>">
			<?php endif; ?>

			<select name="course_id" class="lrn-filter-select" aria-label="<?php esc_attr_e( 'Filter by course', 'learnomy' ); ?>">
				<option value="0"><?php esc_html_e( 'All courses', 'learnomy' ); ?></option>
				<?php foreach ( $all_courses as $course_row ) : ?>
					<option value="<?php echo absint( $course_row->id ); ?>" <?php selected( $course_filter, (int) $course_row->id ); ?>>
						<?php echo esc_html( $course_row->title ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php if ( ! empty( $all_tags ) ) : ?>
				<select name="tag_id" class="lrn-filter-select" aria-label="<?php esc_attr_e( 'Filter by tag', 'learnomy' ); ?>">
					<option value="0"><?php esc_html_e( 'All tags', 'learnomy' ); ?></option>
					<?php foreach ( $all_tags as $tag_row ) : ?>
						<option value="<?php echo absint( $tag_row->id ); ?>" <?php selected( $tag_filter, (int) $tag_row->id ); ?>>
							<?php echo esc_html( $tag_row->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<input type="search" name="s" aria-label="<?php esc_attr_e( 'Search', 'learnomy' ); ?>" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search lessons…', 'learnomy' ); ?>" class="regular-text">
			<button type="submit" class="lrn-admin-btn"><?php esc_html_e( 'Filter', 'learnomy' ); ?></button>
		</form>
	</div>

	<!-- Lessons table -->
	<div class="lrn-bulk-bar lrn-bulk-bar--hidden">
		<div class="lrn-bulk-bar__left">
			<span class="lrn-bulk-bar__count">0 selected</span>
			<select id="lrn-bulk-action">
				<option value=""><?php esc_html_e( 'Bulk Actions', 'learnomy' ); ?></option>
				<option value="delete"><?php esc_html_e( 'Delete', 'learnomy' ); ?></option>
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
				<th scope="col"><?php esc_html_e( 'Course', 'learnomy' ); ?></th>
				<th scope="col" class="lrn-col-section"><?php esc_html_e( 'Section', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Duration', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Created', 'learnomy' ); ?></th>
				<th scope="col" class="lrn-col-actions"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $lessons ) ) : ?>
				<tr>
					<td colspan="9" class="lrn-no-items">
						<?php
						$lrn_empty_state = array(
							'icon'        => 'book-marked',
							'title'       => __( 'No lessons yet', 'learnomy' ),
							'description' => __( 'Lessons live inside courses. Open a course in the builder to add its first lesson.', 'learnomy' ),
							'cta'         => array(
								'url'   => admin_url( 'admin.php?page=learnomy-courses' ),
								'label' => __( 'Browse Courses', 'learnomy' ),
								'icon'  => 'arrow-right',
							),
						);
						include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $lessons as $lesson ) :
					// Resolved from the batched maps built in render_lessons() —
					// no per-row ::find() (big-site checklist #3, no N+1).
					$course       = ! empty( $lesson->course_id ) ? ( $courses_map[ (int) $lesson->course_id ] ?? null ) : null;
					$section      = ! empty( $lesson->section_id ) ? ( $sections_map[ (int) $lesson->section_id ] ?? null ) : null;
					$duration_sec = (int) $lesson->duration_seconds;
					$duration_lbl = $duration_sec > 0
						? sprintf(
							/* translators: 1: minutes, 2: seconds */
							__( '%1$dm %2$ds', 'learnomy' ),
							intdiv( $duration_sec, 60 ),
							$duration_sec % 60
						)
						: '&mdash;';

					$children        = ! empty( $sub_lessons[ $lesson->id ] ) ? $sub_lessons[ $lesson->id ] : array();
					$topic_count     = count( $children );
					$lesson_view_url = \Learnomy\Models\Lesson::get_permalink( $lesson, $course );
					?>
					<tr>
						<td class="lrn-col-check"><input type="checkbox" class="lrn-row-check" value="<?php echo absint( $lesson->id ); ?>"></td>
						<td class="column-title">
							<strong class="lrn-row-title"><?php echo esc_html( $lesson->title ); ?></strong>
							<?php if ( $topic_count > 0 ) : ?>
								<span class="lrn-topic-badge">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: number of topics (child lessons) */
											_n( '%d topic', '%d topics', $topic_count, 'learnomy' ),
											$topic_count
										)
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $course ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . absint( $course->id ) ) ); ?>">
									<?php echo esc_html( $course->title ); ?>
								</a>
							<?php else : ?>
								<span class="lrn-muted">&mdash;</span>
							<?php endif; ?>
						</td>
						<td class="lrn-col-section">
							<?php if ( $section ) : ?>
								<?php echo esc_html( $section->title ); ?>
							<?php else : ?>
								<span class="lrn-muted">&mdash;</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( \Learnomy\Models\Lesson::content_type_label( (string) ( $lesson->content_type ?? 'text' ) ) ); ?></td>
						<td>
							<?php echo \Learnomy\Admin\UX::status_badge( (string) $lesson->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
						<td><?php echo wp_kses_post( $duration_lbl ); ?></td>
						<td><?php echo esc_html( \Learnomy\date_label( $lesson->created_at, '—', get_option( 'date_format' ) ) ); ?></td>
						<td class="lrn-row-actions lrn-col-actions">
							<?php
							$lrn_lesson_actions = array(
								array(
									'label'   => __( 'Edit', 'learnomy' ),
									'href'    => admin_url( 'admin.php?page=learnomy-lesson-editor&lesson_id=' . absint( $lesson->id ) ),
									'icon'    => 'pencil',
									'primary' => true,
									'attrs'   => array( 'class' => 'lrn-action-link' ),
								),
							);
							if ( $lesson_view_url ) {
								$lrn_lesson_actions[] = array(
									'label'  => __( 'View', 'learnomy' ),
									'href'   => $lesson_view_url,
									'icon'   => 'eye',
									'target' => '_blank',
									'attrs'  => array(
										'class' => 'lrn-action-link',
										'rel'   => 'noopener',
									),
								);
							}
							$lrn_lesson_actions[] = array(
								'label'  => __( 'Delete', 'learnomy' ),
								'icon'   => 'trash-2',
								'danger' => true,
								'attrs'  => array(
									'class'          => 'lrn-action-link lrn-action-link--danger',
									'data-action'    => 'learnomy_delete_lesson',
									'data-lesson-id' => absint( $lesson->id ),
									'data-confirm'   => __( 'Delete this lesson? This cannot be undone.', 'learnomy' ),
								),
							);
							echo \Learnomy\Admin\UX::row_actions( $lrn_lesson_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
					</tr>

					<?php
					// Render sub-lessons (topics) indented under the parent.
					foreach ( $children as $child ) :
						$child_duration_sec = (int) $child->duration_seconds;
						$child_duration_lbl = $child_duration_sec > 0
							? sprintf(
								/* translators: 1: minutes, 2: seconds */
								__( '%1$dm %2$ds', 'learnomy' ),
								intdiv( $child_duration_sec, 60 ),
								$child_duration_sec % 60
							)
							: '&mdash;';
						$child_view_url = \Learnomy\Models\Lesson::get_permalink( $child, $course );
						?>
						<tr class="lrn-sub-lesson-row">
							<td class="lrn-col-check"><input type="checkbox" class="lrn-row-check" value="<?php echo absint( $child->id ); ?>"></td>
							<td class="column-title">
								<span class="lrn-row-title lrn-row-title--sub">└ <?php echo esc_html( $child->title ); ?></span>
								<?php
								printf(
									' <span class="lrn-muted lrn-sub-lesson-meta">%s</span>',
									esc_html(
										sprintf(
											/* translators: %s: parent lesson title */
											__( 'Topic of "%s"', 'learnomy' ),
											$lesson->title
										)
									)
								);
								?>
							</td>
							<td>
								<?php if ( $course ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . absint( $course->id ) ) ); ?>">
										<?php echo esc_html( $course->title ); ?>
									</a>
								<?php else : ?>
									<span class="lrn-muted">&mdash;</span>
								<?php endif; ?>
							</td>
							<td class="lrn-col-section">
								<?php if ( $section ) : ?>
									<?php echo esc_html( $section->title ); ?>
								<?php else : ?>
									<span class="lrn-muted">&mdash;</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( \Learnomy\Models\Lesson::content_type_label( (string) ( $child->content_type ?? 'text' ) ) ); ?></td>
							<td>
								<?php echo \Learnomy\Admin\UX::status_badge( (string) $child->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</td>
							<td><?php echo wp_kses_post( $child_duration_lbl ); ?></td>
							<td><?php echo esc_html( \Learnomy\date_label( $child->created_at, '—', get_option( 'date_format' ) ) ); ?></td>
							<td class="lrn-row-actions lrn-col-actions">
								<?php
								$lrn_child_actions = array(
									array(
										'label'   => __( 'Edit', 'learnomy' ),
										'href'    => admin_url( 'admin.php?page=learnomy-lesson-editor&lesson_id=' . absint( $child->id ) ),
										'icon'    => 'pencil',
										'primary' => true,
										'attrs'   => array( 'class' => 'lrn-action-link' ),
									),
								);
								if ( $child_view_url ) {
									$lrn_child_actions[] = array(
										'label'  => __( 'View', 'learnomy' ),
										'href'   => $child_view_url,
										'icon'   => 'eye',
										'target' => '_blank',
										'attrs'  => array(
											'class' => 'lrn-action-link',
											'rel'   => 'noopener',
										),
									);
								}
								$lrn_child_actions[] = array(
									'label'  => __( 'Delete', 'learnomy' ),
									'icon'   => 'trash-2',
									'danger' => true,
									'attrs'  => array(
										'class'          => 'lrn-action-link lrn-action-link--danger',
										'data-action'    => 'learnomy_delete_lesson',
										'data-lesson-id' => absint( $child->id ),
										/* translators: %s: the Topic label, renamable in Settings > Labels. */
										'data-confirm'   => sprintf( __( 'Delete this %s? This cannot be undone.', 'learnomy' ), \Learnomy\topic_label() ),
									),
								);
								echo \Learnomy\Admin\UX::row_actions( $lrn_child_actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							</td>
						</tr>
					<?php endforeach; ?>

				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	</div>


	<?php
	// Carry every active filter into the page links so paging a filtered list
	// (status / course / tag / search) doesn't reset to the full set.
	$lrn_paged_base = $base_url;
	if ( 'all' !== $status_filter ) {
		$lrn_paged_base = add_query_arg( 'status', $status_filter, $lrn_paged_base );
	}
	if ( $course_filter > 0 ) {
		$lrn_paged_base = add_query_arg( 'course_id', $course_filter, $lrn_paged_base );
	}
	if ( $tag_filter > 0 ) {
		$lrn_paged_base = add_query_arg( 'tag_id', $tag_filter, $lrn_paged_base );
	}
	if ( '' !== $search ) {
		$lrn_paged_base = add_query_arg( 's', rawurlencode( $search ), $lrn_paged_base );
	}

	$lrn_pagination = array(
		'current'  => (int) $paged,
		'total'    => (int) $total_pages,
		'results'  => (int) $total,
		'per_page' => isset( $per_page ) ? (int) $per_page : 20,
		'base_url' => $lrn_paged_base,
	);
	require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
	?>

</div><!-- /.learnomy-admin -->
