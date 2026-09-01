<?php
/**
 * Migration 3.39.0 — repair enrollments that paid customers were locked out of.
 *
 * Two shipped defects wrote bad rows into `lrn_enrollments`. Both are fixed at the
 * source in this release; this migration repairs the rows already on disk, because
 * the people affected are customers who have paid and cannot reach what they bought.
 *
 * **Defect 1 — the zero-date expiry.** `Enrollment::reactivate()` built its own
 * UPDATE with `$wpdb->prepare()` and passed PHP `null` into `%s` / `%d`
 * placeholders. `prepare()` renders null as `''` / `0`, and because WordPress
 * strips `STRICT_TRANS_TABLES` / `NO_ZERO_DATE` from the session sql_mode, MySQL
 * stored `expires_at = ''` as the zero-date `0000-00-00 00:00:00` rather than SQL
 * NULL. A zero-date is `IS NOT NULL` and sorts before every real timestamp, so
 * `Enrollment::get_expired()` matched the row and the next
 * `Cron::expire_enrollments()` tick revoked a learner who had just re-subscribed or
 * re-purchased (Basecamp 10134140255, 10134140217). `plan_id` and
 * `subscription_id` landed as the `0` sentinel for the same reason.
 *
 * **Defect 2 — the silent membership re-subscribe.** `Subscription_Service::
 * enroll_plan_courses()` wrote rows with `Enrollment::create()`, bypassing the
 * enrollment service. A member who cancelled and later re-subscribed still owned an
 * `expired` membership row, so the INSERT collided with the UNIQUE
 * `(user_id, course_id, source)` key and failed with no error. Their course stayed
 * `expired` — missing from My Courses, from progress, from certificate eligibility
 * — even while their new subscription was billing.
 *
 * **What is restored, and what deliberately is not.** Access is only given back
 * where the customer is still entitled TODAY:
 *
 *   - `purchase` / `free` / `manual` / any other one-off source: a zero-date
 *     `expired` row can only have come from this bug. A refund revokes through
 *     `Enrollment_Service::unenroll()`, which writes `cancelled`, and a deliberate
 *     removal writes `cancelled` too — neither is touched here.
 *   - `subscription`: restored only while a live course-level subscription exists.
 *   - `membership`: restored only while an active plan covering that course exists.
 *
 * A row whose entitlement has genuinely ended keeps its terminal status and merely
 * has the bogus zero-date cleared, so revocation stays correct. Everything here is
 * idempotent — re-running finds nothing left to change.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_39_0 {

	public function up(): void {
		$this->restore_one_off_enrollments();
		$this->restore_course_subscription_enrollments();
		$this->restore_membership_enrollments();
		$this->clear_remaining_zero_dates();
		$this->clear_sentinel_links();
		$this->backfill_membership_plan_ids();
	}

	// ── Repairs ───────────────────────────────────────────────────────────

	/**
	 * Sources with no ongoing entitlement to re-check: a purchase, a free
	 * enrollment, an admin grant. Nothing but the expiry sweep sets these to
	 * `expired`, and the sweep could only have selected them because of the
	 * zero-date, so every match is a false revocation.
	 */
	private function restore_one_off_enrollments(): void {
		$table = $this->enrollments();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query(
			"UPDATE {$table}
			    SET status = 'active', expires_at = NULL
			  WHERE status = 'expired'
			    AND source NOT IN ( 'subscription', 'membership' )
			    AND " . $this->zero_date()
		);
	}

	/**
	 * Course-level recurring subscribers. Restore only where the subscription is
	 * still live — a re-subscriber who later cancelled for real must stay revoked.
	 * Matched on the user + course rather than the stored `subscription_id`,
	 * because the same bug wrote that column as the `0` sentinel on some rows.
	 */
	private function restore_course_subscription_enrollments(): void {
		$table = $this->enrollments();
		$subs  = $this->db()->prefix . 'lrn_member_subscriptions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query(
			"UPDATE {$table} e
			   JOIN {$subs} s
			     ON s.user_id = e.user_id
			    AND s.course_id = e.course_id
			    AND s.status IN ( 'active', 'trialing', 'past_due' )
			    AND s.current_period_end > NOW()
			    SET e.status = 'active', e.expires_at = NULL
			  WHERE e.status = 'expired'
			    AND e.source = 'subscription'
			    AND " . $this->zero_date( 'e.' )
		);
	}

	/**
	 * Members. Covers both defects at once: the zero-date revocation AND the
	 * re-subscribe whose INSERT collided with the unique key and left the row
	 * `expired`. The entitlement test is the live plan-to-course map, matching
	 * `Subscription_Service::matched_plan_id()`, so a member whose plan no longer
	 * includes the course is not handed access back.
	 *
	 * `cancelled` rows are excluded on purpose — that status is written by a
	 * deliberate removal or a refund, never by the sweep.
	 */
	private function restore_membership_enrollments(): void {
		$table = $this->enrollments();
		$subs  = $this->db()->prefix . 'lrn_member_subscriptions';
		$map   = $this->db()->prefix . 'lrn_membership_plan_courses';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query(
			"UPDATE {$table} e
			   JOIN {$subs} s
			     ON s.user_id = e.user_id
			    AND s.plan_id IS NOT NULL
			    AND s.status IN ( 'active', 'trialing', 'past_due' )
			    AND s.current_period_end > NOW()
			   JOIN {$map} mpc
			     ON mpc.plan_id = s.plan_id
			    AND mpc.course_id = e.course_id
			    SET e.status = 'active',
			        e.expires_at = NULL,
			        e.plan_id = COALESCE( NULLIF( e.plan_id, 0 ), s.plan_id )
			  WHERE e.status = 'expired'
			    AND e.source = 'membership'"
		);
	}

	/**
	 * Rows whose entitlement really has ended, plus rows the sweep has not reached
	 * yet. The bogus date goes; the status is left exactly as it stands, so a
	 * genuine cancellation or expiry is preserved.
	 */
	private function clear_remaining_zero_dates(): void {
		$table = $this->enrollments();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query( "UPDATE {$table} SET expires_at = NULL WHERE " . $this->zero_date() );
	}

	/**
	 * `plan_id = 0` / `subscription_id = 0` are not links, they are what
	 * `prepare()` produced from a PHP null. Restore the honest NULL so
	 * `plan_id IS NULL` means "no plan granted this" everywhere.
	 */
	private function clear_sentinel_links(): void {
		$table = $this->enrollments();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query( "UPDATE {$table} SET plan_id = NULL WHERE plan_id = 0" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query( "UPDATE {$table} SET subscription_id = NULL WHERE subscription_id = 0" );
	}

	/**
	 * Best-effort audit trail for historical membership rows that never recorded
	 * which plan granted them (every self-serve membership enrollment before this
	 * release). Only the unambiguous case is filled in: the member holds exactly
	 * ONE plan that covers that course. Where two of their plans both cover it, the
	 * answer is genuinely unknown and NULL is left in place rather than guessed —
	 * a wrong plan id in an audit trail is worse than an absent one.
	 *
	 * One grouped pass, not a per-row correlated subquery, so this stays sane on a
	 * site with a large enrollment table.
	 */
	private function backfill_membership_plan_ids(): void {
		$table = $this->enrollments();
		$subs  = $this->db()->prefix . 'lrn_member_subscriptions';
		$map   = $this->db()->prefix . 'lrn_membership_plan_courses';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db()->query(
			"UPDATE {$table} e
			   JOIN (
			          SELECT s.user_id, mpc.course_id, MIN( mpc.plan_id ) AS plan_id
			            FROM {$subs} s
			            JOIN {$map} mpc ON mpc.plan_id = s.plan_id
			           WHERE s.plan_id IS NOT NULL
			           GROUP BY s.user_id, mpc.course_id
			          HAVING COUNT( DISTINCT mpc.plan_id ) = 1
			        ) m
			     ON m.user_id = e.user_id
			    AND m.course_id = e.course_id
			    SET e.plan_id = m.plan_id
			  WHERE e.source = 'membership'
			    AND e.plan_id IS NULL"
		);
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * The zero-date sentinel, expressed as a range predicate rather than an
	 * equality against the literal `'0000-00-00 00:00:00'`. A zero-date compares
	 * below every real timestamp, no valid access expiry predates the epoch, and
	 * this form can use the `expires_at` index instead of scanning it.
	 *
	 * @param string $alias Table alias including the dot (e.g. `e.`), or ''.
	 */
	private function zero_date( string $alias = '' ): string {
		return "{$alias}expires_at IS NOT NULL AND {$alias}expires_at < '1970-01-01 00:00:00'";
	}

	private function enrollments(): string {
		return $this->db()->prefix . 'lrn_enrollments';
	}
}
