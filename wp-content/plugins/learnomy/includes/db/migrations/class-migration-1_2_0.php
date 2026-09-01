<?php
/**
 * Migration 1.2.0 — InnoDB engine + payment secret encryption.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

use Learnomy\DB\Schema;
use Learnomy\Security\Encrypted_Option;

class Migration_1_2_0 {

	public function up(): void {
		$this->ensure_innodb();
		$this->migrate_payment_secrets();
	}

	private function ensure_innodb(): void {
		global $wpdb;

		foreach ( Schema::get_table_names() as $name ) {
			$full = $wpdb->prefix . $name;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					DB_NAME,
					$full
				)
			);

			if ( $engine && 'InnoDB' !== $engine ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( 'ALTER TABLE `' . esc_sql( $full ) . '` ENGINE=InnoDB' );
			}
		}
	}

	/**
	 * Move plaintext payment secrets from the learnomy_settings blob
	 * into individual encrypted options, then scrub from the blob.
	 */
	private function migrate_payment_secrets(): void {
		$settings = get_option( 'learnomy_settings', array() );
		$map      = array(
			'stripe_secret_key'     => 'learnomy_stripe_secret_key',
			'stripe_webhook_secret' => 'learnomy_stripe_webhook_secret',
			'paypal_secret'         => 'learnomy_paypal_secret',
			'paypal_webhook_id'     => 'learnomy_paypal_webhook_id',
		);

		$changed = false;

		foreach ( $map as $blob_key => $option_name ) {
			if ( ! empty( $settings[ $blob_key ] ) ) {
				Encrypted_Option::set( $option_name, (string) $settings[ $blob_key ] );
				unset( $settings[ $blob_key ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( 'learnomy_settings', $settings );
		}
	}
}
