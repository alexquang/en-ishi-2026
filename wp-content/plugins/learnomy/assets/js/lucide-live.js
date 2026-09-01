/**
 * Lucide conversion that keeps up with the page.
 *
 * The old wiring ran lucide.createIcons() exactly once at DOMContentLoaded,
 * so anything rendered after load - Interactivity API templates, data-wp-bind
 * icon attributes, AJAX-inserted rows - kept its bare <i data-lucide> and
 * showed nothing. Six modules grew hand-rolled createIcons() calls as local
 * patches while the quiz player (the filed surface) had none (Basecamp
 * 10171508068).
 *
 * One MutationObserver owns the problem instead: it watches for added nodes
 * that carry (or contain) an unconverted icon AND for data-lucide attribute
 * writes on existing elements - the Interactivity API binds the results icon
 * as an attribute on a node that already exists, which a childList-only
 * observer would miss. Conversions batch on requestAnimationFrame; repeated
 * createIcons() passes are cheap because conversion replaces the <i>, so
 * each pass only sees what is still unconverted.
 *
 * Enqueued everywhere the lucide bundle is (frontend and admin, Free and Pro
 * screens). The per-module createIcons() patches are deleted - this file is
 * the one seam.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	function convert() {
		if ( window.lucide && typeof window.lucide.createIcons === 'function' ) {
			window.lucide.createIcons();
		}
	}

	var scheduled = false;
	function schedule() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		window.requestAnimationFrame( function () {
			scheduled = false;
			convert();
		} );
	}

	function needsConversion( node ) {
		return node.nodeType === 1
			&& ( ( node.matches && node.matches( 'i[data-lucide]' ) )
				|| ( node.querySelector && node.querySelector( 'i[data-lucide]' ) ) );
	}

	function start() {
		convert();

		var observer = new MutationObserver( function ( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				var m = mutations[ i ];
				if ( 'attributes' === m.type ) {
					// data-lucide written onto an existing element (the
					// Interactivity API's data-wp-bind--data-lucide path).
					schedule();
					return;
				}
				for ( var j = 0; j < m.addedNodes.length; j++ ) {
					if ( needsConversion( m.addedNodes[ j ] ) ) {
						schedule();
						return;
					}
				}
			}
		} );

		observer.observe( document.body, {
			childList:       true,
			subtree:         true,
			attributes:      true,
			attributeFilter: [ 'data-lucide' ],
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
