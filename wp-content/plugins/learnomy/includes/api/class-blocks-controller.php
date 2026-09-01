<?php
/**
 * Blocks REST controller — member block list (App Store 1.2 UGC safety).
 *
 * The block half of the report/block pair a UGC app must ship. Reporting lives
 * in Reports_Controller; this is the "stop showing me this person" control. A
 * logged-in member blocks/unblocks another member; blocked authors are then
 * filtered out of that member's lesson-comment and course-review reads (see
 * Comments_Controller + Reviews_Controller). Three member surfaces:
 *
 *   POST   /users/{id}/block   — block a member.
 *   DELETE /users/{id}/block   — unblock.
 *   GET    /users/me/blocks    — my block list (for a manage screen).
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use Learnomy\Models\Blocked_User;

class Blocks_Controller extends Base_Controller {

	public function register_routes(): void {
		// POST/DELETE /users/{id}/block — block or unblock a member.
		register_rest_route(
			$this->namespace,
			'/users/(?P<id>\d+)/block',
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'block' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
					],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'unblock' ],
					'permission_callback' => [ $this, 'permission_logged_in' ],
					'args'                => [
						'id' => [
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
					],
				],
			]
		);

		// GET /users/me/blocks — the caller's block list.
		register_rest_route(
			$this->namespace,
			'/users/me/blocks',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'my_blocks' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
			]
		);
	}

	/**
	 * POST /users/{id}/block. Idempotent — re-blocking returns 200 with the same
	 * shape. A self-block or a missing user is a 400.
	 */
	public function block( WP_REST_Request $request ): WP_REST_Response {
		$me     = get_current_user_id();
		$target = (int) $request->get_param( 'id' );

		if ( $target === $me ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_cannot_block_self', __( 'You cannot block yourself.', 'learnomy' ), [ 'status' => 400 ] )
			);
		}
		if ( ! get_user_by( 'ID', $target ) ) {
			return $this->error_response( $this->not_found( 'User' ) );
		}
		// A block hides the target's comments + replies from the blocker. Allowing
		// a member to block a site admin / course owner would let them silence the
		// instructor's own answers on their lessons — so staff are unblockable.
		if ( user_can( $target, 'manage_options' ) ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_cannot_block_admin', __( 'You cannot block an administrator.', 'learnomy' ), [ 'status' => 400 ] )
			);
		}

		Blocked_User::block( $me, $target );

		/**
		 * Fires after one member blocks another.
		 *
		 * @param int $me     The blocking member.
		 * @param int $target The blocked member.
		 */
		do_action( 'learnomy_user_blocked', $me, $target );

		return $this->single_response(
			[
				'blocked' => true,
				'user_id' => $target,
			]
		);
	}

	/**
	 * DELETE /users/{id}/block. Idempotent — unblocking a non-blocked user is 200.
	 */
	public function unblock( WP_REST_Request $request ): WP_REST_Response {
		$me     = get_current_user_id();
		$target = (int) $request->get_param( 'id' );

		Blocked_User::unblock( $me, $target );

		/** Fires after one member unblocks another. */
		do_action( 'learnomy_user_unblocked', $me, $target );

		return $this->single_response(
			[
				'blocked' => false,
				'user_id' => $target,
			]
		);
	}

	/**
	 * GET /users/me/blocks — the caller's blocked members, hydrated with a
	 * display name + avatar so a manage screen can render without an N+1.
	 */
	public function my_blocks( WP_REST_Request $request ): WP_REST_Response {
		$me   = get_current_user_id();
		$rows = Blocked_User::list_for( $me );

		$items = array();
		foreach ( $rows as $row ) {
			$user = get_user_by( 'ID', (int) $row->blocked_id );
			if ( ! $user ) {
				continue;
			}
			$items[] = array(
				'user_id'    => (int) $row->blocked_id,
				'name'       => $user->display_name,
				'avatar'     => get_avatar_url( (int) $row->blocked_id, [ 'size' => 64 ] ),
				'blocked_at' => $row->created_at,
			);
		}

		return $this->single_response( $items );
	}
}
