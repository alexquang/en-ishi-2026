/**
 * Learnomy confirmation modal dialog.
 *
 * Replaces the jarring, untranslatable native window.confirm() with a
 * styled modal that matches the Learnomy admin + frontend visual design.
 * Works in both admin (.learnomy-admin) and frontend (.lrn-app) contexts
 * because both scopes define the same --lrn-* CSS tokens.
 *
 * Usage:
 *   const ok = await window.lrnConfirm( {
 *     title:         'Delete item',
 *     message:       'Are you sure? This cannot be undone.',
 *     confirmLabel:  'Delete',
 *     cancelLabel:   'Cancel',
 *     destructive:   true,
 *   } );
 *   if ( ! ok ) { return; }
 *
 * All DOM is built with document.createElement() + textContent to match
 * the security posture of the sibling toast.js helper — no innerHTML.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var FOCUSABLE_SELECTOR = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	/**
	 * Resolve a translatable string.
	 *
	 * Falls back to the hardcoded English when wp.i18n is unavailable so
	 * the helper keeps working before @wordpress/i18n has loaded.
	 *
	 * @param {string} text   Source string.
	 * @param {string} domain Text domain (ignored in fallback).
	 * @return {string}
	 */
	function __( text, domain ) {
		if ( window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function' ) {
			return window.wp.i18n.__( text, domain || 'learnomy' );
		}
		return text;
	}

	/**
	 * Return the list of focusable elements inside a container, in tab order.
	 *
	 * @param {HTMLElement} container Root element to scan.
	 * @return {HTMLElement[]}
	 */
	function getFocusable( container ) {
		var nodes = container.querySelectorAll( FOCUSABLE_SELECTOR );
		return Array.prototype.slice.call( nodes ).filter( function ( n ) {
			return ! n.hasAttribute( 'disabled' ) && n.offsetParent !== null;
		} );
	}

	/**
	 * Show a confirmation modal.
	 *
	 * @param {Object}  options
	 * @param {string}  [options.title]        Dialog title.
	 * @param {string}  [options.message]      Body message shown under the title.
	 * @param {string}  [options.confirmLabel] Confirm button label (default "Confirm").
	 * @param {string}  [options.cancelLabel]  Cancel button label (default "Cancel").
	 * @param {boolean} [options.destructive]  When true, styles the confirm button
	 *                                          as a danger action and adds the
	 *                                          .lrn-modal--destructive hook.
	 * @param {Object}  [options.input]        Optional single field — turns this into
	 *                                          a "confirm, but with a value" dialog.
	 *                                          { label, type, value, min, max, step, help }.
	 *                                          Native validity is enforced before resolve.
	 * @return {Promise<boolean|string>} Without `input`: true on confirm, false on
	 *                            cancel / Esc / backdrop. With `input`: the field's
	 *                            VALUE on confirm, false on cancel.
	 */
	function lrnConfirm( options ) {
		options = options || {};

		var title        = options.title        || __( 'Please confirm', 'learnomy' );
		var message      = options.message      || '';
		var confirmLabel = options.confirmLabel || __( 'Confirm', 'learnomy' );
		var cancelLabel  = options.cancelLabel  || __( 'Cancel', 'learnomy' );
		var destructive  = !! options.destructive;

		return new Promise( function ( resolve ) {
			// Root container.
			var modal = document.createElement( 'div' );
			modal.className = 'lrn-modal lrn-confirm-modal';
			if ( destructive ) {
				modal.classList.add( 'lrn-modal--destructive' );
			}
			modal.setAttribute( 'role', 'dialog' );
			modal.setAttribute( 'aria-modal', 'true' );

			// Backdrop.
			var backdrop = document.createElement( 'div' );
			backdrop.className = 'lrn-modal__backdrop';
			modal.appendChild( backdrop );

			// Dialog wrapper.
			var dialog = document.createElement( 'div' );
			dialog.className = 'lrn-modal__dialog lrn-confirm-modal__dialog';
			dialog.setAttribute( 'role', 'document' );
			modal.appendChild( dialog );

			// Head (title only — confirm modals omit the X close button so
			// students are forced to make a deliberate choice).
			var head = document.createElement( 'div' );
			head.className = 'lrn-modal__head';

			var titleEl = document.createElement( 'h2' );
			titleEl.className = 'lrn-modal__title';
			titleEl.textContent = title;
			// aria-labelledby wiring for screen readers.
			var titleId = 'lrn-confirm-title-' + Date.now();
			titleEl.id = titleId;
			modal.setAttribute( 'aria-labelledby', titleId );
			head.appendChild( titleEl );

			dialog.appendChild( head );

			// Body — message paragraph, and optionally ONE input.
			//
			// The input exists because a refund is the archetypal "confirm, but how
			// much?" action: partial refunds were implemented in every adapter and in
			// the refund service, and were unreachable because the only refund control
			// in the product was a yes/no button. Rather than hand-roll a bespoke
			// refund dialog (a second modal implementation, and a native prompt() is
			// banned outright), the shared modal grew the field.
			//
			// Contract: with no `input`, this still resolves true/false exactly as
			// before — every existing caller is unaffected. With an `input`, it
			// resolves the VALUE on confirm and `false` on cancel.
			var inputEl = null;

			if ( message || options.input ) {
				var body = document.createElement( 'div' );
				body.className = 'lrn-modal__body';

				if ( message ) {
					var msgEl = document.createElement( 'p' );
					msgEl.className = 'lrn-confirm-modal__message';
					msgEl.textContent = message;
					body.appendChild( msgEl );
				}

				if ( options.input ) {
					var cfg = options.input;

					var field = document.createElement( 'div' );
					field.className = 'lrn-form-field lrn-confirm-modal__field';

					var inputId = 'lrn-confirm-input-' + Date.now();

					if ( cfg.label ) {
						var labelEl = document.createElement( 'label' );
						labelEl.setAttribute( 'for', inputId );
						labelEl.textContent = cfg.label;
						field.appendChild( labelEl );
					}

					inputEl = document.createElement( 'input' );
					inputEl.id = inputId;
					inputEl.type = cfg.type || 'text';
					inputEl.className = 'lrn-input';
					if ( undefined !== cfg.value ) { inputEl.value = String( cfg.value ); }
					if ( undefined !== cfg.min )   { inputEl.min   = String( cfg.min ); }
					if ( undefined !== cfg.max )   { inputEl.max   = String( cfg.max ); }
					if ( undefined !== cfg.step )  { inputEl.step  = String( cfg.step ); }
					field.appendChild( inputEl );

					if ( cfg.help ) {
						var helpEl = document.createElement( 'p' );
						helpEl.className = 'lrn-form-help';
						helpEl.textContent = cfg.help;
						field.appendChild( helpEl );
					}

					body.appendChild( field );
				}

				dialog.appendChild( body );
			}

			// Footer — cancel + confirm buttons.
			var foot = document.createElement( 'div' );
			foot.className = 'lrn-modal__foot';

			var cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'lrn-admin-btn lrn-btn lrn-btn--secondary lrn-confirm-modal__cancel';
			cancelBtn.textContent = cancelLabel;
			foot.appendChild( cancelBtn );

			var confirmBtn = document.createElement( 'button' );
			confirmBtn.type = 'button';
			confirmBtn.className = destructive
				? 'lrn-admin-btn lrn-admin-btn--danger lrn-btn lrn-btn--danger lrn-confirm-modal__confirm'
				: 'lrn-admin-btn lrn-admin-btn--primary lrn-btn lrn-btn--primary lrn-confirm-modal__confirm';
			confirmBtn.textContent = confirmLabel;
			foot.appendChild( confirmBtn );

			dialog.appendChild( foot );

			// Remember the element that had focus before we opened so we can
			// restore it on close.
			var previousActive = document.activeElement;

			/**
			 * Close the modal and resolve the outer Promise.
			 *
			 * @param {boolean} result Value to resolve with.
			 */
			function close( result ) {
				document.removeEventListener( 'keydown', onKeydown, true );
				modal.classList.add( 'lrn-modal--closing' );

				// Remove on next tick — CSS may add a transition, but even
				// without one this keeps the resolution deterministic.
				var remove = function () {
					if ( modal.parentNode ) {
						modal.parentNode.removeChild( modal );
					}
					if ( previousActive && typeof previousActive.focus === 'function' ) {
						try { previousActive.focus(); } catch ( e ) { /* noop */ }
					}
					resolve( result );
				};

				// If the stylesheet provides a transition, wait for it.
				var dur = 0;
				try {
					var cs = window.getComputedStyle( modal );
					dur = parseFloat( cs.transitionDuration ) || 0;
				} catch ( e ) { /* noop */ }

				if ( dur > 0 ) {
					setTimeout( remove, dur * 1000 );
				} else {
					remove();
				}
			}

			/**
			 * Keyboard handler: Esc cancels, Tab traps focus inside the modal.
			 */
			function onKeydown( e ) {
				if ( e.key === 'Escape' ) {
					e.preventDefault();
					e.stopPropagation();
					close( false );
					return;
				}

				if ( e.key !== 'Tab' ) {
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

			cancelBtn.addEventListener( 'click', function () { close( false ); } );
			backdrop.addEventListener( 'click', function () { close( false ); } );

			// With an input, confirm resolves the VALUE. Without one, it resolves true —
			// the original boolean contract, so every existing caller is untouched.
			confirmBtn.addEventListener( 'click', function () {
				if ( ! inputEl ) {
					close( true );
					return;
				}

				// Let the browser enforce min/max/step/required before we hand a bad
				// value to the server. Native validity beats a hand-rolled check.
				if ( typeof inputEl.reportValidity === 'function' && ! inputEl.reportValidity() ) {
					return;
				}

				close( inputEl.value );
			} );

			document.addEventListener( 'keydown', onKeydown, true );

			// Mount and focus the confirm button.
			document.body.appendChild( modal );

			// Diagnostic logging — when QA sees "only the buttons are visible"
			// (card #9861810340), we need the computed styles of the dialog
			// + backdrop to triage. Without it, environment-specific CSS
			// conflicts (theme, plugin, browser zoom) take a manual round-trip
			// to identify. Set window.LRN_CONFIRM_DEBUG=true (or pass
			// options.debug=true) to enable per-open logging.
			if ( window.LRN_CONFIRM_DEBUG || options.debug ) {
				try {
					var dialogStyles = window.getComputedStyle( dialog );
					var backdropStyles = window.getComputedStyle( backdrop );
					var modalStyles = window.getComputedStyle( modal );
					if ( window.console && window.console.info ) {
						window.console.info( '[lrnConfirm] mounted', {
							modal: {
								classList: modal.className,
								display: modalStyles.display,
								zIndex: modalStyles.zIndex,
								position: modalStyles.position,
								width: modalStyles.width,
								height: modalStyles.height,
							},
							dialog: {
								background: dialogStyles.background,
								border: dialogStyles.border,
								maxWidth: dialogStyles.maxWidth,
								display: dialogStyles.display,
								boxShadow: dialogStyles.boxShadow,
							},
							backdrop: {
								background: backdropStyles.background,
								position: backdropStyles.position,
							},
							viewport: { w: window.innerWidth, h: window.innerHeight },
						} );
					}
				} catch ( diagErr ) { /* noop */ }
			}

			// requestAnimationFrame gives the browser a paint before focus,
			// avoiding a jarring scroll jump when the dialog mounts off-screen.
			if ( typeof window.requestAnimationFrame === 'function' ) {
				// Focus the input when there is one — landing focus on a DESTRUCTIVE
				// button and letting Enter fire it is how someone refunds the wrong
				// amount. With a field, the user must type before they can confirm.
				window.requestAnimationFrame( function () {
					( inputEl || confirmBtn ).focus();
					if ( inputEl && typeof inputEl.select === 'function' ) { inputEl.select(); }
				} );
			} else {
				( inputEl || confirmBtn ).focus();
				if ( inputEl && typeof inputEl.select === 'function' ) { inputEl.select(); }
			}
		} );
	}

	// Expose globally.
	window.lrnConfirm = lrnConfirm;
} )();
