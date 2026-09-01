/**
 * Instructor Application — Interactivity API store.
 *
 * Handles form submission busy state and inline client-side validation
 * feedback on the /become-instructor/ page.  Server-side validation errors
 * are surfaced via the #lrn-instructor-form-errors live region already
 * rendered in the template; this store toggles aria-busy on the submit
 * button so screen readers announce the in-progress state.
 *
 * Store name: learnomy/instructor-application
 *
 * @package Learnomy
 */
import { store, getContext } from '@wordpress/interactivity';

store( 'learnomy/instructor-application', {
	state: {
		/** Whether the form is currently submitting. */
		submitting: false,
	},

	actions: {
		/**
		 * Handle form submission: set aria-busy on the submit button.
		 *
		 * The form uses a standard POST (no REST) so the browser navigates
		 * away on success.  We only need to set the busy state — the browser
		 * will reset it on navigation or when the server returns the page with
		 * a flash error.
		 *
		 * @param {Event} event - The submit event.
		 */
		onSubmit( event ) {
			const form      = event.target;
			const submitBtn = form.querySelector( 'button[type="submit"]' );

			if ( submitBtn ) {
				submitBtn.setAttribute( 'aria-busy', 'true' );
				submitBtn.setAttribute( 'disabled', '' );
			}
		},
	},
} );
