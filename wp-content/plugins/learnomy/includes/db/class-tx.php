<?php
/**
 * SQL transaction helper.
 *
 * The companion to {@see Lock}: where an advisory lock serializes callers who
 * have no single row to lock, this wraps work that must be all-or-nothing AND
 * needs a real row lock (`SELECT … FOR UPDATE`) to make a read-then-write
 * atomic. The refund path is the canonical case — "is there enough left to
 * refund?" followed by "insert the refund" must not interleave, or two partial
 * refunds each pass the check and the total exceeds the charge.
 *
 * Lives in includes/db/ for the same reason as Lock: it is one of the two
 * places raw $wpdb is allowed. Services must not open transactions inline,
 * because a hand-rolled BEGIN with an early `return` between it and COMMIT
 * leaks an open transaction that holds its locks until the connection dies.
 * run() closes that hole by construction.
 *
 * NOTE: this is a *database* transaction, unrelated to the payment
 * `Transaction` model. The short name keeps the two readable side by side.
 *
 * @package Learnomy
 */

declare(strict_types=1);

namespace Learnomy\DB;

defined( 'ABSPATH' ) || exit;

class Tx {

	/**
	 * Run $callback inside a SQL transaction.
	 *
	 * Commits when the callback returns, rolls back if it throws, and rethrows.
	 * Every exit path is covered, including an early `return` from inside the
	 * callback, which is exactly what makes this safer than inline BEGIN/COMMIT.
	 *
	 * Nested calls join the outer transaction rather than opening a second one
	 * (MySQL has no true nesting — an inner COMMIT would commit the outer work
	 * early). Only the outermost call commits or rolls back.
	 *
	 * Keep the body short. Side effects that do their own writes — unenrolling a
	 * student, reversing a commission, sending mail — belong AFTER the
	 * transaction, so a slow call doesn't hold row locks.
	 *
	 * @template T
	 * @param callable():T $callback Work to run.
	 * @return T The callback's return value.
	 * @throws \Throwable Whatever the callback threw, after rolling back.
	 */
	public static function run( callable $callback ) {
		global $wpdb;

		// A nested call must not issue its own COMMIT — see the class docblock.
		static $depth = 0;

		if ( $depth > 0 ) {
			++$depth;
			try {
				return $callback();
			} finally {
				--$depth;
			}
		}

		// The transaction IS the concurrency primitive, so the direct-query +
		// no-cache sniffs do not apply.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'START TRANSACTION' );
		++$depth;

		try {
			$result = $callback();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		} finally {
			--$depth;
		}
	}
}
