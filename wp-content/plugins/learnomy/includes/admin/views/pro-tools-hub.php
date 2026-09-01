<?php
/**
 * Pro Tools — landing page for the consolidated Pro extensions slot.
 *
 * Cards are published by Pro extensions via the `learnomy_pro_tools_cards`
 * filter; this view renders whatever the filter returns.
 *
 * @package Learnomy\Admin
 *
 * @var array<int, array{slug:string,label:string,icon:string,description:string,url:string}> $cards Provided by Admin::render_pro_tools_hub().
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap learnomy-admin lrn-pro-tools-hub">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="wrench" class="lrn-page-header__icon" aria-hidden="true"></i>
				<?php esc_html_e( 'Pro Tools', 'learnomy' ); ?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php esc_html_e( 'One place to reach every Pro extension installed on this site.', 'learnomy' ); ?>
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
	if ( empty( $cards ) ) :
		// Canonical empty state — icon + title + desc + CTA via the shared
		// partial (REPORT.md §2.6). Previously a double-class
		// `.lrn-admin-card.lrn-empty-state` wrapper with a bare `<p>`.
		$lrn_empty_state = array(
			'icon'        => 'wrench',
			'title'       => __( 'No Pro extensions are registered', 'learnomy' ),
			'description' => __( 'Activate Learnomy Pro and enable the extensions you need from the Modules page.', 'learnomy' ),
			'cta'         => array(
				'url'   => admin_url( 'admin.php?page=learnomy-modules' ),
				'label' => __( 'Open Modules', 'learnomy' ),
				'icon'  => 'toggle-right',
			),
		);
		include LEARNOMY_DIR . 'includes/admin/views/partials/empty-state.php';
	else :
		?>
		<?php
		/**
		 * Section headings for the hub, in render order.
		 *
		 * With every extension enabled this page is eleven cards, and a flat
		 * grid of eleven makes an owner read all of them to find the one they
		 * want. Grouped by WHAT A TOOL DOES — the same rule the settings
		 * sidebar follows — three headings answer "which part of my job is
		 * this" before they read a single card title.
		 *
		 * A card declares its group with a `group` key. Anything unrecognised
		 * (a third-party extension, or one added before this map) falls into
		 * `more`, so an unknown card is always rendered somewhere visible
		 * rather than silently dropped.
		 *
		 * @param array<string, string> $groups Group key => heading.
		 */
		$lrn_hub_groups = apply_filters(
			'learnomy_pro_tools_card_groups',
			array(
				'teaching'  => __( 'Teaching', 'learnomy' ),
				'progress'  => __( 'Skills & progress', 'learnomy' ),
				'standards' => __( 'Standards & import', 'learnomy' ),
				'more'      => __( 'More tools', 'learnomy' ),
			)
		);

		$lrn_grouped = array_fill_keys( array_keys( $lrn_hub_groups ), array() );
		foreach ( $cards as $lrn_card ) {
			$lrn_key = (string) ( $lrn_card['group'] ?? '' );
			if ( ! isset( $lrn_grouped[ $lrn_key ] ) ) {
				$lrn_key = 'more';
			}
			$lrn_grouped[ $lrn_key ][] = $lrn_card;
		}

		foreach ( $lrn_hub_groups as $lrn_group_key => $lrn_group_label ) :
			$lrn_group_cards = $lrn_grouped[ $lrn_group_key ];
			// Drop empty headings, exactly as the settings sidebar does: a
			// site with only one extension on should not read a heading for
			// tools it does not have.
			if ( ! $lrn_group_cards ) {
				continue;
			}
			?>
			<h2 class="lrn-admin-hub-group__title"><?php echo esc_html( $lrn_group_label ); ?></h2>
			<div class="lrn-admin-hub-grid">
				<?php foreach ( $lrn_group_cards as $card ) : ?>
					<a class="lrn-admin-hub-card" href="<?php echo esc_url( $card['url'] ); ?>">
						<span class="lrn-admin-hub-card__icon" aria-hidden="true">
							<i data-lucide="<?php echo esc_attr( $card['icon'] ); ?>"></i>
						</span>
						<span class="lrn-admin-hub-card__body">
							<span class="lrn-admin-hub-card__title"><?php echo esc_html( $card['label'] ); ?></span>
							<?php if ( '' !== $card['description'] ) : ?>
								<span class="lrn-admin-hub-card__desc"><?php echo esc_html( $card['description'] ); ?></span>
							<?php endif; ?>
						</span>
						<span class="lrn-admin-hub-card__chevron" aria-hidden="true">
							<i data-lucide="chevron-right"></i>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
			<?php
		endforeach;
		?>
	<?php endif; ?>
</div>
