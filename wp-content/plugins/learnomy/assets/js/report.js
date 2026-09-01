/**
 * Learnomy — front-end "Report" affordance.
 *
 * A small, delegated handler for `.lrn-report-btn` buttons that appear on
 * reviews, lesson comments, and courses. On click it asks the member for a
 * short reason (via the shared window.lrnPrompt modal) and POSTs the report to
 * /learnomy/v1/reports, where it lands in the admin moderation queue.
 *
 * Delegated on document so it also covers cards loaded later (e.g. the
 * "Load more reviews" REST paging). ES5, cookie-auth REST, no jQuery.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var cfg  = window.lrnReport || {};
	var i18n = cfg.i18n || {};
	if ( ! cfg.restUrl ) {
		return;
	}

	function toast( type, message ) {
		if ( window.lrnToast ) {
			// Canonical signature is lrnToast( message, type, duration ).
			window.lrnToast( message, type );
		}
	}

	// `learnomy-prompt-modal` is a declared wp_enqueue_script dependency of this
	// file (class-template-loader.php), so window.lrnPrompt is always defined by
	// the time this runs. The old native window.confirm() fallback was
	// unreachable dead code and silently downgraded the reason to an empty
	// string, which is not the same report.
	function askReason() {
		return window.lrnPrompt( {
			title:        i18n.title || 'Report this content',
			message:      i18n.message || 'Tell us briefly why you are reporting this. A moderator will review it.',
			label:        i18n.label || 'Reason (optional)',
			placeholder:  i18n.placeholder || 'e.g. spam, offensive, off-topic',
			confirmLabel: i18n.submit || 'Submit report',
			cancelLabel:  i18n.cancel || 'Cancel',
			required:     false,
			maxLength:    500
		} );
	}

	function markReported( btn ) {
		btn.disabled = true;
		btn.classList.add( 'lrn-report-btn--done' );
		var labelEl = btn.querySelector( '.lrn-report-btn__label' );
		if ( labelEl ) {
			labelEl.textContent = i18n.reported || 'Reported';
		} else {
			btn.textContent = i18n.reported || 'Reported';
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.lrn-report-btn' );
		if ( ! btn || btn.disabled ) {
			return;
		}
		e.preventDefault();

		var objectType = btn.getAttribute( 'data-object-type' );
		var objectId   = btn.getAttribute( 'data-object-id' );
		if ( ! objectType || ! objectId ) {
			return;
		}

		askReason().then( function ( reason ) {
			// null === cancelled.
			if ( null === reason ) {
				return;
			}

			fetch( cfg.restUrl + 'reports', {
				method:      'POST',
				credentials: 'same-origin',
				headers:     {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   cfg.nonce || ''
				},
				body: JSON.stringify( {
					object_type: objectType,
					object_id:   parseInt( objectId, 10 ),
					reason:      reason
				} )
			} )
				.then( function ( r ) {
					return r.json().then( function ( payload ) {
						if ( ! r.ok ) {
							var msg = ( payload && payload.error && payload.error.message )
								|| ( payload && payload.message )
								|| ( i18n.failed || 'Could not submit your report. Please try again.' );
							throw new Error( msg );
						}
						return payload;
					} );
				} )
				.then( function () {
					markReported( btn );
					toast( 'success', i18n.thanks || 'Thanks — your report has been sent to a moderator.' );
				} )
				.catch( function ( err ) {
					toast( 'error', err.message );
				} );
		} );
	} );
} )();
