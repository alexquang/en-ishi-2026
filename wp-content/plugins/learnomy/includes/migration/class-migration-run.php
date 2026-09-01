<?php
/**
 * Migration run record — checkpointed, resumable state for one import.
 *
 * The Id_Map (lrn_audit_log) records WHICH entities a run created (for parent
 * resolution + undo). This record tracks the RUN itself: selection spec, the
 * cursor (which entity + offset to process next), accumulated counters, and
 * status — so an Action-Scheduler-batched import can resume after each page and
 * the wizard/CLI can report live progress.
 *
 * Stored as a non-autoloaded option per run (no schema change); a small index
 * option lists recent run ids for the "runs" view.
 *
 * @package Learnomy
 */

namespace Learnomy\Migration;

use function Learnomy\now;

defined( 'ABSPATH' ) || exit;

/**
 * Checkpointed state for one migration run.
 */
class Migration_Run {

	private const PREFIX     = 'learnomy_migration_run_';
	private const INDEX_KEY  = 'learnomy_migration_runs';
	private const INDEX_KEEP = 50;

	/** Run id (also the Id_Map run tag). */
	public string $id = '';
	/** Source slug. */
	public string $source = '';
	/** Selection/mapping spec. */
	public array $spec = array();
	/** Selected entities in apply order. */
	public array $entities = array();
	/** Per-entity source totals (for progress). */
	public array $totals = array();
	/** Cursor: which entity index + offset to process next. */
	public array $cursor = array(
		'index'  => 0,
		'offset' => 0,
	);
	/** Accumulated counters: created/updated/skipped => entity => n. */
	public array $counters = array(
		'created' => array(),
		'updated' => array(),
		'skipped' => array(),
	);
	/** Unique warnings surfaced during the run. */
	public array $warnings = array();
	/** pending | running | done | failed. */
	public string $status = 'pending';
	/** Failure message when status = failed. */
	public string $error = '';
	/** Who triggered the run. */
	public int $triggered_by = 0;
	/** Timestamps. */
	public string $started_at  = '';
	public string $finished_at = '';

	/**
	 * Build the option key for a run id.
	 *
	 * @param string $id Run id.
	 * @return string
	 */
	private static function option_key( string $id ): string {
		return self::PREFIX . $id;
	}

	/**
	 * Hydrate from a stored array.
	 *
	 * @param array $data Stored state.
	 * @return self
	 */
	private static function from_array( array $data ): self {
		$run = new self();
		foreach ( get_object_vars( $run ) as $prop => $default ) {
			if ( isset( $data[ $prop ] ) ) {
				$run->$prop = $data[ $prop ];
			}
		}
		return $run;
	}

	/**
	 * Load a run by id.
	 *
	 * @param string $id Run id.
	 * @return self|null
	 */
	public static function load( string $id ): ?self {
		$data = get_option( self::option_key( $id ) );
		return is_array( $data ) ? self::from_array( $data ) : null;
	}

	/**
	 * Persist this run (no autoload — runs are read on demand, never every page).
	 *
	 * @return void
	 */
	public function save(): void {
		update_option( self::option_key( $this->id ), get_object_vars( $this ), false );
	}

	/**
	 * Delete this run's record + drop it from the index.
	 *
	 * @return void
	 */
	public function delete(): void {
		delete_option( self::option_key( $this->id ) );
		$index = (array) get_option( self::INDEX_KEY, array() );
		$index = array_values( array_diff( $index, array( $this->id ) ) );
		update_option( self::INDEX_KEY, $index, false );
	}

	/**
	 * Recent run summaries (most recent first), for the "runs" list.
	 *
	 * @param int $limit Max runs.
	 * @return array<int,array>
	 */
	public static function recent( int $limit = 20 ): array {
		$index = (array) get_option( self::INDEX_KEY, array() );
		$out   = array();
		foreach ( array_slice( $index, 0, $limit ) as $id ) {
			$run = self::load( (string) $id );
			if ( $run ) {
				$out[] = $run->summary();
			}
		}
		return $out;
	}

	/**
	 * Create + persist a new running record, and register it in the index.
	 *
	 * @param string $source   Source slug.
	 * @param array  $spec      Selection/mapping spec (must carry 'run').
	 * @param array  $entities Selected entities in apply order.
	 * @param array  $totals    Per-entity source totals.
	 * @return self
	 */
	public static function create( string $source, array $spec, array $entities, array $totals ): self {
		$run               = new self();
		$run->id           = (string) ( $spec['run'] ?? '' );
		$run->source       = $source;
		$run->spec         = $spec;
		$run->entities     = array_values( $entities );
		$run->totals       = $totals;
		$run->status       = 'running';
		$run->triggered_by = get_current_user_id();
		$run->started_at   = now();
		$run->save();

		$index = (array) get_option( self::INDEX_KEY, array() );
		array_unshift( $index, $run->id );
		update_option( self::INDEX_KEY, array_slice( array_unique( $index ), 0, self::INDEX_KEEP ), false );

		return $run;
	}

	/**
	 * Fold one page's report (from Import_Service::report()) into the counters.
	 *
	 * @param array $report Per-page report.
	 * @return void
	 */
	public function merge( array $report ): void {
		foreach ( array( 'created', 'updated', 'skipped' ) as $bucket ) {
			foreach ( (array) ( $report[ $bucket ] ?? array() ) as $entity => $n ) {
				$this->counters[ $bucket ][ $entity ] = ( $this->counters[ $bucket ][ $entity ] ?? 0 ) + (int) $n;
			}
		}
		foreach ( (array) ( $report['warnings'] ?? array() ) as $w ) {
			if ( ! in_array( $w, $this->warnings, true ) ) {
				$this->warnings[] = $w;
			}
		}
	}

	/**
	 * Advance the cursor after a page. A page that read rows moves the offset on;
	 * an empty page moves to the next entity. Marks the run done when the last
	 * entity is exhausted.
	 *
	 * @param int $rows_read Source rows read this page.
	 * @return void
	 */
	public function advance( int $rows_read ): void {
		if ( $rows_read > 0 ) {
			$this->cursor['offset'] += Import_Service::PAGE;
		} else {
			$this->cursor = array(
				'index'  => $this->cursor['index'] + 1,
				'offset' => 0,
			);
		}
		if ( $this->cursor['index'] >= count( $this->entities ) ) {
			$this->finish();
		}
	}

	/** The entity the cursor points at, or '' when complete. */
	public function current_entity(): string {
		return $this->entities[ $this->cursor['index'] ] ?? '';
	}

	/** Whether there is more work to do. */
	public function is_complete(): bool {
		return $this->cursor['index'] >= count( $this->entities );
	}

	/** Mark the run finished. */
	public function finish(): void {
		$this->status      = 'done';
		$this->finished_at = now();
	}

	/**
	 * Mark the run failed.
	 *
	 * @param string $message Failure reason.
	 * @return void
	 */
	public function fail( string $message ): void {
		$this->status      = 'failed';
		$this->error       = $message;
		$this->finished_at = now();
	}

	/**
	 * A progress snapshot for the UI/CLI.
	 *
	 * @return array
	 */
	public function summary(): array {
		$total     = array_sum( $this->totals );
		$processed = 0;
		foreach ( $this->counters as $bucket ) {
			$processed += array_sum( $bucket );
		}
		return array(
			'id'          => $this->id,
			'source'      => $this->source,
			'status'      => $this->status,
			'entities'    => $this->entities,
			'totals'      => $this->totals,
			'counters'    => $this->counters,
			'warnings'    => $this->warnings,
			// Repeated on the completion screen, not only on the dry-run. An owner
			// who clicked through the preview used to finish an import with no
			// reminder that certificates, quiz scores and orders stayed behind, and
			// read "Import complete" as "everything came across"
			// (Basecamp 10143404772). Same single source of copy as the preview.
			'not_migrated' => Import_Service::exclusions( (string) $this->source ),
			'error'       => $this->error,
			'current'     => $this->current_entity(),
			'total'       => $total,
			'processed'   => min( $processed, $total ),
			// A finished run is always 100%. processed (created+updated+skipped)
			// is legitimately < total whenever a source row is read but produces
			// no row — e.g. a progress entry whose lesson never mapped, or a
			// skipped/failed import. Gating the bar on processed/total left a
			// completed import stuck at ~95% (it never reached 100). Partial
			// imports are surfaced via counters + warnings, not a stalled bar.
			'percent'     => ( 'done' === $this->status ) ? 100 : ( $total > 0 ? (int) floor( min( $processed, $total ) / $total * 100 ) : 0 ),
			'started_at'  => $this->started_at,
			'finished_at' => $this->finished_at,
		);
	}
}
