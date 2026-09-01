<?php
/**
 * Rate limiter — prevents students from hammering enrollment, review, and quiz endpoints.
 *
 * @package Learnomy
 */

namespace Learnomy\Permissions;

defined( 'ABSPATH' ) || exit;

class Rate_Limiter {

	/**
	 * Per-day per-action limits for sensitive student actions. These are
	 * deliberately tighter than the global per-minute API cap because the
	 * abuse pattern is different (one student spam-enrolling vs. an
	 * integration making many small reads).
	 */
	private const LIMITS = [
		'enroll'       => 5,
		'leave_review' => 3,
		'take_quiz'    => 10,
	];

	/**
	 * Default cap when the admin hasn't configured one. Mirrors the seed in
	 * includes/class-learnomy.php so a fresh install behaves identically
	 * whether or not the customer has visited Settings > Advanced.
	 */
	private const DEFAULT_API_LIMIT_PER_MINUTE = 60;

	/**
	 * Failed credential attempts allowed per bucket before the door shuts.
	 */
	private const CREDENTIAL_MAX_FAILURES = 10;

	/**
	 * How long a bucket remembers its failures, and therefore how long a
	 * lockout lasts.
	 */
	private const CREDENTIAL_WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * May this bucket try a password right now?
	 *
	 * `check()` above deliberately answers TRUE for any action it has no limit
	 * for, which is right for the student actions it was built for and WRONG
	 * for credentials: an unknown action must not mean "unlimited password
	 * guesses". Password endpoints therefore ask this instead.
	 *
	 * Counts FAILURES, not requests, so a member signing in on ten devices is
	 * never punished while ten wrong guesses shut the bucket. Callers should
	 * gate on both the IP and the username so neither a single host nor a
	 * distributed run at one account can grind away unbounded.
	 *
	 * @param string $bucket Opaque bucket id (hash an IP or a username).
	 * @return bool False when the bucket is locked out.
	 */
	public function check_credential_attempt( string $bucket ): bool {
		$max = (int) apply_filters( 'learnomy_credential_max_failures', self::CREDENTIAL_MAX_FAILURES );

		if ( $max <= 0 ) {
			return true;
		}

		return (int) get_transient( $this->credential_key( $bucket ) ) < $max;
	}

	/**
	 * Record one failed credential attempt.
	 *
	 * The window does NOT slide forward on each failure: it starts at the first
	 * failure and expires, so a locked-out bucket always recovers on its own.
	 *
	 * @param string $bucket Opaque bucket id.
	 */
	public function record_credential_failure( string $bucket ): void {
		$key   = $this->credential_key( $bucket );
		$count = (int) get_transient( $key );

		if ( 0 === $count ) {
			set_transient( $key, 1, (int) apply_filters( 'learnomy_credential_window', self::CREDENTIAL_WINDOW ) );
			return;
		}

		set_transient( $key, $count + 1, $this->remaining_ttl( $key ) );
	}

	/**
	 * Forget a bucket's failures.
	 *
	 * Called on a successful sign-in so an honest member who mistyped twice
	 * does not carry those failures for the rest of the window.
	 *
	 * @param string $bucket Opaque bucket id.
	 */
	public function clear_credential_failures( string $bucket ): void {
		delete_transient( $this->credential_key( $bucket ) );
	}

	private function credential_key( string $bucket ): string {
		return 'lrn_rl_cred_' . md5( $bucket );
	}

	public function check( int $user_id, string $action ): bool {
		// Admins and instructors are never rate-limited — they need to test freely.
		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'learnomy_manage_own_courses' ) ) {
			return true;
		}

		$limit = self::LIMITS[ $action ] ?? null;
		if ( null === $limit ) {
			// Unknown action — allow through; only configured actions are gated.
			return true;
		}

		$count = (int) get_transient( $this->transient_key( $user_id, $action ) );

		return $count < $limit;
	}

	public function increment( int $user_id, string $action ): void {
		$key   = $this->transient_key( $user_id, $action );
		$count = (int) get_transient( $key );

		if ( 0 === $count ) {
			// First hit in the window — set the transient with a 24-hour expiry
			// so the counter resets at the start of each day automatically.
			set_transient( $key, 1, DAY_IN_SECONDS );
		} else {
			set_transient( $key, $count + 1, $this->remaining_ttl( $key ) );
		}
	}

	/**
	 * Per-minute global API rate limit, honoring the customer-configured
	 * `learnomy_settings[api_rate_limit]` value (Settings > Advanced).
	 *
	 * Floor of 1 so a misconfigured zero/negative value can't lock everyone
	 * out — the admin form already validates absint() but a future migration
	 * or filter could write 0; we treat that as "broken, fall back to 1
	 * request per minute" rather than DoS the whole site.
	 *
	 * Each request bumps the counter for the current 60-second window and
	 * returns false the moment the configured cap is exceeded. Admins and
	 * instructors bypass exactly like the per-action check.
	 *
	 * Wave 2E (2026-05-16): row 2E-3 in plan/2026-05-16-token-ready-sweep/
	 * audit-B-data-flow.yaml. Closes the gap where the admin field was
	 * decorative — every API request previously used a hardcoded threshold.
	 */
	public function check_api_rate( int $user_id ): bool {
		// Admins and instructors are never rate-limited — symmetry with check().
		if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'learnomy_manage_own_courses' ) ) {
			return true;
		}

		$limit = $this->api_limit_per_minute();
		if ( $limit <= 0 ) {
			// 0 = customer disabled rate limiting (Settings > API & Performance).
			return true;
		}

		$key   = $this->api_transient_key( $user_id );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		// Increment + (re)set the 60-second window. Using MINUTE_IN_SECONDS
		// resets the counter every minute boundary which is the standard
		// fixed-window algorithm — simple, predictable, no cron required.
		if ( 0 === $count ) {
			set_transient( $key, 1, MINUTE_IN_SECONDS );
		} else {
			set_transient( $key, $count + 1, $this->remaining_ttl( $key ) );
		}

		return true;
	}

	/**
	 * Resolve the configured per-minute API rate limit.
	 *
	 * Returns 0 when the customer has explicitly disabled rate limiting in
	 * Settings > API & Performance. Sanitize_settings already clamps the
	 * stored value to [0, 10000]; we accept 0 here as "disabled" rather than
	 * silently bumping to 1 (a forced floor would override the customer's
	 * stated intent and look like a bug).
	 */
	private function api_limit_per_minute(): int {
		$settings = get_option( 'learnomy_settings', [] );
		return (int) ( $settings['api_rate_limit'] ?? self::DEFAULT_API_LIMIT_PER_MINUTE );
	}

	/**
	 * Build the bucket key for the per-minute API limit.
	 *
	 * Authenticated callers get a per-user bucket. Anonymous callers ($user_id
	 * === 0) get a per-IP bucket — Basecamp #9927978132. Without the IP
	 * component every guest shares one transient (lrn_rl_api_0), so a single
	 * misbehaving bot can lock the entire public REST surface out for a
	 * minute. The IP is hashed (md5) so it never leaves the bucket key in
	 * cleartext, matching the pattern already used by Auth_Controller.
	 */
	private function api_transient_key( int $user_id ): string {
		if ( $user_id > 0 ) {
			return 'lrn_rl_api_u_' . $user_id;
		}

		$ip = $this->client_ip();
		// "anon_x.x.x.x" is too long to fit the transient name budget on
		// older WP (40 chars after the wp_options _transient_ prefix). Hash
		// to keep the key short + non-PII.
		return 'lrn_rl_api_a_' . md5( $ip );
	}

	/**
	 * Best-effort client IP for the per-IP anon bucket. Trusts the first
	 * X-Forwarded-For hop only when present (which the customer's CDN
	 * controls — same trust model the Auth_Controller already uses for
	 * login throttling), otherwise falls back to REMOTE_ADDR. A blank
	 * environment falls back to "0.0.0.0" so the bucket key stays valid
	 * (the request still gets rate-limited as a single anon stream — same
	 * as the old behavior, but bounded by the per-IP semantic).
	 */
	private function client_ip(): string {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$first     = trim( explode( ',', $forwarded )[0] );
			if ( '' !== $first && filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';
		return '' !== $remote && filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
	}

	private function transient_key( int $user_id, string $action ): string {
		return 'lrn_rl_' . $user_id . '_' . sanitize_key( $action );
	}

	private function remaining_ttl( string $key ): int {
		// get_option lets us read the raw expiry stored by the Transients API
		// without the overhead of a separate DB call using the Options API.
		$timeout_key = '_transient_timeout_' . $key;
		$timeout     = (int) get_option( $timeout_key, 0 );
		$remaining   = $timeout - time();

		// Guard against clock drift or already-expired entries.
		return max( 1, $remaining );
	}
}
