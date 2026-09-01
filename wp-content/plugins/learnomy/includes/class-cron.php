<?php
/**
 * Scheduled task registration and handlers.
 *
 * Core jobs run through WP-Cron so the free plugin works on any standard
 * WordPress install. Membership lifecycle jobs (daily sweep, trial reminder,
 * trial conversion) prefer Action Scheduler when available — if it isn't,
 * the hooks simply never fire and the hourly WP-Cron renewal check remains
 * the safety net.
 *
 * Intervals: hourly, daily, weekly, monthly (custom).
 * Hooks are prefixed learnomy_ so they can't clash with other plugins.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\AuditLog;
use Learnomy\Models\Certificate;
use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Notification;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\Subscription;
use Learnomy\Models\Token;
use Learnomy\Services\Commission_Service;
use Learnomy\Services\Subscription_Service;
use Learnomy\Services\Notification_Service;
use Learnomy\Services\Webhook_Service;

class Cron {

	/**
	 * Rows processed per batch by the enrollment expiry / reminder sweeps.
	 *
	 * The handlers cursor-page through their result sets in chunks of this size
	 * so a large backlog (100k+ expiring enrollments) is bounded in memory and
	 * runtime instead of loading every row in one unbounded query — which would
	 * OOM the cron and silently leave expired, paid content accessible.
	 */
	private const EXPIRY_BATCH_SIZE = 500;

	public function __construct() {
		add_action( 'init', [ $this, 'schedule_events' ] );
		// Schedule on 'init' (not 'plugins_loaded') so Action Scheduler's data
		// store is initialised before we call as_has_scheduled_action() /
		// as_schedule_recurring_action(). AS sets up its store on 'init'@1; a
		// plugins_loaded call ran too early and emitted a _doing_it_wrong
		// notice ("called before the Action Scheduler data store was
		// initialized") on every request. This method only schedules — the
		// handlers are bound below in the constructor regardless of hook — so
		// deferring to init is safe. Priority 20 keeps it after schedule_events.
		add_action( 'init', [ $this, 'schedule_membership_actions' ], 20 );

		add_action( 'learnomy_expire_enrollments', [ $this, 'expire_enrollments' ] );
		add_action( 'learnomy_check_memberships', [ $this, 'check_memberships' ] );
		add_action( 'learnomy_send_expiry_reminders', [ $this, 'send_expiry_reminders' ] );
		add_action( 'learnomy_engagement_splits', [ $this, 'calculate_engagement_splits' ] );
		add_action( 'learnomy_recompute_stats', [ $this, 'recompute_stats' ] );
		add_action( 'learnomy_cleanup_attempts', [ $this, 'cleanup_abandoned_attempts' ] );
		add_action( 'learnomy_cleanup_tokens', [ $this, 'cleanup_expired_tokens' ] );
		add_action( 'learnomy_prune_audit_log', [ $this, 'prune_audit_log' ] );
		add_action( 'learnomy_prune_email_log', [ $this, 'prune_email_log' ] );
		add_action( 'learnomy_cert_expiry_check', [ $this, 'check_cert_expiry' ] );
		add_action( 'learnomy_reconcile_payments', [ $this, 'reconcile_payments' ] );

		// Async notification fan-out — Notification_Service::send_many() enqueues
		// one of these per recipient for any large audience (announcements, bulk
		// enrol, cron reminders), draining one recipient per background job
		// instead of blocking the request. This generic, type-carrying action
		// replaced the announcement-only learnomy_send_announcement_async during
		// the async-push refactor (that handler is gone — no enqueuer remained).
		add_action( 'learnomy_send_notification_async', [ $this, 'send_notification_async' ], 10, 3 );
		add_action( 'learnomy_deliver_push', [ $this, 'deliver_push' ], 10, 4 );

		// Keep enrollment.progress_pct accurate when the lesson SET changes.
		// progress_pct is only restamped on lesson completion, so adding /
		// removing a lesson (or reassigning lessons between courses) leaves every
		// enrolled student's stored % stale and drifting from the live player
		// bar. These listeners queue a paged recompute for the affected course;
		// the job re-enqueues itself per page so a big course stays bounded
		// (Basecamp 10109156594). Same-args pending jobs dedupe, so a burst of
		// curriculum edits collapses to one recompute.
		add_action( 'learnomy_recompute_course_progress', [ $this, 'recompute_course_progress' ], 10, 2 );
		add_action( 'learnomy_lesson_created', [ $this, 'queue_course_progress_recompute_from_lesson' ], 20, 2 );
		add_action( 'learnomy_lesson_deleted', [ $this, 'queue_course_progress_recompute_from_lesson' ], 20, 2 );
		add_action(
			'learnomy_lessons_reassigned',
			function ( $lesson_ids ) {
				$this->queue_course_progress_recompute_for_lesson_ids( (array) $lesson_ids );
			},
			20,
			1
		);

		// Outbound webhook delivery — Webhook_Service::dispatch() schedules one
		// single-fire event per subscribed endpoint (Action Scheduler when
		// available, WP-Cron otherwise) carrying [ webhook_id, event, payload ].
		// Bind the handler here, alongside the other async jobs, so the
		// scheduled HTTP POST actually executes. Without this the events queue
		// up but never fire (Basecamp #9961968974). 3 args to match the
		// dispatched signature; the admin "Test" button calls deliver()
		// synchronously, which masked the missing binding.
		add_action( 'learnomy_deliver_webhook', [ $this, 'deliver_webhook' ], 10, 3 );

		// LMS migration importer — each event processes one page of one entity
		// for a run then re-enqueues itself until the run is complete (big-site
		// safe, resumable). The handler is a safe no-op when no such run exists.
		add_action( \Learnomy\Migration\Import_Service::STEP_HOOK, [ \Learnomy\Migration\Import_Service::class, 'step' ], 10, 1 );

		// Membership lifecycle — Action Scheduler (handlers are safe no-ops
		// when Action Scheduler isn't loaded, since the actions never fire).
		add_action( 'learnomy_membership_daily_sweep', [ $this, 'membership_daily_sweep' ] );
		add_action( 'learnomy_membership_trial_reminder', [ $this, 'membership_trial_reminder' ] );
		add_action( 'learnomy_membership_end_trials', [ $this, 'membership_end_trials' ] );
	}

	/**
	 * Registers the custom 'monthly' recurrence and schedules any events that
	 * aren't already in the WP-Cron queue.
	 *
	 * Runs on 'init' so it fires early but after options are readable.
	 */
	public function schedule_events(): void {
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				if ( ! isset( $schedules['monthly'] ) ) {
					$schedules['monthly'] = [
						'interval' => MONTH_IN_SECONDS,
						'display'  => __( 'Monthly', 'learnomy' ),
					];
				}
				return $schedules;
			}
		);

		$events = [
			'learnomy_expire_enrollments'    => 'hourly',
			'learnomy_check_memberships'     => 'hourly',
			'learnomy_send_expiry_reminders' => 'daily',
			'learnomy_engagement_splits'     => 'monthly',
			'learnomy_recompute_stats'       => 'daily',
			'learnomy_cleanup_attempts'      => 'daily',
			'learnomy_cleanup_tokens'        => 'daily',
			'learnomy_prune_audit_log'       => 'weekly',
			'learnomy_prune_email_log'       => 'weekly',
			'learnomy_cert_expiry_check'     => 'daily',
			'learnomy_reconcile_payments'    => 'hourly',
		];

		// Extension cron jobs — Pro modules and third-party code register
		// recurring jobs via this filter. Format: hook => recurrence
		// (recurrence must match a known cron_schedules key).
		// Plan 00 §1.5 D7 / §7 Phase 0.
		$events = (array) apply_filters( 'learnomy_cron_jobs', $events );

		foreach ( $events as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $recurrence, $hook );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	/**
	 * Marks time-limited enrollments as expired when their window closes.
	 * Fires learnomy_enrollment_expired after each status change so other code
	 * (e.g. certificate revocation) can react.
	 *
	 * Every revocation also writes an audit row. This sweep takes paid access away
	 * from a customer with no human in the loop, so when one writes in saying "I
	 * bought this and it's gone", the owner needs to see WHEN it was taken and what
	 * expiry justified it. There was no record at all, which is how the zero-date
	 * revocation bug (Basecamp 10134140255 / 10134140217) ran unnoticed: the rows
	 * simply changed state overnight and nothing said why. One insert per genuinely
	 * expiring row — a small, bounded cost next to the UPDATE already happening,
	 * and the batching keeps it linear.
	 */
	public function expire_enrollments(): void {
		$now      = now();
		$after_id = 0;

		// Cursor-batch: pull oldest-first by id, process, advance past the last
		// id, repeat. Bounds memory + cron runtime on sites with a large expiry
		// backlog so the sweep never OOMs and silently leaves paid content open.
		do {
			$rows        = Enrollment::get_expired( $now, self::EXPIRY_BATCH_SIZE, $after_id );
			$batch_count = count( $rows );
			foreach ( $rows as $row ) {
				Enrollment::update( (int) $row->id, [ 'status' => 'expired' ] );
				AuditLog::log(
					(int) $row->user_id,
					'enrollment',
					'access_expired',
					'course',
					(int) $row->course_id,
					array(
						'enrollment_id' => (int) $row->id,
						'expires_at'    => (string) ( $row->expires_at ?? '' ),
						'swept_at'      => $now,
					)
				);
				do_action( 'learnomy_enrollment_expired', (int) $row->id, (int) $row->user_id, (int) $row->course_id );
				$after_id = (int) $row->id;
			}
		} while ( $batch_count === self::EXPIRY_BATCH_SIZE );
	}

	/**
	 * Expires memberships whose billing period has ended without renewal.
	 * The payment webhook normally handles renewals; this catches anything
	 * that slipped through (failed payments, provider downtime, etc.).
	 */
	public function check_memberships(): void {
		$rows               = Subscription::get_overdue();
		$membership_service = new Subscription_Service();

		foreach ( $rows as $row ) {
			$membership_service->expire( (int) $row->id );
		}
	}

	/**
	 * Daily reminder sweep — sends heads-up notifications + emails for
	 * expiring enrollments and renewing subscriptions at three cadences.
	 *
	 * Enrollment expiry:
	 *   - 7 days out (legacy pass, kept for backward compat) — dedup via
	 *     `lrn_enrollments.reminder_sent_at` column.
	 *   - 3 days out (PR-E) — dedup via `lrn_notifications` row with
	 *     `type='enrollment_expiring_3d'`.
	 *   - 1 day out (PR-E) — dedup via `lrn_notifications` row with
	 *     `type='enrollment_expiring_1d'`.
	 *
	 * Subscription renewal:
	 *   - 7-day legacy pass (existing, kept) — no dedup column today; the
	 *     pre-PR-E code re-sent every cron tick within the window, which
	 *     was a spam bug. Now deduped via `lrn_notifications` with
	 *     `type='membership_expiring'`.
	 *   - 3 days out (PR-E) — `type='membership_renewing_3d'`.
	 *   - 1 day out (PR-E) — `type='membership_renewing_1d'`.
	 *
	 * Site owners can disable either family via filters:
	 *   `apply_filters('learnomy_send_access_expiring_reminders', true)`
	 *   `apply_filters('learnomy_send_renewal_reminders', true)`
	 *
	 * Pro can extend the cadence list via `learnomy_reminder_cadences`
	 * (default `['7d','3d','1d']`); cron iterates whatever the filter
	 * returns, so a 30-day or 14-day cadence costs Pro one filter line.
	 */
	public function send_expiry_reminders(): void {
		$do_access  = (bool) apply_filters( 'learnomy_send_access_expiring_reminders', true );
		$do_renewal = (bool) apply_filters( 'learnomy_send_renewal_reminders', true );

		if ( $do_access ) {
			$this->send_enrollment_expiry_reminders();
		}
		if ( $do_renewal ) {
			$this->send_subscription_renewal_reminders();
		}
	}

	/**
	 * Per-cadence access-expiring reminder sweep.
	 *
	 * Walks every cadence from `learnomy_reminder_cadences`; for each one,
	 * pulls enrollments whose `expires_at` lands inside that cadence's
	 * window and dedups against `lrn_notifications` so the same user can't
	 * get the same reminder twice. Reuses the existing 7d column for the
	 * 7-day pass to stay backward-compatible with rows already marked.
	 */
	private function send_enrollment_expiry_reminders(): void {
		$now      = now();
		$cadences = (array) apply_filters( 'learnomy_reminder_cadences', array( '7d', '3d', '1d' ) );

		foreach ( $cadences as $cadence ) {
			$days = $this->cadence_to_days( (string) $cadence );
			if ( $days < 1 ) {
				continue;
			}

			$window_end = gmdate( 'Y-m-d H:i:s', strtotime( "+{$days} days" ) );

			if ( '7d' === $cadence ) {
				// Legacy 7d pass — keeps using the per-row `reminder_sent_at`
				// column so already-sent rows in the wild don't re-trigger.
				// Cursor-batch by id so a large backlog is processed in bounded
				// chunks instead of one unbounded result set.
				$after_id = 0;
				do {
					$rows        = Enrollment::get_expiring_unremindered( $now, $window_end, self::EXPIRY_BATCH_SIZE, $after_id );
					$batch_count = count( $rows );
					foreach ( $rows as $row ) {
						$this->send_one_enrollment_expiry_reminder( $row, 'enrollment_expiring', $days );
						Enrollment::mark_reminder_sent( (int) $row->id, $now );
						$after_id = (int) $row->id;
					}
				} while ( $batch_count === self::EXPIRY_BATCH_SIZE );
				continue;
			}

			// 3d / 1d / Pro-added cadences — dedup via lrn_notifications.
			// Cursor-batch by id for the same memory/runtime bound.
			$type     = 'enrollment_expiring_' . $cadence;
			$after_id = 0;
			do {
				$rows        = Enrollment::get_expiring_between( $now, $window_end, self::EXPIRY_BATCH_SIZE, $after_id );
				$batch_count = count( $rows );
				foreach ( $rows as $row ) {
					$after_id  = (int) $row->id;
					$user_id   = (int) $row->user_id;
					$course_id = (int) $row->course_id;
					if ( Notification::exists_for_object( $user_id, $type, 'course', $course_id ) ) {
						continue;
					}
					$this->send_one_enrollment_expiry_reminder( $row, $type, $days );
				}
			} while ( $batch_count === self::EXPIRY_BATCH_SIZE );
		}
	}

	/**
	 * Per-cadence renewal reminder sweep — same shape as the enrollment
	 * version but targeting `lrn_member_subscriptions.current_period_end`.
	 */
	private function send_subscription_renewal_reminders(): void {
		$now      = now();
		$cadences = (array) apply_filters( 'learnomy_reminder_cadences', array( '7d', '3d', '1d' ) );

		foreach ( $cadences as $cadence ) {
			$days = $this->cadence_to_days( (string) $cadence );
			if ( $days < 1 ) {
				continue;
			}

			$window_end = gmdate( 'Y-m-d H:i:s', strtotime( "+{$days} days" ) );
			$type       = '7d' === $cadence
				? 'membership_expiring'
				: 'membership_renewing_' . $cadence;

			// Cursor-batch by id so a large expiry window drains in bounded
			// chunks. $after_id advances before the skip checks so a run of
			// already-deduped rows can't stall the loop.
			$after_id = 0;
			do {
				$rows        = Subscription::get_expiring_between( $now, $window_end, self::EXPIRY_BATCH_SIZE, $after_id );
				$batch_count = count( $rows );
				foreach ( $rows as $row ) {
					$after_id = (int) $row->id;
					$user_id  = (int) $row->user_id;
					$plan_id  = (int) ( $row->plan_id ?? 0 );
					if ( $plan_id <= 0 ) {
						continue;
					}
					if ( Notification::exists_for_object( $user_id, $type, 'membership_plan', $plan_id ) ) {
						continue;
					}
					$this->send_one_renewal_reminder( $row, $type, $days );
				}
			} while ( $batch_count === self::EXPIRY_BATCH_SIZE );
		}
	}

	/**
	 * Send one access-expiring notification + email. The Notification_Service
	 * writes the bell-UI row AND fires the email via Email_Content::for_type,
	 * which picks the right branded copy keyed on `$type`.
	 */
	private function send_one_enrollment_expiry_reminder( object $row, string $type, int $days ): void {
		$user_id = (int) $row->user_id;

		// Build every string in the RECIPIENT's locale, not the site/cron locale —
		// the same seam Notifier::notify() uses for event notifications. The cron
		// runs in the site locale, so without this the bell-UI row + email render
		// in the wrong language for a member who set their own. Covers the date's
		// month name too, since wp_date() reads the switched locale.
		$locale   = get_user_locale( $user_id );
		$switched = $locale !== determine_locale() ? switch_to_locale( $locale ) : false;

		try {
			$notification_service = new Notification_Service();
			$expires_at_human     = wp_date( get_option( 'date_format' ), strtotime( $row->expires_at ) );

			$message = 1 === $days
				? __( 'Your course access expires tomorrow. Renew now to keep your progress.', 'learnomy' )
				: sprintf(
					/* translators: 1: days remaining, 2: expiry date */
					_n(
						'Your course access expires in %1$d day (on %2$s). Renew now to keep your progress.',
						'Your course access expires in %1$d days (on %2$s). Renew now to keep your progress.',
						$days,
						'learnomy'
					),
					$days,
					$expires_at_human
				);

			$notification_service->queue_send(
				$user_id,
				$type,
				array(
					'title'       => __( 'Your course access is expiring soon', 'learnomy' ),
					'message'     => $message,
					'object_type' => 'course',
					'object_id'   => (int) $row->course_id,
					'days_out'    => $days,
					'expires_at'  => (string) $row->expires_at,
				)
			);
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Send one renewal notification + email.
	 */
	private function send_one_renewal_reminder( object $row, string $type, int $days ): void {
		$user_id = (int) $row->user_id;

		// Build in the recipient's locale — see send_one_enrollment_expiry_reminder().
		$locale   = get_user_locale( $user_id );
		$switched = $locale !== determine_locale() ? switch_to_locale( $locale ) : false;

		try {
			$notification_service = new Notification_Service();
			$renewal_date         = wp_date( get_option( 'date_format' ), strtotime( $row->current_period_end ) );

			$message = 1 === $days
				? __( 'Your membership renews tomorrow. Make sure your payment method is up to date.', 'learnomy' )
				: sprintf(
					/* translators: 1: days remaining, 2: renewal date */
					_n(
						'Your membership renews in %1$d day (on %2$s). Make sure your payment method is up to date.',
						'Your membership renews in %1$d days (on %2$s). Make sure your payment method is up to date.',
						$days,
						'learnomy'
					),
					$days,
					$renewal_date
				);

			$notification_service->queue_send(
				$user_id,
				$type,
				array(
					'title'              => __( 'Your membership is renewing soon', 'learnomy' ),
					'message'            => $message,
					'object_type'        => 'membership_plan',
					'object_id'          => (int) ( $row->plan_id ?? 0 ),
					'days_out'           => $days,
					'current_period_end' => (string) $row->current_period_end,
				)
			);
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Translate a cadence slug like `7d` / `3d` / `1d` into an int days
	 * value the date math + copy paths can consume. Unknown slugs return 0
	 * so the caller skips them.
	 */
	private function cadence_to_days( string $cadence ): int {
		if ( ! preg_match( '/^(\d+)d$/', $cadence, $m ) ) {
			return 0;
		}
		return (int) $m[1];
	}

	/**
	 * Distributes membership revenue among instructors based on how many
	 * lessons their students completed in the previous calendar month.
	 * Runs on the 1st of each month (next occurrence after monthly cron fires).
	 */
	public function calculate_engagement_splits(): void {
		// Use the previous month so all transactions for that period have settled.
		$period = gmdate( 'Y-m', strtotime( 'first day of last month' ) );

		$commission_service = new Commission_Service();
		$commission_service->calculate_engagement_split( $period );
	}

	/**
	 * Refreshes the denormalized student_count and avg_rating columns on courses.
	 * Running this daily keeps the catalogue pages fast without paying the cost
	 * of a live aggregate query on every page load.
	 */
	public function recompute_stats(): void {
		Course::recompute_student_counts();
		Course::recompute_ratings();
	}

	/**
	 * Removes quiz attempts that have been sitting in-progress for over 24 hours.
	 * These are sessions where the student started but never submitted — keeping
	 * them forever would inflate active-attempt counts and block retakes.
	 */
	public function cleanup_abandoned_attempts(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );
		QuizAttempt::delete_abandoned( $cutoff );
	}

	/**
	 * Deletes JWT tokens that have passed their expiry timestamp.
	 * Token::cleanup_expired() handles the query; we just invoke it on schedule.
	 */
	public function cleanup_expired_tokens(): void {
		Token::cleanup_expired();
	}

	/**
	 * Hard-deletes audit log entries older than the configured retention window.
	 *
	 * Reads `learnomy_settings['data_retention_days']` (default 365) — the
	 * Settings > Advanced > Data Retention field. Falls back to the legacy
	 * `audit_log_retention_days` key for sites whose options predate Wave 2E
	 * (2026-05-16) so an upgrade doesn't silently change behavior.
	 *
	 * Zero or negative means "keep all" — nothing is pruned.
	 *
	 * Wave 2E (2026-05-16): row 2E-1 in plan/2026-05-16-token-ready-sweep/
	 * audit-B-data-flow.yaml. Closes the dead-field wiring gap where the
	 * admin field was written but never read by the prune cron.
	 */
	public function prune_audit_log(): void {
		$settings = get_option( 'learnomy_settings', [] );

		// New canonical field — wired in Wave 2E. Default 365 matches
		// includes/class-learnomy.php seed_default_settings().
		if ( array_key_exists( 'data_retention_days', $settings ) ) {
			$retention_days = (int) $settings['data_retention_days'];
		} else {
			// Legacy key — preserved so upgrades from <2026-05-16 keep
			// honoring whatever shorter window the admin already chose.
			$retention_days = (int) ( $settings['audit_log_retention_days'] ?? 0 );
		}

		if ( $retention_days <= 0 ) {
			// Zero means "keep all" — nothing to do.
			return;
		}

		AuditLog::prune( $retention_days );
	}

	/**
	 * Deletes notification / email-log rows older than the configured window.
	 *
	 * The Email Log grows a row for every email AND every in-app notification,
	 * forever, and those rows hold personal data — recipient, subject, message
	 * body. Nothing pruned it: an academy running for two years accumulated
	 * hundreds of thousands of rows with no way to age any of them out
	 * (Basecamp 10132808821). This is the audit-log prune's twin.
	 *
	 * Reads `learnomy_settings['email_log_retention_days']`, the
	 * Settings > Advanced > Data Management field. Zero or negative means
	 * "keep everything" — and that is the DEFAULT for an existing site, because
	 * an upgrade that silently deletes a site owner's history is a worse bug
	 * than the one being fixed. New installs are seeded with 365 days.
	 */
	public function prune_email_log(): void {
		$settings = get_option( 'learnomy_settings', [] );

		$retention_days = (int) ( $settings['email_log_retention_days'] ?? 0 );

		/**
		 * Filter the email-log retention window in days.
		 *
		 * Return 0 to keep everything. Lets a site enforce a shorter window in
		 * code (a data-processing agreement, say) without the setting.
		 *
		 * @param int $retention_days Days of email log to keep.
		 */
		$retention_days = (int) apply_filters( 'learnomy_email_log_retention_days', $retention_days );

		if ( $retention_days <= 0 ) {
			return;
		}

		Notification::prune( $retention_days );
	}

	/**
	 * Flips certificates to 'expired' when their expires_at timestamp has
	 * passed but the status column is still behind. Fires
	 * learnomy_certificate_expired after each row so downstream code (e.g.
	 * Pro Credly sync, notification listeners) can react without polling.
	 *
	 * Runs daily — certificate expiry is day-granular so hourly would waste
	 * queries and daily keeps the lag below 24 hours which is acceptable for
	 * a credential-expiry event.
	 */
	public function check_cert_expiry(): void {
		$rows = Certificate::get_newly_expired();

		foreach ( $rows as $row ) {
			Certificate::update( (int) $row->id, [ 'status' => 'expired' ] );
			do_action( 'learnomy_certificate_expired', (int) $row->id, (int) $row->user_id, (int) $row->course_id );
		}
	}

	/**
	 * Backstop for on-page (Payment Element) Stripe purchases whose webhook
	 * never fulfilled them — the buyer closed the tab before returning to the
	 * thank-you page (which self-heals synchronously), so nothing reconciled
	 * and the webhook is down. Sweeps recently-succeeded PaymentIntents and
	 * provisions any Learnomy-tagged, not-yet-recorded ones. Idempotent and
	 * adapter-gated; a no-op when Stripe isn't configured.
	 */
	public function reconcile_payments(): void {
		$stripe = \Learnomy\Adapters\Adapter_Registry::get( 'stripe' );
		if ( $stripe && method_exists( $stripe, 'reconcile_recent' ) ) {
			$stripe->reconcile_recent();
		}
	}

	// -------------------------------------------------------------------------
	// Action Scheduler — membership lifecycle
	// -------------------------------------------------------------------------

	/**
	 * Registers the recurring Action Scheduler jobs for membership lifecycle
	 * (expire sweep, trial-ending reminder, trial conversion). Guarded by
	 * `as_has_scheduled_action()` so registrations don't duplicate.
	 */
	public function schedule_membership_actions(): void {
		// WP-Cron fallback recurrences for the membership lifecycle jobs. Used
		// when Action Scheduler is not loaded so the trial reminder, trial
		// conversion, and expire sweep still run (AS is preferred at scale).
		$fallback_jobs = [
			'learnomy_membership_daily_sweep'    => 'daily',
			'learnomy_membership_trial_reminder' => 'hourly',
			'learnomy_membership_end_trials'     => 'hourly',
		];

		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			// Action Scheduler isn't loaded on this install. The WP-Cron
			// `learnomy_check_memberships` hook covers renewals; register the
			// remaining lifecycle jobs on WP-Cron too so trials + drip still
			// fire. Handlers are bound in __construct() regardless of AS.
			foreach ( $fallback_jobs as $hook => $recurrence ) {
				if ( ! wp_next_scheduled( $hook ) ) {
					wp_schedule_event( time(), $recurrence, $hook );
				}
			}
			\Learnomy\Cron\Drip_Cron::register();
			return;
		}

		// Action Scheduler owns these jobs. Clear any WP-Cron fallback events
		// left from a window when AS wasn't loaded so a job can't fire on both
		// engines (e.g. duplicate trial-reminder emails).
		foreach ( array_keys( $fallback_jobs ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		$jobs = [
			// Daily sweep at 03:00 UTC — low-traffic window for most regions.
			'learnomy_membership_daily_sweep'    => [
				'first_run' => strtotime( 'tomorrow 03:00 UTC' ),
				'interval'  => DAY_IN_SECONDS,
			],
			// Trial-ending reminders every 6 hours so the 72h window is covered
			// even when a trial was started mid-day.
			'learnomy_membership_trial_reminder' => [
				'first_run' => time() + HOUR_IN_SECONDS,
				'interval'  => 6 * HOUR_IN_SECONDS,
			],
			// Trial conversion runs hourly so users don't sit in `trialing`
			// longer than an hour past `trial_ends_at`.
			'learnomy_membership_end_trials'     => [
				'first_run' => time() + HOUR_IN_SECONDS,
				'interval'  => HOUR_IN_SECONDS,
			],
		];

		foreach ( $jobs as $hook => $config ) {
			if ( as_has_scheduled_action( $hook, [], 'learnomy' ) ) {
				continue;
			}
			as_schedule_recurring_action(
				$config['first_run'],
				$config['interval'],
				$hook,
				[],
				'learnomy'
			);
		}

		// Lesson drip — hourly cron that fires `learnomy_lesson_unlocked` for
		// students whose lessons have crossed their `available_date`. Owns its
		// own registration so the contract stays inside the handler class.
		// Plan 06 §10 Automation.
		\Learnomy\Cron\Drip_Cron::register();
	}

	/**
	 * Daily membership sweep — expires cancel-at-period-end subs whose period
	 * has lapsed and nudges stuck renewals via the safety-net hook.
	 */
	public function membership_daily_sweep(): void {
		$rows = Subscription::get_due_for_sweep();

		if ( empty( $rows ) ) {
			return;
		}

		$membership_service = new Subscription_Service();

		// How long a past-due subscription keeps access before we cut it off.
		//
		// This was hardcoded to 7 days with no setting, so a site owner who wanted to
		// be gentler (a failed card on holiday) or stricter (a hard cut at renewal)
		// had no way to say so — and nothing in the UI even told them a 7-day window
		// existed. It is one of the most consequential numbers in the product: it
		// decides how long someone keeps a paid course after their payment fails.
		$grace_days   = \Learnomy\subscription_grace_days();
		$grace_cutoff = strtotime( '-' . $grace_days . ' days' );

		foreach ( $rows as $row ) {
			try {
				$period_end_ts = strtotime( (string) $row->current_period_end );

				// Explicit cancellations expire the moment the period ends,
				// no grace window — the user already asked to stop paying.
				if ( (int) $row->cancel_at_period_end === 1 || 'cancelled' === $row->status ) {
					$membership_service->expire( (int) $row->id );
					continue;
				}

				// Otherwise the webhook should have renewed. Allow a 7-day
				// grace — provider outages and retries can legitimately delay
				// the renewal callback. Past the grace we expire and log so
				// admins can audit the pile.
				if ( $period_end_ts && $period_end_ts <= $grace_cutoff ) {
					do_action( 'learnomy_subscription_auto_expired', (int) $row->id, (int) $row->user_id, (int) $row->plan_id );
					$membership_service->expire( (int) $row->id );
				}
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'Learnomy: membership_daily_sweep failed for sub %d — %s', (int) $row->id, $e->getMessage() ) );
				continue;
			}
		}
	}

	/**
	 * Fires `learnomy_subscription_trial_ending` once per trialing subscription
	 * inside the next 72h, using usermeta as the one-shot sentinel.
	 */
	public function membership_trial_reminder(): void {
		$now   = now();
		$until = gmdate( 'Y-m-d H:i:s', strtotime( '+72 hours' ) );
		$rows  = Subscription::get_trials_ending_between( $now, $until );

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			try {
				$sentinel_key = 'learnomy_trial_reminder_sent_' . (int) $row->id;
				if ( get_user_meta( (int) $row->user_id, $sentinel_key, true ) ) {
					continue;
				}

				do_action(
					'learnomy_subscription_trial_ending',
					(int) $row->id,
					(int) $row->user_id,
					(int) $row->plan_id,
					(string) $row->trial_ends_at
				);

				update_user_meta( (int) $row->user_id, $sentinel_key, now() );
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'Learnomy: membership_trial_reminder failed for sub %d — %s', (int) $row->id, $e->getMessage() ) );
				continue;
			}
		}
	}

	/**
	 * Converts trialing subscriptions whose trial window has closed into
	 * active paid subscriptions — delegates to Subscription_Service::end_trial().
	 */
	public function membership_end_trials(): void {
		$rows = Subscription::get_trials_ending_by( now() );

		if ( empty( $rows ) ) {
			return;
		}

		$membership_service = new Subscription_Service();

		foreach ( $rows as $row ) {
			try {
				$membership_service->end_trial( (int) $row->id );
			} catch ( \Throwable $e ) {
				error_log( sprintf( 'Learnomy: membership_end_trials failed for sub %d — %s', (int) $row->id, $e->getMessage() ) );
				continue;
			}
		}
	}

	/**
	 * Handler for `learnomy_send_notification_async` — the generic, type-carrying
	 * counterpart to send_announcement_async, enqueued by
	 * Notification_Service::send_many() so a large fan-out (bulk enrol,
	 * announcements, cron reminders) drains one recipient per background job
	 * instead of blocking the request.
	 *
	 * @param int                  $user_id Recipient.
	 * @param string               $type    Notification type.
	 * @param array<string, mixed> $data    Notification payload.
	 */
	public function send_notification_async( int $user_id, string $type, array $data ): void {
		try {
			( new Notification_Service() )->send( $user_id, $type, $data );
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'Learnomy: send_notification_async failed for user %d — %s', $user_id, $e->getMessage() ) );
		}
	}

	/**
	 * Handler for `learnomy_deliver_push` — one member's push delivery, enqueued
	 * by Notification_Service::send() so the blocking per-device FCM POST never
	 * runs inline in a payment webhook or checkout response. The push preference
	 * was already checked before the enqueue.
	 *
	 * @param int                  $user_id Recipient.
	 * @param string               $title   Push title.
	 * @param string               $body    Push body.
	 * @param array<string, mixed> $data    Push data payload (type, object_type, object_id).
	 */
	public function deliver_push( int $user_id, string $title, string $body, array $data ): void {
		try {
			( new \Learnomy\Services\Push_Service() )->send_to_user( $user_id, $title, $body, $data );
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'Learnomy: deliver_push failed for user %d — %s', $user_id, $e->getMessage() ) );
		}
	}

	/**
	 * Queue a course-progress recompute from a `learnomy_lesson_created` /
	 * `learnomy_lesson_deleted` event (both fire `($lesson_id, $course_id)`).
	 *
	 * @param int $lesson_id Unused — kept to match the hook signature.
	 * @param int $course_id The course whose lesson set just changed.
	 */
	public function queue_course_progress_recompute_from_lesson( $lesson_id, $course_id = 0 ): void {
		$this->queue_course_progress_recompute( (int) $course_id );
	}

	/** Queue a recompute for every course the given lesson ids belong to. */
	public function queue_course_progress_recompute_for_lesson_ids( array $lesson_ids ): void {
		$lesson_ids = array_values( array_unique( array_filter( array_map( 'intval', $lesson_ids ) ) ) );
		if ( empty( $lesson_ids ) ) {
			return;
		}
		foreach ( \Learnomy\Models\Lesson::find_in( $lesson_ids ) as $lesson ) {
			$this->queue_course_progress_recompute( (int) ( $lesson->course_id ?? 0 ) );
		}
	}

	/**
	 * Enqueue (or run inline) a paged recompute of enrollment.progress_pct for a
	 * course. Same-args pending Action Scheduler jobs dedupe, so a burst of
	 * curriculum edits on one course collapses to a single recompute.
	 */
	private function queue_course_progress_recompute( int $course_id, int $after_user_id = 0 ): void {
		if ( $course_id <= 0 ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				'learnomy_recompute_course_progress',
				array(
					'course_id' => $course_id,
					'after'     => $after_user_id,
				),
				'learnomy'
			);
			return;
		}
		// No Action Scheduler — run inline (bounded to one page; better a partial
		// refresh than a silently-skipped one).
		$this->recompute_course_progress( $course_id, $after_user_id );
	}

	/**
	 * Action Scheduler handler: recompute one page of a course's
	 * enrollment.progress_pct, then re-enqueue for the next page until done
	 * (Basecamp 10109156594).
	 *
	 * @param int $course_id Course to refresh.
	 * @param int $after     Keyset cursor (last user_id processed).
	 */
	public function recompute_course_progress( $course_id, $after = 0 ): void {
		$course_id = (int) $course_id;
		if ( $course_id <= 0 ) {
			return;
		}
		$next = ( new \Learnomy\Services\Progress_Service() )->recompute_progress_for_course( $course_id, (int) $after );
		if ( $next > 0 ) {
			$this->queue_course_progress_recompute( $course_id, $next );
		}
	}

	/**
	 * Handler for `learnomy_deliver_webhook` events enqueued by
	 * Webhook_Service::dispatch(). Performs the actual outbound HTTP POST by
	 * delegating to the service so the same delivery code runs in the
	 * background regardless of whether Action Scheduler or WP-Cron drove it.
	 *
	 * The three args mirror what dispatch() schedules: the webhook row id, the
	 * event name, and the payload that gets signed and sent.
	 *
	 * @param int                  $webhook_id Webhook row id.
	 * @param string               $event      Event name (e.g. learnomy.enrollment.created).
	 * @param array<string, mixed> $payload    Event payload sent as the JSON body.
	 */
	public function deliver_webhook( int $webhook_id, string $event, array $payload ): void {
		try {
			( new Webhook_Service() )->deliver( $webhook_id, $event, $payload );
		} catch ( \Throwable $e ) {
			error_log( sprintf( 'Learnomy: deliver_webhook failed for webhook %d — %s', $webhook_id, $e->getMessage() ) );
		}
	}
}
