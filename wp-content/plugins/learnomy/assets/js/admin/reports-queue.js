/**
 * Learnomy — Reports moderation queue scripts.
 *
 * Single page: ?page=learnomy-reports. Handles per-row resolve actions
 * (dismiss / act) via POST admin/reports/{id}/resolve. On success the row's
 * status pill + actions update in place so the moderator sees the result
 * without a full reload.
 *
 * ES5 style + cookie-auth REST + window.lrnConfirm / window.lrnToast — no
 * native dialogs, no inline scripts, no jQuery. Mirrors reviews-list.js.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var __ = wp.i18n.__;

	var cfg       = window.learnomyAdmin || {};
	var restUrl   = cfg.restUrl || '/wp-json/learnomy/v1/';
	var restNonce = cfg.restNonce || '';

	function restCall( method, path, body ) {
		var opts = {
			method:      method,
			credentials: 'same-origin',
			headers:     { 'X-WP-Nonce': restNonce },
		};
		if ( body && method !== 'GET' && method !== 'DELETE' ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = JSON.stringify( body );
		}

		return fetch( restUrl + path, opts ).then( function ( response ) {
			return response.json().then( function ( payload ) {
				if ( ! response.ok ) {
					var msg = ( payload && payload.error && payload.error.message )
						|| ( payload && payload.message )
						|| __( 'Request failed.', 'learnomy' );
					throw new Error( msg );
				}
				return payload && Object.prototype.hasOwnProperty.call( payload, 'data' )
					? payload.data
					: ( payload || {} );
			} );
		} );
	}

	function toast( type, message ) {
		if ( window.lrnToast ) {
			// Canonical signature is lrnToast( message, type, duration ).
			window.lrnToast( message, type );
		}
	}

	function confirmDialog( options ) {
		if ( window.lrnConfirm ) {
			return window.lrnConfirm( options );
		}
		// Without the global confirm modal, fail closed — the moderator should
		// re-load rather than silently triggering a resolve.
		return Promise.resolve( false );
	}

	// Resolve verb → (status slug it lands on, badge modifier, label). Mirrors
	// the PHP status map in includes/admin/class-admin.php::report_status_variants.
	var ACTION_MAP = {
		dismiss: { status: 'dismissed', badge: 'muted',   label: __( 'Dismissed', 'learnomy' ), msg: __( 'Report dismissed.', 'learnomy' ) },
		act:     { status: 'actioned',  badge: 'success', label: __( 'Actioned', 'learnomy' ),  msg: __( 'Report actioned.', 'learnomy' ) }
	};

	function repaintStatusBadge( row, badgeMod, label ) {
		var badge = row.querySelector( '.lrn-status-badge' );
		if ( ! badge ) { return; }

		badge.className = badge.className
			.split( /\s+/ )
			.filter( function ( cls ) { return cls.indexOf( 'lrn-status-badge--' ) !== 0; } )
			.join( ' ' );
		badge.classList.add( 'lrn-status-badge' );
		badge.classList.add( 'lrn-status-badge--' + badgeMod );
		badge.textContent = label;
	}

	// A resolved report is terminal — drop the resolve buttons, keep any
	// "View item" link. Uses DOM APIs only (no innerHTML / user data in markup).
	function removeResolveActions( row ) {
		row.querySelectorAll( '.lrn-report-action' ).forEach( function ( link ) {
			var wrap = link.closest( '.lrn-row-actions__menu' ) ? null : link;
			if ( wrap && wrap.parentNode ) {
				wrap.parentNode.removeChild( wrap );
			} else if ( link.parentNode ) {
				link.parentNode.removeChild( link );
			}
		} );
	}

	function bindRowActions() {
		var table = document.getElementById( 'lrn-reports-table' );
		if ( ! table ) { return; }

		table.addEventListener( 'click', function ( e ) {
			var link = e.target.closest( '.lrn-report-action' );
			if ( ! link ) { return; }
			e.preventDefault();
			// Claim this click exclusively so the global family row-actions
			// handler (actions.js) doesn't also fire on the shared data-action.
			e.stopPropagation();

			var row = link.closest( 'tr' );
			if ( ! row ) { return; }

			var reportId = row.getAttribute( 'data-report-id' );
			var action   = link.getAttribute( 'data-action' );
			var def      = ACTION_MAP[ action ];
			if ( ! reportId || ! def ) { return; }

			confirmDialog( {
				title:        action === 'act' ? __( 'Mark actioned', 'learnomy' ) : __( 'Dismiss report', 'learnomy' ),
				message:      action === 'act'
					? __( 'Mark this report as actioned once you have handled the reported item.', 'learnomy' )
					: __( 'Dismiss this report as needing no action?', 'learnomy' ),
				confirmLabel: action === 'act' ? __( 'Mark actioned', 'learnomy' ) : __( 'Dismiss', 'learnomy' ),
				destructive:  false
			} ).then( function ( ok ) {
				if ( ! ok ) { return; }

				// Say something while the request is in flight. Without this the
				// row sat completely static after the confirm dialog closed, so
				// a slow response read as "my click did nothing" and invited a
				// second click on a destructive-ish action (Basecamp
				// 10144266079). aria-busy carries it to screen readers; the
				// pointer-events lock is what actually prevents the double-fire.
				link.setAttribute( 'aria-busy', 'true' );
				link.style.pointerEvents = 'none';
				link.style.opacity       = '0.6';

				var release = function () {
					link.removeAttribute( 'aria-busy' );
					link.style.pointerEvents = '';
					link.style.opacity       = '';
				};

				restCall( 'POST', 'admin/reports/' + encodeURIComponent( reportId ) + '/resolve', { resolution: action } )
					.then( function () {
						// No release() here on purpose: the success path removes
						// these controls outright, so restoring them first would
						// flash them back for a frame.
						repaintStatusBadge( row, def.badge, def.label );
						removeResolveActions( row );
						row.setAttribute( 'data-status', def.status );
						toast( 'success', def.msg );
					} )
					.catch( function ( err ) {
						// The row is still actionable — give the control back so
						// the owner can retry.
						release();
						toast( 'error', err.message );
					} );
			} );
		} );
	}

	function init() {
		bindRowActions();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
