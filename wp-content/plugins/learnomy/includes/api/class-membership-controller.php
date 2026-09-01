<?php
/**
 * Membership controller — plans catalog, subscriptions, cancel and reactivate.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use Learnomy\Models\AuditLog;
use Learnomy\Models\MembershipPlan;
use Learnomy\Models\MembershipPlanCourse;
use Learnomy\Models\Plan_Provider;
use Learnomy\Models\Subscription;
use Learnomy\Models\Transaction;
use Learnomy\Permissions\Permission_Engine;
use Learnomy\Services\Subscription_Service;

class Membership_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'membership';
	}

	public function register_routes(): void {
		// GET /membership-plans — publicly visible catalog.
		register_rest_route(
			$this->namespace,
			'/membership-plans',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_plans' ],
				'permission_callback' => '__return_true',
			]
		);

		// GET /membership-plans/{id} — single plan with course list.
		register_rest_route(
			$this->namespace,
			'/membership-plans/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_plan' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'id' => [
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
					],
				],
			]
		);

		// POST /memberships/subscribe — start a new subscription.
		register_rest_route(
			$this->namespace,
			'/memberships/subscribe',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'subscribe' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'plan_id'         => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'payment_method'  => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Payment method identifier (e.g. stripe, paypal, manual).', 'learnomy' ),
					],
					'provider_sub_id' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'External subscription ID from the payment provider.', 'learnomy' ),
					],
				],
			]
		);

		// GET /memberships/me — caller's active memberships.
		register_rest_route(
			$this->namespace,
			'/memberships/me',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'my_memberships' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
			]
		);

		// POST /memberships/me/cancel — schedule cancellation at period end,
		// optionally capturing the buyer's exit-survey reason + retention-
		// offer choice for the admin churn dashboard (PR-D).
		register_rest_route(
			$this->namespace,
			'/memberships/me/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'cancel' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'subscription_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'reason'          => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Exit-survey reason slug.', 'learnomy' ),
					],
					'feedback_text'   => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
						'description'       => __( 'Optional free-text follow-up to the radio reason.', 'learnomy' ),
					],
					'accepted_offer'  => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Retention offer accepted before confirming the cancel, if any.', 'learnomy' ),
					],
				],
			]
		);

		// GET /memberships/me/cancel-options?subscription_id=N — returns the
		// reason list + filtered retention offers for the cancel modal.
		register_rest_route(
			$this->namespace,
			'/memberships/me/cancel-options',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'cancel_options' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'subscription_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// POST /memberships/me/reactivate — resume a cancelled subscription.
		register_rest_route(
			$this->namespace,
			'/memberships/me/reactivate',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'reactivate' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'plan_id'        => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'payment_method' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// POST /memberships/me/resume — undo a pending period-end cancel.
		register_rest_route(
			$this->namespace,
			'/memberships/me/resume',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'resume' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
				'args'                => [
					'subscription_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// GET /memberships/me/invoices — payment history for the current user.
		register_rest_route(
			$this->namespace,
			'/memberships/me/invoices',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'my_invoices' ],
				'permission_callback' => [ $this, 'permission_logged_in' ],
			]
		);

		// POST /membership-plans — admin-only create.
		register_rest_route(
			$this->namespace,
			'/membership-plans',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_plan' ],
				'permission_callback' => [ $this, 'require_manage' ],
				'args'                => $this->plan_write_args(),
			]
		);

		// PUT /membership-plans/{id} — admin-only update.
		register_rest_route(
			$this->namespace,
			'/membership-plans/(?P<id>\d+)',
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_plan' ],
				'permission_callback' => [ $this, 'require_manage' ],
				'args'                => array_merge(
					[
						'id' => [
							'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
						],
					],
					$this->plan_write_args( false )
				),
			]
		);

		// DELETE /membership-plans/{id} — admin-only archive.
		register_rest_route(
			$this->namespace,
			'/membership-plans/(?P<id>\d+)',
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'delete_plan' ],
				'permission_callback' => [ $this, 'require_manage' ],
				'args'                => [
					'id' => [
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
					],
				],
			]
		);

		// PATCH /membership-plans/{id}/status — single-column status flip.
		// Distinct from the broader PUT update so the inline badge toggle has
		// a focused, audit-friendly endpoint.
		register_rest_route(
			$this->namespace,
			'/membership-plans/(?P<id>\d+)/status',
			[
				'methods'             => 'PATCH',
				'callback'            => [ $this, 'update_plan_status' ],
				'permission_callback' => [ $this, 'require_manage' ],
				'args'                => [
					'id'     => [
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
					],
					'status' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => [ 'active', 'inactive' ],
					],
				],
			]
		);

		// POST /admin/memberships/{id}/cancel — admin cancels ANY subscription
		// immediately. Distinct from /memberships/me/cancel which is user-self.
		register_rest_route(
			$this->namespace,
			'/admin/memberships/(?P<id>\d+)/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'admin_cancel_subscription' ],
				'permission_callback' => [ $this, 'require_manage' ],
				'args'                => [
					'id'            => [
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
					],
					'at_period_end' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);

		// POST /admin/memberships/{id}/resume — reactivate a paused / past-due
		// subscription (or clear a pending period-end cancellation).
		register_rest_route(
			$this->namespace,
			'/admin/memberships/(?P<id>\d+)/resume',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'admin_resume_subscription' ],
				'permission_callback' => [ $this, 'require_manage' ],
				'args'                => [
					'id' => [
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
					],
				],
			]
		);

		// POST /admin/memberships/{id}/extend — bump the period_end on a sub.
		register_rest_route(
			$this->namespace,
			'/admin/memberships/(?P<id>\d+)/extend',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'admin_extend_subscription' ],
				'permission_callback' => [ $this, 'require_manage' ],
				'args'                => [
					'id'         => [
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
					],
					'period_end' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'New current_period_end as YYYY-MM-DD HH:MM:SS or any strtotime-parseable form. Must be in the future.', 'learnomy' ),
					],
				],
			]
		);

		// GET /admin/memberships/export — CSV stream for the Subscriptions page.
		// Filters mirror the page so admins can export "exactly what they see".
		register_rest_route(
			$this->namespace,
			'/admin/memberships/export',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'export_subscriptions_csv' ],
				'permission_callback' => [ $this, 'require_manage' ],
				'args'                => [
					'status'  => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					],
					'plan_id' => [
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		// GET /admin/membership-plans/export — CSV stream of the plans catalog
		// with current active-subscriber counts (doubles as a "what sells" snapshot).
		register_rest_route(
			$this->namespace,
			'/admin/membership-plans/export',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'export_plans_csv' ],
				'permission_callback' => [ $this, 'require_manage' ],
			]
		);

		// POST /admin/membership-plans/{id}/provider-mapping — write or clear a
		// single (provider, mode) mapping. Used by the smoke test to verify the
		// persistence path independent of the plan-edit UI form.
		register_rest_route(
			$this->namespace,
			'/admin/membership-plans/(?P<id>\d+)/provider-mapping',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'upsert_provider_mapping' ],
				'permission_callback' => [ $this, 'require_manage' ],
				'args'                => [
					'id'               => [
						'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
					],
					'provider'         => [
						'required' => true,
						'type'     => 'string',
						'enum'     => [ 'stripe', 'paypal' ],
					],
					'mode'             => [
						'required' => true,
						'type'     => 'string',
						'enum'     => [ 'test', 'live', 'sandbox' ],
					],
					'provider_plan_id' => [
						'type'              => [ 'string', 'null' ],
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'The Stripe price ID or PayPal plan ID. Empty string or null removes the mapping.', 'learnomy' ),
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function require_manage(): bool|\WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'manage_settings' is admin-only and covers membership plan management;
		// no finer-grained 'manage-plans' cap exists in Capabilities.
		// The original code used 'manage-plans' which has no registered WP cap,
		// causing Permission_Engine to resolve to 'learnomy_manage-plans' (a
		// non-existent cap) — effectively making the filter the sole gate.
		// Using manage_settings is both correct and routes through the engine.
		if ( ! $this->check_permission( 'manage_settings' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * Shared args schema for plan create + update. On update every field
	 * becomes optional so callers can PATCH-style partial edits through PUT.
	 */
	private function plan_write_args( bool $required = true ): array {
		return [
			'title'                  => [
				'required'          => $required,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'slug'                   => [
				// sanitize_title() is multi-arg — using it as a REST
				// sanitize_callback crashes when the slug is empty (the
				// request object becomes the fallback_title arg). Sanitize
				// internally instead.
				'type' => 'string',
			],
			'description'            => [
				'type'              => 'string',
				'sanitize_callback' => 'wp_kses_post',
			],
			'price'                  => [
				'type'    => 'number',
				'minimum' => 0,
			],
			'currency'               => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'billing_interval'       => [
				'type' => 'string',
				'enum' => [ 'daily', 'weekly', 'monthly', 'yearly' ],
			],
			'interval_count'         => [
				'type'    => 'integer',
				'minimum' => 1,
			],
			'trial_days'             => [
				'type'    => 'integer',
				'minimum' => 0,
			],
			'course_limit'           => [
				// Nullable so admins can switch a plan back to "no course
				// limit" by sending null. REST is otherwise strict about
				// types and rejects nulls outright.
				'type' => [ 'integer', 'null' ],
			],
			'price_monthly'          => [
				// Nullable for the dual-to-single pricing-mode transition —
				// the JS posts null to clear the column when switching back.
				'type' => [ 'number', 'null' ],
			],
			'price_yearly'           => [
				'type' => [ 'number', 'null' ],
			],
			'thumbnail_url'          => [
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			],
			'features_json'          => [
				'type' => 'string',
			],
			'is_featured'            => [
				'type' => 'boolean',
			],
			'status'                 => [
				'type' => 'string',
				'enum' => [ 'active', 'inactive' ],
			],
			'course_ids'             => [
				'type'  => 'array',
				'items' => [ 'type' => 'integer' ],
			],
			// Payment provider mapping — stored in lrn_membership_plan_providers,
			// not in lrn_membership_plans. Nullable so the admin can clear a value.
			'stripe_test_price_id'   => [
				'type'              => [ 'string', 'null' ],
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Stripe Test Mode Price ID (e.g. price_test_xxx). Stored in lrn_membership_plan_providers.', 'learnomy' ),
			],
			'stripe_live_price_id'   => [
				'type'              => [ 'string', 'null' ],
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'Stripe Live Mode Price ID (e.g. price_xxx). Stored in lrn_membership_plan_providers.', 'learnomy' ),
			],
			'paypal_sandbox_plan_id' => [
				'type'              => [ 'string', 'null' ],
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'PayPal Sandbox Billing Plan ID (e.g. P-XXXXXXXX). Stored in lrn_membership_plan_providers.', 'learnomy' ),
			],
			'paypal_live_plan_id'    => [
				'type'              => [ 'string', 'null' ],
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => __( 'PayPal Live Billing Plan ID (e.g. P-XXXXXXXX). Stored in lrn_membership_plan_providers.', 'learnomy' ),
			],
		];
	}

	// -------------------------------------------------------------------------
	// Route handlers
	// -------------------------------------------------------------------------

	public function list_plans( WP_REST_Request $request ): WP_REST_Response {
		$plans = MembershipPlan::get_active();

		$etag         = $this->generate_etag( $plans );
		$not_modified = $this->maybe_not_modified( $etag, $request );
		if ( $not_modified ) {
			return $not_modified;
		}

		$response = $this->paginated_response(
			array_map( [ $this, 'format_plan' ], $plans ),
			count( $plans )
		);

		return $this->add_etag( $response, $etag );
	}

	public function get_plan( WP_REST_Request $request ): WP_REST_Response {
		$plan_id = absint( $request->get_param( 'id' ) );
		$plan    = MembershipPlan::find( $plan_id );

		if ( ! $plan ) {
			return $this->error_response( $this->not_found( 'Membership plan' ) );
		}

		$courses = MembershipPlan::get_courses( $plan_id );

		$data            = $this->format_plan( $plan );
		$data['courses'] = array_map( [ $this, 'format_course_summary' ], $courses );

		$etag         = $this->generate_etag( $data );
		$not_modified = $this->maybe_not_modified( $etag, $request );
		if ( $not_modified ) {
			return $not_modified;
		}

		$response = $this->single_response( $data );
		return $this->add_etag( $response, $etag );
	}

	public function subscribe( WP_REST_Request $request ): WP_REST_Response {
		$user_id        = get_current_user_id();
		$plan_id        = absint( $request->get_param( 'plan_id' ) );
		$payment_method = $request->get_param( 'payment_method' );
		$provider_sub   = $request->get_param( 'provider_sub_id' ) ?? null;

		$plan = MembershipPlan::find( $plan_id );
		if ( ! $plan || 'active' !== $plan->status ) {
			return $this->error_response( $this->not_found( 'Membership plan' ) );
		}

		// Paid plans must go through checkout + gateway confirmation. This public
		// self-service endpoint only starts FREE memberships; paid subscriptions
		// are created server-side by the payment adapters (Stripe/PayPal/Woo)
		// after the gateway confirms payment. Without this guard a crafted request
		// (e.g. payment_method=manual on a paid plan) would grant a paid
		// membership for free. [security: card 10062744564]
		if ( ! MembershipPlan::is_free( $plan ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_paid_plan_requires_checkout',
					__( 'This is a paid plan. Please complete checkout to subscribe.', 'learnomy' ),
					[ 'status' => 402 ]
				)
			);
		}

		// Block duplicate active subscriptions on the same plan.
		$existing = Subscription::get_active_for_user( $user_id );
		foreach ( $existing as $sub ) {
			if ( (int) $sub->plan_id === $plan_id ) {
				return $this->error_response(
					$this->validation_error( __( 'You already have an active subscription to this plan.', 'learnomy' ) )
				);
			}
		}

		$service         = new Subscription_Service();
		$subscription_id = $service->subscribe( $user_id, $plan_id, $payment_method, $provider_sub );

		if ( ! $subscription_id ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_subscribe_failed',
					__( 'Unable to start your subscription. The plan may be full or inactive.', 'learnomy' ),
					[ 'status' => 422 ]
				)
			);
		}

		$subscription = Subscription::find( $subscription_id );

		return $this->single_response( $this->format_subscription( $subscription ), [], 201 );
	}

	public function my_memberships( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$subs    = Subscription::get_active_for_user( $user_id );

		return $this->paginated_response(
			array_map( [ $this, 'format_subscription' ], $subs ),
			count( $subs )
		);
	}

	public function cancel( WP_REST_Request $request ): WP_REST_Response {
		$user_id         = get_current_user_id();
		$subscription_id = absint( $request->get_param( 'subscription_id' ) );

		$sub = Subscription::find( $subscription_id );

		if ( ! $sub ) {
			return $this->error_response( $this->not_found( 'Subscription' ) );
		}

		// Users can only cancel their own subscriptions.
		if ( (int) $sub->user_id !== $user_id ) {
			return $this->error_response( $this->permission_error() );
		}

		// A member must be able to cancel any live subscription — including one
		// that is past_due (failing card) or trialing — not only 'active'. The
		// manage page shows the Cancel button for all of these, so rejecting
		// non-active here was a dead-end. Already-cancelled/expired subs stay
		// blocked. [card 10062795906]
		$cancellable_statuses = (array) apply_filters(
			'learnomy_cancellable_subscription_statuses',
			array( 'active', 'past_due', 'trialing' ),
			$sub
		);
		if ( ! in_array( (string) $sub->status, $cancellable_statuses, true ) ) {
			return $this->error_response(
				$this->validation_error( __( 'This subscription can no longer be cancelled.', 'learnomy' ) )
			);
		}

		// Capture exit-survey data BEFORE the cancel runs. Goes through the
		// generic lrn_audit_log table — cancellation is a subscription event
		// like any other, so a per-table churn store is sprawl. The churn
		// dashboard reads with `type='subscription_event' AND action='cancelled'`
		// and pulls reason/feedback/offer out of metadata_json via JSON_EXTRACT.
		$reason         = (string) $request->get_param( 'reason' );
		$feedback_text  = (string) $request->get_param( 'feedback_text' );
		$accepted_offer = (string) $request->get_param( 'accepted_offer' );

		$valid_reasons = $this->valid_cancel_reasons();
		if ( '' === $reason || ! in_array( $reason, $valid_reasons, true ) ) {
			$reason = 'other';
		}

		AuditLog::log(
			$user_id,
			'subscription_event',
			'cancelled',
			'subscription',
			$subscription_id,
			array(
				'reason'         => $reason,
				'feedback_text'  => $feedback_text,
				'accepted_offer' => $accepted_offer,
			)
		);

		$service = new Subscription_Service();
		$service->cancel( $subscription_id );

		$updated = Subscription::find( $subscription_id );

		return $this->single_response( $this->format_subscription( $updated ) );
	}

	/**
	 * GET /memberships/me/cancel-options.
	 *
	 * Powers the save-the-customer modal. Returns the reason list (canonical
	 * + filterable) and any retention offers a site owner or Pro extension
	 * has wired in via the `learnomy_cancel_retention_offers` filter.
	 *
	 * Default offers are empty — Free LMS ships the survey shell and trusts
	 * Pro (or the site owner's snippet) to populate offers. Filter contract:
	 *   array of [ 'slug', 'label', 'description', 'cta_url', 'tone' ].
	 */
	public function cancel_options( WP_REST_Request $request ): WP_REST_Response {
		$user_id         = get_current_user_id();
		$subscription_id = absint( $request->get_param( 'subscription_id' ) );

		$sub = Subscription::find( $subscription_id );
		if ( ! $sub || (int) $sub->user_id !== $user_id ) {
			return $this->error_response( $this->not_found( 'Subscription' ) );
		}

		$offers = (array) apply_filters( 'learnomy_cancel_retention_offers', array(), $sub, $user_id );

		// Defensively normalise so the JS modal can render every row without
		// optional-chaining gymnastics on missing keys.
		$normalised_offers = array();
		foreach ( $offers as $offer ) {
			if ( ! is_array( $offer ) || empty( $offer['slug'] ) || empty( $offer['label'] ) ) {
				continue;
			}
			$normalised_offers[] = array(
				'slug'        => sanitize_key( (string) $offer['slug'] ),
				'label'       => (string) $offer['label'],
				'description' => (string) ( $offer['description'] ?? '' ),
				'cta_url'     => isset( $offer['cta_url'] ) ? esc_url_raw( (string) $offer['cta_url'] ) : '',
				'tone'        => isset( $offer['tone'] ) ? sanitize_key( (string) $offer['tone'] ) : 'neutral',
			);
		}

		return $this->single_response(
			array(
				'reasons' => $this->cancel_reason_labels(),
				'offers'  => $normalised_offers,
			)
		);
	}

	/**
	 * Canonical reason slugs + their translated labels. Filter so site owners
	 * can add or remove reasons without forking the modal.
	 *
	 * @return array<int, array{slug:string, label:string}>
	 */
	private function cancel_reason_labels(): array {
		$default = array(
			'too_expensive'     => __( 'Too expensive', 'learnomy' ),
			'not_using_enough'  => __( 'Not using it enough', 'learnomy' ),
			'missing_features'  => __( 'Missing features I need', 'learnomy' ),
			'found_alternative' => __( 'Found a better alternative', 'learnomy' ),
			'temporary_break'   => __( 'Taking a temporary break', 'learnomy' ),
			'other'             => __( 'Something else', 'learnomy' ),
		);

		$filtered = (array) apply_filters( 'learnomy_cancel_reasons', $default );

		$out = array();
		foreach ( $filtered as $slug => $label ) {
			if ( ! is_string( $slug ) || ! is_string( $label ) || '' === $slug ) {
				continue;
			}
			$out[] = array(
				'slug'  => sanitize_key( $slug ),
				'label' => $label,
			);
		}
		return $out;
	}

	/**
	 * Slug-only list of accepted cancel reasons, used to validate the
	 * incoming POST payload.
	 */
	private function valid_cancel_reasons(): array {
		return array_map(
			static fn( array $row ): string => $row['slug'],
			$this->cancel_reason_labels()
		);
	}

	public function reactivate( WP_REST_Request $request ): WP_REST_Response {
		$user_id        = get_current_user_id();
		$plan_id        = absint( $request->get_param( 'plan_id' ) );
		$payment_method = $request->get_param( 'payment_method' );

		$plan = MembershipPlan::find( $plan_id );
		if ( ! $plan || 'active' !== $plan->status ) {
			return $this->error_response( $this->not_found( 'Membership plan' ) );
		}

		$service         = new Subscription_Service();
		$subscription_id = $service->subscribe( $user_id, $plan_id, $payment_method );

		if ( ! $subscription_id ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_reactivate_failed',
					__( 'Unable to reactivate this plan. Please try again or contact support.', 'learnomy' ),
					[ 'status' => 422 ]
				)
			);
		}

		$subscription = Subscription::find( $subscription_id );

		return $this->single_response( $this->format_subscription( $subscription ), [], 201 );
	}

	public function resume( WP_REST_Request $request ): WP_REST_Response {
		$user_id         = get_current_user_id();
		$subscription_id = absint( $request->get_param( 'subscription_id' ) );

		$sub = Subscription::find( $subscription_id );
		if ( ! $sub ) {
			return $this->error_response( $this->not_found( 'Subscription' ) );
		}
		if ( (int) $sub->user_id !== $user_id ) {
			return $this->error_response( $this->permission_error() );
		}

		$service = new Subscription_Service();
		$ok      = false;

		if ( 'paused' === $sub->status ) {
			// Paused subs need Pro's Pause_Service to recompute the period
			// extension. Pro hooks into this filter and returns the resume
			// result; Free without Pro just returns false here and falls
			// through to the validation error below.
			$ok = (bool) apply_filters( 'learnomy_membership_resume_paused', false, $subscription_id, $sub );
		} else {
			$ok = $service->resume( $subscription_id );
		}

		if ( ! $ok ) {
			return $this->error_response(
				$this->validation_error( __( 'This subscription cannot be resumed.', 'learnomy' ) )
			);
		}

		return $this->single_response( $this->format_subscription( Subscription::find( $subscription_id ) ) );
	}

	public function my_invoices( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$rows    = Transaction::get_membership_invoices( $user_id );

		return $this->paginated_response(
			array_map( [ $this, 'format_invoice' ], $rows ),
			count( $rows )
		);
	}

	public function create_plan( WP_REST_Request $request ): WP_REST_Response {
		$data = $this->collect_plan_payload( $request );
		if ( empty( $data['title'] ) ) {
			return $this->error_response( $this->validation_error( __( 'Plan title is required.', 'learnomy' ) ) );
		}
		if ( empty( $data['slug'] ) ) {
			$data['slug'] = sanitize_title( $data['title'] );
		}

		$plan_id = MembershipPlan::create( $data );
		if ( is_wp_error( $plan_id ) ) {
			return $this->error_response( $plan_id );
		}
		if ( ! $plan_id ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_plan_create_failed',
					__( 'Could not create plan. The slug may already exist.', 'learnomy' ),
					[ 'status' => 422 ]
				)
			);
		}

		$this->sync_plan_courses( $plan_id, (array) $request->get_param( 'course_ids' ) );

		// Payment provider mappings — allow presetting IDs on create so the admin
		// can paste them in while setting up the plan for the first time.
		$this->sync_provider_mappings( $plan_id, $request );

		do_action( 'learnomy_membership_plan_saved', $plan_id, true );

		$plan = MembershipPlan::find( $plan_id );
		return $this->single_response( $this->format_plan( $plan ), [], 201 );
	}

	public function update_plan( WP_REST_Request $request ): WP_REST_Response {
		$plan_id = absint( $request->get_param( 'id' ) );
		$plan    = MembershipPlan::find( $plan_id );
		if ( ! $plan ) {
			return $this->error_response( $this->not_found( 'Plan' ) );
		}

		$data = $this->collect_plan_payload( $request );
		if ( ! empty( $data ) ) {
			MembershipPlan::update( $plan_id, $data );
		}

		$course_ids = $request->get_param( 'course_ids' );
		if ( null !== $course_ids ) {
			$this->sync_plan_courses( $plan_id, (array) $course_ids );
		}

		// Payment provider mappings — persisted to lrn_membership_plan_providers,
		// not to lrn_membership_plans. Only written when the fields are present in
		// the request body so partial updates (e.g. status-only PATCH via
		// /status) don't accidentally wipe existing mappings.
		$this->sync_provider_mappings( $plan_id, $request );

		do_action( 'learnomy_membership_plan_saved', $plan_id, false );

		return $this->single_response( $this->format_plan( MembershipPlan::find( $plan_id ) ) );
	}

	public function delete_plan( WP_REST_Request $request ): WP_REST_Response {
		$plan_id = absint( $request->get_param( 'id' ) );
		$plan    = MembershipPlan::find( $plan_id );
		if ( ! $plan ) {
			return $this->error_response( $this->not_found( 'Plan' ) );
		}

		// Plans with live subscriptions are soft-archived to preserve history;
		// fresh plans can be hard-deleted. Use a bounded COUNT query rather than
		// loading the full subscriber list — on a popular plan this could be
		// thousands of rows just to check a boolean.
		$has_subs = Subscription::count( array( 'plan_id' => $plan_id ) ) > 0;
		if ( $has_subs ) {
			MembershipPlan::update( $plan_id, [ 'status' => 'inactive' ] );
			do_action( 'learnomy_membership_plan_archived', $plan_id );
		} else {
			// Hard-delete cascades to the junction tables. Neither table has an
			// ON DELETE CASCADE FK constraint, so the plan row delete alone would
			// orphan its course links and provider mappings — leaving access
			// checks referencing a ghost plan and dead Stripe/PayPal price IDs
			// registered against the freed plan ID.
			MembershipPlanCourse::delete_for_plan( $plan_id );
			Plan_Provider::delete_all_for_plan( $plan_id );
			MembershipPlan::delete( $plan_id );
			do_action( 'learnomy_membership_plan_deleted', $plan_id );
		}

		return $this->single_response(
			[
				'id'       => $plan_id,
				'archived' => $has_subs,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Admin-only handlers
	// -------------------------------------------------------------------------

	public function update_plan_status( WP_REST_Request $request ): WP_REST_Response {
		$plan_id = absint( $request->get_param( 'id' ) );
		$plan    = MembershipPlan::find( $plan_id );
		if ( ! $plan ) {
			return $this->error_response( $this->not_found( 'Plan' ) );
		}

		$status = $request->get_param( 'status' );
		MembershipPlan::update( $plan_id, [ 'status' => $status ] );

		return $this->single_response(
			[
				'id'     => $plan_id,
				'status' => $status,
			]
		);
	}

	public function admin_cancel_subscription( WP_REST_Request $request ): WP_REST_Response {
		$sub_id = absint( $request->get_param( 'id' ) );
		$sub    = Subscription::find( $sub_id );
		if ( ! $sub ) {
			return $this->error_response( $this->not_found( 'Subscription' ) );
		}

		// Admin chooses: cancel at period end (keep access until
		// current_period_end, status → cancel_pending) or immediately (revoke
		// now). Defaults to immediate so the existing list "Cancel" button is
		// unchanged; the detail page passes at_period_end=1 for the soft path.
		$at_period_end = (bool) $request->get_param( 'at_period_end' );
		$service       = new Subscription_Service();
		$ok            = $service->cancel( $sub_id, ! $at_period_end );
		if ( ! $ok ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_cancel_failed',
					__( 'Could not cancel subscription.', 'learnomy' ),
					[ 'status' => 500 ]
				)
			);
		}

		return $this->single_response(
			[
				'id'        => $sub_id,
				'cancelled' => true,
			]
		);
	}

	/**
	 * Admin reactivates a paused / past-due subscription (or clears a pending
	 * period-end cancellation) via Subscription_Service::resume().
	 */
	public function admin_resume_subscription( WP_REST_Request $request ): WP_REST_Response {
		$sub_id = absint( $request->get_param( 'id' ) );
		$sub    = Subscription::find( $sub_id );
		if ( ! $sub ) {
			return $this->error_response( $this->not_found( 'Subscription' ) );
		}

		$ok = ( new Subscription_Service() )->resume( $sub_id );
		if ( ! $ok ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_resume_failed',
					__( 'Could not resume subscription.', 'learnomy' ),
					[ 'status' => 500 ]
				)
			);
		}

		return $this->single_response(
			[
				'id'      => $sub_id,
				'resumed' => true,
			]
		);
	}

	public function admin_extend_subscription( WP_REST_Request $request ): WP_REST_Response {
		$sub_id  = absint( $request->get_param( 'id' ) );
		$new_end = (string) $request->get_param( 'period_end' );

		$timestamp = strtotime( $new_end );
		if ( false === $timestamp || $timestamp <= time() ) {
			return $this->error_response(
				$this->validation_error( __( 'Period end must be a future date.', 'learnomy' ) )
			);
		}

		$sub = Subscription::find( $sub_id );
		if ( ! $sub ) {
			return $this->error_response( $this->not_found( 'Subscription' ) );
		}

		$ok = Subscription::update(
			$sub_id,
			[
				'current_period_end' => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'status'             => 'active',
			]
		);
		if ( ! $ok ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_extend_failed',
					__( 'Could not extend subscription.', 'learnomy' ),
					[ 'status' => 500 ]
				)
			);
		}

		do_action( 'learnomy_subscription_extended', $sub_id, (int) $sub->user_id, (int) $sub->plan_id );

		return $this->single_response(
			[
				'id'         => $sub_id,
				'period_end' => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'status'     => 'active',
			]
		);
	}

	/**
	 * Stream subscription rows as a CSV download. Filters mirror the
	 * Subscriptions admin page (status + plan_id) so admins can export
	 * exactly what their current view shows.
	 */
	/**
	 * Write or clear a single provider mapping row.
	 *
	 * POST /admin/membership-plans/{id}/provider-mapping
	 * Body: { provider, mode, provider_plan_id }
	 * Returns the full set of mappings for the plan after the write.
	 */
	public function upsert_provider_mapping( WP_REST_Request $request ): WP_REST_Response {
		$plan_id = absint( $request->get_param( 'id' ) );
		$plan    = MembershipPlan::find( $plan_id );
		if ( ! $plan ) {
			return $this->error_response( $this->not_found( 'Membership plan' ) );
		}

		$provider         = (string) $request->get_param( 'provider' );
		$mode             = (string) $request->get_param( 'mode' );
		$provider_plan_id = trim( (string) ( $request->get_param( 'provider_plan_id' ) ?? '' ) );

		if ( '' !== $provider_plan_id ) {
			Plan_Provider::upsert( $plan_id, $provider, $mode, $provider_plan_id );
		} else {
			Plan_Provider::delete_mapping( $plan_id, $provider, $mode );
		}

		$rows = Plan_Provider::get_all_for_plan( $plan_id );

		return $this->single_response(
			[
				'plan_id'  => $plan_id,
				'mappings' => array_map(
					static function ( object $row ): array {
						return [
							'provider'         => $row->provider,
							'mode'             => $row->mode,
							'provider_plan_id' => $row->provider_plan_id,
						];
					},
					$rows
				),
			]
		);
	}

	public function export_subscriptions_csv( WP_REST_Request $request ): WP_REST_Response {
		$status  = (string) ( $request->get_param( 'status' ) ?? 'all' );
		$plan_id = absint( $request->get_param( 'plan_id' ) );

		$where = [];
		if ( 'all' !== $status && '' !== $status && 'cancel_pending' !== $status ) {
			$where['status'] = $status;
		}
		if ( $plan_id ) {
			$where['plan_id'] = $plan_id;
		}

		// Cursor-iterate every matching row instead of the old LIMIT 10000
		// hard cap that silently truncated exports on sites with 10k+
		// subscriptions (Basecamp #9927990887).
		$rows = Subscription::iterate_all( $where, 500 );
		$csv  = $this->build_subscriptions_csv( $rows, $status );

		$response = new WP_REST_Response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="learnomy-subscriptions-' . gmdate( 'Y-m-d' ) . '.csv"' );

		return $response;
	}

	public function export_plans_csv( WP_REST_Request $request ): WP_REST_Response {
		// Same cursor-iterate fix as export_subscriptions_csv — the old
		// LIMIT 10000 silently dropped older plan rows when the catalogue
		// grew (Basecamp #9927990887).
		$plans = MembershipPlan::iterate_all( array(), 500 );
		$csv   = $this->build_plans_csv( $plans );

		$response = new WP_REST_Response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="learnomy-plans-' . gmdate( 'Y-m-d' ) . '.csv"' );

		return $response;
	}

	/**
	 * Render subscription rows into CSV text. Built in-memory rather than
	 * streaming to php://output because REST callbacks return a body the
	 * server frames; streaming would race against the framing layer.
	 */
	private function build_subscriptions_csv( iterable $rows, string $status_filter ): string {
		$buffer = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		fputcsv(
			$buffer,
			[
				__( 'User Email', 'learnomy' ),
				__( 'User Name', 'learnomy' ),
				__( 'Plan Title', 'learnomy' ),
				__( 'Status', 'learnomy' ),
				__( 'Period Start', 'learnomy' ),
				__( 'Period End', 'learnomy' ),
				__( 'Total Paid', 'learnomy' ),
				__( 'Signup Date', 'learnomy' ),
				__( 'Source', 'learnomy' ),
				__( 'Provider', 'learnomy' ),
			]
		);

		$plan_titles = [];
		foreach ( $rows as $row ) {
			if ( 'cancel_pending' === $status_filter && empty( $row->cancel_at_period_end ) ) {
				continue;
			}

			$user        = get_userdata( (int) $row->user_id );
			$plan_id_row = (int) $row->plan_id;
			if ( ! isset( $plan_titles[ $plan_id_row ] ) ) {
				$plan                        = MembershipPlan::find( $plan_id_row );
				$plan_titles[ $plan_id_row ] = $plan ? (string) $plan->title : '';
			}

			$total_paid = 0.0;
			foreach ( Transaction::get_for_subscription( (int) $row->id, 500 ) as $txn ) {
				if ( 'completed' === ( $txn->status ?? '' ) ) {
					$total_paid += (float) ( $txn->amount ?? 0 );
				}
			}

			fputcsv(
				$buffer,
				[
					$user ? $user->user_email : '',
					$user ? $user->display_name : '',
					$plan_titles[ $plan_id_row ],
					(string) ( $row->status ?? '' ),
					(string) ( $row->current_period_start ?? '' ),
					(string) ( $row->current_period_end ?? '' ),
					number_format( $total_paid, 2, '.', '' ),
					(string) ( $row->created_at ?? '' ),
					(string) ( $row->source ?? '' ),
					(string) ( $row->payment_provider ?? '' ),
				]
			);
		}

		rewind( $buffer );
		$csv = (string) stream_get_contents( $buffer );
		fclose( $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv;
	}

	private function build_plans_csv( iterable $plans ): string {
		$buffer = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		fputcsv(
			$buffer,
			[
				__( 'Title', 'learnomy' ),
				__( 'Slug', 'learnomy' ),
				__( 'Price', 'learnomy' ),
				__( 'Currency', 'learnomy' ),
				__( 'Billing Interval', 'learnomy' ),
				__( 'Trial Days', 'learnomy' ),
				__( 'Active Subscribers', 'learnomy' ),
				__( 'Status', 'learnomy' ),
			]
		);

		// Convert the iterable to an array so we can extract IDs for a single
		// GROUP BY query instead of one unbounded SELECT per plan row.
		$plans_arr = is_array( $plans ) ? $plans : iterator_to_array( $plans );
		$plan_ids  = array_map( static fn( $p ) => (int) $p->id, $plans_arr );
		// Batched active-subscriber counts — one query regardless of plan count.
		$active_counts = Subscription::count_active_by_plan( $plan_ids );

		foreach ( $plans_arr as $plan ) {
			$active_subs = $active_counts[ (int) $plan->id ] ?? 0;

			fputcsv(
				$buffer,
				[
					(string) ( $plan->title ?? '' ),
					(string) ( $plan->slug ?? '' ),
					number_format( (float) ( $plan->price ?? 0 ), 2, '.', '' ),
					(string) ( $plan->currency ?? '' ),
					(string) ( $plan->billing_interval ?? '' ),
					(int) ( $plan->trial_days ?? 0 ),
					$active_subs,
					(string) ( $plan->status ?? '' ),
				]
			);
		}

		rewind( $buffer );
		$csv = (string) stream_get_contents( $buffer );
		fclose( $buffer ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $csv;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function collect_plan_payload( WP_REST_Request $request ): array {
		$keys = [
			'title',
			'slug',
			'description',
			'price',
			'currency',
			'billing_interval',
			'interval_count',
			'trial_days',
			'course_limit',
			'price_monthly',
			'price_yearly',
			'thumbnail_url',
			'features_json',
			'is_featured',
			'status',
		];

		// Walk the parsed JSON body rather than get_param() so we can
		// distinguish "key absent" (leave column alone) from "key sent as
		// null" (clear the column). Nullable fields like price_monthly /
		// price_yearly / course_limit rely on this — admins toggling pricing
		// mode in the UI need to actively wipe the inactive columns.
		$body = (array) ( $request->get_json_params() ?? [] );
		$data = [];
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$data[ $key ] = $body[ $key ];
			}
		}
		return $data;
	}

	/**
	 * Write or clear payment-provider mappings for a plan.
	 *
	 * Only acts on fields present in the request body — absent fields are
	 * left untouched so partial saves (e.g. status toggle) don't wipe mappings.
	 * An empty-string value deletes the mapping for that (provider, mode) pair.
	 *
	 * @param int             $plan_id Learnomy membership plan ID.
	 * @param WP_REST_Request $request Incoming REST request.
	 */
	private function sync_provider_mappings( int $plan_id, WP_REST_Request $request ): void {
		// Map of request-body key => [provider, mode].
		$mapping = [
			'stripe_test_price_id'   => [ 'stripe', 'test' ],
			'stripe_live_price_id'   => [ 'stripe', 'live' ],
			'paypal_sandbox_plan_id' => [ 'paypal', 'sandbox' ],
			'paypal_live_plan_id'    => [ 'paypal', 'live' ],
		];

		// Walk the raw JSON body so we can distinguish "key absent" from
		// "key present as empty string" (same technique as collect_plan_payload).
		$body = (array) ( $request->get_json_params() ?? [] );

		foreach ( $mapping as $field => [ $provider, $mode ] ) {
			if ( ! array_key_exists( $field, $body ) ) {
				// Field not in request — leave existing DB row untouched.
				continue;
			}

			$value = trim( (string) ( $body[ $field ] ?? '' ) );
			if ( '' !== $value ) {
				Plan_Provider::upsert( $plan_id, $provider, $mode, $value );
			} else {
				Plan_Provider::delete_mapping( $plan_id, $provider, $mode );
			}
		}
	}

	private function sync_plan_courses( int $plan_id, array $course_ids ): void {
		$course_ids = array_values( array_unique( array_map( 'absint', $course_ids ) ) );
		$course_ids = array_filter( $course_ids );
		MembershipPlanCourse::replace_for_plan( $plan_id, $course_ids );
	}

	// -------------------------------------------------------------------------
	// Formatters
	// -------------------------------------------------------------------------

	private function format_plan( object $plan ): array {
		return [
			'id'               => (int) $plan->id,
			'name'             => $plan->title,
			'title'            => $plan->title,
			'slug'             => $plan->slug,
			'description'      => $plan->description ?? '',
			'price'            => (float) $plan->price,
			'currency'         => $plan->currency ?? 'USD',
			'billing_interval' => $plan->billing_interval ?? 'monthly',
			'interval_count'   => (int) ( $plan->interval_count ?? 1 ),
			'trial_days'       => (int) ( $plan->trial_days ?? 0 ),
			'course_limit'     => isset( $plan->course_limit ) ? (int) $plan->course_limit : null,
			'price_monthly'    => isset( $plan->price_monthly ) ? (float) $plan->price_monthly : null,
			'price_yearly'     => isset( $plan->price_yearly ) ? (float) $plan->price_yearly : null,
			'thumbnail_url'    => $plan->thumbnail_url ?? '',
			'features_json'    => $plan->features_json ?? '',
			'is_featured'      => (bool) ( $plan->is_featured ?? false ),
			'status'           => $plan->status,
		];
	}

	private function format_subscription( object $sub ): array {
		return [
			'id'                   => (int) $sub->id,
			'plan_id'              => (int) $sub->plan_id,
			'status'               => $sub->status,
			'payment_provider'     => $sub->payment_provider ?? '',
			'current_period_start' => $sub->current_period_start,
			'current_period_end'   => $sub->current_period_end,
			'trial_ends_at'        => $sub->trial_ends_at ?? null,
			'cancel_at_period_end' => (bool) ( $sub->cancel_at_period_end ?? 0 ),
			'cancelled_at'         => $sub->cancelled_at ?? null,
		];
	}

	private function format_invoice( object $txn ): array {
		return [
			'id'          => (int) $txn->id,
			'amount'      => (float) $txn->amount,
			'currency'    => $txn->currency ?? 'USD',
			'status'      => $txn->status,
			'provider'    => $txn->payment_provider ?? '',
			'provider_id' => $txn->provider_transaction_id ?? '',
			'created_at'  => $txn->created_at,
		];
	}

	private function format_course_summary( object $course ): array {
		return [
			'id'        => (int) $course->id,
			'title'     => $course->title,
			'thumbnail' => $course->thumbnail_url ?? '',
		];
	}
}
