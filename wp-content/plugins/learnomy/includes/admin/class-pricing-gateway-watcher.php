<?php
/**
 * Publish-time payment-gateway watcher (SS-3).
 *
 * Why this exists
 * - The 2026-05-16 customer-journey walk (see plan/2026-05-16-token-ready-sweep/
 *   customer-journey-walk.md §SS-3) showed that a paid course could be saved
 *   with status=published and zero warnings on installs where no gateway was
 *   configured. The customer landed on the checkout dead-end with no path
 *   forward; the site owner had no signal that anything was wrong until a
 *   support ticket showed up.
 * - This watcher closes the loop: every time a course is published (via
 *   Course_Service::publish, the REST status-write path, or the inline
 *   admin save) we check whether the course will be billable AND whether
 *   the site has any way to take money. If both answers diverge — yes
 *   billable, no gateway — we record the situation so the next admin page
 *   load surfaces a dismissible notice with a deep link to Payments.
 *
 * Design choices
 * - WARN, NEVER BLOCK. Per the SS-3 brief: a site owner might be wiring
 *   WooCommerce externally, or running a mu-plugin gateway, or actively
 *   configuring Stripe in a second tab. Refusing the publish would break
 *   legitimate workflows. The notice is a nudge, not a gate.
 * - The flag lives in a site-wide option (`learnomy_pending_gateway_warnings`),
 *   not user-meta. On multi-admin sites the second admin who logs in still
 *   sees the warning so the issue surfaces to whoever has Payments access.
 *   Per-user dismissal is tracked separately so a dismissal by one admin
 *   doesn't silently swallow the alert for the others.
 * - We listen on three hooks because the publish path is fan-out:
 *     learnomy_course_published — fired by Course_Service::publish()
 *     learnomy_course_created   — for first-save courses created with
 *                                  status=published in a single payload
 *     learnomy_course_updated   — for status transitions through the REST
 *                                  update_course path (instructor draft →
 *                                  admin publishes inline by editing status)
 *   `record_if_warranted()` is idempotent — multiple hooks landing on the
 *   same course just refresh the recorded timestamp.
 *
 * @package Learnomy
 */

namespace Learnomy\Admin;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Course;
use Learnomy\Services\Payment_Settings;

class Pricing_Gateway_Watcher {

	/**
	 * Option key storing courses we've flagged but not yet shown.
	 *
	 * Shape: array<int $course_id, array{recorded_at: string}>. Capped at
	 * 50 entries — at that scale the admin needs to fix the gateway, not
	 * accumulate more warnings.
	 */
	private const PENDING_OPTION = 'learnomy_pending_gateway_warnings';

	/**
	 * User-meta key storing which course warnings the current admin
	 * has already dismissed. Shape: int[] (course IDs).
	 */
	private const DISMISSED_META = 'learnomy_dismissed_gateway_warnings';

	/**
	 * Maximum number of pending entries we keep. Beyond this the oldest
	 * entry is dropped on insert — keeps the option payload bounded.
	 */
	private const MAX_PENDING = 50;

	public function register(): void {
		add_action( 'learnomy_course_published', array( $this, 'on_course_published' ), 10, 1 );
		add_action( 'learnomy_course_created', array( $this, 'on_course_saved' ), 10, 2 );
		add_action( 'learnomy_course_updated', array( $this, 'on_course_saved' ), 10, 2 );

		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_test_mode_notice' ) );
		add_action( 'wp_ajax_learnomy_dismiss_gateway_warning', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Hook target for the explicit publish lifecycle event.
	 */
	public function on_course_published( int $course_id ): void {
		$this->record_if_warranted( $course_id );
	}

	/**
	 * Hook target for create + update events. We only act when the saved
	 * payload includes `status = published` — non-publishing edits are
	 * uninteresting for the SS-3 contract.
	 *
	 * @param int                  $course_id Course row id.
	 * @param array<string, mixed> $data      Saved data payload.
	 */
	public function on_course_saved( int $course_id, array $data ): void {
		$status = isset( $data['status'] ) ? (string) $data['status'] : '';
		if ( 'published' !== $status ) {
			return;
		}
		$this->record_if_warranted( $course_id );
	}

	/**
	 * Core decision: is this a paid course that just went live without any
	 * way to take money? If yes, queue a warning; if no, ensure any stale
	 * entry for the same course is cleared so the notice doesn't keep
	 * nagging after the admin enables Stripe.
	 */
	public function record_if_warranted( int $course_id ): void {
		if ( $course_id <= 0 ) {
			return;
		}

		$course = Course::find( $course_id );
		if ( ! $course ) {
			return;
		}

		// Only courses that actually need a payment route warrant the
		// warning. Free and membership-only courses don't hit checkout.
		if ( ! Course::requires_purchase( $course ) ) {
			$this->clear_for_course( $course_id );
			return;
		}

		// Status must be live in the catalog. Draft and pending_review
		// courses don't expose a checkout URL.
		$status = $course->status ?? 'draft';
		if ( 'published' !== $status ) {
			$this->clear_for_course( $course_id );
			return;
		}

		// The actual gateway question. `is_purchasable()` is TRUE when a
		// customer can complete a purchase through ANY gateway, live OR test.
		// We only warn "cannot purchase" when there is genuinely NO path to buy
		// (not even the Test gateway) — a site running Test mode CAN take
		// (simulated) orders, so the "cannot purchase" copy would be a lie
		// there. Test mode gets its own, separate notice instead (SELL-02).
		if ( Payment_Settings::is_purchasable() ) {
			$this->clear_for_course( $course_id );
			return;
		}

		$pending               = $this->get_pending();
		$pending[ $course_id ] = array( 'recorded_at' => current_time( 'mysql', true ) );
		$pending               = $this->trim_pending( $pending );

		update_option( self::PENDING_OPTION, $pending, false );
	}

	/**
	 * Render the dismissible admin notice. Shown only on Learnomy admin
	 * pages so it doesn't bleed into unrelated screens (Posts, Tools, etc.).
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Scope to money-relevant screens only (dashboard, courses, commerce,
		// settings) — not every Learnomy page (Lessons, LTI, QTI…). ADMIN-01.
		if ( ! $this->is_commerce_relevant_screen() ) {
			return;
		}

		$pending = $this->get_pending();
		if ( empty( $pending ) ) {
			return;
		}

		$user_id   = get_current_user_id();
		$dismissed = $this->get_dismissed( $user_id );

		// Visible = pending minus what this admin already dismissed AND
		// minus stale rows whose course no longer needs the warning. We
		// resolve stale rows opportunistically here so the option self-heals
		// even if the dismissing admin never publishes another course.
		$visible = array();
		foreach ( $pending as $course_id => $row ) {
			$course_id = (int) $course_id;
			if ( in_array( $course_id, $dismissed, true ) ) {
				continue;
			}

			$course = Course::find( $course_id );
			if ( ! $course
				|| 'published' !== ( $course->status ?? '' )
				|| ! Course::requires_purchase( $course )
				|| Payment_Settings::is_purchasable()
			) {
				$this->clear_for_course( $course_id );
				continue;
			}

			$visible[ $course_id ] = $course;
		}

		if ( empty( $visible ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=learnomy-settings#payments' );
		$count        = count( $visible );

		// Build the body. One course = name it; many courses = summarise.
		if ( 1 === $count ) {
			$course  = reset( $visible );
			$message = sprintf(
				/* translators: %s: course title */
				esc_html__( 'The paid course %s is published but no payment gateway is configured. Customers cannot purchase it until you connect one.', 'learnomy' ),
				'<strong>' . esc_html( (string) $course->title ) . '</strong>'
			);
		} else {
			$message = sprintf(
				/* translators: %d: number of paid courses */
				esc_html( _n( '%d paid course is published but no payment gateway is configured. Customers cannot purchase until you connect one.', '%d paid courses are published but no payment gateway is configured. Customers cannot purchase until you connect one.', $count, 'learnomy' ) ),
				$count
			);
		}

		$ids_attr = implode( ',', array_map( 'intval', array_keys( $visible ) ) );
		$nonce    = wp_create_nonce( 'learnomy_dismiss_gateway_warning' );

		?>
		<div class="notice notice-warning is-dismissible learnomy-gateway-warning"
			data-course-ids="<?php echo esc_attr( $ids_attr ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<?php echo wp_kses( $message, array( 'strong' => array() ) ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'Configure payment gateway', 'learnomy' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Single "test payment mode is on" notice (SELL-02).
	 *
	 * Shown whenever the simulated Test gateway is enabled — one calm,
	 * professional banner, scoped to money-relevant screens (not per-course,
	 * not on every Learnomy page). We do NOT block test mode (it is a
	 * legitimate staging state, like WooCommerce's test/sandbox), and we do NOT
	 * try to guess the environment (`wp_get_environment_type()` is unset on most
	 * sites, so it would false-fire for the majority). The real safeguard is
	 * that Test orders are recorded at $0 and excluded from revenue — an owner
	 * who left test mode on sees $0 sales and TEST badges, a far more reliable
	 * signal than env detection.
	 */
	public function render_test_mode_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! $this->is_commerce_relevant_screen() ) {
			return;
		}
		if ( ! Payment_Settings::test_gateway_enabled() ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=learnomy-settings#payments' );
		?>
		<div class="notice notice-warning learnomy-test-mode-notice">
			<p>
				<strong><?php esc_html_e( 'Learnomy is in test payment mode.', 'learnomy' ); ?></strong>
				<?php esc_html_e( 'Orders placed with the Test option are simulated - no real charge is taken, and they are recorded at $0 and kept out of your revenue. Connect Stripe or PayPal (and turn off test mode) to accept live payments.', 'learnomy' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'Payment settings', 'learnomy' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * AJAX endpoint: record this admin's dismissal for the listed courses.
	 *
	 * Per-user dismissal means a sibling admin still sees the warning, but
	 * the dismissing admin gets a quiet workspace until a NEW paid course
	 * is published without a gateway.
	 */
	public function ajax_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'learnomy' ) ), 403 );
		}

		check_ajax_referer( 'learnomy_dismiss_gateway_warning', 'nonce' );

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Not signed in.', 'learnomy' ) ), 401 );
		}

		$raw_ids = isset( $_POST['course_ids'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['course_ids'] ) ) : '';
		$ids     = array_filter( array_map( 'intval', explode( ',', $raw_ids ) ) );

		if ( empty( $ids ) ) {
			wp_send_json_success();
		}

		$dismissed = $this->get_dismissed( $user_id );
		$dismissed = array_values( array_unique( array_merge( $dismissed, $ids ) ) );

		// Bound the dismissed list mirroring the pending cap so a long-lived
		// admin doesn't accumulate forever.
		if ( count( $dismissed ) > self::MAX_PENDING ) {
			$dismissed = array_slice( $dismissed, -self::MAX_PENDING );
		}

		update_user_meta( $user_id, self::DISMISSED_META, $dismissed );

		wp_send_json_success();
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Restrict the payment notices to the money-relevant admin screens —
	 * the Learnomy dashboard, course list/editor, Commerce pages, and Settings
	 * — instead of every page whose id contains `learnomy` (ADMIN-01). Lessons,
	 * Quizzes, LTI, QTI, SCORM, Learning Paths, etc. are not payment surfaces,
	 * so the gateway/test notices don't belong there.
	 */
	private function is_commerce_relevant_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}
		$id = (string) $screen->id;

		// The Learnomy dashboard (owner's home).
		if ( 'toplevel_page_learnomy' === $id ) {
			return true;
		}

		// Course + all Commerce/Settings surfaces (fragment match covers the
		// `{parent}_page_{slug}` screen-id shape WordPress generates).
		$fragments = array(
			'learnomy-course',        // courses, new-course, edit-course
			'learnomy-commerce',
			'learnomy-membership',
			'learnomy-subscription',
			'learnomy-coupon',
			'learnomy-earning',
			'learnomy-transaction',
			'learnomy-space-plans',
			'learnomy-stripe-payouts',
			'learnomy-settings',
		);
		foreach ( $fragments as $fragment ) {
			if ( false !== strpos( $id, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<int, array{recorded_at: string}>
	 */
	private function get_pending(): array {
		$raw = get_option( self::PENDING_OPTION, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $course_id => $row ) {
			$course_id = (int) $course_id;
			if ( $course_id <= 0 ) {
				continue;
			}
			$out[ $course_id ] = is_array( $row )
				? $row
				: array( 'recorded_at' => current_time( 'mysql', true ) );
		}
		return $out;
	}

	/**
	 * @param array<int, array{recorded_at: string}> $pending
	 * @return array<int, array{recorded_at: string}>
	 */
	private function trim_pending( array $pending ): array {
		if ( count( $pending ) <= self::MAX_PENDING ) {
			return $pending;
		}
		// Drop the oldest entries by recorded_at. We sort ascending and
		// keep the last MAX_PENDING — newest wins, oldest evicts.
		uasort(
			$pending,
			static function ( $a, $b ) {
				$ta = isset( $a['recorded_at'] ) ? (string) $a['recorded_at'] : '';
				$tb = isset( $b['recorded_at'] ) ? (string) $b['recorded_at'] : '';
				return strcmp( $ta, $tb );
			}
		);
		return array_slice( $pending, -self::MAX_PENDING, null, true );
	}

	/**
	 * @return int[]
	 */
	private function get_dismissed( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::DISMISSED_META, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', $raw ) ) );
	}

	/**
	 * Drop one course from the pending option (no-op if absent).
	 */
	private function clear_for_course( int $course_id ): void {
		$pending = $this->get_pending();
		if ( ! isset( $pending[ $course_id ] ) ) {
			return;
		}
		unset( $pending[ $course_id ] );
		update_option( self::PENDING_OPTION, $pending, false );
	}
}
