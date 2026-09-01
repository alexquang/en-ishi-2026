<?php
/**
 * Audit log — append-only record of significant user and admin actions.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class AuditLog extends Model {

	protected static function table_name(): string {
		return 'audit_log';
	}

	/**
	 * Appends a new audit log entry.
	 *
	 * @param int    $user_id      The user who performed the action.
	 * @param string $type         Log category (e.g. 'admin_action', 'auth').
	 * @param string $action       Specific action label (e.g. 'course.deleted').
	 * @param string $object_type  Type of affected object (e.g. 'course').
	 * @param int    $object_id    ID of the affected object.
	 * @param array  $metadata     Optional extra data stored as JSON.
	 * @return int  Inserted row ID.
	 */
	public static function log(
		int $user_id,
		string $type,
		string $action,
		string $object_type = '',
		int $object_id = 0,
		array $metadata = []
	): int {
		return static::insert(
			[
				'user_id'       => $user_id,
				'type'          => $type,
				'action'        => $action,
				'object_type'   => $object_type,
				'object_id'     => $object_id,
				'metadata_json' => wp_json_encode( $metadata ),
				'ip_address'    => \Learnomy\client_ip(),
				'created_at'    => now(),
			]
		);
	}

	public static function get_for_user( int $user_id, int $limit = 100 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d ORDER BY created_at DESC LIMIT %d',
				$user_id,
				$limit
			)
		);
	}

	/**
	 * Most-recent log rows of a given type, newest first. Backs cross-plugin
	 * debug/audit views (e.g. the Pro xAPI delivery log). Uses the
	 * type_created (type, created_at) index, so it stays fast on large tables.
	 *
	 * @return object[]
	 */
	public static function recent_by_type( string $type, int $limit = 50 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE type = %s ORDER BY created_at DESC, id DESC LIMIT %d',
				$type,
				$limit
			)
		);
	}

	/**
	 * The latest entry of one action for each of a SET of objects, keyed by
	 * object id — one query for a whole page of rows, never one per row.
	 *
	 * Written for the Email Log, which shows "resent <when>, <outcome>" beside
	 * every row on the page. Rows come back newest-first and the first one seen
	 * per object wins, so the caller gets the most recent event plus a count of
	 * how many there were.
	 *
	 * Uses the `object (object_type, object_id)` index added in DB 3.35.0.
	 *
	 * @param string $object_type Object type recorded on the entry (e.g. 'notification').
	 * @param int[]  $object_ids  Object ids to look up (deduped + int-cast internally).
	 * @param string $action      Action label to match (e.g. 'email.resent').
	 * @return array<int,array{latest:object,count:int}> object_id => newest entry + total.
	 */
	public static function latest_for_objects( string $object_type, array $object_ids, string $action ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $object_ids ) ) ) );
		if ( '' === $object_type || '' === $action || empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal; every value is bound below.
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . "
				 WHERE object_type = %s AND action = %s AND object_id IN ({$placeholders})
				 ORDER BY created_at DESC, id DESC",
				$object_type,
				$action,
				...$ids
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$object_id = (int) $row->object_id;
			if ( ! isset( $map[ $object_id ] ) ) {
				$map[ $object_id ] = array(
					'latest' => $row,
					'count'  => 0,
				);
			}
			++$map[ $object_id ]['count'];
		}

		return $map;
	}

	// ── Report-driven moderation queue ─────────────────────────────────────────
	//
	// A member "reports" a review / lesson comment / course; the report lands
	// here as a `type = 'report'` row instead of a bespoke table (rule 4a —
	// audit_log already models `object_type` / `object_id` / `metadata_json`).
	// The report's lifecycle lives in the `action` column so the admin queue can
	// filter + count by state cheaply within the already-narrow `type='report'`
	// slice (the `type_created` index seeks it):
	//
	// action = 'open'      → freshly reported, awaiting a moderator
	// = 'dismissed' → moderator reviewed, no action needed
	// = 'actioned'  → moderator acted on the underlying item
	//
	// One report per user per object is enforced by `has_reported()` so a single
	// member can't inflate the queue by re-reporting the same item.

	/** Report lifecycle states, mirrored in the `action` column. */
	public const REPORT_OPEN      = 'open';
	public const REPORT_DISMISSED = 'dismissed';
	public const REPORT_ACTIONED  = 'actioned';

	/**
	 * Per-request map of "<user>|<type>" => [ object_id => true ].
	 *
	 * @var array<string, array<int, true>>
	 */
	private static array $reported_cache = array();

	/**
	 * Files a moderation report against a review / comment / course.
	 *
	 * @param int    $reporter_id  Logged-in user filing the report.
	 * @param string $object_type  'review' | 'comment' | 'course'.
	 * @param int    $object_id    ID of the reported object.
	 * @param string $reason       Optional free-text reason from the reporter.
	 * @return int  Inserted row ID (0 on failure).
	 */
	public static function add_report( int $reporter_id, string $object_type, int $object_id, string $reason = '' ): int {
		$inserted = static::insert(
			[
				'user_id'       => $reporter_id,
				'type'          => 'report',
				'action'        => self::REPORT_OPEN,
				'object_type'   => $object_type,
				'object_id'     => $object_id,
				'metadata_json' => wp_json_encode(
					[
						'object_type' => $object_type,
						'object_id'   => $object_id,
						'reporter'    => $reporter_id,
						'reason'      => $reason,
						'status'      => self::REPORT_OPEN,
					]
				),
				'ip_address'    => \Learnomy\client_ip(),
				'created_at'    => now(),
			]
		);

		// The map this request already loaded now has a hole in it. The REST
		// controller reads that map to reject duplicates, so leaving it stale
		// would let a second report through in the same request.
		self::flush_reported_cache();

		return $inserted;
	}

	/**
	 * Has this user already reported this exact object? Backs the one-report-per-
	 * user-per-object dedupe so the queue can't be flooded by repeat reports.
	 *
	 * Answers from a per-request map of everything this user has reported OF THIS
	 * TYPE, loaded once. The alternative — a query per object — is invisible at
	 * the controller, which asks about one object, and an N+1 in the template,
	 * which asks once per row of a review list that is allowed to be long.
	 * Reports are a handful of rows per member even for prolific reporters, so
	 * the whole set is cheaper to hold than a single extra round trip.
	 */
	public static function has_reported( int $reporter_id, string $object_type, int $object_id ): bool {
		if ( $reporter_id <= 0 || $object_id <= 0 || '' === $object_type ) {
			return false;
		}

		return isset( self::reported_ids( $reporter_id, $object_type )[ $object_id ] );
	}

	/**
	 * Every object id of one type this user has reported, keyed by id.
	 *
	 * Keyed rather than a list so callers test with isset() instead of
	 * in_array() — the difference matters once a template calls it per row.
	 *
	 * @param int    $reporter_id Logged-in user.
	 * @param string $object_type 'review' | 'comment' | 'course'.
	 * @return array<int, true>
	 */
	public static function reported_ids( int $reporter_id, string $object_type ): array {
		if ( $reporter_id <= 0 || '' === $object_type ) {
			return array();
		}

		$key = $reporter_id . '|' . $object_type;

		if ( ! isset( self::$reported_cache[ $key ] ) ) {
			$rows = static::db()->get_col(
				static::db()->prepare(
					'SELECT object_id FROM ' . static::table() . " WHERE type = 'report' AND user_id = %d AND object_type = %s",
					$reporter_id,
					$object_type
				)
			);

			self::$reported_cache[ $key ] = array_fill_keys( array_map( 'intval', (array) $rows ), true );
		}

		return self::$reported_cache[ $key ];
	}

	/**
	 * Drop the per-request reported map.
	 *
	 * Called by add_report() so the same request cannot go on believing the
	 * object is unreported. The REST controller reads this map to reject
	 * duplicates, so a stale entry there would let a second report through —
	 * the exact flooding the dedupe exists to prevent.
	 *
	 * A class property rather than a function static specifically so this can
	 * clear it; a static inside the method would be unreachable from here.
	 */
	public static function flush_reported_cache(): void {
		self::$reported_cache = array();
	}

	/**
	 * Paginated report rows for the admin queue, newest first. Constrains on
	 * `type = 'report'` (index-backed) and optionally a lifecycle state.
	 *
	 * @param string $status One of the REPORT_* states, or '' for every state.
	 * @return object[]
	 */
	public static function get_reports( string $status = '', int $limit = 20, int $offset = 0 ): array {
		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		if ( '' !== $status ) {
			return static::db()->get_results(
				static::db()->prepare(
					'SELECT * FROM ' . static::table() . " WHERE type = 'report' AND action = %s ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
					$status,
					$limit,
					$offset
				)
			);
		}

		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE type = 'report' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	/** Total reports matching a lifecycle state (or all when $status is ''). */
	public static function count_reports( string $status = '' ): int {
		if ( '' !== $status ) {
			return (int) static::db()->get_var(
				static::db()->prepare(
					'SELECT COUNT(*) FROM ' . static::table() . " WHERE type = 'report' AND action = %s",
					$status
				)
			);
		}

		return (int) static::db()->get_var(
			'SELECT COUNT(*) FROM ' . static::table() . " WHERE type = 'report'"
		);
	}

	/**
	 * Report counts keyed by lifecycle state — one GROUP BY for every queue tab
	 * so the counts don't cost one COUNT(*) per state.
	 *
	 * @return array<string,int>
	 */
	public static function count_reports_by_status(): array {
		$rows = static::db()->get_results(
			'SELECT action, COUNT(*) AS total FROM ' . static::table() . " WHERE type = 'report' GROUP BY action"
		);

		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->action ] = (int) $row->total;
		}

		return $out;
	}

	/**
	 * Resolves a report — dismiss it or mark it actioned. Moves the lifecycle
	 * state into the `action` column and records who resolved it + when in the
	 * metadata so the audit trail survives.
	 *
	 * @param int    $report_id   Report row ID.
	 * @param string $resolution  'dismiss' | 'act'.
	 * @param int    $resolver_id Admin resolving the report.
	 */
	public static function resolve_report( int $report_id, string $resolution, int $resolver_id ): bool {
		$row = static::find( $report_id );
		if ( ! $row || 'report' !== ( $row->type ?? '' ) ) {
			return false;
		}

		$status = 'dismiss' === $resolution ? self::REPORT_DISMISSED : self::REPORT_ACTIONED;

		$meta = json_decode( (string) ( $row->metadata_json ?? '' ), true );
		if ( ! is_array( $meta ) ) {
			$meta = [];
		}
		$meta['status']      = $status;
		$meta['resolution']  = $resolution;
		$meta['resolved_by'] = $resolver_id;
		$meta['resolved_at'] = now();

		return static::update(
			$report_id,
			[
				'action'        => $status,
				'metadata_json' => wp_json_encode( $meta ),
			]
		);
	}

	/** Hard-deletes log entries older than $days to control table growth. */
	public static function prune( int $days = 90 ): int {
		$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$deleted = static::db()->query(
			static::db()->prepare(
				'DELETE FROM ' . static::table() . ' WHERE created_at < %s',
				$cutoff
			)
		);
		static::invalidate_cache();

		return (int) $deleted;
	}
}
