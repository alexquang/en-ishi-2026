<?php
/**
 * Admin dashboard — Recent Transactions feed widget.
 *
 * Latest 5 completed transactions sitewide. Each row shows the buyer,
 * amount + currency, the item (course / membership plan / gift / etc.),
 * and a human-readable time-diff.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$rows = \Learnomy\Models\Transaction::recent_completed( 5 );
if ( empty( $rows ) ) {
	return;
}

$settings        = (array) get_option( 'learnomy_settings', array() );
$currency_symbol = isset( $settings['currency_symbol'] ) && '' !== $settings['currency_symbol']
	? (string) $settings['currency_symbol']
	: '$';

// Batch-fetch course + plan rows once (id => row) so the loop doesn't run a
// Course::find()/MembershipPlan::find() per row (N+1).
$lrn_course_ids = array_values( array_unique( array_filter( array_map( static fn( $r ) => 'course' === (string) ( $r->transaction_type ?? '' ) ? (int) ( $r->course_id ?? 0 ) : 0, $rows ) ) ) );
$lrn_plan_ids   = array_values( array_unique( array_filter( array_map( static fn( $r ) => in_array( (string) ( $r->transaction_type ?? '' ), array( 'membership', 'membership_renewal' ), true ) ? (int) ( $r->plan_id ?? 0 ) : 0, $rows ) ) ) );
$lrn_courses    = $lrn_course_ids ? \Learnomy\Models\Course::find_in( $lrn_course_ids ) : array();
$lrn_plans      = $lrn_plan_ids ? \Learnomy\Models\MembershipPlan::find_in( $lrn_plan_ids ) : array();
?>
<div class="lrn-admin-card lrn-mb-6">
	<h2 class="lrn-admin-card-heading">
		<?php esc_html_e( 'Recent Transactions', 'learnomy' ); ?>
	</h2>
	<ul class="lrn-admin-feed">
		<?php
		foreach ( $rows as $row ) :
			$user      = (int) $row->user_id > 0 ? get_user_by( 'id', (int) $row->user_id ) : null;
			$buyer     = $user ? $user->display_name : __( 'Guest', 'learnomy' );
			$kind      = (string) ( $row->transaction_type ?? 'purchase' );
			$item_text = '';
			if ( 'course' === $kind && ! empty( $row->course_id ) ) {
				$course    = $lrn_courses[ (int) $row->course_id ] ?? null;
				$item_text = $course ? $course->title : __( 'Course', 'learnomy' );
			} elseif ( in_array( $kind, array( 'membership', 'membership_renewal' ), true ) && ! empty( $row->plan_id ) ) {
				$plan      = $lrn_plans[ (int) $row->plan_id ] ?? null;
				$item_text = $plan ? $plan->title : __( 'Membership', 'learnomy' );
			} else {
				$item_text = ucwords( str_replace( '_', ' ', $kind ) );
			}
			$when = human_time_diff( strtotime( (string) $row->created_at ), time() );
			// See refund-overview.php: this mixed an ISO code with a symbol
			// fallback and printed it straight before the number.
			$cur = (string) ( $row->currency ?: '' );
			?>
			<li class="lrn-admin-feed__item">
				<?php if ( $user ) : ?>
					<?php echo get_avatar( (int) $row->user_id, 28, '', $buyer, array( 'class' => 'lrn-admin-feed__avatar' ) ); ?>
				<?php else : ?>
					<i data-lucide="user" class="lrn-admin-feed__avatar lrn-admin-feed__avatar--placeholder" aria-hidden="true"></i>
				<?php endif; ?>
				<div class="lrn-admin-feed__body">
					<span class="lrn-admin-feed__title">
						<strong><?php echo esc_html( $buyer ); ?></strong>
						<?php esc_html_e( 'bought', 'learnomy' ); ?>
						<em><?php echo esc_html( $item_text ); ?></em>
					</span>
				</div>
				<span class="lrn-admin-feed__value">
					<?php echo esc_html( \Learnomy\format_price( (float) $row->amount, '' !== $cur ? $cur : null ) ); ?>
				</span>
				<time class="lrn-admin-feed__time" datetime="<?php echo esc_attr( (string) $row->created_at ); ?>">
					<?php
					/* translators: %s: human-readable time diff */
					echo esc_html( sprintf( __( '%s ago', 'learnomy' ), $when ) );
					?>
				</time>
			</li>
		<?php endforeach; ?>
	</ul>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=learnomy-earnings' ) ); ?>" class="lrn-admin-btn">
		<?php esc_html_e( 'View all earnings', 'learnomy' ); ?>
	</a>
</div>
