<?php
/**
 * Admin quizzes list view.
 *
 * @package Learnomy
 * @var object[] $quizzes     Paginated quiz rows.
 * @var int      $total       Total quiz count for active filter.
 * @var int      $total_pages Total number of pages.
 * @var int      $paged       Current page number.
 * @var string   $type_filter Active quiz type filter.
 * @var array    $courses_map Map of course_id => course row (batched, no N+1).
 * @var array    $lessons_map Map of lesson_id => lesson row (batched, no N+1).
 */

defined( 'ABSPATH' ) || exit;

$type_tabs = array(
	'all'      => __( 'All', 'learnomy' ),
	'graded'   => __( 'Graded', 'learnomy' ),
	'practice' => __( 'Practice', 'learnomy' ),
	'survey'   => __( 'Survey', 'learnomy' ),
);

// Base URL carries the active filters so pagination + the search form keep
// them on the next page (big-site checklist #1). The type tabs replace only
// `type` via add_query_arg, so they inherit the other filters too.
$base_url           = admin_url( 'admin.php?page=learnomy-quizzes' );
$lrn_active_filters = array();
if ( 'all' !== $type_filter ) {
	$lrn_active_filters['type'] = $type_filter;
}
if ( ! empty( $course_filter ) ) {
	$lrn_active_filters['course_id'] = (int) $course_filter;
}
if ( ! empty( $tag_filter ) ) {
	$lrn_active_filters['tag_id'] = (int) $tag_filter;
}
if ( ! empty( $search ) ) {
	$lrn_active_filters['s'] = $search;
}
if ( $lrn_active_filters ) {
	$base_url = add_query_arg( $lrn_active_filters, $base_url );
}

// Bulk-action wiring — generic handler in list-page.js. Quizzes only
// support `delete`; handler issues DELETE /quizzes/{id} per row.
$lrn_list_attrs = sprintf(
	' data-list-resource="%s" data-list-rest-base="%s" data-list-nonce="%s"',
	'quizzes',
	esc_attr( rest_url( 'learnomy/v1/' ) ),
	esc_attr( wp_create_nonce( 'wp_rest' ) )
);
?>
<div class="wrap learnomy-admin"<?php echo $lrn_list_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values escaped above. ?>>
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="help-circle" class="lrn-page-header__icon"></i>
				<?php echo esc_html( \Learnomy\quiz_label( true ) ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-edit-quiz' ) ); ?>" class="lrn-admin-btn lrn-admin-btn--primary">
				<?php /* translators: %s: singular label for a quiz (e.g. "Quiz") */ ?>
				<i data-lucide="plus"></i> <?php printf( esc_html__( 'Add New %s', 'learnomy' ), esc_html( \Learnomy\quiz_label() ) ); ?>
			</a>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<!-- Type filter tabs -->
	<div class="lrn-admin-filters">
		<ul class="subsubsub">
			<?php
			foreach ( $type_tabs as $slug => $label ) :
				$url        = add_query_arg( 'type', $slug, $base_url );
				$is_current = ( $type_filter === $slug );
				?>
				<li>
					<a href="<?php echo esc_url( $url ); ?>"
						class="<?php echo esc_attr( $is_current ? 'current' : '' ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
					<?php echo 'survey' !== $slug ? ' |' : ''; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="lrn-search-form">
			<input type="hidden" name="page" value="learnomy-quizzes">
			<?php if ( 'all' !== $type_filter ) : ?>
				<input type="hidden" name="type" value="<?php echo esc_attr( $type_filter ); ?>">
			<?php endif; ?>
			<?php if ( ! empty( $all_courses ) ) : ?>
				<select name="course_id" aria-label="<?php esc_attr_e( 'Filter by course', 'learnomy' ); ?>">
					<option value=""><?php esc_html_e( 'All courses', 'learnomy' ); ?></option>
					<?php foreach ( $all_courses as $ac ) : ?>
						<option value="<?php echo absint( $ac->id ); ?>" <?php selected( (int) ( $course_filter ?? 0 ), (int) $ac->id ); ?>>
							<?php echo esc_html( $ac->title ); ?>
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
			<input type="search" name="s" aria-label="<?php esc_attr_e( 'Search', 'learnomy' ); ?>" value="<?php echo esc_attr( $search ?? '' ); ?>"
					placeholder="<?php esc_attr_e( 'Search quizzes…', 'learnomy' ); ?>" class="regular-text">
			<button type="submit" class="lrn-admin-btn"><?php esc_html_e( 'Filter', 'learnomy' ); ?></button>
		</form>
	</div>

	<!-- Quizzes table -->
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
				<th scope="col"><?php esc_html_e( 'Type', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Questions', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Attempts', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Pass Rate', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Course', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Created', 'learnomy' ); ?></th>
				<th scope="col" class="lrn-col-actions"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $quizzes ) ) : ?>
				<tr>
					<td colspan="10" class="lrn-no-items">
						<?php
						$lrn_empty_state = array(
							'icon'        => 'help-circle',
							'title'       => __( 'No quizzes yet', 'learnomy' ),
							'description' => __( 'Quizzes test what students have learned. Build one inside a course or as a standalone challenge.', 'learnomy' ),
						);
						include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $quizzes as $quiz ) :
					// Titles resolved from the batched maps (no per-row ::find()).
					$course_title = '';
					$lesson_title = '';
					if ( ! empty( $quiz->course_id ) && isset( $courses_map[ (int) $quiz->course_id ] ) ) {
						$course_title = $courses_map[ (int) $quiz->course_id ]->title;
					}
					if ( ! empty( $quiz->lesson_id ) && isset( $lessons_map[ (int) $quiz->lesson_id ] ) ) {
						$lesson_title = $lessons_map[ (int) $quiz->lesson_id ]->title;
					}

					// Column is `passing_score` on lrn_quizzes — `pass_mark` does not exist.
					$pass_rate_label = ! empty( $quiz->passing_score ) ? absint( $quiz->passing_score ) . '%' : '&mdash;';
					?>
					<tr>
						<td class="lrn-col-check"><input type="checkbox" class="lrn-row-check" value="<?php echo absint( $quiz->id ); ?>"></td>
						<td class="column-title">
							<strong><?php echo esc_html( $quiz->title ); ?></strong>
						</td>
						<td>
							<?php echo \Learnomy\Admin\UX::status_badge( $quiz->mode ?? 'graded' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $quiz->question_count ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $quiz->attempt_count ?? 0 ) ) ); ?></td>
						<td><?php echo wp_kses_post( $pass_rate_label ); ?></td>
						<td>
							<?php if ( $course_title ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . absint( $quiz->course_id ) ) ); ?>">
									<?php echo esc_html( $course_title ); ?>
								</a>
								<?php if ( $lesson_title ) : ?>
									<div class="lrn-muted">
										<i data-lucide="corner-down-right" class="lrn-icon-inline" aria-hidden="true"></i>
										<?php echo esc_html( $lesson_title ); ?>
									</div>
								<?php endif; ?>
							<?php else : ?>
								<span class="lrn-muted"><?php esc_html_e( 'Standalone', 'learnomy' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo \Learnomy\Admin\UX::status_badge( (string) ( $quiz->status ?? 'draft' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo esc_html( \Learnomy\date_label( $quiz->created_at, '—', get_option( 'date_format' ) ) ); ?></td>
						<td class="lrn-row-actions lrn-col-actions">
							<?php
							$lrn_quiz_status  = (string) ( $quiz->status ?? 'draft' );
							$lrn_quiz_actions = array(
								array(
									'label'   => __( 'Edit', 'learnomy' ),
									'href'    => admin_url( 'admin.php?page=learnomy-edit-quiz&quiz_id=' . absint( $quiz->id ) ),
									'icon'    => 'pencil',
									'primary' => true,
								),
								'published' !== $lrn_quiz_status
									? array(
										'label' => __( 'Publish', 'learnomy' ),
										'icon'  => 'check-circle',
										'attrs' => array(
											'class'       => 'lrn-action-link',
											'data-action' => 'learnomy_update_quiz_status',
											'data-id'     => absint( $quiz->id ),
											'data-id-field' => 'quiz_id',
											'data-status' => 'published',
										),
									)
									: array(
										'label' => __( 'Move to draft', 'learnomy' ),
										'icon'  => 'file-text',
										'attrs' => array(
											'class'       => 'lrn-action-link',
											'data-action' => 'learnomy_update_quiz_status',
											'data-id'     => absint( $quiz->id ),
											'data-id-field' => 'quiz_id',
											'data-status' => 'draft',
										),
									),
								array(
									'label'  => __( 'Delete', 'learnomy' ),
									'icon'   => 'trash-2',
									'danger' => true,
									'attrs'  => array(
										'class'         => 'lrn-action-link lrn-action-link--danger',
										'data-action'   => 'learnomy_delete_quiz',
										'data-id'       => absint( $quiz->id ),
										'data-id-field' => 'quiz_id',
										'data-confirm'  => __( 'Delete this quiz?', 'learnomy' ),
									),
								),
							);
							echo \Learnomy\Admin\UX::row_actions( $lrn_quiz_actions, array( 'inline_max' => 2 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
