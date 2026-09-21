<?php
/**
 * SSRF defence for the crawler (CWE-918).
 *
 * The crawler fetches URLs that came from a third-party API and are therefore untrusted.
 * A host allow-list is not possible — crawling arbitrary business websites is the whole
 * feature — so this validates in the other direction: the scheme, port and shape of the URL
 * must be acceptable, and *every* IP the hostname resolves to must be publicly routable.
 *
 * Residual risk: DNS rebinding. The name is resolved here and may resolve differently when
 * the socket is actually opened. Fully closing that needs connecting to a pinned IP with a
 * forced Host header, which the WP HTTP API cannot express. It is mitigated by validating
 * every redirect hop and by the response size and timeout caps in Http_Fetcher, and it is
 * the same exposure carried by WordPress core's own wp_safe_remote_get().
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Url_Guard {

	/** Only these schemes may ever be fetched. */
	private const ALLOWED_SCHEMES = [ 'http', 'https' ];

	/** Only these ports. Anything else is a service we have no business talking to. */
	private const ALLOWED_PORTS = [ 80, 443 ];

	/** Hostnames that never denote a public website. */
	private const BLOCKED_HOSTS = [
		'localhost', 'localhost.localdomain', 'ip6-localhost', 'ip6-loopback',
		'metadata', 'metadata.google.internal', 'instance-data',
	];

	/** Suffixes reserved for private networks and service discovery. */
	private const BLOCKED_SUFFIXES = [
		'.local', '.localhost', '.internal', '.intranet', '.private',
		'.corp', '.home', '.lan', '.test', '.example', '.invalid', '.onion',
	];

	/**
	 * CIDR blocks that must never be reached, beyond what PHP's own filters catch.
	 * Chiefly the cloud metadata endpoints, which are the highest-value SSRF target.
	 */
	private const BLOCKED_V4_CIDRS = [
		'0.0.0.0/8',          // "This host on this network".
		'10.0.0.0/8',         // RFC 1918 private.
		'100.64.0.0/10',      // Carrier-grade NAT.
		'127.0.0.0/8',        // Loopback.
		'169.254.0.0/16',     // Link-local, includes 169.254.169.254 cloud metadata.
		'172.16.0.0/12',      // RFC 1918 private.
		'192.0.0.0/24',       // IETF protocol assignments.
		'192.0.2.0/24',       // TEST-NET-1.
		'192.168.0.0/16',     // RFC 1918 private.
		'198.18.0.0/15',      // Benchmarking.
		'198.51.100.0/24',    // TEST-NET-2.
		'203.0.113.0/24',     // TEST-NET-3.
		'224.0.0.0/4',        // Multicast.
		'240.0.0.0/4',        // Reserved, includes 255.255.255.255.
	];

	private const BLOCKED_V6_PREFIXES = [
		'::',       // Unspecified and loopback (::1).
		'fc',       // Unique local fc00::/7.
		'fd',       // Unique local.
		'fe8', 'fe9', 'fea', 'feb', // Link-local fe80::/10.
		'ff',       // Multicast.
		'2001:db8', // Documentation.
		'64:ff9b',  // NAT64, can be used to reach v4 private space.
	];

	/**
	 * Validate a URL before it is fetched.
	 *
	 * @return string|WP_Error The normalized URL that is safe to request.
	 */
	public static function validate( string $url ): string|WP_Error {
		// Trim ordinary whitespace only. PHP's default trim() also strips NUL, which would
		// quietly sanitize a null-terminated URL into a valid one instead of rejecting it.
		$url = trim( $url, " \t\n\r\x0B" );

		if ( '' === $url || strlen( $url ) > 2048 ) {
			return new WP_Error( 'leadmap_url_invalid', 'The URL is empty or unreasonably long.' );
		}

		// Control characters can smuggle a second request past a downstream parser.
		if ( preg_match( '/[\x00-\x1F\x7F]/', $url ) ) {
			return new WP_Error( 'leadmap_url_invalid', 'The URL contains control characters.' );
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'leadmap_url_invalid', 'The URL could not be parsed.' );
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( ! in_array( $scheme, self::ALLOWED_SCHEMES, true ) ) {
			return new WP_Error( 'leadmap_url_scheme', 'Only http and https URLs may be fetched.' );
		}

		// Credentials in a URL are a redirect-confusion trick, never a real website.
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'leadmap_url_userinfo', 'URLs carrying credentials are not fetched.' );
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		if ( ! in_array( $port, self::ALLOWED_PORTS, true ) ) {
			return new WP_Error( 'leadmap_url_port', 'Only ports 80 and 443 may be fetched.' );
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );

		if ( '' === $host ) {
			return new WP_Error( 'leadmap_url_invalid', 'The URL has no host.' );
		}

		if ( in_array( $host, self::BLOCKED_HOSTS, true ) ) {
			return new WP_Error( 'leadmap_url_blocked', 'That hostname is not a public website.' );
		}

		foreach ( self::BLOCKED_SUFFIXES as $suffix ) {
			if ( str_ends_with( $host, $suffix ) ) {
				return new WP_Error( 'leadmap_url_blocked', 'That hostname is in a reserved namespace.' );
			}
		}

		$ips = self::resolve( $host );

		if ( is_wp_error( $ips ) ) {
			return $ips;
		}

		// Every address must be public. One private answer blocks the whole host, so a
		// split-horizon or multi-record name cannot be used to slip through.
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return new WP_Error(
					'leadmap_url_private',
					'That hostname resolves to a private or reserved address.'
				);
			}
		}

		return $url;
	}

	/**
	 * Resolve a hostname to every address it answers with.
	 *
	 * @return string[]|WP_Error
	 */
	private static function resolve( string $host ): array|WP_Error {
		// A bare IP in the URL skips DNS but still has to pass the range check.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return [ $host ];
		}

		// Strip the brackets of a literal IPv6 host.
		if ( str_starts_with( $host, '[' ) && str_ends_with( $host, ']' ) ) {
			$inner = substr( $host, 1, -1 );

			return filter_var( $inner, FILTER_VALIDATE_IP )
				? [ $inner ]
				: new WP_Error( 'leadmap_url_invalid', 'Malformed IPv6 host.' );
		}

		if ( ! preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host ) ) {
			return new WP_Error( 'leadmap_url_invalid', 'That is not a valid public hostname.' );
		}

		$ips = [];

		$v4 = gethostbynamel( $host );

		if ( is_array( $v4 ) ) {
			$ips = $v4;
		}

		// AAAA records matter: a host with a public A and a loopback AAAA must be blocked.
		$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( is_array( $v6 ) ) {
			foreach ( $v6 as $record ) {
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = (string) $record['ipv6'];
				}
			}
		}

		if ( ! $ips ) {
			return new WP_Error( 'leadmap_url_dns', 'That hostname does not resolve.' );
		}

		return array_unique( $ips );
	}

	/** True only when the address is globally routable. */
	public static function is_public_ip( string $ip ): bool {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		// PHP's own filter catches the bulk of private and reserved space.
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			foreach ( self::BLOCKED_V4_CIDRS as $cidr ) {
				if ( self::ip_in_cidr( $ip, $cidr ) ) {
					return false;
				}
			}

			return true;
		}

		$normalized = strtolower( (string) inet_ntop( (string) inet_pton( $ip ) ) );

		// An IPv4-mapped IPv6 address (::ffff:127.0.0.1) must be judged as its IPv4 form.
		if ( preg_match( '/^::ffff:(\d+\.\d+\.\d+\.\d+)$/', $normalized, $m ) ) {
			return self::is_public_ip( $m[1] );
		}

		if ( '::1' === $normalized || '::' === $normalized ) {
			return false;
		}

		foreach ( self::BLOCKED_V6_PREFIXES as $prefix ) {
			if ( str_starts_with( $normalized, $prefix ) ) {
				return false;
			}
		}

		return true;
	}

	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		[ $subnet, $bits ] = explode( '/', $cidr );

		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );

		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}

		$mask = -1 << ( 32 - (int) $bits );

		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}
}
