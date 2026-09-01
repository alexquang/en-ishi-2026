<?php
/**
 * Checkout page — unified purchase flow for courses and membership plans.
 *
 * Accessed via /enroll/?course_id=5 or /enroll/?plan_id=3. Handles free
 * enrollment, Stripe card payment, PayPal, and WooCommerce redirect.
 *
 * Variables available:
 *   $route (string) 'enroll'
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Adapters\Adapter_Registry;

// $view_data variables (injected by Template_Loader via Checkout_View_Data):
// $error_type, $item, $item_title, $item_price, $sale_price, $thumbnail,
// $instructor, $is_free, $is_plan, $currency, $currency_symbol,
// $already_enrolled, $course_url, $course_id, $plan_id, $user_id, $settings,
// $stripe_enabled, $paypal_enabled, $wc_active, $test_enabled, $effective_price,
// $billing_cycle

// ---- empty / unavailable states ----
if ( 'course_unavailable' === $error_type ) {
	?>
	<div class="lrn-container lrn-checkout">
		<div class="lrn-checkout__error-page" role="main">
			<p><?php esc_html_e( 'This course is not available for purchase.', 'learnomy' ); ?></p>
			<a href="<?php echo esc_url( \Learnomy\route_url( 'catalog' ) ); ?>" class="lrn-btn lrn-btn--secondary">
				<?php
				printf(
					/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
					esc_html__( 'Browse %s', 'learnomy' ),
					esc_html( \Learnomy\course_label( true ) )
				);
				?>
			</a>
		</div>
	</div>
	<?php
	return;
}
if ( 'plan_unavailable' === $error_type ) {
	?>
	<div class="lrn-container lrn-checkout">
		<div class="lrn-checkout__error-page" role="main">
			<p><?php esc_html_e( 'This membership plan is not available.', 'learnomy' ); ?></p>
			<a href="<?php echo esc_url( \Learnomy\route_url( 'membership-pricing' ) ); ?>" class="lrn-btn lrn-btn--secondary">
				<?php esc_html_e( 'View Plans', 'learnomy' ); ?>
			</a>
		</div>
	</div>
	<?php
	return;
}
if ( 'no_item' === $error_type ) {
	?>
	<div class="lrn-container lrn-checkout">
		<div class="lrn-checkout__error-page" role="main">
			<p><?php esc_html_e( 'No course or plan specified.', 'learnomy' ); ?></p>
			<a href="<?php echo esc_url( \Learnomy\route_url( 'catalog' ) ); ?>" class="lrn-btn lrn-btn--secondary">
				<?php
				printf(
					/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
					esc_html__( 'Browse %s', 'learnomy' ),
					esc_html( \Learnomy\course_label( true ) )
				);
				?>
			</a>
		</div>
	</div>
	<?php
	return;
}

// Already enrolled — show a message instead of the checkout form.
if ( $already_enrolled && $course_id ) {
	?>
	<div class="lrn-container lrn-checkout">
		<div class="lrn-checkout__already-enrolled" role="main">
			<h1><?php esc_html_e( 'Already Enrolled', 'learnomy' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: course title */
					esc_html__( 'You are already enrolled in %s.', 'learnomy' ),
					'<strong>' . esc_html( $item_title ) . '</strong>'
				);
				?>
			</p>
			<a href="<?php echo esc_url( $course_url ); ?>" class="lrn-btn lrn-btn--primary">
				<?php
				printf(
					/* translators: %s: the singular entity label, e.g. "Course" or "Program". */
					esc_html__( 'Go to %s', 'learnomy' ),
					esc_html( \Learnomy\course_label() )
				);
				?>
			</a>
		</div>
	</div>
	<?php
	return;
}

// Closed and invite-only courses are not sold here. The API already refuses
// both (Checkout_Controller, Enrollments_Controller), but this PAGE rendered a
// full purchase UI anyway - a "$0.00" order summary and a billing form for a
// course with no price and no self-serve path, or worse an "Enroll for Free"
// button that failed the moment it was pressed. Refuse at the page too, in the
// same words the rest of the product uses.
if ( $course_id && $item && \Learnomy\Models\Course::is_closed( $item ) ) {
	$lrn_invite_only = \Learnomy\Models\Course::is_invite_only( $item );
	?>
	<div class="lrn-container lrn-checkout">
		<div class="lrn-checkout__already-enrolled" role="main">
			<h1>
				<?php
				echo $lrn_invite_only
					? esc_html__( 'Invite only', 'learnomy' )
					: esc_html__( 'Sold externally', 'learnomy' );
				?>
			</h1>
			<p>
				<?php
				// Names no role, matching the enrolment API: this is read on a
				// company intranet, a school and a solo creator's site, and only
				// one of those has a "site administrator" to go and find.
				echo $lrn_invite_only
					? esc_html__( 'This course does not accept direct enrollments. Access is granted for you rather than requested.', 'learnomy' )
					: esc_html__( 'This course is not available for purchase on this site.', 'learnomy' );
				?>
			</p>
			<a href="<?php echo esc_url( $course_url ); ?>" class="lrn-btn lrn-btn--primary">
				<?php
				printf(
					/* translators: %s: the singular entity label, e.g. "Course" or "Program". */
					esc_html__( 'Go to %s', 'learnomy' ),
					esc_html( \Learnomy\course_label() )
				);
				?>
			</a>
		</div>
	</div>
	<?php
	return;
}

// $stripe_enabled, $paypal_enabled, $wc_active, $test_enabled,
// $effective_price, $currency_symbol all come from Checkout_View_Data.
?>
<main class="lrn-container lrn-checkout" id="lrn-checkout" aria-label="<?php esc_attr_e( 'Checkout', 'learnomy' ); ?>">

	<h1 class="lrn-checkout__heading"><?php esc_html_e( 'Checkout', 'learnomy' ); ?></h1>

	<?php
	/*
	 * Global error container — OUTSIDE the gateway branch on purpose.
	 *
	 * showError() in checkout.js writes here. It used to live inside the else,
	 * so on the WooCommerce hand-off — the one path with no form to validate
	 * against, and therefore the one that relies entirely on the server's
	 * answer — the element did not exist and showError() silently no-opped.
	 * A member with no billing profile pressed Proceed to Payment, the server
	 * answered 422 "Add your billing details to continue.", and the page did
	 * not change by a single character. Every failure on that route was
	 * swallowed the same way: a gateway error, an expired session, a course
	 * that stopped being available (Basecamp 10183733652).
	 */
	?>
	<div id="lrn-checkout-error"
		class="lrn-checkout__message lrn-checkout__message--error"
		role="alert"
		aria-live="assertive"
		aria-atomic="true"
		hidden></div>

	<?php if ( $wc_active && ! $is_free ) : ?>
		<!-- WooCommerce handles payment — redirect to WC cart. -->
		<div class="lrn-checkout__wc-redirect">
			<section class="lrn-checkout__summary lrn-card" aria-labelledby="lrn-wc-summary-heading">
				<h2 id="lrn-wc-summary-heading" class="lrn-sr-only"><?php esc_html_e( 'Order Summary', 'learnomy' ); ?></h2>
				<?php if ( $thumbnail ) : ?>
					<img src="<?php echo esc_url( $thumbnail ); ?>"
						alt="<?php echo esc_attr( $item_title ); ?>"
						class="lrn-checkout__thumbnail" loading="lazy">
				<?php endif; ?>
				<h2 class="lrn-checkout__item-title"><?php echo esc_html( $item_title ); ?></h2>
				<?php if ( $instructor ) : ?>
					<p class="lrn-checkout__instructor">
						<?php
						/* translators: %s: instructor name */
						printf( esc_html__( 'by %s', 'learnomy' ), esc_html( $instructor ) );
						?>
					</p>
				<?php endif; ?>

				<?php
				/*
				 * The amount. This summary showed the title, the instructor and a
				 * "Proceed to Payment" button and NO PRICE AT ALL - on every paid
				 * course, whenever WooCommerce is the gateway. Asking someone to
				 * proceed to payment without telling them what they are about to
				 * pay is below the bar every other store sets, and it is the one
				 * thing this panel exists to say (Basecamp 10198954861).
				 *
				 * Same values and the same recurring treatment as the native
				 * summary below, so the two panels cannot quote different prices
				 * for the same course: a flat amount on a purchase that renews is
				 * the same lie whichever gateway collects it.
				 */
				$lrn_wc_price = (float) ( $effective_price ?? $item_price ?? 0 );
				?>
				<?php if ( $lrn_wc_price > 0 ) : ?>
					<p class="lrn-checkout__wc-price">
						<span class="lrn-checkout__price-amount">
							<?php echo esc_html( \Learnomy\format_price( $lrn_wc_price, $currency ) ); ?>
						</span>
						<?php if ( ! empty( $billing_cycle ) ) : ?>
							<?php
							$lrn_wc_term = match ( (string) $billing_cycle ) {
								'yearly' => __( 'Billed yearly', 'learnomy' ),
								'weekly' => __( 'Billed weekly', 'learnomy' ),
								'daily'  => __( 'Billed daily', 'learnomy' ),
								default  => __( 'Billed monthly', 'learnomy' ),
							};
	?>
							<span class="lrn-badge lrn-badge--muted"><?php echo esc_html( $lrn_wc_term ); ?></span>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</section>
			<p class="lrn-checkout__wc-message">
				<?php esc_html_e( "You'll be redirected to complete payment.", 'learnomy' ); ?>
			</p>
			<button type="button" id="lrn-wc-redirect-btn" class="lrn-btn lrn-btn--primary lrn-btn--lg">
				<?php esc_html_e( 'Proceed to Payment', 'learnomy' ); ?>
			</button>
		</div>

	<?php else : ?>


		<div class="lrn-checkout__layout">

			<!-- Left: Order Summary -->
			<section class="lrn-checkout__summary lrn-card" aria-labelledby="lrn-summary-heading">
				<h2 id="lrn-summary-heading" class="lrn-checkout__section-title">
					<?php esc_html_e( 'Order Summary', 'learnomy' ); ?>
				</h2>

				<div class="lrn-checkout__item">
					<?php if ( $thumbnail ) : ?>
						<img src="<?php echo esc_url( $thumbnail ); ?>"
							alt="<?php echo esc_attr( $item_title ); ?>"
							class="lrn-checkout__thumbnail" loading="lazy">
					<?php endif; ?>
					<div class="lrn-checkout__item-details">
						<h3 class="lrn-checkout__item-title"><?php echo esc_html( $item_title ); ?></h3>
						<?php if ( $instructor ) : ?>
							<p class="lrn-checkout__instructor">
								<?php
								/* translators: %s: instructor name */
								printf( esc_html__( 'by %s', 'learnomy' ), esc_html( $instructor ) );
								?>
							</p>
						<?php endif; ?>
						<?php if ( $is_plan || ! empty( $is_recurring ) ) : ?>
							<span class="lrn-badge"><?php $is_plan ? esc_html_e( 'Membership', 'learnomy' ) : esc_html_e( 'Subscription', 'learnomy' ); ?></span>
							<?php
							/*
							 * Name the term being bought. The page previously showed a
							 * bare amount, so a buyer who picked the annual option had
							 * nothing on the checkout screen confirming it — and nothing
							 * contradicting the monthly amount they were actually
							 * charged (Basecamp 10143220483). A recurring-priced COURSE
							 * gets the same treatment: a flat "$9.00" on a purchase
							 * that renews monthly is the same lie (Basecamp 10155999596).
							 */
							$lrn_term_label = match ( (string) ( $billing_cycle ?? '' ) ) {
								'yearly' => __( 'Billed yearly', 'learnomy' ),
								'weekly' => __( 'Billed weekly', 'learnomy' ),
								'daily'  => __( 'Billed daily', 'learnomy' ),
								default  => __( 'Billed monthly', 'learnomy' ),
							};
	?>
							<span class="lrn-badge lrn-badge--muted"><?php echo esc_html( $lrn_term_label ); ?></span>
						<?php endif; ?>
					</div>
				</div>

				<?php if ( ! $is_free ) : ?>
					<div class="lrn-checkout__price-lines" aria-label="<?php esc_attr_e( 'Price breakdown', 'learnomy' ); ?>">
						<div class="lrn-checkout__price-line">
							<span><?php esc_html_e( 'Price', 'learnomy' ); ?></span>
							<span id="lrn-original-price">
								<?php
								// Currency + amount: not a translatable string — locale formatting
								// is handled by number_format_i18n(). Symbol position varies per
								// locale and should be handled by a currency helper if needed, but
								// symbol+number concatenation is the existing site convention.
								echo esc_html( \Learnomy\format_price( (float) $item_price, $currency ) );
								?>
							</span>
						</div>

						<?php if ( $sale_price > 0 && $sale_price < $item_price ) : ?>
							<div class="lrn-checkout__price-line lrn-checkout__price-line--discount">
								<span><?php esc_html_e( 'Sale discount', 'learnomy' ); ?></span>
								<span>
									<?php
									$sale_diff = $item_price - $sale_price;
									echo esc_html( '-' . \Learnomy\format_price( (float) $sale_diff, $currency ) );
									?>
								</span>
							</div>
						<?php endif; ?>

						<?php
						/*
						 * Billing details — OURS, not the gateway's.
						 *
						 * These used to be Stripe's Address Element, which meant they
						 * only existed for card buyers, could not be prefilled, could
						 * not carry a company or tax number, and were thrown away
						 * after every purchase so the same member retyped them every
						 * time. They live here now, above the totals, in the reading
						 * order a person expects: who is buying, then what it costs.
						 *
						 * Values come from the member's own profile
						 * (Billing_Profile), stored under WooCommerce's meta keys so
						 * a site with Woo needs no second entry and one without Woo
						 * needs no Woo.
						 */
						$lrn_bill      = $billing_profile ?? array();
						$lrn_countries = $billing_countries ?? array();
						?>
						<div class="lrn-checkout__billing" id="lrn-billing-details">
							<h3 class="lrn-checkout__billing-title"><?php esc_html_e( 'Billing details', 'learnomy' ); ?></h3>

							<?php
							// The ONE copy of these fields, shared with the account
							// Billing page. Two address forms drift, and the required
							// set here must match what missing_required() enforces.
							require \Learnomy\template_path( 'partials/billing-fields.php' );
							?>

							<p class="lrn-checkout__billing-note">
								<?php esc_html_e( 'Saved to your account, so you only enter this once.', 'learnomy' ); ?>
							</p>
						</div>

						<div class="lrn-checkout__price-line lrn-checkout__price-line--coupon" id="lrn-coupon-line" hidden>
							<span>
								<?php esc_html_e( 'Coupon:', 'learnomy' ); ?>
								<span id="lrn-coupon-code-display"></span>
							</span>
							<span id="lrn-coupon-discount"></span>
						</div>

						<?php
						// Uniform tax (level-1 compliance). Shown when enabled; the
						// amount + total update client-side as coupons change.
						$lrn_co_settings  = get_option( 'learnomy_settings', array() );
						$lrn_co_tax_on    = ! empty( $lrn_co_settings['tax_enabled'] ) && (float) ( $lrn_co_settings['tax_rate'] ?? 0 ) > 0;
						$lrn_co_tax_rate  = $lrn_co_tax_on ? (float) $lrn_co_settings['tax_rate'] : 0.0;
						$lrn_co_tax_label = ( ! empty( $lrn_co_settings['tax_label'] ) ) ? (string) $lrn_co_settings['tax_label'] : __( 'Tax', 'learnomy' );
						$lrn_co_tax_amt   = $lrn_co_tax_on ? round( $effective_price * ( $lrn_co_tax_rate / 100 ), 2 ) : 0.0;
						$lrn_co_total     = $effective_price + $lrn_co_tax_amt;
						?>
						<div class="lrn-checkout__price-line lrn-checkout__price-line--tax" id="lrn-tax-line" <?php echo $lrn_co_tax_on ? '' : 'hidden'; ?>>
							<span>
								<?php echo esc_html( $lrn_co_tax_label ); ?>
								(<span id="lrn-tax-rate-display"><?php echo esc_html( rtrim( rtrim( number_format_i18n( $lrn_co_tax_rate, 2 ), '0' ), '.' ) ); ?></span>%)
							</span>
							<span id="lrn-tax-amount"><?php echo esc_html( \Learnomy\format_price( (float) $lrn_co_tax_amt, $currency ) ); ?></span>
						</div>

						<div class="lrn-checkout__price-line lrn-checkout__price-line--total">
							<span><?php esc_html_e( 'Total', 'learnomy' ); ?></span>
							<span id="lrn-total-price" aria-live="polite" aria-atomic="true">
								<?php echo esc_html( \Learnomy\format_price( (float) $lrn_co_total, $currency ) ); ?>
							</span>
						</div>

						<?php
						/*
						 * Shown only for a gateway that runs its own tax engine
						 * (WooCommerce today). Those gateways receive our NET price and
						 * add tax at their own checkout, so quoting our rate here would
						 * name a figure the buyer is never charged and then show them a
						 * different total one screen later.
						 */
						?>
						<p class="lrn-checkout__tax-note" id="lrn-tax-deferred-note" hidden>
							<?php esc_html_e( 'Tax is calculated at the next step.', 'learnomy' ); ?>
						</p>
					</div>

					<?php
					/*
					 * Coupon input — one-time purchases only.
					 *
					 * A recurring membership does not accept coupons (see
					 * Checkout_Controller::resolve_checkout_args()): our coupons
					 * have no duration, so the only thing we could honestly do
					 * with one on a subscription is discount the first charge and
					 * let the renewal jump back to full price. Rendering the field
					 * anyway is what produced the actual harm on this card — the
					 * buyer typed a code, watched the total drop, and was charged
					 * the undiscounted amount (Basecamp 10143220397). Don't offer
					 * what we won't honour.
					 */
					?>
					<?php if ( ! $is_plan && empty( $is_recurring ) ) : ?>
					<!-- Coupon input -->
					<div class="lrn-checkout__coupon">
						<label for="lrn-coupon-input" class="lrn-checkout__coupon-label">
							<?php esc_html_e( 'Coupon Code', 'learnomy' ); ?>
						</label>
						<div class="lrn-checkout__coupon-row" id="lrn-coupon-form">
							<input type="text" id="lrn-coupon-input" class="lrn-input"
								autocomplete="off"
								aria-describedby="lrn-coupon-message"
								placeholder="<?php esc_attr_e( 'Enter code', 'learnomy' ); ?>">
							<button type="button" id="lrn-apply-coupon" class="lrn-btn lrn-btn--secondary lrn-btn--tap">
								<?php esc_html_e( 'Apply', 'learnomy' ); ?>
							</button>
						</div>

						<?php
						/*
						 * Applied-coupon chip. Replaces the input row once a code
						 * sticks, and carries the only way to take it off again —
						 * before this there was no Remove control at all, so a
						 * buyer who applied the wrong code had to reload the page
						 * (Basecamp 10134139671). Hidden until the JS applies a
						 * coupon; the JS owns the swap so the chip and the input
						 * can never both be visible.
						 */
						?>
						<div class="lrn-checkout__coupon-chip" id="lrn-coupon-chip" hidden>
							<span class="lrn-checkout__coupon-chip-label">
								<?php esc_html_e( 'Applied:', 'learnomy' ); ?>
								<strong id="lrn-coupon-chip-code"></strong>
							</span>
							<button type="button"
								id="lrn-remove-coupon"
								class="lrn-btn lrn-btn--ghost lrn-btn--sm lrn-btn--tap"
								aria-label="<?php esc_attr_e( 'Remove the applied coupon', 'learnomy' ); ?>">
								<?php esc_html_e( 'Remove', 'learnomy' ); ?>
							</button>
						</div>

						<!-- aria-live="assertive" so coupon feedback reads immediately. -->
						<p id="lrn-coupon-message"
							class="lrn-checkout__coupon-message"
							aria-live="assertive"
							aria-atomic="true"></p>
					</div>
					<?php endif; ?>
				<?php endif; ?>
			</section>

			<!-- Right: Payment -->
			<section class="lrn-checkout__payment lrn-card" aria-labelledby="lrn-payment-heading">

				<?php if ( $is_free ) : ?>
					<!-- Free course — no payment needed -->
					<h2 id="lrn-payment-heading" class="lrn-checkout__section-title">
						<?php esc_html_e( 'Free Enrollment', 'learnomy' ); ?>
					</h2>
					<p class="lrn-checkout__free-note">
						<?php esc_html_e( 'This course is free. Click below to enroll instantly.', 'learnomy' ); ?>
					</p>
					<button type="button" id="lrn-enroll-free" class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-btn--full">
						<?php esc_html_e( 'Enroll for Free', 'learnomy' ); ?>
					</button>

				<?php else : ?>
					<?php
					// Count enabled gateways. A single gateway needs no visible
					// selector — one radio "choice" reads as clutter and, for
					// Stripe, stacks our card chrome around Stripe's own boxed
					// form (box-in-box). When there's only one, keep the radio in
					// the DOM (the JS reads :checked to pick the method) but hide
					// the selector visually.
					$lrn_gateway_count    = (int) $stripe_enabled + (int) $paypal_enabled + (int) $test_enabled;
					$lrn_single_gateway   = ( $lrn_gateway_count <= 1 );
					$lrn_methods_fs_class = 'lrn-checkout__methods-fieldset' . ( $lrn_single_gateway ? ' lrn-checkout__methods-fieldset--hidden' : '' );
					?>
					<h2 id="lrn-payment-heading" class="lrn-checkout__section-title">
						<?php esc_html_e( 'Payment', 'learnomy' ); ?>
					</h2>

					<?php if ( $stripe_enabled || $paypal_enabled || $test_enabled ) : ?>
						<!-- Payment method selector — fieldset + legend for screen-reader radio group. -->
						<fieldset class="<?php echo esc_attr( $lrn_methods_fs_class ); ?>">
							<legend class="lrn-checkout__methods-legend">
								<?php esc_html_e( 'Payment method', 'learnomy' ); ?>
							</legend>
							<div class="lrn-checkout__methods">
								<?php if ( $test_enabled ) : ?>
									<label class="lrn-checkout__method-option">
										<input type="radio" name="lrn_payment_method" value="test"
											data-submit-label="<?php esc_attr_e( 'Complete Test Purchase', 'learnomy' ); ?>"
											<?php checked( true ); ?>>
										<span class="lrn-checkout__method-label">
											<?php esc_html_e( 'Test Payment', 'learnomy' ); ?>
										</span>
									</label>
								<?php endif; ?>
								<?php if ( $stripe_enabled ) : ?>
									<label class="lrn-checkout__method-option">
										<input type="radio" name="lrn_payment_method" value="stripe"
											data-submit-label="<?php esc_attr_e( 'Pay with card', 'learnomy' ); ?>"
											<?php checked( ! $test_enabled ); ?>>
										<span class="lrn-checkout__method-label">
											<?php esc_html_e( 'Credit Card', 'learnomy' ); ?>
										</span>
									</label>
								<?php endif; ?>
								<?php if ( $paypal_enabled ) : ?>
									<label class="lrn-checkout__method-option">
										<input type="radio" name="lrn_payment_method" value="paypal"
											data-submit-label="<?php esc_attr_e( 'Pay with PayPal', 'learnomy' ); ?>"
											<?php checked( ! $stripe_enabled && ! $test_enabled ); ?>>
										<span class="lrn-checkout__method-label">
											<?php esc_html_e( 'PayPal', 'learnomy' ); ?>
										</span>
									</label>
								<?php endif; ?>
							</div>
						</fieldset>
					<?php endif; ?>

					<?php if ( $test_enabled ) : ?>
						<!-- Test gateway — instant approval, no real charge -->
						<div id="lrn-test-container" class="lrn-checkout__test-gateway">
							<div class="lrn-checkout__test-notice" role="status">
								<i data-lucide="flask-conical" aria-hidden="true"></i>
								<div>
									<strong><?php esc_html_e( 'Test Mode Active', 'learnomy' ); ?></strong>
									<p><?php esc_html_e( 'No real payment will be charged. Click the button below to simulate a successful payment.', 'learnomy' ); ?></p>
								</div>
							</div>
						</div>
					<?php endif; ?>

					<?php if ( $stripe_enabled ) : ?>
						<!--
							Stripe Payment Element: card fields render on THIS page
							(no redirect), themed to the site (light/dark) via the
							appearance API in checkout.js. The fields mount into
							#lrn-stripe-payment-element; our own submit button below
							drives stripe.confirmPayment().
						-->
						<div id="lrn-stripe-container" class="lrn-checkout__stripe"
							<?php echo $test_enabled ? 'hidden' : ''; ?>>
							<p class="lrn-checkout__stripe-note">
								<?php esc_html_e( 'Enter your card details to pay securely without leaving this page.', 'learnomy' ); ?>
							</p>
							<div id="lrn-stripe-payment-element" class="lrn-checkout__stripe-element"></div>
							<div id="lrn-stripe-error" class="lrn-checkout__stripe-error" role="alert" hidden></div>
						</div>
					<?php endif; ?>

					<?php if ( $paypal_enabled ) : ?>
						<!-- PayPal Buttons container -->
						<div id="lrn-paypal-container" class="lrn-checkout__paypal"
							<?php echo $stripe_enabled ? 'hidden' : ''; ?>>
							<div id="lrn-paypal-buttons" aria-label="<?php esc_attr_e( 'PayPal payment button', 'learnomy' ); ?>"></div>
						</div>
					<?php endif; ?>

					<!-- Submit button (Stripe + Test flow — PayPal uses its own rendered button) -->
					<?php if ( $stripe_enabled || $test_enabled ) : ?>
						<button type="button" id="lrn-submit-payment"
							class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-btn--full"
							aria-describedby="lrn-submit-help">
							<span id="lrn-submit-text">
								<?php
								echo $test_enabled
									? esc_html__( 'Complete Test Purchase', 'learnomy' )
									: esc_html__( 'Complete Purchase', 'learnomy' );
								?>
							</span>
							<span id="lrn-submit-spinner" class="lrn-checkout__spinner" hidden aria-hidden="true"></span>
						</button>
						<span id="lrn-submit-help" class="lrn-sr-only">
							<?php esc_html_e( 'You will be redirected to the payment provider to complete your purchase.', 'learnomy' ); ?>
						</span>
					<?php endif; ?>

					<!--
						Free-order button - hidden until a coupon zeroes the total.
						checkout.js swaps the gateway UI for this button whenever the
						effective total hits $0: a zero-total order completes without
						any provider (the server never contacts one), so it must not
						depend on a gateway being configured or selected. On a site
						with no gateway at all this is the ONLY way a 100%-coupon
						buyer can finish (Basecamp 10172705555).
					-->
					<button type="button" id="lrn-submit-free-order"
						class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-btn--full"
						hidden>
						<?php esc_html_e( 'Complete Free Order', 'learnomy' ); ?>
					</button>

					<?php if ( ! $stripe_enabled && ! $paypal_enabled && ! $test_enabled ) : ?>
						<p class="lrn-checkout__no-gateways">
							<?php
							// "site owner", not "site administrator". The buyer who
							// hits this is one page away from checkout-thank-you.php,
							// which calls the same person the site owner - and every
							// other customer-facing string in the plugin agrees with
							// that one. This was the single outlier, and two names for
							// one person in a single flow reads as two different people.
							esc_html_e( 'No payment methods are currently available. Please contact the site owner.', 'learnomy' );
							?>
						</p>
					<?php endif; ?>

					<p class="lrn-checkout__secure-badge">
						<i data-lucide="lock" aria-hidden="true"></i>
						<?php esc_html_e( 'Secure checkout. Your payment information is encrypted.', 'learnomy' ); ?>
					</p>
				<?php endif; ?>

			</section>
		</div>

	<?php endif; ?>

	<!--
		Processing overlay — shown while a payment intent is in-flight.
		role="dialog" + aria-live ensures screen readers announce the state change.
		The overlay is also pointer-event blocking to prevent double-submit.
	-->
	<div id="lrn-checkout-overlay"
		class="lrn-checkout__overlay"
		role="dialog"
		aria-modal="true"
		aria-labelledby="lrn-overlay-msg"
		aria-live="assertive"
		hidden>
		<div class="lrn-checkout__overlay-content">
			<span class="lrn-checkout__spinner lrn-checkout__spinner--lg" aria-hidden="true"></span>
			<p id="lrn-overlay-msg">
				<?php esc_html_e( 'Processing your payment. Please do not close this window.', 'learnomy' ); ?>
			</p>
		</div>
	</div>

</main>
