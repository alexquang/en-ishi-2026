<?php
/**
 * Quiz scores widget body. Each row links back to the quiz so a failed
 * attempt has a clear path to retake.
 *
 * Locals: $recent_attempts, $attempt_quizzes
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $recent_attempts ) ) {
	return;
}
?>
<div class="lrn-table-wrap">
	<table class="lrn-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Quiz', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Score', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Result', 'learnomy' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Date', 'learnomy' ); ?></th>
				<th scope="col" class="lrn-table__actions-th"><?php esc_html_e( 'Action', 'learnomy' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $recent_attempts as $attempt ) :
				$quiz   = $attempt_quizzes[ (int) $attempt->quiz_id ] ?? null;
				$passed = ! empty( $attempt->passed );
				// Route choice belongs to Quiz::url(), which picks the
				// course-scoped path for an attached quiz and the standalone
				// one otherwise. This hardcoded 'standalone-quiz' for EVERY
				// attempt, so Review/Retake on a course-attached quiz - the
				// common case - sent the student to a guaranteed 404 (Basecamp
				// 10186056951). Pro's learning-path steps carried the identical
				// bug and were fixed the same way; the helper exists precisely
				// so this decision is made in one place.
				$retake_url = $quiz && ! empty( $quiz->slug )
					? \Learnomy\Models\Quiz::url( $quiz )
					: '';
				?>
				<tr>
					<td><?php echo $quiz ? esc_html( $quiz->title ) : esc_html__( 'Unknown', 'learnomy' ); ?></td>
					<td><?php echo esc_html( $attempt->percentage . '%' ); ?></td>
					<td>
						<?php if ( $passed ) : ?>
							<span class="lrn-badge lrn-badge--green"><?php esc_html_e( 'Pass', 'learnomy' ); ?></span>
						<?php else : ?>
							<span class="lrn-badge lrn-badge--red"><?php esc_html_e( 'Fail', 'learnomy' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $attempt->submitted_at ) ) ); ?></td>
					<td class="lrn-table__actions">
						<?php if ( $retake_url ) : ?>
							<a href="<?php echo esc_url( $retake_url ); ?>" class="lrn-btn lrn-btn--ghost lrn-btn--sm">
								<?php echo $passed ? esc_html__( 'Review', 'learnomy' ) : esc_html__( 'Retake', 'learnomy' ); ?>
							</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
