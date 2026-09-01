<?php
/**
 * The relation registry — one declaration per foreign reference, three uses.
 *
 * WHY THIS EXISTS
 *
 * Every entity in this plugin solved "what happens to the rows pointing at me
 * when I am deleted" separately, and to a different standard. Measured across
 * both plugins:
 *
 *   entity    tables declaring a reference   what its delete path cleaned
 *   user      30                             9 in User_Cleanup_Service + 3 listeners
 *   course    18                             ~6 inline + 6 listeners
 *   lesson    10                             NOTHING but its own row
 *   space      7                             7 — thorough
 *   plan       6                             —
 *   quiz       2                             3 + 2 listeners
 *   cohort     2                             1 + 0 listeners
 *
 * Nothing enforced consistency, so each new feature added a relation and
 * another chance to forget one. The forgetting is silent: no error, no failed
 * write, just rows that outlive their parent and surface much later as a path
 * advertising "This course is no longer available" on a page still taking
 * money, or a member's entitlement list quietly full of dead ids.
 *
 * THE CONTRACT
 *
 * A model declares what it points at, once:
 *
 *     protected static array $references = array(
 *         'course' => 'course_id',
 *         'user'   => array( 'column' => 'user_id', 'on_delete' => self::KEEP ),
 *     );
 *
 * That single declaration powers three things that used to be written by hand
 * and drift apart:
 *
 *   1. CASCADE       — Relations::cascade() cleans every declared reference.
 *   2. DETECTION     — the integrity check derives its orphan queries from here.
 *   3. REPAIR        — `wp learnomy repair data-integrity` reads the same list.
 *
 * The point is not the cascade. It is that a new table CANNOT be added without
 * declaring its relations, and once declared it is covered in all three places
 * without anyone remembering to wire it up.
 *
 * ON_DELETE POLICY
 *
 * DELETE is the default: a row whose parent is gone goes with it. KEEP exists
 * because some references are deliberately outlived by their rows, and a
 * blanket cascade would destroy intent that is already in the code — notably
 * `Course_Service::delete()`, which keeps certificates on purpose so deleting a
 * course VOIDS a credential rather than erasing the fact it was earned. Money
 * and audit rows are KEEP for the same reason: a sale that happened is a fact
 * about the past, and deleting a product should not rewrite the accounts.
 *
 * @package Learnomy\DB
 */

namespace Learnomy\DB;

defined( 'ABSPATH' ) || exit;

final class Relations {

	/** Delete the referencing row when the parent goes. The default. */
	public const DELETE = 'delete';

	/**
	 * Leave the referencing row in place.
	 *
	 * For rows that are a record of something that happened rather than a part
	 * of the parent: transactions, commissions, audit entries, issued
	 * certificates. The reference is left dangling ON PURPOSE, and such a table
	 * must therefore tolerate a missing parent when it reads.
	 */
	public const KEEP = 'keep';

	/**
	 * Keep the row, but blank the pointer.
	 *
	 * The third disposition, and the one that was missing. Some rows outlive
	 * their parent AND must not keep pointing at it: an enrollment survives the
	 * cohort being deleted (the student keeps the course) but the cohort stamp
	 * on it is now a lie, and anything reading it renders a cohort that is not
	 * there.
	 *
	 * This is not a new idea being introduced — it is one the code already had
	 * and could not say. Cohort_Service::delete() called
	 * `Enrollment::clear_cohort()` by hand for exactly this, which is the
	 * duplicated-rule pattern the registry exists to end: a rule spelled in one
	 * service that no other deletion path knows about.
	 *
	 * The column is written NULL when it is nullable and 0 when it is not,
	 * because the schema uses both to mean "none" (`enrollments.cohort_id` is
	 * `DEFAULT NULL`; `enrollments.path_id` is `NOT NULL DEFAULT 0`). Orphan
	 * detection ignores both, so a CLEARed row stops being reported the moment
	 * it is cleared.
	 */
	public const CLEAR = 'clear';

	/**
	 * entity => array of array{ table, column, on_delete, model }
	 *
	 * @var array<string, array<int, array<string, string>>>
	 */
	private static array $map = array();

	/** @var bool Whether models have been asked to declare yet. */
	private static bool $booted = false;

	/**
	 * Parent table for each entity name, resolved lazily.
	 *
	 * Pro contributes its own through `learnomy_relation_parents`, so Free
	 * never names a `lrn_pro_*` table (Rule 9) while still being able to detect
	 * an orphan in one.
	 *
	 * @return array<string, string> entity => fully-prefixed table name.
	 */
	public static function parents(): array {
		$parents = array(
			'course'      => \Learnomy\table( 'courses' ),
			'lesson'      => \Learnomy\table( 'lessons' ),
			'quiz'        => \Learnomy\table( 'quizzes' ),
			'question'    => \Learnomy\table( 'questions' ),
			'plan'        => \Learnomy\table( 'membership_plans' ),
			'coupon'      => \Learnomy\table( 'coupons' ),
			'transaction' => \Learnomy\table( 'transactions' ),
			'enrollment'  => \Learnomy\table( 'enrollments' ),
			'certificate' => \Learnomy\table( 'certificates' ),
		);

		/**
		 * Filters the entity => parent table map.
		 *
		 * Pro registers its own entities (space, cohort, path) here so their
		 * relations are covered by the same cascade, detection and repair as
		 * Free's without Free referencing a Pro table.
		 *
		 * @param array<string,string> $parents entity => table name.
		 */
		return (array) apply_filters( 'learnomy_relation_parents', $parents );
	}

	/**
	 * Declare that a table references an entity.
	 *
	 * Called for you by {@see self::boot()} from each Model's `$references`.
	 * Call it directly only for a table with no Model of its own.
	 *
	 * @param string $entity    Entity name, e.g. 'course'.
	 * @param string $table     Fully-prefixed table holding the reference.
	 * @param string $column    Column holding the parent id.
	 * @param string $on_delete self::DELETE (default), self::KEEP or self::CLEAR.
	 * @param string $model     Optional model class, used to route deletes
	 *                          through the model so its cache is invalidated.
	 */
	public static function register( string $entity, string $table, string $column, string $on_delete = self::DELETE, string $model = '' ): void {
		if ( '' === $entity || '' === $table || '' === $column ) {
			return;
		}

		foreach ( self::$map[ $entity ] ?? array() as $existing ) {
			if ( $existing['table'] === $table && $existing['column'] === $column ) {
				return; // Already declared — registration is idempotent.
			}
		}

		// An unrecognised disposition falls back to KEEP, not DELETE. The
		// previous form coerced everything that was not KEEP into DELETE, so a
		// typo in a declaration — or a constant this class did not know about
		// yet, which is exactly what CLEAR was — quietly became "delete these
		// rows". A wrong guess in that direction destroys customer data; a
		// wrong guess towards KEEP leaves a row the repair pass can still
		// report. Fail towards the recoverable mistake.
		$valid = array( self::DELETE, self::KEEP, self::CLEAR );

		self::$map[ $entity ][] = array(
			'table'     => $table,
			'column'    => $column,
			'on_delete' => in_array( $on_delete, $valid, true ) ? $on_delete : self::KEEP,
			'model'     => $model,
		);
	}

	/**
	 * Ask every Model to declare its references.
	 *
	 * Runs once. Models are discovered through the `learnomy_relation_models`
	 * filter rather than by scanning the filesystem, so Pro's models join the
	 * same registry and the set is inspectable in tests.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		/**
		 * Filters the model classes asked to declare their relations.
		 *
		 * @param string[] $models Fully-qualified model class names.
		 */
		$models = (array) apply_filters( 'learnomy_relation_models', array() );

		foreach ( $models as $model ) {
			if ( ! is_string( $model ) || ! class_exists( $model ) || ! is_subclass_of( $model, '\Learnomy\Models\Model' ) ) {
				continue;
			}

			foreach ( $model::relation_declarations() as $entity => $spec ) {
				$column    = is_array( $spec ) ? (string) ( $spec['column'] ?? '' ) : (string) $spec;
				$on_delete = is_array( $spec ) ? (string) ( $spec['on_delete'] ?? self::DELETE ) : self::DELETE;

				self::register( (string) $entity, $model::relation_table(), $column, $on_delete, $model );
			}
		}
	}

	/**
	 * Every declared reference to an entity.
	 *
	 * @param string $entity Entity name.
	 * @return array<int, array<string, string>>
	 */
	public static function for_entity( string $entity ): array {
		self::boot();
		return self::$map[ $entity ] ?? array();
	}

	/** All declared relations, keyed by entity. */
	public static function all(): array {
		self::boot();
		return self::$map;
	}

	/**
	 * Settle every row pointing at a now-deleted parent.
	 *
	 * Call AFTER the parent row is gone, from that entity's delete path. Each
	 * relation is settled per its declaration: DELETE removes the row, CLEAR
	 * keeps it and blanks the pointer, KEEP leaves it untouched by design.
	 *
	 * Routed through the owning Model's `delete_where()` when one is known, so
	 * model caches invalidate; falls back to a direct scoped delete for tables
	 * with no model.
	 *
	 * @param string $entity Entity name, e.g. 'course'.
	 * @param int    $id     The id that has just been deleted.
	 * @return int Rows DELETED. Cleared rows are counted separately and passed
	 *             to `learnomy_relations_cascaded`, because a caller reporting
	 *             "12 rows removed" must not be counting rows that are still
	 *             there.
	 */
	public static function cascade( string $entity, int $id ): int {
		if ( $id <= 0 ) {
			return 0;
		}

		global $wpdb;
		$removed = 0;
		$cleared = 0;

		foreach ( self::for_entity( $entity ) as $relation ) {
			if ( self::KEEP === $relation['on_delete'] ) {
				continue;
			}

			$is_clear = self::CLEAR === $relation['on_delete'];

			if ( $is_clear ) {
				$rows = self::clear_column( $relation['table'], $relation['column'], $id );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->delete( $relation['table'], array( $relation['column'] => $id ), array( '%d' ) );
			}

			if ( $rows ) {
				if ( $is_clear ) {
					$cleared += (int) $rows;
				} else {
					$removed += (int) $rows;
				}

				// is_callable(), not method_exists(). Several models declare
				// invalidate_cache() as PROTECTED, which method_exists() reports
				// as present and call_user_func() then refuses — throwing out of
				// the middle of a delete and leaving the parent gone with its
				// remaining relations uncleaned. is_callable() answers the
				// question actually being asked: may I call this from here.
				//
				// A stale cache is a cosmetic problem; a half-finished cascade is
				// not. If a model keeps its invalidation private, the rows are
				// still deleted and its cache expires on its own.
				if ( '' !== $relation['model'] && is_callable( array( $relation['model'], 'invalidate_cache' ) ) ) {
					call_user_func( array( $relation['model'], 'invalidate_cache' ) );
				}
			}
		}

		/**
		 * Fires after an entity's declared relations have been cleaned.
		 *
		 * @param string $entity  Entity name.
		 * @param int    $id      Deleted id.
		 * @param int    $removed Rows deleted.
		 * @param int    $cleared Rows kept with the pointer blanked.
		 */
		do_action( 'learnomy_relations_cascaded', $entity, $id, $removed, $cleared );

		return $removed;
	}

	/**
	 * Does this column accept NULL?
	 *
	 * One answer, shared by the cascade and the repair pass, so the two can
	 * never write a different "none" into the same column. Memoised per
	 * request.
	 *
	 * @param string $table  Fully-prefixed table.
	 * @param string $column Column to inspect.
	 */
	private static function is_nullable( string $table, string $column ): bool {
		global $wpdb;

		static $cache = array();
		$key          = $table . '.' . $column;

		if ( ! isset( $cache[ $key ] ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row           = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- `Null` is MySQL's own SHOW COLUMNS field name, not ours to rename.
			$cache[ $key ] = $row && 'YES' === $row->Null;
		}

		return $cache[ $key ];
	}

	/**
	 * Blank a pointer column without touching the rest of the row.
	 *
	 * Writes NULL when the column allows it and 0 when it does not. The schema
	 * uses both spellings for "none" — `lrn_enrollments.cohort_id` is
	 * `DEFAULT NULL`, `lrn_enrollments.path_id` is `NOT NULL DEFAULT 0` — so a
	 * single hardcoded value would either fail under strict mode or write a 0
	 * that reads as a real id elsewhere. Asked once per column per request; a
	 * delete is rare enough that the lookup does not matter.
	 *
	 * @param string $table  Fully-prefixed table.
	 * @param string $column Pointer column.
	 * @param int    $id     Parent id being cleared.
	 * @return int Rows changed.
	 */
	private static function clear_column( string $table, string $column, int $id ): int {
		global $wpdb;

		$blank = self::is_nullable( $table, $column ) ? 'NULL' : '0';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$column} = {$blank} WHERE {$column} = %d", $id ) );

		return false === $ok ? 0 : (int) $wpdb->rows_affected;
	}

	/**
	 * Count rows whose parent no longer exists, per relation.
	 *
	 * Skips KEEP relations: a dangling reference there is the intended state,
	 * not an orphan, and reporting it as one would train owners to ignore the
	 * check.
	 *
	 * @return array<int, array{table:string,column:string,entity:string,count:int}>
	 */
	public static function orphans(): array {
		global $wpdb;

		$parents = self::parents();
		$out     = array();

		foreach ( self::all() as $entity => $relations ) {
			if ( ! isset( $parents[ $entity ] ) ) {
				continue;
			}

			$parent_table = $parents[ $entity ];

			// A parent table that does not exist means its plugin is inactive;
			// every child row would look orphaned, which is wrong and would
			// invite a destructive repair on perfectly good data.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $parent_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $parent_table ) ) ) {
				continue;
			}

			foreach ( $relations as $relation ) {
				if ( self::KEEP === $relation['on_delete'] ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$count = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$relation['table']} c
					 LEFT JOIN {$parent_table} p ON p.id = c.{$relation['column']}
					 WHERE c.{$relation['column']} > 0 AND p.id IS NULL"
				);

				if ( $count > 0 ) {
					$out[] = array(
						'table'     => $relation['table'],
						'column'    => $relation['column'],
						'entity'    => $entity,
						'count'     => $count,
						// Carried so the repair pass settles each one the way it
						// was declared. Without it, purge_orphans() deleted every
						// reported row — which for a CLEAR relation means deleting
						// the enrollments of students whose cohort was removed.
						'on_delete' => $relation['on_delete'],
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Delete every orphaned row the registry knows about.
	 *
	 * @return int Rows removed.
	 */
	public static function purge_orphans(): int {
		global $wpdb;

		$parents = self::parents();
		$removed = 0;

		foreach ( self::orphans() as $orphan ) {
			$parent_table = $parents[ $orphan['entity'] ] ?? '';
			if ( '' === $parent_table ) {
				continue;
			}

			// Settle it the way it was declared. A CLEAR relation's rows are
			// legitimate rows with a stale pointer — blanking that pointer is
			// the repair; deleting the row is data loss wearing a repair's name.
			if ( self::CLEAR === ( $orphan['on_delete'] ?? self::DELETE ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					"UPDATE {$orphan['table']} c
					 LEFT JOIN {$parent_table} p ON p.id = c.{$orphan['column']}
					 SET c.{$orphan['column']} = " . ( self::is_nullable( $orphan['table'], $orphan['column'] ) ? 'NULL' : '0' ) . "
					 WHERE c.{$orphan['column']} > 0 AND p.id IS NULL"
				);
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->query(
				"DELETE c FROM {$orphan['table']} c
				 LEFT JOIN {$parent_table} p ON p.id = c.{$orphan['column']}
				 WHERE c.{$orphan['column']} > 0 AND p.id IS NULL"
			);

			$removed += (int) $rows;
		}

		return $removed;
	}

	/** Test seam — forget every declaration. */
	public static function reset(): void {
		self::$map    = array();
		self::$booted = false;
	}
}
