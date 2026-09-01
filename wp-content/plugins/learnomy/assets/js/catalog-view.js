import { store, getContext, getElement } from '@wordpress/interactivity';

// Debounce timer for the search-as-you-type autocomplete (BC #10043289391).
let suggestTimer;

const { state, actions } = store( 'learnomy/catalog', {
	state: {
		view: 'grid',
		sortBy: 'latest',
		searchQuery: '',
		selectedCategory: null,
		courses: [],
		loading: false,
		cursor: null,
		hasMore: true,
		// Typeahead autocomplete: matching courses shown in a dropdown below the
		// search input as the customer types (BC #10043289391).
		suggestions: [],
		suggestOpen: false,
		suggestLoading: false,
		// Keyboard-selected suggestion for combobox arrow-nav (a11y, card 10062747064).
		// Index into `suggestions` (-1 = none); `activeSuggestionUrl` drives the
		// per-option highlight since data-wp-each exposes no index.
		activeSuggestion: -1,
		activeSuggestionUrl: '',
		// Mobile filter drawer: collapsed by default so results aren't pushed a
		// full screenful down on a phone. Ignored on desktop (CSS shows the rail).
		filtersOpen: false,

		get filtersExpanded() {
			return state.filtersOpen;
		},

		get isGridView() {
			return state.view === 'grid';
		},

		get isListView() {
			return state.view === 'list';
		},

		get isLoading() {
			return state.loading;
		},

		get loadMoreLabel() {
			// Strings are localised via wp_localize_script (lrnCatalog.i18n).
			const i18n = ( window.lrnCatalog && window.lrnCatalog.i18n ) || {};
			return state.loading
				? ( i18n.loading    || 'Loading…' )
				: ( i18n.loadMore   || 'Load More Courses' );
		},

		get filteredCourses() {
			const ctx = getContext();
			const query = state.searchQuery.toLowerCase().trim();
			const category = state.selectedCategory;

			let list = ctx.serverCourses && state.courses.length === 0
				? ctx.serverCourses
				: state.courses;

			if ( query ) {
				list = list.filter( ( course ) =>
					course.title.toLowerCase().includes( query ) ||
					course.excerpt.toLowerCase().includes( query )
				);
			}

			if ( category ) {
				list = list.filter( ( course ) =>
					course.category_ids && course.category_ids.includes( category )
				);
			}

			return list;
		},

		// Autocomplete: show the dropdown only when open and there is a query.
		get suggestVisible() {
			return state.suggestOpen && state.searchQuery.trim().length > 0;
		},

		get suggestEmpty() {
			return state.suggestions.length === 0;
		},

		get suggestStatusLabel() {
			const i18n = ( window.lrnCatalog && window.lrnCatalog.i18n ) || {};
			return state.suggestLoading
				? ( i18n.searching || 'Searching…' )
				: ( i18n.noResults || 'No matching courses' );
		},

		// True for the option the keyboard has landed on (drives aria-selected +
		// the visual highlight). Evaluated per-item inside the data-wp-each.
		get isSuggestActive() {
			const ctx = getContext();
			return state.activeSuggestionUrl !== '' && !! ctx && !! ctx.sug && ctx.sug.url === state.activeSuggestionUrl;
		},

		// DOM id of the keyboard-active option, for the combobox input's
		// aria-activedescendant (empty when none). [card 10062747064]
		get activeSuggestionDomId() {
			const i = state.activeSuggestion;
			return ( i >= 0 && state.suggestions[ i ] ) ? ( state.suggestions[ i ].optId || '' ) : '';
		},
	},

	actions: {
		// Toggle the mobile filter drawer. Desktop ignores this (the rail is
		// always visible via CSS); on a phone the filters start collapsed so the
		// course grid is the first thing in view.
		toggleFilters() {
			state.filtersOpen = ! state.filtersOpen;
		},

		setGridView() {
			state.view = 'grid';
			actions.persistView( 'grid' );
		},

		setListView() {
			state.view = 'list';
			actions.persistView( 'list' );
		},

		// Remember the grid/list choice so it survives the full-page reload that
		// a search / sort / filter triggers (navigateWithFilters). Without this
		// the store re-initialises to the default 'grid' on every search, so a
		// customer browsing in List view was kicked back to Grid as soon as they
		// searched (Basecamp #9972665769).
		persistView( view ) {
			try {
				window.localStorage.setItem( 'learnomy_catalog_view', view );
			} catch ( e ) {
				// Storage blocked (private mode / quota) — degrade silently.
			}
		},

		// Filter / sort changes navigate to the catalog URL with query params
		// so the server-rendered grid (templates/course-archive.php) re-runs
		// `Course::search_catalog()` and emits the correctly-filtered cards.
		// The original implementation tried to update `state.courses` and
		// expected a `data-wp-each` reactive grid to re-render — but the
		// archive template renders via PHP foreach, so JS state changes have
		// no DOM target and the customer sees the unfiltered grid (Basecamp
		// card #9861771320). Server-side filtering already works; we just
		// need the page to actually use it.
		setSort() {
			const el = getElement();
			const value = el.ref.value;
			if ( value === state.sortBy ) {
				return;
			}
			actions.navigateWithFilters( { sort: value } );
		},

		setCategory() {
			const el = getElement();
			actions.navigateWithFilters( { cat: el.ref.value || '' } );
		},

		// Toggle a registered custom facet (Facets::register()). The
		// Facet_Renderer emits pill <button>s (click) and checkbox/radio
		// <input>s (change) carrying data-facet-param / data-facet-value /
		// data-facet-multi. Like setSort/setCategory, this navigates to the
		// catalog URL with the facet's GET param updated so the server
		// pipeline (Facets::resolve_active_from_request → build_clauses →
		// Course::search_catalog) re-runs and returns the filtered grid. The
		// store can't filter client-side because the archive grid is rendered
		// by PHP foreach, not a reactive data-wp-each. Without this action the
		// bindings reference a non-existent handler and the controls are inert
		// (WP IAPI logs an "action not found" warning).
		toggleFacet() {
			const el = getElement();
			const node = el.ref;
			const param = node.getAttribute( 'data-facet-param' );
			const value = node.getAttribute( 'data-facet-value' );
			if ( ! param || value === null ) {
				return;
			}
			const multi = node.getAttribute( 'data-facet-multi' ) === '1';

			// Determine whether the clicked option is now selected. Inputs
			// (checkbox/radio) expose `checked`; pill buttons don't, so fall
			// back to toggling against the option's current pressed state.
			let willSelect;
			if ( typeof node.checked === 'boolean' && node.type !== 'button' ) {
				willSelect = node.checked;
			} else {
				willSelect = node.getAttribute( 'aria-pressed' ) !== 'true';
			}

			// Read the current selection for this param straight from the URL
			// so we don't depend on per-facet store state.
			const url = new URL( window.location.href );
			let current = url.searchParams.getAll( param );
			// A bracketed multi param (`param[]`) and a bare `param` can both
			// appear depending on the noscript form; normalise to bare reads.
			if ( current.length === 0 ) {
				current = url.searchParams.getAll( `${ param }[]` );
			}

			let next;
			if ( multi ) {
				const set = new Set( current );
				if ( willSelect ) {
					set.add( value );
				} else {
					set.delete( value );
				}
				next = Array.from( set );
			} else {
				next = willSelect ? [ value ] : [];
			}

			// Rebuild the URL: drop every prior copy of this param (both
			// bracketed and bare forms), then re-add the new selection.
			url.searchParams.delete( param );
			url.searchParams.delete( `${ param }[]` );
			next.forEach( ( v ) => {
				url.searchParams.append( multi ? `${ param }[]` : param, v );
			} );
			url.searchParams.delete( 'cursor' );
			url.searchParams.delete( 'paged' );
			window.location.href = url.toString();
		},

		search() {
			// Track the query for the client-side filter getter, but DO NOT
			// navigate on every keystroke. The search inputs live inside a
			// native <form method="get" role="search">, so pressing Enter
			// submits and the server re-renders the filtered grid. The previous
			// debounced (500ms) full window.location reload fired mid-word, which
			// dropped input focus and made multi-character search terms
			// impossible to type (Basecamp #10042899710).
			state.searchQuery = getElement().ref.value;
			// Typing invalidates any keyboard-selected suggestion.
			state.activeSuggestion = -1;
			state.activeSuggestionUrl = '';

			// Show a live typeahead dropdown of matching course names so the
			// customer gets immediate feedback instead of a blank wait until
			// Enter (BC #10043289391). Debounced so it doesn't fire per keystroke.
			const query = state.searchQuery.trim();
			if ( suggestTimer ) {
				clearTimeout( suggestTimer );
			}
			if ( query.length < 2 ) {
				state.suggestions = [];
				state.suggestOpen = false;
				return;
			}
			state.suggestOpen = true;
			suggestTimer = setTimeout( () => {
				actions.fetchSuggestions( query );
			}, 250 );
		},

		// Keyboard navigation for the search combobox (a11y / WCAG 2.1.1, card
		// 10062747064): Arrow keys move the active suggestion, Enter opens it,
		// Escape closes the dropdown. Previously the typeahead was mouse-only.
		searchKeydown( event ) {
			const list = state.suggestions;
			if ( ! state.suggestVisible || ! list.length ) {
				if ( 'Escape' === event.key ) {
					state.suggestOpen = false;
				}
				return;
			}
			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					state.activeSuggestion = state.activeSuggestion < list.length - 1 ? state.activeSuggestion + 1 : 0;
					state.activeSuggestionUrl = list[ state.activeSuggestion ].url;
					break;
				case 'ArrowUp':
					event.preventDefault();
					state.activeSuggestion = state.activeSuggestion > 0 ? state.activeSuggestion - 1 : list.length - 1;
					state.activeSuggestionUrl = list[ state.activeSuggestion ].url;
					break;
				case 'Enter':
					if ( state.activeSuggestion >= 0 && list[ state.activeSuggestion ] ) {
						event.preventDefault();
						window.location.href = list[ state.activeSuggestion ].url;
					}
					break;
				case 'Escape':
					state.suggestOpen = false;
					state.activeSuggestion = -1;
					state.activeSuggestionUrl = '';
					break;
				default:
					break;
			}
		},

		// Fetch up to 6 matching courses for the autocomplete dropdown. Reuses
		// the same /courses?search= endpoint the full search submits to, so the
		// suggestions and the results page always agree.
		async fetchSuggestions( query ) {
			state.suggestLoading = true;
			try {
				const response = await wp.apiFetch( {
					path: `/learnomy/v1/courses?search=${ encodeURIComponent( query ) }&limit=6`,
				} );
				// Guard against a stale response landing after the box moved on.
				if ( query !== state.searchQuery.trim() ) {
					return;
				}
				const template = ( window.lrnCatalog && window.lrnCatalog.courseUrlTemplate ) || '';
				const items    = Array.isArray( response.data ) ? response.data : [];
				state.suggestions = items.map( ( course, index ) => ( {
					title: course.title,
					url:   template ? template.replace( 'lrnslugtoken', encodeURIComponent( course.slug || '' ) ) : '#',
					// Stable per-option DOM id so the input's aria-activedescendant can
					// point at the active option (screen-reader announces it on Arrow). [card 10062747064]
					optId: 'lrn-suggest-opt-' + index,
				} ) );
			} catch ( e ) {
				state.suggestions = [];
			} finally {
				state.suggestLoading = false;
			}
		},

		// Reopen the dropdown when the field regains focus with a live query.
		openSuggestions() {
			if ( state.searchQuery.trim().length >= 2 && state.suggestions.length > 0 ) {
				state.suggestOpen = true;
			}
		},

		// Close on blur — deferred so a click on a suggestion registers first.
		hideSuggestions() {
			setTimeout( () => {
				state.suggestOpen = false;
			}, 150 );
		},

		// Build the next URL from the current location plus any params we want
		// to override, then reload. Empty / null values are removed from the
		// URL so e.g. choosing "All Categories" doesn't leave a dangling cat=.
		navigateWithFilters( overrides ) {
			const url = new URL( window.location.href );
			Object.keys( overrides ).forEach( ( key ) => {
				const value = overrides[ key ];
				if ( value === '' || value === null || value === undefined ) {
					url.searchParams.delete( key );
				} else {
					url.searchParams.set( key, value );
				}
			} );
			// Drop any cursor/page param when filters change — start over.
			url.searchParams.delete( 'cursor' );
			url.searchParams.delete( 'paged' );
			window.location.href = url.toString();
		},

		async loadMore() {
			if ( state.loading || ! state.hasMore ) {
				return;
			}

			await actions.fetchCourses( false );
		},

		async fetchCourses( replace = true ) {
			state.loading = true;

			// Seed from the current URL so registered catalog facets (each with
			// its own query param) ride along on cursor pages — without this,
			// load-more dropped every active facet (page 1 filtered, page 2+ not).
			// We strip only the keys we set canonically below; unknown facet
			// params survive and the REST endpoint resolves them server-side.
			const params = new URLSearchParams( window.location.search );
			[ 'limit', 'sort', 'search', 'category', 'cat', 'cursor', 'page', 'paged' ].forEach(
				( key ) => params.delete( key )
			);
			params.set( 'limit', 20 );
			params.set( 'sort', state.sortBy );

			if ( state.searchQuery.trim() ) {
				params.set( 'search', state.searchQuery.trim() );
			}

			if ( state.selectedCategory ) {
				params.set( 'category', state.selectedCategory );
			}

			if ( ! replace && state.cursor ) {
				params.set( 'cursor', state.cursor );
			}

			try {
				// The REST base controller returns `{ data: [...], meta: {...} }`
				// (SDK envelope). Use that shape consistently — older
				// revisions of this file assumed `{ courses, next_cursor }`
				// which silently returned undefined on every fetch.
				const response = await wp.apiFetch( {
					path: `/learnomy/v1/courses?${ params.toString() }`,
				} );

				const items  = Array.isArray( response.data ) ? response.data : [];
				const cursor = response.meta && response.meta.cursor_next ? response.meta.cursor_next : null;

				if ( replace ) {
					state.courses = items;
				} else {
					state.courses = [ ...state.courses, ...items ];
				}

				state.cursor = cursor;
				state.hasMore = !! cursor;
			} finally {
				state.loading = false;
			}
		},

		// URL sync so deep-links and browser back preserve filters.
		applyInitialFilters() {
			const url      = new URL( window.location.href );
			const search   = url.searchParams.get( 's' ) || '';
			const category = url.searchParams.get( 'cat' ) || '';
			const sort     = url.searchParams.get( 'sort' ) || 'latest';

			if ( search ) {
				state.searchQuery = search;
			}
			if ( category ) {
				state.selectedCategory = category;
			}
			if ( sort ) {
				state.sortBy = sort;
			}
		},
	},

	callbacks: {
		onInit() {
			const ctx = getContext() || {};

			if ( ctx.initialCourses && ctx.initialCourses.length > 0 ) {
				state.courses = ctx.initialCourses;
				state.cursor = ctx.initialCursor || null;
				state.hasMore = !! ctx.initialCursor;
			}

			if ( ctx.initialSort ) {
				state.sortBy = ctx.initialSort;
			}

			if ( ctx.initialView ) {
				state.view = ctx.initialView;
			}

			// The customer's own grid/list choice (saved by persistView) wins
			// over the server default, so it survives the search/sort reload
			// (Basecamp #9972665769).
			try {
				const savedView = window.localStorage.getItem( 'learnomy_catalog_view' );
				if ( savedView === 'grid' || savedView === 'list' ) {
					state.view = savedView;
				}
			} catch ( e ) {
				// Storage blocked — keep the server/default view.
			}

			// Always sync state from the current URL — the archive template
			// renders the SELECT/SEARCH inputs from the URL query params, so
			// the JS store has to match or `setSort` thinks the user picked
			// the option that's already active and short-circuits without
			// navigating. (Basecamp #9925400298 — "Newest First filter not
			// working".)
			const url = new URL( window.location.href );
			const urlSort = url.searchParams.get( 'sort' );
			if ( urlSort ) {
				state.sortBy = urlSort;
			}
			const urlSearch = url.searchParams.get( 's' );
			if ( urlSearch ) {
				state.searchQuery = urlSearch;
			}
			const urlCat = url.searchParams.get( 'cat' );
			if ( urlCat ) {
				state.selectedCategory = urlCat;
			}
		},
	},
} );
