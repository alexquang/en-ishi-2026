/**
 * Learnomy admin — Registration & profile fields repeater.
 *
 * Add/remove field rows on the Settings "Registration Fields" section and
 * toggle the dropdown-options box per row. Rows save as
 * learnomy_settings[custom_account_fields][i][...]; new rows get a fresh index
 * so they don't collide with existing ones.
 */
( function () {
	'use strict';

	var repeater = document.getElementById( 'lrn-account-fields-repeater' );
	var addBtn   = document.getElementById( 'lrn-account-fields-add' );
	var template = document.getElementById( 'lrn-account-field-template' );
	if ( ! repeater || ! addBtn || ! template ) {
		return;
	}

	var empty = document.querySelector( '.lrn-account-fields-empty' );

	// Next index = current row count; bump forever so removed indexes aren't reused.
	var nextIndex = repeater.querySelectorAll( '[data-lrn-field-row]' ).length;


	function syncEmpty() {
		if ( empty ) {
			empty.hidden = repeater.querySelectorAll( '[data-lrn-field-row]' ).length > 0;
		}
	}

	// Show the options textarea only for the "Dropdown" (select) type.
	function bindRow( row ) {
		var typeSel = row.querySelector( '[data-lrn-field-type]' );
		var options = row.querySelector( '[data-lrn-field-options]' );
		if ( typeSel && options ) {
			typeSel.addEventListener( 'change', function () {
				options.hidden = 'select' !== typeSel.value;
			} );
		}
		var removeBtn = row.querySelector( '[data-lrn-field-remove]' );
		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function () {
				row.remove();
				syncEmpty();
			} );
		}
	}

	// Bind rows already present on load.
	repeater.querySelectorAll( '[data-lrn-field-row]' ).forEach( bindRow );

	addBtn.addEventListener( 'click', function () {
		// Clone the server-rendered <template> (no innerHTML) and replace the
		// __INDEX__ placeholder — a numeric counter, never user input — in the
		// name attributes of the cloned controls.
		var index    = String( nextIndex++ );
		var fragment = template.content.cloneNode( true );
		var row      = fragment.querySelector( '[data-lrn-field-row]' );
		if ( ! row ) {
			return;
		}
		row.querySelectorAll( '[name]' ).forEach( function ( el ) {
			el.setAttribute( 'name', el.getAttribute( 'name' ).replace( /__INDEX__/g, index ) );
		} );
		repeater.appendChild( row );
		bindRow( row );
		syncEmpty();
		var label = row.querySelector( '[data-lrn-field-label]' );
		if ( label ) {
			label.focus();
		}
	} );
}() );
