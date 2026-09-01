<?php
/**
 * WP-CLI: manage Learnomy modules from the command line.
 *
 * Registers as `wp learnomy module <subcommand>`.
 *
 * @package Learnomy\CLI
 */

namespace Learnomy\CLI;

use Learnomy\Modules\Module_Kernel;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Learnomy module enable/disable state.
 */
class Module {

	/**
	 * List all modules with status and enabled state.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Default: table. Options: table, json, csv, yaml.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy module list
	 *     wp learnomy module list --format=json
	 *
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ): void {
		$kernel  = Module_Kernel::instance();
		$catalog = Module_Kernel::catalog();

		$rows = array();
		foreach ( $catalog as $slug => $module ) {
			$rows[] = array(
				'slug'        => $slug,
				'name'        => $module['name'],
				'category'    => $module['category'] ?? '',
				'enabled'     => $kernel->is_enabled( $slug ) ? 'yes' : 'no',
				'required'    => ! empty( $module['required'] ) ? 'yes' : 'no',
				'pro'         => ! empty( $module['is_pro'] ) ? 'yes' : 'no',
				'description' => $module['description'],
			);
		}

		$format = $assoc_args['format'] ?? 'table';
		\WP_CLI\Utils\format_items( $format, $rows, array( 'slug', 'name', 'category', 'enabled', 'required', 'pro', 'description' ) );
	}

	/**
	 * Enable a module.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The module slug to enable.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy module enable membership
	 *
	 * @when after_wp_load
	 */
	public function enable( $args ): void {
		$slug    = $args[0] ?? '';
		$catalog = Module_Kernel::catalog();

		if ( ! isset( $catalog[ $slug ] ) ) {
			\WP_CLI::error( sprintf( 'Unknown module: %s', $slug ) );
		}

		Module_Kernel::instance()->enable( $slug );
		\WP_CLI::success( sprintf( 'Module enabled: %s', $slug ) );
	}

	/**
	 * Disable a module.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The module slug to disable.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy module disable membership
	 *
	 * @when after_wp_load
	 */
	public function disable( $args ): void {
		$slug    = $args[0] ?? '';
		$catalog = Module_Kernel::catalog();

		if ( ! isset( $catalog[ $slug ] ) ) {
			\WP_CLI::error( sprintf( 'Unknown module: %s', $slug ) );
		}

		if ( in_array( $slug, Module_Kernel::required(), true ) ) {
			\WP_CLI::error( sprintf( 'Module is required and cannot be disabled: %s', $slug ) );
		}

		Module_Kernel::instance()->disable( $slug );
		\WP_CLI::success( sprintf( 'Module disabled: %s', $slug ) );
	}

	/**
	 * Show details about a module.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The module slug to inspect.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy module info membership
	 *
	 * @when after_wp_load
	 */
	public function info( $args ): void {
		$slug    = $args[0] ?? '';
		$catalog = Module_Kernel::catalog();

		if ( ! isset( $catalog[ $slug ] ) ) {
			\WP_CLI::error( sprintf( 'Unknown module: %s', $slug ) );
		}

		$module = $catalog[ $slug ];
		$rows   = array(
			array(
				'field' => 'slug',
				'value' => $slug,
			),
			array(
				'field' => 'name',
				'value' => $module['name'],
			),
			array(
				'field' => 'category',
				'value' => $module['category'] ?? '',
			),
			array(
				'field' => 'description',
				'value' => $module['description'],
			),
			array(
				'field' => 'default_enabled',
				'value' => ! empty( $module['default'] ) ? 'yes' : 'no',
			),
			array(
				'field' => 'required',
				'value' => ! empty( $module['required'] ) ? 'yes' : 'no',
			),
			array(
				'field' => 'pro',
				'value' => ! empty( $module['is_pro'] ) ? 'yes' : 'no',
			),
			array(
				'field' => 'currently_enabled',
				'value' => Module_Kernel::instance()->is_enabled( $slug ) ? 'yes' : 'no',
			),
		);

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
	}
}
