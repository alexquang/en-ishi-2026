/**
 * Copy a block of text to the clipboard from an admin notice.
 *
 * Generic and dependency-free on purpose. It is enqueued by whichever surface
 * needs it (today: the protected-uploads notice, which renders on Learnomy
 * screens AND on Site Health, where the main admin bundle is not loaded), so
 * it must not assume jQuery or any Learnomy global.
 *
 * Markup contract:
 *   <button data-lrn-copy-target="some-id">Copy</button>
 *   <span class="lrn-protected-copied" hidden>Copied.</span>
 *   <textarea id="some-id">…</textarea>
 *
 * The textarea is the source of truth and stays visible and selectable, so a
 * browser that blocks the clipboard API entirely still leaves the owner able
 * to select the text by hand. The button is an accelerator, never the only way.
 */
( function () {
	'use strict';

	/**
	 * Announce success next to the button, then fade back.
	 *
	 * @param {HTMLElement} button The clicked button.
	 */
	function announce( button ) {
		var status = button.parentNode
			? button.parentNode.querySelector( '.lrn-protected-copied' )
			: null;

		if ( ! status ) {
			return;
		}

		status.hidden = false;
		window.setTimeout( function () {
			status.hidden = true;
		}, 3000 );
	}

	/**
	 * Pre-clipboard-API fallback: select the field and let the browser copy.
	 *
	 * @param {HTMLTextAreaElement} field The source field.
	 * @return {boolean} Whether the copy succeeded.
	 */
	function legacyCopy( field ) {
		field.removeAttribute( 'readonly' );
		field.select();
		field.setSelectionRange( 0, field.value.length );

		var ok = false;
		try {
			ok = document.execCommand( 'copy' );
		} catch ( e ) {
			ok = false;
		}

		field.setAttribute( 'readonly', 'readonly' );
		return ok;
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-lrn-copy-target]' );
		if ( ! button ) {
			return;
		}

		event.preventDefault();

		var field = document.getElementById(
			button.getAttribute( 'data-lrn-copy-target' )
		);
		if ( ! field ) {
			return;
		}

		if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
			navigator.clipboard.writeText( field.value ).then(
				function () {
					announce( button );
				},
				function () {
					// Clipboard API present but refused (insecure context,
					// permissions policy). Selecting the text is still useful:
					// it leaves the owner one keystroke from copying.
					if ( legacyCopy( field ) ) {
						announce( button );
					} else {
						field.focus();
						field.select();
					}
				}
			);
			return;
		}

		if ( legacyCopy( field ) ) {
			announce( button );
			return;
		}

		field.focus();
		field.select();
	} );
}() );
