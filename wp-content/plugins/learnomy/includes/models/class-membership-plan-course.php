<?php
/**
 * Junction between membership plans and courses — composite PK, no auto-increment.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;


class MembershipPlanCourse extends Model {

	/**
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'course' => 'course_id',
		'plan'   => 'plan_id',
	);

	protected static function table_name(): string {
		return 'membership_plan_courses';
	}

	// Base find()/delete() operate on a single `id` column which doesn't exist here.
	// All access goes through the composite-key methods below.

	public static function add_course( int $plan_id, int $course_id ): bool {
		// Silently ignore duplicate assignments — idempotent by design.
		$existing = static::has_course( $plan_id, $course_id );
		if ( $existing ) {
			return true;
		}

		$inserted = static::db()->insert(
			static::table(),
			[
				'plan_id'   => $plan_id,
				'course_id' => $course_id,
			]
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();

		return false !== $inserted;
	}

	public static function remove_course( int $plan_id, int $course_id ): bool {
		$deleted = false !== static::db()->delete(
			static::table(),
			[
				'plan_id'   => $plan_id,
				'course_id' => $course_id,
			]
		);
		static::invalidate_cache();
		return $deleted;
	}

	public static function get_courses_for_plan( int $plan_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE plan_id = %d ORDER BY course_id ASC',
				$plan_id
			)
		);
	}

	public static function get_plans_for_course( int $course_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE course_id = %d',
				$course_id
			)
		);
	}

	/**
	 * Which plans cover each of a SET of courses — one query instead of one per
	 * course.
	 *
	 * The transpose of {@see count_courses_by_plan()}, and the batch form of
	 * {@see get_plans_for_course()}. Exists so a catalog page can answer "does
	 * this member's plan cover this course" for a whole grid without running
	 * `get_plans_for_course()` per card, which is the N+1 the list rules exist
	 * to prevent.
	 *
	 * Index-bound on PRIMARY KEY (plan_id, course_id).
	 *
	 * @param int[] $course_ids Course IDs.
	 * @return array<int,int[]> course_id => plan ids covering it. Courses no
	 *                          plan covers are absent, so default to an empty
	 *                          array.
	 */
	public static function get_plans_for_courses( array $course_ids ): array {
		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		if ( ! $course_ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT plan_id, course_id FROM ' . static::table() . " WHERE course_id IN ({$placeholders})",
				...$course_ids
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->course_id ][] = (int) $row->plan_id;
		}
		return $out;
	}

	/**
	 * Batched course counts for a set of plans — one GROUP BY query instead of
	 * one COUNT per plan in an admin-list loop. Returns a map of
	 * plan_id => course_count; plans with zero linked courses are absent from
	 * the map, so callers should default missing keys to 0.
	 *
	 * Index-bound on PRIMARY KEY (plan_id, course_id).
	 *
	 * @param int[] $plan_ids Plan IDs to count for.
	 * @return array<int,int> plan_id => course_count.
	 */
	public static function count_courses_by_plan( array $plan_ids ): array {
		$plan_ids = array_values( array_unique( array_filter( array_map( 'intval', $plan_ids ) ) ) );
		if ( ! $plan_ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $plan_ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT plan_id, COUNT(*) AS total FROM ' . static::table() . " WHERE plan_id IN ({$placeholders}) GROUP BY plan_id",
				...$plan_ids
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->plan_id ] = (int) $row->total;
		}
		return $out;
	}

	public static function has_course( int $plan_id, int $course_id ): bool {
		$count = (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE plan_id = %d AND course_id = %d',
				$plan_id,
				$course_id
			)
		);
		return $count > 0;
	}

	/** Deletes all course links for a plan — used during plan cleanup. */
	public static function delete_for_plan( int $plan_id ): bool {
		$deleted = false !== static::db()->delete(
			static::table(),
			[ 'plan_id' => $plan_id ]
		);
		static::invalidate_cache();
		return $deleted;
	}

	/**
	 * Replace the plan's course set with the given list in a single
	 * transaction — used by the admin plan editor. Idempotent: calling
	 * with the same list twice leaves the table unchanged.
	 */
	public static function replace_for_plan( int $plan_id, array $course_ids ): void {
		static::delete_for_plan( $plan_id );
		foreach ( array_unique( array_map( 'intval', $course_ids ) ) as $course_id ) {
			if ( $course_id > 0 ) {
				static::add_course( $plan_id, $course_id );
			}
		}
	}
}
