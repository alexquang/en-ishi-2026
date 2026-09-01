<?php
/**
 * Notification — in-app alerts delivered to individual users.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Notification extends Model {

	protected static function table_name(): string {
		return 'notifications';
	}

	public static function create( array $notification ): int {
		return static::insert(
			array_merge(
				[
					'is_read'    => 0,
					'created_at' => now(),
				],
				$notification
			)
		);
	}

	/**
	 * Human label for a notification `type` slug, for admin surfaces like the
	 * Email Log where a raw machine slug (`instructor_new_enrollment`) tells a
	 * site owner nothing. Known slugs map to a curated phrase; anything else
	 * falls back to a title-cased humanisation so a NEW type never leaks a raw
	 * slug into the UI. Extend/override with `learnomy_notification_type_label`.
	 *
	 * @param string $type Raw type slug.
	 */
	public static function type_label( string $type ): string {
		$type = trim( $type );
		if ( '' === $type ) {
			return '—';
		}

		$map = array(
			'enrollment'                => __( 'Enrollment', 'learnomy' ),
			'instructor_new_enrollment' => __( 'New enrollment (instructor)', 'learnomy' ),
			'course_completed'          => __( 'Course completed', 'learnomy' ),
			'lesson'                    => __( 'Lesson update', 'learnomy' ),
			'quiz_result'               => __( 'Quiz result', 'learnomy' ),
			'quiz_attempt'              => __( 'Quiz attempt', 'learnomy' ),
			'certificate'               => __( 'Certificate issued', 'learnomy' ),
			'review'                    => __( 'Course review', 'learnomy' ),
			'transaction'               => __( 'Payment', 'learnomy' ),
			'refund'                    => __( 'Refund', 'learnomy' ),
			'membership'                => __( 'Membership', 'learnomy' ),
			'withdrawal'                => __( 'Withdrawal', 'learnomy' ),
			'instructor_application'    => __( 'Instructor application', 'learnomy' ),
			'announcement'              => __( 'Announcement', 'learnomy' ),
			'learnomy'                  => __( 'System', 'learnomy' ),
		);

		$label = $map[ $type ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $type ) );

		/**
		 * Filter the human label for a notification type slug.
		 *
		 * @param string $label Resolved label.
		 * @param string $type  Raw type slug.
		 */
		return (string) apply_filters( 'learnomy_notification_type_label', $label, $type );
	}

	public static function get_unread_for_user( int $user_id, int $limit = 20, int $offset = 0 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d AND is_read = 0 ORDER BY created_at DESC LIMIT %d OFFSET %d',
				$user_id,
				max( 1, $limit ),
				max( 0, $offset )
			)
		) ?: [];
	}

	/**
	 * Mark a single notification read, scoped to its recipient.
	 *
	 * Only the owning user may mark their own notification read. Returns false
	 * when the row does not exist or belongs to someone else, so the caller can
	 * answer 404 instead of silently mutating another user's data. Idempotent —
	 * an already-read notification still returns true for the owner.
	 */
	public static function mark_read( int $id, int $user_id ): bool {
		$owns = (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE id = %d AND user_id = %d',
				$id,
				$user_id
			)
		);

		if ( ! $owns ) {
			return false;
		}

		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET is_read = 1 WHERE id = %d AND user_id = %d',
				$id,
				$user_id
			)
		);
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();

		return true;
	}

	public static function mark_all_read( int $user_id ): void {
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET is_read = 1 WHERE user_id = %d AND is_read = 0',
				$user_id
			)
		);
		static::invalidate_cache();
	}

	/** Returns distinct announcements, newest first. */
	public static function get_announcements( int $limit = 50 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT DISTINCT title, message, created_at FROM ' . static::table() . " WHERE type = 'announcement' ORDER BY created_at DESC LIMIT %d",
				$limit
			)
		) ?: [];
	}

	/**
	 * Announcements authored by a specific instructor (actor_id), distinct
	 * by (title, message, created_at) so one announcement fanned out to N
	 * recipients counts once.
	 *
	 * Powers the instructor-dashboard compose widget's "recently sent"
	 * list. Returns lean projection — the partial just needs the headline.
	 *
	 * @return object[] Rows with title, message, object_type, object_id,
	 *                  created_at, recipients (count).
	 */
	public static function sent_by_actor( int $actor_id, int $limit = 3 ): array {
		if ( $actor_id <= 0 ) {
			return array();
		}
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT title, message, object_type, object_id, created_at,
				        COUNT(*) AS recipients
				 FROM ' . static::table() . "
				 WHERE type = 'announcement' AND actor_id = %d
				 GROUP BY title, message, object_type, object_id, created_at
				 ORDER BY created_at DESC
				 LIMIT %d",
				$actor_id,
				$limit
			)
		) ?: array();
	}

	/**
	 * Announcements authored by an actor scoped to a specific object —
	 * e.g. "announcements I sent to members of THIS space" or "to
	 * students of THIS course". Honors the instructor / space-owner
	 * boundary: each persona sees broadcasts inside their own scope,
	 * never the global outbox.
	 *
	 * @return object[] Same shape as sent_by_actor (with recipients count).
	 */
	public static function sent_by_actor_for_object(
		int $actor_id,
		string $object_type,
		int $object_id,
		int $limit = 3
	): array {
		if ( $actor_id <= 0 || '' === $object_type || $object_id <= 0 ) {
			return array();
		}
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT title, message, object_type, object_id, created_at,
				        COUNT(*) AS recipients
				 FROM ' . static::table() . "
				 WHERE type = 'announcement'
				   AND actor_id = %d
				   AND object_type = %s
				   AND object_id = %d
				 GROUP BY title, message, object_type, object_id, created_at
				 ORDER BY created_at DESC
				 LIMIT %d",
				$actor_id,
				$object_type,
				$object_id,
				$limit
			)
		) ?: array();
	}

	/** Returns past announcements with object_type/object_id for admin display. */
	/**
	 * Counts of notifications created today + past 7 days, plus the
	 * top notification type in the 7-day window. Used by the admin
	 * dashboard "Email Log Summary" widget.
	 *
	 * "Today" is the operator's local calendar day and "7 days" is a rolling
	 * window; both boundaries are converted to UTC for the query because
	 * created_at is stored in UTC. Comparing a site-local-offset string against a
	 * UTC column skews the counts by the site offset on any non-UTC install.
	 *
	 * @return array{today:int,week:int,top_type:string,top_count:int}
	 */
	public static function activity_summary(): array {
		$today    = get_gmt_from_date( wp_date( 'Y-m-d 00:00:00' ) );
		$week_ago = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

		$today_count = (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE created_at >= %s',
				$today
			)
		);
		$week_count  = (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE created_at >= %s',
				$week_ago
			)
		);
		$top_row     = static::db()->get_row(
			static::db()->prepare(
				'SELECT type, COUNT(*) AS cnt
				 FROM ' . static::table() . '
				 WHERE created_at >= %s
				 GROUP BY type
				 ORDER BY cnt DESC
				 LIMIT 1',
				$week_ago
			)
		);
		return array(
			'today'     => $today_count,
			'week'      => $week_count,
			'top_type'  => $top_row ? (string) $top_row->type : '',
			'top_count' => $top_row ? (int) $top_row->cnt : 0,
		);
	}

	public static function get_past_announcements( int $limit = 50, int $offset = 0 ): array {
		$offset = max( 0, $offset );
		$limit  = max( 1, $limit );

		return static::db()->get_results(
			static::db()->prepare(
				'SELECT DISTINCT title, message, object_type, object_id, created_at
				 FROM ' . static::table() . "
				 WHERE type = 'announcement'
				 GROUP BY title, message, object_id, created_at
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		) ?: [];
	}

	/**
	 * Count distinct past announcements (matches get_past_announcements row count).
	 */
	public static function count_past_announcements(): int {
		return (int) static::db()->get_var(
			'SELECT COUNT(*) FROM (
				SELECT DISTINCT title, message, object_id, created_at
				FROM ' . static::table() . "
				WHERE type = 'announcement'
				GROUP BY title, message, object_id, created_at
			) AS a"
		);
	}

	/**
	 * Delete a sent announcement broadcast — every per-recipient notification
	 * row that shares the broadcast's identifying tuple. A broadcast has no
	 * single id (one row per recipient), so it is keyed by the same fields
	 * get_past_announcements() groups on. Returns the number of rows removed.
	 *
	 * @param string $created_at The broadcast timestamp (shared across recipients).
	 * @param string $title      The announcement title.
	 * @param int    $object_id  Course id for a course-scoped announcement, else 0.
	 */
	/**
	 * Resolve a broadcast's id from the tuple an older caller still sends.
	 *
	 * Lets a client that has not learned about `broadcast_id` still get the
	 * reliable delete: the tuple picks any one row of the fan-out, and that
	 * row's id then covers every sibling — including the ones whose timestamps
	 * drifted, which the tuple itself could never reach.
	 */
	public static function broadcast_id_for( string $created_at, string $title, int $object_id = 0 ): string {
		if ( '' === $created_at || '' === $title ) {
			return '';
		}

		return (string) static::db()->get_var(
			static::db()->prepare(
				'SELECT broadcast_id FROM ' . static::table() . "
				 WHERE type = 'announcement' AND created_at = %s AND title = %s AND object_id = %d
				   AND broadcast_id <> '' LIMIT 1",
				$created_at,
				$title,
				$object_id
			)
		);
	}

	public static function delete_announcement( string $created_at, string $title, int $object_id = 0, string $broadcast_id = '' ): int {
		if ( '' === $created_at || '' === $title ) {
			return 0;
		}

		// Prefer the broadcast's own id. The (created_at, title, object_id)
		// tuple only identifies a fan-out while every recipient row shares one
		// timestamp - true since the compose-time pin, but never true for rows
		// written before it, which is why recall historically reached a subset
		// (Basecamp 10132809203). An id is unique by construction and cannot
		// drift, so when one is present it is the whole condition.
		if ( '' !== $broadcast_id ) {
			$deleted = (int) static::db()->query(
				static::db()->prepare(
					'DELETE FROM ' . static::table() . " WHERE type = 'announcement' AND broadcast_id = %s",
					$broadcast_id
				)
			);
			static::invalidate_cache();
			return $deleted;
		}

		$deleted = (int) static::db()->query(
			static::db()->prepare(
				'DELETE FROM ' . static::table() . "
				 WHERE type = 'announcement' AND created_at = %s AND title = %s AND object_id = %d",
				$created_at,
				$title,
				$object_id
			)
		);
		static::invalidate_cache();
		return $deleted;
	}

	public static function get_unread_count( int $user_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE user_id = %d AND is_read = 0',
				$user_id
			)
		);
	}

	/**
	 * Has a notification of exactly this (user, type, object_type, object_id)
	 * already been written? Used by the reminder cron (PR-E) to dedup the
	 * 7/3/1-day cadence passes so the same user never gets the same reminder
	 * twice. Cheap at scale thanks to the compound index
	 * `user_type_object (user_id, type, object_type, object_id)` added by
	 * Migration 2.5.0.
	 *
	 * @param int      $user_id Recipient.
	 * @param string   $type Notification type slug.
	 * @param string   $object_type Related object type.
	 * @param int      $object_id Related object id.
	 * @param int|null $since_days Optional — when set, only counts rows
	 *                             written in the last N days. Useful when a
	 *                             callsite wants periodic re-sends (rare).
	 */
	public static function exists_for_object(
		int $user_id,
		string $type,
		string $object_type,
		int $object_id,
		?int $since_days = null
	): bool {
		if ( null !== $since_days && $since_days > 0 ) {
			$found = static::db()->get_var(
				static::db()->prepare(
					'SELECT id FROM ' . static::table() . '
					WHERE user_id = %d AND type = %s AND object_type = %s AND object_id = %d
					AND created_at >= DATE_SUB(%s, INTERVAL %d DAY)
					LIMIT 1',
					$user_id,
					$type,
					$object_type,
					$object_id,
					\Learnomy\now(),
					$since_days
				)
			);
		} else {
			$found = static::db()->get_var(
				static::db()->prepare(
					'SELECT id FROM ' . static::table() . '
					WHERE user_id = %d AND type = %s AND object_type = %s AND object_id = %d
					LIMIT 1',
					$user_id,
					$type,
					$object_type,
					$object_id
				)
			);
		}
		return ! empty( $found );
	}

	/**
	 * Paginated Email-Log search for the admin viewer.
	 *
	 * Powers the recipient + date + status filters on
	 * `?page=learnomy-email-log`. Always paginated (LIMIT/OFFSET) so the log
	 * stays usable on a site with hundreds of thousands of notification rows;
	 * pair with count_log() for the total. Ordered newest-first to match the
	 * unfiltered view.
	 *
	 * @param array{email_status?:string,type?:string,recipient?:string,date_from?:string,date_to?:string} $args   Filter set (all optional).
	 * @param int                                                                                          $limit  Page size (>=1).
	 * @param int                                                                                          $offset Row offset (>=0).
	 * @return object[]
	 */
	public static function search_log( array $args, int $limit = 25, int $offset = 0 ): array {
		list( $where, $values ) = self::build_log_filter( $args );

		$limit    = max( 1, $limit );
		$offset   = max( 0, $offset );
		$sql      = 'SELECT * FROM ' . static::table() . $where . ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
		$values[] = $limit;
		$values[] = $offset;

		// $values always carries at least LIMIT + OFFSET, so prepare() is safe.
		// The interpolated fragments are static::table() (internal) and the
		// filter's own %s/%d placeholders — never user input.
		return static::db()->get_results(
			static::db()->prepare( $sql, ...$values ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		) ?: array();
	}

	/**
	 * Total rows matching an Email-Log search — the COUNT(*) companion to
	 * search_log(), used to drive pagination without loading every row.
	 *
	 * @param array{email_status?:string,type?:string,recipient?:string,date_from?:string,date_to?:string} $args Filter set (all optional).
	 */
	public static function count_log( array $args ): int {
		list( $where, $values ) = self::build_log_filter( $args );

		$sql = 'SELECT COUNT(*) FROM ' . static::table() . $where;
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql = static::db()->prepare( $sql, ...$values );
		}

		return (int) static::db()->get_var( $sql );
	}

	/**
	 * Build the shared WHERE fragment + bound values for the Email-Log search,
	 * so search_log() and count_log() can never drift apart.
	 *
	 * Recipient search resolves the free-text term to a capped set of user IDs
	 * up front (one WP_User_Query) and filters on the indexed `user_id` column,
	 * rather than joining wp_users per row — the big-site-safe shape. A term
	 * matching no user returns the sentinel `WHERE 1 = 0` so the caller answers
	 * an empty page without scanning the table.
	 *
	 * @param array{email_status?:string,type?:string,recipient?:string,date_from?:string,date_to?:string} $args Filter set.
	 * @return array{0:string,1:array<int,int|string>} [ where_sql, values ].
	 */
	private static function build_log_filter( array $args ): array {
		$clauses = array();
		$values  = array();

		$status = isset( $args['email_status'] ) ? sanitize_key( (string) $args['email_status'] ) : '';
		if ( in_array( $status, array( 'sent', 'failed', 'skipped' ), true ) ) {
			$clauses[] = 'email_status = %s';
			$values[]  = $status;
		}

		// `type` is an OPEN set — every caller of Notification_Service::send()
		// picks its own slug, and Pro adds more — so it cannot be validated
		// against a whitelist the way email_status is. It is bound as %s
		// instead, and an unknown slug simply matches nothing. Leading column
		// of the type_created index, so this stays cheap at any table size.
		$type = isset( $args['type'] ) ? sanitize_key( (string) $args['type'] ) : '';
		if ( '' !== $type ) {
			$clauses[] = 'type = %s';
			$values[]  = $type;
		}

		$recipient = isset( $args['recipient'] ) ? trim( (string) $args['recipient'] ) : '';
		if ( '' !== $recipient ) {
			$ids = self::resolve_recipient_ids( $recipient );
			if ( empty( $ids ) ) {
				// No user matched — force an empty result set cheaply.
				return array( ' WHERE 1 = 0', array() );
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$clauses[]    = "user_id IN ({$placeholders})";
			foreach ( $ids as $id ) {
				$values[] = (int) $id;
			}
		}

		// Date range is inclusive on the calendar day: `date_to` is expanded to
		// end-of-day so a same-day from/to still captures that whole day.
		$date_from = isset( $args['date_from'] ) ? self::sanitize_log_date( (string) $args['date_from'] ) : '';
		if ( '' !== $date_from ) {
			$clauses[] = 'created_at >= %s';
			$values[]  = $date_from . ' 00:00:00';
		}
		$date_to = isset( $args['date_to'] ) ? self::sanitize_log_date( (string) $args['date_to'] ) : '';
		if ( '' !== $date_to ) {
			$clauses[] = 'created_at <= %s';
			$values[]  = $date_to . ' 23:59:59';
		}

		$where = $clauses ? ' WHERE ' . implode( ' AND ', $clauses ) : '';
		return array( $where, $values );
	}

	/**
	 * The notification types that actually exist in the log, for the Email-Log
	 * type filter.
	 *
	 * Read from the table rather than from a hardcoded list, because `type` is
	 * an open set: cron writes slugs the curated {@see type_label()} map has
	 * never heard of, and any extension can add more. A hardcoded dropdown
	 * would silently omit exactly the emails an owner is hunting for.
	 *
	 * Uncached on purpose. `type` is the leading column of the `type_created`
	 * index, so DISTINCT is a loose index scan over distinct values rather than
	 * a table scan, and this runs once per load of one admin screen. Caching it
	 * under the model's group would buy nothing: every notification write bumps
	 * the group incrementor (Rule 14), so on a site that sends email the entry
	 * would be orphaned before it was ever read twice.
	 *
	 * @return string[] Type slugs, alphabetical.
	 */
	public static function log_types(): array {
		$types = static::db()->get_col(
			'SELECT DISTINCT type FROM ' . static::table() . " WHERE type <> '' ORDER BY type ASC"
		);

		return array_values( array_filter( array_map( 'strval', (array) $types ) ) );
	}

	/**
	 * Delete notification rows older than the retention window, in bounded
	 * batches.
	 *
	 * Two reasons this exists, and both are about the rows nobody looks at:
	 * an email log that only ever grows is the classic big-site table (this
	 * one carries a row per email AND per in-app notification), and its rows
	 * hold personal data — recipient, subject and message body — so "keep
	 * everything forever" is not a defensible default under GDPR.
	 *
	 * Batched, unlike {@see \Learnomy\Models\AuditLog::prune()}: a first prune
	 * on a site that has been running for years can match hundreds of thousands
	 * of rows, and one unbounded DELETE would hold locks for the whole of it.
	 *
	 * @param int $days       Keep rows newer than this many days. <= 0 is a no-op.
	 * @param int $batch_size Rows per DELETE.
	 * @param int $max_batches Safety stop so one cron pass cannot run forever.
	 * @return int Rows deleted.
	 */
	public static function prune( int $days = 365, int $batch_size = 1000, int $max_batches = 50 ): int {
		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$deleted = 0;

		for ( $batch = 0; $batch < $max_batches; $batch++ ) {
			$rows = (int) static::db()->query(
				static::db()->prepare(
					'DELETE FROM ' . static::table() . ' WHERE created_at < %s ORDER BY created_at ASC LIMIT %d',
					$cutoff,
					max( 1, $batch_size )
				)
			);

			$deleted += $rows;

			if ( $rows < $batch_size ) {
				break;
			}
		}

		if ( $deleted > 0 ) {
			static::invalidate_cache();
		}

		return $deleted;
	}

	/**
	 * Resolve a recipient free-text term (email / name / login) to a bounded
	 * list of user IDs for the Email-Log filter. Capped at 200 so a broad term
	 * cannot grow the IN() clause without limit.
	 *
	 * @return int[]
	 */
	private static function resolve_recipient_ids( string $term ): array {
		$term = trim( $term );
		if ( '' === $term ) {
			return array();
		}

		$users = get_users(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
				'fields'         => 'ID',
				'number'         => 200,
			)
		);

		return array_map( 'intval', (array) $users );
	}

	/**
	 * Validate a `Y-m-d` date filter, returning '' for anything malformed so a
	 * bad value is ignored rather than injected. Strict round-trip check
	 * rejects impossible dates (e.g. 2026-13-40).
	 */
	private static function sanitize_log_date( string $date ): string {
		$date = trim( $date );
		if ( '' === $date ) {
			return '';
		}
		$parsed = \DateTime::createFromFormat( 'Y-m-d', $date );
		return ( $parsed && $parsed->format( 'Y-m-d' ) === $date ) ? $date : '';
	}

	/**
	 * Where a notification should take the reader.
	 *
	 * Derived at render from `object_type` + `object_id`, which the row already
	 * stores. No new column and no migration: the alternative was persisting a
	 * URL, which rots the moment a slug changes.
	 *
	 * The bell used to render NO link for ANY type — 24 items, zero anchors,
	 * with `cursor: pointer` on the row so it advertised itself as clickable and
	 * then only dimmed itself. Meanwhile `Notifier` resolves an `action_url` at
	 * 14+ call sites and `Notification_Service::send()` drops it on the floor,
	 * so the certificate EMAIL links correctly while the bell row for that same
	 * event does not (Basecamp 10171338941).
	 *
	 * One reader, so the bell, the REST payload and the mobile app agree.
	 * Returns '' when there is genuinely nowhere to go (a broadcast
	 * announcement), and the caller renders plain text rather than a dead link.
	 *
	 * @param object $notif Notification row.
	 * @return string Absolute URL, or '' when the row has no destination.
	 */
	public static function destination_url( object $notif ): string {
		$object_type = (string) ( $notif->object_type ?? '' );
		$object_id   = (int) ( $notif->object_id ?? 0 );

		if ( '' === $object_type || $object_id <= 0 ) {
			return '';
		}

		switch ( $object_type ) {
			case 'certificate':
				$cert = Certificate::find( $object_id );
				// A revoked certificate keeps its row but must not be offered
				// as a destination; see D.revocation-is-durable.
				if ( ! $cert || 'active' !== ( $cert->status ?? '' ) || empty( $cert->uuid ) ) {
					return '';
				}
				return (string) \Learnomy\route_url( 'certificate-verify', array( 'slug' => (string) $cert->uuid ) );

			case 'course':
				$course = Course::find( $object_id );
				return ( $course && ! empty( $course->slug ) )
					? (string) \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) )
					: '';

			case 'quiz_attempt':
				return (string) add_query_arg( 'attempt', $object_id, \Learnomy\route_url( 'account-quiz-results' ) );

			case 'transaction':
				return (string) \Learnomy\route_url( 'account-billing' );
		}

		return '';
	}
}
