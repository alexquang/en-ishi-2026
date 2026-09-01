<?php
/**
 * Certificate Service — issue, verify, and revoke learner certificates.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\Models\Certificate;
use Learnomy\Models\CertificateTemplate;
use Learnomy\Models\Course;
use Learnomy\Models\Enrollment;
use Learnomy\Models\QuizAttempt;

class Certificate_Service {

	// wp_options key that holds the encrypted Ed25519 private key.
	private const PRIVATE_KEY_OPTION = 'learnomy_cert_private_key';
	private const PUBLIC_KEY_OPTION  = 'learnomy_cert_public_key';

	/**
	 * Issues a new certificate for a student on a course.
	 *
	 * The third argument accepts BOTH forms:
	 *   - `int` (legacy) — explicit template id to stamp on the row.
	 *   - `array` (current) — context map with `template_id`, future-proof
	 *     for fields like `expires_at`, `metadata`, `skip_signing`, etc.
	 *
	 * Template resolution cascade (first non-zero wins):
	 *   1. Explicit `template_id` argument / context value.
	 *   2. Course-level `certificate_template_id` from `settings_json`.
	 *   3. First active row from `CertificateTemplate::get_active()`.
	 *
	 * @param int                          $user_id
	 * @param int                          $course_id
	 * @param array<string,mixed>|int|null $context
	 */
	public function issue( int $user_id, int $course_id, array|int|null $context = null ): int {
		// Back-compat: accept a bare template id as the third argument.
		if ( is_int( $context ) ) {
			$context = [ 'template_id' => $context ];
		} elseif ( ! is_array( $context ) ) {
			$context = [];
		}

		$template_id = isset( $context['template_id'] ) ? (int) $context['template_id'] : 0;

		// Prefer the instructor's per-course template when the caller did
		// not pass one explicitly.
		if ( $template_id <= 0 ) {
			$course_template = (int) Course::get_setting( $course_id, 'certificate_template_id', 0 );
			if ( $course_template > 0 ) {
				$template_id = $course_template;
			}
		}

		// Resolve enrollment.
		$enrollment    = Enrollment::find_for_user_course( $user_id, $course_id );
		$enrollment_id = $enrollment ? (int) $enrollment->id : null;

		// Final fallback: first active template in the library.
		if ( $template_id <= 0 ) {
			$active_templates = CertificateTemplate::get_active();
			if ( ! empty( $active_templates ) ) {
				$template_id = (int) $active_templates[0]->id;
			}
		}

		$payload = array_merge(
			[
				'user_id'       => $user_id,
				'course_id'     => $course_id,
				'enrollment_id' => $enrollment_id,
				'template_id'   => $template_id,
			],
			// What this document asserts, frozen now. Everything above is a
			// pointer, and pointers rot (Basecamp 10148480574).
			self::issue_snapshot( $user_id, $course_id, $context )
		);

		// Set expiration if the template defines expiration_days.
		if ( $template_id ) {
			$template = CertificateTemplate::find( $template_id );
			if ( $template && ! empty( $template->expiration_days ) ) {
				$payload['expires_at'] = gmdate( 'Y-m-d H:i:s', strtotime( '+' . (int) $template->expiration_days . ' days' ) );
			}
		}

		$certificate_id = Certificate::issue( $payload );

		// Fetch the freshly created row to get the auto-generated UUID for signing.
		$certificate = Certificate::find( $certificate_id );
		if ( $certificate ) {
			$signature = $this->sign( $certificate->uuid . '|' . $user_id . '|' . $course_id );
			if ( $signature ) {
				Certificate::update( $certificate_id, [ 'signature' => $signature ] );
			}
		}

		do_action( 'learnomy_certificate_issued', $certificate_id, $user_id, $course_id );

		return $certificate_id;
	}

	/**
	 * The facts a certificate asserts, resolved for issue time.
	 *
	 * A caller that knows better than the course table can pass them in
	 * `$context['facts']` — that is how a certificate for something which is not
	 * a course (Pro's learning paths issue with `course_id = 0`) still records
	 * what it is for. `learnomy_certificate_issue_snapshot` is the same seam for
	 * code that does not own the issue call.
	 *
	 * @param array<string,mixed> $context Issue context; `facts` is honoured.
	 * @return array<string,mixed> Column => value, ready to merge into the row.
	 */
	private static function issue_snapshot( int $user_id, int $course_id, array $context ): array {
		$course     = $course_id > 0 ? Course::find( $course_id ) : null;
		$instructor = null;
		if ( $course && ! empty( $course->author_id ) ) {
			$instructor = get_userdata( (int) $course->author_id );
		}

		$user  = get_userdata( $user_id );
		$score = QuizAttempt::best_percentage_for_course( $user_id, $course_id );

		$snapshot = [
			'course_title_snapshot'    => $course ? (string) ( $course->title ?? '' ) : '',
			'recipient_name_snapshot'  => $user ? (string) $user->display_name : '',
			'issuer_name_snapshot'     => (string) get_bloginfo( 'name' ),
			'instructor_name_snapshot' => $instructor ? (string) $instructor->display_name : '',
			'score_snapshot'           => $score,
			'hours_snapshot'           => $course && ! empty( $course->duration_seconds )
				? round( (int) $course->duration_seconds / HOUR_IN_SECONDS, 2 )
				: null,
		];

		$explicit = isset( $context['facts'] ) && is_array( $context['facts'] ) ? $context['facts'] : [];
		foreach ( self::FACT_COLUMNS as $fact => $column ) {
			if ( isset( $explicit[ $fact ] ) ) {
				$snapshot[ $column ] = $explicit[ $fact ];
			}
		}

		/**
		 * Filter the facts recorded on a certificate at issue time.
		 *
		 * @param array<string,mixed> $snapshot  Column => value.
		 * @param int                 $user_id   Recipient.
		 * @param int                 $course_id Course (0 when the certificate is for something else).
		 * @param array<string,mixed> $context   Issue context.
		 */
		$snapshot = (array) apply_filters( 'learnomy_certificate_issue_snapshot', $snapshot, $user_id, $course_id, $context );

		return self::clean_snapshot( $snapshot );
	}

	/**
	 * Fact name => snapshot column. One map so the issue path, the filter
	 * contract and the read path cannot drift apart.
	 */
	private const FACT_COLUMNS = [
		'course_title'    => 'course_title_snapshot',
		'recipient_name'  => 'recipient_name_snapshot',
		'issuer_name'     => 'issuer_name_snapshot',
		'instructor_name' => 'instructor_name_snapshot',
		'score'           => 'score_snapshot',
		'hours'           => 'hours_snapshot',
	];

	/** Sanitize + fit the snapshot to its columns; an unknown value stays NULL. */
	private static function clean_snapshot( array $snapshot ): array {
		$out = [];

		foreach ( self::FACT_COLUMNS as $fact => $column ) {
			$value = $snapshot[ $column ] ?? null;

			if ( in_array( $fact, [ 'score', 'hours' ], true ) ) {
				$out[ $column ] = ( null === $value || '' === $value ) ? null : (float) $value;
				continue;
			}

			$value          = sanitize_text_field( (string) $value );
			$out[ $column ] = '' === $value ? null : mb_substr( $value, 0, 255 );
		}

		return $out;
	}

	/**
	 * What a certificate says it is for, reading the snapshot in preference to
	 * the live rows.
	 *
	 * PRECEDENCE IS DELIBERATE AND ONE-WAY: a recorded fact always wins over the
	 * live lookup, and the live lookup is only ever a fallback for certificates
	 * issued before the snapshot columns existed (and left unrecoverable by
	 * migration 3.33.0). Renaming a course does not, and must not, retitle a
	 * certificate somebody already earned — the document asserts what was true
	 * when it was awarded, which is the whole reason anyone trusts it years
	 * later. A site that genuinely needs to restate an old credential (a real
	 * typo, a legal name change) re-issues it, or filters below; it does not get
	 * to rewrite history by editing a course.
	 *
	 * @param object $certificate Certificate row.
	 * @return array{course_title:string,course_title_source:string,recipient_name:string,issuer_name:string,instructor_name:string,score:?float,hours:?float}
	 */
	public static function asserted_facts( object $certificate ): array {
		$snapshot = [];
		foreach ( self::FACT_COLUMNS as $fact => $column ) {
			$value             = $certificate->$column ?? null;
			$snapshot[ $fact ] = ( null === $value || '' === $value ) ? null : $value;
		}

		// The live rows are consulted only for what the snapshot does not hold,
		// and only then are they even loaded.
		$course_id    = (int) ( $certificate->course_id ?? 0 );
		$needs_course = null === $snapshot['course_title']
			|| null === $snapshot['instructor_name']
			|| null === $snapshot['hours'];
		$course       = ( $needs_course && $course_id > 0 ) ? Course::find( $course_id ) : null;

		$instructor_name = $snapshot['instructor_name'];
		if ( null === $instructor_name && $course && ! empty( $course->author_id ) ) {
			$author          = get_userdata( (int) $course->author_id );
			$instructor_name = $author ? $author->display_name : null;
		}

		$recipient_name = $snapshot['recipient_name'];
		if ( null === $recipient_name && ! empty( $certificate->user_id ) ) {
			$user           = get_userdata( (int) $certificate->user_id );
			$recipient_name = $user ? $user->display_name : null;
		}

		$score = $snapshot['score'];
		if ( null === $score && $course_id > 0 && ! empty( $certificate->user_id ) ) {
			$score = QuizAttempt::best_percentage_for_course( (int) $certificate->user_id, $course_id );
		}

		$hours = $snapshot['hours'];
		if ( null === $hours && $course && ! empty( $course->duration_seconds ) ) {
			$hours = round( (int) $course->duration_seconds / HOUR_IN_SECONDS, 2 );
		}

		$course_title = $snapshot['course_title'];
		$source       = 'snapshot';
		if ( null === $course_title ) {
			$course_title = $course ? (string) ( $course->title ?? '' ) : '';
			$source       = '' !== $course_title ? 'live' : 'unavailable';
		}

		$facts = [
			'course_title'        => (string) $course_title,
			'course_title_source' => $source,
			'recipient_name'      => (string) $recipient_name,
			'issuer_name'         => (string) ( $snapshot['issuer_name'] ?? get_bloginfo( 'name' ) ),
			'instructor_name'     => (string) $instructor_name,
			'score'               => null === $score ? null : (float) $score,
			'hours'               => null === $hours ? null : (float) $hours,
		];

		/**
		 * Filter the facts a certificate asserts, as read back for display.
		 *
		 * The escape hatch for a site that must restate an already-issued
		 * credential (a corrected legal name, a merged academy) without editing
		 * history in the database.
		 *
		 * @param array  $facts       Resolved facts.
		 * @param object $certificate Certificate row.
		 */
		return (array) apply_filters( 'learnomy_certificate_asserted_facts', $facts, $certificate );
	}

	/**
	 * The course line to PRINT, for a surface that has to read as a sentence.
	 *
	 * When the title was never recorded and the course row is gone, the honest
	 * answer is that we do not know the name — but "Course no longer available"
	 * stamped across a credential reads like a void notice and tells an employer
	 * nothing. The document still truthfully asserts that this person completed a
	 * course of study at this academy, on this date, under this credential id,
	 * and that is what it says. It never invents a title.
	 *
	 * @param array<string,mixed> $facts From asserted_facts().
	 */
	public static function course_display_title( array $facts ): string {
		$title = (string) ( $facts['course_title'] ?? '' );
		if ( '' !== $title ) {
			return $title;
		}

		$issuer = (string) ( $facts['issuer_name'] ?? '' );
		if ( '' === $issuer ) {
			return __( 'a course of study', 'learnomy' );
		}

		/* translators: %s: academy or site name that issued the certificate. */
		return sprintf( __( 'a course of study at %s', 'learnomy' ), $issuer );
	}

	/**
	 * Display titles for a LIST of certificates, in one pass.
	 *
	 * The account credentials page and the admin list walk a whole page of rows;
	 * calling asserted_facts() per row would re-query the live course for every
	 * pre-snapshot certificate, which is the N+1 the big-site checklist exists to
	 * prevent. Rows carrying a snapshot need no lookup at all.
	 *
	 * @param object[] $certificates Certificate rows.
	 * @return array<int,array{title:string,recorded:bool}> Keyed by certificate id.
	 */
	public static function display_titles( array $certificates ): array {
		$missing = [];
		foreach ( $certificates as $certificate ) {
			if ( '' === trim( (string) ( $certificate->course_title_snapshot ?? '' ) ) ) {
				$course_id = (int) ( $certificate->course_id ?? 0 );
				if ( $course_id > 0 ) {
					$missing[ $course_id ] = $course_id;
				}
			}
		}

		$courses = $missing ? Course::find_in( array_values( $missing ) ) : [];

		$out = [];
		foreach ( $certificates as $certificate ) {
			$title = trim( (string) ( $certificate->course_title_snapshot ?? '' ) );
			if ( '' === $title ) {
				$course = $courses[ (int) ( $certificate->course_id ?? 0 ) ] ?? null;
				$title  = $course ? (string) ( $course->title ?? '' ) : '';
			}

			$issuer = trim( (string) ( $certificate->issuer_name_snapshot ?? '' ) );

			$out[ (int) $certificate->id ] = [
				'title'    => '' !== $title
					? $title
					: self::course_display_title(
						[
							'course_title' => '',
							'issuer_name'  => '' !== $issuer ? $issuer : get_bloginfo( 'name' ),
						]
					),
				'recorded' => '' !== $title,
			];
		}

		return $out;
	}

	public function verify( string $uuid ): ?object {
		$certificate = Certificate::verify( $uuid );

		if ( $certificate ) {
			do_action( 'learnomy_certificate_verified', $certificate->id, $uuid );
		}

		return $certificate;
	}

	/**
	 * Cryptographically verify a certificate's stored Ed25519 signature, so the
	 * public verify page can detect a tampered record rather than trusting the
	 * status column alone.
	 *
	 * Returns false ONLY when a signature is present, checkable, and does not
	 * match (the certificate was altered). When the signature is absent, sodium
	 * is unavailable, or the public key is missing, it returns true so a genuine
	 * certificate is never shown as invalid just because it can't be checked
	 * (e.g. older certificates issued before signing, or a rotated key).
	 *
	 * @param object $certificate Row with uuid, user_id, course_id, signature.
	 */
	public function verify_signature( object $certificate ): bool {
		return 'invalid' !== $this->signature_state( $certificate );
	}

	/**
	 * The signature verdict, with the REASON — so a caller can tell "this record
	 * was altered" apart from "there is nothing here to check".
	 *
	 * `verify_signature()` collapsing both into `true` was the whole problem
	 * (Basecamp 10134139810). Four states:
	 *
	 *   `valid`        — signature present and it matches.
	 *   `unsigned`     — no signature stored. Certificates issued before signing
	 *                    shipped are genuine; they simply cannot be proved.
	 *   `unverifiable` — we cannot perform the check: no libsodium, no public key,
	 *                    or a public key that is not the right length. That is our
	 *                    problem, not evidence against the certificate.
	 *   `invalid`      — a signature IS stored and it does not verify, INCLUDING
	 *                    when it is not decodable base64. That last case used to
	 *                    return true and is the fail-open the card found: writing
	 *                    garbage into the column reported "valid". A stored
	 *                    signature that cannot be parsed is evidence of tampering,
	 *                    not of a legacy certificate — the two are distinguished by
	 *                    whether the column is EMPTY, not by whether it parses.
	 *
	 * @param object $certificate Row with uuid, user_id, course_id, signature.
	 * @return string One of valid|unsigned|unverifiable|invalid.
	 */
	public function signature_state( object $certificate ): string {
		if ( empty( $certificate->signature ) ) {
			return 'unsigned';
		}

		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return 'unverifiable';
		}

		$public_b64 = (string) get_option( self::PUBLIC_KEY_OPTION, '' );
		if ( '' === $public_b64 ) {
			return 'unverifiable';
		}

		$public_key = base64_decode( $public_b64, true );
		if ( false === $public_key || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $public_key ) ) {
			return 'unverifiable';
		}

		// From here the key is good, so any failure is about the SIGNATURE.
		$signature = base64_decode( (string) $certificate->signature, true );
		if ( false === $signature || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $signature ) ) {
			return 'invalid';
		}

		$message = $certificate->uuid . '|' . (int) $certificate->user_id . '|' . (int) $certificate->course_id;

		return sodium_crypto_sign_verify_detached( $signature, $message, $public_key ) ? 'valid' : 'invalid';
	}

	public function revoke( int $certificate_id, string $reason ): bool {
		// Certificate::revoke() sets status + revoked_at; we extend it with the reason.
		$result = Certificate::revoke( $certificate_id );

		if ( $result && $reason ) {
			Certificate::update( $certificate_id, [ 'revoked_reason' => sanitize_text_field( $reason ) ] );
		}

		if ( $result ) {
			$certificate = Certificate::find( $certificate_id );
			do_action( 'learnomy_certificate_revoked', $certificate_id, $certificate );
		}

		return $result;
	}

	// Returns the Ed25519 private key as a binary string, generating and storing it on first call.
	public function get_signing_key(): ?string {
		if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
			return null;
		}

		$stored = get_option( self::PRIVATE_KEY_OPTION, '' );

		if ( $stored ) {
			return $this->decrypt_key( $stored );
		}

		// Generate a new keypair, store encrypted private + raw public.
		$keypair   = sodium_crypto_sign_keypair();
		$private   = sodium_crypto_sign_secretkey( $keypair );
		$public    = sodium_crypto_sign_publickey( $keypair );
		$encrypted = $this->encrypt_key( $private );

		update_option( self::PRIVATE_KEY_OPTION, $encrypted, false );
		update_option( self::PUBLIC_KEY_OPTION, base64_encode( $public ), false );

		return $private;
	}

	// Signs $data with the site's Ed25519 key. Returns base64-encoded signature or empty string.
	public function sign( string $data ): string {
		$private_key = $this->get_signing_key();
		if ( ! $private_key ) {
			return '';
		}

		$signature = sodium_crypto_sign_detached( $data, $private_key );
		return base64_encode( $signature );
	}

	// ── Key encryption helpers ────────────────────────────────────────────────

	// Encrypt the raw key bytes with AUTH_KEY so they are not stored in plaintext.
	private function encrypt_key( string $raw_key ): string {
		$auth_key   = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box_key    = substr( hash( 'sha256', $auth_key, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$ciphertext = sodium_crypto_secretbox( $raw_key, $nonce, $box_key );
		return base64_encode( $nonce . $ciphertext );
	}

	private function decrypt_key( string $stored ): ?string {
		try {
			$auth_key = defined( 'AUTH_KEY' ) ? AUTH_KEY : wp_salt( 'auth' );
			$decoded  = base64_decode( $stored, true );
			if ( false === $decoded ) {
				return null;
			}
			$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box_key    = substr( hash( 'sha256', $auth_key, true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $box_key );
			return false !== $plaintext ? $plaintext : null;
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
