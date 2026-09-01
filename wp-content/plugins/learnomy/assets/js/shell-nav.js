/**
 * Account / instructor shell navigation — collapse the rail on compact widths.
 *
 * The shell rail (assets/css/account.css) is a left panel at >=768px and a
 * disclosure below that. The markup ships as <details open> on purpose: a
 * CLOSED <details> is not just hidden, Chrome and Safari skip painting its
 * content entirely, which rendered the desktop rail as a blank 255px column.
 * Shipping it open means the rail can never disappear; this script is what
 * collapses it on a phone.
 *
 * Consequently the no-JS fallback is an expanded list on compact screens —
 * long, but every destination is reachable. That is the failure mode we want.
 *
 * Once the visitor toggles the disclosure themselves we stop managing it for
 * that viewport class, so we never fight a deliberate choice. Crossing the
 * breakpoint re-asserts the correct default, because "open" means two different
 * things either side of it: a rail that is always there, or a panel the visitor
 * opened.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var COMPACT = '(max-width: 767px)';

	function init() {
		var details = document.querySelector( '.lrn-shell-nav' );
		if ( ! details || ! window.matchMedia ) {
			return;
		}

		var mq       = window.matchMedia( COMPACT );
		var touched  = false;
		var applying = false;

		function apply() {
			if ( touched ) {
				return;
			}
			// Our own change fires `toggle` too; the guard keeps us from reading
			// it back as a visitor action and then refusing to manage the panel.
			applying = true;
			// Compact: collapse to the trigger. Rail width: always expanded.
			if ( mq.matches ) {
				details.removeAttribute( 'open' );
			} else {
				details.setAttribute( 'open', '' );
			}
			applying = false;
		}

		// A toggle the visitor performed themselves outranks the default, until
		// the viewport changes class and the default means something else.
		details.addEventListener( 'toggle', function () {
			if ( ! applying ) {
				touched = true;
			}
		} );

		// Dismiss on an outside tap, and on Escape.
		//
		// Below the breakpoint this disclosure behaves as a menu — it overlays
		// the page and the visitor expects the two universal ways out. <details>
		// provides neither: it closes only by hitting the summary again, so a
		// tap on the page behind it did nothing and the panel sat there covering
		// the content the visitor was reaching for (Basecamp 10186217280).
		//
		// Compact only. At rail width the panel is not a menu, it is a column of
		// the layout, and closing it because someone clicked the page would make
		// the navigation vanish mid-use.
		//
		// `touched` is deliberately left alone: dismissing IS the visitor driving
		// the panel, and the flag already means "their choice outranks the
		// default until the viewport changes class".
		function dismiss() {
			if ( mq.matches && details.hasAttribute( 'open' ) ) {
				details.removeAttribute( 'open' );
			}
		}

		document.addEventListener( 'click', function ( event ) {
			// A click inside the panel is navigation or the summary's own
			// toggle; the browser owns both and we must not double-handle.
			if ( ! details.contains( event.target ) ) {
				dismiss();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' !== event.key || ! mq.matches || ! details.hasAttribute( 'open' ) ) {
				return;
			}
			dismiss();
			// Focus goes back to the control that opened it, or it would be lost
			// to the top of the document.
			var summary = details.querySelector( 'summary' );
			if ( summary ) {
				summary.focus();
			}
		} );

		if ( 'function' === typeof mq.addEventListener ) {
			mq.addEventListener( 'change', function () {
				touched = false;
				apply();
			} );
		} else if ( 'function' === typeof mq.addListener ) {
			// Safari < 14.
			mq.addListener( function () {
				touched = false;
				apply();
			} );
		}

		apply();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
