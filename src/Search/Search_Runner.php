<?php
/**
 * Executes a saved search against its provider.
 *
 * One job invocation fetches exactly one page and then re-enqueues itself for the next.
 * That keeps every request short, survives PHP timeouts, and lets a long search be
 * cancelled between pages.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Search;

use LeadMap\Events\Event_Repository;
use LeadMap\Jobs\Job_Runner;
use LeadMap\Jobs\Scheduler;
use LeadMap\Leads\Lead_Repository;
use LeadMap\Plugin;
use LeadMap\Providers\Google_Places_Provider;
use LeadMap\Providers\Raw_Place;
use LeadMap\Support\Logger;
use LeadMap\Support\Normalize;
use LeadMap\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Search_Runner {

	/** Google needs a moment before a nextPageToken becomes valid. */
	private const PAGE_DELAY_SECONDS = 3;

	public function run( int $search_id ): void {
		$search = Search_Repository::find( $search_id );

		if ( ! $search ) {
			return;
		}

		if ( in_array( $search->status, [ 'complete', 'failed', 'cancelled' ], true ) ) {
			return;
		}

		$provider = Plugin::instance()->providers()->get( (string) $search->provider );

		if ( ! $provider ) {
			Search_Repository::mark_failed(
				$search_id,
				sprintf( 'Unknown provider "%s".', (string) $search->provider )
			);

			return;
		}

		if ( ! $provider->is_configured() ) {
			Search_Repository::mark_failed(
				$search_id,
				__( 'The provider is missing its API key. Add one under LeadMap → Settings.', 'leadmap' )
			);

			return;
		}

		$cap = (float) Settings::get( 'monthly_spend_cap', 0 );

		if ( $cap > 0 && Search_Repository::spend_this_month() >= $cap ) {
			Search_Repository::mark_failed(
				$search_id,
				sprintf(
					/* translators: %s: the configured monthly cap, formatted. */
					__( 'Monthly API spend cap of $%s reached. Raise it under LeadMap → Settings to continue.', 'leadmap' ),
					number_format( $cap, 2 )
				)
			);

			return;
		}

		Search_Repository::update( $search_id, [ 'status' => 'running' ] );

		$params = json_decode( (string) $search->params_json, true );
		$params = is_array( $params ) ? $params : [];

		$query = Search_Query::from_array(
			array_merge(
				$params,
				[
					'industry'    => (string) $search->industry,
					'location'    => (string) $search->location,
					'zip'         => (string) $search->zip,
					'radius_m'    => (int) $search->radius_m,
					'max_results' => (int) $search->max_results,
				]
			)
		);

		// Resolve a center point once, on the first page, so locationBias can constrain results.
		if ( null === $query->lat && $provider instanceof Google_Places_Provider ) {
			$where = trim( (string) $search->zip . ' ' . (string) $search->location );

			if ( '' !== $where ) {
				$center = $provider->geocode( $where, $query->region_code );

				if ( is_wp_error( $center ) ) {
					// A failed geocode is not fatal — the text query still carries the location.
					Logger::info( 'Geocode failed, continuing without a location bias.', [ 'error' => $center->get_error_message() ] );
				} else {
					$query  = $query->with_center( $center['lat'], $center['lng'] );
					$params = array_merge( $params, [ 'lat' => $center['lat'], 'lng' => $center['lng'] ] );

					Search_Repository::update( $search_id, [ 'params_json' => wp_json_encode( $params ) ] );
				}
			}
		}

		$query  = $query->with_page_token( $search->next_page_token ?: null );
		$result = $provider->search( $query );

		if ( is_wp_error( $result ) ) {
			$this->fail( $search_id, $result );

			return;
		}

		$stats = $this->persist( $result->places, $search );

		$found = (int) $search->results_found + $result->count();
		$new   = (int) $search->results_new + $stats['new'];
		$pages = (int) $search->pages_fetched + 1;
		$cost  = round( (float) $search->api_cost + $result->cost, 4 );

		Search_Repository::update(
			$search_id,
			[
				'results_found'   => $found,
				'results_new'     => $new,
				'pages_fetched'   => $pages,
				'api_cost'        => $cost,
				'next_page_token' => $result->next_page_token,
			]
		);

		$reached_cap = $found >= (int) $search->max_results;

		if ( $result->has_more() && ! $reached_cap ) {
			Scheduler::enqueue_in( self::PAGE_DELAY_SECONDS, Job_Runner::SEARCH_RUN, [ $search_id ] );

			return;
		}

		Search_Repository::mark_complete( $search_id );

		Event_Repository::log(
			'search.completed',
			0,
			[
				'search_id' => $search_id,
				'found'     => $found,
				'new'       => $new,
				'pages'     => $pages,
				'cost'      => $cost,
			]
		);
	}

	/**
	 * @param Raw_Place[] $places
	 *
	 * @return array{new:int,duplicate:int}
	 */
	private function persist( array $places, object $search ): array {
		$dedupe = new Deduplicator();
		$region = (string) Settings::get( 'region_code', 'US' );

		$new       = 0;
		$duplicate = 0;

		foreach ( $places as $place ) {
			if ( '' === $place->external_id || '' === $place->name ) {
				continue;
			}

			$domain = Normalize::domain( $place->website );
			$phone  = Normalize::phone_e164( $place->phone, $region );

			if ( $dedupe->find_duplicate( $place->external_id, $domain, $phone ) ) {
				++$duplicate;

				continue;
			}

			$lead_id = Lead_Repository::insert(
				[
					'search_id'    => (int) $search->id,
					'provider'     => (string) $search->provider,
					'external_id'  => $place->external_id,
					'name'         => $place->name,
					'phone'        => $place->phone,
					'phone_e164'   => $phone,
					'website'      => $place->website,
					'domain'       => $domain,
					'address'      => $place->address,
					'city'         => $place->city,
					'state'        => $place->state,
					'zip'          => $place->zip,
					'country'      => $place->country,
					'lat'          => $place->lat,
					'lng'          => $place->lng,
					'category'     => $place->category,
					'rating'       => $place->rating,
					'review_count' => $place->review_count,
					'maps_url'     => $place->maps_url,
					'status'       => 'new',
				]
			);

			if ( $lead_id ) {
				$dedupe->remember( $lead_id, $place->external_id, $domain, $phone );
				++$new;

				/**
				 * A lead has been collected. Phase 2 hangs enrichment off this.
				 *
				 * @param int $lead_id
				 */
				do_action( 'leadmap_lead_created', $lead_id );
			} else {
				++$duplicate;
			}
		}

		return [
			'new'       => $new,
			'duplicate' => $duplicate,
		];
	}

	private function fail( int $search_id, WP_Error $error ): void {
		Logger::error( 'Search failed.', [ 'search_id' => $search_id, 'error' => $error->get_error_message() ] );

		Search_Repository::mark_failed( $search_id, $error->get_error_message() );

		Event_Repository::log( 'search.failed', 0, [ 'search_id' => $search_id, 'error' => $error->get_error_message() ] );
	}
}
