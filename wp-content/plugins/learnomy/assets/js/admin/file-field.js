/**
 * Learnomy — shared admin file-field behavior.
 *
 * The canonical file upload control for the whole plugin (Free + every Pro
 * extension): a hidden native <input type="file"> behind a `.lrn-admin-btn`
 * "Choose file" trigger (a <label for>), with the picked filename shown beside
 * it. One component, so QTI import, SCORM package upload, CSV imports, etc. all
 * look identical instead of each re-styling a raw browser file input.
 *
 * Markup contract (no per-extension JS needed):
 *
 *   <div class="lrn-file-field">
 *     <input type="file" id="x" class="lrn-file-field__input" hidden>
 *     <label for="x" class="lrn-admin-btn"><i data-lucide="paperclip"></i> Choose file</label>
 *     <span class="lrn-file-field__name" data-empty="No file chosen">No file chosen</span>
 *   </div>
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	// Delegated so it covers file fields rendered after load (modals, AJAX).
	document.addEventListener( 'change', function ( e ) {
		var input = e.target && e.target.closest ? e.target.closest( '.lrn-file-field__input' ) : null;
		if ( ! input ) {
			return;
		}
		var field  = input.closest( '.lrn-file-field' );
		var nameEl = field ? field.querySelector( '.lrn-file-field__name' ) : null;
		if ( ! nameEl ) {
			return;
		}
		var picked = input.files && input.files[ 0 ];
		// The empty-state label is server-rendered (translated) in data-empty.
		nameEl.textContent = picked ? picked.name : ( nameEl.getAttribute( 'data-empty' ) || '' );
	} );
} )();
