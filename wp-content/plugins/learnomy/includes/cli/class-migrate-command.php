<?php
/**
 * `wp learnomy migrate` — import courses from another LMS into Learnomy.
 *
 * Reads the source (LearnDash / Tutor / LifterLMS) and populates Learnomy through
 * its own services/models. Never writes to the source; never touches money.
 *
 * @package Learnomy
 */

namespace Learnomy\CLI;

use Learnomy\Migration\Id_Map;
use Learnomy\Migration\Import_Service;
use Learnomy\Migration\Migration_Run;
use Learnomy\Migration\Source_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI command: import courses from another LMS into Learnomy.
 */
class Migrate_Command {

	/**
	 * Preview an import (dry-run): counts + warnings, no writes.
	 *
	 * ## OPTIONS
	 * <source>
	 * : Source slug (learndash, tutor, lifterlms).
	 *
	 * [--entities=<csv>]
	 * : Comma list to limit entities (default: all the source supports).
	 *
	 * [--map=<file>]
	 * : Path to a JSON selection/mapping spec.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Associative arguments (flags).
	 * @return void
	 */
	public function preview( array $args, array $assoc ): void {
		$spec    = $this->build_spec( $args, $assoc );
		$service = $this->service( $args[0] ?? '', $spec );
		$report  = $service->preview();

		\WP_CLI::log( 'Dry-run for source: ' . $report['source'] );
		foreach ( $report['entities'] as $entity => $n ) {
			\WP_CLI::log( sprintf( '  %-12s %d', $entity, $n ) );
		}
		foreach ( $report['warnings'] as $w ) {
			\WP_CLI::warning( $w );
		}
		if ( ! empty( $report['not_migrated'] ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Not migrated (by design):' );
			foreach ( (array) $report['not_migrated'] as $note ) {
				\WP_CLI::log( '  - ' . $note );
			}
		}
		\WP_CLI::success( 'Preview complete. No data written.' );
	}

	/**
	 * Run an import.
	 *
	 * ## OPTIONS
	 * <source>
	 * : Source slug (learndash, tutor, lifterlms).
	 *
	 * [--entities=<csv>]
	 * : Comma list to limit entities.
	 *
	 * [--map=<file>]
	 * : Path to a JSON selection/mapping spec.
	 *
	 * [--dry-run]
	 * : Preview only (same as the preview subcommand).
	 *
	 * [--async]
	 * : Queue the import on Action Scheduler (batched + resumable) instead of
	 * running it inline. Returns immediately with a run id to poll.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Associative arguments (flags).
	 * @return void
	 */
	public function run( array $args, array $assoc ): void {
		$spec    = $this->build_spec( $args, $assoc );
		$service = $this->service( $args[0] ?? '', $spec );

		if ( isset( $assoc['dry-run'] ) ) {
			$this->preview( $args, $assoc );
			return;
		}

		if ( isset( $assoc['async'] ) ) {
			$source = Source_Registry::get( $args[0] ?? '' );
			$run    = Import_Service::start_async( $source, $spec );
			\WP_CLI::success(
				sprintf(
					'Queued run %s (%d items across %s). Poll: wp learnomy migrate status %s',
					$run->id,
					array_sum( $run->totals ),
					implode( ', ', $run->entities ),
					$run->id
				)
			);
			return;
		}

		\WP_CLI::log( 'Importing from ' . ( $args[0] ?? '' ) . ' (run ' . $spec['run'] . ') …' );
		$report = $service->run();

		foreach ( array( 'created', 'updated', 'skipped' ) as $bucket ) {
			foreach ( (array) ( $report[ $bucket ] ?? array() ) as $entity => $n ) {
				\WP_CLI::log( sprintf( '  %-8s %-12s %d', $bucket, $entity, $n ) );
			}
		}
		foreach ( (array) ( $report['warnings'] ?? array() ) as $w ) {
			\WP_CLI::warning( $w );
		}
		\WP_CLI::success( 'Import complete. Run id: ' . $spec['run'] . ' (undo with: wp learnomy migrate undo ' . $spec['run'] . ')' );
	}

	/**
	 * Undo a previous run (delete exactly what it created).
	 *
	 * ## OPTIONS
	 * <run-id>
	 * : The run id printed when the import finished.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function undo( array $args ): void {
		$run = $args[0] ?? '';
		if ( '' === $run ) {
			\WP_CLI::error( 'A run id is required.' );
		}
		$result = Import_Service::undo_run( $run );
		if ( 0 === $result['deleted'] && ! $result['found'] ) {
			\WP_CLI::error( 'No entities found for run ' . $run );
		}
		\WP_CLI::success( sprintf( 'Undid run %s: deleted %d entities (children + tag links cleared); purged %d id-map rows.', $run, $result['deleted'], $result['map_rows'] ) );
	}

	/**
	 * Show the progress of a run (for the async/queued path).
	 *
	 * ## OPTIONS
	 * <run-id>
	 * : The run id printed when the import was queued.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function status( array $args ): void {
		$run = Migration_Run::load( $args[0] ?? '' );
		if ( ! $run ) {
			\WP_CLI::error( 'No run record for ' . ( $args[0] ?? '' ) );
		}
		$s = $run->summary();
		\WP_CLI::log( sprintf( 'Run %s [%s] %d%% — %d/%d processed', $s['id'], $s['status'], $s['percent'], $s['processed'], $s['total'] ) );
		foreach ( array( 'created', 'updated', 'skipped' ) as $bucket ) {
			foreach ( (array) $s['counters'][ $bucket ] as $entity => $n ) {
				\WP_CLI::log( sprintf( '  %-8s %-12s %d', $bucket, $entity, $n ) );
			}
		}
		foreach ( $s['warnings'] as $w ) {
			\WP_CLI::warning( $w );
		}
		if ( 'failed' === $s['status'] ) {
			\WP_CLI::error( $s['error'], false );
		}
	}

	/**
	 * List recent migration runs.
	 *
	 * @return void
	 */
	public function runs(): void {
		$rows = \Learnomy\Models\AuditLog::where( array( 'type' => 'migration' ), 'id', 'DESC', 500 );
		$runs = array();
		foreach ( $rows as $row ) {
			$meta = json_decode( (string) ( $row->metadata_json ?? '' ), true );
			$run  = is_array( $meta ) ? ( $meta['run'] ?? '' ) : '';
			if ( $run ) {
				$runs[ $run ] = ( $runs[ $run ] ?? 0 ) + 1;
			}
		}
		if ( empty( $runs ) ) {
			\WP_CLI::log( 'No migration runs found.' );
			return;
		}
		foreach ( $runs as $run => $n ) {
			\WP_CLI::log( sprintf( '  %-30s %d entities', $run, $n ) );
		}
	}

	/**
	 * Resolve a source slug to a ready Import_Service, or bail with an error.
	 *
	 * @param string $slug Source slug.
	 * @param array  $spec The run's selection/mapping spec.
	 * @return Import_Service
	 */
	private function service( string $slug, array $spec ): Import_Service {
		$source = Source_Registry::get( $slug );
		if ( ! $source ) {
			\WP_CLI::error( 'Unknown source: ' . $slug . '. Available: ' . implode( ', ', array_keys( Source_Registry::all() ) ) );
		}
		if ( ! $source->is_available() ) {
			\WP_CLI::error( $source->label() . ' is not available on this site (plugin missing or no data).' );
		}
		return new Import_Service( $source, $spec );
	}

	/**
	 * Build the run spec from CLI args + an optional JSON map file.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Associative arguments (flags).
	 * @return array
	 */
	private function build_spec( array $args, array $assoc ): array {
		$overrides = array();
		if ( ! empty( $assoc['entities'] ) ) {
			$overrides['entities'] = array_map( 'trim', explode( ',', (string) $assoc['entities'] ) );
		}
		if ( ! empty( $assoc['map'] ) && is_readable( (string) $assoc['map'] ) ) {
			$json = json_decode( (string) file_get_contents( (string) $assoc['map'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_array( $json ) ) {
				$overrides = array_merge( $overrides, $json );
			}
		}
		// Import_Service::build_spec is the single source of truth (run id +
		// defaults + fallback merge), shared with the wizard REST controller.
		return Import_Service::build_spec( $args[0] ?? '', $overrides );
	}
}
