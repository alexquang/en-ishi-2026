/**
 * Learnomy micro-interaction JS utilities.
 *
 * 1. Intersection Observer for .lrn-fade-in elements.
 * 2. Count-up animation for .lrn-count-up[data-target] elements.
 *
 * Both respect prefers-reduced-motion by applying final state instantly.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var reducedMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	// ── Fade-in on scroll ───────────────────────────────────────────────

	function initFadeIn() {
		var elements = document.querySelectorAll( '.lrn-fade-in' );
		if ( ! elements.length ) {
			return;
		}

		if ( reducedMotion ) {
			for ( var i = 0; i < elements.length; i++ ) {
				elements[ i ].classList.add( 'lrn-fade-in--visible' );
			}
			return;
		}

		if ( ! ( 'IntersectionObserver' in window ) ) {
			// Fallback: just show everything.
			for ( var j = 0; j < elements.length; j++ ) {
				elements[ j ].classList.add( 'lrn-fade-in--visible' );
			}
			return;
		}

		var observer = new IntersectionObserver(
			function ( entries ) {
				for ( var k = 0; k < entries.length; k++ ) {
					if ( entries[ k ].isIntersecting ) {
						entries[ k ].target.classList.add( 'lrn-fade-in--visible' );
						observer.unobserve( entries[ k ].target );
					}
				}
			},
			{ threshold: 0.1 }
		);

		for ( var m = 0; m < elements.length; m++ ) {
			observer.observe( elements[ m ] );
		}
	}

	// ── Count-up ────────────────────────────────────────────────────────

	function animateCountUp( el ) {
		var target   = parseFloat( el.getAttribute( 'data-target' ) ) || 0;
		var duration = parseInt( el.getAttribute( 'data-duration' ), 10 ) || 1200;
		var decimals = ( el.getAttribute( 'data-decimals' ) !== null )
			? parseInt( el.getAttribute( 'data-decimals' ), 10 )
			: 0;
		var prefix   = el.getAttribute( 'data-prefix' ) || '';
		var suffix   = el.getAttribute( 'data-suffix' ) || '';

		if ( reducedMotion || target === 0 ) {
			el.textContent = prefix + target.toFixed( decimals ) + suffix;
			return;
		}

		var start     = 0;
		var startTime = null;

		function step( timestamp ) {
			if ( ! startTime ) {
				startTime = timestamp;
			}

			var elapsed  = timestamp - startTime;
			var progress = Math.min( elapsed / duration, 1 );

			// Ease-out cubic for a natural deceleration.
			var eased = 1 - Math.pow( 1 - progress, 3 );
			var value = start + ( target - start ) * eased;

			el.textContent = prefix + value.toFixed( decimals ) + suffix;

			if ( progress < 1 ) {
				requestAnimationFrame( step );
			}
		}

		requestAnimationFrame( step );
	}

	function initCountUp() {
		var elements = document.querySelectorAll( '.lrn-count-up[data-target]' );
		if ( ! elements.length ) {
			return;
		}

		if ( reducedMotion || ! ( 'IntersectionObserver' in window ) ) {
			for ( var i = 0; i < elements.length; i++ ) {
				animateCountUp( elements[ i ] );
			}
			return;
		}

		var observer = new IntersectionObserver(
			function ( entries ) {
				for ( var k = 0; k < entries.length; k++ ) {
					if ( entries[ k ].isIntersecting ) {
						animateCountUp( entries[ k ].target );
						observer.unobserve( entries[ k ].target );
					}
				}
			},
			{ threshold: 0.2 }
		);

		for ( var j = 0; j < elements.length; j++ ) {
			observer.observe( elements[ j ] );
		}
	}

	// ── Boot ────────────────────────────────────────────────────────────

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initFadeIn();
			initCountUp();
		} );
	} else {
		initFadeIn();
		initCountUp();
	}
} )();
