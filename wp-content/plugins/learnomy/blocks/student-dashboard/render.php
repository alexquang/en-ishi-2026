<?php
/**
 * Render: learnomy/student-dashboard
 *
 * @package Learnomy
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Enrollment;
use Learnomy\Models\Course;

ob_start();

if ( ! is_user_logged_in() ) {
	?>
	<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
	<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-student-dashboard' ) ); ?>>
		<p class="lrn-empty-state">
			<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="lrn-btn lrn-btn--secondary">
				<?php esc_html_e( 'Log in to view your dashboard', 'learnomy' ); ?>
			</a>
		</p>
	</div>
	<?php
	\Learnomy\Blocks::render_filtered( 'student-dashboard', ob_get_clean(), $attributes );
	return;
}

$user_id = get_current_user_id();
// get_distinct_for_user avoids duplicate course cards (CF-007) and already
// caps at 200 rows — appropriate for a dashboard widget.
$enrollments = Enrollment::get_distinct_for_user( $user_id );

if ( empty( $enrollments ) ) {
	?>
	<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
	<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-student-dashboard' ) ); ?>>
		<p class="lrn-empty-state"><?php esc_html_e( 'You have not enrolled in any courses yet.', 'learnomy' ); ?></p>
	</div>
	<?php
	\Learnomy\Blocks::render_filtered( 'student-dashboard', ob_get_clean(), $attributes );
	return;
}

$current_user = wp_get_current_user();

// Batch-fetch all course rows in one query to eliminate the per-enrollment
// Course::find() loop below (N+1 on a 20-item dashboard = 20 extra queries).
$dash_course_ids = array_map( fn( $e ) => (int) $e->course_id, $enrollments );
$dash_course_map = Course::find_in( $dash_course_ids );
?>
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-student-dashboard lrn-container' ) ); ?>>
	<div class="lrn-student-dashboard__header">
		<h2 class="lrn-student-dashboard__greeting">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: user display name */
					__( 'Welcome back, %s', 'learnomy' ),
					$current_user->display_name
				)
			);
			?>
		</h2>
		<span class="lrn-student-dashboard__count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of enrolled courses */
					_n( '%d course enrolled', '%d courses enrolled', count( $enrollments ), 'learnomy' ),
					count( $enrollments )
				)
			);
			?>
		</span>
	</div>
	<div class="lrn-grid lrn-grid--cols-2">
		<?php foreach ( $enrollments as $enrollment ) : ?>
			<?php
			$course = $dash_course_map[ (int) $enrollment->course_id ] ?? null;
			if ( ! $course ) {
				continue;
			}
			$progress     = absint( $enrollment->progress_pct ?? 0 );
			$course_url   = \Learnomy\route_url( 'course', array( 'slug' => $course->slug ?? '' ) );
			$status       = esc_attr( $enrollment->status ?? 'active' );
			$thumbnail_id = absint( $course->thumbnail_id ?? 0 );
			?>
			<div class="lrn-student-dashboard__item lrn-card" data-status="<?php echo esc_attr( $status ); ?>">
				<?php if ( $thumbnail_id ) : ?>
					<div class="lrn-student-dashboard__thumbnail">
						<?php echo wp_get_attachment_image( $thumbnail_id, 'thumbnail', false, array( 'loading' => 'lazy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
				<?php endif; ?>
				<div class="lrn-student-dashboard__item-body">
					<a href="<?php echo esc_url( $course_url ); ?>" class="lrn-student-dashboard__course-title">
						<?php echo esc_html( $course->title ?? '' ); ?>
					</a>
					<?php
					// Canonical partial — ARIA and fill markup in one place.
					// Its "% complete" label is the ONE label; the bare "%" span
					// that used to follow doubled it (Basecamp 10171762145).
					$percent = $progress;
					include \Learnomy\template_path( 'partials/progress-bar.php' );
					?>
					<?php if ( 'completed' === $enrollment->status ) : ?>
						<span class="lrn-badge lrn-badge--success"><?php esc_html_e( 'Completed', 'learnomy' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
</div>
<?php
\Learnomy\Blocks::render_filtered( 'student-dashboard', ob_get_clean(), $attributes );
