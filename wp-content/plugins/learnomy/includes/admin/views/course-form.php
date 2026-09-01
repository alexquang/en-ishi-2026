<?php
/**
 * Admin course creation / editing form — Phase 1 premium UX.
 *
 * Six collapsible settings sections sit in the main column between the
 * Title/Description cards and the sidebar. Sidebar keeps the publish
 * box, category picker, curriculum entry, featured image picker, and
 * instructors so nothing regresses from the pre-sprint form.
 *
 * @package Learnomy
 * @var object|null $course     Course object when editing, null when creating.
 * @var object[]    $categories All course categories for the dropdown.
 * @var bool        $is_edit    True when editing an existing course.
 */

defined( 'ABSPATH' ) || exit;

$page_title = $is_edit
	/* translators: %s: course title */
	? sprintf( __( 'Edit Course: %s', 'learnomy' ), $course->title ?? '' )
	: __( 'Add New Course', 'learnomy' );

// Instructor assignment + the co-instructor revenue split live on the
// course-detail hub (admin.php?page=learnomy-course-detail) to keep this editor
// short. The per-course PLATFORM commission stayed here, beside pricing — see
// the settings-section block below.

// Prerequisites — every other course regardless of status so unpublishing a
// prerequisite doesn't silently drop it from the UI.
$all_courses = $is_edit
	? \Learnomy\Models\Course::where( array(), 'title', 'ASC', 500, 0 )
	: array();

// One decoder — see Course::prerequisite_ids().
$current_prereqs = $is_edit ? \Learnomy\Models\Course::prerequisite_ids( $course ) : array();

// Full settings bag — defaults merged — so every toggle/field reflects the
// saved state without per-key guards inside the partials.
$course_settings = $is_edit && ! empty( $course->id )
	? \Learnomy\Models\Course::get_all_settings( (int) $course->id )
	: \Learnomy\Models\Course::default_settings();

// Certificate templates — the picker in Completion section reads this array.
// Scoped to what the editing user may use: admins see all, instructors see the
// curated library plus any template granted to them.
$certificate_templates = \Learnomy\certificate_pick_list( get_current_user_id() );
if ( ! is_array( $certificate_templates ) ) {
	$certificate_templates = array();
}

// Taxonomies — registry-driven so site owners / Pro can add more course
// filters (Level, Format, Topic…) via the `learnomy_course_taxonomies` filter
// without touching this form. Each taxonomy is a tag `type`; the course's
// current term IDs are type-agnostic (one set spanning all taxonomies).
$course_taxonomies = \Learnomy\course_taxonomies();
$course_tag_ids    = ( $is_edit && ! empty( $course->id ) )
	? \Learnomy\Models\Tag::get_ids_for_course( (int) $course->id )
	: array();
$taxonomy_terms    = array();
foreach ( $course_taxonomies as $lrn_tax ) {
	$lrn_terms                          = \Learnomy\Models\Tag::where( array( 'type' => $lrn_tax['type'] ), 'name', 'ASC', 500, 0 );
	$taxonomy_terms[ $lrn_tax['type'] ] = is_array( $lrn_terms ) ? $lrn_terms : array();
}

// Some sections (e.g. visibility) gate on whether the current user is a site
// owner vs an authoring instructor. Commission + instructor assignment moved to
// the course-detail hub (see course-detail.php "Instructors & commission").
$is_site_admin = current_user_can( 'manage_options' );

$partials_dir = LEARNOMY_DIR . 'includes/admin/views/partials/';
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title"><?php echo esc_html( $page_title ); ?></h1>
			<?php if ( $is_edit && 'published' !== ( $course->status ?? '' ) ) : ?>
				<span class="description"><?php esc_html_e( 'Publish this course to view it on the site.', 'learnomy' ); ?></span>
			<?php endif; ?>
		</div>
		<div class="lrn-page-header__actions">
			<?php
			// Shared header toolbar — on the edit screen this adds a one-click
			// "Back to course" link to the detail page (plus Curriculum / View
			// course) so editing no longer dead-ends at All courses. Same
			// partial the detail hub renders, so the two never drift. See
			// partials/course-header-toolbar.php.
			$lrn_header_screen = 'edit';
			require LEARNOMY_DIR . 'includes/admin/views/partials/course-header-toolbar.php';
			?>
		</div>
	</div>
	<hr class="wp-header-end">

	<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="lrn-admin-card lrn-admin-alert lrn-admin-alert--success lrn-mb-6" id="lrn-save-notice">
			<i data-lucide="check-circle"></i>
			<span><?php esc_html_e( 'Course saved successfully.', 'learnomy' ); ?></span>
		</div>
	<?php endif; ?>

	<form id="lrn-course-form" class="lrn-course-form" data-course-id="<?php echo $is_edit ? absint( $course->id ) : 0; ?>">
		<?php wp_nonce_field( 'learnomy_admin', 'lrn_nonce' ); ?>

		<div class="lrn-course-form__layout">

			<!-- ── Main content ── -->
			<div class="lrn-course-form__main">

				<!-- Title + URL slug (identity) -->
				<div class="lrn-admin-card">
					<div class="lrn-form-field lrn-form-field--wide">
						<label for="lrn-course-title">
							<i data-lucide="type" class="lrn-icon--sm"></i>
							<?php esc_html_e( 'Course Title', 'learnomy' ); ?>
							<span class="required" aria-hidden="true">*</span>
						</label>
						<input type="text" id="lrn-course-title" name="title" class="large-text lrn-input--lg" required
								value="<?php echo $is_edit ? esc_attr( $course->title ?? '' ) : ''; ?>"
								placeholder="<?php esc_attr_e( 'e.g. Introduction to React', 'learnomy' ); ?>">
					</div>
					<div class="lrn-form-field lrn-form-field--wide">
						<label for="lrn-course-slug">
							<i data-lucide="link" class="lrn-icon--sm"></i>
							<?php esc_html_e( 'Course URL slug', 'learnomy' ); ?>
						</label>
						<input type="text" id="lrn-course-slug" name="slug" class="regular-text"
								value="<?php echo $is_edit ? esc_attr( $course->slug ?? '' ) : ''; ?>"
								placeholder="<?php esc_attr_e( 'auto-generated from the title', 'learnomy' ); ?>">
						<p class="lrn-form-help">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: the course URL pattern, e.g. /courses/<slug>/ */
									__( 'Front-end URL: %s. Leave blank to generate it from the title.', 'learnomy' ),
									trailingslashit( \Learnomy\route_url( 'catalog' ) ) . '<' . esc_html__( 'slug', 'learnomy' ) . '>/'
								)
							);
							?>
						</p>
					</div>
				</div>

				<!-- Description (full, block editor) -->
				<div class="lrn-admin-card">
					<h3 class="lrn-admin-card__title">
						<i data-lucide="file-text" class="lrn-icon--sm"></i>
						<?php esc_html_e( 'Description', 'learnomy' ); ?>
					</h3>
					<div class="lrn-form-field lrn-form-field--wide">
						<?php
						// Editor.js mount — the shared admin block editor
						// (`block-editor.js`) mounts every `.lrn-editor-host[data-input]`
						// on this form and, on submit, writes the serialized JSON to
						// the paired hidden input. Same block set as the lesson +
						// message editors; no per-field JS.
						?>
						<div class="lrn-editor-host" data-input="lrn-course-desc"
							data-placeholder="<?php esc_attr_e( 'Write your course description…', 'learnomy' ); ?>"></div>
						<input type="hidden" name="description" id="lrn-course-desc"
							value="<?php echo esc_attr( $is_edit ? \Learnomy\Content\Content_HTML::for_editor( (string) ( $course->description ?? '' ) ) : '' ); ?>">
						<p class="lrn-form-help"><?php esc_html_e( 'Shown on the course sales page. Use the block editor to format your description.', 'learnomy' ); ?></p>
					</div>
				</div>

				<!-- Short description (catalog card summary) -->
				<div class="lrn-admin-card">
					<h3 class="lrn-admin-card__title">
						<i data-lucide="text" class="lrn-icon--sm"></i>
						<?php esc_html_e( 'Short Description', 'learnomy' ); ?>
					</h3>
					<div class="lrn-form-field lrn-form-field--wide">
						<textarea id="lrn-course-short-desc" name="short_description" rows="3" class="large-text"
									placeholder="<?php esc_attr_e( 'Brief summary shown on the catalog card (1-2 sentences).', 'learnomy' ); ?>"><?php echo $is_edit ? esc_textarea( $course->short_description ?? '' ) : ''; ?></textarea>
						<p class="lrn-form-help"><?php esc_html_e( 'Shown on the catalog card and course listings. Plain text, 1-2 sentences.', 'learnomy' ); ?></p>
					</div>
				</div>

				<!-- ── Collapsible course-settings sections ── -->
				<div class="lrn-course-form__settings">
					<?php
					// Pass vars through explicitly by scope (all local here).
					// Sections render conditionally based on the enabled-module
					// list — keeps the form short for site owners running a
					// simple lesson-only academy.
					$kernel = \Learnomy\Modules\Module_Kernel::instance();

					// Ordered by how often an admin reaches for each section:
					// money first (pricing + commission), then how students
					// finish (completion), then discovery metadata (target),
					// flow, engagement, and finally presentation (visibility).
					/**
					 * Whether the course editor shows the pricing panel.
					 *
					 * The module check is the default; the filter is the seam
					 * for sites that never sell — an internal staff academy, a
					 * Pro site-mode toggle, an owner snippet. One product, 10k
					 * owners, more than one way to reach "we do not sell".
					 *
					 * Hiding is safe on both halves: absent inputs are omitted
					 * by the form JS and left untouched by the REST controller,
					 * so existing prices survive a round-trip. And it is never
					 * SILENT — the else branch renders an explained empty state
					 * instead of nothing, which is the actual lesson of
					 * Basecamp #9932303730.
					 *
					 * @param bool        $show   Default: payments or membership enabled.
					 * @param object|null $course The course being edited, null on create.
					 */
					$lrn_show_pricing = apply_filters(
						'learnomy_course_form_show_pricing',
						$kernel->is_enabled( 'payments' ) || $kernel->is_enabled( 'membership' ),
						$course ?? null
					);
					if ( $lrn_show_pricing ) {
						require $partials_dir . 'course-settings-pricing.php';
					} else {
						require $partials_dir . 'course-settings-pricing-off.php';
					}

					// Per-course platform commission. Sits right after pricing —
					// it IS a pricing question ("of this course's price, what does
					// the platform keep?"), which is why the ordering note above
					// has always said "money first (pricing + commission)".
					//
					// Three conditions, all necessary:
					// - the module is on (nothing to configure otherwise),
					// - the caller is the site owner (the rate is the PLATFORM's
					// cut; an instructor must never set their own — the REST
					// controller enforces the same manage_options check), and
					// - the course exists (a rule keys on a course id).
					//
					// This partial shipped in #448, was dropped from the require
					// list in #744 when instructor assignment moved to the
					// course-detail hub, and then existed for four releases as a
					// file nothing included — with a matching collector in
					// course-form.js reading an input that never rendered. Both
					// halves are live again here (Basecamp 10132809019).
					if ( $is_edit && ! empty( $course->id )
						&& $kernel->is_enabled( 'commissions' )
						&& current_user_can( 'manage_options' ) ) {
						$course_commission = \Learnomy\Models\CommissionRule::get_for_entity_exact( 'course', (int) $course->id );
						$inherited_rate    = \Learnomy\Services\Commission_Service::resolve_platform_rate(
							0,
							(int) \Learnomy\Models\Course::main_author_id( (int) $course->id )
						);
						require $partials_dir . 'course-settings-commission.php';
					}

					if ( $kernel->is_enabled( 'certificates' ) ) {
						require $partials_dir . 'course-settings-completion.php';
					}
					require $partials_dir . 'course-settings-target.php';
					require $partials_dir . 'course-settings-seo.php';
					require $partials_dir . 'course-settings-progression.php';
					if ( $kernel->is_enabled( 'reviews' ) ) {
						require $partials_dir . 'course-settings-engagement.php';
					}
					require $partials_dir . 'course-settings-visibility.php';
					?>
				</div>

				<?php // Instructor assignment lives on the course-detail hub. ?>

			</div><!-- /.lrn-course-form__main -->

			<!-- ── Sidebar ── -->
			<div class="lrn-course-form__sidebar">

				<!-- Publish box -->
				<div class="lrn-admin-card">
					<h3 class="lrn-admin-card__title">
						<i data-lucide="send" class="lrn-icon--sm"></i>
						<?php esc_html_e( 'Publish', 'learnomy' ); ?>
					</h3>
					<div class="lrn-form-field">
						<label for="lrn-course-status"><?php esc_html_e( 'Status', 'learnomy' ); ?></label>
						<select id="lrn-course-status" name="status">
							<option value="draft" <?php echo $is_edit ? selected( $course->status ?? '', 'draft', false ) : ''; ?>><?php esc_html_e( 'Draft', 'learnomy' ); ?></option>
							<option value="pending_review" <?php echo $is_edit ? selected( $course->status ?? '', 'pending_review', false ) : ''; ?>><?php esc_html_e( 'Pending Review', 'learnomy' ); ?></option>
							<?php
							/*
							 * Ask the same gate the REST save path asks.
							 *
							 * This was `current_user_can( 'manage_options' )` — a
							 * capability check re-authored on this one screen. The
							 * site policy `instructor_self_publish` is honoured by
							 * Permission_Engine (self_publish_allowed()) and by the
							 * courses controller, so an entitled instructor could
							 * publish over REST and through the Pro builder while
							 * this dropdown refused to offer the option, making the
							 * setting look dead on the screen that owns it
							 * (Basecamp 10189977184).
							 *
							 * Editing an existing course asks `publish_course`,
							 * which pairs ownership WITH the policy; creating asks
							 * `self_publish_course`, the policy half alone, because
							 * there is no course to own yet. That is the same split
							 * the controller makes, and the reason both abilities
							 * exist. Admins still pass via the engine's
							 * manage_options short-circuit, so nothing is lost by
							 * dropping the hardcoded branch.
							 */
							$lrn_publish_course_id = ( $is_edit && ! empty( $course->id ) ) ? (int) $course->id : null;
							$lrn_can_publish       = \Learnomy\Permissions\Permission_Engine::can(
								get_current_user_id(),
								$lrn_publish_course_id ? 'publish_course' : 'self_publish_course',
								$lrn_publish_course_id
							);
							?>
							<?php if ( $lrn_can_publish ) : ?>
								<option value="published" <?php echo $is_edit ? selected( $course->status ?? '', 'published', false ) : ''; ?>><?php esc_html_e( 'Published', 'learnomy' ); ?></option>
							<?php endif; ?>
						</select>
					</div>
					<?php if ( $is_edit ) : ?>
						<div class="lrn-meta-info">
							<p><i data-lucide="calendar" class="lrn-icon--sm"></i>
							<?php
								/* translators: %s: date the course was created */
								echo esc_html( sprintf( __( 'Created: %s', 'learnomy' ), wp_date( get_option( 'date_format' ), strtotime( $course->created_at ?? '' ) ) ) );
							?>
							</p>
							<p><i data-lucide="users" class="lrn-icon--sm"></i>
							<?php
								/* translators: %s: number of students */
								echo esc_html( sprintf( __( 'Students: %s', 'learnomy' ), number_format_i18n( (int) ( $course->student_count ?? 0 ) ) ) );
							?>
							</p>
							<?php if ( ! empty( $course->avg_rating ) ) : ?>
								<p><i data-lucide="star" class="lrn-icon--sm"></i>
								<?php
									/* translators: 1: average rating, 2: number of reviews */
									echo esc_html( sprintf( __( 'Rating: %1$s (%2$s reviews)', 'learnomy' ), number_format_i18n( (float) $course->avg_rating, 1 ), number_format_i18n( (int) $course->review_count ) ) );
								?>
								</p>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<div class="lrn-form-actions">
						<button type="submit" class="lrn-admin-btn lrn-admin-btn--primary lrn-admin-btn--full" id="lrn-save-course">
							<i data-lucide="save"></i>
							<?php echo $is_edit ? esc_html__( 'Update Course', 'learnomy' ) : esc_html__( 'Create Course', 'learnomy' ); ?>
						</button>
						<span class="spinner"></span>
					</div>
				</div>

				<?php
				// Enrollments panel — live count from lrn_enrollments plus the
				// most-recent students so admins can confirm who actually
				// signed up without leaving the course form. "View all
				// students" deep-links to the Students screen filtered to
				// this course. See Basecamp #9925103366.
				if ( $is_edit ) :
					// Distinct people, not enrollment rows: a learner holding this
					// course via two sources (membership + purchase) is one student,
					// not two (matches the course-detail screen; Basecamp 10109120366).
					$lrn_enroll_count = \Learnomy\Models\Enrollment::count_roster_for_course( (int) $course->id );
					// The count above says "students", so this list must be
					// students too — one row per person. Listing raw enrollment
					// rows here showed a learner holding two grants twice,
					// under a count that had already folded them into one
					// (Basecamp 10167508313). Their grants stay visible as
					// chips, because a paying student must be tellable from a
					// comped one.
					$lrn_recent_enrolls = \Learnomy\Models\Enrollment::get_roster_for_course( (int) $course->id, 5, 0 );
					$lrn_students_url   = add_query_arg(
						array(
							'page'      => 'learnomy-students',
							'course_id' => (int) $course->id,
						),
						admin_url( 'admin.php' )
					);

					// Batch-load the 5 user objects so the foreach loop below
					// runs zero extra DB queries instead of one get_userdata()
					// call per row (N+1 at any size, even at the 5-row cap).
					$lrn_enroll_uids = array_filter( array_map( fn( $e ) => (int) $e->user_id, $lrn_recent_enrolls ) );
					$lrn_student_map = array();
					if ( $lrn_enroll_uids ) {
						foreach ( get_users(
							array(
								'include' => $lrn_enroll_uids,
								'number'  => count( $lrn_enroll_uids ),
							)
						) as $lrn_u ) {
							$lrn_student_map[ (int) $lrn_u->ID ] = $lrn_u;
						}
					}
					?>
					<div class="lrn-admin-card">
						<h3 class="lrn-admin-card__title">
							<i data-lucide="users" class="lrn-icon--sm"></i>
							<?php esc_html_e( 'Enrollments', 'learnomy' ); ?>
						</h3>
						<div class="lrn-form-field">
							<p class="lrn-enrollments-summary">
								<strong><?php echo esc_html( number_format_i18n( $lrn_enroll_count ) ); ?></strong>
								<span class="lrn-muted">
									<?php echo esc_html( _n( 'student enrolled', 'students enrolled', $lrn_enroll_count, 'learnomy' ) ); ?>
								</span>
							</p>
						</div>
						<?php if ( ! empty( $lrn_recent_enrolls ) ) : ?>
							<ul class="lrn-enrollments-recent">
								<?php
								foreach ( $lrn_recent_enrolls as $lrn_enr ) :
									$lrn_student = $lrn_student_map[ (int) $lrn_enr->user_id ] ?? null;
									if ( ! $lrn_student ) {
										continue;
									}
									?>
									<li>
										<?php echo get_avatar( $lrn_student->ID, 24, '', '', array( 'class' => 'lrn-avatar lrn-avatar--xs' ) ); ?>
										<span class="lrn-enrollments-recent__name">
											<?php echo esc_html( $lrn_student->display_name ); ?>
										</span>
										<span class="lrn-muted lrn-text-sm">
											<?php echo esc_html( human_time_diff( strtotime( $lrn_enr->enrolled_at ), time() ) . ' ' . __( 'ago', 'learnomy' ) ); ?>
										</span>
										<?php
										// Last in the row and full-width in CSS, so the chips
										// wrap onto their own line instead of competing with
										// the name for a narrow sidebar's width. Nested inside
										// the name span they were clipped; beside it they
										// squeezed "Emma Lindqvist" down to "E...".
										$lrn_grant_sources = $lrn_enr->grant_sources ?? array();
										require __DIR__ . '/partials/enrollment-grants.php';
										?>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<div class="lrn-form-actions">
							<a href="<?php echo esc_url( $lrn_students_url ); ?>" class="lrn-admin-btn lrn-admin-btn--ghost lrn-admin-btn--full">
								<i data-lucide="users"></i>
								<?php esc_html_e( 'View all students', 'learnomy' ); ?>
							</a>
						</div>
					</div>
				<?php endif; ?>

				<!-- Category -->
				<div class="lrn-admin-card">
					<h3 class="lrn-admin-card__title">
						<i data-lucide="folder" class="lrn-icon--sm"></i>
						<?php esc_html_e( 'Category', 'learnomy' ); ?>
					</h3>
					<div class="lrn-form-field">
						<select id="lrn-course-category" name="category_id">
							<option value="0"><?php esc_html_e( 'Uncategorised', 'learnomy' ); ?></option>
							<?php
							$current_cat = $is_edit ? (int) ( $course->category_id ?? 0 ) : 0;
							foreach ( $categories as $cat ) :
								?>
								<option value="<?php echo absint( $cat->id ); ?>" <?php selected( $current_cat, (int) $cat->id ); ?>><?php echo esc_html( $cat->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>


				<!-- Taxonomies (Tags + any registered course taxonomy) -->
				<?php foreach ( $course_taxonomies as $lrn_tax ) : ?>
					<?php $lrn_tax_terms = $taxonomy_terms[ $lrn_tax['type'] ] ?? array(); ?>
					<div class="lrn-admin-card">
						<h3 class="lrn-admin-card__title">
							<i data-lucide="<?php echo esc_attr( $lrn_tax['icon'] ); ?>" class="lrn-icon--sm"></i>
							<?php echo esc_html( $lrn_tax['label'] ); ?>
						</h3>
						<div class="lrn-form-field">
							<?php if ( empty( $lrn_tax_terms ) ) : ?>
								<p class="lrn-form-help">
									<?php
									echo wp_kses_post(
										\Learnomy\Admin\UX::taxonomy_empty_help(
											(string) $lrn_tax['label'],
											admin_url( 'admin.php?page=learnomy-categories' )
										)
									);
									?>
								</p>
							<?php else : ?>
								<ul class="lrn-tag-checklist">
									<?php foreach ( $lrn_tax_terms as $lrn_term ) : ?>
										<li>
											<label class="lrn-checkbox-label">
												<input type="checkbox" name="tag_ids[]" value="<?php echo absint( $lrn_term->id ); ?>"
													<?php checked( in_array( (int) $lrn_term->id, $course_tag_ids, true ) ); ?>>
												<span><?php echo esc_html( $lrn_term->name ); ?></span>
											</label>
										</li>
									<?php endforeach; ?>
								</ul>
								<?php if ( ! empty( $lrn_tax['help'] ) ) : ?>
									<p class="lrn-form-help"><?php echo esc_html( $lrn_tax['help'] ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( $is_site_admin ) : ?>
								<div class="lrn-tag-quick-add" data-tax-type="<?php echo esc_attr( $lrn_tax['type'] ); ?>">
									<input type="text" class="lrn-tag-quick-add__input regular-text" placeholder="<?php esc_attr_e( 'New tag name…', 'learnomy' ); ?>">
									<button type="button" class="lrn-admin-btn lrn-admin-btn--sm lrn-tag-quick-add__btn">
										<i data-lucide="plus" aria-hidden="true"></i> <?php esc_html_e( 'Add Tag', 'learnomy' ); ?>
									</button>
									<span class="lrn-tag-quick-add__status" aria-live="polite"></span>
								</div>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
				<?php if ( $is_edit ) : ?>
				<!-- Curriculum -->
				<div class="lrn-admin-card">
					<h3 class="lrn-admin-card__title">
						<i data-lucide="list-tree" class="lrn-icon--sm"></i>
						<?php esc_html_e( 'Curriculum', 'learnomy' ); ?>
					</h3>
					<p class="lrn-admin-card__desc"><?php esc_html_e( 'Manage sections, lessons, quizzes, and ordering.', 'learnomy' ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-course-builder&course_id=' . $course->id ) ); ?>"
						class="lrn-admin-btn lrn-admin-btn--primary lrn-admin-btn--full">
						<i data-lucide="layout-list"></i>
						<?php esc_html_e( 'Manage Curriculum', 'learnomy' ); ?>
					</a>
				</div>
				<?php endif; ?>

				<!-- Featured / Cover Image -->
				<div class="lrn-admin-card">
					<h3 class="lrn-admin-card__title">
						<i data-lucide="image" class="lrn-icon--sm"></i>
						<?php esc_html_e( 'Featured Image', 'learnomy' ); ?>
					</h3>
					<p class="lrn-admin-card__desc"><?php esc_html_e( 'Shown on the catalog grid card and the single course hero banner.', 'learnomy' ); ?></p>
					<div class="lrn-form-field">
						<input type="url" id="lrn-course-thumbnail" name="thumbnail_url" class="regular-text"
								value="<?php echo $is_edit ? esc_url( $course->thumbnail_url ?? '' ) : ''; ?>"
								placeholder="<?php esc_attr_e( 'https://example.com/cover.jpg', 'learnomy' ); ?>">
						<button type="button" class="lrn-admin-btn" id="lrn-upload-thumbnail">
							<i data-lucide="upload"></i>
							<?php esc_html_e( 'Select Image', 'learnomy' ); ?>
						</button>
					</div>
					<div class="lrn-course-form__thumbnail-preview" id="lrn-thumbnail-preview">
						<?php if ( $is_edit && ! empty( $course->thumbnail_url ) ) : ?>
							<img src="<?php echo esc_url( $course->thumbnail_url ); ?>" alt="" loading="lazy">
						<?php endif; ?>
					</div>
				</div>

				<?php
				/**
				 * Renders extra course-editor fields in the sidebar so integrations
				 * can add their own inputs without forking this template or injecting
				 * DOM via JS (courses are custom-table rows, no CPT meta-box path).
				 * Persist on the matching `learnomy_course_saved` action.
				 *
				 * @param object|null $course The course being edited (null on create).
				 */
				do_action( 'learnomy_course_editor_fields', $course ?? null );

				/*
				 * The shared "Community & Discussion" section - identical here,
				 * on the course builder, on a cohort and on a Learnomy Space, so
				 * an owner learns one control instead of each product's habits
				 * on each screen.
				 *
				 * Renders nothing at all unless a product registers a provider,
				 * so a Learnomy-only site sees no empty heading advertising a
				 * feature it does not have and cannot get from this screen.
				 */
				if ( isset( $course->id ) && (int) $course->id > 0 ) {
					\Learnomy\Services\Object_Integration_Registry::render( 'course', (int) $course->id );
				}
				?>

			</div><!-- /.lrn-course-form__sidebar -->

		</div><!-- /.lrn-course-form__layout -->
	</form>
</div>
