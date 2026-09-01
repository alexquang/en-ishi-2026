<?php
/**
 * Render: learnomy/my-progress
 *
 * @package Learnomy
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Enrollment;
use Learnomy\Models\Course;
use Learnomy\Services\Resume;

ob_start();

if ( ! is_user_logged_in() ) {
	?>
	<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
	<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-my-progress' ) ); ?>>
		<p class="lrn-empty-state">
			<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="lrn-btn lrn-btn--secondary">
				<?php esc_html_e( 'Log in to see your progress', 'learnomy' ); ?>
			</a>
		</p>
	</div>
	<?php
	\Learnomy\Blocks::render_filtered( 'my-progress', ob_get_clean(), $attributes );
	return;
}

$user_id = get_current_user_id();
// get_distinct_for_user avoids duplicate course cards when a student has
// multiple enrollment sources (free + manual) for the same course (CF-007).
$enrollments = Enrollment::get_distinct_for_user( $user_id );

// Tile totals come from the COUNT methods, not count() over the fetched list:
// get_distinct_for_user() caps at 200 rows, so a learner with 200+ courses saw
// truncated tiles. count_distinct_for_user() is a COUNT and shares the exact
// completion predicate the My Courses tabs use.
$total_enrolled  = Enrollment::count_distinct_for_user( $user_id, 'all' );
$total_completed = Enrollment::count_distinct_for_user( $user_id, 'completed' );

// Build the "Continue Learning" rail from the fetched rows through the shared
// predicate (Enrollment::is_in_progress), NOT status==='completed' alone and NOT
// progress_pct — a percentage/manual completion may sit at its coverage % with a
// residual status and must not linger under "Continue" (Basecamp 10109147988),
// and a learner at 0% is in progress, not missing (Basecamp 10183993951).
$in_progress = array();
foreach ( $enrollments as $enrollment ) {
	if ( Enrollment::is_in_progress( $enrollment ) ) {
		$in_progress[] = $enrollment;
	}
}

// Each rail card now deep-links to the student's resume lesson (Resume::url),
// which costs a bounded read per card. Cap the rail on large accounts; the
// full set lives on the dashboard / My Courses. Filterable, 0 = uncapped.
$my_progress_max   = (int) apply_filters( 'learnomy_my_progress_max', 20 );
$in_progress_total = count( $in_progress );
if ( $my_progress_max > 0 && $in_progress_total > $my_progress_max ) {
	$in_progress = array_slice( $in_progress, 0, $my_progress_max );
}

// Batch-fetch all in-progress course rows in one query to eliminate the
// per-enrollment Course::find() loop below (N+1).
$ip_course_ids = array_map( fn( $e ) => (int) $e->course_id, $in_progress );
$ip_course_map = ! empty( $ip_course_ids ) ? Course::find_in( $ip_course_ids ) : array();

$overall_pct = $total_enrolled > 0 ? round( ( $total_completed / $total_enrolled ) * 100 ) : 0;
?>
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-my-progress lrn-container' ) ); ?>>
	<div class="lrn-my-progress__stats">
		<div class="lrn-my-progress__stat lrn-card">
			<span class="lrn-my-progress__stat-value"><?php echo esc_html( $total_enrolled ); ?></span>
			<span class="lrn-my-progress__stat-label"><?php esc_html_e( 'Courses Enrolled', 'learnomy' ); ?></span>
		</div>
		<div class="lrn-my-progress__stat lrn-card">
			<span class="lrn-my-progress__stat-value"><?php echo esc_html( $total_completed ); ?></span>
			<span class="lrn-my-progress__stat-label"><?php esc_html_e( 'Completed', 'learnomy' ); ?></span>
		</div>
		<div class="lrn-my-progress__stat lrn-card">
			<span class="lrn-my-progress__stat-value"><?php echo esc_html( $overall_pct . '%' ); ?></span>
			<span class="lrn-my-progress__stat-label"><?php esc_html_e( 'Overall Progress', 'learnomy' ); ?></span>
		</div>
	</div>

	<?php if ( ! empty( $in_progress ) ) : ?>
		<h3 class="lrn-my-progress__heading"><?php esc_html_e( 'Continue learning', 'learnomy' ); ?></h3>
		<div class="lrn-my-progress__list">
			<?php foreach ( $in_progress as $enrollment ) : ?>
				<?php
				$course = $ip_course_map[ (int) $enrollment->course_id ] ?? null;
				if ( ! $course ) {
					continue;
				}
				$pct = absint( $enrollment->progress_pct ?? 0 );
				// Deep-link to the lesson the student was last on, not the course
				// landing page — the shared resolver every "Continue" surface uses.
				$resume_url = Resume::url( $user_id, $course );
				?>
				<div class="lrn-my-progress__item lrn-card">
					<a href="<?php echo esc_url( $resume_url ); ?>" class="lrn-my-progress__course-title">
						<?php echo esc_html( $course->title ?? '' ); ?>
					</a>
					<?php
					// Use the canonical partial so ARIA markup stays in sync.
					// The partial's own "% complete" label is the ONE label - a
					// second bare "%" span under it doubled the figure (Basecamp
					// 10171762145; the partial documents this exact opt-in).
					$percent = $pct;
					include \Learnomy\template_path( 'partials/progress-bar.php' );
					?>
				</div>
			<?php endforeach; ?>
		</div>
			<?php if ( $my_progress_max > 0 && $in_progress_total > $my_progress_max ) : ?>
				<a href="<?php echo esc_url( \Learnomy\route_url( 'account-courses' ) ); ?>" class="lrn-my-progress__view-all">
					<?php
					/* translators: %d: number of additional in-progress courses not shown. */
					echo esc_html( sprintf( _n( 'View %d more course', 'View all %d courses', $in_progress_total, 'learnomy' ), $in_progress_total ) );
					?>
				</a>
			<?php endif; ?>
	<?php elseif ( $total_enrolled > 0 ) : ?>
		<p class="lrn-my-progress__all-done">
			<?php esc_html_e( 'You have completed all your enrolled courses.', 'learnomy' ); ?>
		</p>
	<?php else : ?>
		<p class="lrn-empty-state"><?php esc_html_e( 'You have not started any courses yet.', 'learnomy' ); ?></p>
	<?php endif; ?>
</div>
<?php
\Learnomy\Blocks::render_filtered( 'my-progress', ob_get_clean(), $attributes );
