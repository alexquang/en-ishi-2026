<?php
/**
 * Course instructor junction — composite PK (course_id, user_id), no auto-increment.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class CourseInstructor extends Model {

	/**
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'course' => 'course_id',
	);

	protected static function table_name(): string {
		return 'course_instructors';
	}

	// Base find()/delete() assume an `id` column — don't use them here.

	public static function add_instructor( int $course_id, int $user_id, float $share_pct = 100.00, bool $is_primary = false ): bool {
		$result = static::db()->insert(
			static::table(),
			[
				'course_id'  => $course_id,
				'user_id'    => $user_id,
				'share_pct'  => $share_pct,
				'is_primary' => $is_primary ? 1 : 0,
				'added_at'   => now(),
			]
		);
		static::invalidate_cache();

		// Anyone assigned to teach a course IS an instructor, so guarantee they
		// have an lrn_instructors profile row — that table drives the admin
		// Instructors list, and without this a course author / co-instructor
		// never appeared there ("No instructors yet" beside real assignments).
		// Idempotent; the paired migration backfills pre-existing assignments.
		if ( false !== $result && $user_id > 0 ) {
			Instructor::ensure_for_user( $user_id );
		}

		return false !== $result;
	}

	public static function remove_instructor( int $course_id, int $user_id ): bool {
		$ok = false !== static::db()->delete(
			static::table(),
			[
				'course_id' => $course_id,
				'user_id'   => $user_id,
			]
		);
		static::invalidate_cache();
		return $ok;
	}

	public static function get_for_course( int $course_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE course_id = %d ORDER BY is_primary DESC, added_at ASC',
				$course_id
			)
		) ?: [];
	}

	public static function get_for_user( int $user_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d ORDER BY added_at DESC',
				$user_id
			)
		) ?: [];
	}

	/**
	 * One page of PUBLISHED course ids the user teaches, newest first. Pair with
	 * {@see count_published_for_user()}.
	 *
	 * Joins courses so an instructor row whose course was deleted or unpublished
	 * never reaches a public credential surface — the same reason
	 * Enrollment::get_distinct_for_user_page() joins it. Returns ids only; hydrate
	 * with Course::find_in() so the caller does one batched read instead of a
	 * query per row.
	 *
	 * @return array
	 */
	public static function published_course_ids_for_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		$courses = \Learnomy\table( 'courses' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$courses} is an internal table name.
		$ids = static::db()->get_col(
			static::db()->prepare(
				'SELECT ci.course_id FROM ' . static::table() . " ci
				 INNER JOIN {$courses} c ON c.id = ci.course_id
				 WHERE ci.user_id = %d AND c.status = 'published'
				 ORDER BY c.id DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				max( 1, $limit ),
				max( 0, $offset )
			)
		) ?: [];

		return array_map( 'intval', $ids );
	}

	/**
	 * Total published courses the user teaches. One COUNT(*), accurate at any
	 * scale — never count( published_course_ids_for_user() ).
	 */
	public static function count_published_for_user( int $user_id ): int {
		$courses = \Learnomy\table( 'courses' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$courses} is an internal table name.
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . " ci
				 INNER JOIN {$courses} c ON c.id = ci.course_id
				 WHERE ci.user_id = %d AND c.status = 'published'",
				$user_id
			)
		);
	}

	public static function is_primary( int $course_id, int $user_id ): bool {
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT is_primary FROM ' . static::table() . ' WHERE course_id = %d AND user_id = %d LIMIT 1',
				$course_id,
				$user_id
			)
		);
		return $row && (bool) $row->is_primary;
	}

	/** Updates the instructor user_id for all rows matching a course. */
	public static function update_for_course( int $course_id, array $data ): bool {
		$ok = false !== static::db()->update(
			static::table(),
			$data,
			[ 'course_id' => $course_id ]
		);
		static::invalidate_cache();
		return $ok;
	}

	/** Deletes all instructor links for a course. */
	public static function delete_for_course( int $course_id ): bool {
		$ok = false !== static::db()->delete(
			static::table(),
			[ 'course_id' => $course_id ]
		);
		static::invalidate_cache();
		return $ok;
	}

	/**
	 * Reassign every course this instructor owns to another user.
	 *
	 * Used when an instructor's WP account is deleted so their courses are
	 * handed to the site admin instead of orphaning to "Unknown" (COURSE-01).
	 * Because the table has a composite PK (course_id, user_id), a plain bulk
	 * UPDATE would collide on any course where the target is already a
	 * co-instructor — so we resolve per course:
	 *   - collision  → drop the departing row (merge); if it was the primary,
	 *                  promote the target so the course keeps a primary.
	 *   - no clash   → repoint the row's user_id (keeps share_pct / is_primary).
	 *
	 * @return int Number of courses reassigned.
	 */
	public static function reassign_user( int $from_user_id, int $to_user_id ): int {
		if ( $from_user_id <= 0 || $to_user_id <= 0 || $from_user_id === $to_user_id ) {
			return 0;
		}

		$rows  = static::get_for_user( $from_user_id );
		$count = 0;
		foreach ( $rows as $row ) {
			$course_id = (int) $row->course_id;

			$target_exists = static::db()->get_var(
				static::db()->prepare(
					'SELECT 1 FROM ' . static::table() . ' WHERE course_id = %d AND user_id = %d LIMIT 1',
					$course_id,
					$to_user_id
				)
			);

			if ( $target_exists ) {
				if ( (int) $row->is_primary === 1 ) {
					static::db()->update(
						static::table(),
						[ 'is_primary' => 1 ],
						[
							'course_id' => $course_id,
							'user_id'   => $to_user_id,
						]
					);
				}
				static::remove_instructor( $course_id, $from_user_id );
			} else {
				static::db()->update(
					static::table(),
					[ 'user_id' => $to_user_id ],
					[
						'course_id' => $course_id,
						'user_id'   => $from_user_id,
					]
				);
			}
			++$count;
		}

		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();

		return $count;
	}
}
