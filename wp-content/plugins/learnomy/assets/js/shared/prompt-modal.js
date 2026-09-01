/**
 * Learnomy single-input prompt modal.
 *
 * Replaces the jarring native window.prompt() with a styled modal that
 * matches the Learnomy admin + frontend visual design. Used anywhere a
 * quick string input is needed (section title, lesson title, quiz title,
 * rename operations, etc).
 *
 * Reuses the `.lrn-modal`/`.lrn-confirm-modal` CSS from confirm-modal.css
 * so the visual layer stays DRY.
 *
 * Usage:
 *   const title = await window.lrnPrompt( {
 *     title:        'Add new section',
 *     message:      'Give this module a short, clear name.',
 *     label:        'Section title',
 *     placeholder:  'e.g. Getting Started',
 *     defaultValue: '',
 *     confirmLabel: 'Add section',
 *     cancelLabel:  'Cancel',
 *     required:     true,
 *     maxLength:    120,
 *   } );
 *   if ( null === title ) { return; } // user cancelled
 *   createSection( title );
 *
 * All DOM is built with document.createElement() + textContent — no
 * innerHTML. Focus is trapped inside the dialog while open; Escape and
 * backdrop click resolve to `null`; Enter submits; submit button is
 * disabled until the input has a non-empty trimmed value (when
 * `required` is true).
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var FOCUSABLE_SELECTOR = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function __( text, domain ) {
		if ( window.wp && window.wp.i18n && 'function' === typeof window.wp.i18n.__ ) {
			return window.wp.i18n.__( text, domain || 'learnomy' );
		}
		return text;
	}

	function getFocusable( container ) {
		var nodes = container.querySelectorAll( FOCUSABLE_SELECTOR );
		return Array.prototype.slice.call( nodes ).filter( function ( n ) {
			return ! n.hasAttribute( 'disabled' ) && null !== n.offsetParent;
		} );
	}

	/**
	 * Show a single-input prompt. Returns a Promise that resolves to the
	 * trimmed string value on submit, or `null` on cancel / backdrop / Escape.
	 *
	 * @param {Object} options
	 * @return {Promise<string|null>}
	 */
	function lrnPrompt( options ) {
		options = options || {};

		var title        = options.title        || __( 'Enter value', 'learnomy' );
		var message      = options.message      || '';
		var label        = options.label        || '';
		var defaultValue = options.defaultValue || '';
		var placeholder  = options.placeholder  || '';
		var confirmLabel = options.confirmLabel || __( 'Save', 'learnomy' );
		var cancelLabel  = options.cancelLabel  || __( 'Cancel', 'learnomy' );
		var required     = false !== options.required;
		var maxLength    = options.maxLength ? parseInt( options.maxLength, 10 ) : 0;

		return new Promise( function ( resolve ) {
			var modal = document.createElement( 'div' );
			modal.className = 'lrn-modal lrn-confirm-modal lrn-prompt-modal';
			modal.setAttribute( 'role', 'dialog' );
			modal.setAttribute( 'aria-modal', 'true' );

			var backdrop = document.createElement( 'div' );
			backdrop.className = 'lrn-modal__backdrop';
			modal.appendChild( backdrop );

			var dialog = document.createElement( 'div' );
			dialog.className = 'lrn-modal__dialog lrn-confirm-modal__dialog';
			dialog.setAttribute( 'role', 'document' );
			modal.appendChild( dialog );

			// Head.
			var head = document.createElement( 'div' );
			head.className = 'lrn-modal__head';
			var titleEl = document.createElement( 'h2' );
			titleEl.className = 'lrn-modal__title';
			var titleId       = 'lrn-prompt-title-' + Date.now();
			titleEl.id        = titleId;
			titleEl.textContent = title;
			modal.setAttribute( 'aria-labelledby', titleId );
			head.appendChild( titleEl );
			dialog.appendChild( head );

			// Body.
			var body = document.createElement( 'div' );
			body.className = 'lrn-modal__body';

			if ( message ) {
				var msgEl = document.createElement( 'p' );
				msgEl.className = 'lrn-confirm-modal__message';
				msgEl.textContent = message;
				body.appendChild( msgEl );
			}

			var inputId = 'lrn-prompt-input-' + Date.now();
			if ( label ) {
				var labelEl = document.createElement( 'label' );
				labelEl.className = 'lrn-prompt-modal__label';
				labelEl.setAttribute( 'for', inputId );
				labelEl.textContent = label;
				body.appendChild( labelEl );
			}

			var input = document.createElement( 'input' );
			input.type        = 'text';
			input.id          = inputId;
			input.className   = 'lrn-prompt-modal__input';
			input.value       = defaultValue;
			input.placeholder = placeholder;
			if ( maxLength > 0 ) {
				input.maxLength = maxLength;
			}
			input.setAttribute( 'autocomplete', 'off' );
			input.setAttribute( 'spellcheck', 'true' );
			body.appendChild( input );

			dialog.appendChild( body );

			// Footer.
			var foot = document.createElement( 'div' );
			foot.className = 'lrn-modal__foot';

			var cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'lrn-admin-btn lrn-btn lrn-btn--secondary lrn-confirm-modal__cancel';
			cancelBtn.textContent = cancelLabel;
			foot.appendChild( cancelBtn );

			var confirmBtn = document.createElement( 'button' );
			confirmBtn.type = 'button';
			confirmBtn.className = 'lrn-admin-btn lrn-admin-btn--primary lrn-btn lrn-btn--primary lrn-confirm-modal__confirm';
			confirmBtn.textContent = confirmLabel;
			foot.appendChild( confirmBtn );

			if ( required && '' === input.value.trim() ) {
				confirmBtn.disabled = true;
			}

			dialog.appendChild( foot );

			var previousActive = document.activeElement;

			function close( value ) {
				document.removeEventListener( 'keydown', onKeydown, true );
				modal.classList.add( 'lrn-modal--closing' );
				setTimeout( function () {
					if ( modal.parentNode ) {
						modal.parentNode.removeChild( modal );
					}
					if ( previousActive && 'function' === typeof previousActive.focus ) {
						previousActive.focus();
					}
					resolve( value );
				}, 120 );
			}

			function submit() {
				if ( confirmBtn.disabled ) {
					return;
				}
				close( input.value.trim() );
			}

			function cancel() {
				close( null );
			}

			function onKeydown( e ) {
				if ( 'Escape' === e.key ) {
					e.preventDefault();
					cancel();
					return;
				}
				if ( 'Enter' === e.key && 'TEXTAREA' !== e.target.tagName ) {
					e.preventDefault();
					submit();
					return;
				}
				if ( 'Tab' !== e.key ) {
					return;
				}
				var focusable = getFocusable( dialog );
				if ( ! focusable.length ) {
					return;
				}
				var first = focusable[ 0 ];
				var last  = focusable[ focusable.length - 1 ];
				if ( e.shiftKey && document.activeElement === first ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}

			input.addEventListener( 'input', function () {
				if ( required ) {
					confirmBtn.disabled = '' === input.value.trim();
				}
			} );

			confirmBtn.addEventListener( 'click', submit );
			cancelBtn.addEventListener( 'click', cancel );
			backdrop.addEventListener( 'click', cancel );

			document.body.appendChild( modal );
			document.addEventListener( 'keydown', onKeydown, true );

			setTimeout( function () {
				input.focus();
				input.select();
			}, 30 );
		} );
	}

	window.lrnPrompt = lrnPrompt;
} )();
