<?php
/**
 * Content_Migrator — bulk JSON→HTML content migration (Phase 7).
 *
 * The shared conversion engine behind BOTH `wp learnomy content migrate` (CLI)
 * and the background Action Scheduler job, so there is one converter. Forward-
 * only, lossless (reuses Content_HTML::for_storage), idempotent (already-HTML
 * rows are skipped), and batched so a large site never converts in one request.
 *
 * The background job runs when the cutover is active
 * (Content_HTML::storage_enabled() — ON by default, the
 * `learnomy_store_content_as_html` filter is the kill-switch). It writes a
 * one-time auto-backup, then migrates existing content in chained batches while
 * new edits convert lazily on save — so the site migrates hands-off. Reads stay
 * dual-format (INV-8); a site that filters the cutover off keeps storing JSON
 * and is never auto-migrated.
 *
 * @package Learnomy\Content
 */

namespace Learnomy\Content;

use Learnomy\Services\Protected_Uploads;

defined( 'ABSPATH' ) || exit;

class Content_Migrator {

	/** Action Scheduler hook for one background batch. */
	public const BATCH_HOOK = 'learnomy_migrate_content_batch';

	/** Rows converted per background batch (kept small for shared hosting). */
	public const BATCH_SIZE = 50;

	/** AS group so the jobs are findable/cancellable. */
	private const GROUP = 'learnomy-content';

	/** Option flag: the one-time pre-migration backup has been written. */
	private const BACKUP_DONE_OPTION = 'learnomy_content_premigration_backup';

	/**
	 * Every in-scope content field for a byte-exact backup:
	 * [ table-suffix, pk, column ]. settings_json captured whole (carries the
	 * nested welcome/completion messages). Shared by the CLI snapshot command.
	 *
	 * @return array<int, array{0:string,1:string,2:string}>
	 */
	public static function snapshot_fields(): array {
		global $wpdb;
		$fields = array(
			array( 'lrn_lessons', 'id', 'content' ),
			array( 'lrn_courses', 'id', 'description' ),
			array( 'lrn_courses', 'id', 'settings_json' ),
			array( 'lrn_quizzes', 'id', 'description' ),
		);
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'lrn_pro_lesson_revisions' ) ) ) {
			$fields[] = array( 'lrn_pro_lesson_revisions', 'id', 'blocks_json' );
		}
		return $fields;
	}

	/**
	 * Write a byte-exact snapshot of every in-scope content field to $file.
	 * The reversible-backup primitive used by BOTH `wp learnomy content snapshot`
	 * and the automatic pre-migration backup.
	 *
	 * @param string $file Destination path.
	 * @return int Rows written (-1 if the file could not be written).
	 */
	public static function backup_to( string $file ): int {
		global $wpdb;
		$records = array();
		foreach ( self::snapshot_fields() as list( $table, $pk, $col ) ) {
			$full = $wpdb->prefix . $table;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results( "SELECT `{$pk}` AS pk, `{$col}` AS val FROM `{$full}` WHERE `{$col}` IS NOT NULL AND `{$col}` <> ''" );
			// phpcs:enable
			foreach ( (array) $results as $r ) {
				$records[] = array(
					'table'  => $table,
					'pk'     => $pk,
					'id'     => (int) $r->pk,
					'column' => $col,
					'value'  => (string) $r->val,
				);
			}
		}
		$payload = array(
			'generated' => gmdate( 'c' ),
			'db_prefix' => $wpdb->prefix,
			'count'     => count( $records ),
			'records'   => $records,
		);
		$ok      = file_put_contents( $file, wp_json_encode( $payload ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false === $ok ? -1 : count( $records );
	}

	/**
	 * Direct content columns: [ table-suffix, pk, column, plain-projection-column ].
	 * The 4th element is the tag-stripped FULLTEXT projection to regenerate
	 * alongside the conversion ('' when the table has none).
	 *
	 * @return array<int, array{0:string,1:string,2:string,3:string}>
	 */
	private static function direct_fields(): array {
		global $wpdb;
		$fields = array(
			array( 'lrn_lessons', 'id', 'content', 'content_plain' ),
			array( 'lrn_courses', 'id', 'description', 'description_plain' ),
			array( 'lrn_quizzes', 'id', 'description', '' ),
		);
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'lrn_pro_lesson_revisions' ) ) ) {
			$fields[] = array( 'lrn_pro_lesson_revisions', 'id', 'blocks_json', '' );
		}
		return $fields;
	}

	/**
	 * How many content values are still stored as Editor.js JSON (need
	 * conversion). Bounded COUNT(*) per field + the nested settings messages.
	 */
	public static function pending_count(): int {
		global $wpdb;
		$pending = 0;
		foreach ( self::direct_fields() as list( $table, $pk, $col ) ) {
			$full = $wpdb->prefix . $table;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pending += (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full}` WHERE `{$col}` LIKE '{%\"blocks\"%'" );
			// phpcs:enable
		}
		$pending += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lrn_courses WHERE settings_json LIKE '%\"blocks\"%'" ); // phpcs:ignore WordPress.DB
		return $pending;
	}

	/**
	 * Convert up to $limit JSON content values to canonical HTML.
	 *
	 * @param int $limit Max rows to convert this call.
	 * @return array{converted:int, remaining:int}
	 */
	public static function convert_batch( int $limit ): array {
		global $wpdb;

		$converted = 0;
		$budget    = max( 1, $limit );

		foreach ( self::direct_fields() as list( $table, $pk, $col, $plain_col ) ) {
			if ( $budget <= 0 ) {
				break;
			}
			$full = $wpdb->prefix . $table;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `{$pk}` AS pk, `{$col}` AS val FROM `{$full}` WHERE `{$col}` LIKE %s LIMIT %d", '{%"blocks"%', $budget ) );
			// phpcs:enable
			foreach ( (array) $rows as $r ) {
				if ( ! Content_HTML::is_blocks_json( (string) $r->val ) ) {
					continue;
				}
				$html = Content_HTML::for_storage( (string) $r->val );
				$data = array( $col => $html );
				if ( '' !== $plain_col ) {
					// Same derivation the models apply on every write — this path
					// writes raw (it converts in place, by table name, without a
					// model), so it calls the shared helper directly rather than
					// keeping a second copy of the rule.
					$data[ $plain_col ] = \Learnomy\search_text_projection( $html );
				}
				$wpdb->update( $full, $data, array( $pk => (int) $r->pk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				++$converted;
				--$budget;
			}
		}

		// Nested course welcome/completion messages, within the remaining budget.
		if ( $budget > 0 ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$courses = $wpdb->get_results( $wpdb->prepare( "SELECT id, settings_json FROM {$wpdb->prefix}lrn_courses WHERE settings_json LIKE %s LIMIT %d", '%"blocks"%', $budget ) );
			// phpcs:enable
			foreach ( (array) $courses as $course ) {
				$settings = json_decode( (string) $course->settings_json, true );
				if ( ! is_array( $settings ) ) {
					continue;
				}
				$dirty = false;
				foreach ( array( 'welcome_message', 'completion_message' ) as $key ) {
					if ( isset( $settings[ $key ] ) && Content_HTML::is_blocks_json( (string) $settings[ $key ] ) ) {
						$settings[ $key ] = Content_HTML::for_storage( (string) $settings[ $key ] );
						$dirty            = true;
					}
				}
				if ( $dirty ) {
					$wpdb->update( $wpdb->prefix . 'lrn_courses', array( 'settings_json' => wp_json_encode( $settings ) ), array( 'id' => (int) $course->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					++$converted;
				}
			}
		}

		return array(
			'converted' => $converted,
			'remaining' => self::pending_count(),
		);
	}

	// ── Background job ───────────────────────────────────────────────────

	/**
	 * Wire the background migrator. Cheap: just binds the batch handler and a
	 * lazy scheduler on init.
	 */
	public static function register(): void {
		add_action( self::BATCH_HOOK, array( self::class, 'run_background_batch' ) );
		// AS sets up its store on init@1; schedule after that.
		add_action( 'init', array( self::class, 'maybe_schedule' ), 25 );
	}

	/**
	 * Schedule the first batch IF the cutover is opted in, there is pending
	 * content, and nothing is already queued. Lazy (only when work exists) and
	 * idempotent (no duplicate chains) — no idle polling.
	 */
	public static function maybe_schedule(): void {
		if ( ! Content_HTML::storage_enabled() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( as_has_scheduled_action( self::BATCH_HOOK, array(), self::GROUP ) ) {
			return;
		}
		if ( self::pending_count() < 1 ) {
			return;
		}
		self::maybe_backup_once();
		as_enqueue_async_action( self::BATCH_HOOK, array(), self::GROUP );
	}

	/**
	 * Write a one-time, byte-exact restore point before the automatic migration
	 * touches anything. Guarded so it runs once per site. The owner can revert
	 * any time via `wp learnomy content restore --file=<the logged path>`.
	 */
	public static function maybe_backup_once(): void {
		if ( get_option( self::BACKUP_DONE_OPTION ) ) {
			return;
		}

		// The snapshot is every lesson body, every course description and every
		// quiz description in one JSON file — the entire paid catalogue. It used
		// to be written to `uploads/learnomy-pre-html-migration-backup.json`, a
		// fixed, guessable, publicly-served path (Basecamp 10148249510, found
		// while sweeping for the same shape as the lesson-handout leak). It now
		// goes into the protected store under a random name. If that store is
		// unavailable we write NOTHING rather than fall back to a public path;
		// the migration is independently reversible through the engine, so a
		// missing restore point is a smaller problem than a published catalogue.
		if ( ! Protected_Uploads::ensure_store() ) {
			update_option( self::BACKUP_DONE_OPTION, '', false );
			return;
		}

		$file = Protected_Uploads::store_dir() . '/pre-html-migration-backup-' . bin2hex( random_bytes( 8 ) ) . '.json';
		$rows = self::backup_to( $file );
		// Mark done even on write failure so a broken uploads dir can't block the
		// migration forever; the cutover stays reversible via the engine itself.
		update_option( self::BACKUP_DONE_OPTION, $file, false );
		if ( $rows >= 0 ) {
			do_action( 'learnomy_content_premigration_backup_written', $file, $rows );
		}
	}

	/**
	 * Move a pre-existing snapshot off its public path.
	 *
	 * Sites that migrated before this fix already have the whole catalogue
	 * sitting at a fixed URL, so protecting only future snapshots would leave
	 * every current one exposed. Called by the protected-uploads backfill, which
	 * runs once per site regardless of whether any content is still pending —
	 * `maybe_backup_once()` cannot do it, because on a fully-migrated site it
	 * never runs again.
	 *
	 * @return string The new path, or '' when there was nothing to move.
	 */
	public static function relocate_backup_to_protected(): string {
		$current = (string) get_option( self::BACKUP_DONE_OPTION, '' );
		if ( '' === $current || ! file_exists( $current ) ) {
			return '';
		}

		if ( str_contains( $current, '/' . Protected_Uploads::DIR_NAME . '/' ) ) {
			return '';
		}

		if ( ! Protected_Uploads::ensure_store() ) {
			return '';
		}

		$target = Protected_Uploads::store_dir() . '/pre-html-migration-backup-' . bin2hex( random_bytes( 8 ) ) . '.json';

		// A failed rename is a return value here, not a warning; this runs under
		// Action Scheduler where WP_Filesystem has no credentials to request.
		if ( ! @rename( $current, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
			return '';
		}

		update_option( self::BACKUP_DONE_OPTION, $target, false );

		return $target;
	}

	/**
	 * One background batch: convert up to BATCH_SIZE rows, then chain the next
	 * batch only while work remains (self-terminating — no idle re-runs).
	 */
	public static function run_background_batch(): void {
		if ( ! Content_HTML::storage_enabled() ) {
			return; // Cutover turned off mid-run — stop.
		}
		$result = self::convert_batch( self::BATCH_SIZE );
		// Re-enqueue ONLY when this batch actually converted rows AND work remains
		// — so a loose-LIKE false positive in pending_count() can never make the
		// chain idle-spin forever (mirrors the CLI drain's converted>0 guard).
		if ( $result['converted'] > 0 && $result['remaining'] > 0 && function_exists( 'as_enqueue_async_action' ) && ! as_has_scheduled_action( self::BATCH_HOOK, array(), self::GROUP ) ) {
			as_enqueue_async_action( self::BATCH_HOOK, array(), self::GROUP );
		}
	}
}
