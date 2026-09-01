/**
 * Admin transactions page — per-row refund button handler.
 *
 * Wired against the table rendered by
 * `includes/admin/views/transactions.php`. The page localises
 * `lrnTransactionsAdmin` with { restUrl, nonce, i18n } that we read here.
 *
 * Uses `window.lrnConfirm()` and `window.lrnToast()` from the plugin's
 * modal toolkit (assets/js/shared/confirm-modal.js + toast.js) — never
 * native browser dialogs per ux-foundation rule F8.
 *
 * @package Learnomy
 */
( function () {
	'use strict';

	if ( typeof window.lrnTransactionsAdmin === 'undefined' ) {
		return;
	}

	var api   = window.lrnTransactionsAdmin.restUrl;
	var nonce = window.lrnTransactionsAdmin.nonce;
	var __    = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var i18n  = window.lrnTransactionsAdmin.i18n || {};

	function showError( message ) {
		if ( window.lrnToast ) {
			window.lrnToast( message, 'error' );
		} else if ( window.lrnAlert ) {
			window.lrnAlert( message );
		} else {
			// Last-resort fallback only — modal toolkit should always be
			// enqueued on this page (see Admin::enqueue_assets).
			console.error( '[Learnomy]', message ); // eslint-disable-line no-console
		}
	}

	document.querySelectorAll( '.lrn-txn-refund-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', async function () {
			var id       = btn.dataset.txnId;
			var amount   = btn.dataset.txnAmount;
			var currency = btn.dataset.txnCurrency;
			var customer = btn.dataset.txnCustomer || '';
			var item     = btn.dataset.txnItem || '';

			var template = i18n.confirm || 'Refund {currency} {amount} for "{item}" ({customer})?';
			var message  = template
				.replace( '{currency}', currency )
				.replace( '{amount}', amount )
				.replace( '{item}', item )
				.replace( '{customer}', customer );

			// Ask HOW MUCH, not just whether. The adapters and the refund service have
			// always taken an amount; the only refund control in the product was a
			// yes/no button, so a partial refund was impossible to issue.
			//
			// `amount` is what is STILL REFUNDABLE on this sale, not the original charge
			// (see data-txn-amount). On an order that was already partly refunded, the
			// dialog must not offer to hand back money that is already gone. It defaults
			// to the whole remainder, so the common case stays one click + Enter.
			var answer = false;
			if ( window.lrnConfirm ) {
				answer = await window.lrnConfirm( {
					title:        i18n.confirm_title || 'Refund transaction',
					message:      message,
					confirmLabel: i18n.confirm_label || 'Refund',
					destructive:  true,
					input: {
						label: i18n.amount_label || 'Refund amount',
						type:  'number',
						value: amount,
						min:   '0.01',
						max:   amount,
						step:  '0.01',
						help:  ( i18n.amount_help || 'Defaults to the full amount still refundable. Enter less to refund part of it now and the rest later.' ),
					},
				} );
			}
			if ( false === answer || '' === answer ) {
				return;
			}

			var refundAmount = parseFloat( answer );
			if ( ! ( refundAmount > 0 ) || refundAmount > parseFloat( amount ) ) {
				showError( i18n.amount_invalid || 'Enter an amount between 0 and the amount still refundable.' );
				return;
			}

			btn.disabled    = true;
			var origLabel   = btn.textContent;
			btn.textContent = i18n.processing || 'Processing…';

			try {
				var response = await fetch( api + id + '/refund', {
					method:      'POST',
					credentials: 'same-origin',
					headers:     {
						'X-WP-Nonce':   nonce,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( { amount: refundAmount } ),
				} );
				var body = await response.json();

				if ( response.ok ) {
					if ( window.lrnToast ) {
						window.lrnToast.flash( i18n.success || 'Refund issued.', 'success' );
					}
					setTimeout( function () { window.location.reload(); }, 600 );
				} else {
					/* translators: 1: error prefix, 2: HTTP status code */
					var errMsg =
						( body && ( body.message || ( body.error && body.error.message ) ) ) ||
						sprintf( __( '%1$s (HTTP %2$d).', 'learnomy' ), ( i18n.error_prefix || __( 'Refund failed', 'learnomy' ) ), response.status );
					showError( errMsg );
					btn.disabled    = false;
					btn.textContent = origLabel;
				}
			} catch ( err ) {
				showError( ( i18n.error_prefix || 'Refund failed' ) + ': ' + err.message );
				btn.disabled    = false;
				btn.textContent = origLabel;
			}
		} );
	} );
}() );
