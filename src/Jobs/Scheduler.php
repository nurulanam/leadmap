<?php
/**
 * Job scheduling. Uses Action Scheduler when a plugin on the site provides it
 * (WooCommerce, or Action Scheduler standalone) and falls back to WP-Cron otherwise,
 * so LeadMap ships with no vendored dependency but gains the better queue when present.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Jobs;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	public const GROUP = 'leadmap';

	public static function has_action_scheduler(): bool {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * Run a job as soon as a worker picks it up.
	 *
	 * @param array<int,mixed> $args
	 */
	public static function enqueue( string $hook, array $args = [] ): void {
		if ( self::has_action_scheduler() ) {
			as_enqueue_async_action( $hook, $args, self::GROUP );

			return;
		}

		// WP-Cron cannot hold two identical events in the same second, so nudge by one.
		$timestamp = time() + 1;

		while ( wp_next_scheduled( $hook, $args ) === $timestamp ) {
			++$timestamp;
		}

		wp_schedule_single_event( $timestamp, $hook, $args );
		self::spawn_cron();
	}

	/**
	 * Run a job after a delay in seconds.
	 *
	 * @param array<int,mixed> $args
	 */
	public static function enqueue_in( int $seconds, string $hook, array $args = [] ): void {
		if ( self::has_action_scheduler() ) {
			as_schedule_single_action( time() + $seconds, $hook, $args, self::GROUP );

			return;
		}

		wp_schedule_single_event( time() + $seconds, $hook, $args );
		self::spawn_cron();
	}

	/** @param array<int,mixed> $args */
	public static function is_queued( string $hook, array $args = [] ): bool {
		if ( self::has_action_scheduler() ) {
			return (bool) as_has_scheduled_action( $hook, $args, self::GROUP );
		}

		return (bool) wp_next_scheduled( $hook, $args );
	}

	/** @param array<int,mixed> $args */
	public static function cancel( string $hook, array $args = [] ): void {
		if ( self::has_action_scheduler() ) {
			as_unschedule_all_actions( $hook, $args, self::GROUP );

			return;
		}

		$timestamp = wp_next_scheduled( $hook, $args );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook, $args );
			$timestamp = wp_next_scheduled( $hook, $args );
		}
	}

	/** Ask WP to run due cron events now rather than waiting for the next page view. */
	private static function spawn_cron(): void {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

		add_action( 'shutdown', static function (): void {
			spawn_cron();
		}, 100 );
	}
}
