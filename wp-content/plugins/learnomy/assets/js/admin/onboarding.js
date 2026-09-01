/**
 * Getting Started onboarding panel — dashboard behavior.
 *
 * Handles dismiss, skip, undo-skip, collapse, and an auto-dismiss flow
 * once every step has been completed or skipped. Fires a short confetti
 * burst when the user first hits 100% in a session.
 */
( function () {
	'use strict';

	var cfg = window.LrnOnboarding;
	if ( ! cfg ) {
		return;
	}

	var root = document.querySelector( '.lrn-onboarding' );
	if ( ! root ) {
		return;
	}

	var admin     = window.learnomyAdmin || {};
	var restUrl   = admin.restUrl || '/wp-json/learnomy/v1/';
	var restNonce = admin.restNonce || '';

	var SNAPSHOT_KEY = 'lrn_onboarding_snapshot';

	// ── REST helper — single PATCH /admin/onboarding-state endpoint
	// handles all four mutations (dismiss/reopen/skip/unskip) by body shape.

	function patchOnboarding( body ) {
		return fetch( restUrl + 'admin/onboarding-state', {
			method:      'PATCH',
			credentials: 'same-origin',
			headers:     {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   restNonce,
			},
			body: JSON.stringify( body || {} ),
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				return { success: res.ok, data: json && json.data, error: json && json.error };
			} ).catch( function () { return { success: false }; } );
		} );
	}

	// The shared toast is `window.lrnToast( message, type )` — a function, and
	// lowercase. This resolved a `window.LrnToast` object and called `.show()`
	// on it: a capitalised global with a `show` method that has never existed
	// in either plugin. The guard above it was permanently false, so every
	// confirmation on this screen was skipped in silence. Found while fixing
	// Basecamp 10185990987.
	function notify( message, type ) {
		if ( ! message || 'function' !== typeof window.lrnToast ) {
			return;
		}
		window.lrnToast( message, type || 'success' );
	}

	// ── Actions ───────────────────────────────────────────────────────────

	function handleDismiss() {
		root.setAttribute( 'data-dismissing', 'true' );
		patchOnboarding( { dismissed: true } ).then( function ( res ) {
			if ( res.success ) {
				setTimeout( function () {
					if ( root && root.parentNode ) {
						root.parentNode.removeChild( root );
					}
				}, 240 );
				notify( ( cfg.i18n && cfg.i18n.dismissed ) || '' );
			} else {
				root.removeAttribute( 'data-dismissing' );
				notify( ( cfg.i18n && cfg.i18n.error ) || 'Error', 'error' );
			}
		} );
	}

	function handleSkip( btn ) {
		var stepId = btn.getAttribute( 'data-step' );
		if ( ! stepId ) {
			return;
		}
		btn.disabled = true;
		patchOnboarding( { skip: stepId } ).then( function ( res ) {
			btn.disabled = false;
			if ( res.success ) {
				// Full reload keeps the DOM authoritative without re-implementing
				// the PHP render in JS — onboarding state changes are infrequent.
				window.location.reload();
			} else {
				notify( ( res.error && res.error.message ) || '', 'error' );
			}
		} );
	}

	function handleUndoSkip( btn ) {
		var stepId = btn.getAttribute( 'data-step' );
		if ( ! stepId ) {
			return;
		}
		btn.disabled = true;
		patchOnboarding( { unskip: stepId } ).then( function ( res ) {
			btn.disabled = false;
			if ( res.success ) {
				window.location.reload();
			}
		} );
	}

	function handleReopen() {
		patchOnboarding( { dismissed: false } ).then( function ( res ) {
			if ( res.success ) {
				window.location.reload();
			} else {
				notify( ( res.error && res.error.message ) || 'Could not reopen guide.', 'error' );
			}
		} );
	}

	function handleToggle( btn ) {
		var expanded = 'true' === btn.getAttribute( 'aria-expanded' );
		btn.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
		if ( expanded ) {
			root.setAttribute( 'data-collapsed', 'true' );
		} else {
			root.removeAttribute( 'data-collapsed' );
		}
	}

	// ── Delegation ────────────────────────────────────────────────────────

	root.addEventListener( 'click', function ( event ) {
		var dismiss = event.target.closest( '.lrn-onboarding__dismiss' );
		if ( dismiss ) {
			event.preventDefault();
			handleDismiss();
			return;
		}

		var skip = event.target.closest( '.lrn-onb-skip' );
		if ( skip ) {
			event.preventDefault();
			handleSkip( skip );
			return;
		}

		var undo = event.target.closest( '.lrn-onb-undo-skip' );
		if ( undo ) {
			event.preventDefault();
			handleUndoSkip( undo );
			return;
		}

		var reopen = event.target.closest( '.lrn-onb-reopen' );
		if ( reopen ) {
			event.preventDefault();
			handleReopen();
			return;
		}

		var toggle = event.target.closest( '.lrn-onboarding__toggle' );
		if ( toggle ) {
			event.preventDefault();
			handleToggle( toggle );
			return;
		}

		// Actionable CTA buttons ([data-onboarding-action]) are deliberately NOT
		// handled here. Each in-page action is owned by the file that implements
		// it, and admin.js listens on document for its own trigger contract —
		// remove_demo used to be forwarded from here by synthesising a click on
		// a button belonging to another widget, which broke the instant that
		// widget stopped rendering (Basecamp 10128600685).
	} );

	// ── Newly-completed animation ─────────────────────────────────────────

	function diffAndCelebrate( previous, current ) {
		if ( ! previous || ! current || typeof previous !== 'object' || typeof current !== 'object' ) {
			return;
		}
		Object.keys( current ).forEach( function ( stepId ) {
			if ( 'completed' === current[ stepId ] && 'completed' !== previous[ stepId ] ) {
				var card = root.querySelector( '.lrn-onboarding__card[data-step="' + stepId + '"]' );
				if ( card ) {
					card.classList.add( 'lrn-onboarding__card--just-completed' );
				}
			}
		} );
	}

	try {
		var prevRaw = window.sessionStorage.getItem( SNAPSHOT_KEY );
		if ( prevRaw && cfg.currentState ) {
			diffAndCelebrate( JSON.parse( prevRaw ), cfg.currentState );
		}
		if ( cfg.currentState ) {
			window.sessionStorage.setItem( SNAPSHOT_KEY, JSON.stringify( cfg.currentState ) );
		}
	} catch ( e ) {
		// sessionStorage unavailable — non-fatal.
	}

	// ── All-done confetti + auto-dismiss ──────────────────────────────────

	if ( cfg.isAllDone ) {
		if ( window.LrnConfetti && typeof window.LrnConfetti.burst === 'function' ) {
			window.LrnConfetti.burst();
		} else if ( window.confetti && typeof window.confetti === 'function' ) {
			window.confetti();
		}
		setTimeout( function () {
			handleDismiss();
		}, 5000 );
	}
} )();
