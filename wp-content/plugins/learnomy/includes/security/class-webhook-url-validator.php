<?php
/**
 * Webhook URL validator — the single guard against SSRF on outbound webhooks.
 *
 * Outbound webhook URLs are admin-supplied and then fetched server-side. Without
 * validation, an admin (or anyone who later compromises an admin session) can point
 * a webhook at an internal address — the cloud instance-metadata endpoint
 * (169.254.169.254), loopback services (Redis on 127.0.0.1:6379, Elasticsearch on
 * localhost:9200), or any RFC-1918 host — and have the response funnelled back into
 * the delivery log. That is a Server-Side Request Forgery with cloud-credential
 * exfiltration impact (Basecamp #9937420156).
 *
 * This class is the ONE place that decides whether a webhook URL may be saved or
 * delivered to. It is enforced at BOTH ends:
 *   - save time   — the REST controller rejects a bad URL before it is stored;
 *   - delivery time — the Free dispatcher and the Pro dispatcher re-resolve the host
 *                     just before the HTTP call (defence against DNS rebinding /
 *                     records that changed since save).
 *
 * Pro must call {@see self::validate()} / {@see self::is_safe()} directly — it does
 * not own a parallel validator (Pro extends Free, never duplicates).
 *
 * @package Learnomy
 */

namespace Learnomy\Security;

defined( 'ABSPATH' ) || exit;

use WP_Error;

class Webhook_URL_Validator {

	/**
	 * CIDR ranges an outbound webhook may never reach.
	 *
	 * Covers loopback, RFC-1918 private space, link-local (which includes the
	 * 169.254.169.254 cloud-metadata endpoint), carrier-grade NAT, the
	 * "this network" / reserved blocks, and their IPv6 equivalents.
	 *
	 * @var string[]
	 */
	private const BLOCKED_RANGES = array(
		// IPv4.
		'0.0.0.0/8',        // "This network" / unspecified.
		'10.0.0.0/8',       // RFC-1918 private.
		'100.64.0.0/10',    // Carrier-grade NAT (RFC-6598).
		'127.0.0.0/8',      // Loopback.
		'169.254.0.0/16',   // Link-local — includes 169.254.169.254 cloud metadata.
		'172.16.0.0/12',    // RFC-1918 private (Docker bridge lives here).
		'192.0.0.0/24',     // IETF protocol assignments.
		'192.0.2.0/24',     // TEST-NET-1 documentation.
		'192.168.0.0/16',   // RFC-1918 private.
		'198.18.0.0/15',    // Benchmarking.
		'198.51.100.0/24',  // TEST-NET-2 documentation.
		'203.0.113.0/24',   // TEST-NET-3 documentation.
		'240.0.0.0/4',      // Reserved (future use) — includes 255.255.255.255 broadcast.
		// IPv6.
		'::/128',           // Unspecified.
		'::1/128',          // Loopback.
		'::ffff:0:0/96',    // IPv4-mapped IPv6 (e.g. ::ffff:169.254.169.254).
		'64:ff9b::/96',     // NAT64 well-known prefix.
		'fc00::/7',         // Unique local addresses (RFC-4193).
		'fe80::/10',        // Link-local.
	);

	/**
	 * Hostnames that resolve to internal infrastructure and must be rejected by
	 * name even before DNS, since a stale or attacker-controlled resolver could
	 * answer with a public IP at save time and an internal one at delivery time.
	 *
	 * @var string[]
	 */
	private const BLOCKED_HOSTS = array(
		'localhost',
		'metadata.google.internal',
		'metadata.goog',
		'metadata.azure.com',
		'instance-data',
		'instance-data.ec2.internal',
	);

	/**
	 * Synchronous, NON-BLOCKING checks: required, parseable, allowed scheme,
	 * blocked-host literals, and — when the host is an IP literal — the
	 * private/internal IP block (no DNS needed). This is what the REST
	 * `validate_callback` must use: it never resolves a hostname, so a slow or
	 * blocked egress DNS can't stall (and exhaust) the PHP-FPM request worker.
	 * The full DNS-based SSRF check still runs in {@see self::validate()} at
	 * delivery time on the async queue.
	 *
	 * @param mixed $url The candidate URL.
	 * @return true|WP_Error
	 */
	public static function validate_format( $url ): bool|WP_Error {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return new WP_Error(
				'learnomy_webhook_url_required',
				__( 'A webhook URL is required.', 'learnomy' ),
				array( 'status' => 400 )
			);
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return new WP_Error(
				'learnomy_webhook_url_malformed',
				__( 'The webhook URL could not be parsed. Provide a full https URL.', 'learnomy' ),
				array( 'status' => 400 )
			);
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		/**
		 * Allowed URL schemes for outbound webhooks. Defaults to https only.
		 * We add http for local development and self-hosted receivers on the same
		 * trusted network, but it is still bound by the private-IP block below, so
		 * an http URL can never reach an internal host.
		 *
		 * @param string[] $schemes Allowed lowercase schemes.
		 */
		$allowed_schemes = (array) apply_filters( 'learnomy_webhook_allowed_schemes', array( 'https', 'http' ) );

		if ( ! in_array( $scheme, $allowed_schemes, true ) ) {
			return new WP_Error(
				'learnomy_webhook_url_scheme',
				__( 'Only http and https webhook URLs are allowed.', 'learnomy' ),
				array( 'status' => 400 )
			);
		}

		$host = strtolower( (string) $parts['host'] );
		$host = trim( $host, '[]' ); // Unwrap bracketed IPv6 literals.

		foreach ( self::BLOCKED_HOSTS as $blocked ) {
			if ( $host === $blocked ) {
				return self::blocked_error();
			}
		}

		// IP-literal hosts can be SSRF-checked with zero DNS, so do it here too —
		// blocks http://169.254.169.254 etc. at the request layer immediately.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) && self::is_blocked_ip( $host ) ) {
			return self::blocked_error();
		}

		return true;
	}

	/**
	 * Full validation INCLUDING blocking DNS resolution + per-IP SSRF block.
	 * Use this where blocking is acceptable: webhook delivery (the async queue)
	 * and any non-request-path gate — NEVER as a REST validate_callback (use
	 * {@see self::validate_format()} there).
	 *
	 * @param mixed $url The candidate URL.
	 * @return true|WP_Error True when the URL is safe to fetch, WP_Error otherwise.
	 */
	public static function validate( $url ): bool|WP_Error {
		$format = self::validate_format( $url );
		if ( is_wp_error( $format ) ) {
			return $format;
		}

		$parts = wp_parse_url( (string) $url );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$host  = trim( $host, '[]' );

		// Resolve the host to every address it answers with, and reject if ANY of
		// them is internal — a hostname that round-robins between a public and a
		// private record must not slip through.
		$ips = self::resolve( $host );

		if ( empty( $ips ) ) {
			return new WP_Error(
				'learnomy_webhook_url_unresolvable',
				__( 'The webhook host could not be resolved to an IP address.', 'learnomy' ),
				array( 'status' => 400 )
			);
		}

		foreach ( $ips as $ip ) {
			if ( self::is_blocked_ip( $ip ) ) {
				return self::blocked_error();
			}
		}

		return true;
	}

	/**
	 * Boolean convenience wrapper around {@see self::validate()} for delivery-time
	 * gates that only need a yes/no.
	 *
	 * @param mixed $url The candidate URL.
	 */
	public static function is_safe( $url ): bool {
		return true === self::validate( $url );
	}

	/**
	 * Resolve a host (which may already be an IP literal) to a flat list of IPs.
	 *
	 * @param string $host Lowercased, bracket-stripped host.
	 * @return string[] Resolved IP strings (may be empty).
	 */
	private static function resolve( string $host ): array {
		// Already an IP literal — nothing to resolve.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		$ips = array();

		// IPv4 (A) records.
		$a = @gethostbynamel( $host );
		if ( is_array( $a ) ) {
			$ips = array_merge( $ips, $a );
		}

		// IPv6 (AAAA) records, when the resolver supports them.
		if ( function_exists( 'dns_get_record' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA );
			if ( is_array( $aaaa ) ) {
				foreach ( $aaaa as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		// Last-ditch single-record lookup if the above found nothing.
		if ( empty( $ips ) ) {
			$single = gethostbyname( $host );
			if ( $single && $single !== $host ) {
				$ips[] = $single;
			}
		}

		return array_values( array_unique( array_filter( $ips ) ) );
	}

	/**
	 * Whether an IP falls inside any blocked range, or is otherwise not a global
	 * (public) address per PHP's own reserved/private range filters.
	 *
	 * @param string $ip Candidate IP literal.
	 */
	private static function is_blocked_ip( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			// Not a parseable IP — refuse it rather than risk an SSRF.
			return true;
		}

		// PHP's built-in private/reserved filters as a first, cheap pass.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		foreach ( self::BLOCKED_RANGES as $range ) {
			if ( self::ip_in_cidr( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Test whether an IP literal sits inside a CIDR block. Handles both IPv4 and
	 * IPv6 by comparing the leading $bits bits of the packed binary forms.
	 *
	 * @param string $ip   IP literal.
	 * @param string $cidr "network/prefix" string.
	 */
	public static function ip_in_cidr( string $ip, string $cidr ): bool {
		if ( false === strpos( $cidr, '/' ) ) {
			return false;
		}

		list( $subnet, $bits ) = explode( '/', $cidr, 2 );
		$bits                  = (int) $bits;

		$ip_bin     = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );

		if ( false === $ip_bin || false === $subnet_bin ) {
			return false;
		}

		// Different families (v4 vs v6) can never match.
		if ( strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$bytes     = intdiv( $bits, 8 );
		$remainder = $bits % 8;

		// Compare the whole bytes.
		if ( $bytes > 0 && 0 !== substr_compare( $ip_bin, $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		// Compare the partial trailing byte, if any.
		if ( $remainder > 0 ) {
			$mask    = ~( 0xFF >> $remainder ) & 0xFF;
			$ip_byte = ord( $ip_bin[ $bytes ] ) & $mask;
			$sn_byte = ord( $subnet_bin[ $bytes ] ) & $mask;

			if ( $ip_byte !== $sn_byte ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The single rejection error so the message is identical at save and delivery.
	 */
	private static function blocked_error(): WP_Error {
		return new WP_Error(
			'learnomy_webhook_url_internal',
			__( 'Webhook URLs that resolve to internal, loopback, or cloud-metadata addresses are not allowed.', 'learnomy' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Redact known cloud-credential fields from a response body before it is
	 * stored or shown. Defence-in-depth for the case where a site owner has
	 * deliberately allow-listed an internal host through the scheme/host filters:
	 * even then we never persist raw IAM tokens to the delivery log.
	 *
	 * @param string $body Raw response body.
	 */
	public static function redact_secrets( string $body ): string {
		if ( '' === $body ) {
			return $body;
		}

		$patterns = array(
			// JSON-style "Key":"value".
			'/("(?:AccessKeyId|SecretAccessKey|Token|SessionToken|session_token|X-Amz-Security-Token)"\s*:\s*")[^"]*(")/i',
			// Header / form style Key: value or Key=value.
			'/((?:AccessKeyId|SecretAccessKey|Token|SessionToken|session_token|X-Amz-Security-Token)\s*[:=]\s*)[^\s,&;"]+/i',
		);

		$redacted = preg_replace( $patterns, '$1[redacted]$2', $body );

		return is_string( $redacted ) ? $redacted : $body;
	}
}
