/**
 * Payments settings — mode radio switching, webhook copy button, test-connection.
 *
 * No jQuery. Runs as an IIFE so it stays scoped and doesn't pollute the window.
 * Depends on: learnomyAdmin (localized in class-admin.php: restUrl, restNonce).
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var __      = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	// ── Mode radio switching ───────────────────────────────────────────────────

	/**
	 * Called on every radio change inside a payment card.
	 * Shows the fieldset for the selected mode, hides the other one,
	 * and updates the badge text + variant class.
	 *
	 * Data attributes on each radio:
	 *   data-target      — shared CSS ID prefix, e.g. "lrn-stripe-creds"
	 *   data-badge       — ID of the badge <span>
	 *   data-badge-test  — badge text for test/sandbox mode
	 *   data-badge-live  — badge text for live mode
	 */
	function handleModeChange( radio ) {
		var mode        = radio.value;                        // "test" | "live" | "sandbox"
		var targetBase  = radio.dataset.target;               // e.g. "lrn-stripe-creds"
		var badgeId     = radio.dataset.badge;
		var badgeTest   = radio.dataset.badgeTest  || '';
		var badgeLive   = radio.dataset.badgeLive  || '';

		// Show/hide credential fieldsets.
		// The fieldsets are named: #<targetBase>-<mode> (e.g. #lrn-stripe-creds-test)
		var allFieldsets = document.querySelectorAll( '[id^="' + targetBase + '-"]' );
		allFieldsets.forEach( function ( fs ) {
			if ( fs.dataset.mode === mode ) {
				fs.classList.add( 'lrn-payment-creds--active' );
			} else {
				fs.classList.remove( 'lrn-payment-creds--active' );
			}
		} );

		// Update the badge.
		var badge = badgeId ? document.getElementById( badgeId ) : null;
		if ( badge ) {
			var isLive = ( 'live' === mode );
			badge.textContent = isLive ? badgeLive : badgeTest;
			badge.classList.toggle( 'lrn-settings-badge--live', isLive );
			badge.classList.toggle( 'lrn-settings-badge--test', ! isLive );
		}

		// Update the :has()-fallback class on the toggle options.
		var card = radio.closest( '.lrn-payment-card' );
		if ( card ) {
			card.querySelectorAll( '.lrn-payment-mode-toggle__option' ).forEach( function ( label ) {
				var inp = label.querySelector( 'input[type="radio"]' );
				if ( inp ) {
					label.classList.toggle( 'is-selected', inp.checked );
				}
			} );
		}
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.classList.contains( 'lrn-payment-mode-radio' ) ) {
			handleModeChange( event.target );
		}
	} );

	// Apply :has()-fallback class on page load for the pre-selected radio.
	document.querySelectorAll( '.lrn-payment-mode-radio' ).forEach( function ( radio ) {
		if ( radio.checked ) {
			var label = radio.closest( '.lrn-payment-mode-toggle__option' );
			if ( label ) {
				label.classList.add( 'is-selected' );
			}
		}
	} );

	// ── Webhook URL copy ───────────────────────────────────────────────────────

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.lrn-settings-webhook-url__copy' );
		if ( ! btn ) {
			return;
		}

		var url = btn.dataset.copy || '';
		if ( ! url ) {
			return;
		}

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then( function () {
				flashCopied( btn );
			} ).catch( function () {
				fallbackCopy( url, btn );
			} );
		} else {
			fallbackCopy( url, btn );
		}
	} );

	function flashCopied( btn ) {
		var originalNodes = Array.from( btn.childNodes ).map( function ( n ) {
			return n.cloneNode( true );
		} );
		var originalDisabled = btn.disabled;
		var originalAriaLive = btn.getAttribute( 'aria-live' );

		// Build "Copied!" state using DOM methods only (no innerHTML).
		var checkSvg = buildCheckSvg();
		var textNode  = document.createTextNode( ' Copied!' );
		while ( btn.firstChild ) {
			btn.removeChild( btn.firstChild );
		}
		btn.appendChild( checkSvg );
		btn.appendChild( textNode );
		btn.disabled = true;
		btn.setAttribute( 'aria-live', 'polite' );

		setTimeout( function () {
			while ( btn.firstChild ) {
				btn.removeChild( btn.firstChild );
			}
			originalNodes.forEach( function ( n ) {
				btn.appendChild( n );
			} );
			btn.disabled = originalDisabled;
			if ( null === originalAriaLive ) {
				btn.removeAttribute( 'aria-live' );
			} else {
				btn.setAttribute( 'aria-live', originalAriaLive );
			}
		}, 1500 );
	}

	function buildCheckSvg() {
		var ns  = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'xmlns', ns );
		svg.setAttribute( 'width', '14' );
		svg.setAttribute( 'height', '14' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '2' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		var poly = document.createElementNS( ns, 'polyline' );
		poly.setAttribute( 'points', '20 6 9 17 4 12' );
		svg.appendChild( poly );
		return svg;
	}

	function fallbackCopy( text, btn ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.left     = '-9999px';
		document.body.appendChild( ta );
		ta.focus();
		ta.select();
		try {
			document.execCommand( 'copy' );
			flashCopied( btn );
		} catch ( err ) {
			// Silent — the readonly input is still selectable so the user can copy manually.
		}
		document.body.removeChild( ta );
	}

	// ── Test connection ────────────────────────────────────────────────────────

	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.lrn-payment-test-btn' );
		if ( ! btn ) {
			return;
		}

		var provider  = btn.dataset.provider;
		var labelTest = btn.dataset.labelTesting || 'Testing…';
		var resultEl  = document.getElementById( 'lrn-' + provider + '-test-result' );

		if ( ! provider || ! resultEl ) {
			return;
		}

		// "Test before save" — read the typed values from the form fields
		// and pass them in the request body so an admin can validate a
		// key without first committing it to the database (Basecamp
		// #9932052574). The button is no longer gated on the saved-flag
		// data attribute — when both the typed field AND saved option are
		// empty the server returns a friendly 400 we render inline.
		var modeRadio = document.querySelector( 'input[name="learnomy_settings[' + provider + '_mode]"]:checked' );
		var currentMode = modeRadio ? modeRadio.value : '';
		var typedSecret = '';
		var typedClient = '';
		if ( 'stripe' === provider ) {
			var stripeField = document.querySelector(
				'input[name="learnomy_encrypted[stripe_' + currentMode + '_secret]"]'
			);
			typedSecret = stripeField ? stripeField.value.trim() : '';
		} else if ( 'paypal' === provider ) {
			var paypalSecret = document.querySelector(
				'input[name="learnomy_encrypted[paypal_' + currentMode + '_secret]"]'
			);
			var paypalClient = document.querySelector(
				'input[name="learnomy_settings[paypal_' + currentMode + '_client]"]'
			);
			typedSecret = paypalSecret ? paypalSecret.value.trim() : '';
			typedClient = paypalClient ? paypalClient.value.trim() : '';
		}

		// Save original button child nodes for restore.
		var savedNodes = Array.from( btn.childNodes ).map( function ( n ) {
			return n.cloneNode( true );
		} );

		// Show loading state — DOM-only, no innerHTML.
		btn.setAttribute( 'aria-busy', 'true' );
		btn.disabled = true;
		while ( btn.firstChild ) {
			btn.removeChild( btn.firstChild );
		}
		var spinSvg = buildSpinSvg();
		btn.appendChild( spinSvg );
		btn.appendChild( document.createTextNode( ' ' + labelTest ) );

		resultEl.textContent = '';
		resultEl.className   = 'lrn-payment-test-result';

		// Build request.
		var restUrl   = ( window.learnomyAdmin && window.learnomyAdmin.restUrl ) || '/wp-json/learnomy/v1/';
		var restNonce = ( window.learnomyAdmin && window.learnomyAdmin.restNonce ) || '';
		var endpoint  = restUrl + 'admin/payments/test-connection';

		fetch( endpoint, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   restNonce,
			},
			body: JSON.stringify(
				Object.assign(
					{ provider: provider },
					typedSecret ? { secret: typedSecret } : {},
					typedClient ? { client_id: typedClient } : {}
				)
			),
		} )
		.then( function ( response ) {
			return response.json().then( function ( data ) {
				return { ok: response.ok, data: data };
			} );
		} )
		.then( function ( result ) {
			restoreBtn( btn, savedNodes );

			if ( result.ok && result.data && result.data.data ) {
				var d    = result.data.data;
				var acct = ( 'stripe' === provider ? d.account_id : d.merchant_id ) || __( 'unknown', 'learnomy' );
				var name = 'stripe' === provider ? d.display_name : d.email;
				var mode = d.mode_detected || '';
				var msg;

				if ( name ) {
					/* translators: 1: account id, 2: display name or email, 3: mode (live/test) */
					msg = sprintf( __( 'Connected as: %1$s (%2$s) — %3$s mode', 'learnomy' ), acct, name, mode );
				} else {
					/* translators: 1: account id, 2: mode (live/test) */
					msg = sprintf( __( 'Connected as: %1$s — %2$s mode', 'learnomy' ), acct, mode );
				}

				resultEl.textContent = msg;
				resultEl.classList.add( 'lrn-payment-test-result--success' );
			} else {
				var errMsg = ( result.data && result.data.error && result.data.error.message )
					? result.data.error.message
					: __( 'Connection failed. Check your credentials and try again.', 'learnomy' );
				resultEl.textContent = errMsg;
				resultEl.classList.add( 'lrn-payment-test-result--error' );
			}
		} )
		.catch( function () {
			restoreBtn( btn, savedNodes );
			resultEl.textContent = __( 'Request failed. Check your internet connection.', 'learnomy' );
			resultEl.classList.add( 'lrn-payment-test-result--error' );
		} );
	} );

	function restoreBtn( btn, savedNodes ) {
		btn.removeAttribute( 'aria-busy' );
		btn.disabled = false;
		while ( btn.firstChild ) {
			btn.removeChild( btn.firstChild );
		}
		savedNodes.forEach( function ( n ) {
			btn.appendChild( n.cloneNode( true ) );
		} );
	}

	function buildSpinSvg() {
		var ns  = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'xmlns', ns );
		svg.setAttribute( 'width', '14' );
		svg.setAttribute( 'height', '14' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '2' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.classList.add( 'lrn-spin' );
		var path = document.createElementNS( ns, 'path' );
		path.setAttribute( 'd', 'M21 12a9 9 0 1 1-6.219-8.56' );
		svg.appendChild( path );
		return svg;
	}

}() );
