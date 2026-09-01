<?php
/**
 * Devices controller — register and unregister push notification tokens.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use Learnomy\Models\Device;

class Devices_Controller extends Base_Controller {

	/** Supported push notification platforms. */
	const PLATFORMS = [ 'ios', 'android', 'web' ];

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'devices';
	}

	public function register_routes(): void {
		// POST /devices — register a device token for the current user.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'register' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				// device_token/platform are NOT marked `required` on purpose: WP
				// validates required args BEFORE the permission_callback, so an
				// anon POST would get a 400 "Missing parameter(s)" (leaking the
				// schema) instead of a 401. Presence is checked in register()
				// after auth. (Same pattern the sections controller uses.)
				'args'                => [
					'device_token' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'The push notification token issued by the platform (APNs, FCM, or browser).', 'learnomy' ),
					],
					'platform'     => [
						'type'              => 'string',
						'enum'              => self::PLATFORMS,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// DELETE /devices/{id} — remove a device the caller owns.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'unregister' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'id' => [
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
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

	public function register( WP_REST_Request $request ): WP_REST_Response {
		$user_id      = get_current_user_id();
		$device_token = $request->get_param( 'device_token' );
		$platform     = $request->get_param( 'platform' );

		// Presence check moved here (post-auth) so anon callers get 401, not a
		// schema-leaking 400 — see the args note in register_routes().
		if ( '' === trim( (string) $device_token ) ) {
			return $this->error_response(
				$this->validation_error( __( 'A device token is required.', 'learnomy' ) )
			);
		}

		if ( ! in_array( $platform, self::PLATFORMS, true ) ) {
			return $this->error_response(
				$this->validation_error(
					sprintf(
						/* translators: %s: comma-separated list of valid platform names */
						__( 'Platform must be one of: %s.', 'learnomy' ),
						implode( ', ', self::PLATFORMS )
					)
				)
			);
		}

		// Device::register() upserts on device_token so a re-install doesn't
		// create a duplicate row.
		$device_id = Device::register( $user_id, $device_token, $platform );

		if ( ! $device_id ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_device_register_failed',
					__( 'Could not register your device. Please try again.', 'learnomy' ),
					[ 'status' => 500 ]
				)
			);
		}

		$device = Device::find( $device_id );

		return $this->single_response( $this->format_device( $device ), [], 201 );
	}

	public function unregister( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = get_current_user_id();
		$device_id = absint( $request->get_param( 'id' ) );
		$device    = Device::find( $device_id );

		if ( ! $device ) {
			return $this->error_response( $this->not_found( 'Device' ) );
		}

		// Prevent one user from removing another user's device token.
		if ( (int) $device->user_id !== $user_id ) {
			return $this->error_response( $this->permission_error() );
		}

		Device::delete( $device_id );

		return $this->single_response(
			[
				'unregistered' => true,
				'id'           => $device_id,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Formatter
	// -------------------------------------------------------------------------

	private function format_device( object $device ): array {
		return [
			'id'         => (int) $device->id,
			'platform'   => $device->platform,
			// Return a partial token so the client can confirm which device was registered
			// without exposing the full push token in the response body.
			'token_hint' => substr( $device->device_token, 0, 8 ) . '…',
			'created_at' => $device->created_at,
		];
	}
}
