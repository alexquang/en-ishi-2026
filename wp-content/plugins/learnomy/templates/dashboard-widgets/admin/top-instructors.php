<?php
/**
 * Admin dashboard — Top Instructors widget.
 *
 * Top 5 instructors by lifetime commission earned (recipient_type =
 * 'instructor', amount > 0). Rank uses every commission row regardless
 * of payout_status so the leaderboard stays stable across payout runs.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$rows = \Learnomy\Models\Commission::top_instructors( 5 );
if ( empty( $rows ) ) {
	return;
}

$settings        = (array) get_option( 'learnomy_settings', array() );
$currency_symbol = isset( $settings['currency_symbol'] ) && '' !== $settings['currency_symbol']
	? (string) $settings['currency_symbol']
	: '$';
?>
<div class="lrn-admin-card lrn-mb-6">
	<h2 class="lrn-admin-card-heading">
		<?php printf( esc_html( /* translators: %s: plural label for instructors (e.g. "Coaches"). */ __( 'Top %s', 'learnomy' ) ), esc_html( \Learnomy\instructor_label( true ) ) ); ?>
	</h2>
	<ol class="lrn-admin-leaderboard">
		<?php
		foreach ( $rows as $i => $row ) :
			$user = get_user_by( 'id', (int) $row->instructor_id );
			if ( ! $user ) {
				continue;
			}
			$rank = $i + 1;
			?>
			<li class="lrn-admin-leaderboard__item">
				<span class="lrn-admin-leaderboard__rank">#<?php echo (int) $rank; ?></span>
				<?php echo get_avatar( (int) $row->instructor_id, 32, '', $user->display_name, array( 'class' => 'lrn-admin-leaderboard__avatar' ) ); ?>
				<div class="lrn-admin-leaderboard__body">
					<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . (int) $row->instructor_id ) ); ?>"
						class="lrn-admin-leaderboard__name">
						<?php echo esc_html( $user->display_name ); ?>
					</a>
					<span class="lrn-admin-leaderboard__sub"><?php echo esc_html( $user->user_email ); ?></span>
				</div>
				<span class="lrn-admin-leaderboard__total">
					<?php echo esc_html( \Learnomy\format_price( (float) $row->total_amount ) ); ?>
				</span>
			</li>
		<?php endforeach; ?>
	</ol>
</div>
