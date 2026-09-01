<?php
/**
 * Per-user coupon usage log — enforces per_user_limit on redemptions.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use Learnomy\DB\Relations;

use function Learnomy\now;

class CouponUsage extends Model {

	/**
	 * Seconds to wait on the per-user advisory lock before failing closed.
	 * Short — the guarded section is a single COUNT + INSERT.
	 */
	private const USER_LOCK_TIMEOUT = 5;

	/**
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		// KEEP: a redemption happened. Deleting the coupon does not un-happen it.
		'coupon'      => array(
			'column'    => 'coupon_id',
			'on_delete' => Relations::KEEP,
		),
		'transaction' => array(
			'column'    => 'transaction_id',
			'on_delete' => Relations::KEEP,
		),
	);

	protected static function table_name(): string {
		return 'coupon_usages';
	}

	public static function record( int $coupon_id, int $user_id, int $transaction_id = 0 ): int {
		return static::insert(
			[
				'coupon_id'      => $coupon_id,
				'user_id'        => $user_id,
				'transaction_id' => $transaction_id,
				'created_at'     => now(),
			]
		);
	}

	/**
	 * Record a per-user redemption atomically against `per_user_limit`.
	 *
	 * The `(coupon_id, user_id)` index is NON-unique (per_user_limit can be > 1),
	 * so a UNIQUE-insert can't guard this — two concurrent redemptions by the same
	 * user could both pass the count check in Coupon_Service::validate() and both
	 * insert, blowing past `per_user_limit`. We wrap the count-check + insert in a
	 * per-(coupon,user) MySQL advisory lock so they serialise, exactly mirroring
	 * Pro's `Space_Member::insert_within_seat_limit()` seat-claim.
	 *
	 * @param int $coupon_id      Coupon id.
	 * @param int $user_id        Redeeming user.
	 * @param int $per_user_limit Max redemptions per user; 0 means unlimited.
	 * @param int $transaction_id Linked transaction id (0 if none).
	 * @return int New usage-row id, or 0 if the per-user cap was already full,
	 *             the lock timed out (fail closed), or the insert was rejected.
	 */
	public static function record_within_user_limit( int $coupon_id, int $user_id, int $per_user_limit, int $transaction_id = 0 ): int {
		if ( $coupon_id <= 0 || $user_id <= 0 ) {
			return 0;
		}

		// No per-user cap — the global claim is the only gate, so just record.
		if ( $per_user_limit <= 0 ) {
			return static::record( $coupon_id, $user_id, $transaction_id );
		}

		$db        = static::db();
		$lock_name = 'lrn_coupon_user_' . $coupon_id . '_' . $user_id;

		// GET_LOCK returns "1" on success, "0" on timeout, NULL on error. The
		// advisory lock IS the concurrency primitive here, so the direct-query +
		// no-cache sniffs do not apply.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $db->get_var( $db->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, self::USER_LOCK_TIMEOUT ) );

		if ( '1' !== (string) $acquired ) {
			// Could not serialise — fail closed rather than risk over-admission.
			return 0;
		}

		try {
			if ( static::get_usage_count_for_user( $coupon_id, $user_id ) >= $per_user_limit ) {
				return 0;
			}
			return static::record( $coupon_id, $user_id, $transaction_id );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$db->query( $db->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/** Redemption rows for one coupon, newest first (admin usage drill-in). */
	public static function get_for_coupon( int $coupon_id, int $limit = 100, int $offset = 0 ): array {
		return static::where( array( 'coupon_id' => $coupon_id ), 'created_at', 'DESC', $limit, $offset );
	}

	/** Total redemptions for one coupon. */
	public static function count_for_coupon( int $coupon_id ): int {
		return static::count( array( 'coupon_id' => $coupon_id ) );
	}

	public static function get_usage_count_for_user( int $coupon_id, int $user_id ): int {
		return (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . ' WHERE coupon_id = %d AND user_id = %d',
				$coupon_id,
				$user_id
			)
		);
	}
}
