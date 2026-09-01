<?php
/**
 * Version-based schema migrations.
 *
 * @package Learnomy
 */

namespace Learnomy\DB;

defined( 'ABSPATH' ) || exit;

class Migrator {

	public static function run( string $from_version ): void {
		foreach ( self::get_migrations() as $version => $class ) {
			if ( version_compare( $from_version, $version, '<' ) ) {
				require_once LEARNOMY_DIR . "includes/db/migrations/class-migration-{$class}.php";
				$fqn = "Learnomy\\DB\\Migrations\\Migration_{$class}";
				( new $fqn() )->up();
				update_option( 'learnomy_db_version', $version );
			}
		}
	}

	// Pre-release: schema changes go in Schema::create_tables().
	// After 1.0 ships, add '1.1.0' => '1_1_0' entries here.
	private static function get_migrations(): array {
		return [
			'1.1.0'  => '1_1_0',
			'1.2.0'  => '1_2_0',
			'1.3.0'  => '1_3_0',
			'1.4.0'  => '1_4_0',
			'1.5.0'  => '1_5_0',
			'1.6.0'  => '1_6_0',
			'1.7.0'  => '1_7_0',
			'1.8.0'  => '1_8_0',
			'1.9.0'  => '1_9_0',
			'2.0.0'  => '2_0_0',
			'2.1.0'  => '2_1_0',
			'2.2.0'  => '2_2_0',
			'2.3.0'  => '2_3_0',
			'2.4.0'  => '2_4_0',
			'2.5.0'  => '2_5_0',
			'2.6.0'  => '2_6_0',
			'2.7.0'  => '2_7_0',
			'2.8.0'  => '2_8_0',
			'2.9.0'  => '2_9_0',
			'3.0.0'  => '3_0_0',
			'3.1.0'  => '3_1_0',
			'3.2.0'  => '3_2_0',
			'3.3.0'  => '3_3_0',
			'3.4.0'  => '3_4_0',
			'3.5.0'  => '3_5_0',
			'3.6.0'  => '3_6_0',
			'3.7.0'  => '3_7_0',
			'3.8.0'  => '3_8_0',
			'3.9.0'  => '3_9_0',
			'3.10.0' => '3_10_0',
			'3.11.0' => '3_11_0',
			'3.12.0' => '3_12_0',
			'3.13.0' => '3_13_0',
			'3.14.0' => '3_14_0',
			'3.15.0' => '3_15_0',
			'3.16.0' => '3_16_0',
			'3.17.0' => '3_17_0',
			'3.18.0' => '3_18_0',
			'3.19.0' => '3_19_0',
			'3.20.0' => '3_20_0',
			'3.21.0' => '3_21_0',
			'3.22.0' => '3_22_0',
			'3.23.0' => '3_23_0',
			'3.24.0' => '3_24_0',
			'3.25.0' => '3_25_0',
			'3.26.0' => '3_26_0',
			'3.27.0' => '3_27_0',
			'3.28.0' => '3_28_0',
			'3.29.0' => '3_29_0',
			'3.30.0' => '3_30_0',
			'3.31.0' => '3_31_0',
			'3.32.0' => '3_32_0',
			'3.33.0' => '3_33_0',
			// 3.34 and 3.36-3.38 were reserved during parallel work and never
			// needed a schema change. The gaps are deliberate: this map lists
			// migrations that EXIST, and renumbering to close a gap would
			// re-run a migration on sites that already recorded the old number.
			'3.35.0' => '3_35_0',
			'3.39.0' => '3_39_0',
			'3.40.0' => '3_40_0',
			'3.41.0' => '3_41_0',
			'3.42.0' => '3_42_0',
			'3.43.0' => '3_43_0',
			'3.44.0' => '3_44_0',
			'3.45.0' => '3_45_0',
			'3.46.0' => '3_46_0',
			'3.47.0' => '3_47_0',
			'3.48.0' => '3_48_0',
		];
	}
}
