<?php
/**
 * Certificate — verifiable proof of course or quiz completion.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use Learnomy\DB\Relations;

use function Learnomy\now;

class Certificate extends Model {

	protected const INT_COLUMNS = [
		'user_id',
		'course_id',
		'template_id',
		'enrollment_id',
		'verification_count',
	];

	/** Snapshot measures — see the `*_snapshot` note in DB\Schema. */
	protected const FLOAT_COLUMNS = [
		'score_snapshot',
		'hours_snapshot',
	];

	/**
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		// KEEP: deleting a course VOIDS a credential, it does not erase
		// the fact somebody earned it. Course_Service::delete() has always
		// kept these on purpose; the declaration makes that intent explicit
		// rather than a comment the cascade could quietly override.
		'course'     => array(
			'column'    => 'course_id',
			'on_delete' => Relations::KEEP,
		),
		'enrollment' => array(
			'column'    => 'enrollment_id',
			'on_delete' => Relations::KEEP,
		),
	);

	protected static function table_name(): string {
		return 'certificates';
	}

	/**
	 * UUID makes public verification URLs unguessable without exposing internal IDs.
	 * verification_count starts at 0 and is bumped each time verify() is called.
	 */
	public static function issue( array $certificate ): int {
		return static::insert(
			array_merge(
				[
					'uuid'               => wp_generate_uuid4(),
					'status'             => 'active',
					'verification_count' => 0,
					'issued_at'          => now(),
				],
				$certificate
			)
		);
	}

	/*
	 * There is deliberately no delete_for_course() here.
	 *
	 * It existed so `Course_Service::delete()` could cascade certificates away
	 * with the course, which meant retiring a course destroyed every credential
	 * already earned for it (Basecamp 10148480574). Certificates now record what
	 * they certify and outlive the course; a credential that should no longer
	 * stand is REVOKED, which keeps the record verifiable and says so.
	 */

	public static function find_by_uuid( string $uuid ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE uuid = %s LIMIT 1',
				$uuid
			)
		) ?: null;
	}

	/**
	 * Active certificate for a given user + course (or path masquerading
	 * as a course — Pro Learning Paths writes the path_id into the
	 * course_id column since this table has no path_id column).
	 */
	public static function find_active_for_user_course( int $user_id, int $course_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE user_id = %d AND course_id = %d AND status = 'active' LIMIT 1",
				$user_id,
				$course_id
			)
		) ?: null;
	}

	/**
	 * Active certificate UUIDs for one user across many courses, as a
	 * course_id => uuid map. The batch companion to
	 * {@see find_active_for_user_course()} — calling that per row is the N+1
	 * the big-site checklist exists to prevent, and a credentials panel walks a
	 * whole page of courses at once.
	 *
	 * A course with two active certificates (re-issued after a revoke) keeps the
	 * most recently issued one, so the map is deterministic.
	 *
	 * @param int   $user_id    Certificate holder.
	 * @param array $course_ids Courses to look up.
	 * @return array<int,string> course_id => uuid, courses without one omitted.
	 */
	public static function active_uuids_for_user_courses( int $user_id, array $course_ids ): array {
		$course_ids = array_values( array_unique( array_filter( array_map( 'intval', $course_ids ) ) ) );
		if ( $user_id <= 0 || empty( $course_ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$placeholders} is a generated %d list, not user input.
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT course_id, uuid FROM ' . static::table() .
				" WHERE user_id = %d AND status = 'active' AND course_id IN ({$placeholders})
				  ORDER BY issued_at ASC, id ASC",
				array_merge( [ $user_id ], $course_ids )
			)
		) ?: [];

		$map = [];
		foreach ( $rows as $row ) {
			// Ascending order means a later row overwrites an earlier one, so the
			// most recently issued certificate wins.
			$map[ (int) $row->course_id ] = (string) $row->uuid;
		}

		return $map;
	}

	/**
	 * Path-level certificate hack: after issuing a certificate with
	 * `course_id = 0`, callers (Pro Learning Paths) overwrite course_id
	 * with the path_id so the certificate row links back to the path.
	 * Exposed here so Pro doesn't reach into the table directly.
	 */
	public static function set_course_id( int $cert_id, int $course_id ): bool {
		return static::update( $cert_id, [ 'course_id' => $course_id ] );
	}

	public static function get_for_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		return static::where( [ 'user_id' => $user_id ], 'issued_at', 'DESC', $limit, $offset );
	}

	/**
	 * Certificates to present to the STUDENT — active only. get_for_user() returns
	 * every status (revoked / expired included), which is correct for GDPR export,
	 * admin, and CLI, but a learner's own account/dashboard must never show a
	 * revoked or lapsed certificate as earned (Basecamp 10109147988). Filters
	 * status='active' AND not past expiry (the daily cron flips expired ->
	 * 'expired'; the expires_at guard covers the window before it runs).
	 */
	public static function get_active_for_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table()
					. " WHERE user_id = %d AND status = 'active' AND ( expires_at IS NULL OR expires_at >= %s )"
					. ' ORDER BY issued_at DESC LIMIT %d OFFSET %d',
				$user_id,
				now(),
				$limit,
				$offset
			)
		) ?: array();
	}

	public static function count_for_user( int $user_id ): int {
		return static::count( [ 'user_id' => $user_id ] );
	}

	/**
	 * Count of the student-facing (active, non-expired) certificates — the COUNT
	 * companion to get_active_for_user() so a paginated list's total matches what
	 * the learner actually sees (Basecamp 10109147988).
	 */
	public static function count_active_for_user( int $user_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table()
					. " WHERE user_id = %d AND status = 'active' AND ( expires_at IS NULL OR expires_at >= %s )",
				$user_id,
				now()
			)
		);
	}

	/**
	 * Filtered admin certificate list. Supports a status filter, a course
	 * filter, and a free-text search that matches either the certificate
	 * UUID or one of the supplied student user IDs (the controller resolves
	 * names/emails to ids first). The base `where()` only does equality, so
	 * the OR-search + IN clause are built here.
	 *
	 * @param array{status?:string,course_id?:int,uuid_search?:string,user_ids?:int[]} $filters
	 */
	public static function list_for_admin( array $filters, string $orderby = 'issued_at', string $order = 'DESC', int $limit = 20, int $offset = 0 ): array {
		[ $where, $params ] = self::build_admin_filter_sql( $filters );

		$direction = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$orderby   = esc_sql( $orderby );
		$params[]  = $limit;
		$params[]  = $offset;

		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE ' . implode( ' AND ', $where )
					. ' ORDER BY `' . $orderby . '` ' . $direction . ' LIMIT %d OFFSET %d',
				...$params
			)
		) ?: array();
	}

	/**
	 * Count for the filtered admin list (same filters as list_for_admin()).
	 *
	 * @param array{status?:string,course_id?:int,uuid_search?:string,user_ids?:int[]} $filters
	 */
	public static function count_for_admin( array $filters ): int {
		[ $where, $params ] = self::build_admin_filter_sql( $filters );

		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE ' . implode( ' AND ', $where ),
				...$params
			)
		);
	}

	/**
	 * Compose the WHERE clause + bound params shared by the admin list + count.
	 *
	 * @param array{status?:string,course_id?:int,uuid_search?:string,user_ids?:int[]} $filters
	 * @return array{0:string[],1:array<int,mixed>} [where[], params[]]
	 */
	private static function build_admin_filter_sql( array $filters ): array {
		$where  = array( '1 = %d' );
		$params = array( 1 );

		$status      = (string) ( $filters['status'] ?? '' );
		$course_id   = (int) ( $filters['course_id'] ?? 0 );
		$uuid_search = (string) ( $filters['uuid_search'] ?? '' );
		$user_ids    = array_values( array_filter( array_map( 'intval', (array) ( $filters['user_ids'] ?? array() ) ) ) );

		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}
		if ( $course_id > 0 ) {
			$where[]  = 'course_id = %d';
			$params[] = $course_id;
		}

		// Search matches the certificate UUID OR any of the resolved student
		// ids. When a search term was entered but resolved to no users and
		// can't match a uuid, the uuid LIKE still bounds the result to zero —
		// which is the correct "no matches" outcome.
		if ( '' !== $uuid_search || ! empty( $user_ids ) ) {
			$or = array();
			if ( '' !== $uuid_search ) {
				$or[]     = 'uuid LIKE %s';
				$params[] = '%' . static::db()->esc_like( $uuid_search ) . '%';
			}
			if ( ! empty( $user_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
				$or[]         = "user_id IN ({$placeholders})";
				foreach ( $user_ids as $uid ) {
					$params[] = $uid;
				}
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		}

		return array( $where, $params );
	}

	/**
	 * Distinct course ids that have at least one certificate — feeds the
	 * admin Course filter dropdown so it only lists courses with certs.
	 *
	 * @return int[]
	 */
	public static function distinct_course_ids(): array {
		return array_map(
			'intval',
			(array) static::db()->get_col(
				'SELECT DISTINCT course_id FROM ' . static::table() . ' WHERE course_id > 0 ORDER BY course_id ASC'
			)
		);
	}

	public static function revoke( int $id ): bool {
		return static::update(
			$id,
			[
				'status'     => 'revoked',
				'revoked_at' => now(),
			]
		);
	}

	/**
	 * GDPR erasure — hard-delete every certificate belonging to a user.
	 *
	 * Revoking is NOT erasure: a revoked row keeps the uuid, the signature and the
	 * user link, so /verify/{uuid} still resolves and the holder is still
	 * identifiable. Art. 17 asks for removal, so the default erasure path deletes
	 * the rows outright and the verify URL 404s (Basecamp 10109167870).
	 *
	 * @return int Rows deleted.
	 */
	public static function delete_for_user( int $user_id ): int {
		$deleted = (int) static::db()->delete( static::table(), array( 'user_id' => $user_id ), array( '%d' ) );
		static::invalidate_cache();
		return $deleted;
	}

	/**
	 * GDPR erasure, retention variant — keep the issuance record but strip the
	 * person from it. For a site owner who must evidence "a certificate was issued
	 * for course X on date Y" without holding the holder's identity. The row can no
	 * longer identify or verify anyone: the user link is dropped and the signature
	 * (which is computed over the holder's identity) is cleared, so /verify/{uuid}
	 * cannot resolve a holder. Selected via the
	 * `learnomy_privacy_certificate_erasure` filter.
	 *
	 * The recipient-name snapshot goes with the user link. It exists so a
	 * certificate keeps naming its holder after a display-name change, which is
	 * exactly the identity Art. 17 asks us to drop — leaving it behind would make
	 * the "keep the issuance record, lose the person" variant name the person.
	 *
	 * @return int Rows anonymized.
	 */
	public static function anonymize_for_user( int $user_id ): int {
		$updated = (int) static::db()->update(
			static::table(),
			array(
				'user_id'                 => 0,
				'signature'               => null,
				'recipient_name_snapshot' => null,
				'status'                  => 'revoked',
				'revoked_at'              => now(),
				'revoked_reason'          => 'gdpr-erasure',
			),
			array( 'user_id' => $user_id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
		static::invalidate_cache();
		return $updated;
	}

	/**
	 * Increments the counter so site owners can see how often a certificate
	 * has been checked — useful for spotting suspicious validation spikes.
	 */
	public static function verify( string $uuid ): ?object {
		$certificate = static::find_by_uuid( $uuid );
		if ( ! $certificate ) {
			return null;
		}

		// Rate-limit the write-on-read. This runs on every public, unauthenticated
		// GET of the verify endpoint, so without a throttle anyone holding a UUID
		// could hammer a row UPDATE (write amplification). Count at most once per
		// certificate per window — verification_count stays a useful audit signal
		// without being an abuse vector. The READ is never throttled; only the
		// increment is. Filterable so a site can widen/disable the window (0 = off).
		$throttle_key    = 'lrn_cert_verify_' . (int) $certificate->id;
		$throttle_window = (int) apply_filters( 'learnomy_cert_verify_throttle_seconds', 5 * MINUTE_IN_SECONDS );

		if ( $throttle_window <= 0 || false === get_transient( $throttle_key ) ) {
			static::db()->query(
				static::db()->prepare(
					'UPDATE ' . static::table() . ' SET verification_count = verification_count + 1, last_verified_at = %s WHERE id = %d',
					now(),
					(int) $certificate->id
				)
			);
			static::invalidate_cache();
			if ( $throttle_window > 0 ) {
				set_transient( $throttle_key, 1, $throttle_window );
			}
			// Reflect the increment on the returned row without a second SELECT.
			$certificate->verification_count = (int) $certificate->verification_count + 1;
			$certificate->last_verified_at   = now();
		}

		return $certificate;
	}

	/**
	 * Recently-issued certificate IDs, newest first. Used by Pro
	 * Certificates' Credly sync cron to walk the recent window without
	 * dragging the whole table.
	 */
	public static function recent_ids_since( string $since, int $limit = 100 ): array {
		$ids = static::db()->get_col(
			static::db()->prepare(
				'SELECT id FROM ' . static::table()
					. ' WHERE issued_at >= %s ORDER BY id DESC LIMIT %d',
				$since,
				$limit
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Certificates that have a non-null expires_at in the past but whose
	 * status has not yet been set to 'expired'. Used by the daily cron job
	 * (F-15) so the handler can flip the status and fire the action.
	 *
	 * Returns only the columns the cron handler needs to minimise data transfer.
	 */
	public static function get_newly_expired(): array {
		return static::db()->get_results(
			'SELECT id, user_id, course_id FROM ' . static::table()
				. " WHERE expires_at IS NOT NULL AND expires_at < NOW() AND status != 'expired'"
		) ?: [];
	}

	/** Count of active (non-revoked) certificates. */
	public static function count_active(): int {
		return (int) static::db()->get_var(
			'SELECT COUNT(*) FROM ' . static::table() . " WHERE status = 'active'"
		);
	}

	/**
	 * Returns IDs of certificate rows whose user_id no longer exists in wp_users.
	 *
	 * Used by the orphan-cleanup CLI command and User_Cleanup_Service to find
	 * certificates left behind when a WP user was deleted before the
	 * `deleted_user` hook was in place (CF-005 / F-DB-01).
	 *
	 * This is a cross-table query that must live in the Model layer — raw
	 * $wpdb is allowed inside includes/models/ per architecture rule F-1.
	 *
	 * @return int[] Certificate row IDs that have no matching WP user.
	 */
	public static function get_orphan_ids(): array {
		$certs_table = static::table();
		$users_table = static::db()->users;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = static::db()->get_col(
			"SELECT c.id FROM {$certs_table} c
			 LEFT JOIN {$users_table} u ON u.ID = c.user_id
			 WHERE u.ID IS NULL"
		);

		return array_map( 'intval', (array) $ids );
	}
}
