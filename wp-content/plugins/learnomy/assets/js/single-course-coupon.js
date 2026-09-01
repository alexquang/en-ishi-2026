/**
 * Single-course sidebar coupon applier.
 *
 * Wired against the markup in `templates/single-course.php`. Inline
 * extraction per ux-foundation rule F2 (no inline <script> in PHP).
 *
 * @package Learnomy
 */
( function () {
	'use strict';
	var i18n = ( window.lrnSingleCourseCoupon || {} ).i18n || {};

							(function(){
								var btn   = document.getElementById('lrn-sidebar-apply-coupon');
								var input = document.getElementById('lrn-coupon-input');
								var msg   = document.getElementById('lrn-sidebar-coupon-message');
								if (!btn || !input) return;

								btn.addEventListener('click', function(){
									var code = (input.value || '').trim();
									if (!code) {
										showMsg(i18n.empty, 'error');
										return;
									}
									btn.disabled = true;

									var body = { code: code, course_id: parseInt(btn.dataset.courseId, 10) };

									fetch(btn.dataset.restUrl + 'coupons/validate', {
										method: 'POST',
										headers: {
											'Content-Type': 'application/json',
											'X-WP-Nonce': btn.dataset.nonce
										},
										credentials: 'same-origin',
										body: JSON.stringify(body)
									})
									.then(function(r){ return r.json(); })
									.then(function(json){
										btn.disabled = false;
										if (json && json.data && json.data.valid) {
											var coupon   = json.data.coupon;
											var cur      = btn.dataset.currency || '$';
											var base     = parseFloat(btn.dataset.basePrice || '0');
											var discount = '';
											var newPrice = base;
											var offLabel = i18n.off || 'off';
											if (coupon.type === 'percent') {
												discount = parseFloat(coupon.value) + '% ' + offLabel;
												newPrice = base * (1 - parseFloat(coupon.value) / 100);
											} else {
												discount = cur + parseFloat(coupon.value).toFixed(2) + ' ' + offLabel;
												newPrice = base - parseFloat(coupon.value);
											}
											if (newPrice < 0) { newPrice = 0; }
											// Persist the validated code so the checkout page
											// auto-applies it instead of asking the customer
											// to re-enter (Basecamp #9927988828). Cleared by
											// checkout.js after it consumes the value.
											try {
												sessionStorage.setItem('lrn_pending_coupon', code);
											} catch (e) {}
											showMsg(i18n.applied + ' ' + discount, 'success');
											// Reprice the sidebar so the customer sees the new
											// total, not the full price (card 10062796589).
											var priceEl = document.getElementById('lrn-sidebar-coupon-price');
											if (priceEl && base > 0) {
												priceEl.textContent = '';
												var strong = document.createElement('strong');
												strong.textContent = (i18n.youPay || 'You pay') + ' ' + cur + newPrice.toFixed(2);
												var was = document.createElement('s');
												was.className = 'lrn-sidebar-card__coupon-was';
												was.textContent = cur + base.toFixed(2);
												priceEl.appendChild(strong);
												priceEl.appendChild(document.createTextNode(' '));
												priceEl.appendChild(was);
												priceEl.hidden = false;
											}
										} else {
											var reason = (json && json.error && json.error.message) || i18n.invalid;
											showMsg(reason, 'error');
											var pe = document.getElementById('lrn-sidebar-coupon-price');
											if (pe) { pe.hidden = true; }
										}
									})
									.catch(function(){
										btn.disabled = false;
										showMsg(i18n.networkErr, 'error');
									});
								});

								function showMsg(text, type) {
									if (!msg) return;
									msg.textContent = text;
									msg.hidden = false;
									msg.className = 'lrn-sidebar-card__coupon-message';
									if (type) msg.classList.add('lrn-sidebar-card__coupon-message--' + type);
								}
							})();

}() );