<?php
/**
 * Migration id-map: (source, entity, source_id) -> Learnomy id.
 *
 * Learnomy tables carry no external-id column, so the map lives in lrn_audit_log
 * (no schema change). Each created entity writes one row:
 *   type='migration', action='<source>:<entity>:<ext_id>', object_type=<entity>,
 *   object_id=<learnomy_id>, metadata={source, ext_id, run, entity}.
 *
 * This powers parent resolution (course_ext_id -> learnomy course_id), idempotent
 * re-runs (resolve before create -> update instead of duplicate), and undo
 * (delete exactly what a run created).
 *
 * @package Learnomy
 */

namespace Learnomy\Migration;

use Learnomy\Models\AuditLog;

defined( 'ABSPATH' ) || exit;

/**
 * Maps (source, entity, source_id) tuples to Learnomy ids via lrn_audit_log.
 */
class Id_Map {

	/**
	 * In-process cache: "source:entity:ext" => learnomy_id.
	 *
	 * @var array<string,int|null>
	 */
	private array $cache = array();

	/**
	 * The current run id.
	 *
	 * @var string
	 */
	private string $run;

	/**
	 * Constructor.
	 *
	 * @param string $run The run id this map belongs to.
	 */
	public function __construct( string $run ) {
		$this->run = $run;
	}

	/**
	 * Build the cache/audit key for a tuple.
	 *
	 * @param string $source Source slug.
	 * @param string $entity Entity key.
	 * @param mixed  $ext_id Source-side id.
	 * @return string
	 */
	private static function key( string $source, string $entity, $ext_id ): string {
		return $source . ':' . $entity . ':' . (string) $ext_id;
	}

	/**
	 * Record a freshly created/updated entity.
	 *
	 * @param string $source      Source slug.
	 * @param string $entity      Entity key.
	 * @param mixed  $ext_id      Source-side id.
	 * @param int    $learnomy_id The created Learnomy id.
	 * @return void
	 */
	public function remember( string $source, string $entity, $ext_id, int $learnomy_id ): void {
		$key                 = self::key( $source, $entity, $ext_id );
		$this->cache[ $key ] = $learnomy_id;

		AuditLog::log(
			get_current_user_id(),
			'migration',
			$key,
			$entity,
			$learnomy_id,
			array(
				'source' => $source,
				'entity' => $entity,
				'ext_id' => (string) $ext_id,
				'run'    => $this->run,
			)
		);
	}

	/**
	 * Resolve a source id to its Learnomy id, or null if not yet imported.
	 *
	 * @param string $source Source slug.
	 * @param string $entity Entity key.
	 * @param mixed  $ext_id Source-side id.
	 * @return int|null
	 */
	public function resolve( string $source, string $entity, $ext_id ): ?int {
		$key = self::key( $source, $entity, $ext_id );

		if ( array_key_exists( $key, $this->cache ) ) {
			return $this->cache[ $key ];
		}

		$rows = AuditLog::where(
			array(
				'type'   => 'migration',
				'action' => $key,
			),
			'id',
			'DESC',
			1
		);

		$id                  = empty( $rows ) ? null : (int) $rows[0]->object_id;
		$this->cache[ $key ] = $id;

		return $id;
	}

	/**
	 * Every (entity, learnomy_id) created by a given run, newest first — for undo.
	 *
	 * @param string $run The run id to list entries for.
	 * @return array<int,object> audit rows with ->object_type and ->object_id.
	 */
	public static function entries_for_run( string $run ): array {
		$rows = AuditLog::where( array( 'type' => 'migration' ), 'id', 'DESC', 0 );

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $run ): bool {
					$meta = json_decode( (string) ( $row->metadata_json ?? '' ), true );
					return is_array( $meta ) && ( $meta['run'] ?? '' ) === $run;
				}
			)
		);
	}

	/**
	 * All (source ext_id => Learnomy id) mappings for one entity in THIS run.
	 *
	 * Powers the prerequisite finalize pass: course prerequisites are forward
	 * references (course A may require course B that imports later), so they can
	 * only be resolved once every course is mapped.
	 *
	 * @param string $source Source slug.
	 * @param string $entity Entity key (e.g. 'course').
	 * @return array<string,int> ext_id => Learnomy id.
	 */
	public function mappings_for_entity( string $source, string $entity ): array {
		$out = array();
		foreach ( self::entries_for_run( $this->run ) as $row ) {
			$meta = json_decode( (string) ( $row->metadata_json ?? '' ), true );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			if ( ( $meta['source'] ?? '' ) === $source && ( $meta['entity'] ?? '' ) === $entity ) {
				$out[ (string) ( $meta['ext_id'] ?? '' ) ] = (int) $row->object_id;
			}
		}
		return $out;
	}

	/**
	 * Delete a run's id-map rows from lrn_audit_log. Undo MUST call this after
	 * deleting the entities, otherwise the map keeps resolving source ids to
	 * now-deleted Learnomy ids — a re-import would skip everything (idempotency
	 * resolver still finds them) and any new child would attach to a dead parent.
	 *
	 * @param string $run The run id to purge.
	 * @return int Rows deleted.
	 */
	public static function delete_run( string $run ): int {
		$deleted = 0;
		foreach ( self::entries_for_run( $run ) as $row ) {
			if ( AuditLog::delete( (int) $row->id ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}
}
