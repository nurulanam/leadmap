<?php
/**
 * Binds job hooks to their handlers, and chains the pipeline: a new lead is enriched,
 * and an enriched lead with a live site gets a PageSpeed measurement.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Jobs;

use LeadMap\Enrich\Enrichment_Service;
use LeadMap\Enrich\Speed_Analyzer;
use LeadMap\Enrich\Speed_Status;
use LeadMap\Leads\Lead_Repository;
use LeadMap\Search\Search_Runner;
use LeadMap\Support\Logger;
use LeadMap\Support\Rate_Limiter;
use LeadMap\Support\Settings;
use LeadMap\Triage\Triage_Service;

defined( 'ABSPATH' ) || exit;

final class Job_Runner {

	public const SEARCH_RUN  = 'leadmap/search/run';
	public const LEAD_ENRICH = 'leadmap/lead/enrich';
	public const LEAD_SPEED  = 'leadmap/lead/speed';

	/** Give up on a rate-limited measurement after this many backoffs. */
	private const MAX_SPEED_ATTEMPTS = 5;

	public function register(): void {
		add_action( self::SEARCH_RUN, [ $this, 'run_search' ], 10, 1 );
		add_action( self::LEAD_ENRICH, [ $this, 'run_enrich' ], 10, 1 );
		add_action( self::LEAD_SPEED, [ $this, 'run_speed' ], 10, 3 );

		// A newly collected lead enriches itself, unless the operator turned that off.
		add_action( 'leadmap_lead_created', [ $this, 'on_lead_created' ], 10, 1 );
	}

	public function run_search( int $search_id = 0 ): void {
		if ( $search_id > 0 ) {
			( new Search_Runner() )->run( $search_id );
		}
	}

	public function on_lead_created( int $lead_id ): void {
		if ( ! Settings::get( 'auto_enrich', true ) ) {
			return;
		}

		self::queue_enrich( $lead_id );
	}

	public static function queue_enrich( int $lead_id ): void {
		if ( $lead_id > 0 && ! Scheduler::is_queued( self::LEAD_ENRICH, [ $lead_id ] ) ) {
			Scheduler::enqueue( self::LEAD_ENRICH, [ $lead_id ] );
		}
	}

	public function run_enrich( int $lead_id = 0 ): void {
		if ( $lead_id <= 0 ) {
			return;
		}

		( new Enrichment_Service() )->enrich( $lead_id );

		// Auto-triage sees a fully enriched lead, so the unambiguous cases never reach the
		// queue. Off by default; every automatic verdict is logged and reversible.
		$lead = Lead_Repository::find( $lead_id );

		if ( $lead ) {
			$service = new Triage_Service();
			$verdict = $service->auto_verdict( $lead );

			if ( '' !== $verdict ) {
				$service->decide( $lead_id, [ $verdict ], '', true );
			}
		}

		if ( ! Settings::get( 'auto_pagespeed', true ) ) {
			return;
		}

		$lead = Lead_Repository::find( $lead_id );

		// Only measure a site that actually answered — PageSpeed on a dead URL wastes a minute.
		if ( ! $lead || '' === (string) $lead->website ) {
			return;
		}

		$enrichment = json_decode( (string) $lead->enrichment_json, true );

		if ( ! is_array( $enrichment ) || ! empty( $enrichment['unreachable'] ) ) {
			return;
		}

		$status = (int) ( $enrichment['http']['status'] ?? 0 );

		if ( $status < 200 || $status >= 400 ) {
			return;
		}

		// Both strategies, like PageSpeed Insights itself. Mobile is what drives the
		// staleness score, but desktop is what the owner sees on their own machine — and a
		// large gap between the two is itself the argument.
		self::queue_speed( $lead_id );
	}

	/** Queue both strategies and mark them so a blank score is never ambiguous. */
	public static function queue_speed( int $lead_id ): void {
		foreach ( Speed_Status::STRATEGIES as $strategy ) {
			Speed_Status::set( $lead_id, $strategy, Speed_Status::QUEUED );
			Scheduler::enqueue( self::LEAD_SPEED, [ $lead_id, $strategy, 0 ] );
		}
	}

	/**
	 * Measure one strategy for one lead.
	 *
	 * PageSpeed is the tightest budget we spend. Unauthenticated it allows only a handful of
	 * requests per minute, and we ask for two per lead, so requests are spaced through a
	 * shared slot queue and a refusal backs off rather than being recorded as a failure.
	 */
	public function run_speed( int $lead_id = 0, string $strategy = 'mobile', int $attempt = 0 ): void {
		if ( $lead_id <= 0 ) {
			return;
		}

		$strategy = 'desktop' === $strategy ? 'desktop' : 'mobile';

		$lead = Lead_Repository::find( $lead_id );

		if ( ! $lead || '' === (string) $lead->website ) {
			return;
		}

		// Wait for a free slot rather than firing into a limit we know we would hit.
		// Asking costs nothing; only the call itself claims the slot.
		$rate = (float) Settings::get( 'pagespeed_per_minute', 4 );
		$wait = Rate_Limiter::wait_for( 'pagespeed' );

		if ( $wait > 0 ) {
			Speed_Status::set( $lead_id, $strategy, Speed_Status::WAITING );
			Scheduler::enqueue_in( $wait, self::LEAD_SPEED, [ $lead_id, $strategy, $attempt ] );

			return;
		}

		Rate_Limiter::consume( 'pagespeed', $rate );
		Speed_Status::set( $lead_id, $strategy, Speed_Status::RUNNING );

		$result = ( new Speed_Analyzer() )->analyze( (string) $lead->website, $strategy );

		if ( ! is_wp_error( $result ) ) {
			( new Enrichment_Service() )->apply_speed( $lead_id, $result, $strategy );
			Speed_Status::set( $lead_id, $strategy, Speed_Status::DONE );

			return;
		}

		// A rate-limit refusal is temporary. Back the whole queue off and try this lead again
		// later, rather than marking it failed and losing the measurement for good.
		$retryable = self::is_rate_limited( $result ) || 'leadmap_psi_timeout' === $result->get_error_code();

		if ( $retryable && $attempt < self::MAX_SPEED_ATTEMPTS ) {
			// A timeout is worth trying again sooner than a rate limit: nothing is throttling
			// us, the page was simply slow on that run.
			$backoff = self::is_rate_limited( $result )
				? (int) min( 900, 60 * ( 2 ** $attempt ) )
				: (int) min( 300, 30 * ( $attempt + 1 ) );

			if ( self::is_rate_limited( $result ) ) {
				Rate_Limiter::penalise( 'pagespeed', $backoff );
			}

			Speed_Status::set(
				$lead_id,
				$strategy,
				Speed_Status::WAITING,
				self::is_rate_limited( $result )
					? sprintf(
						/* translators: %d: minutes until the next attempt. */
						__( 'Rate limited — retrying in about %d minutes', 'leadmap' ),
						(int) max( 1, round( $backoff / 60 ) )
					)
					: sprintf(
						/* translators: %d: seconds until the next attempt. */
						__( 'Google timed out on this page — retrying in %d seconds', 'leadmap' ),
						$backoff
					)
			);
			Scheduler::enqueue_in( $backoff, self::LEAD_SPEED, [ $lead_id, $strategy, $attempt + 1 ] );

			Logger::info(
				'PageSpeed rate limited; backing off.',
				[ 'lead' => $lead_id, 'strategy' => $strategy, 'attempt' => $attempt, 'retry_in' => $backoff ]
			);

			return;
		}

		Logger::error(
			'PageSpeed measurement failed.',
			[ 'lead' => $lead_id, 'strategy' => $strategy, 'error' => $result->get_error_message() ]
		);

		// Store the reason so the lead screen can explain the blank instead of showing
		// nothing at all, which looks like the job never ran.
		( new Enrichment_Service() )->record_speed_failure( $lead_id, $strategy, $result->get_error_message() );
		Speed_Status::set( $lead_id, $strategy, Speed_Status::FAILED, $result->get_error_message() );
	}

	private static function is_rate_limited( \WP_Error $error ): bool {
		if ( 'leadmap_http_429' === $error->get_error_code() ) {
			return true;
		}

		$message = strtolower( $error->get_error_message() );

		foreach ( [ 'rate limit', 'ratelimit', 'quota exceeded', 'too many requests' ] as $needle ) {
			if ( str_contains( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}
