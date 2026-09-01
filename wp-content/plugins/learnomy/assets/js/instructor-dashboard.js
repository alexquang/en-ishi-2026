/**
 * Instructor dashboard frontend actions.
 *
 * "Request Withdrawal" opens a single branded modal collecting amount +
 * payout method together, POSTs to /learnomy/v1/earnings/withdraw, and
 * shows a toast with the result. Replaces the link-to-admin-page pattern
 * that 403'd for instructor-role users who don't have `manage_options`.
 *
 * Reuses `.lrn-modal` / `.lrn-confirm-modal` CSS from confirm-modal.css
 * so visuals stay DRY.
 */
( function () {
	'use strict';

	var FOCUSABLE_SELECTOR = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	// Minimum withdrawal — sourced from the admin `withdrawal_minimum` setting
	// (localized below). Was hardcoded to $50, ignoring the configured value
	// (default $25) and disagreeing with the server's own gate.
	var WITHDRAW_MIN = ( window.learnomyInstructorDashboard
		&& parseFloat( window.learnomyInstructorDashboard.withdrawalMin ) > 0 )
		? parseFloat( window.learnomyInstructorDashboard.withdrawalMin )
		: 25;

	function withdrawMinLabel() {
		return '$' + ( WITHDRAW_MIN % 1 === 0 ? WITHDRAW_MIN : WITHDRAW_MIN.toFixed( 2 ) );
	}

	function toast( msg, type ) {
		if ( 'function' === typeof window.lrnToast ) {
			window.lrnToast( msg, type || 'info' );
		}
	}

	// Queued for the page we are about to reload into — see `lrnToast.flash()`.
	toast.flash = function ( msg, type ) {
		if ( 'function' === typeof window.lrnToast ) {
			window.lrnToast.flash( msg, type || 'info' );
		}
	};

	var __ = ( window.wp && window.wp.i18n && 'function' === typeof window.wp.i18n.__ )
		? window.wp.i18n.__
		: function ( s ) { return s; };

	function getFocusable( container ) {
		var nodes = container.querySelectorAll( FOCUSABLE_SELECTOR );
		return Array.prototype.slice.call( nodes ).filter( function ( n ) {
			return ! n.hasAttribute( 'disabled' ) && null !== n.offsetParent;
		} );
	}

	/**
	 * Open the withdrawal modal. Returns a Promise that resolves to the
	 * submitted { amount, method } object or null on cancel.
	 */
	function openWithdrawModal( available ) {
		return new Promise( function ( resolve ) {
			var modal = document.createElement( 'div' );
			modal.className = 'lrn-modal lrn-confirm-modal lrn-withdraw-modal';
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
			var titleId = 'lrn-withdraw-title-' + Date.now();
			titleEl.id = titleId;
			titleEl.textContent = __( 'Request a withdrawal', 'learnomy' );
			modal.setAttribute( 'aria-labelledby', titleId );
			head.appendChild( titleEl );
			dialog.appendChild( head );

			// Body.
			var body = document.createElement( 'div' );
			body.className = 'lrn-modal__body';

			var intro = document.createElement( 'p' );
			intro.className = 'lrn-confirm-modal__message';
			/* translators: %s: minimum withdrawal amount, e.g. $25 */
			intro.textContent = __( 'Funds are paid out per your site\'s payout schedule. Minimum withdrawal is %s.', 'learnomy' ).replace( '%s', withdrawMinLabel() );
			body.appendChild( intro );

			// Balance hint.
			var balance = document.createElement( 'p' );
			balance.className = 'lrn-withdraw-modal__balance';
			var balanceStrong = document.createElement( 'strong' );
			balanceStrong.textContent = '$' + Number( available ).toFixed( 2 );
			balance.appendChild( document.createTextNode( __( 'Available to withdraw: ', 'learnomy' ) ) );
			balance.appendChild( balanceStrong );
			body.appendChild( balance );

			// Amount field.
			var amountGroup = document.createElement( 'div' );
			amountGroup.className = 'lrn-withdraw-modal__field';
			var amountLabel = document.createElement( 'label' );
			amountLabel.className = 'lrn-prompt-modal__label';
			amountLabel.setAttribute( 'for', 'lrn-withdraw-amount' );
			amountLabel.textContent = __( 'Amount (USD)', 'learnomy' );
			amountGroup.appendChild( amountLabel );

			var amountInput = document.createElement( 'input' );
			amountInput.type = 'number';
			amountInput.id = 'lrn-withdraw-amount';
			amountInput.className = 'lrn-prompt-modal__input';
			amountInput.min = String( WITHDRAW_MIN );
			amountInput.step = '0.01';
			amountInput.max = String( available );
			amountInput.placeholder = '100.00';
			amountInput.required = true;
			amountGroup.appendChild( amountInput );

			// Settlement disclosure. Earnings pay out as WHOLE commission rows,
			// so an arbitrary figure resolves up to a row boundary — a $50
			// request can settle $91. The instructor is told that here, before
			// they submit, because rounding money silently is the same bug one
			// screen earlier. The sentence is composed server-side
			// (GET /earnings/withdraw/preview) so the quoted figure and the
			// recorded figure come from one arithmetic path.
			var settleNote = document.createElement( 'p' );
			settleNote.className = 'lrn-withdraw-modal__settle description';
			settleNote.setAttribute( 'aria-live', 'polite' );
			settleNote.hidden = true;
			amountGroup.appendChild( settleNote );

			body.appendChild( amountGroup );

			// Method field.
			var methodGroup = document.createElement( 'div' );
			methodGroup.className = 'lrn-withdraw-modal__field';
			var methodLabel = document.createElement( 'label' );
			methodLabel.className = 'lrn-prompt-modal__label';
			methodLabel.setAttribute( 'for', 'lrn-withdraw-method' );
			methodLabel.textContent = __( 'Payout method', 'learnomy' );
			methodGroup.appendChild( methodLabel );

			var methodSelect = document.createElement( 'select' );
			methodSelect.id = 'lrn-withdraw-method';
			methodSelect.className = 'lrn-prompt-modal__input';
			methodSelect.required = true;
			// Surface only the payout methods the site admin has enabled.
			// `learnomyInstructorDashboard.payoutMethods` is localized from
			// Template_Loader; absent keys default to enabled so a stale
			// page render before the localization deploys still works.
			// Wired in Wave 2C rows 2C-5 (paypal) / 2C-6 (bank).
			var enabled = ( window.learnomyInstructorDashboard
				&& window.learnomyInstructorDashboard.payoutMethods )
				|| {};
			function isEnabled( key ) {
				return ! Object.prototype.hasOwnProperty.call( enabled, key )
					|| !! enabled[ key ];
			}
			var methods = [
				{ value: '',              label: __( 'Choose a method…', 'learnomy' ),                       always: true },
				{ value: 'paypal',        label: __( 'PayPal', 'learnomy' ),                                 gate: 'paypal' },
				{ value: 'manual',        label: __( 'Other (arrange by email)', 'learnomy' ),               always: true },
				{ value: 'bank_transfer', label: __( 'Bank transfer', 'learnomy' ),                          gate: 'bank_transfer' },
				{ value: 'stripe',        label: __( 'Stripe', 'learnomy' ),                                 gate: 'stripe' },
			];
			methods.forEach( function ( m ) {
				if ( ! m.always && ! isEnabled( m.gate ) ) {
					return;
				}
				var opt = document.createElement( 'option' );
				opt.value = m.value;
				opt.textContent = m.label;
				methodSelect.appendChild( opt );
			} );
			methodGroup.appendChild( methodSelect );
			body.appendChild( methodGroup );

			// PayPal email — shown only when PayPal is the chosen method. This is
			// where the payout should land; the site owner needs it to pay you.
			// Prefilled from the address you used last time so you don't retype it.
			var emailGroup = document.createElement( 'div' );
			emailGroup.className = 'lrn-withdraw-modal__field';
			emailGroup.hidden = true;
			var emailLabel = document.createElement( 'label' );
			emailLabel.className = 'lrn-prompt-modal__label';
			emailLabel.setAttribute( 'for', 'lrn-withdraw-paypal-email' );
			emailLabel.textContent = __( 'PayPal email', 'learnomy' );
			emailGroup.appendChild( emailLabel );

			var emailInput = document.createElement( 'input' );
			emailInput.type = 'email';
			emailInput.id = 'lrn-withdraw-paypal-email';
			emailInput.className = 'lrn-prompt-modal__input';
			emailInput.placeholder = 'you@example.com';
			emailInput.value = ( window.learnomyInstructorDashboard
				&& window.learnomyInstructorDashboard.payoutEmail ) || '';
			emailGroup.appendChild( emailInput );
			body.appendChild( emailGroup );

			// A single hint line that explains what happens next for the chosen
			// method. Empty until a method is picked.
			var methodHint = document.createElement( 'p' );
			methodHint.className = 'lrn-withdraw-modal__hint description';
			methodHint.hidden = true;
			body.appendChild( methodHint );

			dialog.appendChild( body );

			// Foot.
			var foot = document.createElement( 'div' );
			foot.className = 'lrn-modal__foot';

			var cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'lrn-admin-btn lrn-btn lrn-btn--secondary lrn-confirm-modal__cancel';
			cancelBtn.textContent = __( 'Cancel', 'learnomy' );
			foot.appendChild( cancelBtn );

			var submitBtn = document.createElement( 'button' );
			submitBtn.type = 'submit';
			submitBtn.className = 'lrn-admin-btn lrn-admin-btn--primary lrn-btn lrn-btn--primary lrn-confirm-modal__confirm';
			submitBtn.textContent = __( 'Request withdrawal', 'learnomy' );
			submitBtn.disabled = true;
			foot.appendChild( submitBtn );

			dialog.appendChild( foot );

			// Wiring.
			var previousActive = document.activeElement;

			var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

			function syncMethodUI() {
				var method = methodSelect.value;
				emailGroup.hidden = 'paypal' !== method;
				if ( 'paypal' === method ) {
					methodHint.textContent = __( 'The payout is sent to this PayPal email.', 'learnomy' );
					methodHint.hidden = false;
				} else if ( 'manual' === method ) {
					methodHint.textContent = __( 'The site owner will email you to arrange payment. No account details are stored.', 'learnomy' );
					methodHint.hidden = false;
				} else {
					methodHint.hidden = true;
				}
			}

			// Settled total for the amount currently typed, as returned by the
			// preview endpoint. Sent back on submit so the server can refuse a
			// request placed against a figure that changed while the form was
			// open (a new sale landing mid-form moves the row boundary).
			var settledAmount = null;
			var previewToken = 0;
			var previewTimer = null;

			function renderPreview( amount ) {
				settledAmount = null;

				if ( isNaN( amount ) || amount < WITHDRAW_MIN || amount > available ) {
					settleNote.hidden = true;
					settleNote.textContent = '';
					updateValidity();
					return;
				}

				var token = ++previewToken;
				window.wp.apiFetch( {
					path: '/learnomy/v1/earnings/withdraw/preview?amount=' + encodeURIComponent( amount ),
				} ).then( function ( res ) {
					if ( token !== previewToken ) {
						return; // A newer keystroke already superseded this reply.
					}
					var data = ( res && res.data ) ? res.data : res;
					if ( ! data || ! data.can_request ) {
						settleNote.hidden = true;
						settledAmount = null;
					} else {
						settledAmount = data.settled;
						settleNote.textContent = data.notice || '';
						settleNote.hidden = ! data.notice;
						settleNote.classList.toggle( 'is-adjusted', ! data.exact );
					}
					updateValidity();
				} ).catch( function () {
					if ( token !== previewToken ) {
						return;
					}
					// No preview means no promise about the settled figure. Hide
					// the line rather than show a stale one; the request still
					// works, it just goes without the disclosure.
					settleNote.hidden = true;
					settledAmount = null;
					updateValidity();
				} );
			}

			function updateValidity() {
				var amount = parseFloat( amountInput.value );
				var amountOk = ! isNaN( amount ) && amount >= WITHDRAW_MIN && amount <= available;
				var method = methodSelect.value;
				// PayPal needs a valid payee email; every other method needs none.
				var methodOk = '' !== method
					&& ( 'paypal' !== method || EMAIL_RE.test( emailInput.value.trim() ) );
				submitBtn.disabled = ! ( amountOk && methodOk );
			}

			amountInput.addEventListener( 'input', function () {
				updateValidity();
				window.clearTimeout( previewTimer );
				previewTimer = window.setTimeout( function () {
					renderPreview( parseFloat( amountInput.value ) );
				}, 350 );
			} );
			methodSelect.addEventListener( 'change', function () {
				syncMethodUI();
				updateValidity();
			} );
			emailInput.addEventListener( 'input', updateValidity );

			function close( result ) {
				document.removeEventListener( 'keydown', onKeydown, true );
				modal.classList.add( 'lrn-modal--closing' );
				setTimeout( function () {
					if ( modal.parentNode ) {
						modal.parentNode.removeChild( modal );
					}
					if ( previousActive && 'function' === typeof previousActive.focus ) {
						previousActive.focus();
					}
					resolve( result );
				}, 120 );
			}

			function submit() {
				if ( submitBtn.disabled ) {
					return;
				}
				close( {
					amount: parseFloat( amountInput.value ),
					method: methodSelect.value,
					payoutEmail: 'paypal' === methodSelect.value ? emailInput.value.trim() : '',
					settleAmount: settledAmount,
				} );
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

			submitBtn.addEventListener( 'click', submit );
			cancelBtn.addEventListener( 'click', cancel );
			backdrop.addEventListener( 'click', cancel );

			document.body.appendChild( modal );
			document.addEventListener( 'keydown', onKeydown, true );

			setTimeout( function () {
				amountInput.focus();
			}, 30 );
		} );
	}

	document.addEventListener( 'click', async function ( e ) {
		var btn = e.target.closest( '[data-action="request-withdrawal"]' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();

		var available = parseFloat( btn.getAttribute( 'data-available' ) || '0' );

		if ( available < WITHDRAW_MIN ) {
			toast(
				__( 'You need at least %s available before requesting a withdrawal.', 'learnomy' ).replace( '%s', withdrawMinLabel() ),
				'warning'
			);
			return;
		}

		var payload = await openWithdrawModal( available );
		if ( null === payload ) {
			return;
		}

		btn.disabled = true;
		btn.classList.add( 'is-loading' );

		try {
			var data = {
				amount:       payload.amount,
				method:       payload.method,
				payout_email: payload.payoutEmail,
				currency:     'USD',
			};
			// Only sent when a preview succeeded. The server compares it against
			// the live row set and refuses (409) rather than settle a figure the
			// instructor never saw.
			if ( null !== payload.settleAmount && undefined !== payload.settleAmount ) {
				data.settle_amount = payload.settleAmount;
			}

			var res = await window.wp.apiFetch( {
				path:   '/learnomy/v1/earnings/withdraw',
				method: 'POST',
				data:   data,
			} );
			var created = ( res && res.data ) ? res.data : res;
			// Confirm the figure that was actually recorded, never the typed one.
			toast.flash(
				( created && created.notice )
					? created.notice
					: __( 'Withdrawal request submitted. You\'ll hear back within 3 business days.', 'learnomy' ),
				'success'
			);
			setTimeout( function () { window.location.reload(); }, 1500 );
		} catch ( err ) {
			var msg = __( 'Could not submit withdrawal. Please try again.', 'learnomy' );
			if ( err && err.message ) {
				msg = err.message;
			}
			toast( msg, 'error' );
		} finally {
			btn.disabled = false;
			btn.classList.remove( 'is-loading' );
		}
	} );
} )();
