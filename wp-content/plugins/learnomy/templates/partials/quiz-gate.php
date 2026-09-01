<?php
/**
 * Quiz pre-start gate — renders a blocking screen before the student can
 * open the quiz. One partial, seven states, driven by a single $gate_state
 * string so `single-quiz.php` can resolve the gate decision and hand off.
 *
 * Note `locked` and `lesson_locked` are different rules: `locked` is the
 * failed-attempt lockout (the student burned their attempts), `lesson_locked`
 * is the drip/sequential schedule on the lesson the quiz is bound to.
 *
 * Expects:
 *   $quiz       (object) Quiz row.
 *   $gate_state (string) One of: empty | scheduled | lesson_locked | closed | cooldown | locked | code.
 *   Optional:
 *     $opens_at_formatted  (string) — for scheduled state.
 *     $closed_at_formatted (string) — for closed state.
 *     $seconds_remaining   (int)    — for cooldown state.
 *     $max_failed          (int)    — for locked state.
 *     $locked_lesson_title (string) — for lesson_locked state.
 *     $locked_course_url   (string) — for lesson_locked state.
 *     $gate_error          (string) — pre-filled error (e.g. wrong code).
 *
 * All user-facing strings are translatable. All styling uses --lrn-*
 * tokens or existing .lrn-card / .lrn-btn patterns. Lucide icons only.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$gate_state          = $gate_state ?? 'code';
$opens_at_formatted  = $opens_at_formatted ?? '';
$closed_at_formatted = $closed_at_formatted ?? '';
$seconds_remaining   = isset( $seconds_remaining ) ? (int) $seconds_remaining : 0;
$max_failed          = isset( $max_failed ) ? (int) $max_failed : 0;
$gate_error          = $gate_error ?? '';
$quiz_id             = isset( $quiz->id ) ? (int) $quiz->id : 0;
?>
<div class="lrn-container lrn-quiz-gate-wrap">
	<?php if ( 'empty' === $gate_state ) : ?>
		<div class="lrn-quiz-gate lrn-quiz-gate--empty lrn-card">
			<i data-lucide="file-question" aria-hidden="true"></i>
			<h2><?php esc_html_e( 'This quiz has no questions yet', 'learnomy' ); ?></h2>
			<?php if ( ! empty( $is_privileged ) ) : ?>
				<p><?php esc_html_e( 'Add questions in the quiz editor to make it available to students.', 'learnomy' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Check back later.', 'learnomy' ); ?></p>
			<?php endif; ?>
		</div>

	<?php elseif ( 'scheduled' === $gate_state ) : ?>
		<div class="lrn-quiz-gate lrn-quiz-gate--scheduled lrn-card">
			<i data-lucide="calendar-clock" aria-hidden="true"></i>
			<h2>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: formatted date/time when the quiz opens */
						__( 'This quiz opens on %s', 'learnomy' ),
						$opens_at_formatted
					)
				);
				?>
			</h2>
			<p><?php esc_html_e( 'Come back then to start.', 'learnomy' ); ?></p>
		</div>

	<?php elseif ( 'lesson_locked' === $gate_state ) : ?>
		<div class="lrn-quiz-gate lrn-quiz-gate--lesson-locked lrn-card">
			<i data-lucide="lock" aria-hidden="true"></i>
			<h2><?php esc_html_e( 'This quiz unlocks with its lesson', 'learnomy' ); ?></h2>
			<?php if ( ! empty( $locked_lesson_title ) ) : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: title of the lesson the quiz is attached to */
							__( 'It belongs to %s, which is not available to you yet.', 'learnomy' ),
							$locked_lesson_title
						)
					);
					?>
				</p>
			<?php else : ?>
				<p><?php esc_html_e( 'The lesson it belongs to is not available to you yet.', 'learnomy' ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $locked_course_url ) ) : ?>
				<a class="lrn-btn lrn-btn--primary" href="<?php echo esc_url( $locked_course_url ); ?>"><?php esc_html_e( 'Back to the course', 'learnomy' ); ?></a>
			<?php endif; ?>
		</div>

	<?php elseif ( 'closed' === $gate_state ) : ?>
		<div class="lrn-quiz-gate lrn-quiz-gate--closed lrn-card">
			<i data-lucide="lock" aria-hidden="true"></i>
			<h2>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: formatted date/time when the quiz closed */
						__( 'This quiz closed on %s', 'learnomy' ),
						$closed_at_formatted
					)
				);
				?>
			</h2>
			<p><?php esc_html_e( 'Submissions are no longer accepted.', 'learnomy' ); ?></p>
		</div>

	<?php elseif ( 'cooldown' === $gate_state ) : ?>
		<div class="lrn-quiz-gate lrn-quiz-gate--cooldown lrn-card">
			<i data-lucide="timer" aria-hidden="true"></i>
			<h2>
				<?php esc_html_e( 'Another attempt available in', 'learnomy' ); ?>
				<span
					class="lrn-quiz-gate__countdown"
					data-lrn-countdown
					data-seconds="<?php echo esc_attr( (string) $seconds_remaining ); ?>"
				>&hellip;</span>
			</h2>
			<p><?php esc_html_e( 'The page will reload automatically when the cooldown ends.', 'learnomy' ); ?></p>
		</div>

	<?php elseif ( 'locked' === $gate_state ) : ?>
		<div class="lrn-quiz-gate lrn-quiz-gate--locked lrn-card">
			<i data-lucide="shield-alert" aria-hidden="true"></i>
			<h2>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: maximum number of failed attempts allowed */
						_n(
							'You\'ve used all %d attempt',
							'You\'ve used all %d attempts',
							$max_failed,
							'learnomy'
						),
						$max_failed
					)
				);
				?>
			</h2>
			<p><?php esc_html_e( 'Contact your instructor if you need another try.', 'learnomy' ); ?></p>
		</div>

	<?php else : /* code */ ?>
		<div class="lrn-quiz-gate lrn-quiz-gate--code lrn-card">
			<i data-lucide="key-round" aria-hidden="true"></i>
			<h2><?php esc_html_e( 'Access code required', 'learnomy' ); ?></h2>
			<p><?php esc_html_e( 'Enter the code your instructor shared to start this quiz.', 'learnomy' ); ?></p>

			<div
				class="lrn-quiz-gate__error"
				role="alert"
				data-lrn-gate-error
				<?php echo empty( $gate_error ) ? 'hidden' : ''; ?>
			>
				<i data-lucide="alert-circle" aria-hidden="true"></i>
				<span data-lrn-gate-error-text><?php echo esc_html( $gate_error ); ?></span>
			</div>

			<form method="post" class="lrn-quiz-gate__code-form" data-lrn-quiz-gate-form>
				<label for="lrn-quiz-gate-code-<?php echo esc_attr( (string) $quiz_id ); ?>">
					<?php esc_html_e( 'Access code', 'learnomy' ); ?>
				</label>
				<input
					type="text"
					id="lrn-quiz-gate-code-<?php echo esc_attr( (string) $quiz_id ); ?>"
					name="access_code"
					autocomplete="off"
					autocapitalize="characters"
					spellcheck="false"
					required
				>
				<button type="submit" class="lrn-btn lrn-btn--primary">
					<?php esc_html_e( 'Continue', 'learnomy' ); ?>
				</button>
				<?php wp_nonce_field( 'learnomy_quiz_gate_code', 'learnomy_quiz_gate_nonce' ); ?>
				<input type="hidden" name="quiz_id" value="<?php echo esc_attr( (string) $quiz_id ); ?>">
			</form>
		</div>
	<?php endif; ?>
</div>

<?php
// Translated strings for the gate's countdown + code-verify JS. Localised
// onto window.lrnQuizGate.i18n via wp_localize_script() — the script handle
// `learnomy-quiz-gate` enqueued in Template_Loader picks them up.
wp_localize_script(
	'learnomy-quiz-gate',
	'lrnQuizGate',
	array(
		'i18n' => array(
			'incorrectCode'  => __( 'Incorrect access code.', 'learnomy' ),
			'apiUnavailable' => __( 'API not available. Reload and try again.', 'learnomy' ),
			/* translators: minutes abbreviation in a countdown, e.g. "5m 30s" */
			'minutesShort'   => _x( 'm', 'minutes abbreviation', 'learnomy' ),
			/* translators: seconds abbreviation in a countdown, e.g. "5m 30s" */
			'secondsShort'   => _x( 's', 'seconds abbreviation', 'learnomy' ),
		),
	)
);
?>
