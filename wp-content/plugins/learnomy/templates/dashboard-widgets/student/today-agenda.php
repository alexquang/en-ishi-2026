<?php
/**
 * Student dashboard — "Today's plan" widget body.
 *
 * Surfaces a unified, chronologically-ordered list of items happening
 * today across modules (assignments due / lessons unlocking / cohort
 * sessions). The aggregator lives at
 * `Free_Dashboard_Widgets::today_agenda_items( $ctx )` — see that
 * method for the data shape + per-source notes.
 *
 * Locals from extract($view_data): every student view_data field
 * (unused here — the resolver pulls everything from $ctx).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// We re-fetch here rather than threading the items through view_data so
// the resolver remains the single source of truth and partials stay
// stateless. The cost is bounded — small N, mostly indexed queries.
$lrn_today_items = \Learnomy\Services\Free_Dashboard_Widgets::today_agenda_items(
	array( 'user_id' => (int) $user_id )
);

if ( empty( $lrn_today_items ) ) {
	return;
}

$tz = wp_timezone();
?>
<ul class="lrn-today-agenda">
	<?php
	foreach ( $lrn_today_items as $item ) :
		$time_str = ( new DateTimeImmutable( '@' . (int) $item['ts'] ) )
			->setTimezone( $tz )
			->format( get_option( 'time_format' ) );
		$linked   = ! empty( $item['url'] );
		?>
		<li class="lrn-today-agenda__item lrn-today-agenda__item--<?php echo esc_attr( (string) $item['kind'] ); ?>">
			<?php if ( $linked ) : ?>
				<a href="<?php echo esc_url( (string) $item['url'] ); ?>" class="lrn-today-agenda__link">
			<?php else : ?>
				<div class="lrn-today-agenda__link lrn-today-agenda__link--inert">
			<?php endif; ?>
				<i data-lucide="<?php echo esc_attr( (string) $item['icon'] ); ?>" class="lrn-today-agenda__icon" aria-hidden="true"></i>
				<div class="lrn-today-agenda__body">
					<span class="lrn-today-agenda__title">
						<?php echo esc_html( (string) $item['title'] ); ?>
					</span>
					<?php if ( ! empty( $item['subtitle'] ) ) : ?>
						<span class="lrn-today-agenda__subtitle"><?php echo esc_html( (string) $item['subtitle'] ); ?></span>
					<?php endif; ?>
				</div>
				<time class="lrn-today-agenda__time" datetime="<?php echo esc_attr( gmdate( 'c', (int) $item['ts'] ) ); ?>">
					<?php echo esc_html( $time_str ); ?>
				</time>
			<?php if ( $linked ) : ?>
				</a>
			<?php else : ?>
				</div>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
