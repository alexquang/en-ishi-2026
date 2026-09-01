<?php
/**
 * JWT-style authentication for mobile apps — access + refresh token pair.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Token;
use WP_Error;

class Auth_Service {

	// Access tokens expire quickly; refresh tokens live for a billing cycle.
	const ACCESS_TOKEN_TTL  = 15 * MINUTE_IN_SECONDS;
	const REFRESH_TOKEN_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Authenticates credentials and returns a token pair on success.
	 *
	 * @return array|WP_Error Token data array or WP_Error on failure.
	 */
	public function authenticate( string $username, string $password ): array|WP_Error {
		$user = wp_signon(
			[
				'user_login'    => $username,
				'user_password' => $password,
				'remember'      => false,
			],
			false
		);

		if ( is_wp_error( $user ) ) {
			return $user;
		}

		// Clear the auth cookie set by wp_signon — we use tokens, not cookies.
		wp_clear_auth_cookie();

		return $this->issue_token_pair( $user );
	}

	/**
	 * Validates a refresh token, issues a new access+refresh pair, and
	 * REVOKES the old refresh token (rotation).
	 *
	 * Pre-rotation, a stolen refresh token stayed valid for its original
	 * 30-day TTL with no signal that it had been compromised. With rotation
	 * the old refresh token dies on first use of its successor — if an
	 * attacker steals one and the legitimate client refreshes first, the
	 * attacker's next attempt returns `token_revoked` (and vice versa,
	 * giving the legitimate client an early "you've been compromised"
	 * signal). RFC 6749 §10.4 / OAuth 2.0 Security BCP recommend this for
	 * public clients.
	 *
	 * @return array|WP_Error New access+refresh pair or WP_Error.
	 */
	public function refresh( string $refresh_token ): array|WP_Error {
		$token = Token::find_by_hash( $refresh_token );

		if ( ! $token ) {
			return new WP_Error( 'invalid_token', __( 'Refresh token not found.', 'learnomy' ) );
		}

		if ( ! empty( $token->revoked_at ) ) {
			return new WP_Error( 'token_revoked', __( 'This token has been revoked.', 'learnomy' ) );
		}

		if ( strtotime( $token->expires_at ) <= time() ) {
			return new WP_Error( 'token_expired', __( 'Refresh token has expired. Please sign in again.', 'learnomy' ) );
		}

		$user = get_user_by( 'ID', (int) $token->user_id );
		if ( ! $user ) {
			return new WP_Error( 'user_not_found', __( 'Associated user account no longer exists.', 'learnomy' ) );
		}

		$raw_access      = wp_generate_password( 64, false );
		$raw_refresh     = wp_generate_password( 64, false );
		$access_expires  = gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_TOKEN_TTL );
		$refresh_expires = gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TOKEN_TTL );

		$ip         = $this->get_ip();
		$user_agent = $this->get_user_agent();

		Token::create( $user->ID, $raw_access, $access_expires, $ip, $user_agent );
		Token::create( $user->ID, $raw_refresh, $refresh_expires, $ip, $user_agent );

		// Revoke the consumed refresh token — its single-use lifecycle ends
		// here. Subsequent calls with the old token return `token_revoked`.
		Token::revoke( (int) $token->id );

		return [
			'access_token'  => $raw_access,
			'refresh_token' => $raw_refresh,
			'expires_in'    => self::ACCESS_TOKEN_TTL,
			'user'          => $this->basic_user_data( $user ),
		];
	}

	public function revoke( string $token ): bool {
		$row = Token::find_by_hash( $token );
		if ( ! $row ) {
			return false;
		}
		return Token::revoke( (int) $row->id );
	}

	private function issue_token_pair( \WP_User $user ): array {
		$raw_access  = wp_generate_password( 64, false );
		$raw_refresh = wp_generate_password( 64, false );

		$access_expires  = gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_TOKEN_TTL );
		$refresh_expires = gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TOKEN_TTL );

		$ip         = $this->get_ip();
		$user_agent = $this->get_user_agent();

		Token::create( $user->ID, $raw_access, $access_expires, $ip, $user_agent );
		Token::create( $user->ID, $raw_refresh, $refresh_expires, $ip, $user_agent );

		return [
			'access_token'  => $raw_access,
			'refresh_token' => $raw_refresh,
			'expires_in'    => self::ACCESS_TOKEN_TTL,
			'user'          => $this->basic_user_data( $user ),
		];
	}

	private function basic_user_data( \WP_User $user ): array {
		return [
			'id'           => $user->ID,
			'display_name' => $user->display_name,
			'email'        => $user->user_email,
			'avatar_url'   => get_avatar_url( $user->ID, [ 'size' => 96 ] ),
		];
	}

	private function get_ip(): string {
		return \Learnomy\client_ip();
	}

	private function get_user_agent(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
	}
}
