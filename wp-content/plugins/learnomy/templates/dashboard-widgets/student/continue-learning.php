<?php
/**
 * Continue-learning hero widget — surfaces the lesson the student should
 * resume next. Powered by view_data['resume_target'].
 *
 * Locals from extract($view_data): $resume_target (array).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $resume_target ) ) {
	return;
}

$rt_course = $resume_target['course'];
$rt_lesson = $resume_target['lesson'];
$rt_url    = $resume_target['url'];
$rt_pos    = (int) $resume_target['position'];
$rt_total  = (int) $resume_target['total_lessons'];
$rt_pct    = (int) $resume_target['progress_pct'];
?>
<div class="lrn-resume-hero">
	<div class="lrn-resume-hero__body">
		<p class="lrn-resume-hero__eyebrow"><?php esc_html_e( 'Continue learning', 'learnomy' ); ?></p>
		<h2 class="lrn-resume-hero__title"><?php echo esc_html( $rt_course->title ); ?></h2>
		<p class="lrn-resume-hero__meta">
			<?php
			if ( $rt_total > 0 ) {
				printf(
					/* translators: 1: lesson title, 2: current position, 3: total lessons */
					esc_html__( '%1$s · Lesson %2$d of %3$d', 'learnomy' ),
					esc_html( $rt_lesson->title ),
					absint( $rt_pos ),
					absint( $rt_total )
				);
			} else {
				echo esc_html( $rt_lesson->title );
			}
			?>
		</p>
		<?php if ( $rt_pct > 0 ) : ?>
			<div class="lrn-resume-hero__progress" role="progressbar"
				aria-valuenow="<?php echo esc_attr( (string) $rt_pct ); ?>"
				aria-valuemin="0" aria-valuemax="100"
				aria-label="<?php echo esc_attr( sprintf( /* translators: %d: percentage */ __( 'Course progress: %d%%', 'learnomy' ), $rt_pct ) ); ?>">
				<span class="lrn-resume-hero__progress-fill" style="--pct: <?php echo esc_attr( (string) $rt_pct ); ?>%"></span>
			</div>
		<?php endif; ?>
	</div>
	<div class="lrn-resume-hero__actions">
		<a href="<?php echo esc_url( $rt_url ); ?>" class="lrn-btn lrn-btn--primary lrn-btn--lg">
			<i data-lucide="play-circle" aria-hidden="true"></i>
			<?php esc_html_e( 'Resume', 'learnomy' ); ?>
		</a>
		<a href="<?php echo esc_url( \Learnomy\route_url( 'course', array( 'slug' => (string) $rt_course->slug ) ) ); ?>" class="lrn-btn lrn-btn--ghost">
			<?php esc_html_e( 'Course home', 'learnomy' ); ?>
		</a>
	</div>
</div>
