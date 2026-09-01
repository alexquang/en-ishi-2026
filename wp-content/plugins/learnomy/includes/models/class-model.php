<?php
/**
 * Base model for the lrn_* tables.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\table;

abstract class Model {

	/**
	 * Columns that are genuine boolean flags (`tinyint(1)` in the schema).
	 *
	 * `$wpdb` returns every column as a string, so a `"0"` flag fed to a
	 * JavaScript `!! value` coerces to `true` (a non-empty string is truthy).
	 * `to_typed_array()` casts these back to real PHP `bool`, which serialises
	 * to JSON `true` / `false`. Subclasses declare their own flag columns.
	 *
	 * Only list columns that are truly boolean. A small unsigned counter
	 * (`rating`, `passing_score`, `progress_pct`) is an INT, not a flag.
	 *
	 * @var string[]
	 */
	protected const BOOL_COLUMNS = [];

	/**
	 * Integer columns (`int` / `bigint` — counts, durations, foreign keys).
	 * `id` is always cast to int by `to_typed_array()`; do not list it here.
	 *
	 * @var string[]
	 */
	protected const INT_COLUMNS = [];

	/**
	 * Decimal columns (`decimal(p,s)` — money, rates, fractions).
	 *
	 * @var string[]
	 */
	protected const FLOAT_COLUMNS = [];

	abstract protected static function table_name(): string;

	/**
	 * Cast a raw `$wpdb` row to proper PHP scalar types for a REST response.
	 *
	 * `$wpdb` hands back every column as a string. Sending those strings to a
	 * client makes booleans (`"0"` / `"1"`) and numbers (`"5"`) ambiguous — the
	 * common front-end `!! value` coercion treats `"0"` as truthy, silently
	 * flipping a flag. This helper consults the per-model `BOOL_COLUMNS` /
	 * `INT_COLUMNS` / `FLOAT_COLUMNS` maps and casts only those columns, leaving
	 * text, JSON, slug, status, and datetime columns exactly as the DB returned
	 * them. `NULL` always stays `null`. `id` is always returned as an int.
	 *
	 * Route raw-row REST returns through this:
	 *
	 *     $this->single_response( Lesson::to_typed_array( Lesson::find( $id ) ) );
	 *
	 * @param object|array|null $row A `$wpdb` row (object), a row already cast
	 *                               to array, or null (a missing record).
	 * @return array|null The typed associative array, or null when $row is null.
	 */
	public static function to_typed_array( object|array|null $row ): ?array {
		if ( null === $row ) {
			return null;
		}

		$out = (array) $row;

		foreach ( $out as $column => $value ) {
			if ( null === $value ) {
				continue; // Preserve SQL NULL as JSON null.
			}

			if ( 'id' === $column || in_array( $column, static::INT_COLUMNS, true ) ) {
				$out[ $column ] = (int) $value;
			} elseif ( in_array( $column, static::BOOL_COLUMNS, true ) ) {
				$out[ $column ] = (bool) (int) $value;
			} elseif ( in_array( $column, static::FLOAT_COLUMNS, true ) ) {
				$out[ $column ] = (float) $value;
			}
		}

		return $out;
	}

	protected static function table(): string {
		return table( static::table_name() );
	}

	/**
	 * What this table points at, declared once.
	 *
	 * Override in a model that holds a foreign id:
	 *
	 *     protected static array $references = array(
	 *         'course' => 'course_id',
	 *         'user'   => array( 'column' => 'user_id', 'on_delete' => Relations::KEEP ),
	 *     );
	 *
	 * A bare string is the column and means DELETE — the row goes when its
	 * parent does. Use the array form with `on_delete => KEEP` for a row that
	 * is a record of something that HAPPENED rather than a part of the parent
	 * (a transaction, an audit entry, an issued certificate): deleting a course
	 * should void a credential, not erase the fact it was earned, and should
	 * never rewrite the accounts.
	 *
	 * This one declaration drives the cascade, the integrity check and the
	 * repair command — see {@see \Learnomy\DB\Relations}. Before it existed,
	 * each of those three was a hand-written list, and they had already drifted:
	 * lesson deletion cleaned none of the ten tables that reference a lesson.
	 *
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array();

	/**
	 * The declarations above, for the registry.
	 *
	 * Public because `Relations::boot()` reads it from outside the class; the
	 * property itself stays protected so a model declares it rather than having
	 * it assigned from elsewhere.
	 *
	 * @return array<string, string|array{column:string,on_delete:string}>
	 */
	public static function relation_declarations(): array {
		return static::$references;
	}

	/**
	 * This model's table name, for the registry.
	 *
	 * `table()` is protected and stays that way — callers outside the model
	 * layer have no business building queries against it. The registry needs
	 * only the name, so it gets only the name.
	 */
	public static function relation_table(): string {
		return static::table();
	}

	protected static function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Cache group for this model's reads.
	 *
	 * Default derives from the table name (`learnomy_<table>`) so each
	 * Model gets its own group. Subclasses MAY override to share a
	 * group with related Models (e.g., `Course` and `Section` both use
	 * `'learnomy_courses'` so a section change invalidates course list
	 * caches too).
	 *
	 * Used together with `wp_cache_set_last_changed()` — every write
	 * bumps the incrementor for this group; cached reads keyed off
	 * `wp_cache_get_last_changed( cache_group() )` orphan automatically.
	 * See `references/scale-and-cache.md` for the rationale at 10k×100k
	 * scale.
	 */
	protected static function cache_group(): string {
		return 'learnomy_' . static::table_name();
	}

	/**
	 * Bump the cache-group incrementor. Called automatically by
	 * `insert/update/delete`; subclasses may also call it from
	 * domain-specific write methods (e.g., bulk imports).
	 */
	protected static function invalidate_cache(): void {
		wp_cache_set_last_changed( static::cache_group() );
	}

	/**
	 * Single-row PK lookup, cached in the model's object-cache group.
	 *
	 * Curriculum/dashboard render paths call find() for the same course/section
	 * row dozens-to-hundreds of times per request (caching standard §2 — the
	 * single-course page fired ~1k queries, ~5 per lesson, re-fetching the same
	 * course row). The cache key is namespaced by the group incrementor, so any
	 * write to the group (every insert/update/delete + every model raw write,
	 * enforced by coding-rules Rule 14) bumps last_changed and orphans the entry
	 * — no manual bust list. This is coherent ONLY because the Rule 14 sweep made
	 * every write invalidate; a bare find()-cache without it served a stale
	 * student_count (the 2026-07-12 revert). The returned object is cloned so a
	 * caller mutating its copy can't corrupt the shared cached row on a
	 * non-persistent (request-local) cache where wp_cache_get returns a reference.
	 */
	public static function find( int $id ): ?object {
		if ( $id <= 0 ) {
			return null;
		}
		$group = static::cache_group();
		$key   = self::find_cache_key( $id, wp_cache_get_last_changed( $group ) );

		$found  = false;
		$cached = wp_cache_get( $key, $group, false, $found );
		if ( $found ) {
			return is_object( $cached ) ? clone $cached : null;
		}

		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE id = %d',
				$id
			)
		) ?: null;

		wp_cache_set( $key, $row, $group );
		return is_object( $row ) ? clone $row : null;
	}

	/** Cache key for a single find() lookup, namespaced by the group incrementor. */
	private static function find_cache_key( int $id, string $last_changed ): string {
		return static::table_name() . ':find:' . $id . ':' . $last_changed;
	}

	/**
	 * Prime the find() cache from a batch of already-fetched rows (scale C2).
	 *
	 * A curriculum/dashboard resolver that has already loaded a set of rows in one
	 * query (get_for_course, find_in) calls this so the downstream per-row find()
	 * calls — the per-lesson availability/drip checks that fired ~5 queries per
	 * lesson — become cache hits instead of N single-row SELECTs. Same key scheme
	 * as find(), namespaced by the current incrementor, so a later write orphans
	 * the primed entries too.
	 *
	 * @param object[] $rows Rows carrying an `id` (from a batch read of THIS model).
	 */
	public static function prime_find_cache( array $rows ): void {
		if ( ! $rows ) {
			return;
		}
		$group        = static::cache_group();
		$last_changed = wp_cache_get_last_changed( $group );
		foreach ( $rows as $row ) {
			if ( is_object( $row ) && isset( $row->id ) ) {
				// Store a clone: the same $row objects are returned to the caller,
				// so caching the reference would let a caller mutating a
				// get_for_course row corrupt the shared cached copy on a
				// request-local cache. find() also clones on read.
				wp_cache_set( self::find_cache_key( (int) $row->id, $last_changed ), clone $row, $group );
			}
		}
	}

	/**
	 * Batch-lookup rows by ID list. Returns a map of id → row so callers
	 * can enrich a parent list (e.g., path steps → course rows) in one
	 * query instead of N. Always cap your input list — this builds a
	 * `WHERE id IN (...)` and a giant list will fail at MySQL's
	 * `max_allowed_packet` boundary.
	 */
	public static function find_in( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$ids
			)
		);
		$out          = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->id ] = $row;
		}
		return $out;
	}

	/**
	 * Content models (course/lesson/quiz/question/learning-path) opt in so the
	 * WordPress-native `publish` is auto-healed to Learnomy's canonical
	 * `published` on EVERY write — insert or update, whatever the caller.
	 *
	 * The stored publish-status vocabulary is a single word across every content
	 * entity (`published`). Enforcing it here — the one funnel every write passes
	 * through — means no REST client, import, seeder, CLI, or third-party can
	 * reintroduce the `publish`/`published` split (which historically hid rows
	 * from the admin Published tab and returned zero-row learning-path queries).
	 * Non-content models keep their own domain vocabularies and stay opted out.
	 */
	protected static function normalizes_publish_status(): bool {
		return false;
	}

	private static function heal_publish_status( array $data ): array {
		if ( static::normalizes_publish_status()
			&& isset( $data['status'] )
			&& 'publish' === $data['status'] ) { // publish-status-ok — this IS the heal.
			$data['status'] = 'published';
		}
		return $data;
	}

	/**
	 * Derived FULLTEXT projections this model owns: rich column => plain column.
	 *
	 * A projection is a denormalised copy, and a denormalised copy maintained at
	 * one CALL SITE is a copy that every other write path silently skips. That is
	 * exactly what happened to `lrn_courses.description_plain`: only the REST save
	 * path derived it, so courses created by CLI, the demo seeder, the importer or
	 * the LearnDash migrator shipped with an empty projection and never matched a
	 * description search (Basecamp 10134139389). `lrn_lessons.content_plain` had
	 * the same hole on update.
	 *
	 * Declaring the projection here moves the derivation to the one funnel every
	 * write passes through — insert AND update, whatever the caller. A caller may
	 * still pass the projection column explicitly (imports carrying a
	 * pre-computed value); an explicit value always wins.
	 *
	 * @return array<string,string> Source column => projection column.
	 */
	protected static function search_projections(): array {
		return [];
	}

	private static function derive_search_projections( array $data ): array {
		foreach ( static::search_projections() as $source => $target ) {
			if ( array_key_exists( $source, $data ) && ! array_key_exists( $target, $data ) ) {
				$data[ $target ] = \Learnomy\search_text_projection( (string) $data[ $source ] );
			}
		}
		return $data;
	}

	public static function insert( array $data ): int {
		$data = self::heal_publish_status( $data );
		$data = self::derive_search_projections( $data );
		$data = static::stamp_timestamps( $data, true );
		static::db()->insert( static::table(), $data );
		$id = (int) static::db()->insert_id;
		if ( $id ) {
			static::invalidate_cache();
		}
		return $id;
	}

	public static function update( int $id, array $data ): bool {
		$data = self::heal_publish_status( $data );
		$data = self::derive_search_projections( $data );
		$data = static::stamp_timestamps( $data, false );
		$ok   = false !== static::db()->update( static::table(), $data, [ 'id' => $id ] );
		if ( $ok ) {
			static::invalidate_cache();
		}
		return $ok;
	}

	/**
	 * Fill in `created_at` / `updated_at` when the caller left them out.
	 *
	 * THE root-cause fix for the zero-date sentinel. 78 timestamp columns across
	 * 56 tables are declared `datetime NOT NULL DEFAULT '0000-00-00 00:00:00'`,
	 * so an INSERT that omits one does not fail and does not store NULL - MySQL
	 * writes the sentinel. Every seeder, importer, migration and service that
	 * forgot a timestamp has been minting values that later render as
	 * "November 30, -0001" (Basecamp 10184459033) or read as long expired
	 * (Basecamp 10163002840), and each surface then grew its own private guard.
	 *
	 * Guarding the ~53 places that DISPLAY a date is necessary - every install
	 * already has rows carrying the sentinel and we cannot safely rewrite a
	 * customer's data - but it is defensive. This is the half that stops the
	 * plugin producing the value at all, so the problem shrinks over time on
	 * every site rather than needing a new guard each time a surface is added.
	 *
	 * Deliberately conservative:
	 *   - only columns the table actually HAS, checked once per request;
	 *   - never overwrites a value the caller supplied, including a deliberate
	 *     backdate from an importer;
	 *   - `created_at` on insert only, so an update cannot rewrite history.
	 *
	 * @param array<string, mixed> $data     Column map on its way to the DB.
	 * @param bool                 $is_insert True for INSERT, false for UPDATE.
	 * @return array<string, mixed>
	 */
	protected static function stamp_timestamps( array $data, bool $is_insert ): array {
		static $cache = [];

		$table = static::table();
		if ( ! isset( $cache[ $table ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$found           = (array) static::db()->get_col( "SHOW COLUMNS FROM `{$table}` WHERE Field IN ( 'created_at', 'updated_at' )" );
			$cache[ $table ] = array_map( 'strval', $found );
		}

		$columns = $cache[ $table ];
		if ( empty( $columns ) ) {
			return $data;
		}

		$now = \Learnomy\now();

		if ( $is_insert && in_array( 'created_at', $columns, true ) && ! array_key_exists( 'created_at', $data ) ) {
			$data['created_at'] = $now;
		}

		if ( in_array( 'updated_at', $columns, true ) && ! array_key_exists( 'updated_at', $data ) ) {
			$data['updated_at'] = $now;
		}

		return $data;
	}

	public static function delete( int $id ): bool {
		$ok = false !== static::db()->delete( static::table(), [ 'id' => $id ] );
		if ( $ok ) {
			static::invalidate_cache();
		}
		return $ok;
	}

	/**
	 * Does the underlying table physically exist?
	 *
	 * Used by admin views that need to degrade gracefully when an
	 * extension was just activated but its `dbDelta()` hasn't run yet —
	 * e.g., a brand-new Pro install on a site that has a stale opcode
	 * cache. Cheap (single `SHOW TABLES` lookup) and cached by MySQL.
	 */
	public static function table_exists(): bool {
		$found = static::db()->get_var(
			static::db()->prepare( 'SHOW TABLES LIKE %s', static::table() )
		);
		return ! empty( $found );
	}

	/**
	 * Compile a `$where` map into a ` WHERE …` fragment plus its bound values.
	 *
	 * ONE compiler, because `where()` and `count()` must speak exactly the same
	 * vocabulary and previously each carried its own copy of it. The cost of
	 * that is on the record: when `where()` learned the comparison suffixes and
	 * `count()` did not, a caller handing the same conditions array to both got
	 * the right five rows beside a total of 0, plus `WHERE \`id__lt\` = 590` in
	 * the error log on every request (Basecamp 10160235647). A second copy of a
	 * vocabulary is a drift waiting for its next release.
	 *
	 * Key shapes, all purely additive — a bare key is still an equality:
	 *
	 *   'status'      => 'active'          → `status` = %s
	 *   'id__lt'      => 590               → `id` < %d
	 *   'status__in'  => ['active','done'] → `status` IN (%s, %s)
	 *
	 * `__in` with an EMPTY array compiles to a never-matching clause, not to
	 * "no condition". "Restrict to nothing" and "do not restrict" are opposite
	 * instructions, and silently widening the first into the second is how a
	 * scope becomes a leak.
	 *
	 * @param array<string, mixed> $where Column => value map.
	 * @return array{0:string, 1:array<int, mixed>} [ ' WHERE …' or '', values ]
	 */
	protected static function compile_where( array $where ): array {
		if ( empty( $where ) ) {
			return [ '', [] ];
		}

		$clauses = [];
		$values  = [];

		foreach ( $where as $col => $val ) {
			// Set membership. Handled before the scalar suffixes because it
			// binds N placeholders rather than one.
			if ( preg_match( '/^(?<column>.+)__in$/', (string) $col, $m ) ) {
				$list = array_values( (array) $val );
				if ( empty( $list ) ) {
					$clauses[] = '1 = 0';
					continue;
				}
				$placeholders = [];
				foreach ( $list as $item ) {
					$placeholders[] = is_int( $item ) ? '%d' : '%s';
					$values[]       = $item;
				}
				$clauses[] = '`' . esc_sql( $m['column'] ) . '` IN ( ' . implode( ', ', $placeholders ) . ' )';
				continue;
			}

			// Optional comparison suffix, so a keyset cursor can be expressed
			// without every caller hand-rolling SQL.
			$op = '=';
			if ( preg_match( '/^(?<column>.+)__(?<op>lt|lte|gt|gte|not)$/', (string) $col, $m ) ) {
				$col = $m['column'];
				$op  = [
					'lt'  => '<',
					'lte' => '<=',
					'gt'  => '>',
					'gte' => '>=',
					'not' => '!=',
				][ $m['op'] ];
			}

			// The column is backticked and the operator comes from the closed
			// map above — neither is caller-controlled SQL.
			$clauses[] = '`' . esc_sql( (string) $col ) . "` {$op} " . ( is_int( $val ) ? '%d' : '%s' );
			$values[]  = $val;
		}

		return [ ' WHERE ' . implode( ' AND ', $clauses ), $values ];
	}

	public static function count( array $where = [] ): int {
		$sql = 'SELECT COUNT(*) FROM ' . static::table();

		[ $where_sql, $values ] = static::compile_where( $where );
		if ( '' !== $where_sql ) {
			$sql .= $where_sql;
			$sql  = static::db()->prepare( $sql, ...$values );
		}
		return (int) static::db()->get_var( $sql );
	}

	/**
	 * Cursor-based batch iterator for "export everything" use cases.
	 *
	 * Walks rows in chunks of $batch_size using a strictly-increasing `id`
	 * cursor, yielding rows lazily so callers stream a 1M-row table without
	 * holding it in memory. Skips OFFSET pagination (which degrades linearly
	 * after page ~10) and uses range-seek instead, which stays index-bound.
	 *
	 * Powers the CSV exports in Basecamp #9927990443 + #9927990887. Use this
	 * rather than ::where() with a high limit — the old `LIMIT 10000` cap
	 * was a silent truncation that lost data on real-sized accounting feeds.
	 *
	 * @param array $where      Optional simple equality conditions (same shape as ::where()).
	 * @param int   $batch_size Rows per chunk. Default 500 — enough to amortise the round-trip, low enough that one chunk + its hydration fits comfortably under 32 MB.
	 * @return \Generator Yields each row as it is fetched.
	 */
	public static function iterate_all( array $where = [], int $batch_size = 500 ): \Generator {
		$batch_size = max( 1, $batch_size );
		$last_id    = 0;
		while ( true ) {
			$sql    = 'SELECT * FROM ' . static::table() . ' WHERE id > %d';
			$values = array( $last_id );

			foreach ( $where as $col => $val ) {
				$placeholder = is_int( $val ) ? '%d' : '%s';
				$sql        .= " AND `{$col}` = {$placeholder}";
				$values[]    = $val;
			}

			$sql     .= ' ORDER BY id ASC LIMIT %d';
			$values[] = $batch_size;

			$rows = static::db()->get_results( static::db()->prepare( $sql, ...$values ) );
			if ( empty( $rows ) ) {
				return;
			}

			foreach ( $rows as $row ) {
				$last_id = (int) $row->id;
				yield $row;
			}

			if ( count( $rows ) < $batch_size ) {
				return;
			}
		}
	}

	/**
	 * Does this model's table carry an `id` column?
	 *
	 * Cached per table for the request: the junction tables key on composites
	 * and would fatal on an `ORDER BY id` tiebreak.
	 */
	protected static function table_has_id(): bool {
		static $cache = [];

		$table = static::table();
		if ( ! isset( $cache[ $table ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cache[ $table ] = (bool) static::db()->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'id'" );
		}

		return $cache[ $table ];
	}

	public static function where( array $where = [], string $orderby = 'id', string $order = 'DESC', int $limit = 0, int $offset = 0 ): array {
		$sql = 'SELECT * FROM ' . static::table();

		[ $where_sql, $values ] = static::compile_where( $where );
		$sql                   .= $where_sql;

		$allowed_order = in_array( strtoupper( $order ), [ 'ASC', 'DESC' ], true ) ? strtoupper( $order ) : 'DESC';
		$sql          .= ' ORDER BY `' . esc_sql( $orderby ) . '` ' . $allowed_order;

		// Stable tiebreak, so paging over this order is deterministic.
		//
		// ORDER BY a non-unique column leaves tied rows in whatever order the
		// engine feels like, and that order need not be the same between two
		// queries. Page 1 and page 2 then disagree about which rows they own:
		// some come back twice and others are never returned at all. Found by
		// tests/app-contract/pagination-contract.php on /admin/commissions,
		// which orders by created_at DESC over seeded rows sharing timestamps —
		// 34 unique rows walked out of 38, so 4 were duplicated and 4 were
		// unreachable by paging.
		//
		// Ordering by id after the caller's column cannot reorder rows that are
		// NOT tied, so no existing list changes shape; it only makes the ties
		// repeatable. Skipped when the caller already ordered by id, and when
		// the table has no id at all — the junction tables (tag maps, space
		// courses, course instructors) key on composites.
		if ( 'id' !== $orderby && static::table_has_id() ) {
			$sql .= ', `id` ' . $allowed_order;
		}

		// Scale C3: a read with NO explicit limit is capped at a high safety
		// ceiling so a prolific account can't load an entire growing table (every
		// row, every column incl. large content) into memory — the unbounded
		// `SELECT *` footgun. The cap is generous + filterable, so small lists
		// (categories, tags, plans) are unaffected; a result set that HITS the cap
		// is logged under WP_DEBUG, so a genuine over-cap read is never silently
		// truncated — it must paginate (pass an explicit $limit). Callers that
		// legitimately want "all" of a small, bounded set are within the cap.
		$capped  = $limit <= 0;
		$ceiling = $capped
			? max( 1, (int) apply_filters( 'learnomy_model_where_default_limit', 5000, static::table_name() ) )
			: $limit;
		$sql    .= ' LIMIT ' . absint( $ceiling ) . ' OFFSET ' . absint( $offset );

		if ( ! empty( $values ) ) {
			$sql = static::db()->prepare( $sql, ...$values );
		}

		$rows = static::db()->get_results( $sql ) ?: [];
		if ( $capped && count( $rows ) >= $ceiling && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'Learnomy scale: unbounded %s::where() hit the %d-row safety cap — paginate this read.', static::class, $ceiling ) );
		}
		return $rows;
	}
}
