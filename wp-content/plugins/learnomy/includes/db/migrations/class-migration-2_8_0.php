<?php
/**
 * Migration 2.8.0 — Reconcile stale pricing_model with access_type.
 *
 * The `pricing_model` column (added in Migration 2.3.0) defaults to 'free'
 * in the schema. A legacy admin save that wrote only `access_type` left the
 * column at that default, so a course saved as `paid` / `membership` /
 * `paid_or_membership` could carry a stale `pricing_model='free'`.
 * Course::pricing_model() then read the stale column and rendered a paid
 * course as a free enrollment on /enroll/ (the high-priority re-run finding).
 *
 * Migration 2.3.0's backfill only touched rows still at the 'lifetime'
 * column-add default, so courses created AFTER 2.3.0 (which got the schema
 * default 'free') were never reconciled. This migration closes that gap.
 *
 * Forward-only and idempotent: it only rewrites rows where
 * `pricing_model='free'` AND `access_type` is NOT 'free' — i.e. only the
 * stale-in-the-dangerous-direction rows. Rows already consistent, and the
 * richer PR-A1 models (one_time / fixed_term / recurring / members_only /
 * one_time_or_members), are left untouched. Re-runs are no-ops.
 *
 * The controller now mirrors access_type -> pricing_model on every save, so
 * this migration patches history; new drift cannot be introduced.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_8_0 {

	public function up(): void {
		$this->reconcile_stale_pricing_model();
	}

	/**
	 * Rewrite the stale `pricing_model='free'` rows whose `access_type`
	 * disagrees, deriving the correct model from access_type.
	 */
	private function reconcile_stale_pricing_model(): void {
		$table = $this->db()->prefix . 'lrn_courses';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query(
			"UPDATE {$table} SET pricing_model = CASE access_type
				WHEN 'paid'               THEN 'one_time'
				WHEN 'membership'         THEN 'members_only'
				WHEN 'paid_or_membership' THEN 'one_time_or_members'
				ELSE pricing_model
			END
			WHERE pricing_model = 'free'
			AND access_type IN ( 'paid', 'membership', 'paid_or_membership' )"
		);
	}

	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}
}
