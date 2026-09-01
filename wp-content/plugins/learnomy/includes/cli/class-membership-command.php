<?php
/**
 * WP-CLI nested command group: wp learnomy membership <subcommand>.
 *
 * @package Learnomy
 */

namespace Learnomy\CLI;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\MembershipPlan;
use Learnomy\Models\Subscription;

/**
 * Manage Learnomy membership plans from the command line.
 *
 * Registered as `wp learnomy membership <subcommand>`.
 */
class Membership_Command {

	/**
	 * List membership plans.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: table, csv, json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy membership list
	 *     wp learnomy membership list --format=csv
	 *
	 * @subcommand list
	 */
	public function list_( array $args, array $assoc_args ): void {
		$format = sanitize_text_field( $assoc_args['format'] ?? 'table' );
		$plans  = MembershipPlan::where( [], 'sort_order', 'ASC' );

		if ( empty( $plans ) ) {
			\WP_CLI::warning( __( 'No membership plans found.', 'learnomy' ) );
			return;
		}

		$items = array_map(
			function ( $plan ) {
				$subscribers = Subscription::count( [ 'plan_id' => (int) $plan->id ] );
				return [
					'id'          => $plan->id,
					'name'        => $plan->title,
					'price'       => $plan->price,
					'interval'    => $plan->billing_interval ?? '',
					'status'      => $plan->status,
					'subscribers' => $subscribers,
				];
			},
			$plans
		);

		\WP_CLI\Utils\format_items( $format, $items, [ 'id', 'name', 'price', 'interval', 'status', 'subscribers' ] );
	}

	/**
	 * Create a new membership plan.
	 *
	 * ## OPTIONS
	 *
	 * --name=<name>
	 * : Plan display name.
	 *
	 * --price=<price>
	 * : Plan price.
	 *
	 * [--interval=<interval>]
	 * : Billing interval. Default: monthly.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy membership create --name="Pro Plan" --price=29.99
	 *     wp learnomy membership create --name="Annual" --price=199 --interval=yearly
	 *
	 * @subcommand create
	 */
	public function create( array $args, array $assoc_args ): void {
		if ( empty( $assoc_args['name'] ) || ! isset( $assoc_args['price'] ) ) {
			\WP_CLI::error( __( '--name and --price are required.', 'learnomy' ) );
		}

		$name     = sanitize_text_field( $assoc_args['name'] );
		$price    = (float) $assoc_args['price'];
		$interval = sanitize_text_field( $assoc_args['interval'] ?? 'monthly' );
		$slug     = sanitize_title( $name );

		$plan_id = MembershipPlan::create(
			[
				'name'             => $name,
				'slug'             => $slug,
				'price'            => $price,
				'billing_interval' => $interval,
				'status'           => 'active',
			]
		);

		if ( is_wp_error( $plan_id ) ) {
			\WP_CLI::error( $plan_id->get_error_message() );
		}

		if ( ! $plan_id ) {
			\WP_CLI::error( __( 'Could not create membership plan.', 'learnomy' ) );
		}

		\WP_CLI::success(
			/* translators: 1: plan name 2: plan ID */
			sprintf( __( 'Membership plan "%1$s" created (ID %2$d).', 'learnomy' ), $name, $plan_id )
		);
	}
}
