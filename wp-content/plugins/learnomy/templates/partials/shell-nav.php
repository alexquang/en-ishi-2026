<?php
/**
 * The shell rail — ONE renderer for every left-panel surface.
 *
 * Both the learner account family (/account/…) and the instructor teaching
 * family (/instructor/…) render through this partial. They differ only in
 * where their items come from — \Learnomy\account_nav_items() and
 * \Learnomy\instructor_nav_items() — never in how those items are drawn.
 *
 * Before this existed the two were separate implementations sharing only a CSS
 * class, so every shell fix had to be written twice and drifted the first time
 * either side changed. The item SOURCES stay separate (they answer different
 * questions); only the renderer is shared.
 *
 * Expects in scope:
 *   $lrn_nav_items      (array)  Items: key, label, icon, url, current, section.
 *   $lrn_nav_sections   (array)  Ordered sections, each: key, label, sep (bool).
 *                                An empty label prints no heading; sep draws a
 *                                divider before the group. Items whose section
 *                                is unknown fall into the first section, so a
 *                                new item is never dropped.
 *   $lrn_nav_aria_label (string) Accessible name for the <nav>.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $lrn_nav_items ) || ! is_array( $lrn_nav_items ) ) {
	return;
}

$lrn_nav_sections = ( isset( $lrn_nav_sections ) && is_array( $lrn_nav_sections ) && $lrn_nav_sections )
	? $lrn_nav_sections
	: array(
		array(
			'key'   => '',
			'label' => '',
		),
	);

$lrn_nav_aria_label = isset( $lrn_nav_aria_label )
	? (string) $lrn_nav_aria_label
	: __( 'Section navigation', 'learnomy' );

// Bucket the items by section, in the sections' declared order. Keyed lookup
// rather than a nested loop so item count never multiplies section count.
$lrn_buckets  = array();
$lrn_fallback = (string) ( $lrn_nav_sections[0]['key'] ?? '' );
foreach ( $lrn_nav_sections as $lrn_section ) {
	$lrn_buckets[ (string) ( $lrn_section['key'] ?? '' ) ] = array();
}
$lrn_current = null;
foreach ( $lrn_nav_items as $lrn_item ) {
	$lrn_key = (string) ( $lrn_item['section'] ?? $lrn_fallback );
	if ( ! isset( $lrn_buckets[ $lrn_key ] ) ) {
		$lrn_key = $lrn_fallback;
	}
	$lrn_buckets[ $lrn_key ][] = $lrn_item;

	if ( null === $lrn_current && ! empty( $lrn_item['current'] ) ) {
		$lrn_current = $lrn_item;
	}
}

// Below the rail breakpoint the panel collapses behind a disclosure whose
// trigger names the destination you are on. The scroller it replaces lost that
// the moment you swiped, and hid most of itself with no affordance (9 of 18
// items off-screen at 390px). <details> gives the semantics, the keyboard
// behaviour and the open/close state for free — with no script, the panel is
// simply expandable, so every destination stays reachable if JS never runs.
// OPEN BY DEFAULT, deliberately. A closed <details> does not merely hide its
// content - Chrome and Safari skip PAINTING it via content-visibility on
// ::details-content, so the rail computed a full 255x1006 box and still
// rendered as a blank column at desktop width. Overriding that needs
// ::details-content, which older engines do not support, and there the nav
// would silently disappear entirely.
//
// So the markup ships expanded and the script collapses it below the rail
// breakpoint. If the script never runs, the worst case is an expanded list on a
// phone - every destination still reachable. The failure mode is a longer page,
// never an invisible one.
$lrn_current = $lrn_current ?: ( $lrn_nav_items[0] ?? array() );
?>
<details class="lrn-shell-nav" open>
	<summary class="lrn-shell-nav__trigger">
		<?php if ( ! empty( $lrn_current['icon'] ) ) : ?>
			<i data-lucide="<?php echo esc_attr( (string) $lrn_current['icon'] ); ?>" aria-hidden="true"></i>
		<?php endif; ?>
		<span class="lrn-shell-nav__trigger-label"><?php echo esc_html( (string) ( $lrn_current['label'] ?? '' ) ); ?></span>
		<i data-lucide="chevron-down" class="lrn-shell-nav__chevron" aria-hidden="true"></i>
	</summary>
<nav class="lrn-account-nav" aria-label="<?php echo esc_attr( $lrn_nav_aria_label ); ?>">
	<ul class="lrn-account-nav__list" role="list">
		<?php
		foreach ( $lrn_nav_sections as $lrn_section ) {
			$lrn_section_key   = (string) ( $lrn_section['key'] ?? '' );
			$lrn_section_items = $lrn_buckets[ $lrn_section_key ] ?? array();
			if ( empty( $lrn_section_items ) ) {
				continue;
			}

			if ( ! empty( $lrn_section['sep'] ) ) {
				echo '<li class="lrn-account-nav__sep" role="presentation"></li>';
			}

			$lrn_section_label = (string) ( $lrn_section['label'] ?? '' );
			if ( '' !== $lrn_section_label ) {
				printf(
					'<li class="lrn-account-nav__section-label" role="presentation">%s</li>',
					esc_html( $lrn_section_label )
				);
			}

			foreach ( $lrn_section_items as $lrn_item ) {
				$lrn_is_active = ! empty( $lrn_item['current'] );
				?>
				<li class="lrn-account-nav__item">
					<a
						class="lrn-account-nav__link<?php echo $lrn_is_active ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( (string) ( $lrn_item['url'] ?? '#' ) ); ?>"
						<?php echo $lrn_is_active ? 'aria-current="page"' : ''; ?>
					>
						<?php if ( ! empty( $lrn_item['icon'] ) ) : ?>
							<i data-lucide="<?php echo esc_attr( (string) $lrn_item['icon'] ); ?>" aria-hidden="true"></i>
						<?php endif; ?>
						<span class="lrn-account-nav__label"><?php echo esc_html( (string) ( $lrn_item['label'] ?? '' ) ); ?></span>
					</a>
				</li>
				<?php
			}
		}
		?>
	</ul>
</nav>
</details>
