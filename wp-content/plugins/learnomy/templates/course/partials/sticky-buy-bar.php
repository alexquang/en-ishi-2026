<?php
/**
 * Single course — mobile sticky buy bar (shared partial).
 *
 * On phones the purchase card sits at the very bottom of the single-course
 * page (after curriculum, instructor, reviews — ~4000px down), so a visitor
 * has no visible price or CTA without scrolling to the end. This renders a
 * compact bar pinned to the bottom of the viewport on mobile only (hidden on
 * desktop via CSS) carrying the price + the one primary action, mirroring the
 * enroll card's branch logic. The course author/admin sees nothing here (they
 * manage, not buy).
 *
 * Inherits the single-course scope (same vars as enroll-card.php).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// The decision lives in one place now: Course::cta_for_viewer(). This bar used
// to re-derive it from the same predicates the enroll card uses, and the two
// were kept in step by a comment asking the next reader to remember. Whatever
// the card offers, this bar offers - by construction rather than by discipline.
$lrn_cta = \Learnomy\Models\Course::cta_for_viewer(
	$course,
	get_current_user_id(),
	array(
		'has_access'         => ! empty( $has_access ),
		'member_can_claim'   => ! empty( $member_can_claim ),
		'is_owner'           => ! empty( $is_owner ),
		'is_course_complete' => ! empty( $is_course_complete ),
		'progress_pct'       => isset( $enrollment->progress_pct ) ? (int) $enrollment->progress_pct : 0,
		'continue_url'       => (string) ( $continue_url ?? '' ),
		'enroll_url'         => (string) ( $enroll_url ?? '' ),
	)
);

$lrn_bar_label = (string) $lrn_cta['label'];
$lrn_bar_url   = (string) $lrn_cta['url'];
$lrn_bar_price = '';

// Price label. Only the offer states carry one - a learner who already has
// access is not being sold anything, and "external" advertises that the sale
// happens elsewhere.
if ( 'external' === $lrn_cta['kind'] ) {
	$lrn_bar_price = __( 'Sold externally', 'learnomy' );
} elseif ( in_array( $lrn_cta['kind'], array( 'signup_join', 'signup_buy', 'signup_enroll', 'claim', 'members_join', 'buy', 'enroll' ), true ) ) {
	// The interval suffix is part of the price on a recurring course, not
	// decoration: a bare "$19.00" here reads as a one-time $19, which is the
	// wrong promise this bar exists to avoid - and this is the MOBILE-primary
	// surface, so it is the copy most buyers actually see.
	$lrn_bar_suffix = ! empty( $price_suffix ) ? (string) $price_suffix : '';

	if ( \Learnomy\Models\Course::is_members_only( $course ) ) {
		$lrn_bar_price = __( 'Members only', 'learnomy' );
	} elseif ( \Learnomy\Models\Course::is_free( $course ) || (float) ( $original_price ?? 0 ) <= 0 ) {
		$lrn_bar_price = __( 'Free', 'learnomy' );
	} elseif ( ! empty( $has_sale ) ) {
		$lrn_bar_price = \Learnomy\format_price( (float) $sale_price ) . $lrn_bar_suffix;
	} else {
		$lrn_bar_price = \Learnomy\format_price( (float) $original_price ) . $lrn_bar_suffix;
	}
}

if ( '' === $lrn_bar_url || '' === $lrn_bar_label ) {
	return;
}
?>
<div class="lrn-sticky-buy-bar" aria-hidden="false">
	<?php if ( '' !== $lrn_bar_price ) : ?>
		<span class="lrn-sticky-buy-bar__price"><?php echo esc_html( $lrn_bar_price ); ?></span>
	<?php endif; ?>
	<a href="<?php echo esc_url( $lrn_bar_url ); ?>" class="lrn-btn lrn-btn--primary lrn-sticky-buy-bar__cta">
		<?php echo esc_html( $lrn_bar_label ); ?>
	</a>
</div>
