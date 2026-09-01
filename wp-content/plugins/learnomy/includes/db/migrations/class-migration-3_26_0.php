<?php
/**
 * Migration 3.26.0 — reverse-lookup index on lrn_blocked_users.blocked_id.
 *
 * The block table (added 3.22.0) indexed only blocker_id ("who have I blocked").
 * GDPR erasure now also deletes the symmetric side — every row where the erased
 * person is the blocked_id ("who blocked me") — and a moderation/support "who
 * has user N blocked" lookup wants the same access path. Without an index on
 * blocked_id both queries table-scan, which does not hold at 10k+ member scale.
 *
 * Forward-only and idempotent: the ADD KEY is guarded by an information_schema
 * existence check, so re-running is a no-op and a fresh install (which already
 * ships idx_blocked via Schema::create_tables) is unaffected.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_26_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_blocked_users';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_index = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
				$table,
				'idx_blocked'
			)
		);

		if ( 0 === $has_index ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY idx_blocked (blocked_id)" );
		}
	}
}
