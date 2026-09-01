<?php
/**
 * Bearer-token authentication middleware.
 *
 * Hooks into `determine_current_user` to authenticate REST requests that carry
 * an `Authorization: Bearer <token>` header. The token is the opaque access
 * token issued by `Auth_Service::issue_token_pair()` (stored SHA-256-hashed in
 * `lrn_tokens`). When the token is valid the current user is set to the token
 * owner; otherwise the filter falls through so other auth methods (cookie,
 * API key, Application Passwords) can still take effect.
 *
 * Without this layer the token endpoints issue tokens that nothing ever reads,
 * so every `Authorization: Bearer` request returns 401 even with a valid token.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Token;

class Token_Auth {

	public function __construct() {
		add_filter( 'determine_current_user', [ $this, 'authenticate' ], 24 );
	}

	/**
	 * If the request carries a Bearer token, resolve it to a user ID.
	 *
	 * Priority 24 runs after WP cookie auth (priority 20) so an already-
	 * authenticated session is never overridden, and just before the API-key
	 * handler (priority 25). We only act when the incoming $user_id is still 0.
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

		$raw_token = $this->extract_bearer_token();
		if ( '' === $raw_token ) {
			return $user_id;
		}

		$resolved = Token::user_id_for_valid_token( $raw_token );

		return $resolved ?? $user_id;
	}

	/**
	 * Read and parse the `Authorization: Bearer <token>` header.
	 *
	 * The Authorization header is exposed inconsistently across SAPIs: PHP-FPM
	 * and some Apache setups drop it from $_SERVER, so we also consult the
	 * REDIRECT_ copy and getallheaders() before giving up. Application-Password
	 * "Basic" auth is ignored here — only the Bearer scheme is ours.
	 */
	private function extract_bearer_token(): string {
		$header = $this->authorization_header();
		if ( '' === $header ) {
			return '';
		}

		if ( ! preg_match( '/^Bearer\s+(.+)$/i', trim( $header ), $matches ) ) {
			return '';
		}

		return sanitize_text_field( trim( $matches[1] ) );
	}

	/**
	 * Locate the raw Authorization header value across SAPIs.
	 */
	private function authorization_header(): string {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		}

		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $name => $value ) {
				if ( 0 === strcasecmp( $name, 'Authorization' ) ) {
					return (string) $value;
				}
			}
		}

		return '';
	}

	/**
	 * Lightweight check — avoids loading Token on non-REST pages.
	 */
	private function is_rest_request(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		$rest_prefix = rest_get_url_prefix();

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return false !== strpos( $request_uri, $rest_prefix );
	}
}
