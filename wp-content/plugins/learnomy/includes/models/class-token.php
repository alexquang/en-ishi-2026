<?php
/**
 * API tokens — stored as SHA-256 hashes; raw token is never persisted.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Token extends Model {

	protected static function table_name(): string {
		return 'tokens';
	}

	/**
	 * Accepts the raw token string, hashes it before storing.
	 * Returns the new row ID — the caller must keep the raw token as it cannot
	 * be recovered from the database afterward.
	 */
	public static function create( int $user_id, string $raw_token, string $expires_at, string $ip_address = '', string $user_agent = '' ): int {
		return static::insert(
			[
				'user_id'    => $user_id,
				'token_hash' => hash( 'sha256', $raw_token ),
				'ip_address' => $ip_address,
				'user_agent' => $user_agent,
				'expires_at' => $expires_at,
				'created_at' => now(),
			]
		);
	}

	public static function find_by_hash( string $raw_token ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE token_hash = %s LIMIT 1',
				hash( 'sha256', $raw_token )
			)
		) ?: null;
	}

	public static function revoke( int $id ): bool {
		return static::update( $id, [ 'revoked_at' => now() ] );
	}

	public static function cleanup_expired(): int {
		$deleted = static::db()->query(
			static::db()->prepare(
				'DELETE FROM ' . static::table() . ' WHERE expires_at < %s',
				now()
			)
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();

		return (int) $deleted;
	}

	public static function is_valid( string $raw_token ): bool {
		$token = static::find_by_hash( $raw_token );
		if ( ! $token ) {
			return false;
		}
		if ( ! empty( $token->revoked_at ) ) {
			return false;
		}
		return strtotime( $token->expires_at ) > time();
	}

	/**
	 * Resolve a raw bearer token to its owning user ID, but only if the token
	 * is currently usable (exists, not revoked, not expired).
	 *
	 * This backs the `Authorization: Bearer <token>` REST authentication layer
	 * (`Token_Auth`). It returns null for any token that should NOT authenticate
	 * a request so the `determine_current_user` filter can fall through to other
	 * auth methods cleanly.
	 *
	 * @param string $raw_token The opaque token presented by the client.
	 * @return int|null The owning user ID, or null when the token is unusable.
	 */
	public static function user_id_for_valid_token( string $raw_token ): ?int {
		if ( '' === $raw_token ) {
			return null;
		}

		$token = static::find_by_hash( $raw_token );
		if ( ! $token ) {
			return null;
		}
		if ( ! empty( $token->revoked_at ) ) {
			return null;
		}
		if ( strtotime( $token->expires_at ) <= time() ) {
			return null;
		}

		$user_id = (int) $token->user_id;
		return $user_id > 0 ? $user_id : null;
	}
}
