<?php
/**
 * Certificates REST controller — issue, verify, revoke certificates and manage templates.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\Models\Certificate;
use Learnomy\Models\CertificateTemplate;
use Learnomy\Models\Enrollment;
use Learnomy\Services\Certificate_Service;
use Learnomy\Services\Certificate_Renderer;

class Certificates_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'certificates';
	}

	public function register_routes(): void {
		// My certificates (authenticated student).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_my_certificates' ],
				'permission_callback' => [ $this, 'require_login' ],
				'args'                => $this->get_collection_params(),
			]
		);

		// Public verification — no authentication required.
		// The UUID is unguessable, which is the access control here.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<uuid>[a-f0-9\-]{36})',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'verify_certificate' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'uuid' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Issue certificate when a student completes a course.
		register_rest_route(
			$this->namespace,
			'/courses/(?P<course_id>[\d]+)/' . $this->rest_base,
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'issue_certificate' ],
				'permission_callback' => [ $this, 'require_login' ],
				'args'                => [
					'course_id'   => [
						'type'     => 'integer',
						'required' => true,
					],
					'template_id' => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// Revoke a certificate (admin only).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<uuid>[a-f0-9\-]{36})/revoke',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'revoke_certificate' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => [
					'uuid'   => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'reason' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Public download — PDF (default), HTML, or printable certificate.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<uuid>[a-f0-9\-]{36})/download',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'download_certificate' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'uuid'   => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'format' => [
						'type'              => 'string',
						'enum'              => [ 'pdf', 'html' ],
						'default'           => 'pdf',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Templates: list and create.
		register_rest_route(
			$this->namespace,
			'/certificate-templates',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_templates' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_template' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => $this->template_args(),
				],
			]
		);

		// Template update.
		register_rest_route(
			$this->namespace,
			'/certificate-templates/(?P<id>[\d]+)',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_template' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => array_merge(
					[
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
					$this->template_args( false )
				),
			]
		);

		// Template preview — renders with sample data for the certificate designer.
		// Auth: a server-signed `token` query param, NOT cookie+nonce. The
		// preview opens via `window.open()` in a new tab; some browsers /
		// cookie configurations don't carry the auth cookie on the new-tab
		// GET, surfacing as `learnomy_unauthorized` 401 even when the
		// admin is clearly logged in (bug card "Error Appears on Certificate
		// Preview PDF"). The signed token is generated by the cert-designer
		// JS and validated below; no session lookup needed.
		register_rest_route(
			$this->namespace,
			'/certificate-templates/(?P<id>[\d]+)/preview',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'preview_template' ],
				'permission_callback' => [ $this, 'verify_preview_token' ],
				'args'                => [
					'id'    => [
						'type'     => 'integer',
						'required' => true,
					],
					'token' => [
						'type'     => 'string',
						'required' => true,
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	public function get_my_certificates( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		// Active only — the learner's own certificate list must not present a
		// revoked/expired cert as earned; total matches the filtered list so
		// pagination stays correct (Basecamp 10109147988).
		$pagination   = $this->get_pagination( $request );
		$certificates = Certificate::get_active_for_user( $user_id, $pagination['limit'], $pagination['offset'] );
		$total        = Certificate::count_active_for_user( $user_id );

		// Enrich each row with its course title so the app can label the
		// certificate by course ("PMP Exam Prep") instead of a bare
		// "Certificate". Read from the certificate's own snapshot first, so a
		// retired course still labels the credential its holder earned; only
		// pre-snapshot rows fall back to a live lookup, and those are batched
		// (no per-row query) — a learner with dozens of certificates stays one
		// query.
		$titles = Certificate_Service::display_titles( $certificates );
		foreach ( $certificates as $certificate ) {
			$title                              = $titles[ (int) $certificate->id ] ?? [];
			$certificate->course_title          = $title['title'] ?? '';
			$certificate->course_title_recorded = (bool) ( $title['recorded'] ?? false );
		}

		return $this->paginated_response( $certificates, $total, (int) $pagination['offset'] );
	}

	public function verify_certificate( WP_REST_Request $request ): WP_REST_Response {
		$uuid = sanitize_text_field( wp_unslash( $request->get_param( 'uuid' ) ) );

		$service     = new Certificate_Service();
		$certificate = $service->verify( $uuid );

		if ( ! $certificate ) {
			return $this->error_response( $this->not_found( 'Certificate' ) );
		}

		// Tamper check. This endpoint reported `status: active` unchanged after the
		// signature column was overwritten with a well-formed 64-byte value: it
		// never called the crypto at all, so the only real verification lived in
		// templates/certificate-verify.php's HTML path (Basecamp 10134139810). An
		// employer checking a credential over the API was trusting a bare DB
		// lookup. Both surfaces now read the same verdict from one method.
		$signature_state = $service->signature_state( $certificate );
		$is_tampered     = 'invalid' === $signature_state;

		// What the credential asserts, read from the certificate's own snapshot
		// rather than resolved live. Deleting or renaming the course used to
		// empty or rewrite this payload (Basecamp 10148480574).
		$facts = Certificate_Service::asserted_facts( $certificate );

		// Strip sensitive fields from public verification responses.
		$public_data = [
			'uuid'                => $certificate->uuid,
			'status'              => $certificate->status,
			// The composite verdict, which is the question an employer is actually
			// asking. `status` stays the stored column so the two never disagree
			// about what the database says — `is_valid` is what it MEANS.
			'is_valid'            => 'active' === $certificate->status && ! $is_tampered,
			'is_tampered'         => $is_tampered,
			'signature_state'     => $signature_state,
			'user_id'             => (int) $certificate->user_id,
			'course_id'           => (int) $certificate->course_id,
			// Recipient, course and issuer by NAME. The journey spec asks for
			// these and the HTML verify page already shows them; without them the
			// API answer is unusable to a human ("course_id 1987" proves nothing
			// to an employer). No new exposure: the holder shares this URL
			// deliberately, and every value is already public on the HTML page for
			// the same UUID. The score is NOT published here — it is on the
			// document only when the design prints it, and the holder did not
			// necessarily choose to share a grade.
			'recipient_name'      => $facts['recipient_name'],
			'course_title'        => $facts['course_title'],
			// Where the title came from, so an integrator can tell "this is the
			// title as awarded" from "the course record is gone". `course_title`
			// deliberately stays EMPTY in that last case rather than carrying the
			// human sentence the certificate prints: a machine consumer must never
			// store "a course of study at Acme" as if it were a course name.
			'course_title_source' => $facts['course_title_source'],
			'issuer_name'         => $facts['issuer_name'],
			'issued_at'           => $certificate->issued_at,
			'expires_at'          => $certificate->expires_at,
			'verification_count'  => (int) $certificate->verification_count,
			'last_verified_at'    => $certificate->last_verified_at,
		];

		if ( 'revoked' === $certificate->status ) {
			$public_data['revoked_at']     = $certificate->revoked_at;
			$public_data['revoked_reason'] = $certificate->revoked_reason ?? null;
		}

		return $this->single_response( $public_data );
	}

	public function issue_certificate( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$course_id   = absint( $request->get_param( 'course_id' ) );
		$template_id = absint( $request->get_param( 'template_id' ) ) ?: null;

		// Only students who have COMPLETED the course can receive a
		// certificate. `is_enrolled()` returns true for both active and
		// completed enrollments; we need to gate on completion specifically
		// so a student can't self-issue a signed cert mid-course.
		$enrollment = Enrollment::find_for_user_course( $user_id, $course_id );
		if ( ! $enrollment ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_not_enrolled',
					__( 'You must be enrolled in this course to receive a certificate.', 'learnomy' ),
					[ 'status' => 403 ]
				)
			);
		}
		if ( 'completed' !== ( $enrollment->status ?? '' ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_course_not_completed',
					__( 'You must complete this course before a certificate can be issued.', 'learnomy' ),
					[ 'status' => 403 ]
				)
			);
		}

		// One certificate per user per course — check before issuing.
		$existing = Certificate::where(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
			]
		);
		foreach ( $existing as $cert ) {
			if ( 'active' === $cert->status ) {
				return $this->error_response(
					$this->validation_error( __( 'You already have an active certificate for this course.', 'learnomy' ) )
				);
			}
		}

		try {
			$service        = new Certificate_Service();
			$certificate_id = $service->issue( $user_id, $course_id, $template_id );
		} catch ( \Exception $e ) {
			return $this->error_response( $this->validation_error( $e->getMessage() ) );
		}

		$certificate = Certificate::find( $certificate_id );
		$response    = $this->single_response( Certificate::to_typed_array( $certificate ) );
		$response->set_status( 201 );

		return $response;
	}

	public function revoke_certificate( WP_REST_Request $request ): WP_REST_Response {
		$uuid = sanitize_text_field( wp_unslash( $request->get_param( 'uuid' ) ) );

		$certificate = Certificate::find_by_uuid( $uuid );
		if ( ! $certificate ) {
			return $this->error_response( $this->not_found( 'Certificate' ) );
		}

		if ( 'revoked' === $certificate->status ) {
			return $this->error_response(
				$this->validation_error( __( 'This certificate has already been revoked.', 'learnomy' ) )
			);
		}

		$reason  = sanitize_text_field( wp_unslash( $request->get_param( 'reason' ) ?? '' ) );
		$service = new Certificate_Service();

		if ( ! $service->revoke( (int) $certificate->id, $reason ) ) {
			return $this->error_response( $this->validation_error( __( 'Could not revoke the certificate. Please try again.', 'learnomy' ) ) );
		}

		return $this->single_response( Certificate::to_typed_array( Certificate::find( (int) $certificate->id ) ) );
	}

	/**
	 * Serve a certificate as PDF or printable HTML.
	 *
	 * Public endpoint — access control is the unguessable UUID.
	 *
	 * Supports two output formats via the `format` query parameter:
	 * - `pdf`  (default) — Dompdf-generated PDF. Falls back to HTML if Dompdf is missing.
	 * - `html` — Standalone HTML page with a "Print / Save as PDF" button.
	 */
	public function download_certificate( WP_REST_Request $request ): void {
		$uuid   = sanitize_text_field( wp_unslash( $request->get_param( 'uuid' ) ) );
		$format = $request->get_param( 'format' ) ?? 'pdf';

		$certificate = Certificate::find_by_uuid( $uuid );
		if ( ! $certificate || 'active' !== $certificate->status ) {
			status_header( 404 );
			echo esc_html__( 'Certificate not found or has been revoked.', 'learnomy' );
			exit;
		}

		// Resolve the template attached to this certificate, if any.
		$template = null;
		if ( ! empty( $certificate->template_id ) ) {
			$template = CertificateTemplate::find( (int) $certificate->template_id );
		}

		$renderer = new Certificate_Renderer();

		// PDF output — fall back to HTML when Dompdf is not installed.
		if ( 'pdf' === $format ) {
			$pdf = $renderer->render_pdf( $certificate, $template );

			if ( '' !== $pdf ) {
				header( 'Content-Type: application/pdf' );
				header( 'Content-Disposition: inline; filename="certificate-' . esc_attr( $uuid ) . '.pdf"' );
				header( 'Content-Length: ' . strlen( $pdf ) );
				echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF
				exit;
			}

			// Dompdf not available — serve HTML instead so the student still gets something.
			$format = 'html';
		}

		// HTML output — wrapped with print button for a nice download experience.
		$html = $renderer->render_html( $certificate, $template );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo $this->wrap_html_for_print( $html, $uuid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside wrapper
		exit;
	}

	/**
	 * Render a template preview with sample data for the certificate designer.
	 *
	 * Returns a standalone HTML page that can be opened in a new tab.
	 */
	public function preview_template( WP_REST_Request $request ): void {
		$template = CertificateTemplate::find( (int) $request->get_param( 'id' ) );
		if ( ! $template ) {
			status_header( 404 );
			echo esc_html__( 'Template not found.', 'learnomy' );
			exit;
		}

		$renderer = new Certificate_Renderer();
		$html     = $renderer->render_preview( $template );

		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by renderer
		exit;
	}

	public function get_templates( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->get_pagination( $request );
		$templates  = CertificateTemplate::where( [], 'title', 'ASC', $pagination['limit'], $pagination['offset'] );

		return $this->paginated_response( $templates, CertificateTemplate::count(), (int) $pagination['offset'] );
	}

	public function create_template( WP_REST_Request $request ): WP_REST_Response {
		$data = $this->extract_template_fields( $request );
		if ( empty( $data['title'] ) ) {
			return $this->error_response( $this->validation_error( __( 'A template title is required.', 'learnomy' ) ) );
		}

		$data['status']        = $data['status'] ?? 'active';
		$data['triggers_json'] = $this->build_meta_json( $request, null );

		$template_id = CertificateTemplate::create( $data );
		if ( ! $template_id ) {
			return $this->error_response( $this->validation_error( __( 'Could not create the template. Please try again.', 'learnomy' ) ) );
		}

		// Return a freshly-minted preview token alongside the template so
		// the cert designer can preview without waiting for a page reload.
		$payload                  = CertificateTemplate::to_typed_array( CertificateTemplate::find( $template_id ) ) ?? [];
		$payload['preview_token'] = self::mint_preview_token( $template_id );

		$response = $this->single_response( $payload );
		$response->set_status( 201 );

		return $response;
	}

	public function update_template( WP_REST_Request $request ): WP_REST_Response {
		$template = CertificateTemplate::find( (int) $request->get_param( 'id' ) );
		if ( ! $template ) {
			return $this->error_response( $this->not_found( 'Certificate template' ) );
		}

		$data      = $this->extract_template_fields( $request );
		$meta_json = $this->build_meta_json( $request, $template );
		if ( null !== $meta_json ) {
			$data['triggers_json'] = $meta_json;
		}
		if ( empty( $data ) ) {
			return $this->error_response( $this->validation_error( __( 'No valid fields were provided for update.', 'learnomy' ) ) );
		}

		CertificateTemplate::update( (int) $template->id, $data );

		$payload                  = CertificateTemplate::to_typed_array( CertificateTemplate::find( (int) $template->id ) ) ?? [];
		$payload['preview_token'] = self::mint_preview_token( (int) $template->id );

		return $this->single_response( $payload );
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function require_login(): bool|WP_Error {
		$result = $this->require_auth();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return true;
	}

	public function is_admin(): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'manage_settings' is an admin-only cap; Permission_Engine also
		// short-circuits on manage_options so WP admins always pass.
		if ( ! $this->check_permission( 'manage_settings' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * Validate a signed preview token. The token is generated by
	 * `mint_preview_token()` when the certificate designer JS is localized
	 * and embedded in the preview URL as `?token=…`. Format:
	 *
	 *     Base64( "<expiry_unix>:<hex_hmac_sha256>" )
	 *
	 * The HMAC is taken over `<template_id>:<expiry_unix>` keyed with
	 * `wp_salt( 'auth' )` so an attacker can't forge tokens without the
	 * site's auth key. Tokens are valid for 24 hours from issue. This
	 * deliberately bypasses cookie auth so the new-tab preview works
	 * even when the auth cookie isn't carried on `window.open()`.
	 */
	public function verify_preview_token( WP_REST_Request $request ): bool|WP_Error {
		$template_id = (int) $request->get_param( 'id' );
		$token       = (string) $request->get_param( 'token' );

		if ( $template_id <= 0 || '' === $token ) {
			return new WP_Error(
				'learnomy_invalid_preview_token',
				__( 'Invalid preview token.', 'learnomy' ),
				[ 'status' => 403 ]
			);
		}

		$decoded = base64_decode( $token, true );
		if ( false === $decoded || ! str_contains( $decoded, ':' ) ) {
			return new WP_Error(
				'learnomy_invalid_preview_token',
				__( 'Invalid preview token.', 'learnomy' ),
				[ 'status' => 403 ]
			);
		}

		[ $expiry, $signature ] = explode( ':', $decoded, 2 );
		$expiry                 = (int) $expiry;

		if ( $expiry < time() ) {
			return new WP_Error(
				'learnomy_expired_preview_token',
				__( 'Preview token has expired. Reopen the designer to generate a new one.', 'learnomy' ),
				[ 'status' => 403 ]
			);
		}

		$expected = hash_hmac( 'sha256', $template_id . ':' . $expiry, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, $signature ) ) {
			return new WP_Error(
				'learnomy_invalid_preview_token',
				__( 'Invalid preview token.', 'learnomy' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Mint a signed preview token for the cert-designer JS to embed in the
	 * preview URL. Valid for 24 hours.
	 */
	public static function mint_preview_token( int $template_id ): string {
		$expiry    = time() + DAY_IN_SECONDS;
		$signature = hash_hmac( 'sha256', $template_id . ':' . $expiry, wp_salt( 'auth' ) );
		return base64_encode( $expiry . ':' . $signature );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function extract_template_fields( WP_REST_Request $request ): array {
		$data = [];

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$data['title'] = sanitize_text_field( wp_unslash( $title ) );
		}

		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			$data['description'] = sanitize_text_field( wp_unslash( $description ) );
		}

		// Note: template_html and background_url are NOT real columns in
		// lrn_certificate_templates (schema has design_json, orientation, etc.).
		// Accepting them here would silently no-op at the Model update. Dropped.

		$expiration_days = $request->get_param( 'expiration_days' );
		if ( null !== $expiration_days ) {
			$val                     = absint( $expiration_days );
			$data['expiration_days'] = $val > 0 ? $val : null;
		}

		$design_json = $request->get_param( 'design_json' );
		if ( null !== $design_json ) {
			// Accept a JSON string or an associative array from the request body.
			if ( is_array( $design_json ) ) {
				$data['design_json'] = wp_json_encode( $design_json );
			} else {
				$data['design_json'] = sanitize_text_field( wp_unslash( $design_json ) );
			}
		}

		$orientation = $request->get_param( 'orientation' );
		if ( null !== $orientation && in_array( $orientation, [ 'landscape', 'portrait' ], true ) ) {
			$data['orientation'] = $orientation;
		}

		$status = $request->get_param( 'status' );
		if ( null !== $status && in_array( $status, [ 'active', 'inactive' ], true ) ) {
			$data['status'] = $status;
		}

		return $data;
	}

	/**
	 * Build the encoded library-meta value (stored in `triggers_json`) for a
	 * create or update, merging the request's availability/kind/instructor
	 * fields over a sensible base.
	 *
	 * On create the base is the governance default (a new admin template is
	 * "Admins only" — private until the owner grants it). On update the base is
	 * the template's existing meta, so a curated template keeps its design_key
	 * and source even when only availability changes.
	 *
	 * @return string|null Encoded meta, or null on update when nothing changed.
	 */
	private function build_meta_json( WP_REST_Request $request, ?object $existing ): ?string {
		if ( $existing ) {
			$meta = CertificateTemplate::meta( $existing );
		} else {
			$meta                 = CertificateTemplate::meta( (object) [] );
			$meta['availability'] = CertificateTemplate::AVAILABILITY_ADMINS;
		}

		$touched = false;

		$availability = $request->get_param( 'availability' );
		if ( null !== $availability && in_array(
			$availability,
			[
				CertificateTemplate::AVAILABILITY_ALL,
				CertificateTemplate::AVAILABILITY_ADMINS,
				CertificateTemplate::AVAILABILITY_SPECIFIC,
			],
			true
		) ) {
			$meta['availability'] = $availability;
			$touched              = true;
		}

		$instructors = $request->get_param( 'instructors' );
		if ( null !== $instructors ) {
			$meta['instructors'] = array_values( array_unique( array_map( 'intval', (array) $instructors ) ) );
			$touched             = true;
		}

		$kind = $request->get_param( 'kind' );
		if ( null !== $kind && in_array( $kind, [ 'course_completion', 'quiz_completion' ], true ) ) {
			$meta['kind'] = $kind;
			$touched      = true;
		}

		// On create we always stamp meta so the governance default (Admins only)
		// is applied even if the client sent no availability field.
		if ( ! $existing ) {
			return CertificateTemplate::encode_meta( $meta );
		}

		return $touched ? CertificateTemplate::encode_meta( $meta ) : null;
	}

	/**
	 * Wrap a rendered certificate HTML in a presentable page with print and
	 * download buttons. Dompdf-generated PDF is preferred, but this gives
	 * a usable fallback when the library is missing.
	 *
	 * @param string $inner_html Certificate HTML from the renderer.
	 * @param string $uuid       Certificate UUID for the PDF download link.
	 * @return string Full HTML document.
	 */
	private function wrap_html_for_print( string $inner_html, string $uuid ): string {
		$pdf_available = Certificate_Renderer::dompdf_available();
		$pdf_url       = esc_url( rest_url( 'learnomy/v1/certificates/' . $uuid . '/download?format=pdf' ) );

		$download_btn = '';
		if ( $pdf_available ) {
			$download_btn = '<a href="' . $pdf_url . '" style="'
				. 'display:inline-block;font-family:-apple-system,BlinkMacSystemFont,sans-serif;'
				. 'font-size:0.9rem;padding:0.6rem 1.5rem;border:1px solid #6c5ce7;'
				. 'background:#fff;color:#6c5ce7;border-radius:6px;cursor:pointer;'
				. 'text-decoration:none;margin-left:8px;" target="_blank">'
				. esc_html__( 'Download PDF', 'learnomy' ) . '</a>';
		}

		return '<!DOCTYPE html><html lang="' . esc_attr( get_locale() ) . '"><head>'
			. '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . esc_html__( 'Certificate', 'learnomy' ) . '</title>'
			. '<style>'
			. 'html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }'
			. 'body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; background: #f5f5f0; '
			. 'display: flex; flex-direction: column; align-items: center; min-height: 100vh; padding: 2rem; margin: 0; }'
			. '.cert-wrapper { max-width: 100%; overflow: auto; }'
			. '.print-actions { text-align: center; margin-top: 1.5rem; }'
			. '.print-actions button, .print-actions a { vertical-align: middle; }'
			. '.print-actions button { font-family: -apple-system, BlinkMacSystemFont, sans-serif; '
			. 'font-size: 0.9rem; padding: 0.6rem 1.5rem; border: 1px solid #6c5ce7; '
			. 'background: #6c5ce7; color: #fff; border-radius: 6px; cursor: pointer; }'
			. '.print-actions button:hover { background: #5a4bd1; }'
			. '@media print { .print-actions { display: none; } body { background: none; padding: 0; } }'
			. '</style></head><body>'
			. '<div class="cert-wrapper">' . $inner_html . '</div>'
			. '<div class="print-actions">'
			. '<button type="button" class="cert-print-btn">' . esc_html__( 'Print / Save as PDF', 'learnomy' ) . '</button>'
			. $download_btn
			. '</div>'
			. '<script>document.querySelector(".cert-print-btn").addEventListener("click",function(){window.print();});</script>'
			. '</body></html>';
	}

	private function template_args( bool $title_required = true ): array {
		return [
			'title'           => [
				'type'              => 'string',
				'required'          => $title_required,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'description'     => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'design_json'     => [
				'type'        => [ 'string', 'object' ],
				'description' => __( 'Visual designer layout as JSON.', 'learnomy' ),
			],
			'orientation'     => [
				'type' => 'string',
				'enum' => [ 'landscape', 'portrait' ],
			],
			'expiration_days' => [
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Days until certificate expires. Leave empty for no expiration.', 'learnomy' ),
			],
			'status'          => [
				'type' => 'string',
				'enum' => [ 'active', 'inactive' ],
			],
		];
	}
}
