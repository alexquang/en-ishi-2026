/**
 * Admin block editors — the ONE mount for every Editor.js content area on an
 * admin form (course Description, course Completion / Welcome messages, and any
 * future rich field). It replaces the per-field WordPress classic editor
 * (`wp_editor()` / TinyMCE) so every "describe / message" surface in wp-admin
 * uses the same block editor, tools, and upload endpoints — no scattered editors.
 *
 * Convention (no per-field JS): mark up a host + a hidden input and this mounts
 * the editor and keeps the input in sync:
 *
 *   <div class="lrn-editor-host" data-input="lrn-x" data-placeholder="…"></div>
 *   <input type="hidden" id="lrn-x" name="x" value="<seed>">
 *
 * The seed accepts Editor.js JSON, legacy HTML, or empty (legacy HTML is wrapped
 * in a single paragraph block; `\Learnomy\render_editorjs_content()` renders both
 * shapes, so stored content keeps rendering during the transition). On submit of
 * the host's form, every editor serializes to its hidden input first.
 *
 * Reads `window.learnomyBlockEditor` ({ restUrl, nonce, i18n.placeholder }) for
 * the shared upload credentials. Tools come from the shared
 * `window.lrnEditorTools()` factory so all editors carry the same block set.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var config = window.learnomyBlockEditor || {};

	if ( 'undefined' === typeof EditorJS ) {
		return;
	}

	var hosts = document.querySelectorAll( '.lrn-editor-host[data-input]' );
	if ( ! hosts.length ) {
		return;
	}

	// Uniform tool set — the SAME blocks (incl. image upload, table, embed) every
	// editor uses, from the one shared factory. Never hand-build a per-editor
	// tools object: that is exactly how the editors drifted apart.
	var tools = ( 'function' === typeof window.lrnEditorTools )
		? window.lrnEditorTools( { restUrl: config.restUrl, nonce: config.nonce } )
		: {};

	/**
	 * Convert a seed value (Editor.js JSON, legacy HTML, or empty) into an
	 * Editor.js `data` object the constructor accepts.
	 *
	 * @param {string} raw Stored value.
	 * @return {Object} Editor.js data.
	 */
	function seedData( raw ) {
		if ( ! raw ) {
			return { blocks: [] };
		}
		try {
			var parsed = JSON.parse( raw );
			if ( parsed && Array.isArray( parsed.blocks ) ) {
				return parsed;
			}
		} catch ( e ) {
			// Not JSON — fall through to the legacy HTML import path.
		}
		// Legacy HTML — wrap as a single paragraph block so the author can keep
		// editing. The renderer accepts both shapes, so nothing breaks.
		return { blocks: [ { type: 'paragraph', data: { text: raw } } ] };
	}

	var instances = [];
	var forms     = [];

	hosts.forEach( function ( host ) {
		var hidden = document.getElementById( host.getAttribute( 'data-input' ) );
		if ( ! hidden ) {
			return;
		}

		var editor = new EditorJS( {
			holder:      host,
			tools:       tools,
			data:        seedData( hidden.value ),
			// Real primary-content height so each reads as a content area, not a
			// one-line field (the .lrn-editor-host CSS gives the box its floor).
			minHeight:   300,
			placeholder: host.getAttribute( 'data-placeholder' )
				|| ( config.i18n && config.i18n.placeholder )
				|| '',
		} );

		instances.push( { editor: editor, hidden: hidden } );

		var form = host.closest( 'form' );
		if ( form && -1 === forms.indexOf( form ) ) {
			forms.push( form );
		}
	} );

	/**
	 * Serialize every editor into its hidden input. Resolves once all are done.
	 *
	 * @return {Promise} Resolves when every hidden input carries fresh JSON.
	 */
	function syncAll() {
		return Promise.all(
			instances.map( function ( instance ) {
				return instance.editor.save().then( function ( output ) {
					// An empty editor serializes to {"blocks":[]}. Store an empty
					// string instead so downstream "has content?" checks (and the
					// renderer) treat a blank field as truly empty.
					instance.hidden.value = ( output && output.blocks && output.blocks.length > 0 )
						? JSON.stringify( output )
						: '';
				} ).catch( function ( err ) {
					window.console && window.console.error( 'Block editor save failed:', err );
				} );
			} )
		);
	}

	// Block each owning form's submit, serialize all editors, then re-fire so the
	// hidden inputs carry the latest JSON when the form data is gathered.
	forms.forEach( function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( '1' === form.dataset.blockEditorSynced ) {
				return;
			}
			event.preventDefault();
			syncAll().then( function () {
				form.dataset.blockEditorSynced = '1';
				if ( 'function' === typeof form.requestSubmit ) {
					form.requestSubmit();
				} else {
					form.submit();
				}
			} );
		}, true );
	} );
} )();
