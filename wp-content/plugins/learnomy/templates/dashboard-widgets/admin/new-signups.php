<?php
/**
 * Admin dashboard — New Signups feed widget.
 *
 * Latest 5 user registrations across any role (admins included, since
 * the dashboard owner usually wants to see EVERY new account, not just
 * students). Uses WordPress's `users` query — no custom Model needed.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$users = get_users(
	array(
		'number'  => 5,
		'orderby' => 'registered',
		'order'   => 'DESC',
		'fields'  => array( 'ID', 'display_name', 'user_email', 'user_registered' ),
	)
);
if ( empty( $users ) ) {
	return;
}
?>
<div class="lrn-admin-card lrn-mb-6">
	<h2 class="lrn-admin-card-heading">
		<?php esc_html_e( 'New Signups', 'learnomy' ); ?>
	</h2>
	<ul class="lrn-admin-feed">
		<?php
		foreach ( $users as $u ) :
			$registered = (int) ( $u->user_registered ? strtotime( $u->user_registered ) : 0 );
			$when       = $registered ? human_time_diff( $registered, time() ) : '';
			$primary    = '';
			$user_obj   = get_userdata( (int) $u->ID );
			if ( $user_obj && ! empty( $user_obj->roles ) ) {
				$role    = (string) $user_obj->roles[0];
				$primary = ucwords( str_replace( array( '_', '-' ), ' ', $role ) );
			}
			?>
			<li class="lrn-admin-feed__item">
				<?php echo get_avatar( (int) $u->ID, 28, '', $u->display_name, array( 'class' => 'lrn-admin-feed__avatar' ) ); ?>
				<div class="lrn-admin-feed__body">
					<span class="lrn-admin-feed__title">
						<strong><?php echo esc_html( $u->display_name ); ?></strong>
						<?php if ( ! empty( $u->user_email ) ) : ?>
							<span class="lrn-admin-feed__email"><?php echo esc_html( $u->user_email ); ?></span>
						<?php endif; ?>
					</span>
				</div>
				<?php if ( $primary ) : ?>
					<span class="lrn-badge lrn-badge--neutral"><?php echo esc_html( $primary ); ?></span>
				<?php endif; ?>
				<?php if ( $when ) : ?>
					<time class="lrn-admin-feed__time" datetime="<?php echo esc_attr( (string) $u->user_registered ); ?>">
						<?php
						/* translators: %s: human-readable time diff */
						echo esc_html( sprintf( __( '%s ago', 'learnomy' ), $when ) );
						?>
					</time>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<a href="<?php echo esc_url( admin_url( 'users.php?orderby=registered&order=desc' ) ); ?>" class="lrn-admin-btn">
		<?php esc_html_e( 'All users', 'learnomy' ); ?>
	</a>
</div>
