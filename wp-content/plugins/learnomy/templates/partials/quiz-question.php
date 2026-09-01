<?php
/**
 * Quiz question partial — renders a single question inside the quiz player.
 *
 * Expects:
 *   $question       (object) — row from lrn_questions
 *   $question_index (int)    — 1-based index for display ("Question 3 of 10")
 *   $question_total (int)    — total number of questions in this attempt
 *
 * Render conventions for ordering/matching (mirrors the Pro grader at
 * `learnomy-pro/includes/extensions/advanced-quizzes/class-grader.php`):
 *
 *   - Ordering: `lrn_question_answers` rows ordered ASC by `sort_order`
 *     define the correct sequence. This partial displays the items in a
 *     shuffled order and lets the student drag-drop to reorder. The final
 *     ordered answer-IDs array is written to the Interactivity store as
 *     `state.answers[questionId] = [answerId, answerId, ...]`.
 *
 *   - Matching: rows with `group_id = 'prompt'` form the left column (one
 *     row per pair). Rows with `group_id = 'match'` form the right column.
 *     A prompt and a match are "the correct pair" when their `sort_order`
 *     values are equal. This partial shuffles the right column and lets the
 *     student click a left term then click its right match. The final
 *     mapping is written to the store as
 *     `state.answers[questionId] = { leftAnswerId: rightAnswerId, ... }`.
 *
 * The Interactivity API store (learnomy/quiz) owns answer state — this partial
 * only provides the HTML scaffold with the correct data-wp-* bindings.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $question ) ) {
	return;
}

$question_index = isset( $question_index ) ? (int) $question_index : 1;
$question_total = isset( $question_total ) ? (int) $question_total : 1;
$q_type         = $question->type ?? 'mc_single';

// Fetch answers from lrn_question_answers table.
$answers = [];
if ( class_exists( 'Learnomy\Models\Answer' ) ) {
	$answers = \Learnomy\Models\Answer::get_for_question( (int) $question->id );
}

// Split + shuffle helpers for ordering/matching. We do the shuffle server-side
// so the initial DOM is already scrambled even before the Interactivity store
// hydrates — no "flash of correct order" on first paint.
$ordering_items = [];
$matching_left  = [];
$matching_right = [];

if ( 'ordering' === $q_type && $answers ) {
	$ordering_items = $answers;
	if ( count( $ordering_items ) > 1 ) {
		shuffle( $ordering_items );
	}
} elseif ( 'matching' === $q_type && $answers ) {
	foreach ( $answers as $a ) {
		if ( 'prompt' === ( $a->group_id ?? '' ) ) {
			$matching_left[] = $a;
		} elseif ( 'match' === ( $a->group_id ?? '' ) ) {
			$matching_right[] = $a;
		}
	}
	if ( count( $matching_right ) > 1 ) {
		shuffle( $matching_right );
	}
}
?>
<div class="lrn-quiz-question" data-question-id="<?php echo esc_attr( $question->id ); ?>" data-question-type="<?php echo esc_attr( $q_type ); ?>">
	<p class="lrn-quiz-question__counter">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: current question number, 2: total questions */
				__( 'Question %1$d of %2$d', 'learnomy' ),
				$question_index,
				$question_total
			)
		);
		?>
	</p>

	<h3 class="lrn-quiz-question__prompt">
		<?php echo wp_kses_post( $question->title ); ?>
	</h3>

	<?php if ( in_array( $q_type, [ 'mc_single', 'mc_multi', 'true_false' ], true ) && $answers ) : ?>
		<fieldset class="lrn-quiz-question__options">
			<legend class="screen-reader-text">
				<?php esc_html_e( 'Select your answer', 'learnomy' ); ?>
			</legend>
			<?php
			foreach ( $answers as $index => $answer ) :
				$answer_id    = 'lrn-answer-' . esc_attr( $question->id ) . '-' . $index;
				$answer_label = is_object( $answer ) ? ( $answer->content ?? '' ) : ( is_array( $answer ) ? ( $answer['text'] ?? '' ) : $answer );
				$input_type   = 'mc_multi' === $q_type ? 'checkbox' : 'radio';
				?>
				<label class="lrn-quiz-question__option" for="<?php echo esc_attr( $answer_id ); ?>">
					<input
						type="<?php echo esc_attr( $input_type ); ?>"
						id="<?php echo esc_attr( $answer_id ); ?>"
						name="question_<?php echo esc_attr( $question->id ); ?><?php echo 'mc_multi' === $q_type ? '[]' : ''; ?>"
						value="<?php echo esc_attr( is_object( $answer ) ? $answer->id : $index ); ?>"
						data-wp-on--change="actions.selectAnswer"
					>
					<span class="lrn-quiz-question__option-text"><?php echo esc_html( $answer_label ); ?></span>
				</label>
			<?php endforeach; ?>
		</fieldset>

	<?php elseif ( 'short_answer' === $q_type || 'fill_blank' === $q_type ) : ?>
		<div class="lrn-quiz-question__text-answer">
			<label for="lrn-text-answer-<?php echo esc_attr( $question->id ); ?>" class="screen-reader-text">
				<?php esc_html_e( 'Your answer', 'learnomy' ); ?>
			</label>
			<input
				type="text"
				id="lrn-text-answer-<?php echo esc_attr( $question->id ); ?>"
				class="lrn-input"
				placeholder="<?php esc_attr_e( 'Type your answer here…', 'learnomy' ); ?>"
				data-wp-on--input="actions.updateTextAnswer"
				autocomplete="off"
			>
		</div>

	<?php elseif ( 'essay' === $q_type ) : ?>
		<div class="lrn-quiz-question__essay">
			<label for="lrn-essay-<?php echo esc_attr( $question->id ); ?>" class="screen-reader-text">
				<?php esc_html_e( 'Your essay response', 'learnomy' ); ?>
			</label>
			<textarea
				id="lrn-essay-<?php echo esc_attr( $question->id ); ?>"
				class="lrn-textarea"
				rows="8"
				placeholder="<?php esc_attr_e( 'Write your response here…', 'learnomy' ); ?>"
				data-wp-on--input="actions.updateTextAnswer"
			></textarea>
		</div>

	<?php elseif ( 'ordering' === $q_type && $ordering_items ) : ?>
		<div
			class="lrn-question-ordering"
			data-answer-type="ordering"
			data-wp-init="actions.initOrdering"
		>
			<p class="lrn-question-ordering__instruction">
				<?php esc_html_e( 'Drag the items into the correct order.', 'learnomy' ); ?>
			</p>
			<ol class="lrn-ordering-list" role="list">
				<?php foreach ( $ordering_items as $item ) : ?>
					<li
						class="lrn-ordering-item"
						draggable="true"
						data-answer-id="<?php echo absint( $item->id ); ?>"
						data-wp-on--dragstart="actions.orderingDragStart"
						data-wp-on--dragover="actions.orderingDragOver"
						data-wp-on--dragleave="actions.orderingDragLeave"
						data-wp-on--drop="actions.orderingDrop"
						data-wp-on--dragend="actions.orderingDragEnd"
					>
						<span class="lrn-ordering-handle" aria-hidden="true">
							<!-- lucide grip-vertical -->
							<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="12" r="1"></circle><circle cx="9" cy="5" r="1"></circle><circle cx="9" cy="19" r="1"></circle><circle cx="15" cy="12" r="1"></circle><circle cx="15" cy="5" r="1"></circle><circle cx="15" cy="19" r="1"></circle></svg>
						</span>
						<span class="lrn-ordering-content"><?php echo esc_html( $item->content ); ?></span>
						<span class="lrn-ordering-handle-label screen-reader-text">
							<?php esc_html_e( 'Drag to reorder', 'learnomy' ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
		</div>

	<?php elseif ( 'matching' === $q_type && $matching_left && $matching_right ) : ?>
		<div
			class="lrn-question-matching"
			data-answer-type="matching"
			data-wp-init="actions.initMatching"
		>
			<p class="lrn-question-matching__instruction">
				<?php esc_html_e( 'Click a term on the left, then click its match on the right.', 'learnomy' ); ?>
			</p>
			<div class="lrn-matching__grid">
				<ul class="lrn-matching__left" role="list">
					<?php foreach ( $matching_left as $item ) : ?>
						<li
							class="lrn-matching__term lrn-matching__term--left"
							data-answer-id="<?php echo absint( $item->id ); ?>"
							data-side="left"
							tabindex="0"
							role="button"
							data-wp-on--click="actions.matchingSelectLeft"
							data-wp-on--keydown="actions.matchingKeydown"
						>
							<span class="lrn-matching__term-text"><?php echo esc_html( $item->content ); ?></span>
							<span class="lrn-matching__term-pair" aria-hidden="true"></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<ul class="lrn-matching__right" role="list">
					<?php foreach ( $matching_right as $item ) : ?>
						<li
							class="lrn-matching__term lrn-matching__term--right"
							data-answer-id="<?php echo absint( $item->id ); ?>"
							data-side="right"
							tabindex="0"
							role="button"
							data-wp-on--click="actions.matchingSelectRight"
							data-wp-on--keydown="actions.matchingKeydown"
						>
							<span class="lrn-matching__term-text"><?php echo esc_html( $item->content ); ?></span>
							<span class="lrn-matching__term-pair" aria-hidden="true"></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<button
				type="button"
				class="lrn-btn lrn-btn--ghost lrn-matching__clear"
				data-wp-on--click="actions.matchingClear"
			>
				<?php esc_html_e( 'Clear matches', 'learnomy' ); ?>
			</button>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $question->hint ) ) : ?>
		<details class="lrn-quiz-question__hint">
			<summary><?php esc_html_e( 'Show hint', 'learnomy' ); ?></summary>
			<p><?php echo esc_html( $question->hint ); ?></p>
		</details>
	<?php endif; ?>
</div>
