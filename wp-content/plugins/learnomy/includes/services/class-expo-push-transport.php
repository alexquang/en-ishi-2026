<?php
/**
 * Expo push transport — zero-config delivery for the Learnomy mobile app.
 *
 * The app is built on Expo, and Expo devices register `ExponentPushToken[…]`
 * tokens — which FCM cannot deliver to. Expo runs its own relay
 * (`exp.host/--/api/v2/push/send`) that forwards to APNs + FCM on Expo's own
 * credentials, so THIS transport needs no site-owner setup at all: no Firebase
 * service account, no key upload. The FCM v1 transport (Push_Service) remains
 * for non-Expo tokens; Push_Service::send_to_user() splits per token.
 *
 * Dead tokens are pruned on Expo's `DeviceNotRegistered` receipt — same policy
 * as the FCM transport, same reason: an uninstalled app's token is garbage the
 * table would otherwise keep forever.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

use Learnomy\Models\Device;

defined( 'ABSPATH' ) || exit;

class Expo_Push_Transport {

	/** Expo's push relay. */
	private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

	/** Expo accepts at most 100 messages per request. */
	private const BATCH_SIZE = 100;

	/**
	 * Whether a device token is an Expo push token.
	 *
	 * Expo mints both spellings depending on SDK vintage; match either.
	 */
	public static function is_expo_token( string $token ): bool {
		return str_starts_with( $token, 'ExponentPushToken[' )
			|| str_starts_with( $token, 'ExpoPushToken[' );
	}

	/**
	 * Send one notification to a set of Expo tokens, batched.
	 *
	 * @param string[] $tokens Expo push tokens.
	 * @param string   $title  Notification title.
	 * @param string   $body   Notification body.
	 * @param array    $data   Deep-link payload the app routes on. Values are
	 *                         cast to string (mirrors the FCM data-block rule so
	 *                         both transports carry identical payloads).
	 * @return int Number of tokens Expo accepted the message for.
	 */
	public function send( array $tokens, string $title, string $body, array $data = array() ): int {
		$tokens = array_values( array_filter( $tokens, array( self::class, 'is_expo_token' ) ) );
		if ( ! $tokens ) {
			return 0;
		}

		$sent = 0;
		foreach ( array_chunk( $tokens, self::BATCH_SIZE ) as $chunk ) {
			$sent += $this->send_batch( $chunk, $title, $body, $data );
		}

		return $sent;
	}

	/**
	 * POST one batch (≤100 messages) and process the per-message receipts.
	 *
	 * @param string[] $tokens One chunk of Expo tokens.
	 * @return int Accepted count for this batch.
	 */
	private function send_batch( array $tokens, string $title, string $body, array $data ): int {
		$messages = array();
		foreach ( $tokens as $token ) {
			/**
			 * Filter one Expo push message before it is sent.
			 *
			 * @param array  $message The Expo message object.
			 * @param string $token   Target Expo token.
			 * @param array  $data    The caller's data payload.
			 */
			$messages[] = apply_filters(
				'learnomy_expo_push_message',
				array(
					'to'    => $token,
					'title' => $title,
					'body'  => $body,
					'sound' => 'default',
					'data'  => array_map( 'strval', $data ),
				),
				$token,
				$data
			);
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $messages ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$decoded  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$receipts = is_array( $decoded ) && isset( $decoded['data'] ) && is_array( $decoded['data'] )
			? $decoded['data']
			: array();

		$sent = 0;
		foreach ( $receipts as $i => $receipt ) {
			$token = $tokens[ $i ] ?? '';

			if ( 'ok' === ( $receipt['status'] ?? '' ) ) {
				++$sent;
				if ( '' !== $token ) {
					// Same hook the FCM transport fires — listeners see one
					// event stream regardless of transport.
					do_action( 'learnomy_push_sent', $token, $title );
				}
				continue;
			}

			// The app was uninstalled — Expo says so explicitly. Anything else
			// (rate limit, transient relay error) must NOT delete the token.
			$error = (string) ( $receipt['details']['error'] ?? '' );
			if ( 'DeviceNotRegistered' === $error && '' !== $token ) {
				Device::unregister( $token );
			}
		}

		return $sent;
	}
}
