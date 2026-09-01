<?php
/**
 * Reusable plan card — rendered on the pricing grid and on the "upgrade
 * options" section of the manage page.
 *
 * Required variables (set by including template):
 *   $plan            object   Plan row from MembershipPlan::get_active().
 *   $currency_symbol string   Currency glyph, e.g. "$".
 *   $is_current      bool     Whether the viewer is already subscribed to this plan.
 *   $can_change_plan bool     Whether Pro has enabled plan switching.
 *   $show_toggle     bool     Whether the monthly/yearly toggle is active.
 *   $checkout_url    string   Base checkout URL (plan_id appended).
 *   $is_logged_in    bool     Whether the viewer is authenticated.
 *
 * Never called standalone — only via `include` from a template that has
 * already escaped / resolved the arguments.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

/** @var object $plan */
/** @var string $currency_symbol */
/** @var bool   $is_current */
/** @var bool   $can_change_plan */
/** @var bool   $show_toggle */
/** @var string $checkout_url */
/** @var bool   $is_logged_in */

// The plan's OWN currency, not the site default. Every price on this card
// called format_price() with no currency argument, so a plan saved as INR or
// GBP still rendered with the site's symbol — the admin field accepted the
// choice and the storefront ignored it (Basecamp 10195157877). Null falls back
// to the site charge currency, which is correct for a plan that has none.
$plan_currency = isset( $plan->currency ) && '' !== $plan->currency ? (string) $plan->currency : null;

$plan_id       = isset( $plan->id ) ? (int) $plan->id : 0;
$plan_title    = isset( $plan->title ) ? (string) $plan->title : '';
$plan_desc     = isset( $plan->description ) ? (string) $plan->description : '';
$price_monthly = isset( $plan->price_monthly ) ? (float) $plan->price_monthly : 0.0;
$price_yearly  = isset( $plan->price_yearly ) ? (float) $plan->price_yearly : 0.0;
$flat_price    = isset( $plan->price ) ? (float) $plan->price : 0.0;
$trial_days    = isset( $plan->trial_days ) ? (int) $plan->trial_days : 0;
$is_featured   = ! empty( $plan->is_featured );
$features      = array();
if ( ! empty( $plan->features_json ) ) {
	$decoded = json_decode( (string) $plan->features_json, true );
	if ( is_array( $decoded ) ) {
		$features = $decoded;
	}
}

// When the plan has both monthly + yearly the toggle switches the visible
// amount. Otherwise the single price is always displayed.
$has_both_prices = ( $price_monthly > 0 && $price_yearly > 0 );
$primary_amount  = $price_monthly > 0 ? $price_monthly : $flat_price;

// Savings percent — only meaningful when both prices are present.
$savings_percent = 0;
if ( $has_both_prices ) {
	$yearly_full     = $price_monthly * 12;
	$savings_percent = $yearly_full > 0
		? (int) round( ( ( $yearly_full - $price_yearly ) / $yearly_full ) * 100 )
		: 0;
}

$card_classes = array( 'lrn-pricing-card' );
if ( $is_featured ) {
	$card_classes[] = 'lrn-pricing-card--featured';
}
if ( $is_current ) {
	$card_classes[] = 'lrn-pricing-card--current';
}

// Carry the plan's real billing cycle so an annual plan checks out as annual,
// not a hardcoded 'monthly' that contradicts the term the customer is buying.
//
// A dual-price plan starts on MONTHLY because that is where the `learnomy/pricing`
// store starts, and the two must agree at first paint. The toggle then rewrites
// this link through `state.subscribeHref` below — it used to change only the
// displayed number, leaving the CTA pointed at the other term, which is how a
// buyer could read "$190 / year" and be sent to a monthly checkout
// (Basecamp 10143220483).
$plan_billing = $has_both_prices
	? 'monthly'
	: \Learnomy\Models\MembershipPlan::price_for_cycle( $plan )['cycle'];

$lrn_plan_href = static fn( string $cycle ): string => add_query_arg(
	array(
		'plan_id' => $plan_id,
		'billing' => $cycle,
	),
	$checkout_url
);

$checkout_href = $lrn_plan_href( $plan_billing );

// Per-card context for the toggle. Only emitted for dual-price plans — a plan
// with one price has nothing to switch between.
$lrn_card_context = $has_both_prices
	? wp_json_encode(
		array(
			'hrefMonthly' => $lrn_plan_href( 'monthly' ),
			'hrefYearly'  => $lrn_plan_href( 'yearly' ),
			'ctaMonthly'  => sprintf(
				/* translators: %s: formatted monthly price */
				__( 'Subscribe for %s / month', 'learnomy' ),
				\Learnomy\format_price( $price_monthly, $plan_currency )
			),
			'ctaYearly'   => sprintf(
				/* translators: %s: formatted yearly price */
				__( 'Subscribe for %s / year', 'learnomy' ),
				\Learnomy\format_price( $price_yearly, $plan_currency )
			),
		)
	)
	: '';
?>
<?php
/*
 * For featured cards we inject an aria-label that includes "(Most Popular)"
 * so screen readers announce it without relying on the visual badge.
 */
$article_aria_label = $is_featured
	? sprintf(
		/* translators: %s: plan name. */
		__( '%s (Most Popular)', 'learnomy' ),
		$plan_title
	)
	: '';
?>
<article
	class="<?php echo esc_attr( implode( ' ', $card_classes ) ); ?>"
	<?php if ( $plan_id > 0 ) : ?>
		<?php // Anchor target for the admin editor's "Preview on pricing page" link. ?>
	id="lrn-plan-<?php echo esc_attr( (string) $plan_id ); ?>"
	<?php endif; ?>
	data-plan-id="<?php echo esc_attr( (string) $plan_id ); ?>"
	<?php if ( '' !== $lrn_card_context ) : ?>
	data-wp-context="<?php echo esc_attr( $lrn_card_context ); ?>"
	<?php endif; ?>
	<?php if ( '' !== $article_aria_label ) : ?>
	aria-label="<?php echo esc_attr( $article_aria_label ); ?>"
	<?php endif; ?>
>

	<?php if ( $is_featured ) : ?>
		<span class="lrn-pricing-card__badge" aria-hidden="true">
			<?php esc_html_e( 'Most Popular', 'learnomy' ); ?>
		</span>
	<?php endif; ?>

	<header class="lrn-pricing-card__header">
		<h3 class="lrn-pricing-card__name"><?php echo esc_html( $plan_title ); ?></h3>
		<?php if ( '' !== $plan_desc ) : ?>
			<p class="lrn-pricing-card__description"><?php echo esc_html( $plan_desc ); ?></p>
		<?php endif; ?>
	</header>

	<div class="lrn-pricing-card__price" aria-hidden="true">
		<?php if ( $has_both_prices ) : ?>
			<span
				class="lrn-pricing-card__amount"
				data-wp-class--lrn-pricing-card__amount--hidden="state.isYearly"
			><?php echo esc_html( \Learnomy\format_price( $price_monthly, $plan_currency ) ); ?></span>
			<span
				class="lrn-pricing-card__amount"
				data-wp-class--lrn-pricing-card__amount--hidden="!state.isYearly"
			><?php echo esc_html( \Learnomy\format_price( $price_yearly, $plan_currency ) ); ?></span>

			<span
				class="lrn-pricing-card__period"
				data-wp-class--lrn-pricing-card__period--hidden="state.isYearly"
			><?php esc_html_e( '/ month', 'learnomy' ); ?></span>
			<span
				class="lrn-pricing-card__period"
				data-wp-class--lrn-pricing-card__period--hidden="!state.isYearly"
			><?php esc_html_e( '/ year', 'learnomy' ); ?></span>
		<?php else : ?>
			<span class="lrn-pricing-card__amount">
				<?php echo esc_html( \Learnomy\format_price( $primary_amount, $plan_currency ) ); ?>
			</span>
			<span class="lrn-pricing-card__period">
				<?php
				// J-02.2 — Aurora Annual was stored as billing_interval='annual'
				// and the map only knew 'yearly', so it silently fell back to
				// '/ month'. Normalize synonyms (annual→yearly, month→monthly,
				// year→yearly) so all values produced by the admin form, REST
				// import, or migrations land on the same label.
				$interval_label = isset( $plan->billing_interval ) ? (string) $plan->billing_interval : 'monthly';
				$interval_label = strtolower( $interval_label );
				$interval_label = match ( $interval_label ) {
					'annual', 'year'  => 'yearly',
					'month'           => 'monthly',
					'week'            => 'weekly',
					'day'             => 'daily',
					default           => $interval_label,
				};
				$labels = array(
					'daily'   => __( '/ day', 'learnomy' ),
					'weekly'  => __( '/ week', 'learnomy' ),
					'monthly' => __( '/ month', 'learnomy' ),
					'yearly'  => __( '/ year', 'learnomy' ),
				);
				echo esc_html( $labels[ $interval_label ] ?? $labels['monthly'] );
				?>
			</span>
		<?php endif; ?>
	</div>

	<?php
	/*
	 * Screen-reader price announcement — visually hidden, speaks the full
	 * phrase, e.g. "$29.00 per month". The visible price block above is
	 * aria-hidden so screen readers don't double-announce.
	 */
	$sr_interval_labels = array(
		'daily'   => __( 'per day', 'learnomy' ),
		'weekly'  => __( 'per week', 'learnomy' ),
		'monthly' => __( 'per month', 'learnomy' ),
		'yearly'  => __( 'per year', 'learnomy' ),
	);
	if ( $has_both_prices ) {
		$sr_monthly = sprintf(
			/* translators: 1: formatted price e.g. "$29.00", 2: interval label e.g. "per month". */
			__( '%1$s %2$s', 'learnomy' ),
			\Learnomy\format_price( $price_monthly, $plan_currency ),
			__( 'per month', 'learnomy' )
		);
		$sr_yearly = sprintf(
			/* translators: 1: formatted price e.g. "$290.00", 2: interval label e.g. "per year". */
			__( '%1$s %2$s', 'learnomy' ),
			\Learnomy\format_price( $price_yearly, $plan_currency ),
			__( 'per year', 'learnomy' )
		);
		?>
		<span class="lrn-sr-only" data-wp-bind--hidden="state.isYearly">
			<?php echo esc_html( $sr_monthly ); ?>
		</span>
		<span class="lrn-sr-only" data-wp-bind--hidden="!state.isYearly">
			<?php echo esc_html( $sr_yearly ); ?>
		</span>
		<?php
	} else {
		// Mirror the same synonym normalization the visible price block does so
		// screen-reader users hear the correct cadence on annual plans.
		$flat_interval = isset( $plan->billing_interval ) ? (string) $plan->billing_interval : 'monthly';
		$flat_interval = strtolower( $flat_interval );
		$flat_interval = match ( $flat_interval ) {
			'annual', 'year' => 'yearly',
			'month'          => 'monthly',
			'week'           => 'weekly',
			'day'            => 'daily',
			default          => $flat_interval,
		};
		$sr_label = $sr_interval_labels[ $flat_interval ] ?? $sr_interval_labels['monthly'];
		$sr_price = sprintf(
			/* translators: 1: formatted price, 2: interval label. */
			__( '%1$s %2$s', 'learnomy' ),
			\Learnomy\format_price( $primary_amount, $plan_currency ),
			$sr_label
		);
		?>
		<span class="lrn-sr-only"><?php echo esc_html( $sr_price ); ?></span>
		<?php
	}
	?>

	<?php if ( $has_both_prices && $savings_percent > 0 ) : ?>
		<p
			class="lrn-pricing-card__trial-note"
			data-wp-bind--hidden="!state.isYearly"
		>
			<?php
			printf(
				/* translators: %d: percentage savings when paying yearly. */
				esc_html__( 'Save %d%% with yearly billing.', 'learnomy' ),
				(int) $savings_percent
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( $trial_days > 0 ) : ?>
		<p class="lrn-pricing-card__trial-note">
			<?php
			printf(
				/* translators: %d: number of free trial days. */
				esc_html( _n( 'Includes a %d-day free trial.', 'Includes a %d-day free trial.', (int) $trial_days, 'learnomy' ) ),
				(int) $trial_days
			);
			?>
		</p>
	<?php endif; ?>

	<?php
	// A membership plan is an ACCESS TIER, not a course bundle, so the card does
	// not put a per-course count on it — "1 course included" next to a plan the
	// owner describes as "Unlimited access to every course" read as a
	// self-contradiction (Basecamp 10087744843). What a plan grants is conveyed
	// by its description + features and enforced at access time; the pricing card
	// stays at the subscription level (name / price / interval / trial / CTA).
	// The bundle count is still shown where it is a course-scoped statement — the
	// membership-manage "Courses included" list — not on the sales card.
	?>

	<?php if ( $features ) : ?>
		<ul class="lrn-pricing-card__features" role="list" aria-label="<?php esc_attr_e( 'Plan features', 'learnomy' ); ?>">
			<?php foreach ( $features as $feature ) : ?>
				<li>
					<i data-lucide="check" aria-hidden="true"></i>
					<span><?php echo esc_html( (string) $feature ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php
	/**
	 * Hook inside each pricing card so Pro can inject entitlement badges
	 * (e.g. "10 AI credits / month", "Priority support") without touching
	 * the template markup.
	 *
	 * @param object $plan Full plan row.
	 */
	do_action( 'learnomy_pricing_plan_extras', $plan );
	?>

	<div class="lrn-pricing-card__cta">
		<?php if ( $is_current ) : ?>
			<span class="lrn-pricing-card__status">
				<i data-lucide="check-circle" aria-hidden="true"></i>
				<?php esc_html_e( 'Current plan', 'learnomy' ); ?>
			</span>
		<?php elseif ( ! $is_logged_in ) : ?>
			<?php
			// J-02.5 — mirror the J-01.1..J-01.6 fix (PR #131) for the
			// membership flow. Logged-out CTA used to route to wp-login.php,
			// which excludes first-time visitors because WP users_can_register
			// is OFF (Learnomy gates registration its own way). Send them to
			// /register/student/ with the checkout URL as redirect_to so the
			// register form lands them on checkout for the chosen plan after
			// account creation. Secondary "log in" link below for returning
			// customers.
			$lrn_register_url = add_query_arg(
				array( 'redirect_to' => rawurlencode( $checkout_href ) ),
				\Learnomy\route_url( 'register-student' )
			);
			$lrn_login_url    = wp_login_url( $checkout_href );
			?>
			<a
				href="<?php echo esc_url( $lrn_register_url ); ?>"
				class="lrn-btn lrn-btn--primary"
				data-lrn-subscribe-btn
			>
				<?php
				$signup_label = $trial_days > 0
					? sprintf(
						/* translators: %d: free trial days */
						__( 'Sign up for %d-day free trial', 'learnomy' ),
						(int) $trial_days
					)
					: __( 'Sign up to subscribe', 'learnomy' );
				echo esc_html( $signup_label );
				?>
			</a>
			<p class="lrn-pricing-card__login-alt">
				<?php
				printf(
					/* translators: %s: log in link */
					esc_html__( 'Already have an account? %s', 'learnomy' ),
					'<a href="' . esc_url( $lrn_login_url ) . '">' . esc_html__( 'Log in', 'learnomy' ) . '</a>'
				);
				?>
			</p>
		<?php elseif ( ! empty( $GLOBALS['lrn_viewer_has_any_sub'] ) && ! $can_change_plan ) : ?>
			<?php
			/*
			 * The viewer already has an active subscription and this build can't
			 * switch plans in-app, so on a plan they do NOT own (the $is_current
			 * branch above handles the one they do) "Manage your plan" read as if
			 * it managed THIS card's plan. Label it "Manage current plan" and link
			 * to their existing subscription — truthful, and it avoids inviting a
			 * conflicting second subscription that the free flow can't reconcile.
			 */
			?>
			<a
				href="<?php echo esc_url( \Learnomy\route_url( 'membership-manage' ) ); ?>"
				class="lrn-btn lrn-btn--ghost"
				data-lrn-manage-btn
			>
				<?php esc_html_e( 'Manage current plan', 'learnomy' ); ?>
			</a>
		<?php elseif ( ! empty( $GLOBALS['lrn_viewer_has_any_sub'] ) && $can_change_plan ) : ?>
			<a
				href="<?php echo esc_url( $checkout_href ); ?>"
				<?php if ( $has_both_prices ) : ?>
					data-wp-bind--href="state.subscribeHref"
				<?php endif; ?>
				class="lrn-btn lrn-btn--secondary"
				data-lrn-subscribe-btn
			>
				<?php esc_html_e( 'Switch to this', 'learnomy' ); ?>
			</a>
		<?php elseif ( $trial_days > 0 ) : ?>
			<a
				href="<?php echo esc_url( $checkout_href ); ?>"
				<?php if ( $has_both_prices ) : ?>
					data-wp-bind--href="state.subscribeHref"
				<?php endif; ?>
				class="lrn-btn lrn-btn--primary"
				data-lrn-subscribe-btn
			>
				<?php
				printf(
					/* translators: %d: number of free trial days. */
					esc_html__( 'Start %d-day free trial', 'learnomy' ),
					(int) $trial_days
				);
				?>
			</a>
		<?php else : ?>
			<a
				href="<?php echo esc_url( $checkout_href ); ?>"
				<?php if ( $has_both_prices ) : ?>
				data-wp-bind--href="state.subscribeHref"
				data-wp-text="state.subscribeLabel"
				<?php endif; ?>
				class="lrn-btn lrn-btn--primary"
				data-lrn-subscribe-btn
			>
				<?php
				// J-02.2 — pass the same interval-normalized period to the
				// subscribe-CTA label so annual plans don't say "/ month".
				// A dual-price card renders its MONTHLY label first, because the
				// toggle starts on monthly and `state.subscribeLabel` takes over
				// from there. Rendering the plan's stored interval instead would
				// print "$19 / year" on a card whose yearly price is $190.
				$cta_interval = $has_both_prices
					? 'monthly'
					: ( isset( $plan->billing_interval ) ? (string) $plan->billing_interval : 'monthly' );
				$cta_interval = strtolower( $cta_interval );
				$cta_interval = match ( $cta_interval ) {
					'annual', 'year' => 'yearly',
					'month'          => 'monthly',
					'week'           => 'weekly',
					'day'            => 'daily',
					default          => $cta_interval,
				};
				$cta_label = match ( $cta_interval ) {
					'yearly' => sprintf(
						/* translators: %s: formatted yearly price */
						__( 'Subscribe for %s / year', 'learnomy' ),
						\Learnomy\format_price( $primary_amount, $plan_currency )
					),
					'weekly' => sprintf(
						/* translators: %s: formatted weekly price */
						__( 'Subscribe for %s / week', 'learnomy' ),
						\Learnomy\format_price( $primary_amount, $plan_currency )
					),
					'daily'  => sprintf(
						/* translators: %s: formatted daily price */
						__( 'Subscribe for %s / day', 'learnomy' ),
						\Learnomy\format_price( $primary_amount, $plan_currency )
					),
					default  => sprintf(
						/* translators: %s: formatted monthly price */
						__( 'Subscribe for %s / month', 'learnomy' ),
						\Learnomy\format_price( $primary_amount, $plan_currency )
					),
				};
				echo esc_html( $cta_label );
	?>
			</a>
		<?php endif; ?>
		<!-- J-02.3 — Redirect notice shown by JS after subscribe click.
			Comment used to claim "Hidden by default" but the actual hidden
			attribute was missing, so this text leaked into every plan card
			for every visitor. Add the attribute. JS removes it on click. -->
		<p class="lrn-pricing-card__redirect-notice" role="status" aria-live="polite" hidden>
			<?php esc_html_e( 'Redirecting to payment. Do not close this page.', 'learnomy' ); ?>
		</p>
	</div>

</article>
