<?php
/**
 * API key model — stored as hashed entries in wp_usermeta.
 *
 * Keys use a `lrn_` prefix followed by 40 random characters. Only the
 * SHA-256 hash is persisted; the plaintext key is shown once at creation
 * and cannot be recovered afterward.
 *
 * This class extends the abstract Model base to satisfy the F2 invariant
 * (every Model in includes/models/ must extend Model). The base's table
 * helpers are not used for reads/writes because API key records live in
 * wp_usermeta — not in a dedicated lrn_* table. table_name() declares the
 * logical suffix anyway so that table_exists() degrades gracefully and the
 * cache-group name follows the standard "learnomy_<table>" convention.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Api_Key extends Model {

	/**
	 * Logical table suffix — satisfies the abstract Model contract.
	 *
	 * There is no physical lrn_api_keys table; data lives in wp_usermeta.
	 * table_exists() will therefore return false, which is the correct
	 * graceful-degrade behaviour for admin views that probe for the table.
	 */
	protected static function table_name(): string {
		return 'api_keys';
	}

	/** Usermeta key where the JSON array of API key records lives. */
	const META_KEY = 'learnomy_api_keys';

	/** Prefix prepended to every generated key so it's recognisable in logs. */
	const KEY_PREFIX = 'lrn_';

	/**
	 * Generate a new API key for a user.
	 *
	 * @param int    $user_id The WordPress user who owns this key.
	 * @param string $label   A human-friendly label (e.g. "Zapier integration").
	 * @param array  $permissions Optional permission scopes. Empty = full access.
	 * @return array{raw_key: string, id: string, label: string} The full key (show once).
	 */
	public static function create( int $user_id, string $label, array $permissions = [] ): array {
		$raw_key = self::KEY_PREFIX . wp_generate_password( 40, false );
		$id      = wp_generate_uuid4();

		$record = [
			'id'           => $id,
			'key_hash'     => hash( 'sha256', $raw_key ),
			'key_prefix'   => substr( $raw_key, 0, 8 ),
			'label'        => sanitize_text_field( $label ),
			'permissions'  => $permissions,
			'created_at'   => now(),
			'last_used_at' => null,
		];

		$keys   = self::get_all_for_user( $user_id );
		$keys[] = $record;
		update_user_meta( $user_id, self::META_KEY, $keys );

		return [
			'raw_key' => $raw_key,
			'id'      => $id,
			'label'   => $record['label'],
		];
	}

	/**
	 * Retrieve all API key records for a user (hashes only, never plaintext).
	 *
	 * @return array<int, array>
	 */
	public static function get_all_for_user( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $stored ) ? $stored : [];
	}

	/**
	 * Look up a key record across all users by its raw plaintext value.
	 *
	 * Returns the owning user ID and record on success, or null on failure.
	 * Also updates `last_used_at` when a match is found.
	 *
	 * @return array{user_id: int, record: array}|null
	 */
	public static function find_by_raw_key( string $raw_key ): ?array {
		if ( 0 !== strpos( $raw_key, self::KEY_PREFIX ) ) {
			return null;
		}

		$hash = hash( 'sha256', $raw_key );

		// Look up all users who have at least one API key stored. The meta
		// query is intentionally broad because there's no way to query inside
		// serialised JSON with WP_User_Query. For sites with many keys this
		// could be optimised with a dedicated DB table later.
		$users = get_users(
			[
				'meta_key' => self::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'fields'       => 'ID',
			]
		);

		foreach ( $users as $uid ) {
			$keys = self::get_all_for_user( (int) $uid );
			foreach ( $keys as $index => $record ) {
				if ( ! empty( $record['key_hash'] ) && hash_equals( $record['key_hash'], $hash ) ) {
					// Touch the last-used timestamp.
					$keys[ $index ]['last_used_at'] = now();
					update_user_meta( (int) $uid, self::META_KEY, $keys );

					return [
						'user_id' => (int) $uid,
						'record'  => $record,
					];
				}
			}
		}

		return null;
	}

	/**
	 * Revoke (delete) a specific key by its UUID.
	 */
	public static function revoke( int $user_id, string $key_id ): bool {
		$keys    = self::get_all_for_user( $user_id );
		$updated = array_values(
			array_filter(
				$keys,
				static fn( array $k ) => $k['id'] !== $key_id
			)
		);

		if ( count( $updated ) === count( $keys ) ) {
			return false; // Key not found — nothing removed.
		}

		update_user_meta( $user_id, self::META_KEY, $updated );
		return true;
	}

	/**
	 * Format a key record for safe external display (no hash).
	 */
	public static function safe_output( array $record ): array {
		return [
			'id'           => $record['id'],
			'key_prefix'   => $record['key_prefix'] ?? '',
			'label'        => $record['label'],
			'permissions'  => $record['permissions'] ?? [],
			'created_at'   => $record['created_at'],
			'last_used_at' => $record['last_used_at'],
		];
	}
}
