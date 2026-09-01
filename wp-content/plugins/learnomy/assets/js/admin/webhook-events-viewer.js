/**
 * Webhook events viewer — View Payload modal and Replay button wiring.
 *
 * Depends on: learnomyAdmin (restUrl, restNonce) localized by class-admin.php.
 * Depends on: learnomyWebhookEvents (i18n.replaying) localized per-page.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var restUrl   = ( window.learnomyAdmin && window.learnomyAdmin.restUrl )   ? window.learnomyAdmin.restUrl   : '';
	var restNonce = ( window.learnomyAdmin && window.learnomyAdmin.restNonce ) ? window.learnomyAdmin.restNonce : '';
	var i18n      = ( window.learnomyWebhookEvents && window.learnomyWebhookEvents.i18n )
		? window.learnomyWebhookEvents.i18n
		: {};

	var labelReplaying = i18n.replaying || 'Replaying…';

	// ── Modal helpers ────────────────────────────────────────────────────────

	var modal       = document.getElementById( 'lrn-wh-payload-modal' );
	var modalPre    = document.getElementById( 'lrn-wh-payload-pre' );
	var modalMeta   = document.getElementById( 'lrn-wh-payload-meta' );
	var modalErrDiv = document.getElementById( 'lrn-wh-error-detail' );
	var modalErrTxt = document.getElementById( 'lrn-wh-error-text' );

	function openModal() {
		if ( ! modal ) { return; }
		modal.classList.remove( 'lrn-modal--hidden' );
		modal.removeAttribute( 'hidden' );
		if ( modalPre ) { modalPre.focus(); }
	}

	function closeModal() {
		if ( ! modal ) { return; }
		modal.classList.add( 'lrn-modal--hidden' );
		modal.setAttribute( 'hidden', '' );
	}

	if ( modal ) {
		modal.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) { closeModal(); }
		} );
		modal.querySelectorAll( '[data-lrn-modal-close]' ).forEach( function ( el ) {
			el.addEventListener( 'click', closeModal );
		} );
	}

	// ── View Payload ─────────────────────────────────────────────────────────

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.lrn-wh-view-payload' );
		if ( ! btn || ! restUrl ) { return; }

		var eventId = btn.getAttribute( 'data-event-id' );
		if ( ! eventId ) { return; }

		btn.disabled = true;

		fetch( restUrl + 'admin/webhook-events/' + encodeURIComponent( eventId ), {
			headers: { 'X-WP-Nonce': restNonce }
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( body ) {
			btn.disabled = false;
			var row = body.data || {};

			// Pretty-print the stored payload JSON.
			var rawPayload = row.payload_json || '';
			var pretty     = rawPayload;
			try {
				pretty = JSON.stringify( JSON.parse( rawPayload ), null, 2 );
			} catch ( ignore ) {}

			// Use safe DOM methods — no direct HTML injection.
			if ( modalPre ) { modalPre.textContent = pretty; }

			if ( modalMeta ) {
				var metaText = ( row.provider || '' ) + ' — ' + ( row.event_type || '' ) + ' — ' + ( row.processed_at || '' );
				modalMeta.textContent = metaText;
			}

			if ( modalErrDiv && modalErrTxt ) {
				if ( row.error_message ) {
					modalErrTxt.textContent = row.error_message;
					modalErrDiv.removeAttribute( 'hidden' );
				} else {
					modalErrDiv.setAttribute( 'hidden', '' );
				}
			}

			openModal();
		} )
		.catch( function ( err ) {
			btn.disabled = false;
			window.console.error( 'Learnomy: failed to load webhook payload', err );
		} );
	} );

	// ── Replay ───────────────────────────────────────────────────────────────

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.lrn-wh-replay' );
		if ( ! btn || ! restUrl ) { return; }

		var eventId = btn.getAttribute( 'data-event-id' );
		if ( ! eventId ) { return; }

		var originalText = btn.textContent.trim();
		btn.disabled     = true;
		btn.textContent  = labelReplaying;

		fetch( restUrl + 'admin/webhook-events/' + encodeURIComponent( eventId ) + '/replay', {
			method:  'POST',
			headers: {
				'X-WP-Nonce':   restNonce,
				'Content-Type': 'application/json',
			},
			body: '{}',
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( body ) {
			btn.disabled    = false;
			btn.textContent = originalText;

			var result  = body.data || {};
			var outcome = result.outcome || 'unknown';

			// Update the outcome badge in the row without a page reload.
			var tableRow = btn.closest( 'tr' );
			if ( tableRow ) {
				var outcomeCell = tableRow.querySelector( '.lrn-wh-outcome-cell' );
				if ( outcomeCell ) {
					var modMap = {
						handled:   'success',
						error:     'danger',
						rejected:  'warning',
						duplicate: 'info',
					};
					var mod      = modMap[ outcome ] || 'neutral';
					var capLabel = outcome.charAt( 0 ).toUpperCase() + outcome.slice( 1 );

					var span = document.createElement( 'span' );
					span.className   = 'lrn-status-badge lrn-status-badge--' + mod;
					span.textContent = capLabel;

					// Remove existing badge and insert fresh one.
					while ( outcomeCell.firstChild ) {
						outcomeCell.removeChild( outcomeCell.firstChild );
					}
					outcomeCell.appendChild( span );
				}
			}

			// Show signature rotation warning as a notice above the table.
			if ( result.signature_note ) {
				var note = document.createElement( 'div' );
				note.className   = 'lrn-notice lrn-notice--warning';
				note.textContent = result.signature_note;
				var wrap = document.querySelector( '.lrn-admin-table-wrap' );
				if ( wrap && wrap.parentNode ) {
					wrap.parentNode.insertBefore( note, wrap );
				}
			}
		} )
		.catch( function ( err ) {
			btn.disabled    = false;
			btn.textContent = originalText;
			window.console.error( 'Learnomy: replay failed', err );
		} );
	} );

}() );
