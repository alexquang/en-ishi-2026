<?php
/**
 * Setup wizard service — orchestrates settings persistence, payment provider
 * configuration, and demo data seeding for the install wizard and the
 * Settings page demo controls.
 *
 * Replaces the legacy Setup_Handler AJAX shim. Every method accepts a
 * pre-sanitized array, so callers (REST controllers, CLI commands, tests)
 * can drive the same orchestration without touching $_POST.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Admin\Ajax\Demo_Seeder;
use Learnomy\Modules\Module_Kernel;

class Setup_Service {

	/**
	 * Allowed wizard currencies. Mirrors the dropdown rendered in step 2.
	 *
	 * @var string[]
	 */
	private const ALLOWED_CURRENCIES = array(
		'USD',
		'EUR',
		'GBP',
		'CAD',
		'AUD',
		'INR',
		'BRL',
		'MXN',
		'JPY',
		'SGD',
		'ZAR',
		'NGN',
		'IDR',
	);

	/**
	 * Allowed enrollment modes for the wizard.
	 *
	 * @var string[]
	 */
	private const ALLOWED_ENROLLMENT_MODES = array( 'open', 'approval' );

	/**
	 * Allowed payment modes accepted by the wizard step 3.
	 *
	 * @var string[]
	 */
	private const ALLOWED_PAYMENT_MODES = array( 'sandbox', 'stripe_test', 'deferred' );

	// Preset slugs come from Module_Kernel::presets(). The wizard sends one
	// string slug; we validate it against the kernel's registry instead of
	// keeping a separate allowlist here so adding a preset stays single-
	// edit.

	// -------------------------------------------------------------------------
	// Public entry points
	// -------------------------------------------------------------------------

	/**
	 * Persist the wizard's general settings (slug, currency, enrollment, use
	 * cases) and optionally mark setup as complete.
	 *
	 * @param array $payload Sanitized payload — see normalize_settings() for
	 *                       accepted keys.
	 * @return array {
	 *     @type array $settings Resolved settings stored on the option.
	 *     @type bool  $complete Whether learnomy_setup_complete is now true.
	 * }
	 */
	public function save_settings( array $payload ): array {
		$settings      = $this->normalize_settings( $payload );
		$mark_complete = ! array_key_exists( 'mark_complete', $payload )
			|| (bool) $payload['mark_complete'];

		$stored = (array) get_option( 'learnomy_settings', array() );

		$stored['base_slug']       = $settings['base_slug'];
		$stored['currency']        = $settings['currency'];
		$stored['enrollment_mode'] = $settings['enrollment_mode'];

		update_option( 'learnomy_settings', $stored );

		if ( $mark_complete ) {
			update_option( 'learnomy_setup_complete', true );
			do_action( 'learnomy_setup_completed', $settings );
		}

		// Apply the wizard preset. The preset is a single decisive choice
		// that maps to a curated module list — see Module_Kernel::presets().
		// apply_preset() writes the enabled-module option in one go so the
		// admin sidebar reshapes on the next request.
		if ( ! empty( $settings['preset'] ) ) {
			Module_Kernel::instance()->apply_preset( $settings['preset'] );
			update_option( 'learnomy_setup_preset', $settings['preset'] );
		}

		// Rebuild rewrite rules so the new catalog slug is live immediately.
		flush_rewrite_rules( false );

		return array(
			'settings' => $stored,
			'complete' => (bool) get_option( 'learnomy_setup_complete', false ),
		);
	}

	/**
	 * Persist the step-3 payment choice. Validates Stripe test keys when the
	 * caller picks the stripe_test mode.
	 *
	 * @param array $payload {
	 *     @type string $payment_mode                 sandbox | stripe_test | deferred.
	 *     @type string $stripe_test_publishable_key  Required when mode = stripe_test.
	 *     @type string $stripe_test_secret_key       Required when mode = stripe_test.
	 * }
	 * @return array{provider:string, configured:bool}|\WP_Error
	 */
	public function save_payment( array $payload ): array|\WP_Error {
		$raw_mode     = isset( $payload['payment_mode'] ) ? sanitize_key( (string) $payload['payment_mode'] ) : 'sandbox';
		$payment_mode = in_array( $raw_mode, self::ALLOWED_PAYMENT_MODES, true ) ? $raw_mode : 'sandbox';

		$stored                 = (array) get_option( 'learnomy_settings', array() );
		$stored['payment_mode'] = $payment_mode;
		$configured             = false;

		if ( 'sandbox' === $payment_mode ) {
			$stored['enable_test_gateway'] = true;
			$configured                    = true;
		}

		if ( 'stripe_test' === $payment_mode ) {
			$pk = isset( $payload['stripe_test_publishable_key'] )
				? sanitize_text_field( (string) $payload['stripe_test_publishable_key'] )
				: '';
			$sk = isset( $payload['stripe_test_secret_key'] )
				? sanitize_text_field( (string) $payload['stripe_test_secret_key'] )
				: '';

			if ( '' === $pk || 0 !== strpos( $pk, 'pk_test_' ) ) {
				return new \WP_Error(
					'learnomy_invalid_stripe_publishable_key',
					__( 'Enter a Stripe test publishable key (starts with pk_test_).', 'learnomy' ),
					array( 'status' => 422 )
				);
			}

			if ( '' === $sk || 0 !== strpos( $sk, 'sk_test_' ) ) {
				return new \WP_Error(
					'learnomy_invalid_stripe_secret_key',
					__( 'Enter a Stripe test secret key (starts with sk_test_).', 'learnomy' ),
					array( 'status' => 422 )
				);
			}

			$stored['enable_stripe']               = true;
			$stored['stripe_test_publishable_key'] = $pk;
			$stored['stripe_test_secret_key']      = $sk;
			$configured                            = true;
		}

		if ( 'deferred' === $payment_mode ) {
			$configured = false;
		}

		update_option( 'learnomy_settings', $stored );

		return array(
			'provider'   => $payment_mode,
			'configured' => $configured,
		);
	}

	/**
	 * Seed the demo dataset, persist the manifest, and return the wizard
	 * payload (stats + previews).
	 *
	 * @param int $admin_id Current admin user ID, used as fallback author.
	 * @return array{manifest:array, stats:array, previews:array}|\WP_Error
	 */
	public function seed_demo( int $admin_id ): array|\WP_Error {
		$manifest = Demo_Seeder::seed( $admin_id );

		if ( empty( $manifest ) || ! empty( $manifest['_error'] ) ) {
			$reason = isset( $manifest['_error'] ) && '' !== $manifest['_error']
				? (string) $manifest['_error']
				: __( 'Demo data could not be created. Check the error log for details.', 'learnomy' );

			return new \WP_Error(
				'learnomy_seed_failed',
				$reason,
				array( 'status' => 500 )
			);
		}

		$stats    = isset( $manifest['_stats'] ) ? (array) $manifest['_stats'] : array();
		$previews = isset( $manifest['_previews'] ) ? (array) $manifest['_previews'] : array();

		// Stats and previews are wizard-only — strip before persisting so the
		// stored manifest only contains IDs that cleanup() can reverse.
		unset( $manifest['_stats'], $manifest['_previews'] );
		update_option( 'learnomy_demo_data', $manifest, false );

		do_action( 'learnomy_demo_data_seeded', $manifest, $admin_id );

		return array(
			'manifest' => $manifest,
			'stats'    => $stats,
			'previews' => $previews,
		);
	}

	/**
	 * Remove demo content seeded by seed_demo(). Falls back to signature-based
	 * cleanup when no manifest is stored — covers DB imports and plugin
	 * updates that cleared the option but left the rows behind.
	 *
	 * @param int $reassign_to User ID to reassign any leftover content to.
	 *                         Defaults to the current user.
	 * @return array{cleaned:bool, counts:array<string,int>}
	 */
	public function cleanup_demo( int $reassign_to = 0 ): array {
		if ( 0 === $reassign_to ) {
			$reassign_to = get_current_user_id();
		}

		$manifest = (array) get_option( 'learnomy_demo_data', array() );

		if ( ! empty( $manifest ) ) {
			Demo_Seeder::cleanup( $manifest, $reassign_to );
			delete_option( 'learnomy_demo_data' );

			do_action( 'learnomy_demo_data_cleaned', $manifest, $reassign_to );

			return array(
				'cleaned' => true,
				'counts'  => array(),
			);
		}

		// No manifest — fall back to signature-based cleanup so the action
		// still works after a DB import, a plugin update that cleared the
		// option, or any seed() run outside the wizard.
		$counts = Demo_Seeder::cleanup_by_signature( $reassign_to );

		do_action( 'learnomy_demo_data_cleaned', array(), $reassign_to );

		return array(
			'cleaned' => array_sum( $counts ) > 0,
			'counts'  => $counts,
		);
	}

	/**
	 * Destructive reset — wipe demo content and reseed it in one operation.
	 *
	 * @param int $admin_id Current admin user ID, used for both the cleanup
	 *                      reassignment and the new seeder author.
	 * @return array{manifest:array, stats:array, previews:array}|\WP_Error
	 */
	public function reset_demo( int $admin_id ): array|\WP_Error {
		$this->cleanup_demo( $admin_id );

		return $this->seed_demo( $admin_id );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Whitelist and coerce wizard settings into the canonical shape used by
	 * persist_settings(). Unknown values fall back to defaults instead of
	 * errors — the wizard is best-effort.
	 *
	 * @param array $payload Raw payload (already sanitized for type, but not
	 *                       value-validated).
	 * @return array{base_slug:string, currency:string, enrollment_mode:string, preset:string}
	 */
	private function normalize_settings( array $payload ): array {
		$raw_slug  = isset( $payload['base_slug'] ) ? sanitize_title( (string) $payload['base_slug'] ) : 'courses';
		$base_slug = ( $raw_slug && preg_match( '/^[a-z0-9\-]+$/', $raw_slug ) ) ? $raw_slug : 'courses';

		$raw_currency = isset( $payload['currency'] ) ? strtoupper( sanitize_text_field( (string) $payload['currency'] ) ) : 'USD';
		$currency     = in_array( $raw_currency, self::ALLOWED_CURRENCIES, true ) ? $raw_currency : 'USD';

		$raw_mode        = isset( $payload['enrollment_mode'] ) ? sanitize_key( (string) $payload['enrollment_mode'] ) : 'open';
		$enrollment_mode = in_array( $raw_mode, self::ALLOWED_ENROLLMENT_MODES, true ) ? $raw_mode : 'open';

		$raw_preset    = isset( $payload['preset'] ) ? sanitize_key( (string) $payload['preset'] ) : '';
		$known_presets = array_keys( Module_Kernel::presets() );
		$preset        = in_array( $raw_preset, $known_presets, true ) ? $raw_preset : '';

		return array(
			'base_slug'       => $base_slug,
			'currency'        => $currency,
			'enrollment_mode' => $enrollment_mode,
			'preset'          => $preset,
		);
	}
}
