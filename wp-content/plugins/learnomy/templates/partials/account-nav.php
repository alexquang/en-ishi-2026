<?php
/**
 * "My Account" vertical navigation — the learner half of the shell rail.
 *
 * Shared across every account surface (My Courses, Order history, Memberships,
 * Spaces, …) so the customer's pages stay linked — WooCommerce My Account style.
 * Renders inside the content area, so it works on the website (in the theme) and
 * inside the app WebView (chromeless) alike.
 *
 * This file is now only the ITEM SOURCE + section order. The markup lives in
 * partials/shell-nav.php, which the instructor rail renders through too — one
 * component, so a shell fix is written once instead of twice.
 *
 * Items are grouped into labelled sections ("Learning", "Account", "Teaching"),
 * then a divided group for Log out. Each item declares its section — see
 * \Learnomy\account_nav_items().
 *
 * Expects in scope:
 *   $account_nav_active (string) the current account route key (for highlight).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$account_nav_active = isset( $account_nav_active )
	? (string) $account_nav_active
	: (string) get_query_var( 'learnomy_route' );

$lrn_nav_items = \Learnomy\account_nav_items( $account_nav_active );
if ( empty( $lrn_nav_items ) ) {
	return;
}

/**
 * Filter the account-nav sections — ordered section-key => label. Drives both
 * the section ORDER and the labels; pair with the `learnomy_account_nav_section_map`
 * filter (in \Learnomy\account_nav_items) to assign items to a section. A
 * developer can relabel, reorder, or add a whole new section here.
 *
 * @param array<string,string> $sections Section key => label, in render order.
 */
$lrn_section_labels = (array) apply_filters(
	'learnomy_account_nav_sections',
	array(
		'learning' => __( 'Learning', 'learnomy' ),
		'account'  => __( 'Account', 'learnomy' ),
		'teaching' => __( 'Teaching', 'learnomy' ),
	)
);

// Translate the public key => label filter shape into the renderer's ordered
// section list. Log out carries section '' (see account_nav_items) and is
// pinned last behind a divider, so it never reads as part of a section.
$lrn_nav_sections = array();
foreach ( $lrn_section_labels as $lrn_key => $lrn_label ) {
	$lrn_nav_sections[] = array(
		'key'   => (string) $lrn_key,
		'label' => (string) $lrn_label,
	);
}
$lrn_nav_sections[] = array(
	'key'   => '',
	'label' => '',
	'sep'   => true,
);

$lrn_nav_aria_label = __( 'Account navigation', 'learnomy' );

require \Learnomy\template_path( 'partials/shell-nav.php' );
