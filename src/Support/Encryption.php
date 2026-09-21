<?php
/**
 * AES-256-GCM at-rest encryption for API keys, keyed off the site's salts.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Support;

defined( 'ABSPATH' ) || exit;

final class Encryption {

	private const METHOD = 'aes-256-gcm';
	private const PREFIX = 'lm1:';

	public static function available(): bool {
		return function_exists( 'openssl_encrypt' )
			&& in_array( self::METHOD, openssl_get_cipher_methods(), true );
	}

	public static function encrypt( string $plain ): string {
		if ( '' === $plain || ! self::available() ) {
			return $plain;
		}

		$iv  = random_bytes( 12 );
		$tag = '';

		$cipher = openssl_encrypt( $plain, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $cipher ) {
			return $plain;
		}

		return self::PREFIX . base64_encode( $iv . $tag . $cipher );
	}

	public static function decrypt( string $stored ): string {
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			return $stored; // Legacy or never encrypted.
		}

		if ( ! self::available() ) {
			return '';
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );

		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );

		$plain = openssl_decrypt( $cipher, self::METHOD, self::key(), OPENSSL_RAW_DATA, $iv, $tag );

		return is_string( $plain ) ? $plain : '';
	}

	/** Masked form for display: never send a decrypted key back to the browser. */
	public static function mask( string $plain ): string {
		$len = strlen( $plain );

		if ( 0 === $len ) {
			return '';
		}

		return str_repeat( '•', 8 ) . ( $len > 4 ? substr( $plain, -4 ) : '' );
	}

	private static function key(): string {
		$material = ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' )
			. ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );

		return hash( 'sha256', 'leadmap|' . $material, true );
	}
}
