<?php
/**
 * REST route behind the shared "Community & Discussion" toggles.
 *
 * One route for every object type and every provider, because the section is
 * one control: a second endpoint per product is how the two halves drift apart
 * and start disagreeing about who may change what.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

use Learnomy\Services\Object_Integration_Registry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

class Object_Integrations_Controller extends Base_Controller {

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/object-integrations',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_state' ),
				'permission_callback' => array( $this, 'can_manage_object' ),
				'args'                => array(
					'type'     => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => Object_Integration_Registry::TYPES,
						'sanitize_callback' => 'sanitize_key',
					),
					'id'       => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'provider' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'enabled'  => array(
						'required' => true,
						'type'     => 'boolean',
					),
				),
			)
		);
	}

	/**
	 * May this user change integrations on this object?
	 *
	 * Delegates to Permission_Engine rather than re-deciding. A course uses the
	 * same `manage_course` check the course editor itself is gated on, so a
	 * provider toggle can never be reachable by someone who cannot edit the
	 * thing it is attached to. Cohorts and Learnomy Spaces are Pro constructs
	 * managed from admin screens, so they take the site-owner capability.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function can_manage_object( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$type = (string) $request->get_param( 'type' );
		$id   = (int) $request->get_param( 'id' );

		if ( 'course' === $type ) {
			return $this->check_permission( 'manage_course', $id )
				? true
				: $this->permission_error();
		}

		return current_user_can( 'manage_options' ) ? true : $this->permission_error();
	}

	/**
	 * Turn one provider on or off for one object.
	 *
	 * Responds with the state the PROVIDER reports afterwards, not the state
	 * that was asked for. A provider may decline - a draft course has nothing to
	 * attach a room to yet - and the UI should show what is true rather than
	 * what was requested.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_state( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$type     = (string) $request->get_param( 'type' );
		$id       = (int) $request->get_param( 'id' );
		$provider = (string) $request->get_param( 'provider' );
		$enabled  = (bool) $request->get_param( 'enabled' );

		$state = Object_Integration_Registry::apply( $type, $id, $provider, $enabled );

		if ( null === $state ) {
			return new WP_Error(
				'learnomy_unknown_integration',
				__( 'That integration is not available for this item.', 'learnomy' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'type'     => $type,
				'id'       => $id,
				'provider' => $provider,
				'enabled'  => $state,
			)
		);
	}
}
