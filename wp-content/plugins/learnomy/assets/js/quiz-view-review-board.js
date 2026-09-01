/**
 * Review Board quiz store extension -- split-panel question list + detail.
 *
 * This module EXTENDS the base learnomy/quiz store (quiz-view.js) by merging
 * question status tracking, flagging, filtering, and mobile bottom sheet
 * actions. Desktop: two-column grid. Mobile: bottom sheet for question list.
 *
 * @package Learnomy
 */

import { store, getContext } from '@wordpress/interactivity';

// Inherit the same i18n object injected by Template_Loader for the base store.
const i18n = ( window.lrnQuiz && window.lrnQuiz.i18n ) ? window.lrnQuiz.i18n : {};
const __ = ( key, fallback ) => i18n[ key ] || fallback;

/** Maximum title length before truncation in the sidebar list. */
const TITLE_MAX = 50;

const { state, actions } = store( 'learnomy/quiz', {
	state: {
		/**
		 * Map of question IDs the student has flagged for review, DERIVED from
		 * the shared base `state.flagged` array so flags hydrate on resume and
		 * persist through persistRunnerState (BC #10056240860). Read-only view.
		 *
		 * @type {Object}
		 */
		get flaggedQuestions() {
			const map = {};
			( state.flagged || [] ).forEach( ( id ) => { map[ id ] = true; } );
			return map;
		},

		/**
		 * Current list filter: 'all' | 'unanswered' | 'flagged'.
		 *
		 * @type {string}
		 */
		listFilter: 'all',

		// Per-category collapse state for the grouped navigator (BC #9993397735),
		// keyed by category name. Empty = all groups expanded.
		collapsedCategories: {},

		/**
		 * Whether the mobile bottom sheet is expanded.
		 *
		 * @type {boolean}
		 */
		bottomSheetOpen: false,

		// -----------------------------------------------------------
		// Computed: progress
		// -----------------------------------------------------------

		/**
		 * Progress text for the top bar (e.g. "5 of 20 answered").
		 *
		 * @return {string}
		 */
		get rbProgressText() {
			return `${ state.answeredCount } of ${ state.totalQuestions } answered`;
		},

		// -----------------------------------------------------------
		// Computed: question statuses
		// -----------------------------------------------------------

		/**
		 * Full question list enriched with status data for the sidebar.
		 *
		 * @return {Array}
		 */
		get questionStatuses() {
			return state.questions.map( ( question, index ) => {
				const answerVal  = state.answers[ question.id ];
				const isAnswered = answerVal !== undefined && answerVal !== '';
				const isFlagged  = !! state.flaggedQuestions[ question.id ];
				const isCurrent  = index === state.currentIndex;

				// Truncate title for sidebar display.
				const title      = question.title || '';
				const titleShort = title.length > TITLE_MAX
					? title.substring( 0, TITLE_MAX ) + '\u2026'
					: title;

				// Determine which Lucide icon to show.
				let statusIcon = 'circle'; // unanswered.
				if ( isCurrent ) {
					statusIcon = 'disc';
				} else if ( isFlagged ) {
					statusIcon = 'flag';
				} else if ( isAnswered ) {
					statusIcon = 'check-circle';
				}

				return {
					index,
					id:           question.id,
					label:        `${ index + 1 }`,
					titleShort,
					categoryName: question.category_name || '',
					isAnswered,
					isFlagged,
					isCurrent,
					statusIcon,
				};
			} );
		},

		/**
		 * Filtered question list based on the active filter.
		 *
		 * @return {Array}
		 */
		get filteredQuestionList() {
			const all = state.questionStatuses;

			if ( state.listFilter === 'unanswered' ) {
				return all.filter( ( q ) => ! q.isAnswered );
			}

			if ( state.listFilter === 'flagged' ) {
				return all.filter( ( q ) => q.isFlagged );
			}

			return all;
		},

		/**
		 * Whether to render the navigator as collapsible per-category groups
		 * (BC #9993397735) — only when the quiz opts into grouping AND there are
		 * at least two categories to separate.
		 *
		 * @return {boolean}
		 */
		get showGroupedList() {
			if ( ! state.groupByCategory ) {
				return false;
			}
			const names = new Set( state.questionStatuses.map( ( q ) => q.categoryName || '' ) );
			return names.size > 1;
		},

		/**
		 * The filtered question list grouped into collapsible per-subject
		 * sections with an answered/total count, so a student jumps to a subject
		 * and sees only its questions (BC #9993397735). Questions arrive
		 * category-contiguous when grouping is on.
		 *
		 * @return {Array}
		 */
		get groupedQuestionList() {
			const groups = [];
			let prevName = null;
			state.filteredQuestionList.forEach( ( q ) => {
				const name = q.categoryName || __( 'generalCategory', 'General' );
				if ( name !== prevName ) {
					groups.push( { name, items: [], total: 0, answered: 0, collapsed: !! state.collapsedCategories[ name ] } );
					prevName = name;
				}
				const group = groups[ groups.length - 1 ];
				group.items.push( q );
				group.total += 1;
				if ( q.isAnswered ) {
					group.answered += 1;
				}
			} );
			groups.forEach( ( g ) => {
				g.countLabel = `${ g.answered }/${ g.total }`;
			} );
			return groups;
		},

		// -----------------------------------------------------------
		// Computed: filter states (for active pill highlighting)
		// -----------------------------------------------------------

		get isFilterAll() {
			return state.listFilter === 'all';
		},

		get isFilterUnanswered() {
			return state.listFilter === 'unanswered';
		},

		get isFilterFlagged() {
			return state.listFilter === 'flagged';
		},

		// -----------------------------------------------------------
		// Computed: flagging
		// -----------------------------------------------------------

		/**
		 * Whether the currently visible question is flagged.
		 *
		 * @return {boolean}
		 */
		get isCurrentFlagged() {
			if ( ! state.currentQuestion ) {
				return false;
			}
			return !! state.flaggedQuestions[ state.currentQuestion.id ];
		},

		// -----------------------------------------------------------
		// Computed: bottom sheet
		// -----------------------------------------------------------

		/**
		 * Label for the mobile bottom sheet peek bar.
		 *
		 * @return {string}
		 */
		get bottomSheetLabel() {
			const flagCount = Object.keys( state.flaggedQuestions ).length;
			if ( flagCount > 0 ) {
				return `${ state.answeredCount }/${ state.totalQuestions } answered \u00b7 ${ flagCount } flagged`;
			}
			return `${ state.answeredCount }/${ state.totalQuestions } answered`;
		},
	},

	// ==============================================================
	// Actions
	// ==============================================================

	actions: {
		/**
		 * Jump to a question from the sidebar list.
		 *
		 * Reads the question index from the list item's context.
		 */
		jumpToListItem() {
			const ctx = getContext();
			if ( ctx.item && typeof ctx.item.index === 'number' ) {
				actions.jumpToQuestion( ctx.item.index );

				// Close bottom sheet on mobile after selection.
				state.bottomSheetOpen = false;
			}
		},

		/**
		 * Collapse / expand a category group in the grouped navigator so a
		 * student can focus one subject at a time (BC #9993397735).
		 */
		toggleCategoryGroup() {
			const ctx  = getContext();
			const name = ctx.cat && ctx.cat.name;
			if ( ! name ) {
				return;
			}
			// Reassign the map so the Interactivity store picks up the change.
			const next = { ...state.collapsedCategories };
			next[ name ] = ! next[ name ];
			state.collapsedCategories = next;
		},

		/**
		 * Toggle the flagged state on the current question.
		 */
		flagQuestion() {
			if ( ! state.currentQuestion ) {
				return;
			}

			// Toggle on the shared base `state.flagged` array (single source) and
			// persist, so the flag survives a mid-exam refresh (BC #10056240860).
			const id = state.currentQuestion.id;
			state.flagged = state.flagged.includes( id )
				? state.flagged.filter( ( f ) => f !== id )
				: [ ...state.flagged, id ];
			actions.persistRunnerState();
		},

		/**
		 * Set list filter to 'all'.
		 */
		setFilterAll() {
			state.listFilter = 'all';
		},

		/**
		 * Set list filter to 'unanswered'.
		 */
		setFilterUnanswered() {
			state.listFilter = 'unanswered';
		},

		/**
		 * Set list filter to 'flagged'.
		 */
		setFilterFlagged() {
			state.listFilter = 'flagged';
		},

		/**
		 * Toggle the mobile bottom sheet open/closed.
		 */
		toggleBottomSheet() {
			state.bottomSheetOpen = ! state.bottomSheetOpen;
		},
	},
} );
