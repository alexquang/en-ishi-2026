<?php
/**
 * The member's billing identity, stored on the member.
 *
 * @package Learnomy
 */

declare(strict_types=1);

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes a buyer's billing details as plain user meta.
 *
 * A billing address belongs to the PERSON, not to whichever plugin happened to
 * take the payment. Someone who bought a course last month and a service today
 * types their address once, and both receipts carry it.
 *
 * The keys are WooCommerce's, verbatim, and that is the whole mechanism rather
 * than a shortcut. They are ordinary user meta under the de-facto WordPress
 * names, so:
 *
 *   - WooCommerce is NOT required. Nothing here calls a Woo function or checks
 *     for one; on a site without it these are simply user meta rows, which is
 *     the common case.
 *   - A site that DOES run Woo contributes every address it has already
 *     collected, for free, and inherits ours if Woo is installed later.
 *   - Any other plugin using the same convention reads what we captured with no
 *     integration code and no load-order problem.
 *
 * Before this, the address was collected inside Stripe's own Address Element.
 * That tied it to one gateway — a PayPal or WooCommerce buyer supplied no
 * address at all — it could not carry a company name or a tax registration, and
 * it was thrown away after every purchase, so the same member retyped it every
 * single time.
 *
 * @since 1.8.2
 */
class Billing_Profile {

	/**
	 * The fields we store, in the order a person reads them.
	 *
	 * `billing_gst` is ours: WooCommerce has no tax-registration field, and one
	 * key covers GSTIN in India and VAT ID in the EU so any product's invoice
	 * can show it.
	 */
	private const FIELDS = array(
		'billing_first_name',
		'billing_last_name',
		'billing_company',
		'billing_country',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_email',
		'billing_phone',
		'billing_gst',
	);

	/** Fields a purchase cannot proceed without. */
	private const REQUIRED = array(
		'billing_first_name',
		'billing_last_name',
		'billing_country',
		'billing_address_1',
		'billing_city',
		'billing_postcode',
	);

	/**
	 * A member's stored billing details.
	 *
	 * Falls back to the WordPress account for the name and email, so a first
	 * purchase starts half-filled rather than blank.
	 *
	 * @param int $user_id Member.
	 * @return array<string,string> Keyed by the full meta key.
	 */
	public static function get( int $user_id ): array {
		$profile = array_fill_keys( self::FIELDS, '' );
		if ( $user_id <= 0 ) {
			return $profile;
		}

		foreach ( self::FIELDS as $key ) {
			$profile[ $key ] = (string) get_user_meta( $user_id, $key, true );
		}

		$user = get_userdata( $user_id );
		if ( $user ) {
			if ( '' === $profile['billing_first_name'] ) {
				$profile['billing_first_name'] = (string) $user->first_name;
			}
			if ( '' === $profile['billing_last_name'] ) {
				$profile['billing_last_name'] = (string) $user->last_name;
			}
			if ( '' === $profile['billing_email'] ) {
				$profile['billing_email'] = (string) $user->user_email;
			}
		}

		return $profile;
	}

	/**
	 * Store a member's billing details.
	 *
	 * Only writes the fields that were supplied, so a caller that knows part of
	 * the address cannot blank the rest of it.
	 *
	 * @param int   $user_id Member.
	 * @param array $data    Field => value, keyed with or without the `billing_` prefix.
	 * @return array<string,string> The profile as stored.
	 */
	public static function save( int $user_id, array $data ): array {
		if ( $user_id <= 0 ) {
			return self::get( 0 );
		}

		foreach ( self::FIELDS as $key ) {
			$short = substr( $key, 8 ); // Accept 'city' as well as 'billing_city'.
			$value = $data[ $key ] ?? ( $data[ $short ] ?? null );
			if ( null === $value ) {
				continue;
			}

			$value = sanitize_text_field( (string) $value );
			if ( 'billing_email' === $key ) {
				$value = sanitize_email( $value );
			}
			if ( 'billing_country' === $key || 'billing_state' === $key ) {
				$value = strtoupper( $value );
			}

			update_user_meta( $user_id, $key, $value );
		}

		return self::get( $user_id );
	}

	/**
	 * Which required fields a profile is still missing.
	 *
	 * @param array $profile Profile as returned by get().
	 * @return string[] Missing meta keys; empty when the profile can be charged.
	 */
	public static function missing_required( array $profile ): array {
		$missing = array();
		foreach ( self::REQUIRED as $key ) {
			if ( '' === trim( (string) ( $profile[ $key ] ?? '' ) ) ) {
				$missing[] = $key;
			}
		}

		return $missing;
	}

	/**
	 * A frozen copy for an order.
	 *
	 * The profile holds the CURRENT address; an invoice has to show what was
	 * billed then. Editing a profile next year must not rewrite last year's
	 * receipts — the same split WooCommerce makes between `billing_*` on the
	 * user and `_billing_*` on the order.
	 *
	 * @param array $profile Profile as returned by get().
	 * @return array<string,string> Values only, no empties.
	 */
	public static function snapshot( array $profile ): array {
		return array_filter(
			array_intersect_key( $profile, array_flip( self::FIELDS ) ),
			static fn( $value ) => '' !== trim( (string) $value )
		);
	}
}
