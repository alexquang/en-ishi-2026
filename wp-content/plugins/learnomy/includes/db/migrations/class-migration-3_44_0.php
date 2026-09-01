<?php
/**
 * Migration 3.44.0 — give seeded media an owner.
 *
 * `bin/seed-model-site.php` created its attachments with no `post_author`. It
 * runs under WP-CLI where there is no current user, so WordPress stored the
 * author as 0 and every seeded file ended up owned by nobody. 24 such rows were
 * measured on the QA sandbox.
 *
 * That was harmless until the Media Library became author-scoped (card
 * 10158410834). An author-less file now matches no instructor, so seeded lesson
 * worksheets are invisible in the picker to exactly the people meant to use
 * them — and invisible is worse than wrong, because nobody reports it.
 *
 * SCOPED TO OUR OWN SEED MEDIA. Only attachments carrying
 * `_lrn_model_seed_origin` are touched. A `post_author = 0` attachment is not
 * automatically ours — an import, another plugin, or a WP-CLI upload can
 * produce one, and reassigning another plugin's media would be a worse bug than
 * the one being fixed. Verified on the sandbox: all 24 orphans carry the marker.
 *
 * Idempotent — a second run finds nothing to reassign.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_44_0 {

	public function up(): void {
		global $wpdb;

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);
		$target = $admins ? (int) $admins[0] : 0;

		if ( $target <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} p
				   INNER JOIN {$wpdb->postmeta} m
				           ON m.post_id = p.ID AND m.meta_key = '_lrn_model_seed_origin'
				    SET p.post_author = %d
				  WHERE p.post_type = 'attachment'
				    AND p.post_author = 0",
				$target
			)
		);

		if ( $updated > 0 ) {
			clean_post_cache( 0 );
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				sprintf(
					'Learnomy 3.44.0: gave %d seeded media file(s) an owner (they were stored with post_author 0 and were invisible under Media Library author scoping).',
					$updated
				)
			);
		}
	}
}
