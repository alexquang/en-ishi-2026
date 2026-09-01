<?php
/**
 * Onboarding state resolver — canonical step catalog + live completion detection.
 *
 * Pure resolver. Reads options, user meta, and model counts to compute the
 * onboarding progress. No output, no AJAX — the Onboarding_Handler wraps
 * the mutating methods and the dashboard partial reads the resolved map.
 *
 * @package Learnomy
 */

namespace Learnomy\Admin;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\CertificateTemplate;
use Learnomy\Models\Course;
use Learnomy\Models\InstructorApplication;

/**
 * Pure resolver for the Getting Started onboarding panel.
 *
 * Owns the canonical step catalog, live completion detection, and skip
 * bookkeeping. Mutations are always routed through the public static
 * methods so the panel, the handler, and any future consumer stay in sync.
 */
class Onboarding_State {

	private const OPTION_SKIPPED = 'learnomy_onboarding_skipped';
	private const USER_META_SEEN = 'learnomy_onboarding_seen_dashboard';

	/**
	 * Canonical step definitions — order matters for display.
	 *
	 * Each entry has: icon (Lucide), title, description, cta label, url (absolute
	 * or empty), completion_rule callable, skippable bool.
	 *
	 * @return array<string, array{icon: string, title: string, description: string, cta: string, url: string, completion_rule: callable, skippable: bool}>
	 */
	public static function get_steps(): array {
		$steps = array(
			'setup_wizard'          => array(
				'icon'            => 'rocket',
				'title'           => __( 'Complete initial setup', 'learnomy' ),
				'description'     => __( 'You configured slug, currency, and enrollment mode.', 'learnomy' ),
				'cta'             => __( 'View settings', 'learnomy' ),
				'url'             => admin_url( 'admin.php?page=learnomy-settings' ),
				'completion_rule' => static fn(): bool => (bool) get_option( 'learnomy_setup_complete' ),
				'skippable'       => false,
			),
			'explore_demo'          => array(
				'icon'            => 'compass',
				'title'           => __( 'Explore your dashboard', 'learnomy' ),
				'description'     => __( 'You have landed on the admin dashboard.', 'learnomy' ),
				'cta'             => '',
				'url'             => '',
				'completion_rule' => static fn(): bool => '1' === (string) get_user_meta( get_current_user_id(), self::USER_META_SEEN, true ),
				'skippable'       => false,
			),
			'payment_gateway'       => array(
				'icon'            => 'credit-card',
				'title'           => __( 'Connect a payment gateway', 'learnomy' ),
				'description'     => __( 'Accept payments via Stripe, PayPal, or WooCommerce.', 'learnomy' ),
				'cta'             => __( 'Configure', 'learnomy' ),
				// Routes through the enable-and-configure action so the
				// Payments module is turned on (when it is off) before the
				// settings page tries to render its #payments section. A
				// plain deep-link to #payments fell back to the General tab
				// silently when the module gating dropped the Payments nav
				// item — see SS-5 in the 2026-05-16 customer journey walk.
				'url'             => wp_nonce_url(
					admin_url( 'admin.php?page=learnomy&learnomy_action=enable-payments-and-configure' ),
					'learnomy_enable_payments',
					'_lrn_nonce'
				),
				'completion_rule' => array( self::class, 'is_payment_gateway_configured' ),
				'skippable'       => false,
			),
			'customize_certificate' => array(
				'icon'            => 'award',
				'title'           => __( 'Customize your certificate', 'learnomy' ),
				'description'     => __( 'Upload your logo, signature, and brand colors.', 'learnomy' ),
				'cta'             => __( 'Customize', 'learnomy' ),
				'url'             => admin_url( 'admin.php?page=learnomy-certificate-designer' ),
				'completion_rule' => array( self::class, 'has_customized_certificate' ),
				'skippable'       => true,
			),
			'first_course'          => array(
				'icon'            => 'book-open',
				'title'           => __( 'Create Your First Course', 'learnomy' ),
				'description'     => __( 'Build a real course your students can enroll in.', 'learnomy' ),
				'cta'             => __( 'Create Course', 'learnomy' ),
				'url'             => admin_url( 'admin.php?page=learnomy-new-course' ),
				'completion_rule' => array( self::class, 'has_non_demo_course' ),
				'skippable'       => false,
			),
			'instructors'           => array(
				'icon'            => 'user-plus',
				'title'           => __( 'Invite instructors', 'learnomy' ),
				'description'     => __( 'Review applications or add instructors to courses.', 'learnomy' ),
				'cta'             => __( 'Review', 'learnomy' ),
				'url'             => admin_url( 'admin.php?page=learnomy-applications' ),
				'completion_rule' => array( self::class, 'has_real_instructor' ),
				'skippable'       => true,
			),
			'remove_demo'           => array(
				'icon'            => 'trash-2',
				'title'           => __( 'Remove Demo Content', 'learnomy' ),
				'description'     => __( 'Delete sample data when you are ready to go live.', 'learnomy' ),
				'cta'             => __( 'Remove demo', 'learnomy' ),
				'url'             => '',
				'completion_rule' => static fn(): bool => empty( get_option( 'learnomy_demo_data' ) ),
				'skippable'       => true,
			),
		);

		/*
		 * Two steps stay pending BECAUSE the only content on the site is demo
		 * content, and neither said so.
		 *
		 * The owner loads the demo academy, the dashboard reports "4 Total
		 * Courses", and the checklist still asks them to create their first
		 * course. From their chair the plugin is contradicting itself, and the
		 * rational responses are a support ticket or deleting the demo content
		 * to make the checklist agree with the dashboard - destroying the thing
		 * they loaded in order to evaluate (Basecamp 10171334428).
		 *
		 * The gates themselves are RIGHT and are deliberately left alone:
		 * has_non_demo_course() and has_real_instructor() both exclude the demo
		 * manifest on purpose, because marking onboarding complete for someone
		 * who has never opened the course builder teaches them nothing. The
		 * defect is silence, not logic.
		 *
		 * The panel already holds the fact: "Remove Demo Content" is pending
		 * from this same option, in this same list. It just never used it. So
		 * the explanation is added here, in the one place both resolve() and
		 * the view read, rather than as a second rule in the template.
		 */
		$demo = get_option( 'learnomy_demo_data', array() );
		if ( ! empty( $demo ) ) {
			$demo_courses = is_array( $demo ) && ! empty( $demo['course_ids'] ) ? count( (array) $demo['course_ids'] ) : 0;

			if ( $demo_courses > 0 && isset( $steps['first_course'] ) ) {
				$steps['first_course']['description'] = sprintf(
					/* translators: %d: number of demo courses currently installed. */
					_n(
						'You have %d demo course. Build one of your own: demo content does not count here, and you will remove it before going live.',
						'You have %d demo courses. Build one of your own: demo content does not count here, and you will remove it before going live.',
						$demo_courses,
						'learnomy'
					),
					$demo_courses
				);
			}

			if ( isset( $steps['instructors'] ) ) {
				$steps['instructors']['description'] = __(
					'Add a real instructor. The demo academy ships with its own, and those do not count here.',
					'learnomy'
				);
			}
		}

		return apply_filters( 'learnomy_onboarding_steps', $steps );
	}

	/**
	 * Merge stored skips + live completion detection.
	 *
	 * @return array<string, string> Map of step_id => 'pending' | 'completed' | 'skipped'.
	 */
	public static function resolve(): array {
		$skipped  = self::get_skipped_map();
		$resolved = array();

		foreach ( self::get_steps() as $step_id => $step ) {
			$is_done = false;

			if ( is_callable( $step['completion_rule'] ) ) {
				try {
					$is_done = (bool) call_user_func( $step['completion_rule'] );
				} catch ( \Throwable $e ) {
					$is_done = false;
				}
			}

			if ( $is_done ) {
				$resolved[ $step_id ] = 'completed';
				continue;
			}

			if ( ! empty( $skipped[ $step_id ] ) && ! empty( $step['skippable'] ) ) {
				$resolved[ $step_id ] = 'skipped';
				continue;
			}

			$resolved[ $step_id ] = 'pending';
		}

		return $resolved;
	}

	/**
	 * Persist a skip for one skippable step.
	 *
	 * @param string $step_id Step identifier.
	 * @return bool True when the step exists and is skippable.
	 */
	public static function mark_skipped( string $step_id ): bool {
		$steps = self::get_steps();
		if ( empty( $steps[ $step_id ] ) || empty( $steps[ $step_id ]['skippable'] ) ) {
			return false;
		}

		$skipped             = self::get_skipped_map();
		$skipped[ $step_id ] = time();
		update_option( self::OPTION_SKIPPED, $skipped, false );
		return true;
	}

	/**
	 * Reverse a previous skip. Always succeeds (idempotent).
	 *
	 * @param string $step_id Step identifier.
	 * @return bool
	 */
	public static function undo_skip( string $step_id ): bool {
		$skipped = self::get_skipped_map();
		if ( ! isset( $skipped[ $step_id ] ) ) {
			return true;
		}
		unset( $skipped[ $step_id ] );
		update_option( self::OPTION_SKIPPED, $skipped, false );
		return true;
	}

	/**
	 * True when every step is completed or skipped.
	 *
	 * @param array<string, string> $resolved Map of step_id => status.
	 * @return bool
	 */
	public static function is_all_done( array $resolved ): bool {
		foreach ( $resolved as $status ) {
			if ( 'pending' === $status ) {
				return false;
			}
		}
		return ! empty( $resolved );
	}

	/**
	 * Count steps that are completed or skipped (for progress display).
	 *
	 * @param array<string, string> $resolved Map of step_id => status.
	 * @return int
	 */
	public static function count_complete( array $resolved ): int {
		$n = 0;
		foreach ( $resolved as $status ) {
			if ( 'completed' === $status || 'skipped' === $status ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Total number of steps in the canonical catalog.
	 *
	 * @return int
	 */
	public static function count_total(): int {
		return count( self::get_steps() );
	}

	/**
	 * Progress percentage (0–100) for the resolved state.
	 *
	 * @param array<string, string> $resolved Map of step_id => status.
	 * @return int
	 */
	public static function progress_percent( array $resolved ): int {
		$total = self::count_total();
		if ( $total < 1 ) {
			return 0;
		}
		return (int) round( ( self::count_complete( $resolved ) / $total ) * 100 );
	}

	/**
	 * Mark the dashboard-seen flag for step 2.
	 *
	 * Called by the onboarding partial the first time it renders.
	 *
	 * @param int $user_id Current user ID.
	 * @return void
	 */
	public static function mark_dashboard_seen( int $user_id ): void {
		if ( $user_id < 1 ) {
			return;
		}
		if ( '1' === (string) get_user_meta( $user_id, self::USER_META_SEEN, true ) ) {
			return;
		}
		update_user_meta( $user_id, self::USER_META_SEEN, '1' );
	}

	// ── Completion rules (public so callables resolve cleanly) ───────────────

	/**
	 * True when any supported gateway is enabled AND its secret credential is set.
	 *
	 * Stripe and PayPal completion checks delegate to Payment_Settings so the
	 * same mode-aware resolution used by the adapters and the checkout view
	 * decides whether the dashboard task is "completed". Before the 2026-05-16
	 * payment-onboarding fix this read the legacy flat
	 * `learnomy_stripe_secret_key` / `learnomy_paypal_secret` options and
	 * therefore showed "pending" forever on freshly configured sites.
	 */
	public static function is_payment_gateway_configured(): bool {
		$settings = get_option( 'learnomy_settings', array() );
		if ( ! is_array( $settings ) ) {
			return false;
		}

		if ( \Learnomy\Services\Payment_Settings::stripe_enabled() ) {
			return true;
		}

		if ( \Learnomy\Services\Payment_Settings::paypal_enabled() ) {
			return true;
		}

		// WooCommerce adapter — its enablement is the flag itself.
		if ( ! empty( $settings['enable_woocommerce'] ) ) {
			return true;
		}

		return false;
	}

	/**
	 * True when the admin has edited/created a certificate template (not just the seeded default).
	 */
	public static function has_customized_certificate(): bool {
		if ( ! class_exists( CertificateTemplate::class ) ) {
			return false;
		}

		try {
			$templates = CertificateTemplate::where( array(), 'id', 'ASC', 50 );
		} catch ( \Throwable $e ) {
			return false;
		}

		foreach ( $templates as $tpl ) {
			$created    = isset( $tpl->created_at ) ? (string) $tpl->created_at : '';
			$updated    = isset( $tpl->updated_at ) ? (string) $tpl->updated_at : '';
			$created_by = isset( $tpl->created_by ) ? (int) $tpl->created_by : 0;

			if ( $created_by > 0 && $updated && $created && $updated > $created ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * True when at least one non-demo course exists.
	 */
	public static function has_non_demo_course(): bool {
		if ( ! class_exists( Course::class ) ) {
			return false;
		}

		try {
			$total = (int) Course::count();
		} catch ( \Throwable $e ) {
			return false;
		}

		if ( $total < 1 ) {
			return false;
		}

		$manifest = get_option( 'learnomy_demo_data', array() );
		$demo_ids = array();
		if ( is_array( $manifest ) && ! empty( $manifest['course_ids'] ) ) {
			foreach ( (array) $manifest['course_ids'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$demo_ids[] = $id;
				}
			}
		}

		return $total > count( $demo_ids );
	}

	/**
	 * True when an approved application exists OR an instructor user outside the demo set.
	 */
	public static function has_real_instructor(): bool {
		if ( class_exists( InstructorApplication::class ) ) {
			try {
				if ( InstructorApplication::count( array( 'status' => 'approved' ) ) > 0 ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				// Fall through to user check.
				unset( $e );
			}
		}

		$manifest      = get_option( 'learnomy_demo_data', array() );
		$demo_user_ids = array();
		if ( is_array( $manifest ) && ! empty( $manifest['user_ids'] ) ) {
			foreach ( (array) $manifest['user_ids'] as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$demo_user_ids[] = $id;
				}
			}
		}

		$users = get_users(
			array(
				'role'    => 'lrn_instructor',
				'fields'  => 'ID',
				'number'  => 50,
				'exclude' => $demo_user_ids,
			)
		);

		return ! empty( $users );
	}

	// ── Internals ────────────────────────────────────────────────────────────

	/**
	 * Read the persisted skip map.
	 *
	 * @return array<string, int> Map of step_id => unix timestamp of skip.
	 */
	private static function get_skipped_map(): array {
		$map = get_option( self::OPTION_SKIPPED, array() );
		return is_array( $map ) ? $map : array();
	}
}
