<?php
/**
 * Course builder — tree manager for curriculum editing.
 *
 * @package Learnomy
 * @var object   $course          Course object.
 * @var object[] $sections        Sections ordered by sort_order.
 * @var array    $section_lessons Lessons grouped by section ID.
 * @var array    $lesson_topics   Topics grouped by parent lesson ID.
 * @var array    $section_quizzes Quizzes grouped by section ID.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap learnomy-admin lrn-builder">

	<!-- Page header — matches the course-form / list-page shell so the builder sits in the same visual frame. -->
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="layout-list" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php esc_html_e( 'Course Builder', 'learnomy' ); ?>
			</h1>
			<nav class="lrn-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'learnomy' ); ?>">
				<a class="lrn-breadcrumb__item"
					href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-courses' ) ); ?>">
					<?php esc_html_e( 'Courses', 'learnomy' ); ?>
				</a>
				<span class="lrn-breadcrumb__sep" aria-hidden="true">/</span>
				<a class="lrn-breadcrumb__item"
					href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . $course->id ) ); ?>">
					<?php echo esc_html( $course->title ); ?>
				</a>
				<span class="lrn-breadcrumb__sep" aria-hidden="true">/</span>
				<span class="lrn-breadcrumb__item" aria-current="page">
					<?php esc_html_e( 'Curriculum', 'learnomy' ); ?>
				</span>
			</nav>
		</div>
		<div class="lrn-page-header__actions">
			<?php
			/**
			 * AI authoring slot — the Pro ai-authoring extension renders its
			 * "Generate with AI" trigger here so admins get the same authoring
			 * power on the wp-admin course builder that instructors get on the
			 * front-end builder. No-op when AI is off / not connected.
			 *
			 * @param int $course_id The course being edited.
			 */
			do_action( 'learnomy_pro_course_builder_ai_slot', (int) $course->id );
			?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-edit-course&course_id=' . $course->id ) ); ?>"
				class="lrn-admin-btn">
				<i data-lucide="arrow-left" aria-hidden="true"></i>
				<?php esc_html_e( 'Back to Course', 'learnomy' ); ?>
			</a>
			<?php if ( ! empty( $course->slug ) ) : ?>
			<a href="<?php echo esc_url( \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) ) ); ?>"
				class="lrn-admin-btn" target="_blank" rel="noopener">
				<i data-lucide="eye" aria-hidden="true"></i>
				<?php esc_html_e( 'Preview', 'learnomy' ); ?>
			</a>
			<?php endif; ?>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<!-- Add Section button -->
	<div class="lrn-builder__add-section">
		<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" id="lrn-add-section">
			<i data-lucide="plus" aria-hidden="true"></i>
			<?php esc_html_e( 'Add Section', 'learnomy' ); ?>
		</button>
	</div>

	<!-- Tree container -->
	<div id="lrn-builder-tree">

		<?php
		$loose_lessons = isset( $loose_lessons ) && is_array( $loose_lessons ) ? $loose_lessons : array();
		$loose_quizzes = isset( $loose_quizzes ) && is_array( $loose_quizzes ) ? $loose_quizzes : array();
		$has_loose     = ! empty( $loose_lessons ) || ! empty( $loose_quizzes );
		?>
		<?php if ( empty( $sections ) && ! $has_loose ) : ?>
			<!-- Empty state -->
			<div class="lrn-builder__empty" id="lrn-builder-empty">
				<i data-lucide="graduation-cap" class="lrn-builder__empty-icon" aria-hidden="true"></i>
				<h3 class="lrn-builder__empty-title"><?php esc_html_e( 'No content yet', 'learnomy' ); ?></h3>
				<p class="lrn-builder__empty-desc">
					<?php esc_html_e( 'Start building your curriculum. Add a section to group lessons into chapters, or add lessons directly - sections are optional.', 'learnomy' ); ?>
				</p>
			</div>
		<?php else : ?>

			<?php
			// Ungrouped group — section-less lessons/quizzes (sections are optional).
			// data-section-id="0" so the existing move/delete/add JS keys onto it like
			// any section; it just omits the rename/delete/reorder section controls.
			?>
			<?php if ( $has_loose ) : ?>
				<div class="lrn-builder__section lrn-builder__section--ungrouped" data-section-id="0">
					<div class="lrn-builder__section-header" role="button" tabindex="0" aria-expanded="true"
						aria-label="<?php esc_attr_e( 'Ungrouped lessons', 'learnomy' ); ?>">
						<span class="lrn-builder__toggle"><i data-lucide="chevron-down" aria-hidden="true"></i></span>
						<span class="lrn-builder__section-title"><?php esc_html_e( 'Ungrouped', 'learnomy' ); ?></span>
					</div>
					<div class="lrn-builder__section-body">
						<ul class="lrn-builder__items">
							<?php
							$builder_section_id = 0;
							$builder_lessons    = $loose_lessons;
							$builder_quizzes    = $loose_quizzes;
							include __DIR__ . '/partials/course-builder-items.php';
							?>
						</ul>
						<div class="lrn-builder__section-footer">
							<button type="button" class="lrn-builder__add-btn js-add-lesson" data-section-id="0">
								<i data-lucide="plus" aria-hidden="true"></i><?php esc_html_e( 'Add Lesson', 'learnomy' ); ?>
							</button>
							<button type="button" class="lrn-builder__add-btn js-add-quiz" data-section-id="0">
								<i data-lucide="plus" aria-hidden="true"></i><?php esc_html_e( 'Add Quiz', 'learnomy' ); ?>
							</button>
							<button type="button" class="lrn-builder__add-btn js-attach-existing" data-section-id="0">
								<i data-lucide="link" aria-hidden="true"></i><?php esc_html_e( 'Attach Existing', 'learnomy' ); ?>
							</button>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php foreach ( $sections as $section_index => $section ) : ?>
				<div class="lrn-builder__section" data-section-id="<?php echo absint( $section->id ); ?>">

					<!-- Section header -->
					<div class="lrn-builder__section-header" role="button" tabindex="0"
						aria-expanded="true"
						aria-label="<?php echo esc_attr( $section->title ); ?>">
						<span class="lrn-builder__toggle">
							<i data-lucide="chevron-down" aria-hidden="true"></i>
						</span>
						<span class="lrn-builder__section-title">
							<?php echo esc_html( $section->title ); ?>
						</span>
						<?php /* Click-bubble guard for the section header is in assets/js/admin/course-builder.js (e.target.closest('.lrn-builder__section-actions') short-circuits the toggle handler); inline onclick removed for CSP + skill rule. */ ?>
						<span class="lrn-builder__section-actions">
							<button type="button" class="lrn-builder__icon-btn js-move-section-up"
									title="<?php esc_attr_e( 'Move Up', 'learnomy' ); ?>"
									<?php disabled( 0 === $section_index ); ?>>
								<i data-lucide="chevron-up" aria-hidden="true"></i>
							</button>
							<button type="button" class="lrn-builder__icon-btn js-move-section-down"
									title="<?php esc_attr_e( 'Move Down', 'learnomy' ); ?>"
									<?php disabled( $section_index === count( $sections ) - 1 ); ?>>
								<i data-lucide="chevron-down" aria-hidden="true"></i>
							</button>
							<button type="button" class="lrn-builder__icon-btn js-edit-section"
									title="<?php esc_attr_e( 'Rename', 'learnomy' ); ?>">
								<i data-lucide="pencil" aria-hidden="true"></i>
							</button>
							<button type="button" class="lrn-builder__icon-btn lrn-builder__icon-btn--danger js-delete-section"
									title="<?php esc_attr_e( 'Delete', 'learnomy' ); ?>">
								<i data-lucide="trash-2" aria-hidden="true"></i>
							</button>
						</span>
					</div>

					<!-- Section body -->
					<div class="lrn-builder__section-body">
						<ul class="lrn-builder__items">

							<?php
								$builder_section_id = (int) $section->id;
								$builder_lessons    = $section_lessons[ $section->id ] ?? array();
								$builder_quizzes    = $section_quizzes[ $section->id ] ?? array();
								include __DIR__ . '/partials/course-builder-items.php';
							?>

												</ul>

						<!-- Add lesson / quiz / attach -->
						<div class="lrn-builder__section-footer">
							<button type="button" class="lrn-builder__add-btn js-add-lesson"
									data-section-id="<?php echo absint( $section->id ); ?>">
								<i data-lucide="plus" aria-hidden="true"></i>
								<?php esc_html_e( 'Add Lesson', 'learnomy' ); ?>
							</button>
							<button type="button" class="lrn-builder__add-btn js-add-quiz"
									data-section-id="<?php echo absint( $section->id ); ?>">
								<i data-lucide="plus" aria-hidden="true"></i>
								<?php esc_html_e( 'Add Quiz', 'learnomy' ); ?>
							</button>
							<button type="button" class="lrn-builder__add-btn js-attach-existing"
									data-section-id="<?php echo absint( $section->id ); ?>">
								<i data-lucide="link" aria-hidden="true"></i>
								<?php esc_html_e( 'Attach Existing', 'learnomy' ); ?>
							</button>
						</div>
					</div>

				</div>
			<?php endforeach; ?>

		<?php endif; ?>

	</div><!-- /#lrn-builder-tree -->

	<!-- Attach Existing modal (hidden by default) -->
	<div class="lrn-builder-modal" id="lrn-attach-modal" role="dialog" aria-modal="true"
		aria-labelledby="lrn-attach-modal-title">
		<div class="lrn-builder-modal__dialog">
			<div class="lrn-builder-modal__header">
				<h2 class="lrn-builder-modal__title" id="lrn-attach-modal-title">
					<?php esc_html_e( 'Attach Existing Item', 'learnomy' ); ?>
				</h2>
				<button type="button" class="lrn-builder-modal__close" id="lrn-attach-modal-close"
						aria-label="<?php esc_attr_e( 'Close', 'learnomy' ); ?>">
					<i data-lucide="x" aria-hidden="true"></i>
				</button>
			</div>
			<div class="lrn-builder-modal__body">
				<input type="search" class="lrn-builder-modal__search" id="lrn-attach-search"
						placeholder="<?php esc_attr_e( 'Search lessons and quizzes by title…', 'learnomy' ); ?>"
						autocomplete="off">
				<ul class="lrn-builder-modal__results" id="lrn-attach-results"></ul>
			</div>
		</div>
	</div>

	<!-- Toast notification -->
	<div class="lrn-builder-toast" id="lrn-builder-toast"></div>

</div>
