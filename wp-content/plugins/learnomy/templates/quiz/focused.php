<?php
/**
 * Focused quiz template — API-driven, one question at a time.
 *
 * The PHP template renders a minimal shell: start screen, question container,
 * navigation bar, and results screen. All question data is fetched from the
 * REST API when the student clicks "Start Quiz".
 *
 * Variables available:
 *   $route     (string) 'quiz' or 'standalone-quiz'
 *   $slug      (string) course slug (empty on standalone)
 *   $quiz_slug (string) quiz slug
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// $quiz, $course, $back_url, $time_limit_secs, $time_limit_mins, $passing_pct,
// $max_attempts, $question_count are all pre-resolved by Single_Quiz_View_Data
// and supplied through Template_Loader's extract(). Parent single-quiz.php
// already 404s when the resolver returns quiz=null (gate code at the top of
// the parent), so this style template never runs without a valid quiz.
?>
<main id="lrn-quiz-main" class="lrn-container lrn-quiz"
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

	<!-- Breadcrumb -->
	<nav class="lrn-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'learnomy' ); ?>">
		<a href="<?php echo esc_url( $back_url ); ?>">
			<?php echo esc_html( $back_label ?? ( $course ? $course->title : __( 'Courses', 'learnomy' ) ) ); ?>
		</a>
		<span aria-hidden="true"> &rsaquo; </span>
		<span aria-current="page"><?php echo esc_html( $quiz->title ); ?></span>
	</nav>

	<!-- ============================================================
		1. START SCREEN
		============================================================ -->
	<?php
	// Focused layout has no layout-specific rule items.
	$extra_rules = [];
	require \Learnomy\template_path( 'partials/quiz-start-screen.php' );
	?>

	<!-- ============================================================
		2. QUIZ PLAYER (hidden until started, hidden after submit)
		============================================================ -->
	<div class="lrn-quiz__player" data-wp-bind--hidden="state.playerHidden">
		<?php require \Learnomy\template_path( 'partials/quiz-resume-banner.php' ); ?>

		<!-- Top bar: progress ring + counter + timer -->
		<div class="lrn-quiz__top-bar">
			<button type="button" class="lrn-quiz__progress-ring" data-wp-on--click="actions.toggleOverview"
				aria-label="<?php esc_attr_e( 'Quiz progress', 'learnomy' ); ?>">
				<svg viewBox="0 0 36 36" class="lrn-quiz__ring-svg">
					<circle cx="18" cy="18" r="15.91" class="lrn-quiz__ring-bg"></circle>
					<circle cx="18" cy="18" r="15.91" class="lrn-quiz__ring-fill"
						data-wp-style--stroke-dasharray="state.ringDasharray"></circle>
				</svg>
				<span class="lrn-quiz__ring-label" data-wp-text="state.answeredCount">0</span>
			</button>

			<span class="lrn-quiz__counter" data-wp-text="state.questionCounter" aria-live="polite"></span>

			<span class="lrn-quiz__timer" role="timer" aria-live="polite"
				data-wp-bind--hidden="!state.hasTimer"
				data-wp-class--lrn-quiz__timer--urgent="state.timerUrgent">
				<i data-lucide="clock" aria-hidden="true"></i>
				<span data-wp-text="state.timerDisplay"></span>
			</span>
		</div>

		<!-- Anti-cheat warning -->
		<div class="lrn-quiz__cheat-warning" data-wp-bind--hidden="!state.tabSwitchWarning" role="alert">
			<i data-lucide="alert-triangle" aria-hidden="true"></i>
			<?php esc_html_e( 'Tab switching detected. This activity is recorded.', 'learnomy' ); ?>
		</div>

		<!-- A4: clickable question map (toggled by the progress ring) -->
		<div class="lrn-quiz__map" data-wp-bind--hidden="!state.overviewOpen">
			<div class="lrn-quiz__map-legend" aria-hidden="true">
				<span class="lrn-quiz__map-legend-item"><span class="lrn-quiz__map-dot lrn-quiz__map-dot--answered"></span><?php esc_html_e( 'Answered', 'learnomy' ); ?></span>
				<span class="lrn-quiz__map-legend-item"><span class="lrn-quiz__map-dot lrn-quiz__map-dot--flagged"></span><?php esc_html_e( 'Flagged', 'learnomy' ); ?></span>
				<span class="lrn-quiz__map-legend-item"><span class="lrn-quiz__map-dot lrn-quiz__map-dot--current"></span><?php esc_html_e( 'Current', 'learnomy' ); ?></span>
				<span class="lrn-quiz__map-legend-item"><span class="lrn-quiz__map-dot"></span><?php esc_html_e( 'Unseen', 'learnomy' ); ?></span>
			</div>
			<nav class="lrn-quiz__map-grid" aria-label="<?php esc_attr_e( 'Jump to question', 'learnomy' ); ?>">
				<template data-wp-each="state.questionMap" data-wp-each-key="context.item.index">
					<button type="button" class="lrn-quiz__map-cell"
						data-wp-class--is-answered="context.item.isAnswered"
						data-wp-class--is-flagged="context.item.isFlagged"
						data-wp-class--is-current="context.item.isCurrent"
						data-wp-on--click="actions.jumpFromMap"
						data-wp-text="context.item.number"></button>
				</template>
			</nav>
		</div>

		<!-- Question container — single slot, content swapped by store -->
		<div class="lrn-quiz__question-wrap" data-wp-bind--hidden="state.preSubmitOpen">
			<!-- Category heading for group-by-category quizzes -->
			<p class="lrn-quiz__category-heading" data-wp-bind--hidden="!state.currentCategoryLabel" data-wp-text="state.currentCategoryLabel"></p>
			<h3 class="lrn-quiz-question__prompt" data-wp-text="state.currentQuestionTitle"></h3>

			<!-- A3: rich question stem (server-rendered, kses'd) above the answers -->
			<div class="lrn-quiz-question__stem" data-wp-bind--hidden="!state.currentQuestionStemHtml" data-wp-watch--stem="callbacks.renderCurrentStem"></div>

			<!-- MC / True-False answers -->
			<fieldset class="lrn-quiz-question__options"
				data-wp-bind--hidden="state.isTextType">
				<legend class="screen-reader-text"><?php esc_html_e( 'Select your answer', 'learnomy' ); ?></legend>
				<template data-wp-each="state.currentAnswers">
					<label class="lrn-quiz-question__option"
						data-wp-class--lrn-quiz-question__option--selected="state.isOptionSelected">
						<input
							type="radio"
									data-wp-bind--type="state.optionInputType"
							data-wp-bind--name="state.currentInputName"
							data-wp-bind--value="context.item.id"
							data-wp-bind--checked="state.isOptionSelected"
							data-wp-on--change="actions.selectAnswer">
						<span class="lrn-quiz-question__option-text" data-wp-text="context.item.content"></span>
					</label>
				</template>
			</fieldset>

			<!-- Short answer -->
			<div class="lrn-quiz-question__text-answer" data-wp-bind--hidden="!state.isShortAnswerType">
				<input type="text" class="lrn-input"
					placeholder="<?php esc_attr_e( 'Type your answer here…', 'learnomy' ); ?>"
					data-wp-on--input="actions.updateTextAnswer"
					data-wp-on--blur="actions.commitTextAnswer"
					data-wp-bind--value="state.currentTextAnswer"
					autocomplete="off">
			</div>

			<!-- Fill in the blank — one input per {{blankN}} token -->
			<div class="lrn-quiz-question__blanks" data-wp-bind--hidden="!state.isFillBlankType">
				<template data-wp-each="state.currentBlanks" data-wp-each-key="context.item.key">
					<label class="lrn-quiz-question__blank">
						<span class="lrn-quiz-question__blank-label" data-wp-text="context.item.label"></span>
						<input type="text" class="lrn-input"
							data-wp-bind--data-blank-key="context.item.key"
							data-wp-bind--value="context.item.value"
							data-wp-on--input="actions.updateBlankAnswer"
							data-wp-on--blur="actions.commitTextAnswer"
							autocomplete="off">
					</label>
				</template>
			</div>

			<!-- Essay -->
			<div class="lrn-quiz-question__essay" data-wp-bind--hidden="!state.isEssayType">
				<textarea class="lrn-textarea" rows="8"
					placeholder="<?php esc_attr_e( 'Write your response here…', 'learnomy' ); ?>"
					data-wp-on--input="actions.updateTextAnswer"
					data-wp-bind--value="state.currentTextAnswer"></textarea>
			</div>

			<!-- Custom text-answer types registered by an extension
				(Pro numeric / math / calculated). Reuses the text-answer store. -->
			<div class="lrn-quiz-question__text-answer" data-wp-bind--hidden="!state.isCustomTextType">
				<input type="text" class="lrn-input"
					data-wp-on--input="actions.updateTextAnswer"
					data-wp-on--blur="actions.commitTextAnswer"
					data-wp-bind--value="state.currentTextAnswer"
					autocomplete="off">
			</div>

			<!-- Ordering (Surface G integration point) -->
			<div class="lrn-question-ordering"
				data-answer-type="ordering"
				data-wp-bind--hidden="!state.isOrderingType"
				data-wp-init="actions.initOrdering">
				<p class="lrn-question-ordering__instruction">
					<?php esc_html_e( 'Drag the items into the correct order.', 'learnomy' ); ?>
				</p>
				<ol class="lrn-ordering-list" role="list">
					<template data-wp-each="state.currentOrderingItems">
						<li class="lrn-ordering-item"
							draggable="true"
							data-wp-bind--data-answer-id="context.item.id"
							data-wp-on--dragstart="actions.orderingDragStart"
							data-wp-on--dragover="actions.orderingDragOver"
							data-wp-on--dragleave="actions.orderingDragLeave"
							data-wp-on--drop="actions.orderingDrop"
							data-wp-on--dragend="actions.orderingDragEnd">
							<span class="lrn-ordering-handle" aria-hidden="true">
								<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="12" r="1"></circle><circle cx="9" cy="5" r="1"></circle><circle cx="9" cy="19" r="1"></circle><circle cx="15" cy="12" r="1"></circle><circle cx="15" cy="5" r="1"></circle><circle cx="15" cy="19" r="1"></circle></svg>
							</span>
							<span class="lrn-ordering-content" data-wp-text="context.item.content"></span>
						</li>
					</template>
				</ol>
			</div>

			<!-- Matching (Surface G integration point) -->
			<div class="lrn-question-matching"
				data-answer-type="matching"
				data-wp-bind--hidden="!state.isMatchingType"
				data-wp-init="actions.initMatching">
				<p class="lrn-question-matching__instruction">
					<?php esc_html_e( 'Click a term on the left, then click its match on the right.', 'learnomy' ); ?>
				</p>
				<div class="lrn-matching__grid">
					<ul class="lrn-matching__left" role="list">
						<template data-wp-each="state.currentMatchingLeft">
							<li class="lrn-matching__term lrn-matching__term--left"
								tabindex="0" role="button"
								data-side="left"
								data-wp-bind--data-answer-id="context.item.id"
								data-wp-on--click="actions.matchingSelectLeft"
								data-wp-on--keydown="actions.matchingKeydown">
								<span class="lrn-matching__term-text" data-wp-text="context.item.content"></span>
								<span class="lrn-matching__term-pair" aria-hidden="true"></span>
							</li>
						</template>
					</ul>
					<ul class="lrn-matching__right" role="list">
						<template data-wp-each="state.currentMatchingRight">
							<li class="lrn-matching__term lrn-matching__term--right"
								tabindex="0" role="button"
								data-side="right"
								data-wp-bind--data-answer-id="context.item.id"
								data-wp-on--click="actions.matchingSelectRight"
								data-wp-on--keydown="actions.matchingKeydown">
								<span class="lrn-matching__term-text" data-wp-text="context.item.content"></span>
								<span class="lrn-matching__term-pair" aria-hidden="true"></span>
							</li>
						</template>
					</ul>
				</div>
				<button type="button" class="lrn-btn lrn-btn--ghost lrn-matching__clear"
					data-wp-on--click="actions.matchingClear">
					<?php esc_html_e( 'Clear matches', 'learnomy' ); ?>
				</button>
			</div>

			<!-- Hint -->
			<details class="lrn-quiz-question__hint" data-wp-bind--hidden="!state.currentQuestionHint">
				<summary><?php esc_html_e( 'Show hint', 'learnomy' ); ?></summary>
				<p data-wp-text="state.currentQuestionHint"></p>
			</details>

			<!-- Immediate feedback panel -->
			<div class="lrn-quiz-question__feedback" data-wp-bind--hidden="!state.currentAnswerFeedback">
				<div class="lrn-quiz-question__feedback-badge"
					data-wp-class--lrn-quiz-question__feedback-badge--correct="state.currentAnswerFeedback.correct"
					data-wp-class--lrn-quiz-question__feedback-badge--incorrect="!state.currentAnswerFeedback.correct">
					<i data-lucide="check-circle" aria-hidden="true" data-wp-bind--hidden="!state.currentAnswerFeedback.correct"></i>
					<i data-lucide="x-circle" aria-hidden="true" data-wp-bind--hidden="state.currentAnswerFeedback.correct"></i>
					<span data-wp-text="state.feedbackLabel"></span>
				</div>
				<p class="lrn-quiz-question__feedback-explanation"
					data-wp-bind--hidden="!state.currentAnswerFeedback.explanation"
					data-wp-text="state.currentAnswerFeedback.explanation"></p>
			</div>
		</div>

		<!-- Navigation: Previous | Flag | Next/Submit -->
		<div class="lrn-quiz__nav" data-wp-bind--hidden="state.preSubmitOpen">
			<?php
			$action_icon  = 'arrow-left';
			$action_label = __( 'Previous', 'learnomy' );
			$action_title = null;
			$action_href  = null;
			$action_aria  = __( 'Go to previous question', 'learnomy' );
			$action_class = '';
			$action_attrs = [
				'data-wp-on--click'      => 'actions.prevQuestion',
				'data-wp-bind--disabled' => 'state.isFirstQuestion',
				'data-wp-bind--hidden'   => 'state.isSequential',
			];
			require \Learnomy\template_path( 'partials/lrn-action.php' );
			?>

			<!-- A4: flag the current question for review -->
			<button type="button" class="lrn-quiz__flag-btn"
				data-wp-class--is-flagged="state.isCurrentFlagged"
				data-wp-bind--aria-pressed="state.isCurrentFlagged"
				data-wp-on--click="actions.toggleFlag">
				<i data-lucide="flag" aria-hidden="true"></i>
				<span data-wp-text="state.flagBtnLabel"></span>
			</button>

			<span class="lrn-quiz__nav-spacer"></span>

			<?php
			$action_icon    = 'arrow-right';
			$action_label   = __( 'Next', 'learnomy' );
			$action_title   = null;
			$action_href    = null;
			$action_aria    = __( 'Go to next question or submit', 'learnomy' );
			$action_variant = 'primary';
			$action_class   = '';
			$action_attrs   = [
				'data-wp-on--click' => 'actions.nextOrSubmit',
				'data-wp-text'      => 'state.nextBtnLabel',
			];
			require \Learnomy\template_path( 'partials/lrn-action.php' );
			$action_variant = null;
			?>
		</div>

		<!-- A4: pre-submit review — shown (instead of the question) when the last
			"Submit" is reached with anything unanswered or flagged. -->
		<div class="lrn-quiz__presubmit" data-wp-bind--hidden="!state.preSubmitOpen" role="group"
			aria-label="<?php esc_attr_e( 'Review before submitting', 'learnomy' ); ?>">
			<i data-lucide="clipboard-list" class="lrn-quiz__presubmit-icon" aria-hidden="true"></i>
			<h3 class="lrn-quiz__presubmit-title"><?php esc_html_e( 'Before you submit', 'learnomy' ); ?></h3>
			<p class="lrn-quiz__presubmit-summary" data-wp-text="state.preSubmitSummary"></p>
			<div class="lrn-quiz__presubmit-actions">
				<button type="button" class="lrn-btn lrn-btn--secondary"
					data-wp-bind--hidden="!state.unansweredCount" data-wp-on--click="actions.jumpToFirstUnanswered">
					<?php esc_html_e( 'Review unanswered', 'learnomy' ); ?>
				</button>
				<button type="button" class="lrn-btn lrn-btn--secondary"
					data-wp-bind--hidden="!state.flaggedCount" data-wp-on--click="actions.jumpToFirstFlagged">
					<?php esc_html_e( 'Review flagged', 'learnomy' ); ?>
				</button>
				<button type="button" class="lrn-btn lrn-btn--secondary" data-wp-on--click="actions.closePreSubmit">
					<?php esc_html_e( 'Keep working', 'learnomy' ); ?>
				</button>
				<button type="button" class="lrn-btn lrn-btn--primary" data-wp-on--click="actions.confirmSubmit">
					<?php esc_html_e( 'Submit quiz', 'learnomy' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- ============================================================
		3. RESULTS SCREEN (hidden until submitted)
		============================================================ -->
	<div class="lrn-quiz__results" data-wp-bind--hidden="state.resultsHidden" aria-live="polite" aria-atomic="true">

		<!-- Score summary card -->
		<div class="lrn-quiz__results-summary lrn-card">
			<?php
			$lrn_results_icon_class = 'lrn-quiz__results-icon';
			require \Learnomy\template_path( 'partials/quiz-results-icon.php' );
			?>
			<h2 class="lrn-quiz__results-title" data-wp-text="state.resultsTitle"></h2>
			<p class="lrn-quiz__results-score" data-wp-text="state.scoreText"></p>
			<p class="lrn-quiz__results-breakdown" data-wp-text="state.scoreSummary"></p>
			<p class="lrn-quiz__results-status" data-wp-text="state.passFailText"></p>

			<!-- A2: per-category score breakdown (only when the quiz groups by category) -->
			<div class="lrn-quiz__results-categories" data-wp-bind--hidden="!state.hasCategoryBreakdown">
				<h3 class="lrn-quiz__results-categories-title"><?php esc_html_e( 'Score by category', 'learnomy' ); ?></h3>
				<ul class="lrn-quiz__results-categories-list">
					<template data-wp-each--cat="state.categoryBreakdown" data-wp-each-key="context.cat.category_id">
						<li class="lrn-quiz__results-category">
							<span class="lrn-quiz__results-category-name" data-wp-text="context.cat.label"></span>
							<span class="lrn-quiz__results-category-score" data-wp-text="context.cat.summary"></span>
						</li>
					</template>
				</ul>
			</div>

			<?php require \Learnomy\template_path( 'partials/quiz-result-actions.php' ); ?>
		</div>
	</div>

	<!-- Per-question review. A SIBLING of the results hero, not a child:
		nested inside .lrn-quiz__results it inherited the hero's text-align
		center and could never exceed the hero's 540px cap, which squeezed
		the whole review into one narrow centered column on wide screens. -->
	<div class="lrn-quiz__review" data-wp-bind--hidden="!state.hasReview">
			<h3 class="lrn-quiz__review-heading"><?php esc_html_e( 'Question Review', 'learnomy' ); ?></h3>
		<?php require \Learnomy\template_path( 'partials/quiz-review-filters.php' ); ?>

			<template data-wp-each="state.reviewQuestions">
				<details class="lrn-quiz__review-item lrn-card"
					data-wp-bind--open="state.reviewOpenByDefault"
					data-wp-class--lrn-quiz__review-item--correct="context.item.correct"
					data-wp-class--lrn-quiz__review-item--incorrect="context.item.incorrect"
					data-wp-class--lrn-quiz__review-item--pending="context.item.pending">

					<summary class="lrn-quiz__review-item-header">
						<span class="lrn-quiz__review-item-status">
							<i data-wp-bind--data-lucide="context.item.status_icon" aria-hidden="true"></i>
						</span>
						<h4 class="lrn-quiz__review-item-title" data-wp-text="context.item.title"></h4>
						<span class="lrn-quiz__review-item-score">
							<span data-wp-text="context.item.score"></span>/<span data-wp-text="context.item.max_score"></span>
						</span>
					<i class="lrn-quiz__review-item-chevron" data-lucide="chevron-down" aria-hidden="true"></i>
					</summary>

					<!-- Answer options with correct/selected indicators -->
					<template data-wp-each="context.item.answers">
						<div class="lrn-quiz__review-answer"
							data-wp-class--lrn-quiz__review-answer--correct="context.item.correct"
							data-wp-class--lrn-quiz__review-answer--selected="context.item.selected"
							data-wp-class--lrn-quiz__review-answer--wrong="context.item.wrong">
							<span class="lrn-quiz__review-answer-icon">
								<i data-wp-bind--data-lucide="context.item.icon" aria-hidden="true"></i>
							</span>
							<span data-wp-text="context.item.content"></span>
						</div>
					</template>

					<!-- Essay response (if essay type) -->
					<div class="lrn-quiz__review-essay" data-wp-bind--hidden="!context.item.given">
						<p class="lrn-quiz__review-essay-label"><?php esc_html_e( 'Your response:', 'learnomy' ); ?></p>
						<blockquote class="lrn-quiz__review-essay-text" data-wp-text="context.item.given"></blockquote>
						<p class="lrn-quiz__review-essay-pending" data-wp-bind--hidden="!context.item.pending">
							<i data-lucide="clock" aria-hidden="true"></i>
							<?php esc_html_e( 'Awaiting instructor review', 'learnomy' ); ?>
						</p>
					</div>
				</details>
			</template>
	</div>

</main>
