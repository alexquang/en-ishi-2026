/**
 * Extension-field collector — completes the editor-fields integration seam.
 *
 * The course / lesson / quiz editors build their REST body from a fixed
 * allowlist of named fields, so any markup an integration prints via
 * `learnomy_{course,lesson,quiz}_editor_fields` is otherwise dropped before
 * the request is sent. This util gathers every named control inside a
 * `[data-lrn-ext-fields]` panel into a nested object that each editor merges
 * onto the request as `extension_fields`. The matching `learnomy_*_saved`
 * action then reads it from `$request->get_json_params()['extension_fields']`.
 *
 * Supports the same name shapes WordPress form fields use: scalars (`foo`),
 * bare arrays (`foo[]`), and nested keys (`foo[bar][]`, `foo[0][baz]`). This
 * means an integration can render its own picker (e.g. WP Fusion's
 * `wpf_render_tag_multiselect()`, which emits `meta[field][]`) and have it
 * round-trip without translation.
 *
 * Vanilla JS, no jQuery. Exposed as window.lrnExtFields.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	/**
	 * Split a PHP-style field name into path parts.
	 * "wpf-settings[apply_tags][]" -> [ "wpf-settings", "apply_tags", "" ]
	 *
	 * @param {string} name Field name.
	 * @return {Array} Path parts; an empty string marks a bare `[]` segment.
	 */
	function pathOf( name ) {
		var parts = [];
		var head  = name.indexOf( '[' );
		if ( head === -1 ) {
			return [ name ];
		}
		parts.push( name.slice( 0, head ) );
		var rest = name.slice( head );
		var re   = /\[([^\]]*)\]/g;
		var m;
		while ( ( m = re.exec( rest ) ) !== null ) {
			parts.push( m[ 1 ] );
		}
		return parts;
	}

	/**
	 * Assign a value into a nested object following the parsed path. Empty
	 * path segments ("") push onto an array container.
	 *
	 * @param {Object} root  The accumulator object.
	 * @param {Array}  parts Path parts from pathOf().
	 * @param {*}      value The value to assign (string or array of strings).
	 */
	function assign( root, parts, value ) {
		var node = root;
		for ( var i = 0; i < parts.length; i++ ) {
			var part   = parts[ i ];
			var isLast = i === parts.length - 1;

			if ( part === '' ) {
				// Bare [] — append to an array at this position.
				if ( isLast ) {
					if ( Array.isArray( value ) ) {
						value.forEach( function ( v ) { node.push( v ); } );
					} else {
						node.push( value );
					}
					return;
				}
				// Bare [] mid-path: create/descend into a fresh array slot.
				var slot = {};
				node.push( slot );
				node = slot;
				continue;
			}

			if ( isLast ) {
				node[ part ] = value;
				return;
			}

			// Look ahead: a bare [] next means this key holds an array.
			var next = parts[ i + 1 ];
			if ( node[ part ] === undefined ) {
				node[ part ] = ( next === '' ) ? [] : {};
			}
			node = node[ part ];
		}
	}

	/**
	 * Read a control's value, returning an array for multi-value controls
	 * (multi-selects, repeated checkboxes) and null when the control should
	 * not contribute (unchecked single checkbox/radio).
	 *
	 * @param {HTMLElement} el A form control with a name.
	 * @return {*} The value, an array, or null to skip.
	 */
	function valueOf( el ) {
		var type = ( el.type || '' ).toLowerCase();

		if ( type === 'checkbox' || type === 'radio' ) {
			return el.checked ? ( el.value || '1' ) : null;
		}

		if ( el.tagName === 'SELECT' && el.multiple ) {
			return Array.prototype.filter
				.call( el.options, function ( o ) { return o.selected; } )
				.map( function ( o ) { return o.value; } );
		}

		return el.value;
	}

	/**
	 * Serialize every named control inside each `[data-lrn-ext-fields]` panel
	 * found under `root` into one nested object.
	 *
	 * @param {HTMLElement} [root] Scan root; defaults to document.
	 * @return {Object} The collected extension fields (empty object if none).
	 */
	function collect( root ) {
		var scope  = root || document;
		var panels = scope.querySelectorAll( '[data-lrn-ext-fields]' );
		var out    = {};

		Array.prototype.forEach.call( panels, function ( panel ) {
			var controls = panel.querySelectorAll( '[name]' );
			Array.prototype.forEach.call( controls, function ( el ) {
				if ( el.disabled ) {
					return;
				}
				var name = el.getAttribute( 'name' );
				if ( ! name ) {
					return;
				}
				var value = valueOf( el );
				if ( value === null ) {
					return;
				}
				assign( out, pathOf( name ), value );
			} );
		} );

		return out;
	}

	window.lrnExtFields = { collect: collect };
}() );
