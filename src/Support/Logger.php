<?php
/**
 * Minimal leveled logger. Writes through error_log only when WP_DEBUG_LOG is on,
 * and mirrors errors into the events table so they are visible in admin.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Support;

defined( 'ABSPATH' ) || exit;

final class Logger {

	public static function error( string $message, array $context = [] ): void {
		self::write( 'error', $message, $context );
	}

	public static function info( string $message, array $context = [] ): void {
		self::write( 'info', $message, $context );
	}

	public static function debug( string $message, array $context = [] ): void {
		self::write( 'debug', $message, $context );
	}

	private static function write( string $level, string $message, array $context ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			if ( 'error' !== $level ) {
				return;
			}
		}

		$line = sprintf(
			'[leadmap][%s] %s %s',
			$level,
			$message,
			$context ? wp_json_encode( $context ) : ''
		);

		error_log( rtrim( $line ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
