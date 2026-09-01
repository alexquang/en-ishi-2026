<?php
/**
 * Tag — flat taxonomy shared across courses and other object types.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

class Tag extends Model {

	protected const INT_COLUMNS = [
		'usage_count',
	];

	protected static function table_name(): string {
		return 'tags';
	}

	public static function create( array $tag ): int {
		return static::insert(
			array_merge(
				[
					'type'        => 'course',
					'usage_count' => 0,
				],
				$tag
			)
		);
	}

	/**
	 * Slug lookup scoped to a vocabulary. Tags are unique per (slug, type) — the
	 * same name can exist as a Course tag AND a Lesson tag — so every lookup is
	 * type-scoped. (A type-agnostic find_by_slug existed in the course-only era;
	 * it was removed once tags became per-type — it could return the wrong
	 * vocabulary's tag.)
	 */
	public static function find_by_slug_and_type( string $slug, string $type ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE slug = %s AND type = %s LIMIT 1',
				$slug,
				$type
			)
		) ?: null;
	}

	/**
	 * Tags of a vocabulary with their usage count, for the central Tags
	 * manager. `usage_count` is the number of objects the tag is mapped to in
	 * `lrn_tag_map` for the SAME `object_type` as the tag's `type` (a quiz tag
	 * is used by quizzes, a course tag by courses, …). The base list query
	 * couldn't express this, which is why the manager's count column showed 0
	 * for every non-course vocabulary.
	 *
	 * Single aggregate query: a LEFT JOIN on lrn_tag_map grouped by tag, counted
	 * with one filesort. The previous shape ran a correlated subquery per row
	 * (one COUNT(*) execution per tag) and returned the whole table unbounded,
	 * so at 5k+ tags the manager fired 5k subqueries and hydrated 5k rows. The
	 * JOIN is index-bound (lrn_tag_map KEY tag_object_type (tag_id, object_type)
	 * covers the ON clause) and LIMIT/OFFSET caps the page. Pair with
	 * {@see count_by_type()} for the total so the caller paginates off COUNT(*),
	 * never count( list_with_usage() ).
	 *
	 * Note: COUNT(m.tag_id) — lrn_tag_map has no `id` column (its primary key is
	 * the composite (object_type, object_id, tag_id)); tag_id is non-null on
	 * every matched row, so it counts mappings exactly while staying NULL-safe
	 * for tags with zero mappings under the LEFT JOIN.
	 *
	 * @param string $type   Tag vocabulary (course / lesson / quiz / question / …).
	 * @param int    $limit  Max rows to return. Default 500 — one manager page.
	 * @param int    $offset Row offset for pagination. Default 0.
	 * @return object[] Tag rows ordered by name, each with a `usage_count` int.
	 */
	public static function list_with_usage( string $type, int $limit = 500, int $offset = 0 ): array {
		$tags = static::table();
		$map  = static::db()->prefix . 'lrn_tag_map';

		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		return static::db()->get_results(
			static::db()->prepare(
				"SELECT t.*, COUNT(m.tag_id) AS usage_count
				 FROM {$tags} t
				 LEFT JOIN {$map} m ON m.tag_id = t.id AND m.object_type = %s
				 WHERE t.type = %s
				 GROUP BY t.id
				 ORDER BY t.name ASC
				 LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$type,
				$type,
				$limit,
				$offset
			)
		) ?: array();
	}

	/**
	 * Total tags in a vocabulary, for paginating {@see list_with_usage()}. A
	 * dedicated COUNT(*) so the manager never calls count( list_with_usage() )
	 * (which would defeat the LIMIT). Mirrors {@see Model::count()} but stays a
	 * single typed helper because the column is fixed to `type`.
	 */
	public static function count_by_type( string $type ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE type = %s',
				$type
			)
		);
	}

	/**
	 * Tag IDs currently mapped to any object (via lrn_tag_map). Used to
	 * pre-check the editor tag pickers for courses and lessons alike.
	 *
	 * @return int[]
	 */
	public static function get_ids_for_object( int $object_id, string $object_type ): array {
		if ( $object_id <= 0 || '' === $object_type ) {
			return array();
		}
		$map = static::db()->prefix . 'lrn_tag_map';
		$ids = static::db()->get_col(
			static::db()->prepare(
				"SELECT tag_id FROM {$map} WHERE object_id = %d AND object_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$object_id,
				$object_type
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Tag IDs mapped to a course. Thin alias over {@see get_ids_for_object()}
	 * kept for existing callers.
	 *
	 * @return int[]
	 */
	public static function get_ids_for_course( int $course_id ): array {
		return static::get_ids_for_object( $course_id, 'course' );
	}

	/**
	 * Replace an object's tag-map rows with the given list. Tag IDs that don't
	 * exist are skipped — they can't cascade into the junction if the tag row
	 * itself is missing. Lives in the model so no raw $wpdb leaks into
	 * controllers (architecture rule 4).
	 *
	 * @param int    $object_id Object being tagged.
	 * @param string $object_type Object type (course, question, ...).
	 * @param int[]  $tag_ids
	 */
	public static function sync_object_tags( int $object_id, string $object_type, array $tag_ids ): void {
		if ( $object_id <= 0 || '' === $object_type ) {
			return;
		}

		$map = static::db()->prefix . 'lrn_tag_map';

		// Tags losing the association need their denormalized usage_count
		// recomputed too, so capture the prior set before we drop the rows.
		$previous = self::get_ids_for_object( $object_id, $object_type );

		// Drop all existing rows for this object, then re-insert the new set.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		static::db()->delete(
			$map,
			array(
				'object_id'   => $object_id,
				'object_type' => $object_type,
			)
		);

		$new = array();
		foreach ( array_unique( array_map( 'intval', $tag_ids ) ) as $tag_id ) {
			if ( $tag_id <= 0 || ! static::find( $tag_id ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			static::db()->insert(
				$map,
				array(
					'tag_id'      => $tag_id,
					'object_id'   => $object_id,
					'object_type' => $object_type,
				)
			);
			$new[] = $tag_id;
		}

		// Keep the denormalized usage_count accurate for every tag whose
		// membership changed (the union of removed + added). This is the single
		// chokepoint for assignment, so the count never drifts and the app's
		// "popular tags first" ordering ({@see get_tags()} REST) stays truthful.
		foreach ( array_unique( array_merge( $previous, $new ) ) as $affected_id ) {
			self::recompute_usage_count( (int) $affected_id );
		}

		// Raw delete/insert above bypass base insert/update/delete — bump the cache group.
		static::invalidate_cache();
	}

	/**
	 * Recompute one tag's denormalized usage_count from lrn_tag_map. Called by
	 * sync_object_tags() whenever a tag's membership changes; also the unit of
	 * work for {@see recompute_all_usage_counts()}.
	 */
	public static function recompute_usage_count( int $id ): void {
		if ( $id <= 0 ) {
			return;
		}
		$map = static::db()->prefix . 'lrn_tag_map';
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . " SET usage_count = ( SELECT COUNT(*) FROM {$map} WHERE tag_id = %d ) WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id,
				$id
			)
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
	}

	/**
	 * One-shot backfill: recompute usage_count for every tag from lrn_tag_map.
	 * Used to seed accurate counts on installs that predate the maintenance in
	 * sync_object_tags(). Single UPDATE … correlated subquery, no per-row loop.
	 */
	public static function recompute_all_usage_counts(): void {
		$map = static::db()->prefix . 'lrn_tag_map';
		static::db()->query(
			'UPDATE ' . static::table() . " t SET t.usage_count = ( SELECT COUNT(*) FROM {$map} m WHERE m.tag_id = t.id )" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
	}
}
