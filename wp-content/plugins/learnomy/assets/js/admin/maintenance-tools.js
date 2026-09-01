/**
 * Learnomy — Maintenance Tools (Settings → Developer & Data).
 *
 * Wires the Send Test Email and Flush Rewrite Rules buttons to their REST
 * endpoints under /learnomy/v1/admin/. Both endpoints use cookie + nonce
 * auth via the X-WP-Nonce header — no jQuery, no admin-ajax.
 *
 * Depends on: learnomyAdmin (restUrl, restNonce), window.lrnToast.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var __ = wp.i18n.__;

	var cfg       = window.learnomyAdmin || {};
	var restUrl   = cfg.restUrl || '/wp-json/learnomy/v1/';
	var restNonce = cfg.restNonce || '';
	var i18n      = cfg.maintenanceTools || {};
	var userEmail = cfg.currentUserEmail || '';

	/**
	 * Two-step confirm before firing a destructive maintenance action
	 * (Basecamp #9927979266). Uses window.lrnConfirm so it inherits the
	 * plugin's dialog styling + a11y; falls through if the dialog helper
	 * isn't loaded.
	 */
	function confirmAction( opts ) {
		if ( typeof window.lrnConfirm !== 'function' ) {
			return Promise.resolve( true );
		}
		return window.lrnConfirm( {
			title:        opts.title,
			message:      opts.message,
			confirmLabel: opts.confirmLabel,
			destructive:  true,
		} );
	}

	/**
	 * Snapshot the button's child nodes so we can restore the label
	 * (which contains a Lucide <i> icon) without re-rendering HTML.
	 */
	function snapshotChildren( el ) {
		var nodes = [];
		for ( var i = 0; i < el.childNodes.length; i++ ) {
			nodes.push( el.childNodes[ i ].cloneNode( true ) );
		}
		return nodes;
	}

	function restoreChildren( el, nodes ) {
		while ( el.firstChild ) {
			el.removeChild( el.firstChild );
		}
		nodes.forEach( function ( n ) {
			el.appendChild( n );
		} );
	}

	function setBusyLabel( el, label ) {
		while ( el.firstChild ) {
			el.removeChild( el.firstChild );
		}
		el.appendChild( document.createTextNode( label ) );
	}

	function postJson( endpoint, button, busyLabel ) {
		if ( ! button ) {
			return;
		}

		var savedNodes = snapshotChildren( button );
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		if ( busyLabel ) {
			setBusyLabel( button, busyLabel );
		}

		var restore = function () {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
			restoreChildren( button, savedNodes );
		};

		fetch( restUrl + endpoint, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   restNonce,
			},
			body: JSON.stringify( {} ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} )
			.then( function ( result ) {
				restore();
				if ( result.ok && result.body && result.body.data ) {
					var msg = result.body.data.message || __( 'Done.', 'learnomy' );
					if ( 'function' === typeof window.lrnToast ) {
						// shared/toast.js signature: ( message, type, duration ).
						window.lrnToast( msg, 'success' );
					}
				} else {
					var errMsg = __( 'Something went wrong.', 'learnomy' );
					if ( result.body && result.body.error && result.body.error.message ) {
						errMsg = result.body.error.message;
					} else if ( result.body && result.body.message ) {
						errMsg = result.body.message;
					}
					if ( 'function' === typeof window.lrnToast ) {
						window.lrnToast( errMsg, 'error' );
					}
				}
			} )
			.catch( function () {
				restore();
				if ( 'function' === typeof window.lrnToast ) {
					window.lrnToast( __( 'Network error. Please try again.', 'learnomy' ), 'error' );
				}
			} );
	}

	// Buttons are inside the Tools card on the Developer & Data tab. They may
	// not exist on other settings tabs / pages, so guard every lookup.
	// Bind every test-email trigger by class so the same action can live in
	// more than one place (the Developer Tools card AND the Emails settings
	// section) without duplicate ids. The legacy #lrn-test-email button carries
	// the class too.
	var testEmailBtns = document.querySelectorAll( '.lrn-js-test-email' );
	testEmailBtns.forEach( function ( testEmailBtn ) {
		testEmailBtn.addEventListener( 'click', async function () {
			var msg = ( i18n.testEmailMessage || __( 'A test email will be sent to {email}. Continue?', 'learnomy' ) )
				.replace( '{email}', userEmail || 'your account email' );
			var ok = await confirmAction( {
				title:        i18n.testEmailTitle || __( 'Send test email', 'learnomy' ),
				message:      msg,
				confirmLabel: i18n.testEmailConfirm || __( 'Send', 'learnomy' ),
			} );
			if ( ! ok ) { return; }
			postJson( 'admin/test-email', testEmailBtn, i18n.testEmailBusy || __( 'Sending…', 'learnomy' ) );
		} );
	} );

	var flushBtn = document.getElementById( 'lrn-flush-rules' );
	if ( flushBtn ) {
		flushBtn.addEventListener( 'click', async function () {
			var ok = await confirmAction( {
				title:        i18n.flushTitle || __( 'Flush rewrite rules', 'learnomy' ),
				message:      i18n.flushMessage || __( 'WordPress will recompute every URL rule. On large sites visitors may briefly see 404s while rules rebuild. Continue?', 'learnomy' ),
				confirmLabel: i18n.flushConfirm || __( 'Flush', 'learnomy' ),
			} );
			if ( ! ok ) { return; }
			postJson( 'admin/flush-rewrite-rules', flushBtn, i18n.flushBusy || __( 'Flushing…', 'learnomy' ) );
		} );
	}
}() );
