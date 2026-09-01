/**
 * Focused quiz store — API-driven, one question at a time.
 *
 * Data flow:
 *   1. Page loads → minimal PHP shell visible (start screen).
 *   2. User clicks Start → POST /quizzes/{id}/start → returns questions[].
 *   3. Store renders one question at a time from state.questions[].
 *   4. User answers and navigates → answers stored client-side in state.answers{}.
 *   5. User submits → POST /attempts/{id}/submit with all answers → returns score.
 *   6. Store shows results screen.
 *
 * @package Learnomy
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

// Translated strings injected by Template_Loader via window.lrnQuiz.i18n.
// Falls back to English so the store works even if the inline script is missing
// (e.g. direct PHP unit tests that don't run wp_head).
const i18n = ( window.lrnQuiz && window.lrnQuiz.i18n ) ? window.lrnQuiz.i18n : {};
const __ = ( key, fallback ) => i18n[ key ] || fallback;

/**
 * Fill positional number placeholders in a localized template — handles both
 * `%d` and `%1$d`/`%2$d` forms. Mirrors quiz-view-sectioned.js.
 *
 * @param {string}    template Localized template, e.g. "%1$d of %2$d correct".
 * @param {...number} nums     Positional numbers.
 * @return {string}
 */
function fillNums( template, ...nums ) {
	let out = String( template );
	nums.forEach( ( n, i ) => {
		out = out.replace( new RegExp( '%' + ( i + 1 ) + '\\$d', 'g' ), n );
	} );
	return out.replace( /%d/g, nums[ 0 ] );
}

let timerInterval = null;
// Auto-submit resilience: when submit is triggered by the timer or the
// tab-switch limit, the answers are frozen and the student cannot re-trigger,
// so a transient network failure must not strand them. [card 10062744656]
let autoSubmitPending = false;
let submitAutoRetries = 0;

// A4: debounce handle for the resume-state autosave (position + answers).
let runnerSaveTimer = null;

/**
 * A4 resume: coerce a saved answer value back to the shape the player compares
 * against. response_json round-trips numeric answer ids as strings; MC
 * selection compares strictly against numeric ids, so numeric strings (and
 * arrays of them) are coerced to numbers. Objects (fill_blank maps) and real
 * text answers pass through untouched.
 *
 * @param {*} val Saved answer value from the start payload.
 * @return {*} Player-shaped answer value.
 */
function hydrateSavedAnswer( val ) {
	const toNum = ( v ) => ( /^\d+$/.test( String( v ) ) ? Number( v ) : v );
	if ( Array.isArray( val ) ) {
		return val.map( toNum );
	}
	if ( val !== null && typeof val === 'object' ) {
		return val;
	}
	return toNum( val );
}

/**
 * A4: whether an answer value counts as "answered" (for the question-map and
 * the pre-submit unanswered count). Empty string / array / all-blank map = not
 * answered; a number, non-empty string/array, or a map with any value = answered.
 *
 * @param {*} val Answer value from state.answers.
 * @return {boolean}
 */
/**
 * Does this question accept MORE THAN ONE answer?
 *
 * `mc_multi` is a first-class question type: the editor offers it, the DB stores
 * it, and the server grades it (Quiz_Service::score_mc_multi, with a sanitizer
 * that deliberately preserves array shape). The frontend was the only layer that
 * never learned about it — every quiz layout hardcoded `type="radio"` and every
 * store wrote a scalar answer id, so a student could only ever pick one option
 * and multi-answer questions were unanswerable as designed (Basecamp 10134139919).
 *
 * Kept as one predicate rather than repeating the string, because five view
 * modules and four templates all need the same answer.
 *
 * @param {Object} question Question descriptor from state.questions.
 * @return {boolean} True when the answer is a set, not a single value.
 */
function isMultiSelect( question ) {
	return !! question && 'mc_multi' === question.type;
}

/**
 * Toggle one answer id inside a multi-select question's answer array.
 *
 * Returns a NEW array — the Interactivity store proxies state, so mutating the
 * stored array in place does not reliably trigger a re-render.
 *
 * @param {*}      current Existing stored answer (array, scalar, or undefined).
 * @param {number} id      Answer id being toggled.
 * @return {Array} The next selection.
 */
function toggleMultiAnswer( current, id ) {
	// A question that was previously answered as single-select (or restored from
	// an older attempt) can arrive as a scalar. Normalise rather than discard, so
	// switching a question's type does not silently drop a saved answer.
	const list = Array.isArray( current )
		? current.slice()
		: ( current === undefined || current === null || current === '' ? [] : [ current ] );

	const at = list.indexOf( id );
	if ( at === -1 ) {
		list.push( id );
	} else {
		list.splice( at, 1 );
	}

	return list;
}

/**
 * Is this answer id currently selected, for either answer shape?
 *
 * @param {*}      saved Stored answer for the question.
 * @param {number} id    Answer id to test.
 * @return {boolean}
 */
function isAnswerSelected( saved, id ) {
	return Array.isArray( saved ) ? saved.includes( id ) : saved === id;
}

function isAnswerFilled( val ) {
	if ( val === undefined || val === null || val === '' ) {
		return false;
	}
	if ( Array.isArray( val ) ) {
		return val.length > 0;
	}
	if ( typeof val === 'object' ) {
		return Object.values( val ).some( ( v ) => String( v ).trim() !== '' );
	}
	return String( val ).trim() !== '';
}

// Module-scope helpers for ordering drag-drop. Kept outside the store because
// the Interactivity store tries to proxy stored references; a plain DOM node
// must not be proxied or React-like change detection will complain.
let orderingDragSource = null;

const { state, actions } = store( 'learnomy/quiz', {
	state: {
		// Lifecycle.
		isStarting: false,
		started: false,
		submitted: false,
		attemptId: null,
		navigation: 'free',
		// Attempts left AFTER the latest submission, from the /submit response
		// (null = unlimited / not yet known). Lets retakeQuiz() retire a stale
		// "Try Again" instead of re-POSTing /start into a 400. [Basecamp 10096673115]
		attemptsRemaining: null,

		// Questions (populated from API on start).
		questions: [],
		currentIndex: 0,
		answers: {},
		feedbackTiming: 'after_submit',
		// A2: whether the quiz groups its questions by category (drives the
		// classic player's per-category headings). Set from the start response.
		groupByCategory: false,
		questionFeedback: {},

		// Matching: the currently-selected left term (prompt answer id) waiting
		// to be paired with a right-column match. Reset after every pairing.
		matchingSelectedLeft: null,

		// A4 Phase 2: large-exam wayfinding.
		flagged: [],          // question ids flagged for review (restored on resume)
		overviewOpen: false,  // the clickable question-map panel
		preSubmitOpen: false, // the pre-submit review screen

		// Resume narration — when a frozen attempt is reopened, a banner tells
		// the student their state was restored (how many answers, time left) so
		// the reappearing screen doesn't read as "progress lost". [card 10062949966]
		resumeNoticeVisible: false,
		resumeRestoredCount: 0,

		// Timer.
		timeRemaining: null,

		// Anti-cheat.
		tabSwitches: 0,
		tabSwitchWarning: false,
		// Proctoring: auto-submit after this many tab switches (0 = off), and a
		// flag so the results screen can explain why the quiz was submitted.
		tabSwitchLimit: 0,
		tabSwitchAutoSubmitted: false,

		// Results (populated from API on submit).
		result: null,

		// -----------------------------------------------------------
		// Computed: lifecycle
		// -----------------------------------------------------------

		get startBtnLabel() {
			return state.isStarting
				? __( 'loading', 'Loading\u2026' )
				: __( 'startQuiz', 'Start Quiz' );
		},

		/*
		 * Same button, different word for someone who has sat this before.
		 *
		 * The template binds THIS getter instead of startBtnLabel when a prior
		 * attempt exists. Two getters rather than one that inspects the
		 * element, because the only difference is the idle label and the
		 * loading state must stay identical (Basecamp 10171334411).
		 */
		get retakeBtnLabel() {
			return state.isStarting
				? __( 'loading', 'Loading\u2026' )
				: __( 'retakeQuiz', 'Retake Quiz' );
		},

		get playerHidden() {
			return ! state.started || state.submitted;
		},

		get resultsHidden() {
			return ! state.submitted || ! state.result;
		},

		get isSequential() {
			return state.navigation === 'sequential';
		},

		get isImmediateFeedback() {
			return state.feedbackTiming === 'immediate';
		},

		// `after_question` shows correctness inline while the student is
		// answering but hides the correct answer + explanation until the full
		// attempt is submitted. `immediate` is a superset (shows everything).
		get isAfterQuestionFeedback() {
			return state.feedbackTiming === 'after_question';
		},

		get isPerQuestionFeedback() {
			return state.isImmediateFeedback || state.isAfterQuestionFeedback;
		},

		// `after_attempt` / `never` collapse the results screen because the
		// server payload is shaped accordingly by submit_attempt().
		get isResultsMinimal() {
			return state.feedbackTiming === 'after_attempt';
		},

		get isResultsSilent() {
			return state.feedbackTiming === 'never';
		},

		get currentAnswerFeedback() {
			if ( ! state.isPerQuestionFeedback || ! state.currentQuestion ) {
				return null;
			}
			return state.questionFeedback[ state.currentQuestion.id ] || null;
		},

		get feedbackLabel() {
			const fb = state.currentAnswerFeedback;
			if ( ! fb ) {
				return '';
			}
			return fb.correct
				? __( 'correct', 'Correct!' )
				: __( 'incorrect', 'Incorrect' );
		},

		// -----------------------------------------------------------
		// Computed: current question
		// -----------------------------------------------------------

		get totalQuestions() {
			return state.questions.length;
		},

		get currentQuestion() {
			return state.questions[ state.currentIndex ] || null;
		},

		get currentQuestionTitle() {
			return state.currentQuestion?.title || '';
		},

		// Category heading for the one-at-a-time layouts (focused / sectioned /
		// conversational). When group_by_category is on the server returns the
		// questions category-contiguous, so showing the current question's
		// category above the prompt keeps subjects visibly grouped instead of
		// silently interleaved. Empty when grouping is off.
		get currentCategoryLabel() {
			if ( ! state.groupByCategory || ! state.currentQuestion ) {
				return '';
			}
			return state.currentQuestion.category_name || '';
		},

		// A3: the rich question stem (server-rendered + kses'd HTML) shown above
		// the answers, for the one-at-a-time templates. Empty when no context.
		get currentQuestionStemHtml() {
			return state.currentQuestion?.stemHtml || '';
		},

		get currentQuestionHint() {
			return state.currentQuestion?.hint || '';
		},

		get currentAnswers() {
			return state.currentQuestion?.answers || [];
		},

		get currentInputName() {
			return state.currentQuestion ? `q_${ state.currentQuestion.id }` : 'q';
		},

		get currentQuestionType() {
			return state.currentQuestion?.type || 'mc_single';
		},

		/**
		 * `isTextType` gates the MC/True-False fieldset. Ordering + matching
		 * also need that fieldset hidden, otherwise a student attempting one
		 * of those types sees an empty radio group above their real UI.
		 */
		get isTextType() {
			// Built-in non-MC types, plus any type an extension registers via
			// window.learnomyTextAnswerTypes (Pro numeric / math / calculated).
			// All keep the MC / True-False radio fieldset hidden.
			var extra = ( typeof window !== 'undefined' && window.learnomyTextAnswerTypes ) || [];
			return [ 'short_answer', 'fill_blank', 'essay', 'ordering', 'matching' ].includes( state.currentQuestionType )
				|| extra.includes( state.currentQuestionType );
		},

		get isShortAnswerType() {
			return state.currentQuestionType === 'short_answer';
		},

		get isFillBlankType() {
			return state.currentQuestionType === 'fill_blank';
		},

		get isEssayType() {
			return state.currentQuestionType === 'essay';
		},

		/**
		 * A custom text-style type registered by an extension via
		 * window.learnomyTextAnswerTypes (Pro numeric / math / calculated).
		 * Renders the generic single-line text input that reuses
		 * currentTextAnswer + updateTextAnswer, so no per-type store code.
		 */
		get isCustomTextType() {
			var extra = ( typeof window !== 'undefined' && window.learnomyTextAnswerTypes ) || [];
			return extra.includes( state.currentQuestionType );
		},

		get currentTextAnswer() {
			if ( ! state.currentQuestion ) {
				return '';
			}
			const saved = state.answers[ state.currentQuestion.id ];
			// short_answer / essay store a plain string. fill_blank stores a
			// per-blank map, which must never leak into the single text field.
			return typeof saved === 'string' ? saved : '';
		},

		/**
		 * One descriptor per blank for a fill_blank question. `key` is the
		 * `group_id` token (blank1, blank2…) the grader matches against, `label`
		 * is the human "Blank N" caption, and `value` is the student's current
		 * entry pulled from the per-question answer map.
		 */
		get currentBlanks() {
			if ( ! state.currentQuestion ) {
				return [];
			}
			const keys = state.currentQuestion.blanks || [];
			const saved = state.answers[ state.currentQuestion.id ];
			const map = saved && typeof saved === 'object' && ! Array.isArray( saved ) ? saved : {};
			return keys.map( ( key, index ) => ( {
				key,
				label: __( 'blankLabel', 'Blank %d' ).replace( '%d', index + 1 ),
				value: map[ key ] ?? '',
			} ) );
		},

		get isOptionSelected() {
			const ctx = getContext();
			if ( ! state.currentQuestion || ! ctx.item ) {
				return false;
			}
			// Array-aware: an mc_multi answer is a set of ids, not one id.
			return isAnswerSelected( state.answers[ state.currentQuestion.id ], ctx.item.id );
		},

		/**
		 * The input element the current question's options should render as.
		 *
		 * Bound in the templates with data-wp-bind--type rather than hardcoded,
		 * because the choice is per QUESTION and the options are rendered client
		 * side inside data-wp-each — PHP cannot decide it per item. Hardcoding
		 * `radio` is what made mc_multi unanswerable (Basecamp 10134139919).
		 *
		 * @return {string} 'checkbox' for multi-answer questions, else 'radio'.
		 */
		get optionInputType() {
			return isMultiSelect( state.currentQuestion ) ? 'checkbox' : 'radio';
		},

		/**
		 * Whether the current question takes a SET of answers.
		 *
		 * Exposed on the store because template overlays (conversational) are
		 * separate ES modules and cannot import this file's private helpers.
		 * They need it to decide affordances — a multi-select has no "done"
		 * signal, so anything that advances on selection must not.
		 *
		 * @return {boolean} True for mc_multi.
		 */
		get isCurrentMultiSelect() {
			return isMultiSelect( state.currentQuestion );
		},

		/**
		 * ARIA role for one option button in a button-based layout.
		 *
		 * The conversational layout draws options as <button>s rather than
		 * inputs, so the role has to carry the single/multi semantics that
		 * `optionInputType` carries for the input-based layouts.
		 *
		 * @return {string} 'checkbox' for multi-answer questions, else 'radio'.
		 */
		get optionButtonRole() {
			return isMultiSelect( state.currentQuestion ) ? 'checkbox' : 'radio';
		},

		/**
		 * ARIA role for the container of those option buttons.
		 *
		 * A radiogroup announces "one of N"; for a multi-select that is a lie,
		 * and a screen-reader user is told they cannot do the thing the
		 * question asks of them.
		 *
		 * @return {string} 'group' for multi-answer questions, else 'radiogroup'.
		 */
		get optionGroupRole() {
			return isMultiSelect( state.currentQuestion ) ? 'group' : 'radiogroup';
		},

		// -----------------------------------------------------------
		// Computed: ordering + matching question types
		// -----------------------------------------------------------

		get isOrderingType() {
			return state.currentQuestionType === 'ordering';
		},

		get isMatchingType() {
			return state.currentQuestionType === 'matching';
		},

		/**
		 * Items for the ordering question, in the student's current order.
		 * Returns the shuffled payload for first render, then once the
		 * student has reordered items we mirror the DOM order via
		 * state.answers[questionId].
		 */
		get currentOrderingItems() {
			if ( ! state.currentQuestion ) {
				return [];
			}
			const items = state.currentQuestion.answers || [];
			const saved = state.answers[ state.currentQuestion.id ];
			if ( Array.isArray( saved ) && saved.length === items.length ) {
				const byId = {};
				for ( const item of items ) {
					byId[ item.id ] = item;
				}
				return saved.map( ( id ) => byId[ id ] ).filter( Boolean );
			}
			return items;
		},

		/**
		 * Prompt column for matching questions. The controller tags each
		 * answer with side=left|right; fall back to `group_id` for callers
		 * that still pass the raw DB row shape.
		 */
		get currentMatchingLeft() {
			if ( ! state.currentQuestion ) {
				return [];
			}
			return ( state.currentQuestion.answers || [] ).filter(
				( a ) => a.side === 'left' || a.group_id === 'prompt'
			);
		},

		get currentMatchingRight() {
			if ( ! state.currentQuestion ) {
				return [];
			}
			return ( state.currentQuestion.answers || [] ).filter(
				( a ) => a.side === 'right' || a.group_id === 'match'
			);
		},

		get currentMatchingPairs() {
			if ( ! state.currentQuestion ) {
				return {};
			}
			const saved = state.answers[ state.currentQuestion.id ];
			return saved && typeof saved === 'object' && ! Array.isArray( saved ) ? saved : {};
		},

		// -----------------------------------------------------------
		// Computed: navigation
		// -----------------------------------------------------------

		get isFirstQuestion() {
			return state.currentIndex === 0;
		},

		get isLastQuestion() {
			return state.currentIndex >= state.totalQuestions - 1;
		},

		get nextBtnLabel() {
			return state.isLastQuestion
				? __( 'submitQuiz', 'Submit Quiz' )
				: __( 'next', 'Next' ) + ' \u2192';
		},

		get questionCounter() {
			if ( state.totalQuestions === 0 ) {
				return '';
			}
			return `Q${ state.currentIndex + 1 } of ${ state.totalQuestions }`;
		},

		// -----------------------------------------------------------
		// Computed: progress ring
		// -----------------------------------------------------------

		get answeredCount() {
			return Object.keys( state.answers ).length;
		},

		get answeredFraction() {
			if ( state.totalQuestions === 0 ) {
				return 0;
			}
			return state.answeredCount / state.totalQuestions;
		},

		get ringDasharray() {
			const circumference = 100;
			const filled = Math.round( state.answeredFraction * circumference );
			return `${ filled } ${ circumference - filled }`;
		},

		// -----------------------------------------------------------
		// Computed: A4 Phase 2 wayfinding (question-map / flag / pre-submit)
		// -----------------------------------------------------------

		// One entry per question for the clickable map: number, target index,
		// and reactive state (current / answered / flagged → the legend).
		get questionMap() {
			return state.questions.map( ( q, i ) => ( {
				number:     i + 1,
				index:      i,
				isCurrent:  i === state.currentIndex,
				isAnswered: isAnswerFilled( state.answers[ q.id ] ),
				isFlagged:  state.flagged.includes( q.id ),
			} ) );
		},

		// Questions with no (meaningful) answer yet — drives the pre-submit notice.
		get unansweredCount() {
			return state.questions.filter( ( q ) => ! isAnswerFilled( state.answers[ q.id ] ) ).length;
		},

		// Flags scoped to questions still in the set.
		get flaggedCount() {
			const ids = new Set( state.questions.map( ( q ) => q.id ) );
			return state.flagged.filter( ( id ) => ids.has( id ) ).length;
		},

		get isCurrentFlagged() {
			return !! state.currentQuestion && state.flagged.includes( state.currentQuestion.id );
		},

		get flagBtnLabel() {
			return state.isCurrentFlagged
				? __( 'flagged', 'Flagged' )
				: __( 'flagForReview', 'Flag for review' );
		},

		// Pre-submit summary line, e.g. "3 unanswered · 2 flagged".
		get preSubmitSummary() {
			const parts = [];
			if ( state.unansweredCount > 0 ) {
				parts.push( ( state.unansweredCount === 1
					? __( 'oneUnanswered', '1 unanswered' )
					: __( 'nUnanswered', '%d unanswered' ).replace( '%d', state.unansweredCount ) ) );
			}
			if ( state.flaggedCount > 0 ) {
				parts.push( ( state.flaggedCount === 1
					? __( 'oneFlagged', '1 flagged' )
					: __( 'nFlagged', '%d flagged' ).replace( '%d', state.flaggedCount ) ) );
			}
			return parts.join( ' · ' );
		},

		get preSubmitAllClear() {
			return state.unansweredCount === 0 && state.flaggedCount === 0;
		},

		// -----------------------------------------------------------
		// Computed: timer
		// -----------------------------------------------------------

		get hasTimer() {
			return state.timeRemaining !== null;
		},

		get timerUrgent() {
			return state.timeRemaining !== null && state.timeRemaining <= 60;
		},

		get timerDisplay() {
			if ( state.timeRemaining === null ) {
				return '';
			}
			const m = Math.floor( state.timeRemaining / 60 );
			const s = state.timeRemaining % 60;
			return `${ String( m ).padStart( 2, '0' ) }:${ String( s ).padStart( 2, '0' ) }`;
		},

		// Detail line for the resume banner: "3 answers restored · 12:34 left".
		get resumeNoticeDetail() {
			const n     = state.resumeRestoredCount;
			const parts = [ `${ n } ${ 1 === n ? __( 'answerRestored', 'answer restored' ) : __( 'answersRestored', 'answers restored' ) }` ];
			if ( state.timeRemaining !== null && state.timeRemaining > 0 ) {
				const m = Math.floor( state.timeRemaining / 60 );
				const s = state.timeRemaining % 60;
				parts.push( `${ String( m ).padStart( 2, '0' ) }:${ String( s ).padStart( 2, '0' ) } ${ __( 'timeLeft', 'left' ) }` );
			}
			return parts.join( ' · ' );
		},

		// -----------------------------------------------------------
		// Computed: results
		// -----------------------------------------------------------

		get hasPassed() {
			return !! state.result?.passed;
		},

		// There is deliberately NO resultsIcon getter. It existed, and the five
		// results templates bound it with data-wp-bind--data-lucide — but
		// lucide.createIcons() replaces the <i> it is bound to, so the binding
		// could only ever fire once, at hydration, when state.result is still
		// null. Every student who passed was shown the x-circle chosen at load
		// (Basecamp 10171508068). partials/quiz-results-icon.php now renders
		// both icons statically and binds VISIBILITY on a wrapper lucide does
		// not touch. Do not reintroduce a bound icon NAME here.

		get resultsTitle() {
			if ( ! state.result ) {
				return '';
			}
			return state.hasPassed
				? __( 'congratulations', 'Congratulations!' )
				: __( 'quizComplete', 'Quiz complete' );
		},

		get scoreText() {
			if ( ! state.result ) {
				return '';
			}
			const tpl = __( 'youScored', 'You scored %d%%' );
			// Replace the whole `%d%%` token in one shot. Splitting it into two
			// `.replace()` calls used to match `%d%` first and leave the trailing
			// `%` from `%%` behind, producing "100%%".
			return tpl.replace( '%d%%', ( state.result.percentage ?? 0 ) + '%' );
		},

		get passFailText() {
			if ( ! state.result ) {
				return '';
			}
			return state.hasPassed
				? __( 'youPassed', 'You passed the quiz!' )
				: __( 'youDidNotPass', 'You did not reach the passing score. Try again!' );
		},

		get reviewQuestions() {
			return state.result?.review || [];
		},

		get hasReview() {
			return state.reviewQuestions.length > 0;
		},

		// A short review reads at a glance; a long one must collapse. Above
		// this line every review card renders closed (a <details> summary
		// row), so 300 questions scan as a list of titles + status glyphs
		// instead of a 38,000px wall. The student opens what they need.
		get reviewOpenByDefault() {
			return state.reviewQuestions.length <= 20;
		},

		get reviewTotalCount() {
			return state.reviewQuestions.length;
		},

		// Review grouped by question CATEGORY (the review-board template's
		// grouping, mirroring how the quiz itself is subject-grouped). Falls
		// back to ONE unlabeled group when fewer than two real categories
		// exist, so the template renders the same structure either way.
		get reviewGroups() {
			const review = state.reviewQuestions;
			const groups = [];
			const seen   = new Map();

			for ( const q of review ) {
				const label = q.category || '';
				if ( ! seen.has( label ) ) {
					const g = { label, questions: [] };
					seen.set( label, g );
					groups.push( g );
				}
				seen.get( label ).questions.push( q );
			}

			const named = groups.filter( ( g ) => g.label !== '' );
			if ( named.length < 2 ) {
				return [ { label: '', summary: '', questions: review } ];
			}

			for ( const g of groups ) {
				const correct = g.questions.filter( ( q ) => q.correct ).length;
				g.summary = fillNums(
					__( 'groupCorrectSummary', '%1$d of %2$d correct' ),
					correct,
					g.questions.length
				);
			}

			return groups;
		},

		// The certificate the student holds for this quiz's course, resolved
		// server-side AFTER grading — so a course-final quiz that completes the
		// course in the same request links to the certificate it just issued.
		// Null on standalone quizzes and whenever nothing has been earned.
		get hasCertificate() {
			return !! state.result?.certificate?.url;
		},

		get certificateUrl() {
			return state.result?.certificate?.url || '';
		},

		// A2: per-category score breakdown on the results screen (present only
		// when the quiz groups by category and the reveal gate allows it).
		// Each row gets a display `summary` ("2 / 2 (100%)") composed here so the
		// template binds plain text (numbers/percent are locale-neutral).
		get categoryBreakdown() {
			const rows = state.result?.category_breakdown || [];
			return rows.map( row => ( {
				...row,
				summary: ( row.earned ?? 0 ) + ' / ' + ( row.possible ?? 0 ) + ' (' + ( row.percentage ?? 0 ) + '%)',
			} ) );
		},

		get hasCategoryBreakdown() {
			return state.categoryBreakdown.length > 0;
		},

		get correctCount() {
			return state.reviewQuestions.filter( q => q.correct ).length;
		},

		get incorrectCount() {
			return state.reviewQuestions.filter( q => ! q.correct && ! q.pending ).length;
		},

		get pendingCount() {
			return state.reviewQuestions.filter( q => q.pending ).length;
		},

		get scoreSummary() {
			const parts = [];
			if ( state.correctCount > 0 ) {
				parts.push(
					__( 'countCorrect', '%d correct' ).replace( '%d', state.correctCount )
				);
			}
			if ( state.incorrectCount > 0 ) {
				parts.push(
					__( 'countIncorrect', '%d incorrect' ).replace( '%d', state.incorrectCount )
				);
			}
			if ( state.pendingCount > 0 ) {
				parts.push(
					__( 'countPending', '%d pending review' ).replace( '%d', state.pendingCount )
				);
			}
			return parts.join( ' · ' );
		},
	},

	// ==============================================================
	// Actions
	// ==============================================================

	actions: {
		async startQuiz() {
			if ( state.isStarting ) {
				return;
			}

			const ctx = getContext();
			state.isStarting = true;

			try {
				const res = await wp.apiFetch( {
					path: `/learnomy/v1/quizzes/${ ctx.quizId }/start`,
					method: 'POST',
				} );

				state.attemptId      = res.data.attempt_id;
				state.questions      = res.data.questions || [];
				state.navigation     = res.data.navigation || 'free';
				state.tabSwitchLimit = parseInt( res.data.tab_switch_limit, 10 ) || 0;
				state.feedbackTiming = res.data.feedback_timing || 'after_submit';
				state.groupByCategory = !! res.data.group_by_category;
				// Section-level navigation for the sectioned template (BC #9993392864).
				// In free mode the sectioned player opens on the section picker
				// instead of the section-1 intro (the flags are sectioned-only).
				state.sectionNavigation = res.data.section_navigation || 'linear';
				state.questionsPerSection = parseInt( res.data.questions_per_section, 10 ) || 10;
				if ( 'free' === state.sectionNavigation ) {
					state.sectionPickerVisible = true;
					state.sectionIntroVisible  = false;
				} else if ( typeof state.sectionIntroVisible === 'boolean' ) {
					/*
					 * LINEAR sectioned start opens on section 1's questions, not
					 * on its intro card.
					 *
					 * sectionIntroVisible initialises true and only the free-nav
					 * and resume branches cleared it, so a fresh linear start
					 * landed on an intro card whose own comment says it is for
					 * "transitioning between sections" — and nothing auto-opened
					 * section 1, because beginSection() is reachable only from
					 * that card's button. The student clicked Start and got a
					 * second gate asking them to start again (Basecamp
					 * 10171347934).
					 *
					 * It also burned exam time: startTimer() runs a few lines
					 * below, so on a timed paper the clock ran while they sat on
					 * a gate they had not asked for, with no upper bound — walk
					 * away there and the attempt auto-submits unanswered
					 * (Basecamp 10171576667).
					 *
					 * The intro is untouched BETWEEN sections, which is its
					 * documented job: the section-advance still raises it, so a
					 * student finishing section 1 gets the "Section 2 of 3" card
					 * as before. Only the redundant first one goes.
					 */
					state.sectionIntroVisible = false;
				}

				// A4 resume: restore saved answers + position + flags from a
				// frozen attempt so reopening continues exactly where it stopped.
				if ( res.data.is_resume ) {
					const saved   = res.data.saved_answers || {};
					const answers = {};
					Object.keys( saved ).forEach( ( qid ) => {
						if ( saved[ qid ] !== null && saved[ qid ] !== undefined ) {
							answers[ qid ] = hydrateSavedAnswer( saved[ qid ] );
						}
					} );
					state.answers = answers;
					state.flagged = Array.isArray( res.data.flagged ) ? res.data.flagged.slice() : [];
					const lastQuestion = Math.max( 0, state.questions.length - 1 );
					state.currentIndex = Math.min( Math.max( 0, parseInt( res.data.current_index, 10 ) || 0 ), lastQuestion );

					// SECTIONED layout: put them back in the section that question
					// belongs to. currentSectionIndex was never derived from the
					// resumed index, so beginSection() always opened Section 1's
					// intro and the student had to step forward to where they
					// already were — contradicting the resume promise in
					// quizzes/autosave-and-resume.md (Basecamp 10163002975).
					//
					// Guarded on the sectioned state existing, so the focused and
					// one-page layouts are untouched.
					if ( typeof state.currentSectionIndex === 'number' ) {
						const perSection = state.questionsPerSection || 10;
						state.currentSectionIndex  = Math.floor( state.currentIndex / perSection );
						state.sectionQuestionIndex = state.currentIndex % perSection;
						// We are resuming by definition here: land on the question,
						// not on the intro card for a section already under way.
						state.sectionIntroVisible  = false;
					}

					// Narrate the restore so the reappearing screen doesn't read as
					// "progress lost". [card 10062949966]
					state.resumeRestoredCount = Object.keys( answers ).length;
					state.resumeNoticeVisible = true;
				}

				if ( res.data.time_limit && res.data.time_limit > 0 ) {
					state.timeRemaining = res.data.time_limit;
					actions.startTimer();
				}

				state.started    = true;
				state.isStarting = false;

				actions.initAntiCheat();
			} catch ( err ) {
				state.isStarting = false;
				// Show error via toast if available.
				if ( window.lrnToast ) {
					window.lrnToast(
						( err && err.error && err.error.message ) || ( err && err.message ) || __( 'failedToStart', 'Failed to start quiz.' ),
						'error'
					);
				}
			}
		},

		/**
		 * Store the option under the current directive context as this
		 * question's answer.
		 *
		 * Pure bookkeeping, no network. Split out so that layouts which own
		 * their own selection flow — the conversational overlay decides whether
		 * to auto-advance — do not have to restate how an answer is stored.
		 * Duplicating that rule is how the conversational layout came to write
		 * a bare id over an mc_multi selection (Basecamp 10134139919).
		 */
		recordCurrentChoice() {
			const ctx = getContext();
			if ( ctx.item?.id === undefined ) {
				return;
			}
			actions.recordChoice( ctx.item.id );
		},

		/**
		 * Store one answer id against the current question.
		 *
		 * The single place that knows an mc_multi answer is a SET. Every entry
		 * point routes through here — click, and the conversational layout's
		 * number-key shortcuts, which reach it without a directive context.
		 *
		 * @param {number} answerId The option being chosen or un-chosen.
		 */
		recordChoice( answerId ) {
			if ( ! state.currentQuestion || answerId === undefined ) {
				return;
			}
			const qId = state.currentQuestion.id;

			// mc_multi accumulates a SET of ids and toggles on re-click;
			// mc_single / true_false replace the single stored id.
			state.answers = {
				...state.answers,
				[ qId ]: isMultiSelect( state.currentQuestion )
					? toggleMultiAnswer( state.answers[ qId ], answerId )
					: answerId,
			};
		},

		async selectAnswer() {
			if ( ! state.currentQuestion ) {
				return;
			}

			const qId = state.currentQuestion.id;

			actions.recordCurrentChoice();

			// Per-question feedback: POST the answer so the server can grade it
			// and return correctness. `immediate` gets the full payload (correct
			// IDs + explanation); `after_question` only gets the correct boolean.
			// Other timings (`after_submit`, `after_attempt`, `never`) skip this.
			// Ordering + matching store complex payloads that don't round-trip
			// through the single-question feedback endpoint — their answers are
			// committed only on submit.
			//
			// mc_multi joins them, for a different reason: a selection is not
			// FINISHED until the student stops ticking boxes, and there is no
			// "done" signal. Grading on each toggle would mark a correct
			// two-of-three answer wrong on the first click and then flip, which
			// reads as the quiz being broken. Committed on submit instead.
			if (
				state.isPerQuestionFeedback
				&& state.attemptId
				&& state.answers[ qId ] !== undefined
				&& ! state.isOrderingType
				&& ! state.isMatchingType
				&& ! isMultiSelect( state.currentQuestion )
			) {
				try {
					const res = await wp.apiFetch( {
						path: `/learnomy/v1/attempts/${ state.attemptId }/answer`,
						method: 'POST',
						data: { question_id: qId, response: String( state.answers[ qId ] ) },
					} );
					if ( res.data?.feedback ) {
						state.questionFeedback = { ...state.questionFeedback, [ qId ]: res.data.feedback };
					}
				} catch ( err ) {
					// Silently fail — the answer is already stored locally and will be
					// submitted with the batch on quiz submit.
				}
			}

			actions.persistRunnerState();
		},

		updateTextAnswer( event ) {
			if ( ! state.currentQuestion ) {
				return;
			}
			const qId = state.currentQuestion.id;
			state.answers = { ...state.answers, [ qId ]: event.target.value };
			actions.persistRunnerState();
		},

		/**
		 * Fill-in-the-blank: store the per-blank entries as a map keyed by the
		 * blank token (group_id). This is the exact shape
		 * Quiz_Service::score_fill_blank() reads — `{ blank1: 'x', blank2: 'y' }`.
		 * Each input carries its blank key on data-blank-key.
		 */
		updateBlankAnswer( event ) {
			if ( ! state.currentQuestion ) {
				return;
			}
			const qId = state.currentQuestion.id;
			const key = event.target.dataset.blankKey;
			if ( ! key ) {
				return;
			}
			const current = state.answers[ qId ];
			const map = current && typeof current === 'object' && ! Array.isArray( current )
				? { ...current }
				: {};
			map[ key ] = event.target.value;
			state.answers = { ...state.answers, [ qId ]: map };
			actions.persistRunnerState();
		},

		/**
		 * Per-question feedback for free-text questions (short_answer / fill_blank).
		 *
		 * Choice questions POST for grading from selectAnswer(); text inputs only
		 * stored their value locally, so the immediate / after_question feedback
		 * never fired for them. Bound to the input's blur event — when the student
		 * finishes typing, POST the answer so the server grades it against its
		 * accepted answers and returns ✓/✗ (and the explanation on `immediate`).
		 */
		async commitTextAnswer() {
			if ( ! state.currentQuestion ) {
				return;
			}
			const qId   = state.currentQuestion.id;
			const value = state.answers[ qId ];

			// Empty answers don't get graded; essay is manually graded (no auto
			// per-question feedback). Only short_answer / fill_blank qualify.
			const hasValue = 'string' === typeof value
				? '' !== value.trim()
				: !! ( value && 'object' === typeof value && Object.values( value ).some( ( v ) => '' !== String( v ).trim() ) );

			if (
				! state.isPerQuestionFeedback
				|| ! state.attemptId
				|| ! hasValue
				|| ! ( state.isShortAnswerType || state.isFillBlankType )
			) {
				return;
			}

			try {
				const res = await wp.apiFetch( {
					path: `/learnomy/v1/attempts/${ state.attemptId }/answer`,
					method: 'POST',
					data: { question_id: qId, response: value },
				} );
				if ( res.data?.feedback ) {
					state.questionFeedback = { ...state.questionFeedback, [ qId ]: res.data.feedback };
				}
			} catch ( err ) {
				// Silently fail — the answer is stored locally and submitted with
				// the batch on quiz submit.
			}
		},

		// -----------------------------------------------------------
		// Ordering: HTML5 native drag-and-drop
		//
		// Payload shape sent to the grader: [answerId, answerId, ...] in the
		// student's chosen order. This mirrors what
		// learnomy-pro/.../class-grader.php::grade_ordering() expects.
		// -----------------------------------------------------------

		/**
		 * Seed the answer array with the current DOM order on first render.
		 * Without this, a student who doesn't touch the list still submits a
		 * valid "attempt" — important so they can't accidentally skip an
		 * ordering question by just clicking Next.
		 */
		initOrdering( { ref } = {} ) {
			if ( ! state.currentQuestion ) {
				return;
			}
			const container = ref || document.querySelector(
				`.lrn-question-ordering[data-question-id="${ state.currentQuestion.id }"]`
			) || document.querySelector( '.lrn-question-ordering' );
			if ( ! container ) {
				return;
			}
			const qId = state.currentQuestion.id;
			if ( Array.isArray( state.answers[ qId ] ) ) {
				return;
			}
			const ids = Array.from(
				container.querySelectorAll( '.lrn-ordering-item' )
			).map( ( el ) => parseInt( el.dataset.answerId, 10 ) ).filter( Boolean );
			if ( ids.length ) {
				state.answers = { ...state.answers, [ qId ]: ids };
			}
		},

		orderingDragStart( event ) {
			const item = event.target.closest( '.lrn-ordering-item' );
			if ( ! item ) {
				return;
			}
			orderingDragSource = item;
			item.classList.add( 'lrn-ordering-item--dragging' );
			if ( event.dataTransfer ) {
				event.dataTransfer.effectAllowed = 'move';
				try {
					event.dataTransfer.setData( 'text/plain', item.dataset.answerId || '' );
				} catch ( _e ) {
					// Some browsers (Safari in strict modes) reject setData calls.
				}
			}
		},

		orderingDragOver( event ) {
			event.preventDefault();
			if ( event.dataTransfer ) {
				event.dataTransfer.dropEffect = 'move';
			}
			const item = event.target.closest( '.lrn-ordering-item' );
			if ( item && item !== orderingDragSource ) {
				item.classList.add( 'lrn-ordering-item--drag-over' );
			}
		},

		orderingDragLeave( event ) {
			const item = event.target.closest( '.lrn-ordering-item' );
			if ( item ) {
				item.classList.remove( 'lrn-ordering-item--drag-over' );
			}
		},

		orderingDrop( event ) {
			event.preventDefault();
			const target = event.target.closest( '.lrn-ordering-item' );
			if ( ! orderingDragSource || ! target || target === orderingDragSource ) {
				if ( target ) {
					target.classList.remove( 'lrn-ordering-item--drag-over' );
				}
				return;
			}
			target.classList.remove( 'lrn-ordering-item--drag-over' );

			// Insert above or below the drop target based on the cursor's
			// vertical position within the target row.
			const parent = target.parentNode;
			const rect   = target.getBoundingClientRect();
			const before = event.clientY < rect.top + rect.height / 2;
			if ( before ) {
				parent.insertBefore( orderingDragSource, target );
			} else {
				parent.insertBefore( orderingDragSource, target.nextSibling );
			}

			if ( state.currentQuestion ) {
				const qId = state.currentQuestion.id;
				const ids = Array.from(
					parent.querySelectorAll( '.lrn-ordering-item' )
				).map( ( el ) => parseInt( el.dataset.answerId, 10 ) ).filter( Boolean );
				state.answers = { ...state.answers, [ qId ]: ids };
			}
		},

		orderingDragEnd( event ) {
			const item = event.target.closest( '.lrn-ordering-item' );
			if ( item ) {
				item.classList.remove( 'lrn-ordering-item--dragging' );
			}
			document
				.querySelectorAll( '.lrn-ordering-item--drag-over' )
				.forEach( ( el ) => el.classList.remove( 'lrn-ordering-item--drag-over' ) );
			orderingDragSource = null;
		},

		// -----------------------------------------------------------
		// Matching: click-to-pair
		//
		// Payload shape sent to the grader: { leftAnswerId: rightAnswerId, ... }
		// keyed by prompt (left) id. Mirrors what
		// learnomy-pro/.../class-grader.php::grade_matching() expects.
		// -----------------------------------------------------------

		initMatching() {
			if ( ! state.currentQuestion ) {
				return;
			}
			const qId   = state.currentQuestion.id;
			const saved = state.answers[ qId ];
			if ( ! saved || typeof saved !== 'object' || Array.isArray( saved ) ) {
				state.answers = { ...state.answers, [ qId ]: {} };
			}
		},

		matchingSelectLeft( event ) {
			const term = event.target.closest( '.lrn-matching__term--left' );
			if ( ! term ) {
				return;
			}
			const id = parseInt( term.dataset.answerId, 10 );
			if ( ! id ) {
				return;
			}
			// Toggle selection: clicking the same left term twice deselects.
			state.matchingSelectedLeft = state.matchingSelectedLeft === id ? null : id;

			const container = term.closest( '.lrn-question-matching' );
			if ( container ) {
				container
					.querySelectorAll( '.lrn-matching__term--left' )
					.forEach( ( el ) => {
						el.classList.toggle(
							'lrn-matching__term--selected',
							parseInt( el.dataset.answerId, 10 ) === state.matchingSelectedLeft
						);
					} );
			}
		},

		matchingSelectRight( event ) {
			const term = event.target.closest( '.lrn-matching__term--right' );
			if ( ! term || ! state.currentQuestion || ! state.matchingSelectedLeft ) {
				return;
			}
			const rightId = parseInt( term.dataset.answerId, 10 );
			const leftId  = state.matchingSelectedLeft;
			if ( ! rightId || ! leftId ) {
				return;
			}

			const qId  = state.currentQuestion.id;
			const prev = state.answers[ qId ] && typeof state.answers[ qId ] === 'object' && ! Array.isArray( state.answers[ qId ] )
				? { ...state.answers[ qId ] }
				: {};

			// A right-column term can only be paired once — if it already
			// belongs to another left term, release that pairing first.
			for ( const lKey of Object.keys( prev ) ) {
				if ( prev[ lKey ] === rightId ) {
					delete prev[ lKey ];
				}
			}
			prev[ String( leftId ) ] = rightId;
			state.answers            = { ...state.answers, [ qId ]: prev };
			state.matchingSelectedLeft = null;

			const container = term.closest( '.lrn-question-matching' );
			if ( container ) {
				actions.repaintMatchingDOM( container );
			}
		},

		matchingClear() {
			if ( ! state.currentQuestion ) {
				return;
			}
			const qId = state.currentQuestion.id;
			state.answers = { ...state.answers, [ qId ]: {} };
			state.matchingSelectedLeft = null;
			const container = document.querySelector(
				`.lrn-question-matching[data-question-id="${ qId }"]`
			) || document.querySelector( '.lrn-question-matching' );
			if ( container ) {
				container
					.querySelectorAll( '.lrn-matching__term' )
					.forEach( ( el ) => {
						el.classList.remove(
							'lrn-matching__term--selected',
							'lrn-matching__term--matched'
						);
						const label = el.querySelector( '.lrn-matching__term-pair' );
						if ( label ) {
							label.textContent = '';
						}
					} );
			}
		},

		matchingKeydown( event ) {
			// Space / Enter mirrors a click so matching is keyboard-operable.
			if ( event.key === ' ' || event.key === 'Enter' ) {
				event.preventDefault();
				const left  = event.target.closest( '.lrn-matching__term--left' );
				const right = event.target.closest( '.lrn-matching__term--right' );
				if ( left ) {
					actions.matchingSelectLeft( event );
				} else if ( right ) {
					actions.matchingSelectRight( event );
				}
			}
		},

		/**
		 * Repaint matched-pair indicators after a pair commits. Uses a numeric
		 * badge on each term so visitors can see which left maps to which
		 * right without needing an SVG connector overlay.
		 */
		repaintMatchingDOM( container ) {
			if ( ! state.currentQuestion ) {
				return;
			}
			const qId = state.currentQuestion.id;
			const pairs = state.answers[ qId ];
			if ( ! pairs || typeof pairs !== 'object' ) {
				return;
			}

			const leftIds = Object.keys( pairs ).map( ( k ) => parseInt( k, 10 ) );
			const pairIndexByLeft = {};
			leftIds.forEach( ( id, i ) => { pairIndexByLeft[ id ] = i + 1; } );

			container
				.querySelectorAll( '.lrn-matching__term' )
				.forEach( ( el ) => {
					const id   = parseInt( el.dataset.answerId, 10 );
					const side = el.dataset.side;
					let index  = 0;

					if ( side === 'left' ) {
						index = pairIndexByLeft[ id ] || 0;
					} else if ( side === 'right' ) {
						for ( const leftKey of Object.keys( pairs ) ) {
							if ( pairs[ leftKey ] === id ) {
								index = pairIndexByLeft[ parseInt( leftKey, 10 ) ] || 0;
								break;
							}
						}
					}

					el.classList.toggle( 'lrn-matching__term--matched', index > 0 );
					el.classList.remove( 'lrn-matching__term--selected' );
					const label = el.querySelector( '.lrn-matching__term-pair' );
					if ( label ) {
						label.textContent = index ? String( index ) : '';
					}
				} );
		},

		nextQuestion() {
			if ( state.currentIndex < state.totalQuestions - 1 ) {
				state.currentIndex++;
				actions.persistRunnerState();
			}
		},

		prevQuestion() {
			if ( state.currentIndex > 0 ) {
				state.currentIndex--;
				actions.persistRunnerState();
			}
		},

		// A4: fire-and-forget save of the resume state (position + the live
		// answers map) so reopening continues exactly where it stopped, for
		// every feedback timing. Debounced so rapid typing/clicking coalesces.
		// Never blocks the UI — a dropped save just resumes a step earlier.
		persistRunnerState() {
			if ( ! state.attemptId ) {
				return;
			}
			if ( runnerSaveTimer ) {
				clearTimeout( runnerSaveTimer );
			}
			runnerSaveTimer = setTimeout( () => {
				try {
					const data = {
						current_index: state.currentIndex,
						answers: state.answers,
						// Persist flags too, so flag-for-review survives a resume
						// (the review workflow is pointless if reopening clears them).
						flagged: state.flagged,
					};
					// Report remaining seconds so the pause_on_resume policy can
					// continue from here (the default wall-clock policy ignores it).
					if ( state.timeRemaining !== null && state.timeRemaining >= 0 ) {
						data.remaining = state.timeRemaining;
					}
					wp.apiFetch( {
						path: `/learnomy/v1/attempts/${ state.attemptId }/state`,
						method: 'POST',
						data,
					} ).catch( () => {} );
				} catch ( e ) {
					// Best-effort — ignore.
				}
			}, 400 );
		},

		nextOrSubmit() {
			if ( state.isLastQuestion ) {
				// A4: show the pre-submit review when anything needs attention
				// (unanswered or flagged); a fully-answered, unflagged attempt
				// submits straight away so we never add friction to a clean run.
				if ( state.preSubmitAllClear ) {
					actions.submitQuiz();
				} else {
					actions.openPreSubmit();
				}
			} else {
				actions.nextQuestion();
			}
		},

		// Proctoring: the tab-switch limit was reached, so finalize the attempt
		// and flag it so the results screen can explain why it was auto-submitted.
		autoSubmitForTabSwitch() {
			if ( state.submitted || state.tabSwitchAutoSubmitted ) {
				return;
			}
			state.tabSwitchAutoSubmitted = true;
			autoSubmitPending = true;
			actions.submitQuiz();
		},

		async submitQuiz() {
			if ( state.submitted || ! state.attemptId ) {
				return;
			}

			state.submitted = true;
			clearInterval( timerInterval );
			timerInterval = null;

			try {
				const res = await wp.apiFetch( {
					path: `/learnomy/v1/attempts/${ state.attemptId }/submit`,
					method: 'POST',
					data: { answers: state.answers },
				} );

				const result = res.data || res;
				// Add computed `incorrect` flag to each review question.
				if ( result.review ) {
					result.review = result.review.map( q => ( {
						...q,
						incorrect: ! q.correct && ! q.pending,
					} ) );
				}
				state.result = result;
				// Retire the retake path once the cap is reached, on the same
				// count basis the server gate uses — so a same-session "Try Again"
				// shows "no attempts left" instead of a bare 400. null = unlimited
				// (practice quizzes, or no cap), where retake stays open.
				if ( typeof result.attempts_remaining === 'number' ) {
					state.attemptsRemaining = result.attempts_remaining;
				}
				autoSubmitPending = false;
				submitAutoRetries = 0;
			} catch ( err ) {
				// Revert so the attempt can be retried.
				state.submitted = false;

				// Frozen auto-submit (timer expiry / tab-switch limit): the student
				// can no longer change answers or re-trigger, so a transient failure
				// must not strand them on a dead expired screen. Retry with backoff,
				// then reload as a last resort. Re-submitting a finalized attempt is
				// rejected server-side, so this can never double-grade; on a
				// lost-response case the reload renders the graded results.
				// [card 10062744656]
				if ( autoSubmitPending ) {
					if ( submitAutoRetries < 3 ) {
						submitAutoRetries++;
						setTimeout( () => actions.submitQuiz(), 1500 * submitAutoRetries );
						return;
					}
					autoSubmitPending = false;
					submitAutoRetries = 0;
					window.location.reload();
					return;
				}

				// Manual submit — surface the error and leave the Submit control
				// available so the user can retry.
				if ( window.lrnToast ) {
					window.lrnToast(
						( err && err.error && err.error.message ) || ( err && err.message ) || __( 'failedToSubmit', 'Failed to submit quiz.' ),
						'error'
					);
				}
			}
		},

		retakeQuiz() {
			// The "Try Again" button is server-rendered once, so after the final
			// attempt it can still be on screen. Re-POSTing /start would 400 with
			// "max attempts reached"; catch it here and tell the student instead
			// of resetting to a start screen they can't use. [Basecamp 10096673115]
			if ( typeof state.attemptsRemaining === 'number' && state.attemptsRemaining <= 0 ) {
				if ( window.lrnToast ) {
					window.lrnToast(
						__( 'noAttemptsLeft', 'You have used all attempts for this quiz.' ),
						'info'
					);
				}
				return;
			}

			state.currentIndex         = 0;
			state.answers              = {};
			state.submitted            = false;
			state.started              = false;
			state.result               = null;
			state.isStarting           = false;
			state.tabSwitches          = 0;
			state.tabSwitchWarning     = false;
			state.timeRemaining        = null;
			state.attemptId            = null;
			state.questions            = [];
			state.navigation           = 'free';
			state.feedbackTiming       = 'after_submit';
			state.questionFeedback     = {};
			state.matchingSelectedLeft = null;
		},

		jumpToQuestion( index ) {
			if ( state.navigation === 'sequential' ) {
				return;
			}
			if ( index >= 0 && index < state.totalQuestions ) {
				state.currentIndex = index;
				actions.persistRunnerState();
			}
		},

		toggleOverview() {
			state.overviewOpen = ! state.overviewOpen;
		},

		closeOverview() {
			state.overviewOpen = false;
		},

		dismissResumeNotice() {
			state.resumeNoticeVisible = false;
		},

		// Jump to a question from the map. Index comes from the per-item context.
		jumpFromMap() {
			const ctx = getContext();
			const idx = ctx && ctx.item ? parseInt( ctx.item.index, 10 ) : NaN;
			if ( isNaN( idx ) ) {
				return;
			}
			actions.jumpToQuestion( idx );
			state.overviewOpen = false;
		},

		// Toggle flag-for-review on the current question; persist for resume.
		toggleFlag() {
			if ( ! state.currentQuestion ) {
				return;
			}
			const id = state.currentQuestion.id;
			state.flagged = state.flagged.includes( id )
				? state.flagged.filter( ( f ) => f !== id )
				: [ ...state.flagged, id ];
			actions.persistRunnerState();
		},

		// Pre-submit review screen — opened instead of submitting straight away
		// so the learner sees unanswered/flagged counts and can jump back.
		openPreSubmit() {
			state.overviewOpen  = false;
			state.preSubmitOpen = true;
		},

		closePreSubmit() {
			state.preSubmitOpen = false;
		},

		// Jump from the pre-submit screen to the first unanswered question.
		jumpToFirstUnanswered() {
			const idx = state.questions.findIndex( ( q ) => ! isAnswerFilled( state.answers[ q.id ] ) );
			if ( idx >= 0 ) {
				actions.jumpToQuestion( idx );
				state.preSubmitOpen = false;
			}
		},

		// Jump from the pre-submit screen to the first flagged question.
		jumpToFirstFlagged() {
			const idx = state.questions.findIndex( ( q ) => state.flagged.includes( q.id ) );
			if ( idx >= 0 ) {
				actions.jumpToQuestion( idx );
				state.preSubmitOpen = false;
			}
		},

		confirmSubmit() {
			state.preSubmitOpen = false;
			actions.submitQuiz();
		},

		// Timer.
		startTimer() {
			if ( timerInterval ) {
				return;
			}
			timerInterval = setInterval( () => {
				if ( state.timeRemaining === null || state.timeRemaining <= 0 ) {
					clearInterval( timerInterval );
					timerInterval = null;
					if ( ! state.submitted ) {
						autoSubmitPending = true;
						actions.submitQuiz();
					}
					return;
				}
				state.timeRemaining--;
			}, 1000 );
		},

		// Anti-cheat.
		initAntiCheat() {
			const handler = () => {
				if ( state.attemptId && ! state.submitted ) {
					state.tabSwitches++;
					state.tabSwitchWarning = true;

					// Proctoring auto-submit: if the student has now reached the
					// configured limit, finalize immediately (client-side, so the
					// UX is instant) — the server response below is the backstop.
					if ( state.tabSwitchLimit > 0 && state.tabSwitches >= state.tabSwitchLimit ) {
						actions.autoSubmitForTabSwitch();
						return;
					}

					// Anti-cheat tab-switch counter is registered as
					// `/quiz-attempts/{id}/tab-switch` (not `/attempts/...`).
					// The JS path used to be `/attempts/...` which 404'd
					// silently — the .catch(() => {}) below swallowed every
					// 404 so the proctoring feature looked like it worked.
					// Confirmed via grep + live curl: only the quiz-attempts
					// prefix matches the registered route.
					wp.apiFetch( {
						path: `/learnomy/v1/quiz-attempts/${ state.attemptId }/tab-switch`,
						method: 'POST',
						data: { count: state.tabSwitches },
					} ).then( ( res ) => {
						// Server is authoritative on the limit (guards against a
						// tampered client-side threshold).
						if ( res && res.data && res.data.auto_submit ) {
							actions.autoSubmitForTabSwitch();
						}
					} ).catch( () => {} );
				}
			};

			document.addEventListener( 'visibilitychange', () => {
				if ( document.hidden ) {
					handler();
				}
			} );
		},
	},

	callbacks: {
		// A3: inject the rich question stem (server-rendered + wp_kses_post'd, so
		// trusted) into the one-at-a-time templates. WP Interactivity has no HTML
		// bind directive, so a watch callback sets innerHTML imperatively — the
		// same trusted-server-HTML pattern the lesson player uses.
		renderCurrentStem() {
			const { ref } = getElement();
			if ( ref ) {
				ref.innerHTML = state.currentQuestionStemHtml || '';
			}
		},
	},
} );
