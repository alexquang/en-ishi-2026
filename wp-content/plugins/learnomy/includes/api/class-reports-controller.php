<?php
/**
 * Reports REST controller — light, report-driven moderation queue.
 *
 * A logged-in member can report a review, a lesson comment, or a course; the
 * report lands in `lrn_audit_log` (type='report') via AuditLog and surfaces in
 * the admin Reports queue. This is deliberately NOT a keyword/rule engine — it
 * is admin control unless a member reports (owner steer). Two surfaces:
 *
 *   POST /reports                       — member files a report (auth required).
 *   POST /admin/reports/{id}/resolve    — admin dismisses / acts (manage_options).
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\Models\AuditLog;
use Learnomy\Models\Review;
use Learnomy\Models\Course;

class Reports_Controller extends Base_Controller {

	/**
	 * Reportable object types. Each maps to a validator that confirms the target
	 * actually exists before a report is stored, so the queue never fills with
	 * dangling references to deleted rows.
	 */
	private const OBJECT_TYPES = [ 'review', 'comment', 'course' ];

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'reports';
	}

	public function register_routes(): void {
		// POST /reports — a member reports a review / comment / course.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'submit_report' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'object_type' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => self::OBJECT_TYPES,
					],
					'object_id'   => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					],
					'reason'      => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// POST /admin/reports/{id}/resolve — moderator dismisses or acts.
		register_rest_route(
			$this->namespace,
			'/admin/' . $this->rest_base . '/(?P<id>\d+)/resolve',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'resolve_report' ],
				'permission_callback' => [ $this, 'require_manage' ],
				'args'                => [
					'id'         => [
						'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0,
					],
					'resolution' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => [ 'dismiss', 'act' ],
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * POST /reports — file a moderation report.
	 *
	 * Rate-limited, dedupes to one report per user per object, and validates the
	 * reported object still exists. Fires `learnomy_report_submitted` so a
	 * notifier (or Pro) can react without this controller knowing about it.
	 */
	public function submit_report( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$rate_check = $this->check_rate_limit( $user_id, 'submit_report' );
		if ( is_wp_error( $rate_check ) ) {
			return $this->error_response( $rate_check );
		}

		$object_type = (string) $request->get_param( 'object_type' );
		$object_id   = absint( $request->get_param( 'object_id' ) );
		$reason      = sanitize_text_field( wp_unslash( (string) ( $request->get_param( 'reason' ) ?? '' ) ) );

		if ( ! $this->object_exists( $object_type, $object_id ) ) {
			return $this->error_response( $this->not_found( __( 'Reported item', 'learnomy' ) ) );
		}

		// One report per user per object — a member can't inflate the queue by
		// re-reporting. Answer 200 (idempotent) rather than an error so the UI
		// simply confirms "already reported" instead of surfacing a scary failure.
		if ( AuditLog::has_reported( $user_id, $object_type, $object_id ) ) {
			return $this->single_response(
				[
					'reported'         => true,
					'already_reported' => true,
				]
			);
		}

		// Cap the reason so a hostile client can't stuff the log; the UI collects
		// a short sentence.
		$reason    = mb_substr( $reason, 0, 500 );
		$report_id = AuditLog::add_report( $user_id, $object_type, $object_id, $reason );

		if ( ! $report_id ) {
			return $this->error_response(
				$this->validation_error( __( 'Your report could not be filed. Please try again.', 'learnomy' ) )
			);
		}

		/**
		 * Fires after a member files a moderation report.
		 *
		 * @param int    $report_id   The new audit_log row ID.
		 * @param string $object_type 'review' | 'comment' | 'course'.
		 * @param int    $object_id   Reported object ID.
		 * @param int    $user_id     Reporting member.
		 * @param string $reason      Reporter's reason (may be empty).
		 */
		do_action( 'learnomy_report_submitted', $report_id, $object_type, $object_id, $user_id, $reason );

		$response = $this->single_response(
			[
				'reported'  => true,
				'report_id' => $report_id,
			]
		);
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * POST /admin/reports/{id}/resolve — dismiss or act on a report.
	 */
	public function resolve_report( WP_REST_Request $request ): WP_REST_Response {
		$report_id  = absint( $request->get_param( 'id' ) );
		$resolution = (string) $request->get_param( 'resolution' );

		$report = AuditLog::find( $report_id );
		if ( ! $report || 'report' !== ( $report->type ?? '' ) ) {
			return $this->error_response( $this->not_found( __( 'Report', 'learnomy' ) ) );
		}

		if ( ! AuditLog::resolve_report( $report_id, $resolution, get_current_user_id() ) ) {
			return $this->error_response(
				$this->validation_error( __( 'The report could not be updated. Please refresh and try again.', 'learnomy' ) )
			);
		}

		$status = 'dismiss' === $resolution ? AuditLog::REPORT_DISMISSED : AuditLog::REPORT_ACTIONED;

		/**
		 * Fires after a moderator resolves a report.
		 *
		 * @param int    $report_id  Report row ID.
		 * @param string $status     'dismissed' | 'actioned'.
		 * @param int    $moderator  Resolving admin.
		 */
		do_action( 'learnomy_report_resolved', $report_id, $status, get_current_user_id() );

		return $this->single_response(
			[
				'id'     => $report_id,
				'status' => $status,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks + helpers
	// -------------------------------------------------------------------------

	/**
	 * Admin moderation gate — same pattern as the reviews queue: routes through
	 * Permission_Engine so the admin-only short-circuit applies uniformly.
	 */
	public function require_manage(): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( ! $this->check_permission( 'manage_settings' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * Does the reported object still exist? Keeps the queue free of dangling
	 * references to rows that were deleted between the page load and the report.
	 */
	private function object_exists( string $object_type, int $object_id ): bool {
		if ( $object_id <= 0 ) {
			return false;
		}

		switch ( $object_type ) {
			case 'review':
				return (bool) Review::find( $object_id );
			case 'course':
				return (bool) Course::find( $object_id );
			case 'comment':
				return (bool) get_comment( $object_id );
		}

		return false;
	}
}
