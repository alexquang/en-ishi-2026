<?php
/**
 * Discount coupons with usage caps and optional date windows.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Coupon extends Model {

	protected static function table_name(): string {
		return 'coupons';
	}

	public static function create( array $coupon ): int {
		return static::insert(
			array_merge(
				[
					'created_at' => now(),
					'times_used' => 0,
				],
				static::normalize_window( $coupon )
			)
		);
	}

	/**
	 * A blank campaign window means "no limit" — store NULL, never ''.
	 *
	 * An empty string handed to a DATETIME column becomes `0000-00-00 00:00:00`,
	 * which is NOT empty and whose strtotime() is -62169984000 (year zero), so
	 * is_valid() below read it as long expired. A coupon created with the Expiry
	 * Date left blank — the way you say "this never expires" — was refused at
	 * checkout as expired (Basecamp 10163002840).
	 *
	 * @param array $coupon Column map on its way to insert/update.
	 */
	public static function normalize_window( array $coupon ): array {
		foreach ( [ 'starts_at', 'expires_at' ] as $key ) {
			if ( ! array_key_exists( $key, $coupon ) ) {
				continue;
			}
			$value = $coupon[ $key ];
			if ( null === $value || '' === trim( (string) $value ) || static::is_zero_date( (string) $value ) ) {
				$coupon[ $key ] = null;
			}
		}
		return $coupon;
	}

	/** Editing a coupon and CLEARING the expiry must null it, not blank it. */
	public static function update( int $id, array $data ): bool {
		return parent::update( $id, static::normalize_window( $data ) );
	}

	/**
	 * The MySQL zero-date sentinel.
	 *
	 * Kept as a model method because callers read naturally against it, but the
	 * predicate itself lives in `\Learnomy\is_zero_date()` now. It was solved
	 * here first, on the Coupon model, where no other surface could find it -
	 * and the identical value then printed "November 30, -0001" in the Space
	 * Plans admin list (Basecamp 10184459033). One implementation, reachable
	 * from anywhere.
	 */
	public static function is_zero_date( string $value ): bool {
		return \Learnomy\is_zero_date( $value );
	}

	/** Is this coupon usable on a recurring membership at all? */
	public static function applies_to_memberships( object $coupon ): bool {
		return in_array( (string) ( $coupon->applies_to ?? 'course' ), array( 'membership', 'all' ), true );
	}

	public static function find_by_code( string $code ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE code = %s LIMIT 1',
				strtoupper( $code )
			)
		) ?: null;
	}

	/**
	 * All four conditions must pass:
	 *  - status active
	 *  - campaign window open (starts_at in the past, expires_at in the future or not set)
	 *  - usage cap not reached (usage_limit null means unlimited)
	 */
	public static function is_valid( string $code ): bool {
		$coupon = static::find_by_code( $code );
		if ( ! $coupon ) {
			return false;
		}

		if ( 'active' !== $coupon->status ) {
			return false;
		}

		$ts = time();

		// `! empty()` is not enough on its own: the zero-date sentinel is a
		// non-empty string, so every coupon already stored with a blank window
		// fails these comparisons. Treat it as "not set", which is what the
		// owner meant, so existing rows heal without a migration.
		$starts_at = (string) ( $coupon->starts_at ?? '' );
		if ( '' !== $starts_at && ! static::is_zero_date( $starts_at ) && strtotime( $starts_at ) > $ts ) {
			return false;
		}

		$expires_at = (string) ( $coupon->expires_at ?? '' );
		if ( '' !== $expires_at && ! static::is_zero_date( $expires_at ) && strtotime( $expires_at ) < $ts ) {
			return false;
		}

		if ( ! is_null( $coupon->usage_limit ) && (int) $coupon->times_used >= (int) $coupon->usage_limit ) {
			return false;
		}

		return true;
	}

	public static function increment_usage( int $id ): bool {
		return false !== static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET times_used = times_used + 1 WHERE id = %d',
				$id
			)
		);
	}

	/**
	 * Atomically claim one global redemption against the coupon's `usage_limit`.
	 *
	 * The cap check and the increment run in a SINGLE conditional UPDATE, so two
	 * concurrent redemptions can never both read a stale `times_used` below the
	 * cap and both increment past it (the check-then-act TOCTOU race in the old
	 * is_valid() + increment_usage() pair). MySQL serialises the row write, so
	 * exactly one of the racers sees the row still under the cap.
	 *
	 * `usage_limit IS NULL` means unlimited — the WHERE clause always matches and
	 * the increment always succeeds. A non-null cap only increments while
	 * `times_used < usage_limit`, so `times_used` can never exceed `usage_limit`.
	 *
	 * Mirrors `Gift_Code::claim_atomically()` (Pro): `$wpdb->query()` returns the
	 * affected-row count on an UPDATE (int), or `false` on error. One row affected
	 * means this caller claimed the slot; zero means the cap was already full.
	 *
	 * @param int $id Coupon id.
	 * @return bool True if a slot was claimed (counter incremented), false if the
	 *              global usage cap was already reached or the query errored.
	 */
	public static function claim_redemption( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table()
					. ' SET times_used = times_used + 1'
					. ' WHERE id = %d AND ( usage_limit IS NULL OR times_used < usage_limit )',
				$id
			)
		);

		$ok = is_int( $result ) && $result > 0;
		if ( $ok ) {
			static::invalidate_cache();
		}
		return $ok;
	}

	/**
	 * Recompute `times_used` from the source of truth — the real redemption rows
	 * in `lrn_coupon_usages`. The counter is increment-only and directly GATES the
	 * usage cap (`times_used < usage_limit`), so any path that removes a usage row
	 * without decrementing (a void/refund, or a GDPR erase deleting a usage) leaves
	 * the counter too high and can prematurely lock a coupon that still has real
	 * redemptions left. Call this whenever a usage row is removed, and from the
	 * repair reconciler for existing drift (denormalized-column audit).
	 *
	 * @return int The reconciled count.
	 */
	public static function recompute_times_used( int $id ): int {
		if ( $id <= 0 ) {
			return 0;
		}
		$usages = \Learnomy\table( 'coupon_usages' );
		$count  = (int) static::db()->get_var(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name; id bound.
				"SELECT COUNT(*) FROM {$usages} WHERE coupon_id = %d",
				$id
			)
		);
		static::db()->query(
			static::db()->prepare(
				'UPDATE ' . static::table() . ' SET times_used = %d WHERE id = %d',
				$count,
				$id
			)
		);
		static::invalidate_cache();
		return $count;
	}
}
