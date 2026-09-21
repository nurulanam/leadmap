<?php
/**
 * Value normalizers used for deduplication. Two leads are the same business if their
 * place id, their normalized domain, or their E.164 phone match.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Support;

defined( 'ABSPATH' ) || exit;

final class Normalize {

	/** Hosts that are never a business's own site — a shared profile page is not a domain match. */
	private const GENERIC_HOSTS = [
		'facebook.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com',
		'yelp.com', 'google.com', 'business.site', 'sites.google.com',
		'wixsite.com', 'weebly.com', 'blogspot.com', 'wordpress.com',
		'linktr.ee', 'youtube.com', 'tiktok.com', 'squarespace.com',
	];

	/** Lowercased registrable host with www stripped; '' when the URL is unusable. */
	public static function domain( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}

		$host = strtolower( $host );
		$host = preg_replace( '/^www\./', '', $host ) ?? $host;

		// parse_url() is permissive — it will hand back "not a url" as a host. A real
		// domain is label(.label)+ with a TLD, so anything else is discarded rather than
		// stored, where it could false-match another lead during deduplication.
		if ( ! preg_match( '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host ) ) {
			return '';
		}

		return $host;
	}

	/** True when the host is a shared platform, so it must not be used as a dedupe key. */
	public static function is_generic_host( string $domain ): bool {
		if ( '' === $domain ) {
			return true;
		}

		foreach ( self::GENERIC_HOSTS as $generic ) {
			if ( $domain === $generic || str_ends_with( $domain, '.' . $generic ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Best-effort E.164. Google returns an international number for most places; when it
	 * does not, we fall back to the national number plus the search region's calling code.
	 */
	public static function phone_e164( string $phone, string $region = 'US' ): string {
		$digits = preg_replace( '/[^0-9+]/', '', $phone ) ?? '';

		if ( '' === $digits ) {
			return '';
		}

		if ( str_starts_with( $digits, '+' ) ) {
			$rest = preg_replace( '/[^0-9]/', '', substr( $digits, 1 ) ) ?? '';

			return '' === $rest ? '' : '+' . $rest;
		}

		$digits = preg_replace( '/[^0-9]/', '', $digits ) ?? '';

		if ( 'US' === $region || 'CA' === $region ) {
			if ( 10 === strlen( $digits ) ) {
				return '+1' . $digits;
			}

			if ( 11 === strlen( $digits ) && str_starts_with( $digits, '1' ) ) {
				return '+' . $digits;
			}
		}

		return '' === $digits ? '' : '+' . $digits;
	}

	/** Trims and hard-caps a string to a column width, multibyte safe. */
	public static function text( ?string $value, int $max ): string {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	/** Google place types are snake_case; render them as words. */
	public static function humanize_type( string $type ): string {
		return ucwords( str_replace( '_', ' ', trim( $type ) ) );
	}
}
