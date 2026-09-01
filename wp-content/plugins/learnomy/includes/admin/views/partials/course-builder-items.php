<?php
/**
 * Course builder — the item rows (lessons + their topics + quizzes) inside one
 * group's `<ul class="lrn-builder__items">`. Extracted so the real sections AND
 * the "Ungrouped" group (section-less lessons/quizzes, since sections are
 * optional) render identical markup with no duplication.
 *
 * Expected vars (set by the includer):
 *   $builder_section_id int     Section id, or 0 for the ungrouped group.
 *   $builder_lessons    array   Top-level lesson rows for this group.
 *   $builder_quizzes    array   Quiz rows for this group.
 *   $lesson_topics      array   Map of lesson_id => topic[] (batch-fetched).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$builder_section_id = isset( $builder_section_id ) ? (int) $builder_section_id : 0;
$builder_lessons    = isset( $builder_lessons ) && is_array( $builder_lessons ) ? $builder_lessons : array();
$builder_quizzes    = isset( $builder_quizzes ) && is_array( $builder_quizzes ) ? $builder_quizzes : array();
$lesson_topics      = isset( $lesson_topics ) && is_array( $lesson_topics ) ? $lesson_topics : array();

foreach ( $builder_lessons as $lesson_index => $lesson ) :
	$topics   = $lesson_topics[ $lesson->id ] ?? array();
	$edit_url = admin_url( 'admin.php?page=learnomy-lesson-editor&lesson_id=' . $lesson->id );
	?>
	<li class="lrn-builder__item lrn-builder__item--lesson lrn-lesson-item"
		draggable="true"
		data-item-type="lesson"
		data-item-id="<?php echo absint( $lesson->id ); ?>"
		data-section-id="<?php echo absint( $builder_section_id ); ?>">
		<span class="lrn-builder__item-drag-handle" aria-hidden="true" title="<?php esc_attr_e( 'Drag to reorder', 'learnomy' ); ?>">
			<i data-lucide="grip-vertical"></i>
		</span>
		<span class="lrn-builder__item-icon lrn-builder__item-icon--lesson">
			<i data-lucide="file-text" aria-hidden="true"></i>
		</span>
		<a href="<?php echo esc_url( $edit_url ); ?>" class="lrn-builder__item-title">
			<?php echo esc_html( $lesson->title ); ?>
		</a>
		<?php if ( 'published' !== $lesson->status ) : ?>
			<span class="lrn-builder__item-status lrn-builder__item-status--draft">
				<?php esc_html_e( 'Draft', 'learnomy' ); ?>
			</span>
		<?php endif; ?>
		<span class="lrn-builder__item-actions">
			<button type="button" class="lrn-builder__icon-btn js-move-item-up"
					title="<?php esc_attr_e( 'Move Up', 'learnomy' ); ?>"
					<?php disabled( 0 === $lesson_index ); ?>>
				<i data-lucide="chevron-up" aria-hidden="true"></i>
			</button>
			<button type="button" class="lrn-builder__icon-btn js-move-item-down"
					title="<?php esc_attr_e( 'Move Down', 'learnomy' ); ?>">
				<i data-lucide="chevron-down" aria-hidden="true"></i>
			</button>
			<a href="<?php echo esc_url( $edit_url ); ?>" class="lrn-builder__icon-btn"
				title="<?php esc_attr_e( 'Edit', 'learnomy' ); ?>">
				<i data-lucide="pencil" aria-hidden="true"></i>
			</a>
			<button type="button" class="lrn-builder__icon-btn js-add-topic"
					data-parent-id="<?php echo absint( $lesson->id ); ?>"
					title="<?php esc_attr_e( 'Add Topic', 'learnomy' ); ?>">
				<i data-lucide="plus" aria-hidden="true"></i>
			</button>
			<button type="button" class="lrn-builder__icon-btn lrn-builder__icon-btn--danger js-delete-item"
					title="<?php esc_attr_e( 'Remove', 'learnomy' ); ?>">
				<i data-lucide="trash-2" aria-hidden="true"></i>
			</button>
		</span>
	</li>

	<?php
	foreach ( $topics as $topic_index => $topic ) :
		$topic_url = admin_url( 'admin.php?page=learnomy-lesson-editor&lesson_id=' . $topic->id );
		?>
		<li class="lrn-builder__item lrn-builder__item--topic"
			data-item-type="topic"
			data-item-id="<?php echo absint( $topic->id ); ?>"
			data-parent-id="<?php echo absint( $lesson->id ); ?>">
			<span class="lrn-builder__item-icon lrn-builder__item-icon--topic">
				<i data-lucide="file-text" aria-hidden="true"></i>
			</span>
			<a href="<?php echo esc_url( $topic_url ); ?>" class="lrn-builder__item-title">
				<?php echo esc_html( $topic->title ); ?>
			</a>
			<span class="lrn-builder__item-actions">
				<button type="button" class="lrn-builder__icon-btn js-move-item-up"
						title="<?php esc_attr_e( 'Move Up', 'learnomy' ); ?>"
						<?php disabled( 0 === $topic_index ); ?>>
					<i data-lucide="chevron-up" aria-hidden="true"></i>
				</button>
				<button type="button" class="lrn-builder__icon-btn js-move-item-down"
						title="<?php esc_attr_e( 'Move Down', 'learnomy' ); ?>">
					<i data-lucide="chevron-down" aria-hidden="true"></i>
				</button>
				<a href="<?php echo esc_url( $topic_url ); ?>" class="lrn-builder__icon-btn"
					title="<?php esc_attr_e( 'Edit', 'learnomy' ); ?>">
					<i data-lucide="pencil" aria-hidden="true"></i>
				</a>
				<button type="button" class="lrn-builder__icon-btn lrn-builder__icon-btn--danger js-delete-item"
						title="<?php esc_attr_e( 'Remove', 'learnomy' ); ?>">
					<i data-lucide="trash-2" aria-hidden="true"></i>
				</button>
			</span>
		</li>
	<?php endforeach; ?>

<?php endforeach; ?>

<?php
/*
 * Placement labels, so this builder stops being the one surface that cannot see
 * where a quiz actually renders.
 *
 * `lrn_quizzes.lesson_id` is the AUTHOR'S placement decision — the quiz editor's
 * Lesson dropdown literally offers "End of section" as its empty option — and
 * the student outline honours it. This list looped every quiz after every
 * lesson with no label, so a quiz bound to lesson 3 was visually identical to a
 * section-end quiz, and the author's own tool disagreed with what their students
 * saw. Pro's frontend builder was given the same chips on 10181610591; this is
 * the third entry point that was named in two card bodies and landed nowhere.
 *
 * Quiz::split_by_lesson() is the shared splitter the three student-facing
 * surfaces already use, so the label cannot drift from the render. Its fallback
 * matters here too: a quiz whose lesson is not in THIS group (moved, drafted)
 * falls to section_end rather than vanishing.
 *
 * Deliberately labels rather than nests, matching the choice made in Pro: an
 * authoring tool wants one manageable list where every quiz is findable and
 * reorderable; the outline is where hierarchy is the point.
 */
$lrn_lesson_titles = array();
foreach ( $builder_lessons as $lrn_bl ) {
	$lrn_lesson_titles[ (int) $lrn_bl->id ] = (string) $lrn_bl->title;
}
$lrn_quiz_split     = \Learnomy\Models\Quiz::split_by_lesson( $builder_quizzes, array_keys( $lrn_lesson_titles ) );
$lrn_quiz_placement = array();
foreach ( $lrn_quiz_split['by_lesson'] as $lrn_lesson_id => $lrn_bound ) {
	foreach ( $lrn_bound as $lrn_bq ) {
		$lrn_quiz_placement[ (int) $lrn_bq->id ] = sprintf(
			/* translators: %s: lesson title the quiz is bound to. */
			__( 'Under: %s', 'learnomy' ),
			$lrn_lesson_titles[ (int) $lrn_lesson_id ] ?? ''
		);
	}
}
foreach ( $lrn_quiz_split['section_end'] as $lrn_eq ) {
	$lrn_quiz_placement[ (int) $lrn_eq->id ] = __( 'End of section', 'learnomy' );
}

foreach ( $builder_quizzes as $quiz ) :
	// learnomy-edit-quiz, not learnomy-quizzes. The quizzes LIST screen reads
	// paged/type/course_id/tag_id and ignores quiz_id entirely, so this link
	// dropped the author on the unfiltered list of every quiz on the site
	// instead of the one they clicked. Found while replicating 10181610591 —
	// it is the only wrong copy of this URL in either plugin.
	$quiz_edit_url = admin_url( 'admin.php?page=learnomy-edit-quiz&quiz_id=' . $quiz->id );
	?>
	<li class="lrn-builder__item lrn-builder__item--quiz"
		data-item-type="quiz"
		data-item-id="<?php echo absint( $quiz->id ); ?>"
		data-section-id="<?php echo absint( $builder_section_id ); ?>">
		<span class="lrn-builder__item-icon lrn-builder__item-icon--quiz">
			<i data-lucide="help-circle" aria-hidden="true"></i>
		</span>
		<a href="<?php echo esc_url( $quiz_edit_url ); ?>" class="lrn-builder__item-title">
			<?php echo esc_html( $quiz->title ); ?>
		</a>
		<?php if ( ! empty( $lrn_quiz_placement[ (int) $quiz->id ] ) ) : ?>
			<span class="lrn-builder__item-placement">
				<?php echo esc_html( $lrn_quiz_placement[ (int) $quiz->id ] ); ?>
			</span>
		<?php endif; ?>
		<?php if ( 'published' !== $quiz->status ) : ?>
			<span class="lrn-builder__item-status lrn-builder__item-status--draft">
				<?php esc_html_e( 'Draft', 'learnomy' ); ?>
			</span>
		<?php endif; ?>
		<span class="lrn-builder__item-actions">
			<button type="button" class="lrn-builder__icon-btn js-move-item-up"
					title="<?php esc_attr_e( 'Move Up', 'learnomy' ); ?>">
				<i data-lucide="chevron-up" aria-hidden="true"></i>
			</button>
			<button type="button" class="lrn-builder__icon-btn js-move-item-down"
					title="<?php esc_attr_e( 'Move Down', 'learnomy' ); ?>">
				<i data-lucide="chevron-down" aria-hidden="true"></i>
			</button>
			<a href="<?php echo esc_url( $quiz_edit_url ); ?>" class="lrn-builder__icon-btn"
				title="<?php esc_attr_e( 'Edit', 'learnomy' ); ?>">
				<i data-lucide="pencil" aria-hidden="true"></i>
			</a>
			<button type="button" class="lrn-builder__icon-btn lrn-builder__icon-btn--danger js-delete-item"
					title="<?php esc_attr_e( 'Remove', 'learnomy' ); ?>">
				<i data-lucide="trash-2" aria-hidden="true"></i>
			</button>
		</span>
	</li>
<?php endforeach; ?>
