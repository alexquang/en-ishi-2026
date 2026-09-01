<?php
/**
 * Pending reviews widget body.
 *
 * Locals: $pending_reviews, $review_users, $review_courses
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $pending_reviews ) ) {
	return;
}

foreach ( array_slice( $pending_reviews, 0, 5 ) as $review ) :
	$reviewer     = get_userdata( (int) $review->user_id );
	$rev_course   = $review_courses[ (int) $review->course_id ] ?? null;
	$review_stars = (int) ( $review->rating ?? 0 );
	?>
	<div class="lrn-review-item">
		<?php if ( $reviewer ) : ?>
			<?php echo get_avatar( (int) $review->user_id, 32, '', $reviewer->display_name, array( 'class' => 'lrn-review-item__avatar' ) ); ?>
		<?php endif; ?>
		<div class="lrn-review-item__body">
			<p class="lrn-review-item__meta">
				<strong><?php echo $reviewer ? esc_html( $reviewer->display_name ) : esc_html__( 'Unknown', 'learnomy' ); ?></strong>
				<?php esc_html_e( 'on', 'learnomy' ); ?>
				<em><?php echo $rev_course ? esc_html( $rev_course->title ) : esc_html__( 'Unknown Course', 'learnomy' ); ?></em>
				<?php if ( $review_stars > 0 ) : ?>
					<span class="lrn-review-item__stars">
						<?php echo esc_html( str_repeat( "\xe2\x98\x85", $review_stars ) ); ?>
					</span>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $review->content ) ) : ?>
				<p class="lrn-review-item__text"><?php echo esc_html( wp_trim_words( $review->content, 30 ) ); ?></p>
			<?php endif; ?>
		</div>
		<div class="lrn-review-item__actions">
			<?php if ( $rev_course && ! empty( $rev_course->slug ) ) : ?>
				<a href="<?php echo esc_url( \Learnomy\route_url( 'course', array( 'slug' => (string) $rev_course->slug ) ) . '#reviews' ); ?>"
					class="lrn-btn lrn-btn--ghost lrn-btn--sm">
					<?php esc_html_e( 'View on course', 'learnomy' ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
<?php endforeach; ?>
