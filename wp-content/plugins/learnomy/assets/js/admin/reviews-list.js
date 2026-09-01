/**
 * Learnomy — Reviews moderation page scripts.
 *
 * Single page: ?page=learnomy-reviews. Handles per-row moderation actions
 * (approve / reject / spam / pending / delete) and bulk actions that route
 * through the same JS bridge (`lrn:bulk-action`) used by every other admin
 * list page.
 *
 * ES5 style + cookie-auth REST + window.lrnConfirm / window.lrnToast — no
 * native dialogs, no inline scripts, no jQuery.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var __      = wp.i18n.__;
	var _n      = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	var cfg       = window.learnomyAdmin || {};
	var restUrl   = cfg.restUrl || '/wp-json/learnomy/v1/';
	var restNonce = cfg.restNonce || '';

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

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

	// Queued for the page we are about to reload into — see `lrnToast.flash()`.
	// Keeps this file's reversed ( type, message ) order on purpose.
	toast.flash = function ( type, message ) {
		if ( window.lrnToast ) {
			window.lrnToast.flash( message, type );
		}
	};

	function confirmDialog( options ) {
		if ( window.lrnConfirm ) {
			return window.lrnConfirm( options );
		}
		// Without the global confirm modal, fail closed — the moderator should
		// re-load the page rather than silently triggering a destructive op.
		return Promise.resolve( false );
	}

	// Map a single-row action verb to (status-endpoint, status-value) or
	// signal "this verb deletes". Centralised so per-row + bulk paths share
	// one definition.
	var ACTION_MAP = {
		approve: { kind: 'status', status: 'approved', label: __( 'Approved', 'learnomy' ), msg: __( 'Review approved.', 'learnomy' ) },
		reject:  { kind: 'status', status: 'rejected', label: __( 'Rejected', 'learnomy' ), msg: __( 'Review rejected.', 'learnomy' ) },
		spam:    { kind: 'status', status: 'spam',     label: __( 'Spam', 'learnomy' ),     msg: __( 'Review marked as spam.', 'learnomy' ) },
		pending: { kind: 'status', status: 'pending',  label: __( 'Pending', 'learnomy' ),  msg: __( 'Marked as pending.', 'learnomy' ) },
		delete:  { kind: 'delete' }
	};

	// Map a status slug to its admin.css badge modifier. Mirrors the PHP-side
	// map in includes/admin/views/reviews.php so the JS can repaint the badge
	// after a status flip without a full reload.
	var STATUS_BADGE_MAP = {
		pending:  'warn',
		approved: 'success',
		rejected: 'danger',
		spam:     'danger'
	};

	function repaintStatusBadge( row, nextStatus, nextLabel ) {
		// The status pill is rendered by \Learnomy\Admin\UX::status_badge(),
		// which emits `.lrn-status-badge` (NOT a `.lrn-review-status` class) — so
		// select the canonical family class or the repaint silently no-ops.
		var badge = row.querySelector( '.lrn-status-badge' );
		if ( ! badge ) { return; }

		// Drop every modifier — defensive in case the PHP-rendered class list
		// changes later — then add the new one.
		badge.className = badge.className
			.split( /\s+/ )
			.filter( function ( cls ) { return cls.indexOf( 'lrn-status-badge--' ) !== 0; } )
			.join( ' ' );
		badge.classList.add( 'lrn-status-badge' );
		badge.classList.add( 'lrn-status-badge--' + ( STATUS_BADGE_MAP[ nextStatus ] || 'default' ) );
		badge.textContent = nextLabel;
		row.setAttribute( 'data-status', nextStatus );
	}

	// Re-render the row-actions strip after a status change so the verb that
	// matches the current status is hidden (you can't "approve" an approved
	// row). Uses textContent + DOM APIs only — no innerHTML / no user data
	// concatenated into markup.
	function refreshRowActions( row ) {
		var status = row.getAttribute( 'data-status' );
		row.querySelectorAll( '.row-actions [data-action]' ).forEach( function ( link ) {
			var verb = link.getAttribute( 'data-action' );
			var hide = (
				( verb === 'approve' && status === 'approved' ) ||
				( verb === 'reject'  && status === 'rejected' ) ||
				( verb === 'spam'    && status === 'spam' ) ||
				( verb === 'pending' && status === 'pending' )
			);
			var span = link.parentNode;
			if ( span ) {
				span.style.display = hide ? 'none' : '';
			}
		} );
	}

	// -------------------------------------------------------------------------
	// Per-row actions
	// -------------------------------------------------------------------------

	function bindRowActions() {
		var table = document.getElementById( 'lrn-reviews-table' );
		if ( ! table ) { return; }

		table.addEventListener( 'click', function ( e ) {
			var link = e.target.closest( '.lrn-review-action' );
			if ( ! link ) { return; }
			e.preventDefault();
			// Claim this click exclusively. The buttons also carry `data-action`,
			// which the global family row-actions handler (actions.js) listens for
			// on document; without stopPropagation it ALSO fires, falling through
			// to a non-existent admin-ajax action and showing a spurious error
			// toast on every approve/reject/spam.
			e.stopPropagation();

			var row = link.closest( 'tr' );
			if ( ! row ) { return; }

			var reviewId = row.getAttribute( 'data-review-id' );
			var action   = link.getAttribute( 'data-action' );

			// Reply is not a moderation status — reveal the inline reply form.
			if ( action === 'reply' ) {
				var replyForm = row.querySelector( '[data-role="review-reply-form"]' );
				if ( replyForm ) {
					replyForm.hidden = ! replyForm.hidden;
					if ( ! replyForm.hidden ) {
						var replyInput = replyForm.querySelector( '.lrn-review-reply-input' );
						if ( replyInput ) { replyInput.focus(); }
					}
				}
				return;
			}

			var def = ACTION_MAP[ action ];
			if ( ! reviewId || ! def ) { return; }

			if ( def.kind === 'delete' ) {
				confirmDialog( {
					title:        __( 'Delete review', 'learnomy' ),
					message:      __( 'This permanently removes the review and recomputes the course rating. Continue?', 'learnomy' ),
					confirmLabel: __( 'Delete', 'learnomy' ),
					destructive:  true
				} ).then( function ( ok ) {
					if ( ! ok ) { return; }
					restCall( 'DELETE', 'reviews/' + encodeURIComponent( reviewId ) )
						.then( function () {
							if ( row.parentNode ) { row.parentNode.removeChild( row ); }
							toast( 'success', 'Review deleted.' );
						} )
						.catch( function ( err ) { toast( 'error', err.message ); } );
				} );
				return;
			}

			// Status change — guard destructive moderation calls (reject / spam)
			// behind a confirm so a misclick on a long row doesn't silently
			// hide the review from the catalog.
			var needsConfirm = ( action === 'reject' || action === 'spam' );
			var confirmPromise = needsConfirm
				? confirmDialog( {
					title:        action === 'spam' ? __( 'Mark as spam', 'learnomy' ) : __( 'Reject review', 'learnomy' ),
					message:      __( 'The review will be hidden from the course page and excluded from the average rating.', 'learnomy' ),
					confirmLabel: action === 'spam' ? __( 'Mark as spam', 'learnomy' ) : __( 'Reject', 'learnomy' ),
					destructive:  true
				} )
				: Promise.resolve( true );

			confirmPromise.then( function ( ok ) {
				if ( ! ok ) { return; }
				restCall( 'PATCH', 'admin/reviews/' + encodeURIComponent( reviewId ) + '/status', { status: def.status } )
					.then( function () {
						repaintStatusBadge( row, def.status, def.label );
						refreshRowActions( row );
						toast( 'success', def.msg );
					} )
					.catch( function ( err ) { toast( 'error', err.message ); } );
			} );
		} );
	}

	// -------------------------------------------------------------------------
	// Reply form (save / cancel). These buttons are NOT .lrn-review-action, so
	// they route here, not through the moderation handler. Reply persists via
	// PATCH /reviews/{id} { reply } (update_review, gated to owner/admin).
	// -------------------------------------------------------------------------

	function bindReplyForm() {
		var table = document.getElementById( 'lrn-reviews-table' );
		if ( ! table ) { return; }

		table.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-action="reply-save"], [data-action="reply-cancel"]' );
			if ( ! btn ) { return; }
			e.preventDefault();
			e.stopPropagation();

			var form = btn.closest( '[data-role="review-reply-form"]' );
			var row  = btn.closest( 'tr' );
			if ( ! form || ! row ) { return; }

			if ( btn.getAttribute( 'data-action' ) === 'reply-cancel' ) {
				form.hidden = true;
				return;
			}

			var reviewId = row.getAttribute( 'data-review-id' );
			var input    = form.querySelector( '.lrn-review-reply-input' );
			var text     = input ? input.value.trim() : '';
			if ( ! reviewId ) { return; }

			btn.disabled = true;
			restCall( 'PATCH', 'reviews/' + encodeURIComponent( reviewId ), { reply: text } )
				.then( function () {
					var current = row.querySelector( '[data-role="review-reply-current"]' );
					if ( text ) {
						if ( current ) {
							var span = current.querySelector( '[data-role="review-reply-text"]' );
							if ( span ) { span.textContent = text; }
						} else {
							// Build "Your reply: …" with DOM APIs only (no innerHTML).
							current = document.createElement( 'p' );
							current.className = 'lrn-review-reply';
							current.setAttribute( 'data-role', 'review-reply-current' );
							var strong = document.createElement( 'strong' );
							strong.textContent = __( 'Your reply:', 'learnomy' ) + ' ';
							var textSpan = document.createElement( 'span' );
							textSpan.setAttribute( 'data-role', 'review-reply-text' );
							textSpan.textContent = text;
							current.appendChild( strong );
							current.appendChild( textSpan );
							form.parentNode.insertBefore( current, form );
						}
					} else if ( current && current.parentNode ) {
						current.parentNode.removeChild( current );
					}
					form.hidden = true;
					toast( 'success', text ? __( 'Reply saved.', 'learnomy' ) : __( 'Reply removed.', 'learnomy' ) );
				} )
				.catch( function ( err ) { toast( 'error', err.message ); } )
				.finally( function () { btn.disabled = false; } );
		} );
	}

	// -------------------------------------------------------------------------
	// Bulk actions — hook the lrn:bulk-action event from list-page.js
	// -------------------------------------------------------------------------

	function bindBulkActions() {
		if ( ! document.getElementById( 'lrn-reviews-table' ) ) { return; }

		document.addEventListener( 'lrn:bulk-action', function ( e ) {
			var detail = e.detail || {};
			var ids    = detail.ids || [];
			var action = detail.action;

			if ( ! ids.length || ! action || ! ACTION_MAP[ action ] ) {
				return;
			}

			var confirmPromise;
			if ( action === 'delete' ) {
				confirmPromise = confirmDialog( {
					title:        __( 'Delete reviews', 'learnomy' ),
					/* translators: %d: number of reviews */
					message:      sprintf( _n( 'Delete %d review? This permanently removes it and recomputes the course rating.', 'Delete %d reviews? This permanently removes them and recomputes the course rating.', ids.length, 'learnomy' ), ids.length ),
					confirmLabel: __( 'Delete', 'learnomy' ),
					destructive:  true
				} );
			} else if ( action === 'reject' || action === 'spam' ) {
				confirmPromise = confirmDialog( {
					title:        action === 'spam' ? __( 'Mark as spam', 'learnomy' ) : __( 'Reject reviews', 'learnomy' ),
					/* translators: %d: number of reviews */
					message:      sprintf( _n( 'Apply to %d review? It will be hidden from the catalog.', 'Apply to %d reviews? They will be hidden from the catalog.', ids.length, 'learnomy' ), ids.length ),
					confirmLabel: action === 'spam' ? __( 'Mark as spam', 'learnomy' ) : __( 'Reject', 'learnomy' ),
					destructive:  true
				} );
			} else {
				confirmPromise = Promise.resolve( true );
			}

			confirmPromise.then( function ( ok ) {
				if ( ! ok ) { return; }
				restCall( 'POST', 'admin/reviews/bulk', { ids: ids, action: action } )
					.then( function ( data ) {
						var count = ( data && data.count ) || 0;
						toast.flash( 'success', count + ' review(s) updated.' );
						window.location.reload();
					} )
					.catch( function ( err ) { toast( 'error', err.message ); } );
			} );
		} );
	}

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	function init() {
		bindRowActions();
		bindReplyForm();
		bindBulkActions();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
