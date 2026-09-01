<?php
/**
 * Tag map — composite PK junction (object_type, object_id, tag_id), no auto-increment.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\table;

class TagMap extends Model {

	protected static function table_name(): string {
		return 'tag_map';
	}

	// Base find()/delete() are id-based — not valid for this table.
	//
	// Per-object assignment goes through Tag::sync_object_tags() (the single
	// chokepoint that also keeps usage_count accurate), so the row-level
	// attach()/detach() primitives this model used to expose were removed —
	// they had no callers and let writes bypass the count maintenance.

	public static function get_tags_for_object( string $object_type, int $object_id ): array {
		$tags_table = table( 'tags' );
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT t.* FROM ' . $tags_table . ' t
				INNER JOIN ' . static::table() . ' tm ON tm.tag_id = t.id
				WHERE tm.object_type = %s AND tm.object_id = %d
				ORDER BY t.name ASC',
				$object_type,
				$object_id
			)
		) ?: [];
	}

	/**
	 * Remove every junction row for a tag. Called when a tag is deleted so the
	 * many-to-many associations don't outlive the tag as orphan rows.
	 *
	 * @param int $tag_id Tag being deleted.
	 * @return int Number of junction rows removed.
	 */
	public static function delete_for_tag( int $tag_id ): int {
		// Raw write bypasses base insert/update/delete — bump the cache group.
		$deleted = (int) static::db()->query(
			static::db()->prepare(
				'DELETE FROM ' . static::table() . ' WHERE tag_id = %d',
				$tag_id
			)
		);
		static::invalidate_cache();
		return $deleted;
	}
}
