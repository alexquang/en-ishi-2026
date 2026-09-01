<?php
/**
 * Review filter chips — All / Incorrect / Correct / Pending.
 *
 * At exam scale the student's first question is "what did I get wrong?", and
 * a 300-card review answers it only by scrolling. One chip narrows the list
 * to the cards worth reading; the counts alone are a summary of the attempt.
 *
 * Two rendering modes, one markup shape:
 * - Interactivity templates (in-quiz results) leave `$lrn_review_counts`
 *   unset: counts bind to the store getters and appear after submit.
 * - The account partial passes `$lrn_review_counts` (array with total /
 *   incorrect / correct / pending): counts render as plain PHP text.
 *
 * Filtering itself is CSS driven off `data-review-filter` on the review
 * container — assets/js/quiz-review.js flips the attribute; the state
 * classes the cards already carry do the rest. No store round-trip.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_review_counts = isset( $lrn_review_counts ) && is_array( $lrn_review_counts ) ? $lrn_review_counts : null;

$lrn_chips = array(
	'all'       => __( 'All', 'learnomy' ),
	'incorrect' => __( 'Incorrect', 'learnomy' ),
	'correct'   => __( 'Correct', 'learnomy' ),
	'pending'   => __( 'Pending', 'learnomy' ),
);

// The store getter each live count binds to (Interactivity mode).
$lrn_chip_getters = array(
	'all'       => 'state.reviewTotalCount',
	'incorrect' => 'state.incorrectCount',
	'correct'   => 'state.correctCount',
	'pending'   => 'state.pendingCount',
);
?>
<div class="lrn-quiz__review-filters" role="group" aria-label="<?php esc_attr_e( 'Filter reviewed questions', 'learnomy' ); ?>">
	<?php foreach ( $lrn_chips as $lrn_key => $lrn_label ) : ?>
		<?php
		// A pending chip with nothing pending is noise — hide it statically
		// in PHP mode; Interactivity mode hides it via the count binding.
		if ( 'pending' === $lrn_key && null !== $lrn_review_counts && empty( $lrn_review_counts['pending'] ) ) {
			continue;
		}
		?>
		<button type="button"
			class="lrn-quiz__review-filter"
			data-review-filter="<?php echo esc_attr( $lrn_key ); ?>"
			aria-pressed="<?php echo 'all' === $lrn_key ? 'true' : 'false'; ?>"
			<?php if ( 'pending' === $lrn_key && null === $lrn_review_counts ) : ?>
				data-wp-bind--hidden="!state.pendingCount"
			<?php endif; ?>
		>
			<?php echo esc_html( $lrn_label ); ?>
			<span class="lrn-quiz__review-filter-count"
				<?php if ( null === $lrn_review_counts ) : ?>
					data-wp-text="<?php echo esc_attr( $lrn_chip_getters[ $lrn_key ] ); ?>"
				<?php endif; ?>
			><?php echo null !== $lrn_review_counts ? esc_html( (string) (int) ( $lrn_review_counts[ $lrn_key ] ?? 0 ) ) : ''; ?></span>
		</button>
	<?php endforeach; ?>
</div>
