<?php
/**
 * Instructor withdrawal requests — lifecycle from pending through processed.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Withdrawal extends Model {

	protected static function table_name(): string {
		return 'withdrawals';
	}

	/**
	 * Record a payout request.
	 *
	 * `$destination` is where the money should go (e.g. the PayPal payee email).
	 * It stays NULL for a manual payout — the instructor arranges that with the
	 * site owner off-platform, so there is nothing sensitive to keep. Never store
	 * a bank/IBAN here; that belongs in the gateway that moves the funds.
	 */
	public static function request( int $instructor_id, float $amount, string $currency, string $method, string $destination = '' ): int {
		return static::insert(
			[
				'instructor_id' => $instructor_id,
				'amount'        => $amount,
				'currency'      => $currency,
				'method'        => $method,
				'destination'   => '' !== $destination ? $destination : null,
				'status'        => 'pending',
				'requested_at'  => now(),
			]
		);
	}

	public static function get_for_instructor( int $instructor_id ): array {
		return static::where( [ 'instructor_id' => $instructor_id ], 'requested_at', 'DESC' );
	}

	public static function get_pending(): array {
		return static::db()->get_results(
			'SELECT * FROM ' . static::table() . " WHERE status = 'pending' ORDER BY requested_at ASC"
		);
	}

	/**
	 * How many payout requests are still awaiting the owner — a COUNT(*), so
	 * the Modules page can say "3 instructors are waiting to be paid" without
	 * loading every pending row.
	 */
	public static function count_pending(): int {
		return (int) static::db()->get_var(
			'SELECT COUNT(*) FROM ' . static::table() . " WHERE status = 'pending'"
		);
	}

	/** Sum of all pending withdrawal amounts — drives the admin earnings widget. */
	public static function sum_pending(): float {
		return (float) static::db()->get_var(
			'SELECT COALESCE(SUM(amount), 0) FROM ' . static::table() . " WHERE status = 'pending'"
		);
	}

	public static function reject( int $id, string $reason ): bool {
		return static::update(
			$id,
			[
				'status'           => 'rejected',
				'rejection_reason' => $reason,
			]
		);
	}

	public static function mark_processed( int $id, string $reference = '' ): bool {
		return static::update(
			$id,
			[
				'status'       => 'processed',
				'processed_at' => now(),
				'reference'    => $reference,
			]
		);
	}

	/**
	 * Conditional settle: only transitions a row still in `pending`.
	 *
	 * This is the atomicity signal the payout path needs. Two actors can reach
	 * settlement for the same withdrawal — an admin clicking Approve and Pro's
	 * Stripe Connect transfer firing `learnomy_withdrawal_paid` — and an
	 * unconditional UPDATE lets both "succeed", so both then mark commissions
	 * paid and both fire their notifications. The `status = 'pending'` predicate
	 * makes the loser a 0-row no-op, which is what
	 * {@see \Learnomy\Services\Commission_Service::settle_withdrawal()} reports
	 * back as "somebody else already settled this".
	 *
	 * Mirrors Commission::mark_requested_if_pending(), the same guard one step
	 * earlier in the lifecycle.
	 *
	 * @return bool True only when THIS call performed the transition.
	 */
	public static function mark_processed_if_pending( int $id, string $reference = '' ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		$sql = static::db()->prepare(
			'UPDATE ' . static::table() . " SET status = 'processed', processed_at = %s, reference = %s WHERE id = %d AND status = 'pending'",
			now(),
			$reference,
			$id
		);

		$ok = static::db()->query( $sql );
		static::invalidate_cache();

		return false !== $ok && static::db()->rows_affected > 0;
	}
}
