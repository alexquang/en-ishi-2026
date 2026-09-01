<?php
/**
 * Instructor commissions — earned amounts awaiting or fulfilled in withdrawals.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use Learnomy\DB\Relations;

use function Learnomy\now;

class Commission extends Model {

	/**
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		// KEEP: a commission is an accounting record. Deleting the product
		// it came from must not rewrite what an instructor was paid.
		'transaction' => array(
			'column'    => 'transaction_id',
			'on_delete' => Relations::KEEP,
		),
	);

	protected static function table_name(): string {
		return 'commissions';
	}

	/**
	 * Payout statuses meaning the money is no longer earned.
	 *
	 * Two code paths void a commission and they use different words for it.
	 * Free's own reversal ({@see self::reverse_for_transaction()}) writes
	 * `reversed`; Pro's Stripe refund handler writes `refunded`, described in
	 * its own docblock as "a new state outside the pending|requested|paid
	 * enum". Nine aggregate queries in this class each spelled the exclusion
	 * inline, comparing payout_status against the single literal 'reversed',
	 * so none of them learned about the second word and every one of them kept
	 * counting refunded money as earned. Measured on a live instructor:
	 * refunding a $34.30 commission moved lifetime earnings by $0.00 while
	 * "Available to Withdraw" dropped correctly, so one screen reported two
	 * different balances (Basecamp 10194302253).
	 *
	 * The list lives here so there is one answer to "does this row still
	 * count", and it is filterable so the next payout integration adds its
	 * terminal state here instead of silently inheriting a wrong total.
	 *
	 * Note what is NOT in this list: `requested` and `paid` are still earned
	 * money — they describe where the money went, not that it evaporated.
	 *
	 * @return string[]
	 */
	public static function void_payout_statuses(): array {
		/**
		 * Filters the payout statuses that exclude a commission from earnings.
		 *
		 * @param string[] $statuses Terminal statuses meaning "not earned".
		 */
		$statuses = (array) apply_filters(
			'learnomy_commission_void_payout_statuses',
			array( 'reversed', 'refunded' )
		);

		return array_values( array_unique( array_filter( array_map( 'strval', $statuses ) ) ) );
	}

	/**
	 * SQL predicate excluding voided commissions, for use inside aggregates.
	 *
	 * Returns a complete boolean expression (never an empty string), so a
	 * filter that empties the list cannot silently drop the WHERE clause and
	 * change every total on the site.
	 *
	 * @param string $alias Optional table alias, without the trailing dot.
	 */
	protected static function not_void_sql( string $alias = '' ): string {
		$column   = ( '' !== $alias ? $alias . '.' : '' ) . 'payout_status';
		$statuses = static::void_payout_statuses();

		if ( empty( $statuses ) ) {
			return '1=1';
		}

		$quoted = array_map(
			static fn( string $status ): string => "'" . esc_sql( $status ) . "'",
			$statuses
		);

		return $column . ' NOT IN (' . implode( ',', $quoted ) . ')';
	}

	public static function create( array $commission ): int {
		$commission = array_merge( [ 'created_at' => now() ], $commission );

		// Stamp the payout period (YYYY-MM) from the commission date when the
		// caller didn't set one, so period-grouped payout aggregates +
		// reporting have a value to bucket by. Derived from created_at so
		// backdated rows land in the right month.
		if ( empty( $commission['payout_period'] ) ) {
			$commission['payout_period'] = gmdate( 'Y-m', strtotime( (string) $commission['created_at'] ) );
		}

		return static::insert( $commission );
	}

	public static function get_for_instructor( int $recipient_id, int $limit = 50, int $offset = 0 ): array {
		return static::where( [ 'recipient_id' => $recipient_id ], 'created_at', 'DESC', $limit, $offset );
	}

	public static function count_for_instructor( int $recipient_id ): int {
		return static::count( [ 'recipient_id' => $recipient_id ] );
	}

	/**
	 * Earnings rollup for ONE instructor, computed as SQL SUMs over EVERY
	 * commission row (no row cap). Powers the abilities `get_earnings` reply.
	 *
	 * Buckets: `paid` = rows with payout_status 'paid', `pending` = everything
	 * that is neither paid nor voided.
	 *
	 * Voided rows are excluded from all three figures. This method previously
	 * had no payout_status filter at all, which made it the worst affected by
	 * the split described on {@see self::void_payout_statuses()}: a refunded
	 * commission was counted as earned AND, because the pending bucket was
	 * merely "not paid", counted a second time as money still owed. Both are
	 * money the instructor will never receive.
	 *
	 * @return array{total_earned:float,paid:float,pending:float,count:int}
	 */
	public static function earnings_summary_for_instructor( int $recipient_id ): array {
		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT
					COALESCE(SUM(amount), 0) AS total_earned,
					COALESCE(SUM(CASE WHEN payout_status = %s THEN amount ELSE 0 END), 0) AS paid,
					COALESCE(SUM(CASE WHEN payout_status <> %s THEN amount ELSE 0 END), 0) AS pending,
					COUNT(*) AS commission_count
				 FROM ' . static::table() . ' WHERE recipient_id = %d AND ' . static::not_void_sql(),
				'paid',
				'paid',
				$recipient_id
			)
		);

		return array(
			'total_earned' => (float) ( $row->total_earned ?? 0 ),
			'paid'         => (float) ( $row->paid ?? 0 ),
			'pending'      => (float) ( $row->pending ?? 0 ),
			'count'        => (int) ( $row->commission_count ?? 0 ),
		);
	}

	/**
	 * Lifetime + pending instructor earnings for a SET of recipients, in one
	 * grouped query. Powers the admin Instructors list so a page of N rows costs
	 * one query instead of N (no per-row aggregation). Recipients with no
	 * commission rows are simply absent from the map (caller defaults to 0).
	 *
	 * @param int[] $recipient_ids
	 * @return array<int,array{earned:float,pending:float}> keyed by recipient_id.
	 */
	public static function earnings_for_recipients( array $recipient_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $recipient_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT recipient_id,
					COALESCE(SUM(amount), 0) AS earned,
					COALESCE(SUM(CASE WHEN payout_status = %s THEN amount ELSE 0 END), 0) AS pending
				 FROM ' . static::table() . "
				 WHERE recipient_type = 'instructor' AND " . static::not_void_sql() . " AND recipient_id IN ({$placeholders})
				 GROUP BY recipient_id",
				'pending',
				...$ids
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->recipient_id ] = array(
				'earned'  => (float) $row->earned,
				'pending' => (float) $row->pending,
			);
		}
		return $map;
	}

	/** Only 'pending' commissions are eligible to include in a withdrawal request. */
	public static function get_pending_for_instructor( int $recipient_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE recipient_id = %d AND payout_status = 'pending' ORDER BY created_at ASC",
				$recipient_id
			)
		);
	}

	public static function get_for_period( int $recipient_id, string $from, string $to ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE recipient_id = %d AND created_at BETWEEN %s AND %s ORDER BY created_at ASC',
				$recipient_id,
				$from,
				$to
			)
		);
	}

	/**
	 * Conditional batch-mark: only transitions rows still in `pending` state.
	 *
	 * Returns the number of rows actually updated, so callers can detect
	 * concurrent withdrawal attempts — if rows_affected < count($ids), another
	 * request has already claimed at least one of the commissions and the
	 * caller should roll its own state back (delete the withdrawal row).
	 *
	 * Basecamp #9928228985 (TOCTOU withdraw race) — the per-row
	 * `payout_status = 'pending'` guard turns the second concurrent caller's
	 * UPDATE into a 0-row no-op, which is the atomicity signal we need.
	 */
	public static function mark_requested_if_pending( array $ids, int $withdrawal_id = 0 ): int {
		if ( empty( $ids ) ) {
			return 0;
		}

		// Stamp the owning withdrawal so reject/approve can later target exactly
		// these rows (revert to pending on reject, mark paid on approve) without
		// touching commissions claimed by a different outstanding request.
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = static::db()->prepare(
			'UPDATE ' . static::table() . " SET payout_status = 'requested', requested_at = %s, withdrawal_id = %d WHERE payout_status = 'pending' AND id IN ({$placeholders})",
			array_merge( [ now(), $withdrawal_id ], $ids )
		);

		$ok = static::db()->query( $sql );
		static::invalidate_cache();
		if ( false === $ok ) {
			return 0;
		}

		return (int) static::db()->rows_affected;
	}

	/**
	 * Mark every commission claimed by a withdrawal as paid. Called when an
	 * admin approves/processes a manual withdrawal so the underlying commissions
	 * leave the `requested` limbo and land in `paid` (Free-only sites had no
	 * caller for this — only Pro's auto-payout marked commissions paid).
	 */
	public static function mark_paid_for_withdrawal( int $withdrawal_id ): bool {
		if ( $withdrawal_id <= 0 ) {
			return false;
		}

		$sql = static::db()->prepare(
			'UPDATE ' . static::table() . " SET payout_status = 'paid', paid_at = %s WHERE withdrawal_id = %d AND payout_status = 'requested'",
			now(),
			$withdrawal_id
		);

		$ok = false !== static::db()->query( $sql );
		static::invalidate_cache();
		return $ok;
	}

	/**
	 * Return every commission claimed by a withdrawal to `pending` so the
	 * instructor's available balance (which counts only `pending`) is restored.
	 * Called when an admin rejects a manual withdrawal. Clears the withdrawal
	 * link and the requested_at stamp.
	 */
	public static function revert_requested_to_pending( int $withdrawal_id ): bool {
		if ( $withdrawal_id <= 0 ) {
			return false;
		}

		$sql = static::db()->prepare(
			'UPDATE ' . static::table() . " SET payout_status = 'pending', requested_at = NULL, withdrawal_id = 0 WHERE withdrawal_id = %d AND payout_status = 'requested'",
			$withdrawal_id
		);

		$ok = false !== static::db()->query( $sql );
		static::invalidate_cache();
		return $ok;
	}

	/** Total commissions earned by an instructor (all time; excludes refund-reversed rows). */
	public static function sum_for_instructor( int $user_id ): float {
		return (float) static::db()->get_var(
			static::db()->prepare(
				'SELECT COALESCE(SUM(amount), 0) FROM ' . static::table() . " WHERE recipient_id = %d AND recipient_type = 'instructor' AND " . static::not_void_sql() . '',
				$user_id
			)
		);
	}

	/**
	 * Total owed (not yet requested/paid) commissions for an instructor.
	 *
	 * Filters payout_status='pending' — the actual "owed" state in the
	 * commission lifecycle (insert→pending, request→requested, pay→paid).
	 * The old 'unpaid' value this method used to query is never written by
	 * any code path, so it always returned 0 (the J-04.6 root + the Stripe
	 * payout pipeline breakage). Kept as an alias of the pending sum so
	 * existing callers stay correct.
	 */
	public static function sum_unpaid_for_instructor( int $user_id ): float {
		return static::sum_pending_for_instructor( $user_id );
	}

	/**
	 * Total commissions still awaiting payout (default state on insert).
	 * Separate from {@see self::sum_unpaid_for_instructor()} because
	 * `payout_status` has multiple pre-payout values (pending, requested).
	 */
	public static function sum_pending_for_instructor( int $user_id ): float {
		return (float) static::db()->get_var(
			static::db()->prepare(
				'SELECT COALESCE(SUM(amount), 0) FROM ' . static::table() . " WHERE recipient_id = %d AND recipient_type = 'instructor' AND payout_status = 'pending'",
				$user_id
			)
		);
	}

	/**
	 * Commissions grouped by YYYY-MM from $since onwards. Returns a map of
	 * month → total so callers can render 12-month history without pulling
	 * every row.
	 *
	 * @return array<string,float>
	 */
	public static function monthly_totals_for_instructor( int $user_id, string $since ): array {
		$rows = static::db()->get_results(
			static::db()->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS month, SUM(amount) AS total
				 FROM " . static::table() . "
				 WHERE recipient_id = %d AND recipient_type = 'instructor' AND " . static::not_void_sql() . ' AND created_at >= %s
				 GROUP BY month',
				$user_id,
				$since
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->month ] = (float) $row->total;
		}
		return $out;
	}

	/** Total commissions earned by an instructor since a given date. */
	public static function sum_for_instructor_since( int $user_id, string $since ): float {
		return (float) static::db()->get_var(
			static::db()->prepare(
				'SELECT COALESCE(SUM(amount), 0) FROM ' . static::table() . " WHERE recipient_id = %d AND recipient_type = 'instructor' AND " . static::not_void_sql() . ' AND created_at >= %s',
				$user_id,
				$since
			)
		);
	}

	/**
	 * Group unpaid instructor commissions by recipient for the given
	 * period, returning only rows whose summed amount clears the
	 * minimum-payout threshold. Used by Stripe Connect's payout
	 * processor — pre-filters in SQL so the PHP loop only walks
	 * instructors who actually qualify.
	 *
	 * Each row carries: recipient_id, total_amount, commission_ids (CSV).
	 */
	public static function aggregate_unpaid_by_instructor( string $period, float $minimum ): array {
		// Owed commissions = payout_status 'pending' (the lifecycle's actual
		// owed state). The old query filtered 'unpaid' — a status nothing
		// ever sets — so payouts always aggregated to 0 (J-PRO-STRIPE.1).
		// Sweep everything owed THROUGH the given period, including
		// per-transaction commissions whose payout_period is NULL, so a
		// monthly run pays out the full owed balance rather than only rows
		// stamped with the exact current month.
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT recipient_id AS instructor_id,'
					. ' SUM(amount) AS total_amount,'
					. ' GROUP_CONCAT(id) AS commission_ids'
					. ' FROM ' . static::table()
					. " WHERE recipient_type = 'instructor'"
					. ' AND ( payout_period IS NULL OR payout_period <= %s )'
					. " AND payout_status = 'pending'"
					. ' GROUP BY recipient_id'
					. ' HAVING total_amount >= %f',
				$period,
				$minimum
			)
		) ?: [];
	}

	/**
	 * Daily commission totals for a single instructor over the last
	 * $days — used by the instructor-dashboard "Revenue Trend" widget
	 * to render a tiny CSS bar chart without enqueuing a charting library.
	 *
	 * The payout_status filter is omitted on purpose: an instructor wants
	 * to see "earned today" not "approved today", and the indexed lookup
	 * stays cheap.
	 *
	 * @return array<string, float> Map keyed by `Y-m-d` UTC date string.
	 *                              Sparse — the caller pads missing days
	 *                              with zero to keep the chart dense.
	 */
	public static function daily_trend_for_instructor( int $instructor_id, int $days = 30 ): array {
		if ( $instructor_id <= 0 || $days <= 0 ) {
			return array();
		}
		$cutoff = gmdate( 'Y-m-d 00:00:00', time() - ( $days * DAY_IN_SECONDS ) );
		$rows   = static::db()->get_results(
			static::db()->prepare(
				'SELECT DATE(created_at) AS day, SUM(amount) AS total
				 FROM ' . static::table() . "
				 WHERE recipient_type = 'instructor' AND " . static::not_void_sql() . '
				   AND recipient_id = %d
				   AND amount > 0
				   AND created_at >= %s
				 GROUP BY day
				 ORDER BY day ASC',
				$instructor_id,
				$cutoff
			)
		);
		$out    = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->day ] = (float) $row->total;
		}
		return $out;
	}

	/**
	 * Top earning instructors sitewide. Sums every commission row marked
	 * for an instructor recipient (regardless of payout_status) so a
	 * brand-new payout doesn't drop the instructor off the leaderboard.
	 *
	 * Used by the admin dashboard "Top Instructors" widget.
	 *
	 * @return object[] Rows with instructor_id, total_amount.
	 */
	public static function top_instructors( int $limit = 5 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT recipient_id AS instructor_id, SUM(amount) AS total_amount
				 FROM ' . static::table() . "
				 WHERE recipient_type = 'instructor' AND amount > 0 AND " . static::not_void_sql() . '
				 GROUP BY recipient_id
				 ORDER BY total_amount DESC
				 LIMIT %d',
				$limit
			)
		) ?: array();
	}

	public static function mark_paid( array $ids ): bool {
		if ( empty( $ids ) ) {
			return true;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = static::db()->prepare(
			'UPDATE ' . static::table() . " SET payout_status = 'paid', paid_at = %s WHERE id IN ({$placeholders})",
			array_merge( [ now() ], $ids )
		);

		$ok = false !== static::db()->query( $sql );
		static::invalidate_cache();
		return $ok;
	}

	/** Instructor earnings export: per-instructor lifetime, this-month, last-month sums. */
	public static function get_instructor_earnings_export( string $this_month, string $last_month ): array {
		return static::db()->get_results(
			static::db()->prepare(
				"SELECT recipient_id,
				        SUM(amount)                                              AS lifetime,
				        SUM(CASE WHEN DATE_FORMAT(created_at,'%%Y-%%m') = %s THEN amount ELSE 0 END) AS this_month,
				        SUM(CASE WHEN DATE_FORMAT(created_at,'%%Y-%%m') = %s THEN amount ELSE 0 END) AS last_month
				 FROM " . static::table() . "
				 WHERE recipient_type = 'instructor' AND " . static::not_void_sql() . '
				 GROUP BY recipient_id
				 ORDER BY lifetime DESC',
				$this_month,
				$last_month
			)
		) ?: [];
	}

	/**
	 * All commission rows for a given transaction, ordered by id ASC.
	 *
	 * Covers both platform and instructor splits so callers can verify the
	 * full split breakdown against a transaction amount in one query.
	 */
	public static function get_for_transaction( int $transaction_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE transaction_id = %d ORDER BY id ASC',
				$transaction_id
			)
		) ?: [];
	}

	/**
	 * Platform + instructor split totals for a SET of transactions, in one
	 * grouped query (no N+1 in the admin Recent Transactions table). The split
	 * lives in `lrn_commissions` rows (recipient_type platform/instructor) — it
	 * was never a column on `lrn_transactions`, which is why the earnings view's
	 * `$txn->platform_amount` always rendered $0. Refund-reversed rows are
	 * excluded so a refunded sale correctly shows a $0 split.
	 *
	 * @param int[] $transaction_ids
	 * @return array<int,array{platform:float,instructor:float}> keyed by transaction_id.
	 */
	public static function split_for_transactions( array $transaction_ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $transaction_ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT transaction_id, recipient_type, COALESCE(SUM(amount), 0) AS total
				 FROM ' . static::table() . ' WHERE ' . static::not_void_sql() . " AND transaction_id IN ({$placeholders})
				 GROUP BY transaction_id, recipient_type",
				...$ids
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$tid = (int) $row->transaction_id;
			if ( ! isset( $map[ $tid ] ) ) {
				$map[ $tid ] = array(
					'platform'   => 0.0,
					'instructor' => 0.0,
				);
			}
			if ( 'platform' === $row->recipient_type ) {
				$map[ $tid ]['platform'] = (float) $row->total;
			} elseif ( 'instructor' === $row->recipient_type ) {
				$map[ $tid ]['instructor'] = (float) $row->total;
			}
		}
		return $map;
	}

	/**
	 * Void every not-yet-paid commission tied to a refunded transaction.
	 *
	 * Called from {@see \Learnomy\Services\Refund_Service::apply()} — the
	 * gateway-agnostic refund chokepoint — so a refund on ANY provider
	 * (stripe / paypal / woocommerce / test) reverses the instructor's
	 * commission, not just Stripe Connect. Transitions `pending` and
	 * `requested` rows to `reversed`; `paid` rows are intentionally left
	 * alone — money has already been transferred, so Pro's Stripe-Connect
	 * `Refund_Handler` claws those back via `lrn_pro_payout_debts`.
	 *
	 * `reversed` is a terminal state: it is excluded from every withdrawable
	 * balance (which only counts `pending`) and from lifetime-earned rollups,
	 * while preserving the row as an audit trail of the voided earning.
	 *
	 * @param int $transaction_id The refunded (original) transaction id.
	 * @return int Rows reversed.
	 */
	public static function reverse_for_transaction( int $transaction_id ): int {
		if ( $transaction_id <= 0 ) {
			return 0;
		}

		$ok = static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . " SET payout_status = 'reversed'"
					. " WHERE transaction_id = %d AND payout_status IN ( 'pending', 'requested' )",
				$transaction_id
			)
		);
		static::invalidate_cache();

		return false === $ok ? 0 : (int) static::db()->rows_affected;
	}

	/**
	 * All commission rows whose `payout_period` matches the given YYYY-MM string.
	 *
	 * Used by the engagement-split CLI scenario to inspect every commission
	 * row written for a given period, regardless of recipient.
	 */
	public static function get_for_payout_period( string $period ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE payout_period = %s ORDER BY id ASC',
				$period
			)
		) ?: [];
	}

	/**
	 * Delete every commission row associated with a transaction.
	 *
	 * Called by the CLI fixture-builder teardown so commission rows seeded
	 * during a test scenario are removed before their parent transaction row
	 * is deleted — preserving referential integrity without FK cascades.
	 */
	public static function delete_for_transaction( int $transaction_id ): bool {
		$ok = false !== static::db()->delete( static::table(), [ 'transaction_id' => $transaction_id ] );
		static::invalidate_cache();
		return $ok;
	}

	/**
	 * Commission rows whose parent transaction no longer exists.
	 *
	 * Engagement splits carry `transaction_id = 0` by design (they settle a
	 * period, not a sale), so they are excluded rather than reported as orphans.
	 * Rows like these predate the fixture teardown that deletes commissions
	 * before their transaction; the `wp learnomy cleanup orphan-commissions`
	 * task clears them.
	 *
	 * @return int[]
	 */
	public static function get_orphan_ids(): array {
		$commissions_table  = static::table();
		$transactions_table = static::db()->prefix . 'lrn_transactions';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = static::db()->get_col(
			"SELECT cm.id FROM {$commissions_table} cm
			 LEFT JOIN {$transactions_table} t ON t.id = cm.transaction_id
			 WHERE cm.transaction_id <> 0 AND t.id IS NULL"
		);

		return array_map( 'intval', (array) $ids );
	}

	/*
	 * There is deliberately no delete-by-payout-period method here.
	 *
	 * One existed, documented as removing "synthetic engagement commissions
	 * inserted during the test run" — but it deleted every commission row in the
	 * period, synthetic or not. Its only caller was the CLI engagement scenario's
	 * teardown, so `wp learnomy test` erased that month's instructor earnings on
	 * whatever install it ran against. The scenario now deletes the rows it
	 * created, by ID.
	 *
	 * A payout period is a settlement boundary, not a scratch namespace. If a
	 * future caller genuinely needs to clear one, it needs an explicit
	 * confirmation path and an audit-log entry — not a bare model helper that
	 * reads as harmless at the call site.
	 */
}
