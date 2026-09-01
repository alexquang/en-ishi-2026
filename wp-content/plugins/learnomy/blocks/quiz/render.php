<?php
/**
 * Render: learnomy/quiz
 *
 * @package Learnomy
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Quiz;

ob_start();

$quiz_id = isset( $attributes['quizId'] ) ? absint( $attributes['quizId'] ) : 0;

if ( ! $quiz_id ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'Select a quiz to embed.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

$quiz = Quiz::find( $quiz_id );

if ( ! $quiz ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'The selected quiz no longer exists. Pick another in the block settings.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

$title       = esc_html( $quiz->title ?? '' );
$description = wp_kses_post( $quiz->description ?? '' );
$time_limit  = absint( $quiz->time_limit_seconds ?? 0 );

// Read the columns the quizzes table actually has. `pass_mark` and
// `attempts_allowed` were never fields of lrn_quizzes, so both rules resolved
// to 0 and the block rendered an empty <ul> on every quiz it embedded.
$pass_mark        = absint( $quiz->passing_score ?? 0 );
$attempts_allowed = Quiz::effective_attempt_limit( $quiz );

// The player lives on the quiz's own route, which is where the Interactivity
// module, the display template and Pro's per-slot extensions are wired up. The
// block is the intro card that sends the student there — it must not fork a
// third quiz surface that none of that reaches. A course-attached quiz keeps
// its course-scoped URL; a standalone one uses /quiz/<slug>/.
$quiz_course = empty( $quiz->course_id ) ? null : Course::find( (int) $quiz->course_id );
$quiz_url    = $quiz_course && ! empty( $quiz_course->slug )
	? \Learnomy\route_url(
		'quiz',
		array(
			'slug'      => (string) $quiz_course->slug,
			'quiz_slug' => (string) $quiz->slug,
		)
	)
	: \Learnomy\route_url( 'standalone-quiz', array( 'slug' => (string) $quiz->slug ) );

if ( $time_limit > 0 ) {
	$minutes = (int) floor( $time_limit / 60 );
	$seconds = $time_limit % 60;
	if ( $seconds > 0 ) {
		/* translators: 1: minutes, 2: seconds */
		$time_display = sprintf( __( '%1$d min %2$d sec', 'learnomy' ), $minutes, $seconds );
	} else {
		/* translators: %d: minutes */
		$time_display = sprintf( _n( '%d minute', '%d minutes', $minutes, 'learnomy' ), $minutes );
	}
} else {
	$time_display = '';
}

$is_logged_in = is_user_logged_in();
?>
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-quiz lrn-card' ) ); ?>
	data-quiz-id="<?php echo esc_attr( $quiz_id ); ?>">

	<div class="lrn-quiz__header">
		<h3 class="lrn-quiz__title"><?php echo esc_html( $title ); ?></h3>
	</div>

	<?php if ( $description ) : ?>
		<div class="lrn-quiz__description">
			<?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	<?php endif; ?>

	<?php if ( $pass_mark > 0 || $time_display || $attempts_allowed > 0 ) : ?>
	<ul class="lrn-quiz__rules" aria-label="<?php esc_attr_e( 'Quiz details', 'learnomy' ); ?>">
		<?php if ( $pass_mark > 0 ) : ?>
			<li class="lrn-quiz__rule">
				<strong><?php esc_html_e( 'Passing score:', 'learnomy' ); ?></strong>
				<?php echo esc_html( $pass_mark . '%' ); ?>
			</li>
		<?php endif; ?>
		<?php if ( $time_display ) : ?>
			<li class="lrn-quiz__rule">
				<strong><?php esc_html_e( 'Time limit:', 'learnomy' ); ?></strong>
				<?php echo esc_html( $time_display ); ?>
			</li>
		<?php endif; ?>
		<?php if ( $attempts_allowed > 0 ) : ?>
			<li class="lrn-quiz__rule">
				<strong><?php esc_html_e( 'Attempts allowed:', 'learnomy' ); ?></strong>
				<?php echo esc_html( $attempts_allowed ); ?>
			</li>
		<?php endif; ?>
	</ul>
	<?php endif; ?>

	<div class="lrn-quiz__actions">
		<?php if ( $is_logged_in ) : ?>
			<a
				href="<?php echo esc_url( $quiz_url ); ?>"
				class="lrn-btn lrn-btn--primary lrn-quiz__start"
				data-quiz-id="<?php echo esc_attr( $quiz_id ); ?>">
				<?php esc_html_e( 'Start Quiz', 'learnomy' ); ?>
			</a>
		<?php else : ?>
			<p class="lrn-quiz__login-notice">
				<a href="<?php echo esc_url( wp_login_url( $quiz_url ) ); ?>" class="lrn-btn lrn-btn--secondary">
					<?php esc_html_e( 'Log in to take this quiz', 'learnomy' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>
</div>
<?php
\Learnomy\Blocks::render_filtered( 'quiz', ob_get_clean(), $attributes );
