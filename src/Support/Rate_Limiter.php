<?php
/**
 * A slot-based rate limiter for outbound APIs.
 *
 * Checking and claiming are deliberately separate. A job that cannot go yet only *asks* how
 * long to wait and reschedules itself; it claims a slot only when it actually makes the call.
 * If merely asking consumed a slot, every deferred job would push the queue further out each
 * time it woke up, and the wait would grow without bound.
 *
 * Because only real calls advance the marker, the wait is never longer than one interval.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Support;

defined( 'ABSPATH' ) || exit;

final class Rate_Limiter {

	private const PREFIX = 'leadmap_rl_';

	/** Seconds between calls at the given rate. */
	public static function interval( float $per_minute ): int {
		return (int) ceil( 60 / max( 0.1, $per_minute ) );
	}

	/**
	 * How long until this bucket may be used. Does not claim anything.
	 *
	 * @return int Seconds to wait. 0 means go now.
	 */
	public static function wait_for( string $bucket ): int {
		$next = (int) get_option( self::PREFIX . $bucket, 0 );

		return (int) max( 0, $next - time() );
	}

	/** Claim a slot. Call this immediately before making the request. */
	public static function consume( string $bucket, float $per_minute ): void {
		$now  = time();
		$next = max( (int) get_option( self::PREFIX . $bucket, 0 ), $now );

		update_option( self::PREFIX . $bucket, $next + self::interval( $per_minute ), false );
	}

	/** Push the next slot out after a provider has told us to back off. */
	public static function penalise( string $bucket, int $seconds ): void {
		$next = max( (int) get_option( self::PREFIX . $bucket, 0 ), time() ) + max( 1, $seconds );

		update_option( self::PREFIX . $bucket, $next, false );
	}

	public static function reset( string $bucket ): void {
		delete_option( self::PREFIX . $bucket );
	}
}
