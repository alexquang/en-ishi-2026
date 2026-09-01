<?php
/**
 * Notifications REST controller — unread count, list, mark read.
 *
 * Exposes the lrn_notifications table data for the notification bell
 * dropdown on the frontend.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\Models\Notification;

class Notifications_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'notifications';
	}

	public function register_routes(): void {
		// List recent notifications for the current user.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_notifications' ),
				'permission_callback' => array( $this, 'permission_logged_in' ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 50,
					),
				),
			)
		);

		// Unread count.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/unread-count',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_unread_count' ),
				'permission_callback' => array( $this, 'permission_logged_in' ),
			)
		);

		// Mark single notification as read.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/read',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'mark_read' ),
				'permission_callback' => array( $this, 'permission_logged_in' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		// Mark all notifications as read.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/mark-all-read',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_all_read' ),
				'permission_callback' => array( $this, 'permission_logged_in' ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	public function get_notifications( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();

		// Real pagination: page the unread set with a true offset and report the
		// REAL unread total. Previously meta.total was just the page size (count
		// of returned rows), so the bell could never page past the first `limit`
		// and has_more was always false.
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 ) {
			$per_page = (int) $request->get_param( 'limit' ); // Back-compat alias.
		}
		$per_page = min( 100, max( 1, $per_page ?: 20 ) );
		$offset   = $this->resolve_offset( $request, (int) $per_page );

		$items     = Notification::get_unread_for_user( $user_id, $per_page, $offset );
		$total     = Notification::get_unread_count( $user_id );
		$formatted = array_map( array( $this, 'format_notification' ), $items );

		return $this->paginated_response( $formatted, $total, $offset );
	}

	public function get_unread_count(): WP_REST_Response {
		$user_id = get_current_user_id();
		$count   = Notification::get_unread_count( $user_id );

		return $this->single_response( array( 'count' => $count ) );
	}

	public function mark_read( WP_REST_Request $request ): WP_REST_Response {
		$notification_id = (int) $request->get_param( 'id' );

		// Scope to the current user so one user cannot mark another user's
		// notifications read. A miss (not owned / not found) is a 404.
		if ( ! Notification::mark_read( $notification_id, get_current_user_id() ) ) {
			return $this->error_response( $this->not_found( 'Notification' ) );
		}

		return $this->single_response(
			array(
				'marked' => true,
				'id'     => $notification_id,
			)
		);
	}

	public function mark_all_read(): WP_REST_Response {
		$user_id = get_current_user_id();

		Notification::mark_all_read( $user_id );

		return $this->single_response( array( 'marked_all' => true ) );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function format_notification( object $notification ): array {
		$icon = $this->icon_for_type( $notification->type ?? '' );

		return array(
			'id'          => (int) $notification->id,
			'type'        => $notification->type ?? '',
			'title'       => $notification->title ?? '',
			'message'     => $notification->message ?? '',
			'object_type' => $notification->object_type ?? '',
			'object_id'   => (int) ( $notification->object_id ?? 0 ),
			// The mobile app had the same dead-end as the bell: object_type and
			// object_id went out, but nothing said where they LEAD, so every
			// client had to re-derive routing it cannot know. One resolver,
			// shared with the web bell (Basecamp 10171338941).
			'url'         => \Learnomy\Models\Notification::destination_url( $notification ),
			'is_read'     => (int) ( $notification->is_read ?? 0 ),
			'icon'        => $icon,
			'created_at'  => $notification->created_at ?? '',
			'time_ago'    => sprintf(
				/* translators: %s: human-readable time difference */
				__( '%s ago', 'learnomy' ),
				human_time_diff( strtotime( $notification->created_at ), time() )
			),
		);
	}

	private function icon_for_type( string $type ): string {
		$map = array(
			'enrollment'              => 'graduation-cap',
			'course_completed'        => 'check-circle',
			'certificate_issued'      => 'award',
			'quiz_submitted'          => 'pencil',
			'quiz_result'             => 'bar-chart-3',
			'application_approved'    => 'users',
			'application_rejected'    => 'x-circle',
			'payment_receipt'         => 'shopping-cart',
			'withdrawal_approved'     => 'banknote',
			'withdrawal_rejected'     => 'x-circle',
			'withdrawal_paid'         => 'banknote',
			'withdrawal_failed'       => 'alert-triangle',
			'admin_withdrawal_failed' => 'alert-triangle',
			'review_submitted'        => 'star',
			'membership_created'      => 'users',
			'membership_cancelled'    => 'x-circle',
			'membership_renewed'      => 'refresh-cw',
			'membership_expired'      => 'clock',
			'announcement'            => 'megaphone',
		);

		return $map[ $type ] ?? 'bell';
	}
}
