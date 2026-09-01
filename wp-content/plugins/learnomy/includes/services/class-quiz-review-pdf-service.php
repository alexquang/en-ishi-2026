<?php
/**
 * Quiz attempt review as a downloadable PDF.
 *
 * The printable twin of the account attempt review: a student keeps a record
 * of what they answered (certification prep, submitting evidence to an
 * employer), and an admin or instructor exports the same document when
 * supporting a learner. Renders with the committed, scoped Dompdf under
 * `libs/` — the same rail Receipt_PDF_Service ships on, no composer install.
 *
 * The rows come from the single shared Quiz_Service::build_attempt_review()
 * and group through Quiz_Service::group_attempt_review(), so the PDF says
 * exactly what the account page and the live results screen say — including
 * honouring feedback_timing 'never', which exports the score summary with no
 * per-question solutions.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Quiz;
use Learnomy\Models\QuizAttempt;
use Learnomy\Vendor\Dompdf\Dompdf;
use Learnomy\Vendor\Dompdf\Options;

class Quiz_Review_PDF_Service {

	/**
	 * Stream the attempt-review PDF to the requesting user. Exits the request.
	 *
	 * Same gate as the account review page: the attempt's owner, or an admin
	 * (support). Anyone else gets a 404 — not a 403, so the export URL does
	 * not confirm which attempt ids exist.
	 */
	public static function stream( int $attempt_id, int $user_id ): void {
		$attempt  = QuizAttempt::find( $attempt_id );
		$is_owner = $attempt && (int) $attempt->user_id === $user_id;
		$is_admin = user_can( $user_id, 'manage_options' );

		if ( ! $attempt || ( ! $is_owner && ! $is_admin ) ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		$quiz   = Quiz::find( (int) $attempt->quiz_id );
		$course = $quiz && ! empty( $quiz->course_id ) ? Course::find( (int) $quiz->course_id ) : null;

		// Same reveal resolution as the account page and the live results screen,
		// through the one shared helper — a PDF is the worst place to leak a
		// solution because it outlives the session and travels. Gating on
		// 'never' alone exported the full key to a student who had failed an
		// after_pass exam (Basecamp 10184327412).
		$lrn_quiz_service = new Quiz_Service();
		$feedback_timing  = $lrn_quiz_service->resolve_feedback_timing(
			$attempt,
			(string) ( $quiz->feedback_timing ?? 'after_submit' )
		);
		$no_feedback      = ! Quiz_Service::timing_reveals_review( $feedback_timing );
		$rows             = $no_feedback ? array() : $lrn_quiz_service->build_attempt_review( $attempt, $feedback_timing );
		$groups           = Quiz_Service::group_attempt_review( $rows, $quiz );

		$html = self::render_html( $attempt, $quiz, $course, $groups, $no_feedback );

		$autoload = LEARNOMY_DIR . 'libs/autoload.php';
		if ( file_exists( $autoload ) ) {
			require_once $autoload;
		}

		if ( ! class_exists( '\\Learnomy\\Vendor\\Dompdf\\Dompdf' ) ) {
			// Library missing — fall back to HTML so the student still gets a
			// printable document. Should never happen on a built release.
			header( 'Content-Type: text/html; charset=utf-8' );
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- composed and escaped in render_html().
			exit;
		}

		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'sans-serif' );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		$filename = sprintf( 'quiz-review-attempt-%d.pdf', $attempt_id );

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: private, no-store' );

		echo $dompdf->output(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF.
		exit;
	}

	/**
	 * The print document. Inline styles only — Dompdf reads no external CSS,
	 * and a deterministic single template has no theme-override surface.
	 *
	 * @param object      $attempt     The attempt row.
	 * @param object|null $quiz        The quiz row.
	 * @param object|null $course      The course row.
	 * @param array       $groups      Groups from Quiz_Service::group_attempt_review().
	 * @param bool        $no_feedback Whether per-question detail is withheld.
	 */
	private static function render_html( object $attempt, ?object $quiz, ?object $course, array $groups, bool $no_feedback ): string {
		$site       = (string) get_bloginfo( 'name' );
		$quiz_title = $quiz ? (string) ( $quiz->title ?? '' ) : \Learnomy\quiz_label();
		$user       = get_userdata( (int) $attempt->user_id );
		$student    = $user ? ( trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name ) : '';
		$date       = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) ( $attempt->submitted_at ?: $attempt->started_at ) );
		$pct        = (float) ( $attempt->percentage ?? 0 );
		$passed     = ! empty( $attempt->passed );

		ob_start();
		?>
<meta charset="utf-8">
<style>
	/* Core PDF fonts only. The scoped php-font-lib cannot load TTFs (the
		scoper prefix breaks its dynamic class lookup), so unicode glyphs like
		the check mark render as "?" — states are carried by color + text
		labels instead. Same constraint as every other Learnomy PDF. */
	body { font-family: sans-serif; font-size: 11px; color: #222; margin: 24px; }
	h1 { font-size: 18px; margin: 0 0 2px; }
	h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 3px; }
	.meta { color: #555; margin: 0 0 14px; }
	.score { font-size: 14px; font-weight: bold; margin: 10px 0 2px; }
	.badge { display: inline-block; padding: 1px 8px; border-radius: 8px; font-size: 10px; }
	.badge-pass { background: #d9f2e0; color: #14683c; }
	.badge-fail { background: #fbdcdc; color: #99231f; }
	.tally { color: #666; font-weight: normal; font-size: 10px; }
	.q { margin: 0 0 10px; padding: 6px 8px; border-left: 3px solid #bbb; background: #fafafa; page-break-inside: avoid; }
	.q-correct { border-left-color: #1f9d55; }
	.q-incorrect { border-left-color: #d33; }
	.q-pending { border-left-color: #d97706; }
	.q-title { font-weight: bold; margin: 0 0 4px; }
	.q-score { float: right; color: #555; font-weight: normal; }
	.ans { margin: 1px 0 1px 12px; }
	.ans-correct { color: #14683c; }
	.ans-wrong { color: #99231f; }
	.given { margin: 4px 0 0 12px; color: #444; font-style: italic; }
	.note { color: #666; font-style: italic; }
	.footer { margin-top: 18px; color: #888; font-size: 9px; border-top: 1px solid #ddd; padding-top: 6px; }
</style>
<h1><?php echo esc_html( $quiz_title ); ?></h1>
<p class="meta">
		<?php echo esc_html( $site ); ?>
		<?php if ( $course && ! empty( $course->title ) ) : ?>
		&middot; <?php echo esc_html( (string) $course->title ); ?>
	<?php endif; ?>
	<br>
		<?php
		/* translators: %s: student display name */
		echo esc_html( sprintf( __( 'Student: %s', 'learnomy' ), $student ) );
		?>
	&middot; <?php echo esc_html( $date ); ?>
</p>
<p class="score">
		<?php
		/* translators: %s: score percentage */
		echo esc_html( sprintf( __( 'Score: %s%%', 'learnomy' ), number_format_i18n( $pct, 2 ) ) );
		?>
	<span class="badge <?php echo $passed ? 'badge-pass' : 'badge-fail'; ?>">
		<?php echo $passed ? esc_html__( 'Passed', 'learnomy' ) : esc_html__( 'Not passed', 'learnomy' ); ?>
	</span>
</p>

		<?php if ( $no_feedback ) : ?>
	<p class="note"><?php esc_html_e( 'Per-question detail is not shown for this quiz.', 'learnomy' ); ?></p>
		<?php else : ?>
			<?php foreach ( $groups as $group ) : ?>
				<?php if ( '' !== (string) $group['label'] ) : ?>
			<h2><?php echo esc_html( (string) $group['label'] ); ?> <span class="tally"><?php echo esc_html( (string) $group['summary'] ); ?></span></h2>
		<?php endif; ?>
				<?php foreach ( (array) $group['rows'] as $row ) : ?>
					<?php
					$state = ! empty( $row['correct'] ) ? 'q-correct' : ( ! empty( $row['pending'] ) ? 'q-pending' : 'q-incorrect' );
					?>
			<div class="q <?php echo esc_attr( $state ); ?>">
				<p class="q-title">
					<?php if ( isset( $row['score'] ) ) : ?>
						<span class="q-score"><?php echo esc_html( (string) (float) $row['score'] ); ?>/<?php echo esc_html( (string) (float) ( $row['max_score'] ?? 0 ) ); ?></span>
					<?php endif; ?>
					<?php echo esc_html( (string) ( $row['title'] ?? '' ) ); ?>
				</p>
					<?php foreach ( (array) ( $row['answers'] ?? array() ) as $ans ) : ?>
						<?php $cls = ! empty( $ans['correct'] ) ? 'ans-correct' : ( ! empty( $ans['wrong'] ) ? 'ans-wrong' : '' ); ?>
					<p class="ans <?php echo esc_attr( $cls ); ?>">
						&bull; <?php echo esc_html( (string) ( $ans['content'] ?? '' ) ); ?>
						<?php if ( ! empty( $ans['correct'] ) ) : ?>
							&mdash; <?php esc_html_e( 'correct answer', 'learnomy' ); ?>
						<?php endif; ?>
						<?php if ( ! empty( $ans['selected'] ) ) : ?>
							<em>(<?php esc_html_e( 'your answer', 'learnomy' ); ?>)</em>
						<?php endif; ?>
					</p>
				<?php endforeach; ?>
					<?php if ( null !== ( $row['given'] ?? null ) && '' !== (string) $row['given'] ) : ?>
					<p class="given">
						<?php esc_html_e( 'Your response:', 'learnomy' ); ?>
						<?php echo esc_html( (string) $row['given'] ); ?>
					</p>
				<?php endif; ?>
					<?php if ( ! empty( $row['pending'] ) ) : ?>
					<p class="note"><?php esc_html_e( 'Awaiting instructor review', 'learnomy' ); ?></p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	<?php endforeach; ?>
		<?php endif; ?>

<p class="footer">
		<?php
		/* translators: 1: site name, 2: attempt id */
		echo esc_html( sprintf( __( 'Generated by %1$s - attempt #%2$d', 'learnomy' ), $site, (int) $attempt->id ) );
		?>
</p>
		<?php
		return (string) ob_get_clean();
	}
}
