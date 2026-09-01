/**
 * Email Log — Resend button wiring.
 *
 * Re-sends the email for a single logged notification through the REST route
 * POST /learnomy/v1/admin/email-log/{id}/resend, then ADDS the outcome of that
 * attempt to the row and surfaces it in a toast.
 *
 * The original status badge is deliberately left alone. It records what the
 * first attempt did, and a resend is a second event, not a correction of the
 * first — repainting the badge is how the evidence used to disappear
 * (Basecamp 10132808821).
 *
 * Depends on: window.learnomyAdmin (restUrl + restNonce, localized on the base
 * `learnomy-admin` handle) and window.lrnToast (learnomy-toast).
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var admin     = window.learnomyAdmin || {};
	var restUrl   = admin.restUrl || '';
	var restNonce = admin.restNonce || '';

	var __ = ( window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function' )
		? window.wp.i18n.__
		: function ( text ) { return text; };

	function toast( message, type ) {
		if ( typeof window.lrnToast === 'function' ) {
			window.lrnToast( message, type || 'info', 'error' === type ? 0 : 4000 );
		}
	}

	/**
	 * Record this resend attempt under the row's original status badge, matching
	 * what the server-rendered page shows on reload. The badge itself is never
	 * touched: it is the first attempt's outcome, and it stays true.
	 *
	 * @param {HTMLElement} row  The <tr> for the notification.
	 * @param {Object}      data Resend response payload.
	 */
	function recordResend( row, data ) {
		if ( ! row ) { return; }

		var wrap = row.querySelector( '[data-email-log-status]' );
		if ( ! wrap ) { return; }

		var line = wrap.querySelector( '[data-email-log-resend]' );
		if ( ! line ) {
			line = document.createElement( 'span' );
			line.className = 'lrn-email-log-status__resend';
			line.setAttribute( 'data-email-log-resend', '' );
			wrap.appendChild( line );
		}

		var text = data.sent
			? __( 'Resent just now - delivered', 'learnomy' )
			: __( 'Resent just now - failed', 'learnomy' );

		var count = parseInt( data.resend_count, 10 ) || 1;
		if ( count > 1 ) {
			text += ' (' + count + ')';
		}

		line.textContent = text;

		// A failure reason belongs to THIS attempt, so it replaces any reason
		// shown for a previous resend — never the original row's own hint.
		var reasonEl = wrap.querySelector( '[data-email-log-resend-reason]' );
		if ( ! data.sent && data.reason ) {
			if ( ! reasonEl ) {
				reasonEl = document.createElement( 'span' );
				reasonEl.className = 'lrn-email-log-status__hint';
				reasonEl.setAttribute( 'data-email-log-resend-reason', '' );
				wrap.appendChild( reasonEl );
			}
			reasonEl.textContent = data.reason;
			reasonEl.setAttribute( 'title', data.reason );
		} else if ( reasonEl ) {
			reasonEl.parentNode.removeChild( reasonEl );
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.lrn-email-log-resend' );
		if ( ! btn ) { return; }

		e.preventDefault();

		if ( ! restUrl || ! restNonce ) {
			toast( __( 'Cannot resend — admin context failed to load. Reload the page and try again.', 'learnomy' ), 'error' );
			return;
		}

		var id = btn.getAttribute( 'data-id' );
		if ( ! id ) { return; }

		var recipient = btn.getAttribute( 'data-recipient' ) || '';
		var row       = btn.closest( '[data-email-log-row]' );

		btn.disabled = true;
		btn.classList.add( 'is-busy' );

		fetch( restUrl + 'admin/email-log/' + encodeURIComponent( id ) + '/resend', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': restNonce
			}
		} )
		.then( function ( r ) {
			return r.json().then( function ( body ) {
				return { ok: r.ok, body: body };
			} );
		} )
		.then( function ( res ) {
			btn.disabled = false;
			btn.classList.remove( 'is-busy' );

			// REST error envelope: { error: { message } }.
			if ( ! res.ok || ( res.body && res.body.error ) ) {
				var errMsg = ( res.body && res.body.error && res.body.error.message )
					? res.body.error.message
					: __( 'Resend failed.', 'learnomy' );
				toast( errMsg, 'error' );
				return;
			}

			var data = ( res.body && res.body.data ) ? res.body.data : {};
			recordResend( row, data );

			if ( data.sent ) {
				toast(
					recipient
						/* translators: %s: recipient email address. */
						? __( 'Email resent to', 'learnomy' ) + ' ' + recipient
						: __( 'Email resent.', 'learnomy' ),
					'success'
				);
			} else {
				toast(
					__( 'Resend failed:', 'learnomy' ) + ' ' + ( data.reason || __( 'unknown error.', 'learnomy' ) ),
					'error'
				);
			}
		} )
		.catch( function () {
			btn.disabled = false;
			btn.classList.remove( 'is-busy' );
			toast( __( 'Network error — could not resend the email.', 'learnomy' ), 'error' );
		} );
	} );
}() );
