/**
 * Learnomy toast notification system.
 *
 * Works in both admin (.learnomy-admin) and frontend (.lrn-app) contexts.
 * Reads --lrn-* CSS tokens from the DOM so it adapts to whichever context
 * is active.
 *
 * Usage:
 *   lrnToast( 'Course published!', 'success' );
 *   lrnToast( 'Network error.', 'error', 0 ); // manual dismiss only
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	// Fall back to the identity function when wp.i18n isn't loaded (e.g. the
	// standalone setup wizard) so toast.js never throws on a chrome-less page.
	var __ = ( window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function' )
		? window.wp.i18n.__
		: function ( text ) { return text; };

	var MAX_VISIBLE  = 5;
	// Longer than the 0.2s exit animation, short enough that a toast whose
	// animation never runs still feels dismissed on click.
	var EXIT_FALLBACK_MS = 400;
	var reducedMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var container     = null;
	var toasts        = [];

	/**
	 * Create an SVG element from attributes. All icon markup is built with
	 * DOM methods — no innerHTML — to keep the security surface clean.
	 *
	 * @param {Array} children Array of {tag, attrs} objects.
	 * @return {SVGElement}
	 */
	function svg( children ) {
		var ns  = 'http://www.w3.org/2000/svg';
		var el  = document.createElementNS( ns, 'svg' );
		el.setAttribute( 'width', '20' );
		el.setAttribute( 'height', '20' );
		el.setAttribute( 'viewBox', '0 0 24 24' );
		el.setAttribute( 'fill', 'none' );
		el.setAttribute( 'stroke', 'currentColor' );
		el.setAttribute( 'stroke-width', '2' );
		el.setAttribute( 'stroke-linecap', 'round' );
		el.setAttribute( 'stroke-linejoin', 'round' );

		for ( var i = 0; i < children.length; i++ ) {
			var child = document.createElementNS( ns, children[ i ].tag );
			var attrs = children[ i ].attrs;
			for ( var key in attrs ) {
				if ( attrs.hasOwnProperty( key ) ) {
					child.setAttribute( key, attrs[ key ] );
				}
			}
			el.appendChild( child );
		}

		return el;
	}

	/**
	 * Build an icon SVG for the given toast type.
	 */
	var iconBuilders = {
		success: function () {
			return svg( [
				{ tag: 'path', attrs: { d: 'M22 11.08V12a10 10 0 1 1-5.93-9.14' } },
				{ tag: 'polyline', attrs: { points: '22 4 12 14.01 9 11.01' } }
			] );
		},
		error: function () {
			return svg( [
				{ tag: 'circle', attrs: { cx: '12', cy: '12', r: '10' } },
				{ tag: 'line', attrs: { x1: '15', y1: '9', x2: '9', y2: '15' } },
				{ tag: 'line', attrs: { x1: '9', y1: '9', x2: '15', y2: '15' } }
			] );
		},
		info: function () {
			return svg( [
				{ tag: 'circle', attrs: { cx: '12', cy: '12', r: '10' } },
				{ tag: 'line', attrs: { x1: '12', y1: '16', x2: '12', y2: '12' } },
				{ tag: 'line', attrs: { x1: '12', y1: '8', x2: '12.01', y2: '8' } }
			] );
		},
		warning: function () {
			return svg( [
				{ tag: 'path', attrs: { d: 'M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z' } },
				{ tag: 'line', attrs: { x1: '12', y1: '9', x2: '12', y2: '13' } },
				{ tag: 'line', attrs: { x1: '12', y1: '17', x2: '12.01', y2: '17' } }
			] );
		}
	};

	/**
	 * Build a small X icon for the dismiss button.
	 */
	function dismissIcon() {
		var ns  = 'http://www.w3.org/2000/svg';
		var el  = document.createElementNS( ns, 'svg' );
		el.setAttribute( 'width', '14' );
		el.setAttribute( 'height', '14' );
		el.setAttribute( 'viewBox', '0 0 24 24' );
		el.setAttribute( 'fill', 'none' );
		el.setAttribute( 'stroke', 'currentColor' );
		el.setAttribute( 'stroke-width', '2' );
		el.setAttribute( 'stroke-linecap', 'round' );
		el.setAttribute( 'stroke-linejoin', 'round' );

		var l1 = document.createElementNS( ns, 'line' );
		l1.setAttribute( 'x1', '18' );
		l1.setAttribute( 'y1', '6' );
		l1.setAttribute( 'x2', '6' );
		l1.setAttribute( 'y2', '18' );
		el.appendChild( l1 );

		var l2 = document.createElementNS( ns, 'line' );
		l2.setAttribute( 'x1', '6' );
		l2.setAttribute( 'y1', '6' );
		l2.setAttribute( 'x2', '18' );
		l2.setAttribute( 'y2', '18' );
		el.appendChild( l2 );

		return el;
	}

	/**
	 * Ensure the toast stack container exists in the DOM.
	 */
	function ensureContainer() {
		if ( container ) {
			return;
		}

		container = document.createElement( 'div' );
		container.className = 'lrn-toast-stack';
		container.setAttribute( 'aria-live', 'polite' );
		container.setAttribute( 'role', 'status' );
		document.body.appendChild( container );
	}

	/**
	 * Remove a toast element from the DOM and the internal array.
	 *
	 * @param {HTMLElement} el Toast element.
	 */
	function removeToast( el ) {
		var idx = toasts.indexOf( el );
		if ( idx > -1 ) {
			toasts.splice( idx, 1 );
		}

		if ( reducedMotion ) {
			el.remove();
			return;
		}

		el.classList.add( 'lrn-toast--exit' );

		// Removal must not depend on an animation event that may never arrive.
		//
		// It used to: the only path out of the DOM was `animationend`, and the
		// exit rule that fires it had been losing on specificity to
		// `.lrn-toast-stack .lrn-toast` (two classes to one), so no exit
		// animation ever started. A toast then left only when some OTHER
		// animation happened to end after dismiss was clicked — the entry
		// slide still running, or the auto-dismiss progress bar finishing and
		// bubbling its event up. A manual-only toast (duration 0) has neither,
		// so its × button did nothing, for good (Basecamp 10144304356).
		//
		// The CSS specificity is fixed too, but this is the part that keeps it
		// fixed: a timeout removes the element whatever the stylesheet does.
		var removed = false;
		function finish() {
			if ( removed ) {
				return;
			}
			removed = true;
			el.remove();
		}

		el.addEventListener( 'animationend', function ( event ) {
			// Our own exit animation only. Without the target check, the
			// progress bar's animationend bubbles up here and evicts the toast
			// early; without the name check, so does the entry slide.
			if ( event.target === el && 'lrn-toast-slide-out' === event.animationName ) {
				finish();
			}
		} );

		setTimeout( finish, EXIT_FALLBACK_MS );
	}

	/**
	 * Evict the oldest toast when the stack exceeds MAX_VISIBLE.
	 */
	function enforceLimit() {
		while ( toasts.length > MAX_VISIBLE ) {
			removeToast( toasts[0] );
		}
	}

	/**
	 * Show a toast notification.
	 *
	 * @param {string}                              message  The message to display.
	 * @param {'success'|'error'|'info'|'warning'}  type     Toast type (default 'info').
	 * @param {number}                              duration Auto-dismiss in ms (default 4000, 0 = manual only).
	 */
	function lrnToast( message, type, duration ) {
		if ( typeof type === 'undefined' ) {
			type = 'info';
		}
		if ( typeof duration === 'undefined' ) {
			duration = 4000;
		}

		ensureContainer();

		var el = document.createElement( 'div' );
		el.className = 'lrn-toast lrn-toast--' + type;

		if ( reducedMotion ) {
			el.classList.add( 'lrn-toast--no-motion' );
		}

		// Icon.
		var iconWrap = document.createElement( 'span' );
		iconWrap.className = 'lrn-toast__icon';
		var buildIcon = iconBuilders[ type ] || iconBuilders.info;
		iconWrap.appendChild( buildIcon() );
		el.appendChild( iconWrap );

		// Message.
		var msg = document.createElement( 'span' );
		msg.className = 'lrn-toast__message';
		msg.textContent = message;
		el.appendChild( msg );

		// Dismiss button.
		var dismiss = document.createElement( 'button' );
		dismiss.className = 'lrn-toast__dismiss';
		dismiss.type = 'button';
		dismiss.setAttribute( 'aria-label', __( 'Dismiss', 'learnomy' ) );
		dismiss.appendChild( dismissIcon() );
		dismiss.addEventListener( 'click', function () {
			removeToast( el );
		} );
		el.appendChild( dismiss );

		// Progress bar for auto-dismiss.
		if ( duration > 0 ) {
			var bar = document.createElement( 'div' );
			bar.className = 'lrn-toast__progress';

			if ( ! reducedMotion ) {
				bar.style.animationDuration = duration + 'ms';
			}

			el.appendChild( bar );

			setTimeout( function () {
				if ( el.parentNode ) {
					removeToast( el );
				}
			}, duration );
		}

		container.appendChild( el );
		toasts.push( el );
		enforceLimit();
	}

	/**
	 * Show a toast with an inline Undo button.
	 *
	 * The undo handler fires only if the user clicks Undo before the toast
	 * auto-dismisses. Use for reversible side-effects (plan archive, cancel
	 * scheduling) — not for actions that have already hit a third-party API.
	 *
	 * @param {string}   message  Message to display.
	 * @param {Function} onUndo   Callback when the user clicks Undo.
	 * @param {number}   [timeout=10000] Auto-dismiss in ms.
	 */
	function lrnUndo( message, onUndo, timeout ) {
		if ( typeof timeout === 'undefined' ) {
			timeout = 10000;
		}

		ensureContainer();

		var el = document.createElement( 'div' );
		el.className = 'lrn-toast lrn-toast--undo';

		if ( reducedMotion ) {
			el.classList.add( 'lrn-toast--no-motion' );
		}

		// Icon — clock glyph signals "you can still change your mind."
		var iconWrap = document.createElement( 'span' );
		iconWrap.className = 'lrn-toast__icon';
		iconWrap.appendChild(
			svg( [
				{ tag: 'circle', attrs: { cx: '12', cy: '12', r: '10' } },
				{ tag: 'polyline', attrs: { points: '12 6 12 12 16 14' } }
			] )
		);
		el.appendChild( iconWrap );

		var msg = document.createElement( 'span' );
		msg.className   = 'lrn-toast__message';
		msg.textContent = message;
		el.appendChild( msg );

		// Undo action button.
		var undoBtn = document.createElement( 'button' );
		undoBtn.type        = 'button';
		undoBtn.className   = 'lrn-toast__undo';
		undoBtn.textContent = __( 'Undo', 'learnomy' );

		var dismiss = document.createElement( 'button' );
		dismiss.className = 'lrn-toast__dismiss';
		dismiss.type      = 'button';
		dismiss.setAttribute( 'aria-label', __( 'Dismiss', 'learnomy' ) );
		dismiss.appendChild( dismissIcon() );

		var undone = false;
		var timer  = null;

		function finish( wasUndone ) {
			if ( undone ) { return; }
			undone = true;
			if ( timer ) { clearTimeout( timer ); timer = null; }
			if ( wasUndone && typeof onUndo === 'function' ) {
				try { onUndo(); } catch ( _e ) { /* swallow — toast dismissal should not hide errors loudly */ }
			}
			if ( el.parentNode ) {
				removeToast( el );
			}
		}

		undoBtn.addEventListener( 'click', function () { finish( true ); } );
		dismiss.addEventListener( 'click', function () { finish( false ); } );

		el.appendChild( undoBtn );
		el.appendChild( dismiss );

		// Progress bar ticks down the undo window visually.
		if ( timeout > 0 ) {
			var bar = document.createElement( 'div' );
			bar.className = 'lrn-toast__progress';
			if ( ! reducedMotion ) {
				bar.style.animationDuration = timeout + 'ms';
			}
			el.appendChild( bar );

			timer = setTimeout( function () { finish( false ); }, timeout );
		}

		container.appendChild( el );
		toasts.push( el );
		enforceLimit();
	}

	/**
	 * Show a toast on the NEXT page load instead of this one.
	 *
	 * A confirmation rendered into a page that is about to be replaced is not a
	 * confirmation. Nearly every save in this plugin ends with a toast followed
	 * by `location.reload()` or a redirect, so the message was being destroyed
	 * with the document — 21 of those call sites navigated immediately, giving
	 * the toast a zero-millisecond life, and the rest allowed 600-800ms, which
	 * is inside its own fade-in. Measured on the question editor: created at
	 * +28127ms, visible at +28140ms, page gone at +28730ms. The user saw
	 * nothing and reasonably concluded the save had failed silently
	 * (Basecamp 10185990987).
	 *
	 * So the message has to outlive the document. It parks in sessionStorage —
	 * same-origin, survives both reload and redirect, dies with the tab — and
	 * `drainFlash()` renders it once the destination page is up.
	 *
	 * Use this whenever the success path navigates. Use `lrnToast()` when the
	 * page stays put.
	 *
	 * @param {string}                              message  The message to display.
	 * @param {'success'|'error'|'info'|'warning'}  type     Toast type (default 'info').
	 * @param {number}                              duration Auto-dismiss in ms (default 4000, 0 = manual only).
	 */
	function lrnToastFlash( message, type, duration ) {
		var entry = {
			message:  message,
			type:     type || 'info',
			duration: duration,
			at:       Date.now()
		};

		var queue = readFlashQueue();

		// sessionStorage is unavailable in some privacy modes and inside
		// sandboxed iframes, where touching it throws. Losing the confirmation
		// entirely is the worse failure, so fall back to showing it here — it
		// is short-lived, but a half-second toast still beats no toast.
		if ( null === queue ) {
			lrnToast( message, type, duration );
			return;
		}

		queue.push( entry );
		writeFlashQueue( queue );
	}

	var FLASH_KEY = 'lrnToastFlash';

	// A flash is meant for the page one navigation away. If that navigation
	// never happens — the request failed, the user hit Escape, they wandered
	// off — the entry must not ambush them on an unrelated screen ten minutes
	// later, saying something was saved that they have long since forgotten
	// doing. Fifteen seconds is far longer than any same-tab navigation and far
	// shorter than a stale message stays meaningful.
	var FLASH_TTL_MS = 15000;

	/**
	 * @return {Array|null} Queued entries, or null when sessionStorage is unusable.
	 */
	function readFlashQueue() {
		try {
			var raw = window.sessionStorage.getItem( FLASH_KEY );
			if ( ! raw ) {
				return [];
			}
			var parsed = JSON.parse( raw );
			return Array.isArray( parsed ) ? parsed : [];
		} catch ( e ) {
			return null;
		}
	}

	function writeFlashQueue( queue ) {
		try {
			window.sessionStorage.setItem( FLASH_KEY, JSON.stringify( queue ) );
		} catch ( e ) {
			// Quota or availability — nothing useful to do, and throwing here
			// would take down the save handler that called us.
		}
	}

	/**
	 * Render and clear any toasts queued by the previous page.
	 *
	 * Clearing happens before rendering so a toast that somehow throws cannot
	 * leave its entry behind to fire again on every subsequent page load.
	 */
	function drainFlash() {
		var queue = readFlashQueue();
		if ( null === queue || ! queue.length ) {
			return;
		}

		try {
			window.sessionStorage.removeItem( FLASH_KEY );
		} catch ( e ) {
			// If it cannot be cleared it would repeat, so do not render it.
			return;
		}

		var now = Date.now();
		for ( var i = 0; i < queue.length; i++ ) {
			var entry = queue[ i ];
			if ( ! entry || ! entry.message ) {
				continue;
			}
			if ( now - ( entry.at || 0 ) > FLASH_TTL_MS ) {
				continue;
			}
			lrnToast( entry.message, entry.type, entry.duration );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', drainFlash );
	} else {
		drainFlash();
	}

	// Expose globally.
	window.lrnToast = lrnToast;
	window.lrnUndo  = lrnUndo;

	// `lrnToast.flash()` rather than a second global: the two are the same
	// notification with different lifetimes, and a call site choosing between
	// them should not have to remember a different symbol.
	window.lrnToast.flash = lrnToastFlash;
} )();
