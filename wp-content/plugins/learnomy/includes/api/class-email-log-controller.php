<?php
/**
 * Email Log admin controller — resend a logged notification email.
 *
 * Route:
 *   POST /learnomy/v1/admin/email-log/{id}/resend — re-dispatch the email for
 *   one notification row through the existing Notification_Service pipeline and
 *   report the real outcome (and, on failure, the captured wp_mail reason).
 *
 * Gated on manage_options. The list + recipient/date/status search themselves
 * are server-rendered in includes/admin/views/email-log.php (paginated GET), so
 * this controller only owns the write action.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Learnomy\Models\AuditLog;
use Learnomy\Models\Notification;
use Learnomy\Services\Notification_Service;

class Email_Log_Controller extends Base_Controller {

	/**
	 * Audit-log action label for a manual resend. Shared with the Email Log
	 * view, which reads the events back to show each row's resend history, so
	 * the writer and the reader can never drift.
	 */
	public const RESEND_ACTION = 'email.resent';

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'admin/email-log';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/resend',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resend' ),
				'permission_callback' => array( $this, 'is_admin' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);
	}

	/**
	 * Permission callback — require manage_options.
	 *
	 * @return true|WP_Error
	 */
	public function is_admin(): bool|WP_Error {
		return $this->require_cap( 'manage_options' );
	}

	/**
	 * Re-send the email for one notification row via the existing mailer.
	 *
	 * Reuses Notification_Service::send_email() so the branded template pipeline,
	 * sender resolution, and every learnomy_email_* filter behave exactly as they
	 * did on the original send — no parallel sending logic. WordPress only exposes
	 * a wp_mail failure reason through the wp_mail_failed action, so it is hooked
	 * for the duration of this one attempt and detached immediately after.
	 */
	public function resend( WP_REST_Request $request ): WP_REST_Response {
		$id  = (int) $request->get_param( 'id' );
		$row = Notification::find( $id );

		if ( ! $row ) {
			return $this->error_response( $this->not_found( 'Notification' ) );
		}

		$user_id = (int) $row->user_id;
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $user || empty( $user->user_email ) ) {
			return $this->error_response(
				$this->validation_error(
					__( 'This notification has no deliverable recipient - the user was deleted or has no email address.', 'learnomy' )
				)
			);
		}

		// Capture the real failure reason for THIS attempt.
		$reason  = '';
		$capture = static function ( $wp_error ) use ( &$reason ) {
			if ( $wp_error instanceof WP_Error ) {
				$reason = $wp_error->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $capture );

		$service = new Notification_Service();
		$sent    = $service->send_email(
			$user_id,
			(string) $row->type,
			array(
				'title'       => $row->title,
				'message'     => $row->message,
				'object_type' => $row->object_type ?? '',
				'object_id'   => (int) ( $row->object_id ?? 0 ),
				'actor_id'    => isset( $row->actor_id ) ? (int) $row->actor_id : null,
			)
		);

		remove_action( 'wp_mail_failed', $capture );

		// Record the resend as its own event; the original row is NOT touched.
		//
		// It used to overwrite `email_status` + `email_error` in place, which
		// answered the wrong question. "Did this student get their receipt?"
		// needs BOTH events — the first attempt failed at 09:02 with an SMTP
		// timeout, the resend went out at 14:31 — and overwriting left only the
		// last one, with the original failure gone as if it had never happened
		// (Basecamp 10132808821).
		//
		// The event goes to lrn_audit_log, not to a second lrn_notifications
		// row: a row in that table IS a notification (it drives the member's
		// unread bell, the activity summary and the privacy exporter), so
		// logging an attempt there would show the student a duplicate
		// notification they were never sent. An audit entry is exactly what
		// this is — an admin action against an object (Rule 4a: reuse the table
		// that already fits).
		$resend_attempt = array(
			'email_status' => $sent ? 'sent' : 'failed',
			'recipient'    => $user->user_email,
			'error'        => ( ! $sent && '' !== $reason ) ? mb_substr( (string) $reason, 0, 500 ) : '',
			'type'         => (string) $row->type,
		);

		AuditLog::log(
			get_current_user_id(),
			'admin_action',
			self::RESEND_ACTION,
			'notification',
			$id,
			$resend_attempt
		);

		/**
		 * Fires after an admin manually resends a logged notification email.
		 *
		 * @param int    $id      Notification row id.
		 * @param int    $user_id Recipient user id.
		 * @param bool   $sent    Whether wp_mail() reported success.
		 * @param string $reason  Failure reason captured from wp_mail_failed (empty on success).
		 */
		do_action( 'learnomy_email_log_resent', $id, $user_id, $sent, $reason );

		$fallback_reason = __( 'The mailer reported a failure but gave no reason. Check your SMTP / email delivery configuration.', 'learnomy' );

		$history = AuditLog::latest_for_objects( 'notification', array( $id ), self::RESEND_ACTION );

		return $this->single_response(
			array(
				'id'            => $id,
				// The row's OWN status, untouched by this resend — the UI keeps
				// showing what the original attempt did.
				'email_status'  => (string) $row->email_status,
				'sent'          => $sent,
				'recipient'     => $user->user_email,
				'reason'        => $sent ? '' : ( '' !== $reason ? $reason : $fallback_reason ),
				// This attempt, for the "Resent ..." line the row grows.
				'resend_status' => $sent ? 'sent' : 'failed',
				'resend_at'     => (string) ( $history[ $id ]['latest']->created_at ?? \Learnomy\now() ),
				'resend_count'  => (int) ( $history[ $id ]['count'] ?? 1 ),
			)
		);
	}
}
