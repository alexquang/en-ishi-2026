<?php
/**
 * Instructor area vertical navigation — the teaching half of the shell rail.
 *
 * Driven by \Learnomy\instructor_nav_items(); rendered by
 * partials/shell-nav.php, the same component the learner account rail uses.
 * Previously this was a second implementation of that markup sharing only a CSS
 * class, which meant every shell change had to be made twice.
 *
 * The teaching rail is a flat list today: its items all sit in one unlabelled
 * group, with the "way back to the learner area" item split off behind a
 * divider. That is expressed as two sections with empty labels, so it renders
 * exactly as before while going through the shared renderer.
 *
 * Expects in scope:
 *   $instructor_nav_active (string) current instructor route key (for highlight).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$instructor_nav_active = isset( $instructor_nav_active )
	? (string) $instructor_nav_active
	: (string) get_query_var( 'learnomy_route' );

$lrn_nav_items = \Learnomy\instructor_nav_items( $instructor_nav_active );
if ( empty( $lrn_nav_items ) ) {
	return;
}

// The `back` flag is the item source's way of saying "this one leads out of the
// teaching area". Translate it into a section so the renderer needs no special
// case — a divider before a group is something any rail can ask for.
foreach ( $lrn_nav_items as $lrn_i => $lrn_item ) {
	$lrn_nav_items[ $lrn_i ]['section'] = empty( $lrn_item['back'] ) ? 'teaching' : 'back';
}

// Both labels are empty: the teaching rail draws no section headings today.
$lrn_nav_sections = array(
	array(
		'key'   => 'teaching',
		'label' => '',
	),
	array(
		'key'   => 'back',
		'label' => '',
		'sep'   => true,
	),
);

$lrn_nav_aria_label = sprintf( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s navigation', 'learnomy' ), \Learnomy\instructor_label() );

require \Learnomy\template_path( 'partials/shell-nav.php' );
