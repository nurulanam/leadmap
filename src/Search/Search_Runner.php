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

	/**
	 * Stop after this many consecutive pages that returned nothing at all.
	 *
	 * Google will hand back a nextPageToken alongside an empty result set, indefinitely. A
	 * page with no businesses on it means the results are exhausted, so continuing only
	 * spends money. One such page is enough to know.
	 */
	private const MAX_EMPTY_PAGES = 1;

	/** Stop after this many consecutive pages whose results were all already known. */
	private const MAX_BARREN_PAGES = 3;

	/**
	 * Absolute ceiling on pages per search, whatever else happens.
	 *
	 * Text Search returns at most 60 results, so a well-behaved search never needs more than
	 * three pages. This is the backstop for when the provider misbehaves.
	 */
	private const HARD_PAGE_CAP = 10;

	public function run( int $search_id ): void {
		$search = Search_Repository::find( $search_id );

		if ( ! $search ) {
			return;
		}

		// A search stopped by the operator must not be restarted by a job already in flight,
		// by the watchdog, or by a poll that arrives a moment later.
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

		$first_page = 0 === (int) $search->pages_fetched;

		if ( $first_page ) {
			Search_Repository::log(
				$search_id,
				sprintf(
					/* translators: 1: industry, 2: location. */
					__( 'Starting search for "%1$s" in %2$s', 'leadmap' ),
					(string) $search->industry,
					(string) ( $search->zip ?: $search->location )
				)
			);
		}

		Search_Repository::update(
			$search_id,
			[
				'status'           => 'running',
				'last_progress_at' => current_time( 'mysql', true ),
			]
		);

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

					Search_Repository::log(
						$search_id,
						__( 'Could not pin the location exactly; searching by name instead', 'leadmap' ),
						'warn'
					);
				} else {
					Search_Repository::log(
						$search_id,
						sprintf(
							/* translators: 1: latitude, 2: longitude, 3: radius in km. */
							__( 'Located %1$s, %2$s — searching within %3$s km', 'leadmap' ),
							number_format( $center['lat'], 4 ),
							number_format( $center['lng'], 4 ),
							number_format( (int) $search->radius_m / 1000, 1 )
						),
						'good'
					);

					$query  = $query->with_center( $center['lat'], $center['lng'] );
					$params = array_merge( $params, [ 'lat' => $center['lat'], 'lng' => $center['lng'] ] );

					Search_Repository::update( $search_id, [ 'params_json' => wp_json_encode( $params ) ] );
				}
			}
		}

		$query = $query->with_page_token( $search->next_page_token ?: null );

		Search_Repository::log(
			$search_id,
			sprintf(
				/* translators: %d: the page number being requested. */
				__( 'Requesting results page %d from Google…', 'leadmap' ),
				(int) $search->pages_fetched + 1
			)
		);

		$result = $provider->search( $query );

		if ( is_wp_error( $result ) ) {
			$this->fail( $search_id, $result );

			return;
		}

		$stats = $this->persist( $result->places, $search );

		Search_Repository::log(
			$search_id,
			sprintf(
				/* translators: 1: results on this page, 2: new businesses, 3: duplicates. */
				__( 'Page returned %1$d businesses — %2$d new, %3$d already known', 'leadmap' ),
				$result->count(),
				$stats['new'],
				$stats['duplicate']
			),
			$stats['new'] > 0 ? 'good' : 'info'
		);

		$found = (int) $search->results_found + $result->count();
		$new   = (int) $search->results_new + $stats['new'];
		$pages = (int) $search->pages_fetched + 1;
		$cost  = round( (float) $search->api_cost + $result->cost, 4 );

		Search_Repository::update(
			$search_id,
			[
				'results_found'    => $found,
				'results_new'      => $new,
				'pages_fetched'    => $pages,
				'api_cost'         => $cost,
				'next_page_token'  => $result->next_page_token,
				'last_progress_at' => current_time( 'mysql', true ),
			]
		);

		// --- Decide whether to ask for another page ------------------------------------
		// Google will keep issuing page tokens after it has run out of businesses, so the
		// result cap alone is not a stop condition: with no new results it is never reached.
		$empty  = 0 === $result->count();
		$barren = ! $empty && 0 === $stats['new'];

		$empty_streak  = $empty ? (int) $search->empty_pages + 1 : 0;
		$barren_streak = $barren ? (int) $search->barren_pages + 1 : 0;

		Search_Repository::update(
			$search_id,
			[
				'empty_pages'  => $empty_streak,
				'barren_pages' => $barren_streak,
			]
		);

		$page_budget = max( 1, (int) ceil( (int) $search->max_results / max( 1, $provider->page_size() ) ) );
		$cost_cap    = (float) Settings::get( 'max_cost_per_search', 0.50 );

		$stop = match ( true ) {
			! $result->has_more()                  => 'exhausted',
			$found >= (int) $search->max_results    => 'reached_limit',
			$empty_streak >= self::MAX_EMPTY_PAGES  => 'empty_pages',
			$barren_streak >= self::MAX_BARREN_PAGES => 'no_new_results',
			$pages >= $page_budget                  => 'page_budget',
			$pages >= self::HARD_PAGE_CAP           => 'page_cap',
			$cost_cap > 0 && $cost >= $cost_cap     => 'cost_cap',
			default                                 => '',
		};

		if ( '' === $stop ) {
			Search_Repository::log( $search_id, __( 'More results available — fetching the next page…', 'leadmap' ) );

			Scheduler::enqueue_in( self::PAGE_DELAY_SECONDS, Job_Runner::SEARCH_RUN, [ $search_id ] );

			return;
		}

		$this->log_stop_reason( $search_id, $stop, $search, $pages, $cost );

		Search_Repository::update( $search_id, [ 'stop_reason' => $stop ] );

		Search_Repository::log(
			$search_id,
			sprintf(
				/* translators: 1: new leads, 2: total seen, 3: cost. */
				__( 'Finished — %1$d new leads from %2$d results, $%3$s spent', 'leadmap' ),
				$new,
				$found,
				number_format( $cost, 2 )
			),
			'good'
		);

		if ( $new > 0 ) {
			Search_Repository::log( $search_id, __( 'Enriching websites in the background to find email addresses…', 'leadmap' ) );
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

	/** Say plainly why the search stopped, so a short result never looks like a failure. */
	private function log_stop_reason( int $search_id, string $reason, object $search, int $pages, float $cost ): void {
		$messages = [
			'exhausted'      => __( 'Google has no more results for this search', 'leadmap' ),
			'reached_limit'  => sprintf(
				/* translators: %d: the configured result cap. */
				__( 'Reached your limit of %d results. Raise it on the search form to collect more.', 'leadmap' ),
				(int) $search->max_results
			),
			'empty_pages'    => __( 'Google offered another page but returned no businesses on it, which means the results are exhausted. Stopping rather than paying for empty pages.', 'leadmap' ),
			'no_new_results' => sprintf(
				/* translators: %d: how many consecutive pages produced nothing new. */
				__( 'The last %d pages held only businesses already collected. Stopping — widen the radius or change the industry for more.', 'leadmap' ),
				self::MAX_BARREN_PAGES
			),
			'page_budget'    => sprintf(
				/* translators: %d: pages fetched. */
				__( 'Fetched all %d pages this search allows. Google caps Text Search at 60 results per query.', 'leadmap' ),
				$pages
			),
			'page_cap'       => __( 'Hit the safety limit on pages per search', 'leadmap' ),
			'cost_cap'       => sprintf(
				/* translators: %s: the amount spent. */
				__( 'Reached the per-search spend limit at $%s. Raise it under Settings if this search should go further.', 'leadmap' ),
				number_format( $cost, 2 )
			),
		];

		$level = in_array( $reason, [ 'empty_pages', 'no_new_results', 'cost_cap', 'page_cap' ], true ) ? 'warn' : 'info';

		Search_Repository::log( $search_id, $messages[ $reason ] ?? $reason, $level );
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

		Search_Repository::log( $search_id, $error->get_error_message(), 'error' );
		Search_Repository::mark_failed( $search_id, $error->get_error_message() );

		Event_Repository::log( 'search.failed', 0, [ 'search_id' => $search_id, 'error' => $error->get_error_message() ] );
	}
}
