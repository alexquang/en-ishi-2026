<?php
/**
 * Student streak widget — small motivational header.
 *
 * Boundary: own data only. Surfaces nothing for students with no
 * active streak (a "0-day streak" widget would mock the user); the
 * is_available gate ensures the widget is silently absent.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$streak = \Learnomy\Models\Progress::current_streak( (int) $user_id );
if ( $streak <= 0 ) {
	return;
}

$label = 60 <= $streak
	? __( '60+ day streak', 'learnomy' )
	: sprintf(
		/* translators: %d: streak length in days */
		_n( '%d-day streak', '%d-day streak', $streak, 'learnomy' ),
		$streak
	);

$flavor = '';
if ( $streak >= 7 ) {
	$flavor = __( "You're on a roll. Keep it going.", 'learnomy' );
} elseif ( $streak >= 3 ) {
	$flavor = __( 'A few more days will make it stick.', 'learnomy' );
} else {
	$flavor = __( 'Even small consistency compounds.', 'learnomy' );
}
?>
<section class="lrn-dashboard__section" data-widget-id="streak">
	<div class="lrn-streak">
		<i data-lucide="flame" class="lrn-streak__icon" aria-hidden="true"></i>
		<div class="lrn-streak__body">
			<span class="lrn-streak__count"><?php echo esc_html( $label ); ?></span>
			<span class="lrn-streak__flavor"><?php echo esc_html( $flavor ); ?></span>
		</div>
	</div>
</section>
