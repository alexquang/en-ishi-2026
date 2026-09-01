<?php
/**
 * Migration 3.47.0 — give every broadcast an identity of its own.
 *
 * Recall used to find a broadcast's recipients by matching
 * `(type, created_at, title, object_id)`. That works only while every recipient
 * row shares one `created_at`, which is true today because both senders pin a
 * compose-time stamp before fan-out — but it is a tuple that HAPPENS to be
 * unique, not an identity, and the difference is not academic:
 *
 *   - Two broadcasts of the same title to the same object inside one second are
 *     indistinguishable, and deleting either takes both.
 *   - Rows written BEFORE the compose-time pin landed still carry per-worker
 *     timestamps, so recall reaches only the subset that happened to run in the
 *     same second — the original defect (Basecamp 10132809203), unfixable by
 *     any change to the matching rule because the data itself is fragmented.
 *   - Any future path that forgets to pin re-breaks recall silently.
 *
 * `broadcast_id` is unique by construction, so none of those are possible. The
 * column is added by `dbDelta()` from the schema; this migration backfills what
 * already exists so historical broadcasts become recallable too:
 *
 * 1. Rows sharing `(title, object_id, created_at)` are one broadcast and get
 *    one id — the identity the old rule inferred, now recorded rather than
 *    re-derived on every read.
 * 2. Pre-pin broadcasts, whose rows drifted across a few seconds, are grouped by
 *    `(title, object_id)` within a 5-minute window. That reunites a fan-out the
 *    old rule could never fully reach, which is precisely what the card asked
 *    for and what a matching-rule change alone cannot deliver.
 *
 * Forward-only and idempotent: both passes skip rows that already carry an id.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_47_0 {

	/** Rows further apart than this are treated as separate broadcasts. */
	private const WINDOW_SECONDS = 300;

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_notifications';

		// Add the column ourselves when it is missing, rather than waiting for
		// Schema::create_tables().
		//
		// This is NOT belt-and-braces. `Learnomy::check_db_version()` runs the
		// migrator on every upgrade WITHOUT calling create_tables() first —
		// that only happens on activation — so on a plugin update the column
		// genuinely does not exist yet when this runs. An earlier draft simply
		// returned in that case, and because the migrator records the version
		// regardless, the backfill would have been skipped PERMANENTLY on every
		// real upgrade: the migration never runs twice. Silently doing nothing
		// and marking yourself done is the worst shape a migration can take.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_column = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'broadcast_id'" );
		if ( ! $has_column ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN broadcast_id varchar(32) NOT NULL DEFAULT '' AFTER email_error" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY broadcast_id (broadcast_id)" );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'broadcast_id'" ) ) {
				// Could not add it — leave the version alone rather than
				// claiming a backfill that did not happen.
				return;
			}
		}

		// Pass 1 — exact groups. These are the broadcasts sent since the
		// compose-time pin: one timestamp across every recipient.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$groups = $wpdb->get_results(
			"SELECT title, object_id, created_at
			   FROM `{$table}`
			  WHERE type = 'announcement' AND broadcast_id = ''
			  GROUP BY title, object_id, created_at"
		);

		foreach ( $groups as $group ) {
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE `{$table}` SET broadcast_id = %s
					  WHERE type = 'announcement' AND broadcast_id = ''
					    AND title <=> %s AND object_id = %d AND created_at = %s",
					self::new_id(),
					$group->title,
					(int) $group->object_id,
					$group->created_at
				)
			);
		}

		// Pass 2 — pre-pin fan-outs whose rows drifted across seconds. Group by
		// (title, object_id) and walk them in time order, starting a new id
		// whenever the gap exceeds the window. Anything Pass 1 already claimed
		// is excluded, so a single-timestamp broadcast is never re-grouped.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, title, object_id, created_at
			   FROM `{$table}`
			  WHERE type = 'announcement' AND broadcast_id = ''
			  ORDER BY title, object_id, created_at, id"
		);

		$current_key = null;
		$current_id  = '';
		$last_time   = 0;

		foreach ( $rows as $row ) {
			$key  = (string) $row->title . '|' . (int) $row->object_id;
			$time = (int) strtotime( (string) $row->created_at );

			if ( $key !== $current_key || ( $time - $last_time ) > self::WINDOW_SECONDS ) {
				$current_key = $key;
				$current_id  = self::new_id();
			}

			$last_time = $time;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $table, [ 'broadcast_id' => $current_id ], [ 'id' => (int) $row->id ] );
		}
	}

	/** A 32-char identity. Not a nonce — it only has to be unique. */
	private static function new_id(): string {
		return wp_generate_password( 32, false, false );
	}
}
