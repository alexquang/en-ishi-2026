<?php
/**
 * Batch controller — execute multiple API sub-requests in a single round trip.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;

class Batch_Controller extends Base_Controller {

	/** Hard cap on sub-requests per batch call. */
	const MAX_REQUESTS = 25;

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'batch';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'execute' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'requests' => [
						'required'    => true,
						'type'        => 'array',
						'description' => __( 'Array of sub-requests to execute. Maximum 25.', 'learnomy' ),
						'items'       => [
							'type'       => 'object',
							'required'   => [ 'method', 'path' ],
							'properties' => [
								'method'  => [
									'type' => 'string',
									'enum' => [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ],
								],
								'path'    => [
									'type'              => 'string',
									'sanitize_callback' => 'sanitize_text_field',
								],
								'body'    => [ 'type' => 'object' ],
								'headers' => [ 'type' => 'object' ],
							],
						],
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Route handlers
	// -------------------------------------------------------------------------

	public function execute( WP_REST_Request $request ): WP_REST_Response {
		$sub_requests = $request->get_param( 'requests' );

		if ( ! is_array( $sub_requests ) || empty( $sub_requests ) ) {
			return $this->error_response(
				$this->validation_error( __( 'The requests array cannot be empty.', 'learnomy' ) )
			);
		}

		if ( count( $sub_requests ) > self::MAX_REQUESTS ) {
			return $this->error_response(
				$this->validation_error(
					sprintf(
						/* translators: %d: maximum allowed sub-requests */
						__( 'A batch may contain at most %d requests.', 'learnomy' ),
						self::MAX_REQUESTS
					)
				)
			);
		}

		$responses = [];
		$server    = rest_get_server();

		foreach ( $sub_requests as $index => $item ) {
			$responses[] = $this->dispatch_sub_request( $server, $item, $index );
		}

		return new WP_REST_Response( [ 'responses' => $responses ] );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Dispatches a single sub-request and returns a normalised response object.
	 *
	 * @param \WP_REST_Server $server The REST server instance.
	 * @param mixed           $item   Raw sub-request definition.
	 * @param int             $index  Position in the batch (for error reporting).
	 */
	private function dispatch_sub_request( \WP_REST_Server $server, mixed $item, int $index ): array {
		// Validate the bare minimum before dispatching.
		if ( ! is_array( $item ) || empty( $item['method'] ) || empty( $item['path'] ) ) {
			return [
				'status' => 400,
				'body'   => [
					'error' => [
						'code'    => 'learnomy_batch_invalid_item',
						'message' => sprintf(
							/* translators: %d: sub-request position */
							__( 'Sub-request at index %d is missing method or path.', 'learnomy' ),
							$index
						),
					],
				],
			];
		}

		$method  = strtoupper( sanitize_text_field( $item['method'] ) );
		$path    = sanitize_text_field( $item['path'] );
		$body    = isset( $item['body'] ) && is_array( $item['body'] ) ? $item['body'] : [];
		$headers = isset( $item['headers'] ) && is_array( $item['headers'] ) ? $item['headers'] : [];

		// Build a well-formed REST request.
		$sub = new WP_REST_Request( $method, $this->resolve_path( $path ) );

		// Forward the current user's auth context so permission checks work.
		foreach ( $headers as $header_name => $header_value ) {
			$sub->set_header( sanitize_text_field( $header_name ), sanitize_text_field( $header_value ) );
		}

		if ( ! empty( $body ) ) {
			$sub->set_body_params( $body );
		}

		// Parse query string from the path if present.
		$query_string = wp_parse_url( $path, PHP_URL_QUERY );
		if ( $query_string ) {
			$query_params = [];
			parse_str( $query_string, $query_params );
			// Forward query params RAW, exactly like body params above — each
			// target endpoint sanitizes its own args via its registered
			// sanitize_callback. A blanket sanitize_text_field() here was an
			// asymmetric second pass that could corrupt values another
			// endpoint's own sanitizer is built to accept.
			$sub->set_query_params( $query_params );
		}

		$result = rest_do_request( $sub );
		$data   = $server->response_to_data( $result, false );

		return [
			'status' => $result->get_status(),
			'body'   => $data,
		];
	}

	/**
	 * Strips a leading namespace prefix if the caller included it, so both
	 * "/learnomy/v1/courses" and "/courses" work in batch sub-requests.
	 */
	private function resolve_path( string $path ): string {
		// The route must never carry a query string. The caller parses query
		// params from the original path and sets them via set_query_params();
		// a route like "/courses?per_page=2" otherwise matches NO route (404).
		// Strip it here so "/courses" and "/courses?per_page=2" resolve the same.
		$query_pos = strpos( $path, '?' );
		if ( false !== $query_pos ) {
			$path = substr( $path, 0, $query_pos );
		}

		// Strip the namespace if the caller included it so paths like
		// "/learnomy/v1/courses/1" and "/courses/1" are treated identically.
		$prefix = '/' . $this->namespace;
		if ( str_starts_with( $path, $prefix ) ) {
			return $path;
		}

		// Make sure it's rooted under our namespace.
		if ( ! str_starts_with( $path, '/' ) ) {
			$path = '/' . $path;
		}

		return $prefix . $path;
	}
}
