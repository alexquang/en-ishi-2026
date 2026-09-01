<?php
/**
 * Render: learnomy/instructor-card
 *
 * @package Learnomy
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Models\Instructor;

ob_start();

$user_id = isset( $attributes['userId'] ) ? absint( $attributes['userId'] ) : 0;

if ( ! $user_id ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'Select an instructor to feature in this card.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

$user = get_userdata( $user_id );

if ( ! $user ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'The selected user no longer exists. Pick another in the block settings.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

// Any user id produced an "instructor" card carrying that member's name, avatar
// and bio — on an LMS most accounts are students. The card renders only for
// someone who has a public instructor profile. Viewer 0 so the block previews
// in the editor exactly as a visitor sees it; the placeholder tells the editor
// why it is empty instead of leaving them guessing (it renders in the editor
// only, never on the front end).
if ( ! Instructor::has_public_profile( $user_id, 0 ) ) {
	ob_end_clean();
	echo \Learnomy\block_editor_placeholder( __( 'This user is not a public instructor yet. A profile appears once they author or co-teach a published course.', 'learnomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside helper.
	return;
}

$name   = esc_html( $user->display_name );
$bio    = esc_html( get_user_meta( $user_id, 'description', true ) );
$avatar = get_avatar( $user_id, 96, '', $user->display_name, array( 'class' => 'lrn-instructor-card__avatar' ) );

// Course count and average rating via model.
$stats = Course::get_instructor_card_stats( $user_id );

$course_count = absint( $stats->course_count );
$avg_rating   = number_format_i18n( (float) ( $stats->avg_rating ?? 0 ), 1 );
?>
<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() is safe WP core function. ?>
<div <?php echo get_block_wrapper_attributes( array( 'class' => 'lrn-instructor-card lrn-card' ) ); ?>>
	<div class="lrn-instructor-card__avatar-wrap">
		<?php echo $avatar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<div class="lrn-instructor-card__info">
		<h3 class="lrn-instructor-card__name"><?php echo esc_html( $name ); ?></h3>
		<?php if ( $bio ) : ?>
			<p class="lrn-instructor-card__bio"><?php echo esc_html( $bio ); ?></p>
		<?php endif; ?>
		<div class="lrn-instructor-card__meta">
			<span class="lrn-instructor-card__courses">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of courses */
						_n( '%d course', '%d courses', $course_count, 'learnomy' ),
						$course_count
					)
				);
				?>
			</span>
			<?php if ( (float) $avg_rating > 0 ) : ?>
				<span class="lrn-instructor-card__rating" aria-label="
				<?php
				/* translators: %s: average rating value */
				echo esc_attr( sprintf( __( 'Average rating: %s', 'learnomy' ), $avg_rating ) );
				?>
		">
					&#9733; <?php echo esc_html( $avg_rating ); ?>
				</span>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php
\Learnomy\Blocks::render_filtered( 'instructor-card', ob_get_clean(), $attributes );
