<?php
/**
 * Announcements widget body.
 *
 * Locals: $announcements, $announcement_courses, $announcement_actors
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $announcements ) ) {
	return;
}
?>
<ul class="lrn-activity-list">
	<?php
	foreach ( $announcements as $ann ) :
		$ann_course  = ( (int) $ann->object_id > 0 ) ? ( $announcement_courses[ (int) $ann->object_id ] ?? null ) : null;
		$ann_actor   = ! empty( $ann->actor_id ) ? ( $announcement_actors[ (int) $ann->actor_id ] ?? null ) : null;
		$ann_time    = human_time_diff( strtotime( $ann->created_at ), time() );
		$actor_name  = $ann_actor ? $ann_actor->display_name : __( 'Admin', 'learnomy' );
		$course_name = $ann_course ? $ann_course->title : '';
		?>
		<li class="lrn-activity-list__item">
			<i data-lucide="megaphone" class="lrn-activity-list__icon" aria-hidden="true"></i>
			<span class="lrn-activity-list__text">
				<span class="lrn-announcement__head">
					<?php if ( $course_name ) : ?>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: instructor name. 2: course title. 3: announcement title. */
								__( '%1$s posted in %2$s: "%3$s"', 'learnomy' ),
								$actor_name,
								$course_name,
								$ann->title
							)
						);
						?>
					<?php else : ?>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: instructor name. 2: announcement title. */
								__( '%1$s announced: "%2$s"', 'learnomy' ),
								$actor_name,
								$ann->title
							)
						);
						?>
					<?php endif; ?>
				</span>
				<?php if ( ! empty( $ann->message ) ) : ?>
					<span class="lrn-announcement__body"><?php echo nl2br( esc_html( (string) $ann->message ) ); ?></span>
				<?php endif; ?>
			</span>
			<time class="lrn-activity-list__time" datetime="<?php echo esc_attr( $ann->created_at ); ?>">
				<?php
				/* translators: %s: human-readable time difference */
				echo esc_html( sprintf( __( '%s ago', 'learnomy' ), $ann_time ) );
				?>
			</time>
		</li>
	<?php endforeach; ?>
</ul>
