<?php
/**
 * WP-CLI nested command group: wp learnomy db <subcommand>.
 *
 * @package Learnomy
 */

namespace Learnomy\CLI;

defined( 'ABSPATH' ) || exit;

use Learnomy\DB\Schema;
use Learnomy\DB\Migrator;

/**
 * Manage the Learnomy database schema from the command line.
 *
 * Registered as `wp learnomy db <subcommand>`.
 */
class DB_Command {

	/**
	 * Run database migrations.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy db migrate
	 *
	 * @subcommand migrate
	 */
	public function migrate( array $args, array $assoc_args ): void {
		require_once LEARNOMY_DIR . 'includes/db/class-schema.php';
		require_once LEARNOMY_DIR . 'includes/db/class-migrator.php';

		Schema::create_tables();

		$from = get_option( 'learnomy_db_version', '0.0.0' );
		Migrator::run( $from );

		update_option( 'learnomy_db_version', LEARNOMY_DB_VERSION );

		\WP_CLI::success( __( 'Database schema is up to date.', 'learnomy' ) );
	}

	/**
	 * Drop all Learnomy tables and recreate them from scratch.
	 *
	 * This is a destructive operation that removes all LMS data.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy db reset --yes
	 *
	 * @subcommand reset
	 */
	public function reset( array $args, array $assoc_args ): void {
		\WP_CLI::confirm(
			__( 'This will delete ALL Learnomy data (courses, enrollments, progress, etc.). Are you sure?', 'learnomy' ),
			$assoc_args
		);

		require_once LEARNOMY_DIR . 'includes/db/class-schema.php';

		Schema::drop_tables();
		Schema::create_tables();

		update_option( 'learnomy_db_version', LEARNOMY_DB_VERSION );

		\WP_CLI::success( __( 'All Learnomy tables have been reset.', 'learnomy' ) );
	}
}
