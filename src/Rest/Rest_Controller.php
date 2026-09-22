<?php
/**
 * Admin-only REST endpoints backing the search screens.
 *
 * Geocoding is proxied rather than called from the browser on purpose: the Google key is
 * restricted to this server's IP address, which is the correct configuration for a key used
 * server-side, and that means it cannot be used from JavaScript at all.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Rest;

use LeadMap\Jobs\Job_Runner;
use LeadMap\Jobs\Lock;
use LeadMap\Jobs\Scheduler;
use LeadMap\Providers\Google_Places_Provider;
use LeadMap\Search\Search_Repository;
use LeadMap\Search\Search_Runner;
use LeadMap\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class Rest_Controller {

	public const NAMESPACE = 'leadmap/v1';

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/geocode',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'geocode' ],
				'permission_callback' => [ $this, 'can_search' ],
				'args'                => [
					'location' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'zip'      => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/searches/(?P<id>\d+)/stop',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'stop' ],
				'permission_callback' => [ $this, 'can_search' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/searches/(?P<id>\d+)/progress',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'progress' ],
				'permission_callback' => [ $this, 'can_search' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	public function can_search(): bool {
		return current_user_can( 'leadmap_search' );
	}

	/** Stop a running search. Anything already collected is kept. */
	public function stop( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = absint( $request->get_param( 'id' ) );
		$search = Search_Repository::find( $id );

		if ( ! $search ) {
			return new WP_Error( 'leadmap_not_found', __( 'Search not found.', 'leadmap' ), [ 'status' => 404 ] );
		}

		if ( ! in_array( (string) $search->status, [ 'queued', 'running' ], true ) ) {
			return new WP_REST_Response( [ 'status' => (string) $search->status, 'stopped' => false ] );
		}

		// Cancel before logging, so a page already in flight sees the new status and stops.
		Search_Repository::cancel( $id );
		Scheduler::cancel( Job_Runner::SEARCH_RUN, [ $id ] );

		Search_Repository::log(
			$id,
			sprintf(
				/* translators: %d: how many leads were collected before stopping. */
				__( 'Stopped by you — %d leads collected so far have been kept', 'leadmap' ),
				(int) $search->results_new
			),
			'warn'
		);

		return new WP_REST_Response( [ 'status' => 'cancelled', 'stopped' => true ] );
	}

	/**
	 * Run the next page of a stalled search inline.
	 *
	 * A search advances by enqueueing its next page as a background job. That job is handed
	 * to WP-Cron when Action Scheduler is not installed, and WP-Cron cannot be relied on to
	 * run it promptly: the job is scheduled from *inside* a cron request, that process has
	 * already decided which events it will run, and nothing spawns a fresh one. On hosts with
	 * DISABLE_WP_CRON it may never run at all.
	 *
	 * So while the operator is watching, their own polling requests move the search along.
	 * The lock keeps two overlapping polls from fetching the same page twice.
	 *
	 * @return bool True when a page was run.
	 */
	private function nudge( object $search ): bool {
		if ( ! in_array( (string) $search->status, [ 'queued', 'running' ], true ) ) {
			return false;
		}

		if ( $this->stalled_for( $search ) < self::STALL_SECONDS ) {
			return false;
		}

		$lock = 'search_' . (int) $search->id;

		if ( ! Lock::acquire( $lock, 120 ) ) {
			return false; // Another request is already on it.
		}

		try {
			( new Search_Runner() )->run( (int) $search->id );
		} finally {
			Lock::release( $lock );
		}

		return true;
	}

	/** Seconds since this search last did anything. */
	private function stalled_for( object $search ): int {
		$marker = (string) ( $search->last_progress_at ?: $search->created_at );

		if ( '' === $marker ) {
			return PHP_INT_MAX;
		}

		return max( 0, time() - (int) strtotime( $marker . ' UTC' ) );
	}

	/** Resolve a city and/or ZIP to a point the map can centre on. */
	public function geocode( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$location = trim( (string) $request->get_param( 'location' ) );
		$zip      = trim( (string) $request->get_param( 'zip' ) );
		$where    = trim( $zip . ' ' . $location );

		if ( '' === $where ) {
			return new WP_Error(
				'leadmap_no_location',
				__( 'Enter a city or a ZIP code.', 'leadmap' ),
				[ 'status' => 400 ]
			);
		}

		if ( mb_strlen( $where ) > 200 ) {
			return new WP_Error( 'leadmap_bad_location', __( 'That location is too long.', 'leadmap' ), [ 'status' => 400 ] );
		}

		$region = (string) Settings::get( 'region_code', 'US' );

		// Geocoding is billed, so an identical lookup is answered from cache. The map is
		// queried on every keystroke pause, which would otherwise be a lot of calls.
		$cache_key = 'leadmap_geo_' . md5( strtolower( $where ) . '|' . $region );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			$cached['cached'] = true;

			return new WP_REST_Response( $cached );
		}

		$result = ( new Google_Places_Provider() )->geocode( $where, $region );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => 422 ]
			);
		}

		$payload = [
			'lat'    => $result['lat'],
			'lng'    => $result['lng'],
			'label'  => $where,
			'cached' => false,
		];

		set_transient( $cache_key, $payload, WEEK_IN_SECONDS );

		return new WP_REST_Response( $payload );
	}

	/** A search that has made no progress for this long is being driven forward from here. */
	private const STALL_SECONDS = 12;

	/** Everything the live log panel needs in one call. */
	public function progress( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id     = absint( $request->get_param( 'id' ) );
		$search = Search_Repository::find( $id );

		if ( ! $search ) {
			return new WP_Error( 'leadmap_not_found', __( 'Search not found.', 'leadmap' ), [ 'status' => 404 ] );
		}

		// While someone is watching, this request will drive the search itself rather than
		// waiting on WP-Cron. See nudge() for why that is necessary.
		if ( $this->nudge( $search ) ) {
			$search = Search_Repository::find( $id ) ?? $search;
		}

		$status  = (string) $search->status;
		$running = in_array( $status, [ 'queued', 'running' ], true );

		$log = array_map(
			static function ( array $line ): array {
				return [
					'time'    => gmdate( 'H:i:s', (int) ( $line['at'] ?? 0 ) ),
					'level'   => (string) ( $line['level'] ?? 'info' ),
					'message' => (string) ( $line['message'] ?? '' ),
				];
			},
			Search_Repository::get_log( $id )
		);

		return new WP_REST_Response(
			[
				'id'        => $id,
				'stalled'   => $running && $this->stalled_for( $search ) > 90,
				'status'    => $status,
				'running'   => $running,
				'found'     => (int) $search->results_found,
				'new'       => (int) $search->results_new,
				'pages'     => (int) $search->pages_fetched,
				'max'       => (int) $search->max_results,
				'cost'      => (float) $search->api_cost,
				'error'     => (string) ( $search->error ?? '' ),
				'log'       => $log,
				'leads_url' => admin_url( 'admin.php?page=leadmap&search_id=' . $id ),
			]
		);
	}
}
