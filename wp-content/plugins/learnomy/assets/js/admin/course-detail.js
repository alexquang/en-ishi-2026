/**
 * Learnomy — Course overview page: "Enroll a student".
 *
 * Resolves an email or username server-side and manually enrolls that student
 * in this course. A text input (not a dropdown of every user) keeps it
 * scale-safe. Everything else on the page (reset / remove per enrolled
 * student, publish toggle) rides the generic actions.js data-action path.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var admin = window.learnomyAdmin || {};
	var form  = document.getElementById( 'lrn-course-enroll' );
	if ( ! form ) {
		return;
	}

	var input  = document.getElementById( 'lrn-course-enroll-user' );
	var button = document.getElementById( 'lrn-course-enroll-btn' );
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

	function submit() {
		var query = ( input.value || '' ).trim();
		if ( ! query ) {
			toast( __( 'Enter an email or username.', 'learnomy' ), 'error' );
			return;
		}

		var courseId = parseInt( form.getAttribute( 'data-course-id' ), 10 );
		button.disabled = true;

		window.fetch( admin.restUrl + 'admin/courses/' + courseId + '/enroll', {
			method:      'POST',
			credentials: 'same-origin',
			headers:     {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   admin.restNonce || '',
			},
			body: JSON.stringify( { user: query } ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok ) {
					toast.flash( __( 'Student enrolled.', 'learnomy' ), 'success' );
					window.location.reload();
					return;
				}
				var message = ( result.body && result.body.error && result.body.error.message )
					? result.body.error.message
					: __( 'Could not enroll that student.', 'learnomy' );
				toast( message, 'error' );
				button.disabled = false;
			} )
			.catch( function () {
				toast( __( 'Network error. Please try again.', 'learnomy' ), 'error' );
				button.disabled = false;
			} );
	}

	button.addEventListener( 'click', submit );
	input.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			submit();
		}
	} );
} )();

/**
 * Learnomy — Course overview: "Bulk enrol from a list (CSV / emails)".
 *
 * Reads a pasted list or an uploaded CSV/txt of emails and POSTs them to the
 * bulk-enroll endpoint (manual source; unknown emails are provisioned with a
 * set-password invite). Shows a per-run summary + a "left to do" count when the
 * server caps a big paste.
 */
( function () {
	'use strict';

	var admin = window.learnomyAdmin || {};
	var wrap  = document.getElementById( 'lrn-course-bulk-enroll' );
	if ( ! wrap ) {
		return;
	}

	var __       = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) || function ( s ) { return s; };
	var fileEl   = document.getElementById( 'lrn-bulk-enroll-file' );
	var textEl   = document.getElementById( 'lrn-bulk-enroll-emails' );
	var btnEl    = document.getElementById( 'lrn-bulk-enroll-btn' );
	var resultEl = document.getElementById( 'lrn-bulk-enroll-result' );

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

	if ( fileEl ) {
		fileEl.addEventListener( 'change', function () {
			var file = fileEl.files && fileEl.files[ 0 ];
			if ( ! file ) {
				return;
			}
			var reader = new FileReader();
			reader.onload = function () {
				textEl.value = ( textEl.value ? textEl.value + '\n' : '' ) + String( reader.result || '' );
			};
			reader.readAsText( file );
		} );
	}

	if ( btnEl ) {
		btnEl.addEventListener( 'click', function () {
			var lines = ( textEl.value || '' ).split( /\r\n|\r|\n/ ).map( function ( l ) { return l.trim(); } ).filter( Boolean );
			if ( ! lines.length ) {
				toast( __( 'Paste or upload at least one email.', 'learnomy' ), 'error' );
				return;
			}
			var courseId = parseInt( wrap.getAttribute( 'data-course-id' ), 10 );
			btnEl.disabled = true;
			window.fetch( admin.restUrl + 'admin/courses/' + courseId + '/bulk-enroll', {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': admin.restNonce || '' },
				body:        JSON.stringify( { emails: lines } )
			} )
				.then( function ( r ) { return r.json().then( function ( b ) { return { ok: r.ok, body: b }; } ); } )
				.then( function ( res ) {
					btnEl.disabled = false;
					if ( ! res.ok ) {
						toast( ( res.body && res.body.error && res.body.error.message ) || __( 'Bulk enrol failed.', 'learnomy' ), 'error' );
						return;
					}
					var d = res.body.data || {};
					var s = d.summary || {};
					if ( resultEl ) {
						resultEl.hidden = false;
						resultEl.textContent = ( s.enrolled || 0 ) + __( ' enrolled, ', 'learnomy' )
							+ ( s.created || 0 ) + __( ' new accounts invited, ', 'learnomy' )
							+ ( s.skipped || 0 ) + __( ' already in, ', 'learnomy' )
							+ ( s.failed || 0 ) + __( ' failed.', 'learnomy' )
							+ ( d.remaining ? ( ' ' + d.remaining + __( ' left - submit again to continue.', 'learnomy' ) ) : '' );
					}
					toast( __( 'Bulk enrolment complete.', 'learnomy' ), 'success' );
				} )
				.catch( function () {
					btnEl.disabled = false;
					toast( __( 'Network error. Please try again.', 'learnomy' ), 'error' );
				} );
		} );
	}
} )();

/**
 * Learnomy — Course overview: "Instructors & commission".
 *
 * Saves the lead/co-instructor roster + per-course commission with a single
 * partial PATCH /courses/{id} — the same payload the course editor sent before
 * this card moved here, so the controller's roster sync + commission resolution
 * is reused unchanged (no revenue logic duplicated). The card only renders for
 * site owners (manage_options).
 */
( function () {
	'use strict';

	var admin = window.learnomyAdmin || {};
	var card  = document.getElementById( 'lrn-course-instructors' );
	if ( ! card ) {
		return;
	}

	var saveBtn = document.getElementById( 'lrn-cd-save-instructors' );
	if ( ! saveBtn ) {
		return;
	}

	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) || function ( s ) { return s; };

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

	saveBtn.addEventListener( 'click', function () {
		var courseId = parseInt( card.getAttribute( 'data-course-id' ), 10 );
		if ( ! courseId ) {
			return;
		}

		var payload   = {};
		var primaryEl = card.querySelector( '#lrn-cd-primary' );
		var primaryId = primaryEl ? ( parseInt( primaryEl.value, 10 ) || 0 ) : 0;
		if ( primaryEl ) {
			payload.primary_instructor_id = primaryId;
		}

		// Roster = primary + checked co-instructors (deduped), so the lead is
		// never dropped regardless of its disabled checkbox state. Only sent when
		// the instructor controls render (commission-only sites omit it).
		if ( primaryEl || card.querySelector( 'input[name="cd_instructors[]"]' ) ) {
			var ids = {};
			if ( primaryId ) {
				ids[ primaryId ] = true;
			}
			card.querySelectorAll( 'input[name="cd_instructors[]"]:checked' ).forEach( function ( cb ) {
				var id = parseInt( cb.value, 10 ) || 0;
				if ( id ) {
					ids[ id ] = true;
				}
			} );
			payload.instructors = Object.keys( ids ).map( function ( id ) {
				return { user_id: parseInt( id, 10 ), share_pct: null };
			} );
		}

		// No commission_rate here. This card shows the rate read-only and links
		// to the course editor, which owns the input — sending it from two
		// screens is how the value drifts. See course-settings-commission.php.

		saveBtn.disabled = true;
		saveBtn.classList.add( 'is-loading' );

		var done = function () {
			saveBtn.disabled = false;
			saveBtn.classList.remove( 'is-loading' );
		};

		window.fetch( admin.restUrl + 'courses/' + courseId, {
			method:      'PATCH',
			credentials: 'same-origin',
			headers:     {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   admin.restNonce || '',
			},
			body: JSON.stringify( payload ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok ) {
					toast.flash( __( 'Instructors & commission saved.', 'learnomy' ), 'success' );
					window.location.reload();
					return;
				}
				var message = ( result.body && result.body.error && result.body.error.message )
					? result.body.error.message
					: __( 'Could not save. Please try again.', 'learnomy' );
				toast( message, 'error' );
				done();
			} )
			.catch( function () {
				toast( __( 'Network error. Please try again.', 'learnomy' ), 'error' );
				done();
			} );
	} );
} )();
