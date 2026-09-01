/**
 * Student dashboard — certificate share clipboard helpers + tab nav.
 *
 * Wired against the markup in `templates/student-dashboard.php`. Inline
 * extraction per ux-foundation rule F2.
 *
 * @package Learnomy
 */
( function () {
	'use strict';
	var i18n = ( window.lrnStudentDashboard || {} ).i18n || {};

		// Certificate Share — copies the verify URL to the clipboard.
		//
		// `navigator.clipboard.writeText()` is a secure-context-only API: it
		// is undefined under HTTP (which our local + many self-hosted WP
		// installs run on). The previous implementation called it
		// unconditionally, throwing TypeError "navigator.clipboard is
		// undefined" the first time a student clicked Share over HTTP and
		// silently doing nothing.
		//
		// Strategy:
		//   1. Async Clipboard API when available (HTTPS / localhost).
		//   2. Legacy execCommand('copy') with a hidden textarea fallback —
		//      works under HTTP and on every browser shipping in the last
		//      decade. Deprecated but not removed.
		//   3. prompt() final fallback — surfaces the URL in a selectable
		//      dialog when both clipboard mechanisms fail (e.g., sandboxed
		//      iframe). Better than silent failure.
		( function () {
			var copiedLabel = i18n.copied;
			var failedLabel = i18n.copyManual;

			function flashLabel( btn, message ) {
				// The visible "Share" text sits in the next text node after
				// the lucide icon span. Tolerate templates that change that
				// shape by falling back to the button's own innerText.
				var icon   = btn.querySelector( '[data-lucide]' );
				var target = ( icon && icon.nextSibling && icon.nextSibling.nodeType === Node.TEXT_NODE )
					? icon.nextSibling
					: btn;
				var saved = target === btn ? btn.innerText : target.textContent;
				if ( target === btn ) {
					btn.innerText = message;
				} else {
					target.textContent = message;
				}
				setTimeout( function () {
					if ( target === btn ) {
						btn.innerText = saved;
					} else {
						target.textContent = saved;
					}
				}, 2000 );
			}

			function legacyCopy( url ) {
				var ta             = document.createElement( 'textarea' );
				ta.value           = url;
				ta.style.position  = 'fixed';
				ta.style.top       = '-1000px';
				ta.style.left      = '-1000px';
				ta.setAttribute( 'readonly', '' );
				document.body.appendChild( ta );
				ta.select();
				ta.setSelectionRange( 0, ta.value.length );
				var ok = false;
				try {
					ok = document.execCommand( 'copy' );
				} catch ( e ) {
					ok = false;
				}
				document.body.removeChild( ta );
				return ok;
			}

			document.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.lrn-copy-link' );
				if ( ! btn ) {
					return;
				}
				var url = btn.getAttribute( 'data-url' );
				if ( ! url ) {
					return;
				}

				if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
					navigator.clipboard.writeText( url )
						.then( function () { flashLabel( btn, copiedLabel ); } )
						.catch( function () {
							if ( legacyCopy( url ) ) {
								flashLabel( btn, copiedLabel );
							} else {
								if ( window.lrnPrompt ) { window.lrnPrompt( { message: failedLabel, defaultValue: url } ); } else if ( window.lrnToast ) { window.lrnToast( failedLabel + ' ' + url, 'info', 8000 ); }
							}
						} );
					return;
				}

				if ( legacyCopy( url ) ) {
					flashLabel( btn, copiedLabel );
					return;
				}

				if ( window.lrnPrompt ) { window.lrnPrompt( { message: failedLabel, defaultValue: url } ); } else if ( window.lrnToast ) { window.lrnToast( failedLabel + ' ' + url, 'info', 8000 ); }
			} );
		} )();

}() );