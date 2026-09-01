<?php
/**
 * Monthly engagement rollups — atomic increments via ON DUPLICATE KEY UPDATE.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

class EngagementMonthly extends Model {

	/**
	 * CLEAR. These are per-month rollup rows, and the history stays meaningful
	 * after a plan is retired — an owner comparing this year to last still
	 * wants the months the plan was selling. Deleting them would silently
	 * rewrite past reporting. Blanking the pointer keeps the totals and stops
	 * the row claiming a plan that is gone.
	 *
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'plan' => array(
			'column'    => 'plan_id',
			'on_delete' => \Learnomy\DB\Relations::CLEAR,
		),
	);


	protected static function table_name(): string {
		return 'engagement_monthly';
	}

	/**
	 * Bumps the lesson_count for a given instructor/plan/period combination.
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE so concurrent requests don't race —
	 * the DB serialises the increment atomically without needing a prior SELECT.
	 *
	 * @param int    $instructor_id
	 * @param int    $plan_id
	 * @param string $period  Period string in 'YYYY-MM' format (e.g. '2026-03').
	 * @param int    $by      Number of lessons to add (default 1).
	 * @return bool
	 */
	public static function increment( int $instructor_id, int $plan_id, string $period, int $by = 1 ): bool {
		$sql = static::db()->prepare(
			'INSERT INTO ' . static::table() . '
				(instructor_id, plan_id, period, lesson_count)
			VALUES (%d, %d, %s, %d)
			ON DUPLICATE KEY UPDATE lesson_count = lesson_count + VALUES(lesson_count)',
			$instructor_id,
			$plan_id,
			$period,
			$by
		);

		// Raw write bypasses base insert/update/delete — bump the cache group.
		$ok = false !== static::db()->query( $sql );
		static::invalidate_cache();
		return $ok;
	}

	public static function get_for_period( string $period ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE period = %s ORDER BY instructor_id ASC',
				$period
			)
		);
	}

	public static function get_for_instructor( int $instructor_id, int $limit = 12 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE instructor_id = %d ORDER BY period DESC LIMIT %d',
				$instructor_id,
				$limit
			)
		);
	}

	/*
	 * No delete-by-period method here, for the same reason Commission dropped
	 * its own: engagement counts are the weighting behind a month's membership
	 * payout, so wiping a period silently changes what instructors are owed. The
	 * CLI scenario that used it now deletes the rows it created, by ID.
	 */
}
