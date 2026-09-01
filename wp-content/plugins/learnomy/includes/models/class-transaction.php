<?php
/**
 * Financial transactions — immutable ledger of every payment event.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Transaction extends Model {

	/**
	 * Every reference here is KEEP. A transaction is the record of money that
	 * moved, and it has to survive the course, plan, enrollment or coupon it
	 * refers to — an owner reconciling last quarter's revenue, or answering a
	 * chargeback, needs the row long after the product was retired. Readers
	 * must therefore tolerate a missing parent.
	 *
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'course'     => array(
			'column'    => 'course_id',
			'on_delete' => \Learnomy\DB\Relations::KEEP,
		),
		'plan'       => array(
			'column'    => 'plan_id',
			'on_delete' => \Learnomy\DB\Relations::KEEP,
		),
		'enrollment' => array(
			'column'    => 'enrollment_id',
			'on_delete' => \Learnomy\DB\Relations::KEEP,
		),
		'coupon'     => array(
			'column'    => 'coupon_id',
			'on_delete' => \Learnomy\DB\Relations::KEEP,
		),
	);


	protected static function table_name(): string {
		return 'transactions';
	}

	/**
	 * Cache key prefix for the three revenue aggregate methods.
	 * All keys share this prefix so a single delete_transient() call in
	 * record() can target them by prefix via the month-keyed variant; the
	 * all-time keys are invalidated on every write because a new completed
	 * transaction can only increase them.
	 */
	private const REVENUE_CACHE_GROUP = 'learnomy_transactions_revenue';

	/**
	 * The statuses that make up NET revenue.
	 *
	 * A refund writes a SECOND row (negative amount, `status='completed'`) and
	 * flips the ORIGINAL to `refunded` / `partially_refunded`. Summing
	 * `status='completed'` alone therefore subtracts every refund twice: the
	 * sale leaves the sum and its negative reversal stays in it. On a 49.00 sale
	 * with a 9.80 partial refund that reads -9.80 instead of 39.20
	 * (Basecamp 10167903396).
	 *
	 * Including all three makes `SUM(amount)` net by construction.
	 *
	 * Use it for TOTALS. Queries that deliberately want sales only already say
	 * so with `amount > 0` / `refund_of IS NULL`, and must keep saying it —
	 * `find_purchase_for()` and the refund-totals helper are not revenue sums.
	 */
	private const REVENUE_STATUSES = "( 'completed', 'refunded', 'partially_refunded' )";

	public static function record( array $transaction ): int {
		$id = static::insert( array_merge( [ 'created_at' => now() ], $transaction ) );

		// Invalidate all revenue caches. Total-revenue and summary are all-time
		// and always change on a new row; month-keyed cache is invalidated via
		// the month of the new row AND the running current-month key.
		if ( $id > 0 ) {
			wp_cache_delete( 'total_completed', self::REVENUE_CACHE_GROUP );
			wp_cache_delete( 'summary', self::REVENUE_CACHE_GROUP );
			// Month key for the newly recorded transaction.
			$ym = isset( $transaction['created_at'] )
				? substr( (string) $transaction['created_at'], 0, 7 )
				: gmdate( 'Y-m' );
			wp_cache_delete( 'month_' . $ym, self::REVENUE_CACHE_GROUP );
			// Also bust the current calendar month in case the row's created_at
			// differs from wall-clock (e.g. a test fixture backdated one second).
			wp_cache_delete( 'month_' . gmdate( 'Y-m' ), self::REVENUE_CACHE_GROUP );
		}

		return $id;
	}

	/**
	 * Override base delete() to cascade commission rows. The schema does
	 * not declare a MySQL FK between commissions.transaction_id and
	 * transactions.id, so every caller that hard-deletes a transaction
	 * must clear its commission children first to avoid orphan rows.
	 * Centralising that here covers fixture-builder, demo-seeder, refund
	 * cleanup, and any future caller.
	 *
	 * Transactions are immutable in normal payment flow; this path fires
	 * only for synthetic fixture rows and admin-tools cleanup.
	 */
	public static function delete( int $id ): bool {
		Commission::delete_for_transaction( $id );
		return parent::delete( $id );
	}

	/**
	 * SELL-02: the listed price of a simulated Test-gateway order.
	 *
	 * The Test adapter records simulated orders at $0 (no money is taken, so
	 * revenue and commission sums exclude them by value) and keeps the price
	 * the buyer was shown in metadata. The page-level "test payment mode"
	 * notice explains this while test mode is ON, but the rows outlive the
	 * notice — once a site goes live, a bare "$0.00 completed" on a priced
	 * course reads as lost revenue (Basecamp 10155593996 was filed from
	 * exactly that misreading). Every surface that renders a single
	 * transaction's amount uses this one check to annotate such rows.
	 *
	 * @param object $txn A transaction row (model read or raw list row).
	 * @return float|null Listed price at purchase when the row is a simulated
	 *                    test order, null for real (or legacy) transactions.
	 */
	public static function simulated_listed_price( object $txn ): ?float {
		if ( empty( $txn->metadata_json ) ) {
			return null;
		}

		$meta = json_decode( (string) $txn->metadata_json, true );
		if ( empty( $meta['test_mode'] ) ) {
			return null;
		}

		return isset( $meta['intended_amount'] ) ? (float) $meta['intended_amount'] : 0.0;
	}

	public static function get_for_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		return static::where( [ 'user_id' => $user_id ], 'created_at', 'DESC', $limit, $offset );
	}

	/** Transactions for a user filtered by transaction_type — e.g. 'subscription'. */
	public static function get_for_user_by_type( int $user_id, string $type, int $limit = 50 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d AND transaction_type = %s ORDER BY created_at DESC LIMIT %d',
				$user_id,
				$type,
				$limit
			)
		);
	}

	/**
	 * A member's billing history: the initial membership charge plus every
	 * renewal. The adapters record these as transaction_type `membership` and
	 * `membership_renewal` (the legacy `subscription` value is never written by
	 * the live checkout path), so the member-facing invoices endpoint must match
	 * those two types or it returns nothing on a real site.
	 */
	public static function get_membership_invoices( int $user_id, int $limit = 50 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE user_id = %d AND transaction_type IN ('membership', 'membership_renewal') ORDER BY created_at DESC LIMIT %d",
				$user_id,
				$limit
			)
		);
	}

	/** All transactions linked to one subscription — invoice history. */
	public static function get_for_subscription( int $subscription_id, int $limit = 100 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE subscription_id = %d ORDER BY created_at DESC LIMIT %d',
				$subscription_id,
				$limit
			)
		);
	}

	public static function count_for_user( int $user_id ): int {
		return static::count( [ 'user_id' => $user_id ] );
	}

	public static function get_for_course( int $course_id, int $limit = 50, int $offset = 0 ): array {
		return static::where( [ 'course_id' => $course_id ], 'created_at', 'DESC', $limit, $offset );
	}

	public static function count_for_course( int $course_id ): int {
		return static::count( [ 'course_id' => $course_id ] );
	}

	public static function find_by_provider_id( string $provider_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE provider_transaction_id = %s LIMIT 1',
				$provider_id
			)
		) ?: null;
	}

	/**
	 * Most recent completed PURCHASE transaction for a (user, course) pair.
	 * Used by `Enrollment_Service::enroll()` as proof-of-payment when
	 * promoting a source='purchase' enrollment for paid courses.
	 *
	 * Filters that matter:
	 *   - status='completed' — the payment cleared
	 *   - transaction_type='course' — excludes membership and refund records
	 *   - amount > 0 — excludes refund-side mirror rows that get logged as
	 *     completed with a negative amount, AND any zero-amount free promos
	 *     that shouldn't grant paid-course access
	 *   - refund_of IS NULL — hard exclusion for refund records that didn't
	 *     get the negative-amount treatment in older data
	 *
	 * If a customer was refunded, the most-recent qualifying purchase row
	 * disappears once the refund is recorded (the original row's status
	 * becomes 'refunded') — so this method correctly returns null and the
	 * gate refuses re-enrollment without a new purchase.
	 *
	 * Why most-recent: a customer may legitimately purchase the same course
	 * twice (gift, refund + repurchase). We only need ONE qualifying
	 * transaction to authorize enrollment.
	 */
	public static function find_completed_for_user_course( int $user_id, int $course_id ): ?object {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return null;
		}
		// amount >= 0 (not > 0): a fully-discounted order (100% coupon) is a
		// legitimate completed purchase and must authorize enrollment too —
		// refunds are already excluded via refund_of IS NULL (Basecamp
		// #9941278467).
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE user_id = %d AND course_id = %d AND status = 'completed' AND transaction_type = 'course' AND amount >= 0 AND refund_of IS NULL ORDER BY created_at DESC LIMIT 1",
				$user_id,
				$course_id
			)
		) ?: null;
	}

	/** Anonymizes transaction data for GDPR erasure. */
	public static function anonymize_for_user( int $user_id ): void {
		static::db()->update(
			static::table(),
			[
				'user_id'                 => 0,
				'provider_transaction_id' => null,
				'metadata_json'           => null,
			],
			[ 'user_id' => $user_id ],
			[ '%d', '%s', '%s' ],
			[ '%d' ]
		);
		static::invalidate_cache();
	}

	/** Completed transactions of a given type for a YYYY-MM period. */
	public static function get_completed_for_period( string $type, string $period ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE transaction_type = %s AND status = 'completed' AND DATE_FORMAT(created_at, '%%Y-%%m') = %s",
				$type,
				$period
			)
		);
	}

	/**
	 * Totals grouped by status — used for revenue dashboards and reconciliation.
	 *
	 * Cached in the object-cache (or WordPress transient fallback) for five
	 * minutes. Invalidated on every call to record() so a new payment busts
	 * the cache immediately. Five minutes is also the Stripe webhook retry
	 * window, so stale data disappears long before any operator would notice.
	 */
	public static function get_revenue_summary(): array {
		$cached = wp_cache_get( 'summary', self::REVENUE_CACHE_GROUP );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		$rows = static::db()->get_results(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'SELECT status, SUM(amount) AS total, COUNT(*) AS count FROM ' . static::table() . ' GROUP BY status'
		);

		$summary = [];
		foreach ( $rows as $row ) {
			$summary[ $row->status ] = [
				'total' => (float) $row->total,
				'count' => (int) $row->count,
			];
		}

		wp_cache_set( 'summary', $summary, self::REVENUE_CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

		return $summary;
	}

	/**
	 * Total completed revenue (all time).
	 *
	 * Cached in the object-cache for five minutes; busted by record().
	 */
	public static function total_completed_revenue(): float {
		$cached = wp_cache_get( 'total_completed', self::REVENUE_CACHE_GROUP );
		if ( false !== $cached ) {
			return (float) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (float) static::db()->get_var(
			'SELECT COALESCE(SUM(amount), 0) FROM ' . static::table() . ' WHERE status IN ' . self::REVENUE_STATUSES
		);

		wp_cache_set( 'total_completed', $total, self::REVENUE_CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

		return $total;
	}

	/**
	 * Completed revenue for a single calendar month.
	 *
	 * @param string $ym Month prefix in 'Y-m' form (e.g. '2026-05'); matched
	 *                   against created_at via a LIKE 'Y-m%' anchored prefix.
	 *
	 * Cached by month key; busted by record() for the relevant month.
	 */
	public static function completed_revenue_in_month( string $ym ): float {
		$cache_key = 'month_' . preg_replace( '/[^0-9-]/', '', $ym );
		$cached    = wp_cache_get( $cache_key, self::REVENUE_CACHE_GROUP );
		if ( false !== $cached ) {
			return (float) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (float) static::db()->get_var(
			static::db()->prepare(
				'SELECT COALESCE(SUM(amount), 0) FROM ' . static::table() . ' WHERE status IN ' . self::REVENUE_STATUSES . ' AND created_at LIKE %s',
				static::db()->esc_like( $ym ) . '%'
			)
		);

		wp_cache_set( $cache_key, $total, self::REVENUE_CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

		return $total;
	}

	/** Count of completed transactions (all time). */
	public static function count_completed(): int {
		return (int) static::db()->get_var(
			'SELECT COUNT(*) FROM ' . static::table() . " WHERE status = 'completed'"
		);
	}

	/** Daily revenue grouped by day for completed transactions since a date. */
	public static function get_daily_revenue_since( string $since ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT DATE(created_at) AS day, SUM(amount) AS total
				 FROM ' . static::table() . '
				 WHERE status IN ' . self::REVENUE_STATUSES . ' AND created_at >= %s
				 GROUP BY DATE(created_at)
				 ORDER BY day ASC',
				$since
			)
		) ?: [];
	}

	/**
	 * Refunds processed over the last $days — count + total + the most
	 * recent N. Refund rows store transaction_type='refund' with
	 * `refund_of` pointing at the original; the amount on a refund row
	 * is the refunded portion stored negative (so revenue rollups net
	 * out), and `total` here is returned as a positive figure for display.
	 *
	 * Powers the admin dashboard "Refund Overview" widget. There is no
	 * "pending refund" workflow in Free — refunds are an admin action
	 * triggered through Payments_Admin_Controller, processed instantly
	 * by the gateway. This summary is therefore a 30-day overview, not
	 * a queue.
	 *
	 * @return array{count:int,total:float,recent:object[]}
	 */
	public static function refund_overview( int $days = 30, int $recent_limit = 3 ): array {
		$cutoff = gmdate( 'Y-m-d 00:00:00', time() - ( $days * DAY_IN_SECONDS ) );

		$summary = static::db()->get_row(
			static::db()->prepare(
				'SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total
				 FROM ' . static::table() . "
				 WHERE transaction_type = 'refund' AND created_at >= %s",
				$cutoff
			)
		);
		$recent  = static::db()->get_results(
			static::db()->prepare(
				'SELECT id, user_id, amount, currency, refund_of, created_at
				 FROM ' . static::table() . "
				 WHERE transaction_type = 'refund' AND created_at >= %s
				 ORDER BY created_at DESC
				 LIMIT %d",
				$cutoff,
				$recent_limit
			)
		);
		return array(
			'count'  => (int) ( $summary->cnt ?? 0 ),
			'total'  => abs( (float) ( $summary->total ?? 0 ) ),
			'recent' => $recent ?: array(),
		);
	}

	/**
	 * Newest completed transactions sitewide. Powers the admin dashboard
	 * "Recent Transactions" feed widget. A single indexed ORDER BY on
	 * created_at DESC plus the existing status+refund_of filters; cheap
	 * for any reasonable transaction volume.
	 *
	 * @return object[] Rows with id, user_id, amount, currency, transaction_type,
	 *                  course_id, plan_id, status, created_at.
	 */
	public static function recent_completed( int $limit = 5 ): array {
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT id, user_id, amount, currency, transaction_type, course_id, plan_id, status, created_at
				 FROM ' . static::table() . "
				 WHERE status = 'completed' AND amount > 0 AND refund_of IS NULL
				 ORDER BY created_at DESC
				 LIMIT %d",
				$limit
			)
		);
		return $rows ?: array();
	}

	/**
	 * Sum the `amount` of completed transactions whose IDs are in the given set.
	 *
	 * Used by the demo-seeder stats builder to compute total demo revenue from
	 * a known batch of transaction IDs without loading every row into PHP.
	 *
	 * @param int[] $ids Transaction IDs to include.
	 * @return float     Total completed amount, or 0.0 if the list is empty.
	 */
	public static function sum_completed_for_ids( array $ids ): float {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0.0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (float) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT COALESCE(SUM(amount), 0) FROM ' . static::table() . " WHERE id IN ({$placeholders}) AND status = 'completed'",
				...$ids
			)
		);
	}

	/**
	 * Filter + search + paginate transactions for the admin list view.
	 *
	 * Joins wp_users so the admin can search by buyer email / display name and
	 * so each row carries the buyer's name. Returns the requested page of rows
	 * plus the unpaginated total for the pager. The admin view passes already
	 * sanitised request params.
	 *
	 * @param array{status?: string, provider?: string, search?: string, paged?: int, per_page?: int} $args
	 * @return array{rows: object[], total: int, total_pages: int}
	 */
	public static function admin_list( array $args = array() ): array {
		$db       = static::db();
		$table    = static::table();
		$users    = $db->users;
		$status   = isset( $args['status'] ) ? (string) $args['status'] : '';
		$provider = isset( $args['provider'] ) ? (string) $args['provider'] : '';
		$search   = isset( $args['search'] ) ? (string) $args['search'] : '';
		$per_page = isset( $args['per_page'] ) ? max( 1, (int) $args['per_page'] ) : 25;
		$paged    = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;

		$where      = '1=1';
		$where_args = array();
		if ( '' !== $status ) {
			$where       .= ' AND t.status = %s';
			$where_args[] = $status;
		}
		if ( '' !== $provider ) {
			$where       .= ' AND t.payment_provider = %s';
			$where_args[] = $provider;
		}
		if ( '' !== $search ) {
			$like         = '%' . $db->esc_like( $search ) . '%';
			$where       .= ' AND ( u.user_email LIKE %s OR u.display_name LIKE %s OR t.id = %s OR t.provider_transaction_id LIKE %s )';
			$where_args[] = $like;
			$where_args[] = $like;
			$where_args[] = (int) $search;
			$where_args[] = $like;
		}

		// Table names are internal (static::table() + $wpdb->users); only the
		// WHERE values are user input, and those are always parameterised.
		$count_sql = "SELECT COUNT(*) FROM {$table} t LEFT JOIN {$users} u ON u.ID = t.user_id WHERE {$where}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $where_args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $db->get_var( $db->prepare( $count_sql, $where_args ) );
		} else {
			// No filters → no placeholders; prepare() would warn, so query directly.
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $db->get_var( $count_sql );
		}

		$offset    = ( $paged - 1 ) * $per_page;
		$rows_args = array_merge( $where_args, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $db->get_results(
			$db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT t.*, u.display_name AS user_name, u.user_email
				 FROM {$table} t
				 LEFT JOIN {$users} u ON u.ID = t.user_id
				 WHERE {$where}
				 ORDER BY t.id DESC
				 LIMIT %d OFFSET %d",
				$rows_args
			)
		);

		return array(
			'rows'        => $rows ?: array(),
			'total'       => $total,
			'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	/**
	 * ID of the refund transaction that already refunds the given source txn,
	 * or 0 if none exists. Lets callers both gate ("already refunded") and
	 * reference the existing refund in a message.
	 *
	 * @param int $txn_id The original (non-refund) transaction ID.
	 */
	public static function find_refund_id( int $txn_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT id FROM ' . static::table() . ' WHERE refund_of = %d LIMIT 1',
				$txn_id
			)
		);
	}

	/**
	 * How much of this transaction has been refunded so far, across every refund
	 * issued against it.
	 *
	 * THE reader for "is there anything left to refund". Refund rows carry the
	 * refunded portion as a NEGATIVE amount (so the revenue rollups net out), hence
	 * the ABS.
	 *
	 * This exists because partial refunds made "has it been refunded?" the wrong
	 * question. The old guard asked exactly that — `find_refund_id()`, a bare "does
	 * any refund row exist" — and answered 409 to the second refund. So a $25 refund
	 * on a $100 sale permanently stranded the other $75: the amount field was there,
	 * but the remainder could never be claimed.
	 *
	 * @param int $txn_id The original (non-refund) transaction ID.
	 * @return float Total refunded, as a positive number. 0.0 when nothing is refunded.
	 */
	public static function refunded_total( int $txn_id ): float {
		$total = static::db()->get_var(
			static::db()->prepare(
				'SELECT SUM(ABS(amount)) FROM ' . static::table() . "
				  WHERE refund_of = %d
				    AND status    = 'completed'",
				$txn_id
			)
		);

		return round( (float) $total, 2 );
	}

	/**
	 * Refunded totals for many transactions at once, keyed by original id.
	 *
	 * The list view needs this for every row on the page. Calling refunded_total()
	 * per row would be a query per row — the exact N+1 the big-site checklist exists
	 * to prevent — so the page fetches the whole page's totals in one grouped query.
	 *
	 * @param int[] $txn_ids Original (non-refund) transaction IDs.
	 * @return array<int,float> id => total refunded (positive). Ids with no refund are absent.
	 */
	public static function refunded_totals_for( array $txn_ids ): array {
		$ids = array_filter( array_map( 'intval', $txn_ids ) );
		if ( ! $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated %d, ids are ints.
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT refund_of AS txn_id, SUM(ABS(amount)) AS total
				   FROM ' . static::table() . "
				  WHERE status = 'completed'
				    AND refund_of IN ( {$placeholders} )
				  GROUP BY refund_of",
				...$ids
			)
		);

		$totals = array();
		foreach ( (array) $rows as $row ) {
			$totals[ (int) $row->txn_id ] = round( (float) $row->total, 2 );
		}

		return $totals;
	}

	/**
	 * What is still refundable on this transaction: charged minus already refunded.
	 *
	 * @param object     $original       The original (non-refund) transaction row.
	 * @param float|null $already_refunded Pre-fetched total (see refunded_totals_for()),
	 *                                     to avoid a query per row in a list view.
	 * @return float Remaining refundable amount; 0.0 once fully refunded.
	 */
	public static function refundable_remaining( object $original, ?float $already_refunded = null ): float {
		$charged   = round( (float) $original->amount, 2 );
		$refunded  = null === $already_refunded
			? static::refunded_total( (int) $original->id )
			: $already_refunded;
		$remaining = $charged - $refunded;

		return $remaining > 0.0 ? round( $remaining, 2 ) : 0.0;
	}

	/**
	 * Take a write lock on one transaction row for the rest of the surrounding
	 * SQL transaction, so a concurrent caller blocks until it commits.
	 *
	 * Only meaningful inside {@see \Learnomy\DB\Tx::run()} — outside one, the
	 * implicit single-statement transaction ends immediately and the lock is
	 * released before the caller does anything with it.
	 *
	 * This is what makes the refund remaining-check atomic: two partial refunds
	 * arriving together (an admin double-click, or an admin action racing the
	 * gateway's echo webhook) would otherwise both read "enough left" before
	 * either wrote, and the total would exceed the charge.
	 */
	public static function lock_for_update( int $id ): void {
		// The row lock IS the concurrency primitive, so the direct-query +
		// no-cache sniffs do not apply.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		static::db()->get_var(
			static::db()->prepare( 'SELECT id FROM ' . static::table() . ' WHERE id = %d FOR UPDATE', $id )
		);
	}
}
