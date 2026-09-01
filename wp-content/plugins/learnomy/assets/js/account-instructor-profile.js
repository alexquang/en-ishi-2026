/**
 * Instructor profile form — saves headline / bio / expertise / links via REST
 * (POST learnomy/v1/account/instructor-profile). No admin-ajax; REST-only.
 */
( function () {
	'use strict';

	var form = document.getElementById( 'lrn-instructor-profile-form' );
	var cfg  = window.lrnInstructorProfile;
	if ( ! form || ! cfg ) {
		return;
	}

	var statusEl = form.querySelector( '.lrn-account-form__status' );
	var btn      = form.querySelector( 'button[type="submit"]' );

	function showStatus( message, ok ) {
		if ( ! statusEl ) {
			return;
		}
		statusEl.textContent = message;
		statusEl.hidden = false;
		statusEl.classList.toggle( 'is-success', !! ok );
	}

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var payload = {
			headline:       form.headline.value,
			bio:            form.bio.value,
			expertise:      form.expertise.value,
			website_url:    form.website_url.value,
			twitter_handle: form.twitter_handle.value,
			linkedin_url:   form.linkedin_url.value
		};

		// Forward any custom account fields (name="learnomy_field_*") so the
		// server can persist them. This payload is built by hand rather than
		// from FormData, so a field rendered through learnomy_account_fields
		// would otherwise be drawn on the form and silently dropped on save.
		form.querySelectorAll( '[name^="learnomy_field_"]' ).forEach( function ( el ) {
			if ( el.type === 'checkbox' ) {
				payload[ el.name ] = el.checked ? '1' : '';
				return;
			}
			payload[ el.name ] = el.value;
		} );

		btn.disabled = true;
		btn.setAttribute( 'aria-busy', 'true' );

		fetch( cfg.restUrl, {
			method:      'POST',
			credentials: 'include',
			headers:     {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   cfg.nonce
			},
			body: JSON.stringify( payload )
		} ).then( function ( response ) {
			return response.json().then( function ( body ) {
				return { ok: response.ok, body: body };
			} );
		} ).then( function ( result ) {
			btn.disabled = false;
			btn.removeAttribute( 'aria-busy' );

			if ( ! result.ok ) {
				var errMsg = ( result.body && result.body.error && result.body.error.message ) || cfg.i18n.genericError;
				showStatus( errMsg, false );
				return;
			}

			showStatus( cfg.i18n.saved, true );
			if ( window.lrnToast ) {
				window.lrnToast( cfg.i18n.saved, 'success' );
			}
		} ).catch( function () {
			btn.disabled = false;
			btn.removeAttribute( 'aria-busy' );
			showStatus( cfg.i18n.genericError, false );
		} );
	} );
} )();
