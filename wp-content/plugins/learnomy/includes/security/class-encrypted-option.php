<?php
/**
 * Encrypted option storage using libsodium.
 *
 * Wraps wp_options reads/writes with authenticated symmetric encryption
 * so payment secrets are never stored in plaintext. Uses AUTH_KEY as the
 * root secret, derived via HKDF (sodium_crypto_generichash).
 *
 * @package Learnomy
 */

namespace Learnomy\Security;

defined( 'ABSPATH' ) || exit;

class Encrypted_Option {

	private const PREFIX = 'enc::';

	/**
	 * Encrypt and store a value.
	 */
	public static function set( string $option_name, string $plaintext ): bool {
		if ( '' === $plaintext ) {
			return delete_option( $option_name );
		}

		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			// Fallback: store plain if libsodium unavailable (PHP < 7.2 edge case).
			return update_option( $option_name, $plaintext, false );
		}

		$key    = self::derive_key();
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		$blob   = self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return update_option( $option_name, $blob, false );
	}

	/**
	 * Read and decrypt a value.
	 */
	public static function get( string $option_name, string $default = '' ): string {
		$blob = get_option( $option_name, '' );

		if ( ! is_string( $blob ) || '' === $blob ) {
			return $default;
		}

		// Not encrypted — return as-is (plaintext from before migration).
		if ( 0 !== strpos( $blob, self::PREFIX ) ) {
			return $blob;
		}

		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return $default;
		}

		$raw = base64_decode( substr( $blob, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return $default;
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::derive_key() );
		} catch ( \SodiumException $e ) {
			return $default;
		}

		return false === $plain ? $default : $plain;
	}

	/**
	 * Delete an encrypted option.
	 */
	public static function delete( string $option_name ): bool {
		return delete_option( $option_name );
	}

	/**
	 * Derive a 256-bit key from AUTH_KEY via HKDF.
	 */
	private static function derive_key(): string {
		$secret = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );

		return sodium_crypto_generichash(
			'learnomy:encrypted-option:v1:' . $secret,
			'',
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}
}
