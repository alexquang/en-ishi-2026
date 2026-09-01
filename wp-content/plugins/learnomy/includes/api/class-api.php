<?php
/**
 * API bootstrap — registers all REST controllers.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

class Api {

	private array $controllers = [];

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		// Stream `text/csv` responses raw instead of letting WP_REST_Server
		// JSON-encode the body (which wrapped every CSV export in quotes +
		// escaped newlines, so Excel/Sheets dumped the whole file into one
		// cell). Fixes all CSV export endpoints at once — including any built
		// on Csv_Formatter — while keeping the REST middleware (auth, hooks)
		// intact, unlike the echo+exit workaround. Basecamp #9958460514.
		add_filter( 'rest_pre_serve_request', [ $this, 'stream_csv_responses' ], 10, 3 );
	}

	/**
	 * Short-circuit JSON serialization for `text/csv` REST responses, emitting
	 * the raw CSV string with the response's own headers.
	 *
	 * @param bool              $served  Whether the request has already been served.
	 * @param \WP_HTTP_Response $result The response object.
	 * @param \WP_REST_Request  $request The request object.
	 * @return bool True when we stream the CSV ourselves (stops the default JSON serve).
	 */
	public function stream_csv_responses( $served, $result, $request ): bool {
		if ( $served || ! $result instanceof \WP_REST_Response ) {
			return $served;
		}

		$content_type = '';
		foreach ( $result->get_headers() as $name => $value ) {
			if ( 'content-type' === strtolower( (string) $name ) ) {
				$content_type = (string) $value;
				break;
			}
		}

		if ( false === stripos( $content_type, 'text/csv' ) ) {
			return $served;
		}

		$data = $result->get_data();
		if ( ! is_string( $data ) ) {
			return $served; // Not a raw CSV body — let WP handle it normally.
		}

		if ( ! headers_sent() ) {
			http_response_code( $result->get_status() );
			foreach ( $result->get_headers() as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}

		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSV stream, headers force text/csv download.
		return true;
	}

	public function register_routes(): void {
		$dir = LEARNOMY_DIR . 'includes/api/';

		$classes = [
			'courses'            => 'Courses_Controller',
			'sections'           => 'Sections_Controller',
			'lessons'            => 'Lessons_Controller',
			'topics'             => 'Topics_Controller',
			'enrollments'        => 'Enrollments_Controller',
			'progress'           => 'Progress_Controller',
			'quizzes'            => 'Quizzes_Controller',
			'questions'          => 'Questions_Controller',
			'certificates'       => 'Certificates_Controller',
			'reviews'            => 'Reviews_Controller',
			'reports'            => 'Reports_Controller',
			'blocks'             => 'Blocks_Controller',
			'categories'         => 'Categories_Controller',
			'auth'               => 'Auth_Controller',
			'membership'         => 'Membership_Controller',
			'earnings'           => 'Earnings_Controller',
			'coupons'            => 'Coupons_Controller',
			'webhooks'           => 'Webhooks_Controller',
			'devices'            => 'Devices_Controller',
			'batch'              => 'Batch_Controller',
			'admin'              => 'Admin_Controller',
			'comments'           => 'Comments_Controller',
			'notifications'      => 'Notifications_Controller',
			'checkout'           => 'Checkout_Controller',
			'payments-admin'     => 'Payments_Admin_Controller',
			'webhook-events'     => 'Webhook_Events_Controller',
			'email-log'          => 'Email_Log_Controller',
			'account'            => 'Account_Controller',
			'media'              => 'Media_Controller',
			'broadcasts'         => 'Broadcasts_Controller',
			'migration'          => 'Migration_Controller',

			// Public per-member credentials (completed + teaching) for external
			// consumers (BuddyNext portfolio, the app).
			'member-credentials' => 'Member_Credentials_Controller',

			// The mobile app's pre-login bootstrap (flags + branding + legal +
			// the app_enabled license gate). See docs/mobile/MOBILE-API-CONTRACT.md.
			'app-config'         => 'App_Config_Controller',

			// Backs the shared "Community & Discussion" toggles on a course,
			// cohort or Learnomy Space. ONE route for every object type and
			// every provider - a second endpoint per product is how the two
			// halves drift and start disagreeing about who may change what.
			'object-integrations' => 'Object_Integrations_Controller',
		];

		require_once $dir . 'class-base-controller.php';

		$classes = apply_filters( 'learnomy_api_controllers', $classes );

		foreach ( $classes as $key => $class ) {
			$file = $dir . 'class-' . str_replace( '_', '-', strtolower( $key ) ) . '-controller.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				$fqn        = __NAMESPACE__ . '\\' . $class;
				$controller = new $fqn();
				$controller->register_routes();
				$this->controllers[ $key ] = $controller;
			}
		}
	}

	public function get_controller( string $key ): ?Base_Controller {
		return $this->controllers[ $key ] ?? null;
	}
}
