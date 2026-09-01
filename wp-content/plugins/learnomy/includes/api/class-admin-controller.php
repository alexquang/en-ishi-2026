<?php
/**
 * Admin controller — revenue overview, commissions, withdrawals, applications, and settings.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use Learnomy\Models\Commission;
use Learnomy\Models\CommissionRule;
use Learnomy\Models\Course;
use Learnomy\Models\CourseInstructor;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Instructor;
use Learnomy\Models\InstructorApplication;
use Learnomy\Models\Transaction;
use Learnomy\Models\Withdrawal;
use Learnomy\Modules\Module_Kernel;
use Learnomy\Admin\Onboarding_State;
use Learnomy\Permissions\Capabilities;
use Learnomy\Services\Commission_Service;
use Learnomy\Services\Notification_Service;
use Learnomy\Services\Setup_Service;

class Admin_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'admin';
	}

	public function register_routes(): void {
		// GET /admin/revenue — platform-wide earnings overview.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/revenue',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'revenue' ],
				'permission_callback' => [ $this, 'is_admin' ],
			]
		);

		// Commission records.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/commissions',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_commissions' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => $this->get_collection_params(),
			]
		);

		// Withdrawal requests.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/withdrawals',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_withdrawals' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => $this->get_collection_params(),
			]
		);

		// Approve or reject a specific withdrawal.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/withdrawals/(?P<id>\d+)/approve',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'approve_withdrawal' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id'        => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					'reference' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Payment reference or transaction ID from the payout provider.', 'learnomy' ),
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/withdrawals/(?P<id>\d+)/reject',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reject_withdrawal' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id'     => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					'reason' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Reason shown to the instructor.', 'learnomy' ),
					],
				],
			]
		);

		// Commission rule create (per-course / per-instructor override).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/commission-rules',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_commission_rule' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'rule_type'     => [
						'type'     => 'string',
						'required' => true,
						'enum'     => [ 'course', 'instructor' ],
					],
					'entity_id'     => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'platform_rate' => [
						'type'     => 'number',
						'required' => true,
						'minimum'  => 0,
						'maximum'  => 100,
					],
				],
			]
		);

		// Commission rule updates.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/commission-rules/(?P<id>\d+)',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_commission_rule' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id'            => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					'platform_rate' => [
						'type'    => 'number',
						'minimum' => 0,
						'maximum' => 100,
					],
				],
			]
		);

		// Commission rule delete — removes an OVERRIDE so the entity inherits
		// again. Only create and update were ever registered, while the
		// customer documentation told owners to "click the delete action next
		// to it" and the Actions column offered only Save, so a rule could be
		// added and never taken back (Basecamp 10194234900). The semantics are
		// not new: CommissionRule::set_course_rate( $id, null ) has always
		// deleted the row to fall back to inherit.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/commission-rules/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_commission_rule' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
				],
			]
		);

		// Instructor applications.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/instructor-applications',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_applications' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => array_merge(
					$this->get_collection_params(),
					[
						'status' => [
							'type'    => 'string',
							'enum'    => [ 'pending', 'approved', 'rejected' ],
							'default' => 'pending',
						],
					]
				),
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/instructor-applications/(?P<id>\d+)/approve',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'approve_application' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/instructor-applications/(?P<id>\d+)/reject',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reject_application' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id'       => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					'feedback' => [
						'type'              => 'string',
						'sanitize_callback' => 'wp_kses_post',
						'description'       => __( 'Feedback sent to the applicant explaining why the application was declined.', 'learnomy' ),
					],
				],
			]
		);

		// Student management from the per-student detail page.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/students/(?P<id>\d+)/enroll',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'enroll_student' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id'        => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					'course_id' => [
						'required'          => true,
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
					],
				],
			]
		);

		// Pause or resume an instructor. POST rather than DELETE because nothing
		// is destroyed: it flips lrn_instructors.status, and the same route
		// reverses it (Basecamp 10203580675).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/instructors/(?P<id>\d+)/status',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'set_instructor_status' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id'     => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					'status' => [
						'required'          => true,
						'validate_callback' => fn( $v ) => in_array( $v, [ Instructor::STATUS_ACTIVE, Instructor::STATUS_SUSPENDED ], true ),
					],
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/students/(?P<id>\d+)/reset-course',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reset_student_course' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id'        => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					'course_id' => [
						'required'          => true,
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
					],
				],
			]
		);

		// Give one student extra attempts on ONE quiz. The surgical alternative
		// to reset-course above, which unblocks a quiz by deleting the whole
		// course's progress.
		//
		// Gated on `grade_quiz` for the quiz in question, not on admin-only
		// `manage_settings`. The same person already decides what this attempt
		// SCORED (the grading routes in Quizzes_Controller run on `grade_quiz`),
		// so refusing them the strictly smaller act of allowing one more sitting
		// left instructors with no way to answer "I failed, can I retry" on their
		// own course. Permission_Engine short-circuits on manage_options, so
		// admins keep every quiz; an instructor gets only quizzes in courses they
		// own, and a standalone quiz (no course) stays admin-only because
		// `grade_quiz` has no owner to resolve. An unknown or unowned quiz id
		// denies with 403 before the handler runs (a missing one 400s on the
		// required-arg check), so the gate is never evaluated with no context.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/students/(?P<id>\d+)/quiz-attempt-grant',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'grant_quiz_attempts' ],
				'permission_callback' => fn( $request ) => $this->permission_check( 'grade_quiz', absint( $request->get_param( 'quiz_id' ) ) ),
				'args'                => [
					'id'       => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					'quiz_id'  => [
						'required'          => true,
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
					],
					// Negative takes a grant back; the model floors the balance at 0.
					'attempts' => [
						'default'           => 1,
						'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v >= -50 && (int) $v <= 50,
					],
				],
			]
		);

		// Enroll a student into a course from the course overview page. Takes
		// an email or username (scale-safe — no dropdown of every user).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/courses/(?P<id>\d+)/enroll',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'enroll_into_course' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id'   => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					'user' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Bulk-enroll a list of emails into a course (CSV / paste). Manual
		// source; unknown emails are provisioned (set-password invite). Chunked
		// + capped per request so a big paste never times out.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/courses/(?P<id>\d+)/bulk-enroll',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'bulk_enroll_into_course' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id'     => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					'emails' => [
						'required' => true,
						'type'     => 'array',
						'items'    => [ 'type' => 'string' ],
					],
				],
			]
		);

		// Toggle an instructor's verification badge from the detail page.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/instructors/(?P<id>\d+)/verify',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'set_instructor_verification' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id'       => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					'verified' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ],
				],
			]
		);

		// POST /admin/instructors/{id}/require-approval — per-instructor override
		// of the marketplace publish gate. Persisted as user meta and honoured by
		// Course_Service::requires_approval() alongside the site-wide setting.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/instructors/(?P<id>\d+)/require-approval',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'set_instructor_require_approval' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'id'               => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					'require_approval' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) ],
				],
			]
		);

		// GET /admin/transactions/export — CSV stream of every transaction
		// row. Single-shot (no filters yet); admins get the full ledger and
		// slice it externally. Same in-memory php://temp pattern as the
		// memberships exports so REST framing isn't fought.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/transactions/export',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'export_transactions_csv' ],
				'permission_callback' => [ $this, 'is_admin' ],
			]
		);

		// GET /admin/students/export — CSV of all lrn_student users.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/students/export',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'export_students_csv' ],
				'permission_callback' => [ $this, 'is_admin' ],
			]
		);

		// POST /admin/modules/{slug} — toggle a module on / off. Replaces
		// the legacy learnomy_module_toggle AJAX. The handler is aware of
		// Pro extensions (slugs prefixed `pro:`) and routes them through
		// the existing learnomy_pro_extensions option.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/modules/(?P<slug>[a-z0-9_:-]+)',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'toggle_module' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'slug'    => [
						'required' => true,
						'type'     => 'string',
					],
					'enabled' => [
						'required' => true,
						'type'     => 'boolean',
					],
					// Acknowledgement that the caller has seen the live-commitment
					// warning. Absent (or false) on a disable that still owes people
					// money or access, the endpoint answers 409 with the impact
					// instead of switching the module off — see toggle_module().
					'confirm' => [
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					],
				],
			]
		);

		// PATCH /admin/onboarding-state — single endpoint that handles all
		// four onboarding mutations based on body shape:
		// { dismissed: true|false }       → reopen / dismiss the panel
		// { skip:   "step-id" }           → mark skipped
		// { unskip: "step-id" }           → undo skip
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/onboarding-state',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'patch_onboarding_state' ],
				'permission_callback' => [ $this, 'is_admin' ],
			]
		);

		// POST /admin/dismiss-notice/{notice_id} — store a per-user dismissal
		// flag for an admin notice. Notice IDs are whitelisted server-side so
		// the endpoint can't be abused to write arbitrary user_meta keys.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/dismiss-notice/(?P<notice_id>[a-z0-9_-]+)',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'dismiss_notice' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'notice_id' => [
						'required' => true,
						'type'     => 'string',
					],
				],
			]
		);

		// POST /admin/announcements — broadcast an in-app notification (and
		// optional email) to enrolled students. course_id=0 → all students.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/announcements',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send_announcement' ],
				'permission_callback' => [ $this, 'can_announce' ],
				'args'                => [
					'title'      => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'message'    => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
					'course_id'  => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'send_email' => [ 'type' => 'boolean' ],
				],
			]
		);

		// POST /admin/announcements/delete — recall a sent broadcast (delete
		// every per-recipient notification row). Keyed by the broadcast tuple
		// because a broadcast has no single id.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/announcements/delete',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'delete_announcement' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'created_at' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'title'      => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'object_id'  => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// Setup wizard state — replaces the legacy learnomy_setup_* AJAX
		// actions. Each route maps to one Setup_Service entry point.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/setup-state',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'patch_setup_state' ],
				'permission_callback' => [ $this, 'is_admin' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/setup-state/payment',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_payment_settings' ],
				'permission_callback' => [ $this, 'is_admin' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/setup-state/sample',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_sample_data' ],
				'permission_callback' => [ $this, 'is_admin' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/setup-state/reset-demo',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reset_demo_data' ],
				'permission_callback' => [ $this, 'is_admin' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/setup-state/cleanup-demo',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cleanup_demo_data' ],
				'permission_callback' => [ $this, 'is_admin' ],
			]
		);

		// Plugin settings.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'is_admin' ],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'is_admin' ],
				],
			]
		);

		// POST /admin/test-email — send a diagnostic test email to the current
		// admin user. Verifies wp_mail() is reaching the inbox and that the
		// configured sender name + sender email render correctly in the From
		// header. Wired from the Maintenance Tools card on Settings.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/test-email',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send_test_email' ],
				'permission_callback' => [ $this, 'is_admin' ],
			]
		);

		// POST /admin/flush-rewrite-rules — call flush_rewrite_rules() so site
		// admins can recover from rewrite-rule drift without dropping into the
		// Permalinks settings page. Wired from the Maintenance Tools card.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/flush-rewrite-rules',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'flush_rewrite_rules' ],
				'permission_callback' => [ $this, 'is_admin' ],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Announcement gate (Basecamp 10104886458). Admins keep full reach (any
	 * course, incl. the all-students broadcast). A non-admin INSTRUCTOR may
	 * announce ONLY to a course they teach (author OR co-instructor) — never the
	 * all-students broadcast (course_id=0 stays admin-only). Lets the app's Teach
	 * tab post per-course announcements without opening the whole-site megaphone.
	 */
	public function can_announce( WP_REST_Request $request ): bool|\WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$course_id = absint( $request->get_param( 'course_id' ) );
		if ( $course_id <= 0 ) {
			// All-students broadcast is admin-only.
			return $this->permission_error();
		}
		$course    = Course::find( $course_id );
		$is_author = $course && (int) ( $course->author_id ?? 0 ) === $user_id;
		$teaches   = array_map(
			static fn( $row ) => (int) $row->course_id,
			CourseInstructor::get_for_user( $user_id )
		);
		return ( $is_author || in_array( $course_id, $teaches, true ) ) ? true : $this->permission_error();
	}

	public function is_admin(): bool|\WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'manage_settings' is admin-only; Permission_Engine short-circuits on
		// manage_options so WP admins always pass through automatically.
		if ( ! $this->check_permission( 'manage_settings' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Route handlers
	// -------------------------------------------------------------------------

	public function revenue( WP_REST_Request $request ): WP_REST_Response {
		// Aggregate via indexed SQL SUM in the models instead of pulling every
		// completed transaction into PHP and summing in a loop (Basecamp
		// #9927990443). On a 100k+ transaction site the old path held ~80 MB of
		// stdClass rows for one dashboard widget; these serve the same numbers
		// at constant memory.
		$this_month_prefix = gmdate( 'Y-m' );
		$last_month_prefix = gmdate( 'Y-m', strtotime( 'first day of last month' ) );

		$lifetime         = Transaction::total_completed_revenue();
		$this_month       = Transaction::completed_revenue_in_month( $this_month_prefix );
		$last_month       = Transaction::completed_revenue_in_month( $last_month_prefix );
		$count            = Transaction::count_completed();
		$pending_wd_total = Withdrawal::sum_pending();

		$summary = array(
			'this_month'               => round( $this_month, 2 ),
			'last_month'               => round( $last_month, 2 ),
			'lifetime'                 => round( $lifetime, 2 ),
			'count'                    => $count,
			'pending_withdrawal_total' => round( $pending_wd_total, 2 ),
		);

		return $this->single_response( $summary );
	}

	public function list_commissions( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->get_pagination( $request );

		// Push LIMIT/OFFSET into the model so we don't hydrate every row
		// (Basecamp #9927990443). Old path held all commission rows in
		// memory for a single page render.
		$rows  = Commission::where( array(), 'created_at', 'DESC', (int) $pagination['limit'], (int) $pagination['offset'] );
		$total = Commission::count();

		$items = $this->enrich_with_author(
			array_map( fn( $c ) => (array) $c, $rows ),
			'recipient_id'
		);

		if ( 'csv' === $request->get_param( 'format' ) ) {
			// Map data keys to the export column names: enrich_with_author writes
			// `author_name` (not recipient_name) and the model column is
			// `payout_status` (not status), so both columns were blank for every
			// row. Alias them onto the keys the header asks for.
			$csv_rows = array_map(
				static function ( $row ) {
					$row['recipient_name'] = $row['author_name'] ?? ( $row['recipient_name'] ?? '' );
					$row['status']         = $row['payout_status'] ?? ( $row['status'] ?? '' );
					return $row;
				},
				$items
			);
			return Csv_Formatter::respond(
				[ 'id', 'recipient_id', 'recipient_name', 'amount', 'currency', 'status', 'payout_period', 'created_at' ],
				$csv_rows,
				'commissions'
			);
		}

		return $this->paginated_response( $items, $total, (int) $pagination['offset'] );
	}

	public function list_withdrawals( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->get_pagination( $request );

		// Bounded page fetch + indexed count, instead of get_pending() +
		// array_slice (Basecamp #9927990443).
		//
		// Order by `requested_at` — the real timestamp column on
		// wp_lrn_withdrawals. The table has NO `created_at` column (the
		// Withdrawal model writes/reads `requested_at` everywhere), so the
		// prior 'created_at' sort raised "Unknown column 'created_at' in
		// 'order clause'" and the admin withdrawals list errored
		// (Basecamp #9962456976).
		$rows  = Withdrawal::where(
			array( 'status' => 'pending' ),
			'requested_at',
			'DESC',
			(int) $pagination['limit'],
			(int) $pagination['offset']
		);
		$total = Withdrawal::count( array( 'status' => 'pending' ) );

		$items = $this->enrich_with_author(
			array_map( fn( $w ) => (array) $w, $rows ),
			'instructor_id'
		);

		if ( 'csv' === $request->get_param( 'format' ) ) {
			// Expose `requested_at` (the real column) instead of the
			// non-existent `created_at`; the prior field name produced an
			// always-empty CSV column (Basecamp #9962456976). Not a
			// documented SDK key (absent from audit/rest-contracts.json and
			// unread by any JS consumer), so renaming the key is safe.
			return Csv_Formatter::respond(
				[ 'id', 'instructor_id', 'instructor_name', 'amount', 'currency', 'method', 'status', 'requested_at' ],
				$items,
				'withdrawals'
			);
		}

		return $this->paginated_response( $items, $total, (int) $pagination['offset'] );
	}

	public function approve_withdrawal( WP_REST_Request $request ): WP_REST_Response {
		$withdrawal_id = absint( $request->get_param( 'id' ) );
		$reference     = sanitize_text_field( $request->get_param( 'reference' ) ?? '' );

		$withdrawal = Withdrawal::find( $withdrawal_id );
		if ( ! $withdrawal ) {
			return $this->error_response( $this->not_found( 'Withdrawal request' ) );
		}

		// Only PENDING withdrawals progress here. Allowing 'approved' /
		// 'processed' to re-enter this handler (the prior contract)
		// re-fired the `learnomy_withdrawal_approved` action on duplicate
		// POSTs — double-click, retry, race — which downstream
		// notification + payout listeners interpreted as a second
		// approval (duplicate emails / double payout). Treat re-approval
		// as an idempotent no-op so clients see an explicit signal
		// rather than a silent duplicate side-effect.
		//
		// `mark_processed()` transitions pending → processed (the prior
		// branch labelled it 'approved', but the actual column value is
		// 'processed' — match both for forward-compat with sites that
		// might have legacy 'approved' rows).
		if ( in_array( $withdrawal->status, array( 'approved', 'processed' ), true ) ) {
			return $this->single_response(
				array(
					'approved'  => true,
					'id'        => $withdrawal_id,
					'reference' => (string) ( $withdrawal->reference ?? '' ),
					'noop'      => true,
				)
			);
		}
		if ( 'pending' !== $withdrawal->status ) {
			return $this->error_response(
				$this->validation_error(
					__( 'Only pending withdrawals can be processed.', 'learnomy' )
				)
			);
		}

		// One settlement chokepoint (status transition + commission rows), shared
		// with the `learnomy_withdrawal_paid` rail. It is conditional on the row
		// still being `pending`, so a replayed approval that slipped past the
		// status read above — a double click, a retry, a Stripe transfer landing
		// in the same instant — loses the race here and settles nothing twice.
		if ( ! Commission_Service::settle_withdrawal( $withdrawal_id, $reference ) ) {
			$withdrawal = Withdrawal::find( $withdrawal_id );
			return $this->single_response(
				array(
					'approved'  => true,
					'id'        => $withdrawal_id,
					'reference' => (string) ( $withdrawal->reference ?? '' ),
					'noop'      => true,
				)
			);
		}

		// Reload the row so listeners receive the post-transition state
		// (status='processed', `reference` populated). Action contract:
		// `($user_id, $withdrawal_object)` — matches Notifier's
		// `on_withdrawal_approved(int $user_id, object $withdrawal)`
		// handler. The prior call passed `($withdrawal_id, $instructor_id)`
		// (two ints) which fataled the listener with a TypeError on every
		// approval — caught by smoke F-1 (2026-05-27).
		$withdrawal = Withdrawal::find( $withdrawal_id );
		do_action( 'learnomy_withdrawal_approved', (int) $withdrawal->instructor_id, $withdrawal );

		return $this->single_response(
			[
				'approved'  => true,
				'id'        => $withdrawal_id,
				'reference' => $reference,
			]
		);
	}

	public function reject_withdrawal( WP_REST_Request $request ): WP_REST_Response {
		$withdrawal_id = absint( $request->get_param( 'id' ) );
		$reason        = sanitize_text_field( $request->get_param( 'reason' ) );

		$withdrawal = Withdrawal::find( $withdrawal_id );
		if ( ! $withdrawal ) {
			return $this->error_response( $this->not_found( 'Withdrawal request' ) );
		}

		if ( 'pending' !== $withdrawal->status ) {
			return $this->error_response(
				$this->validation_error( __( 'Only pending withdrawals can be rejected.', 'learnomy' ) )
			);
		}

		Withdrawal::reject( $withdrawal_id, $reason );

		// Return the claimed commissions to `pending` so the instructor's
		// available balance (which counts only `pending`) comes back. Without
		// this a rejected withdrawal stranded the balance forever — the
		// commissions stayed `requested` (not payable, not paid).
		Commission::revert_requested_to_pending( $withdrawal_id );

		do_action( 'learnomy_withdrawal_rejected', $withdrawal_id, (int) $withdrawal->instructor_id, $reason );

		return $this->single_response(
			[
				'rejected' => true,
				'id'       => $withdrawal_id,
			]
		);
	}

	/**
	 * POST /admin/commission-rules — create a per-course or per-instructor rate
	 * override. Per-instructor rules are Pro (lean-Pro decision); Free creates
	 * per-course only. Validates the entity exists, blocks duplicates (the
	 * `(rule_type, entity_id)` unique key would reject them anyway), and stores
	 * the platform rate.
	 */
	public function create_commission_rule( WP_REST_Request $request ): WP_REST_Response {
		$commissions_off = $this->commissions_module_off();
		if ( $commissions_off ) {
			return $this->error_response( $commissions_off );
		}

		$rule_type     = (string) $request->get_param( 'rule_type' );
		$entity_id     = absint( $request->get_param( 'entity_id' ) );
		$platform_rate = (float) $request->get_param( 'platform_rate' );

		if ( 'instructor' === $rule_type && ! (bool) apply_filters( 'learnomy_is_pro', defined( 'LEARNOMY_PRO_VERSION' ) ) ) {
			return $this->error_response(
				$this->validation_error( __( 'Per-instructor commission rates are a Pro feature.', 'learnomy' ) )
			);
		}
		if ( $entity_id <= 0 ) {
			return $this->error_response( $this->validation_error( __( 'Choose a course or instructor for the rule.', 'learnomy' ) ) );
		}

		// Confirm the target exists so a rule can't dangle on a deleted entity.
		if ( 'course' === $rule_type && ! \Learnomy\Models\Course::find( $entity_id ) ) {
			return $this->error_response( $this->not_found( __( 'Course', 'learnomy' ) ) );
		}
		if ( 'instructor' === $rule_type && ! get_userdata( $entity_id ) ) {
			return $this->error_response( $this->not_found( __( 'Instructor', 'learnomy' ) ) );
		}

		if ( CommissionRule::get_for_entity_exact( $rule_type, $entity_id ) ) {
			return $this->error_response(
				$this->validation_error( __( 'A rule already exists for that course or instructor. Edit it instead.', 'learnomy' ) )
			);
		}

		$id = CommissionRule::create(
			array(
				'rule_type'     => $rule_type,
				'entity_id'     => $entity_id,
				'platform_rate' => $platform_rate,
			)
		);

		return $this->single_response( CommissionRule::to_typed_array( CommissionRule::find( (int) $id ) ) );
	}

	public function update_commission_rule( WP_REST_Request $request ): WP_REST_Response {
		$commissions_off = $this->commissions_module_off();
		if ( $commissions_off ) {
			return $this->error_response( $commissions_off );
		}

		$rule_id       = absint( $request->get_param( 'id' ) );
		$platform_rate = $request->get_param( 'platform_rate' );

		$rule = CommissionRule::find( $rule_id );
		if ( ! $rule ) {
			return $this->error_response( $this->not_found( 'Commission rule' ) );
		}

		if ( null !== $platform_rate ) {
			$platform_rate = (float) $platform_rate;

			if ( $platform_rate < 0 || $platform_rate > 100 ) {
				return $this->error_response(
					$this->validation_error( __( 'Platform rate must be between 0 and 100.', 'learnomy' ) )
				);
			}

			CommissionRule::update( $rule_id, [ 'platform_rate' => $platform_rate ] );
		}

		return $this->single_response( CommissionRule::to_typed_array( CommissionRule::find( $rule_id ) ) );
	}

	/**
	 * DELETE /admin/commission-rules/{id} — drop a per-course or per-instructor
	 * override.
	 *
	 * Deleting an override is not deleting a rate: resolution falls back to the
	 * instructor rule, then the global rule, then the platform default (see
	 * Commission_Service::resolve_platform_rate). Existing commission rows are
	 * untouched, because they recorded the rate that applied at the time of the
	 * sale — re-rating history from a settings change would silently rewrite
	 * what instructors were already told they had earned.
	 *
	 * The GLOBAL rule is deliberately not deletable here. It is the site-wide
	 * default that every unset course and instructor falls back to, so removing
	 * it would change the rate on every one of them at once from a row that
	 * looks like any other in this table. Owners change it by editing the rate.
	 */
	public function delete_commission_rule( WP_REST_Request $request ): WP_REST_Response {
		$commissions_off = $this->commissions_module_off();
		if ( $commissions_off ) {
			return $this->error_response( $commissions_off );
		}

		$rule_id = absint( $request->get_param( 'id' ) );

		$rule = CommissionRule::find( $rule_id );
		if ( ! $rule ) {
			return $this->error_response( $this->not_found( 'Commission rule' ) );
		}

		if ( 'global' === (string) ( $rule->rule_type ?? '' ) ) {
			return $this->error_response(
				$this->validation_error(
					__( 'The global commission rule cannot be deleted, because every course and instructor without an override falls back to it. Edit its rate instead.', 'learnomy' )
				)
			);
		}

		CommissionRule::delete( $rule_id );

		return $this->single_response(
			[
				'deleted' => true,
				'id'      => $rule_id,
			]
		);
	}

	/**
	 * Refuse a commission-rule write while the `commissions` module is off.
	 *
	 * With the module off the Earnings screen is unregistered, so a rule
	 * created here would govern real payouts from a screen the owner cannot
	 * open to inspect or delete it. The rule endpoints answer 409 rather than
	 * dropping the field the way the course endpoint does, because a caller
	 * whose ENTIRE request is "make this commission rule" deserves to be told
	 * it did not happen.
	 *
	 * @return \WP_Error|null Error when the module is off, null when it is on.
	 */
	private function commissions_module_off(): ?\WP_Error {
		if ( Module_Kernel::instance()->is_enabled( 'commissions' ) ) {
			return null;
		}

		return new \WP_Error(
			'learnomy_module_disabled',
			__( 'The Commissions module is switched off, so commission rules cannot be changed. Enable it under LMS Settings > Modules first.', 'learnomy' ),
			[ 'status' => 409 ]
		);
	}

	public function list_applications( WP_REST_Request $request ): WP_REST_Response {
		$status     = sanitize_text_field( $request->get_param( 'status' ) ?? 'pending' );
		$pagination = $this->get_pagination( $request );

		// Push LIMIT/OFFSET into the model (Basecamp #9927990443) — the
		// old path fetched every application row of the requested status
		// before slicing in PHP.
		$where = array( 'status' => $status );
		$rows  = InstructorApplication::where(
			$where,
			'created_at',
			'DESC',
			(int) $pagination['limit'],
			(int) $pagination['offset']
		);
		$total = InstructorApplication::count( $where );

		$items = $this->enrich_with_author(
			array_map( fn( $a ) => (array) $a, $rows ),
			'user_id'
		);

		if ( 'csv' === $request->get_param( 'format' ) ) {
			return Csv_Formatter::respond(
				[ 'id', 'user_id', 'user_name', 'status', 'bio', 'expertise', 'experience', 'course_idea', 'created_at' ],
				$items,
				'instructor-applications'
			);
		}

		return $this->paginated_response( $items, $total, (int) $pagination['offset'] );
	}

	public function approve_application( WP_REST_Request $request ): WP_REST_Response {
		$app_id   = absint( $request->get_param( 'id' ) );
		$admin_id = get_current_user_id();

		$app = InstructorApplication::find( $app_id );
		if ( ! $app ) {
			return $this->error_response( $this->not_found( 'Instructor application' ) );
		}

		if ( 'pending' !== $app->status ) {
			return $this->error_response(
				$this->validation_error( __( 'This application has already been reviewed.', 'learnomy' ) )
			);
		}

		InstructorApplication::approve( $app_id, $admin_id );

		// Grant the instructor role + ensure the user holds every
		// instructor capability directly. Belt-and-suspenders: even if the
		// `lrn_instructor` role has drifted (Capabilities migration didn't
		// re-add a cap that shipped later, or the role was created by an
		// older release), the per-user `add_cap()` calls below guarantee
		// the approved instructor can reach `?page=learnomy-new-course`
		// without falling through to wp-admin's auth_redirect → /my-account/.
		// Also re-runs the role healer so future approvals + existing
		// instructors share the same cap set going forward.
		$user = get_userdata( (int) $app->user_id );
		if ( $user ) {
			$user->add_role( 'lrn_instructor' );

			// Bug fix Basecamp #9936673357: applicants register as lrn_student
			// and that role lingered after approval — leaving the user with
			// BOTH lrn_student + lrn_instructor. WordPress's primary-role
			// display picks the first match, so wp-admin Users list and
			// every "current role" UI showed "Student" for someone who's
			// actually an Instructor. They are mutually exclusive in our
			// model (course access is gated by Permission_Engine, not by
			// having lrn_student), so drop the student role on approval.
			if ( in_array( 'lrn_student', (array) $user->roles, true ) ) {
				$user->remove_role( 'lrn_student' );
			}

			$capabilities = new Capabilities();
			$capabilities->register();

			foreach ( $this->instructor_caps_for_grant() as $cap ) {
				if ( ! $user->has_cap( $cap ) ) {
					$user->add_cap( $cap );
				}
			}
		}

		// Create + seed the consolidated instructor profile from the application
		// so a newly-approved instructor appears in the Instructors list and
		// starts with the bio/expertise they submitted (co-author model — ends
		// the F4 scatter where the bio was stranded in the write-once
		// application row). Shared with the Abilities approve path so the two
		// can't drift. Only fills fields the profile doesn't already have.
		\Learnomy\Models\Instructor::provision_from_application( $app );

		do_action( 'learnomy_instructor_application_approved', $app_id, (int) $app->user_id );

		return $this->single_response(
			[
				'approved' => true,
				'id'       => $app_id,
				'user_id'  => (int) $app->user_id,
			]
		);
	}

	/**
	 * Capability list assigned to a freshly-approved instructor. Mirrored
	 * from {@see Capabilities::instructor_only_caps()} + student caps that
	 * an instructor also needs (enroll into their own courses, take their
	 * own quizzes for QA, leave reviews) + the WP `upload_files` baseline
	 * needed by the course/lesson media library.
	 *
	 * Kept in sync with Capabilities::add_instructor_role() — the assertion
	 * test in D.instructor-approval-grants-caps fails CI if the list drifts.
	 */
	private function instructor_caps_for_grant(): array {
		return array(
			'learnomy_create_course',
			'learnomy_manage_own_courses',
			'learnomy_view_own_students',
			'learnomy_view_own_earnings',
			'learnomy_manage_quizzes',
			'learnomy_grade',
			'upload_files',
			'learnomy_enroll',
			'learnomy_learn',
			'learnomy_take_quiz',
			'learnomy_leave_review',
			'learnomy_view_courses',
			'read',
		);
	}

	public function reject_application( WP_REST_Request $request ): WP_REST_Response {
		$app_id   = absint( $request->get_param( 'id' ) );
		$admin_id = get_current_user_id();
		$feedback = wp_kses_post( $request->get_param( 'feedback' ) ?? '' );

		$app = InstructorApplication::find( $app_id );
		if ( ! $app ) {
			return $this->error_response( $this->not_found( 'Instructor application' ) );
		}

		if ( 'pending' !== $app->status ) {
			return $this->error_response(
				$this->validation_error( __( 'This application has already been reviewed.', 'learnomy' ) )
			);
		}

		InstructorApplication::reject( $app_id, $admin_id, $feedback );

		do_action( 'learnomy_instructor_application_rejected', $app_id, (int) $app->user_id, $feedback );

		return $this->single_response(
			[
				'rejected' => true,
				'id'       => $app_id,
			]
		);
	}

	/**
	 * Manually enroll a student in a course (admin grant). The `manual`
	 * source is the admin-override path in Enrollment_Service, so this grants
	 * access to paid / membership courses too — the permission_callback has
	 * already confirmed the caller is an admin. Prerequisites still apply.
	 */
	public function enroll_student( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = absint( $request->get_param( 'id' ) );
		$course_id = absint( $request->get_param( 'course_id' ) );

		if ( ! get_userdata( $user_id ) ) {
			return $this->error_response( $this->not_found( 'Student' ) );
		}

		return $this->do_manual_enroll( $user_id, $course_id );
	}

	/**
	 * Reset a student's progress + quiz attempts for one course so they can
	 * retake it from the start. The enrollment row itself is kept.
	 */
	/**
	 * Pause or resume an instructor.
	 *
	 * Suspension hides them from the public directory and refuses NEW
	 * enrollments in their courses. It deliberately does NOT touch their
	 * courses, their existing students' access, or money already owed - see
	 * Instructor::STATUS_SUSPENDED for why deleting the profile row (what the
	 * card originally asked for) would have been both destructive and
	 * ineffective.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function set_instructor_status( $request ): \WP_REST_Response {
		$user_id = (int) $request->get_param( 'id' );
		$status  = (string) $request->get_param( 'status' );

		if ( ! get_userdata( $user_id ) ) {
			return $this->error_response( $this->not_found( 'Instructor' ) );
		}

		if ( ! Instructor::set_status( $user_id, $status ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_instructor_status_failed',
					__( 'Could not change this instructor status.', 'learnomy' ),
					[ 'status' => 500 ]
				)
			);
		}

		return $this->single_response(
			[
				'user_id' => $user_id,
				'status'  => $status,
			]
		);
	}

	public function reset_student_course( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = absint( $request->get_param( 'id' ) );
		$course_id = absint( $request->get_param( 'course_id' ) );

		if ( ! get_userdata( $user_id ) ) {
			return $this->error_response( $this->not_found( 'Student' ) );
		}

		\Learnomy\Models\Progress::reset_for_course( $user_id, $course_id );
		\Learnomy\Models\QuizAttempt::delete_for_user_course( $user_id, $course_id );
		// Also reset the denormalized enrollment summary, otherwise the
		// enrollments table keeps showing "100%" + the Completed badge after
		// the per-lesson rows are wiped (Basecamp #10042843844).
		\Learnomy\Models\Enrollment::reset_for_user_course( $user_id, $course_id );

		do_action( 'learnomy_admin_reset_course_progress', $user_id, $course_id );

		return $this->single_response(
			[
				'reset'     => true,
				'user_id'   => $user_id,
				'course_id' => $course_id,
			]
		);
	}

	/**
	 * Give one student extra attempts on one quiz.
	 *
	 * The targeted counterpart to {@see reset_student_course()}: that endpoint
	 * unblocks a capped quiz by deleting every attempt and all lesson progress
	 * for the whole course, which costs the student their completion date and
	 * their certificate. This adds to the student's own cap for a single quiz
	 * and touches nothing else (Basecamp 10134140122).
	 *
	 * Balance state lives on the user; who granted it and when lives in the
	 * audit log, so an owner can answer "why does this student have 4 attempts"
	 * — and, since instructors grant too, "who gave it to them".
	 */
	public function grant_quiz_attempts( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = absint( $request->get_param( 'id' ) );
		$quiz_id  = absint( $request->get_param( 'quiz_id' ) );
		$attempts = (int) $request->get_param( 'attempts' );

		if ( ! get_userdata( $user_id ) ) {
			return $this->error_response( $this->not_found( 'Student' ) );
		}

		$quiz = \Learnomy\Models\Quiz::find( $quiz_id );
		if ( ! $quiz ) {
			return $this->error_response( $this->not_found( 'Quiz' ) );
		}

		$granted = \Learnomy\Models\Quiz::grant_attempts( $user_id, $quiz_id, $attempts );

		\Learnomy\Models\AuditLog::log(
			get_current_user_id(),
			'quiz',
			'attempts.grant',
			'quiz',
			$quiz_id,
			array(
				'student_id' => $user_id,
				'delta'      => $attempts,
				'granted'    => $granted,
			)
		);

		/**
		 * Fires after an admin changes a student's extra-attempt balance on a quiz.
		 *
		 * @since 1.8.1
		 *
		 * @param int $user_id Student the grant applies to.
		 * @param int $quiz_id Quiz the grant applies to.
		 * @param int $granted The student's new grant total for this quiz.
		 * @param int $delta   Attempts added (negative when removed).
		 */
		do_action( 'learnomy_quiz_attempts_granted', $user_id, $quiz_id, $granted, $attempts );

		return $this->single_response(
			array(
				'user_id'       => $user_id,
				'quiz_id'       => $quiz_id,
				'granted'       => $granted,
				'attempt_limit' => \Learnomy\Models\Quiz::effective_attempt_limit_for_user( $quiz, $user_id ),
				'attempts_used' => \Learnomy\Models\QuizAttempt::count_graded( $user_id, $quiz_id ),
			)
		);
	}

	/**
	 * Toggle an instructor's verification badge (verified ↔ unverified).
	 */
	public function set_instructor_verification( WP_REST_Request $request ): WP_REST_Response {
		$user_id = absint( $request->get_param( 'id' ) );
		$status  = 1 === (int) $request->get_param( 'verified' ) ? 'verified' : 'unverified';

		if ( ! get_userdata( $user_id ) ) {
			return $this->error_response( $this->not_found( 'Instructor' ) );
		}

		\Learnomy\Models\Instructor::set_verification_status( $user_id, $status );

		return $this->single_response(
			[
				'user_id'             => $user_id,
				'verification_status' => $status,
			]
		);
	}

	/**
	 * Toggle the per-instructor "require course approval" override. When on, the
	 * instructor's course publishes are downgraded to Pending Review even if the
	 * site-wide self-publish setting is enabled — see
	 * Course_Service::requires_approval(). Stored as the `learnomy_require_course_approval`
	 * user meta ('1' when on; the row is deleted when off so absence == default).
	 */
	public function set_instructor_require_approval( WP_REST_Request $request ): WP_REST_Response {
		$user_id = absint( $request->get_param( 'id' ) );
		$require = 1 === (int) $request->get_param( 'require_approval' );

		if ( ! get_userdata( $user_id ) ) {
			return $this->error_response( $this->not_found( 'Instructor' ) );
		}

		if ( $require ) {
			update_user_meta( $user_id, 'learnomy_require_course_approval', '1' );
		} else {
			delete_user_meta( $user_id, 'learnomy_require_course_approval' );
		}

		return $this->single_response(
			[
				'user_id'          => $user_id,
				'require_approval' => $require,
			]
		);
	}

	/**
	 * Enroll a student into a course by email or username (admin grant from the
	 * course overview page). Resolves the identifier server-side, then delegates
	 * to the shared enroll helper — the admin-override path, so paid/membership
	 * courses are granted too. Prerequisites still apply.
	 */
	public function enroll_into_course( WP_REST_Request $request ): WP_REST_Response {
		$course_id = absint( $request->get_param( 'id' ) );
		$query     = trim( (string) $request->get_param( 'user' ) );

		if ( '' === $query ) {
			return $this->error_response( $this->validation_error( __( 'Enter an email address or username.', 'learnomy' ) ) );
		}

		$user = is_email( $query )
			? get_user_by( 'email', $query )
			: ( get_user_by( 'login', $query ) ?: get_user_by( 'email', $query ) );
		if ( ! $user ) {
			return $this->error_response(
				$this->validation_error(
					/* translators: %s: the email or username searched for */
					sprintf( __( 'No user found for "%s".', 'learnomy' ), $query )
				)
			);
		}

		return $this->do_manual_enroll( (int) $user->ID, $course_id );
	}

	/**
	 * Shared manual-enroll implementation used by enroll_student() and
	 * enroll_into_course() — both paths grant a manual enrollment in the same
	 * way and return the same response shape; only user resolution differs.
	 */
	private function do_manual_enroll( int $user_id, int $course_id ): WP_REST_Response {
		if ( ! Course::find( $course_id ) ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		$result = ( new \Learnomy\Services\Enrollment_Service() )->enroll( $user_id, $course_id, 'manual' );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}
		if ( ! $result ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not grant access. The student may already be enrolled, or the course has prerequisites they have not met.', 'learnomy' ) )
			);
		}

		return $this->single_response(
			[
				'enrolled'      => true,
				'user_id'       => $user_id,
				'course_id'     => $course_id,
				'enrollment_id' => (int) $result,
			]
		);
	}

	/**
	 * Bulk-enroll a pasted / uploaded list of emails into a course. Unknown
	 * emails are provisioned (account + set-password invite) via the same
	 * Provisioning_Service the Closed webhook uses; already-enrolled students are
	 * skipped. Capped per request (filter `learnomy_bulk_enroll_max`, default
	 * 500) so a huge paste can't time out — the capped remainder is reported so
	 * the owner re-submits the rest (no silent truncation).
	 */
	public function bulk_enroll_into_course( WP_REST_Request $request ): WP_REST_Response {
		$course_id = absint( $request->get_param( 'id' ) );
		if ( ! Course::find( $course_id ) ) {
			return $this->error_response( $this->not_found( 'Course' ) );
		}

		// Split on any whitespace / comma / semicolon, sanitize, dedupe.
		$unique = [];
		foreach ( (array) $request->get_param( 'emails' ) as $line ) {
			foreach ( preg_split( '/[\s,;]+/', (string) $line ) as $token ) {
				$email = sanitize_email( trim( (string) $token ) );
				if ( is_email( $email ) ) {
					$unique[ strtolower( $email ) ] = $email;
				}
			}
		}
		$emails = array_values( $unique );

		if ( empty( $emails ) ) {
			return $this->error_response( $this->validation_error( __( 'No valid email addresses were found in the list.', 'learnomy' ) ) );
		}

		$cap    = (int) apply_filters( 'learnomy_bulk_enroll_max', 500 );
		$capped = count( $emails ) - $cap;
		if ( $capped > 0 ) {
			$emails = array_slice( $emails, 0, $cap );
		}

		$service      = new \Learnomy\Services\Enrollment_Service();
		$provisioning = new \Learnomy\Services\Provisioning_Service();
		$rows         = [];
		$summary      = [
			'enrolled' => 0,
			'created'  => 0,
			'skipped'  => 0,
			'failed'   => 0,
		];

		foreach ( $emails as $email ) {
			$existing = get_user_by( 'email', $email );

			if ( $existing && \Learnomy\Models\Enrollment::find_for_user_course( (int) $existing->ID, $course_id ) ) {
				$rows[] = [
					'email'  => $email,
					'status' => 'skipped',
				];
				++$summary['skipped'];
				continue;
			}

			$user_id = $provisioning->ensure_user_by_email( $email );
			if ( is_wp_error( $user_id ) ) {
				$rows[] = [
					'email'  => $email,
					'status' => 'failed',
				];
				++$summary['failed'];
				continue;
			}

			$created = ! $existing;
			$result  = $service->enroll( (int) $user_id, $course_id, 'manual' );
			if ( is_wp_error( $result ) || ! $result ) {
				$rows[] = [
					'email'  => $email,
					'status' => 'failed',
				];
				++$summary['failed'];
				continue;
			}

			$rows[] = [
				'email'  => $email,
				'status' => $created ? 'created' : 'enrolled',
			];
			$created ? ++$summary['created'] : ++$summary['enrolled'];
		}

		return $this->single_response(
			[
				'summary'   => $summary,
				'rows'      => $rows,
				'remaining' => max( 0, $capped ),
			]
		);
	}

	/**
	 * Stream every transaction row as CSV. Admins use this as the audit
	 * trail / accounting feed — the legacy AJAX export it replaces had no
	 * filters and dumped the full ledger, so we keep the same scope here.
	 */
	public function export_transactions_csv( WP_REST_Request $request ): WP_REST_Response {
		// Cursor-iterate every transaction instead of hydrating the whole
		// ledger upfront (Basecamp #9927990443). On a site with 500k
		// transactions the old path doubled memory on each enrichment pass;
		// the cursor keeps memory bounded by $batch_size (500 rows ≈ 2-4 MB).
		$transactions = Transaction::iterate_all( array(), 500 );

		$buffer = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv(
			$buffer,
			[
				__( 'ID', 'learnomy' ),
				__( 'User ID', 'learnomy' ),
				__( 'Course ID', 'learnomy' ),
				__( 'Amount', 'learnomy' ),
				__( 'Currency', 'learnomy' ),
				__( 'Status', 'learnomy' ),
				__( 'Provider', 'learnomy' ),
				__( 'Provider Transaction ID', 'learnomy' ),
				__( 'Created At', 'learnomy' ),
			]
		);
		foreach ( $transactions as $txn ) {
			fputcsv(
				$buffer,
				[
					$txn->id,
					$txn->user_id ?? '',
					$txn->course_id ?? '',
					$txn->amount ?? '0.00',
					$txn->currency ?? '',
					$txn->status ?? '',
					$txn->payment_provider ?? '',
					$txn->provider_transaction_id ?? '',
					$txn->created_at ?? '',
				]
			);
		}

		rewind( $buffer );
		$csv = (string) stream_get_contents( $buffer );
		fclose( $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$response = new WP_REST_Response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="learnomy-transactions-' . gmdate( 'Y-m-d' ) . '.csv"' );
		return $response;
	}

	/**
	 * Stream the enrolled student list as a CSV download.
	 *
	 * Uses a cursor-style batch loop (2 000 user IDs per round) rather than
	 * get_users(number:-1) to keep memory bounded on sites with 50k+ students.
	 * Mirrors the Transaction::iterate_all() pattern used by export_transactions_csv.
	 */
	public function export_students_csv( WP_REST_Request $request ): WP_REST_Response {
		// Enrollment-driven (matches the Students list), NOT role='lrn_student'
		// — the role filter silently dropped enrolled users who lack the role
		// (admin-manual / purchase-guest / demo enrollments). Basecamp #9958305530.
		$all_user_ids = Enrollment::get_all_distinct_user_ids();

		$buffer = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv(
			$buffer,
			[
				__( 'ID', 'learnomy' ),
				__( 'Name', 'learnomy' ),
				__( 'Email', 'learnomy' ),
				__( 'Registered', 'learnomy' ),
			]
		);

		// Process in 2 000-ID batches so PHP memory stays bounded regardless of
		// total student count (50k users × ~10 usermeta = 500k rows in one query).
		$batch_size = 2000;
		$chunks     = array_chunk( $all_user_ids, $batch_size );
		foreach ( $chunks as $id_batch ) {
			$students = get_users(
				[
					'include' => $id_batch,
					'orderby' => 'registered',
					'order'   => 'DESC',
					'number'  => count( $id_batch ),
				]
			);
			foreach ( $students as $student ) {
				fputcsv(
					$buffer,
					[
						$student->ID,
						$student->display_name,
						$student->user_email,
						$student->user_registered,
					]
				);
			}
		}

		rewind( $buffer );
		$csv = (string) stream_get_contents( $buffer );
		fclose( $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$response = new WP_REST_Response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="learnomy-students-' . gmdate( 'Y-m-d' ) . '.csv"' );
		return $response;
	}

	/**
	 * Broadcast an announcement to enrolled students. Creates an in-app
	 * notification per recipient via the Notification_Service; the email
	 * channel is opt-in via the `send_email` flag (per-user unsubscribe
	 * preferences still apply downstream).
	 */
	public function send_announcement( WP_REST_Request $request ): WP_REST_Response {
		$title      = (string) $request->get_param( 'title' );
		$message    = (string) $request->get_param( 'message' );
		$course_id  = absint( $request->get_param( 'course_id' ) );
		$send_email = (bool) $request->get_param( 'send_email' );

		// All-students broadcast (course_id=0) is destructive at scale —
		// require explicit `confirm: true` so a misclick can't fire 10k
		// emails. Per-course announcements skip this guard because the
		// course_id picker is already a deliberate selection.
		if ( 0 === $course_id ) {
			$body = (array) ( $request->get_json_params() ?? array() );
			if ( true !== ( $body['confirm'] ?? false ) ) {
				return $this->error_response(
					new \WP_Error(
						'learnomy_confirm_required',
						__( 'All-students announcement requires {"confirm": true} in the body to fire.', 'learnomy' ),
						array( 'status' => 400 )
					)
				);
			}
		}

		$user_ids = Enrollment::get_active_user_ids( $course_id );
		if ( empty( $user_ids ) ) {
			return $this->error_response(
				$this->validation_error( __( 'No enrolled students found for the selected course.', 'learnomy' ) )
			);
		}

		// Same audience ceiling the Broadcasts_Controller twin enforces — this
		// endpoint had none, so a 50k-student course would build a 50k-id array
		// and fan out to all of them from one request. 0 = unlimited.
		$max_audience = (int) apply_filters( 'learnomy_broadcast_max_audience', 10000 );
		if ( $max_audience > 0 && count( $user_ids ) > $max_audience ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_broadcast_audience_too_large',
					sprintf(
						/* translators: %s: maximum audience size */
						__( 'This announcement would reach more than %s students. Narrow the audience or raise the learnomy_broadcast_max_audience limit.', 'learnomy' ),
						number_format_i18n( $max_audience )
					),
					array( 'status' => 422 )
				)
			);
		}

		$service      = new Notification_Service();
		$course       = $course_id ? Course::find( $course_id ) : null;
		$course_title = $course ? $course->title : __( 'All Courses', 'learnomy' );
		$actor_id     = get_current_user_id();

		$base_data = array(
			'title'        => $title,
			'message'      => $message,
			'object_type'  => 'course',
			'object_id'    => $course_id,
			'actor_id'     => $actor_id,
			'course_title' => $course_title,
			// Same defect as the space/instructor twin: send_email decided only
			// whether to attach a link, so unticking it still emailed everyone.
			// Notification_Service treats this as a decline-only opt-out.
			'send_email'   => $send_email,
		);
		if ( $send_email && $course ) {
			$base_data['action_url']   = \Learnomy\route_url( 'course', array( 'slug' => (string) $course->slug ) );
			$base_data['action_label'] = __( 'View Course', 'learnomy' );
		}

		// The broadcast's identity: ONE compose-time timestamp stamped onto
		// every recipient row. Async workers otherwise write their own
		// insert-time, splintering one announcement into several
		// (title, created_at) identities — which is exactly what recall
		// targets, so recall only reached the recipients whose job ran in
		// the same second (Basecamp 10132809006).
		$base_data['created_at'] = \Learnomy\now();
		// ...and one identity for the whole fan-out, so recall targets the
		// broadcast itself rather than inferring it from a timestamp tuple
		// (Basecamp 10132809203).
		$base_data['broadcast_id'] = wp_generate_password( 32, false, false );

		// One primitive for every fan-out: send_many() queues one Action
		// Scheduler job per recipient (default async), so the REST call returns
		// in milliseconds instead of blocking on thousands of sequential wp_mail
		// / push calls. Replaces the endpoint-local threshold+loop that each
		// announce twin carried its own slightly-different copy of.
		$count = $service->send_many( $user_ids, 'announcement', $base_data );

		return $this->single_response(
			array(
				'sent'   => true,
				'queued' => $service->deliver_async_enabled(),
				'count'  => $count,
			)
		);
	}

	/**
	 * Recall a sent announcement broadcast — delete every per-recipient
	 * notification row belonging to it.
	 *
	 * Prefers `broadcast_id`, which every fan-out has carried since 3.47.0 and
	 * which the migration backfilled onto historical rows. The (created_at,
	 * title, object_id) tuple stays as the fallback for a caller that has not
	 * been updated: it identifies a broadcast only while every recipient row
	 * shares one timestamp, which is exactly the assumption that failed
	 * (Basecamp 10132809203).
	 */
	public function delete_announcement( WP_REST_Request $request ): WP_REST_Response {
		$created_at   = (string) $request->get_param( 'created_at' );
		$title        = (string) $request->get_param( 'title' );
		$object_id    = absint( $request->get_param( 'object_id' ) );
		$broadcast_id = (string) $request->get_param( 'broadcast_id' );

		// A recall issued with only the tuple can still resolve the id, so an
		// older client gets the reliable delete too.
		if ( '' === $broadcast_id && '' !== $created_at && '' !== $title ) {
			$broadcast_id = \Learnomy\Models\Notification::broadcast_id_for( $created_at, $title, $object_id );
		}

		$deleted = \Learnomy\Models\Notification::delete_announcement( $created_at, $title, $object_id, $broadcast_id );
		if ( $deleted <= 0 ) {
			return $this->error_response(
				$this->validation_error( __( 'No matching announcement was found to delete.', 'learnomy' ) )
			);
		}

		return $this->single_response(
			[
				'deleted' => true,
				'count'   => $deleted,
			]
		);
	}

	/**
	 * Toggle a module on or off. Aware of Pro extension slugs (`pro:<id>`)
	 * which round-trip through the `learnomy_pro_extensions` option rather
	 * than the Free Module_Kernel.
	 *
	 * A disable that would walk away from live commitments (people still being
	 * billed, payouts still owed, certificates already awarded) answers **409**
	 * with the impact list instead of switching anything off. Re-POST with
	 * `confirm: true` to proceed. The gate lives here rather than in the JS so
	 * a scripted or agent-driven disable gets the same stop — the original bug
	 * was reported against a raw REST call, not against the Modules page.
	 */
	public function toggle_module( WP_REST_Request $request ): WP_REST_Response {
		$slug    = (string) $request->get_param( 'slug' );
		$enabled = (bool) $request->get_param( 'enabled' );

		$catalog = Module_Kernel::catalog();
		if ( ! isset( $catalog[ $slug ] ) ) {
			return $this->error_response( $this->not_found( 'Module' ) );
		}

		if ( in_array( $slug, Module_Kernel::required(), true ) && ! $enabled ) {
			return $this->error_response(
				$this->validation_error( __( 'This module is required and cannot be disabled.', 'learnomy' ) )
			);
		}

		if ( ! $enabled && ! (bool) $request->get_param( 'confirm' ) ) {
			$impact = Module_Kernel::disable_impact( $slug );
			if ( ! empty( $impact ) ) {
				return $this->error_response(
					new \WP_Error(
						'learnomy_module_disable_impact',
						__( 'This module still has live commitments. Review them, then confirm to switch it off.', 'learnomy' ),
						[
							'status' => 409,
							'impact' => $impact,
						]
					)
				);
			}
		}

		if ( 0 === strpos( $slug, 'pro:' ) ) {
			$pro_id      = substr( $slug, 4 );
			$pro_enabled = (array) get_option( 'learnomy_pro_extensions', [] );
			$pro         = class_exists( 'Learnomy_Pro\\Learnomy_Pro' ) ? \Learnomy_Pro\Learnomy_Pro::instance() : null;

			if ( $enabled && ! in_array( $pro_id, $pro_enabled, true ) ) {
				$pro_enabled[] = $pro_id;
				if ( $pro ) {
					$ext = $pro->get_extension( $pro_id );
					if ( $ext ) {
						$ext->activate(); }
				}
			} elseif ( ! $enabled ) {
				$pro_enabled = array_values( array_diff( $pro_enabled, [ $pro_id ] ) );
				if ( $pro ) {
					$ext = $pro->get_extension( $pro_id );
					if ( $ext ) {
						$ext->deactivate(); }
				}
			}
			update_option( 'learnomy_pro_extensions', array_values( array_unique( $pro_enabled ) ) );
		} else {
			$kernel = Module_Kernel::instance();
			if ( $enabled ) {
				$kernel->enable( $slug );
			} else {
				$kernel->disable( $slug );
			}
		}

		do_action( 'learnomy_module_toggled', $slug, $enabled );

		return $this->single_response(
			[
				'slug'    => $slug,
				'enabled' => $enabled,
			]
		);
	}

	/**
	 * PATCH /admin/onboarding-state — single endpoint that handles the four
	 * mutations the Getting Started panel needs:
	 *   { dismissed: true|false }      → reopen / dismiss the panel
	 *   { skip:   "step-id" }          → mark a step skipped
	 *   { unskip: "step-id" }          → undo a skip
	 */
	public function patch_onboarding_state( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) ( $request->get_json_params() ?? [] );

		if ( array_key_exists( 'dismissed', $body ) ) {
			update_option( 'learnomy_onboarding_dismissed', $body['dismissed'] ? 1 : 0, false );
		}

		if ( array_key_exists( 'skip', $body ) ) {
			$step_id = sanitize_key( (string) $body['skip'] );
			if ( '' === $step_id ) {
				return $this->error_response( $this->validation_error( __( 'Missing step identifier.', 'learnomy' ) ) );
			}
			$ok = Onboarding_State::mark_skipped( $step_id );
			if ( ! $ok ) {
				return $this->error_response( $this->validation_error( __( 'This step cannot be skipped.', 'learnomy' ) ) );
			}
		}

		if ( array_key_exists( 'unskip', $body ) ) {
			$step_id = sanitize_key( (string) $body['unskip'] );
			if ( '' === $step_id ) {
				return $this->error_response( $this->validation_error( __( 'Missing step identifier.', 'learnomy' ) ) );
			}
			Onboarding_State::undo_skip( $step_id );
		}

		return $this->single_response(
			[
				'dismissed' => (bool) get_option( 'learnomy_onboarding_dismissed', false ),
				'resolved'  => Onboarding_State::resolve(),
			]
		);
	}

	/**
	 * Persist a per-user dismissal flag for one of the whitelisted admin
	 * notices. Used by plan-08's Pro Features pointer notice on the Modules
	 * page; can be reused by any future notice that needs the same pattern.
	 */
	public function dismiss_notice( WP_REST_Request $request ): WP_REST_Response {
		$notice_id = sanitize_key( (string) $request->get_param( 'notice_id' ) );

		$allowed = [
			'learnomy_pro_modules_notice_dismissed',
		];

		if ( ! in_array( $notice_id, $allowed, true ) ) {
			return $this->error_response(
				$this->validation_error( __( 'Unknown notice identifier.', 'learnomy' ) )
			);
		}

		update_user_meta( get_current_user_id(), $notice_id, 1 );

		return $this->single_response(
			[
				'dismissed' => true,
				'notice_id' => $notice_id,
			]
		);
	}

	/**
	 * PATCH /admin/setup-state — persist wizard step 2 settings (catalog
	 * slug, currency, enrollment mode, use cases) and optionally mark setup
	 * complete. Replaces the learnomy_setup_save AJAX action.
	 */
	public function patch_setup_state( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) ( $request->get_json_params() ?? [] );

		$service = new Setup_Service();
		$result  = $service->save_settings( $body );

		return $this->single_response( $result );
	}

	/**
	 * POST /admin/setup-state/payment — persist the wizard step 3 payment
	 * choice. Replaces the learnomy_setup_save_payment AJAX action.
	 *
	 * Validation errors return a 422 with the error code so the wizard can
	 * surface the message inline. Per plan §12 R3 the secret key is never
	 * echoed back in the error envelope.
	 */
	public function save_payment_settings( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) ( $request->get_json_params() ?? [] );

		$service = new Setup_Service();
		$result  = $service->save_payment( $body );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->single_response( $result );
	}

	/**
	 * POST /admin/setup-state/sample — persist wizard step 2 settings and
	 * seed the demo dataset in one round-trip. Replaces the
	 * learnomy_setup_create_sample AJAX action.
	 */
	public function create_sample_data( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) ( $request->get_json_params() ?? [] );

		$service = new Setup_Service();
		$service->save_settings( $body );

		$result = $service->seed_demo( get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->single_response( $result, [], 201 );
	}

	/**
	 * POST /admin/setup-state/reset-demo — destructive reset that wipes the
	 * stored manifest and reseeds the dataset. Replaces the
	 * learnomy_setup_reset_demo AJAX action.
	 */
	public function reset_demo_data( WP_REST_Request $request ): WP_REST_Response {
		// Destructive operation — require an explicit `confirm: true` body
		// flag. Prior contract accepted an empty body and ran the wipe,
		// so a one-click XHR (or a stale tab retry) could lose every demo
		// row. Confirmation requirement narrows the foot-gun to actions
		// the caller deliberately opted into.
		$body = (array) ( $request->get_json_params() ?? array() );
		if ( true !== ( $body['confirm'] ?? false ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_confirm_required',
					/* translators: shown only if a client calls the reset endpoint without confirming; the UI always confirms first. */
					__( 'Resetting demo data needs confirmation. Please retry the action from the button.', 'learnomy' ),
					array( 'status' => 400 )
				)
			);
		}

		$service = new Setup_Service();
		$result  = $service->reset_demo( get_current_user_id() );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->single_response(
			[
				'reset'    => true,
				'manifest' => $result['manifest'],
				'stats'    => $result['stats'],
				'previews' => $result['previews'],
			]
		);
	}

	/**
	 * POST /admin/setup-state/cleanup-demo — remove every row created by the
	 * demo seeder. Replaces the learnomy_cleanup_demo AJAX action.
	 *
	 * Requires `confirm: true` in the body — same rationale as
	 * `reset_demo_data()` above. Accepts an optional `reassign_to` body
	 * field. When omitted the current user's ID is used as the reassignment
	 * target.
	 */
	public function cleanup_demo_data( WP_REST_Request $request ): WP_REST_Response {
		$body = (array) ( $request->get_json_params() ?? array() );
		if ( true !== ( $body['confirm'] ?? false ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_confirm_required',
					__( 'Cleanup demo data is destructive. Send {"confirm": true} in the body to proceed.', 'learnomy' ),
					array( 'status' => 400 )
				)
			);
		}
		$reassign_to = isset( $body['reassign_to'] ) ? absint( $body['reassign_to'] ) : 0;

		$service = new Setup_Service();
		$result  = $service->cleanup_demo( $reassign_to );

		return $this->single_response( $result );
	}

	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		$settings = get_option( 'learnomy_settings', [] );

		// Strip any keys that should not leave the server.
		unset( $settings['_internal'] );

		return $this->single_response( $this->sanitize_settings_output( $settings ) );
	}

	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) || empty( $body ) ) {
			return $this->error_response(
				$this->validation_error( __( 'No settings were provided.', 'learnomy' ) )
			);
		}

		// Filter to keys this endpoint exposes BEFORE handing off to the
		// canonical sanitizer — the wp-admin form covers 30+ keys but the
		// REST surface intentionally only allows the 8 in $allowed. Anything
		// else gets reported back as rejected.
		$allowed  = $this->allowed_settings_keys();
		$rejected = array();
		$raw      = array();
		foreach ( $body as $key => $value ) {
			$key = sanitize_key( $key );
			if ( ! in_array( $key, $allowed, true ) ) {
				$rejected[] = $key;
				continue;
			}
			$raw[ $key ] = $value;
		}

		// Single sanitize path — same callback the wp-admin Settings form
		// uses (Basecamp #9928023966). Routes the input through every clamp,
		// slug normalisation, and the slug-change rewrite-flush trigger;
		// also fires `sanitize_option_learnomy_settings` for third-party
		// hooks. Without this, the REST endpoint was a back door that wrote
		// raw values straight to the option.
		$admin = new \Learnomy\Admin\Admin();
		$clean = $admin->sanitize_settings( $raw );

		update_option( 'learnomy_settings', $clean );

		$response_data = $this->sanitize_settings_output( $clean );
		if ( ! empty( $rejected ) ) {
			$response_data['_rejected_keys'] = $rejected;
		}

		return $this->single_response( $response_data );
	}

	// -------------------------------------------------------------------------
	// Settings helpers
	// -------------------------------------------------------------------------

	private function allowed_settings_keys(): array {
		return [
			'base_slug',
			'currency',
			'default_platform_rate',
			'withdrawal_minimum',
			'withdrawal_methods',
			'enable_certificates',
			'enable_reviews',
			// enrollment_mode removed until the approval feature is built
			// (half-cooked audit F1) — a REST-writable value nothing reads is
			// a contract lie to API consumers too.
			// Fully-private academy toggle — settable via the app so a private
			// site behaves the same whether configured in wp-admin or over REST
			// (Zoho #40814). Boolean; sanitized by the shared checkbox path.
			'require_login_all_content',
			// Terms of Service page URL. Writable over REST for the same reason
			// as the private-academy toggle: the app config endpoint already
			// PUBLISHES `terms_url` under `legal`, so an owner configuring the
			// site from the app could read it but never set it. Sanitized by
			// the shared esc_url_raw branch in Admin::sanitize_settings().
			'terms_url',
		];
	}


	private function sanitize_settings_output( array $settings ): array {
		// Cast numeric settings so the API always returns the correct type.
		$float_keys = [ 'default_platform_rate', 'withdrawal_minimum' ];
		foreach ( $float_keys as $k ) {
			if ( isset( $settings[ $k ] ) ) {
				$settings[ $k ] = (float) $settings[ $k ];
			}
		}
		return $settings;
	}

	// -------------------------------------------------------------------------
	// Maintenance Tools (Settings → Developer & Data)
	// -------------------------------------------------------------------------

	/**
	 * Send a diagnostic test email to the currently signed-in admin.
	 *
	 * The recipient is always the current user, so this cannot be used to
	 * relay mail to arbitrary addresses. Verifies wp_mail() is reaching the
	 * inbox and that the configured sender name + sender email render in the
	 * From header.
	 */
	public function send_test_email( WP_REST_Request $request ): WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! $user || empty( $user->user_email ) ) {
			return $this->error_response(
				$this->validation_error( __( 'Your account has no email address on file.', 'learnomy' ) )
			);
		}

		$recipient = $user->user_email;
		$site_name = get_bloginfo( 'name' );

		/* translators: %s: site name */
		$subject = sprintf( __( '[%s] Learnomy test email', 'learnomy' ), $site_name );

		$body_lines = [
			sprintf(
				/* translators: 1: recipient display name, 2: site name */
				__( 'Hi %1$s, this is a test email from %2$s.', 'learnomy' ),
				$user->display_name ?: $user->user_login,
				$site_name
			),
			'',
			__( 'If you can read this, Learnomy can send mail through your site. The From header above shows the sender name and address it used.', 'learnomy' ),
			'',
			sprintf(
				/* translators: %s: site URL */
				__( 'Sent from %s', 'learnomy' ),
				home_url( '/' )
			),
		];

		$sent = wp_mail( $recipient, $subject, implode( "\n", $body_lines ) );

		if ( ! $sent ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not send the test email. Check the Email Log and your SMTP configuration.', 'learnomy' ) )
			);
		}

		return $this->single_response(
			[
				'sent'      => true,
				'recipient' => $recipient,
				/* translators: %s: email address the test was sent to */
				'message'   => sprintf( __( 'Test email sent to %s.', 'learnomy' ), $recipient ),
			]
		);
	}

	/**
	 * Force WordPress to rebuild its rewrite-rules cache. Use when custom URLs
	 * (courses, lessons, certificate verify) stop resolving after a slug or
	 * permastruct change.
	 */
	public function flush_rewrite_rules( WP_REST_Request $request ): WP_REST_Response {
		// Hard flush — pass `false` so .htaccess is not rewritten. WP regenerates
		// the rules in the database; that's all we need for pretty-permalink fixes.
		flush_rewrite_rules( false );

		return $this->single_response(
			[
				'flushed' => true,
				'message' => __( 'Rewrite rules flushed.', 'learnomy' ),
			]
		);
	}
}
