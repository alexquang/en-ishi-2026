<?php
/**
 * Free's first-party dashboard widget definitions.
 *
 * Registers every Learnomy-core widget (student + instructor) through the
 * canonical `learnomy_dashboard_widgets` filter — same surface Pro and any
 * third party uses. Free has no privileged in-template path.
 *
 * The render callbacks delegate to per-widget partials under
 * `templates/dashboard-widgets/{surface}/{id}.php` so designers can edit
 * markup without opening PHP classes.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

class Free_Dashboard_Widgets {

	public static function boot(): void {
		add_filter( 'learnomy_dashboard_widgets', array( __CLASS__, 'register' ), 10, 3 );
	}

	/**
	 * @param array  $widgets Existing widget list from upstream filters.
	 * @param string $surface 'student' or 'instructor'.
	 * @param array  $ctx     user_id, user, view_data, surface, route.
	 */
	public static function register( array $widgets, string $surface, array $ctx ): array {
		if ( 'student' === $surface ) {
			$widgets = array_merge( $widgets, self::student_widgets() );
		} elseif ( 'instructor' === $surface ) {
			$widgets = array_merge( $widgets, self::instructor_widgets() );
		} elseif ( 'admin' === $surface ) {
			$widgets = array_merge( $widgets, self::admin_widgets() );
		}
		return $widgets;
	}

	// -------------------------------------------------------------------------
	// Student surface
	// -------------------------------------------------------------------------

	private static function student_widgets(): array {
		return array(
			array(
				'id'           => 'continue-learning',
				'surface'      => 'student',
				'priority'     => 5,
				'title'        => '',
				'shell'        => 'hero',
				'is_available' => static fn( $c ) => ! empty( $c['view_data']['resume_target'] ),
				'render'       => static fn( $c ) => self::partial( 'student/continue-learning', $c ),
			),
			array(
				'id'           => 'streak',
				'surface'      => 'student',
				// Between continue-learning (5) and today-agenda (7). Streak
				// is a tiny motivational header — sees-it-first signal that
				// confirms momentum.
				'priority'     => 6,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => \Learnomy\Models\Progress::current_streak( (int) ( $c['user_id'] ?? 0 ) ) > 0,
				'render'       => static fn( $c ) => self::partial( 'student/streak', $c ),
			),
			array(
				'id'           => 'today-agenda',
				'surface'      => 'student',
				// Right after the continue-learning hero (5) + streak (6),
				// before the enrollments tab (10). "What's on your plate
				// today" is the most-actionable signal of all student
				// widgets.
				'priority'     => 7,
				'title'        => __( "Today's plan", 'learnomy' ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => ! empty( self::today_agenda_items( $c ) ),
				'render'       => static fn( $c ) => self::partial( 'student/today-agenda', $c ),
			),
			// Compact course preview — a few in-progress courses + a "View all"
			// link to the dedicated /account/courses/ section. The full tabbed
			// list (with Load more) lives there now, so the dashboard home stays
			// a lean overview and the secondary widgets below aren't buried under
			// a long course grid.
			array(
				'id'           => 'courses-preview',
				'surface'      => 'student',
				'priority'     => 10,
				'title'        => '',
				'shell'        => 'naked',
				'is_available' => static fn( $c ) => true, // partial renders its own empty state.
				'render'       => static fn( $c ) => self::partial( 'student/courses-preview', $c ),
			),
			// The "My Membership" widget was removed — Memberships now has its own
			// dedicated /account/membership/ section in the account nav, so a
			// dashboard widget would just duplicate it.
			array(
				'id'           => 'announcements',
				'surface'      => 'student',
				'priority'     => 30,
				'title'        => __( 'Announcements', 'learnomy' ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => ! empty( $c['view_data']['announcements'] ),
				'render'       => static fn( $c ) => self::partial( 'student/announcements', $c ),
			),
			array(
				'id'           => 'recent-activity',
				'surface'      => 'student',
				'priority'     => 40,
				'title'        => __( 'Recent Activity', 'learnomy' ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => ! empty( $c['view_data']['recent_progress'] ),
				'render'       => static fn( $c ) => self::partial( 'student/recent-activity', $c ),
			),
			array(
				'id'           => 'quiz-scores',
				'surface'      => 'student',
				'priority'     => 50,
				'title'        => __( 'Quiz Scores', 'learnomy' ),
				'shell'        => 'flush',
				'is_available' => static fn( $c ) => ! empty( $c['view_data']['recent_attempts'] ),
				'render'       => static fn( $c ) => self::partial( 'student/quiz-scores', $c ),
			),
			// `certificates` widget removed from the dashboard — it now has its
			// own /account/certificates/ section in the account nav (shown when
			// the certificates module is enabled).
			array(
				'id'           => 'recommendations',
				'surface'      => 'student',
				// At the bottom of the dashboard — discovery surface, not
				// the actionable section. Once a student has done what's
				// in their courses, suggesting what to learn next is the
				// natural next-step.
				'priority'     => 70,
				'title'        => __( 'Recommended for you', 'learnomy' ),
				'shell'        => 'naked',
				'is_available' => static fn( $c ) => ! empty(
					\Learnomy\Models\Course::recommended_for_user( (int) ( $c['user_id'] ?? 0 ), 1 )
				),
				'render'       => static fn( $c ) => self::partial( 'student/recommendations', $c ),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Instructor surface
	// -------------------------------------------------------------------------

	private static function instructor_widgets(): array {
		return array(
			array(
				'id'           => 'my-courses',
				'surface'      => 'instructor',
				'section'      => 'courses',
				'priority'     => 10,
				/* translators: %s: the plural entity label, e.g. "Courses" or "Programs". */
				'title'        => sprintf( __( 'My %s', 'learnomy' ), \Learnomy\course_label( true ) ),
				'shell'        => 'flush',
				'is_available' => static fn( $c ) => true, // empty-state when no courses.
				'render'       => static fn( $c ) => self::partial( 'instructor/my-courses', $c ),
			),
			array(
				'id'           => 'funnel',
				'surface'      => 'instructor',
				'section'      => 'overview',
				'priority'     => 20,
				'title'        => __( 'Completion Funnel', 'learnomy' ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => (int) ( $c['view_data']['funnel_enrolled'] ?? 0 ) > 0,
				'render'       => static fn( $c ) => self::partial( 'instructor/funnel', $c ),
			),
			// Overview summaries of the two highest-value student signals — the
			// at-risk roster + enrollment momentum — surfaced on the dashboard
			// HOME (not just the Students rail section) so the overview is an
			// actionable summary, not a near-empty page. Same partials/data as
			// the Students-section widgets; distinct ids so both can render.
			array(
				'id'           => 'overview-students-at-risk',
				'surface'      => 'instructor',
				'section'      => 'overview',
				'priority'     => 22,
				'title'        => sprintf( /* translators: %s: plural label for students (e.g. "Learners"). */ __( '%s at Risk', 'learnomy' ), \Learnomy\student_label( true ) ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => ! empty(
					\Learnomy\Models\Enrollment::at_risk_for_instructor( (int) ( $c['user_id'] ?? 0 ), 14, 1 )
				),
				'render'       => static fn( $c ) => self::partial( 'instructor/students-at-risk', $c ),
			),
			array(
				'id'           => 'overview-recent-enrollments',
				'surface'      => 'instructor',
				'section'      => 'overview',
				'priority'     => 24,
				'title'        => __( 'Recent Enrollments', 'learnomy' ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => ! empty( $c['view_data']['recent_enrollments'] ),
				'render'       => static fn( $c ) => self::partial( 'instructor/recent-enrollments', $c ),
			),
			array(
				'id'           => 'engagement',
				'surface'      => 'instructor',
				'section'      => 'overview',
				'priority'     => 30,
				'title'        => __( 'Engagement Alerts', 'learnomy' ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => (int) ( $c['view_data']['inactive_7'] ?? 0 ) > 0,
				'render'       => static fn( $c ) => self::partial( 'instructor/engagement', $c ),
			),
			array(
				'id'           => 'qa-inbox',
				'surface'      => 'instructor',
				'section'      => 'students',
				// Between engagement (30) and pending-reviews (40). Q&A is
				// the highest-friction instructor signal per the gap audit
				// — it goes ABOVE the reviews queue.
				'priority'     => 33,
				'title'        => sprintf( /* translators: %s: singular label for a student (e.g. "Learner"). */ __( '%s Q&A', 'learnomy' ), \Learnomy\student_label() ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => ! empty(
					\Learnomy\Models\Lesson_Comment::pending_for_instructor( (int) ( $c['user_id'] ?? 0 ), 5 )
				),
				'render'       => static fn( $c ) => self::partial( 'instructor/qa-inbox', $c ),
			),
			array(
				'id'           => 'awaiting-grading',
				'surface'      => 'instructor',
				'section'      => 'overview',
				// Right after Q&A (33), before pending-reviews (40): essay/manual
				// quiz attempts the instructor must grade before the student's
				// score finalises. [card 10062796121]
				'priority'     => 34,
				'title'        => __( 'Awaiting Grading', 'learnomy' ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => (int) ( $c['view_data']['pending_grading_count'] ?? 0 ) > 0,
				'render'       => static fn( $c ) => self::partial( 'instructor/awaiting-grading', $c ),
			),
			array(
				'id'           => 'compose-announcement',
				'surface'      => 'instructor',
				'section'      => 'overview',
				// Between qa-inbox (33) and pending-reviews (40). Announcements
				// are the outbound counterpart to Q&A — answering students vs
				// broadcasting to them — so the two surface together.
				'priority'     => 35,
				'title'        => __( 'Announcements', 'learnomy' ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => true, // Always — compose CTA matters even with no history.
				'render'       => static fn( $c ) => self::partial( 'instructor/compose-announcement', $c ),
			),
			array(
				'id'           => 'students-at-risk',
				'surface'      => 'instructor',
				'section'      => 'students',
				// Between compose (35) and pending-reviews (40). The actionable
				// per-student counterpart to the site-wide Engagement Alerts
				// widget (priority=30) which only shows TOTALS.
				'priority'     => 37,
				'title'        => sprintf( /* translators: %s: plural label for students (e.g. "Learners"). */ __( '%s at Risk', 'learnomy' ), \Learnomy\student_label( true ) ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => ! empty(
					\Learnomy\Models\Enrollment::at_risk_for_instructor( (int) ( $c['user_id'] ?? 0 ), 14, 1 )
				),
				'render'       => static fn( $c ) => self::partial( 'instructor/students-at-risk', $c ),
			),
			array(
				'id'           => 'pending-reviews',
				'surface'      => 'instructor',
				'section'      => 'students',
				'priority'     => 40,
				'title'        => __( 'Pending Reviews', 'learnomy' ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => ! empty( $c['view_data']['pending_reviews'] ),
				'render'       => static fn( $c ) => self::partial( 'instructor/pending-reviews', $c ),
			),
			array(
				'id'           => 'earnings',
				'surface'      => 'instructor',
				'section'      => 'earnings',
				'priority'     => 50,
				'title'        => __( 'Earnings', 'learnomy' ),
				'shell'        => 'card',
				// Always show earnings — even at zero the panel surfaces the
				// withdrawal entry point, which a brand-new instructor needs.
				'is_available' => static fn( $c ) => true,
				'render'       => static fn( $c ) => self::partial( 'instructor/earnings', $c ),
			),
			array(
				'id'           => 'revenue-trend',
				'surface'      => 'instructor',
				'section'      => 'earnings',
				// Right after earnings — visual companion to the lifetime /
				// month / available numbers above it. Hides via is_available
				// when the instructor has zero commission rows in the period
				// (a brand-new instructor sees earnings totals only, not a
				// flat-line empty chart that would look broken).
				'priority'     => 52,
				'title'        => __( 'Revenue Trend (Last 30 Days)', 'learnomy' ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => ! empty(
					\Learnomy\Models\Commission::daily_trend_for_instructor( (int) ( $c['user_id'] ?? 0 ), 30 )
				),
				'render'       => static fn( $c ) => self::partial( 'instructor/revenue-trend', $c ),
			),
			array(
				'id'           => 'recent-enrollments',
				'surface'      => 'instructor',
				'section'      => 'students',
				'priority'     => 60,
				'title'        => __( 'Recent Enrollments', 'learnomy' ),
				'shell'        => 'card',
				'is_available' => static fn( $c ) => ! empty( $c['view_data']['recent_enrollments'] ),
				'render'       => static fn( $c ) => self::partial( 'instructor/recent-enrollments', $c ),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Admin surface
	// -------------------------------------------------------------------------

	private static function admin_widgets(): array {
		return array(
			array(
				'id'           => 'admin-onboarding',
				'surface'      => 'admin',
				'priority'     => 1,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => ! ( $c['view_data']['onboarding_dismissed'] ?? false )
					&& ! ( $c['view_data']['onboarding_resolved']->is_complete ?? false ),
				'render'       => static fn( $c ) => self::partial( 'admin/onboarding', $c ),
			),
			array(
				'id'           => 'admin-demo-alert',
				'surface'      => 'admin',
				'priority'     => 2,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => ! empty( $c['view_data']['has_demo'] )
					&& ! self::onboarding_owns_demo_removal( $c ),
				'render'       => static fn( $c ) => self::partial( 'admin/demo-alert', $c ),
			),
			array(
				'id'           => 'admin-stats',
				'surface'      => 'admin',
				'priority'     => 5,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => true,
				'render'       => static fn( $c ) => self::partial( 'admin/stats', $c ),
			),
			array(
				'id'           => 'admin-quick-actions',
				'surface'      => 'admin',
				'priority'     => 20,
				// Admin widgets manage their own `.lrn-admin-card` chrome
				// because the admin design system differs from the frontend
				// shell (.lrn-card). Keeping `shell=none` here is the
				// cleanest split until the admin shell variants exist.
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => true,
				'render'       => static fn( $c ) => self::partial( 'admin/quick-actions', $c ),
			),
			array(
				'id'           => 'admin-view-frontend',
				'surface'      => 'admin',
				'priority'     => 25,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => true,
				'render'       => static fn( $c ) => self::partial( 'admin/view-frontend', $c ),
			),
			array(
				'id'           => 'admin-trends',
				'surface'      => 'admin',
				'priority'     => 30,
				// Two charts in a 2-col grid. They share state (period selector)
				// so keeping them in one widget body avoids cross-widget CSS
				// dependencies and the registry doesn't need a "row group"
				// concept.
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => true,
				'render'       => static fn( $c ) => self::partial( 'admin/trends', $c ),
			),
			array(
				'id'           => 'admin-completion-top-courses',
				'surface'      => 'admin',
				'priority'     => 40,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => true,
				'render'       => static fn( $c ) => self::partial( 'admin/completion-top-courses', $c ),
			),
			array(
				'id'           => 'admin-recent-transactions',
				'surface'      => 'admin',
				// Right after the chart pair (40) — operators want to see
				// the latest money flow alongside the chart visualization.
				'priority'     => 42,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => ! empty(
					\Learnomy\Models\Transaction::recent_completed( 1 )
				),
				'render'       => static fn( $c ) => self::partial( 'admin/recent-transactions', $c ),
			),
			array(
				'id'           => 'admin-new-signups',
				'surface'      => 'admin',
				'priority'     => 43,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => true,
				'render'       => static fn( $c ) => self::partial( 'admin/new-signups', $c ),
			),
			array(
				'id'           => 'admin-top-instructors',
				'surface'      => 'admin',
				'priority'     => 44,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => ! empty(
					\Learnomy\Models\Commission::top_instructors( 1 )
				),
				'render'       => static fn( $c ) => self::partial( 'admin/top-instructors', $c ),
			),
			array(
				'id'           => 'admin-refund-overview',
				'surface'      => 'admin',
				// Right after top-instructors (44). Refunds belong with the
				// business analytics group (top, transactions, signups).
				// Hidden when there are zero refunds in the last 30 days
				// so a healthy site doesn't get a "zero refunds" empty card.
				'priority'     => 44,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => \Learnomy\Models\Transaction::refund_overview( 30, 0 )['count'] > 0,
				'render'       => static fn( $c ) => self::partial( 'admin/refund-overview', $c ),
			),
			array(
				'id'           => 'admin-system-health',
				'surface'      => 'admin',
				// Between completion+top-courses (40) and pending-applications
				// (50). Operator-facing signal — sits in the operational
				// half of the dashboard, not the business analytics half.
				'priority'     => 45,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => true,
				'render'       => static fn( $c ) => self::partial( 'admin/system-health', $c ),
			),
			array(
				'id'           => 'admin-email-log-summary',
				'surface'      => 'admin',
				// After system-health (45). Email/notification log is
				// operational signal, pairs with system-health.
				'priority'     => 46,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => \Learnomy\Models\Notification::activity_summary()['week'] > 0,
				'render'       => static fn( $c ) => self::partial( 'admin/email-log-summary', $c ),
			),
			array(
				'id'           => 'admin-pending-applications',
				'surface'      => 'admin',
				'priority'     => 50,
				'title'        => '',
				'shell'        => 'none',
				'is_available' => static fn( $c ) => ! empty( $c['view_data']['stats']['pending_applications'] ) && ! \Learnomy\solo_mode(),
				'render'       => static fn( $c ) => self::partial( 'admin/pending-applications', $c ),
			),
		);
	}

	/**
	 * Buffer-include a widget partial. The partial receives
	 * $ctx, $widget, and unpacked $view_data for ergonomic access.
	 */
	/**
	 * Roll up "what's on your plate today" across multiple modules.
	 *
	 * Sources (each gated on class_exists so Free-only sites still get
	 * a working widget if they ever introduce assignments later):
	 *   - Assignments (Pro): rows with due_date inside today
	 *   - Content Drip (Pro): lessons in the user's enrolled courses
	 *     whose unlock date falls today
	 *   - Cohorts (Pro): cohorts the user belongs to whose starts_at
	 *     is today
	 *
	 * Returns up to 5 items sorted by event time, ascending. Items
	 * without a specific time-of-day (date-only) sort last in the day.
	 *
	 * "Today" is computed in site timezone via current_time() so
	 * student-facing copy matches the wall clock the student reads.
	 *
	 * @return array<int, array{icon:string,kind:string,title:string,subtitle:string,url:string,ts:int}>
	 */
	public static function today_agenda_items( array $ctx ): array {
		$user_id = (int) ( $ctx['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			return array();
		}

		$tz          = wp_timezone();
		$today       = new \DateTimeImmutable( 'today', $tz );
		$tomorrow    = $today->modify( '+1 day' );
		$today_ts    = (int) $today->getTimestamp();
		$tomorrow_ts = (int) $tomorrow->getTimestamp();

		$items = array();

		// ── Assignments due today (Pro) ──
		$assignment_class = '\\Learnomy_Pro\\Extensions\\Assignments\\Models\\Assignment';
		if ( class_exists( $assignment_class ) && method_exists( $assignment_class, 'upcoming_for_user' ) ) {
			$rows = $assignment_class::upcoming_for_user( $user_id, 25 );
			foreach ( $rows as $row ) {
				if ( empty( $row->due_date ) ) {
					continue;
				}
				$ts = strtotime( (string) $row->due_date );
				if ( ! $ts || $ts < $today_ts || $ts >= $tomorrow_ts ) {
					continue;
				}
				$items[] = array(
					'icon'     => 'file-text',
					'kind'     => 'assignment',
					'title'    => sprintf( /* translators: %s: assignment title */ __( 'Assignment due: %s', 'learnomy' ), (string) $row->title ),
					'subtitle' => (string) ( $row->course_title ?? '' ),
					'url'      => ! empty( $row->course_id ) && class_exists( '\\Learnomy\\Models\\Course' )
						? \Learnomy\route_url( 'course', array( 'slug' => (string) ( \Learnomy\Models\Course::find( (int) $row->course_id )->slug ?? '' ) ) )
						: '',
					'ts'       => $ts,
				);
			}
		}

		// ── Content Drip unlocks today (Pro) ──
		$drip_class = '\\Learnomy_Pro\\Extensions\\Content_Drip\\Drip_Service';
		if ( class_exists( $drip_class ) && class_exists( '\\Learnomy\\Models\\Enrollment' ) ) {
			$service     = new $drip_class();
			$enrollments = \Learnomy\Models\Enrollment::get_distinct_for_user( $user_id );
			foreach ( $enrollments as $enr ) {
				if ( ! empty( $enr->completed_at ) ) {
					continue;
				}
				$course_id = (int) $enr->course_id;
				$rules     = $service->get_rules_for_course( $course_id );
				foreach ( $rules as $rule ) {
					if ( 'lesson' !== ( $rule->item_type ?? 'lesson' ) ) {
						continue;
					}
					$unlock = $service->get_unlock_date( $user_id, $course_id, (int) $rule->item_id, (string) $rule->item_type );
					if ( ! $unlock ) {
						continue;
					}
					$ts = strtotime( $unlock );
					if ( ! $ts || $ts < $today_ts || $ts >= $tomorrow_ts ) {
						continue;
					}
					$lesson  = class_exists( '\\Learnomy\\Models\\Lesson' )
						? \Learnomy\Models\Lesson::find( (int) $rule->item_id )
						: null;
					$course  = class_exists( '\\Learnomy\\Models\\Course' )
						? \Learnomy\Models\Course::find( $course_id )
						: null;
					$items[] = array(
						'icon'     => 'lock-keyhole',
						'kind'     => 'drip',
						'title'    => $lesson
							? sprintf( /* translators: %s: lesson title */ __( 'Lesson unlocks: %s', 'learnomy' ), (string) $lesson->title )
							: __( 'Lesson unlocks', 'learnomy' ),
						'subtitle' => $course ? (string) $course->title : '',
						'url'      => ( $lesson && $course && ! empty( $course->slug ) && ! empty( $lesson->slug ) )
							? \Learnomy\route_url(
								'lesson',
								array(
									'slug'        => (string) $course->slug,
									'lesson_slug' => (string) $lesson->slug,
								)
							)
							: '',
						'ts'       => $ts,
					);
				}
			}
		}

		// ── Cohorts starting today (Pro) ──
		$cohort_class = '\\Learnomy_Pro\\Extensions\\Cohorts\\Models\\Cohort';
		if ( class_exists( $cohort_class ) && method_exists( $cohort_class, 'cohorts_for_user' ) ) {
			$cohorts = $cohort_class::cohorts_for_user( $user_id );
			foreach ( $cohorts as $cohort ) {
				if ( empty( $cohort->starts_at ) ) {
					continue;
				}
				$ts = strtotime( (string) $cohort->starts_at );
				if ( ! $ts || $ts < $today_ts || $ts >= $tomorrow_ts ) {
					continue;
				}
				$identifier = ! empty( $cohort->slug ) ? (string) $cohort->slug : (string) $cohort->id;
				$url        = class_exists( '\\Learnomy_Pro\\Extensions\\Cohorts\\Extension' )
					? \Learnomy_Pro\Extensions\Cohorts\Extension::cohort_url( $identifier )
					: '';
				$items[]    = array(
					'icon'     => 'users-round',
					'kind'     => 'cohort',
					'title'    => sprintf( /* translators: %s: cohort title */ __( 'Cohort session: %s', 'learnomy' ), (string) $cohort->title ),
					'subtitle' => '',
					'url'      => $url,
					'ts'       => $ts,
				);
			}
		}

		// Stable sort by event time, ascending. PHP's usort is unstable,
		// but with the small N here that's not user-visible.
		usort(
			$items,
			static fn( $a, $b ) => $a['ts'] <=> $b['ts']
		);

		return array_slice( $items, 0, 5 );
	}

	/**
	 * Whether the Getting Started guide currently owns demo removal.
	 *
	 * The guide's `remove_demo` checklist step offers the same irreversible
	 * action as the demo-alert widget. Rendering both is not redundancy, it is
	 * a trap: the site owner was shown three ways to wipe their sample data in
	 * one viewport, two of them identically labelled 147px apart
	 * (Basecamp 10128600685). So exactly one surface offers it at a time —
	 * the guide while the step is still actionable, the alert afterwards
	 * (guide dismissed, or the step completed or skipped).
	 *
	 * @param array $ctx Widget context.
	 * @return bool True when the alert must stand down.
	 */
	private static function onboarding_owns_demo_removal( array $ctx ): bool {
		$view = $ctx['view_data'] ?? array();

		if ( ! empty( $view['onboarding_dismissed'] ) ) {
			return false;
		}

		$resolved = $view['onboarding_resolved'] ?? array();

		return is_array( $resolved ) && 'pending' === ( $resolved['remove_demo'] ?? '' );
	}

	private static function partial( string $relative, array $ctx ): string {
		$path = LEARNOMY_DIR . 'templates/dashboard-widgets/' . $relative . '.php';
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$view_data = $ctx['view_data'] ?? array();
		$user      = $ctx['user'] ?? null;
		$user_id   = (int) ( $ctx['user_id'] ?? 0 );
		// Unpack view_data so partials can read `$enrollments` etc. directly
		// without ceremony — matches the contract the old monolithic
		// templates relied on.
		extract( $view_data, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		ob_start();
		include $path;
		return (string) ob_get_clean();
	}
}
