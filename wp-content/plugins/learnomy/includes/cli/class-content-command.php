<?php
/**
 * `wp learnomy content` — content-model tooling for the HTML-first migration
 * (plan/archive/2026-06-26-content-model-html-first.md). Phase-0 deliverable: a
 * byte-exact backup/restore of every in-scope content field, so any later
 * migration is reversible. Round-trip proving lives in
 * bin/content-roundtrip.php (the Phase-1 gate).
 *
 * @package Learnomy\CLI
 */

namespace Learnomy\CLI;

defined( 'ABSPATH' ) || exit;

class Content_Command {

	/**
	 * Snapshot every in-scope content field to a JSON file (byte-exact).
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Where to write the snapshot. Default: uploads/learnomy-content-snapshot-<ts>.json
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy content snapshot
	 *     wp learnomy content snapshot --file=/tmp/before.json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args --file.
	 */
	public function snapshot( array $args, array $assoc_args ): void {
		$file = (string) ( $assoc_args['file'] ?? '' );
		if ( '' === $file ) {
			$uploads = wp_upload_dir();
			$file    = trailingslashit( $uploads['basedir'] ) . 'learnomy-content-snapshot-' . gmdate( 'Ymd-His' ) . '.json';
		}

		$rows = \Learnomy\Content\Content_Migrator::backup_to( $file );
		if ( $rows < 0 ) {
			\WP_CLI::error( "Could not write snapshot to {$file}" );
		}
		\WP_CLI::success( "Snapshotted {$rows} content rows → {$file}" );
	}

	/**
	 * Restore content fields from a snapshot file (byte-exact reverse of snapshot).
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : The snapshot JSON to restore from.
	 *
	 * [--dry-run]
	 * : Report what would change without writing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy content restore --file=/tmp/before.json
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args --file, --dry-run.
	 */
	public function restore( array $args, array $assoc_args ): void {
		global $wpdb;

		$file = (string) ( $assoc_args['file'] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			\WP_CLI::error( 'Provide a readable --file=<snapshot.json>.' );
		}
		$dry = isset( $assoc_args['dry-run'] );

		$payload = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		if ( ! is_array( $payload ) || empty( $payload['records'] ) ) {
			\WP_CLI::error( 'Snapshot file has no records.' );
		}

		$changed = 0;
		$same    = 0;
		foreach ( $payload['records'] as $rec ) {
			$full = $wpdb->prefix . (string) $rec['table'];
			$pk   = (string) $rec['pk'];
			$col  = (string) $rec['column'];
			$id   = (int) $rec['id'];
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT `{$col}` FROM `{$full}` WHERE `{$pk}` = %d", $id ) );
			// phpcs:enable
			if ( (string) $current === (string) $rec['value'] ) {
				++$same;
				continue;
			}
			++$changed;
			if ( ! $dry ) {
				$wpdb->update( $full, array( $col => (string) $rec['value'] ), array( $pk => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			}
		}

		\WP_CLI::success( sprintf( '%s%d rows %s, %d already matched (of %d).', $dry ? '[dry-run] ' : '', $changed, $dry ? 'would be restored' : 'restored', $same, count( $payload['records'] ) ) );
	}

	/**
	 * Migrate every in-scope content field from Editor.js JSON to canonical
	 * semantic HTML (the HTML-first cutover, Phase 7). Forward-only, lossless,
	 * idempotent. Reuses the proven Content_HTML engine. ALWAYS snapshot first
	 * (auto, unless --skip-snapshot) so the run is reversible via `content restore`.
	 *
	 * ## OPTIONS
	 *
	 * [--to-html]
	 * : Direction. Currently the only direction (kept explicit for clarity).
	 *
	 * [--dry-run]
	 * : Report per-field counts without writing.
	 *
	 * [--skip-snapshot]
	 * : Skip the automatic pre-migration snapshot (NOT recommended).
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy content migrate --to-html --dry-run
	 *     wp learnomy content migrate --to-html
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args --dry-run, --skip-snapshot.
	 */
	public function migrate( array $args, array $assoc_args ): void {
		$dry     = isset( $assoc_args['dry-run'] );
		$pending = \Learnomy\Content\Content_Migrator::pending_count();

		if ( $dry ) {
			\WP_CLI::success( sprintf( '[dry-run] %d content field(s) would convert to semantic HTML. Run without --dry-run to apply.', $pending ) );
			return;
		}

		if ( 0 === $pending ) {
			\WP_CLI::success( 'Nothing to migrate — all content is already semantic HTML.' );
			return;
		}

		if ( ! isset( $assoc_args['skip-snapshot'] ) ) {
			$this->snapshot( array(), array() ); // Auto-backup before any write.
		}

		// Drain in batches via the SAME engine the background job uses, so the
		// CLI and the async path can never diverge.
		$total    = 0;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Converting content', $pending );
		do {
			$result = \Learnomy\Content\Content_Migrator::convert_batch( \Learnomy\Content\Content_Migrator::BATCH_SIZE );
			$total += $result['converted'];
			$progress->tick( $result['converted'] );
		} while ( $result['converted'] > 0 && $result['remaining'] > 0 );
		$progress->finish();

		\WP_CLI::success( sprintf( '%d content field(s) converted to semantic HTML. Re-run to confirm 0 remaining; `wp learnomy content restore` to revert.', $total ) );
	}
}
