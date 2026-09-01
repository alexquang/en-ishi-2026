<?php
/**
 * Recent activity widget body.
 *
 * Locals: $recent_progress, $progress_lessons
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $recent_progress ) ) {
	return;
}
?>
<ul class="lrn-activity-list">
	<?php
	foreach ( $recent_progress as $progress ) :
		$lesson = $progress_lessons[ (int) $progress->item_id ] ?? null;
		if ( ! $lesson ) {
			continue;
		}
		$time_ago = human_time_diff( strtotime( $progress->updated_at ), time() );
		?>
		<?php
		// Verb AND icon come from the row's own status, through one shared
		// answerer - this template used to hardcode "Completed" with a green
		// check-circle for every row, whatever the learner had actually done
		// (Basecamp 10184532025).
		$announcement = \Learnomy\Models\Progress::activity_announcement( (string) ( $progress->status ?? '' ) );
		?>
		<li class="lrn-activity-list__item">
			<i data-lucide="<?php echo esc_attr( $announcement['icon'] ); ?>" class="lrn-activity-list__icon" aria-hidden="true"></i>
			<span class="lrn-activity-list__text">
				<?php echo esc_html( sprintf( $announcement['text'], $lesson->title ) ); ?>
			</span>
			<time class="lrn-activity-list__time" datetime="<?php echo esc_attr( $progress->updated_at ); ?>">
				<?php
				/* translators: %s: human-readable time difference */
				echo esc_html( sprintf( __( '%s ago', 'learnomy' ), $time_ago ) );
				?>
			</time>
		</li>
	<?php endforeach; ?>
</ul>
