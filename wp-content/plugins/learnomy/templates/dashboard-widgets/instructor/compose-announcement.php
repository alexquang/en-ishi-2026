<?php
/**
 * Instructor compose-announcement widget body.
 *
 * Boundary: an instructor's broadcast stays scoped to students enrolled
 * in their authored courses. The widget posts to
 * POST /learnomy/v1/broadcasts with audience='instructor_courses' —
 * Free's Broadcasts_Controller verifies the actor authors at least one
 * course before resolving the recipient list.
 *
 * The inline form is the entire surface — no bouncing to wp-admin,
 * no admin form dependency, no role-mismatch (instructors without
 * manage_options can use it). The "recently sent" list pulls history
 * via the same Notification::sent_by_actor query as before.
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

$sent = \Learnomy\Models\Notification::sent_by_actor( (int) $user_id, 3 );
?>
<div class="lrn-broadcast-form"
	data-broadcast-audience="instructor_courses"
	data-rest-base="<?php echo esc_attr( rest_url( 'learnomy/v1/broadcasts' ) ); ?>"
	data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">

	<p class="lrn-broadcast-form__intro">
		<?php esc_html_e( 'Send a message to every student enrolled in your courses.', 'learnomy' ); ?>
	</p>

	<form class="lrn-broadcast-form__form" data-role="broadcast-form" novalidate>
		<div class="lrn-form-field">
			<label class="lrn-form-label" for="lrn-broadcast-title-instructor">
				<?php esc_html_e( 'Title', 'learnomy' ); ?>
			</label>
			<input
				type="text"
				id="lrn-broadcast-title-instructor"
				name="title"
				class="lrn-input"
				required
				maxlength="200"
				placeholder="<?php esc_attr_e( 'New lesson added · Office hours · …', 'learnomy' ); ?>">
		</div>
		<div class="lrn-form-field">
			<label class="lrn-form-label" for="lrn-broadcast-message-instructor">
				<?php esc_html_e( 'Message', 'learnomy' ); ?>
			</label>
			<textarea
				id="lrn-broadcast-message-instructor"
				name="message"
				class="lrn-textarea"
				rows="3"
				required
				maxlength="2000"
				placeholder="<?php esc_attr_e( 'Keep it short and clear. Recipients see this in their notifications.', 'learnomy' ); ?>"></textarea>
		</div>
		<div class="lrn-broadcast-form__actions">
			<label class="lrn-broadcast-form__email-toggle">
				<input type="checkbox" name="send_email" value="1">
				<?php esc_html_e( 'Also send email', 'learnomy' ); ?>
			</label>
			<button type="submit" class="lrn-btn lrn-btn--primary lrn-btn--sm" data-role="broadcast-submit">
				<i data-lucide="send" aria-hidden="true"></i>
				<?php esc_html_e( 'Send', 'learnomy' ); ?>
			</button>
		</div>
		<p class="lrn-broadcast-form__status" data-role="broadcast-status" aria-live="polite" hidden></p>
	</form>

	<?php if ( ! empty( $sent ) ) : ?>
		<h3 class="lrn-announcements-widget__heading">
			<?php esc_html_e( 'Recently sent', 'learnomy' ); ?>
		</h3>
		<ul class="lrn-announcements-widget__list" data-role="broadcast-history">
			<?php
			foreach ( $sent as $row ) :
				$when       = human_time_diff( strtotime( (string) $row->created_at ), time() );
				$recipients = (int) ( $row->recipients ?? 0 );
				?>
				<li class="lrn-announcements-widget__item">
					<i data-lucide="megaphone" class="lrn-announcements-widget__icon" aria-hidden="true"></i>
					<div class="lrn-announcements-widget__body">
						<span class="lrn-announcements-widget__title">
							<?php echo esc_html( (string) $row->title ); ?>
						</span>
						<span class="lrn-announcements-widget__meta">
							<?php
							printf(
								/* translators: 1: recipient count, 2: time diff */
								esc_html( _n( '%1$d recipient · %2$s ago', '%1$d recipients · %2$s ago', $recipients, 'learnomy' ) ),
								absint( $recipients ),
								esc_html( $when )
							);
							?>
						</span>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
