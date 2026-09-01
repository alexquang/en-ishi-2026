/**
 * Account billing — Stripe Customer Portal button handler.
 *
 * Wires every [data-lrn-portal-btn] on the /account/billing/ page: on click,
 * POST to /learnomy/v1/account/billing/portal with the subscription_id stored
 * in data-subscription-id, then open the returned Stripe-hosted URL in a new
 * tab. While the request is in flight, the button is disabled and its label
 * swaps to lrnAccountBilling.i18n.portalOpening; failures surface via
 * lrnToast (or alert as a last resort if the toast module is missing).
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	if ( ! window.lrnAccountBilling ) {
		return;
	}

	var cfg  = window.lrnAccountBilling;
	var i18n = cfg.i18n || {};

	function notifyFailure( message ) {
		if ( typeof window.lrnToast === 'function' ) {
			window.lrnToast( message, 'error' );
			return;
		}
		// lrnToast is an enqueued dependency, so this only fires if the toast
		// module failed to load — log it rather than block the page with a
		// native alert() (per the no-native-dialog rule).
		if ( window.console && window.console.error ) {
			window.console.error( '[learnomy] ' + message );
		}
	}

	function openPortal( button ) {
		var subId = parseInt( button.getAttribute( 'data-subscription-id' ) || '0', 10 );

		// Swap the LABEL, not the whole button. The button contains a Lucide
		// <i> icon alongside its text, and assigning button.textContent
		// destroys every child node — so the credit-card icon vanished on the
		// first click and never came back, even on success. Keep the icon and
		// rewrite only the trailing text node.
		var label = lastTextNode( button );
		var prior = label ? label.nodeValue : '';

		if ( label ) {
			label.nodeValue = i18n.portalOpening || 'Opening…';
		}

		button.disabled = true;

		var body = subId > 0 ? JSON.stringify( { subscription_id: subId } ) : null;

		fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Accept':       'application/json',
				'Content-Type': 'application/json',
				'X-WP-Nonce':   cfg.nonce
			},
			body: body
		} )
			.then( function ( r ) {
				return r.json().then( function ( j ) {
					if ( ! r.ok ) {
						throw new Error( ( j && j.error && j.error.message ) || ( i18n.portalFailed || 'Request failed.' ) );
					}
					var url = j && j.data && j.data.url;
					if ( ! url ) {
						throw new Error( i18n.portalFailed || 'Request failed.' );
					}
					// Open in a new tab — the buyer returns to /account/billing/
					// from the portal's return_url, so a fresh tab keeps both
					// surfaces visible side-by-side.
					var win = window.open( url, '_blank', 'noopener,noreferrer' );
					if ( ! win ) {
						// Popup blocker — fall back to same-tab navigation.
						window.location.href = url;
					}
				} );
			} )
			.catch( function ( err ) {
				notifyFailure( err.message || ( i18n.portalFailed || 'Request failed.' ) );
			} )
			.finally( function () {
				button.disabled = false;
				if ( label ) {
					label.nodeValue = prior;
				}
			} );
	}

	/**
	 * The button's own text node — the one holding its visible label, ignoring
	 * the icon element and any whitespace-only nodes the template indentation
	 * leaves behind.
	 *
	 * @param {HTMLElement} el Button.
	 * @return {Text|null} The label node, or null when the button is icon-only.
	 */
	function lastTextNode( el ) {
		var nodes = Array.prototype.slice.call( el.childNodes );
		for ( var i = nodes.length - 1; i >= 0; i-- ) {
			if ( 3 === nodes[ i ].nodeType && nodes[ i ].nodeValue.trim() !== '' ) {
				return nodes[ i ];
			}
		}
		return null;
	}

	function init() {
		document.querySelectorAll( '[data-lrn-portal-btn]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				openPortal( btn );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();

/*
 * Billing address form.
 *
 * The write half of the account Billing page. Until this shipped, the only
 * writer of billing_* anywhere in either plugin was a live checkout
 * submission, so a member who mistyped an address could correct it only by
 * buying something else (Basecamp 10171338907).
 *
 * Kept in this file rather than a new one: it is the same page, the same
 * localized config and the same nonce, and a second script for one form would
 * be another thing to enqueue and keep in sync.
 */
( function () {
	'use strict';

	var cfg = window.lrnAccountBilling;
	if ( ! cfg || ! cfg.addressUrl ) {
		return;
	}

	var form = document.getElementById( 'lrn-billing-address-form' );
	if ( ! form ) {
		return;
	}

	var status = form.querySelector( '.lrn-billing-address-form__status' );
	var btn    = form.querySelector( 'button[type="submit"]' );
	var i18n   = cfg.i18n || {};

	function say( text ) {
		if ( status ) {
			status.textContent = text;
		}
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		btn.disabled = true;
		say( i18n.addressSaving || 'Saving…' );

		var body = {};
		form.querySelectorAll( '[name^="billing_"]' ).forEach( function ( field ) {
			body[ field.name ] = field.value;
		} );

		fetch( cfg.addressUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			body:        JSON.stringify( body )
		} ).then( function ( r ) {
			if ( ! r.ok ) {
				throw new Error( 'save failed' );
			}
			return r.json();
		} ).then( function () {
			btn.disabled = false;
			say( i18n.addressSaved || 'Address saved.' );
			if ( typeof window.lrnToast === 'function' ) {
				window.lrnToast( i18n.addressSaved || 'Address saved.', 'success' );
			}
		} ).catch( function () {
			btn.disabled = false;
			say( i18n.addressFailed || 'Could not save your address. Try again.' );
		} );
	} );
} )();
