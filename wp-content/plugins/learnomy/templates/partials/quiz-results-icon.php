<?php
/**
 * Quiz results pass/fail icon.
 *
 * BOTH icons are rendered, with a literal `data-lucide`, and visibility is
 * bound on the wrapping <span>. That shape is load-bearing, not a style
 * choice: `lucide.createIcons()` REPLACES an `<i data-lucide>` with an
 * `<svg>`, which destroys the node the Interactivity runtime is holding a
 * reference to. A `data-wp-bind--data-lucide` on that `<i>` is therefore a
 * ONE-SHOT binding — it paints whatever the state said at hydration and can
 * never repaint.
 *
 * That is exactly how a student who PASSED was shown a red X icon tinted
 * green by the pass class: at hydration `state.result` is null, so the bound
 * name resolved to `x-circle`, lucide swapped the `<i>` for an svg, and the
 * later update to `check-circle` went to a detached node. The sibling
 * `data-wp-class` binding on the wrapper kept working throughout — proving
 * the wrapper survives and only the converted `<i>` is lost — which is why
 * the icon was the wrong glyph in the right colour (Basecamp 10171508068).
 *
 * The rule this encodes: never bind an attribute on an element another
 * script replaces. Bind on a node you own, and let the icon be static.
 *
 * Five quiz templates rendered these three lines independently; they share
 * this partial now so the next person fixing an icon cannot fix four of them.
 *
 * @var string $lrn_results_icon_class Wrapper block class for this template.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_results_icon_class = isset( $lrn_results_icon_class ) && '' !== $lrn_results_icon_class
	? $lrn_results_icon_class
	: 'lrn-quiz__results-icon';
?>
<div class="<?php echo esc_attr( $lrn_results_icon_class ); ?>"
	data-wp-class--<?php echo esc_attr( $lrn_results_icon_class ); ?>--pass="state.hasPassed">
	<span data-wp-bind--hidden="!state.hasPassed"><i data-lucide="check-circle" aria-hidden="true"></i></span>
	<span data-wp-bind--hidden="state.hasPassed"><i data-lucide="x-circle" aria-hidden="true"></i></span>
</div>
