<?php
/**
 * Provisioning_Service — land externally-authenticated users with the right LMS
 * role + optional auto-enrollment on their first login.
 *
 * When a user arrives via a WP SSO plugin OR an LTI launch (E3), they must not
 * land as a blank account. This is thin glue, not an identity system:
 *
 *   - Producer: on the first login WP sees for a user, fire
 *     `learnomy_user_provisioned($user_id, $context)` exactly once (a user-meta
 *     guard makes it idempotent). LTI fires the same hook at launch time (E3),
 *     so both paths converge on one provisioning event.
 *   - Role mapper: give the user a default LMS role (filterable) unless they
 *     already have one — never downgrade an admin or an existing instructor.
 *   - Auto-enroll: optionally enroll into site-owner-configured courses
 *     (filterable; empty by default) via the `provision` enrollment source.
 *
 * No new table.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Enrollment;
use WP_User;

class Provisioning_Service {

	/** First-seen guard so the provisioning event fires once per user. */
	private const SEEN_META = 'learnomy_provisioned';

	/** The LMS roles that count as "already provisioned" for the role mapper. */
	private const LMS_ROLES = array( 'lrn_student', 'lrn_instructor' );

	public function register_hooks(): void {
		// Producer: first login WP sees for this user.
		add_action( 'wp_login', array( $this, 'on_login' ), 10, 2 );

		// Consumers of the provisioning event (also fired by LTI at launch).
		add_action( 'learnomy_user_provisioned', array( $this, 'assign_role' ), 10, 2 );
		add_action( 'learnomy_user_provisioned', array( $this, 'auto_enroll' ), 20, 2 );

		// Authorize the system-initiated `provision` enrollment source (a
		// configured entitlement, like admin `manual` — bypasses the payment gate).
		add_filter( 'learnomy_validate_enrollment_source', array( $this, 'authorize_provision_source' ), 10, 5 );
	}

	/**
	 * Return the user id for an email, creating an lrn_student if none exists.
	 *
	 * For "sold externally" flows (Closed courses, CSV enroll) the buyer bought
	 * on a third-party platform and may not have an account here yet — never 404
	 * a real buyer. New accounts get the set-password / welcome email via
	 * `wp_new_user_notification()` (the same path the space-invite flow uses).
	 * Firing `learnomy_user_provisioned` keeps role assignment consistent with
	 * the SSO/LTI provisioning path. Existing users are returned as-is.
	 *
	 * @param string              $email The buyer email.
	 * @param array<string,mixed> $args  Optional: `full_name`, `source` label.
	 * @return int|\WP_Error User id, or WP_Error on invalid email / create failure.
	 */
	public function ensure_user_by_email( string $email, array $args = array() ): int|\WP_Error {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'learnomy_invalid_email', __( 'A valid email address is required.', 'learnomy' ), array( 'status' => 400 ) );
		}

		$existing = get_user_by( 'email', $email );
		if ( $existing instanceof WP_User ) {
			return (int) $existing->ID;
		}

		// Build a unique login from the email local-part.
		$base  = sanitize_user( current( explode( '@', $email ) ), true );
		$login = '' !== $base ? $base : 'user';
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . $i;
			++$i;
		}

		$full_name = sanitize_text_field( (string) ( $args['full_name'] ?? '' ) );
		$user_id   = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24, true ),
				'display_name' => '' !== $full_name ? $full_name : $login,
				'role'         => 'lrn_student',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Set-password + welcome email — the buyer lands able to log in.
		wp_new_user_notification( $user_id, null, 'user' );

		// Converge on the one provisioning event so role/enroll listeners run.
		do_action( 'learnomy_user_provisioned', (int) $user_id, array_merge( array( 'source' => 'ensure_user_by_email' ), $args ) );

		return (int) $user_id;
	}

	/**
	 * Fire `learnomy_user_provisioned` once, on the first login Learnomy sees.
	 */
	public function on_login( string $user_login, WP_User $user ): void {
		if ( ! $user instanceof WP_User || ! $user->ID ) {
			return;
		}
		if ( get_user_meta( $user->ID, self::SEEN_META, true ) ) {
			return;
		}
		update_user_meta( $user->ID, self::SEEN_META, current_time( 'mysql', true ) );

		/**
		 * A user was provisioned into the LMS (first login here, or an external
		 * launch). Consumers assign a role + optional enrollment.
		 *
		 * @param int   $user_id The user.
		 * @param array $context source ('login' | 'lti' | …) + details.
		 */
		do_action(
			'learnomy_user_provisioned',
			(int) $user->ID,
			array(
				'source'     => 'login',
				'user_login' => $user_login,
			)
		);
	}

	/**
	 * Give a provisioned user a default LMS role — never downgrade an admin or a
	 * user who already has an LMS role.
	 *
	 * @param int                 $user_id Provisioned user.
	 * @param array<string,mixed> $context
	 */
	public function assign_role( int $user_id, array $context ): void {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		if ( user_can( $user, 'manage_options' ) ) {
			return;
		}
		if ( array_intersect( (array) $user->roles, self::LMS_ROLES ) ) {
			return;
		}

		/**
		 * The role a newly provisioned user receives. Return '' to skip.
		 *
		 * @param string $role    Default `lrn_student`.
		 * @param int    $user_id The user.
		 * @param array  $context Provisioning context.
		 */
		$role = (string) apply_filters( 'learnomy_provision_role', 'lrn_student', $user_id, $context );
		if ( '' !== $role ) {
			$user->add_role( $role );
		}
	}

	/**
	 * Optionally auto-enroll a provisioned user into configured courses. Empty by
	 * default — opt in via `learnomy_provision_enrollments`.
	 *
	 * @param int                 $user_id Provisioned user.
	 * @param array<string,mixed> $context
	 */
	public function auto_enroll( int $user_id, array $context ): void {
		/**
		 * Course IDs to auto-enroll a provisioned user into (empty = none).
		 *
		 * @param int[] $course_ids
		 * @param int   $user_id
		 * @param array $context
		 */
		$course_ids = (array) apply_filters( 'learnomy_provision_enrollments', array(), $user_id, $context );
		$course_ids = array_unique( array_filter( array_map( 'intval', $course_ids ) ) );
		if ( ! $course_ids ) {
			return;
		}

		$service = new Enrollment_Service();
		foreach ( $course_ids as $course_id ) {
			if ( ! Enrollment::is_enrolled( $user_id, $course_id ) ) {
				$service->enroll( $user_id, $course_id, 'provision' );
			}
		}
	}

	/**
	 * Authorize the `provision` enrollment source (return null = granted); pass
	 * every other source through untouched.
	 *
	 * @param mixed  $decision Sentinel/decision from Free.
	 * @param string $source   The source under validation.
	 * @return mixed
	 */
	public function authorize_provision_source( $decision, string $source, int $user_id, int $course_id, string $access_type ) {
		unset( $user_id, $course_id, $access_type );
		return 'provision' === $source ? null : $decision;
	}
}
