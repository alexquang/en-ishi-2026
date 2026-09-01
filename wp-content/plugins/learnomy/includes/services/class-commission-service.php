<?php
/**
 * Commission calculation, engagement-based splits, and withdrawal requests.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\AuditLog;
use Learnomy\Models\Commission;
use Learnomy\Models\CommissionRule;
use Learnomy\Models\Course;
use Learnomy\Models\EngagementMonthly;
use Learnomy\Models\Subscription;
use Learnomy\Models\Transaction;
use Learnomy\Models\Withdrawal;
use function Learnomy\now;

class Commission_Service {

	/**
	 * Wire the payout lifecycle listeners this service owns.
	 *
	 * `learnomy_withdrawal_paid` is fired by Pro's Stripe Connect extension the
	 * moment a transfer succeeds. Until 2026-07-30 NOTHING listened to it: the
	 * money had left the platform while `lrn_withdrawals.status` still read
	 * `pending` and the commission rows still read `requested`. That is the
	 * ledger disagreeing with reality, and it let an admin approve (and pay,
	 * again) a withdrawal Stripe had already settled. Settling here, at
	 * priority 5, means the state is correct before any notification listener
	 * reads the row.
	 */
	public function register_hooks(): void {
		add_action( 'learnomy_withdrawal_paid', array( __CLASS__, 'on_withdrawal_paid' ), 5, 2 );
	}

	/**
	 * Settle a withdrawal an external payout rail already paid.
	 *
	 * @param int    $withdrawal_id Withdrawal row id.
	 * @param string $reference     Provider transfer id, used as the payout reference.
	 */
	public static function on_withdrawal_paid( int $withdrawal_id, string $reference = '' ): void {
		self::settle_withdrawal( $withdrawal_id, $reference );
	}

	// Hard-coded fallback used only when neither a CommissionRule row nor the
	// admin `platform_commission` setting yields a usable value. Kept for
	// backward-compat with code (and tests) that referenced this constant
	// before the admin setting was wired in (Wave 2C row 2C-1).
	const DEFAULT_PLATFORM_RATE = 30.00;

	/**
	 * Whether an instructor participates in revenue at all — i.e. their
	 * effective revenue share is > 0%. False only when they have NO course with
	 * a share above zero AND no commission rule (per-course or instructor-level)
	 * grants them anything. Drives whether the dashboard shows earnings UI: a
	 * non-revenue instructor (guest presenter, staff-curated catalog, content
	 * under a direct contract) should never see a wall of $0.00.
	 *
	 * @param int $user_id Instructor user id.
	 * @return bool
	 */
	public static function instructor_participates_in_revenue( int $user_id ): bool {
		$courses = \Learnomy\Models\CourseInstructor::get_for_user( $user_id );
		foreach ( $courses as $row ) {
			if ( (float) ( $row->share_pct ?? 0 ) > 0 ) {
				return true;
			}
		}

		// An instructor-level commission rule that grants them a cut.
		$instructor_rule = CommissionRule::get_for_entity_exact( 'instructor', $user_id );
		if ( $instructor_rule && (float) ( $instructor_rule->rate ?? 0 ) > 0 ) {
			return true;
		}

		// A per-course commission rule on any course they teach.
		foreach ( $courses as $row ) {
			$course_rule = CommissionRule::get_for_entity_exact( 'course', (int) $row->course_id );
			if ( $course_rule && (float) ( $course_rule->rate ?? 0 ) > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reads the admin-configured fallback platform rate from `learnomy_settings`.
	 *
	 * Used when no per-course / per-instructor CommissionRule applies. Treats
	 * any non-numeric or out-of-range (0-100) saved value as missing and falls
	 * back to the hard-coded default so a corrupted option can't produce
	 * negative platform cuts or send 200% to instructors.
	 */
	public static function get_default_platform_rate(): float {
		$settings = get_option( 'learnomy_settings', array() );
		if ( ! is_array( $settings ) || ! array_key_exists( 'platform_commission', $settings ) ) {
			return self::DEFAULT_PLATFORM_RATE;
		}

		$saved = $settings['platform_commission'];
		if ( ! is_numeric( $saved ) ) {
			return self::DEFAULT_PLATFORM_RATE;
		}

		$rate = (float) $saved;
		if ( $rate < 0.0 || $rate > 100.0 ) {
			return self::DEFAULT_PLATFORM_RATE;
		}

		return $rate;
	}

	/**
	 * Resolve the platform rate for a payout: per-course override → the
	 * instructor's per-instructor override → the global default (Settings).
	 *
	 * Each override is trusted only when it is genuinely that type of rule —
	 * CommissionRule::get_for_entity() falls back to the global row, so without
	 * the rule_type guard a per-course lookup could silently inherit a global
	 * value. (The global CommissionRule itself was retired in migration 3.2.0;
	 * the guard keeps the resolution correct even if one reappears.)
	 */
	public static function resolve_platform_rate( int $course_id, int $instructor_id ): float {
		if ( $course_id > 0 ) {
			$rule = CommissionRule::get_for_entity( 'course', $course_id );
			if ( $rule && 'course' === $rule->rule_type ) {
				return (float) $rule->platform_rate;
			}
		}
		if ( $instructor_id > 0 ) {
			$rule = CommissionRule::get_for_entity( 'instructor', $instructor_id );
			if ( $rule && 'instructor' === $rule->rule_type ) {
				return (float) $rule->platform_rate;
			}
		}
		return self::get_default_platform_rate();
	}

	/**
	 * Resolve the net amount a commission split runs on: gross minus the
	 * payment-processing fee, minus collected tax when the owner excludes it.
	 *
	 * The fee is an admin-modeled estimate (percent + fixed), NOT the exact
	 * per-charge processor cost. Capturing the real fee would need an extra
	 * API round-trip per gateway; the estimate is enough to stop the platform
	 * silently absorbing 100% of the processor cost while the instructor is
	 * paid on gross. Tax is whatever the gateway actually collected (read from
	 * the transaction's metadata); it is excluded from the base unless the
	 * owner opts to include it (tax is the site owner's policy call).
	 *
	 * Both inputs are clamped so a sub-dollar sale can never owe more in
	 * fee+tax than it brought in — we never compute a negative pool.
	 *
	 * @return array{base: float, fee: float, tax: float} all rounded to 2dp.
	 */
	public static function net_base( float $gross, float $tax = 0.0 ): array {
		$settings = get_option( 'learnomy_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$fee = 0.0;
		if ( ! empty( $settings['commission_deduct_fee'] ) ) {
			$pct   = isset( $settings['commission_fee_percent'] ) ? (float) $settings['commission_fee_percent'] : 0.0;
			$fixed = isset( $settings['commission_fee_fixed'] ) ? (float) $settings['commission_fee_fixed'] : 0.0;
			$fee   = round( ( $gross * ( $pct / 100 ) ) + $fixed, 2 );
		}

		$tax_excluded = empty( $settings['commission_tax_in_base'] ) ? round( max( 0.0, $tax ), 2 ) : 0.0;

		// Clamp: fee + excluded tax can never exceed gross. On a tiny sale the
		// fixed fee component could otherwise drive the base negative; the
		// platform absorbs the shortfall rather than paying a negative pool.
		if ( $fee + $tax_excluded > $gross ) {
			$fee = round( max( 0.0, $gross - $tax_excluded ), 2 );
		}

		$base = round( $gross - $fee - $tax_excluded, 2 );
		if ( $base < 0 ) {
			$base = 0.0;
		}

		return array(
			'base' => $base,
			'fee'  => $fee,
			'tax'  => $tax_excluded,
		);
	}

	/**
	 * Read the gateway-collected tax recorded on a transaction's metadata.
	 * Adapters write `metadata_json.tax` when the provider reports it (Stripe
	 * total_details.amount_tax, Woo get_total_tax, PayPal breakdown.tax_total);
	 * absent or non-tax-collecting gateways yield 0.
	 */
	private static function tax_from_transaction( object $transaction ): float {
		$meta = json_decode( (string) ( $transaction->metadata_json ?? '' ), true );
		if ( ! is_array( $meta ) ) {
			return 0.0;
		}
		return isset( $meta['tax'] ) ? max( 0.0, (float) $meta['tax'] ) : 0.0;
	}

	/**
	 * Freeze the fee/tax breakdown onto the transaction so the instructor and
	 * admin earnings screens can show "gross − fee − tax = net" with the exact
	 * numbers the split actually used — recomputing later would drift if the
	 * fee setting changes. Stored in the existing metadata_json (no schema
	 * column); merges with any provider metadata already present.
	 */
	private static function record_breakdown( object $transaction, float $gross, array $net ): void {
		$meta = json_decode( (string) ( $transaction->metadata_json ?? '' ), true );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$meta['commission_breakdown'] = array(
			'gross'    => round( $gross, 2 ),
			'fee'      => $net['fee'],
			'tax'      => $net['tax'],
			'net_base' => $net['base'],
		);
		Transaction::update( (int) $transaction->id, array( 'metadata_json' => wp_json_encode( $meta ) ) );
	}

	public function calculate( int $transaction_id ): void {
		$transaction = Transaction::find( $transaction_id );
		if ( ! $transaction ) {
			return;
		}

		// A test-gateway order splits nothing, because nothing was taken.
		//
		// It used to write the full pair of rows anyway, at 0.00 and
		// payout_status 'pending'. No money could ever be claimed from them, but
		// they queued up in the instructor's payouts as pending entries for
		// sales that never happened — and any count of pending rows, as opposed
		// to a sum of their amounts, reports work owed on a rehearsal.
		//
		// Uses the same test-order check the transactions list, receipt PDF,
		// notifier and billing screen use, so "is this a test order" has one
		// answer everywhere.
		if ( null !== Transaction::simulated_listed_price( $transaction ) ) {
			return;
		}

		$gross     = (float) $transaction->amount;
		$currency  = $transaction->currency;
		$course_id = (int) $transaction->course_id;

		// Deduct the processing fee and (optionally) collected tax BEFORE the
		// split, so both the platform and the instructor are paid on the real
		// net the business keeps — not the gross the customer was charged.
		$net    = self::net_base( $gross, self::tax_from_transaction( $transaction ) );
		$amount = $net['base'];
		self::record_breakdown( $transaction, $gross, $net );

		// Rule resolution: course-specific → instructor-specific → global.
		// Co-author model: by default the whole instructor pool goes to the
		// course's MAIN AUTHOR. Co-instructors get credit + student-reporting
		// access, never a cut. With no course (e.g. a standalone sale), fall back
		// to the transaction's own user. Resolved first because the rate can be
		// per-instructor (it keys on the main author).
		$main_author_id = $course_id ? Course::main_author_id( $course_id ) : 0;
		if ( $main_author_id <= 0 ) {
			$main_author_id = (int) $transaction->user_id;
		}

		// Full rate resolution: per-course override → the main author's
		// per-instructor override → the global default (Settings). The global
		// CommissionRule was retired in migration 3.2.0 — Settings is the one
		// default — so resolve_platform_rate() never leaks a stale global row.
		$platform_rate   = self::resolve_platform_rate( $course_id, $main_author_id );
		$platform_cut    = round( $amount * ( $platform_rate / 100 ), 2 );
		$instructor_pool = round( $amount - $platform_cut, 2 );

		// Platform commission row (recipient_id = 0 means platform/admin).
		Commission::create(
			[
				'transaction_id' => $transaction_id,
				'recipient_type' => 'platform',
				'recipient_id'   => 0,
				'amount'         => $platform_cut,
				'currency'       => $currency,
				'rate_applied'   => $platform_rate,
			]
		);

		// Extension seam (replaces the old hardcoded split_among_instructors): a
		// future / Pro module can re-introduce multi-instructor revenue sharing —
		// e.g. split by lrn_course_instructors.share_pct — by returning its own
		// allocation list, without the engine changing. Each entry:
		// ['recipient_id' => int, 'amount' => float, 'rate_applied' => float].
		// The default is the single main-author allocation.
		$allocations = apply_filters(
			'learnomy_commission_instructor_allocations',
			array(
				array(
					'recipient_id' => $main_author_id,
					'amount'       => $instructor_pool,
					'rate_applied' => round( 100 - $platform_rate, 2 ),
				),
			),
			$transaction_id,
			$course_id,
			$instructor_pool,
			$platform_rate
		);

		foreach ( (array) $allocations as $allocation ) {
			$recipient = isset( $allocation['recipient_id'] ) ? (int) $allocation['recipient_id'] : 0;
			if ( $recipient <= 0 ) {
				continue;
			}
			Commission::create(
				[
					'transaction_id' => $transaction_id,
					'recipient_type' => 'instructor',
					'recipient_id'   => $recipient,
					'amount'         => round( (float) ( $allocation['amount'] ?? 0 ), 2 ),
					'currency'       => $currency,
					'rate_applied'   => round( (float) ( $allocation['rate_applied'] ?? ( 100 - $platform_rate ) ), 2 ),
				]
			);
		}

		do_action( 'learnomy_commission_calculated', $transaction_id );
	}

	/**
	 * Distributes membership revenue among instructors based on lesson engagement
	 * for the given period. Called by a monthly cron job after subscription payments settle.
	 */
	public function calculate_engagement_split( string $period ): void {
		// Fetch all completed membership transactions for this period.
		$transactions = Transaction::get_completed_for_period( 'membership', $period );

		if ( empty( $transactions ) ) {
			return;
		}

		// Group total amount + currency by plan_id. Currency derives from the
		// underlying transactions (rather than the hardcoded 'USD' the prior
		// code used) so a EUR/GBP plan's instructor commissions are recorded
		// in the same currency the customer actually paid. We pick the first
		// transaction's currency per plan and trust the upstream invariant
		// that a single plan bills in one currency — mixed-currency rows on
		// the same plan would be a separate data-integrity bug, not ours to
		// silently re-denominate.
		$plan_totals     = [];
		$plan_taxes      = [];
		$plan_currencies = [];
		foreach ( $transactions as $txn ) {
			$pid                 = (int) $txn->plan_id;
			$plan_totals[ $pid ] = ( $plan_totals[ $pid ] ?? 0.0 ) + (float) $txn->amount;
			$plan_taxes[ $pid ]  = ( $plan_taxes[ $pid ] ?? 0.0 ) + self::tax_from_transaction( $txn );
			if ( ! isset( $plan_currencies[ $pid ] ) ) {
				$plan_currencies[ $pid ] = (string) ( $txn->currency ?? 'USD' );
			}
		}

		foreach ( $plan_totals as $plan_id => $gross ) {
			// Net the plan's pool of fee + (optionally) tax before distributing,
			// same as the one-time path — membership revenue carries the same
			// processor cost.
			$net      = self::net_base( $gross, $plan_taxes[ $plan_id ] ?? 0.0 );
			$pool_net = $net['base'];
			$currency = $plan_currencies[ $plan_id ] ?? 'USD';

			$rows          = EngagementMonthly::get_for_period( $period );
			$plan_rows     = array_filter( $rows, fn( $r ) => (int) $r->plan_id === $plan_id );
			$total_lessons = array_sum( array_column( $plan_rows, 'lesson_count' ) );

			if ( $total_lessons <= 0 ) {
				continue;
			}

			foreach ( $plan_rows as $row ) {
				$instructor_id = (int) $row->instructor_id;

				// Each instructor's slice of the NET pool by lesson engagement,
				// THEN their own platform rate applied — honoring a per-instructor
				// CommissionRule instead of the global rate for everyone. Without
				// this, a membership instructor with a negotiated rate was paid
				// the default (the F2 bug).
				$share       = (float) $row->lesson_count / $total_lessons;
				$gross_share = round( $pool_net * $share, 2 );

				// Membership has no course context, so the rate resolves on the
				// instructor: their per-instructor override, else the global
				// default. Same resolver as the one-time path.
				$platform_rate  = self::resolve_platform_rate( 0, $instructor_id );
				$instructor_pct = round( 100 - $platform_rate, 2 );
				$payout         = round( $gross_share * ( $instructor_pct / 100 ), 2 );

				$commission_id = Commission::create(
					[
						'transaction_id' => 0, // Engagement splits are not tied to a single txn.
						'recipient_type' => 'instructor',
						'recipient_id'   => $instructor_id,
						'amount'         => $payout,
						'currency'       => $currency,
						'rate_applied'   => $instructor_pct,
						'payout_period'  => $period,
					]
				);

				// Fire the same hook the per-transaction path uses so
				// downstream listeners (Pro Stripe-Connect auto-payout,
				// notification dispatchers) see engagement-derived
				// commissions too. Canonical signature:
				// ($transaction_id, $commission_id = 0, $context = []). The
				// per-transaction site fires just ($transaction_id); this site
				// fires all three because an engagement commission has no single
				// transaction (arg 1 is 0 by design). The Pro listener declares
				// accepted_args=3 and gates on transaction_id OR commission_id, so
				// this path IS delivered. (It was NOT until 2026-07-12: the listener
				// declared accepted_args=1 and its payout job early-returned on an
				// empty transaction_id, so every membership commission was dropped
				// and those instructors were never auto-paid — seam P0-4.)
				do_action(
					'learnomy_commission_calculated',
					0, // $transaction_id — 0 marks engagement-derived rows.
					(int) $commission_id,
					array(
						'context'       => 'engagement',
						'period'        => $period,
						'plan_id'       => (int) $plan_id,
						'instructor_id' => (int) $row->instructor_id,
						'amount'        => $payout,
						'currency'      => $currency,
					)
				);
			}
		}
	}

	/**
	 * Work out exactly which commission rows a withdrawal request would settle,
	 * and what that actually totals — WITHOUT writing anything.
	 *
	 * A commission row is the atomic unit of settlement: it is either paid out
	 * whole or it stays available. So a request for an arbitrary figure almost
	 * never lands on a row boundary, and the request has to resolve to the row
	 * set that covers it — oldest first, FIFO, so the longest-waiting earnings
	 * are paid first.
	 *
	 * This is the single source of that arithmetic. Both the instructor-facing
	 * preview (so nobody is surprised at settlement time) and
	 * {@see self::request_withdrawal()} call it, so the figure quoted and the
	 * figure recorded can never drift apart.
	 *
	 * Until 2026-07-30 this loop lived inline in request_withdrawal() and its
	 * result was thrown away: the withdrawal recorded the amount the instructor
	 * TYPED while the claim covered whole rows worth more, and approval then
	 * marked all of them `paid`. A $50 request against rows of 43.05/47.95/...
	 * marked $91.00 of earnings paid against a $50.00 payout — the instructor
	 * silently lost $41.00 and the balance was unrecoverable without SQL.
	 *
	 * @param int   $instructor_id Recipient user id.
	 * @param float $amount        Amount the instructor asked for.
	 * @return array{
	 *     requested: float,
	 *     settled: float,
	 *     available: float,
	 *     remaining: float,
	 *     commission_ids: int[],
	 *     lines: int,
	 *     exact: bool,
	 *     error: string
	 * } `error` is '' when the request is settleable, else `invalid_amount`
	 *   or `exceeds_balance`.
	 */
	public static function plan_withdrawal( int $instructor_id, float $amount ): array {
		$pending         = Commission::get_pending_for_instructor( $instructor_id );
		$available_cents = 0;
		foreach ( $pending as $commission ) {
			$available_cents += (int) round( (float) $commission->amount * 100 );
		}

		// Compare in integer cents, not raw floats. Summing DECIMAL columns as
		// PHP floats accumulates binary-representation error, so a request for
		// the *exact* available balance (e.g. 495.60) can land a fraction of a
		// cent above the float sum (495.5999999999…) and be wrongly rejected —
		// an instructor literally could not withdraw their full balance.
		$amount_cents = (int) round( $amount * 100 );

		$plan = array(
			'requested'      => round( $amount, 2 ),
			'settled'        => 0.0,
			'available'      => round( $available_cents / 100, 2 ),
			'remaining'      => round( $available_cents / 100, 2 ),
			'commission_ids' => array(),
			'lines'          => 0,
			'exact'          => false,
			'error'          => '',
		);

		if ( $amount_cents <= 0 ) {
			$plan['error'] = 'invalid_amount';
			return $plan;
		}
		if ( $amount_cents > $available_cents ) {
			$plan['error'] = 'exceeds_balance';
			return $plan;
		}

		// Claim whole rows, oldest first, until the request is covered. The
		// running total is accumulated in cents for the same precision reason as
		// the balance guard above — a raw-float running total can stop one row
		// short when the request lands exactly on a row boundary.
		//
		// The loop always terminates at or before the last pending row because
		// the request is already guaranteed to be <= the available balance, so
		// the settled total can never exceed what the instructor is owed.
		$ids           = array();
		$running_cents = 0;
		foreach ( $pending as $commission ) {
			$ids[]          = (int) $commission->id;
			$running_cents += (int) round( (float) $commission->amount * 100 );
			if ( $running_cents >= $amount_cents ) {
				break;
			}
		}

		$plan['commission_ids'] = $ids;
		$plan['lines']          = count( $ids );
		$plan['settled']        = round( $running_cents / 100, 2 );
		$plan['remaining']      = round( ( $available_cents - $running_cents ) / 100, 2 );
		$plan['exact']          = ( $running_cents === $amount_cents );

		return $plan;
	}

	/**
	 * Create a withdrawal request.
	 *
	 * The recorded `amount` is the SETTLED total from {@see self::plan_withdrawal()},
	 * not the figure the instructor typed. That is the whole point: the payout
	 * (manual approval in Free, `create_transfer()` in Pro) is driven off this
	 * row, so recording the settled total is what makes "value marked paid" and
	 * "value actually paid" the same number. Rounding the request DOWN to a row
	 * boundary was the alternative and was rejected — it pays an instructor less
	 * cash than they asked for, can settle below the owner's configured minimum,
	 * and hard-blocks anyone whose oldest single earning is larger than their
	 * request.
	 *
	 * The typed figure is not lost: when it differs from the settled total the
	 * difference is written to `lrn_audit_log` (audit_log is the home for
	 * historical signal — no new column, no migration), so the trail shows both.
	 *
	 * @param float $amount Amount the instructor asked for; the row records the settled total.
	 * @return int Withdrawal id, or 0 when the request could not be placed.
	 */
	public function request_withdrawal( int $instructor_id, float $amount, string $currency, string $method, string $destination = '' ): int {
		$plan = self::plan_withdrawal( $instructor_id, $amount );
		if ( '' !== $plan['error'] ) {
			return 0;
		}

		$settled = (float) $plan['settled'];

		$withdrawal_id = Withdrawal::request( $instructor_id, $settled, $currency, $method, $destination );
		if ( ! $withdrawal_id ) {
			return 0;
		}

		// Concurrency guard (Basecamp #9928228985). The conditional UPDATE
		// only matches commissions still in 'pending' status, so if a second
		// caller raced us between plan_withdrawal() and here, its UPDATE
		// returns a row count below what we asked to claim. Treat that
		// as a lost race: roll back the withdrawal row we just inserted and
		// report failure. Without this, two concurrent requests can each
		// approve a withdrawal that covers the same commission rows — direct
		// money-impact bug.
		$claimed = Commission::mark_requested_if_pending( $plan['commission_ids'], $withdrawal_id );
		if ( $claimed !== $plan['lines'] ) {
			// Lost race: another request already claimed some of these rows.
			// Return whatever WE flipped back to pending (scoped by this
			// withdrawal's id so we never touch the winner's commissions), then
			// drop the withdrawal row we inserted.
			Commission::revert_requested_to_pending( $withdrawal_id );
			Withdrawal::delete( $withdrawal_id );
			return 0;
		}

		if ( ! $plan['exact'] ) {
			AuditLog::log(
				$instructor_id,
				'commerce',
				'withdrawal.rounded_to_commission_boundary',
				'withdrawal',
				$withdrawal_id,
				array(
					'requested' => $plan['requested'],
					'settled'   => $settled,
					'lines'     => $plan['lines'],
					'currency'  => $currency,
				)
			);
		}

		// Arg 3 is the SETTLED amount, so any payout rail listening here (Pro's
		// Stripe Connect transfers on exactly this hook) moves the same figure
		// the commission rows are worth.
		do_action( 'learnomy_withdrawal_requested', $withdrawal_id, $instructor_id, $settled );

		return $withdrawal_id;
	}

	/**
	 * Move a withdrawal to `processed` and settle the commissions it claimed.
	 *
	 * The ONE place a withdrawal is settled — admin approval and an external
	 * payout rail (`learnomy_withdrawal_paid`) both come through here, so the
	 * two can never each half-settle the same row.
	 *
	 * Idempotent by construction: the status transition is a conditional UPDATE
	 * that only matches a row still `pending`, so a replayed approval (double
	 * click, retry, a Stripe transfer racing an admin click) loses the race and
	 * returns false without touching a single commission row or firing a second
	 * payout.
	 *
	 * @param int    $withdrawal_id Withdrawal row id.
	 * @param string $reference     Payout reference (provider transfer id, cheque number…).
	 * @return bool True when THIS call performed the settlement.
	 */
	public static function settle_withdrawal( int $withdrawal_id, string $reference = '' ): bool {
		if ( $withdrawal_id <= 0 ) {
			return false;
		}

		if ( ! Withdrawal::mark_processed_if_pending( $withdrawal_id, $reference ) ) {
			return false;
		}

		// Settle the commissions this withdrawal claimed: move them out of the
		// `requested` limbo into `paid`. On a Free-only site nothing else does
		// this (Pro's Stripe-Connect auto-payout is the only other caller of
		// Commission::mark_paid), so without it approved payouts left
		// commissions permanently mislabelled `requested`.
		Commission::mark_paid_for_withdrawal( $withdrawal_id );

		return true;
	}
}
