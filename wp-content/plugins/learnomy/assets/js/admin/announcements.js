/**
 * Learnomy — Announcements page form handler.
 *
 * Submits the broadcast through REST POST /admin/announcements (replaces
 * the legacy learnomy_send_announcement AJAX, deleted in Stage 5K-G).
 *
 * @package Learnomy
 */
(function () {
	'use strict';

	var __ = wp.i18n.__;

	var form    = document.getElementById( 'lrn-announcement-form' );
	var spinner = document.getElementById( 'lrn-ann-spinner' );
	var submit  = document.getElementById( 'lrn-ann-submit' );

	if ( ! form ) {
		return;
	}

	var cfg       = window.learnomyAdmin || {};
	var i18n      = cfg.i18n || {};
	var restUrl   = cfg.restUrl || '/wp-json/learnomy/v1/';
	var restNonce = cfg.restNonce || '';

	// The form carries no data-ajax-action attribute, so the generic
	// `form[data-ajax-action]` handler in actions.js never claims it and this
	// is the only submit handler. That attribute used to be present and named
	// an admin-ajax action deleted in Stage 5K-G, which made every SUCCESSFUL
	// broadcast raise a spurious "Action failed." toast; it was suppressed by
	// binding in the capture phase and calling stopImmediatePropagation. The
	// attribute is gone now, so the suppression is gone with it — one handler
	// on one form beats two handlers racing, with the loser silenced by
	// registration order.
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();

		var title     = document.getElementById( 'lrn-ann-title' ).value.trim();
		var message   = document.getElementById( 'lrn-ann-message' ).value.trim();
		var courseId  = parseInt( document.getElementById( 'lrn-ann-course' ).value, 10 ) || 0;
		var sendEmail = document.getElementById( 'lrn-ann-email' ).checked;

		if ( ! title || ! message ) {
			return;
		}

		var doSend = function ( confirmAll ) {
			submit.disabled = true;
			spinner.classList.add( 'is-active' );

			var payload = {
				title:      title,
				message:    message,
				course_id:  courseId,
				send_email: sendEmail,
			};
			// course_id 0 = every enrolled student. The server refuses that
			// without an explicit confirm so a misclick can't blast thousands.
			if ( confirmAll ) {
				payload.confirm = true;
			}

			fetch( restUrl + 'admin/announcements', {
				method:      'POST',
				credentials: 'same-origin',
				headers:     {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   restNonce,
				},
				body: JSON.stringify( payload ),
			} )
				.then( function ( r ) {
					return r.json().then( function ( body ) {
						return { ok: r.ok, body: body };
					} );
				} )
				.then( function ( out ) {
					if ( out.ok ) {
						var count = ( out.body && out.body.data && out.body.data.count ) || 0;
						lrnToast(
							count + ( count === 1 ? ' student' : ' students' ) + ' notified.',
							'success'
						);
						form.reset();
					} else {
						var msg = ( out.body && out.body.error && out.body.error.message ) || __( 'An error occurred.', 'learnomy' );
						lrnToast( msg, 'error' );
					}
				} )
				.catch( function () {
					lrnToast( __( 'Network error. Please try again.', 'learnomy' ), 'error' );
				} )
				.finally( function () {
					submit.disabled = false;
					spinner.classList.remove( 'is-active' );
				} );
		};

		// An all-students broadcast needs an explicit confirmation (matches the
		// server's confirm guard). Use the in-app confirm dialog, never a native
		// one. Course-scoped announcements send straight through.
		if ( 0 === courseId && typeof window.lrnConfirm === 'function' ) {
			window.lrnConfirm( {
				title:       i18n.confirmAllTitle || __( 'Send to all students?', 'learnomy' ),
				message:     i18n.confirmAllMessage || __( 'This announcement will go to every enrolled student. Continue?', 'learnomy' ),
				confirmLabel: i18n.confirmAllConfirm || __( 'Send to all', 'learnomy' ),
			} ).then( function ( ok ) {
				if ( ok ) {
					doSend( true );
				}
			} );
			return;
		}

		doSend( 0 === courseId );
	} );
})();
