<?php
/**
 * Earnings controller — instructor commission summary, history, and withdrawals.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use Learnomy\Models\Commission;
use Learnomy\Models\Transaction;
use Learnomy\Models\Withdrawal;
use Learnomy\Services\Commission_Service;

class Earnings_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'earnings';
	}

	public function register_routes(): void {
		// GET /earnings — summary totals for the calling instructor.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'summary' ],
				'permission_callback' => [ $this, 'is_instructor' ],
			]
		);

		// GET /earnings/history — monthly breakdown for the past 12 months.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/history',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'history' ],
				'permission_callback' => [ $this, 'is_instructor' ],
			]
		);

		// GET /earnings/transactions — paginated commission records.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/transactions',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'transactions' ],
				'permission_callback' => [ $this, 'is_instructor' ],
				'args'                => $this->get_collection_params(),
			]
		);

		// POST /earnings/withdraw — request a payout.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/withdraw',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'withdraw' ],
				'permission_callback' => [ $this, 'is_instructor' ],
				'args'                => [
					'amount'        => [
						'required'    => true,
						'type'        => 'number',
						'minimum'     => 0.01,
						'description' => __( 'Amount you would like to withdraw.', 'learnomy' ),
					],
					'currency'      => [
						'type'              => 'string',
						'default'           => 'USD',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'method'        => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'description'       => __( 'Payout method (e.g. paypal, manual, bank_transfer).', 'learnomy' ),
					],
					'payout_email'  => [
						'type'              => 'string',
						'format'            => 'email',
						'sanitize_callback' => 'sanitize_email',
						// A custom sanitize_callback REPLACES core's default
						// schema parse for the param, so `format: email` was
						// never validated — "not-an-email" sanitized to '' and
						// sailed through (the other args validate because they
						// have no custom callback and get the default). This
						// restores the boundary check, with one carve-out: a
						// BLANK value passes, because a blank field means "use
						// my saved address" and must keep falling back rather
						// than 400 (Basecamp 10172139611).
						'validate_callback' => static function ( $value, $request, $param ) {
							if ( null === $value || '' === trim( (string) $value ) ) {
								return true;
							}
							return rest_validate_request_arg( $value, $request, $param );
						},
						'description'       => __( 'PayPal email that should receive the payout. Required when method is paypal; ignored otherwise.', 'learnomy' ),
					],
					'settle_amount' => [
						'type'        => 'number',
						'minimum'     => 0,
						'description' => __( 'The settled total you were shown before submitting. When supplied it must still match, so a request is never placed against a figure that has changed since.', 'learnomy' ),
					],
				],
			]
		);

		// GET /earnings/withdraw/preview — what a request would ACTUALLY settle.
		// Earnings pay out as whole commission rows, so an arbitrary figure
		// resolves up to a row boundary. The instructor sees that number BEFORE
		// they commit, not after: quoting one figure and settling another is the
		// same defect, one screen earlier.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/withdraw/preview',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'withdraw_preview' ],
				'permission_callback' => [ $this, 'is_instructor' ],
				'args'                => [
					'amount' => [
						'required'    => true,
						'type'        => 'number',
						'minimum'     => 0.01,
						'description' => __( 'Amount you would like to withdraw.', 'learnomy' ),
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function is_instructor(): bool|\WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'manage_own_courses' resolves to learnomy_manage_own_courses; admins
		// pass via Permission_Engine's manage_options short-circuit (line 33).
		if ( ! $this->check_permission( 'manage_own_courses' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Route handlers
	// -------------------------------------------------------------------------

	public function summary( WP_REST_Request $request ): WP_REST_Response {
		$instructor_id = get_current_user_id();

		// All aggregates come from SQL — previously this loaded every
		// commission row into memory and summed in PHP. For a top instructor
		// that could be thousands of rows per dashboard hit.
		$this_month_start = gmdate( 'Y-m-01 00:00:00' );
		$last_month_start = gmdate( 'Y-m-01 00:00:00', strtotime( 'first day of last month' ) );

		$lifetime         = Commission::sum_for_instructor( $instructor_id );
		$this_month       = Commission::sum_for_instructor_since( $instructor_id, $this_month_start );
		$since_last_month = Commission::sum_for_instructor_since( $instructor_id, $last_month_start );
		$last_month       = max( 0.0, $since_last_month - $this_month );
		$pending          = Commission::sum_pending_for_instructor( $instructor_id );

		$totals = array(
			'this_month' => round( $this_month, 2 ),
			'last_month' => round( $last_month, 2 ),
			'lifetime'   => round( $lifetime, 2 ),
			'pending'    => round( $pending, 2 ),
		);

		$pending_withdrawals               = Withdrawal::get_for_instructor( $instructor_id );
		$totals['withdrawals_in_progress'] = count(
			array_filter( $pending_withdrawals, fn( $w ) => in_array( $w->status, [ 'pending', 'approved' ], true ) )
		);

		return $this->single_response( $totals );
	}

	public function history( WP_REST_Request $request ): WP_REST_Response {
		$instructor_id = get_current_user_id();

		// 12-month window. SQL groups commissions by YYYY-MM for us, so we
		// no longer pull every row into PHP. Empty months show up with 0.0.
		$months = [];
		for ( $i = 11; $i >= 0; $i-- ) {
			$months[] = gmdate( 'Y-m', strtotime( "-{$i} months" ) );
		}

		$earliest_month_start = gmdate( 'Y-m-01 00:00:00', strtotime( '-11 months' ) );
		$monthly_totals       = Commission::monthly_totals_for_instructor( $instructor_id, $earliest_month_start );

		$by_month = array_fill_keys( $months, 0.0 );
		foreach ( $monthly_totals as $month => $total ) {
			if ( isset( $by_month[ $month ] ) ) {
				$by_month[ $month ] = $total;
			}
		}

		$breakdown = [];
		foreach ( $by_month as $month => $total ) {
			$breakdown[] = [
				'month'    => $month,
				'earnings' => round( $total, 2 ),
			];
		}

		return $this->paginated_response( $breakdown, count( $breakdown ) );
	}

	public function transactions( WP_REST_Request $request ): WP_REST_Response {
		$instructor_id = get_current_user_id();
		$pagination    = $this->get_pagination( $request );
		$limit         = (int) $pagination['limit'];
		$offset        = (int) $pagination['offset'];

		// DB-level pagination — a top instructor can rack up thousands of
		// commission rows and the old array_slice loaded them all.
		$total       = Commission::count_for_instructor( $instructor_id );
		$commissions = Commission::get_for_instructor( $instructor_id, $limit, $offset );
		$items       = array_map( [ $this, 'format_commission' ], $commissions );

		if ( 'csv' === $request->get_param( 'format' ) ) {
			return Csv_Formatter::respond(
				[ 'id', 'amount', 'currency', 'status', 'payout_period', 'created_at' ],
				$items,
				'earnings-transactions'
			);
		}

		return $this->paginated_response( $items, $total, $offset );
	}

	/**
	 * GET /earnings/withdraw/preview — the honest answer to "what does this
	 * actually settle?", before the instructor commits.
	 *
	 * Earnings settle as WHOLE commission rows, so an arbitrary figure resolves
	 * up to the row boundary that covers it. Rounding that silently is the same
	 * class of bug as settling the wrong figure — the instructor has to see the
	 * real number, the number of earnings behind it, and what stays available.
	 * The sentence is composed server-side so the quoted figure and the settled
	 * figure come out of one arithmetic path (Commission_Service::plan_withdrawal)
	 * and one translated string.
	 */
	public function withdraw_preview( WP_REST_Request $request ): WP_REST_Response {
		$instructor_id = get_current_user_id();
		$amount        = (float) $request->get_param( 'amount' );

		return $this->single_response(
			$this->describe_plan( Commission_Service::plan_withdrawal( $instructor_id, $amount ) )
		);
	}

	/**
	 * Turn a withdrawal plan into the instructor-facing disclosure: the settled
	 * total, what is left, and one plain-English sentence explaining any gap.
	 *
	 * @param array<string,mixed> $plan From {@see Commission_Service::plan_withdrawal()}.
	 * @return array<string,mixed>
	 */
	private function describe_plan( array $plan ): array {
		$currency = strtoupper( (string) \Learnomy\charge_currency() );
		$minimum  = $this->withdrawal_minimum();

		$settled   = (float) $plan['settled'];
		$requested = (float) $plan['requested'];
		$notice    = '';

		if ( 'exceeds_balance' === $plan['error'] ) {
			$notice = sprintf(
				/* translators: %s: formatted available balance */
				__( 'You have %s available to withdraw.', 'learnomy' ),
				\Learnomy\format_price( (float) $plan['available'], $currency )
			);
		} elseif ( '' === $plan['error'] ) {
			if ( $plan['exact'] ) {
				$notice = sprintf(
					/* translators: 1: formatted settled amount, 2: number of earnings */
					_n(
						'This settles %1$s from %2$d earning.',
						'This settles %1$s from %2$d earnings.',
						(int) $plan['lines'],
						'learnomy'
					),
					\Learnomy\format_price( $settled, $currency ),
					(int) $plan['lines']
				);
			} else {
				$notice = sprintf(
					// The neighbouring `exact` branch above pluralises through
					// _n(); this one hardcoded the plural, so settling a single
					// earning read "across 1 earnings" (Basecamp 10190750723).
					// A bare __() is also untranslatable for languages whose
					// plural rules differ from English at n=1.
					/* translators: 1: formatted settled amount, 2: number of earnings, 3: formatted difference, 4: formatted remaining balance */
					_n(
						'Earnings pay out whole, so this settles %1$s across %2$d earning - %3$s more than you asked for. %4$s stays available.',
						'Earnings pay out whole, so this settles %1$s across %2$d earnings - %3$s more than you asked for. %4$s stays available.',
						(int) $plan['lines'],
						'learnomy'
					),
					\Learnomy\format_price( $settled, $currency ),
					(int) $plan['lines'],
					\Learnomy\format_price( round( $settled - $requested, 2 ), $currency ),
					\Learnomy\format_price( (float) $plan['remaining'], $currency )
				);
			}
		}

		return array(
			'requested'   => round( $requested, 2 ),
			'settled'     => round( $settled, 2 ),
			'difference'  => round( $settled - $requested, 2 ),
			'available'   => (float) $plan['available'],
			'remaining'   => (float) $plan['remaining'],
			'lines'       => (int) $plan['lines'],
			'exact'       => (bool) $plan['exact'],
			'currency'    => $currency,
			'minimum'     => $minimum,
			'can_request' => '' === $plan['error'] && $requested >= $minimum,
			'notice'      => $notice,
		);
	}

	/**
	 * The site-wide withdrawal floor. `withdrawal_minimum` is the canonical key;
	 * the legacy `min_withdrawal` the Settings field used to (wrongly) save into
	 * is honoured as a fallback until the admin next saves.
	 */
	private function withdrawal_minimum(): float {
		$settings = get_option( 'learnomy_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		return (float) ( $settings['withdrawal_minimum'] ?? $settings['min_withdrawal'] ?? 25.0 );
	}

	public function withdraw( WP_REST_Request $request ): WP_REST_Response {
		$instructor_id = get_current_user_id();
		$amount        = (float) $request->get_param( 'amount' );
		$currency      = $request->get_param( 'currency' );
		$method        = $request->get_param( 'method' );

		if ( $amount <= 0 ) {
			return $this->error_response(
				$this->validation_error( __( 'Withdrawal amount must be greater than zero.', 'learnomy' ) )
			);
		}

		// Enforce the site-wide withdrawal minimum the admin configured.
		// Without this guard a hand-crafted POST could request $0.01
		// payouts and ignore the policy. The settled total is always >= the
		// requested amount, so checking the request also guarantees the payout
		// clears the floor.
		$minimum = $this->withdrawal_minimum();
		if ( $minimum > 0 && $amount < $minimum ) {
			return $this->error_response(
				$this->validation_error(
					sprintf(
						/* translators: 1: minimum amount, 2: currency code */
						__( 'The minimum withdrawal amount is %1$s %2$s.', 'learnomy' ),
						number_format_i18n( $minimum, 2 ),
						(string) $currency
					)
				)
			);
		}

		// Server-side enforcement of the per-method site-wide toggles
		// (`payout_paypal`, `payout_bank`). The JS hides the disabled methods
		// from the dropdown but a hand-crafted POST would bypass that — so the
		// REST layer rejects the request before it reaches the service.
		// Wired in Wave 2C rows 2C-5 (paypal) / 2C-6 (bank).
		$method_check = $this->validate_payout_method( $method );
		if ( is_wp_error( $method_check ) ) {
			return $this->error_response( $method_check );
		}

		// Resolve WHERE the payout goes. Only `paypal` carries a destination: the
		// payee email, a low-sensitivity "pay me here" identifier we capture on
		// the request so the site owner isn't left guessing. We remember it as
		// readable user meta so the instructor doesn't retype it next time — no
		// encryption vault, because a PayPal email is not a secret. Every other
		// method stores no destination: `manual` is arranged off-platform, and
		// `stripe`/`bank_transfer` are handled by their own onboarding.
		$destination = '';
		if ( 'paypal' === $method ) {
			// The RAW value, before the arg's sanitize_email ran — get_param()
			// only sees the sanitized result, where an invalid address and an
			// omitted field are both '', and those two must not behave alike.
			$raw_email    = $request->get_json_params()['payout_email']
				?? $request->get_body_params()['payout_email']
				?? $request->get_query_params()['payout_email']
				?? null;
			$payout_email = sanitize_email( (string) $raw_email );

			// A SENT-but-invalid address must refuse, never fall back. The old
			// shape sanitized first and fell back on '', so a typo'd address
			// silently rerouted the payout to whatever was saved on a previous
			// request — money to the wrong place behind a 201. The saved
			// address is only for requests that OMIT the field. Normally the
			// arg's restored validate_callback 400s first; this is the
			// defense-in-depth for any caller that bypasses arg validation
			// (Basecamp 10172139611, the real defect behind the app's report).
			if ( '' === $payout_email && null !== $raw_email && '' !== trim( (string) $raw_email ) ) {
				return $this->error_response(
					$this->validation_error(
						sprintf(
							/* translators: %s: the address as typed */
							__( '"%s" is not a valid email address. Check the PayPal email and try again.', 'learnomy' ),
							sanitize_text_field( (string) $raw_email )
						)
					)
				);
			}

			if ( '' === $payout_email ) {
				// Nothing sent — fall back to the address saved on a previous request.
				$payout_email = sanitize_email( (string) get_user_meta( $instructor_id, 'learnomy_payout_email', true ) );
			}
			if ( '' === $payout_email || ! is_email( $payout_email ) ) {
				return $this->error_response(
					$this->validation_error( __( 'Enter the PayPal email that should receive this payout.', 'learnomy' ) )
				);
			}
			update_user_meta( $instructor_id, 'learnomy_payout_email', $payout_email );
			$destination = $payout_email;
		}

		$rate_ok = $this->check_rate_limit( $instructor_id, 'withdrawal_request' );
		if ( is_wp_error( $rate_ok ) ) {
			return $this->error_response( $rate_ok );
		}

		// What this request will really settle. Resolved here so the client can
		// be held to the figure it displayed, and so the response can state it.
		$plan = Commission_Service::plan_withdrawal( $instructor_id, $amount );

		// A client that showed the instructor a settled total sends it back. If
		// a new sale (or a competing request) changed the row set in between,
		// the quoted figure is stale — refuse rather than settle a number the
		// instructor never saw. Omitting the param keeps every existing API
		// client working unchanged.
		$quoted = $request->get_param( 'settle_amount' );
		if ( null !== $quoted && '' === $plan['error']
			&& (int) round( (float) $quoted * 100 ) !== (int) round( (float) $plan['settled'] * 100 ) ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_withdraw_amount_changed',
					__( 'Your available earnings changed while this form was open. Reopen it to see what this request will settle.', 'learnomy' ),
					array(
						'status' => 409,
						'plan'   => $this->describe_plan( $plan ),
					)
				)
			);
		}

		$service       = new Commission_Service();
		$withdrawal_id = $service->request_withdrawal( $instructor_id, $amount, $currency, $method, $destination );

		if ( ! $withdrawal_id ) {
			return $this->error_response(
				new \WP_Error(
					'learnomy_withdraw_failed',
					__( 'Withdrawal request failed. Your available balance may be lower than the requested amount.', 'learnomy' ),
					[ 'status' => 422 ]
				)
			);
		}

		$withdrawal = Withdrawal::find( $withdrawal_id );

		// `amount` on the row IS the settled total. The disclosure is rebuilt from
		// the row and the rows it claimed — never from the pre-flight plan — so
		// the figure the response states is the figure that was written.
		$settled = (float) $withdrawal->amount;
		$lines   = Commission::count( array( 'withdrawal_id' => $withdrawal_id ) );

		$body                        = $this->format_withdrawal( $withdrawal );
		$body['requested_amount']    = round( $amount, 2 );
		$body['settled_amount']      = round( $settled, 2 );
		$body['settles_commissions'] = $lines;
		$body['notice']              = $this->describe_plan(
			array(
				'requested' => round( $amount, 2 ),
				'settled'   => round( $settled, 2 ),
				'available' => (float) $plan['available'],
				'remaining' => round( (float) $plan['available'] - $settled, 2 ),
				'lines'     => $lines,
				'exact'     => (int) round( $settled * 100 ) === (int) round( $amount * 100 ),
				'error'     => '',
			)
		)['notice'];

		return $this->single_response( $body, [], 201 );
	}

	/**
	 * Rejects withdrawal requests using a payout method the site admin has
	 * disabled. Maps the dropdown method values used by the instructor
	 * dashboard JS (`bank_transfer`, `paypal`, `stripe`) onto the admin
	 * settings flags (`payout_bank`, `payout_paypal`). Stripe currently
	 * has no per-method toggle, so it's accepted unconditionally — when
	 * a Stripe toggle ships, add it here.
	 *
	 * @return \WP_Error|true WP_Error on rejection, true when allowed.
	 */
	private function validate_payout_method( $method ) {
		$method   = is_string( $method ) ? $method : '';
		$settings = get_option( 'learnomy_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		// Map method → setting key. `null` means "no toggle gates this method".
		// `manual` is always available: it stores no destination — the instructor
		// arranges payment with the site owner off-platform (email), so there is
		// no gateway to enable and nothing sensitive to keep.
		$gate_map = [
			'paypal'        => 'payout_paypal',
			'bank_transfer' => 'payout_bank',
			'stripe'        => null,
			'manual'        => null,
		];

		if ( '' === $method || ! array_key_exists( $method, $gate_map ) ) {
			return new \WP_Error(
				'learnomy_invalid_payout_method',
				__( 'Choose a payout method to continue.', 'learnomy' ),
				[ 'status' => 422 ]
			);
		}

		$setting_key = $gate_map[ $method ];
		if ( null === $setting_key ) {
			return true;
		}

		// Default-on when the option row pre-dates the admin field. Matches
		// the install defaults in includes/class-learnomy.php.
		$enabled = ! array_key_exists( $setting_key, $settings )
			|| ! empty( $settings[ $setting_key ] );

		if ( ! $enabled ) {
			return new \WP_Error(
				'learnomy_payout_method_disabled',
				__( 'That payout method is not available on this site. Pick a different method.', 'learnomy' ),
				[ 'status' => 422 ]
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Formatters
	// -------------------------------------------------------------------------

	private function format_commission( object $c ): array {
		return [
			'id'             => (int) $c->id,
			'transaction_id' => (int) $c->transaction_id,
			'amount'         => (float) $c->amount,
			'currency'       => $c->currency,
			'rate_applied'   => (float) $c->rate_applied,
			'payout_status'  => $c->payout_status,
			'payout_period'  => $c->payout_period ?? null,
			'created_at'     => $c->created_at,
		];
	}

	private function format_withdrawal( object $w ): array {
		return [
			'id'           => (int) $w->id,
			'amount'       => (float) $w->amount,
			'currency'     => $w->currency,
			'method'       => $w->method,
			'destination'  => $w->destination ?? null,
			'status'       => $w->status,
			'requested_at' => $w->requested_at,
		];
	}
}
