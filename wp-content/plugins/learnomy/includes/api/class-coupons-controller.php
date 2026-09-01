<?php
/**
 * Coupons controller — public validation and admin CRUD.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use Learnomy\Models\Coupon;
use Learnomy\Services\Coupon_Service;

class Coupons_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'coupons';
	}

	public function register_routes(): void {
		// POST /coupons/validate — open to everyone; price context is optional.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/validate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'validate' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'code'      => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Coupon code to check.', 'learnomy' ),
					],
					'course_id' => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'plan_id'   => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'price'     => [
						'type'        => 'number',
						'minimum'     => 0,
						'description' => __( 'Original price to compute the discount against.', 'learnomy' ),
					],
				],
			]
		);

		// Admin coupon collection.
		register_rest_route(
			$this->namespace,
			'/admin/coupons',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list_coupons' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => $this->get_collection_params(),
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_coupon' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => $this->coupon_args( true ),
				],
			]
		);

		// Admin coupon by ID.
		register_rest_route(
			$this->namespace,
			'/admin/coupons/(?P<id>\d+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_coupon' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => array_merge(
						[ 'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ] ],
						$this->coupon_args( false )
					),
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete_coupon' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => [
						'id' => [ 'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0 ],
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

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

	public function validate( WP_REST_Request $request ): WP_REST_Response {
		$code      = strtoupper( $request->get_param( 'code' ) );
		$course_id = $request->get_param( 'course_id' ) ? absint( $request->get_param( 'course_id' ) ) : null;
		$plan_id   = $request->get_param( 'plan_id' ) ? absint( $request->get_param( 'plan_id' ) ) : null;
		$user_id   = get_current_user_id() ?: null;
		$price     = $request->get_param( 'price' ) !== null ? (float) $request->get_param( 'price' ) : null;

		$service = new Coupon_Service();
		$result  = $service->validate( $code, $course_id, $plan_id, $user_id, $price );

		if ( ! $result['valid'] ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_coupon_invalid',
					$result['reason'],
					[ 'status' => 422 ]
				)
			);
		}

		$discount = 0.0;
		if ( null !== $price && $price > 0 ) {
			$discount = $service->calculate_discount( $result['coupon'], $price );
		}

		return $this->single_response(
			[
				'valid'       => true,
				'code'        => $code,
				'type'        => $result['coupon']->type,
				'value'       => (float) $result['coupon']->value,
				'discount'    => $discount,
				'final_price' => null !== $price ? max( 0.0, round( $price - $discount, 2 ) ) : null,
				'applies_to'  => $result['coupon']->applies_to,
				// Nested coupon object — frontend (checkout.js, single-course.php)
				// reads `data.coupon.type` to compute the discount client-side.
				// Keep the top-level fields for SDK consumers.
				'coupon'      => $this->format_coupon( $result['coupon'] ),
			]
		);
	}

	public function list_coupons( WP_REST_Request $request ): WP_REST_Response {
		$pagination = $this->get_pagination( $request );
		$limit      = (int) $pagination['limit'];
		$offset     = (int) $pagination['offset'];

		// Pull a single page from the DB instead of loading all coupons and
		// slicing in PHP — stays fast as the coupon table grows.
		$total   = Coupon::count();
		$coupons = Coupon::where( [], 'created_at', 'DESC', $limit, $offset );

		return $this->paginated_response(
			array_map( [ $this, 'format_coupon' ], $coupons ),
			$total,
			$offset
		);
	}

	public function create_coupon( WP_REST_Request $request ): WP_REST_Response {
		$fields = $this->extract_coupon_fields( $request );

		if ( empty( $fields['code'] ) ) {
			return $this->error_response(
				$this->validation_error( __( 'A coupon code is required.', 'learnomy' ) )
			);
		}

		// Reject duplicate codes immediately so the error message is clear.
		$existing = Coupon::find_by_code( $fields['code'] );
		if ( $existing ) {
			return $this->error_response(
				$this->validation_error( __( 'A coupon with that code already exists.', 'learnomy' ) )
			);
		}

		$coupon_id = Coupon::create( $fields );

		if ( ! $coupon_id ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_coupon_save_failed', __( 'Could not save the coupon.', 'learnomy' ), [ 'status' => 500 ] )
			);
		}

		$coupon = Coupon::find( $coupon_id );

		return new WP_REST_Response( [ 'data' => $this->format_coupon( $coupon ) ], 201 );
	}

	public function update_coupon( WP_REST_Request $request ): WP_REST_Response {
		$coupon_id = absint( $request->get_param( 'id' ) );
		$coupon    = Coupon::find( $coupon_id );

		if ( ! $coupon ) {
			return $this->error_response( $this->not_found( 'Coupon' ) );
		}

		$fields = $this->extract_coupon_fields( $request );

		// Don't allow code changes that collide with another coupon.
		if ( ! empty( $fields['code'] ) ) {
			$existing = Coupon::find_by_code( $fields['code'] );
			if ( $existing && (int) $existing->id !== $coupon_id ) {
				return $this->error_response(
					$this->validation_error( __( 'That coupon code is already in use.', 'learnomy' ) )
				);
			}
		}

		Coupon::update( $coupon_id, $fields );

		return $this->single_response( $this->format_coupon( Coupon::find( $coupon_id ) ) );
	}

	public function delete_coupon( WP_REST_Request $request ): WP_REST_Response {
		$coupon_id = absint( $request->get_param( 'id' ) );
		$coupon    = Coupon::find( $coupon_id );

		if ( ! $coupon ) {
			return $this->error_response( $this->not_found( 'Coupon' ) );
		}

		Coupon::delete( $coupon_id );

		return $this->single_response(
			[
				'deleted' => true,
				'id'      => $coupon_id,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function extract_coupon_fields( WP_REST_Request $request ): array {
		// Walk get_json_params() with array_key_exists() so explicit nulls
		// pass through (used to clear nullable columns like usage_limit and
		// min_purchase). Falling back to get_param's null-vs-absent ambiguity
		// would silently swallow those clears.
		$body   = (array) ( $request->get_json_params() ?? [] );
		$fields = [];

		// 'applies_to' is handled below through canonical_scope(), not copied raw.
		$str_params = [ 'type', 'status', 'starts_at', 'expires_at' ];
		foreach ( $str_params as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$fields[ $key ] = null === $body[ $key ] ? null : sanitize_text_field( (string) $body[ $key ] );
			}
		}

		// Normalise the scope through the one canonicaliser rather than storing
		// whatever the form sent. Three spellings of this field already drifted
		// apart once ('course'/'membership'/'both' written, 'all' stored and
		// labelled), and the broadest-sounding value silently behaved as the
		// narrowest. Writing it canonical means the drift cannot restart here.
		if ( array_key_exists( 'applies_to', $body ) ) {
			$fields['applies_to'] = \Learnomy\Services\Coupon_Service::canonical_scope(
				(string) $body['applies_to']
			);
		}

		if ( array_key_exists( 'code', $body ) ) {
			$fields['code'] = strtoupper( sanitize_text_field( (string) $body['code'] ) );
		}

		if ( array_key_exists( 'value', $body ) ) {
			$fields['value'] = (float) $body['value'];
		}

		// usage_limit is nullable in the schema (null = unlimited).
		if ( array_key_exists( 'usage_limit', $body ) ) {
			$raw                   = $body['usage_limit'];
			$fields['usage_limit'] = ( null === $raw || '' === $raw ) ? null : absint( $raw );
		}

		// per_user_limit is NOT NULL with default 1. An empty / null value
		// from the form means "no per-user cap" → omit the key so the DB
		// default (1) wins. Sending null here trips the column constraint.
		if ( array_key_exists( 'per_user_limit', $body ) ) {
			$raw = $body['per_user_limit'];
			if ( null !== $raw && '' !== $raw ) {
				$fields['per_user_limit'] = absint( $raw );
			}
		}

		if ( array_key_exists( 'min_purchase', $body ) ) {
			$raw                    = $body['min_purchase'];
			$fields['min_purchase'] = ( null === $raw || '' === $raw ) ? null : (float) $raw;
		}

		// Items restriction — accept either `items` (array) or legacy
		// `course_ids` (csv string). Empty / null clears the column.
		if ( array_key_exists( 'items', $body ) ) {
			$items                = is_array( $body['items'] ) ? array_filter( array_map( 'absint', $body['items'] ) ) : [];
			$fields['items_json'] = empty( $items ) ? null : wp_json_encode( array_values( $items ) );
		} elseif ( array_key_exists( 'course_ids', $body ) ) {
			$raw = (string) ( $body['course_ids'] ?? '' );
			if ( '' === trim( $raw ) ) {
				$fields['items_json'] = null;
			} else {
				$ids                  = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
				$fields['items_json'] = empty( $ids ) ? null : wp_json_encode( array_values( $ids ) );
			}
		}

		return $fields;
	}

	private function format_coupon( object $coupon ): array {
		return [
			'id'             => (int) $coupon->id,
			'code'           => $coupon->code,
			'type'           => $coupon->type,
			'value'          => (float) $coupon->value,
			'applies_to'     => $coupon->applies_to,
			'usage_limit'    => $coupon->usage_limit !== null ? (int) $coupon->usage_limit : null,
			'per_user_limit' => $coupon->per_user_limit !== null ? (int) $coupon->per_user_limit : null,
			'times_used'     => (int) $coupon->times_used,
			'status'         => $coupon->status,
			'starts_at'      => $coupon->starts_at ?? null,
			'expires_at'     => $coupon->expires_at ?? null,
			'created_at'     => $coupon->created_at,
		];
	}

	private function coupon_args( bool $required ): array {
		return [
			'code'           => [
				'required'          => $required,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'type'           => [
				'required' => $required,
				'type'     => 'string',
				'enum'     => [ 'percent', 'fixed' ],
			],
			'value'          => [
				'required' => $required,
				'type'     => 'number',
				'minimum'  => 0,
			],
			'applies_to'     => [
				'type' => 'string',
				'enum' => [ 'course', 'membership', 'both' ],
			],
			// Nullable: null = unlimited. The string union accepts empty
			// strings from <input type="number"> fields that legitimately
			// represent "no limit"; the controller normalises '' → null.
			// Without these unions REST validation would reject both null
			// and '' with rest_invalid_type.
			'usage_limit'    => [
				'type' => [ 'integer', 'null', 'string' ],
			],
			'per_user_limit' => [
				'type' => [ 'integer', 'null', 'string' ],
			],
			// Minimum purchase threshold required for the coupon to apply.
			// Null / '' clears the floor so the coupon works on any cart size.
			'min_purchase'   => [
				'type' => [ 'number', 'null', 'string' ],
			],
			'status'         => [
				'type' => 'string',
				'enum' => [ 'active', 'inactive' ],
			],
			'starts_at'      => [ 'type' => 'string' ],
			'expires_at'     => [ 'type' => 'string' ],
			// Two shapes accepted for the items restriction: SDK clients send
			// `items` as an array of IDs; the legacy admin form posts
			// `course_ids` as a comma-separated string. Both end up in the
			// items_json column via extract_coupon_fields().
			'items'          => [
				'type'  => 'array',
				'items' => [ 'type' => 'integer' ],
			],
			'course_ids'     => [
				'type' => 'string',
			],
		];
	}
}
