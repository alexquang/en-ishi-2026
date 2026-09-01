/**
 * Lesson player sidebar — Interactivity API store.
 *
 * Handles sidebar collapse/expand and section accordion behavior.
 * Sidebar state persists in localStorage so it survives page navigations.
 *
 * @package Learnomy
 */
import { store } from '@wordpress/interactivity';

const STORAGE_KEY = 'learnomy_sidebar_collapsed';

// Below this width the curriculum is an off-canvas overlay drawer that sits ON
// TOP of the lesson; above it, a side rail beside the content.
const DRAWER_QUERY = '(max-width: 1023.98px)';

function isDrawerViewport() {
	try {
		return window.matchMedia( DRAWER_QUERY ).matches;
	} catch {
		return false;
	}
}

/**
 * Read the sidebar's initial collapsed state.
 *
 * On the overlay-drawer breakpoints the curriculum covers the lesson, so it
 * ALWAYS starts collapsed on every load — a visitor lands on the content they
 * opened, never a curriculum overlay, and tapping a lesson closes the drawer
 * for the next page. The saved expand/collapse preference only governs the
 * desktop side rail, which sits beside the content and is a lasting layout
 * choice.
 */
function readSidebarState() {
	if ( isDrawerViewport() ) {
		return true;
	}

	try {
		const saved = localStorage.getItem( STORAGE_KEY );
		if ( null !== saved ) {
			return '1' === saved;
		}
	} catch {
		// localStorage unavailable — fall through to the desktop default.
	}

	return false;
}

// The active curriculum item across BOTH lesson-player layouts: `embedded`
// (default, `.lrn-lesson-side__*`) and `focus` (`.lrn-player-*`). Both rails
// mark the current lesson the same way conceptually but with different class
// names, so this behavior keys off both and finds the scroller generically
// rather than hardcoding one layout's markup.
const ACTIVE_ITEM_SELECTOR = '.lrn-lesson-side__item--current, .lrn-player-item--current';

/**
 * The nearest scrollable ancestor of an element — the rail's own scroll region,
 * whatever its class name. Lets the scroll-into-view behavior work for either
 * layout without knowing which one rendered.
 */
function nearestScroller( el ) {
	let node = el.parentElement;
	while ( node && node !== document.body ) {
		const overflowY = getComputedStyle( node ).overflowY;
		if ( ( overflowY === 'auto' || overflowY === 'scroll' ) && node.scrollHeight > node.clientHeight ) {
			return node;
		}
		node = node.parentElement;
	}
	return null;
}

/**
 * Bring the active curriculum item into view WITHIN the rail's own scroller —
 * never by scrolling the page. On a long course the current lesson can sit far
 * down the rail, off-screen on load, so a learner opens the player and can't
 * see where they are (Basecamp #40981). Scrolls the rail's scroll region (not
 * the window), no-ops when the item is already visible so it never fights the
 * learner's own scroll, and is layout-agnostic so it covers embedded + focus.
 */
function scrollActiveLessonIntoView() {
	const current = document.querySelector( ACTIVE_ITEM_SELECTOR );
	if ( ! current ) {
		return;
	}
	const scroller = nearestScroller( current );
	// No scroller, or the rail is collapsed/off-canvas (unmeasurable) — skip;
	// the drawer-open hook re-runs this once the rail is laid out.
	if ( ! scroller || scroller.clientHeight === 0 ) {
		return;
	}
	const sRect = scroller.getBoundingClientRect();
	const iRect = current.getBoundingClientRect();
	if ( iRect.top >= sRect.top && iRect.bottom <= sRect.bottom ) {
		return; // already fully visible — leave the learner's position alone.
	}
	const delta = ( iRect.top - sRect.top ) - ( scroller.clientHeight / 2 - iRect.height / 2 );
	scroller.scrollTop += delta;
}

// Run once the DOM is parsed (script modules are deferred, so this is usually
// immediate) and again whenever a curriculum drawer announces it just opened —
// a drawer that was collapsed at load couldn't be measured until now.
function scheduleScrollActiveIntoView() {
	requestAnimationFrame( scrollActiveLessonIntoView );
}
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', scheduleScrollActiveIntoView, { once: true } );
} else {
	scheduleScrollActiveIntoView();
}
document.addEventListener( 'learnomy:curriculum-opened', scheduleScrollActiveIntoView );

function saveSidebarState( collapsed ) {
	// Only the desktop rail's state is worth remembering; the drawer always
	// reopens closed, so persisting its session toggles would just bleed a
	// mobile choice into the desktop rail.
	if ( isDrawerViewport() ) {
		return;
	}
	try {
		localStorage.setItem( STORAGE_KEY, collapsed ? '1' : '0' );
	} catch {
		// Storage unavailable — silently ignore.
	}
}

store( 'learnomy/lesson-player', {
	state: {
		sidebarCollapsed: readSidebarState(),

		/*
		 * The same fact stated positively, so the mobile stylesheet can be
		 * closed-by-default and OPEN on a class.
		 *
		 * Below 1024px the closed state used to be carried only by
		 * `--collapsed`, which nothing but hydration could add, so the panel
		 * painted open over the lesson on every load until this module ran
		 * (Basecamp 10167960085). A CSS default cannot flash; a JS-applied one
		 * always can. This getter exists so the markup can bind BOTH polarities
		 * from one source of truth rather than two booleans that can disagree.
		 */
		get sidebarOpen() {
			return ! store( 'learnomy/lesson-player' ).state.sidebarCollapsed;
		},
	},

	actions: {
		toggleSidebar() {
			const { state } = store( 'learnomy/lesson-player' );
			state.sidebarCollapsed = ! state.sidebarCollapsed;
			saveSidebarState( state.sidebarCollapsed );

			// Just opened the drawer/rail — the current item may have been
			// unmeasurable while collapsed; announce it so the shared
			// scroll-into-view behavior centers the active lesson now.
			if ( ! state.sidebarCollapsed ) {
				document.dispatchEvent( new CustomEvent( 'learnomy:curriculum-opened' ) );
			}
		},

		/**
		 * Close the OVERLAY drawer, if that is what is currently on screen.
		 *
		 * Shared by Escape and click-outside below. Returns whether it closed
		 * anything so a caller can tell a no-op from a real close.
		 */
		closeDrawer() {
			const { state } = store( 'learnomy/lesson-player' );
			if ( ! isDrawerViewport() || state.sidebarCollapsed ) {
				return false;
			}
			state.sidebarCollapsed = true;
			// Deliberately not persisted: saveSidebarState() no-ops at drawer
			// widths, and the drawer always reopens closed anyway.
			return true;
		},

		toggleSection( event ) {
			const button = event.target.closest( '.lrn-player-section__header' );
			if ( ! button ) {
				return;
			}

			const section  = button.closest( '.lrn-player-section' );
			const itemList = section.querySelector( '.lrn-player-section__items' );
			if ( ! itemList ) {
				return;
			}

			const isExpanded = button.getAttribute( 'aria-expanded' ) === 'true';
			button.setAttribute( 'aria-expanded', isExpanded ? 'false' : 'true' );

			if ( isExpanded ) {
				itemList.setAttribute( 'hidden', '' );
			} else {
				itemList.removeAttribute( 'hidden' );
			}
		},
	},
} );

/*
 * Dismissal for the OVERLAY drawer only.
 *
 * Below 1024px the curriculum is not a rail beside the lesson, it is a panel
 * ON TOP of it — and the only way to close it was the collapse button, so a
 * learner who opened it had one specific target to find again (Basecamp
 * 10184327445). Escape and a tap outside are what anyone expects of a panel
 * covering the page; the course page's own curriculum sheet already offers
 * both, and this is the same gesture on the other surface.
 *
 * Both guard on isDrawerViewport() inside closeDrawer(): on desktop the rail
 * sits BESIDE the content and its expanded/collapsed state is a saved layout
 * preference, so Escape or a stray click must never collapse it.
 */
const player = () => store( 'learnomy/lesson-player' );

document.addEventListener( 'keydown', ( event ) => {
	if ( 'Escape' === event.key ) {
		player().actions.closeDrawer();
	}
} );

document.addEventListener( 'click', ( event ) => {
	const target = event.target;
	if ( ! target || ! target.closest ) {
		return;
	}
	// A click inside the drawer is navigation, not dismissal; and the toggle
	// owns its own state — closing here too would fight it and leave the
	// drawer stuck shut on the tap that was meant to open it.
	if ( target.closest( '.lrn-player-sidebar' ) || target.closest( '[data-wp-on--click="actions.toggleSidebar"]' ) ) {
		return;
	}
	player().actions.closeDrawer();
} );
