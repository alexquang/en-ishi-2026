<?php
namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_1_1_0 {

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'lrn_quizzes';
		$col   = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'display_template'" );

		if ( empty( $col ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN display_template VARCHAR(30) NOT NULL DEFAULT 'focused' AFTER feedback_timing" );
		}
	}
}
