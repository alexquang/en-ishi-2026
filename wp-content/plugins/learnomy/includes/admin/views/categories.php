<?php
/**
 * Admin categories and tags management view — tabbed layout.
 *
 * @package Learnomy
 * @var object[] $all_categories All course categories.
 * @var object[] $all_tags        Tags for the active type vocabulary.
 * @var array    $tag_types       Map of tag type => label (registered vocabularies).
 * @var array    $tag_type_counts Map of tag type => count.
 * @var string   $active_tag_type The currently selected tag vocabulary.
 * @var int       $tags_total      Total tags in the active vocabulary (COUNT(*)).
 * @var int       $tags_paged      Current 1-based page of the tag list.
 * @var int       $tags_pages      Total pages of the tag list.
 * @var int       $tags_per_page   Tags shown per page.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'categories';
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="folder-tree" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Categories & Tags', 'learnomy' ); ?>
			</h1>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<!-- ── Tab navigation ─────────────────────────────────────────────── -->
	<div class="lrn-tab-nav" role="tablist">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-categories&tab=categories' ) ); ?>"
			class="lrn-tab-nav__item <?php echo 'categories' === $active_tab ? 'lrn-tab-nav__item--active' : ''; ?>"
			role="tab"
			aria-selected="<?php echo 'categories' === $active_tab ? 'true' : 'false'; ?>">
			<i data-lucide="folder-tree" aria-hidden="true"></i>
			<?php echo esc_html( \Learnomy\category_label( true ) ); ?>
			<span class="lrn-tab-nav__count"><?php echo esc_html( count( $all_categories ) ); ?></span>
		</a>
		<?php
		// One top-level tab per registered tag vocabulary (Course Tags, Lesson
		// Tags, Quiz Tags, Question Tags, …). Driven by registered_tag_types()
		// so a Pro-registered vocabulary adds its own tab with no code change.
		foreach ( $tag_types as $lrn_type => $lrn_label ) :
			$lrn_tab_active = ( 'tags' === $active_tab && $active_tag_type === $lrn_type );
			?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-categories&tab=tags&tag_type=' . $lrn_type ) ); ?>"
				class="lrn-tab-nav__item <?php echo $lrn_tab_active ? 'lrn-tab-nav__item--active' : ''; ?>"
				role="tab"
				aria-selected="<?php echo $lrn_tab_active ? 'true' : 'false'; ?>">
				<i data-lucide="tag" aria-hidden="true"></i>
				<?php
				/* translators: %s: content type label, e.g. "Course" → "Course Tags" */
				printf( esc_html__( '%s Tags', 'learnomy' ), esc_html( $lrn_label ) );
				?>
				<span class="lrn-tab-nav__count"><?php echo esc_html( (int) ( $tag_type_counts[ $lrn_type ] ?? 0 ) ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>

	<?php if ( 'categories' === $active_tab ) : ?>
	<!-- ── Categories ─────────────────────────────────────────────────── -->
	<div class="lrn-tab-panel" role="tabpanel">
		<div class="lrn-split-layout">

			<!-- Add / Edit form -->
			<div class="lrn-split-layout__form" id="lrn-category-form-wrap">
				<div class="lrn-admin-card">
					<?php /* translators: %s: singular label for a category (e.g. "Category") */ ?>
					<h3 class="lrn-admin-card__title"><?php printf( esc_html__( 'Add New %s', 'learnomy' ), esc_html( \Learnomy\category_label() ) ); ?></h3>
					<p class="lrn-admin-card__desc"><?php esc_html_e( 'Categories group courses by subject area. They support hierarchy: a category can have a parent.', 'learnomy' ); ?></p>
					<div class="lrn-form-grid">
						<div class="lrn-form-field">
							<label for="lrn-cat-name"><?php esc_html_e( 'Name', 'learnomy' ); ?> <span class="required" aria-hidden="true">*</span></label>
							<input type="text" id="lrn-cat-name" class="regular-text" required>
							<p class="lrn-form-help"><?php esc_html_e( 'The name is how it appears on your site.', 'learnomy' ); ?></p>
						</div>
						<div class="lrn-form-field">
							<label for="lrn-cat-slug"><?php esc_html_e( 'Slug', 'learnomy' ); ?></label>
							<input type="text" id="lrn-cat-slug" class="regular-text"
									placeholder="<?php esc_attr_e( 'Auto-generated from name', 'learnomy' ); ?>">
							<p class="lrn-form-help"><?php esc_html_e( 'The URL-friendly version of the name. Usually lowercase with hyphens.', 'learnomy' ); ?></p>
						</div>
						<div class="lrn-form-field">
							<?php /* translators: %s: singular label for a category (e.g. "Category") */ ?>
							<label for="lrn-cat-parent"><?php printf( esc_html__( 'Parent %s', 'learnomy' ), esc_html( \Learnomy\category_label() ) ); ?></label>
							<select id="lrn-cat-parent">
								<option value="0"><?php esc_html_e( 'None', 'learnomy' ); ?></option>
								<?php foreach ( $all_categories as $cat ) : ?>
									<option value="<?php echo absint( $cat->id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="lrn-form-help"><?php esc_html_e( 'Categories can be nested. Assign a parent to create sub-categories.', 'learnomy' ); ?></p>
						</div>
						<div class="lrn-form-field lrn-form-field--wide">
							<label for="lrn-cat-description"><?php esc_html_e( 'Description', 'learnomy' ); ?></label>
							<textarea id="lrn-cat-description" rows="3" class="large-text"></textarea>
							<p class="lrn-form-help"><?php esc_html_e( 'Displayed on the category archive page. Some themes may also show it elsewhere.', 'learnomy' ); ?></p>
						</div>
					</div>
					<div class="lrn-form-actions">
						<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-save-category">
							<?php /* translators: %s: singular label for a category (e.g. "Category") */ ?>
							<?php printf( esc_html__( 'Add %s', 'learnomy' ), esc_html( \Learnomy\category_label() ) ); ?>
						</button>
						<button type="button" class="lrn-admin-btn lrn-cancel-edit" id="lrn-cancel-category-edit" hidden>
							<?php esc_html_e( 'Cancel', 'learnomy' ); ?>
						</button>
						<span class="spinner"></span>
					</div>
				</div>
			</div>

			<!-- Categories table -->
			<div class="lrn-split-layout__table">
				<div class="lrn-admin-card">
					<h3 class="lrn-admin-card__title">
						<i data-lucide="folder-tree" class="lrn-icon--sm" aria-hidden="true"></i>
						<?php esc_html_e( 'All Categories', 'learnomy' ); ?>
					</h3>
					<?php if ( empty( $all_categories ) ) : ?>
						<div class="lrn-empty-state">
							<i data-lucide="folder-tree" class="lrn-empty-state__icon" aria-hidden="true"></i>
							<h3 class="lrn-empty-state__title"><?php esc_html_e( 'No categories yet', 'learnomy' ); ?></h3>
							<p class="lrn-empty-state__desc"><?php esc_html_e( 'Create your first category to organise courses by subject area.', 'learnomy' ); ?></p>
						</div>
					<?php else : ?>
						<div class="lrn-admin-table-wrap">
						<table class="lrn-admin-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Name', 'learnomy' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Slug', 'learnomy' ); ?></th>
									<th scope="col" class="lrn-admin-table__cell--right"><?php esc_html_e( 'Courses', 'learnomy' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
								</tr>
							</thead>
							<tbody id="lrn-categories-list">
								<?php foreach ( $all_categories as $cat ) : ?>
									<tr data-id="<?php echo absint( $cat->id ); ?>">
										<td>
											<strong class="lrn-row-title"><?php echo esc_html( $cat->name ); ?></strong>
											<?php if ( ! empty( $cat->description ) ) : ?>
												<p class="lrn-row-excerpt"><?php echo esc_html( wp_trim_words( $cat->description, 10 ) ); ?></p>
											<?php endif; ?>
										</td>
										<td><code class="lrn-slug"><?php echo esc_html( $cat->slug ); ?></code></td>
										<td class="lrn-admin-table__cell--right"><?php echo esc_html( number_format_i18n( (int) ( $cat->course_count ?? 0 ) ) ); ?></td>
										<td class="lrn-col-actions">
											<?php
											// Family row-actions (was pipe-separated .lrn-action-link text
											// links). JS hooks (.lrn-edit-category / .lrn-delete-category +
											// data-*) are preserved via attrs so categories.js keeps binding.
											$lrn_cat_actions = array(
												array(
													'label' => __( 'Edit', 'learnomy' ),
													'icon' => 'pencil',
													'primary' => true,
													'attrs' => array(
														'class'            => 'lrn-edit-category',
														'data-id'          => (string) absint( $cat->id ),
														'data-name'        => (string) $cat->name,
														'data-slug'        => (string) $cat->slug,
														'data-description' => (string) ( $cat->description ?? '' ),
														'data-parent'      => (string) absint( $cat->parent_id ?? 0 ),
													),
												),
												array(
													'label' => __( 'Delete', 'learnomy' ),
													'icon' => 'trash-2',
													'danger' => true,
													'attrs' => array(
														'class'             => 'lrn-delete-category',
														'data-id'           => (string) absint( $cat->id ),
														'data-course-count' => (string) absint( $cat->course_count ?? 0 ),
													),
												),
											);
											// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::row_actions() escapes internally.
											echo \Learnomy\Admin\UX::row_actions( $lrn_cat_actions );
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						</div>
					<?php endif; ?>
				</div>
			</div>

		</div><!-- /.lrn-split-layout -->
	</div><!-- /.lrn-tab-panel -->

	<?php else : ?>
	<!-- ── Tags ───────────────────────────────────────────────────────── -->
	<div class="lrn-tab-panel" role="tabpanel">

		<?php
		// The active vocabulary is selected by the top-level tab nav above; this
		// label drives the Add form heading + the list title.
		$lrn_active_tag_label = $tag_types[ $active_tag_type ] ?? ucfirst( $active_tag_type );
		?>

		<div class="lrn-split-layout">

			<!-- Add tag form -->
			<div class="lrn-split-layout__form">
				<div class="lrn-admin-card">
					<h3 class="lrn-admin-card__title">
						<?php
						/* translators: %s: content type label, e.g. Lesson */
						printf( esc_html__( 'Add New %s Tag', 'learnomy' ), esc_html( $lrn_active_tag_label ) );
						?>
					</h3>
					<p class="lrn-admin-card__desc"><?php esc_html_e( 'Tags are flat labels for cross-cutting topics. Unlike categories, they cannot be nested. Each content type keeps its own set.', 'learnomy' ); ?></p>
					<input type="hidden" id="lrn-tag-type" value="<?php echo esc_attr( $active_tag_type ); ?>">
					<div class="lrn-form-grid">
						<div class="lrn-form-field">
							<label for="lrn-tag-name"><?php esc_html_e( 'Name', 'learnomy' ); ?> <span class="required" aria-hidden="true">*</span></label>
							<input type="text" id="lrn-tag-name" class="regular-text" required>
							<p class="lrn-form-help"><?php esc_html_e( 'The name is how it appears on your site.', 'learnomy' ); ?></p>
						</div>
						<div class="lrn-form-field">
							<label for="lrn-tag-slug"><?php esc_html_e( 'Slug', 'learnomy' ); ?></label>
							<input type="text" id="lrn-tag-slug" class="regular-text"
									placeholder="<?php esc_attr_e( 'Auto-generated from name', 'learnomy' ); ?>">
							<p class="lrn-form-help"><?php esc_html_e( 'The URL-friendly version of the name.', 'learnomy' ); ?></p>
						</div>
					</div>
					<div class="lrn-form-actions">
						<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-save-tag">
							<?php esc_html_e( 'Add Tag', 'learnomy' ); ?>
						</button>
						<button type="button" class="lrn-admin-btn lrn-cancel-edit" id="lrn-cancel-tag-edit" hidden>
							<?php esc_html_e( 'Cancel', 'learnomy' ); ?>
						</button>
						<span class="spinner"></span>
					</div>
				</div>
			</div>

			<!-- Tags table -->
			<div class="lrn-split-layout__table">
				<div class="lrn-admin-card">
					<h3 class="lrn-admin-card__title">
						<i data-lucide="tag" class="lrn-icon--sm" aria-hidden="true"></i>
						<?php
						/* translators: %s: content type label, e.g. Lesson */
						printf( esc_html__( 'All %s Tags', 'learnomy' ), esc_html( $lrn_active_tag_label ) );
						?>
					</h3>
					<?php if ( empty( $all_tags ) ) : ?>
						<div class="lrn-empty-state">
							<i data-lucide="tag" class="lrn-empty-state__icon" aria-hidden="true"></i>
							<h3 class="lrn-empty-state__title"><?php esc_html_e( 'No tags yet', 'learnomy' ); ?></h3>
							<p class="lrn-empty-state__desc"><?php esc_html_e( 'Create your first tag to label courses with cross-cutting topics.', 'learnomy' ); ?></p>
						</div>
					<?php else : ?>
						<div class="lrn-admin-table-wrap">
						<table class="lrn-admin-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Name', 'learnomy' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Slug', 'learnomy' ); ?></th>
									<th scope="col" class="lrn-admin-table__cell--right"><?php echo esc_html( $tag_count_label ); ?></th>
									<th scope="col"><?php esc_html_e( 'Actions', 'learnomy' ); ?></th>
								</tr>
							</thead>
							<tbody id="lrn-tags-list">
								<?php foreach ( $all_tags as $tag ) : ?>
									<tr data-id="<?php echo absint( $tag->id ); ?>">
										<td><strong class="lrn-row-title"><?php echo esc_html( $tag->name ); ?></strong></td>
										<td><code class="lrn-slug"><?php echo esc_html( $tag->slug ); ?></code></td>
										<td class="lrn-admin-table__cell--right"><?php echo esc_html( number_format_i18n( (int) ( $tag->usage_count ?? 0 ) ) ); ?></td>
										<td class="lrn-col-actions">
											<?php
											// Family row-actions (was pipe-separated links). JS hooks
											// (.lrn-edit-tag / .lrn-delete-tag + data-*) preserved via attrs.
											$lrn_tag_actions = array(
												array(
													'label' => __( 'Edit', 'learnomy' ),
													'icon' => 'pencil',
													'primary' => true,
													'attrs' => array(
														'class'     => 'lrn-edit-tag',
														'data-id'   => (string) absint( $tag->id ),
														'data-name' => (string) $tag->name,
														'data-slug' => (string) $tag->slug,
													),
												),
												array(
													'label' => __( 'Delete', 'learnomy' ),
													'icon' => 'trash-2',
													'danger' => true,
													'attrs' => array(
														'class'   => 'lrn-delete-tag',
														'data-id' => (string) absint( $tag->id ),
													),
												),
											);
											// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- UX::row_actions() escapes internally.
											echo \Learnomy\Admin\UX::row_actions( $lrn_tag_actions );
											?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						</div>
						<?php
						$lrn_pagination = array(
							'current'  => (int) $tags_paged,
							'total'    => (int) $tags_pages,
							'results'  => (int) $tags_total,
							'per_page' => (int) $tags_per_page,
							'base_url' => add_query_arg(
								array(
									'page'     => 'learnomy-categories',
									'tab'      => 'tags',
									'tag_type' => $active_tag_type,
								),
								admin_url( 'admin.php' )
							),
						);
						require LEARNOMY_DIR . 'includes/admin/views/partials/pagination.php';
						?>
					<?php endif; ?>
				</div>
			</div>

		</div><!-- /.lrn-split-layout -->
	</div><!-- /.lrn-tab-panel -->
	<?php endif; ?>

</div><!-- /.learnomy-admin -->
