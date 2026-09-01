<?php
/**
 * Lesson_Unlock — per-(user, lesson) computed unlock rows for relative drip.
 *
 * Maps to `lrn_lesson_unlocks`. Populated by the
 * `learnomy_student_enrolled` listener at enrolment time for every
 * relative-drip lesson in the course; scanned hourly by
 * `Drip_Cron::process_window()` to fire `learnomy_lesson_unlocked` once the
 * stored datetime passes.
 *
 * Storage contract
 * - `unlocks_at` is UTC, MySQL `Y-m-d H:i:s`. Compared against
 *   `current_time( 'mysql', true )` in the gate so site-timezone settings
 *   cannot drift the boundary.
 * - UNIQUE (user_id, lesson_id) lets the listener use INSERT IGNORE and
 *   stay idempotent across re-enrolment / membership-driven re-issue
 *   events without recomputing or de-duplicating in PHP.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

class Lesson_Unlock extends Model {

	/**
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'lesson' => 'lesson_id',
	);

	protected static function table_name(): string {
		return 'lesson_unlocks';
	}

	/**
	 * Insert a new unlock row. Uses INSERT IGNORE so a re-enrolment that
	 * tries to recompute the same (user, lesson) row leaves the original
	 * unlock untouched.
	 *
	 * @param int    $user_id    Student user id.
	 * @param int    $lesson_id  Lesson row id.
	 * @param string $unlocks_at UTC datetime in `Y-m-d H:i:s`.
	 *
	 * @return bool True when a new row was inserted, false otherwise.
	 */
	public static function insert_ignore( int $user_id, int $lesson_id, string $unlocks_at ): bool {
		if ( $user_id <= 0 || $lesson_id <= 0 || '' === $unlocks_at ) {
			return false;
		}

		$table = static::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name resolved internally.
		$sql = static::db()->prepare(
			"INSERT IGNORE INTO {$table} (user_id, lesson_id, unlocks_at) VALUES (%d, %d, %s)",
			$user_id,
			$lesson_id,
			$unlocks_at
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = static::db()->query( $sql );

		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();

		return (int) $result > 0;
	}

	/**
	 * Batch counterpart to insert_ignore() — writes many unlock-ledger rows in
	 * ONE multi-row `INSERT IGNORE`. Lets a caller collapse a per-lesson insert
	 * loop (e.g. Pro content-drip scheduling every dripped lesson of a course on
	 * enrolment) into a single round-trip, which matters at bulk-enrol scale.
	 *
	 * @param array<int, array{user_id:int, lesson_id:int, unlocks_at:string}> $rows
	 * @return int Rows affected (new rows inserted; duplicates ignored).
	 */
	public static function insert_ignore_many( array $rows ): int {
		$placeholders = array();
		$params       = array();
		foreach ( $rows as $row ) {
			$user_id    = (int) ( $row['user_id'] ?? 0 );
			$lesson_id  = (int) ( $row['lesson_id'] ?? 0 );
			$unlocks_at = (string) ( $row['unlocks_at'] ?? '' );
			if ( $user_id <= 0 || $lesson_id <= 0 || '' === $unlocks_at ) {
				continue;
			}
			$placeholders[] = '(%d, %d, %s)';
			$params[]       = $user_id;
			$params[]       = $lesson_id;
			$params[]       = $unlocks_at;
		}

		if ( ! $placeholders ) {
			return 0;
		}

		$table = static::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + %d/%s placeholders only.
		$sql = "INSERT IGNORE INTO {$table} (user_id, lesson_id, unlocks_at) VALUES " . implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$result = static::db()->query( static::db()->prepare( $sql, ...$params ) );

		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();

		return (int) $result;
	}

	/**
	 * Fetch the unlock row for one (user, lesson) pair, or null when none
	 * exists. Single-row read on the UNIQUE index — O(1).
	 */
	public static function find_for_user_lesson( int $user_id, int $lesson_id ): ?object {
		if ( $user_id <= 0 || $lesson_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d AND lesson_id = %d LIMIT 1',
				$user_id,
				$lesson_id
			)
		);

		return $row ?: null;
	}

	/**
	 * Fetch all unlock rows whose `unlocks_at` falls inside (`$from_utc`, `$to_utc`].
	 *
	 * Used by `Drip_Cron::process_window()` to fan out
	 * `learnomy_lesson_unlocked` once a relative-drip row crosses NOW().
	 * The window matches the fixed-date scan exactly so both drip modes
	 * fire from the same cron tick.
	 *
	 * @return object[] Rows with id, user_id, lesson_id, unlocks_at.
	 */
	public static function get_unlocks_in_window( string $from_utc, string $to_utc ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT id, user_id, lesson_id, unlocks_at FROM ' . static::table() . '
				 WHERE unlocks_at > %s AND unlocks_at <= %s',
				$from_utc,
				$to_utc
			)
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Delete every computed unlock row for a lesson. Called by the
	 * {@see \Learnomy\Models\Lesson::delete()} cascade so removing a relative-drip
	 * lesson can't leave orphaned `lrn_lesson_unlocks` rows behind — orphans here
	 * would mis-gate drip access for a re-enrolled student against a lesson id
	 * that no longer exists.
	 *
	 * @param int $lesson_id Lesson row id.
	 * @return int Number of unlock rows removed.
	 */
	public static function delete_for_lesson( int $lesson_id ): int {
		if ( $lesson_id <= 0 ) {
			return 0;
		}
		$deleted = (int) static::db()->delete( static::table(), [ 'lesson_id' => $lesson_id ], [ '%d' ] );
		static::invalidate_cache();
		return $deleted;
	}

	/**
	 * Delete still-PENDING unlock rows (`unlocks_at` in the future) for a set of
	 * lessons.
	 *
	 * The collaborative cleanup primitive: a Pro extension that scheduled unlock
	 * rows into this Free ledger (e.g. content-drip) calls this — through the
	 * model, never a raw `lrn_` query — when it stops driving those lessons, so
	 * the Free drip cron stops firing "lesson unlocked" for gating that is gone.
	 * Only future rows are removed; a row whose `unlocks_at` has already passed
	 * has already fired (or been suppressed by the enrollment gate), so deleting
	 * it would change nothing and could re-fire on a clock skew.
	 *
	 * @param int[]       $lesson_ids Lesson ids to purge pending unlocks for.
	 * @param string|null $now_utc    UTC boundary; defaults to now. Rows with
	 *                                `unlocks_at > $now_utc` are removed.
	 * @return int Rows deleted.
	 */
	public static function delete_pending_for_lessons( array $lesson_ids, ?string $now_utc = null ): int {
		$lesson_ids = array_values( array_unique( array_filter( array_map( 'absint', $lesson_ids ) ) ) );
		if ( empty( $lesson_ids ) ) {
			return 0;
		}

		$now_utc      = $now_utc ?? current_time( 'mysql', true );
		$placeholders = implode( ',', array_fill( 0, count( $lesson_ids ), '%d' ) );
		$table        = static::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name + %d/%s placeholders only.
		$sql = "DELETE FROM {$table} WHERE unlocks_at > %s AND lesson_id IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$deleted = (int) static::db()->query( static::db()->prepare( $sql, $now_utc, ...$lesson_ids ) );
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
		return $deleted;
	}
}
