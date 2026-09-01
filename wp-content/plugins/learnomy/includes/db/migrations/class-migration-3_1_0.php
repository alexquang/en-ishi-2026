<?php
/**
 * Migration 3.1.0 — consolidated instructor profile table.
 *
 * Creates `lrn_instructors` (one row per instructor) and backfills it from the
 * scattered stores the profile used to read: WP `user_meta` (`description`,
 * `learnomy_expertise`, `twitter`, `linkedin`), `wp_users.user_url`, and — only
 * where the meta is empty — the instructor's latest application row. This ends
 * the "bio lives in two places, neither editable" problem (audit finding F4) so
 * the public profile and the new self-edit form read one source of truth.
 *
 * Forward-only and idempotent: the table create is guarded by a table-exists
 * check (Schema::create_tables() is the canonical builder), and the backfill
 * skips any user that already has a row, so re-running is a no-op. Pro extends
 * this table via its own tables — it never alters this one.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

use Learnomy\DB\Schema;

defined( 'ABSPATH' ) || exit;

class Migration_3_1_0 {

	public function up(): void {
		if ( ! $this->table_exists( $this->db()->prefix . 'lrn_instructors' ) ) {
			Schema::create_tables();
		}
		$this->backfill();
	}

	/**
	 * Seed one row per existing instructor from user_meta / user_url, falling
	 * back to the latest application only where the meta is blank.
	 *
	 * One-time pass: `get_users()` by role is acceptable for a migration even on
	 * a large roster (it runs once); per-user inserts are guarded by a row-exists
	 * check so a partial run resumes cleanly.
	 */
	private function backfill(): void {
		$instructor_ids = get_users(
			array(
				'role'   => 'lrn_instructor',
				'fields' => 'ID',
			)
		);

		$now   = gmdate( 'Y-m-d H:i:s' );
		$wpdb  = $this->db();
		$table = $wpdb->prefix . 'lrn_instructors';

		foreach ( $instructor_ids as $raw_id ) {
			$user_id = (int) $raw_id;
			if ( $user_id <= 0 || $this->row_exists( $user_id ) ) {
				continue;
			}

			$bio       = (string) get_user_meta( $user_id, 'description', true );
			$expertise = (string) get_user_meta( $user_id, 'learnomy_expertise', true );

			// Only reach for the (write-once) application when the live meta is
			// empty — the meta is the more recent value when both exist.
			if ( '' === trim( $bio ) || '' === trim( $expertise ) ) {
				$application = $this->latest_application( $user_id );
				if ( $application ) {
					if ( '' === trim( $bio ) ) {
						$bio = (string) ( $application->bio ?? '' );
					}
					if ( '' === trim( $expertise ) ) {
						$expertise = (string) ( $application->expertise ?? '' );
					}
				}
			}

			$userdata = get_userdata( $user_id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				array(
					'user_id'             => $user_id,
					'bio'                 => $bio,
					'expertise'           => $expertise,
					'website_url'         => $userdata ? (string) $userdata->user_url : '',
					'twitter_handle'      => (string) get_user_meta( $user_id, 'twitter', true ),
					'linkedin_url'        => (string) get_user_meta( $user_id, 'linkedin', true ),
					'verification_status' => 'unverified',
					'created_at'          => $now,
					'updated_at'          => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	private function row_exists( int $user_id ): bool {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . 'lrn_instructors WHERE user_id = %d LIMIT 1',
				$user_id
			)
		);
	}

	private function latest_application( int $user_id ): ?object {
		$wpdb = $this->db();
		// Prefer an approved application; fall back to the most recent of any
		// status so a still-pending applicant who already holds the role (manual
		// grant) doesn't lose their submitted bio.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT bio, expertise FROM ' . $wpdb->prefix . "lrn_instructor_applications WHERE user_id = %d ORDER BY ( status = 'approved' ) DESC, created_at DESC LIMIT 1",
				$user_id
			)
		) ?: null;
	}

	private function table_exists( string $table ): bool {
		$wpdb = $this->db();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$table
			)
		);
	}

	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}
}
