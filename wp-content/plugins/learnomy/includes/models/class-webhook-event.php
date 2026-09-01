<?php
/**
 * Webhook event log — one row per inbound provider webhook delivery.
 *
 * Every Stripe and PayPal webhook is logged here BEFORE processing so the
 * admin has a permanent audit trail and can replay failed events without
 * waiting for the provider's retry schedule.
 *
 * Idempotency contract
 * - `log()` returns 0 when the UNIQUE (provider, event_id) constraint fires.
 *   Callers treat 0 as "already seen" and skip processing.
 * - `increment_retry()` bumps retry_count for subsequent deliveries of the
 *   same event_id so ops can see how many times a provider retried.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Webhook_Event extends Model {

	protected static function table_name(): string {
		return 'webhook_events';
	}

	protected static function cache_group(): string {
		return 'learnomy_webhook_events';
	}

	/**
	 * Insert a new event row and return its ID.
	 *
	 * Returns 0 on a UNIQUE constraint violation (duplicate event_id for the
	 * same provider) — callers MUST check for 0 and skip processing. Returns
	 * 0 also on any other DB error; callers should check `$wpdb->last_error`
	 * if they need to distinguish the two cases.
	 *
	 * @param array{provider: string, event_type: string, event_id: string, payload_json: string, signature_status: string, outcome: string, error_message: string|null, retry_count?: int} $data Event row fields.
	 * @return int Inserted row ID, or 0 on duplicate / error.
	 */
	public static function log( array $data ): int {
		// INSERT IGNORE returns 0 affected rows on duplicate without causing
		// a DB error, which allows wpdb::insert_id to remain 0. We use a raw
		// query here rather than wpdb::insert() so we can control the INSERT
		// verb (IGNORE).
		$db = static::db();

		$outcome       = $data['outcome'] ?? 'pending';
		$retry_count   = isset( $data['retry_count'] ) ? (int) $data['retry_count'] : 0;
		$error_message = $data['error_message'] ?? null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$db->query(
			$db->prepare(
				'INSERT IGNORE INTO ' . static::table() . '
				(provider, event_type, event_id, payload_json, signature_status, processed_at, outcome, error_message, retry_count)
				VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %d)',
				$data['provider'],
				$data['event_type'],
				$data['event_id'],
				$data['payload_json'],
				$data['signature_status'],
				now(),
				$outcome,
				$error_message,
				$retry_count
			)
		);

		$id = (int) $db->insert_id;

		if ( $id ) {
			static::invalidate_cache();
		}

		return $id;
	}

	/**
	 * Find an event row by its provider event ID.
	 *
	 * Used for idempotency checks and for the admin replay flow where we need
	 * to reconstruct the full row including payload_json before re-dispatching.
	 *
	 * @param string $provider  'stripe' | 'paypal'.
	 * @param string $event_id  Provider's unique event ID (evt_xxx / WH-xxx).
	 * @return object|null The DB row, or null when not found.
	 */
	public static function find_by_event_id( string $provider, string $event_id ): ?object {
		$last_changed = wp_cache_get_last_changed( static::cache_group() );
		$cache_key    = "webhook_event:{$provider}:{$event_id}:{$last_changed}";

		$cached = wp_cache_get( $cache_key, static::cache_group() );
		if ( false !== $cached ) {
			return '' === $cached ? null : $cached;
		}

		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE provider = %s AND event_id = %s LIMIT 1',
				$provider,
				$event_id
			)
		);

		$to_cache = $row ?: '';
		wp_cache_set( $cache_key, $to_cache, static::cache_group() );

		return $row ?: null;
	}

	/**
	 * Paginated list of recent events for the admin viewer.
	 *
	 * Supports optional filtering by provider and outcome. Results are ordered
	 * newest-first so the most recent activity appears at the top.
	 *
	 * @param string $provider Filter to 'stripe' or 'paypal', or '' for all.
	 * @param string $outcome  Filter to specific outcome, or '' for all.
	 * @param int    $limit    Maximum rows to return (default 50, capped at 200).
	 * @param int    $offset   Offset for pagination.
	 * @return object[]
	 */
	public static function recent( string $provider = '', string $outcome = '', int $limit = 50, int $offset = 0 ): array {
		$limit = max( 1, min( 200, $limit ) );

		$where   = [];
		$values  = [];
		$clauses = [];

		if ( '' !== $provider ) {
			$clauses[] = 'provider = %s';
			$values[]  = $provider;
		}

		if ( '' !== $outcome ) {
			$clauses[] = 'outcome = %s';
			$values[]  = $outcome;
		}

		$sql = 'SELECT * FROM ' . static::table();

		if ( ! empty( $clauses ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
		}

		$sql .= ' ORDER BY id DESC LIMIT ' . absint( $limit ) . ' OFFSET ' . absint( $offset );

		if ( ! empty( $values ) ) {
			$sql = static::db()->prepare( $sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$rows = static::db()->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Count rows matching the given filters.
	 *
	 * Mirrors the signature of `recent()` so the controller can compute
	 * pagination totals with the same filter parameters.
	 *
	 * @param string $provider Filter to 'stripe' or 'paypal', or '' for all.
	 * @param string $outcome  Filter to specific outcome, or '' for all.
	 * @return int Total matching rows.
	 */
	public static function count_filtered( string $provider = '', string $outcome = '' ): int {
		$values  = [];
		$clauses = [];

		if ( '' !== $provider ) {
			$clauses[] = 'provider = %s';
			$values[]  = $provider;
		}

		if ( '' !== $outcome ) {
			$clauses[] = 'outcome = %s';
			$values[]  = $outcome;
		}

		$sql = 'SELECT COUNT(*) FROM ' . static::table();

		if ( ! empty( $clauses ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
		}

		if ( ! empty( $values ) ) {
			$sql = static::db()->prepare( $sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return (int) static::db()->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Bump retry_count for a row identified by its database ID.
	 *
	 * Called after a duplicate event_id is detected (log() returned 0) to
	 * record that the provider re-delivered the event.
	 *
	 * @param int $id The `lrn_webhook_events.id` row ID.
	 * @return bool True on success.
	 */
	public static function increment_retry( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = false !== static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET retry_count = retry_count + 1 WHERE id = %d',
				$id
			)
		);

		if ( $ok ) {
			static::invalidate_cache();
		}

		return $ok;
	}

	/**
	 * Bump retry_count for a row identified by (provider, event_id).
	 *
	 * Convenience wrapper for adapter code that has the provider event ID but
	 * not the row's internal primary key.
	 *
	 * @param string $provider 'stripe' | 'paypal'.
	 * @param string $event_id Provider's unique event ID.
	 * @return bool True on success.
	 */
	public static function increment_retry_by_event_id( string $provider, string $event_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = false !== static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET retry_count = retry_count + 1 WHERE provider = %s AND event_id = %s',
				$provider,
				$event_id
			)
		);

		if ( $ok ) {
			static::invalidate_cache();
		}

		return $ok;
	}

	/**
	 * Update the outcome and optional error_message for a logged event.
	 *
	 * Called after the adapter has finished processing so the row reflects the
	 * real final state: 'handled', 'rejected', 'error', or 'duplicate'.
	 *
	 * @param int         $id      The `lrn_webhook_events.id` row ID.
	 * @param string      $outcome 'handled' | 'duplicate' | 'rejected' | 'error'.
	 * @param string|null $error   Error message when outcome is 'error', null otherwise.
	 * @return bool True on success.
	 */
	public static function update_outcome( int $id, string $outcome, ?string $error = null ): bool {
		$ok = static::update(
			$id,
			[
				'outcome'       => $outcome,
				'error_message' => $error,
			]
		);

		return $ok;
	}
}
