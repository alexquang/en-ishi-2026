<?php
/**
 * Review — one student, one course, one rating. UNIQUE enforced at DB level.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Review extends Model {

	/**
	 * Length ceilings for the free-text columns — ONE source, read by the REST
	 * arg schemas, the service guards and the form `maxlength` attributes so the
	 * three surfaces cannot drift apart.
	 *
	 * `title` is exactly the `varchar(255)` column width, and that is not
	 * cosmetic: `wpdb::insert()` REFUSES an over-length value (it returns false
	 * with "Processing the value for the following field failed") rather than
	 * truncating it, so a 300-character title used to sink the whole insert and
	 * the controller then fatalled on the null row. Cap == column width means
	 * the boundary rejects what the DB would have rejected, with a message.
	 *
	 * `content` and `instructor_reply` are `longtext`, so nothing downstream
	 * ever complained — a 10,000-character review was stored verbatim. 5,000 is
	 * the product call: roughly 800 words, more than any honest course review
	 * needs, and the same ceiling this plugin already applies to instructor bios
	 * and teaching-experience fields.
	 */
	public const MAX_TITLE_LENGTH   = 255;
	public const MAX_CONTENT_LENGTH = 5000;
	public const MAX_REPLY_LENGTH   = 5000;

	protected const INT_COLUMNS = [
		'user_id',
		'course_id',
		'rating',
	];

	/**
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'course' => 'course_id',
	);

	protected static function table_name(): string {
		return 'reviews';
	}

	public static function create( array $review ): int {
		$defaults = [
			'status'     => 'approved',
			'created_at' => now(),
			'updated_at' => now(),
		];
		return static::insert( array_merge( $defaults, $review ) );
	}

	/**
	 * Delete every review for a course — used by `Course_Service::delete()`
	 * to cascade on hard-delete. Returns the number of rows removed.
	 */
	public static function delete_for_course( int $course_id ): int {
		// Raw write bypasses base insert/update/delete — bump the cache group.
		$deleted = (int) static::db()->delete( static::table(), [ 'course_id' => $course_id ], [ '%d' ] );
		static::invalidate_cache();
		return $deleted;
	}

	public static function get_for_course( int $course_id, int $page = 1, int $per_page = 15, array $exclude_user_ids = [] ): array {
		$offset  = ( max( 1, $page ) - 1 ) * $per_page;
		$exclude = self::exclude_clause( $exclude_user_ids );
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE course_id = %d AND status = 'approved'" . $exclude . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$course_id,
				$per_page,
				$offset
			)
		) ?: [];
	}

	/**
	 * Approved-review count for a course, optionally excluding a set of authors
	 * (the viewer's block list) so a filtered list's total stays honest.
	 */
	public static function count_for_course( int $course_id, array $exclude_user_ids = [] ): int {
		$exclude = self::exclude_clause( $exclude_user_ids );
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . " WHERE course_id = %d AND status = 'approved'" . $exclude,
				$course_id
			)
		);
	}

	/**
	 * Build a safe `AND user_id NOT IN (...)` fragment from a list of ids. Ids
	 * are cast to int, so the interpolation carries no user input. Empty list →
	 * empty string (no filter), the common case.
	 */
	private static function exclude_clause( array $exclude_user_ids ): string {
		$ids = array_filter( array_map( 'intval', $exclude_user_ids ) );
		if ( ! $ids ) {
			return '';
		}
		return ' AND user_id NOT IN (' . implode( ',', $ids ) . ')';
	}

	public static function get_user_review( int $user_id, int $course_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d AND course_id = %d LIMIT 1',
				$user_id,
				$course_id
			)
		) ?: null;
	}

	/** Aggregate rating and count for a course's approved reviews. */
	public static function get_aggregate( int $course_id ): object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT COALESCE(AVG(rating), 0) AS avg_rating, COUNT(*) AS review_count FROM ' . static::table() . " WHERE course_id = %d AND status = 'approved'",
				$course_id
			)
		) ?: (object) [
			'avg_rating'   => 0,
			'review_count' => 0,
		];
	}

	/**
	 * Star histogram for a course's approved reviews, as a 5→1 keyed map of
	 * counts (always all five keys, zero-filled). One GROUP BY query instead of
	 * pulling every review row into PHP to tally — the single-course page used
	 * to fetch up to 999 rows just to build this, which doesn't scale on a
	 * course with thousands of reviews.
	 *
	 * @return array<int,int> [5=>n, 4=>n, 3=>n, 2=>n, 1=>n]
	 */
	public static function get_rating_distribution( int $course_id ): array {
		$distribution = array(
			5 => 0,
			4 => 0,
			3 => 0,
			2 => 0,
			1 => 0,
		);
		$rows         = static::db()->get_results(
			static::db()->prepare(
				'SELECT ROUND(rating) AS star, COUNT(*) AS total FROM ' . static::table() . " WHERE course_id = %d AND status = 'approved' GROUP BY ROUND(rating)",
				$course_id
			)
		) ?: array();
		foreach ( $rows as $row ) {
			$star                   = max( 1, min( 5, (int) $row->star ) );
			$distribution[ $star ] += (int) $row->total;
		}
		return $distribution;
	}

	/** Anonymizes all reviews for a user — GDPR erasure. */
	public static function anonymize_for_user( int $user_id ): void {
		static::db()->update(
			static::table(),
			[
				'user_id' => 0,
				'title'   => null,
				'content' => null,
				'status'  => 'anonymized',
			],
			[ 'user_id' => $user_id ],
			[ '%d', '%s', '%s', '%s' ],
			[ '%d' ]
		);
		static::invalidate_cache();
	}

	public static function has_reviewed( int $user_id, int $course_id ): bool {
		return null !== static::get_user_review( $user_id, $course_id );
	}

	/**
	 * Pending reviews across a set of courses, newest first. Single SQL
	 * query — the instructor dashboard was previously looping per course and
	 * filtering in PHP, which meant N+1 queries plus a silent bug
	 * ({@see self::get_for_course()} filters to status='approved' so it
	 * never returned pending rows).
	 *
	 * @param int[] $course_ids
	 * @return object[]
	 */
	public static function get_pending_for_courses( array $course_ids, int $limit = 10 ): array {
		if ( empty( $course_ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
		$params       = array_merge( $course_ids, array( $limit ) );

		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() .
				" WHERE status = 'pending' AND course_id IN ({$placeholders})" .
				' ORDER BY created_at DESC LIMIT %d',
				...$params
			)
		) ?: [];
	}

	/**
	 * Pending reviews across every course an instructor owns or co-teaches,
	 * scoped in SQL via Course's id subquery — so the dashboard surfaces
	 * pending reviews from the whole catalogue, not just the page of courses it
	 * lists. The subquery is already prepared (integer user id only), so it is
	 * safe to interpolate.
	 *
	 * @param int $user_id Instructor user ID.
	 * @param int $limit   Max rows to return.
	 * @return object[]
	 */
	public static function get_pending_for_instructor( int $user_id, int $limit = 10 ): array {
		if ( $user_id <= 0 ) {
			return [];
		}
		$subquery = \Learnomy\Models\Course::instructor_course_ids_subquery( $user_id );
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() .
				" WHERE status = 'pending' AND course_id IN ({$subquery})" .
				' ORDER BY created_at DESC LIMIT %d',
				$limit
			)
		) ?: [];
	}

	/**
	 * Paginated review fetch for the admin moderation page.
	 *
	 * The default `where()` helper does only equality + a fixed ORDER BY,
	 * which can't satisfy the admin Reviews list (status filter, content
	 * LIKE search, sortable date column). We do the LIKE search at the DB
	 * tier rather than client-side because a moderation queue can grow
	 * into the thousands — pulling every row into PHP to filter would
	 * blow past the big-site readiness checklist.
	 *
	 * Indexed: `(course_id, status, created_at)` covers status + date
	 * sort; the content LIKE is a moderation-page-only cost on the same
	 * query.
	 *
	 * @param array{status?:string,search?:string,sort?:string,limit?:int,offset?:int} $args
	 * @return object[]
	 */
	public static function get_paginated_admin( array $args = array() ): array {
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		$search = isset( $args['search'] ) ? (string) $args['search'] : '';
		$sort   = isset( $args['sort'] ) && 'date_asc' === $args['sort'] ? 'ASC' : 'DESC';
		$limit  = max( 1, min( 100, (int) ( $args['limit'] ?? 20 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$sql    = 'SELECT * FROM ' . static::table();
		$values = array();
		$where  = array();

		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}

		if ( '' !== $search ) {
			$like     = '%' . static::db()->esc_like( $search ) . '%';
			$where[]  = '(content LIKE %s OR title LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql     .= ' ORDER BY created_at ' . $sort . ', id ' . $sort;
		$sql     .= ' LIMIT %d OFFSET %d';
		$values[] = $limit;
		$values[] = $offset;

		return static::db()->get_results( static::db()->prepare( $sql, ...$values ) ) ?: array();
	}

	/**
	 * Count for the admin moderation page. Mirrors the WHERE clauses of
	 * `get_paginated_admin()` so total-pages stays in lockstep.
	 *
	 * @param array{status?:string,search?:string} $args
	 */
	public static function count_admin( array $args = array() ): int {
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		$search = isset( $args['search'] ) ? (string) $args['search'] : '';

		$sql    = 'SELECT COUNT(*) FROM ' . static::table();
		$values = array();
		$where  = array();

		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}

		if ( '' !== $search ) {
			$like     = '%' . static::db()->esc_like( $search ) . '%';
			$where[]  = '(content LIKE %s OR title LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $where ) ) {
			$sql = static::db()->prepare(
				$sql . ' WHERE ' . implode( ' AND ', $where ),
				...$values
			);
		}

		return (int) static::db()->get_var( $sql );
	}

	/**
	 * Status totals for the admin moderation page tabs. Single GROUP BY
	 * query so the tab counts don't cost one COUNT(*) per status.
	 *
	 * @return array<string,int> Map of status slug to row count.
	 */
	public static function count_by_status(): array {
		$rows = static::db()->get_results(
			'SELECT status, COUNT(*) AS total FROM ' . static::table() . ' GROUP BY status'
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->status ] = (int) $row->total;
		}
		return $out;
	}
}
