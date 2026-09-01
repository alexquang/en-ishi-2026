<?php
/**
 * Sectioned quiz template -- multi-section exam style (SAT/GRE).
 *
 * Questions are grouped into sections. The student completes one section
 * before moving to the next. A section intro card appears between
 * sections. Within a section, navigation is one question at a time
 * (prev/next within section bounds). Once a section is ended, the
 * student cannot go back.
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
<main id="lrn-quiz-main" class="lrn-container lrn-quiz lrn-quiz--sectioned"
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
	// Sectioned layout: one extra rule explaining the section-locked progression.
	$extra_rules = [
		[
			'icon' => 'layout-grid',
			'text' => __( 'Multi-section exam -- complete each section in order', 'learnomy' ),
		],
	];
	require \Learnomy\template_path( 'partials/quiz-start-screen.php' );
	?>

	<!-- ============================================================
		2. QUIZ PLAYER (hidden until started, hidden after submit)
		============================================================ -->
	<div class="lrn-quiz__player lrn-quiz__player--sectioned" data-wp-bind--hidden="state.playerHidden">
		<?php require \Learnomy\template_path( 'partials/quiz-resume-banner.php' ); ?>

		<!-- Top bar: section name + section progress + overall progress + timer -->
		<div class="lrn-sect__top-bar">
			<div class="lrn-sect__section-info">
				<span class="lrn-sect__section-name" data-wp-text="state.currentSectionLabel"></span>
				<span class="lrn-sect__section-counter" data-wp-text="state.sectionQuestionCounter" aria-live="polite"></span>
			</div>

			<div class="lrn-sect__progress-wrap">
				<!-- Segmented section progress -->
				<div class="lrn-sect__progress-segments" aria-label="<?php esc_attr_e( 'Section progress', 'learnomy' ); ?>">
					<template data-wp-each="state.sectionSegments">
						<div class="lrn-sect__progress-segment"
							data-wp-class--lrn-sect__progress-segment--active="context.item.active"
							data-wp-class--lrn-sect__progress-segment--completed="context.item.completed"
							data-wp-class--lrn-sect__progress-segment--locked="context.item.locked">
						</div>
					</template>
				</div>
				<span class="lrn-sect__overall-progress" data-wp-text="state.overallProgressText" aria-live="polite"></span>
			</div>

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

		<!-- BC #9993392864: free-navigation section picker landing -->
		<div class="lrn-sect__picker" data-wp-bind--hidden="!state.sectionPickerVisible">
			<div class="lrn-sect__picker-card lrn-card">
				<h2 class="lrn-sect__picker-title"><?php esc_html_e( 'Choose a section', 'learnomy' ); ?></h2>
				<p class="lrn-sect__picker-help"><?php esc_html_e( 'Tackle the sections in any order. You can switch between them until you submit.', 'learnomy' ); ?></p>
				<div class="lrn-sect__picker-grid">
					<template data-wp-each="state.sectionCards" data-wp-each-key="context.item.index">
						<button type="button" class="lrn-sect__picker-item"
							data-wp-class--is-complete="context.item.isComplete"
							data-wp-class--is-current="context.item.isCurrent"
							data-wp-on--click="actions.enterSection">
							<span class="lrn-sect__picker-item-name" data-wp-text="context.item.label"></span>
							<span class="lrn-sect__picker-item-count" data-wp-text="context.item.countLabel"></span>
							<i class="lrn-sect__picker-item-check" data-lucide="check-circle" aria-hidden="true"></i>
						</button>
					</template>
				</div>
				<div class="lrn-sect__picker-actions">
					<button type="button" class="lrn-btn lrn-btn--primary" data-wp-on--click="actions.submitFromPicker">
						<?php esc_html_e( 'Submit exam', 'learnomy' ); ?>
					</button>
				</div>
			</div>
		</div>

		<!-- Section intro card (linear mode — visible when transitioning between sections) -->
		<div class="lrn-sect__intro" data-wp-bind--hidden="!state.sectionIntroVisible">
			<div class="lrn-sect__intro-card lrn-card">
				<span class="lrn-sect__intro-badge" data-wp-text="state.sectionIntroBadge"></span>
				<h2 class="lrn-sect__intro-title" data-wp-text="state.currentSectionLabel"></h2>
				<p class="lrn-sect__intro-meta" data-wp-text="state.sectionIntroMeta"></p>
				<button type="button" class="lrn-btn lrn-btn--primary lrn-sect__intro-btn"
					data-wp-on--click="actions.beginSection">
					<?php esc_html_e( 'Begin Section', 'learnomy' ); ?>
				</button>
			</div>
		</div>

		<!-- Question container (hidden during section intro or the picker) -->
		<div class="lrn-sect__question-area" data-wp-bind--hidden="state.hideSectionQuestions">
			<div class="lrn-quiz__question-wrap">
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

			<!-- Section navigation: Previous | Counter | Next / End Section -->
			<div class="lrn-quiz__nav">
				<?php
				$action_icon  = 'arrow-left';
				$action_label = __( 'Previous', 'learnomy' );
				$action_title = null;
				$action_href  = null;
				$action_aria  = __( 'Go to previous question', 'learnomy' );
				$action_class = '';
				$action_attrs = [
					'data-wp-on--click'      => 'actions.prevSectionQuestion',
					'data-wp-bind--disabled' => 'state.isFirstInSection',
				];
				require \Learnomy\template_path( 'partials/lrn-action.php' );
				?>

				<span class="lrn-quiz__nav-spacer"></span>

				<!-- BC #9993392864: jump back to the section picker (free mode only) -->
				<button type="button" class="lrn-btn lrn-btn--ghost lrn-sect__to-picker"
					data-wp-bind--hidden="!state.isFreeSectionNav"
					data-wp-on--click="actions.openSectionPicker">
					<i data-lucide="layout-grid" aria-hidden="true"></i>
					<?php esc_html_e( 'Sections', 'learnomy' ); ?>
				</button>

				<?php
				$action_icon    = 'arrow-right';
				$action_label   = __( 'Next', 'learnomy' );
				$action_title   = null;
				$action_href    = null;
				$action_aria    = __( 'Go to next question or end section', 'learnomy' );
				$action_variant = 'primary';
				$action_class   = '';
				$action_attrs   = [
					'data-wp-on--click' => 'actions.nextSectionQuestionOrEnd',
					'data-wp-text'      => 'state.sectionNextBtnLabel',
				];
				require \Learnomy\template_path( 'partials/lrn-action.php' );
				$action_variant = null;
				?>
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

	<!-- Per-question review grouped by section. A SIBLING of the results
		hero, not a child: nested inside .lrn-quiz__results it inherited the
		hero's text-align center and could never exceed the hero's 540px cap,
		which squeezed the whole review into one narrow centered column on
		wide screens (every other template already has it as a sibling). -->
	<div class="lrn-quiz__review" data-wp-bind--hidden="!state.hasReview">
			<h3 class="lrn-quiz__review-heading"><?php esc_html_e( 'Question Review', 'learnomy' ); ?></h3>
		<?php require \Learnomy\template_path( 'partials/quiz-review-filters.php' ); ?>

			<template data-wp-each="state.reviewSections">
				<div class="lrn-sect__review-group">
					<h4 class="lrn-sect__review-group-title">
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
