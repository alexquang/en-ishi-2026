<?php
/**
 * Membership pricing page — /membership/
 *
 * Rendered by `Template_Loader::render()` which first resolves data via
 * `Membership_Pricing_View_Data`. The template performs zero DB work and
 * zero service calls — it only consumes the resolved view data.
 *
 * Variables available (extracted from `$view_data`):
 *   $plans            array<object> Active plans, ordered by sort_order.
 *   $current_user_id  int           Current user, 0 when guest.
 *   $current_subs     array<object> Active subscriptions for the user.
 *   $current_plan_ids array<int>    Ids of plans the user is already on.
 *   $show_toggle      bool          Render monthly/yearly toggle?
 *   $currency         string        ISO 4217 code.
 *   $currency_symbol  string        Currency glyph, e.g. "$".
 *   $can_change_plan  bool          Pro-gated ability to switch plans.
 *   $checkout_url     string        Base checkout URL.
 *   $route            string        'membership-pricing'.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.PHP.DontExtract.extract_extract -- locals come from Template_Loader extract().

$plans            = isset( $plans ) && is_array( $plans ) ? $plans : array();
$current_user_id  = isset( $current_user_id ) ? (int) $current_user_id : 0;
$current_plan_ids = isset( $current_plan_ids ) && is_array( $current_plan_ids ) ? $current_plan_ids : array();
$show_toggle      = ! empty( $show_toggle );
$currency_symbol  = isset( $currency_symbol ) ? (string) $currency_symbol : '$';
$can_change_plan  = ! empty( $can_change_plan );
$checkout_url     = isset( $checkout_url ) ? (string) $checkout_url : \Learnomy\route_url( 'enroll' );
$is_logged_in     = $current_user_id > 0;

// Tell the partial whether this viewer is already a subscriber — used to
// pick the "Currently subscribed" state vs the normal CTA on non-current
// plans. Kept on $GLOBALS so the include has access without re-passing a
// dozen variables through every iteration.
$GLOBALS['lrn_viewer_has_any_sub'] = ! empty( $current_plan_ids );

?>
<main
	class="lrn-container lrn-pricing-page"
	id="lrn-pricing-page"
	data-wp-interactive="learnomy/pricing"
>

	<?php
	// A member who already subscribes arrives here to COMPARE or upgrade, and
	// their subscription lives on the billing page -- so Home is not where they
	// want to go back to. An anonymous visitor evaluating plans does want Home.
	// Same page, two different journeys, so the crumb follows the reader.
	//
	// $current_subs is already resolved by the view-data; no extra query.
	$breadcrumb_items = ( ! empty( $current_user_id ) && ! empty( $current_subs ) )
		? [
			[
				'label' => __( 'Billing', 'learnomy' ),
				'href'  => \Learnomy\route_url( 'account-billing' ),
				'icon'  => 'credit-card',
			],
			[
				'label' => __( 'Plans', 'learnomy' ),
			],
		]
		: [
			// breadcrumb_root(): an anonymous visitor anchors to the catalog,
			// a logged-in reader without a subscription to their Account.
			\Learnomy\breadcrumb_root(),
			[
				'label' => __( 'Membership', 'learnomy' ),
			],
		];
	require \Learnomy\template_path( 'partials/breadcrumb.php' );
	?>

	<header class="lrn-pricing-page__intro">
		<h1 class="lrn-pricing-page__title">
			<?php echo esc_html( isset( $page_headline ) ? (string) $page_headline : __( 'Simple, transparent pricing', 'learnomy' ) ); ?>
		</h1>
		<p class="lrn-pricing-page__subtitle">
			<?php echo esc_html( isset( $page_subtitle ) ? (string) $page_subtitle : __( 'One subscription. Every course. Cancel anytime.', 'learnomy' ) ); ?>
		</p>

		<?php
		/**
		 * Fires above the plan grid. Pro uses it to inject a trust-badges
		 * row ("30-day money-back guarantee", "SSL secure"), a live learner
		 * counter, or a promo banner.
		 */
		do_action( 'learnomy_pricing_page_header' );
		?>

		<?php if ( $show_toggle ) : ?>
			<div
				class="lrn-pricing-toggle"
				role="group"
				aria-label="<?php esc_attr_e( 'Billing period', 'learnomy' ); ?>"
			>
				<button
					type="button"
					class="lrn-pricing-toggle__btn"
					data-wp-on--click="actions.setMonthly"
					data-wp-class--is-active="state.isMonthly"
					data-wp-bind--aria-pressed="state.isMonthly"
				>
					<?php esc_html_e( 'Monthly', 'learnomy' ); ?>
				</button>
				<button
					type="button"
					class="lrn-pricing-toggle__btn"
					data-wp-on--click="actions.setYearly"
					data-wp-class--is-active="state.isYearly"
					data-wp-bind--aria-pressed="state.isYearly"
				>
					<?php esc_html_e( 'Yearly', 'learnomy' ); ?>
					<span class="lrn-pricing-toggle__save">
						<?php esc_html_e( 'Save up to 20%', 'learnomy' ); ?>
					</span>
				</button>
			</div>
		<?php endif; ?>
	</header>

	<?php if ( empty( $plans ) ) : ?>

		<div class="lrn-empty-state">
			<i data-lucide="package-open" class="lrn-empty-state__icon" aria-hidden="true"></i>
			<h2 class="lrn-empty-state__title">
				<?php esc_html_e( 'No plans are available yet', 'learnomy' ); ?>
			</h2>
			<p class="lrn-empty-state__desc">
				<?php esc_html_e( 'Membership tiers are being finalised.', 'learnomy' ); ?>
			</p>
		</div>

	<?php else : ?>

		<ul class="lrn-pricing-grid" role="list">
			<?php
			foreach ( $plans as $plan ) :
				$plan_id    = isset( $plan->id ) ? (int) $plan->id : 0;
				$is_current = in_array( $plan_id, $current_plan_ids, true );
				?>
				<li>
					<?php include \Learnomy\template_path( 'partials/membership-plan-card.php' ); ?>
				</li>
				<?php
			endforeach;
			?>
		</ul>

		<!-- Coupon box: applies at checkout, not here. Keep it quiet. -->
		<form
			class="lrn-pricing-coupon"
			action="<?php echo esc_url( $checkout_url ); ?>"
			method="get"
		>
			<label for="lrn-pricing-coupon" class="lrn-pricing-coupon__label">
				<?php esc_html_e( 'Have a coupon code?', 'learnomy' ); ?>
			</label>
			<div class="lrn-pricing-coupon__row">
				<input
					id="lrn-pricing-coupon"
					name="coupon"
					type="text"
					autocomplete="off"
					placeholder="<?php esc_attr_e( 'Enter code', 'learnomy' ); ?>"
				/>
				<button type="submit" class="lrn-btn lrn-btn--secondary">
					<?php esc_html_e( 'Apply at checkout', 'learnomy' ); ?>
				</button>
			</div>
		</form>

	<?php endif; ?>

	<?php
	/**
	 * Fires inside the pricing page's social-proof section. Pro uses it
	 * to render testimonials, logo walls, review stars, and press mentions.
	 * Empty by default — renders a zero-height section.
	 */
	?>
	<section class="lrn-pricing-social-proof" aria-label="<?php esc_attr_e( 'What our learners say', 'learnomy' ); ?>">
		<?php do_action( 'learnomy_pricing_social_proof' ); ?>
	</section>

	<?php
	// Gifting widgets render here (before the FAQ). Gated by the admin
	// "Show gifting" toggle; Pro hooks learnomy_pricing_before_faq to render
	// the redeem / buy-as-a-gift forms.
	$lrn_show_gift = ! isset( $show_gift ) || $show_gift;
	if ( $lrn_show_gift ) {
		/**
		 * Fires after the plan grid + social proof but BEFORE the FAQ. Pro
		 * renders the gift redeem / buy-as-a-gift widgets here so they sit with
		 * the purchase options instead of stranded below the FAQ.
		 */
		do_action( 'learnomy_pricing_before_faq' );
	}
	?>

	<?php
	$lrn_show_faq = ! isset( $show_faq ) || $show_faq;
	$lrn_faq      = isset( $faq_items ) && is_array( $faq_items ) ? $faq_items : array();
	if ( $lrn_show_faq && ! empty( $lrn_faq ) ) :
		?>
		<section class="lrn-pricing-faq" aria-labelledby="lrn-pricing-faq-title">
			<h2 id="lrn-pricing-faq-title" class="lrn-pricing-faq__title">
				<?php esc_html_e( 'Frequently asked questions', 'learnomy' ); ?>
			</h2>

			<?php foreach ( $lrn_faq as $lrn_faq_row ) : ?>
				<?php
				$lrn_q = isset( $lrn_faq_row['q'] ) ? (string) $lrn_faq_row['q'] : '';
				$lrn_a = isset( $lrn_faq_row['a'] ) ? (string) $lrn_faq_row['a'] : '';
				if ( '' === trim( $lrn_q ) ) {
					continue;
				}
				?>
				<details class="lrn-pricing-faq__item">
					<summary><?php echo esc_html( $lrn_q ); ?></summary>
					<div class="lrn-pricing-faq__answer"><?php echo esc_html( $lrn_a ); ?></div>
				</details>
			<?php endforeach; ?>
		</section>
	<?php endif; ?>

	<?php
	/**
	 * Fires below the FAQ block. Pro uses it to inject a money-back
	 * guarantee card, a secondary CTA, or a compare-plans table.
	 */
	do_action( 'learnomy_pricing_page_footer' );
	?>

</main>
<?php
unset( $GLOBALS['lrn_viewer_has_any_sub'] );
// phpcs:enable WordPress.PHP.DontExtract.extract_extract
