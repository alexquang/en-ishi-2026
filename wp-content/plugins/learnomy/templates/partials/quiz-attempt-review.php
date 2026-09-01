<?php
/**
 * Static (server-rendered) per-question quiz review.
 *
 * The persistent twin of the quiz player's Interactivity review
 * (templates/quiz/*.php) — identical `.lrn-quiz__review-*` markup + CSS, but
 * rendered from a plain PHP array so a finished attempt can be reviewed without
 * the live store. Rows come from the single shared builder
 * \Learnomy\Services\Quiz_Service::build_attempt_review(), so the "what did I
 * answer / what was right" shape never forks from the live results screen.
 *
 * Expected in scope:
 *   $review_rows (array) rows from Quiz_Service::build_attempt_review()
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$review_rows = isset( $review_rows ) && is_array( $review_rows ) ? $review_rows : array();
if ( empty( $review_rows ) ) {
	return;
}
?>
<div class="lrn-quiz__review">
	<h3 class="lrn-quiz__review-heading"><?php esc_html_e( 'Question Review', 'learnomy' ); ?></h3>

	<?php
	// Filter chips with server-computed counts — at exam scale the first
	// question is "what did I get wrong?", and the chips answer it without
	// scrolling. Same partial the live results screen uses.
	$lrn_review_counts              = array(
		'all'     => count( $review_rows ),
		'correct' => count( array_filter( $review_rows, static fn( $r ) => ! empty( $r['correct'] ) ) ),
		'pending' => count( array_filter( $review_rows, static fn( $r ) => ! empty( $r['pending'] ) ) ),
	);
	$lrn_review_counts['incorrect'] = $lrn_review_counts['all'] - $lrn_review_counts['correct'] - $lrn_review_counts['pending'];
	require \Learnomy\template_path( 'partials/quiz-review-filters.php' );

	// Past ~20 questions every card renders CLOSED (a <details> summary row)
	// so a large attempt scans as a list of titles + status glyphs.
	$lrn_review_open = count( $review_rows ) <= 20 ? ' open' : '';

	// Grouped by the quiz's own structure (sections / categories) when the
	// view-data resolver supplied groups — the persistent twin of the live
	// screen's grouping. A single unlabeled group renders headerless, i.e.
	// exactly the old flat list.
	$lrn_review_groups = isset( $review_groups ) && is_array( $review_groups ) && $review_groups
		? $review_groups
		: array(
			array(
				'label'   => '',
				'summary' => '',
				'rows'    => $review_rows,
			),
		);

	foreach ( $lrn_review_groups as $lrn_group ) :
		$lrn_group_label = (string) ( $lrn_group['label'] ?? '' );
		if ( '' !== $lrn_group_label ) :
			?>
			<div class="lrn-sect__review-group">
			<h4 class="lrn-sect__review-group-title">
				<span><?php echo esc_html( $lrn_group_label ); ?></span>
				<span class="lrn-sect__review-group-summary"><?php echo esc_html( (string) ( $lrn_group['summary'] ?? '' ) ); ?></span>
			</h4>
			<?php
		endif;

		foreach ( (array) ( $lrn_group['rows'] ?? array() ) as $lrn_row ) :
			$lrn_correct    = ! empty( $lrn_row['correct'] );
			$lrn_pending    = ! empty( $lrn_row['pending'] );
			$lrn_state_cls  = $lrn_correct
			? ' lrn-quiz__review-item--correct'
			: ( $lrn_pending ? ' lrn-quiz__review-item--pending' : ' lrn-quiz__review-item--incorrect' );
			$lrn_state_icon = $lrn_correct ? 'check-circle' : ( $lrn_pending ? 'clock' : 'x-circle' );
			?>
		<details class="lrn-quiz__review-item lrn-card<?php echo esc_attr( $lrn_state_cls ); ?>"<?php echo esc_attr( $lrn_review_open ); ?>>
			<summary class="lrn-quiz__review-item-header">
				<span class="lrn-quiz__review-item-status">
					<i data-lucide="<?php echo esc_attr( $lrn_state_icon ); ?>" aria-hidden="true"></i>
				</span>
				<h4 class="lrn-quiz__review-item-title"><?php echo esc_html( (string) ( $lrn_row['title'] ?? '' ) ); ?></h4>
				<?php if ( isset( $lrn_row['score'] ) ) : ?>
					<span class="lrn-quiz__review-item-score">
						<?php echo esc_html( (string) (float) $lrn_row['score'] ); ?>/<?php echo esc_html( (string) (float) ( $lrn_row['max_score'] ?? 0 ) ); ?>
					</span>
				<?php endif; ?>
				<i class="lrn-quiz__review-item-chevron" data-lucide="chevron-down" aria-hidden="true"></i>
			</summary>

			<?php
			foreach ( (array) ( $lrn_row['answers'] ?? array() ) as $lrn_ans ) :
				$lrn_a_correct  = ! empty( $lrn_ans['correct'] );
				$lrn_a_selected = ! empty( $lrn_ans['selected'] );
				$lrn_a_cls      = '';
				if ( $lrn_a_correct ) {
					$lrn_a_cls .= ' lrn-quiz__review-answer--correct';
				}
				if ( $lrn_a_selected ) {
					$lrn_a_cls .= ' lrn-quiz__review-answer--selected';
				}
				if ( $lrn_a_selected && ! $lrn_a_correct ) {
					$lrn_a_cls .= ' lrn-quiz__review-answer--wrong';
				}
				$lrn_a_icon = $lrn_a_correct ? 'check' : ( $lrn_a_selected ? 'x' : 'circle' );
				?>
				<div class="lrn-quiz__review-answer<?php echo esc_attr( $lrn_a_cls ); ?>">
					<span class="lrn-quiz__review-answer-icon">
						<i data-lucide="<?php echo esc_attr( $lrn_a_icon ); ?>" aria-hidden="true"></i>
					</span>
					<span><?php echo esc_html( (string) ( $lrn_ans['content'] ?? '' ) ); ?></span>
				</div>
			<?php endforeach; ?>

			<?php
			/*
			 * "Your instructor graded this."
			 *
			 * Shown for EVERY question type, outside the essay block below,
			 * because the case it exists for is an auto-gradeable answer an
			 * instructor corrected - a mis-keyed or ambiguous multiple choice.
			 * That override now survives regrading, so without this line a
			 * student sees a mark the answer key would not produce and nothing
			 * explaining why (Basecamp 10198940016).
			 *
			 * Essays already say this implicitly - somebody always grades them.
			 * The row that needed it is the one the machine usually scores.
			 */
			?>
			<?php if ( ! empty( $lrn_row['graded_by_instructor'] ) ) : ?>
				<p class="lrn-quiz__review-graded-by">
					<i data-lucide="user-check" aria-hidden="true"></i>
					<?php esc_html_e( 'Your instructor graded this question.', 'learnomy' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( null !== ( $lrn_row['given'] ?? null ) && '' !== (string) $lrn_row['given'] ) : ?>
				<div class="lrn-quiz__review-essay">
					<p class="lrn-quiz__review-essay-label"><?php esc_html_e( 'Your response:', 'learnomy' ); ?></p>
					<blockquote class="lrn-quiz__review-essay-text"><?php echo esc_html( (string) $lrn_row['given'] ); ?></blockquote>
					<?php if ( $lrn_pending ) : ?>
						<p class="lrn-quiz__review-essay-pending">
							<i data-lucide="clock" aria-hidden="true"></i>
							<?php esc_html_e( 'Awaiting instructor review', 'learnomy' ); ?>
						</p>
					<?php elseif ( '' !== (string) ( $lrn_row['grader_feedback'] ?? '' ) ) : ?>
						<div class="lrn-quiz__review-feedback">
							<p class="lrn-quiz__review-feedback-label">
								<i data-lucide="message-square" aria-hidden="true"></i>
								<?php printf( esc_html( /* translators: %s: singular label for an instructor (e.g. "Coach"). */ __( '%s feedback', 'learnomy' ) ), esc_html( \Learnomy\instructor_label() ) ); ?>
							</p>
							<blockquote class="lrn-quiz__review-feedback-text"><?php echo esc_html( (string) $lrn_row['grader_feedback'] ); ?></blockquote>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</details>
		<?php endforeach; ?>

		<?php if ( '' !== $lrn_group_label ) : ?>
			</div>
		<?php endif; ?>
	<?php endforeach; ?>
</div>
