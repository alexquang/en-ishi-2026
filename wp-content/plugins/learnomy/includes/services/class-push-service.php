<?php
/**
 * Push notifications — the sender.
 *
 * `lrn_devices` has been collecting APNs/FCM tokens since 1.0 through
 * `POST /devices`, and until now NOTHING sent to them. The mobile app dutifully
 * registered a token on every launch, the table filled up, and not one push was
 * ever delivered. Half a feature: the intake with no outlet.
 *
 * Transport is Firebase Cloud Messaging HTTP v1, which relays to Android AND to
 * iOS (via APNs on Google's side), so one credential covers both platforms. The
 * legacy FCM server-key API is dead and deliberately not implemented.
 *
 * The site owner pastes their Firebase service-account JSON into Settings. We sign
 * a JWT with its private key, swap it for an OAuth access token (cached for its
 * lifetime, because minting one per notification would be an HTTP round trip per
 * push), and POST each device token.
 *
 * DEAD TOKENS ARE PRUNED. A token dies whenever the app is uninstalled, and FCM
 * says so with UNREGISTERED / NOT_FOUND. Without pruning, `lrn_devices` grows
 * forever and every send wastes a request on a device that no longer exists — the
 * table would be mostly garbage within a year on a real site.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

use Learnomy\Models\Device;

defined( 'ABSPATH' ) || exit;

class Push_Service {

	/** Google's OAuth2 token endpoint. */
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/** Scope required to send FCM messages. */
	private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

	/** Transient holding the OAuth access token. */
	private const TOKEN_TRANSIENT = 'learnomy_fcm_access_token';

	/**
	 * Responses that mean "this token is dead, stop sending to it".
	 *
	 * Anything else (a 500, a rate limit, a network blip) is transient and must NOT
	 * delete the token — throwing away a live device because Google had a bad minute
	 * would silently unsubscribe a real user.
	 */
	private const DEAD_TOKEN_CODES = array( 'UNREGISTERED', 'NOT_FOUND', 'INVALID_ARGUMENT' );

	/**
	 * Whether push is usable: enabled, with a service account that parses.
	 */
	public static function is_configured(): bool {
		return null !== self::service_account();
	}

	/**
	 * Send a push to every device a user has registered.
	 *
	 * Two transports, chosen per token:
	 *
	 *  - Expo tokens (`ExponentPushToken[…]` — the Learnomy mobile app) go
	 *    through Expo's relay, which needs NO site-owner configuration. The
	 *    `push_enabled` / FCM settings do not gate these: an app user who
	 *    registered a device has opted in, and there is no credential to miss.
	 *  - Everything else goes through FCM v1, which requires the owner's
	 *    Firebase service account (is_configured()).
	 *
	 * Safe to call unconditionally — a site with no mobile app has no tokens
	 * and no credentials, and pays for neither.
	 *
	 * @param int    $user_id Recipient.
	 * @param string $title   Notification title.
	 * @param string $body    Notification body.
	 * @param array  $data    Extra key/value payload the app can route on. Values are
	 *                        cast to string — FCM's data block rejects anything else.
	 * @return int Number of devices the push was accepted for.
	 */
	public function send_to_user( int $user_id, string $title, string $body, array $data = array() ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		$devices = Device::get_for_user( $user_id );
		if ( ! $devices ) {
			return 0;
		}

		// Split per token: Expo relay vs FCM v1.
		$expo_tokens = array();
		$fcm_tokens  = array();
		foreach ( $devices as $device ) {
			$device_token = (string) ( $device->device_token ?? '' );
			if ( '' === $device_token ) {
				continue;
			}
			if ( Expo_Push_Transport::is_expo_token( $device_token ) ) {
				$expo_tokens[] = $device_token;
			} else {
				$fcm_tokens[] = $device_token;
			}
		}

		$sent = 0;

		if ( $expo_tokens ) {
			$sent += ( new Expo_Push_Transport() )->send( $expo_tokens, $title, $body, $data );
		}

		if ( $fcm_tokens && self::is_configured() ) {
			$token = $this->access_token();
			if ( '' !== $token ) {
				foreach ( $fcm_tokens as $device_token ) {
					if ( $this->send_to_token( $token, $device_token, $title, $body, $data ) ) {
						++$sent;
					}
				}
			}
		}

		return $sent;
	}

	/**
	 * POST one message to one device token.
	 *
	 * @return bool True when FCM accepted it.
	 */
	private function send_to_token( string $access_token, string $device_token, string $title, string $body, array $data ): bool {
		$account    = self::service_account();
		$project_id = (string) ( $account['project_id'] ?? '' );
		if ( '' === $project_id ) {
			return false;
		}

		/**
		 * Filter the FCM message before it is sent.
		 *
		 * @param array  $message      The FCM v1 `message` object.
		 * @param int    $device_token Target device token.
		 * @param array  $data         The caller's data payload.
		 */
		$message = apply_filters(
			'learnomy_push_message',
			array(
				'token'        => $device_token,
				'notification' => array(
					'title' => $title,
					'body'  => $body,
				),
				// FCM rejects a data block whose values are not strings, and it fails
				// the WHOLE message when it does — so cast rather than trust callers.
				'data'         => array_map( 'strval', $data ),
			),
			$device_token,
			$data
		);

		$response = wp_remote_post(
			"https://fcm.googleapis.com/v1/projects/{$project_id}/messages:send",
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'message' => $message ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			/**
			 * Fires after a push is accepted by FCM for one device.
			 *
			 * @param int    $device_token_id Not the token itself — do not leak it to listeners.
			 * @param string $title           Notification title.
			 */
			do_action( 'learnomy_push_sent', $device_token, $title );
			return true;
		}

		// The app was uninstalled (or the token was never valid). Drop it: otherwise
		// the table keeps a dead row forever and every future send burns a request on
		// a device that does not exist.
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$status  = (string) ( $decoded['error']['status'] ?? '' );

		if ( in_array( $status, self::DEAD_TOKEN_CODES, true ) ) {
			Device::unregister( $device_token );
		}

		return false;
	}

	/**
	 * A valid OAuth access token for the FCM API, minted from the service account.
	 *
	 * Cached until just before it expires. Minting one per push would add an HTTP
	 * round trip to Google in front of every single notification.
	 */
	private function access_token(): string {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$account = self::service_account();
		if ( ! $account ) {
			return '';
		}

		$jwt = $this->signed_jwt( $account );
		if ( '' === $jwt ) {
			return '';
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$token   = (string) ( $decoded['access_token'] ?? '' );
		if ( '' === $token ) {
			return '';
		}

		// Expire our copy a minute early — a token that dies mid-request is a push
		// silently lost, and the cost of re-minting one minute sooner is nothing.
		$ttl = max( 60, (int) ( $decoded['expires_in'] ?? 3600 ) - 60 );
		set_transient( self::TOKEN_TRANSIENT, $token, $ttl );

		return $token;
	}

	/**
	 * Sign the assertion JWT with the service account's private key (RS256).
	 */
	private function signed_jwt( array $account ): string {
		$key   = (string) ( $account['private_key'] ?? '' );
		$email = (string) ( $account['client_email'] ?? '' );
		if ( '' === $key || '' === $email || ! function_exists( 'openssl_sign' ) ) {
			return '';
		}

		$now    = time();
		$header = self::b64(
			(string) wp_json_encode(
				array(
					'alg' => 'RS256',
					'typ' => 'JWT',
				)
			)
		);
		$claims = self::b64(
			(string) wp_json_encode(
				array(
					'iss'   => $email,
					'scope' => self::SCOPE,
					'aud'   => self::TOKEN_URL,
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);

		$signature = '';
		if ( ! openssl_sign( "{$header}.{$claims}", $signature, $key, 'sha256WithRSAEncryption' ) ) {
			return '';
		}

		return "{$header}.{$claims}." . self::b64( $signature );
	}

	/** Base64url, per JWS. */
	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWS encoding, not obfuscation.
	}

	/**
	 * The decoded Firebase service account, or null when push is off / unconfigured
	 * / the pasted JSON is not a service account.
	 */
	private static function service_account(): ?array {
		$settings = get_option( 'learnomy_settings', array() );

		if ( empty( $settings['push_enabled'] ) ) {
			return null;
		}

		$json = (string) ( $settings['push_fcm_service_account'] ?? '' );
		if ( '' === $json ) {
			return null;
		}

		$account = json_decode( $json, true );

		// Must actually be a service account. A site owner who pastes the wrong file
		// (the web-app config, say — an easy mistake, both are JSON from the Firebase
		// console) gets a clean "not configured" rather than a stream of failed sends.
		if ( ! is_array( $account )
			|| empty( $account['private_key'] )
			|| empty( $account['client_email'] )
			|| empty( $account['project_id'] ) ) {
			return null;
		}

		return $account;
	}
}
