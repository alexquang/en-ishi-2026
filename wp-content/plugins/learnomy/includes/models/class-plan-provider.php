<?php
/**
 * Plan-to-provider mapping — records the provider-specific price/plan ID for
 * each (membership plan, provider, mode) triple.
 *
 * One row per combination. The UNIQUE (plan_id, provider, mode) constraint in
 * the DB table makes upserts safe and deterministic. Phase 2 payment adapters
 * will read from this model rather than the scattered
 * `learnomy_stripe_price_<n>` / `learnomy_paypal_plan_<n>` wp_options that
 * currently have no admin UI and are invisible to QA testers.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Plan_Provider extends Model {

	/**
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'plan' => 'plan_id',
	);

	protected static function table_name(): string {
		return 'membership_plan_providers';
	}

	protected static function cache_group(): string {
		return 'learnomy_plan_providers';
	}

	/**
	 * Look up the provider-specific price/plan ID for a given combination.
	 *
	 * Returns null when no mapping has been configured — callers should treat
	 * null as "this provider is not set up for this plan in this mode."
	 *
	 * @param int    $plan_id  Learnomy membership plan ID.
	 * @param string $provider 'stripe' | 'paypal'.
	 * @param string $mode     'test' | 'live' | 'sandbox'.
	 * @return string|null Provider price/plan ID, or null if not set.
	 */
	public static function get_provider_plan_id( int $plan_id, string $provider, string $mode ): ?string {
		$last_changed = wp_cache_get_last_changed( static::cache_group() );
		$cache_key    = "plan_provider_id:{$plan_id}:{$provider}:{$mode}:{$last_changed}";

		$cached = wp_cache_get( $cache_key, static::cache_group() );
		if ( false !== $cached ) {
			return '' === $cached ? null : (string) $cached;
		}

		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT provider_plan_id FROM ' . static::table() . ' WHERE plan_id = %d AND provider = %s AND mode = %s LIMIT 1',
				$plan_id,
				$provider,
				$mode
			)
		);

		// Cache empty string to represent "not found" so we don't hit the DB
		// on every request for an unconfigured plan.
		$value = $row ? (string) $row->provider_plan_id : '';
		wp_cache_set( $cache_key, $value, static::cache_group() );

		return $row ? $value : null;
	}

	/**
	 * Insert or update the mapping for a (plan_id, provider, mode) triple.
	 *
	 * Uses INSERT … ON DUPLICATE KEY UPDATE so concurrent writes on the same
	 * row converge safely. Bumps the cache incrementor so stale cached lookups
	 * are orphaned immediately.
	 *
	 * @param int    $plan_id          Learnomy membership plan ID.
	 * @param string $provider         'stripe' | 'paypal'.
	 * @param string $mode             'test' | 'live' | 'sandbox'.
	 * @param string $provider_plan_id Stripe price_xxx or PayPal P-xxx.
	 * @return bool True on success, false on DB error.
	 */
	public static function upsert( int $plan_id, string $provider, string $mode, string $provider_plan_id ): bool {
		$now = now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = static::db()->query(
			static::db()->prepare(
				'INSERT INTO ' . static::table() . ' (plan_id, provider, mode, provider_plan_id, created_at, updated_at)
				VALUES (%d, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE provider_plan_id = VALUES(provider_plan_id), updated_at = VALUES(updated_at)',
				$plan_id,
				$provider,
				$mode,
				$provider_plan_id,
				$now,
				$now
			)
		);

		if ( false !== $result ) {
			static::invalidate_cache();
			return true;
		}

		return false;
	}

	/**
	 * All provider mappings configured for a given plan.
	 *
	 * Returns rows ordered by provider ASC, mode ASC for consistent display in
	 * the Phase 4 plan-edit admin UI. Empty array when no mappings exist yet.
	 *
	 * @param int $plan_id Learnomy membership plan ID.
	 * @return object[] Rows from lrn_membership_plan_providers.
	 */
	public static function get_all_for_plan( int $plan_id ): array {
		$last_changed = wp_cache_get_last_changed( static::cache_group() );
		$cache_key    = "plan_all_providers:{$plan_id}:{$last_changed}";

		$cached = wp_cache_get( $cache_key, static::cache_group() );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE plan_id = %d ORDER BY provider ASC, mode ASC',
				$plan_id
			)
		);

		$rows = is_array( $rows ) ? $rows : [];
		wp_cache_set( $cache_key, $rows, static::cache_group() );

		return $rows;
	}

	/**
	 * Delete a specific plan-provider-mode mapping.
	 *
	 * Typically called when a plan is deleted or the admin clears a price ID
	 * from the plan-edit UI.
	 *
	 * @param int    $plan_id  Learnomy membership plan ID.
	 * @param string $provider 'stripe' | 'paypal'.
	 * @param string $mode     'test' | 'live' | 'sandbox'.
	 * @return bool True on success (including no-op when row absent), false on DB error.
	 */
	public static function delete_mapping( int $plan_id, string $provider, string $mode ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = false !== static::db()->delete(
			static::table(),
			[
				'plan_id'  => $plan_id,
				'provider' => $provider,
				'mode'     => $mode,
			],
			[ '%d', '%s', '%s' ]
		);

		if ( $ok ) {
			static::invalidate_cache();
		}

		return $ok;
	}

	/**
	 * Delete every provider mapping for a plan in one statement.
	 *
	 * Used during plan cleanup (hard-delete) so no stale Stripe/PayPal price/plan
	 * IDs remain registered against a plan that no longer exists. Mirrors
	 * MembershipPlanCourse::delete_for_plan() — a single scoped DELETE rather than
	 * a per-row loop, with one cache invalidation.
	 *
	 * @param int $plan_id Learnomy membership plan ID.
	 * @return bool True on success (including no-op when no rows exist), false on DB error.
	 */
	public static function delete_all_for_plan( int $plan_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = false !== static::db()->delete(
			static::table(),
			[ 'plan_id' => $plan_id ],
			[ '%d' ]
		);

		if ( $ok ) {
			static::invalidate_cache();
		}

		return $ok;
	}
}
