<?php
/**
 * Learnomy Commerce — landing page for the second top-level menu.
 *
 * Lists every commerce sub-page registered for the current user as a card
 * grid so the operator can scan money flow at a glance.
 *
 * @package Learnomy\Admin
 *
 * @var array<int, array{slug:string,label:string,url:string}> $cards Provided by Admin::render_commerce_overview().
 */

defined( 'ABSPATH' ) || exit;

// Per-slug icon map. Slugs not in the map fall back to a neutral icon so the
// grid never renders without a glyph. NOTE: `learnomy-transactions` was
// previously missing from both maps, so the card rendered with a generic
// 'square' icon and no description — caught in the 2026-05-23 admin UX
// audit (REPORT.md §3, Commerce Overview row).
$icon_map = array(
	'learnomy-memberships'             => 'badge-check',
	'learnomy-subscriptions'           => 'repeat',
	'learnomy-coupons'                 => 'ticket-percent',
	'learnomy-earnings'                => 'banknote',
	'learnomy-transactions'            => 'receipt',
	'learnomy-refundable-transactions' => 'rotate-ccw',
	'learnomy-space-plans'             => 'package',
	'learnomy-spaces'                  => 'building-2',
	'learnomy-membership-analytics'    => 'line-chart',
	'learnomy-stripe-payouts'          => 'credit-card',
);

// Per-slug one-line description. Pro extensions can add their own via the
// `learnomy_commerce_card_descriptions` filter without changing this file.
$descriptions = apply_filters(
	'learnomy_commerce_card_descriptions',
	array(
		'learnomy-memberships'          => __( 'Plans, pricing, and access tiers.', 'learnomy' ),
		'learnomy-subscriptions'        => __( 'Active recurring memberships and renewals.', 'learnomy' ),
		'learnomy-coupons'              => __( 'Discount codes and promotional offers.', 'learnomy' ),
		'learnomy-earnings'             => __( 'Revenue, commissions, and instructor payouts.', 'learnomy' ),
		'learnomy-transactions'         => __( 'Every charge, refund, and chargeback in one ledger.', 'learnomy' ),
		'learnomy-membership-analytics' => __( 'Conversion, retention, and MRR trends.', 'learnomy' ),
		'learnomy-stripe-payouts'       => __( 'Stripe Connect payout schedule and history.', 'learnomy' ),
	)
);
?>
<div class="wrap learnomy-admin lrn-commerce-overview">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="shopping-cart" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php
				// The sidebar row is "Overview" under LMS Commerce; the H1 said
				// "Commerce", matching neither the row nor its parent.
				echo esc_html( \Learnomy\Admin\UX::page_title( __( 'Overview', 'learnomy' ) ) );
				?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php esc_html_e( 'Money flow at a glance. Pick a sub-page to drill in.', 'learnomy' ); ?>
			</p>
		</div>
	</div>

	<?php
	// `wp-header-end` marker — admin notices render below this line.
	// Without it, third-party plugin notices stack at the top of the
	// page and overlap the page header (round 3 audit-rule-8).
	?>
	<hr class="wp-header-end">

	<?php
	/*
	 * KPI row — the money-flow snapshot the page-header subtitle promises.
	 * Before 2026-05-23 (admin UX audit) this page was nav cards only and
	 * the operator had to click into a sub-page just to see "is there
	 * any money?". Now the four KPIs sit above the nav grid: MRR + active
	 * subscribers (Subscription::get_mrr_stats) and lifetime revenue +
	 * lifetime transactions (Transaction::total_completed_revenue +
	 * get_revenue_summary). Uses the canonical `.lrn-admin-stat-card`
	 * pattern so Commerce Overview reads in the same KPI shape as
	 * Dashboard / Earnings / Pro Analytics.
	 */
	if ( ! empty( $commerce_kpis ) ) :
		?>
		<div class="lrn-admin-stats">
			<?php foreach ( $commerce_kpis as $kpi ) : ?>
				<div class="lrn-admin-stat-card">
					<div class="lrn-admin-stat-card__icon" aria-hidden="true">
						<i data-lucide="<?php echo esc_attr( $kpi['icon'] ); ?>"></i>
					</div>
					<div class="lrn-admin-stat-card__body">
						<span class="lrn-admin-stat-card__number"><?php echo esc_html( $kpi['value'] ); ?></span>
						<span class="lrn-admin-stat-card__label"><?php echo esc_html( $kpi['label'] ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	endif;
	?>

	<?php
	/*
	 * Three-jobs routing (Basecamp 10132713132) - the owner's jobs in the
	 * owner's words, each landing on the screen that does the work. Sits
	 * between the KPIs and the feature-named nav cards so a new owner reads
	 * "what do you want to do" before "what do we call it". Rendered even
	 * when $cards is empty: with no commerce module on, this strip IS the
	 * routing the empty state cannot give.
	 */
	if ( ! empty( $commerce_jobs ) ) :
		?>
		<h2 class="lrn-commerce-jobs__title"><?php esc_html_e( 'What do you want to sell?', 'learnomy' ); ?></h2>
		<div class="lrn-admin-hub-grid lrn-commerce-jobs">
			<?php foreach ( $commerce_jobs as $lrn_job ) : ?>
				<a class="lrn-admin-hub-card" href="<?php echo esc_url( $lrn_job['url'] ); ?>">
					<span class="lrn-admin-hub-card__icon" aria-hidden="true">
						<i data-lucide="<?php echo esc_attr( $lrn_job['icon'] ); ?>"></i>
					</span>
					<span class="lrn-admin-hub-card__body">
						<span class="lrn-admin-hub-card__title"><?php echo esc_html( $lrn_job['label'] ); ?></span>
						<span class="lrn-admin-hub-card__desc"><?php echo esc_html( $lrn_job['hint'] ); ?></span>
					</span>
					<span class="lrn-admin-hub-card__chevron" aria-hidden="true">
						<i data-lucide="chevron-right"></i>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	endif;
	?>

	<?php
	if ( empty( $cards ) ) :
		// Canonical empty state — icon + title + desc + CTA via the shared
		// partial (REPORT.md §2.6). Previously this was a double-class
		// `.lrn-admin-card.lrn-empty-state` wrapper with a bare `<p>`,
		// which reused the empty-state container chrome without any of
		// the empty-state INTERNALS (icon, title, structured CTA).
		$lrn_empty_state = array(
			'icon'        => 'shopping-cart',
			'title'       => __( 'No commerce modules are enabled', 'learnomy' ),
			'description' => __( 'Turn on Memberships, Subscriptions, Coupons, or other commerce modules to start seeing money flow here.', 'learnomy' ),
			'cta'         => array(
				'url'   => admin_url( 'admin.php?page=learnomy-modules' ),
				'label' => __( 'Open Modules', 'learnomy' ),
				'icon'  => 'toggle-right',
			),
		);
		include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
	else :
		?>
		<div class="lrn-admin-hub-grid">
			<?php foreach ( $cards as $card ) : ?>
				<?php
				$icon        = $icon_map[ $card['slug'] ] ?? 'square';
				$description = $descriptions[ $card['slug'] ] ?? '';
				?>
				<a class="lrn-admin-hub-card" href="<?php echo esc_url( $card['url'] ); ?>">
					<span class="lrn-admin-hub-card__icon" aria-hidden="true">
						<i data-lucide="<?php echo esc_attr( $icon ); ?>"></i>
					</span>
					<span class="lrn-admin-hub-card__body">
						<span class="lrn-admin-hub-card__title"><?php echo esc_html( $card['label'] ); ?></span>
						<?php if ( '' !== $description ) : ?>
							<span class="lrn-admin-hub-card__desc"><?php echo esc_html( $description ); ?></span>
						<?php endif; ?>
					</span>
					<span class="lrn-admin-hub-card__chevron" aria-hidden="true">
						<i data-lucide="chevron-right"></i>
					</span>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
