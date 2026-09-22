<?php
/**
 * A short-lived exclusive lock.
 *
 * Built on add_option() rather than a transient because the options table has a unique index
 * on option_name: the insert either succeeds or it does not, so two simultaneous requests
 * cannot both believe they hold the lock. A transient read-then-write can.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Jobs;

defined( 'ABSPATH' ) || exit;

final class Lock {

	private const PREFIX = 'leadmap_lock_';

	/** @return bool True when the lock was acquired. */
	public static function acquire( string $name, int $ttl = 120 ): bool {
		$key = self::PREFIX . $name;

		// add_option() fails when the row already exists — that is the atomic part.
		if ( add_option( $key, (string) ( time() + $ttl ), '', false ) ) {
			return true;
		}

		// A lock left behind by a crashed request must not block forever.
		$expires = (int) get_option( $key, 0 );

		if ( $expires > 0 && $expires < time() ) {
			delete_option( $key );

			return add_option( $key, (string) ( time() + $ttl ), '', false );
		}

		return false;
	}

	public static function release( string $name ): void {
		delete_option( self::PREFIX . $name );
	}

	public static function held( string $name ): bool {
		$expires = (int) get_option( self::PREFIX . $name, 0 );

		return $expires > time();
	}
}
