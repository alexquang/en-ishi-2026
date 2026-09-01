<?php
/**
 * Admin question bank view.
 *
 * Filter bar supports category, type, difficulty, status and author. The
 * list table renders one row per question with row actions (Edit, Duplicate,
 * Delete). CSV import/export controls live in the page header.
 *
 * @package Learnomy
 * @var object[] $questions          Paginated question rows (each carries `use_count`).
 * @var int      $total              Total count for active filters.
 * @var int      $total_pages        Total pages.
 * @var int      $paged              Current page.
 * @var int      $category_filter    Active category ID (0 = all).
 * @var string   $type_filter        Active question type filter ('all' or slug).
 * @var int      $difficulty_filter  Active difficulty (0 = all, 1–5).
 * @var string   $status_filter      Active status ('all', 'draft', 'published', 'archived').
 * @var int      $author_filter      Active author user ID (0 = all).
 * @var int      $tag_filter         Active tag ID (0 = all).
 * @var object[] $all_tags           Tags for the filter dropdown.
 * @var object[] $all_categories     All question categories for filter dropdown.
 * @var object[] $all_authors        Instructor / admin users for author filter.
 * @var array    $question_types     Map of slug => label from QuestionTypes::all().
 */

defined( 'ABSPATH' ) || exit;

$type_choices = array_merge( array( 'all' => __( 'All Types', 'learnomy' ) ), $question_types );

$difficulty_labels = array(
	1 => __( 'Beginner', 'learnomy' ),
	2 => __( 'Easy', 'learnomy' ),
	3 => __( 'Medium', 'learnomy' ),
	4 => __( 'Hard', 'learnomy' ),
	5 => __( 'Expert', 'learnomy' ),
);

$status_choices = array(
	'all'       => __( 'All Statuses', 'learnomy' ),
	'draft'     => __( 'Draft', 'learnomy' ),
	'published' => __( 'Published', 'learnomy' ),
	'archived'  => __( 'Archived', 'learnomy' ),
);

$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$base_url = admin_url( 'admin.php?page=learnomy-questions' );

$has_filters = $category_filter || 'all' !== $type_filter || $difficulty_filter || 'all' !== $status_filter || $author_filter || $tag_filter || $search;

// Bulk-action wiring — generic handler in list-page.js. Question bank
// only supports `delete`; handler issues DELETE /questions/{id} per row.
$lrn_list_attrs = sprintf(
	' data-list-resource="%s" data-list-rest-base="%s" data-list-nonce="%s"',
	'questions',
	esc_attr( rest_url( 'learnomy/v1/' ) ),
	esc_attr( wp_create_nonce( 'wp_rest' ) )
);
?>
<div class="wrap learnomy-admin"<?php echo $lrn_list_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values escaped above. ?>>
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="list-checks" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Question Bank', 'learnomy' ); ?>
			</h1>
		</div>
		<div class="lrn-page-header__actions">
			<button type="button" class="lrn-admin-btn" id="lrn-questions-export">
				<i data-lucide="download"></i> <?php esc_html_e( 'Export CSV', 'learnomy' ); ?>
			</button>
			<button type="button" class="lrn-admin-btn" id="lrn-questions-import">
				<i data-lucide="upload"></i> <?php esc_html_e( 'Import CSV', 'learnomy' ); ?>
			</button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-question-editor' ) ); ?>" class="lrn-admin-btn lrn-admin-btn--primary">
				<i data-lucide="plus"></i> <?php esc_html_e( 'Add Question', 'learnomy' ); ?>
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
	<div class="lrn-filter-bar">
		<form method="get" action="<?php echo esc_url( $base_url ); ?>">
			<input type="hidden" name="page" value="learnomy-questions">

			<select name="category_id" aria-label="<?php esc_attr_e( 'Category', 'learnomy' ); ?>">
				<option value="0"><?php esc_html_e( 'All Categories', 'learnomy' ); ?></option>
				<?php if ( empty( $all_categories ) ) : ?>
					<option value="" disabled>
						<?php esc_html_e( 'No categories yet (create one while adding a question)', 'learnomy' ); ?>
					</option>
				<?php else : ?>
					<?php foreach ( $all_categories as $cat ) : ?>
						<option value="<?php echo absint( $cat->id ); ?>"
								<?php selected( $category_filter, $cat->id ); ?>>
							<?php echo esc_html( $cat->name ); ?>
						</option>
					<?php endforeach; ?>
				<?php endif; ?>
			</select>

			<select name="type" aria-label="<?php esc_attr_e( 'Type', 'learnomy' ); ?>">
				<?php foreach ( $type_choices as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>"
							<?php selected( $type_filter, $slug ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="difficulty" aria-label="<?php esc_attr_e( 'Difficulty', 'learnomy' ); ?>">
				<option value="0"><?php esc_html_e( 'All Difficulties', 'learnomy' ); ?></option>
				<?php foreach ( $difficulty_labels as $level => $label ) : ?>
					<option value="<?php echo absint( $level ); ?>"
							<?php selected( $difficulty_filter, $level ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="status" aria-label="<?php esc_attr_e( 'Status', 'learnomy' ); ?>">
				<?php foreach ( $status_choices as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>"
							<?php selected( $status_filter, $slug ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php
			// Hidden for instructors: their view is force-scoped to their own
			// questions, so picking another author here changed nothing and
			// read as a broken control.
			if ( ! empty( $can_filter_by_author ) && ! empty( $all_authors ) ) :
				?>
				<select name="author_id" aria-label="<?php esc_attr_e( 'Author', 'learnomy' ); ?>">
					<option value="0"><?php esc_html_e( 'All Authors', 'learnomy' ); ?></option>
					<?php foreach ( $all_authors as $author ) : ?>
						<option value="<?php echo absint( $author->ID ); ?>"
								<?php selected( $author_filter, (int) $author->ID ); ?>>
							<?php echo esc_html( $author->display_name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<?php if ( ! empty( $all_tags ) ) : ?>
				<select name="tag_id" aria-label="<?php esc_attr_e( 'Tag', 'learnomy' ); ?>">
					<option value="0"><?php esc_html_e( 'All Tags', 'learnomy' ); ?></option>
					<?php foreach ( $all_tags as $tag_row ) : ?>
						<option value="<?php echo absint( $tag_row->id ); ?>"
								<?php selected( $tag_filter, (int) $tag_row->id ); ?>>
							<?php echo esc_html( $tag_row->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<button type="submit" class="lrn-admin-btn"><?php esc_html_e( 'Filter', 'learnomy' ); ?></button>

			<?php if ( $has_filters ) : ?>
				<a href="<?php echo esc_url( $base_url ); ?>" class="lrn-admin-btn">
					<?php esc_html_e( 'Clear', 'learnomy' ); ?>
				</a>
			<?php endif; ?>

			<input type="search" name="s" aria-label="<?php esc_attr_e( 'Search', 'learnomy' ); ?>" value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'Search questions…', 'learnomy' ); ?>" class="regular-text">
		</form>
	</div>

	<!-- Questions table -->
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
				<th scope="col"><?php esc_html_e( 'Question', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Category', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Difficulty', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Times Used', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Created', 'learnomy' ); ?></th>
				<th scope="col" class="lrn-col-actions"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $questions ) ) : ?>
				<tr>
					<td colspan="9" class="lrn-no-items">
						<?php
						$lrn_empty_state = array(
							'icon'        => 'help-circle',
							'title'       => __( 'No questions match these filters', 'learnomy' ),
							'description' => __( 'Clear the filters to see every question, or build your first question to add to the bank.', 'learnomy' ),
						);
						include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
						?>
					</td>
				</tr>
			<?php else : ?>
				<?php
				foreach ( $questions as $q ) :
					$q_type       = $q->type ?? 'mc_single';
					$q_type_label = isset( $question_types[ $q_type ] ) ? $question_types[ $q_type ] : ucfirst( str_replace( '_', ' ', $q_type ) );
					$difficulty   = (int) ( $q->difficulty ?? 1 );
					$diff_label   = $difficulty_labels[ $difficulty ] ?? __( 'Unknown', 'learnomy' );
					$status       = (string) ( $q->status ?? 'published' );
					$status_label = $status_choices[ $status ] ?? $status;

					$cat_name = '';
					if ( ! empty( $q->category_id ) ) {
						foreach ( $all_categories as $cat ) {
							if ( (int) $cat->id === (int) $q->category_id ) {
								$cat_name = $cat->name;
								break;
							}
						}
					}
					?>
					<tr>
						<td class="lrn-col-check"><input type="checkbox" class="lrn-row-check" value="<?php echo absint( $q->id ); ?>"></td>
						<td class="column-question">
							<?php echo esc_html( wp_trim_words( wp_strip_all_tags( $q->title ?? '' ), 18 ) ); ?>
						</td>
						<td>
							<?php echo \Learnomy\Admin\UX::status_badge( $q_type, $q_type_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
						<td>
							<?php echo $cat_name ? esc_html( $cat_name ) : '<span class="lrn-muted">' . esc_html__( 'Uncategorized', 'learnomy' ) . '</span>'; ?>
						</td>
						<td>
							<span class="lrn-difficulty lrn-difficulty--<?php echo esc_attr( (string) $difficulty ); ?>">
								<?php echo esc_html( $diff_label ); ?>
							</span>
						</td>
						<td>
							<?php echo \Learnomy\Admin\UX::status_badge( $status, $status_label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (int) ( $q->use_count ?? 0 ) ) ); ?></td>
						<td><?php echo esc_html( \Learnomy\date_label( $q->created_at, '—', get_option( 'date_format' ) ) ); ?></td>
						<td class="lrn-row-actions lrn-col-actions">
							<?php
							// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::render_action() escapes every label, href and attribute; the statement spans lines so a single-line ignore never covered it.
							echo \Learnomy\Admin\UX::row_actions(
								array(
									array(
										'label'   => __( 'Edit', 'learnomy' ),
										'href'    => admin_url( 'admin.php?page=learnomy-question-editor&question_id=' . absint( $q->id ) ),
										'icon'    => 'pencil',
										'primary' => true,
									),
									array(
										'label' => __( 'Duplicate', 'learnomy' ),
										'icon'  => 'copy',
										'attrs' => array(
											'class'       => 'lrn-action-link',
											'data-action' => 'learnomy_duplicate_question',
											'data-id'     => absint( $q->id ),
											'data-id-field' => 'question_id',
										),
									),
									array(
										'label'  => __( 'Delete', 'learnomy' ),
										'icon'   => 'trash-2',
										'danger' => true,
										'attrs'  => array(
											'class'        => 'lrn-action-link lrn-action-link--danger',
											'data-action'  => 'learnomy_delete_question',
											'data-id'      => absint( $q->id ),
											'data-id-field' => 'question_id',
											'data-confirm' => __( 'Delete this question?', 'learnomy' ),
										),
									),
								)
							);
							// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	</div>


	<?php
	// Carry every active filter into the page links so paging a filtered bank
	// (category / type / difficulty / status / author / tag / search) doesn't
	// reset to the full set.
	$lrn_paged_base = $base_url;
	if ( $category_filter ) {
		$lrn_paged_base = add_query_arg( 'category_id', $category_filter, $lrn_paged_base );
	}
	if ( 'all' !== $type_filter ) {
		$lrn_paged_base = add_query_arg( 'type', $type_filter, $lrn_paged_base );
	}
	if ( $difficulty_filter ) {
		$lrn_paged_base = add_query_arg( 'difficulty', $difficulty_filter, $lrn_paged_base );
	}
	if ( 'all' !== $status_filter ) {
		$lrn_paged_base = add_query_arg( 'status', $status_filter, $lrn_paged_base );
	}
	if ( $author_filter ) {
		$lrn_paged_base = add_query_arg( 'author_id', $author_filter, $lrn_paged_base );
	}
	if ( $tag_filter ) {
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

	<!-- CSV import modal -->
	<div class="lrn-modal lrn-modal--hidden" id="lrn-csv-import-modal" role="dialog" aria-modal="true" aria-labelledby="lrn-csv-import-title">
		<div class="lrn-modal__backdrop" data-lrn-modal-close></div>
		<div class="lrn-modal__dialog">
			<div class="lrn-modal__head">
				<h3 class="lrn-modal__title" id="lrn-csv-import-title"><?php esc_html_e( 'Import questions from CSV', 'learnomy' ); ?></h3>
				<button type="button" class="lrn-modal__close" data-lrn-modal-close aria-label="<?php esc_attr_e( 'Close', 'learnomy' ); ?>">
					<i data-lucide="x"></i>
				</button>
			</div>
			<div class="lrn-modal__body">
				<form id="lrn-csv-import-form" enctype="multipart/form-data">
					<p class="description">
						<?php esc_html_e( 'CSV columns: title, type, difficulty, category_slug, status, hint, body_text, answers_json.', 'learnomy' ); ?>
					</p>
					<div class="lrn-file-field">
						<input type="file" name="csv_file" id="lrn-csv-import-file" accept=".csv,text/csv" class="lrn-file-field__input" hidden required>
						<label for="lrn-csv-import-file" class="lrn-admin-btn"><i data-lucide="paperclip"></i><?php esc_html_e( 'Choose file', 'learnomy' ); ?></label>
						<span class="lrn-file-field__name" data-empty="<?php esc_attr_e( 'No file chosen', 'learnomy' ); ?>"><?php esc_html_e( 'No file chosen', 'learnomy' ); ?></span>
					</div>
					<div class="lrn-csv-import-result" id="lrn-csv-import-result" hidden></div>
				</form>
			</div>
			<div class="lrn-modal__foot">
				<button type="button" class="lrn-admin-btn" data-lrn-modal-close>
					<?php esc_html_e( 'Cancel', 'learnomy' ); ?>
				</button>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-csv-import-submit">
					<?php esc_html_e( 'Upload', 'learnomy' ); ?>
				</button>
			</div>
		</div>
	</div>

</div><!-- /.learnomy-admin -->
