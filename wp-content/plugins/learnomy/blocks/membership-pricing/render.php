<?php
/**
 * Render: learnomy/membership-pricing
 *
 * @package Learnomy
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\MembershipPlan;

ob_start();

// No $currency_symbol here: format_price() below resolves the symbol together
// with the per-currency decimals and the locale-aware placement. Holding a
// separate symbol invites the "$symbol . number_format()" pattern back.
$plans = MembershipPlan::get_active();

if ( empty( $plans ) ) {
	?>
	<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
	<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-membership-pricing' ) ); ?>>
		<p class="lrn-empty-state"><?php esc_html_e( 'No membership plans are currently available.', 'learnomy' ); ?></p>
	</div>
	<?php
	\Learnomy\Blocks::render_filtered( 'membership-pricing', ob_get_clean(), $attributes );
	return;
}
?>
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-membership-pricing lrn-container' ) ); ?>
	data-wp-interactive="learnomy/membership">
	<div class="lrn-grid lrn-grid--cols-<?php echo esc_attr( min( count( $plans ), 3 ) ); ?>">
		<?php foreach ( $plans as $plan ) : ?>
			<?php
			$name        = esc_html( $plan->title ?? '' );
			$slug        = esc_attr( $plan->slug ?? '' );
			$description = wp_kses_post( $plan->description ?? '' );
			// format_price() resolves symbol + per-currency decimals + locale
			// placement together. Hand-building "$symbol . number_format()" here
			// meant this block rendered "$29.00" while the /membership/ route
			// rendered "29,00 $" for the SAME plan in de_DE — two surfaces
			// disagreeing on one price. Pass the plan's own currency so a
			// non-site-currency plan does not borrow the site glyph.
			$price        = \Learnomy\format_price( (float) ( $plan->price ?? 0 ), $plan->currency ?? null );
			$period       = esc_html( $plan->billing_interval ?? 'month' );
			$features_raw = $plan->features_json ?? '[]';
			$features     = json_decode( $features_raw, true );
			$features     = is_array( $features ) ? $features : array();
			$is_featured  = ! empty( $plan->is_featured );
			$card_classes = 'lrn-card lrn-membership-pricing__plan' . ( $is_featured ? ' lrn-membership-pricing__plan--featured' : '' );
			?>
			<div class="<?php echo esc_attr( $card_classes ); ?>">
				<?php if ( $is_featured ) : ?>
					<div class="lrn-membership-pricing__badge">
						<span class="lrn-badge"><?php esc_html_e( 'Most Popular', 'learnomy' ); ?></span>
					</div>
				<?php endif; ?>
				<h3 class="lrn-membership-pricing__name"><?php echo esc_html( $name ); ?></h3>
				<div class="lrn-membership-pricing__price">
					<span class="lrn-membership-pricing__amount"><?php echo esc_html( $price ); ?></span>
					<span class="lrn-membership-pricing__period">
						<?php
						/* translators: %s: billing period (month/year) */
						echo esc_html( sprintf( __( '/ %s', 'learnomy' ), $period ) );
						?>
					</span>
				</div>
				<?php if ( $description ) : ?>
					<div class="lrn-membership-pricing__description">
						<?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $features ) ) : ?>
					<ul class="lrn-membership-pricing__features">
						<?php foreach ( $features as $feature ) : ?>
							<li class="lrn-membership-pricing__feature"><?php echo esc_html( $feature ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<div class="lrn-membership-pricing__actions">
					<?php
					// Build checkout URL the same way the plan-card partial does:
					// route_url('enroll') + plan_id query arg. The old code appended
					// the plan slug directly to the membership-pricing archive URL,
					// producing /membership/<slug> which is not a registered route (404).
					$plan_id_int = isset( $plan->id ) ? absint( $plan->id ) : 0;
					// Derive the billing cycle from the plan so an annual plan links
					// to annual billing, not a hardcoded 'monthly' that contradicts
					// the price shown on the very same card. One canonical reader.
					$plan_billing  = 'yearly' === \Learnomy\Services\Subscription_Service::normalize_interval( (string) ( $plan->billing_interval ?? 'monthly' ) ) ? 'yearly' : 'monthly';
					$plan_checkout = add_query_arg(
						array(
							'plan_id' => $plan_id_int,
							'billing' => $plan_billing,
						),
						\Learnomy\route_url( 'enroll' )
					);
					?>
					<?php if ( is_user_logged_in() ) : ?>
						<a
							href="<?php echo esc_url( $plan_checkout ); ?>"
							class="lrn-btn lrn-btn--primary lrn-membership-pricing__cta"
							data-plan-slug="<?php echo esc_attr( $slug ); ?>">
							<?php esc_html_e( 'Get Started', 'learnomy' ); ?>
						</a>
					<?php else : ?>
						<a href="<?php echo esc_url( wp_login_url( $plan_checkout ) ); ?>" class="lrn-btn lrn-btn--secondary">
							<?php esc_html_e( 'Log in to Join', 'learnomy' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</div>
<?php
\Learnomy\Blocks::render_filtered( 'membership-pricing', ob_get_clean(), $attributes );
