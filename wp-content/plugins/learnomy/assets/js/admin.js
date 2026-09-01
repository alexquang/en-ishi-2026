/**
 * Learnomy admin JS — handles AJAX actions from admin views.
 *
 * @package Learnomy
 */
(function () {
	'use strict';

	const { __ } = wp.i18n;
	const { ajaxUrl, nonce } = window.learnomyAdmin || {};
	const adminRestUrl   = window.learnomyAdmin ? window.learnomyAdmin.restUrl : '';
	const adminRestNonce = window.learnomyAdmin ? window.learnomyAdmin.restNonce : '';

	if ( ! ajaxUrl ) {
		return;
	}

	// Demo content removal. This is the ONLY implementation of the flow —
	// every surface that offers "remove the demo data" routes here, and no
	// other file may re-author the trigger.
	//
	// The trigger contract is two selectors, matched by ONE delegated listener:
	//   .js-lrn-remove-demo                     — a standalone button (demo-alert widget)
	//   [data-onboarding-action="remove_demo"]  — the Getting Started checklist CTA
	//
	// History, so nobody re-introduces either shape:
	//   1. It was bound with getElementById, so when two surfaces rendered the
	//      duplicate id made only the first one live (Basecamp 10128600685).
	//   2. The class fixed that, but onboarding.js then reached across files to
	//      synthesise a click on a button owned by another surface — which
	//      silently no-ops the moment that surface is not on the page. Both
	//      shapes are now gone: delegation means the handler does not care
	//      which surface rendered, how many did, or when they appeared.
	var LRN_DEMO_TRIGGER = '.js-lrn-remove-demo, [data-onboarding-action="remove_demo"]';

	var setDemoBtnsState = function ( disabled, label ) {
		Array.prototype.forEach.call( document.querySelectorAll( LRN_DEMO_TRIGGER ), function ( b ) {
			b.disabled = disabled;
			b.textContent = label;
		} );
	};

	document.addEventListener( 'click', async function ( event ) {
		var trigger = event.target.closest( LRN_DEMO_TRIGGER );
		if ( ! trigger ) {
			return;
		}
		event.preventDefault();

		var ok = await window.lrnConfirm( {
			title:        learnomyAdmin.removeDemoTitle || __( 'Remove demo content', 'learnomy' ),
			message:      learnomyAdmin.removeDemoConfirm || __( 'Remove all demo content?', 'learnomy' ),
			confirmLabel: learnomyAdmin.removeDemoLabel || __( 'Remove Demo Content', 'learnomy' ),
			destructive:  true,
		} );
		if ( ! ok ) {
			return;
		}

		setDemoBtnsState( true, learnomyAdmin.removeDemoLoading || __( 'Removing…', 'learnomy' ) );
		fetch( adminRestUrl + 'admin/setup-state/cleanup-demo', {
			method:      'POST',
			credentials: 'same-origin',
			headers:     {
				'Accept':       'application/json',
				'Content-Type': 'application/json',
				'X-WP-Nonce':   adminRestNonce,
			},
			// The endpoint fails closed without an explicit confirmation flag
			// (destructive: it wipes every demo row). The lrnConfirm dialog
			// above IS that confirmation — without this body the server
			// answered 400 on every click and the button looked dead
			// (Basecamp 10128600685, second bounce).
			body: JSON.stringify( { confirm: true } ),
		} )
			.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, body: j }; } ); } )
			.then( function ( res ) {
				if ( res.ok ) {
					// Reload rather than surgically removing cards: demo removal
					// changes the onboarding step state, the dashboard counts and
					// the alert together, and PHP already renders all three. Same
					// reasoning as onboarding.js's skip/unskip handlers.
					window.location.reload();
				} else {
					setDemoBtnsState( false, learnomyAdmin.removeDemoLabel || __( 'Remove Demo Content', 'learnomy' ) );
				}
			} )
			.catch( function () {
				setDemoBtnsState( false, learnomyAdmin.removeDemoLabel || __( 'Remove Demo Content', 'learnomy' ) );
			} );
	} );

	// Delegate click events on elements with data-lrn-action.
	document.addEventListener( 'click', async function ( e ) {
		const btn = e.target.closest( '[data-lrn-action]' );
		if ( ! btn ) {
			return;
		}

		e.preventDefault();

		const action = btn.dataset.lrnAction;
		const id     = btn.dataset.id || '';
		const extra  = btn.dataset.extra || '';

		if ( btn.classList.contains( 'lrn-confirm' ) ) {
			const ok = await window.lrnConfirm( {
				title:       __( 'Please confirm', 'learnomy' ),
				message:     __( 'Are you sure?', 'learnomy' ),
				destructive: true,
			} );
			if ( ! ok ) {
				return;
			}
		}

		btn.disabled = true;
		btn.textContent = __( 'Processing…', 'learnomy' );

		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', nonce );
		body.append( 'id', id );
		if ( extra ) {
			body.append( 'extra', extra );
		}

		fetch( ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( data.success ) {
					window.location.reload();
				} else {
					window.lrnToast( data.data || __( 'Action failed.', 'learnomy' ), 'error' );
					btn.disabled = false;
					btn.textContent = __( 'Retry', 'learnomy' );
				}
			} )
			.catch( function () {
				window.lrnToast( __( 'Network error. Please try again.', 'learnomy' ), 'error' );
				btn.disabled = false;
				btn.textContent = __( 'Retry', 'learnomy' );
			} );
	} );

	// ── API Key Management (Settings > Advanced) ─────────────────────────────

	var apiKeysBody     = document.getElementById( 'lrn-api-keys-body' );
	var generateBtn     = document.getElementById( 'lrn-generate-api-key' );
	var labelInput      = document.getElementById( 'lrn-api-key-label' );
	var createdAlert    = document.getElementById( 'lrn-api-key-created' );
	var keyValueDisplay = document.getElementById( 'lrn-api-key-value' );
	var restUrl         = window.learnomyAdmin ? window.learnomyAdmin.restUrl : '';
	var restNonce       = window.learnomyAdmin ? window.learnomyAdmin.restNonce : '';

	function apiKeyHeaders() {
		return {
			'Content-Type': 'application/json',
			'X-WP-Nonce':   restNonce,
		};
	}

	function formatDate( dateStr ) {
		if ( ! dateStr ) {
			return '\u2014';
		}
		var d = new Date( dateStr );
		return isNaN( d.getTime() ) ? dateStr : d.toLocaleDateString();
	}

	/**
	 * Build a table cell with safe text content (no HTML injection).
	 */
	function textCell( text ) {
		var td = document.createElement( 'td' );
		td.textContent = text || '';
		return td;
	}

	function renderApiKeys( keys ) {
		if ( ! apiKeysBody ) {
			return;
		}

		// Clear existing rows safely.
		while ( apiKeysBody.firstChild ) {
			apiKeysBody.removeChild( apiKeysBody.firstChild );
		}

		if ( ! keys.length ) {
			var emptyRow = document.createElement( 'tr' );
			var emptyTd  = document.createElement( 'td' );
			emptyTd.setAttribute( 'colspan', '5' );
			emptyTd.textContent = __( 'No API keys yet.', 'learnomy' );
			emptyRow.appendChild( emptyTd );
			apiKeysBody.appendChild( emptyRow );
			return;
		}

		keys.forEach( function ( key ) {
			var tr = document.createElement( 'tr' );

			tr.appendChild( textCell( key.label ) );

			// Key prefix in a <code> element.
			var prefixTd   = document.createElement( 'td' );
			var prefixCode = document.createElement( 'code' );
			prefixCode.textContent = ( key.key_prefix || '' ) + '…';
			prefixTd.appendChild( prefixCode );
			tr.appendChild( prefixTd );

			tr.appendChild( textCell( formatDate( key.created_at ) ) );
			tr.appendChild( textCell( formatDate( key.last_used_at ) ) );

			// Revoke button.
			var actionTd  = document.createElement( 'td' );
			var revokeBtn = document.createElement( 'button' );
			revokeBtn.type = 'button';
			revokeBtn.className = 'lrn-admin-btn lrn-admin-btn--danger lrn-admin-btn--sm';
			revokeBtn.textContent = __( 'Revoke', 'learnomy' );
			revokeBtn.setAttribute( 'data-revoke-id', key.id );

			revokeBtn.addEventListener( 'click', async function () {
				const ok = await window.lrnConfirm( {
					title:        __( 'Revoke API key', 'learnomy' ),
					message:      __( 'Revoke this API key? This cannot be undone.', 'learnomy' ),
					confirmLabel: __( 'Revoke', 'learnomy' ),
					destructive:  true,
				} );
				if ( ! ok ) {
					return;
				}
				revokeBtn.disabled = true;
				revokeBtn.textContent = __( 'Revoking…', 'learnomy' );
				fetch( restUrl + 'auth/api-keys/' + encodeURIComponent( key.id ), {
					method:      'DELETE',
					headers:     apiKeyHeaders(),
					credentials: 'same-origin',
				} )
				.then( function ( r ) { return r.json(); } )
				.then( function () { loadApiKeys(); } )
				.catch( function () {
					revokeBtn.disabled = false;
					revokeBtn.textContent = __( 'Revoke', 'learnomy' );
				} );
			} );

			actionTd.appendChild( revokeBtn );
			tr.appendChild( actionTd );
			apiKeysBody.appendChild( tr );
		} );
	}

	function loadApiKeys() {
		if ( ! apiKeysBody || ! restUrl ) {
			return;
		}

		fetch( restUrl + 'auth/api-keys', {
			method:      'GET',
			headers:     apiKeyHeaders(),
			credentials: 'same-origin',
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( res ) {
			renderApiKeys( res.data || [] );
		} )
		.catch( function () {
			while ( apiKeysBody.firstChild ) {
				apiKeysBody.removeChild( apiKeysBody.firstChild );
			}
			var errRow = document.createElement( 'tr' );
			var errTd  = document.createElement( 'td' );
			errTd.setAttribute( 'colspan', '5' );
			errTd.textContent = __( 'Failed to load API keys.', 'learnomy' );
			errRow.appendChild( errTd );
			apiKeysBody.appendChild( errRow );
		} );
	}

	if ( generateBtn ) {
		generateBtn.addEventListener( 'click', function () {
			var label = labelInput ? labelInput.value.trim() : '';
			if ( ! label ) {
				labelInput.focus();
				return;
			}

			generateBtn.disabled = true;
			generateBtn.textContent = __( 'Generating…', 'learnomy' );

			fetch( restUrl + 'auth/api-keys', {
				method:      'POST',
				headers:     apiKeyHeaders(),
				credentials: 'same-origin',
				body:        JSON.stringify( { label: label } ),
			} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				generateBtn.disabled = false;
				generateBtn.textContent = __( 'Generate New API Key', 'learnomy' );

				if ( res.data && res.data.api_key ) {
					keyValueDisplay.textContent = res.data.api_key;
					createdAlert.style.display = 'block';
					labelInput.value = '';
					loadApiKeys();
				}
			} )
			.catch( function () {
				generateBtn.disabled = false;
				generateBtn.textContent = __( 'Generate New API Key', 'learnomy' );
			} );
		} );
	}

	// Load keys on page init when the section is present.
	loadApiKeys();
})();
