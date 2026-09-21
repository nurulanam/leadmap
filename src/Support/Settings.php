<?php
/**
 * Typed accessor over the single leadmap_settings option.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Support;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'leadmap_settings';

	/** @var array<string,mixed>|null */
	private static ?array $cache = null;

	public const DEFAULTS = [
		'google_api_key'      => '',
		'provider'            => 'google_places',
		'default_radius_m'    => 5000,
		'default_max_results' => 60,
		'region_code'         => 'US',
		'language_code'       => 'en',
		'monthly_spend_cap'   => 50.00,
		'log_level'           => 'error',
	];

	/** @return array<string,mixed> */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored     = get_option( self::OPTION, [] );
			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : [], self::DEFAULTS );
		}

		return self::$cache;
	}

	public static function get( string $key, mixed $default = null ): mixed {
		$all = self::all();

		return $all[ $key ] ?? $default ?? ( self::DEFAULTS[ $key ] ?? null );
	}

	/** @param array<string,mixed> $values */
	public static function update( array $values ): void {
		$all = array_merge( self::all(), $values );

		update_option( self::OPTION, $all, false );
		self::$cache = null;
	}

	/**
	 * The Google API key, preferring the wp-config constant so it never touches the database.
	 */
	public static function google_api_key(): string {
		if ( defined( 'LEADMAP_GOOGLE_API_KEY' ) && '' !== (string) LEADMAP_GOOGLE_API_KEY ) {
			return (string) LEADMAP_GOOGLE_API_KEY;
		}

		return Encryption::decrypt( (string) self::get( 'google_api_key', '' ) );
	}

	public static function key_is_from_constant(): bool {
		return defined( 'LEADMAP_GOOGLE_API_KEY' ) && '' !== (string) LEADMAP_GOOGLE_API_KEY;
	}

	public static function flush(): void {
		self::$cache = null;
	}
}
