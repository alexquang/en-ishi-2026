/**
 * Inline "add tag" for the editor taxonomy cards.
 *
 * Each taxonomy checklist (course / lesson / question editors) can render a
 * `.lrn-tag-quick-add` row (admin-only — POST /admin/tags is admin-gated). On
 * Add, this creates the tag via REST, then prepends a pre-checked checkbox to
 * the adjacent `.lrn-tag-checklist` so the author can assign it immediately
 * without leaving the editor. No-op on pages without the markup.
 *
 * Reads REST config from the shared `window.learnomyAdmin` global.
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	var __ = wp.i18n.__;

	var admin     = window.learnomyAdmin || {};
	var restUrl   = admin.restUrl || ( ( window.wpApiSettings && window.wpApiSettings.root ) || '/wp-json/' ) + 'learnomy/v1/';
	var restNonce = admin.restNonce || admin.nonce || ( window.wpApiSettings && window.wpApiSettings.nonce ) || '';

	document.querySelectorAll( '.lrn-tag-quick-add' ).forEach( function ( box ) {
		var input  = box.querySelector( '.lrn-tag-quick-add__input' );
		var button = box.querySelector( '.lrn-tag-quick-add__btn' );
		var status = box.querySelector( '.lrn-tag-quick-add__status' );
		// The checklist this row feeds — the card's existing tag list, or (when
		// the taxonomy had no terms yet) created on demand below the help text.
		var card   = box.closest( '.lrn-admin-card' );
		var type   = box.getAttribute( 'data-tax-type' ) || 'course';
		if ( ! input || ! button || ! card ) {
			return;
		}

		function setStatus( msg, isError ) {
			if ( ! status ) { return; }
			status.textContent = msg || '';
			status.classList.toggle( 'is-error', !! isError );
		}

		function addTag() {
			var name = input.value.trim();
			if ( ! name ) {
				input.focus();
				return;
			}
			button.disabled = true;
			setStatus( '' );

			fetch( restUrl + 'admin/tags', {
				method:      'POST',
				credentials: 'same-origin',
				headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
				body:        JSON.stringify( { name: name, type: type } ),
			} )
				.then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, j: j }; } ); } )
				.then( function ( res ) {
					button.disabled = false;
					if ( ! res.ok ) {
						var msg = ( res.j && res.j.error && res.j.error.message )
							|| ( res.j && res.j.message ) || __( 'Could not add the tag.', 'learnomy' );
						setStatus( msg, true );
						return;
					}
					var tag = res.j && res.j.data ? res.j.data : res.j;
					if ( ! tag || ! tag.id ) {
						setStatus( __( 'Could not add the tag.', 'learnomy' ), true );
						return;
					}

					// Find (or create) the checklist and prepend the new, checked tag.
					var list = card.querySelector( '.lrn-tag-checklist' );
					if ( ! list ) {
						list = document.createElement( 'ul' );
						list.className = 'lrn-tag-checklist';
						box.parentNode.insertBefore( list, box );
					}
					if ( list.querySelector( 'input[value="' + String( tag.id ) + '"]' ) ) {
						setStatus( '' );
						input.value = '';
						return;
					}
					var li    = document.createElement( 'li' );
					var label = document.createElement( 'label' );
					label.className = 'lrn-checkbox-label';
					var cb = document.createElement( 'input' );
					cb.type    = 'checkbox';
					cb.name    = 'tag_ids[]';
					cb.value   = String( tag.id );
					cb.checked = true;
					var span = document.createElement( 'span' );
					span.textContent = tag.name || name;
					label.appendChild( cb );
					label.appendChild( span );
					li.appendChild( label );
					list.insertBefore( li, list.firstChild );

					input.value = '';
					input.focus();
					setStatus( '' );
				} )
				.catch( function () {
					button.disabled = false;
					setStatus( __( 'Network error. Please try again.', 'learnomy' ), true );
				} );
		}

		button.addEventListener( 'click', addTag );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				addTag();
			}
		} );
	} );
} )();
