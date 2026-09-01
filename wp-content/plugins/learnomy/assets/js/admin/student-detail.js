/**
 * Learnomy — Student detail page: "Grant course access".
 *
 * The generic action dispatcher (actions.js) reads static data-* attributes;
 * the grant-access control needs the value of a <select> chosen at click time,
 * so it gets this small dedicated handler. Everything else on the page (remove
 * access, reset progress, remove student) rides the generic data-action path.
 *
 * POSTs to /admin/students/{id}/enroll with the chosen course_id, then reloads
 * so the enrollments table reflects the new row.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var admin = window.learnomyAdmin || {};
	var form  = document.getElementById( 'lrn-grant-access' );
	if ( ! form ) {
		return;
	}

	var select = document.getElementById( 'lrn-grant-course' );
	var button = document.getElementById( 'lrn-grant-access-btn' );
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
		var courseId = parseInt( select.value, 10 );
		if ( ! courseId ) {
			toast( __( 'Pick a course first.', 'learnomy' ), 'error' );
			return;
		}

		var userId = parseInt( form.getAttribute( 'data-user-id' ), 10 );
		button.disabled = true;

		window.fetch( admin.restUrl + 'admin/students/' + userId + '/enroll', {
			method:      'POST',
			credentials: 'same-origin',
			headers:     {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   admin.restNonce || '',
			},
			body: JSON.stringify( { course_id: courseId } ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok ) {
					toast.flash( __( 'Course access granted.', 'learnomy' ), 'success' );
					window.location.reload();
					return;
				}
				var message = ( result.body && result.body.error && result.body.error.message )
					? result.body.error.message
					: __( 'Could not grant access.', 'learnomy' );
				toast( message, 'error' );
				button.disabled = false;
			} )
			.catch( function () {
				toast( __( 'Network error. Please try again.', 'learnomy' ), 'error' );
				button.disabled = false;
			} );
	} );
} )();
