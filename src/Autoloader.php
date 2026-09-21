<?php
/**
 * PSR-4 autoloader. Avoids a Composer dependency for a plugin that ships no vendor libs.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private const PREFIX = 'LeadMap\\';

	public static function register(): void {
		spl_autoload_register( [ self::class, 'load' ] );
	}

	public static function load( string $class ): void {
		if ( ! str_starts_with( $class, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::PREFIX ) );
		$path     = LEADMAP_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
