/**
 * Sectioned quiz store extension -- multi-section exam style (SAT/GRE).
 *
 * This module EXTENDS the base learnomy/quiz store (quiz-view.js) by merging
 * section-specific computed properties and actions. Questions are divided into
 * sections of configurable size (default 10). The student completes one section
 * at a time and cannot revisit completed sections.
 *
 * @package Learnomy
 */

import { store, getContext } from '@wordpress/interactivity';

// Inherit the same i18n object injected by Template_Loader for the base store.
const i18n = ( window.lrnQuiz && window.lrnQuiz.i18n ) ? window.lrnQuiz.i18n : {};

/**
 * Fill a PHP-style sprintf template from the localized bag. Only the
 * placeholder forms our templates use (%d, %1$d, %2$d) are handled - the
 * section labels shipped as template literals no translator could reach
 * (Basecamp 10171812517), while neighboring strings in this same file
 * already read window.lrnQuiz.i18n.
 *
 * @param {string} template Localized template, e.g. "Section %1$d of %2$d".
 * @param {...number} nums  Positional numbers.
 * @return {string}
 */
function fillNums( template, ...nums ) {
	let out = String( template );
	nums.forEach( ( n, i ) => {
		out = out.replace( new RegExp( '%' + ( i + 1 ) + '\\$d', 'g' ), n );
	} );
	return out.replace( /%d/g, nums[ 0 ] );
}
const __ = ( key, fallback ) => i18n[ key ] || fallback;

const { state, actions } = store( 'learnomy/quiz', {
	state: {
		/**
		 * Number of questions per section. Configurable default.
		 *
		 * @type {number}
		 */
		questionsPerSection: 10,

		/**
		 * Index of the current section (0-based).
		 *
		 * @type {number}
		 */
		currentSectionIndex: 0,

		/**
		 * Whether the section intro card is visible.
		 *
		 * @type {boolean}
		 */
		sectionIntroVisible: true,

		/**
		 * Track which sections have been completed (locked).
		 * Map of sectionIndex -> true.
		 *
		 * @type {Object}
		 */
		completedSections: {},

		/**
		 * Current question index within the current section (0-based).
		 *
		 * @type {number}
		 */
		sectionQuestionIndex: 0,

		/**
		 * Section-level navigation mode — 'linear' (finish a section before the
		 * next, no revisiting) or 'free' (pick any section, switch freely until
		 * submit). Seeded from the quiz in the base start payload (BC #9993392864).
		 *
		 * @type {string}
		 */
		sectionNavigation: 'linear',

		/**
		 * Whether the free-navigation section picker landing is showing.
		 *
		 * @type {boolean}
		 */
		sectionPickerVisible: false,

		// -----------------------------------------------------------
		// Computed: free section navigation (BC #9993392864)
		// -----------------------------------------------------------

		/** True when the candidate may choose/switch sections in any order. */
		get isFreeSectionNav() {
			return state.sectionNavigation === 'free';
		},

		/** The question area is hidden during the section intro OR the picker. */
		get hideSectionQuestions() {
			return state.sectionIntroVisible || state.sectionPickerVisible;
		},

		/**
		 * The sections as pickable cards — name, question count, answered
		 * progress, and completion — for the free-navigation landing.
		 *
		 * @return {Array}
		 */
		get sectionCards() {
			return state.sections.map( ( sec, idx ) => {
				let answered = 0;
				sec.questions.forEach( ( q ) => {
					const val = state.answers[ q.id ];
					if ( val !== undefined && val !== '' && ! ( Array.isArray( val ) && val.length === 0 ) ) {
						answered++;
					}
				} );
				return {
					index:      idx,
					label:      sec.label,
					count:      sec.count,
					answered,
					isComplete: answered === sec.count,
					isCurrent:  idx === state.currentSectionIndex && ! state.sectionPickerVisible,
					countLabel: answered + ' / ' + sec.count,
				};
			} );
		},

		// -----------------------------------------------------------
		// Computed: sections
		// -----------------------------------------------------------

		/**
		 * Divide questions into section groups.
		 *
		 * @return {Array} Array of section objects.
		 */
		get sections() {
			const perSection = state.questionsPerSection || 10;
			const questions  = state.questions;
			const groups     = [];
			let idx          = 0;

			while ( idx < questions.length ) {
				const end    = Math.min( idx + perSection, questions.length );
				const slice  = questions.slice( idx, end );
				const sIndex = groups.length;

				groups.push( {
					label:      fillNums( i18n.sectionLabel || 'Section %d', sIndex + 1 ),
					questions:  slice,
					startIndex: idx,
					endIndex:   end - 1,
					count:      slice.length,
				} );

				idx = end;
			}

			return groups;
		},

		/**
		 * Total number of sections.
		 *
		 * @return {number}
		 */
		get sectionCount() {
			return state.sections.length;
		},

		/**
		 * The current section object.
		 *
		 * @return {Object|null}
		 */
		get currentSection() {
			return state.sections[ state.currentSectionIndex ] || null;
		},

		/**
		 * Label for the current section (e.g. "Section 1").
		 *
		 * @return {string}
		 */
		get currentSectionLabel() {
			return state.currentSection?.label || '';
		},

		/**
		 * Question counter within the current section.
		 *
		 * @return {string}
		 */
		get sectionQuestionCounter() {
			if ( ! state.currentSection ) {
				return '';
			}
			return fillNums( i18n.sectionQuestionBadge || 'Q%1$d of %2$d', state.sectionQuestionIndex + 1, state.currentSection.count );
		},

		/**
		 * Badge for the section intro (e.g. "Section 1 of 3").
		 *
		 * @return {string}
		 */
		get sectionIntroBadge() {
			return fillNums( i18n.sectionBadge || 'Section %1$d of %2$d', state.currentSectionIndex + 1, state.sectionCount );
		},

		/**
		 * Meta text for the section intro card.
		 *
		 * @return {string}
		 */
		get sectionIntroMeta() {
			if ( ! state.currentSection ) {
				return '';
			}
			const count = state.currentSection.count;
			return count === 1
				? __( 'oneQuestionInSection', '1 question in this section' )
				: __( 'nQuestionsInSection', '%d questions in this section' ).replace( '%d', count );
		},

		/**
		 * Overall progress text (e.g. "12 of 30 answered").
		 *
		 * @return {string}
		 */
		get overallProgressText() {
			return __( 'answeredOf', '%1$d of %2$d answered' )
				.replace( '%1$d', state.answeredCount )
				.replace( '%2$d', state.totalQuestions );
		},

		/**
		 * Whether the current question is the first in its section.
		 *
		 * @return {boolean}
		 */
		get isFirstInSection() {
			return state.sectionQuestionIndex === 0;
		},

		/**
		 * Whether the current question is the last in its section.
		 *
		 * @return {boolean}
		 */
		get isLastInSection() {
			if ( ! state.currentSection ) {
				return true;
			}
			return state.sectionQuestionIndex >= state.currentSection.count - 1;
		},

		/**
		 * Label for the section navigation next button.
		 *
		 * @return {string}
		 */
		get sectionNextBtnLabel() {
			return state.isLastInSection
				? __( 'endSection', 'End Section' )
				: __( 'next', 'Next' ) + ' \u2192';
		},

		/**
		 * Segmented progress bar data for the top bar.
		 *
		 * Each segment represents one section with active/completed/locked flags.
		 *
		 * @return {Array}
		 */
		get sectionSegments() {
			return state.sections.map( ( section, idx ) => ( {
				index:     idx,
				label:     section.label,
				active:    idx === state.currentSectionIndex,
				completed: !! state.completedSections[ idx ],
				locked:    !! state.completedSections[ idx ] && idx !== state.currentSectionIndex,
			} ) );
		},

		/**
		 * Per-section answered count for progress tracking.
		 *
		 * @return {Array} Array of {sectionIndex, answered, total}.
		 */
		get sectionProgress() {
			return state.sections.map( ( section, idx ) => {
				const answered = section.questions.filter(
					( q ) => state.answers[ q.id ] !== undefined && state.answers[ q.id ] !== ''
				).length;
				return {
					sectionIndex: idx,
					answered,
					total: section.count,
				};
			} );
		},

		/**
		 * Review questions grouped by section for the results screen.
		 *
		 * @return {Array}
		 */
		get reviewSections() {
			const review = state.reviewQuestions;
			if ( ! review.length ) {
				return [];
			}

			const perSection = state.questionsPerSection || 10;
			const groups     = [];
			let idx          = 0;

			while ( idx < review.length ) {
				const end   = Math.min( idx + perSection, review.length );
				const slice = review.slice( idx, end );
				// Same localized label as the player's section badge — this
				// was a hardcoded English template literal, invisible to the
				// i18n gate because no __() twin existed.
				const correct = slice.filter( ( q ) => q.correct ).length;
				groups.push( {
					label:   fillNums( i18n.sectionLabel || 'Section %d', groups.length + 1 ),
					// "7 of 10 correct" beside the section name, so at 300
					// questions a student sees WHICH sections need review
					// before opening a single card.
					summary: fillNums( i18n.groupCorrectSummary || '%1$d of %2$d correct', correct, slice.length ),
					questions: slice,
				} );
				idx = end;
			}

			return groups;
		},
	},

	// ==============================================================
	// Actions
	// ==============================================================

	actions: {
		/**
		 * Begin the current section (dismiss the intro card).
		 *
		 * Syncs the global currentIndex to the section's first question.
		 */
		beginSection() {
			state.sectionIntroVisible = false;

			if ( state.currentSection ) {
				state.sectionQuestionIndex = 0;
				state.currentIndex = state.currentSection.startIndex;
			}
		},

		/**
		 * Navigate to the previous question within the current section.
		 */
		prevSectionQuestion() {
			if ( state.sectionQuestionIndex > 0 ) {
				state.sectionQuestionIndex--;
				state.currentIndex--;
			}
		},

		/**
		 * Navigate to the next question in the section, or finish the section.
		 * In free mode finishing returns to the picker (no lock — the candidate
		 * may revisit any section until submit); linear mode ends + locks it.
		 */
		nextSectionQuestionOrEnd() {
			if ( state.isLastInSection ) {
				if ( state.isFreeSectionNav ) {
					state.sectionPickerVisible = true;
				} else {
					actions.endSection();
				}
			} else {
				state.sectionQuestionIndex++;
				state.currentIndex++;
			}
		},

		/**
		 * Free-navigation: enter a section chosen from the picker (BC #9993392864).
		 * Reads the clicked section index from the data-wp-each context.
		 */
		enterSection() {
			const ctx = getContext();
			if ( ! ctx || ! ctx.item || typeof ctx.item.index !== 'number' ) {
				return;
			}
			const section = state.sections[ ctx.item.index ];
			if ( ! section ) {
				return;
			}
			state.currentSectionIndex  = ctx.item.index;
			state.sectionQuestionIndex = 0;
			state.currentIndex         = section.startIndex;
			state.sectionIntroVisible  = false;
			state.sectionPickerVisible = false;
		},

		/** Free-navigation: return to the section picker to switch sections. */
		openSectionPicker() {
			state.sectionPickerVisible = true;
		},

		/** Free-navigation: submit the whole exam from the picker. */
		submitFromPicker() {
			actions.submitQuiz();
		},

		/**
		 * End the current section with a confirmation, then move to next
		 * section or auto-submit if this was the last section.
		 */
		async endSection() {
			const confirmed = await window.lrnConfirm( {
				title:        __( 'endSectionTitle', 'End section' ),
				message:      __( 'endSectionConfirm', 'Are you sure you want to end this section? You cannot return to it.' ),
				confirmLabel: __( 'endSectionTitle', 'End section' ),
				destructive:  true,
			} );

			if ( ! confirmed ) {
				return;
			}

			// Lock the current section.
			state.completedSections = {
				...state.completedSections,
				[ state.currentSectionIndex ]: true,
			};

			actions.nextSectionOrSubmit();
		},

		/**
		 * Move to the next section intro, or submit the quiz if all
		 * sections are complete.
		 */
		nextSectionOrSubmit() {
			const nextIndex = state.currentSectionIndex + 1;

			if ( nextIndex >= state.sectionCount ) {
				// All sections done -- auto-submit.
				actions.submitQuiz();
			} else {
				state.currentSectionIndex  = nextIndex;
				state.sectionQuestionIndex = 0;
				state.sectionIntroVisible  = true;
			}
		},
	},
} );
