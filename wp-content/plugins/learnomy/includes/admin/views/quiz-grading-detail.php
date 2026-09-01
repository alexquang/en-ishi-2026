<?php
/**
 * Admin grading detail — grade one student's quiz attempt.
 *
 * Ungraded responses (essays and other manually-reviewed types) get an inline
 * score + feedback control saved through `POST learnomy/v1/attempts/{id}/grade`
 * (see assets/js/admin/quiz-grading.js). Already-graded responses render
 * read-only for context. When the last pending response is graded the backend
 * finalizes the attempt automatically (Quiz_Service::maybe_finalize_attempt).
 *
 * Every value below is assembled by \Learnomy\Services\Grading_Service::
 * attempt_detail() — the same payload the front-end grader at
 * /instructor/grading/?attempt=N renders — so the two screens show one student
 * one thing.
 *
 * @package Learnomy
 * @var object         $attempt           Quiz attempt row.
 * @var object         $quiz              Quiz row.
 * @var \WP_User|false $student           Attempt owner.
 * @var array[]        $rows              Normalised responses: id, index, prompt,
 *                                        type_label, answer_html, max, graded,
 *                                        score, feedback.
 * @var string         $ip_address        Submitting IP, or '' when not recorded.
 * @var bool           $tab_tracking      Whether the global tab-switch recorder is on.
 * @var int            $tab_switches      Times the learner left the quiz tab.
 * @var bool           $tab_flagged       Whether $tab_switches reached the threshold.
 * @var bool           $exam_late         Whether the attempt was submitted past its deadline.
 * @var int            $exam_late_minutes Minutes past the deadline (advisory).
 */

defined( 'ABSPATH' ) || exit;

$list_url    = admin_url( 'admin.php?page=learnomy-grading' );
$rows        = isset( $rows ) && is_array( $rows ) ? $rows : array();
$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

// Whether this attempt is finished is a property of the DATA, not of whether
// the admin happened to grade the last response in this page session. The
// finalization banner below used to be hard-coded `hidden`, and only
// quiz-grading.js could ever reveal it — after a live save — so reopening a
// fully-graded attempt showed nothing. `$rows` is the same normalised list
// Grading_Service::attempt_detail() derives its own `pending_count` from, so
// counting it here cannot drift from the service.
$lrn_pending_total = count(
	array_filter(
		$rows,
		static function ( $lrn_response ) {
			return empty( $lrn_response['graded'] );
		}
	)
);
?>
<div class="wrap learnomy-admin" data-grading-attempt="<?php echo absint( $attempt->id ); ?>">
	<div class="lrn-page-header">
		<div class="lrn-page-header__left">
			<h1 class="lrn-page-header__title">
				<i data-lucide="clipboard-check" class="lrn-page-header__icon"></i>
				<?php esc_html_e( 'Grade attempt', 'learnomy' ); ?>
			</h1>
			<p class="lrn-page-header__subtitle">
				<?php
				printf(
					/* translators: 1: student name, 2: quiz title */
					esc_html__( '%1$s, %2$s', 'learnomy' ),
					esc_html( $student ? $student->display_name : __( 'Deleted user', 'learnomy' ) ),
					esc_html( $quiz->title )
				);
				echo $attempt->submitted_at
					? ' · ' . esc_html( \Learnomy\date_label( $attempt->submitted_at, '—', $date_format ) )
					: '';
				?>
			</p>
				<?php $exam_ip = (string) ( $ip_address ?? '' ); ?>
			<?php if ( $tab_tracking || $exam_late || '' !== $exam_ip ) : ?>
				<p class="lrn-grading-meta">
					<?php if ( '' !== $exam_ip ) : ?>
						<span class="lrn-grading-tabswitch">
							<i data-lucide="map-pin" aria-hidden="true"></i>
							<?php
							printf(
								/* translators: %s: IP address the attempt was submitted from */
								esc_html__( 'IP: %s', 'learnomy' ),
								esc_html( $exam_ip )
							);
							?>
						</span>
					<?php endif; ?>
					<?php if ( $tab_tracking ) : ?>
						<span class="lrn-grading-tabswitch<?php echo $tab_flagged ? ' lrn-grading-tabswitch--flagged' : ''; ?>">
							<i data-lucide="<?php echo $tab_flagged ? 'shield-alert' : 'shield-check'; ?>" aria-hidden="true"></i>
							<?php
							printf(
								/* translators: %d: number of times the learner left the quiz tab */
								esc_html__( 'Tab switches: %d', 'learnomy' ),
								(int) $tab_switches
							);
							?>
						</span>
					<?php endif; ?>
					<?php if ( $exam_late ) : ?>
						<span class="lrn-grading-tabswitch lrn-grading-tabswitch--flagged">
							<i data-lucide="timer-off" aria-hidden="true"></i>
							<?php
							if ( $exam_late_minutes > 0 ) {
								printf(
									/* translators: %d: minutes past the time limit */
									esc_html( _n( 'Submitted %d min after the time limit', 'Submitted %d min after the time limit', $exam_late_minutes, 'learnomy' ) ),
									(int) $exam_late_minutes
								);
							} else {
								esc_html_e( 'Submitted after the time limit', 'learnomy' );
							}
							?>
						</span>
					<?php endif; ?>
					<?php if ( $tab_flagged ) : ?>
						<span class="lrn-grading-tabswitch__hint"><?php esc_html_e( 'Advisory only, not proof of misconduct.', 'learnomy' ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</div>
		<div class="lrn-page-header__actions">
			<a href="<?php echo esc_url( $list_url ); ?>" class="lrn-admin-btn">
				<i data-lucide="arrow-left"></i>
				<?php esc_html_e( 'Back to grading', 'learnomy' ); ?>
			</a>
		</div>
	</div>

	<hr class="wp-header-end">

	<div class="lrn-grading-banner notice notice-success" data-grading-done <?php echo $lrn_pending_total > 0 ? 'hidden' : ''; ?>>
		<p>
			<?php esc_html_e( 'All responses are graded. This attempt has been finalized.', 'learnomy' ); ?>
			<a href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Back to grading', 'learnomy' ); ?></a>
		</p>
	</div>

	<div class="lrn-grading-list">
		<?php foreach ( $rows as $lrn_row ) : ?>
			<div class="lrn-settings-card lrn-grading-card<?php echo $lrn_row['graded'] ? ' is-graded' : ''; ?>"
				data-response-id="<?php echo absint( $lrn_row['id'] ); ?>"
				data-max="<?php echo esc_attr( (string) $lrn_row['max'] ); ?>">
				<div class="lrn-settings-card__head">
					<h2 class="lrn-settings-card__title">
						<?php
						printf(
							/* translators: 1: question number, 2: question prompt */
							esc_html__( 'Q%1$d. %2$s', 'learnomy' ),
							(int) $lrn_row['index'],
							esc_html( (string) $lrn_row['prompt'] )
						);
						?>
					</h2>
					<p class="lrn-settings-card__desc">
						<?php
						$lrn_resp_badge = \Learnomy\Admin\UX::status_badge(
							$lrn_row['graded'] ? 'active' : 'pending',
							$lrn_row['graded'] ? __( 'Graded', 'learnomy' ) : __( 'Needs grading', 'learnomy' )
						);
						echo $lrn_resp_badge; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						printf(
							' <span class="lrn-muted">%s · %s</span>',
							esc_html( (string) $lrn_row['type_label'] ),
							sprintf(
								/* translators: %s: maximum marks for the question */
								esc_html__( 'max %s', 'learnomy' ),
								esc_html( (string) $lrn_row['max'] )
							)
						);
						?>
					</p>
				</div>

				<div class="lrn-grading-card__answer">
					<h3 class="lrn-grading-card__label"><?php esc_html_e( 'Student answer', 'learnomy' ); ?></h3>
					<div class="lrn-grading-card__answer-body">
						<?php echo $lrn_row['answer_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by Grading_Service::render_answer(). ?>
					</div>
				</div>

				<?php if ( $lrn_row['graded'] ) : ?>
					<div class="lrn-grading-card__result">
						<p>
							<strong><?php esc_html_e( 'Score', 'learnomy' ); ?>:</strong>
							<?php echo esc_html( (string) $lrn_row['score'] ); ?> / <?php echo esc_html( (string) $lrn_row['max'] ); ?>
						</p>
						<?php if ( '' !== (string) $lrn_row['feedback'] ) : ?>
							<p><strong><?php esc_html_e( 'Feedback', 'learnomy' ); ?>:</strong> <?php echo nl2br( esc_html( (string) $lrn_row['feedback'] ) ); ?></p>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<div class="lrn-grading-card__form" data-grade-form>
						<div class="lrn-form-field">
							<label for="lrn-score-<?php echo absint( $lrn_row['id'] ); ?>"><?php esc_html_e( 'Score', 'learnomy' ); ?></label>
							<input type="number" id="lrn-score-<?php echo absint( $lrn_row['id'] ); ?>"
								class="small-text" data-grade-score
								min="0" max="<?php echo esc_attr( (string) $lrn_row['max'] ); ?>" step="0.5"
								value="">
							<span class="lrn-muted">/ <?php echo esc_html( (string) $lrn_row['max'] ); ?></span>
						</div>
						<div class="lrn-form-field">
							<label for="lrn-feedback-<?php echo absint( $lrn_row['id'] ); ?>"><?php esc_html_e( 'Feedback (optional)', 'learnomy' ); ?></label>
							<textarea id="lrn-feedback-<?php echo absint( $lrn_row['id'] ); ?>"
								class="large-text" rows="3" data-grade-feedback
								placeholder="<?php esc_attr_e( 'Shown to the student with their result.', 'learnomy' ); ?>"></textarea>
						</div>
						<div class="lrn-grading-card__actions">
							<button type="button" class="lrn-admin-btn lrn-admin-btn--primary" data-grade-save>
								<?php esc_html_e( 'Save grade', 'learnomy' ); ?>
							</button>
						</div>
						<?php
						// Extension seam: Pro advanced-quizzes attaches its rubric
						// grader here so rubric-based scoring augments (not parallels)
						// this control. Free renders nothing extra. Signature unchanged
						// (response row, attempt, question) — the front-end grader
						// fires the identical action.
						do_action( 'learnomy_quiz_grading_response_controls', $lrn_row['response'], $attempt, $lrn_row['question'] );
						?>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<?php if ( empty( $rows ) ) : ?>
			<div class="lrn-settings-card">
				<p class="lrn-muted"><?php esc_html_e( 'This attempt has no recorded responses.', 'learnomy' ); ?></p>
			</div>
		<?php endif; ?>
	</div>
</div>
