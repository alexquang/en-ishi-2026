/**
 * Conversational quiz store — extends the base learnomy/quiz store
 * with auto-advance, transitions, and keyboard shortcuts.
 *
 * Auto-advance: selecting an MC answer triggers a 500ms delay, then
 * advances to the next question (or submits if last). Text/essay
 * questions show a manual "Continue" button instead.
 *
 * Keyboard: number keys 1-4 select the corresponding answer option.
 *
 * @package Learnomy
 */

import { store, getContext } from '@wordpress/interactivity';

const { state, actions } = store( 'learnomy/quiz', {
	state: {
		/**
		 * Milliseconds to wait after answer selection before advancing.
		 *
		 * @type {number}
		 */
		autoAdvanceDelay: 500,

		/**
		 * True while the card fade-out transition is playing.
		 *
		 * @type {boolean}
		 */
		isTransitioning: false,

		/**
		 * Width string for the thin progress bar at the top of the viewport.
		 *
		 * @return {string} CSS width value, e.g. '33.3%'.
		 */
		get progressBarWidth() {
			if ( state.totalQuestions === 0 ) {
				return '0%';
			}
			return ( ( state.currentIndex + 1 ) / state.totalQuestions * 100 ) + '%';
		},

		/**
		 * Whether the current question requires text input (no auto-advance).
		 *
		 * @return {boolean}
		 */
		get isTextInputQuestion() {
			return [ 'short_answer', 'fill_blank', 'essay' ].includes( state.currentQuestionType );
		},
	},

	actions: {
		/**
		 * Handle MC/true-false answer click: record answer, animate, auto-advance.
		 *
		 * 1. Record the selected answer in state.answers.
		 * 2. Set isTransitioning = true (triggers CSS fade-out).
		 * 3. After autoAdvanceDelay ms:
		 *    - Last question: submit the quiz.
		 *    - Otherwise: advance to next question and clear transition.
		 */
		selectAndAdvance() {
			const ctx = getContext();
			if ( ! state.currentQuestion || ! ctx.item ) {
				return;
			}

			// How an answer is STORED belongs to the base store — it owns the
			// mc_multi set-toggle. This used to write `ctx.item.id` directly,
			// which meant a multi-select kept only the last option clicked.
			actions.recordCurrentChoice();

			// A multi-select has no "done" signal: the student is still
			// choosing. Auto-advancing on the first tick made mc_multi
			// unanswerable in this layout — one click and the question was gone,
			// capping the score at one option's worth of credit (Basecamp
			// 10134139919). They advance with Continue instead.
			if ( state.isCurrentMultiSelect ) {
				return;
			}

			actions.advanceAfterDelay();
		},

		/**
		 * Play the fade-out, then move on (or submit if this was the last).
		 *
		 * Shared by auto-advance and the multi-select Continue button so both
		 * paths get the same transition and the same last-question handling.
		 */
		advanceAfterDelay() {
			state.isTransitioning = true;

			setTimeout( () => {
				if ( state.isLastQuestion ) {
					actions.submitQuiz();
				} else {
					state.currentIndex++;
					state.isTransitioning = false;
				}
			}, state.autoAdvanceDelay );
		},

		/**
		 * Manual advance for a multi-select question, once the student says so.
		 */
		continueFromMultiSelect() {
			actions.advanceAfterDelay();
		},

		/**
		 * Manual advance for text/essay questions (no auto-advance trigger).
		 */
		continueFromText() {
			if ( state.isLastQuestion ) {
				actions.submitQuiz();
			} else {
				state.currentIndex++;
			}
		},

		/**
		 * Keyboard shortcut handler — number keys 1-4 select an answer.
		 *
		 * Bound to the document keydown event after quiz start.
		 *
		 * @param {KeyboardEvent} event
		 */
		handleKeyboard( event ) {
			// Ignore when typing in an input or textarea.
			const tag = event.target.tagName;
			if ( tag === 'INPUT' || tag === 'TEXTAREA' ) {
				return;
			}

			// Ignore if not started, already submitted, or transitioning.
			if ( ! state.started || state.submitted || state.isTransitioning ) {
				return;
			}

			// Ignore for text-input question types.
			if ( state.isTextInputQuestion ) {
				return;
			}

			const num = parseInt( event.key, 10 );
			if ( isNaN( num ) || num < 1 || num > state.currentAnswers.length ) {
				return;
			}

			const answer = state.currentAnswers[ num - 1 ];
			if ( ! answer ) {
				return;
			}

			// Storage rule lives in the base store, so the number keys and the
			// click path cannot disagree about what a multi-select answer is.
			actions.recordChoice( answer.id );

			// Same as clicking: a multi-select does NOT advance, so the number
			// keys can tick several options in a row.
			if ( state.isCurrentMultiSelect ) {
				return;
			}

			actions.advanceAfterDelay();
		},
	},

	callbacks: {
		/**
		 * Attach keyboard listener once the store is initialized.
		 */
		initKeyboard() {
			document.addEventListener( 'keydown', ( event ) => {
				actions.handleKeyboard( event );
			} );
		},
	},
} );
