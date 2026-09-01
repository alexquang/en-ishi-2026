<?php
/**
 * "My Membership" widget body.
 *
 * Locals: $membership_sub, $membership_plan
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $membership_sub ) ) {
	return;
}

$plan = $membership_plan ?? null;
?>
<div class="lrn-membership-card__header">
	<h3><?php echo $plan ? esc_html( $plan->title ) : esc_html__( 'Membership', 'learnomy' ); ?></h3>
	<span class="lrn-badge lrn-badge--green"><?php esc_html_e( 'Active', 'learnomy' ); ?></span>
</div>
<?php if ( ! empty( $membership_sub->next_billing_date ) ) : ?>
	<p>
		<?php
		$lrn_when = date_i18n( get_option( 'date_format' ), strtotime( $membership_sub->next_billing_date ) );
		echo esc_html(
			\Learnomy\subscription_ends_at_period_end( $membership_sub )
				/* translators: %s: date access ends. */
				? sprintf( __( 'Ends %s', 'learnomy' ), $lrn_when )
				/* translators: %s: next billing date. */
				: sprintf( __( 'Renews %s', 'learnomy' ), $lrn_when )
		);
		?>
	</p>
<?php endif; ?>
<a href="<?php echo esc_url( \Learnomy\route_url( 'membership-manage' ) ); ?>" class="lrn-btn lrn-btn--ghost lrn-btn--sm">
	<?php esc_html_e( 'Manage', 'learnomy' ); ?>
</a>
