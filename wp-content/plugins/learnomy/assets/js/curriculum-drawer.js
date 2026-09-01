/**
 * Mobile curriculum drawer — Interactivity API store.
 *
 * Backs templates/partials/curriculum-drawer.php: a slide-up bottom sheet
 * that gives mobile visitors quick access to the course curriculum without
 * scrolling to the inline section. Desktop hides the toggle via CSS, so the
 * store is inert there.
 *
 * @package Learnomy
 */
import { store } from '@wordpress/interactivity';

const { state } = store( 'learnomy/curriculum', {
	state: {
		drawerOpen: false,
	},

	actions: {
		openDrawer() {
			state.drawerOpen = true;
			// Lock the page behind the sheet so only the sheet scrolls.
			document.body.style.overflow = 'hidden';
		},

		closeDrawer() {
			state.drawerOpen = false;
			document.body.style.overflow = '';
		},
	},
} );

// Close on Escape while the sheet is open — a dialog affordance the
// backdrop and close button otherwise provide.
document.addEventListener( 'keydown', ( event ) => {
	if ( 'Escape' === event.key && state.drawerOpen ) {
		state.drawerOpen = false;
		document.body.style.overflow = '';
	}
} );
