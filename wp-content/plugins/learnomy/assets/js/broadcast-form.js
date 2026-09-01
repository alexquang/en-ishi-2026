/**
 * Inline broadcast form — used by the instructor compose-announcement
 * widget AND the space-owner broadcast-to-members widget. Both render
 * the same `.lrn-broadcast-form` shell with different data-attributes,
 * so one tiny handler serves both surfaces.
 *
 * Markup contract per `.lrn-broadcast-form`:
 *   data-broadcast-audience  Required. e.g. "instructor_courses" or "space:55"
 *   data-rest-base           URL of POST /learnomy/v1/broadcasts
 *   data-rest-nonce          Nonce for the REST request
 *
 * Inside, [data-role="broadcast-form"] is the <form>, [data-role="broadcast-status"]
 * is the inline status line, [data-role="broadcast-history"] is the
 * recently-sent <ul> we prepend new rows into on success.
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	// Translated strings injected by Template_Loader via window.learnomyBroadcast.i18n.
	// English fallbacks keep the widget working if the localize call is missing.
	var I18N = ( window.learnomyBroadcast && window.learnomyBroadcast.i18n ) || {};
	function t( key, fallback ) {
		return I18N[ key ] || fallback;
	}

	// Pick a singular/plural template by count and interpolate the number.
	function nText( one, many, count ) {
		return ( 1 === count ? one : many.replace( '%d', count ) );
	}

	function init( root ) {
		var form = root.querySelector( '[data-role="broadcast-form"]' );
		if ( ! form || form.dataset.broadcastBound === '1' ) {
			return;
		}
		form.dataset.broadcastBound = '1';

		var restBase  = root.dataset.restBase;
		var nonce     = root.dataset.restNonce;
		var audience  = root.dataset.broadcastAudience;
		var status    = root.querySelector( '[data-role="broadcast-status"]' );
		var submit    = form.querySelector( '[data-role="broadcast-submit"]' );
		var historyEl = root.querySelector( '[data-role="broadcast-history"]' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			if ( submit.disabled ) {
				return;
			}
			var title   = form.elements.title ? form.elements.title.value.trim() : '';
			var message = form.elements.message ? form.elements.message.value.trim() : '';
			var email   = form.elements.send_email ? form.elements.send_email.checked : false;

			if ( ! title || ! message ) {
				show( status, 'error', t( 'fillBoth', 'Please fill in both fields.' ) );
				return;
			}

			submit.disabled = true;
			var originalText = submit.textContent;
			submit.textContent = '…';
			show( status, 'info', t( 'sending', 'Sending…' ) );

			fetch( restBase, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   nonce
				},
				body: JSON.stringify( {
					title:      title,
					message:    message,
					audience:   audience,
					send_email: email
				} )
			} )
			.then( function ( resp ) {
				return resp.json().then( function ( body ) {
					return { ok: resp.ok, status: resp.status, body: body };
				} );
			} )
			.then( function ( res ) {
				submit.disabled = false;
				submit.textContent = originalText;

				if ( ! res.ok ) {
					// Two error shapes reach this handler and only one was read.
					// WP core's own validation failures are flat
					// ({ code, message, data }), but everything the controller
					// itself refuses comes back through Base_Controller's
					// envelope ({ error: { code, message, details, request_id } }).
					// Reading `body.message` alone missed the entire second set,
					// so a real, actionable reason — "No recipients found for the
					// selected audience." — rendered as the generic "Could not
					// send. Please try again.", which is what the card reported
					// (Basecamp 10190134583). register.js already reads both
					// shapes for exactly this reason; this file was missed.
					var body   = res.body || {};
					var errMsg = ( body.error && body.error.message )
						|| body.message
						|| t( 'sendFailed', 'Could not send. Please try again.' );
					show( status, 'error', errMsg );
					return;
				}

				var data  = ( res.body && res.body.data ) || {};
				var count = data.count || 0;
				show( status, 'success',
					data.queued
						? nText( t( 'queuedOne', 'Queued for 1 recipient. They will receive it shortly.' ), t( 'queuedMany', 'Queued for %d recipients. They will receive it shortly.' ), count )
						: nText( t( 'sentOne', 'Sent to 1 recipient.' ), t( 'sentMany', 'Sent to %d recipients.' ), count )
				);

				// Optimistically prepend a row into the recently-sent list.
				if ( historyEl ) {
					var li = document.createElement( 'li' );
					li.className = 'lrn-announcements-widget__item';
					li.innerHTML =
						'<i data-lucide="megaphone" class="lrn-announcements-widget__icon" aria-hidden="true"></i>' +
						'<div class="lrn-announcements-widget__body">' +
							'<span class="lrn-announcements-widget__title"></span>' +
							'<span class="lrn-announcements-widget__meta"></span>' +
						'</div>';
					li.querySelector( '.lrn-announcements-widget__title' ).textContent = title;
					li.querySelector( '.lrn-announcements-widget__meta' ).textContent =
						nText( t( 'metaOne', '1 recipient · just now' ), t( 'metaMany', '%d recipients · just now' ), count );
					historyEl.prepend( li );
				}

				// Clear the form for the next message.
				form.elements.title.value   = '';
				form.elements.message.value = '';
				if ( form.elements.send_email ) {
					form.elements.send_email.checked = false;
				}

			} )
			.catch( function () {
				submit.disabled = false;
				submit.textContent = originalText;
				show( status, 'error', t( 'sendFailed', 'Could not send. Please try again.' ) );
			} );
		} );
	}

	function show( el, kind, text ) {
		if ( ! el ) {
			return;
		}
		el.hidden = false;
		el.textContent = text;
		el.className = 'lrn-broadcast-form__status lrn-broadcast-form__status--' + kind;
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			document.querySelectorAll( '.lrn-broadcast-form' ).forEach( init );
		} );
	} else {
		document.querySelectorAll( '.lrn-broadcast-form' ).forEach( init );
	}
} )();
