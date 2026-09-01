<?php
/**
 * Course category — supports nested hierarchy via parent_id.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Category extends Model {

	protected const INT_COLUMNS = [
		'parent_id',
		'sort_order',
		'course_count',
	];

	protected static function table_name(): string {
		return 'categories';
	}

	public static function create( array $category ): int {
		$defaults = [
			'sort_order'   => 0,
			'course_count' => 0,
			'type'         => 'course',
			'created_at'   => now(),
		];
		return static::insert( array_merge( $defaults, $category ) );
	}

	public static function find_by_slug( string $slug ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE slug = %s LIMIT 1',
				$slug
			)
		) ?: null;
	}

	public static function list_top_level( string $type = 'course' ): array {
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE (parent_id IS NULL OR parent_id = 0) AND type = %s ORDER BY sort_order ASC, name ASC, id ASC',
				$type
			)
		) ?: [];

		// Dedup by lower(name). Customer catalogues frequently end up with
		// "Web Development" (slug: web-development) AND "Web Development"
		// (slug: web-dev) after a CSV import, theme demo, or duplicate
		// seed pass. The filter dropdown surfaces this as a clear UX
		// defect — two identical-looking options that route to different
		// categories. Keep the earliest-id row (presumed canonical) and
		// drop the rest from the listing. The underlying rows stay in
		// the DB so an admin can merge them through a dedicated tool;
		// the dropdown stays clean either way.
		$seen = array();
		$out  = array();
		foreach ( $rows as $row ) {
			$key = strtolower( trim( (string) ( $row->name ?? '' ) ) );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $row;
		}
		return static::attach_course_counts( $out );
	}

	/**
	 * Flat, dropdown-ready category list for a type: one row per unique
	 * (slug, name), id/name/slug only, ordered by name.
	 *
	 * Shared by the Pro frontend course builder + editor, which previously
	 * each ran this query as raw $wpdb inside the template — a cross-plugin
	 * read of a Free table from Pro and a DB query in a template, both
	 * architecture-invariant violations (Basecamp #9936924900). Pro now calls
	 * this Free model method instead.
	 *
	 * @param string $type 'course' or 'learning_path'.
	 * @return object[] Rows with id, name, slug.
	 */
	public static function list_for_select( string $type = 'course' ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT MIN(id) AS id, name, slug FROM ' . static::table() . ' WHERE type = %s GROUP BY slug, name ORDER BY name ASC',
				$type
			)
		) ?: [];
	}

	public static function list_children( int $parent_id ): array {
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE parent_id = %d ORDER BY sort_order ASC, name ASC',
				$parent_id
			)
		) ?: [];
		return static::attach_course_counts( $rows );
	}

	/**
	 * The chain from the root down to (but not including) this category.
	 *
	 * The mirror of {@see descendant_ids()}, and the breadcrumb's source. A
	 * child category used to render as "Home > Courses > Typography" with the
	 * parent missing entirely, so a learner three levels deep could see where
	 * they were but not how they got there, and could not climb one step.
	 *
	 * Cycle-safe by the same `$visited` guard descendant_ids() uses: a
	 * self-parented or mutually-parented row would otherwise loop forever, and
	 * bad data must degrade to a short breadcrumb rather than a hung request.
	 *
	 * @param int $category_id Leaf category.
	 * @return object[] Ancestors, ROOT FIRST, ready to render in order.
	 */
	public static function ancestors( int $category_id ): array {
		if ( $category_id <= 0 ) {
			return array();
		}

		$chain   = array();
		$visited = array( $category_id => true );
		$current = static::find( $category_id );

		while ( $current && ! empty( $current->parent_id ) ) {
			$parent_id = (int) $current->parent_id;

			if ( isset( $visited[ $parent_id ] ) ) {
				break;
			}

			$visited[ $parent_id ] = true;
			$parent                = static::find( $parent_id );

			if ( ! $parent ) {
				break;
			}

			array_unshift( $chain, $parent );
			$current = $parent;
		}

		return $chain;
	}

	/**
	 * A category id plus every category nested underneath it, at any depth.
	 *
	 * THE definition of "the courses in this category". A category tree exists
	 * (`parent_id`, {@see self::list_children()}, and the archive renders its
	 * subcategory chips), but every listing query asked `category_id = %d`
	 * exactly — so a course filed under "Design > Typography" was absent from
	 * "Design", and the count beside "Design" disagreed with nothing anybody
	 * could click (Basecamp 10134139480). A visitor browsing a parent expects
	 * its children's courses; a shop that hides half its stock one level down
	 * is not a hierarchy, it is a set of unrelated labels.
	 *
	 * The whole table is read once per request and walked in PHP rather than
	 * issuing a query per level: `lrn_categories` is a taxonomy, not a data
	 * table — tens of rows on a real site, and one indexed read beats N round
	 * trips at any depth. Recursive CTEs would need MySQL 8, which the rest of
	 * this model deliberately does not require.
	 *
	 * Cycle-safe: bad data (a category reparented under its own descendant)
	 * terminates on the visited set instead of exhausting memory.
	 *
	 * @param int $category_id Root category id.
	 * @return int[] The root id first, then every descendant. Empty for id <= 0.
	 */
	public static function descendant_ids( int $category_id ): array {
		if ( $category_id <= 0 ) {
			return array();
		}

		/**
		 * Whether a category listing rolls up the courses filed under its
		 * descendants. Return false to restore strict "this category only"
		 * matching for a site that uses nesting purely as a filing convention.
		 *
		 * @since 1.8.1
		 *
		 * @param bool $rollup      Whether descendants are included. Default true.
		 * @param int  $category_id The category being listed.
		 */
		if ( ! apply_filters( 'learnomy_category_includes_descendants', true, $category_id ) ) {
			return array( $category_id );
		}

		$children = self::children_by_parent();

		$ids     = array( $category_id );
		$visited = array( $category_id => true );
		$queue   = array( $category_id );

		while ( ! empty( $queue ) ) {
			$parent = (int) array_shift( $queue );
			foreach ( $children[ $parent ] ?? array() as $child_id ) {
				if ( isset( $visited[ $child_id ] ) ) {
					continue;
				}
				$visited[ $child_id ] = true;
				$ids[]                = $child_id;
				$queue[]              = $child_id;
			}
		}

		return $ids;
	}

	/**
	 * parent_id => child ids, for the whole table, resolved once per request.
	 *
	 * Backs {@see self::descendant_ids()}. Kept private and memoized because a
	 * category listing page calls the resolver once per rendered category (the
	 * subcategory chips, the sidebar counts) and each of those would otherwise
	 * repeat the same full-table read.
	 *
	 * @return array<int, int[]>
	 */
	private static function children_by_parent(): array {
		if ( null !== self::$children_map ) {
			return self::$children_map;
		}

		$rows = static::db()->get_results(
			'SELECT id, parent_id FROM ' . static::table()
		) ?: array();

		$map = array();
		foreach ( $rows as $row ) {
			$parent = (int) ( $row->parent_id ?? 0 );
			if ( $parent > 0 ) {
				$map[ $parent ][] = (int) $row->id;
			}
		}

		self::$children_map = $map;

		return $map;
	}

	/**
	 * Request-local parent => children map. Null until first resolved.
	 *
	 * @var array<int, int[]>|null
	 */
	private static ?array $children_map = null;

	/**
	 * Drop the request-local tree alongside the model's object-cache group.
	 *
	 * Every write path in {@see Model} (insert / update / delete) and
	 * {@see self::reparent_children()} already calls this, so creating,
	 * deleting or reparenting a category in the same request that later lists
	 * one cannot serve a stale hierarchy. Overriding the existing hook is the
	 * whole invalidation story — there is no second bust list to keep in sync.
	 */
	protected static function invalidate_cache(): void {
		self::$children_map = null;
		parent::invalidate_cache();
	}

	/**
	 * Move every direct child of $from_parent up to $to_parent.
	 *
	 * Called when a category is deleted so its children are never orphaned —
	 * left pointing at a parent_id that no longer exists, which hides them from
	 * both {@see self::list_top_level()} and the (now-gone) parent's
	 * {@see self::list_children()}. Deleting a top-level category lifts its
	 * children to the top level ($to_parent = 0); deleting a nested category
	 * lifts its children to their grandparent.
	 *
	 * @param int $from_parent The category being deleted.
	 * @param int $to_parent   Where its children reattach (0 = top level).
	 * @return int Number of child rows moved.
	 */
	public static function reparent_children( int $from_parent, int $to_parent ): int {
		$moved = static::db()->update(
			static::table(),
			[ 'parent_id' => $to_parent ],
			[ 'parent_id' => $from_parent ]
		);
		if ( $moved ) {
			static::invalidate_cache();
		}
		return (int) $moved;
	}

	/**
	 * Categories of a type, every row carrying its live published-course count.
	 *
	 * Returns ALL rows of the type (top-level + children, no dedup) ordered
	 * by name — the shape the admin "All Categories" table needs. The count
	 * is computed on read via {@see self::attach_course_counts()}; see the
	 * note there for why the denormalized column isn't used.
	 *
	 * @param string $type Filter by 'course' or 'learning_path'.
	 * @return object[] Category rows with $cat->course_count populated.
	 */
	public static function list_with_course_count( string $type = 'course' ): array {
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE type = %s ORDER BY name ASC',
				$type
			)
		) ?: [];
		return static::attach_course_counts( $rows );
	}

	/**
	 * Attach the live published-course count to each category row.
	 *
	 * The denormalized `course_count` column was never maintained
	 * (Basecamp #9936653960) — every category listing surface (catalog
	 * filter, sidebar widget, category archive, admin table) instead
	 * computes the tally on read. Batches the whole row set into a single
	 * grouped query (no N+1) and writes the result onto each row's
	 * `course_count` property, making that property the single source of
	 * truth regardless of which list method produced the row.
	 *
	 * @param object[] $rows Category rows, each with an `id`.
	 * @return object[] The same rows, each with `course_count` set to the live tally.
	 */
	private static function attach_course_counts( array $rows ): array {
		if ( empty( $rows ) ) {
			return $rows;
		}

		// Each row counts its own courses PLUS its descendants' — the number
		// beside a category has to be the number of cards you get when you
		// click it, and the archive rolls nesting up ({@see Course::category_scope()}).
		$tree  = array();
		$union = array();
		foreach ( $rows as $row ) {
			$id          = (int) $row->id;
			$tree[ $id ] = self::descendant_ids( $id );
			$union       = array_merge( $union, $tree[ $id ] );
		}
		$union = array_values( array_unique( array_map( 'intval', $union ) ) );

		if ( empty( $union ) ) {
			foreach ( $rows as $row ) {
				$row->course_count = 0;
			}
			return $rows;
		}

		// One grouped read over the union, however deep the tree — never a
		// query per category, and never a query per level.
		$placeholders  = implode( ',', array_fill( 0, count( $union ), '%d' ) );
		$courses_table = static::db()->prefix . 'lrn_courses';

		// The same visibility gate every public listing uses. Counting bare
		// `status = 'published'` advertised unlisted and private courses in a
		// number an anonymous visitor could read, and then showed them a
		// shorter grid — the count and the page disagreed by exactly the
		// hidden rows.
		[ $where, $where_values ] = Course::public_listing_where();

		$counts = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, placeholder list and $where are code-built; values are bound.
				"SELECT category_id, COUNT(*) AS actual
				 FROM {$courses_table}
				 WHERE {$where} AND category_id IN ({$placeholders})
				 GROUP BY category_id",
				...array_merge( $where_values, $union )
			),
			OBJECT_K
		);

		foreach ( $rows as $row ) {
			$total = 0;
			foreach ( $tree[ (int) $row->id ] as $branch_id ) {
				$total += isset( $counts[ $branch_id ] ) ? (int) $counts[ $branch_id ]->actual : 0;
			}
			$row->course_count = $total;
		}

		return $rows;
	}
}
