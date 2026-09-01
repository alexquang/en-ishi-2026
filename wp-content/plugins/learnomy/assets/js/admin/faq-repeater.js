/**
 * Membership FAQ repeater — add / remove Q&A rows on the Settings screen.
 *
 * The server renders the saved rows plus a <template> holding one blank row
 * (field names carry an `__INDEX__` placeholder). "Add question" clones the
 * template with the next index; each row's trash button removes it. The
 * settings sanitizer already drops blank rows and doesn't care about index
 * gaps, so no re-indexing is needed here.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var repeater = document.querySelector( '[data-lrn-faq-repeater]' );
	var addBtn   = document.querySelector( '[data-lrn-faq-add]' );
	var template = document.getElementById( 'lrn-faq-row-template' );

	if ( ! repeater || ! addBtn || ! template ) {
		return;
	}

	// Next index continues past the highest server-rendered row so new rows
	// never collide with an existing one in the submitted array.
	var nextIndex = repeater.querySelectorAll( '[data-lrn-faq-row]' ).length;

	function bindRemove( row ) {
		var btn = row.querySelector( '[data-lrn-faq-remove]' );
		if ( btn ) {
			btn.addEventListener( 'click', function () {
				row.remove();
			} );
		}
	}

	repeater.querySelectorAll( '[data-lrn-faq-row]' ).forEach( bindRemove );

	addBtn.addEventListener( 'click', function () {
		// Clone the server-rendered <template> (no innerHTML) and swap the
		// placeholder in the field names for the next index.
		var fragment = template.content.cloneNode( true );
		var row      = fragment.querySelector( '[data-lrn-faq-row]' );
		if ( ! row ) {
			return;
		}
		row.querySelectorAll( '[name]' ).forEach( function ( el ) {
			el.name = el.name.replace( '__INDEX__', String( nextIndex ) );
		} );
		nextIndex++;
		bindRemove( row );
		repeater.appendChild( row );

		// No icon call here: lucide-live.js observes the document and converts
		// anything added later, including this row (Basecamp 10184590411).

		var firstInput = row.querySelector( 'input' );
		if ( firstInput ) {
			firstInput.focus();
		}
	} );
} )();
