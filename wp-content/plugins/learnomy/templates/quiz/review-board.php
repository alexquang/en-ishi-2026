<?php
/**
 * Review Board quiz template -- split-panel layout with question list.
 *
 * Desktop-first design for long exams with random access. Left panel
 * shows a scrollable question list with status badges (answered,
 * unanswered, flagged). Right panel shows the active question at full
 * detail. On mobile, the left panel becomes a bottom sheet.
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
<main id="lrn-quiz-main" class="lrn-container lrn-quiz lrn-quiz--review-board"
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
	// Review-board layout: one extra rule explaining the free-navigation model.
	$extra_rules = [
		[
			'icon' => 'columns',
			'text' => __( 'Review board -- jump to any question at any time', 'learnomy' ),
		],
	];
	require \Learnomy\template_path( 'partials/quiz-start-screen.php' );
	?>

	<!-- ============================================================
		2. QUIZ PLAYER (hidden until started, hidden after submit)
		============================================================ -->
	<div class="lrn-quiz__player lrn-quiz__player--review-board" data-wp-bind--hidden="state.playerHidden">
		<?php require \Learnomy\template_path( 'partials/quiz-resume-banner.php' ); ?>

		<!-- Top bar: timer + progress + submit -->
		<div class="lrn-rb__top-bar">
			<span class="lrn-rb__progress-text" data-wp-text="state.rbProgressText" aria-live="polite"></span>

			<span class="lrn-quiz__timer" role="timer" aria-live="polite"
				data-wp-bind--hidden="!state.hasTimer"
				data-wp-class--lrn-quiz__timer--urgent="state.timerUrgent">
				<i data-lucide="clock" aria-hidden="true"></i>
				<span data-wp-text="state.timerDisplay"></span>
			</span>

			<?php
			$action_icon    = 'send';
			$action_label   = __( 'Submit', 'learnomy' );
			$action_title   = null;
			$action_href    = null;
			$action_aria    = __( 'Submit quiz', 'learnomy' );
			$action_variant = 'primary';
			$action_class   = 'lrn-rb__submit-btn';
			$action_attrs   = [
				'data-wp-on--click' => 'actions.submitQuiz',
			];
			require \Learnomy\template_path( 'partials/lrn-action.php' );
			$action_variant = null;
			?>
		</div>

		<!-- Anti-cheat warning -->
		<div class="lrn-quiz__cheat-warning" data-wp-bind--hidden="!state.tabSwitchWarning" role="alert">
			<i data-lucide="alert-triangle" aria-hidden="true"></i>
			<?php esc_html_e( 'Tab switching detected. This activity is recorded.', 'learnomy' ); ?>
		</div>

		<!-- Two-column grid layout -->
		<div class="lrn-rb__grid">

			<!-- Left panel: question list -->
			<aside class="lrn-rb__sidebar">
				<!-- Filter pills -->
				<div class="lrn-rb__filters" role="tablist" aria-label="<?php esc_attr_e( 'Question filters', 'learnomy' ); ?>">
					<button type="button" class="lrn-rb__filter-pill"
						role="tab"
						data-wp-class--lrn-rb__filter-pill--active="state.isFilterAll"
						data-wp-on--click="actions.setFilterAll"
						aria-selected="true">
						<?php esc_html_e( 'All', 'learnomy' ); ?>
					</button>
					<button type="button" class="lrn-rb__filter-pill"
						role="tab"
						data-wp-class--lrn-rb__filter-pill--active="state.isFilterUnanswered"
						data-wp-on--click="actions.setFilterUnanswered">
						<?php esc_html_e( 'Unanswered', 'learnomy' ); ?>
					</button>
					<button type="button" class="lrn-rb__filter-pill"
						role="tab"
						data-wp-class--lrn-rb__filter-pill--active="state.isFilterFlagged"
						data-wp-on--click="actions.setFilterFlagged">
						<?php esc_html_e( 'Flagged', 'learnomy' ); ?>
					</button>
				</div>

				<!-- Scrollable question list (flat — shown when NOT grouped) -->
				<div class="lrn-rb__question-list" role="list" data-wp-bind--hidden="state.showGroupedList">
					<template data-wp-each="state.filteredQuestionList">
						<button type="button" class="lrn-rb__list-item" role="listitem"
							data-wp-class--lrn-rb__list-item--current="context.item.isCurrent"
							data-wp-class--lrn-rb__list-item--answered="context.item.isAnswered"
							data-wp-class--lrn-rb__list-item--flagged="context.item.isFlagged"
							data-wp-on--click="actions.jumpToListItem">
							<span class="lrn-rb__list-item-number" data-wp-text="context.item.label"></span>
							<span class="lrn-rb__list-item-title" data-wp-text="context.item.titleShort"></span>
							<span class="lrn-rb__list-item-status">
								<i data-wp-bind--data-lucide="context.item.statusIcon" aria-hidden="true"></i>
							</span>
						</button>
					</template>
				</div>

				<!-- BC #9993397735: grouped-by-category navigator (collapsible per-subject) -->
				<div class="lrn-rb__question-list lrn-rb__question-list--grouped" role="list" data-wp-bind--hidden="!state.showGroupedList">
					<template data-wp-each--cat="state.groupedQuestionList" data-wp-each-key="context.cat.name">
						<div class="lrn-rb__cat-group" data-wp-class--is-collapsed="context.cat.collapsed">
							<button type="button" class="lrn-rb__cat-header"
								data-wp-on--click="actions.toggleCategoryGroup">
								<i data-lucide="chevron-down" class="lrn-rb__cat-chevron" aria-hidden="true"></i>
								<span class="lrn-rb__cat-name" data-wp-text="context.cat.name"></span>
								<span class="lrn-rb__cat-count" data-wp-text="context.cat.countLabel"></span>
							</button>
							<div class="lrn-rb__cat-items" data-wp-bind--hidden="context.cat.collapsed">
								<template data-wp-each--item="context.cat.items" data-wp-each-key="context.item.id">
									<button type="button" class="lrn-rb__list-item" role="listitem"
										data-wp-class--lrn-rb__list-item--current="context.item.isCurrent"
										data-wp-class--lrn-rb__list-item--answered="context.item.isAnswered"
										data-wp-class--lrn-rb__list-item--flagged="context.item.isFlagged"
										data-wp-on--click="actions.jumpToListItem">
										<span class="lrn-rb__list-item-number" data-wp-text="context.item.label"></span>
										<span class="lrn-rb__list-item-title" data-wp-text="context.item.titleShort"></span>
										<span class="lrn-rb__list-item-status">
											<i data-wp-bind--data-lucide="context.item.statusIcon" aria-hidden="true"></i>
										</span>
									</button>
								</template>
							</div>
						</div>
					</template>
				</div>
			</aside>

			<!-- Right panel: active question detail -->
			<section class="lrn-rb__main" aria-label="<?php esc_attr_e( 'Question detail', 'learnomy' ); ?>">
				<div class="lrn-rb__question-header">
					<span class="lrn-rb__question-number" data-wp-text="state.questionCounter"></span>
					<button type="button" class="lrn-rb__flag-btn"
						data-wp-class--lrn-rb__flag-btn--active="state.isCurrentFlagged"
						data-wp-bind--aria-pressed="state.isCurrentFlagged"
						data-wp-on--click="actions.flagQuestion"
						aria-label="<?php esc_attr_e( 'Flag this question', 'learnomy' ); ?>">
						<i data-lucide="flag" aria-hidden="true"></i>
					</button>
				</div>

				<div class="lrn-quiz__question-wrap">
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

					<!-- Custom text-answer types (Pro numeric / math / calculated). -->
					<div class="lrn-quiz-question__text-answer" data-wp-bind--hidden="!state.isCustomTextType">
						<input type="text" class="lrn-input"
							data-wp-on--input="actions.updateTextAnswer"
							data-wp-on--blur="actions.commitTextAnswer"
							data-wp-bind--value="state.currentTextAnswer"
							autocomplete="off">
					</div>

					<!-- Hint -->
					<details class="lrn-quiz-question__hint" data-wp-bind--hidden="!state.currentQuestionHint">
						<summary><?php esc_html_e( 'Show hint', 'learnomy' ); ?></summary>
						<p data-wp-text="state.currentQuestionHint"></p>
					</details>
				</div>

				<!-- Navigation: Previous | Next -->
				<div class="lrn-quiz__nav">
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
					];
					require \Learnomy\template_path( 'partials/lrn-action.php' );
					?>

					<span class="lrn-quiz__nav-spacer"></span>

					<?php
					$action_icon    = 'arrow-right';
					$action_label   = __( 'Next', 'learnomy' );
					$action_title   = null;
					$action_href    = null;
					$action_aria    = __( 'Go to next question', 'learnomy' );
					$action_variant = 'primary';
					$action_class   = '';
					$action_attrs   = [
						'data-wp-on--click'      => 'actions.nextQuestion',
						'data-wp-bind--disabled' => 'state.isLastQuestion',
					];
					require \Learnomy\template_path( 'partials/lrn-action.php' );
					$action_variant = null;
					?>
				</div>
			</section>
		</div>

		<!-- Mobile bottom sheet: peek bar to reveal question list -->
		<div class="lrn-rb__bottom-sheet"
			data-wp-class--lrn-rb__bottom-sheet--open="state.bottomSheetOpen">
			<button type="button" class="lrn-rb__bottom-sheet-handle"
				data-wp-on--click="actions.toggleBottomSheet"
				aria-label="<?php esc_attr_e( 'Toggle question list', 'learnomy' ); ?>">
				<span class="lrn-rb__bottom-sheet-bar"></span>
				<span class="lrn-rb__bottom-sheet-label" data-wp-text="state.bottomSheetLabel"></span>
			</button>
			<div class="lrn-rb__bottom-sheet-content">
				<!-- Filter pills (mobile) -->
				<div class="lrn-rb__filters" role="tablist" aria-label="<?php esc_attr_e( 'Question filters', 'learnomy' ); ?>">
					<button type="button" class="lrn-rb__filter-pill"
						role="tab"
						data-wp-class--lrn-rb__filter-pill--active="state.isFilterAll"
						data-wp-on--click="actions.setFilterAll">
						<?php esc_html_e( 'All', 'learnomy' ); ?>
					</button>
					<button type="button" class="lrn-rb__filter-pill"
						role="tab"
						data-wp-class--lrn-rb__filter-pill--active="state.isFilterUnanswered"
						data-wp-on--click="actions.setFilterUnanswered">
						<?php esc_html_e( 'Unanswered', 'learnomy' ); ?>
					</button>
					<button type="button" class="lrn-rb__filter-pill"
						role="tab"
						data-wp-class--lrn-rb__filter-pill--active="state.isFilterFlagged"
						data-wp-on--click="actions.setFilterFlagged">
						<?php esc_html_e( 'Flagged', 'learnomy' ); ?>
					</button>
				</div>

				<!-- Question list (mobile) -->
				<div class="lrn-rb__question-list" role="list" data-wp-bind--hidden="state.showGroupedList">
					<template data-wp-each="state.filteredQuestionList">
						<button type="button" class="lrn-rb__list-item" role="listitem"
							data-wp-class--lrn-rb__list-item--current="context.item.isCurrent"
							data-wp-class--lrn-rb__list-item--answered="context.item.isAnswered"
							data-wp-class--lrn-rb__list-item--flagged="context.item.isFlagged"
							data-wp-on--click="actions.jumpToListItem">
							<span class="lrn-rb__list-item-number" data-wp-text="context.item.label"></span>
							<span class="lrn-rb__list-item-title" data-wp-text="context.item.titleShort"></span>
							<span class="lrn-rb__list-item-status">
								<i data-wp-bind--data-lucide="context.item.statusIcon" aria-hidden="true"></i>
							</span>
						</button>
					</template>
				</div>

				<!-- BC #9993397735: grouped-by-category navigator (mobile mirror) -->
				<div class="lrn-rb__question-list lrn-rb__question-list--grouped" role="list" data-wp-bind--hidden="!state.showGroupedList">
					<template data-wp-each--cat="state.groupedQuestionList" data-wp-each-key="context.cat.name">
						<div class="lrn-rb__cat-group" data-wp-class--is-collapsed="context.cat.collapsed">
							<button type="button" class="lrn-rb__cat-header"
								data-wp-on--click="actions.toggleCategoryGroup">
								<i data-lucide="chevron-down" class="lrn-rb__cat-chevron" aria-hidden="true"></i>
								<span class="lrn-rb__cat-name" data-wp-text="context.cat.name"></span>
								<span class="lrn-rb__cat-count" data-wp-text="context.cat.countLabel"></span>
							</button>
							<div class="lrn-rb__cat-items" data-wp-bind--hidden="context.cat.collapsed">
								<template data-wp-each--item="context.cat.items" data-wp-each-key="context.item.id">
									<button type="button" class="lrn-rb__list-item" role="listitem"
										data-wp-class--lrn-rb__list-item--current="context.item.isCurrent"
										data-wp-class--lrn-rb__list-item--answered="context.item.isAnswered"
										data-wp-class--lrn-rb__list-item--flagged="context.item.isFlagged"
										data-wp-on--click="actions.jumpToListItem">
										<span class="lrn-rb__list-item-number" data-wp-text="context.item.label"></span>
										<span class="lrn-rb__list-item-title" data-wp-text="context.item.titleShort"></span>
										<span class="lrn-rb__list-item-status">
											<i data-wp-bind--data-lucide="context.item.statusIcon" aria-hidden="true"></i>
										</span>
									</button>
								</template>
							</div>
						</div>
					</template>
				</div>
			</div>
		</div>
	</div>

	<!-- ============================================================
		3. RESULTS SCREEN (hidden until submitted)
		============================================================ -->
	<div class="lrn-quiz__results" data-wp-bind--hidden="state.resultsHidden">

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

	<!-- Per-question review. A SIBLING of the results hero (nested inside it
		this inherited the hero's centering and width cap), GROUPED by question
		category — the same structure the review-board player itself navigates
		by, so a 300-question subject-grouped exam reviews subject by subject
		instead of as one flat list. reviewGroups falls back to a single
		unlabeled group when fewer than two categories exist. -->
	<div class="lrn-quiz__review" data-wp-bind--hidden="!state.hasReview">
		<h3 class="lrn-quiz__review-heading"><?php esc_html_e( 'Question Review', 'learnomy' ); ?></h3>
		<?php require \Learnomy\template_path( 'partials/quiz-review-filters.php' ); ?>

		<template data-wp-each="state.reviewGroups">
			<div class="lrn-sect__review-group">
				<h4 class="lrn-sect__review-group-title" data-wp-bind--hidden="!context.item.label">
					<span data-wp-text="context.item.label"></span>
					<span class="lrn-sect__review-group-summary" data-wp-text="context.item.summary"></span>
				</h4>

			<template data-wp-each="context.item.questions">
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
		</template>
	</div>

</main>
