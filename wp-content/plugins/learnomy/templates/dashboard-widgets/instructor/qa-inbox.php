<?php
/**
 * Instructor Q&A inbox widget body.
 *
 * Unanswered student questions on lessons in courses the current
 * instructor authors. Each row links to the lesson page (where the
 * comment thread lives) so the instructor lands in context to reply.
 *
 * Locals: $user_id (top-level local from class-free-dashboard-widgets).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$pending = \Learnomy\Models\Lesson_Comment::pending_for_instructor( (int) $user_id, 5 );
if ( empty( $pending ) ) {
	?>
	<div class="lrn-empty-state">
		<i data-lucide="message-circle" class="lrn-empty-state__icon" aria-hidden="true"></i>
		<h3 class="lrn-empty-state__title"><?php esc_html_e( 'All caught up', 'learnomy' ); ?></h3>
		<p class="lrn-empty-state__desc"><?php esc_html_e( 'No pending student questions on your lessons.', 'learnomy' ); ?></p>
	</div>
	<?php
	return;
}

// Batch-fetch lesson + course rows once (id => row) so the loop doesn't run a
// Lesson::find()/Course::find() per row (N+1).
$lrn_lesson_ids = array_values( array_unique( array_filter( array_map( static fn( $r ) => (int) ( $r->lesson_id ?? 0 ), $pending ) ) ) );
$lrn_course_ids = array_values( array_unique( array_filter( array_map( static fn( $r ) => (int) ( $r->course_id ?? 0 ), $pending ) ) ) );
$lrn_lessons    = $lrn_lesson_ids ? \Learnomy\Models\Lesson::find_in( $lrn_lesson_ids ) : array();
$lrn_courses    = $lrn_course_ids ? \Learnomy\Models\Course::find_in( $lrn_course_ids ) : array();
?>
<ul class="lrn-qa-inbox">
	<?php
	foreach ( $pending as $row ) :
		$lesson   = ( $row->course_title && $row->lesson_title ) ? ( $lrn_lessons[ (int) $row->lesson_id ] ?? null ) : null;
		$course   = (int) $row->course_id > 0 ? ( $lrn_courses[ (int) $row->course_id ] ?? null ) : null;
		$url      = $lesson && $course && $lesson->slug && $course->slug
			? \Learnomy\route_url(
				'lesson',
				array(
					'slug'        => (string) $course->slug,
					'lesson_slug' => (string) $lesson->slug,
				)
			) . '#comment-' . (int) $row->comment_id
			: '#';
		$time_ago = human_time_diff( strtotime( (string) $row->comment_date ), time() );
		?>
		<li class="lrn-qa-inbox__item">
			<a href="<?php echo esc_url( $url ); ?>" class="lrn-qa-inbox__link">
				<?php echo get_avatar( (int) $row->author_id, 32, '', $row->author_name, array( 'class' => 'lrn-qa-inbox__avatar' ) ); ?>
				<div class="lrn-qa-inbox__body">
					<p class="lrn-qa-inbox__meta">
						<strong><?php echo esc_html( $row->author_name ); ?></strong>
						<?php esc_html_e( 'asked in', 'learnomy' ); ?>
						<em><?php echo esc_html( $row->lesson_title ); ?></em>
					</p>
					<p class="lrn-qa-inbox__excerpt">
						<?php echo esc_html( wp_trim_words( $row->content, 28 ) ); ?>
					</p>
				</div>
			</a>
			<time class="lrn-qa-inbox__time" datetime="<?php echo esc_attr( (string) $row->comment_date ); ?>">
				<?php
				/* translators: %s: human-readable time diff */
				echo esc_html( sprintf( __( '%s ago', 'learnomy' ), $time_ago ) );
				?>
			</time>
		</li>
	<?php endforeach; ?>
</ul>
