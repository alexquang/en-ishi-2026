/**
 * Webhooks admin page — local UI helpers (copy / regenerate secret, select-all
 * events). Uses a dedicated `data-webhook-action` hook, NOT `data-action`,
 * because the generic admin AJAX handler (actions.js) treats every
 * `[data-action]` click as a server action + page reload.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ )
		? window.wp.i18n.__
		: function ( s ) { return s; };

	function toast( message, type ) {
		if ( typeof window.lrnToast === 'function' ) {
			window.lrnToast( message, type || 'success' );
		}
	}

	/** Generate a 32-char alphanumeric secret (matches the PHP default length). */
	function generateSecret() {
		var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
		var out   = '';
		var rand  = new Uint32Array( 32 );
		( window.crypto || window.msCrypto ).getRandomValues( rand );
		for ( var i = 0; i < 32; i++ ) {
			out += chars.charAt( rand[ i ] % chars.length );
		}
		return out;
	}

	function copy( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}
		// Fallback for non-secure contexts.
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); } catch ( e ) { /* noop */ }
		document.body.removeChild( ta );
		return Promise.resolve();
	}

	/** POST to an incoming-enrollment admin REST route with the localized nonce. */
	function restPost( path, body ) {
		var cfg = window.lrnWebhooksIncoming || {};
		return fetch( ( cfg.restBase || '' ) + path, {
			method:  'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body:    JSON.stringify( body || {} )
		} ).then( function ( r ) {
			return r.json().then( function ( j ) { return { ok: r.ok, status: r.status, json: j }; } );
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-webhook-action]' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var action = btn.getAttribute( 'data-webhook-action' );
		var secret = document.getElementById( 'webhook-secret' );

		if ( 'copy-secret' === action && secret ) {
			copy( secret.value ).then( function () {
				toast( __( 'Secret key copied.', 'learnomy' ), 'success' );
			} );
			return;
		}

		if ( 'regenerate-secret' === action && secret ) {
			secret.value = generateSecret();
			toast( __( 'New secret generated. Copy it before saving.', 'learnomy' ), 'info' );
			return;
		}

		// ── Incoming-enrollment recipe panel (Closed courses) ────────────────
		if ( 'copy-incoming-url' === action ) {
			var urlEl = document.getElementById( 'lrn-incoming-url' );
			if ( urlEl ) {
				copy( urlEl.value ).then( function () { toast( __( 'Endpoint URL copied.', 'learnomy' ), 'success' ); } );
			}
			return;
		}

		if ( 'reveal-incoming-secret' === action ) {
			var revEl = document.getElementById( 'lrn-incoming-secret' );
			if ( revEl ) { revEl.type = 'password' === revEl.type ? 'text' : 'password'; }
			return;
		}

		if ( 'copy-incoming-secret' === action ) {
			var inSec = document.getElementById( 'lrn-incoming-secret' );
			if ( inSec ) {
				copy( inSec.value ).then( function () { toast( __( 'Signing secret copied.', 'learnomy' ), 'success' ); } );
			}
			return;
		}

		if ( 'regenerate-incoming-secret' === action ) {
			// Destructive: every connected store stops being able to sign until
			// its copy of the secret is updated. `learnomy-confirm-modal` is a
			// declared dependency of this script (class-admin.php).
			window.lrnConfirm( {
				title:        __( 'Regenerate the signing secret?', 'learnomy' ),
				message:      __( 'Existing connections stop working until you update the secret in them.', 'learnomy' ),
				confirmLabel: __( 'Regenerate secret', 'learnomy' ),
				cancelLabel:  __( 'Cancel', 'learnomy' ),
				destructive:  true
			} ).then( function ( ok ) {
				if ( ! ok ) {
					return;
				}
				restPost( 'regenerate-secret', {} ).then( function ( res ) {
					var newSecret = res.ok && res.json && res.json.data ? res.json.data.secret : '';
					if ( newSecret ) {
						var sec3 = document.getElementById( 'lrn-incoming-secret' );
						if ( sec3 ) { sec3.type = 'text'; sec3.value = newSecret; }
						toast( __( 'New secret generated. Copy it into your store now.', 'learnomy' ), 'success' );
					} else {
						toast( __( 'Could not regenerate the secret.', 'learnomy' ), 'error' );
					}
				} );
			} );
			return;
		}

		if ( 'send-test-enrollment' === action ) {
			var courseEl = document.getElementById( 'lrn-incoming-test-course' );
			var emailEl  = document.getElementById( 'lrn-incoming-test-email' );
			var resultEl = document.getElementById( 'lrn-incoming-test-result' );
			if ( ! courseEl || ! emailEl ) { return; }
			btn.disabled = true;
			restPost( 'test', { course_id: parseInt( courseEl.value, 10 ) || 0, user_email: emailEl.value } ).then( function ( res ) {
				btn.disabled = false;
				if ( ! resultEl ) { return; }
				resultEl.hidden = false;
				var d = ( res.json && res.json.data ) ? res.json.data : {};
				if ( res.ok && d.accepted ) {
					resultEl.textContent = __( 'Success - access granted (enrollment #', 'learnomy' ) + ( d.enrollment_id || '-' ) + ')'
						+ ( d.user_created ? __( '. A new account was created and emailed a set-password link.', 'learnomy' ) : '.' );
				} else {
					var msg = ( res.json && res.json.error && res.json.error.message ) || __( 'the request was rejected.', 'learnomy' );
					resultEl.textContent = __( 'Test failed - ', 'learnomy' ) + msg;
				}
			} );
			return;
		}

		if ( 'toggle-all' === action ) {
			var group = document.querySelector( '[data-role="webhook-events"]' );
			if ( ! group ) {
				return;
			}
			var boxes = group.querySelectorAll( 'input[type="checkbox"]' );
			var anyOff = Array.prototype.some.call( boxes, function ( b ) { return ! b.checked; } );
			Array.prototype.forEach.call( boxes, function ( b ) { b.checked = anyOff; } );
			btn.textContent = anyOff ? __( 'Clear all', 'learnomy' ) : __( 'Select all', 'learnomy' );
			return;
		}

		if ( 'toggle-group' === action ) {
			var famGroup = btn.closest( '[data-role="webhook-event-group"]' );
			if ( ! famGroup ) {
				return;
			}
			var famBoxes = famGroup.querySelectorAll( 'input[type="checkbox"]' );
			var famOff = Array.prototype.some.call( famBoxes, function ( b ) { return ! b.checked; } );
			Array.prototype.forEach.call( famBoxes, function ( b ) { b.checked = famOff; } );
			btn.textContent = famOff ? __( 'Clear', 'learnomy' ) : __( 'Select all', 'learnomy' );
			return;
		}

		if ( 'edit' === action ) {
			var events = [];
			try { events = JSON.parse( btn.getAttribute( 'data-webhook-events' ) || '[]' ) || []; } catch ( err ) { events = []; }
			setFormMode( true, {
				id:     btn.getAttribute( 'data-webhook-id' ),
				url:    btn.getAttribute( 'data-webhook-url' ),
				events: events,
			} );
		}
	} );

	// Cancel editing → back to "add new" mode.
	var cancelBtn = document.getElementById( 'lrn-webhook-cancel' );
	if ( cancelBtn ) {
		cancelBtn.addEventListener( 'click', function () { setFormMode( false ); } );
	}

	/**
	 * Switch the webhook form between "add new" and "edit" modes. In edit mode
	 * the form PATCHes the existing webhook (URL + events only — the secret is
	 * write-once and never retrievable, so the secret row is hidden and left
	 * untouched on the server).
	 *
	 * @param {boolean} editing Whether to enter edit mode.
	 * @param {?object} data    { id, url, events[] } when editing.
	 */
	function setFormMode( editing, data ) {
		var form      = document.getElementById( 'lrn-webhook-form' );
		if ( ! form ) { return; }
		var title     = document.getElementById( 'lrn-webhook-form-title' );
		var submit    = document.getElementById( 'lrn-webhook-submit' );
		var cancel    = document.getElementById( 'lrn-webhook-cancel' );
		var idField   = document.getElementById( 'lrn-webhook-id' );
		var secretRow = document.getElementById( 'lrn-webhook-secret-row' );
		var url       = document.getElementById( 'webhook-url' );
		var boxes     = document.querySelectorAll( '[data-role="webhook-events"] input[type="checkbox"]' );

		if ( editing ) {
			form.setAttribute( 'data-ajax-action', 'learnomy_update_webhook' );
			idField.value = data.id || '';
			url.value     = data.url || '';
			Array.prototype.forEach.call( boxes, function ( b ) { b.checked = data.events.indexOf( b.value ) !== -1; } );
			if ( title )     { title.textContent = __( 'Edit Webhook', 'learnomy' ); }
			if ( submit )    { submit.textContent = __( 'Update Webhook', 'learnomy' ); }
			if ( secretRow ) { secretRow.hidden = true; }
			if ( cancel )    { cancel.hidden = false; }
			form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		} else {
			form.setAttribute( 'data-ajax-action', 'learnomy_create_webhook' );
			idField.value = '';
			url.value     = '';
			Array.prototype.forEach.call( boxes, function ( b ) { b.checked = false; } );
			if ( title )     { title.textContent = __( 'Add New Webhook', 'learnomy' ); }
			if ( submit )    { submit.textContent = __( 'Add Webhook', 'learnomy' ); }
			if ( secretRow ) { secretRow.hidden = false; }
			if ( cancel )    { cancel.hidden = true; }
		}
	}
} )();
