/**
 * Classic quiz store extension — all questions on one scrollable page.
 *
 * This module EXTENDS the base learnomy/quiz store (quiz-view.js) by merging
 * additional computed properties and actions specific to the classic template.
 * The Interactivity API merges store() calls with the same namespace, so
 * startQuiz, submitQuiz, retakeQuiz etc. all come from the base module.
 *
 * @package Learnomy
 */

import { store, getContext, getElement } from '@wordpress/interactivity';

// Inherit the same i18n object injected by Template_Loader for the base store.
const i18n = ( window.lrnQuiz && window.lrnQuiz.i18n ) ? window.lrnQuiz.i18n : {};
const __ = ( key, fallback ) => i18n[ key ] || fallback;

const TEXT_TYPES = [ 'short_answer', 'fill_blank', 'essay' ];

const { state, actions } = store( 'learnomy/quiz', {
	state: {
		/**
		 * Human-readable answered progress text for the sticky bar.
		 *
		 * @return {string} e.g. "3 of 10 answered"
		 */
		get answeredText() {
			const tpl = __( 'answeredOf', '%1$d of %2$d answered' );
			return tpl
				.replace( '%1$d', state.answeredCount )
				.replace( '%2$d', state.totalQuestions );
		},

		/**
		 * Whether any questions remain unanswered.
		 *
		 * @return {boolean}
		 */
		get hasUnanswered() {
			return state.answeredCount < state.totalQuestions;
		},

		/**
		 * Notice text shown near the bottom submit button.
		 *
		 * @return {string}
		 */
		get unansweredNotice() {
			const remaining = state.totalQuestions - state.answeredCount;
			if ( remaining <= 0 ) {
				return '';
			}
			return remaining === 1
				? __( 'questionStillUnanswered', '1 question still unanswered' )
				: __( 'questionsUnanswered', '%d questions still unanswered' )
					.replace( '%d', remaining );
		},

		/**
		 * All questions enriched with per-question computed helpers.
		 *
		 * Each item gains: label, isAnswered, isTextType, isShortAnswerType,
		 * isEssayType, textValue, inputName, and per-answer isSelected.
		 *
		 * @return {Array} Enriched question objects.
		 */
		get allQuestions() {
			// Each new category run gets the next anchor id, matched by the
			// jump-nav in state.categoryGroups (same first-appearance order).
			let categoryRunIndex = -1;
			return state.questions.map( ( question, index ) => {
				// A2: show a category heading above the first question of each
				// category run (questions arrive category-ordered when grouping is
				// on). Only when the quiz groups by category, so a normal quiz with
				// categorised questions shows no spurious headings.
				const catName       = question.category_name || '';
				const prevCatName   = index > 0 ? ( state.questions[ index - 1 ].category_name || '' ) : null;
				const showCategoryHeading = !! state.groupByCategory && ( index === 0 || catName !== prevCatName );
				const categoryHeading     = catName || __( 'generalCategory', 'General' );

				if ( showCategoryHeading ) {
					categoryRunIndex += 1;
				}
				// Anchor only on the run's first question (where the heading
				// renders); other questions carry '' so the id isn't duplicated.
				const categoryAnchorId = showCategoryHeading ? `lrn-quiz-cat-${ categoryRunIndex }` : '';

				const qType      = question.type || 'mc_single';
				// Custom text-style types registered by an extension
				// (Pro numeric / math / calculated) count as text here, so the
				// MC fieldset hides and the single text field surfaces.
				const customText = ( ( typeof window !== 'undefined' && window.learnomyTextAnswerTypes ) || [] ).includes( qType );
				const isText     = TEXT_TYPES.includes( qType ) || customText;
				const answerVal  = state.answers[ question.id ];
				const isAnswered = answerVal !== undefined && answerVal !== '';

				const isFillBlank = qType === 'fill_blank';
				const blankMap    = isFillBlank && answerVal && typeof answerVal === 'object' && ! Array.isArray( answerVal )
					? answerVal
					: {};

				return {
					...question,
					label:            `Q${ index + 1 }`,
					showCategoryHeading,
					categoryHeading,
					categoryAnchorId,
					isAnswered,
					isTextType:       isText,
					isShortAnswerType: qType === 'short_answer',
					isCustomTextType: customText,
					isFillBlankType:  isFillBlank,
					// One descriptor per {{blankN}} token, keyed by the group_id
					// the grader matches. Empty for non-fill_blank questions.
					blanks:           isFillBlank
						? ( question.blanks || [] ).map( ( key, i ) => ( {
							key,
							label: `Blank ${ i + 1 }`,
							value: blankMap[ key ] ?? '',
						} ) )
						: [],
					isEssayType:      qType === 'essay',
					// fill_blank keeps a per-blank map, not a string, so the
					// single text field must not surface it.
					textValue:        ( isText && ! isFillBlank ) ? ( answerVal ?? '' ) : '',
					inputName:        `q_${ question.id }`,
					// Per-question input type. The classic layout renders every
					// question on one page, so this cannot be a page-level value —
					// each question decides for itself. Hardcoded `radio` in the
					// template made mc_multi unanswerable (Basecamp 10134139919).
					inputType:        'mc_multi' === qType ? 'checkbox' : 'radio',
					isMultiSelect:    'mc_multi' === qType,
					answers: ( question.answers || [] ).map( ( answer ) => ( {
						...answer,
						inputName:  `q_${ question.id }`,
						inputType:  'mc_multi' === qType ? 'checkbox' : 'radio',
						// Array-aware: an mc_multi answer is a set of ids.
						isSelected: Array.isArray( answerVal )
							? answerVal.includes( answer.id )
							: answerVal === answer.id,
					} ) ),
				};
			} );
		},

		/**
		 * A2 jump-nav: the distinct categories in this quiz, in display
		 * order, each with answered/total and an anchor id matching the
		 * heading rendered by allQuestions. Empty unless grouping is on.
		 *
		 * @return {Array} Category groups [{ name, anchorId, total, answered, countLabel }].
		 */
		get categoryGroups() {
			if ( ! state.groupByCategory ) {
				return [];
			}
			const groups = [];
			let prevName  = null;
			state.questions.forEach( ( question ) => {
				const name = question.category_name || __( 'generalCategory', 'General' );
				// Questions arrive category-contiguous when grouping is on, so a
				// change in name starts a new run = a new heading = a new anchor.
				if ( name !== prevName ) {
					groups.push( { name, anchorId: `lrn-quiz-cat-${ groups.length }`, total: 0, answered: 0, countLabel: '' } );
					prevName = name;
				}
				const group = groups[ groups.length - 1 ];
				group.total += 1;
				// Match the headline "X of N answered": a question counts once
				// its id appears in the answers map.
				if ( Object.prototype.hasOwnProperty.call( state.answers, question.id ) ) {
					group.answered += 1;
				}
			} );
			groups.forEach( ( group ) => {
				group.countLabel = `${ group.answered }/${ group.total }`;
			} );
			return groups;
		},

		/**
		 * Show the category jump-nav only when grouping is on and there are
		 * at least two categories to move between (one needs no nav).
		 *
		 * @return {boolean} Whether to render the jump-nav.
		 */
		get hasCategoryJumpNav() {
			return state.groupByCategory && state.categoryGroups.length > 1;
		},
	},

	actions: {
		/**
		 * Select an MC / true-false answer in classic mode.
		 *
		 * Reads the question ID from the radio input's name attribute and
		 * the answer ID from the event target value. Works with the nested
		 * data-wp-each context where context.item is an answer, not a question.
		 */
		selectClassicAnswer( event ) {
			const input = event.target;
			// The name follows the pattern "q_{questionId}".
			const qId = parseInt( input.name.replace( 'q_', '' ), 10 );
			const aId = parseInt( input.value, 10 );

			if ( isNaN( qId ) || isNaN( aId ) ) {
				return;
			}

			// A checkbox toggles into a set; a radio replaces the single value.
			// Reading the rendered input type rather than re-deriving the question
			// type keeps this in step with whatever the template actually drew —
			// they cannot disagree about whether this is a multi-select.
			if ( 'checkbox' === input.type ) {
				const current = state.answers[ qId ];
				const list = Array.isArray( current )
					? current.slice()
					: ( current === undefined || current === null || current === '' ? [] : [ current ] );
				const at = list.indexOf( aId );
				if ( at === -1 ) {
					list.push( aId );
				} else {
					list.splice( at, 1 );
				}
				state.answers = { ...state.answers, [ qId ]: list };
				return;
			}

			state.answers = { ...state.answers, [ qId ]: aId };
		},

		/**
		 * Update a text / essay answer in classic mode.
		 *
		 * Walks up from the input/textarea to the question card and reads
		 * the question ID from the data-wp-key attribute.
		 */
		updateClassicTextAnswer( event ) {
			// The question id comes from the Interactivity context (the
			// data-wp-each item), NOT from the DOM: `data-wp-key` is a directive
			// that renders the literal string "context.item.id", so reading it as
			// an attribute yields NaN and the answer was silently dropped — which
			// broke EVERY text/essay/Pro answer in the classic layout.
			const context = getContext();
			const qId     = context && context.item ? parseInt( context.item.id, 10 ) : NaN;
			if ( isNaN( qId ) ) {
				return;
			}

			state.answers = { ...state.answers, [ qId ]: event.target.value };
		},

		/**
		 * Update one blank of a fill-in-the-blank answer in classic mode.
		 * Reads the question id from the card's data-wp-key and the blank
		 * token from the input's data-blank-key, storing a per-blank map
		 * keyed by group_id — the shape Quiz_Service::score_fill_blank() reads.
		 */
		updateClassicBlankAnswer( event ) {
			const el      = event.target;
			// Same fix as updateClassicTextAnswer: id from context, not data-wp-key.
			const context = getContext();
			const qId     = context && context.item ? parseInt( context.item.id, 10 ) : NaN;
			const key     = el.dataset.blankKey;
			if ( isNaN( qId ) || ! key ) {
				return;
			}
			const current = state.answers[ qId ];
			const map     = current && typeof current === 'object' && ! Array.isArray( current )
				? { ...current }
				: {};
			map[ key ] = el.value;
			state.answers = { ...state.answers, [ qId ]: map };
		},

		/**
		 * Scroll to the first unanswered question card.
		 *
		 * Uses scroll-margin-top to account for the sticky top bar.
		 */
		scrollToFirstUnanswered() {
			const cards = document.querySelectorAll( '.lrn-quiz-classic__question-card' );
			for ( const card of cards ) {
				// An unanswered card will NOT have the --answered modifier class.
				if ( ! card.classList.contains( 'lrn-quiz-classic__question-card--answered' ) ) {
					card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					// Briefly highlight to draw attention.
					card.classList.add( 'lrn-quiz-classic__question-card--highlight' );
					setTimeout( () => {
						card.classList.remove( 'lrn-quiz-classic__question-card--highlight' );
					}, 1500 );
					break;
				}
			}
		},

		/**
		 * Jump to a category's heading from a jump-nav chip.
		 *
		 * Reads the target anchor from the clicked chip's context and scrolls
		 * the matching heading into view. scroll-margin-top on the heading
		 * clears the sticky top bar.
		 */
		jumpToCategory() {
			const context  = getContext();
			const anchorId = context && context.cat ? context.cat.anchorId : '';
			if ( ! anchorId ) {
				return;
			}
			const heading = document.getElementById( anchorId );
			if ( heading ) {
				heading.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		},
	},

	callbacks: {
		// A3: inject the rich question stem (server-rendered + wp_kses_post'd, so
		// trusted) for the classic all-at-once template. Per data-wp-each item, so
		// it reads the item's stemHtml. WP Interactivity has no HTML bind directive;
		// a watch callback sets innerHTML imperatively (trusted server HTML only).
		renderStem() {
			const { ref } = getElement();
			if ( ref ) {
				ref.innerHTML = getContext().item.stemHtml || '';
			}
		},
	},
} );
