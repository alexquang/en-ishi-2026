<?php
/**
 * View-data resolver for the account-details template (/account/details/).
 *
 * Pre-fetches the current user's editable fields + the REST endpoint/nonce so
 * the template stays presentation-only. Saving happens via REST
 * (POST learnomy/v1/auth/account) from account-details.js.
 *
 * @package Learnomy
 */

namespace Learnomy\Services\View_Data;

defined( 'ABSPATH' ) || exit;

use Learnomy\API\Auth_Controller;

class Account_Details_View_Data {

	/**
	 * @param array $data Route data (unused).
	 * @return array Pre-fetched view data.
	 */
	public static function resolve( array $data ): array {
		unset( $data );

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		// Notification opt-outs (the "Notifications" card). Defaults come from
		// Auth_Controller::notification_pref_defaults() — the single canonical
		// source — so adding a new pref key in one place automatically picks it
		// up here without a separate edit.
		$np_stored = $user ? get_user_meta( $user_id, '_learnomy_notification_prefs', true ) : array();
		$prefs     = is_array( $np_stored )
			? array_merge( Auth_Controller::notification_pref_defaults(), $np_stored )
			: Auth_Controller::notification_pref_defaults();

		// Blocked members — the web manage/unblock surface (three-entry-points
		// parity with the app's block list). Hydrate names + avatars in ONE
		// WP_User_Query, not per row.
		$blocked_members = array();
		if ( $user_id > 0 ) {
			$blocked_rows = \Learnomy\Models\Blocked_User::list_for( $user_id );
			$blocked_uids = array_values( array_filter( array_map( static fn( $r ) => (int) $r->blocked_id, $blocked_rows ) ) );
			$by_id        = array();
			if ( $blocked_uids ) {
				foreach ( get_users(
					array(
						'include' => $blocked_uids,
						'number'  => count( $blocked_uids ),
					)
				) as $bu ) {
					$by_id[ (int) $bu->ID ] = $bu;
				}
			}
			foreach ( $blocked_rows as $r ) {
				$bu = $by_id[ (int) $r->blocked_id ] ?? null;
				if ( ! $bu ) {
					continue;
				}
				$blocked_members[] = array(
					'id'     => (int) $bu->ID,
					'name'   => (string) $bu->display_name,
					'avatar' => (string) get_avatar_url( (int) $bu->ID, array( 'size' => 40 ) ),
				);
			}
		}

		return array(
			'blocked_members'    => $blocked_members,
			'block_rest_url'     => esc_url_raw( rest_url( 'learnomy/v1/' ) ),
			'display_name'       => $user ? (string) $user->display_name : '',
			'first_name'         => $user ? (string) $user->first_name : '',
			'last_name'          => $user ? (string) $user->last_name : '',
			'email'              => $user ? (string) $user->user_email : '',
			'pending_email'      => $user ? (string) get_user_meta( $user_id, 'learnomy_pending_email', true ) : '',
			'rest_url'           => esc_url_raw( rest_url( 'learnomy/v1/auth/account' ) ),
			'rest_nonce'         => wp_create_nonce( 'wp_rest' ),
			'notification_prefs' => $prefs,
			'notifications_url'  => esc_url_raw( rest_url( 'learnomy/v1/auth/me/notifications' ) ),
			'delete_account_url' => esc_url_raw( rest_url( 'learnomy/v1/auth/account' ) ),
			'avatar_url'         => $user ? get_avatar_url( $user_id, array( 'size' => 192 ) ) : '',
			'avatar_endpoint'    => esc_url_raw( rest_url( 'learnomy/v1/auth/account/avatar' ) ),
			'has_custom_avatar'  => $user_id > 0 && (int) get_user_meta( $user_id, \Learnomy\Services\Avatar_Service::META_KEY, true ) > 0,
		);
	}
}
