/**
 * Learnomy checkout — vanilla JS controller for the /enroll/ page.
 *
 * Handles coupon validation, payment method toggling, Stripe Checkout Session
 * redirect, PayPal server-driven redirect, free enrollment, and WooCommerce
 * redirect.
 *
 * Stripe flow: POST /learnomy/v1/checkout with {gateway:'stripe', course_id,
 * plan_id, coupon_code} → receive redirect_url → window.location.href to
 * Stripe-hosted checkout. Webhook checkout.session.completed creates
 * lrn_transactions + lrn_enrollments. No Stripe.js / Elements client-side.
 *
 * PayPal flow: POST /learnomy/v1/checkout with {gateway:'paypal', course_id,
 * plan_id, coupon_code} → receive redirect_url (PayPal approval URL) →
 * window.location.href to PayPal-hosted checkout. Webhook
 * CHECKOUT.ORDER.APPROVED calls capture_order() and reads custom_id to
 * create lrn_transactions + lrn_enrollments. No PayPal JS SDK used.
 *
 * @package Learnomy
 */

( function () {
	'use strict';

	var config = window.learnomyCheckout || {};
	// Translated strings — supplied by wp_localize_script via build_checkout_data().
	var i18n = config.i18n || {};

	// NB-1 (2026-05-18): wp_localize_script() converts integer values to JSON
	// strings, so `config.planId` arrives as the string "0" for one-time paid
	// courses. Boolean("0") is truthy in JS, so every truthiness check on
	// `config.planId` fires even when there is no plan. That made the Stripe
	// button POST to /checkout/subscribe instead of /checkout, returning 404
	// "Membership plan not found." and dead-ending every paid course.
	//
	// Resolve the plan id once, as a real integer, and gate every downstream
	// check on `planId > 0`. Same for courseId — the same coercion bug would
	// silently flip endpoint selection if a future PHP change ever passed 0.
	var planId   = parseInt( config.planId, 10 );
	if ( isNaN( planId ) || planId < 0 ) {
		planId = 0;
	}
	var courseId = parseInt( config.courseId, 10 );
	if ( isNaN( courseId ) || courseId < 0 ) {
		courseId = 0;
	}
	var hasPlan   = planId > 0;
	var hasCourse = courseId > 0;

	// The billing cycle the buyer picked on the pricing page ('monthly' |
	// 'yearly' | ''). It must ride on EVERY subscribe POST: the server prices
	// the plan from this, and a request without it falls back to the plan's own
	// interval — which is how a yearly signup got billed monthly.
	var billingCycle = config.billingCycle || '';

	// Attach the subscription terms to a request body. One helper so a new
	// gateway button cannot forget the cycle the price depends on.
	function withPlanTerms( body ) {
		if ( hasCourse ) {
			body.course_id = courseId;
		}
		if ( hasPlan ) {
			body.plan_id = planId;
			if ( billingCycle ) {
				body.billing_cycle = billingCycle;
			}
		}
		// `state` is declared below and is still undefined during the
		// WooCommerce pass-through, which runs before it and carries no coupon
		// of its own (WC uses its own coupon field on its own checkout page).
		if ( state && state.couponValid && state.couponCode ) {
			body.coupon_code = state.couponCode;
		}

		// The buyer's billing details ride along with every purchase request, so
		// the server can store them on the member. Attached here rather than in
		// one gateway's handler because the point is that they are the MEMBER'S:
		// whichever rail takes the payment, they type this once and never again.
		var billing = collectBillingFields();
		if ( billing ) {
			body.billing = billing;
		}

		return body;
	}

	/**
	 * The billing fields as a flat map, or null when the block is not rendered
	 * (a free enrolment, or a gateway that collects billing on its own screen).
	 */
	function collectBillingFields() {
		var block = document.getElementById( 'lrn-billing-details' );
		if ( ! block ) {
			return null;
		}

		var out   = {};
		var found = false;
		block.querySelectorAll( 'input[name], select[name]' ).forEach( function ( field ) {
			var value = ( field.value || '' ).trim();
			if ( '' !== value ) {
				out[ field.name ] = value;
				found = true;
			}
		} );

		return found ? out : null;
	}

	/**
	 * Point the buyer at the billing fields they left blank.
	 *
	 * The template marks six fields `required`, but the billing block is not
	 * inside a <form>, so the browser never validates them and never will —
	 * constraint validation is a form API. That is why a paid order could be
	 * completed with every field empty (Basecamp 10167587258). The server now
	 * refuses that, and this is the half that tells the buyer WHICH field,
	 * rather than making them guess from a one-line error.
	 *
	 * Driven by the `required` attributes already in the markup, so the template
	 * stays the single declaration of what is required and this cannot drift
	 * from it the way a second hardcoded list would.
	 *
	 * @param {string[]} names Optional field names the server flagged.
	 * @return {boolean} True when something is missing; the caller should stop.
	 */
	function flagMissingBilling( names ) {
		var block = document.getElementById( 'lrn-billing-details' );
		if ( ! block ) {
			return false;
		}

		var missing = [];
		block.querySelectorAll( '[required]' ).forEach( function ( field ) {
			var empty = '' === ( field.value || '' ).trim();
			var named = ! names || ! names.length || names.indexOf( field.name ) !== -1;
			field.classList.toggle( 'lrn-field--invalid', empty && named );
			field.setAttribute( 'aria-invalid', empty && named ? 'true' : 'false' );
			if ( empty && named ) {
				missing.push( field );
			}
		} );

		if ( missing.length ) {
			missing[ 0 ].focus();
			missing[ 0 ].scrollIntoView( { block: 'center', behavior: 'smooth' } );
		}

		return missing.length > 0;
	}

	// Bail early when the checkout container is missing.
	var root = document.getElementById( 'lrn-checkout' );
	if ( ! root ) {
		return;
	}

	// -------------------------------------------------------------------------
	// WooCommerce redirect — skip all payment logic.
	// -------------------------------------------------------------------------

	var wcRedirectBtn = document.getElementById( 'lrn-wc-redirect-btn' );
	if ( wcRedirectBtn && config.woocommerceActive ) {
		var originalBtnText = wcRedirectBtn.textContent;

		var goToWooCommerceCheckout = function () {
			wcRedirectBtn.disabled = true;
			wcRedirectBtn.textContent = i18n.wcRedirecting || 'Redirecting to secure checkout…';

			// Route WooCommerce through the SAME unified checkout endpoints as
			// Stripe/PayPal so the WC checkout URL is returned and the buyer
			// actually pays. /checkout and /checkout/subscribe dispatch to
			// WooCommerce_Adapter::create_purchase_session, which builds the WC
			// cart line and returns wc_get_checkout_url().
			var endpoint = hasPlan
				? config.restUrl + 'checkout/subscribe'
				: config.restUrl + 'checkout';

			// WooCommerce redirect mode has no coupon field of its own (WC's own
			// coupon system is used on the WC checkout page) — withPlanTerms
			// adds only the ids and the billing cycle at this point.
			var body = withPlanTerms( { gateway: 'woocommerce' } );

			postJSON( endpoint, body )
				.then( function ( data ) {
					var url = ( data && data.data && data.data.redirect_url ) || ( data && data.redirect_url ) || config.woocommerceCartUrl || '/';
					// replace() instead of href: this is a pass-through
					// interstitial, so Back from the WC checkout should return to
					// the course page, not loop through here again.
					window.location.replace( url );
				} )
				.catch( function ( err ) {
					// A missing-billing refusal is the one failure the member can
					// fix themselves, and this page has no form to do it on.
					var billingHref = ( err && err.code === 'learnomy_billing_details_required' )
						? ( config.billingUrl || '' )
						: '';
					showError( err.message || i18n.wcRedirectFailed, billingHref, i18n.addBillingDetails );
					wcRedirectBtn.disabled = false;
					wcRedirectBtn.textContent = originalBtnText;
				} );
		};

		wcRedirectBtn.addEventListener( 'click', goToWooCommerceCheckout );

		// WooCommerce IS the gateway, so this page is just a pass-through to its
		// checkout — go there straight away instead of making the buyer click
		// "Proceed to Payment". The button remains as a fallback if the
		// auto-redirect ever fails.
		goToWooCommerceCheckout();
		return;
	}

	// -------------------------------------------------------------------------
	// State
	// -------------------------------------------------------------------------

	var state = {
		couponCode: '',
		couponDiscount: 0,
		couponValid: false,
		selectedMethod: 'stripe',
		processing: false,
	};

	// Effective base price: sale price or regular price.
	var basePrice = ( config.salePrice > 0 ) ? config.salePrice : config.price;

	// -------------------------------------------------------------------------
	// Free enrollment
	// -------------------------------------------------------------------------

	var freeBtn = document.getElementById( 'lrn-enroll-free' );
	if ( freeBtn && config.isFreeCourse ) {
		var freeBtnLabel = freeBtn.textContent;
		freeBtn.addEventListener( 'click', function () {
			if ( state.processing ) {
				return;
			}
			state.processing = true;
			freeBtn.disabled = true;
			// Never blank the label: a failure used to leave an empty blue
			// button behind (the catch restored `disabled` but not the text).
			freeBtn.textContent = i18n.enrolling || freeBtnLabel;

			var endpoint = hasCourse
				? config.restUrl + 'courses/' + courseId + '/enroll'
				: config.restUrl + 'memberships/subscribe';

			var body = {};
			if ( hasPlan ) {
				body.plan_id = planId;
			}

			postJSON( endpoint, body )
				.then( function () {
					window.location.href = hasCourse
						? buildCourseUrl()
						: config.myCoursesUrl;
				} )
				.catch( function ( err ) {
					showError( err.message || i18n.enrollmentFailed );
					freeBtn.textContent = freeBtnLabel;
					freeBtn.disabled    = false;
					state.processing    = false;
				} );
		} );
		return;
	}

	// -------------------------------------------------------------------------
	// Coupon validation
	// -------------------------------------------------------------------------

	var couponInput   = document.getElementById( 'lrn-coupon-input' );
	var couponApply   = document.getElementById( 'lrn-apply-coupon' );
	var couponMessage = document.getElementById( 'lrn-coupon-message' );
	var couponLine    = document.getElementById( 'lrn-coupon-line' );
	var couponChip    = document.getElementById( 'lrn-coupon-chip' );
	var couponChipCode = document.getElementById( 'lrn-coupon-chip-code' );
	var couponRemove  = document.getElementById( 'lrn-remove-coupon' );
	var couponForm    = document.getElementById( 'lrn-coupon-form' );

	/**
	 * Drop the applied coupon and put the price back to full.
	 *
	 * There was no way to do this before (Basecamp 10134139671): once a code was
	 * applied it stayed in `state` for the life of the page, so a buyer who
	 * changed their mind — or applied the wrong code — had to reload. Worse, the
	 * discounted total stayed on screen while the Stripe session behind it was
	 * whatever the last successful apply built.
	 *
	 * Clearing state is not enough on its own: the mounted Stripe Payment Element
	 * is bound to a PaymentIntent for the OLD amount, so it is rebuilt here for
	 * the same reason applying a coupon rebuilds it.
	 */
	function clearCoupon( message ) {
		state.couponCode     = '';
		state.couponValid    = false;
		state.couponDiscount = 0;

		if ( couponInput ) {
			couponInput.value = '';
		}
		// Never let a removed code get auto-applied again on the next visit.
		try {
			sessionStorage.removeItem( 'lrn_pending_coupon' );
		} catch ( e ) {} // eslint-disable-line no-empty

		updatePriceDisplay();
		setCouponMessage( message || '', message ? 'success' : '' );

		if ( 'stripe' === state.selectedMethod && paymentElement ) {
			initStripePaymentElement( true );
		}
	}

	if ( couponRemove ) {
		couponRemove.addEventListener( 'click', function () {
			if ( state.processing ) {
				return;
			}
			clearCoupon( i18n.couponRemoved || 'Coupon removed.' );
			if ( couponInput ) {
				couponInput.focus();
			}
		} );
	}

	// Enter in the coupon field applies the code instead of submitting the
	// surrounding form (there is no form to submit — the page posts via fetch).
	if ( couponInput ) {
		couponInput.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				if ( couponApply ) {
					couponApply.click();
				}
			}
		} );
	}

	/** Swap the input row for the applied-code chip, or back again. */
	function syncCouponUI() {
		var applied = !! ( state.couponValid && state.couponCode );
		if ( couponChip ) {
			couponChip.hidden = ! applied;
		}
		if ( couponChipCode && applied ) {
			couponChipCode.textContent = state.couponCode;
		}
		if ( couponForm ) {
			couponForm.hidden = applied;
		}
	}

	if ( couponApply ) {
		// Auto-apply a coupon that was validated on the single-course page
		// (Basecamp #9927988828). Customer enters + applies a coupon on
		// /courses/<slug>/ → clicks Buy Now → arrives here; we read the
		// pending code from sessionStorage, pre-fill the input, and click
		// Apply on their behalf. Cleared after one consumption so it
		// doesn't bleed into the next visit.
		try {
			var pending = sessionStorage.getItem( 'lrn_pending_coupon' );
			if ( pending && couponInput ) {
				couponInput.value = pending;
				sessionStorage.removeItem( 'lrn_pending_coupon' );
				// Fire the click on next tick so the Apply listener
				// below is wired first.
				setTimeout( function () { couponApply.click(); }, 0 );
			}
		} catch ( e ) {}

		couponApply.addEventListener( 'click', function () {
			var code = ( couponInput.value || '' ).trim();
			if ( ! code ) {
				setCouponMessage( i18n.enterCouponCode || 'Please enter a coupon code.', 'error' );
				return;
			}

			couponApply.disabled = true;

			var body = { code: code };
			if ( hasCourse ) {
				body.course_id = courseId;
			}
			if ( hasPlan ) {
				body.plan_id = planId;
			}

			postJSON( config.restUrl + 'coupons/validate', body )
				.then( function ( data ) {
					couponApply.disabled = false;

					if ( data && data.data && data.data.valid ) {
						state.couponCode  = code;
						state.couponValid = true;

						// The REST endpoint returns the coupon object; we compute the
						// discount client-side against the base price.
						var coupon   = data.data.coupon;
						var discount = 0;

						if ( coupon && coupon.type === 'percent' ) {
							discount = basePrice * ( parseFloat( coupon.value ) / 100 );
						} else if ( coupon ) {
							discount = Math.min( parseFloat( coupon.value ), basePrice );
						}

						discount = Math.max( 0, Math.round( discount * 100 ) / 100 );
						state.couponDiscount = discount;

						updatePriceDisplay();
						setCouponMessage( i18n.couponApplied || 'Coupon applied.', 'success' );
					} else {
						state.couponValid    = false;
						state.couponDiscount = 0;
						updatePriceDisplay();
						setCouponMessage( ( data && data.data && data.data.reason ) || i18n.invalidCoupon || 'Invalid coupon.', 'error' );
					}

					// The amount changed — rebuild the embedded Stripe session so
					// the card form charges the new total (only when Stripe is the
					// selected method and a form is already mounted).
					if ( 'stripe' === state.selectedMethod && paymentElement ) {
						initStripePaymentElement( true );
					}
				} )
				.catch( function ( err ) {
					couponApply.disabled = false;
					state.couponValid    = false;
					state.couponDiscount = 0;
					// Repaint: without this the previously discounted total stayed
					// on screen after a failed re-validation, so the page showed a
					// price the server would never charge.
					updatePriceDisplay();
					setCouponMessage( err.message || i18n.couponValidateFailed || 'Could not validate coupon.', 'error' );
				} );
		} );
	}

	function setCouponMessage( text, type ) {
		if ( ! couponMessage ) {
			return;
		}
		couponMessage.textContent = text;
		couponMessage.className   = 'lrn-checkout__coupon-message';
		if ( type ) {
			couponMessage.classList.add( 'lrn-checkout__coupon-message--' + type );
		}
	}

	function updatePriceDisplay() {
		var totalEl   = document.getElementById( 'lrn-total-price' );
		var discountEl = document.getElementById( 'lrn-coupon-discount' );
		var codeEl    = document.getElementById( 'lrn-coupon-code-display' );

		var subtotal = Math.max( 0, basePrice - state.couponDiscount );

		// Uniform tax recompute on the discounted subtotal — unless the chosen
		// gateway taxes the sale itself. A store gateway (WooCommerce today)
		// receives our NET price and applies its own rates at its own checkout,
		// so showing ours here would quote a figure the buyer is never charged
		// and then hand them a different total one screen later.
		var delegated = ( config.taxDelegatedGateways || [] ).indexOf( state.selectedMethod ) !== -1;
		var taxRate   = ( config.taxEnabled && ! delegated ) ? ( parseFloat( config.taxRate ) || 0 ) : 0;
		var tax       = taxRate > 0 ? Math.round( subtotal * ( taxRate / 100 ) * 100 ) / 100 : 0;
		var total     = subtotal + tax;

		var taxAmountEl = document.getElementById( 'lrn-tax-amount' );
		if ( taxAmountEl && taxRate > 0 ) {
			taxAmountEl.textContent = formatCurrency( tax );
		}

		// Swap the quoted tax line for an honest note when the gateway owns it.
		var taxLineEl = document.getElementById( 'lrn-tax-line' );
		var taxNoteEl = document.getElementById( 'lrn-tax-deferred-note' );
		if ( taxLineEl && config.taxEnabled ) {
			taxLineEl.hidden = delegated;
		}
		if ( taxNoteEl ) {
			taxNoteEl.hidden = ! ( delegated && config.taxEnabled );
		}

		if ( totalEl ) {
			totalEl.textContent = formatCurrency( total );
		}

		// A coupon that zeroes the total retires the gateway UI: the server
		// completes a $0 order without contacting any provider, so showing card
		// fields (or, on a no-gateway site, nothing at all) is a dead end. Swap
		// in the free-order button; removing the coupon swaps everything back.
		syncFreeOrderUI( state.couponValid && subtotal <= 0 );

		if ( couponLine ) {
			if ( state.couponValid && state.couponDiscount > 0 ) {
				couponLine.hidden = false;
				if ( codeEl ) {
					codeEl.textContent = state.couponCode;
				}
				if ( discountEl ) {
					discountEl.textContent = '-' + formatCurrency( state.couponDiscount );
				}
			} else {
				couponLine.hidden = true;
			}
		}

		// The chip and the input row are two views of the same state — driving
		// them from here means no caller can update the total and forget one.
		syncCouponUI();
	}

	// Whether the coupon-to-zero swap is currently applied — transitions only,
	// so restoring cannot fight the method toggle on every repaint.
	var freeOrderActive = false;

	/**
	 * Swap the gateway UI for the free-order button when the effective total is
	 * $0, and back when it is not. Elements are queried per call because this
	 * runs from updatePriceDisplay(), which is declared before the gateway
	 * elements are looked up below.
	 *
	 * Restoration is inline (not via syncMethodUI) on purpose: syncMethodUI
	 * calls updatePriceDisplay, which calls back here — routing the restore
	 * through it would recurse.
	 */
	function syncFreeOrderUI( freeOrder ) {
		var freeOrderBtn = document.getElementById( 'lrn-submit-free-order' );
		if ( ! freeOrderBtn || freeOrder === freeOrderActive ) {
			return;
		}
		freeOrderActive = freeOrder;

		var fieldset = root.querySelector( '.lrn-checkout__methods-fieldset' );
		var stripeEl = document.getElementById( 'lrn-stripe-container' );
		var paypalEl = document.getElementById( 'lrn-paypal-container' );
		var testEl   = document.getElementById( 'lrn-test-container' );
		var payBtn   = document.getElementById( 'lrn-submit-payment' );

		freeOrderBtn.hidden = ! freeOrder;

		if ( freeOrder ) {
			[ fieldset, stripeEl, paypalEl, testEl, payBtn ].forEach( function ( el ) {
				if ( el ) {
					el.hidden = true;
				}
			} );
			return;
		}

		if ( fieldset ) {
			fieldset.hidden = false;
		}
		if ( payBtn ) {
			payBtn.hidden = false;
		}
		if ( stripeEl ) {
			stripeEl.hidden = ( state.selectedMethod !== 'stripe' );
		}
		if ( paypalEl ) {
			paypalEl.hidden = ( state.selectedMethod !== 'paypal' );
		}
		if ( testEl ) {
			testEl.hidden = ( state.selectedMethod !== 'test' );
		}
	}

	// -------------------------------------------------------------------------
	// Payment method toggle
	// -------------------------------------------------------------------------

	var methodRadios    = root.querySelectorAll( 'input[name="lrn_payment_method"]' );
	var stripeContainer = document.getElementById( 'lrn-stripe-container' );
	var paypalContainer = document.getElementById( 'lrn-paypal-container' );
	var testContainer   = document.getElementById( 'lrn-test-container' );
	var submitBtn       = document.getElementById( 'lrn-submit-payment' );

	function syncMethodUI( method ) {
		state.selectedMethod = method;

		// The total depends on the gateway now: one that taxes the sale itself
		// is quoted net. Recompute on every switch, or the buyer sees the
		// previous gateway's total against the new one's button.
		updatePriceDisplay();
		if ( stripeContainer ) {
			stripeContainer.hidden = ( method !== 'stripe' );
		}
		if ( paypalContainer ) {
			paypalContainer.hidden = ( method !== 'paypal' );
		}
		if ( testContainer ) {
			testContainer.hidden = ( method !== 'test' );
		}
		if ( submitBtn ) {
			// Our submit button drives Stripe (Payment Element has no button of
			// its own) and the Test gateway. PayPal renders its own button.
			submitBtn.hidden = ( method === 'paypal' );
		}
		// J-01.8 fix — the submit label is server-rendered for the initially-
		// checked gateway, but the selector lets the buyer switch without
		// reloading. The label has to follow or it lies about what the click
		// does (e.g. shows "Complete Test Purchase" while routing to Stripe).
		var submitText = document.getElementById( 'lrn-submit-text' );
		if ( submitText ) {
			var selectedRadio = root.querySelector(
				'input[name="lrn_payment_method"][value="' + method + '"]'
			);
			var label = selectedRadio && selectedRadio.getAttribute( 'data-submit-label' );
			if ( label ) {
				submitText.textContent = label;
			}
		}

		if ( 'stripe' === method ) {
			// Mount the themed card fields immediately — no extra click to reveal.
			initStripePaymentElement();
		} else {
			teardownStripePaymentElement();
		}
	}

	methodRadios.forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			syncMethodUI( radio.value );
		} );
	} );

	// Set initial state from the checked radio.
	var checkedRadio = root.querySelector( 'input[name="lrn_payment_method"]:checked' );
	if ( checkedRadio ) {
		syncMethodUI( checkedRadio.value );
	}

	// Re-theme the card fields live when the site dark-mode toggles. Stripe's
	// appearance is fixed at element creation, so we watch the .lrn-dark flag
	// (it lands on .lrn-app or body) and rebuild the element in the new scheme.
	( function watchDarkMode() {
		if ( typeof MutationObserver === 'undefined' ) {
			return;
		}
		var wasDark = !! document.querySelector( '.lrn-dark' );
		var observer = new MutationObserver( function () {
			var isDark = !! document.querySelector( '.lrn-dark' );
			if ( isDark === wasDark ) {
				return;
			}
			wasDark = isDark;
			if ( 'stripe' === state.selectedMethod && paymentElement ) {
				initStripePaymentElement( true ); // Rebuild in the new theme.
			}
		} );
		observer.observe( document.body, { attributes: true, attributeFilter: [ 'class' ] } );
		var app = document.querySelector( '.lrn-app' );
		if ( app ) {
			observer.observe( app, { attributes: true, attributeFilter: [ 'class' ] } );
		}
	} )();

	// -------------------------------------------------------------------------
	// Stripe form submission
	// -------------------------------------------------------------------------

	if ( submitBtn ) {
		submitBtn.addEventListener( 'click', function () {
			if ( state.processing ) {
				return;
			}
			// Check before dispatching, not only after the server refuses, so the
			// buyer gets the answer without a round trip. The server check is the
			// authority; this is the courtesy.
			if ( flagMissingBilling() ) {
				showError( i18n.billingRequired || 'Add your billing details to continue.' );
				return;
			}
			if ( state.selectedMethod === 'test' ) {
				handleTestPayment();
			} else if ( state.selectedMethod === 'stripe' ) {
				confirmStripePayment();
			}
		} );
	}

	// Free-order submit — the coupon-to-zero path. Posts to /checkout with NO
	// gateway: the server's zero-total branch runs before its gateway check and
	// completes the order without a provider, so this works on a site with no
	// gateway configured at all (Basecamp 10172705555).
	var freeOrderBtn = document.getElementById( 'lrn-submit-free-order' );
	if ( freeOrderBtn ) {
		freeOrderBtn.addEventListener( 'click', function () {
			if ( state.processing ) {
				return;
			}
			if ( flagMissingBilling() ) {
				showError( i18n.billingRequired || 'Add your billing details to continue.' );
				return;
			}
			setProcessing( true );

			postJSON( config.restUrl + 'checkout', withPlanTerms( {} ) )
				.then( function ( data ) {
					if ( data && data.data && data.data.redirect_url ) {
						window.location.href = data.data.redirect_url;
					} else {
						onPaymentSuccess();
					}
				} )
				.catch( function ( err ) {
					showError( err.message || i18n.enrollmentFailed );
					setProcessing( false );
				} );
		} );
	}

	function handleTestPayment() {
		setProcessing( true );

		var endpoint = config.restUrl + 'checkout/test';

		var body = withPlanTerms( { payment_method: 'test' } );

		postJSON( endpoint, body )
			.then( function ( data ) {
				if ( data && data.data && data.data.redirect_url ) {
					window.location.href = data.data.redirect_url;
				} else {
					onPaymentSuccess();
				}
			} )
			.catch( function ( err ) {
				showError( err.message || i18n.testPaymentFailed );
				setProcessing( false );
			} );
	}

	// Stripe Payment Element state. The card fields render on THIS page, themed
	// to the site (light/dark) via the appearance API, and our own submit button
	// confirms the payment. `stripeBusy` guards overlapping inits.
	var stripeInstance  = null;
	var stripeElements  = null;
	var paymentElement  = null;
	var stripeBusy      = false;
	// True when the mounted secret is a SetupIntent (seti_) — a trialing
	// subscription's card-on-file collection rather than an immediate charge.
	var stripeSecretIsSetup = false;

	// Build the secure card fields on the page. Called when Stripe is selected
	// (on load + on method switch) and re-called after a coupon change (force).
	//
	// Subscription (plan_id present): POST to /checkout/subscribe instead of
	// /checkout. Both return a PaymentIntent client_secret.
	//
	// NB-1: this is the endpoint selector that caused the 2026-05-18 walk
	// regression. `hasPlan` is the integer-coerced gate; do NOT replace with
	// `config.planId` truthiness — string "0" is truthy and routes every
	// one-time paid course to the subscription endpoint → 404.
	function initStripePaymentElement( force ) {
		if ( stripeBusy ) {
			return;
		}
		if ( paymentElement && ! force ) {
			return; // Already showing the card fields.
		}
		if ( ! window.Stripe || ! config.stripeKey ) {
			showError( i18n.stripeNotInit || 'Payment processing is not available. Please refresh the page.' );
			return;
		}

		stripeBusy = true;
		setStripeError( '' );

		var endpoint = hasPlan
			? config.restUrl + 'checkout/subscribe'
			: config.restUrl + 'checkout';

		var body = withPlanTerms( { gateway: 'stripe' } );

		postJSON( endpoint, body )
			.then( function ( data ) {
				var payload = ( data && data.data ) || {};
				if ( payload.client_secret ) {
					mountPaymentElement( payload.client_secret );
				} else if ( payload.redirect_url ) {
					// Fallback for any gateway that still returns a hosted URL.
					window.location.href = payload.redirect_url;
				} else {
					showError( i18n.paymentFailed || 'Could not load the secure card form. Please try again.' );
				}
			} )
			.catch( function ( err ) {
				showError( err.message || i18n.paymentFailed || 'Could not load the secure card form. Please try again.' );
			} )
			.then( function () {
				stripeBusy = false;
			} );
	}

	// Map the site's --lrn-* tokens (and dark mode) onto Stripe's appearance API
	// so the card fields match the surrounding checkout in both schemes.
	function buildStripeAppearance() {
		// Dark mode lands on .lrn-app (or body/html, depending on surface), so
		// look for the flag anywhere before theming the card fields. We pick
		// Stripe's matching base theme ('night' / 'stripe') and only override
		// the brand accent + radius — letting the theme own background / text /
		// borders keeps the whole form cohesive (overriding bg/text with the
		// site tokens risked a light input on a dark theme when a token read
		// resolved to the wrong scheme).
		var isDark = !! document.querySelector( '.lrn-dark' ) ||
			document.body.classList.contains( 'lrn-dark' ) ||
			document.documentElement.classList.contains( 'lrn-dark' );
		var scopeEl = document.querySelector( '.lrn-app' ) || document.body;
		var accent  = getComputedStyle( scopeEl ).getPropertyValue( '--lrn-accent' );
		accent = ( accent && accent.trim() ) ? accent.trim() : '#2383e2';

		return {
			theme: isDark ? 'night' : 'stripe',
			variables: {
				colorPrimary: accent,
				borderRadius: '8px'
			}
		};
	}

	function setStripeError( message ) {
		var el = document.getElementById( 'lrn-stripe-error' );
		if ( ! el ) {
			return;
		}
		el.textContent = message || '';
		el.hidden = ! message;
	}

	function teardownStripePaymentElement() {
		if ( paymentElement ) {
			try {
				paymentElement.unmount();
			} catch ( e ) {} // eslint-disable-line no-empty
		}
		paymentElement = null;
		stripeElements = null;
		setStripeError( '' );
	}

	function mountPaymentElement( clientSecret ) {
		var mountEl = document.getElementById( 'lrn-stripe-payment-element' );
		if ( ! window.Stripe || ! config.stripeKey || ! mountEl ) {
			showError( i18n.stripeNotInit || 'Payment processing is not available. Please refresh the page.' );
			return;
		}

		teardownStripePaymentElement();

		// A trialing plan's first invoice is $0, so the server hands back a
		// SetupIntent secret (seti_) instead of a PaymentIntent one — the card
		// is saved now and billed when the trial ends. Remembered here so
		// confirmStripePayment() calls the matching confirm method
		// (Basecamp 10172704327).
		stripeSecretIsSetup = clientSecret.indexOf( 'seti_' ) === 0;

		stripeInstance = stripeInstance || window.Stripe( config.stripeKey );
		stripeElements = stripeInstance.elements( {
			clientSecret: clientSecret,
			appearance: buildStripeAppearance()
		} );

		// No Address Element. The address is the MEMBER'S — collected in our own
		// fields in the summary column, stored on their account, and handed to
		// Stripe at confirm time via billing_details. Stripe still receives it
		// (an Indian account rejects export charges without a name and address);
		// it simply no longer owns it, so PayPal and WooCommerce buyers supply
		// one too and a returning member never retypes it.
		// Opt out of NAME and ADDRESS only, not of billing details wholesale.
		// `billingDetails: 'never'` makes Stripe require every field back on
		// confirm — including `phone`, which we do not collect — and it rejects
		// the call with an integration error rather than a payment error, so the
		// buyer sees a dead button and no message. The granular form asks for
		// exactly what we supply and leaves email/phone on Stripe's defaults.
		paymentElement = stripeElements.create( 'payment', {
			fields: { billingDetails: { name: 'never', address: 'never' } },
			defaultValues: { billingDetails: { email: config.buyerEmail || '' } }
		} );
		paymentElement.mount( '#lrn-stripe-payment-element' );
	}

	// Confirm the payment with the card the buyer entered. On success Stripe
	// redirects to returnUrl; the webhook (payment_intent.succeeded / first
	// invoice) provisions enrollment server-side.
	/**
	 * The billing details the buyer entered, shaped for Stripe.
	 *
	 * `fields.billingDetails: 'never'` tells the Payment Element we are supplying
	 * these, so they must be present on confirm — Stripe rejects the call
	 * otherwise. Reading straight from the DOM keeps one source: whatever the
	 * buyer sees is exactly what is sent and what we later store.
	 */
	function readBillingDetails() {
		var val = function ( id ) {
			var el = document.getElementById( id );
			return el ? el.value.trim() : '';
		};

		var name = ( val( 'lrn-billing-first-name' ) + ' ' + val( 'lrn-billing-last-name' ) ).trim();

		// Exactly the fields we opted out of above — name and address. Sending
		// more than was opted out of is rejected just as firmly as sending less.
		return {
			name: name || config.buyerName || '',
			address: {
				line1: val( 'lrn-billing-address-1' ),
				line2: val( 'lrn-billing-address-2' ),
				city: val( 'lrn-billing-city' ),
				state: val( 'lrn-billing-state' ),
				postal_code: val( 'lrn-billing-postcode' ),
				country: val( 'lrn-billing-country' ) || config.buyerCountry || 'US'
			}
		};
	}

	function confirmStripePayment() {
		if ( ! stripeInstance || ! stripeElements || ! paymentElement ) {
			// Card fields not ready yet — (re)build them, buyer can retry.
			initStripePaymentElement( true );
			return;
		}

		setProcessing( true );
		setStripeError( '' );

		// try/catch as well as .catch(): a Stripe INTEGRATION error (bad
		// confirmParams, a missing opted-out field) is thrown synchronously
		// rather than rejecting the promise, so the promise handlers never run.
		// That left the button disabled with its label hidden and no message —
		// a dead control the buyer could only escape by reloading, and no
		// indication anything had gone wrong.
		var confirmation;
		try {
			// confirmSetup for a trial's card-on-file secret (seti_),
			// confirmPayment for a normal charge — same params either way.
			confirmation = stripeInstance[ stripeSecretIsSetup ? 'confirmSetup' : 'confirmPayment' ]( {
				elements: stripeElements,
				confirmParams: {
					return_url: config.returnUrl || config.myCoursesUrl || window.location.href,
					payment_method_data: {
						billing_details: readBillingDetails()
					}
				}
			} );
		} catch ( err ) {
			setStripeError( ( err && err.message ) || i18n.paymentFailed || 'Payment failed. Please try again.' );
			setProcessing( false );
			return;
		}

		confirmation
			.then( function ( result ) {
				// Only reached when confirmation did NOT redirect (an immediate
				// error). On success Stripe navigates to return_url.
				if ( result && result.error ) {
					setStripeError( result.error.message || i18n.paymentFailed || 'Payment failed. Please check your card details and try again.' );
					setProcessing( false );
				}
			} )
			.catch( function ( err ) {
				setStripeError( ( err && err.message ) || i18n.paymentFailed || 'Payment failed. Please try again.' );
				setProcessing( false );
			} );
	}

	// -------------------------------------------------------------------------
	// PayPal server-driven flow
	//
	// H4 FIX: The former Smart-Buttons approach called actions.order.create()
	// client-side, which meant the PayPal order was built without custom_id.
	// When CHECKOUT.ORDER.APPROVED fired, parse_custom_id('') returned
	// user_id=0 and handle_order_approved() bailed before creating any
	// enrollment. No purchase, no enrollment — a silent null path.
	//
	// Fix: replace the Smart-Buttons block with a plain "Pay with PayPal"
	// button that POSTs to /wp-json/learnomy/v1/checkout with gateway:'paypal'.
	// PayPal_Adapter::create_checkout() builds the order with the correct
	// custom_id and returns the approval URL. We redirect there; PayPal's
	// hosted checkout handles the rest. After approval, the existing
	// CHECKOUT.ORDER.APPROVED webhook calls capture_order() then reads
	// custom_id to create lrn_transactions + lrn_enrollments.
	// -------------------------------------------------------------------------

	if ( config.paypalEnabled ) {
		var paypalButtonsEl = document.getElementById( 'lrn-paypal-buttons' );

		if ( paypalButtonsEl ) {
			// Render a standard Pay button inside the container that the
			// template already provides with the correct aria-label.
			var paypalPayBtn = document.createElement( 'button' );
			paypalPayBtn.type = 'button';
			paypalPayBtn.className = 'lrn-btn lrn-btn--paypal lrn-btn--lg lrn-btn--full';
			paypalPayBtn.id = 'lrn-paypal-pay-btn';
			paypalPayBtn.textContent = i18n.payWithPayPal || 'Pay with PayPal';
			paypalButtonsEl.appendChild( paypalPayBtn );

			paypalPayBtn.addEventListener( 'click', function () {
				if ( state.processing ) {
					return;
				}

				setProcessing( true );

				var body = withPlanTerms( { gateway: 'paypal' } );

				// POST to the unified checkout endpoint. The server builds the
				// PayPal order with custom_id attached, then returns the
				// PayPal approval URL for us to redirect to. Memberships route
				// to /checkout/subscribe so PayPal_Adapter::create_subscription
				// builds a recurring billing agreement — the one-time /checkout
				// path charged once and created no subscription or access.
				postJSON( config.restUrl + ( hasPlan ? 'checkout/subscribe' : 'checkout' ), body )
					.then( function ( response ) {
						if ( response && response.data && response.data.redirect_url ) {
							window.location.href = response.data.redirect_url;
						} else {
							showError( i18n.paypalError || 'PayPal did not return a checkout URL. Please try again.' );
							setProcessing( false );
						}
					} )
					.catch( function ( err ) {
						showError( err.message || i18n.paypalError || 'PayPal encountered an error. Please try again.' );
						setProcessing( false );
					} );
			} );
		}
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function postJSON( url, body ) {
		return fetch( url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			credentials: 'same-origin',
			body: JSON.stringify( body ),
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok ) {
					var msg = ( json && json.error && json.error.message )
						|| ( json && json.message )
						|| i18n.requestFailed
					|| 'Request failed.';
					var failure = new Error( msg );
					// Carry the machine-readable code and any field list through
					// to the caller. Throwing a bare Error discarded both, so a
					// handler could restate what went wrong but never react to
					// WHICH thing went wrong — no "here is where you fix it"
					// link is possible from a message string alone.
					failure.code   = ( json && json.error && json.error.code ) || ( json && json.code ) || '';
					failure.fields = ( json && json.error && json.error.details && json.error.details.fields ) || [];
					throw failure;
				}
				return json;
			} );
		} );
	}

	/**
	 * Show a checkout error.
	 *
	 * @param {string} message  What went wrong.
	 * @param {string} [href]   Optional destination that fixes it. Naming a
	 *                          problem the member cannot act on from the page
	 *                          they are stuck on is only half an error message,
	 *                          and the WooCommerce hand-off carries no billing
	 *                          form of its own.
	 * @param {string} [label]  Link text.
	 */
	function showError( message, href, label ) {
		var errorEl = document.getElementById( 'lrn-checkout-error' );
		if ( ! errorEl ) {
			return;
		}
		errorEl.textContent = message;
		if ( href ) {
			errorEl.appendChild( document.createTextNode( ' ' ) );
			var link = document.createElement( 'a' );
			link.href = href;
			link.textContent = label || href;
			link.className = 'lrn-checkout__message-action';
			errorEl.appendChild( link );
		}
		errorEl.hidden = false;
		errorEl.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function hideError() {
		var errorEl = document.getElementById( 'lrn-checkout-error' );
		if ( errorEl ) {
			errorEl.hidden = true;
			errorEl.textContent = '';
		}
	}

	function setProcessing( active ) {
		state.processing = active;
		var overlay = document.getElementById( 'lrn-checkout-overlay' );
		var text    = document.getElementById( 'lrn-submit-text' );
		var spinner = document.getElementById( 'lrn-submit-spinner' );

		if ( overlay ) {
			overlay.hidden = ! active;
		}

		if ( submitBtn ) {
			submitBtn.disabled = active;
		}
		if ( text ) {
			text.hidden = active;
		}
		if ( spinner ) {
			spinner.hidden = ! active;
		}

		if ( active ) {
			hideError();
		}
	}

	function onPaymentSuccess() {
		if ( hasCourse ) {
			window.location.href = buildCourseUrl();
		} else {
			window.location.href = config.myCoursesUrl || '/my-courses/';
		}
	}

	function buildCourseUrl() {
		// We don't have the slug client-side, so redirect to my-courses which
		// handles the most recently enrolled course display.
		return config.myCoursesUrl || '/my-courses/';
	}

	function formatCurrency( amount ) {
		var symbols = {
			USD: '$', EUR: '\u20ac', GBP: '\u00a3',
			INR: '\u20b9', AUD: 'A$', CAD: 'C$',
		};
		var symbol = symbols[ config.currency ] || ( config.currency + ' ' );
		return symbol + amount.toFixed( 2 );
	}

} )();
