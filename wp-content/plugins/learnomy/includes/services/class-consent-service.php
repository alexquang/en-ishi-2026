<?php
/**
 * Consent Service — the terms-of-service gate at registration, and the record
 * that proves someone passed through it.
 *
 * Before this, the plugin had no concept of consent at all: the registration
 * forms collected a name, an email and a password, and the site owner had no
 * way to require agreement to anything or to show, months later, that a
 * particular member had agreed. A checkbox that is merely `required` in HTML is
 * not a consent mechanism — it is a hint the browser enforces and a scripted
 * POST ignores, and it leaves nothing behind to point at.
 *
 * What a site owner actually needs to prove is four things: WHO agreed, WHEN,
 * WHAT they were shown, and from WHERE. All four are written here.
 *
 * Storage is deliberately split:
 *   - User meta is the durable record. It survives as long as the account does
 *     and is queryable with a plain `meta_key` lookup, WP_User_Query, or SQL.
 *   - `lrn_audit_log` gets a matching line for the admin-facing activity trail,
 *     but it is NOT the record of consent: `AuditLog::prune()` deletes rows
 *     after 90 days, and a consent proof that evaporates in a quarter is worse
 *     than none because it looks like coverage.
 *
 * No new table (CLAUDE.md rule 4a): three user-meta keys plus the existing
 * audit log carry this completely.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\AuditLog;
use function Learnomy\now;

class Consent_Service {

	/** UTC datetime the account holder accepted. Presence of this key IS the consent. */
	public const META_ACCEPTED_AT = 'learnomy_terms_accepted_at';

	/** The legal document URLs shown at the moment of acceptance, JSON-encoded. */
	public const META_ACCEPTED_DOCS = 'learnomy_terms_accepted_docs';

	/** Originating IP, the same evidence every other audited action records. */
	public const META_ACCEPTED_IP = 'learnomy_terms_accepted_ip';

	/**
	 * The site owner's Terms of Service page.
	 *
	 * Reads `learnomy_settings['terms_url']` — a key the mobile app-config
	 * endpoint has always published but which nothing ever wrote, because no
	 * admin field existed for it. The Settings > Pages field added alongside
	 * this service is that missing writer.
	 */
	public static function terms_url(): string {
		$settings = get_option( 'learnomy_settings', [] );
		$url      = is_array( $settings ) ? (string) ( $settings['terms_url'] ?? '' ) : '';

		return '' !== $url ? esc_url_raw( $url ) : '';
	}

	/**
	 * WordPress core's privacy policy page, if the owner published one. Reused
	 * rather than duplicated as a second Learnomy setting — core already owns
	 * this and the WP admin already surfaces it.
	 */
	public static function privacy_url(): string {
		return (string) get_privacy_policy_url();
	}

	/**
	 * Every legal document the consent checkbox should link to, keyed by kind.
	 *
	 * @return array<string,string>
	 */
	public static function documents(): array {
		$docs = [];

		$terms = self::terms_url();
		if ( '' !== $terms ) {
			$docs['terms'] = $terms;
		}

		$privacy = self::privacy_url();
		if ( '' !== $privacy ) {
			$docs['privacy'] = $privacy;
		}

		/**
		 * Filters the legal documents a new member must agree to.
		 *
		 * Lets a site owner add a code of conduct or a regional addendum
		 * without forking the registration templates.
		 *
		 * @param array<string,string> $docs Kind => absolute URL.
		 */
		return (array) apply_filters( 'learnomy_consent_documents', $docs );
	}

	/**
	 * Is the consent checkbox shown and enforced?
	 *
	 * Gated on having something to consent TO. A default install with no Terms
	 * page and no privacy policy would otherwise present "I agree to the Terms"
	 * with nothing behind the link — a checkbox that teaches members the
	 * agreement is meaningless, and one more thing for the owner to debug. Set
	 * the page, get the gate.
	 */
	public static function is_required(): bool {
		/**
		 * Filters whether terms acceptance is enforced at registration.
		 *
		 * @param bool                 $required Default: true once a legal document exists.
		 * @param array<string,string> $docs     The configured documents.
		 */
		return (bool) apply_filters( 'learnomy_consent_required', ! empty( self::documents() ), self::documents() );
	}

	/**
	 * Write the consent record for a freshly created account.
	 *
	 * Called once, immediately after `wp_insert_user()` succeeds, so a failed
	 * registration never leaves a consent stamp behind.
	 */
	public static function record( int $user_id, string $context = 'registration' ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$docs = self::documents();
		$ip   = \Learnomy\client_ip();

		update_user_meta( $user_id, self::META_ACCEPTED_AT, now() );
		update_user_meta( $user_id, self::META_ACCEPTED_DOCS, wp_json_encode( $docs ) );
		update_user_meta( $user_id, self::META_ACCEPTED_IP, $ip );

		// Mirror into the activity trail. `type` is varchar(30) and `action`
		// varchar(50) — both well inside.
		AuditLog::log(
			$user_id,
			'auth',
			'consent.terms_accepted',
			'user',
			$user_id,
			[
				'context'   => $context,
				'documents' => $docs,
			]
		);
	}

	/**
	 * The stored consent for a member, or null if none was ever recorded.
	 *
	 * Accounts that pre-date this feature legitimately have no record — the
	 * honest answer is "no record", never a fabricated timestamp.
	 *
	 * @return array{accepted_at:string,documents:array<string,string>,ip:string}|null
	 */
	public static function get( int $user_id ): ?array {
		$accepted_at = (string) get_user_meta( $user_id, self::META_ACCEPTED_AT, true );
		if ( '' === $accepted_at ) {
			return null;
		}

		$docs = json_decode( (string) get_user_meta( $user_id, self::META_ACCEPTED_DOCS, true ), true );

		return [
			'accepted_at' => $accepted_at,
			'documents'   => is_array( $docs ) ? $docs : [],
			'ip'          => (string) get_user_meta( $user_id, self::META_ACCEPTED_IP, true ),
		];
	}
}
