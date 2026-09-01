<?php
/**
 * Migration (LMS import) admin controller — backs the import wizard.
 *
 * Routes (all gated on manage_options):
 *   GET  /learnomy/v1/admin/migration/sources            — available sources + entities + counts
 *   POST /learnomy/v1/admin/migration/preview            — dry-run (counts + warnings) for a selection
 *   POST /learnomy/v1/admin/migration/run                — queue a batched import, returns a run id
 *   GET  /learnomy/v1/admin/migration/runs               — recent runs
 *   GET  /learnomy/v1/admin/migration/runs/{id}          — one run's progress (polled)
 *   POST /learnomy/v1/admin/migration/runs/{id}/undo     — undo a run
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use Learnomy\Migration\Import_Service;
use Learnomy\Migration\Migration_Run;
use Learnomy\Migration\Source_Registry;

/**
 * REST controller for the LMS migration wizard.
 */
class Migration_Controller extends Base_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->rest_base = 'admin/migration';
	}

	/**
	 * Permission callback — require manage_options.
	 *
	 * @return true|\WP_Error
	 */
	public function is_admin(): bool|\WP_Error {
		return $this->require_cap( 'manage_options' );
	}

	/**
	 * Register the wizard routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$admin = array( $this, 'is_admin' );

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sources',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_sources' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => $admin,
				'args'                => $this->run_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => $admin,
				'args'                => $this->run_args(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/runs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_runs' ),
				'permission_callback' => $admin,
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/runs/(?P<id>[A-Za-z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_run' ),
				'permission_callback' => $admin,
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/runs/(?P<id>[A-Za-z0-9\-]+)/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'undo_run' ),
				'permission_callback' => $admin,
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Shared args for preview + run.
	 *
	 * @return array
	 */
	private function run_args(): array {
		return array(
			'source'                  => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'entities'                => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => array( 'type' => 'string' ),
			),
			'question_type_fallbacks' => array(
				'type'    => 'object',
				'default' => array(),
			),
		);
	}

	/**
	 * GET sources — every registered source with availability + (when available)
	 * its entities, per-entity counts, and taxonomies, for the wizard picker.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_sources( WP_REST_Request $request ): WP_REST_Response {
		$out = array();
		foreach ( Source_Registry::all() as $source ) {
			$available = $source->is_available();
			$row       = array(
				'slug'       => $source->slug(),
				'label'      => $source->label(),
				'available'  => $available,
				'entities'   => array(),
				'counts'     => array(),
				'taxonomies' => array(),
			);
			if ( $available ) {
				$row['entities']   = $source->supported_entities();
				$row['taxonomies'] = $source->supported_taxonomies();
				foreach ( $row['entities'] as $entity ) {
					$row['counts'][ $entity ] = $source->count( $entity );
				}
			}
			$out[] = $row;
		}
		return new WP_REST_Response( array( 'data' => $out ), 200 );
	}

	/**
	 * POST preview — dry-run for a selection: counts + downgrade/skip warnings.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function preview( WP_REST_Request $request ): WP_REST_Response {
		$source = $this->resolve_source( $request );
		if ( $source instanceof WP_REST_Response ) {
			return $source;
		}
		$spec    = $this->spec_from_request( $request );
		$service = new Import_Service( $source, $spec );
		return new WP_REST_Response( array( 'data' => $service->preview() ), 200 );
	}

	/**
	 * POST run — queue a batched import; returns the run summary (id + totals).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function start( WP_REST_Request $request ): WP_REST_Response {
		$source = $this->resolve_source( $request );
		if ( $source instanceof WP_REST_Response ) {
			return $source;
		}
		$spec = $this->spec_from_request( $request );
		$run  = Import_Service::start_async( $source, $spec );
		return new WP_REST_Response( array( 'data' => $run->summary() ), 202 );
	}

	/**
	 * GET runs — recent run summaries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_runs( WP_REST_Request $request ): WP_REST_Response {
		return new WP_REST_Response( array( 'data' => Migration_Run::recent( 20 ) ), 200 );
	}

	/**
	 * GET runs/{id} — one run's progress (polled by the wizard).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_run( WP_REST_Request $request ): WP_REST_Response {
		$run = Migration_Run::load( (string) $request['id'] );
		if ( ! $run ) {
			return new WP_REST_Response( array( 'error' => array( 'message' => __( 'Run not found.', 'learnomy' ) ) ), 404 );
		}
		return new WP_REST_Response( array( 'data' => $run->summary() ), 200 );
	}

	/**
	 * POST runs/{id}/undo — delete everything a run created.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function undo_run( WP_REST_Request $request ): WP_REST_Response {
		$result = Import_Service::undo_run( (string) $request['id'] );
		if ( 0 === $result['deleted'] && ! $result['found'] ) {
			return new WP_REST_Response( array( 'error' => array( 'message' => __( 'Run not found.', 'learnomy' ) ) ), 404 );
		}
		return new WP_REST_Response( array( 'data' => $result ), 200 );
	}

	/**
	 * Resolve + validate the requested source, or an error response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \Learnomy\Migration\Migration_Source|WP_REST_Response
	 */
	private function resolve_source( WP_REST_Request $request ) {
		$slug   = (string) $request['source'];
		$source = Source_Registry::get( $slug );
		if ( ! $source ) {
			return new WP_REST_Response( array( 'error' => array( 'message' => sprintf( /* translators: %s: source slug */ __( 'Unknown source: %s', 'learnomy' ), $slug ) ) ), 400 );
		}
		if ( ! $source->is_available() ) {
			return new WP_REST_Response( array( 'error' => array( 'message' => sprintf( /* translators: %s: source label */ __( '%s is not available on this site.', 'learnomy' ), $source->label() ) ) ), 400 );
		}
		return $source;
	}

	/**
	 * Build a run spec from the request (shared defaults via Import_Service).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function spec_from_request( WP_REST_Request $request ): array {
		$overrides = array();
		$entities  = (array) $request['entities'];
		if ( ! empty( $entities ) ) {
			$overrides['entities'] = array_map( 'sanitize_key', $entities );
		}
		$fallbacks = (array) $request['question_type_fallbacks'];
		if ( ! empty( $fallbacks ) ) {
			$overrides['question_type_fallbacks'] = array_map( 'sanitize_key', $fallbacks );
		}
		return Import_Service::build_spec( (string) $request['source'], $overrides );
	}
}
