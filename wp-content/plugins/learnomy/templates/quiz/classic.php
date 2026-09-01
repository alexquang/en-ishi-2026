<?php
/**
 * Classic quiz template — all questions on one scrollable page.
 *
 * Ideal for short quizzes (<15 questions), surveys, and practice mode.
 * All questions render simultaneously after start — no prev/next navigation.
 * A sticky top bar shows answered count, timer, and submit button.
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
<main id="lrn-quiz-main" class="lrn-container lrn-quiz lrn-quiz--classic"
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
	// Classic layout: one extra rule explaining the scroll-all layout.
	$extra_rules = [
		[
			'icon' => 'scroll-text',
			'text' => __( 'All questions shown at once. Scroll to answer.', 'learnomy' ),
		],
	];
	require \Learnomy\template_path( 'partials/quiz-start-screen.php' );
	?>

	<!-- ============================================================
		2. QUIZ PLAYER — all questions on one scrollable page
		============================================================ -->
	<div class="lrn-quiz__player lrn-quiz__player--classic" data-wp-bind--hidden="state.playerHidden">
		<?php require \Learnomy\template_path( 'partials/quiz-resume-banner.php' ); ?>

		<!-- Top bar: answered count + timer. Submit lives at the bottom only
			so the user has one canonical submit point. The bar tries to stick
			but most themes wrap our content in an overflow:hidden host
			(BuddyX .site, Astra .ast-container, etc.) which silently disables
			position:sticky — a second top submit would still scroll off-screen
			and just add clutter. -->
		<div class="lrn-quiz-classic__top-bar">
			<div class="lrn-quiz-classic__top-bar-row">
				<span class="lrn-quiz-classic__answered-count" aria-live="polite"
					data-wp-text="state.answeredText">
				</span>

				<span class="lrn-quiz__timer" role="timer" aria-live="polite"
					data-wp-bind--hidden="!state.hasTimer"
					data-wp-class--lrn-quiz__timer--urgent="state.timerUrgent">
					<i data-lucide="clock" aria-hidden="true"></i>
					<span data-wp-text="state.timerDisplay"></span>
				</span>
			</div>

			<!-- A2: category jump-nav — click a chip to jump to that category's
				run of questions. Shown only when grouping is on with 2+ categories. -->
			<nav class="lrn-quiz-classic__cat-nav"
				aria-label="<?php esc_attr_e( 'Jump to category', 'learnomy' ); ?>"
				data-wp-bind--hidden="!state.hasCategoryJumpNav">
				<template data-wp-each--cat="state.categoryGroups" data-wp-each-key="context.cat.anchorId">
					<button type="button" class="lrn-quiz-classic__cat-chip" data-wp-on--click="actions.jumpToCategory">
						<span class="lrn-quiz-classic__cat-chip-name" data-wp-text="context.cat.name"></span>
						<span class="lrn-quiz-classic__cat-chip-count" data-wp-text="context.cat.countLabel"></span>
					</button>
				</template>
			</nav>
		</div>

		<!-- Anti-cheat warning -->
		<div class="lrn-quiz__cheat-warning" data-wp-bind--hidden="!state.tabSwitchWarning" role="alert">
			<i data-lucide="alert-triangle" aria-hidden="true"></i>
			<?php esc_html_e( 'Tab switching detected. This activity is recorded.', 'learnomy' ); ?>
		</div>

		<!-- All questions rendered at once -->
		<div class="lrn-quiz-classic__questions">
			<template data-wp-each="state.allQuestions">
				<div class="lrn-quiz-classic__group-item" data-wp-key="context.item.id">

				<!-- A2: category heading above the first question of each category run -->
				<h2 class="lrn-quiz-classic__category-heading" data-wp-bind--id="context.item.categoryAnchorId" data-wp-bind--hidden="!context.item.showCategoryHeading" data-wp-text="context.item.categoryHeading"></h2>

				<div class="lrn-quiz-classic__question-card"
					data-wp-class--lrn-quiz-classic__question-card--answered="context.item.isAnswered">

					<div class="lrn-quiz-classic__question-header">
						<span class="lrn-quiz-classic__question-number" data-wp-text="context.item.label"></span>
						<span class="lrn-quiz-classic__answered-badge" data-wp-bind--hidden="!context.item.isAnswered">
							<i data-lucide="check" aria-hidden="true"></i>
						</span>
					</div>

					<h3 class="lrn-quiz-question__prompt" data-wp-text="context.item.title"></h3>

					<!-- A3: rich question stem (server-rendered, kses'd) above the answers -->
					<div class="lrn-quiz-question__stem" data-wp-bind--hidden="!context.item.stemHtml" data-wp-watch--stem="callbacks.renderStem"></div>

					<!-- MC / True-False answers -->
					<fieldset class="lrn-quiz-question__options"
						data-wp-bind--hidden="context.item.isTextType">
						<legend class="screen-reader-text"><?php esc_html_e( 'Select your answer', 'learnomy' ); ?></legend>
						<template data-wp-each="context.item.answers">
							<label class="lrn-quiz-question__option"
								data-wp-class--lrn-quiz-question__option--selected="context.item.isSelected">
								<input
									type="radio"
									data-wp-bind--type="context.item.inputType"
									data-wp-bind--name="context.item.inputName"
									data-wp-bind--value="context.item.id"
									data-wp-bind--checked="context.item.isSelected"
									data-wp-on--change="actions.selectClassicAnswer">
								<span class="lrn-quiz-question__option-text" data-wp-text="context.item.content"></span>
							</label>
						</template>
					</fieldset>

					<!-- Short answer -->
					<div class="lrn-quiz-question__text-answer" data-wp-bind--hidden="!context.item.isShortAnswerType">
						<input type="text" class="lrn-input"
							placeholder="<?php esc_attr_e( 'Type your answer here…', 'learnomy' ); ?>"
							data-wp-on--input="actions.updateClassicTextAnswer"
							data-wp-bind--value="context.item.textValue"
							autocomplete="off">
					</div>

					<!-- Fill in the blank — one input per {{blankN}} token.
						Nested data-wp-each: the iterable (context.item.blanks)
						resolves in the question scope; inside the loop
						context.item is the blank (same shadowing pattern the
						answers loop above uses). The question id is read from
						the card's data-wp-key by the action, not from context. -->
					<div class="lrn-quiz-question__blanks" data-wp-bind--hidden="!context.item.isFillBlankType">
						<template data-wp-each="context.item.blanks" data-wp-each-key="context.item.key">
							<label class="lrn-quiz-question__blank">
								<span class="lrn-quiz-question__blank-label" data-wp-text="context.item.label"></span>
								<input type="text" class="lrn-input"
									data-wp-bind--data-blank-key="context.item.key"
									data-wp-bind--value="context.item.value"
									data-wp-on--input="actions.updateClassicBlankAnswer"
									autocomplete="off">
							</label>
						</template>
					</div>

					<!-- Essay -->
					<div class="lrn-quiz-question__essay" data-wp-bind--hidden="!context.item.isEssayType">
						<textarea class="lrn-textarea" rows="6"
							placeholder="<?php esc_attr_e( 'Write your response here…', 'learnomy' ); ?>"
							data-wp-on--input="actions.updateClassicTextAnswer"
							data-wp-bind--value="context.item.textValue"></textarea>
					</div>

					<!-- Custom text-answer types (Pro numeric / math / calculated). -->
					<div class="lrn-quiz-question__text-answer" data-wp-bind--hidden="!context.item.isCustomTextType">
						<input type="text" class="lrn-input"
							data-wp-on--input="actions.updateClassicTextAnswer"
							data-wp-bind--value="context.item.textValue"
							autocomplete="off">
					</div>

					<!-- Hint -->
					<details class="lrn-quiz-question__hint" data-wp-bind--hidden="!context.item.hint">
						<summary><?php esc_html_e( 'Show hint', 'learnomy' ); ?></summary>
						<p data-wp-text="context.item.hint"></p>
					</details>
				</div>
				</div><!-- /.lrn-quiz-classic__group-item -->
			</template>
		</div>

		<!-- Unanswered warning + bottom submit -->
		<div class="lrn-quiz-classic__bottom-bar">
			<p class="lrn-quiz-classic__unanswered-notice" data-wp-bind--hidden="!state.hasUnanswered">
				<i data-lucide="alert-circle" aria-hidden="true"></i>
				<span data-wp-text="state.unansweredNotice"></span>
				<button type="button" class="lrn-btn lrn-btn--ghost lrn-btn--sm"
					data-wp-on--click="actions.scrollToFirstUnanswered">
					<?php esc_html_e( 'Jump to first', 'learnomy' ); ?>
				</button>
			</p>

			<?php
			$action_icon    = 'send';
			$action_label   = __( 'Submit', 'learnomy' );
			$action_title   = null;
			$action_href    = null;
			$action_aria    = __( 'Submit quiz', 'learnomy' );
			$action_variant = 'primary';
			$action_class   = 'lrn-quiz-classic__submit-bottom';
			$action_attrs   = [
				'data-wp-on--click' => 'actions.submitQuiz',
			];
			require \Learnomy\template_path( 'partials/lrn-action.php' );
			$action_variant = null;
			?>
		</div>
	</div>

	<!-- ============================================================
		3. RESULTS SCREEN (same as focused template)
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
