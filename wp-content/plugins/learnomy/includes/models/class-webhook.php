<?php
/**
 * Outbound webhooks — registered endpoints for event delivery.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Webhook extends Model {

	protected static function table_name(): string {
		return 'webhooks';
	}

	/**
	 * Default maximum number of active outbound webhooks on Free.
	 *
	 * Documented as "lrn_webhooks (outgoing, max 3 free)". Pro raises or
	 * removes the cap through the `learnomy_max_webhooks` filter rather than
	 * duplicating the model — Pro extends Free.
	 */
	const DEFAULT_MAX = 3;

	/**
	 * Resolve the active-webhook cap.
	 *
	 * The cap is filterable via `learnomy_max_webhooks` so Pro can raise it
	 * (return a higher integer) or remove it entirely (return 0, meaning
	 * "no limit"). Free always resolves to {@see self::DEFAULT_MAX}.
	 *
	 * @return int The cap, or 0 for unlimited.
	 */
	public static function max_allowed(): int {
		/**
		 * Filter the maximum number of active outbound webhooks.
		 *
		 * @param int $max The cap. Return 0 to allow an unlimited number.
		 */
		$max = (int) apply_filters( 'learnomy_max_webhooks', self::DEFAULT_MAX );

		return max( 0, $max );
	}

	/**
	 * Count the webhooks that currently count toward the cap.
	 *
	 * Only active webhooks consume a slot — an inactive (paused) endpoint
	 * is not delivering, so it does not multiply outbound fan-out.
	 */
	public static function active_count(): int {
		return static::count( [ 'status' => 'active' ] );
	}

	/**
	 * Whether another active webhook can be created under the current cap.
	 */
	public static function can_create(): bool {
		$max = static::max_allowed();

		// 0 means "no limit" (Pro removed the cap).
		if ( 0 === $max ) {
			return true;
		}

		return static::active_count() < $max;
	}

	public static function create( array $webhook ): int {
		// Defense in depth — the REST controller is the primary gate, but
		// never let any caller push past the cap straight into the table.
		$status = $webhook['status'] ?? 'active';
		if ( 'active' === $status && ! static::can_create() ) {
			return 0;
		}

		return static::insert( array_merge( [ 'created_at' => now() ], $webhook ) );
	}

	/**
	 * Delete a webhook and announce it, so rows hanging off it can be cleaned up.
	 *
	 * The action used to fire in the REST controller only, which meant it
	 * announced "a webhook was deleted" for exactly one of the ways a webhook can
	 * be deleted. Every other caller — the demo seeder's cleanup, WP-CLI, any
	 * extension — removed the row silently and left Pro's delivery log pointing at
	 * a webhook that no longer exists.
	 *
	 * Firing from the model makes the announcement a property of deletion rather
	 * than of one route.
	 *
	 * @param int $id Webhook row ID.
	 */
	public static function delete( int $id ): bool {
		$deleted = parent::delete( $id );

		if ( $deleted ) {
			/**
			 * Fires after a webhook is deleted so extensions can clean up rows
			 * that hang off it. Safe when Pro is absent — nothing listens.
			 *
			 * @param int $id The deleted webhook's row ID.
			 */
			do_action( 'learnomy_webhook_deleted', $id );
		}

		return $deleted;
	}

	public static function get_active(): array {
		return static::db()->get_results(
			'SELECT * FROM ' . static::table() . " WHERE status = 'active' ORDER BY created_at ASC"
		);
	}

	public static function update_last_triggered( int $id ): bool {
		return static::update( $id, [ 'last_triggered_at' => now() ] );
	}
}
