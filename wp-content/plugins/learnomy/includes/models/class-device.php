<?php
/**
 * Registered user devices — supports push notifications and session management.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Device extends Model {

	protected static function table_name(): string {
		return 'devices';
	}

	/**
	 * Upserts by device_token so re-registering an existing device (e.g. after
	 * app reinstall) refreshes metadata rather than creating a duplicate row.
	 */
	public static function register( int $user_id, string $device_token, string $platform ): int {
		$existing = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE device_token = %s LIMIT 1',
				$device_token
			)
		);

		if ( $existing ) {
			static::update(
				(int) $existing->id,
				[
					'user_id'  => $user_id,
					'platform' => $platform,
				]
			);
			return (int) $existing->id;
		}

		return static::insert(
			[
				'user_id'      => $user_id,
				'device_token' => $device_token,
				'platform'     => $platform,
				'created_at'   => now(),
			]
		);
	}

	public static function unregister( string $device_token ): bool {
		$deleted = false !== static::db()->delete( static::table(), [ 'device_token' => $device_token ] );
		static::invalidate_cache();
		return $deleted;
	}

	public static function get_for_user( int $user_id ): array {
		return static::where( [ 'user_id' => $user_id ], 'created_at', 'DESC' );
	}
}
