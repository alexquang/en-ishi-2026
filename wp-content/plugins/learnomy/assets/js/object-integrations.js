/**
 * Community & Discussion toggles.
 *
 * Shared by every screen the section renders on - course editor, course
 * builder, cohort, Learnomy Space - so all four behave identically.
 *
 * There is deliberately no <form> around these controls: the section renders
 * inside whatever form the host screen already has, and HTML forbids nested
 * forms. Each toggle posts to the REST route on change instead.
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	/**
	 * Bind one toggle.
	 *
	 * @param {HTMLInputElement} box The checkbox.
	 * @return {void}
	 */
	function bind( box ) {
		if ( box.dataset.lrnOiBound ) {
			return;
		}
		box.dataset.lrnOiBound = '1';

		box.addEventListener( 'change', function () {
			var cfg  = window.learnomyObjectIntegrations || {};
			var wrap = box.closest( '[data-lrn-object-type]' );
			if ( ! wrap ) {
				return;
			}

			var wanted = box.checked;
			box.disabled = true;

			window.fetch( cfg.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce
				},
				body: JSON.stringify( {
					type: wrap.getAttribute( 'data-lrn-object-type' ),
					id: parseInt( wrap.getAttribute( 'data-lrn-object-id' ), 10 ),
					provider: box.dataset.lrnObjectIntegration,
					enabled: wanted
				} )
			} ).then( function ( res ) {
				if ( ! res.ok ) {
					throw new Error( 'rejected' );
				}
				return res.json();
			} ).then( function ( data ) {
				// Show what the PROVIDER reports, not what was asked for. A
				// provider may decline - a draft course has nothing to attach a
				// room to yet - and the box should tell the truth about that
				// rather than sit ticked over a room that does not exist.
				if ( data && typeof data.enabled === 'boolean' ) {
					box.checked = data.enabled;
				}
			} ).catch( function () {
				box.checked = ! wanted;
			} ).then( function () {
				box.disabled = false;
			} );
		} );
	}

	/**
	 * Bind everything currently in the document.
	 *
	 * @return {void}
	 */
	function bindAll() {
		var boxes = document.querySelectorAll( '[data-lrn-object-integration]' );
		Array.prototype.forEach.call( boxes, bind );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', bindAll );
	} else {
		bindAll();
	}

	// The course builder renders its settings panel client-side, so the section
	// can appear after this file has run. Re-binding is cheap and guarded by
	// dataset.lrnOiBound, so a late panel still works without a second script.
	if ( window.MutationObserver ) {
		new window.MutationObserver( bindAll ).observe(
			document.documentElement,
			{ childList: true, subtree: true }
		);
	}
}() );
