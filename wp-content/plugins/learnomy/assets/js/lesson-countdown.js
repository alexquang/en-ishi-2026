/**
 * Lesson drip-schedule countdown ticker.
 *
 * Updates every `[data-lrn-countdown][data-unlocks-at]` element on the page
 * once per minute with a humanised "Unlocks in X" label. When the unlock
 * datetime passes, the chip switches to a "Refresh to unlock" message and
 * the page reloads once so the unlocked content takes over.
 *
 * Strings come from `window.lrnDripStrings` (localised via PHP) and use
 * {d}/{h}/{m} placeholders that we substitute at runtime.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var TICK_MS  = 60 * 1000;
	var SELECTOR = '[data-lrn-countdown][data-unlocks-at]';

	function strings() {
		var s = ( typeof window !== 'undefined' && window.lrnDripStrings ) || {};
		return {
			daysHours:    s.daysHours    || 'Unlocks in {d}d {h}h',
			hoursMinutes: s.hoursMinutes || 'Unlocks in {h}h {m}m',
			minutes:      s.minutes      || 'Unlocks in {m}m',
			lessThanMin:  s.lessThanMin  || 'Unlocks shortly',
			refresh:      s.refresh      || 'Refresh to unlock'
		};
	}

	function format( diffMs ) {
		var s = strings();

		if ( diffMs <= 0 ) {
			return s.refresh;
		}

		var totalMinutes = Math.floor( diffMs / 60000 );
		var days         = Math.floor( totalMinutes / ( 60 * 24 ) );
		var hours        = Math.floor( ( totalMinutes % ( 60 * 24 ) ) / 60 );
		var minutes      = totalMinutes % 60;

		if ( days > 0 ) {
			return s.daysHours.replace( '{d}', days ).replace( '{h}', hours );
		}
		if ( hours > 0 ) {
			return s.hoursMinutes.replace( '{h}', hours ).replace( '{m}', minutes );
		}
		if ( minutes > 0 ) {
			return s.minutes.replace( '{m}', minutes );
		}
		return s.lessThanMin;
	}

	function tick() {
		var nodes = document.querySelectorAll( SELECTOR );
		if ( ! nodes.length ) {
			return;
		}

		var now            = Date.now();
		var anyJustUnlocked = false;

		for ( var i = 0; i < nodes.length; i++ ) {
			var node   = nodes[ i ];
			var target = node.getAttribute( 'data-unlocks-at' );
			if ( ! target ) {
				continue;
			}
			var targetMs = Date.parse( target );
			if ( isNaN( targetMs ) ) {
				continue;
			}

			var diff = targetMs - now;
			node.textContent = format( diff );

			if ( diff <= 0 && ! node.dataset.lrnCountdownDone ) {
				node.dataset.lrnCountdownDone = '1';
				anyJustUnlocked = true;
			}
		}

		// One-shot reload after the first row crosses the unlock threshold.
		// The 1.5s grace lets the chip flip to the "Refresh to unlock" copy
		// so the user sees what is happening before the navigation.
		if ( anyJustUnlocked && ! window.__lrnDripReloaded ) {
			window.__lrnDripReloaded = true;
			setTimeout( function () {
				window.location.reload();
			}, 1500 );
		}
	}

	function start() {
		tick();
		// Avoid double-binding when the script is enqueued twice on a page.
		if ( ! window.__lrnDripTicker ) {
			window.__lrnDripTicker = setInterval( tick, TICK_MS );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
