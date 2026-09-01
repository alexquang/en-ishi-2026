<?php
/**
 * API key authentication middleware.
 *
 * Hooks into `determine_current_user` to authenticate REST requests
 * that carry an `X-API-Key` header. When a valid key is found the
 * current user is set to the key owner. If the key is invalid the
 * filter falls through so other auth methods (cookie, JWT,
 * Application Passwords) can still take effect.
 *
 * The header is the only accepted transport — see `extract_key()` for
 * why the `?api_key=` query-string fallback was removed.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Api_Key;

class Api_Key_Auth {

	public function __construct() {
		add_filter( 'determine_current_user', [ $this, 'authenticate' ], 25 );
	}

	/**
	 * If the request carries an API key, resolve it to a user ID.
	 *
	 * Priority 25 runs after WP cookie auth (priority 20) so an already-
	 * authenticated session is never overridden. We only act when the
	 * incoming $user_id is still 0 (unauthenticated).
	 *
	 * @param int|false $user_id Current user ID or false.
	 * @return int|false Resolved user ID, or the original value.
	 */
	public function authenticate( $user_id ) {
		// Don't override an already-authenticated user.
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		// Only act on REST API requests to avoid slowing down the admin.
		if ( ! $this->is_rest_request() ) {
			return $user_id;
		}

		$raw_key = $this->extract_key();
		if ( ! $raw_key ) {
			return $user_id;
		}

		$match = Api_Key::find_by_raw_key( $raw_key );
		if ( ! $match ) {
			return $user_id;
		}

		return $match['user_id'];
	}

	/**
	 * Read the API key from the X-API-Key request header only.
	 *
	 * The `?api_key=` query-string fallback was removed (card 10062746959): a
	 * long-lived credential in the URL leaks into server access logs, Referer
	 * headers, browser history, and CDN/proxy caches. Callers must send the key
	 * in the header; for interactive/integration auth prefer WordPress
	 * Application Passwords (Basic auth, HTTPS-only, revocable).
	 */
	private function extract_key(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return isset( $_SERVER['HTTP_X_API_KEY'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_API_KEY'] ) )
			: '';
	}

	/**
	 * Lightweight check — avoids loading Api_Key on non-REST pages.
	 */
	private function is_rest_request(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		// Fallback: check the request URI for the REST prefix.
		$rest_prefix = rest_get_url_prefix();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return false !== strpos( $request_uri, $rest_prefix );
	}
}
