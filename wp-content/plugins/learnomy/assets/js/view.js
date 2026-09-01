/**
 * Learnomy frontend — main Interactivity API entry point.
 *
 * This module bootstraps shared UI behaviors (dark mode toggle, toast
 * notifications) that are used across all frontend templates. Feature-
 * specific stores (catalog, quiz, progress, etc.) are loaded separately.
 *
 * @package Learnomy
 */
import { store, getContext } from '@wordpress/interactivity';

const { state, actions } = store( 'learnomy/app', {
	state: {
		toastMessage: '',
		toastType: 'info',
		toastVisible: false,
	},

	actions: {
		showToast() {
			const ctx = getContext();
			state.toastMessage = ctx.message || '';
			state.toastType    = ctx.type || 'info';
			state.toastVisible = true;

			setTimeout( () => {
				state.toastVisible = false;
			}, 3000 );
		},

		dismissToast() {
			state.toastVisible = false;
		},

		// Only reachable when the site owner has opted into Learnomy's standalone
		// dark mode (see \Learnomy\standalone_dark_mode()); by default the theme
		// owns the colour scheme and this button is not rendered at all.
		toggleDarkMode() {
			document.body.classList.toggle( 'lrn-dark' );

			const isDark = document.body.classList.contains( 'lrn-dark' );

			// Persist preference via the auth notification-prefs endpoint
			// (which is the user-preferences write surface for the plugin).
			// `dark_mode` was previously dropped silently because it wasn't
			// in the endpoint's args allowlist — the toggle worked visually
			// but never survived a page reload. The endpoint now accepts
			// dark_mode (see Auth_Controller register_routes /me/notifications
			// args list). The .catch() keeps the visual toggle responsive
			// even when the persistence call fails (e.g. offline).
			wp.apiFetch( {
				path: '/learnomy/v1/auth/me/notifications',
				method: 'PATCH',
				data: { dark_mode: isDark },
			} ).catch( () => {} );
		},
	},
} );

/*
 * Lesson time-tracking heartbeat lives in `progress-view.js` (one
 * implementation, runs on lesson pages with the proper completion-rule
 * Interactivity store). The 30-second heartbeat that used to live here
 * (calling `/learnomy/v1/progress/time`) was BOTH duplicate AND broken —
 * the route was never registered (only `/lessons/{id}/progress` exists),
 * so every fire returned 404 which the .catch() silently swallowed.
 * Removing it eliminates the noise + the would-be double-counting if
 * someone "fixed" it by routing both heartbeats to the same endpoint.
 */
