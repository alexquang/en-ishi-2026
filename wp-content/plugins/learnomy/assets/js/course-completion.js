/**
 * Course-completion retake form — REST submit with progressive enhancement.
 *
 * Wired against `templates/partials/course-completion.php`. Inline
 * extraction per ux-foundation rule F2.
 *
 * @package Learnomy
 */
( function () {
	'use strict';
	var i18n = ( window.lrnCourseCompletion || {} ).i18n || {};

		( function () {
			var form = document.getElementById( 'lrn-retake-form' );
			if ( ! form ) { return; }

			// Migrated to REST: POST /learnomy/v1/courses/{id}/retake
			// (commit f4c950e Wave-2-cousin). The handler in
			// includes/api/class-courses-controller.php returns the same
			// envelope shape as the legacy AJAX handler so json.data.message
			// + json.data.redirect access patterns are preserved.
			//
			// Nonce: wp_rest (auto-injected by wp.apiFetch) instead of the
			// legacy `learnomy_retake_course` nonce. The form still renders
			// the legacy nonce field for back-compat with any third-party
			// JS that still POSTs to admin-ajax during the deprecation
			// window.

			var courseId   = parseInt( form.getAttribute( 'data-course-id' ), 10 );
			var defaultErr = i18n.defaultErr;
			var networkErr = i18n.networkErr;

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var btn  = form.querySelector( 'button[type="submit"]' );
				var note = form.querySelector( '.lrn-course-completion__retake-note' );
				if ( btn ) { btn.disabled = true; }

				var apiPromise;
				if ( window.wp && window.wp.apiFetch ) {
					// Preferred — wp.apiFetch handles nonce + base URL + JSON parsing.
					apiPromise = window.wp.apiFetch( {
						path:   '/learnomy/v1/courses/' + courseId + '/retake',
						method: 'POST',
					} );
				} else {
					// Fallback — direct fetch when wp.apiFetch isn't enqueued
					// on the current screen. Uses wp_rest nonce from
					// wpApiSettings (loaded by wp_enqueue_script('wp-api-fetch')).
					var restRoot  = ( window.wpApiSettings && window.wpApiSettings.root ) || '/wp-json/';
					var restNonce = ( window.wpApiSettings && window.wpApiSettings.nonce ) || '';
					apiPromise = fetch( restRoot + 'learnomy/v1/courses/' + courseId + '/retake', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': restNonce, 'Content-Type': 'application/json' },
					} ).then( function ( r ) {
						if ( ! r.ok ) {
							return r.json().then( function ( body ) { throw body; } );
						}
						return r.json();
					} );
				}

				apiPromise
					.then( function ( result ) {
						// Success envelope: { data: { message: ..., redirect: ... } }
						var redirect = result && result.data && result.data.redirect;
						if ( redirect ) {
							window.location.href = redirect;
							return;
						}
						if ( btn ) { btn.disabled = false; }
						if ( note ) {
							note.textContent = ( result && result.data && result.data.message )
								? result.data.message
								: defaultErr;
						}
					} )
					.catch( function ( err ) {
						if ( btn ) { btn.disabled = false; }
						if ( ! note ) { return; }
						// Learnomy error envelope: { error: { code, message, details, request_id } }
						// WP_Error envelope (used by wp.apiFetch on legacy errors): { code, message, data }
						// Plain network error: empty/undefined
						var msg = networkErr;
						if ( err ) {
							if ( err.error && err.error.message ) {
								msg = err.error.message;            // Learnomy envelope
							} else if ( err.message ) {
								msg = err.message;                  // WP_Error envelope
							}
						}
						note.textContent = msg;
					} );
			} );
		} )();

}() );