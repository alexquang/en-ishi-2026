<?php
/**
 * Unified notification dispatch — in-app rows + optional email delivery.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Notification;
use Learnomy\Notifications\Email_Content;
use Learnomy\Notifications\Email_Template;

class Notification_Service {

	/**
	 * Creates an in-app notification row and optionally sends an email.
	 *
	 * $data expected keys: title, message, object_type, object_id, actor_id (optional).
	 */
	public function send( int $user_id, string $type, array $data ): void {
		/**
		 * Short-circuit a notification (in-app + email) before anything is
		 * created or sent. Return true to skip entirely.
		 *
		 * The migration importer enables this for the duration of a run so that
		 * importing historical enrollments/progress for thousands of users does
		 * not blast each of them a "Welcome / You are enrolled" email.
		 *
		 * @param bool   $suppress Whether to skip this notification. Default false.
		 * @param string $type     Notification type (e.g. 'enrollment').
		 * @param int    $user_id  Recipient user id.
		 * @param array  $data     Notification payload.
		 */
		if ( apply_filters( 'learnomy_suppress_notifications', false, $type, $user_id, $data ) ) {
			return;
		}

		$insert = [
			'user_id'      => $user_id,
			'actor_id'     => $data['actor_id'] ?? null,
			'type'         => $type,
			'object_type'  => $data['object_type'] ?? '',
			'object_id'    => (int) ( $data['object_id'] ?? 0 ),
			'title'        => $data['title'] ?? null,
			'message'      => $data['message'] ?? '',
			// Default until the email outcome below is known. A row that
			// never attempts an email keeps `skipped` — the in-app
			// notification still exists, but the admin Email Log must not
			// claim a delivery that never happened.
			'email_status' => 'skipped',
		];

		// A caller may pin created_at — broadcasts do, stamping ONE compose
		// time onto every recipient of a fan-out. Without this, each async
		// job wrote its own worker-time timestamp, so one announcement
		// splintered into several (title, created_at) identities: the
		// Announcements list showed fragments, and recall — which targets
		// that identity — deleted only the recipients whose job happened to
		// run in the same second (Basecamp 10132809006). Format-checked so a
		// malformed payload cannot corrupt the column.
		if ( ! empty( $data['created_at'] ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $data['created_at'] ) ) {
			$insert['created_at'] = (string) $data['created_at'];
		}

		// The broadcast's real identity. The pinned timestamp above keeps a
		// fan-out's rows agreeing, but a tuple that happens to be unique is not
		// an id: two broadcasts of the same title to the same object inside one
		// second are indistinguishable by it, and any future path that forgets
		// to pin silently re-breaks recall. `broadcast_id` cannot collide and
		// cannot be forgotten by accident, because recall targets it directly
		// (Basecamp 10132809203).
		if ( ! empty( $data['broadcast_id'] ) ) {
			$insert['broadcast_id'] = substr( (string) $data['broadcast_id'], 0, 32 );
		}

		$notification_id = Notification::create( $insert );

		// Read settings once per send() so the five downstream helpers
		// (is_email_enabled_for_type, resolve_sender_name, resolve_sender_email,
		// filter_mail_from, filter_mail_from_name) do not each issue a separate
		// cache lookup for the same option. WordPress's object cache makes each
		// get_option() call cheap, but five lookups on every notification still
		// adds up on high-volume sites.
		$settings = get_option( 'learnomy_settings', array() );

		// Send email only if the per-type toggle is enabled and the user has not
		// opted out via their profile preferences. The toggle lives under the
		// canonical `learnomy_settings` option as `email_notify_<type>`; admin
		// defaults that key to checked, so a missing entry is treated as on and
		// only an explicit `0` disables delivery.
		//
		// Capture the real wp_mail() outcome and persist it on the row so the
		// admin Email Log reflects the truth (sent / failed / skipped) instead
		// of a hardcoded "sent". `skipped` stays put when no email is attempted.
		// A caller may decline the email copy for THIS send — that is what the
		// "Also send email" checkbox on a broadcast means. It can only decline:
		// `send_email => true` never overrides a site that has the type's email
		// switched off, and the recipient's own unsubscribe still wins. Absent
		// from $data means "no opinion", which is every existing caller.
		$caller_wants_email = ! array_key_exists( 'send_email', $data ) || (bool) $data['send_email'];

		if ( $caller_wants_email && $this->is_email_enabled_for_type( $type, $settings ) && ! $this->user_has_unsubscribed( $user_id, $type ) ) {
			// Capture the real wp_mail() error for this one send so a failed row
			// records WHY (surfaced in the admin Email Log), not just that it failed.
			$mail_error = '';
			$capture    = static function ( $wp_error ) use ( &$mail_error ) {
				if ( $wp_error instanceof \WP_Error ) {
					$mail_error = (string) $wp_error->get_error_message();
				}
			};
			add_action( 'wp_mail_failed', $capture );
			$sent = $this->send_email( $user_id, $type, $data, $settings );
			remove_action( 'wp_mail_failed', $capture );

			if ( $notification_id > 0 ) {
				Notification::update(
					$notification_id,
					array(
						'email_status' => $sent ? 'sent' : 'failed',
						'email_error'  => ( ! $sent && '' !== $mail_error ) ? mb_substr( $mail_error, 0, 500 ) : null,
					)
				);
			}
		}

		// Push. Same chokepoint as in-app and email, deliberately: a push that is sent
		// from somewhere else would eventually disagree with them about what the user
		// was told and when.
		//
		// `lrn_devices` had been collecting device tokens since 1.0 and NOTHING ever
		// sent to them — the app registered a token on every launch and not one push
		// was ever delivered. This is the outlet for that intake.
		//
		// A no-op on the vast majority of sites: no mobile app means no tokens and no
		// FCM credentials, and Push_Service returns immediately without touching the
		// network or the devices table.
		//
		// Gate on the member's PUSH preference, not user_has_unsubscribed() — that
		// reads the EMAIL buckets, so wiring push to it (as this first shipped) made
		// un-ticking "Enrollment emails" silently kill the enrollment PUSH too, a
		// channel the member never opted out of. Push has its own single switch
		// (the `push_reminders` pref behind the account toggle), and this is the one
		// place that reads it — before this, that toggle was saved and never
		// consulted, so it did nothing.
		if ( ! $this->push_unsubscribed( $user_id ) ) {
			$push_title = (string) ( $data['title'] ?? get_bloginfo( 'name' ) );
			$push_body  = (string) ( $data['message'] ?? '' );
			$push_meta  = array(
				'type'        => $type,
				'object_type' => (string) ( $data['object_type'] ?? '' ),
				'object_id'   => (string) (int) ( $data['object_id'] ?? 0 ),
			);

			// Push delivery is a blocking wp_remote_post to FCM PER DEVICE, each
			// with a 15s timeout. send() runs SYNCHRONOUSLY from Stripe/PayPal
			// webhook handlers and the checkout controller, so delivering push
			// inline added those blocking calls to the webhook response — risking
			// a gateway timeout and a webhook RETRY (which, before the refund fix,
			// compounded into double-recorded refunds). Hand push to Action
			// Scheduler so the request returns immediately and delivery drains in
			// the background. A site with no app (no tokens, no FCM creds) still
			// no-ops instantly; a site with no AS runner, or one that opts out via
			// the filter, falls back to inline.
			if ( $this->deliver_async_enabled() ) {
				as_enqueue_async_action(
					'learnomy_deliver_push',
					array(
						'user_id' => $user_id,
						'title'   => $push_title,
						'body'    => $push_body,
						'data'    => $push_meta,
					),
					'learnomy'
				);
			} else {
				( new Push_Service() )->send_to_user( $user_id, $push_title, $push_body, $push_meta );
			}
		}

		do_action( 'learnomy_send_notification', $user_id, $type, $data );
	}

	/**
	 * Send the SAME notification to many users without blocking the request.
	 *
	 * The one primitive every fan-out should use — bulk enrolment, announcements,
	 * cron reminders. A loop of send() calls does N sequential wp_mail (plus, once,
	 * a blocking push per device) inside whatever request triggered it; at a
	 * 500-enrolee cap or a 10k-member announcement that is thousands of blocking
	 * calls in one REST response. This queues one Action Scheduler job per
	 * recipient instead, so the request returns immediately and delivery drains in
	 * the background, each recipient independently retryable.
	 *
	 * Default async (right for a 10k-site owner). A site can force inline with the
	 * `learnomy_notifications_async` filter, and a site with no Action Scheduler
	 * runner falls back to inline automatically.
	 *
	 * @param int[]                $user_ids Recipient user ids (deduped, zeros dropped).
	 * @param string               $type     Notification type.
	 * @param array<string, mixed> $data     Payload passed to send().
	 * @return int Number of recipients queued or sent.
	 */
	public function send_many( array $user_ids, string $type, array $data = array() ): int {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( empty( $user_ids ) ) {
			return 0;
		}

		if ( ! $this->deliver_async_enabled() ) {
			foreach ( $user_ids as $uid ) {
				$this->send( $uid, $type, $data );
			}
			return count( $user_ids );
		}

		foreach ( $user_ids as $uid ) {
			as_enqueue_async_action(
				'learnomy_send_notification_async',
				array(
					'user_id' => $uid,
					'type'    => $type,
					'data'    => $data,
				),
				'learnomy'
			);
		}
		return count( $user_ids );
	}

	/**
	 * Send ONE notification with per-recipient data, off the request when possible.
	 *
	 * Where send_many() sends the same payload to many users, the reminder crons
	 * build a bespoke payload per row (each member's own course / expiry / plan), so
	 * they need a single-recipient deferral. This queues one `learnomy_send_notification_async`
	 * job (the same handler send_many() uses) so the blocking wp_mail drains in the
	 * background — a daily sweep over thousands of expiring rows no longer runs
	 * thousands of serial wp_mail calls inside one cron tick. Honours the same
	 * `learnomy_notifications_async` filter and falls back to an inline send() when
	 * Action Scheduler isn't available.
	 *
	 * @param int                  $user_id Recipient.
	 * @param string               $type    Notification type.
	 * @param array<string, mixed> $data    Notification payload (already localized).
	 */
	public function queue_send( int $user_id, string $type, array $data = array() ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		if ( ! $this->deliver_async_enabled() ) {
			$this->send( $user_id, $type, $data );
			return;
		}

		as_enqueue_async_action(
			'learnomy_send_notification_async',
			array(
				'user_id' => $user_id,
				'type'    => $type,
				'data'    => $data,
			),
			'learnomy'
		);
	}

	/**
	 * Whether delivery is handed to Action Scheduler rather than run inline.
	 *
	 * True by default (async is the right behaviour for the vast majority of
	 * sites); a site owner can force inline via `learnomy_notifications_async`, and
	 * a site with no AS runner available falls back to inline on its own. Public so
	 * a fan-out endpoint can report whether it queued or sent.
	 */
	public function deliver_async_enabled(): bool {
		return (bool) apply_filters( 'learnomy_notifications_async', true )
			&& function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Sends a branded HTML email using the Email_Content + Email_Template pipeline.
	 *
	 * Falls back to the legacy inline builder if no template exists for the type,
	 * so existing notification types without dedicated content still work.
	 *
	 * @param int    $user_id  Recipient user ID.
	 * @param string $template Notification type / template identifier.
	 * @param array  $data     Notification payload.
	 * @param array  $settings Pre-fetched learnomy_settings option. When NULL
	 *                         the method reads it itself, so callers that invoke
	 *                         send_email() directly (e.g. tests, Pro extensions)
	 *                         do not need to supply it.
	 */
	public function send_email( int $user_id, string $template, array $data, ?array $settings = null ): bool {
		$user = get_user_by( 'ID', $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return false;
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		// Use the pre-fetched settings when available; otherwise read once here
		// so this method remains usable as a standalone caller.
		if ( null === $settings ) {
			$settings = get_option( 'learnomy_settings', array() );
		}

		// Resolve sender name / sender email. Wave 2B (2026-05-16) wired the
		// Settings -> Emails fields into outgoing mail; prefer those values so
		// the UI promise actually holds, and fall back to the legacy top-level
		// options (used by older sites) and finally the WordPress defaults.
		$from_name  = self::resolve_sender_name( $settings );
		$from_email = self::resolve_sender_email( $settings );
		$headers[]  = 'From: ' . sanitize_text_field( $from_name ) . ' <' . sanitize_email( $from_email ) . '>';

		// Build the email in the RECIPIENT's locale, not the actor's / site's
		// (i18n standard R7). A German member must get a German email even when
		// the enrolling instructor or the cron runs under en_US. All email copy
		// (subject, heading, body, CTA, template chrome) is resolved inside this
		// switch via __(); we restore the previous locale before wp_mail() so the
		// rest of the request is unaffected. switch_to_locale() is a no-op that
		// returns false when the recipient's locale already matches the active
		// one, so single-locale sites pay nothing.
		$recipient_locale = get_user_locale( $user_id );
		$switched_locale  = switch_to_locale( $recipient_locale );

		// Try the branded template system first.
		$content = Email_Content::for_type( $template, $user, $data );

		if ( $content ) {
			$subject = $content['subject'];
			$body    = Email_Template::render(
				$content['subject'],
				$content['heading'],
				$content['body'],
				$content['cta_url'] ?? '',
				$content['cta_text'] ?? '',
				$content['footer'] ?? ''
			);
		} else {
			// Fallback for notification types without dedicated email content.
			$subject = $this->resolve_subject( $template, $data );
			$body    = $this->build_email_body( $template, $data, $user );
		}

		if ( $switched_locale ) {
			restore_previous_locale();
		}

		// Site-owner per-template copy override (Settings -> Emails). Applied here
		// so it sits on top of the branded default but under the developer filters
		// below, which remain the final word.
		$override = $this->admin_copy_override( $template, $settings, $user, $data, $subject, $body );
		$subject  = $override['subject'];
		$body     = $override['body'];

		$to      = apply_filters( 'learnomy_email_to', $user->user_email, $template, $user_id, $data );
		$subject = apply_filters( 'learnomy_email_subject', $subject, $template, $user_id, $data );
		$body    = apply_filters( 'learnomy_email_body', $body, $template, $user_id, $data );
		$headers = apply_filters( 'learnomy_email_headers', $headers, $template, $user_id );

		/**
		 * Fires immediately before an email is sent.
		 *
		 * @param string $to      Recipient email address.
		 * @param string $subject Email subject.
		 * @param string $template Notification type / template identifier.
		 * @param int    $user_id Recipient user ID.
		 */
		do_action( 'learnomy_before_email_send', $to, $subject, $template, $user_id );

		$sent = wp_mail( $to, $subject, $body, $headers );

		/**
		 * Fires immediately after an email send attempt.
		 *
		 * @param string $to      Recipient email address.
		 * @param string $subject Email subject.
		 * @param string $template Notification type / template identifier.
		 * @param int    $user_id Recipient user ID.
		 * @param bool   $sent    Whether wp_mail() reported success.
		 */
		do_action( 'learnomy_after_email_send', $to, $subject, $template, $user_id, $sent );

		return $sent;
	}

	public function get_unread( int $user_id, int $limit = 20 ): array {
		return Notification::get_unread_for_user( $user_id, $limit );
	}

	/**
	 * Returns true when the admin per-type email toggle is on.
	 *
	 * The toggle is stored under the canonical `learnomy_settings` option as the
	 * sub-key `email_notify_<type>`. The admin Emails tab renders every toggle
	 * checked by default, so a row that has never been saved is treated as
	 * enabled. Only an explicit `0` (saved after the user un-ticks the box)
	 * disables delivery.
	 *
	 * Public so it can be unit-tested in isolation and reused by upstream
	 * dispatchers (Notifier, Pro extensions) without re-reading the option.
	 *
	 * @param string     $type     Notification type identifier.
	 * @param array|null $settings Pre-fetched learnomy_settings array. When NULL
	 *                             the method reads the option itself for backward
	 *                             compatibility (direct callers, tests).
	 */
	public function is_email_enabled_for_type( string $type, ?array $settings = null ): bool {
		if ( null === $settings ) {
			$settings = get_option( 'learnomy_settings', array() );
		}
		$key = 'email_notify_' . $type;

		if ( ! is_array( $settings ) || ! array_key_exists( $key, $settings ) ) {
			return true;
		}

		return ! empty( $settings[ $key ] );
	}

	/**
	 * Canonical list of notifiable email templates (key => human label).
	 *
	 * Single source for BOTH the Settings → Emails toggles AND the per-template
	 * copy editor + the sanitiser, so the three can never drift. The key is the
	 * notification $type used everywhere (toggle `email_notify_<key>`, copy
	 * override `email_subj_<key>` / `email_body_<key>`, and the $template passed
	 * to send_email()). Extend via the `learnomy_email_template_types` filter.
	 *
	 * @return array<string,string>
	 */
	public static function email_template_types(): array {
		return apply_filters(
			'learnomy_email_template_types',
			array(
				'enrollment'                => __( 'Enrollment Welcome', 'learnomy' ),
				'course_completed'          => __( 'Course Completed', 'learnomy' ),
				'certificate_issued'        => __( 'Certificate Issued', 'learnomy' ),

				/*
				 * `quiz_result`, not `quiz_graded`. The key IS the notification
				 * type (see the docblock above), and on_quiz_graded() sends
				 * `quiz_result` — as do Email_Content::for_type() and the stored
				 * notification rows. The old key matched nothing, so all three
				 * things this list drives were dead for this email: the toggle
				 * did nothing, and the owner's custom subject/body were ignored
				 * (Basecamp 10104865195). The label stays "Quiz Graded" because
				 * that is when it fires, and it is what owners already recognise.
				 */
				'quiz_result'               => __( 'Quiz Graded', 'learnomy' ),
				// Fired by Notifier::on_quiz_submitted() on every submission and
				// missing from this list entirely, so no toggle was ever rendered
				// and is_email_enabled_for_type() defaulted it to on — an email a
				// site owner could not switch off (Basecamp 10104865042).
				'quiz_submitted'            => __( 'Quiz Submitted', 'learnomy' ),

				/*
				 * Same mismatch as quiz_result above, found by diffing this list
				 * against every type Notifier::notify() actually sends: the
				 * toggles said `instructor_*` while on_application_approved() /
				 * _rejected() send `application_*` — as does
				 * Email_Content::for_type(). Both toggles had never done anything.
				 */
				'application_approved'      => __( 'Instructor Application Approved', 'learnomy' ),
				'application_rejected'      => __( 'Instructor Application Rejected', 'learnomy' ),
				'application_submitted'     => __( 'Instructor Application Received', 'learnomy' ),
				'withdrawal_approved'       => __( 'Withdrawal Approved', 'learnomy' ),
				'withdrawal_rejected'       => __( 'Withdrawal Rejected', 'learnomy' ),
				'withdrawal_paid'           => __( 'Payout Sent', 'learnomy' ),
				'withdrawal_failed'         => __( 'Payout Failed', 'learnomy' ),
				'admin_withdrawal_failed'   => __( 'Payout Failed (to the site owner)', 'learnomy' ),

				/*
				 * The rest of what we send. Every one of these was firing with no
				 * toggle at all: absent from this list means is_email_enabled_for_type()
				 * finds no key and defaults to ON, so a site owner who turned off
				 * everything they could see still could not stop them.
				 *
				 * The bar is the one owners already know from WooCommerce: if it
				 * sends an email, there is a switch for it. No exceptions, including
				 * the ones that feel mandatory to us:
				 *
				 * - instructor_new_enrollment fires on EVERY enrolment. One popular
				 *   course is hundreds of emails a day at an instructor who never
				 *   asked for them.
				 * - admin_payment_received fires on EVERY sale — WooCommerce ships
				 *   exactly this toggle ("New order") because busy stores turn it off.
				 * - payment_receipt looks non-optional until you remember the receipt
				 *   is not ours to mandate: an owner selling through WooCommerce or
				 *   EDD already sends one from the gateway, so ours is a SECOND
				 *   receipt for the same purchase.
				 *
				 * Defaults stay on, so nothing changes for anyone until they choose.
				 */
				'payment_receipt'           => __( 'Payment Receipt', 'learnomy' ),
				'lesson_unlocked'           => __( 'Lesson Unlocked (drip)', 'learnomy' ),
				'instructor_new_enrollment' => __( 'New Enrollment (to the instructor)', 'learnomy' ),
				'admin_new_application'     => __( 'New Instructor Application (to the site owner)', 'learnomy' ),
				'admin_payment_received'    => __( 'New Payment (to the site owner)', 'learnomy' ),
				'membership_created'        => __( 'Membership Created', 'learnomy' ),
				'membership_cancelled'      => __( 'Membership Cancelled', 'learnomy' ),
				'membership_renewed'        => __( 'Membership Renewed', 'learnomy' ),
				'membership_expired'        => __( 'Membership Expired', 'learnomy' ),
				'membership_trial_ending'   => __( 'Trial Ending Reminder', 'learnomy' ),
				'review_submitted'          => __( 'New Course Review', 'learnomy' ),
				'lesson_comment'            => __( 'New Lesson Comment', 'learnomy' ),
				'comment_reply'             => __( 'Reply to a Comment', 'learnomy' ),
			)
		);
	}

	/**
	 * Merge-tag map for a recipient + notification payload.
	 *
	 * Common recipient/site tags plus every scalar in $data as `{key}`, so an
	 * event that carries `course_title` exposes `{course_title}` automatically.
	 * Values are HTML-escaped — they land in an HTML email body.
	 *
	 * @return array<string,string> Search => replace map for strtr().
	 */
	private static function merge_tag_map( \WP_User $user, array $data ): array {
		$first = '' !== (string) $user->first_name ? $user->first_name : $user->display_name;

		$tags = array(
			'{student_name}'       => esc_html( $user->display_name ),
			'{student_first_name}' => esc_html( $first ),
			'{student_email}'      => esc_html( $user->user_email ),
			'{site_name}'          => esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'{site_url}'           => esc_url( home_url( '/' ) ),
			'{login_url}'          => esc_url( wp_login_url() ),
		);

		foreach ( $data as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$tags[ '{' . $key . '}' ] = esc_html( (string) $value );
			}
		}

		return $tags;
	}

	/**
	 * Applies a site owner's custom subject/body for this template, if one is set
	 * in Settings → Emails. Non-technical surface on top of the branded defaults;
	 * the `learnomy_email_subject` / `learnomy_email_body` filters still win for
	 * developers (they run after this). An empty field keeps the default, so an
	 * owner can override just the subject, just the body, or both.
	 *
	 * @return array{subject:string,body:string} The (possibly overridden) pair.
	 */
	private function admin_copy_override( string $template, array $settings, \WP_User $user, array $data, string $subject, string $body ): array {
		$custom_subject = trim( (string) ( $settings[ 'email_subj_' . $template ] ?? '' ) );
		$custom_body    = trim( (string) ( $settings[ 'email_body_' . $template ] ?? '' ) );

		if ( '' === $custom_subject && '' === $custom_body ) {
			return array(
				'subject' => $subject,
				'body'    => $body,
			);
		}

		$tags = self::merge_tag_map( $user, $data );

		if ( '' !== $custom_subject ) {
			$subject = strtr( $custom_subject, $tags );
		}

		if ( '' !== $custom_body ) {
			// Keep the branded wrapper; the owner supplies the inner copy only.
			$body = Email_Template::render( $subject, $subject, wpautop( strtr( $custom_body, $tags ) ), '', '', '' );
		}

		return array(
			'subject' => $subject,
			'body'    => $body,
		);
	}

	/**
	 * Resolves the outgoing sender name.
	 *
	 * Lookup order:
	 *   1. learnomy_settings[email_sender_name]   — wired from Settings -> Emails UI (Wave 2B).
	 *   2. learnomy_email_from_name               — legacy top-level option for older sites.
	 *   3. WordPress site name (get_bloginfo).
	 *
	 * @param array|null $settings Pre-fetched learnomy_settings array. When NULL
	 *                             the method reads the option itself so it remains
	 *                             usable as a standalone static call.
	 */
	public static function resolve_sender_name( ?array $settings = null ): string {
		if ( null === $settings ) {
			$settings = get_option( 'learnomy_settings', array() );
		}
		$ui_name = isset( $settings['email_sender_name'] ) ? trim( (string) $settings['email_sender_name'] ) : '';
		if ( '' !== $ui_name ) {
			return $ui_name;
		}

		$legacy = (string) get_option( 'learnomy_email_from_name', '' );
		if ( '' !== $legacy ) {
			return $legacy;
		}

		/**
		 * Filter the resolved sender name before WordPress's default is kept.
		 *
		 * @param string $name Resolved name, '' when nothing is configured.
		 */
		$resolved = trim( (string) apply_filters( 'learnomy_sender_name', '' ) );

		// Empty when nothing is configured, so WordPress's own default is kept
		// — same reasoning as resolve_sender_email(). Returning the blog name
		// here meant the plugin renamed the sender on every install that had
		// never opened the Emails screen, which is a change the owner did not
		// ask for and cannot see the origin of.
		return $resolved;
	}

	/**
	 * Resolves the outgoing sender email address.
	 *
	 * Lookup order:
	 *   1. learnomy_settings[email_sender_email]  — wired from Settings -> Emails UI (Wave 2B).
	 *   2. learnomy_email_from_address            — legacy top-level option for older sites.
	 *   3. admin_email                            — WordPress default.
	 *
	 * Invalid addresses (failing is_email) at any tier are skipped so a bad
	 * value cannot silently break delivery; the next valid tier wins.
	 *
	 * @param array|null $settings Pre-fetched learnomy_settings array. When NULL
	 *                             the method reads the option itself so it remains
	 *                             usable as a standalone static call.
	 */
	public static function resolve_sender_email( ?array $settings = null ): string {
		if ( null === $settings ) {
			$settings = get_option( 'learnomy_settings', array() );
		}
		$ui_email = isset( $settings['email_sender_email'] ) ? trim( (string) $settings['email_sender_email'] ) : '';
		if ( '' !== $ui_email && is_email( $ui_email ) ) {
			return $ui_email;
		}

		$legacy = (string) get_option( 'learnomy_email_from_address', '' );
		if ( '' !== $legacy && is_email( $legacy ) ) {
			return $legacy;
		}

		/**
		 * Filter the resolved sender address before WordPress's default is kept.
		 *
		 * @param string $email Resolved address, '' when nothing is configured.
		 */
		$resolved = (string) apply_filters( 'learnomy_sender_email', '' );

		// EMPTY when the owner has configured nothing, so `filter_mail_from()`
		// hands WordPress's own default straight back.
		//
		// This used to return admin_email, on the stated assumption that
		// admin_email IS the value WordPress passes in. It is not — WordPress
		// passes `wordpress@<site-domain>`. So on every install where nobody
		// had touched the setting, Learnomy silently rewrote the From address
		// to the admin's, which is frequently on another domain (a gmail.com
		// address is the common case). That breaks SPF/DKIM alignment and
		// sends transactional mail to spam, on a site whose owner never
		// configured anything. Keeping the WordPress default keeps the sending
		// domain aligned with the site.
		return '' !== $resolved && is_email( $resolved ) ? $resolved : '';
	}

	/**
	 * Filter callback for `wp_mail_from`.
	 *
	 * Delegates to resolve_sender_email() so the 3-tier lookup lives in one
	 * place only. When the resolved address equals the WP default (admin_email)
	 * the filter is effectively a no-op; when a custom address is configured
	 * it wins over WP's default (priority 10) while leaving room for
	 * third-party plugins at 30+ to take precedence.
	 *
	 * Registered at priority 20 from Notifier::__construct().
	 *
	 * @param string $from Default from-address chosen by WordPress.
	 */
	public static function filter_mail_from( string $from ): string {
		$resolved = self::resolve_sender_email();
		// Only override when the resolved email is a valid, non-default address.
		// resolve_sender_email() falls back to admin_email which IS $from, so
		// returning it is safe either way — we just avoid the is_email() check.
		return '' !== $resolved ? $resolved : $from;
	}

	/**
	 * Filter callback for `wp_mail_from_name`.
	 *
	 * Delegates to resolve_sender_name() so the 3-tier lookup lives in one
	 * place only. When no custom name is configured the WordPress default
	 * (usually "WordPress") is preserved.
	 *
	 * @param string $from_name Default from-name chosen by WordPress.
	 */
	public static function filter_mail_from_name( string $from_name ): string {
		$resolved = self::resolve_sender_name();
		return '' !== $resolved ? $resolved : $from_name;
	}

	/**
	 * Whether the member has switched OFF mobile push.
	 *
	 * Push has a single master switch (the `push_reminders` pref, saved by the
	 * account "Push notifications" toggle) — distinct from the per-type EMAIL
	 * buckets. Default is subscribed: a member who never touched the toggle, and
	 * every transactional push, goes through. THIS is the only reader of that
	 * pref; it was write-only until now (the toggle did nothing).
	 *
	 * @param int $user_id Recipient.
	 * @return bool True when push is switched off for this member.
	 */
	private function push_unsubscribed( int $user_id ): bool {
		$prefs = get_user_meta( $user_id, '_learnomy_notification_prefs', true );
		if ( ! is_array( $prefs ) ) {
			return false;
		}

		// Explicit false disables push; a missing key means "not chosen" = subscribed.
		return isset( $prefs['push_reminders'] ) && false === (bool) $prefs['push_reminders'];
	}

	private function user_has_unsubscribed( int $user_id, string $type ): bool {
		// Read the SAME meta the account "Notifications" UI writes
		// (_learnomy_notification_prefs). Each notification type maps to one of
		// the coarse opt-out buckets the learner controls; types with no bucket
		// are transactional (receipts, security) and can't be suppressed.
		$prefs = get_user_meta( $user_id, '_learnomy_notification_prefs', true );
		if ( ! is_array( $prefs ) ) {
			return false;
		}

		$bucket = $this->pref_bucket_for_type( $type );
		if ( '' === $bucket ) {
			return false;
		}

		// Only an explicit false suppresses; an unset bucket means subscribed.
		return isset( $prefs[ $bucket ] ) && false === (bool) $prefs[ $bucket ];
	}

	/**
	 * Map a notification type to the learner-facing email preference bucket that
	 * gates it. Returns '' for transactional types that are never opt-out-able.
	 *
	 * @param string $type Notification type passed to send().
	 * @return string Bucket key, or '' when the type is not user-suppressible.
	 */
	private function pref_bucket_for_type( string $type ): string {
		$map = array(
			'enrollment'         => 'email_enrolled',
			'course_completed'   => 'email_completed',
			'certificate_issued' => 'email_completed',

			/*
			 * `quiz_result`, not `quiz_graded` — the type on_quiz_graded()
			 * actually sends. This map keys on the TYPE, and a type it does not
			 * know is treated as transactional and NEVER suppressed, so the wrong
			 * name here did not fail loudly: it quietly promoted quiz-result mail
			 * to un-opt-out-able. A member who unticked "Course completion" in
			 * their own account preferences still received it (verified: 1 email
			 * sent to an opted-out member, while course_completed — a correct key
			 * in this same map — was suppressed).
			 *
			 * The fourth thing the one wrong key broke, after the admin toggle and
			 * the two copy overrides (Basecamp 10104865195).
			 */
			'quiz_result'        => 'email_completed',
			'lesson_comment'     => 'email_new_content',
			'comment_reply'      => 'email_new_content',
			'review_submitted'   => 'email_new_content',
		);

		/**
		 * Map notification types to learner email-preference buckets. Extensions
		 * add their own types here so a learner's opt-out covers them too. A type
		 * absent from the map is treated as transactional (never suppressed).
		 *
		 * @param array<string,string> $map Type => bucket.
		 */
		$map = apply_filters( 'learnomy_notification_pref_buckets', $map );

		return isset( $map[ $type ] ) ? (string) $map[ $type ] : '';
	}

	private function resolve_subject( string $template, array $data ): string {
		// Allow per-type subject override via settings; fall back to title or generic.
		$setting_key = 'learnomy_email_subject_' . $template;
		$subject     = get_option( $setting_key, '' );

		if ( empty( $subject ) ) {
			$subject = ! empty( $data['title'] )
				? $data['title']
				/* translators: %s: site name */
				: sprintf( __( 'Notification from %s', 'learnomy' ), get_bloginfo( 'name' ) );
		}

		return wp_strip_all_tags( $subject );
	}

	private function build_email_body( string $template, array $data, \WP_User $user ): string {
		$site_name = esc_html( get_bloginfo( 'name' ) );
		$message   = wp_kses_post( $data['message'] ?? '' );
		$greeting  = sprintf(
			/* translators: %s: user's display name */
			esc_html__( 'Hi %s,', 'learnomy' ),
			esc_html( $user->display_name )
		);

		// Simple inline-styled wrapper — keeps emails readable across clients
		// without requiring a full template engine.
		$body  = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">';
		$body .= '<p>' . $greeting . '</p>';
		$body .= '<p>' . $message . '</p>';

		// Attach a CTA link if provided.
		if ( ! empty( $data['action_url'] ) && ! empty( $data['action_label'] ) ) {
			$body .= '<p><a href="' . esc_url( $data['action_url'] ) . '" ';
			$body .= 'style="background:#2563eb;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">';
			$body .= esc_html( $data['action_label'] ) . '</a></p>';
		}

		$body .= '<hr style="margin-top:30px;border:none;border-top:1px solid #e5e7eb;">';
		$body .= '<p style="font-size:12px;color:#6b7280;">' . $site_name . '</p>';
		$body .= '</div>';

		return $body;
	}
}
