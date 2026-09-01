<?php
/**
 * MySQL advisory-lock helper.
 *
 * Serializes concurrent code paths that read-then-write the same logical row
 * but have no single UNIQUE key to lean on — e.g. "check for a recent duplicate
 * comment, then insert if none". Without a lock, two requests fired by one
 * double-click both run the SELECT before either INSERT commits, both see "no
 * duplicate", and both insert. GET_LOCK makes the second caller wait for the
 * first, so it sees the freshly-inserted row and can dedupe.
 *
 * Lives in includes/db/ because it is the one place raw $wpdb is allowed; the
 * coupon-usage and space-member models inline the same primitive, this is the
 * shared form for callers that aren't themselves Models.
 *
 * @package Learnomy
 */

declare(strict_types=1);

namespace Learnomy\DB;

defined( 'ABSPATH' ) || exit;

class Lock {

	/**
	 * Run $callback while holding a named MySQL lock keyed on $key.
	 *
	 * The lock is an optimization against races, not a correctness gate on its
	 * own — if it can't be acquired within $timeout (or errors), the callback
	 * still runs, so a busy lock degrades to the unlocked behaviour rather than
	 * failing the user's action. The lock is always released when held.
	 *
	 * @param string   $key      Logical lock key (hashed to a valid lock name).
	 * @param callable $callback Work to run while holding the lock.
	 * @param int      $timeout  Seconds to wait for the lock.
	 * @return mixed The callback's return value.
	 */
	public static function with_lock( string $key, callable $callback, int $timeout = 5 ) {
		global $wpdb;

		// MySQL lock names are limited to 64 chars; a prefixed md5 fits and
		// avoids collisions with other plugins' locks.
		$name = 'lrn_' . md5( $key );

		// GET_LOCK returns "1" on success, "0" on timeout, NULL on error.
		// The advisory lock IS the concurrency primitive, so the direct-query +
		// no-cache sniffs do not apply.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout ) );

		try {
			return $callback();
		} finally {
			if ( '1' === (string) $acquired ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			}
		}
	}
}
