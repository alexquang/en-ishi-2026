<?php
/**
 * Account controller — customer-facing /account/* REST routes.
 *
 * POST /learnomy/v1/account/billing/portal
 *   Body: { "subscription_id": <int> }   // optional; auto-picks latest active Stripe sub
 *   Response (success): { "data": { "url": "<hosted Stripe portal URL>" } }
 *   Response (failure): { "error": { "code", "message" } }
 *
 *   Creates a Stripe Customer Portal session for the buyer's customer
 *   record. The portal lets them update payment method, view Stripe-side
 *   invoice history, and cancel from Stripe's hosted UI. We never see
 *   card data; the response URL opens in a new tab on the client.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use Learnomy\Adapters\Adapter_Registry;
use Learnomy\Adapters\Stripe_Adapter;
use Learnomy\Models\Course;
use Learnomy\Models\Quiz;
use Learnomy\Models\QuizAttempt;
use Learnomy\Models\Subscription;
use WP_REST_Request;
use WP_REST_Response;

class Account_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'account';
	}

	public function register_routes(): void {
		// A learner's own attempt history. The web has had this since forever at
		// /account/quiz-results/; the API had no member-facing listing at all -
		// the only route that lists attempts is `/quizzes/{id}/attempts`, which
		// is instructor-scoped, so a student holding four results could not
		// retrieve them (Basecamp 10180524824). The app's history screen had
		// nowhere to read from, which is a rule-8 parity gap: the same learner
		// sees their results on the website and not in the app.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/quiz-attempts',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_own_quiz_attempts' ),
				// "The caller is the subject" - no ownership rule to get wrong,
				// because the user id is never taken from the request.
				'permission_callback' => array( $this, 'permission_logged_in' ),
				'args'                => array(
					'per_page' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'offset'   => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/billing/address',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_billing_address' ),
				'permission_callback' => array( $this, 'permission_logged_in' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/billing/portal',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_portal_session' ),
				'permission_callback' => array( $this, 'permission_logged_in' ),
				'args'                => array(
					'subscription_id' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'description'       => __( 'Optional subscription row ID. Falls back to the user\'s latest active Stripe subscription.', 'learnomy' ),
					),
				),
			)
		);

		// Instructor self-edit profile — writes the FREE lrn_instructors row the
		// public profile reads. Closes the other half of F4 (an instructor could
		// not update their bio/expertise after approval). Editable FREE fields
		// only; location / extra socials / verification stay Pro-written.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/instructor-profile',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_instructor_profile' ),
				'permission_callback' => array( $this, 'permission_instructor' ),
				'args'                => array(
					'headline'       => array(
						'type'     => 'string',
						'required' => false,
					),
					'bio'            => array(
						'type'     => 'string',
						'required' => false,
					),
					'expertise'      => array(
						'type'     => 'string',
						'required' => false,
					),
					'website_url'    => array(
						'type'     => 'string',
						'required' => false,
					),
					'twitter_handle' => array(
						'type'     => 'string',
						'required' => false,
					),
					'linkedin_url'   => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Permission: logged in AND holds the instructor role. Each instructor edits
	 * only their own profile (the handler always writes the current user's row),
	 * so no per-object ownership check is needed.
	 */
	public function permission_instructor(): bool {
		$user = wp_get_current_user();
		return $user instanceof \WP_User && $user->ID > 0
			&& ( in_array( 'lrn_instructor', (array) $user->roles, true ) || user_can( $user, 'manage_options' ) );
	}

	/**
	 * POST /account/instructor-profile — save the current instructor's profile.
	 *
	 * Sanitizes each editable field and upserts the lrn_instructors row. Returns
	 * the saved values so the client can confirm. The model whitelists columns,
	 * so unknown payload keys can never reach a stat / verification column.
	 */
	public function save_instructor_profile( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$fields = array();
		if ( null !== $request->get_param( 'headline' ) ) {
			$fields['headline'] = sanitize_text_field( (string) $request->get_param( 'headline' ) );
		}
		if ( null !== $request->get_param( 'bio' ) ) {
			$fields['bio'] = sanitize_textarea_field( (string) $request->get_param( 'bio' ) );
		}
		if ( null !== $request->get_param( 'expertise' ) ) {
			$fields['expertise'] = sanitize_text_field( (string) $request->get_param( 'expertise' ) );
		}
		if ( null !== $request->get_param( 'website_url' ) ) {
			$fields['website_url'] = esc_url_raw( (string) $request->get_param( 'website_url' ) );
		}
		if ( null !== $request->get_param( 'twitter_handle' ) ) {
			// Accept either a handle or a full URL; store the bare handle.
			$handle                   = ltrim( trim( (string) $request->get_param( 'twitter_handle' ) ), '@' );
			$handle                   = preg_replace( '#^https?://(www\.)?(twitter|x)\.com/#i', '', $handle );
			$fields['twitter_handle'] = sanitize_text_field( $handle );
		}
		if ( null !== $request->get_param( 'linkedin_url' ) ) {
			$fields['linkedin_url'] = esc_url_raw( (string) $request->get_param( 'linkedin_url' ) );
		}

		// Required custom fields first: refuse before writing anything, so a
		// rejected save does not leave half the profile updated.
		$field_errors = ( new \Learnomy\Services\Custom_Fields() )->validate( $request, 'account_instructor' );
		if ( ! empty( $field_errors ) ) {
			return $this->error_response(
				new \WP_Error( 'learnomy_required_field', implode( ' ', $field_errors ), array( 'status' => 400 ) )
			);
		}

		/**
		 * Reject the save before anything is written. Partner to
		 * `learnomy_account_fields` and `learnomy_account_fields_save`; see the
		 * registration route for why this exists separately from save.
		 *
		 * @param true|\WP_Error   $valid   Validity so far. Default true.
		 * @param \WP_REST_Request $request The submitted request.
		 * @param string           $context Surface: account_instructor.
		 */
		$lrn_field_valid = apply_filters( 'learnomy_account_fields_validate', true, $request, 'account_instructor' );
		if ( is_wp_error( $lrn_field_valid ) ) {
			$lrn_field_valid->add_data( array( 'status' => 400 ) );
			return $this->error_response( $lrn_field_valid );
		}

		\Learnomy\Models\Instructor::upsert( (int) $user_id, $fields );

		/**
		 * Persist extra fields submitted from the instructor profile screen.
		 *
		 * The profile itself lives in lrn_instructors; custom fields live in
		 * user meta, so this route has to fire the same save hook the account
		 * and registration routes do. Without it the screen would render inputs
		 * through learnomy_account_fields and quietly drop every value.
		 *
		 * @param int             $user_id The instructor.
		 * @param WP_REST_Request $request The submitted request.
		 */
		do_action( 'learnomy_account_fields_save', (int) $user_id, $request );

		$saved = \Learnomy\Models\Instructor::find_by_user( (int) $user_id );

		return $this->single_response(
			array(
				'headline'       => (string) ( $saved->headline ?? '' ),
				'bio'            => (string) ( $saved->bio ?? '' ),
				'expertise'      => (string) ( $saved->expertise ?? '' ),
				'website_url'    => (string) ( $saved->website_url ?? '' ),
				'twitter_handle' => (string) ( $saved->twitter_handle ?? '' ),
				'linkedin_url'   => (string) ( $saved->linkedin_url ?? '' ),
			)
		);
	}

	/**
	 * POST /account/billing/portal.
	 *
	 * Resolves the buyer's Stripe subscription (explicit ID if given, else the
	 * latest active Stripe sub on this user), asks the Stripe adapter to mint
	 * a portal session for the customer attached to it, and returns the hosted
	 * URL. The browser opens it in a new tab via the enqueued account-billing
	 * JS handler.
	 *
	 * Errors return the standard envelope ({ error: { code, message } }) — the
	 * JS handler surfaces `error.message` via lrnToast and re-enables the button.
	 */
	/**
	 * Save the member's own billing address.
	 *
	 * The write side of the account Billing page. Until this existed, the ONLY
	 * writer of `billing_*` anywhere in either plugin was a live checkout
	 * submission, so a member who mistyped their address could correct it only
	 * by buying something else (Basecamp 10171338907).
	 *
	 * Goes through Billing_Profile::save(), the same helper checkout uses, so
	 * the two surfaces cannot disagree about what is stored. save() writes only
	 * the keys it is given, so a partial post cannot blank the rest.
	 *
	 * Deliberately does NOT enforce missing_required(): a member is allowed to
	 * keep a half-filled address on file. Completeness is a condition of taking
	 * money, and checkout is where that is enforced.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function save_billing_address( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();

		$fields = array(
			'billing_first_name',
			'billing_last_name',
			'billing_company',
			'billing_country',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_phone',
			'billing_gst',
		);

		$data = array();
		foreach ( $fields as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$data[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		$profile = \Learnomy\Services\Billing_Profile::save( $user_id, $data );

		return $this->single_response(
			array(
				'billing' => $profile,
				'missing' => array_values( \Learnomy\Services\Billing_Profile::missing_required( $profile ) ),
			)
		);
	}

	public function create_portal_session( WP_REST_Request $request ): WP_REST_Response {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $this->error_response( $user_id );
		}

		$explicit_sub_id = (int) $request->get_param( 'subscription_id' );
		$subscription    = $explicit_sub_id > 0
			? $this->find_owned_subscription( $user_id, $explicit_sub_id )
			: $this->pick_latest_stripe_subscription( $user_id );

		if ( null === $subscription ) {
			return $this->error_response( $this->not_found( __( 'Stripe subscription', 'learnomy' ) ) );
		}

		if ( 'stripe' !== (string) ( $subscription->payment_provider ?? '' ) ) {
			return $this->error_response(
				$this->validation_error( __( 'The Customer Portal is only available for Stripe subscriptions.', 'learnomy' ) )
			);
		}

		$stripe_sub_id = (string) ( $subscription->provider_subscription_id ?? '' );
		if ( '' === $stripe_sub_id ) {
			return $this->error_response(
				$this->validation_error( __( 'This subscription is missing a Stripe identifier, so no portal session can be created.', 'learnomy' ) )
			);
		}

		$adapter = Adapter_Registry::get( 'stripe' );
		if ( ! $adapter instanceof Stripe_Adapter ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_stripe_unavailable',
					__( 'The Stripe adapter is not currently active.', 'learnomy' ),
					[ 'status' => 503 ]
				)
			);
		}

		$return_url = \Learnomy\route_url( 'account-billing' );
		$result     = $adapter->create_billing_portal_session( $stripe_sub_id, $return_url );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result );
		}

		return $this->single_response( [ 'url' => (string) $result ] );
	}

	/**
	 * Look up a subscription row by ID and confirm the calling user owns it.
	 * Returns null on miss or cross-user access attempt — the caller maps
	 * that to a 404 so we never leak whether the row exists for someone else.
	 */
	private function find_owned_subscription( int $user_id, int $sub_id ): ?object {
		$sub = Subscription::find( $sub_id );
		if ( null === $sub || (int) $sub->user_id !== $user_id ) {
			return null;
		}
		return $sub;
	}

	/**
	 * Pick the most-recent active Stripe subscription for the user. Active
	 * here matches Subscription::get_active_for_user (active|trialing|past_due).
	 * Past-due is intentionally included — the portal is the recommended
	 * recovery path for a failed payment.
	 */
	private function pick_latest_stripe_subscription( int $user_id ): ?object {
		$active = Subscription::get_active_for_user( $user_id );
		foreach ( $active as $sub ) {
			if ( 'stripe' === (string) ( $sub->payment_provider ?? '' ) && ! empty( $sub->provider_subscription_id ) ) {
				return $sub;
			}
		}
		return null;
	}

	/**
	 * GET /account/quiz-attempts — the caller's own attempt history.
	 *
	 * Reads `QuizAttempt::get_for_user()` / `count_for_user()`, which is
	 * literally what the website's own Quiz Results page reads. That is
	 * deliberate: two readers of "my attempts" would eventually disagree, and
	 * the app showing a different history from the site is the bug this route
	 * exists to prevent, not a new one to introduce.
	 *
	 * Each row carries its quiz and course title, batch-fetched, so the app can
	 * render a history list without N+1 follow-up calls. A row opens into the
	 * existing `GET /attempts/{id}` for the full review.
	 */
	public function list_own_quiz_attempts( WP_REST_Request $request ): WP_REST_Response {
		$user_id  = get_current_user_id();
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$offset   = max( 0, (int) $request->get_param( 'offset' ) );

		$total = QuizAttempt::count_for_user( $user_id );
		$rows  = QuizAttempt::get_for_user( $user_id, $per_page, $offset );

		// Batch the quiz + course lookups, same as the web view-data does.
		$quiz_ids   = array_values( array_unique( array_map( static fn( $r ) => (int) $r->quiz_id, $rows ) ) );
		$quiz_by_id = array();
		$course_ids = array();
		foreach ( $quiz_ids ? Quiz::find_in( $quiz_ids ) : array() as $quiz ) {
			$quiz_by_id[ (int) $quiz->id ] = $quiz;
			$course_id                     = (int) ( $quiz->course_id ?? 0 );
			if ( $course_id ) {
				$course_ids[ $course_id ] = true;
			}
		}
		$course_by_id = array();
		foreach ( $course_ids ? Course::find_in( array_keys( $course_ids ) ) : array() as $course ) {
			$course_by_id[ (int) $course->id ] = $course;
		}

		$items = array();
		foreach ( $rows as $row ) {
			$quiz   = $quiz_by_id[ (int) $row->quiz_id ] ?? null;
			$course = $quiz ? ( $course_by_id[ (int) ( $quiz->course_id ?? 0 ) ] ?? null ) : null;

			$item                 = QuizAttempt::to_typed_array( $row ) ?? array();
			$item['quiz_title']   = $quiz ? (string) $quiz->title : '';
			$item['quiz_slug']    = $quiz ? (string) $quiz->slug : '';
			$item['course_id']    = $quiz ? (int) ( $quiz->course_id ?? 0 ) : 0;
			$item['course_title'] = $course ? (string) $course->title : '';

			$items[] = $item;
		}

		return $this->paginated_response( $items, $total, $offset );
	}
}
