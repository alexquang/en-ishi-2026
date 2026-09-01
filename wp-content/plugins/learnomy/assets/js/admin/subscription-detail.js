/**
 * Learnomy — Subscription detail page: "Extend" control.
 *
 * Posts a new period_end to /admin/memberships/{id}/extend. A datetime input
 * (not a static data attr) needs its value read at click time, so it gets this
 * small dedicated handler; Cancel / Resume ride the generic actions.js path.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var admin = window.learnomyAdmin || {};
	var wrap  = document.getElementById( 'lrn-sub-extend' );
	if ( ! wrap ) {
		return;
	}

	var input  = document.getElementById( 'lrn-sub-extend-date' );
	var button = document.getElementById( 'lrn-sub-extend-btn' );
	var __     = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) || function ( s ) { return s; };

	function toast( message, type ) {
		if ( window.lrnToast ) {
			window.lrnToast( message, type || 'info' );
		}
	}

	// Queued for the page we are about to reload into — see `lrnToast.flash()`.
	toast.flash = function ( message, type ) {
		if ( window.lrnToast ) {
			window.lrnToast.flash( message, type || 'info' );
		}
	};

	button.addEventListener( 'click', function () {
		var value = ( input.value || '' ).trim();
		if ( ! value ) {
			toast( __( 'Pick a new end date.', 'learnomy' ), 'error' );
			return;
		}
		// <input type="datetime-local"> gives "YYYY-MM-DDTHH:MM" — the REST
		// handler accepts any strtotime-parseable string; send "YYYY-MM-DD HH:MM".
		var periodEnd = value.replace( 'T', ' ' );
		var subId     = parseInt( wrap.getAttribute( 'data-subscription-id' ), 10 );
		button.disabled = true;

		window.fetch( admin.restUrl + 'admin/memberships/' + subId + '/extend', {
			method:      'POST',
			credentials: 'same-origin',
			headers:     {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   admin.restNonce || '',
			},
			body: JSON.stringify( { period_end: periodEnd } ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok ) {
					toast.flash( __( 'Subscription extended.', 'learnomy' ), 'success' );
					window.location.reload();
					return;
				}
				var message = ( result.body && result.body.error && result.body.error.message )
					? result.body.error.message
					: __( 'Could not extend the subscription.', 'learnomy' );
				toast( message, 'error' );
				button.disabled = false;
			} )
			.catch( function () {
				toast( __( 'Network error. Please try again.', 'learnomy' ), 'error' );
				button.disabled = false;
			} );
	} );
} )();
