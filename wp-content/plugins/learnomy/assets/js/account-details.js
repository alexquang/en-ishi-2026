/**
 * Account details form — saves name / email / password via REST
 * (POST learnomy/v1/auth/account). No admin-ajax; frontend UX is REST-only.
 */
( function () {
	'use strict';

	// --- Notification preferences: each toggle saves on change. Self-contained
	// so it works regardless of the name/email form below. ---
	( function () {
		var card = document.querySelector( '[data-lrn-notifications]' );
		if ( ! card ) {
			return;
		}

		var __       = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) ? window.wp.i18n.__ : function ( s ) { return s; };
		var endpoint = card.getAttribute( 'data-endpoint' );
		var nonce    = card.getAttribute( 'data-nonce' );
		var statusEl = card.querySelector( '.lrn-account-notifications__status' );

		function note( message, ok ) {
			if ( ! statusEl ) {
				return;
			}
			statusEl.textContent = message;
			statusEl.hidden = false;
			statusEl.classList.toggle( 'is-success', !! ok );
		}

		card.addEventListener( 'change', function ( event ) {
			var input = event.target;
			if ( ! input || 'checkbox' !== input.type || ! input.hasAttribute( 'data-pref' ) ) {
				return;
			}

			var data = {};
			data[ input.getAttribute( 'data-pref' ) ] = input.checked;
			input.disabled = true;

			fetch( endpoint, {
				method:      'PATCH',
				credentials: 'same-origin',
				headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body:        JSON.stringify( data ),
			} )
				.then( function ( r ) {
					if ( ! r.ok ) {
						throw new Error( 'save failed' );
					}
					note( __( 'Saved', 'learnomy' ), true );
				} )
				.catch( function () {
					// Roll the toggle back so the UI reflects the persisted state.
					input.checked = ! input.checked;
					note( __( 'Could not save. Please try again.', 'learnomy' ), false );
				} )
				.finally( function () {
					input.disabled = false;
				} );
		} );
	} )();

	var form = document.getElementById( 'lrn-account-form' );
	var cfg  = window.lrnAccountDetails;
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

	// --- Delete-account: confirm via lrnConfirm then DELETE /auth/account. ---
	( function () {
		var zone = document.querySelector( '[data-lrn-delete-account]' );
		if ( ! zone ) {
			return;
		}

		var btn      = document.getElementById( 'lrn-delete-account-btn' );
		var endpoint = zone.getAttribute( 'data-endpoint' );
		var nonce    = zone.getAttribute( 'data-nonce' );
		var statusEl = zone.querySelector( '.lrn-account-delete-zone__status' );

		function note( message, ok ) {
			if ( ! statusEl ) {
				return;
			}
			statusEl.textContent = message;
			statusEl.hidden = false;
			statusEl.classList.toggle( 'is-error', ! ok );
		}

		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			if ( ! window.lrnConfirm ) {
				return;
			}
			// lrnConfirm takes an options object and returns a Promise<boolean>
			// (NOT a (message, callback) pair). Passing a string built an empty
			// "Please confirm" modal with no body and never ran the delete, so the
			// account was never removed (#9972687563).
			window.lrnConfirm( {
				title:        __( 'Delete account', 'learnomy' ),
				message:      __( 'Are you sure you want to permanently delete your account? This cannot be undone.', 'learnomy' ),
				confirmLabel: __( 'Delete my account', 'learnomy' ),
				destructive:  true,
			} ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				btn.disabled = true;

				fetch( endpoint, {
					method:      'DELETE',
					credentials: 'same-origin',
					headers:     { 'X-WP-Nonce': nonce },
				} )
					.then( function ( r ) {
						return r.json().then( function ( body ) {
							return { ok: r.ok, body: body };
						} );
					} )
					.then( function ( result ) {
						if ( ! result.ok ) {
							btn.disabled = false;
							var msg = ( result.body && result.body.error && result.body.error.message )
								|| __( 'Could not delete your account. Please try again.', 'learnomy' );
							note( msg, false );
							return;
						}
						// Account gone — redirect to home so the browser does not
						// replay a now-invalid auth cookie.
						window.location.href = '/';
					} )
					.catch( function () {
						btn.disabled = false;
						note( __( 'Could not delete your account. Please try again.', 'learnomy' ), false );
					} );
			} );
		} );
	} )();

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var payload = {
			display_name:     form.display_name.value,
			first_name:       form.first_name.value,
			last_name:        form.last_name.value,
			email:            form.email.value,
			current_password: form.current_password.value,
			new_password:     form.new_password.value
		};

		// Forward any custom account fields (name="learnomy_field_*") so the
		// learnomy_account_fields_save consumer can persist them on this surface.
		form.querySelectorAll( '[name^="learnomy_field_"]' ).forEach( function ( el ) {
			payload[ el.name ] = ( 'checkbox' === el.type ) ? ( el.checked ? '1' : '' ) : el.value;
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

			var data  = result.body.data || {};
			var parts = [ cfg.i18n.saved ];
			if ( data.pending_email ) {
				parts.push( cfg.i18n.emailPending.replace( '%s', data.pending_email ) );
			}
			showStatus( parts.join( ' ' ), true );

			// Never leave password fields populated after a save.
			form.current_password.value = '';
			form.new_password.value = '';

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
