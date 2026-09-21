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
use LeadMap\Leads\Lead_Repository;
use LeadMap\Search\Search_Runner;
use LeadMap\Support\Logger;
use LeadMap\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Job_Runner {

	public const SEARCH_RUN  = 'leadmap/search/run';
	public const LEAD_ENRICH = 'leadmap/lead/enrich';
	public const LEAD_SPEED  = 'leadmap/lead/speed';

	public function register(): void {
		add_action( self::SEARCH_RUN, [ $this, 'run_search' ], 10, 1 );
		add_action( self::LEAD_ENRICH, [ $this, 'run_enrich' ], 10, 1 );
		add_action( self::LEAD_SPEED, [ $this, 'run_speed' ], 10, 2 );

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
		Scheduler::enqueue( self::LEAD_SPEED, [ $lead_id, 'mobile' ] );
		Scheduler::enqueue( self::LEAD_SPEED, [ $lead_id, 'desktop' ] );
	}

	public function run_speed( int $lead_id = 0, string $strategy = 'mobile' ): void {
		if ( $lead_id <= 0 ) {
			return;
		}

		$strategy = 'desktop' === $strategy ? 'desktop' : 'mobile';

		$lead = Lead_Repository::find( $lead_id );

		if ( ! $lead || '' === (string) $lead->website ) {
			return;
		}

		$result = ( new Speed_Analyzer() )->analyze( (string) $lead->website, $strategy );

		if ( is_wp_error( $result ) ) {
			Logger::error(
				'PageSpeed measurement failed.',
				[ 'lead' => $lead_id, 'strategy' => $strategy, 'error' => $result->get_error_message() ]
			);

			// Store the reason so the lead screen can explain the blank instead of showing
			// nothing at all, which looks like the job never ran.
			( new Enrichment_Service() )->record_speed_failure( $lead_id, $strategy, $result->get_error_message() );

			return;
		}

		( new Enrichment_Service() )->apply_speed( $lead_id, $result, $strategy );
	}
}
