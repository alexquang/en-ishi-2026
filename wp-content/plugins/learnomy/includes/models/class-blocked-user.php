<?php
/**
 * Member block list — one member blocking another (App Store 1.2 UGC safety).
 *
 * Read on every lesson-comment and course-review list to hide a blocked
 * author's content from the blocker. Writes are idempotent on the
 * (blocker_id, blocked_id) unique pair.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

class Blocked_User extends Model {

	protected static function table_name(): string {
		return 'blocked_users';
	}

	/**
	 * Block $blocked on behalf of $blocker. Idempotent (INSERT IGNORE on the
	 * unique pair) — re-blocking is a no-op, not an error. Self-blocks rejected.
	 */
	public static function block( int $blocker_id, int $blocked_id ): bool {
		if ( $blocker_id <= 0 || $blocked_id <= 0 || $blocker_id === $blocked_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		static::db()->query(
			static::db()->prepare(
				'INSERT IGNORE INTO ' . static::table() . ' (blocker_id, blocked_id, created_at) VALUES (%d, %d, %s)',
				$blocker_id,
				$blocked_id,
				current_time( 'mysql', true )
			)
		);
		static::invalidate_cache();

		return true;
	}

	/**
	 * Remove a block. Returns true whether or not a row existed (idempotent).
	 */
	public static function unblock( int $blocker_id, int $blocked_id ): bool {
		if ( $blocker_id <= 0 || $blocked_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		static::db()->query(
			static::db()->prepare(
				'DELETE FROM ' . static::table() . ' WHERE blocker_id = %d AND blocked_id = %d',
				$blocker_id,
				$blocked_id
			)
		);
		static::invalidate_cache();

		return true;
	}

	public static function is_blocked( int $blocker_id, int $blocked_id ): bool {
		if ( $blocker_id <= 0 || $blocked_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) static::db()->get_var(
			static::db()->prepare(
				'SELECT id FROM ' . static::table() . ' WHERE blocker_id = %d AND blocked_id = %d LIMIT 1',
				$blocker_id,
				$blocked_id
			)
		);
	}

	/**
	 * The user IDs this member blocks — the exclusion set for comment/review
	 * reads. Empty array for a member who has blocked no one (the common case),
	 * so callers can skip the filter entirely.
	 *
	 * @return int[]
	 */
	public static function blocked_ids_for( int $blocker_id ): array {
		if ( $blocker_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = static::db()->get_col(
			static::db()->prepare(
				'SELECT blocked_id FROM ' . static::table() . ' WHERE blocker_id = %d',
				$blocker_id
			)
		);

		return array_map( 'intval', $ids ?: array() );
	}

	/**
	 * The blocker's list (blocked_id + when), newest first — for a manage screen.
	 *
	 * @return object[]
	 */
	public static function list_for( int $blocker_id ): array {
		if ( $blocker_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT blocked_id, created_at FROM ' . static::table() . ' WHERE blocker_id = %d ORDER BY created_at DESC',
				$blocker_id
			)
		) ?: array();
	}
}
