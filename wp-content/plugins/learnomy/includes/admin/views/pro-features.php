<?php
/**
 * Pro Features admin page — informational catalog of every Learnomy module
 * (Free + Pro), shown side-by-side with clear FREE / PRO tier pills.
 *
 * The page intentionally has zero external CTAs (no Buy / Download / Docs
 * links) because Pro is still in development. Plan-09 will add those at
 * launch. See docs/plans/08-pro-promotion.md.
 *
 * @package Learnomy\Admin
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Admin\Pro_Catalog;

$catalog       = Pro_Catalog::unified_catalog();
$free_features = Pro_Catalog::free_modules();
$pro_features  = Pro_Catalog::get_all();
$labels        = Pro_Catalog::category_labels();
$active_slugs  = Pro_Catalog::active_slugs_on_site();
$total_free    = count( $free_features );
$total_pro     = count( $pro_features );

$status_pill = static function ( string $status ): array {
	switch ( $status ) {
		case 'stable':
			return array(
				'class' => 'lrn-pro-pill--stable',
				'label' => __( 'Stable', 'learnomy' ),
			);
		case 'beta':
			return array(
				'class' => 'lrn-pro-pill--beta',
				'label' => __( 'Beta', 'learnomy' ),
			);
		case 'alpha':
			return array(
				'class' => 'lrn-pro-pill--alpha',
				'label' => __( 'Alpha', 'learnomy' ),
			);
		default:
			// Unknown maturity slug — humanise as a last resort (known
			// statuses stable/beta/alpha are translated above).
			return array(
				'class' => 'lrn-pro-pill--neutral',
				'label' => ucfirst( $status ),
			);
	}
};
?>
<div class="wrap learnomy-admin lrn-pro-features-page">

	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="package" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php
				// Heading = this page's own sidebar label, so a rename moves both
				// (Basecamp 10184393141). The fallback MATCHES that label rather
				// than restating the old heading: it only applies when the page has
				// no sidebar row, and a stale literal here made source reads report
				// the bug as unfixed even though the rendered H1 was correct.
				echo esc_html( \Learnomy\Admin\UX::page_title( __( 'What\'s in Pro', 'learnomy' ) ) );
				?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php
				printf(
					/* translators: 1: number of Free modules, 2: number of Pro modules */
					esc_html__( 'Learnomy Free includes %1$d modules. Learnomy Pro provides %2$d additional modules. Pro is in active development. Release details will be announced separately.', 'learnomy' ),
					(int) $total_free,
					(int) $total_pro
				);
				?>
			</p>
		</div>
	</div>

	<hr class="wp-header-end">

	<?php
	/*
	 * The two count tiles below use the canonical `.lrn-admin-stat-card`
	 * pattern (same shape used by Dashboard, Earnings, and Analytics) so
	 * stat tiles read identically across every admin page. The
	 * `--accent-free` / `--accent-pro` modifiers carry the tier accent
	 * (green border-start for Free, gold for Pro) without inventing a
	 * new tile shape — see plan/2026-05-23-admin-ux-audit/REPORT.md §2.4.
	 *
	 * The third item is a tier LEGEND (stable + beta pills + a note),
	 * which is not a stat tile, so it stays a plain `.lrn-admin-card`
	 * and just shares the same `.lrn-admin-stats` grid for layout.
	 */
	?>
	<div class="lrn-admin-stats">
		<div class="lrn-admin-stat-card lrn-admin-stat-card--accent-free">
			<div class="lrn-admin-stat-card__icon" aria-hidden="true">
				<i data-lucide="package-check"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( (string) $total_free ); ?></span>
				<span class="lrn-admin-stat-card__label">
					<?php esc_html_e( 'Included modules', 'learnomy' ); ?>
					<span class="lrn-pro-pill lrn-pro-pill--tier-free"><?php esc_html_e( 'Free', 'learnomy' ); ?></span>
				</span>
			</div>
		</div>
		<div class="lrn-admin-stat-card lrn-admin-stat-card--accent-pro">
			<div class="lrn-admin-stat-card__icon" aria-hidden="true">
				<i data-lucide="sparkles"></i>
			</div>
			<div class="lrn-admin-stat-card__body">
				<span class="lrn-admin-stat-card__number"><?php echo esc_html( (string) $total_pro ); ?></span>
				<span class="lrn-admin-stat-card__label">
					<?php esc_html_e( 'Additional modules', 'learnomy' ); ?>
					<span class="lrn-pro-pill lrn-pro-pill--tier-pro"><?php esc_html_e( 'Pro', 'learnomy' ); ?></span>
				</span>
			</div>
		</div>
		<div class="lrn-admin-card lrn-tier-legend">
			<div class="lrn-tier-legend__pills">
				<span class="lrn-pro-pill lrn-pro-pill--stable"><?php esc_html_e( 'Stable', 'learnomy' ); ?></span>
				<span class="lrn-pro-pill lrn-pro-pill--beta"><?php esc_html_e( 'Beta', 'learnomy' ); ?></span>
			</div>
			<p class="lrn-tier-legend__note">
				<?php esc_html_e( 'Beta modules are functional. Their interfaces may change before Pro 1.0.', 'learnomy' ); ?>
			</p>
		</div>
	</div>

	<div class="lrn-pro-filters" role="tablist" aria-label="<?php esc_attr_e( 'Filter modules by tier', 'learnomy' ); ?>">
		<button type="button" class="lrn-pro-filter is-active" data-filter="all" role="tab" aria-selected="true">
			<?php esc_html_e( 'All modules', 'learnomy' ); ?>
			<span class="lrn-pro-filter__count"><?php echo esc_html( (string) ( $total_free + $total_pro ) ); ?></span>
		</button>
		<button type="button" class="lrn-pro-filter" data-filter="free" role="tab" aria-selected="false">
			<?php esc_html_e( 'Free only', 'learnomy' ); ?>
			<span class="lrn-pro-filter__count"><?php echo esc_html( (string) $total_free ); ?></span>
		</button>
		<button type="button" class="lrn-pro-filter" data-filter="pro" role="tab" aria-selected="false">
			<?php esc_html_e( 'Pro only', 'learnomy' ); ?>
			<span class="lrn-pro-filter__count"><?php echo esc_html( (string) $total_pro ); ?></span>
		</button>
	</div>

	<?php
	/**
	 * Fires before the catalog grid on the Pro Features page.
	 */
	do_action( 'learnomy_admin_pro_features_before_categories' );
	?>

	<?php if ( empty( $catalog ) ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e( 'Module catalog is empty. Run "php bin/sync-pro-catalog.php" to regenerate the bundled snapshot.', 'learnomy' ); ?>
			</p>
		</div>
	<?php else : ?>

		<div class="lrn-pro-grid">
			<?php foreach ( $catalog as $feature ) : ?>
				<?php
				$tier          = (string) ( $feature['tier'] ?? 'free' );
				$is_pro        = 'pro' === $tier;
				$pill          = $status_pill( (string) ( $feature['status'] ?? 'stable' ) );
				$is_active     = $is_pro && in_array( $feature['slug'], $active_slugs, true );
				$stories       = (array) ( $feature['user_stories'] ?? array() );
				$story_count   = count( $stories );
				$is_required   = ! empty( $feature['required'] );
				$category_slug = (string) ( $feature['category'] ?? 'operations' );
				$category_name = $labels[ $category_slug ] ?? ucfirst( str_replace( '-', ' ', $category_slug ) );
				$tier_pill     = $is_pro ? 'lrn-pro-pill--tier-pro' : 'lrn-pro-pill--tier-free';
				$tier_label    = $is_pro ? __( 'Pro', 'learnomy' ) : __( 'Free', 'learnomy' );
				?>
				<article
					class="lrn-pro-card lrn-pro-card--<?php echo esc_attr( $tier ); ?><?php echo $is_active ? ' lrn-pro-card--active' : ''; ?>"
					data-tier="<?php echo esc_attr( $tier ); ?>"
				>
					<header class="lrn-pro-card__head">
						<h3 class="lrn-pro-card__title"><?php echo esc_html( $feature['name'] ); ?></h3>
						<span class="lrn-pro-pill <?php echo esc_attr( $tier_pill ); ?>">
							<?php echo esc_html( $tier_label ); ?>
						</span>
					</header>

					<div class="lrn-pro-card__meta">
						<span class="lrn-pro-pill lrn-pro-pill--category lrn-pro-pill--cat-<?php echo esc_attr( $category_slug ); ?>">
							<?php echo esc_html( $category_name ); ?>
						</span>
						<?php if ( $is_pro ) : ?>
							<span class="lrn-pro-pill <?php echo esc_attr( $pill['class'] ); ?>">
								<?php echo esc_html( $pill['label'] ); ?>
							</span>
						<?php endif; ?>
						<?php if ( $is_required ) : ?>
							<span class="lrn-pro-pill lrn-pro-pill--required">
								<?php esc_html_e( 'Required', 'learnomy' ); ?>
							</span>
						<?php endif; ?>
					</div>

					<p class="lrn-pro-card__desc">
						<?php echo esc_html( $feature['description'] ); ?>
					</p>

					<?php if ( $story_count > 0 ) : ?>
						<ul class="lrn-pro-card__stories">
							<?php foreach ( array_slice( $stories, 0, 4 ) as $story ) : ?>
								<li><?php echo esc_html( $story ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<footer class="lrn-pro-card__foot">
						<?php
						/*
						 * THREE states, not two.
						 *
						 * "Not active" used to render as "Available with Learnomy Pro"
						 * regardless of whether Pro was installed. So an owner running
						 * Pro saw ten of the twenty-three modules telling them to buy
						 * the thing they had already bought, on the page whose whole job
						 * is explaining what they get. The honest reading from their
						 * chair is "my licence must not cover these" or "there is a
						 * second Pro" (Basecamp 10171338928 / 10171341093, filed as
						 * Gradebook and Cohorts being unreachable).
						 *
						 * They were never unreachable. Both switch on from the Modules
						 * screen in one click. Nothing pointed there, because the card
						 * was selling instead of telling.
						 *
						 * Pro_Catalog::pro_plugin_active() already exists for exactly
						 * this and its docblock says so: the Modules page uses it so the
						 * operator "never sees 'consider Pro' copy on a site that already
						 * has Pro installed". This page simply never asked.
						 */
						?>
						<?php if ( $is_pro ) : ?>
							<?php if ( $is_active ) : ?>
								<span class="lrn-pro-card__tag lrn-pro-card__tag--active">
									<?php esc_html_e( 'Active on this site', 'learnomy' ); ?>
								</span>
							<?php elseif ( Pro_Catalog::pro_plugin_active() ) : ?>
								<a class="lrn-pro-card__tag lrn-pro-card__tag--off"
									href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-modules' ) ); ?>">
									<?php esc_html_e( 'Off. Turn it on in Modules', 'learnomy' ); ?>
								</a>
							<?php else : ?>
								<span class="lrn-pro-card__tag">
									<?php esc_html_e( 'Available with Learnomy Pro', 'learnomy' ); ?>
								</span>
							<?php endif; ?>
						<?php else : ?>
							<span class="lrn-pro-card__tag lrn-pro-card__tag--free">
								<?php esc_html_e( 'Included in Learnomy Free', 'learnomy' ); ?>
							</span>
						<?php endif; ?>
					</footer>
				</article>
			<?php endforeach; ?>
		</div>

	<?php endif; ?>

	<?php do_action( 'learnomy_admin_pro_features_after_categories' ); ?>

</div>

