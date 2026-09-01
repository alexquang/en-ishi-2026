/**
 * Dashboard tabs — switches between All, In Progress, Completed, and Wishlist.
 *
 * Implements the ARIA tablist / roving-tabindex keyboard pattern:
 *   - Arrow Left / Arrow Right move focus between tabs and activate them.
 *   - Home / End jump to the first / last tab.
 *   - Tab key exits the tablist into the active panel.
 *   - Only the active tab has tabindex="0"; all others carry tabindex="-1".
 *
 * @package Learnomy
 */
( function() {
	'use strict';

	var nav = document.querySelector( '.lrn-tab-nav' );
	if ( ! nav ) {
		return;
	}

	var tabs   = nav.querySelectorAll( '.lrn-tab-nav__item' );
	var panels = document.querySelectorAll( '.lrn-tab-panel' );

	function switchTab( targetTab ) {
		var tabName = targetTab.getAttribute( 'data-tab' );

		// Update tab buttons: active state + roving tabindex.
		for ( var i = 0; i < tabs.length; i++ ) {
			var isActive = tabs[ i ] === targetTab;
			tabs[ i ].classList.toggle( 'lrn-tab-nav__item--active', isActive );
			tabs[ i ].setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			tabs[ i ].setAttribute( 'tabindex', isActive ? '0' : '-1' );
		}

		// Show/hide panels.
		for ( var j = 0; j < panels.length; j++ ) {
			var isTarget = panels[ j ].getAttribute( 'data-tab' ) === tabName;
			panels[ j ].hidden = ! isTarget;
		}
	}

	// Bind click handlers.
	for ( var k = 0; k < tabs.length; k++ ) {
		tabs[ k ].addEventListener( 'click', function( e ) {
			e.preventDefault();
			switchTab( this );
			this.focus();
		} );
	}

	// Deep-link: activate the tab named in ?tab=<name> on load, so an account-nav
	// "Wishlist" link (account-courses/?tab=wishlist) opens straight on that tab
	// instead of always landing on "All". No-op when absent / unknown.
	try {
		var requested = new URLSearchParams( window.location.search ).get( 'tab' );
		if ( requested ) {
			for ( var t = 0; t < tabs.length; t++ ) {
				if ( tabs[ t ].getAttribute( 'data-tab' ) === requested ) {
					switchTab( tabs[ t ] );
					break;
				}
			}
		}
	} catch ( err ) { /* URLSearchParams unsupported — keep the default tab. */ }

	// Keyboard navigation — arrow keys, Home, End.
	nav.addEventListener( 'keydown', function( e ) {
		if (
			e.key !== 'ArrowLeft' &&
			e.key !== 'ArrowRight' &&
			e.key !== 'Home' &&
			e.key !== 'End'
		) {
			return;
		}

		var items = Array.prototype.slice.call( tabs );
		var current = nav.querySelector( '.lrn-tab-nav__item--active' );
		var index   = items.indexOf( current );

		if ( e.key === 'ArrowRight' ) {
			index = ( index + 1 ) % items.length;
		} else if ( e.key === 'ArrowLeft' ) {
			index = ( index - 1 + items.length ) % items.length;
		} else if ( e.key === 'Home' ) {
			index = 0;
		} else if ( e.key === 'End' ) {
			index = items.length - 1;
		}

		// Prevent page scroll on Home/End.
		e.preventDefault();

		items[ index ].focus();
		switchTab( items[ index ] );
	} );
} )();
