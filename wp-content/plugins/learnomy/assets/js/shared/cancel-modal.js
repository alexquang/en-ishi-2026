/**
 * Learnomy save-the-customer cancel modal.
 *
 * Two-stage flow:
 *
 *   1. GET /memberships/me/cancel-options?subscription_id=N to fetch the
 *      site's reason list + any retention offers the
 *      `learnomy_cancel_retention_offers` filter has injected (Pro or a
 *      site owner's snippet).
 *
 *   2. Render a single dialog with: retention-offer cards (if any),
 *      reason radio list, optional follow-up textarea, primary
 *      "Cancel anyway" button + secondary "Keep subscription" cancel.
 *
 * Resolves to { confirmed, reason, feedback_text, accepted_offer } when
 * the buyer confirms the cancel, or { confirmed: false } when they
 * dismiss. Caller hits POST /memberships/me/cancel with the resolved
 * fields.
 *
 * All DOM built via createElement + textContent — matches the security
 * posture of sibling confirm-modal.js / toast.js (no innerHTML, no XSS
 * surface even with translated strings).
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	var FOCUSABLE_SELECTOR = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function __( text, domain ) {
		if ( window.wp && window.wp.i18n && typeof window.wp.i18n.__ === 'function' ) {
			return window.wp.i18n.__( text, domain || 'learnomy' );
		}
		return text;
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) { node.className = className; }
		if ( undefined !== text && null !== text ) { node.textContent = String( text ); }
		return node;
	}

	function buildOfferCard( offer, onAccept ) {
		var card = el( 'div', 'lrn-cancel-modal__offer' );
		card.dataset.offerSlug = offer.slug;

		card.appendChild( el( 'div', 'lrn-cancel-modal__offer-label', offer.label ) );
		if ( offer.description ) {
			card.appendChild( el( 'p', 'lrn-cancel-modal__offer-desc', offer.description ) );
		}

		var cta = el( 'button', 'lrn-btn lrn-btn--primary lrn-btn--sm', offer.cta_label || __( 'Apply offer', 'learnomy' ) );
		cta.type = 'button';
		cta.addEventListener( 'click', function () { onAccept( offer ); } );
		card.appendChild( cta );

		return card;
	}

	function buildDialog( options, onResolve ) {
		var data = options.data || { reasons: [], offers: [] };

		var modal = el( 'div', 'lrn-cancel-modal lrn-modal lrn-modal--hidden' );
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );
		modal.setAttribute( 'aria-labelledby', 'lrn-cancel-modal-title' );

		var backdrop = el( 'div', 'lrn-modal__backdrop' );
		modal.appendChild( backdrop );

		var dialog = el( 'div', 'lrn-cancel-modal__dialog' );
		modal.appendChild( dialog );

		var title = el( 'h2', 'lrn-cancel-modal__title', __( 'Before you go', 'learnomy' ) );
		title.id = 'lrn-cancel-modal-title';
		dialog.appendChild( title );

		dialog.appendChild( el( 'p', 'lrn-cancel-modal__lede',
			__( "We're sorry to see you go. A quick note about why helps us improve. There may also be a better option below.", 'learnomy' )
		) );

		// Retention offers (optional).
		var resolveSlug = '';
		if ( Array.isArray( data.offers ) && data.offers.length ) {
			var offerWrap = el( 'div', 'lrn-cancel-modal__offers' );
			offerWrap.setAttribute( 'aria-label', __( 'Suggested alternatives', 'learnomy' ) );
			data.offers.forEach( function ( offer ) {
				offerWrap.appendChild( buildOfferCard( offer, function ( accepted ) {
					resolveSlug = accepted.slug;
					// Offer CTA accepts the offer — close the modal and report
					// the accepted slug back. No subscription change is made
					// here; the caller decides whether to apply the offer.
					cleanup();
					onResolve( { confirmed: false, reason: '', feedback_text: '', accepted_offer: resolveSlug } );
				} ) );
			} );
			dialog.appendChild( offerWrap );
		}

		// Reason radio list.
		var fieldset = el( 'fieldset', 'lrn-cancel-modal__reasons' );
		var legend   = el( 'legend', 'lrn-cancel-modal__reasons-legend', __( "What's the main reason?", 'learnomy' ) );
		fieldset.appendChild( legend );

		var reasonGroupName = 'lrn-cancel-reason';
		var reasons = Array.isArray( data.reasons ) ? data.reasons : [];
		var chosenReason = '';
		reasons.forEach( function ( reason, idx ) {
			var rowLabel = el( 'label', 'lrn-cancel-modal__reason' );
			var input    = document.createElement( 'input' );
			input.type   = 'radio';
			input.name   = reasonGroupName;
			input.value  = reason.slug;
			input.className = 'lrn-cancel-modal__reason-input';
			// No pre-selected reason — the first row was auto-checked, so the
			// modal always opened with "Too expensive" selected and submitted it
			// even when the member never chose it. Let them pick (reason is
			// optional feedback, not required to cancel).
			input.addEventListener( 'change', function () {
				if ( input.checked ) { chosenReason = reason.slug; }
			} );
			rowLabel.appendChild( input );
			rowLabel.appendChild( el( 'span', 'lrn-cancel-modal__reason-label', reason.label ) );
			fieldset.appendChild( rowLabel );
		} );
		dialog.appendChild( fieldset );

		// Optional follow-up.
		var feedbackWrap = el( 'div', 'lrn-cancel-modal__feedback' );
		feedbackWrap.appendChild( el( 'label', 'lrn-cancel-modal__feedback-label', __( 'Anything else? (optional)', 'learnomy' ) ) );
		var feedback = document.createElement( 'textarea' );
		feedback.className = 'lrn-cancel-modal__feedback-input';
		feedback.rows = 3;
		feedback.maxLength = 500;
		feedbackWrap.appendChild( feedback );
		dialog.appendChild( feedbackWrap );

		// Action buttons.
		var actions   = el( 'div', 'lrn-cancel-modal__actions' );
		var keepBtn   = el( 'button', 'lrn-btn lrn-btn--secondary', __( 'Keep subscription', 'learnomy' ) );
		keepBtn.type  = 'button';
		keepBtn.addEventListener( 'click', function () {
			cleanup();
			onResolve( { confirmed: false, reason: '', feedback_text: '', accepted_offer: '' } );
		} );
		var confirmBtn   = el( 'button', 'lrn-btn lrn-btn--danger', __( 'Cancel anyway', 'learnomy' ) );
		confirmBtn.type  = 'button';
		confirmBtn.addEventListener( 'click', function () {
			cleanup();
			onResolve( {
				confirmed:      true,
				reason:         chosenReason,
				feedback_text:  feedback.value || '',
				accepted_offer: ''
			} );
		} );
		actions.appendChild( keepBtn );
		actions.appendChild( confirmBtn );
		dialog.appendChild( actions );

		// Esc closes; first focusable gets focus on mount.
		function onKeydown( e ) {
			if ( 'Escape' === e.key ) {
				e.preventDefault();
				keepBtn.click();
			}
		}
		backdrop.addEventListener( 'click', function () { keepBtn.click(); } );
		document.addEventListener( 'keydown', onKeydown );

		function cleanup() {
			document.removeEventListener( 'keydown', onKeydown );
			if ( modal.parentNode ) { modal.parentNode.removeChild( modal ); }
		}

		document.body.appendChild( modal );
		modal.classList.remove( 'lrn-modal--hidden' );

		var firstFocusable = dialog.querySelector( FOCUSABLE_SELECTOR );
		if ( firstFocusable ) { firstFocusable.focus(); }
	}

	/**
	 * window.lrnCancelModal({ subscriptionId, restUrl, nonce })
	 *
	 * Fetches the cancel-options payload, renders the modal, resolves to the
	 * buyer's choice. Errors during the fetch surface via lrnToast and
	 * resolve to a `confirmed: false` result so the caller treats them as
	 * a dismissed dialog (the buyer never sees the error path explicitly).
	 */
	window.lrnCancelModal = function ( opts ) {
		opts = opts || {};
		var subId   = parseInt( opts.subscriptionId, 10 ) || 0;
		var restUrl = (opts.restUrl || '').replace( /\/$/, '' );
		var nonce   = opts.nonce || '';

		if ( ! subId || ! restUrl ) {
			return Promise.resolve( { confirmed: false } );
		}

		var url = restUrl + '/memberships/me/cancel-options?subscription_id=' + encodeURIComponent( subId );

		return fetch( url, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'Accept': 'application/json', 'X-WP-Nonce': nonce }
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				var data = ( j && j.data ) ? j.data : { reasons: [], offers: [] };
				return new Promise( function ( resolve ) {
					buildDialog( { data: data }, resolve );
				} );
			} )
			.catch( function () {
				if ( typeof window.lrnToast === 'function' ) {
					window.lrnToast( __( 'Could not load cancel options. Please try again.', 'learnomy' ), 'error' );
				}
				return { confirmed: false };
			} );
	};
} )();
