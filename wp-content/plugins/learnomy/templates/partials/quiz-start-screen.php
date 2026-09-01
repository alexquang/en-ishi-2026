<?php
/**
 * Quiz start screen — shared across all quiz layout templates.
 *
 * Variables expected (all pre-resolved by Single_Quiz_View_Data):
 *   $quiz           (object) The quiz row.
 *   $question_count (int)    Total number of questions.
 *   $time_limit_mins (int)   Time limit in minutes; 0 = none.
 *   $passing_pct    (int)    Passing score percentage.
 *   $max_attempts   (int)    Max allowed attempts; 0 = unlimited.
 *   $attempts_remaining (int) Attempts left for this student (PHP_INT_MAX when
 *                            unlimited); 0 means none left — hide Start.
 *   $attempts_used  (int)    Graded attempts this student has already used.
 *   $enroll_required (bool)  Whether enrollment is required but not met.
 *   $enroll_url     (string) URL to the course for the enroll CTA.
 *   $extra_rules    (array)  Optional additional <li> items (icon + text pairs)
 *                            injected by the layout template for layout-specific
 *                            context (e.g. "Multi-section exam" for sectioned,
 *                            "Jump to any question" for review-board).
 *                            Each entry: [ 'icon' => string, 'text' => string ]
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$extra_rules     = $extra_rules ?? [];
$proctoring_note = $proctoring_note ?? '';
?>
<div class="lrn-quiz__start-screen lrn-quiz__start--<?php echo esc_attr( \Learnomy\quiz_start_style() ); ?>" data-wp-bind--hidden="state.started">
	<h1 class="lrn-quiz__title"><?php echo esc_html( $quiz->title ); ?></h1>

	<?php if ( ! empty( $quiz->description ) ) : ?>
		<div class="lrn-quiz__description"><?php echo \Learnomy\render_editorjs_content( (string) $quiz->description, '', 'html' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_editorjs_content runs wp_kses_post on every block. ?></div>
	<?php endif; ?>

	<ul class="lrn-quiz__rules lrn-card">
		<li class="lrn-quiz__rule--stat">
			<i data-lucide="list-ordered" aria-hidden="true"></i>
			<?php
			printf(
				/* translators: %d: number of questions. */
				esc_html( _n( '%d question', '%d questions', $question_count, 'learnomy' ) ),
				(int) $question_count
			);
			?>
		</li>
		<?php if ( $time_limit_mins > 0 ) : ?>
			<li class="lrn-quiz__rule--stat">
				<i data-lucide="clock" aria-hidden="true"></i>
				<?php
				printf(
					/* translators: %d: number of minutes. */
					esc_html( _n( '%d minute time limit', '%d minutes time limit', $time_limit_mins, 'learnomy' ) ),
					(int) $time_limit_mins
				);
				?>
			</li>
		<?php endif; ?>
		<?php
		$lrn_mode = (string) ( $quiz->mode ?? 'graded' );
		?>
		<?php if ( 'survey' === $lrn_mode ) : ?>
			<?php
			// A survey has no pass mark, so "Passing score: 0%" is not a
			// low bar - it is a number about a thing that is not being scored
			// (Basecamp 10184407410). Say what a survey actually is instead.
			?>
			<li class="lrn-quiz__rule--stat">
				<i data-lucide="message-square" aria-hidden="true"></i>
				<?php esc_html_e( 'Not graded - your answers are collected, not scored', 'learnomy' ); ?>
			</li>
		<?php else : ?>
			<li class="lrn-quiz__rule--stat">
				<i data-lucide="check-circle" aria-hidden="true"></i>
				<?php
				/* translators: %d: passing percentage. */
				printf( esc_html__( 'Passing score: %d%%', 'learnomy' ), (int) $passing_pct );
				?>
			</li>
		<?php endif; ?>
		<?php if ( $max_attempts > 0 ) : ?>
			<li class="lrn-quiz__rule--stat">
				<i data-lucide="repeat" aria-hidden="true"></i>
				<?php
				printf(
					/* translators: %d: number of attempts. */
					esc_html( _n( '%d attempt allowed', '%d attempts allowed', $max_attempts, 'learnomy' ) ),
					(int) $max_attempts
				);
				?>
			</li>
		<?php elseif ( 'practice' === $lrn_mode ) : ?>
			<?php
			// Unlimited attempts rendered NOTHING, so practice mode's whole
			// value - retake as often as you like, no effect on completion - was
			// invisible on the one screen where a learner decides whether to
			// start. An absent row reads as "no information", not as "no limit".
			?>
			<li class="lrn-quiz__rule--stat">
				<i data-lucide="repeat" aria-hidden="true"></i>
				<?php esc_html_e( 'Unlimited attempts - practice as often as you like', 'learnomy' ); ?>
			</li>
		<?php endif; ?>
		<?php foreach ( $extra_rules as $rule ) : ?>
			<li class="lrn-quiz__rule--note">
				<i data-lucide="<?php echo esc_attr( $rule['icon'] ); ?>" aria-hidden="true"></i>
				<?php echo esc_html( $rule['text'] ); ?>
			</li>
		<?php endforeach; ?>
		<?php if ( ! empty( $proctoring_note ) ) : ?>
			<li class="lrn-quiz__rule--proctored lrn-quiz__rule--note">
				<i data-lucide="eye" aria-hidden="true"></i>
				<?php echo esc_html( $proctoring_note ); ?>
			</li>
		<?php endif; ?>
	</ul>

	<?php if ( ! empty( $enroll_required ) ) : ?>
		<div class="lrn-quiz__enroll-gate" role="status">
			<p class="lrn-quiz__enroll-gate-text">
				<?php esc_html_e( 'Enroll in this course to take the quiz.', 'learnomy' ); ?>
			</p>
			<?php if ( ! empty( $enroll_url ) ) : ?>
				<a class="lrn-btn lrn-btn--primary" href="<?php echo esc_url( $enroll_url ); ?>">
					<?php esc_html_e( 'View Course', 'learnomy' ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php elseif ( isset( $max_attempts ) && $max_attempts > 0 && empty( $attempts_remaining ) ) : ?>
		<?php
		// No attempts left. Show WHY, and do NOT render Start — the server
		// would 400 with "max attempts reached" and the player only surfaced a
		// generic "Failed to start quiz" (Basecamp 10096673115). The "Try Again"
		// buttons on the results screens are already gated on attempts_remaining;
		// this closes the same gap on the start screen a returning student lands on.
		?>
		<div class="lrn-quiz__attempts-gate" role="status">
			<p class="lrn-quiz__attempts-gate-text">
				<?php
				printf(
					/* translators: %d: the number of attempts the student was allowed. */
					esc_html( _n( 'You have used your %d attempt for this quiz.', 'You have used all %d attempts for this quiz.', (int) $max_attempts, 'learnomy' ) ),
					(int) $max_attempts
				);
				?>
			</p>
		</div>
	<?php else : ?>
		<?php
		/*
		 * A returning student's first question is "did I already pass this?"
		 *
		 * This screen used to answer "here are the rules" — the same screen a
		 * first-timer sees — so someone who had passed weeks earlier could not
		 * tell their attempt had saved, and support gets "I already passed, why
		 * is it asking me to start again?" (Basecamp 10171334411).
		 *
		 * Nothing new is fetched. `$latest_attempt` is already resolved by
		 * Single_Quiz_View_Data and was being used only for cooldown
		 * arithmetic, then discarded. `?attempt=N` on the results page already
		 * resolves, so the Review link is a destination that already works.
		 */
		$lrn_prior = ( isset( $latest_attempt ) && $latest_attempt && ! empty( $latest_attempt->submitted_at ) )
			? $latest_attempt
			: null;
		?>
		<?php if ( $lrn_prior ) : ?>
			<?php
			$lrn_passed = ! empty( $lrn_prior->passed );
			$lrn_pct    = round( (float) ( $lrn_prior->percentage ?? 0 ) );
			$lrn_when   = wp_date( get_option( 'date_format' ), strtotime( (string) $lrn_prior->submitted_at ) );
			$lrn_review = add_query_arg(
				'attempt',
				(int) $lrn_prior->id,
				\Learnomy\route_url( 'account-quiz-results' )
			);
			?>
			<div class="lrn-quiz__prior">
				<p class="lrn-quiz__prior-line">
					<span class="lrn-badge <?php echo $lrn_passed ? 'lrn-badge--green' : 'lrn-badge--amber'; ?>">
						<?php echo $lrn_passed ? esc_html__( 'Passed', 'learnomy' ) : esc_html__( 'Not passed', 'learnomy' ); ?>
					</span>
					<?php
					printf(
						/* translators: 1: score percentage, 2: date of the attempt. */
						esc_html__( 'You scored %1$d%% on %2$s.', 'learnomy' ),
						(int) $lrn_pct,
						esc_html( $lrn_when )
					);
					?>
					<a href="<?php echo esc_url( $lrn_review ); ?>"><?php esc_html_e( 'Review your answers', 'learnomy' ); ?></a>
				</p>
				<?php if ( 'highest' === ( $quiz->grading_method ?? 'highest' ) ) : ?>
					<p class="lrn-quiz__prior-note">
						<?php esc_html_e( 'Taking it again keeps your best score.', 'learnomy' ); ?>
					</p>
				<?php else : ?>
					<p class="lrn-quiz__prior-note">
						<?php esc_html_e( 'Taking it again replaces the score above.', 'learnomy' ); ?>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<button type="button" class="lrn-btn lrn-btn--primary lrn-quiz__start-btn"
			data-wp-on--click="actions.startQuiz"
			data-wp-bind--disabled="state.isStarting"
			data-wp-bind--aria-busy="state.isStarting">
			<span data-wp-text="<?php echo $lrn_prior ? 'state.retakeBtnLabel' : 'state.startBtnLabel'; ?>">
				<?php
				// "Start" is wrong for someone who has sat this before. Both go
				// through quiz_label() so a site that renamed Quiz to Exam or
				// Assessment reads correctly here too.
				if ( $lrn_prior ) {
					/* translators: %s: singular Quiz label, renamable in Settings > Labels */
					printf( esc_html__( 'Retake %s', 'learnomy' ), esc_html( \Learnomy\quiz_label() ) );
				} else {
					/* translators: %s: singular Quiz label, renamable in Settings > Labels */
					printf( esc_html__( 'Start %s', 'learnomy' ), esc_html( \Learnomy\quiz_label() ) );
				}
				?>
			</span>
		</button>
	<?php endif; ?>
</div>
