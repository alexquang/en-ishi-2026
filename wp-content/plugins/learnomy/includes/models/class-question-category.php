<?php
/**
 * Question category — hierarchical taxonomy for organising the question bank.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class QuestionCategory extends Model {

	protected static function table_name(): string {
		return 'question_categories';
	}

	public static function create( array $category ): int {
		return static::insert( array_merge( [ 'question_count' => 0 ], $category ) );
	}

	public static function find_by_slug( string $slug ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE slug = %s LIMIT 1',
				$slug
			)
		) ?: null;
	}

	/** Categories at the root of the hierarchy — parent_id = 0 or NULL. */
	public static function list_top_level(): array {
		return static::db()->get_results(
			'SELECT * FROM ' . static::table() . ' WHERE parent_id IS NULL OR parent_id = 0 ORDER BY name ASC'
		);
	}

	public static function list_children( int $parent_id ): array {
		return static::where( [ 'parent_id' => $parent_id ], 'name', 'ASC' );
	}

	/**
	 * Flat id+name list for a select control (the bank-question picker filter).
	 * Ordered by name; bounded so a runaway taxonomy can't blow up a dropdown.
	 *
	 * @return array<int,array{id:int,name:string}>
	 */
	public static function list_for_select(): array {
		$rows = static::db()->get_results(
			'SELECT id, name FROM ' . static::table() . ' ORDER BY name ASC LIMIT 500'
		);
		$out  = [];
		foreach ( (array) $rows as $row ) {
			$out[] = [
				'id'   => (int) $row->id,
				'name' => (string) $row->name,
			];
		}
		return $out;
	}

	/** Keeps the denormalised count in sync after a question is added to this category. */
	public static function increment_question_count( int $id ): bool {
		$updated = false !== static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET question_count = question_count + 1 WHERE id = %d',
				$id
			)
		);
		static::invalidate_cache();
		return $updated;
	}

	/**
	 * Live question count per category, in one grouped query. The denormalised
	 * `question_count` column only ever INCREMENTS (on CSV import) — it is never
	 * decremented when a question is deleted or moved, and never recomputed, so it
	 * over-reports. Any picker/list that shows a per-category count should read
	 * this instead of the stored column (Basecamp 10109341075).
	 *
	 * @param int[] $category_ids Empty = count every category.
	 * @return array<int,int> category_id => live question count (absent = 0).
	 */
	public static function live_question_counts( array $category_ids = array() ): array {
		$questions = \Learnomy\table( 'questions' );
		$ids       = array_values( array_unique( array_filter( array_map( 'intval', $category_ids ) ) ) );

		$where  = '';
		$params = array();
		if ( ! empty( $ids ) ) {
			$where  = ' WHERE category_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params = $ids;
		}
		$sql = "SELECT category_id, COUNT(*) AS n FROM {$questions}{$where} GROUP BY category_id";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table + placeholders internal; ids bound.
		$rows = $params
			? static::db()->get_results( static::db()->prepare( $sql, ...$params ) )
			: static::db()->get_results( $sql );

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->category_id ] = (int) $row->n;
		}
		return $map;
	}
}
