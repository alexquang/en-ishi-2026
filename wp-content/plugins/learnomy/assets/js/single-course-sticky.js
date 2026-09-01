/**
 * Single-course sticky sidebar offset.
 *
 * The course "Course Information" sidebar is `position: sticky`. On a theme that
 * pins its own header to the top of the viewport (e.g. Reign's 90px sticky
 * header), a fixed `top` offset makes the card stick UNDERNEATH that header on
 * scroll, so the two overlap (Basecamp #9972595954).
 *
 * This measures the active pinned header at the top of the viewport and writes
 * its height into `--lrn-sticky-header-offset`, which the sidebar's `top: calc()`
 * adds to its own gap. On themes without a sticky header the measured height is
 * 0, so the sidebar keeps its plain gap (no behaviour change).
 *
 * Pure measurement: no layout writes except the one CSS variable, and the scroll
 * listener is rAF-throttled so it stays cheap.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var sidebar = document.querySelector( '.lrn-single-course__sidebar' );
	if ( ! sidebar ) {
		return;
	}

	/**
	 * The bottom edge (distance from the viewport top) of everything pinned at
	 * the top that the sticky card must clear: the WordPress admin bar when
	 * logged in, PLUS the theme's pinned site header which sits *below* the admin
	 * bar. Returning the bottom edge (not just a height) means the offset already
	 * includes the admin-bar push, so a logged-in user's card parks below both
	 * (Basecamp #9972595954 — the earlier height-only measure read the header's
	 * top as ~32px, failed the `top <= 1` test, and returned 0 when logged in).
	 *
	 * @return {number} Pixels from the viewport top.
	 */
	function topPinnedOffset() {
		var best = 0;

		// WP admin bar — fixed at the very top on desktop (absolute on mobile,
		// where it scrolls away and its bottom goes negative, so it drops out).
		var adminBar = document.getElementById( 'wpadminbar' );
		if ( adminBar ) {
			var abPos = window.getComputedStyle( adminBar ).position;
			if ( 'fixed' === abPos || 'absolute' === abPos ) {
				best = Math.max( best, adminBar.getBoundingClientRect().bottom );
			}
		}

		// Theme site header pinned at (or just below the admin bar at) the top.
		var elements = document.querySelectorAll( 'header, .site-header, [class*="header"]' );
		elements.forEach( function ( element ) {
			var position = window.getComputedStyle( element ).position;
			if ( 'sticky' !== position && 'fixed' !== position ) {
				return;
			}
			var rect = element.getBoundingClientRect();
			// Pinned at the top region — allow it to sit just under the admin bar
			// (top <= current best + 1), header-sized so a thin bar or a
			// full-page overlay isn't mistaken for the site header.
			if ( rect.top <= best + 1 && rect.height >= 40 && rect.height <= 240 ) {
				best = Math.max( best, rect.bottom );
			}
		} );

		return Math.max( 0, best );
	}

	function apply() {
		sidebar.style.setProperty( '--lrn-sticky-header-offset', topPinnedOffset() + 'px' );
	}

	var ticking = false;
	function onScroll() {
		if ( ticking ) {
			return;
		}
		ticking = true;
		window.requestAnimationFrame( function () {
			apply();
			ticking = false;
		} );
	}

	apply();
	window.addEventListener( 'resize', apply );
	// A theme header often only pins (and reaches its final height) after the
	// first scroll, so keep the offset in sync as the user scrolls.
	window.addEventListener( 'scroll', onScroll, { passive: true } );
} )();
