/**
 * Learnomy — membership pricing & manage page interactivity.
 *
 * Two stores share this module:
 *   • `learnomy/pricing` drives the monthly/yearly price toggle.
 *   • `learnomy/membership` owns the cancel / resume subscription actions
 *     triggered from the manage page via the `lrnConfirm` modal.
 *
 * @package Learnomy
 */

import { store, getContext } from '@wordpress/interactivity';

const pricing = store( 'learnomy/pricing', {
	state: {
		cycle: 'monthly',
		get isMonthly() { return pricing.state.cycle === 'monthly'; },
		get isYearly() { return pricing.state.cycle === 'yearly'; },

		/**
		 * The checkout link for the cycle currently on screen.
		 *
		 * The toggle used to swap only the DISPLAYED price; the Subscribe link
		 * kept whatever `billing=` the server rendered. So a visitor could switch
		 * to Yearly, read "$190 / year", click Subscribe, and be taken to a
		 * monthly checkout — the first link in the chain that ended with a yearly
		 * buyer billed the monthly amount (Basecamp 10143220483). The href is now
		 * derived from the same state as the price beside it.
		 *
		 * Only dual-price cards carry this context; single-price cards keep the
		 * server-rendered href and never reach these getters.
		 */
		get subscribeHref() {
			const ctx = getContext();
			if ( ! ctx ) { return ''; }
			return pricing.state.cycle === 'yearly'
				? ( ctx.hrefYearly || ctx.hrefMonthly || '' )
				: ( ctx.hrefMonthly || '' );
		},

		/** CTA label for the cycle currently on screen. */
		get subscribeLabel() {
			const ctx = getContext();
			if ( ! ctx ) { return ''; }
			return pricing.state.cycle === 'yearly'
				? ( ctx.ctaYearly || '' )
				: ( ctx.ctaMonthly || '' );
		},
	},
	actions: {
		setMonthly() { pricing.state.cycle = 'monthly'; },
		setYearly() { pricing.state.cycle = 'yearly'; },
		toggleBilling() {
			pricing.state.cycle = pricing.state.cycle === 'monthly' ? 'yearly' : 'monthly';
		},
	},
} );

// membership-view.js is an ES module and cannot use wp_set_script_translations,
// so its cancel/resume strings are seeded server-side into window.lrnMembership.i18n
// (see class-template-loader.php). Read from there, falling back to English.
const mI18n = ( window.lrnMembership && window.lrnMembership.i18n ) || {};
const mt = ( key, fallback ) => mI18n[ key ] || fallback;

const membership = store( 'learnomy/membership', {
	state: {
		busyAction: '',
		errorMessage: '',
		get isCancelBusy() {
			return membership.state.busyAction === 'cancel';
		},
		get isResumeBusy() {
			return membership.state.busyAction === 'resume';
		},
	},

	actions: {
		async cancelSubscription( event ) {
			if ( event && event.preventDefault ) { event.preventDefault(); }
			const ctx = getContext();
			const subId = parseInt( ctx.subscriptionId, 10 );
			if ( ! subId ) { return; }

			// Save-the-customer modal (PR-D): fetch reason list + any
			// retention offers the site/Pro has injected, show the modal,
			// resolve to { confirmed, reason, feedback_text, accepted_offer }.
			// If the buyer accepts a retention offer instead of confirming,
			// `confirmed` is false but `accepted_offer` carries the slug —
			// the listener for `learnomy_cancel_offer_accepted` (Pro / site
			// owner) handles the actual offer side-effect.
			const cfg = window.lrnCancelConfig || {};
			let outcome = { confirmed: false };

			if ( typeof window.lrnCancelModal === 'function' ) {
				outcome = await window.lrnCancelModal( {
					subscriptionId: subId,
					restUrl:        cfg.restUrl,
					nonce:          cfg.nonce,
				} );
			} else {
				// Fallback to the previous confirm-only path if the cancel
				// modal asset didn't load for some reason (graceful degrade
				// rather than block the cancel entirely).
				const ok = await window.lrnConfirm( {
					title:        mt( 'cancelTitle', 'Cancel subscription' ),
					message:      mt( 'cancelConfirm', 'You will keep access until the end of your current paid period. Continue?' ),
					confirmLabel: mt( 'cancelTitle', 'Cancel subscription' ),
					cancelLabel:  mt( 'keepSubscription', 'Keep subscription' ),
					destructive:  true,
				} );
				outcome = { confirmed: ok, reason: 'other', feedback_text: '', accepted_offer: '' };
			}

			if ( outcome.accepted_offer ) {
				// Buyer accepted a retention offer — let Pro / site owner
				// snippets react. The default Free build does nothing
				// further; no DB change yet. Listeners can subscribe via
				// document.addEventListener('learnomy:offer-accepted', ...).
				document.dispatchEvent( new CustomEvent( 'learnomy:offer-accepted', {
					detail: { subscriptionId: subId, offerSlug: outcome.accepted_offer },
				} ) );
				if ( window.lrnToast ) {
					window.lrnToast(
						mt( 'offerAccepted', 'Offer accepted. Your subscription was kept.' ),
						{ type: 'success' }
					);
				}
				return;
			}

			if ( ! outcome.confirmed ) { return; }

			// Persist the cancellation IMMEDIATELY, then offer Undo as a true
			// resume/uncancel. The previous pattern deferred the POST behind a
			// 10s timer, so a user who confirmed and navigated away within the
			// window was told "Cancellation scheduled" while the server never
			// heard about it — the subscription stayed active and kept billing.
			// The cancel is a grace cancel (access stays until period end), so
			// firing now is safe and Undo simply resumes.
			const UNDO_MS = 10000;

			membership.state.busyAction = 'cancel';

			try {
				await wp.apiFetch( {
					path:   '/learnomy/v1/memberships/me/cancel',
					method: 'POST',
					data:   {
						subscription_id: subId,
						reason:          outcome.reason || '',
						feedback_text:   outcome.feedback_text || '',
						accepted_offer:  '',
					},
				} );
			} catch ( err ) {
				membership.state.busyAction = '';
				const msg = err.message || mt( 'cancelFailed', 'Unable to cancel. Please try again.' );
				if ( window.lrnToast ) { window.lrnToast( msg, 'error' ); }
				return;
			}

			// Cancellation is committed. Reflect the final state on a short delay
			// so an Undo (resume) can intercept; reload either way so the page
			// shows the true server state.
			let undone     = false;
			const settle   = setTimeout( () => { if ( ! undone ) { window.location.reload(); } }, UNDO_MS );

			if ( window.lrnUndo ) {
				window.lrnUndo(
					mt( 'cancelled', 'Subscription cancelled. It stays active until the end of the period.' ),
					async () => {
						undone = true;
						clearTimeout( settle );
						try {
							await wp.apiFetch( {
								path:   '/learnomy/v1/memberships/me/resume',
								method: 'POST',
								data:   { subscription_id: subId },
							} );
							if ( window.lrnToast ) {
								window.lrnToast( mt( 'reverted', 'Cancellation reverted.' ), 'info' );
							}
						} catch ( err ) {
							const msg = err.message || mt( 'revertFailed', 'Unable to revert. Please refresh and try again.' );
							if ( window.lrnToast ) { window.lrnToast.flash( msg, 'error' ); }
						}
						window.location.reload();
					},
					UNDO_MS
				);
			} else {
				window.location.reload();
			}
		},

		async resumeSubscription( event ) {
			if ( event && event.preventDefault ) { event.preventDefault(); }
			const ctx = getContext();
			const subId = parseInt( ctx.subscriptionId, 10 );
			if ( ! subId ) { return; }

			membership.state.busyAction = 'resume';
			try {
				await wp.apiFetch( {
					path:   '/learnomy/v1/memberships/me/resume',
					method: 'POST',
					data:   { subscription_id: subId },
				} );
				window.location.reload();
			} catch ( err ) {
				membership.state.busyAction = '';
				const msg = err.message || mt( 'resumeFailed', 'Unable to resume. Please try again.' );
				if ( window.lrnToast ) { window.lrnToast( msg, 'error' ); }
			}
		},

		/**
		 * Fired by a subscribe/switch-plan link before browser navigation.
		 * Shows the redirect notice inside the card and briefly disables the
		 * button so double-clicks don't open two checkout tabs.
		 *
		 * @param {MouseEvent} event
		 */
		handleSubscribeClick( event ) {
			const btn = event && event.target ? event.target.closest( '[data-lrn-subscribe-btn]' ) : null;
			if ( ! btn ) { return; }

			btn.setAttribute( 'aria-disabled', 'true' );
			btn.classList.add( 'is-busy' );

			const notice = btn.closest( '.lrn-pricing-card__cta' )
				?.querySelector( '.lrn-pricing-card__redirect-notice' );
			if ( notice ) {
				notice.classList.add( 'is-visible' );
			}
		},
	},
} );

// Wire the subscribe redirect notice to every subscribe anchor on the page.
// The click listener is intentionally outside the IAPI store because the
// subscribe CTAs are plain <a> links that navigate the browser — they don't
// need a full IAPI action, just a brief DOM mutation before navigation.
document.addEventListener( 'click', ( event ) => {
	if ( event.target && event.target.closest( '[data-lrn-subscribe-btn]' ) ) {
		membership.actions.handleSubscribeClick( event );
	}
} );
