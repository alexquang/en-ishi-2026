<?php
/**
 * Lesson — embedded sidebar nav item (one curriculum row).
 *
 * Renders a single lesson / topic row in the persistent right-rail nav of the
 * embedded layout: a number-or-check leading marker (done / active / locked /
 * pending), the label (link unless current/locked), and the duration.
 *
 * Included via `include` from templates/lesson/embedded.php, inheriting:
 *   $sl                 (object) lesson or topic row
 *   $sl_url             (string) lesson_url() for this row
 *   $sl_done            (bool)   completed
 *   $sl_current         (bool)   the lesson being viewed
 *   $sl_locked          (bool)   sequential / drip locked
 *   $lrn_lesson_number  (int)    1-based position (shown when not done)
 *   $lrn_is_sub         (bool)   true for nested topics (optional)
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// "Done" and "current" are not alternatives — the row a student marks complete
// is BOTH. The old if/elseif dropped --done from that row, so the check marker
// (chosen below, which DID test done first) kept the current-row accent instead
// of the completed green (Basecamp 10134140321). Only --locked is genuinely
// exclusive: a finished lesson is never locked.
$lrn_item_class = 'lrn-lesson-side__item';
if ( ! empty( $lrn_is_sub ) ) {
	$lrn_item_class .= ' lrn-lesson-side__item--sub';
}
if ( $sl_done ) {
	$lrn_item_class .= ' lrn-lesson-side__item--done';
}
if ( $sl_current ) {
	$lrn_item_class .= ' lrn-lesson-side__item--current';
}
if ( $sl_locked && ! $sl_done ) {
	$lrn_item_class .= ' lrn-lesson-side__item--locked';
}
?>
<li class="<?php echo esc_attr( $lrn_item_class ); ?>">
	<span class="lrn-lesson-side__marker" aria-hidden="true">
		<?php if ( $sl_done ) : ?>
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
		<?php elseif ( $sl_locked ) : ?>
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
		<?php else : ?>
			<span class="lrn-lesson-side__number"><?php echo esc_html( (string) $lrn_lesson_number ); ?></span>
		<?php endif; ?>
	</span>

	<?php if ( $sl_current ) : ?>
		<span class="lrn-lesson-side__label" aria-current="true"><?php echo esc_html( $sl->title ); ?></span>
	<?php elseif ( $sl_locked ) : ?>
		<span class="lrn-lesson-side__label is-locked" aria-disabled="true" title="<?php esc_attr_e( 'Complete the previous lessons to unlock this one.', 'learnomy' ); ?>"><?php echo esc_html( $sl->title ); ?></span>
	<?php else : ?>
		<a href="<?php echo esc_url( $sl_url ); ?>" class="lrn-lesson-side__label"><?php echo esc_html( $sl->title ); ?></a>
	<?php endif; ?>

	<?php if ( ! empty( $sl->duration ) ) : ?>
		<span class="lrn-lesson-side__duration"><?php echo esc_html( $sl->duration ); ?></span>
	<?php endif; ?>
</li>
