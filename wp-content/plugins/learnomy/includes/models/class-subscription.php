<?php
/**
 * Member subscription — tracks active/cancelled billing relationships.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;
use function Learnomy\table;

class Subscription extends Model {

	/**
	 * KEEP on both. A subscription is live billing state: it may still be
	 * charging, still be owed a refund, and still need cancelling with the
	 * provider. Deleting the plan or the course it was sold against must not
	 * quietly remove the owner's record of it. Nothing deletes these rows
	 * today either, so this declares the behaviour rather than changing it.
	 *
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'plan'   => array(
			'column'    => 'plan_id',
			'on_delete' => \Learnomy\DB\Relations::KEEP,
		),
		'course' => array(
			'column'    => 'course_id',
			'on_delete' => \Learnomy\DB\Relations::KEEP,
		),
	);


	protected static function table_name(): string {
		return 'member_subscriptions';
	}

	public static function create( array $subscription ): int {
		return static::insert( array_merge( [ 'created_at' => now() ], $subscription ) );
	}

	/**
	 * Delete a subscription and announce it, so rows hanging off it go too.
	 *
	 * Nothing fired on deletion, and nothing anywhere deletes
	 * `lrn_pro_dunning_attempts` — so every subscription removed by the demo
	 * seeder, WP-CLI or an extension left its dunning history behind pointing at
	 * an id that no longer exists. On the model site that is 249 of 267 rows.
	 *
	 * Same shape as Webhook::delete(): the announcement belongs to deletion
	 * rather than to whichever route happened to call it, so every caller
	 * cascades without each one having to remember.
	 *
	 * @param int $id Subscription row ID.
	 */
	public static function delete( int $id ): bool {
		$deleted = parent::delete( $id );

		if ( $deleted ) {
			/**
			 * Fires after a subscription is deleted so extensions can clean up
			 * rows that hang off it. Safe when Pro is absent — nothing listens.
			 *
			 * @param int $id The deleted subscription's row ID.
			 */
			do_action( 'learnomy_subscription_deleted', $id );
		}

		return $deleted;
	}

	public static function get_active_for_user( int $user_id ): array {
		// 'trialing' counts as active access — the trial window is inside
		// current_period_end so the time-gate filter still keeps it honest.
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . "
				WHERE user_id = %d
				AND status IN ( 'active', 'trialing', 'past_due' )
				AND current_period_end > %s
				ORDER BY created_at DESC",
				$user_id,
				now()
			)
		);
	}

	/**
	 * All of a user's "live" subscriptions — including paused ones — so the
	 * manage page can surface the resume-from-pause button. Access decisions
	 * still use get_active_for_user() which excludes paused.
	 */
	public static function get_manageable_for_user( int $user_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . "
				WHERE user_id = %d
				AND status IN ( 'active', 'trialing', 'past_due', 'paused' )
				ORDER BY created_at DESC",
				$user_id
			)
		);
	}

	/**
	 * How many "live" subscriptions exist site-wide — the same status set as
	 * get_manageable_for_user(), asked without a user.
	 *
	 * COUNT(*), never count( get_all() ): this answers a "how many people are
	 * still being billed?" question on the Modules page, which must stay cheap
	 * on a site with 100k subscription rows.
	 */
	public static function count_live(): int {
		return (int) static::db()->get_var(
			'SELECT COUNT(*) FROM ' . static::table() . "
			WHERE status IN ( 'active', 'trialing', 'past_due', 'paused' )"
		);
	}

	/**
	 * Whether ONE user still has a live subscription. Used to decide whether a
	 * member keeps their billing-management surface after the site owner
	 * switches the membership module off — someone who is still being charged
	 * must never lose the page where they can see and stop the charge.
	 *
	 * LIMIT 1 rather than a full count: the caller only asks yes/no, and this
	 * runs on every account page render.
	 */
	public static function user_has_live( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return (bool) static::db()->get_var(
			static::db()->prepare(
				'SELECT 1 FROM ' . static::table() . "
				WHERE user_id = %d
				AND status IN ( 'active', 'trialing', 'past_due', 'paused' )
				LIMIT 1",
				$user_id
			)
		);
	}

	public static function get_for_plan( int $plan_id ): array {
		return static::where( [ 'plan_id' => $plan_id ], 'created_at', 'DESC' );
	}

	/**
	 * Batched active-subscriber counts for a set of plans — one GROUP BY query
	 * instead of one count per plan in the admin plans list. Returns a map of
	 * plan_id => active_count (status = 'active'); plans with zero active
	 * subscribers are absent from the map, so callers default missing keys to 0.
	 *
	 * Mirrors the per-row Subscription::where(['plan_id'=>x,'status'=>'active'])
	 * the memberships list used to run, so the rendered counts are identical.
	 * Index-bound on KEY plan_status (plan_id, status).
	 *
	 * @param int[] $plan_ids Plan IDs to count for.
	 * @return array<int,int> plan_id => active subscriber count.
	 */
	public static function count_active_by_plan( array $plan_ids ): array {
		$plan_ids = array_values( array_unique( array_filter( array_map( 'intval', $plan_ids ) ) ) );
		if ( ! $plan_ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $plan_ids ), '%d' ) );
		$rows         = static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT plan_id, COUNT(*) AS total FROM ' . static::table() . " WHERE status = 'active' AND plan_id IN ({$placeholders}) GROUP BY plan_id",
				...$plan_ids
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->plan_id ] = (int) $row->total;
		}
		return $out;
	}

	/**
	 * Active subscription targeting a specific course, owned by a user. Used
	 * by Subscription_Service::has_access() to grant access via course-level
	 * recurring subscriptions (added in PR-A1).
	 *
	 * Returns the most-recent live row when one exists; null otherwise.
	 */
	public static function find_active_for_user_course( int $user_id, int $course_id ): ?object {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return null;
		}
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . "
				WHERE user_id = %d
				AND course_id = %d
				AND status IN ( 'active', 'trialing', 'past_due' )
				AND current_period_end > %s
				ORDER BY created_at DESC
				LIMIT 1",
				$user_id,
				$course_id,
				now()
			)
		) ?: null;
	}

	/**
	 * All subscriptions (active or otherwise) for a user, joined to show
	 * either the plan title or the course title in one query so the billing
	 * page can render uniform rows without a per-row Course::find lookup.
	 *
	 * Returns rows with: id, user_id, plan_id, course_id, status,
	 * payment_provider, current_period_start, current_period_end,
	 * trial_ends_at, cancelled_at, cancel_at_period_end, created_at,
	 * plus virtual columns target_type ('plan'|'course'), target_id,
	 * target_title.
	 */
	public static function get_all_for_user_with_target( int $user_id ): array {
		$courses_table = \Learnomy\table( 'courses' );
		$plans_table   = \Learnomy\table( 'membership_plans' );

		return static::db()->get_results(
			static::db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'SELECT s.*,
					CASE WHEN s.course_id IS NOT NULL THEN "course" ELSE "plan" END AS target_type,
					COALESCE(s.course_id, s.plan_id) AS target_id,
					COALESCE(c.title, p.title) AS target_title
				FROM ' . static::table() . " s
				LEFT JOIN {$courses_table} c ON c.id = s.course_id
				LEFT JOIN {$plans_table}   p ON p.id = s.plan_id
				WHERE s.user_id = %d
				ORDER BY s.created_at DESC",
				$user_id
			)
		) ?: array();
	}

	public static function cancel( int $id ): bool {
		return static::update(
			$id,
			[
				'status'       => 'cancelled',
				'cancelled_at' => now(),
			]
		);
	}

	/** Flag a subscription to cancel automatically when the paid period ends. */
	public static function flag_cancel_at_period_end( int $id ): bool {
		return static::update(
			$id,
			[
				'cancel_at_period_end' => 1,
				'cancelled_at'         => now(),
			]
		);
	}

	/** Reverse a pending "cancel at period end" flag — subscriber changed mind. */
	public static function resume( int $id ): bool {
		return static::update(
			$id,
			[
				'cancel_at_period_end' => 0,
				'cancelled_at'         => null,
			]
		);
	}

	/**
	 * Checks both status field and whether the billing period is still live.
	 * `trialing` and `past_due` both still grant access — grace is decided
	 * by the service, which may hook in to shorten or extend the window.
	 */
	public static function is_active( int $id ): bool {
		$sub = static::find( $id );
		if ( ! $sub ) {
			return false;
		}
		$live_statuses = [ 'active', 'trialing', 'past_due' ];
		return in_array( $sub->status, $live_statuses, true )
			&& strtotime( $sub->current_period_end ) > time();
	}

	/** Full subscription history for a user, newest first. */
	public static function get_history_for_user( int $user_id, int $limit = 20 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE user_id = %d ORDER BY created_at DESC LIMIT %d',
				$user_id,
				$limit
			)
		);
	}

	/**
	 * Active subscriptions whose billing period has already ended.
	 *
	 * Bounded batch: the renewal cron advances each row's period_end (or
	 * expires it), so a processed row drops out of this set and the backlog
	 * drains over successive ticks. The default cap keeps a mass-failure event
	 * (provider outage, card-expiry wave) from loading the whole table into PHP.
	 */
	public static function get_overdue( int $limit = 500 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE status = 'active' AND current_period_end < NOW() ORDER BY current_period_end ASC LIMIT %d",
				$limit
			)
		);
	}

	/** Active subscriptions expiring within the given number of days. */
	public static function get_expiring_soon( int $days = 7 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . " WHERE status = 'active' AND current_period_end BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	public static function find_by_provider_id( string $provider_id ): ?object {
		return static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE provider_subscription_id = %s LIMIT 1',
				$provider_id
			)
		) ?: null;
	}

	/**
	 * Active subscriptions expiring between $now and $until — for reminder cron.
	 *
	 * Cursor-batched by id (mirrors Enrollment::get_expiring_between) so a large
	 * expiry window is walked in bounded chunks instead of loading the whole set
	 * into PHP. The reminder send writes a notification row but never mutates the
	 * subscription, so each id appears in exactly one batch as `$after_id` advances.
	 */
	public static function get_expiring_between( string $now, string $until, int $limit = 500, int $after_id = 0 ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT id, user_id, plan_id, current_period_end
				FROM ' . static::table() . "
				WHERE status = 'active'
				AND current_period_end > %s
				AND current_period_end <= %s
				AND id > %d
				ORDER BY id ASC
				LIMIT %d",
				$now,
				$until,
				$after_id,
				$limit
			)
		) ?: [];
	}

	/**
	 * Live subscriptions whose current_period_end has lapsed — used by the
	 * daily sweep to decide between expire() and the renewal safety net.
	 */
	public static function get_due_for_sweep( int $limit = 500 ): array {
		// Bounded batch: the daily sweep expire()s or renew()s each row, so a
		// processed row leaves this set and the backlog drains over ticks. The
		// cap prevents a full-table load into PHP during a mass-lapse event.
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT id, user_id, plan_id, status, current_period_end, cancel_at_period_end, trial_ends_at
				FROM ' . static::table() . "
				WHERE current_period_end <= NOW()
				AND status IN ( 'active', 'trialing', 'past_due' )
				ORDER BY current_period_end ASC LIMIT %d",
				$limit
			)
		) ?: [];
	}

	/**
	 * Trialing subscriptions whose trial_ends_at falls on or before $until —
	 * used for the trial-conversion cron.
	 */
	public static function get_trials_ending_by( string $until ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT id, user_id, plan_id, trial_ends_at
				FROM ' . static::table() . "
				WHERE status = 'trialing'
				AND trial_ends_at IS NOT NULL
				AND trial_ends_at <= %s",
				$until
			)
		) ?: [];
	}

	/**
	 * Trialing subscriptions with trial_ends_at inside a reminder window —
	 * used by the trial-ending reminder cron before filtering by sentinel.
	 */
	public static function get_trials_ending_between( string $now, string $until ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT id, user_id, plan_id, trial_ends_at
				FROM ' . static::table() . "
				WHERE status = 'trialing'
				AND trial_ends_at IS NOT NULL
				AND trial_ends_at > %s
				AND trial_ends_at <= %s",
				$now,
				$until
			)
		) ?: [];
	}

	/**
	 * Recurring-revenue snapshot for the Earnings dashboard.
	 *
	 * Returns three counters — active subscriber count, monthly recurring
	 * revenue (normalized from every billing interval) and an approximation
	 * of trial conversions this month (active subs created this month that
	 * carry a trial_ends_at). Pro replaces this with a full analytics page.
	 *
	 * @return array{active_count: int, monthly_recurring: float, trial_conversions_this_month: int}
	 */
	public static function get_mrr_stats(): array {
		$plans_table = table( 'membership_plans' );

		// Active count — subscriptions currently granting access.
		$active_count = (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . "
				WHERE status IN ( 'active', 'trialing', 'past_due' )
				AND current_period_end > %s",
				now()
			)
		);

		// Monthly recurring — sum of plan price normalized to a month. Uses
		// CASE on billing_interval so a single query covers every cadence.
		// Fallback factors match the spec: monthly=1, yearly/12, weekly*4.33,
		// daily*30.42. interval_count multiplies against the base unit so
		// "every 2 months" divides by 2.
		$mrr = (float) static::db()->get_var(
			static::db()->prepare(
				"SELECT COALESCE( SUM(
					CASE LOWER( p.billing_interval )
						WHEN 'yearly'  THEN ( p.price / 12 ) / GREATEST( p.interval_count, 1 )
						WHEN 'monthly' THEN p.price / GREATEST( p.interval_count, 1 )
						WHEN 'weekly'  THEN ( p.price * 4.33 ) / GREATEST( p.interval_count, 1 )
						WHEN 'daily'   THEN ( p.price * 30.42 ) / GREATEST( p.interval_count, 1 )
						ELSE p.price
					END
				), 0 )
				FROM " . static::table() . ' s
				INNER JOIN ' . $plans_table . " p ON p.id = s.plan_id
				WHERE s.status IN ( 'active', 'trialing' )
				AND s.current_period_end > %s",
				now()
			)
		);

		// Trial conversions this month — approximated as active subs
		// created this month that were seeded with a trial window. Pro
		// tracks real transitions in lrn_pro_subscription_changes.
		$month_start = gmdate( 'Y-m-01 00:00:00' );
		$conversions = (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT COUNT(*) FROM ' . static::table() . "
				WHERE status = 'active'
				AND trial_ends_at IS NOT NULL
				AND created_at >= %s",
				$month_start
			)
		);

		return [
			'active_count'                 => $active_count,
			'monthly_recurring'            => round( $mrr, 2 ),
			'trial_conversions_this_month' => $conversions,
		];
	}
}
