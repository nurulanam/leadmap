<?php
/**
 * Unsticks leads whose enrichment never finished.
 *
 * A PHP fatal, a killed worker or a hosting timeout leaves a lead sitting in `enriching`
 * forever: it shows as in-progress, is skipped by anything that looks for unenriched leads,
 * and never retries. This sweeps those up on a schedule and puts them back into play.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Jobs;

use LeadMap\Events\Event_Repository;
use LeadMap\Install\Schema;
use LeadMap\Leads\Lead_Repository;
use LeadMap\Support\Logger;
use LeadMap\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Watchdog {

	public const HOOK = 'leadmap/maintenance/unstick';

	/** How many stuck leads to recover in one sweep. */
	private const BATCH = 50;

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'schedule' ] );
	}

	/** Keep an hourly sweep on the calendar. */
	public function schedule(): void {
		if ( Scheduler::has_action_scheduler() ) {
			if ( ! as_has_scheduled_action( self::HOOK, [], Scheduler::GROUP ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, self::HOOK, [], Scheduler::GROUP );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	public function run(): void {
		global $wpdb;

		$minutes = max( 5, (int) Settings::get( 'stuck_after_minutes', 15 ) );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $minutes * MINUTE_IN_SECONDS ) );
		$table   = Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$stuck = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, website FROM {$table} WHERE status = %s AND updated_at < %s ORDER BY id ASC LIMIT %d",
				'enriching',
				$cutoff,
				self::BATCH
			)
		);

		if ( ! $stuck ) {
			return;
		}

		foreach ( $stuck as $lead ) {
			$this->recover( (int) $lead->id, $minutes );
		}

		Logger::error(
			'Recovered leads stuck in enrichment.',
			[ 'count' => count( $stuck ), 'stuck_after_minutes' => $minutes ]
		);
	}

	/**
	 * Move one lead out of `enriching`. It is marked enriched with the reason recorded, so
	 * it stops blocking the pipeline, and requeued once so a transient failure self-heals.
	 */
	private function recover( int $lead_id, int $minutes ): void {
		$lead = Lead_Repository::find( $lead_id );

		if ( ! $lead ) {
			return;
		}

		$enrichment = json_decode( (string) $lead->enrichment_json, true );
		$enrichment = is_array( $enrichment ) ? $enrichment : [];

		$retries = (int) ( $enrichment['enrich_retries'] ?? 0 );

		// One automatic retry. A lead that stalls twice has a real problem — usually a site
		// that accepts the connection and then never responds — so stop burning jobs on it.
		if ( $retries < 1 ) {
			$enrichment['enrich_retries'] = $retries + 1;

			Lead_Repository::update(
				$lead_id,
				[
					'status'          => 'new',
					'enrichment_json' => wp_json_encode( $enrichment ),
				]
			);

			Event_Repository::log( 'lead.enrich_stalled', $lead_id, [ 'retry' => $retries + 1 ] );
			Job_Runner::queue_enrich( $lead_id );

			return;
		}

		$enrichment['unreachable'] = true;
		$enrichment['error']       = sprintf(
			/* translators: %d: the timeout in minutes. */
			__( 'Enrichment did not finish within %d minutes and was abandoned. The site most likely accepts connections but never responds.', 'leadmap' ),
			$minutes
		);

		Lead_Repository::update(
			$lead_id,
			[
				'status'          => 'enriched',
				'enrichment_json' => wp_json_encode( $enrichment ),
				'enriched_at'     => current_time( 'mysql', true ),
			]
		);

		Event_Repository::log( 'lead.enrich_timeout', $lead_id, [ 'minutes' => $minutes ] );
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], Scheduler::GROUP );
		}

		$timestamp = wp_next_scheduled( self::HOOK );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}
}
