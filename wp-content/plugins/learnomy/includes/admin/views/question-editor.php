<?php
/**
 * Admin question editor view — card + accordion layout.
 *
 * Visual parity with the Course form: a `.lrn-page-header` with Back action,
 * two `.lrn-admin-card` shells wrapping `.lrn-settings-section` accordions —
 * one for Question Details, one for Answers. The Answers region is re-rendered
 * per type by `assets/js/admin/question-editor.js`; the JS still reads every
 * `name=""` / `id=""` attribute preserved from the previous layout so nothing
 * server-side changes.
 *
 * @package Learnomy
 * @var object|null $question             Existing question object (null for new).
 * @var bool        $is_edit              Whether we are editing an existing question.
 * @var object[]    $question_categories  All question categories for the category selector.
 * @var object[]    $answers              Existing answer rows (empty on new).
 * @var array       $question_types       Map of type slug => label from QuestionTypes::all().
 */

defined( 'ABSPATH' ) || exit;

$difficulty_levels = array(
	1 => __( 'Beginner', 'learnomy' ),
	2 => __( 'Easy', 'learnomy' ),
	3 => __( 'Medium', 'learnomy' ),
	4 => __( 'Hard', 'learnomy' ),
	5 => __( 'Expert', 'learnomy' ),
);

$page_title = $is_edit
	? __( 'Edit Question', 'learnomy' )
	: __( 'Add New Question', 'learnomy' );

$ajax_action    = $is_edit ? 'learnomy_update_question' : 'learnomy_create_question';
$current_type   = $is_edit ? ( $question->type ?? 'mc_single' ) : 'mc_single';
$current_status = $is_edit ? ( $question->status ?? 'published' ) : 'published';

// Pre-encode the answer rows so the client-side editor can hydrate without
// another REST round-trip. wp_json_encode() safely handles quotes/backslashes.
$answers_initial = array();
foreach ( (array) $answers as $a ) {
	$answers_initial[] = array(
		'content'    => (string) ( $a->content ?? '' ),
		'is_correct' => (int) ( $a->is_correct ?? 0 ),
		'fraction'   => (float) ( $a->fraction ?? 0.0 ),
		'feedback'   => (string) ( $a->feedback ?? '' ),
		'group_id'   => (string) ( $a->group_id ?? '' ),
		'sort_order' => (int) ( $a->sort_order ?? 0 ),
	);
}
?>
<div class="wrap learnomy-admin">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="list-checks" class="lrn-page-header__icon"></i>
				<?php echo esc_html( $page_title ); ?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php esc_html_e( 'Write the question, pick a type, and define how the student\'s answer is graded.', 'learnomy' ); ?>
			</p>
		</div>
		<div class="lrn-page-header__actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-questions' ) ); ?>" class="lrn-admin-btn">
				<i data-lucide="arrow-left"></i>
				<?php esc_html_e( 'Back to Question Bank', 'learnomy' ); ?>
			</a>
			<?php if ( $is_edit ) : ?>
				<button type="button" class="lrn-admin-btn lrn-admin-btn--danger" id="lrn-delete-question"
						data-question-id="<?php echo absint( $question->id ); ?>">
					<i data-lucide="trash-2" aria-hidden="true"></i> <?php esc_html_e( 'Delete Question', 'learnomy' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>
	<hr class="wp-header-end">

	<form data-ajax-action="<?php echo esc_attr( $ajax_action ); ?>" class="lrn-editor-form lrn-question-editor" id="lrn-question-editor-form">
		<?php if ( $is_edit ) : ?>
			<input type="hidden" name="question_id" value="<?php echo absint( $question->id ); ?>">
		<?php endif; ?>

		<div class="lrn-course-form__layout">
		<div class="lrn-course-form__main">

		<!-- ── Question Details card ──────────────────────────────────── -->
		<div class="lrn-admin-card lrn-question-editor__card">
			<section class="lrn-settings-section is-open" data-section="details">
				<button type="button" class="lrn-settings-section__header" aria-expanded="true">
					<span class="lrn-settings-section__heading">
						<i data-lucide="file-text"></i>
						<span><?php esc_html_e( 'Question Details', 'learnomy' ); ?></span>
					</span>
					<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
				</button>
				<div class="lrn-settings-section__body">
					<div class="lrn-form-field lrn-form-field--wide">
						<label for="lrn-question-title">
							<?php esc_html_e( 'Question Text', 'learnomy' ); ?>
							<span class="required" aria-hidden="true">*</span>
						</label>
						<textarea id="lrn-question-title" name="title"
							class="large-text lrn-input--lg" rows="3" required
							placeholder="<?php esc_attr_e( 'e.g. Which of the following are prime numbers?', 'learnomy' ); ?>"><?php echo esc_textarea( $question->title ?? '' ); ?></textarea>
						<p class="lrn-form-help">
							<?php esc_html_e( 'For "Fill in the blank" questions, place tokens like {{blank1}}, {{blank2}} where you want the blanks to appear.', 'learnomy' ); ?>
						</p>
					</div>

					<?php // A3: rich question stem (its own `stem` column) — Editor.js mount + a hidden field the editor serialises into on save. ?>
					<div class="lrn-form-field lrn-form-field--wide">
						<label for="lrn-question-stem"><?php esc_html_e( 'Passage / Context (optional)', 'learnomy' ); ?></label>
						<div class="lrn-question-editor__stem" id="lrn-question-stem"></div>
						<input type="hidden" name="stem" id="lrn-question-stem-input" value="<?php echo esc_attr( $question->stem ?? '' ); ?>">
						<p class="lrn-form-help"><?php esc_html_e( 'A rich passage, code block, or image shown above the answers. Supports formatting.', 'learnomy' ); ?></p>
					</div>

					<div class="lrn-form-field lrn-form-field--wide"<?php echo empty( $question->content ) ? ' hidden' : ''; ?>>
						<label for="lrn-question-content"><?php esc_html_e( 'Additional Context (plain text)', 'learnomy' ); ?></label>
						<textarea id="lrn-question-content" name="content"
							class="large-text" rows="4"
							placeholder="<?php esc_attr_e( 'Optional plain-text note shown with the question.', 'learnomy' ); ?>"><?php echo esc_textarea( $question->content ?? '' ); ?></textarea>
						<p class="lrn-form-help"><?php esc_html_e( 'Plain-text context. For a formatted passage, use Passage / Context above.', 'learnomy' ); ?></p>
					</div>

					<div class="lrn-form-field lrn-form-field--wide">
						<label for="lrn-question-hint"><?php esc_html_e( 'Hint (optional)', 'learnomy' ); ?></label>
						<textarea id="lrn-question-hint" name="hint"
							class="large-text" rows="2"
							placeholder="<?php esc_attr_e( 'A nudge shown at a small marks cost.', 'learnomy' ); ?>"><?php echo esc_textarea( $question->hint ?? '' ); ?></textarea>
						<p class="lrn-form-help"><?php esc_html_e( 'Students can reveal this hint at a small marks cost.', 'learnomy' ); ?></p>
					</div>

					<div class="lrn-question-editor__grid">
						<div class="lrn-form-field">
							<label for="lrn-question-type"><?php esc_html_e( 'Type', 'learnomy' ); ?></label>
							<select id="lrn-question-type" name="type" data-lrn-question-type>
								<?php foreach ( $question_types as $type_key => $type_label ) : ?>
									<option value="<?php echo esc_attr( $type_key ); ?>"
										<?php selected( $current_type, $type_key ); ?>>
										<?php echo esc_html( $type_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="lrn-form-help"><?php esc_html_e( 'Changes the answers layout below.', 'learnomy' ); ?></p>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-question-category"><?php esc_html_e( 'Category', 'learnomy' ); ?></label>
							<select id="lrn-question-category" name="category_id">
								<option value=""><?php esc_html_e( 'None', 'learnomy' ); ?></option>
								<?php foreach ( $question_categories as $cat ) : ?>
									<option value="<?php echo absint( $cat->id ); ?>"
										<?php selected( $question->category_id ?? '', $cat->id ); ?>>
										<?php echo esc_html( $cat->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-question-difficulty"><?php esc_html_e( 'Difficulty', 'learnomy' ); ?></label>
							<select id="lrn-question-difficulty" name="difficulty">
								<?php foreach ( $difficulty_levels as $level => $level_label ) : ?>
									<option value="<?php echo absint( $level ); ?>"
										<?php selected( (int) ( $question->difficulty ?? 3 ), $level ); ?>>
										<?php echo esc_html( $level_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="lrn-form-field">
							<label for="lrn-question-status"><?php esc_html_e( 'Status', 'learnomy' ); ?></label>
							<select id="lrn-question-status" name="status">
								<option value="draft" <?php selected( $current_status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'learnomy' ); ?></option>
								<option value="published" <?php selected( $current_status, 'published' ); ?>><?php esc_html_e( 'Published', 'learnomy' ); ?></option>
								<option value="archived" <?php selected( $current_status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'learnomy' ); ?></option>
							</select>
						</div>
					</div>

					<div class="lrn-form-field lrn-form-field--wide">
						<label for="lrn-question-explanation"><?php esc_html_e( 'Explanation', 'learnomy' ); ?></label>
						<textarea id="lrn-question-explanation" name="explanation"
							class="large-text" rows="3"
							placeholder="<?php esc_attr_e( 'Shown to the student after they answer. Teach, do not just grade.', 'learnomy' ); ?>"><?php echo esc_textarea( $question->explanation ?? '' ); ?></textarea>
						<p class="lrn-form-help"><?php esc_html_e( 'Shown to the student after answering (if enabled on the quiz).', 'learnomy' ); ?></p>
					</div>

					<div class="lrn-form-field lrn-form-field--wide lrn-question-editor__assigned">
						<label for="lrn-question-quizzes"><?php esc_html_e( 'Add to quizzes', 'learnomy' ); ?></label>
						<?php if ( ! empty( $selectable_quizzes ) ) : ?>
							<select id="lrn-question-quizzes" name="quiz_ids[]" multiple size="6"
								class="lrn-question-editor__quiz-select">
								<?php foreach ( $selectable_quizzes as $selectable_quiz ) : ?>
									<option value="<?php echo (int) $selectable_quiz->id; ?>"
										<?php selected( in_array( (int) $selectable_quiz->id, $assigned_quiz_ids, true ), true ); ?>>
										<?php echo esc_html( $selectable_quiz->title ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="lrn-form-help">
								<?php esc_html_e( 'Hold Ctrl/Cmd to select more than one. Showing the 100 most recently updated quizzes.', 'learnomy' ); ?>
							</p>
						<?php else : ?>
							<p class="lrn-form-help">
								<?php esc_html_e( 'No quizzes exist yet. Create a quiz first, then come back to attach this question.', 'learnomy' ); ?>
							</p>
						<?php endif; ?>
					</div>
				</div>
			</section>
		</div>

		<!-- ── Answers card ───────────────────────────────────────────── -->
		<div class="lrn-admin-card lrn-question-editor__card lrn-question-editor__card--answers">
			<section class="lrn-settings-section is-open" data-section="answers">
				<button type="button" class="lrn-settings-section__header" aria-expanded="true">
					<span class="lrn-settings-section__heading">
						<i data-lucide="list-checks"></i>
						<span><?php esc_html_e( 'Answers', 'learnomy' ); ?></span>
					</span>
					<i data-lucide="chevron-down" class="lrn-settings-section__chevron"></i>
				</button>
				<div class="lrn-settings-section__body">
					<p class="lrn-settings-section__help">
						<?php esc_html_e( 'The answer layout changes based on the question type above.', 'learnomy' ); ?>
					</p>

					<div class="lrn-answers-region" data-lrn-answers-region>
						<?php // Client-side renderer hydrates this region from initialAnswers. ?>
						<p class="lrn-muted lrn-answers-loading">
							<?php esc_html_e( 'Loading answers editor…', 'learnomy' ); ?>
						</p>
					</div>

					<div class="lrn-answers-footer">
						<button type="button" class="lrn-admin-btn lrn-answers-add" data-lrn-answers-add>
							<i data-lucide="plus"></i>
							<?php esc_html_e( 'Add answer', 'learnomy' ); ?>
						</button>
					</div>
				</div>
			</section>
		</div>

		</div><!-- /.lrn-course-form__main -->

		<?php if ( $is_edit ) : ?>
		<div class="lrn-course-form__sidebar">
			<!-- ── Tags card ──────────────────────────────────────────────── -->
			<?php foreach ( $question_taxonomies as $q_tax ) : ?>
				<?php $q_tax_terms = $taxonomy_terms[ $q_tax['type'] ] ?? array(); ?>
				<div class="lrn-admin-card">
					<h3 class="lrn-admin-card__title">
						<i data-lucide="<?php echo esc_attr( $q_tax['icon'] ); ?>" class="lrn-icon--sm"></i>
						<?php echo esc_html( $q_tax['label'] ); ?>
					</h3>
					<div class="lrn-form-field">
						<?php if ( empty( $q_tax_terms ) ) : ?>
							<p class="lrn-form-help">
								<?php
								echo wp_kses_post(
									\Learnomy\Admin\UX::taxonomy_empty_help(
										(string) $q_tax['label'],
										admin_url( 'admin.php?page=learnomy-categories' )
									)
								);
								?>
							</p>
						<?php else : ?>
							<ul class="lrn-tag-checklist">
								<?php foreach ( $q_tax_terms as $q_term ) : ?>
									<li>
										<label class="lrn-checkbox-label">
											<input type="checkbox" name="tag_ids[]" value="<?php echo absint( $q_term->id ); ?>"
												<?php checked( in_array( (int) $q_term->id, $question_tag_ids, true ) ); ?>>
											<span><?php echo esc_html( $q_term->name ); ?></span>
										</label>
									</li>
								<?php endforeach; ?>
							</ul>
							<?php if ( ! empty( $q_tax['help'] ) ) : ?>
								<p class="lrn-form-help"><?php echo esc_html( $q_tax['help'] ); ?></p>
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<div class="lrn-tag-quick-add" data-tax-type="<?php echo esc_attr( $q_tax['type'] ); ?>">
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

			<!-- ── Details card (read-only metadata) ──────────────────────── -->
			<div class="lrn-admin-card">
				<h3 class="lrn-admin-card__title">
					<i data-lucide="info" class="lrn-icon--sm"></i>
					<?php esc_html_e( 'Details', 'learnomy' ); ?>
				</h3>
				<dl class="lrn-meta-list">
					<div class="lrn-meta-list__row">
						<dt><?php esc_html_e( 'Question ID', 'learnomy' ); ?></dt>
						<dd><?php echo absint( $question->id ); ?></dd>
					</div>
					<div class="lrn-meta-list__row">
						<dt><?php esc_html_e( 'Used in', 'learnomy' ); ?></dt>
						<dd>
							<?php
							$q_used = count( (array) $assigned_quiz_ids );
							/* translators: %d: number of quizzes this question is attached to */
							echo esc_html( sprintf( _n( '%d quiz', '%d quizzes', $q_used, 'learnomy' ), $q_used ) );
							?>
						</dd>
					</div>
					<div class="lrn-meta-list__row">
						<dt><?php esc_html_e( 'Version', 'learnomy' ); ?></dt>
						<dd><?php echo absint( $question->version ?? 1 ); ?></dd>
					</div>
					<?php if ( $question_author_name ) : ?>
						<div class="lrn-meta-list__row">
							<dt><?php esc_html_e( 'Author', 'learnomy' ); ?></dt>
							<dd><?php echo esc_html( $question_author_name ); ?></dd>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $question->updated_at ) && '0000-00-00 00:00:00' !== $question->updated_at ) : ?>
						<div class="lrn-meta-list__row">
							<dt><?php esc_html_e( 'Last updated', 'learnomy' ); ?></dt>
							<dd><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $question->updated_at . ' UTC' ) ) ); ?></dd>
						</div>
					<?php endif; ?>
				</dl>
			</div>
		</div><!-- /.lrn-course-form__sidebar -->
		<?php endif; ?>
		</div><!-- /.lrn-course-form__layout -->

		<!-- ── Sticky save bar ────────────────────────────────────────── -->
		<div class="lrn-question-editor__actions">
			<button type="submit" class="lrn-admin-btn lrn-admin-btn--primary">
				<i data-lucide="save"></i>
				<?php echo $is_edit ? esc_html__( 'Update Question', 'learnomy' ) : esc_html__( 'Create Question', 'learnomy' ); ?>
			</button>
		</div>
	</form>

	<script type="application/json" id="lrn-question-editor-initial">
		<?php
		echo wp_json_encode(
			array(
				'type'    => $current_type,
				'answers' => $answers_initial,
			)
		);
		?>
	</script>
</div>
