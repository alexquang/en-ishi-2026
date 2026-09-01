/**
 * Learnomy — Settings page hash routing (Pattern A)
 *
 * Handles sidebar navigation, section switching via URL hash,
 * and preserves hash across form saves.
 *
 * @package Learnomy
 */
(function () {
	'use strict';

	var NAV      = '.lrn-settings-nav__link[data-section]';
	var SECTION  = '.lrn-settings-section';
	var ACTIVE   = 'is-active';

	/**
	 * Show the section matching the given id, hide all others.
	 *
	 * @param {string} id Section identifier (e.g. "general", "payments").
	 */
	function activate( id ) {
		var navLinks = document.querySelectorAll( NAV );
		var sections = document.querySelectorAll( SECTION );

		navLinks.forEach( function ( el ) {
			el.classList.remove( ACTIVE );
		});
		sections.forEach( function ( el ) {
			el.classList.remove( ACTIVE );
		});

		var targetNav = document.querySelector( NAV + '[data-section="' + id + '"]' );
		var targetSec = document.getElementById( 'section-' + id );

		if ( targetNav && targetSec ) {
			targetNav.classList.add( ACTIVE );
			targetSec.classList.add( ACTIVE );
		} else {
			// Fallback: activate the first section.
			var firstNav = document.querySelector( NAV );
			var firstSec = document.querySelector( SECTION );
			if ( firstNav ) {
				firstNav.classList.add( ACTIVE );
			}
			if ( firstSec ) {
				firstSec.classList.add( ACTIVE );
			}
		}
	}

	// Nav click handler — switch section, update hash.
	document.querySelectorAll( NAV ).forEach( function ( item ) {
		item.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var section = item.getAttribute( 'data-section' );
			activate( section );
			history.replaceState( null, '', '#' + section );
			// Show the top of the newly-activated section. Sections vary a lot
			// in height (a long Payments form vs. a short Integrations empty
			// state); without this, switching from a tall section while scrolled
			// down lands the user below a short section, showing a blank panel.
			window.scrollTo( 0, 0 );
		});
	});

	// Preserve hash on form submit so the user returns to the same section.
	document.querySelectorAll( SECTION + ' form' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function () {
			var hash = window.location.hash;
			if ( hash ) {
				var action = form.getAttribute( 'action' ) || '';
				// Prevent double hash.
				if ( action.indexOf( '#' ) === -1 ) {
					form.setAttribute( 'action', action + hash );
				}
			}
		});
	});

	// Handle browser back/forward.
	window.addEventListener( 'hashchange', function () {
		activate( window.location.hash.replace( '#', '' ) || '' );
	});

	// Activate on page load from current hash (or default to first).
	activate( window.location.hash.replace( '#', '' ) || '' );

	// No icon call here: lucide-live.js converts at load and keeps converting,
	// so icons inside sections revealed later paint on their own
	// (Basecamp 10184590411).
})();
