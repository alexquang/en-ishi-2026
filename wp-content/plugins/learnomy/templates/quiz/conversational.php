<?php
/**
 * Conversational (Typeform-style) quiz template.
 *
 * Full-viewport immersive cards, large typography, auto-advance on answer
 * selection, forward-only navigation. Designed for short engaging quizzes
 * (3-15 questions), surveys, and lead gen.
 *
 * Variables available:
 *   $route     (string) 'quiz' or 'standalone-quiz'
 *   $slug      (string) course slug (empty on standalone)
 *   $quiz_slug (string) quiz slug
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// All quiz vars are supplied by Single_Quiz_View_Data via Template_Loader.
// Parent single-quiz.php gates quiz=null with a 404 before reaching here.
?>
<main id="lrn-quiz-main" class="lrn-conv"
	data-wp-interactive="learnomy/quiz"
	data-wp-context='
	<?php
	echo esc_attr(
		wp_json_encode(
			[
				'quizId'        => (int) $quiz->id,
				'backUrl'       => $back_url,
				'questionTotal' => (int) $question_count,
				'timeLimitSecs' => $time_limit_secs,
				'passingPct'    => $passing_pct,
			]
		)
	);
	?>
	'>

	<!-- Progress bar — thin line at top of viewport -->
	<div class="lrn-conv__progress"
		data-wp-bind--hidden="!state.started"
		data-wp-style--width="state.progressBarWidth"
		aria-hidden="true"></div>

	<!-- Timer — minimal, top-right -->
	<span class="lrn-conv__timer" role="timer" aria-live="polite"
		data-wp-bind--hidden="!state.hasTimer"
		data-wp-class--lrn-conv__timer--urgent="state.timerUrgent">
		<i data-lucide="clock" aria-hidden="true"></i>
		<span data-wp-text="state.timerDisplay"></span>
	</span>

	<!-- ============================================================
		1. START SCREEN
		============================================================ -->
	<?php
	// The SHARED start screen, not a hand-rolled copy. The copy this
	// replaced silently missed three shipped fixes - the attempts-exhausted
	// gate, the prior-attempt panel with its Review link, and the
	// retake-aware button label - so a returning student always read
	// "Start Quiz" and then hit the server's 400 (Basecamp 10171576650).
	// Layout (cover / brief / boarding) follows the site-wide
	// quiz_start_style() setting like every other template.
	require \Learnomy\template_path( 'partials/quiz-start-screen.php' );
	?>

	<!-- ============================================================
		2. QUIZ PLAYER — full viewport question cards
		============================================================ -->
	<div class="lrn-conv__player" data-wp-bind--hidden="state.playerHidden">
		<?php require \Learnomy\template_path( 'partials/quiz-resume-banner.php' ); ?>

		<!-- Anti-cheat warning -->
		<div class="lrn-conv__cheat-warning" data-wp-bind--hidden="!state.tabSwitchWarning" role="alert">
			<i data-lucide="alert-triangle" aria-hidden="true"></i>
			<?php esc_html_e( 'Tab switching detected. This activity is recorded.', 'learnomy' ); ?>
		</div>

		<!-- Full-viewport question card -->
		<div class="lrn-conv__card"
			data-wp-class--lrn-conv__card--transitioning="state.isTransitioning">

			<!-- Question counter -->
			<span class="lrn-conv__counter" data-wp-text="state.questionCounter" aria-live="polite"></span>

			<!-- Category heading for group-by-category quizzes -->
			<p class="lrn-quiz__category-heading" data-wp-bind--hidden="!state.currentCategoryLabel" data-wp-text="state.currentCategoryLabel"></p>
			<!-- Question text -->
			<h2 class="lrn-conv__question" data-wp-text="state.currentQuestionTitle"></h2>

			<!-- A3: rich question stem (server-rendered, kses'd) above the answers -->
			<div class="lrn-quiz-question__stem" data-wp-bind--hidden="!state.currentQuestionStemHtml" data-wp-watch--stem="callbacks.renderCurrentStem"></div>

			<!-- MC / True-False — pill-shaped answer buttons -->
			<?php
			/*
			 * Roles are BOUND, not fixed: this layout draws options as buttons,
			 * so the role carries the single/multi semantics that the input
			 * `type` carries in the other layouts. A hardcoded radiogroup told a
			 * screen-reader user "one of N" on a question that wants several,
			 * and the matching auto-advance made that true in practice
			 * (Basecamp 10134139919).
			 */
			?>
			<div class="lrn-conv__options"
				data-wp-bind--hidden="state.isTextInputQuestion"
				role="radiogroup"
				data-wp-bind--role="state.optionGroupRole"
				aria-label="<?php esc_attr_e( 'Answer options', 'learnomy' ); ?>">
				<template data-wp-each="state.currentAnswers">
					<button type="button"
						class="lrn-conv__option"
						role="radio"
						data-wp-bind--role="state.optionButtonRole"
						data-wp-bind--aria-checked="state.isOptionSelected"
						data-wp-class--lrn-conv__option--selected="state.isOptionSelected"
						data-wp-on--click="actions.selectAndAdvance"
						data-wp-text="context.item.content">
					</button>
				</template>
			</div>

			<?php
			/*
			 * Multi-select needs an explicit "I am done choosing" — there is no
			 * such signal in a set of toggles, which is why this layout must not
			 * auto-advance on the first tick.
			 */
			?>
			<div class="lrn-conv__multi-continue" data-wp-bind--hidden="!state.isCurrentMultiSelect">
				<p class="lrn-conv__multi-hint"><?php esc_html_e( 'Pick every answer that applies, then continue.', 'learnomy' ); ?></p>
				<?php
				$action_icon    = 'arrow-right';
				$action_label   = __( 'Continue', 'learnomy' );
				$action_title   = null;
				$action_href    = null;
				$action_aria    = __( 'Continue to next question', 'learnomy' );
				$action_variant = 'primary';
				$action_class   = 'lrn-conv__continue';
				$action_attrs   = array(
					'data-wp-on--click' => 'actions.continueFromMultiSelect',
				);
				require \Learnomy\template_path( 'partials/lrn-action.php' );
				$action_variant = null;
				?>
			</div>

			<!-- Fill in the blank — one input per {{blankN}} token -->
			<div class="lrn-conv__blanks" data-wp-bind--hidden="!state.isFillBlankType">
				<template data-wp-each="state.currentBlanks" data-wp-each-key="context.item.key">
					<label class="lrn-quiz-question__blank">
						<span class="lrn-quiz-question__blank-label" data-wp-text="context.item.label"></span>
						<input type="text" class="lrn-input lrn-conv__input"
							data-wp-bind--data-blank-key="context.item.key"
							data-wp-bind--value="context.item.value"
							data-wp-on--input="actions.updateBlankAnswer"
							autocomplete="off">
					</label>
				</template>
			</div>

			<!-- Short answer -->
			<div class="lrn-conv__text-input" data-wp-bind--hidden="!state.isShortAnswerType">
				<input type="text" class="lrn-input lrn-conv__input"
					placeholder="<?php esc_attr_e( 'Type your answer here…', 'learnomy' ); ?>"
					data-wp-on--input="actions.updateTextAnswer"
					data-wp-bind--value="state.currentTextAnswer"
					autocomplete="off">
				<?php
				$action_icon    = 'arrow-right';
				$action_label   = __( 'Continue', 'learnomy' );
				$action_title   = null;
				$action_href    = null;
				$action_aria    = __( 'Continue to next question', 'learnomy' );
				$action_variant = 'primary';
				$action_class   = 'lrn-conv__continue';
				$action_attrs   = [
					'data-wp-on--click' => 'actions.continueFromText',
				];
				require \Learnomy\template_path( 'partials/lrn-action.php' );
				$action_variant = null;
				?>
			</div>

			<!-- Essay -->
			<div class="lrn-conv__essay" data-wp-bind--hidden="!state.isEssayType">
				<textarea class="lrn-textarea lrn-conv__textarea" rows="6"
					placeholder="<?php esc_attr_e( 'Write your response here…', 'learnomy' ); ?>"
					data-wp-on--input="actions.updateTextAnswer"
					data-wp-bind--value="state.currentTextAnswer"></textarea>
				<?php
				$action_icon    = 'arrow-right';
				$action_label   = __( 'Continue', 'learnomy' );
				$action_title   = null;
				$action_href    = null;
				$action_aria    = __( 'Continue to next question', 'learnomy' );
				$action_variant = 'primary';
				$action_class   = 'lrn-conv__continue';
				$action_attrs   = [
					'data-wp-on--click' => 'actions.continueFromText',
				];
				require \Learnomy\template_path( 'partials/lrn-action.php' );
				$action_variant = null;
				?>
			</div>

			<!-- Hint -->
			<details class="lrn-conv__hint" data-wp-bind--hidden="!state.currentQuestionHint">
				<summary><?php esc_html_e( 'Show hint', 'learnomy' ); ?></summary>
				<p data-wp-text="state.currentQuestionHint"></p>
			</details>
		</div>

		<!-- Keyboard hint -->
		<p class="lrn-conv__keyboard-hint" data-wp-bind--hidden="state.isTextInputQuestion">
			<?php esc_html_e( 'Press 1-4 to select an answer', 'learnomy' ); ?>
		</p>
	</div>

	<!-- ============================================================
		3. RESULTS SCREEN — full viewport centered
		============================================================ -->
	<div class="lrn-conv__results" data-wp-bind--hidden="state.resultsHidden">

		<?php
		$lrn_results_icon_class = 'lrn-conv__results-icon';
		require \Learnomy\template_path( 'partials/quiz-results-icon.php' );
		?>
		<h2 class="lrn-conv__results-title" data-wp-text="state.resultsTitle"></h2>
		<p class="lrn-conv__results-score" data-wp-text="state.scoreText"></p>
		<p class="lrn-conv__results-breakdown" data-wp-text="state.scoreSummary"></p>
		<p class="lrn-conv__results-status" data-wp-text="state.passFailText"></p>

		<!-- A2: per-category score breakdown (only when the quiz groups by category) -->
		<div class="lrn-conv__results-categories" data-wp-bind--hidden="!state.hasCategoryBreakdown">
			<h3 class="lrn-conv__results-categories-title"><?php esc_html_e( 'Score by category', 'learnomy' ); ?></h3>
			<ul class="lrn-conv__results-categories-list">
				<template data-wp-each--cat="state.categoryBreakdown" data-wp-each-key="context.cat.category_id">
					<li class="lrn-conv__results-category">
						<span class="lrn-conv__results-category-name" data-wp-text="context.cat.label"></span>
						<span class="lrn-conv__results-category-score" data-wp-text="context.cat.summary"></span>
					</li>
				</template>
			</ul>
		</div>

		<?php
		$results_actions_class = 'lrn-conv__results-actions';
		require \Learnomy\template_path( 'partials/quiz-result-actions.php' );
		?>

		<!-- Per-question review -->
		<div class="lrn-conv__review" data-wp-bind--hidden="!state.hasReview">
			<h3 class="lrn-conv__review-heading"><?php esc_html_e( 'Question Review', 'learnomy' ); ?></h3>

			<template data-wp-each="state.reviewQuestions">
				<div class="lrn-conv__review-item lrn-card"
					data-wp-class--lrn-conv__review-item--correct="context.item.correct"
					data-wp-class--lrn-conv__review-item--incorrect="context.item.incorrect"
					data-wp-class--lrn-conv__review-item--pending="context.item.pending">

					<div class="lrn-conv__review-item-header">
						<span class="lrn-conv__review-item-status">
							<i data-wp-bind--data-lucide="context.item.status_icon" aria-hidden="true"></i>
						</span>
						<h4 class="lrn-conv__review-item-title" data-wp-text="context.item.title"></h4>
						<span class="lrn-conv__review-item-score">
							<span data-wp-text="context.item.score"></span>/<span data-wp-text="context.item.max_score"></span>
						</span>
					</div>

					<template data-wp-each="context.item.answers">
						<div class="lrn-conv__review-answer"
							data-wp-class--lrn-conv__review-answer--correct="context.item.correct"
							data-wp-class--lrn-conv__review-answer--selected="context.item.selected"
							data-wp-class--lrn-conv__review-answer--wrong="context.item.wrong">
							<span class="lrn-conv__review-answer-icon">
								<i data-wp-bind--data-lucide="context.item.icon" aria-hidden="true"></i>
							</span>
							<span data-wp-text="context.item.content"></span>
						</div>
					</template>

					<div class="lrn-conv__review-essay" data-wp-bind--hidden="!context.item.given">
						<p class="lrn-conv__review-essay-label"><?php esc_html_e( 'Your response:', 'learnomy' ); ?></p>
						<blockquote class="lrn-conv__review-essay-text" data-wp-text="context.item.given"></blockquote>
						<p class="lrn-conv__review-essay-pending" data-wp-bind--hidden="!context.item.pending">
							<i data-lucide="clock" aria-hidden="true"></i>
							<?php esc_html_e( 'Awaiting instructor review', 'learnomy' ); ?>
						</p>
					</div>
				</div>
			</template>
		</div>
	</div>

</main>
