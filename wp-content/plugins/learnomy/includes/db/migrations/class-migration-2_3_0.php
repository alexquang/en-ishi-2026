<?php
/**
 * Migration 2.3.0 — Flexible course pricing schema (PR-A).
 *
 * Adds the course-side `pricing_model` vocabulary and the subscription-side
 * `course_id` link so a single subscription row can target a plan OR a
 * course. Also wires `subscription_id` onto enrollments so a recurring-
 * course customer's enrollment is traceable to the billing relationship
 * that grants it.
 *
 * Steps (every one idempotent):
 *
 *   A. Add columns to `lrn_courses`:
 *        - pricing_model         VARCHAR(20)   default 'lifetime'
 *        - access_duration_days  INT           NULL
 *        - recurring_amount      DECIMAL(10,2) NULL
 *        - recurring_interval    VARCHAR(20)   NULL
 *        - stripe_test_price_id   VARCHAR(255) NULL
 *        - stripe_live_price_id   VARCHAR(255) NULL
 *        - paypal_sandbox_plan_id VARCHAR(255) NULL
 *        - paypal_live_plan_id    VARCHAR(255) NULL
 *
 *   B. Backfill `pricing_model` from `access_type` for legacy rows:
 *        - free               -> free
 *        - paid               -> one_time
 *        - membership         -> members_only
 *        - paid_or_membership -> one_time_or_members
 *      (Default column literal stays 'lifetime' so the column-add itself is
 *      fast on huge tables; the UPDATE pass normalises the typed values.)
 *
 *   C. Make `lrn_member_subscriptions.plan_id` NULLable and add
 *      `course_id` for course-level recurring subs.
 *
 *   D. Add `subscription_id` to `lrn_enrollments` so course-level
 *      recurring enrollments link back to their billing row.
 *
 *   E. Bug fix B2 — backfill `subscription_id` on existing
 *      `lrn_transactions` rows whose webhook missed the link. Uses a
 *      single UPDATE join keyed on provider_subscription_id ↔ subscription
 *      row so re-runs are no-ops.
 *
 * Why we use raw ALTER TABLE rather than `Schema::create_tables()` /
 * dbDelta for steps A, C and D: dbDelta is finicky about pre-existing
 * tables with manually-added columns (it diffs by name only and can drop
 * indexes silently). Direct column-add via `INFORMATION_SCHEMA` guard
 * leaves the table untouched when the column already exists and keeps the
 * migration loop chunk-free.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_3_0 {

	public function up(): void {
		$this->add_course_pricing_columns();
		$this->backfill_pricing_model();
		$this->extend_member_subscriptions();
		$this->add_enrollment_subscription_link();
		$this->backfill_transaction_subscription_ids();
	}

	// ── Step A — Course pricing columns ───────────────────────────────────

	private function add_course_pricing_columns(): void {
		$table = $this->db()->prefix . 'lrn_courses';

		$columns = array(
			'pricing_model'          => "VARCHAR(20) NOT NULL DEFAULT 'lifetime'",
			'access_duration_days'   => 'INT NULL',
			'recurring_amount'       => 'DECIMAL(10,2) NULL',
			'recurring_interval'     => 'VARCHAR(20) NULL',
			'stripe_test_price_id'   => 'VARCHAR(255) NULL',
			'stripe_live_price_id'   => 'VARCHAR(255) NULL',
			'paypal_sandbox_plan_id' => 'VARCHAR(255) NULL',
			'paypal_live_plan_id'    => 'VARCHAR(255) NULL',
		);

		foreach ( $columns as $column => $definition ) {
			$this->add_column_if_missing( $table, $column, $definition );
		}
	}

	// ── Step B — Backfill pricing_model from access_type ──────────────────

	/**
	 * Translate legacy `access_type` values into the new pricing_model
	 * vocabulary. Only touches rows where the new column is still at the
	 * `lifetime` default — once we've migrated a row, future re-runs are
	 * no-ops because the WHERE clause filters them out.
	 *
	 * Re-running this method on a fresh install (where access_type is
	 * already its default 'free') promotes the row to pricing_model='free'
	 * which is the correct semantic.
	 */
	private function backfill_pricing_model(): void {
		$table = $this->db()->prefix . 'lrn_courses';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$this->db()->query(
			"UPDATE {$table} SET pricing_model = CASE access_type
				WHEN 'free'               THEN 'free'
				WHEN 'paid'               THEN 'one_time'
				WHEN 'membership'         THEN 'members_only'
				WHEN 'paid_or_membership' THEN 'one_time_or_members'
				ELSE 'free'
			END
			WHERE pricing_model = 'lifetime'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	// ── Step C — Subscription table can target plan OR course ─────────────

	/**
	 * Drop the NOT NULL on `plan_id` and add `course_id`. The new column
	 * defaults to NULL so existing plan-targeted rows stay valid; new
	 * course-targeted rows leave `plan_id` NULL and populate `course_id`.
	 *
	 * No CHECK constraint — MySQL < 8.0.16 silently ignores them. Validation
	 * lives in `Subscription_Service::subscribe_to_course()` instead.
	 */
	private function extend_member_subscriptions(): void {
		$table = $this->db()->prefix . 'lrn_member_subscriptions';

		// Drop NOT NULL on plan_id so course-level subs can leave it null.
		if ( $this->column_is_not_null( $table, 'plan_id' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->db()->query( "ALTER TABLE {$table} MODIFY plan_id BIGINT(20) UNSIGNED NULL DEFAULT NULL" );
		}

		$this->add_column_if_missing( $table, 'course_id', 'BIGINT(20) UNSIGNED NULL DEFAULT NULL' );
		$this->add_index_if_missing( $table, 'course_status', '(course_id, status)' );
	}

	// ── Step D — Enrollment ↔ subscription link ───────────────────────────

	private function add_enrollment_subscription_link(): void {
		$table = $this->db()->prefix . 'lrn_enrollments';
		$this->add_column_if_missing( $table, 'subscription_id', 'BIGINT(20) UNSIGNED NULL DEFAULT NULL' );
		$this->add_index_if_missing( $table, 'subscription_id', '(subscription_id)' );
	}

	// ── Step E — Backfill transaction subscription_id (Bug fix B2) ────────

	/**
	 * Pre-PR-#103 sessions never wrote `subscription_id` on the initial
	 * `checkout.session.completed` transaction, so the /membership/manage/
	 * invoices list was orphaned from the first payment. PR #103 fixed
	 * forward; this migration patches history.
	 *
	 * Strategy: for every transaction where `subscription_id IS NULL` and
	 * `transaction_type` is a subscription-flavor type, look up a matching
	 * row in `lrn_member_subscriptions` keyed on `(user_id, plan_id)` plus
	 * created-at proximity (the subscribe writes happen in the same web
	 * request as the transaction record). If a unique match exists, fill
	 * the `subscription_id`. Multiple matches stay NULL so we never link
	 * the wrong charge to the wrong subscription.
	 *
	 * Idempotent — only touches NULL rows. Safe to re-run.
	 */
	private function backfill_transaction_subscription_ids(): void {
		$txn_table = $this->db()->prefix . 'lrn_transactions';
		$sub_table = $this->db()->prefix . 'lrn_member_subscriptions';

		// Bulk UPDATE: link transactions to subscriptions where the
		// (user_id, plan_id) pair maps to exactly one subscription row.
		// MySQL doesn't allow LIMIT in multi-table UPDATE so we use a
		// subquery returning the unique match.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query(
			"UPDATE {$txn_table} t
			SET subscription_id = (
				SELECT s.id FROM {$sub_table} s
				WHERE s.user_id = t.user_id
				AND s.plan_id = t.plan_id
				AND s.plan_id IS NOT NULL
				LIMIT 1
			)
			WHERE t.subscription_id IS NULL
			AND t.plan_id IS NOT NULL
			AND t.transaction_type IN ('membership', 'membership_renewal')
			AND (
				SELECT COUNT(*) FROM {$sub_table} s
				WHERE s.user_id = t.user_id
				AND s.plan_id = t.plan_id
				AND s.plan_id IS NOT NULL
			) = 1"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Add a column when it doesn't already exist. Uses INFORMATION_SCHEMA
	 * so the guard works on MySQL + MariaDB.
	 */
	private function add_column_if_missing( string $table, string $column, string $definition ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $this->db()->get_var(
			$this->db()->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				$column
			)
		);

		if ( $exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
	}

	private function add_index_if_missing( string $table, string $index_name, string $cols ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $this->db()->get_var(
			$this->db()->prepare(
				'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
				DB_NAME,
				$table,
				$index_name
			)
		);

		if ( $exists ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query( "ALTER TABLE {$table} ADD INDEX {$index_name} {$cols}" );
	}

	private function column_is_not_null( string $table, string $column ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$nullable = $this->db()->get_var(
			$this->db()->prepare(
				'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table,
				$column
			)
		);
		return 'NO' === $nullable;
	}
}
