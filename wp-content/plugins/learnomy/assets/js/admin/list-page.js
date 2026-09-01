/**
 * Learnomy — Shared list page behaviors (Pattern B)
 *
 * Select-all checkbox, bulk action handling, confirm delete,
 * row hover actions. Loaded on all Pattern B admin pages.
 *
 * @package Learnomy
 */
(function () {
	'use strict';

	var __      = wp.i18n.__;
	var _n      = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;

	/* ── Select-all checkbox ── */

	var selectAll = document.getElementById( 'lrn-select-all' );
	if ( selectAll ) {
		selectAll.addEventListener( 'change', function () {
			var checked  = selectAll.checked;
			var boxes    = document.querySelectorAll( '.lrn-row-check' );
			boxes.forEach( function ( box ) {
				box.checked = checked;
				box.closest( 'tr' ).classList.toggle( 'is-selected', checked );
			});
			updateBulkBar();
		});
	}

	// Per-row checkbox change.
	document.querySelectorAll( '.lrn-row-check' ).forEach( function ( box ) {
		box.addEventListener( 'change', function () {
			box.closest( 'tr' ).classList.toggle( 'is-selected', box.checked );
			syncSelectAll();
			updateBulkBar();
		});
	});

	/* ── Filter dropdowns auto-submit ──
	 * Changing a filter <select> in an admin list filter bar applies it
	 * immediately, instead of requiring a separate "Filter"/"Search" click.
	 * Scoped to the shared filter-form containers, so the bulk-action select
	 * (.lrn-bulk-bar) and AJAX create forms are never caught. The free-text
	 * search input is left alone — it still submits on Enter / its button so
	 * we don't reload on every keystroke. Dropping `paged` resets to page 1,
	 * avoiding an out-of-range page when the new filter has fewer results.
	 */
	document.querySelectorAll(
		'.lrn-search-form select, form.lrn-admin-filters select, form.lrn-filter-bar select'
	).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			var form = select.closest( 'form' );
			if ( ! form ) {
				return;
			}
			var paged = form.querySelector( '[name="paged"]' );
			if ( paged ) {
				paged.value = '1';
			}
			if ( typeof form.requestSubmit === 'function' ) {
				form.requestSubmit();
			} else {
				form.submit();
			}
		});
	});

	/**
	 * Keep the select-all checkbox in sync with row checkboxes.
	 */
	function syncSelectAll() {
		if ( ! selectAll ) {
			return;
		}
		var boxes   = document.querySelectorAll( '.lrn-row-check' );
		var checked = document.querySelectorAll( '.lrn-row-check:checked' );
		selectAll.checked       = boxes.length > 0 && boxes.length === checked.length;
		selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
	}

	/**
	 * Show/hide the bulk actions bar based on selection count.
	 */
	function updateBulkBar() {
		var bar   = document.querySelector( '.lrn-bulk-bar' );
		var count = document.querySelectorAll( '.lrn-row-check:checked' ).length;
		if ( ! bar ) {
			return;
		}
		if ( count > 0 ) {
			bar.classList.remove( 'lrn-bulk-bar--hidden' );
			var label = bar.querySelector( '.lrn-bulk-bar__count' );
			if ( label ) {
				label.textContent = count + ' selected';
			}
		} else {
			bar.classList.add( 'lrn-bulk-bar--hidden' );
		}
	}

	/* ── Shared list context resolver ──
	 *
	 * The .wrap container declares the REST resource, base, and nonce as
	 * data-* attributes. Both the bulk-action handler and the single-row
	 * delete handler read from this single source of truth so a list page
	 * wires every delete affordance just by declaring the data attributes.
	 */
	function resolveListContext() {
		var container = document.querySelector( '.wrap[data-list-resource]' );
		if ( ! container ) {
			return null;
		}
		var resource = container.getAttribute( 'data-list-resource' ) || '';
		if ( ! resource ) {
			return null;
		}
		var nonce = container.getAttribute( 'data-list-nonce' )
			|| ( window.wpApiSettings && window.wpApiSettings.nonce )
			|| '';
		var restBase = container.getAttribute( 'data-list-rest-base' )
			|| ( window.wpApiSettings && window.wpApiSettings.root
				? window.wpApiSettings.root + 'learnomy/v1/'
				: '/wp-json/learnomy/v1/' );
		return { resource: resource, nonce: nonce, restBase: restBase };
	}

	/* ── Bulk action apply ── */

	var bulkApply = document.getElementById( 'lrn-bulk-apply' );
	if ( bulkApply ) {
		bulkApply.addEventListener( 'click', async function ( e ) {
			e.preventDefault();
			var select = document.getElementById( 'lrn-bulk-action' );
			if ( ! select || select.value === '' ) {
				return;
			}

			var action = select.value;
			var ids    = [];
			document.querySelectorAll( '.lrn-row-check:checked' ).forEach( function ( box ) {
				ids.push( box.value );
			});

			if ( ids.length === 0 ) {
				return;
			}

			// Confirm destructive actions.
			if ( action === 'delete' ) {
				var okBulk = await window.lrnConfirm( {
					title:        __( 'Delete items', 'learnomy' ),
					/* translators: %d: number of items to delete */
					message:      sprintf( _n( 'Are you sure you want to delete %d item? This cannot be undone.', 'Are you sure you want to delete %d items? This cannot be undone.', ids.length, 'learnomy' ), ids.length ),
					confirmLabel: __( 'Delete', 'learnomy' ),
					destructive:  true,
				} );
				if ( ! okBulk ) {
					return;
				}
			}

			// Dispatch custom event for page-specific handlers (memberships
			// has its own listener with status-toggle wiring). The default
			// generic handler below catches the standard REST resources
			// (courses, lessons, quizzes, certificates, question-bank,
			// instructor-applications) so every list page's bulk-action
			// dropdown is wired without per-page boilerplate.
			var event = new CustomEvent( 'lrn:bulk-action', {
				detail: { action: action, ids: ids, defaultPrevented: false },
				cancelable: true,
			});
			var allowDefault = document.dispatchEvent( event );
			if ( ! allowDefault || event.defaultPrevented ) {
				return; // Page-specific handler took it.
			}

			runGenericBulkAction( action, ids );
		});
	}

	/* ── Generic bulk action handler ──
	 *
	 * Reads the resource + status-action map from `data-*` attributes on
	 * the .wrap container so each list-page template declares its own
	 * mapping without per-page JS. Without this, courses/lessons/quizzes/
	 * certificates/question-bank/instructor-applications all rendered the
	 * dropdown + Apply button but did literally nothing on click — only
	 * memberships had its own listener (see memberships.js:156).
	 */
	function runGenericBulkAction( action, ids ) {
		var ctx = resolveListContext();
		if ( ! ctx ) {
			return; // No declared resource — silently no-op (memberships path).
		}

		var container = document.querySelector( '.wrap[data-list-resource]' );
		var resource  = ctx.resource;
		var nonce     = ctx.nonce;
		var restBase  = ctx.restBase;

		// Status-action map: which dropdown values should PATCH the
		// resource's status field, and to what value? Read from the
		// data-list-status-map attribute as a JSON object.
		var statusMap = {};
		try {
			statusMap = JSON.parse( container.getAttribute( 'data-list-status-map' ) || '{}' );
		} catch ( e ) {
			statusMap = {};
		}

		// Action map for sub-endpoint actions like approve/reject/revoke
		// where the REST surface is `POST /resource/{id}/<action>` instead
		// of a status PATCH. Each entry: { "approve": { "method": "POST",
		// "suffix": "/approve" } }. Suffix is appended after the id segment.
		var actionMap = {};
		try {
			actionMap = JSON.parse( container.getAttribute( 'data-list-action-map' ) || '{}' );
		} catch ( e ) {
			actionMap = {};
		}

		var promises = ids.map( function ( id ) {
			var url, options;
			if ( action === 'delete' ) {
				url = restBase + resource + '/' + encodeURIComponent( id );
				options = { method: 'DELETE', headers: { 'X-WP-Nonce': nonce } };
			} else if ( actionMap[ action ] ) {
				var spec = actionMap[ action ];
				url = restBase + resource + '/' + encodeURIComponent( id ) + ( spec.suffix || '' );
				options = {
					method:  spec.method || 'POST',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
					body:    JSON.stringify( spec.body || {} ),
				};
			} else if ( statusMap[ action ] ) {
				url = restBase + resource + '/' + encodeURIComponent( id );
				options = {
					method: 'PATCH',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce,
					},
					body: JSON.stringify( { status: statusMap[ action ] } ),
				};
			} else {
				return Promise.resolve(); // Unknown action — skip.
			}

			return fetch( url, options ).then( function ( r ) {
				if ( ! r.ok ) {
					return r.json().then( function ( body ) {
						throw new Error( ( body && ( body.message || ( body.error && body.error.message ) ) ) || ( 'Request failed: ' + r.status ) );
					} );
				}
				return r.json();
			} );
		} );

		Promise.all( promises )
			.then( function () {
				window.location.reload();
			} )
			.catch( function ( err ) {
				var msg = err.message || __( 'Bulk action failed.', 'learnomy' );
				if ( window.lrnToast ) {
					window.lrnToast( msg, 'error' );
				} else if ( window.console && window.console.error ) {
					window.console.error( msg );
				}
			} );
	}

	/* ── Single-row certificate revoke ──
	 *
	 * The bulk machinery wires `revoke` for the whole table, but the per-row
	 * Revoke button had no handler at all (C-24). Mirror the bulk fetch: read
	 * the revoke sub-endpoint suffix from data-list-action-map, build the URL
	 * from the row's UUID (the route matches a 36-char UUID, never the numeric
	 * id), confirm with lrnConfirm, and reflect the row as Revoked in place on
	 * success so the admin doesn't lose their scroll position.
	 */
	document.querySelectorAll( '.lrn-revoke-certificate' ).forEach( function ( button ) {
		button.addEventListener( 'click', async function ( e ) {
			e.preventDefault();

			var uuid = button.getAttribute( 'data-uuid' ) || '';
			if ( ! uuid ) {
				return;
			}

			var okRevoke = await window.lrnConfirm( {
				title:        __( 'Revoke certificate', 'learnomy' ),
				message:      __( 'Are you sure you want to revoke this certificate? The student will no longer be able to verify or download it.', 'learnomy' ),
				confirmLabel: __( 'Revoke', 'learnomy' ),
				destructive:  true,
			} );
			if ( ! okRevoke ) {
				return;
			}

			var ctx = resolveListContext();
			if ( ! ctx ) {
				return;
			}

			var container = document.querySelector( '.wrap[data-list-resource]' );
			var actionMap = {};
			try {
				actionMap = JSON.parse( container.getAttribute( 'data-list-action-map' ) || '{}' );
			} catch ( parseErr ) {
				actionMap = {};
			}
			var spec   = actionMap.revoke || { method: 'POST', suffix: '/revoke' };
			var url     = ctx.restBase + ctx.resource + '/' + encodeURIComponent( uuid ) + ( spec.suffix || '/revoke' );

			button.disabled = true;
			try {
				var res = await fetch( url, {
					method:  spec.method || 'POST',
					headers: { 'X-WP-Nonce': ctx.nonce, 'Content-Type': 'application/json' },
					body:    JSON.stringify( {} ),
				} );
				if ( ! res.ok ) {
					var body = await res.json().catch( function () { return {}; } );
					throw new Error( ( body && ( body.message || ( body.error && body.error.message ) ) ) || ( 'Revoke failed: ' + res.status ) );
				}

				// Reflect the row as Revoked in place: flip the status badge and
				// drop the now-irrelevant Revoke button.
				var row   = button.closest( 'tr' );
				var badge = row ? row.querySelector( '.lrn-status-badge' ) : null;
				if ( badge ) {
					badge.classList.remove( 'lrn-status-badge--active' );
					badge.classList.add( 'lrn-status-badge--revoked' );
					badge.textContent = __( 'Revoked', 'learnomy' );
				}
				button.remove();
				if ( window.lrnToast ) {
					window.lrnToast( __( 'Certificate revoked.', 'learnomy' ), 'success' );
				}
			} catch ( err ) {
				button.disabled = false;
				var msg = err.message || __( 'Revoke failed.', 'learnomy' );
				if ( window.lrnToast ) {
					window.lrnToast( msg, 'error' );
				} else if ( window.console && window.console.error ) {
					window.console.error( msg );
				}
			}
		} );
	} );

	/* ── Confirm delete on single row ── */

	document.querySelectorAll( '.row-actions .delete a, .row-actions .trash a' ).forEach( function ( link ) {
		link.addEventListener( 'click', async function ( e ) {
			// Prevent the native navigation so we can wait for the user's
			// choice; the link itself is href="#" — the actual delete goes
			// through the REST resource declared on the .wrap container.
			e.preventDefault();

			var okRow = await window.lrnConfirm( {
				title:        __( 'Delete item', 'learnomy' ),
				message:      __( 'Are you sure you want to delete this item? This cannot be undone.', 'learnomy' ),
				confirmLabel: __( 'Delete', 'learnomy' ),
				destructive:  true,
			} );
			if ( ! okRow ) {
				return;
			}

			// Resolve the row id: prefer the row checkbox value (shared across
			// every Pattern B list), fall back to a data-*-id attribute on the
			// link itself (e.g. data-lesson-id).
			var row = link.closest( 'tr' );
			var id  = '';
			var checkbox = row ? row.querySelector( '.lrn-row-check' ) : null;
			if ( checkbox && checkbox.value ) {
				id = checkbox.value;
			} else {
				Object.keys( link.dataset ).some( function ( key ) {
					if ( /Id$/.test( key ) && link.dataset[ key ] ) {
						id = link.dataset[ key ];
						return true;
					}
					return false;
				} );
			}

			// If there is a declared REST resource, delete through it and
			// drop the row in place. Without one, fall back to the link href
			// (legacy admin-post style pages).
			var ctx = resolveListContext();
			if ( ctx && id ) {
				var url = ctx.restBase + ctx.resource + '/' + encodeURIComponent( id );
				try {
					var res = await fetch( url, {
						method:  'DELETE',
						headers: { 'X-WP-Nonce': ctx.nonce },
					} );
					if ( ! res.ok ) {
						var body = await res.json().catch( function () { return {}; } );
						throw new Error( ( body && ( body.message || ( body.error && body.error.message ) ) ) || ( 'Delete failed: ' + res.status ) );
					}
					if ( row ) {
						row.remove();
					}
					if ( window.lrnToast ) {
						window.lrnToast( __( 'Deleted.', 'learnomy' ), 'success' );
					}
				} catch ( err ) {
					var msg = err.message || __( 'Delete failed.', 'learnomy' );
					if ( window.lrnToast ) {
						window.lrnToast( msg, 'error' );
					} else if ( window.console && window.console.error ) {
						window.console.error( msg );
					}
				}
				return;
			}

			var href = link.getAttribute( 'href' );
			if ( href && href !== '#' ) {
				window.location.href = href;
			}
		});
	});

	/* ── Toast ──
	 *
	 * There is exactly ONE toast implementation: assets/js/shared/toast.js,
	 * enqueued as `learnomy-toast` (a declared dependency of this script). This
	 * file used to define a SECOND `window.lrnToast` here, with the arguments
	 * in the opposite order — `( type, message )` instead of the canonical
	 * `( message, type, duration )`. Whichever script loaded last silently won,
	 * so on every admin list page a correct `lrnToast( 'Cookie check failed',
	 * 'error' )` rendered the literal word "error" as the message and set the
	 * class to `lrn-toast--Cookie check failed`, which matched no style and no
	 * icon. Do not re-add a local toast here — call window.lrnToast directly.
	 */

	/* ── Init ── */
	updateBulkBar();
})();
