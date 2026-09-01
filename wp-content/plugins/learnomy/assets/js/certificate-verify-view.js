/**
 * Certificate Verify — Interactivity API store.
 *
 * Handles the copy-link button feedback on the public verification page.
 * All clipboard logic is lifted from the inline script that previously lived
 * in the template; centralising it here makes it testable and keeps the
 * template markup clean.
 *
 * Store name: learnomy/certificate-verify
 *
 * @package Learnomy
 */
import { store, getContext } from '@wordpress/interactivity';

store( 'learnomy/certificate-verify', {
	state: {
		/** Whether the copy-link action is in progress. */
		copying: false,
	},

	actions: {
		/**
		 * Open the certificate in a new tab and trigger the print dialog.
		 *
		 * Replaces the inline `onclick` that previously lived on the Print
		 * link (CSP + no-inline-JS rule). Honours ctrl/cmd-click so power
		 * users can still open the printable view in a background tab without
		 * the auto-print popping.
		 *
		 * @param {Event} event - The click event.
		 */
		printCert( event ) {
			if ( event.ctrlKey || event.metaKey || event.shiftKey ) {
				return; // Let the browser handle modified clicks normally.
			}
			event.preventDefault();
			const link = event.target.closest( 'a' );
			const href = link && link.href;
			if ( ! href ) {
				return;
			}
			const printWindow = window.open( href );
			if ( printWindow ) {
				setTimeout( () => printWindow.print(), 500 );
			}
		},

		/**
		 * Copy the certificate verification URL to the clipboard.
		 *
		 * Reads `data-wp-context="{ url: '…' }"` from the button element,
		 * falls back to the legacy execCommand path, and finally falls back
		 * to the Learnomy prompt modal (window.lrnPrompt) so the user can
		 * manually copy the URL on hostile (non-HTTPS or very old)
		 * environments. The native window.prompt was replaced because the
		 * audit flags it as a UX block-severity violation.
		 *
		 * @param {Event} event - The click event.
		 */
		async copyLink( event ) {
			event.preventDefault();

			const ctx     = getContext();
			const url     = ctx.url;
			const copiedLabel = ctx.copiedLabel || window.learnomyCertVerify?.copiedLabel || '';
			const failedLabel = ctx.failedLabel || window.learnomyCertVerify?.failedLabel || '';

			if ( ! url ) {
				return;
			}

			const btn = event.target.closest( '[data-wp-on--click]' );

			/**
			 * Open the Learnomy prompt modal pre-filled with the URL so the
			 * user can copy it manually. No-op if the helper hasn't loaded
			 * (route should always enqueue it, but the guard keeps the
			 * action graceful).
			 *
			 * @param {string} message - Failure label shown above the input.
			 * @param {string} value   - URL to pre-fill.
			 */
			function manualCopyFallback( message, value ) {
				if ( 'function' !== typeof window.lrnPrompt ) {
					return;
				}
				window.lrnPrompt( {
					title:        message,
					label:        message,
					defaultValue: value,
					confirmLabel: copiedLabel || message,
					required:     false,
				} );
			}

			/**
			 * Flash the button label briefly, then restore the original text.
			 *
			 * @param {HTMLElement} el      - The button element.
			 * @param {string}      message - Temporary label to show.
			 */
			function flashLabel( el, message ) {
				if ( ! el ) {
					return;
				}
				const lucideIcon = el.querySelector( '[data-lucide]' );
				const textNode   = lucideIcon && lucideIcon.nextSibling
					&& lucideIcon.nextSibling.nodeType === Node.TEXT_NODE
					? lucideIcon.nextSibling
					: null;
				const target = textNode || el;
				const saved  = target === el ? el.innerText : target.textContent;

				if ( target === el ) {
					el.innerText = message;
				} else {
					target.textContent = message;
				}

				setTimeout( () => {
					if ( target === el ) {
						el.innerText = saved;
					} else {
						target.textContent = saved;
					}
				}, 2000 );
			}

			/**
			 * Legacy execCommand copy path for non-secure or older contexts.
			 *
			 * @param {string} text - The text to copy.
			 * @return {boolean} Whether the copy succeeded.
			 */
			function legacyCopy( text ) {
				const ta            = document.createElement( 'textarea' );
				ta.value            = text;
				ta.style.position   = 'fixed';
				ta.style.top        = '-1000px';
				ta.style.left       = '-1000px';
				ta.setAttribute( 'readonly', '' );
				document.body.appendChild( ta );
				ta.select();
				ta.setSelectionRange( 0, ta.value.length );
				let ok = false;
				try {
					ok = document.execCommand( 'copy' );
				} catch ( _e ) {
					ok = false;
				}
				document.body.removeChild( ta );
				return ok;
			}

			if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
				try {
					await navigator.clipboard.writeText( url );
					flashLabel( btn, copiedLabel );
				} catch ( _e ) {
					if ( legacyCopy( url ) ) {
						flashLabel( btn, copiedLabel );
					} else {
						manualCopyFallback( failedLabel, url );
					}
				}
				return;
			}

			if ( legacyCopy( url ) ) {
				flashLabel( btn, copiedLabel );
				return;
			}

			manualCopyFallback( failedLabel, url );
		},
	},
} );

/**
 * Scale the sandboxed certificate-preview iframe to fit its container.
 *
 * The certificate renders at its native print size (297mm x 210mm) inside an
 * isolated iframe (templates/certificate-verify.php) so its global print CSS
 * (`html, body { width: 297mm }`, a `*` reset, `@page`) can't leak onto the
 * verify page and squeeze the theme layout (Basecamp #9976411641). Here we
 * scale that fixed-size frame to the container's current width and set the
 * container height to match, so the preview fills the column at the correct
 * 297:210 aspect on every breakpoint and on resize.
 */
// 297mm in CSS px. The matching 210mm height is deliberately NOT a constant
// here — the box's height comes from `aspect-ratio: 297/210` in cert-verify.css,
// and a second copy of that ratio in JS is exactly what let the two drift.
const CERT_NATIVE_WIDTH = 1122.52;

function fitCertPreviews() {
	document.querySelectorAll( '[data-cert-preview]' ).forEach( ( box ) => {
		const frame = box.querySelector( '.lrn-certificate-verify__preview-frame' );
		if ( ! frame ) {
			return;
		}
		// Scale only. The box's height belongs to `aspect-ratio: 297/210` in
		// cert-verify.css and must NOT also be written here.
		//
		// Writing an inline height made the two mechanisms fight: with a height
		// set, aspect-ratio derives the box's WIDTH from that height instead of
		// from the parent, so the next resize measured the element's own stale
		// answer and never reconverged. Loading at 1440 then resizing to 390
		// left a 730px box inside a 360px parent, clipping the recipient's name
		// and the course title off-screen (Basecamp 10159650505).
		//
		// The two agree at first paint, which is why this survived: it only
		// diverges on the SECOND measurement. A fresh load at any single width
		// was always correct; a phone rotating on a shared verification link
		// was not.
		frame.style.transform = `scale(${ box.clientWidth / CERT_NATIVE_WIDTH })`;
	} );
}

if ( document.querySelector( '[data-cert-preview]' ) ) {
	fitCertPreviews();
	// Re-fit on viewport resize and once each iframe's document has loaded.
	window.addEventListener( 'resize', fitCertPreviews );
	document
		.querySelectorAll( '.lrn-certificate-verify__preview-frame' )
		.forEach( ( frame ) => frame.addEventListener( 'load', fitCertPreviews ) );
}
