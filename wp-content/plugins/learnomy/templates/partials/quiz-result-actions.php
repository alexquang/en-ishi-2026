<?php
/**
 * Quiz results screen — the action row.
 *
 * Shared by every quiz style (classic, focused, sectioned, review-board,
 * conversational). It exists because this block was re-authored five times,
 * byte-identical in four of them and differing only by wrapper class in the
 * fifth — so the certificate CTA this partial adds could have shipped on four
 * screens and silently missed the one a given site had selected
 * (Basecamp 10171377745).
 *
 * The certificate action is bound to state, not rendered server-side, because
 * the results screen is only reached by an in-page submit: passing a
 * course-final quiz completes the course and issues the certificate inside
 * that same request, so a page-render copy would be stale exactly when it
 * matters. Its href/visibility come from `state.certificateUrl` /
 * `state.hasCertificate`, both fed by the submit response.
 *
 * It carries no icon on purpose. Lucide's `createIcons()` does not process this
 * subtree — every `data-lucide` inside the results screen stays an unconverted
 * empty element, including the pass/fail icon above it — so an icon here would
 * ship as an invisible box. The sibling actions are text-only anyway.
 *
 * Required caller variables:
 *   $back_url            (string) course home, or catalog for a standalone quiz
 *   $next_url            (string) next curriculum item; '' when there is none
 *   $next_label          (string) label for $next_url
 *   $attempts_remaining  (int)    0 = none left; PHP_INT_MAX = unlimited
 *
 * Optional:
 *   $results_actions_class (string) wrapper class; defaults to the shared one.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$lrn_actions_class = isset( $results_actions_class ) && '' !== $results_actions_class
	? (string) $results_actions_class
	: 'lrn-quiz__results-actions';
?>

<div class="<?php echo esc_attr( $lrn_actions_class ); ?>">
	<a href="" class="lrn-btn lrn-btn--primary"
		data-wp-bind--href="state.certificateUrl"
		data-wp-bind--hidden="!state.hasCertificate">
		<?php esc_html_e( 'View Certificate', 'learnomy' ); ?>
	</a>

	<?php if ( ! empty( $next_url ) ) : ?>
		<a href="<?php echo esc_url( $next_url ); ?>" class="lrn-btn lrn-btn--primary"
			data-wp-bind--hidden="!state.hasPassed">
			<?php echo esc_html( $next_label ); ?>
		</a>
	<?php endif; ?>

	<a href="<?php echo esc_url( $back_url ); ?>" class="lrn-btn lrn-btn--secondary lrn-btn--wrap">
		<?php
		// Names the actual destination. For a lesson-bound quiz back_url is the
		// LESSON, so "Back to Course" was about to become untrue the moment that
		// changed (Basecamp 10184407556) — the label has to travel with the URL,
		// not be re-derived here.
		//
		// The unbound case still resolves through course_label(), so a site that
		// renamed Course to Program says Program: the five inline copies this
		// partial replaced all hardcoded the noun, which left the quiz result
		// screen saying "Course" on a renamed install (Basecamp 10168014231).
		$lrn_back_label = isset( $back_label ) && '' !== (string) $back_label
			? (string) $back_label
			: \Learnomy\course_label();
		/* translators: %s: the destination — a lesson title, a course title, or the Course label. */
		printf( esc_html__( 'Back to %s', 'learnomy' ), esc_html( $lrn_back_label ) );
		?>
	</a>

	<?php if ( ! empty( $attempts_remaining ) ) : ?>
		<button type="button" class="lrn-btn lrn-btn--ghost"
			data-wp-on--click="actions.retakeQuiz">
			<?php esc_html_e( 'Try Again', 'learnomy' ); ?>
		</button>
	<?php else : ?>
		<p class="lrn-quiz__attempts-exhausted">
			<?php esc_html_e( 'You have used all attempts for this quiz.', 'learnomy' ); ?>
		</p>
	<?php endif; ?>
</div>
