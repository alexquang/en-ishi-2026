<?php
/**
 * Notification bell — floating bell with dropdown panel.
 *
 * Included via wp_footer on all Learnomy frontend pages for logged-in users.
 * Uses the Interactivity API store `learnomy/notifications`.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

$bell_user_id = get_current_user_id();
$unread_count = \Learnomy\Models\Notification::get_unread_count( $bell_user_id );
$unread_items = \Learnomy\Models\Notification::get_unread_for_user( $bell_user_id, 10 );

$icon_map = array(
	'enrollment'           => 'graduation-cap',
	'course_completed'     => 'check-circle',
	'certificate_issued'   => 'award',
	'quiz_submitted'       => 'pencil',
	'quiz_result'          => 'bar-chart-3',
	'application_approved' => 'user-check',
	'application_rejected' => 'user-x',
	'payment_receipt'      => 'credit-card',
	'withdrawal_approved'  => 'banknote',
	'review_submitted'     => 'star',
	'membership_created'   => 'users',
	'membership_cancelled' => 'x-circle',
	'membership_renewed'   => 'refresh-cw',
	'membership_expired'   => 'clock',
	'announcement'         => 'megaphone',
);
?>
<?php
/*
 * Dark-mode toggle — HIDDEN by default. Learnomy follows the theme's colour
 * scheme; a plugin-only switch floating over someone else's theme is a way for
 * the two to disagree, not a feature. The markup stays so a site owner on a
 * theme with no dark mode of its own can hand the switch back to their members
 * via `learnomy_standalone_dark_mode`.
 *
 * Same helper the <body> class asks (\Learnomy\standalone_dark_mode()) — the
 * toggle and the palette are two halves of one decision and must never be able
 * to disagree, which is what a second `has_active_bridge()` check here would
 * eventually allow.
 */
if ( \Learnomy\standalone_dark_mode() ) :
	?>
	<button type="button"
		class="lrn-dark-toggle"
		data-wp-interactive="learnomy/app"
		data-wp-on--click="actions.toggleDarkMode"
		aria-label="<?php esc_attr_e( 'Toggle dark mode', 'learnomy' ); ?>">
		<i data-lucide="moon" class="lrn-dark-toggle__icon lrn-dark-toggle__icon--moon" aria-hidden="true"></i>
		<i data-lucide="sun" class="lrn-dark-toggle__icon lrn-dark-toggle__icon--sun" aria-hidden="true"></i>
	</button>
	<?php
endif;
?>

<?php
// Outside-click and Escape close through the STORE, not by writing to the DOM.
// The panel's visibility is bound to context.open, so a raw `panel.hidden =
// true` closed it visually while leaving context.open true — after which the
// next bell click merely flipped context.open back to false and looked like a
// dead button. One source of truth, or the two disagree.
?>
<div class="lrn-notification-bell"
	data-wp-interactive="learnomy/notifications"
	data-wp-context='{"open":false,"unreadCount":<?php echo (int) $unread_count; ?>}'
	data-wp-on-document--click="actions.closeOnOutsideClick"
	data-wp-on-document--keydown="actions.closeOnEscape">

	<button type="button"
		class="lrn-notification-bell__trigger"
		data-wp-on--click="actions.togglePanel"
		aria-haspopup="true"
		data-wp-bind--aria-expanded="context.open"
		aria-label="<?php esc_attr_e( 'Notifications', 'learnomy' ); ?>">
		<i data-lucide="bell" aria-hidden="true"></i>
		<?php if ( $unread_count > 0 ) : ?>
			<span class="lrn-notification-bell__count" data-wp-text="context.unreadCount">
				<?php echo esc_html( $unread_count ); ?>
			</span>
		<?php endif; ?>
	</button>

	<div class="lrn-notification-bell__panel"
		data-wp-bind--hidden="!context.open"
		hidden>

		<div class="lrn-notification-bell__header">
			<h4 class="lrn-notification-bell__title"><?php esc_html_e( 'Notifications', 'learnomy' ); ?></h4>
			<?php if ( $unread_count > 0 ) : ?>
				<button type="button"
					class="lrn-notification-bell__mark-all"
					data-wp-on--click="actions.markAllRead">
					<?php esc_html_e( 'Mark all read', 'learnomy' ); ?>
				</button>
			<?php endif; ?>
		</div>

		<ul class="lrn-notification-bell__list">
			<?php if ( empty( $unread_items ) ) : ?>
				<li class="lrn-notification-bell__empty">
					<?php esc_html_e( 'No new notifications.', 'learnomy' ); ?>
				</li>
			<?php else : ?>
				<?php
				foreach ( $unread_items as $notif ) :
					$n_icon    = $icon_map[ $notif->type ] ?? 'bell';
					$n_time    = human_time_diff( strtotime( $notif->created_at ), time() );
					$n_is_read = (int) $notif->is_read;
					// Derived from object_type + object_id, which the row already
					// stores. Empty when there is genuinely nowhere to go, and the
					// body below stays plain text rather than becoming a dead link.
					$n_url = \Learnomy\Models\Notification::destination_url( $notif );
					?>
					<li class="lrn-notification-item<?php echo esc_attr( ! $n_is_read ? ' lrn-notification-item--unread' : '' ); ?>"
						data-notification-id="<?php echo (int) $notif->id; ?>"
						data-wp-on--click="actions.markRead">
						<i data-lucide="<?php echo esc_attr( $n_icon ); ?>" class="lrn-notification-item__icon" aria-hidden="true"></i>
						<div class="lrn-notification-item__body">
							<?php if ( $n_url ) : ?>
								<a class="lrn-notification-item__link" href="<?php echo esc_url( $n_url ); ?>">
							<?php endif; ?>
							<?php if ( ! empty( $notif->title ) ) : ?>
								<p class="lrn-notification-item__title"><?php echo esc_html( $notif->title ); ?></p>
							<?php endif; ?>
							<?php
							// Printed whole. These are system-composed strings - the
							// longest on a seeded QA site is 31 words - and a 12-word
							// wp_trim_words() cut most of them mid-sentence, on top of a
							// CSS ellipsis that had already clipped them to one line
							// (Basecamp 10199428997). Two truncations stacked on a
							// message that was never long enough to need one.
							//
							// wp_trim_words() counts on spaces, so the cut also landed
							// somewhere arbitrary in any language that does not use them.
							// The panel scrolls; the notification is the content.
							?>
							<p class="lrn-notification-item__message"><?php echo esc_html( $notif->message ); ?></p>
							<?php if ( $n_url ) : ?>
								</a>
							<?php endif; ?>
							<span class="lrn-notification-item__time">
								<?php
								/* translators: %s: human-readable time difference */
								echo esc_html( sprintf( __( '%s ago', 'learnomy' ), $n_time ) );
								?>
							</span>
						</div>
					</li>
				<?php endforeach; ?>
			<?php endif; ?>
		</ul>
	</div>
</div>
