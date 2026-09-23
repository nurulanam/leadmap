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

use LeadMap\Ai\Problem_Writer;
use LeadMap\Audit\Audit_Service;
use LeadMap\Audit\Issue_Tags;
use LeadMap\Enrich\Enrichment_Service;
use LeadMap\Enrich\Speed_Analyzer;
use LeadMap\Enrich\Speed_Status;
use LeadMap\Jobs\Job_Runner;
use LeadMap\Jobs\Lock;
use LeadMap\Support\Rate_Limiter;
use LeadMap\Jobs\Scheduler;
use LeadMap\Leads\Lead_Repository;
use LeadMap\Providers\Google_Places_Provider;
use LeadMap\Search\Search_Repository;
use LeadMap\Search\Search_Runner;
use LeadMap\Support\Logger;
use LeadMap\Support\Settings;
use LeadMap\Triage\Triage_Service;
use LeadMap\Triage\Verdicts;
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
			'/speed/next',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'speed_next' ],
				'permission_callback' => [ $this, 'can_audit' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/leads/(?P<id>\d+)/speed',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'check_speed' ],
				'permission_callback' => [ $this, 'can_audit' ],
				'args'                => [
					'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/leads/(?P<id>\d+)/speed/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'speed_status' ],
				'permission_callback' => [ $this, 'can_audit' ],
				'args'                => [
					'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/leads/(?P<id>\d+)/draft',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'draft_problem' ],
				'permission_callback' => [ $this, 'can_audit' ],
				'args'                => [
					'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/leads/(?P<id>\d+)/audit',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'save_audit' ],
				'permission_callback' => [ $this, 'can_audit' ],
				'args'                => [
					'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/leads/(?P<id>\d+)/triage',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'triage' ],
				'permission_callback' => [ $this, 'can_audit' ],
				'args'                => [
					'id'    => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
					'flags' => [ 'type' => 'array', 'required' => true ],
					'note'  => [ 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/leads/(?P<id>\d+)/triage/undo',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'triage_undo' ],
				'permission_callback' => [ $this, 'can_audit' ],
				'args'                => [
					'id' => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
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

	public function can_audit(): bool {
		return current_user_can( 'leadmap_audit' );
	}

	/**
	 * Measure speed on demand.
	 *
	 * Runs mobile inline so the operator sees a number straight away, and queues desktop
	 * behind it. Waiting on two sixty-second Lighthouse runs in one request would time out
	 * on most hosts.
	 */
	public function check_speed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = absint( $request->get_param( 'id' ) );
		$lead = Lead_Repository::find( $id );

		if ( ! $lead ) {
			return new WP_Error( 'leadmap_not_found', __( 'Lead not found.', 'leadmap' ), [ 'status' => 404 ] );
		}

		if ( '' === (string) $lead->website ) {
			return new WP_Error(
				'leadmap_no_website',
				__( 'This lead has no website to measure.', 'leadmap' ),
				[ 'status' => 400 ]
			);
		}

		$lock = 'speed_' . $id;

		if ( ! Lock::acquire( $lock, 180 ) ) {
			return new WP_Error(
				'leadmap_busy',
				__( 'A speed check for this lead is already running.', 'leadmap' ),
				[ 'status' => 409 ]
			);
		}

		try {
			Speed_Status::set( $id, 'mobile', Speed_Status::RUNNING );

			$result = ( new Speed_Analyzer() )->analyze( (string) $lead->website, 'mobile' );

			if ( is_wp_error( $result ) ) {
				( new Enrichment_Service() )->record_speed_failure( $id, 'mobile', $result->get_error_message() );
				Speed_Status::set( $id, 'mobile', Speed_Status::FAILED, $result->get_error_message() );

				return new WP_Error( 'leadmap_speed_failed', $result->get_error_message(), [ 'status' => 422 ] );
			}

			( new Enrichment_Service() )->apply_speed( $id, $result, 'mobile' );
			Speed_Status::set( $id, 'mobile', Speed_Status::DONE );
		} finally {
			Lock::release( $lock );
		}

		// Desktop goes to the queue so this request returns promptly.
		Speed_Status::set( $id, 'desktop', Speed_Status::QUEUED );
		Scheduler::enqueue( Job_Runner::LEAD_SPEED, [ $id, 'desktop', 0 ] );

		return new WP_REST_Response(
			[
				'lead_id' => $id,
				'mobile'  => $result,
				'desktop' => [ 'state' => Speed_Status::QUEUED ],
			]
		);
	}

	/**
	 * Run the next outstanding measurement.
	 *
	 * PageSpeed jobs are handed to WP-Cron, which spawns at most once a minute and dies part
	 * way through a batch — so a queue of measurements that should take three seconds apart
	 * trickles out at one or two a minute. While a LeadMap screen is open, the browser calls
	 * this and becomes the worker instead. One measurement per call, rate limiter respected.
	 */
	public function speed_next( WP_REST_Request $request ): WP_REST_Response {
		$pending = Speed_Status::pending_count();

		if ( 0 === $pending ) {
			return new WP_REST_Response( [ 'ran' => false, 'pending' => 0, 'idle' => true ] );
		}

		$wait = Rate_Limiter::wait_for( 'pagespeed' );

		if ( $wait > 0 ) {
			return new WP_REST_Response( [ 'ran' => false, 'pending' => $pending, 'retry_in' => $wait ] );
		}

		$next = Speed_Status::next_pending();

		if ( ! $next ) {
			return new WP_REST_Response( [ 'ran' => false, 'pending' => 0, 'idle' => true ] );
		}

		$lock = 'speed_' . $next['lead_id'] . '_' . $next['strategy'];

		if ( ! Lock::acquire( $lock, 200 ) ) {
			return new WP_REST_Response( [ 'ran' => false, 'pending' => $pending, 'retry_in' => 5 ] );
		}

		try {
			Rate_Limiter::consume( 'pagespeed', (float) Settings::get( 'pagespeed_per_minute', 4 ) );

			( new Job_Runner() )->run_speed( $next['lead_id'], $next['strategy'] );
		} finally {
			Lock::release( $lock );
		}

		return new WP_REST_Response(
			[
				'ran'      => true,
				'lead_id'  => $next['lead_id'],
				'strategy' => $next['strategy'],
				'pending'  => Speed_Status::pending_count(),
			]
		);
	}

	/** Poll both strategies while they work through the queue. */
	public function speed_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = absint( $request->get_param( 'id' ) );
		$lead = Lead_Repository::find( $id );

		if ( ! $lead ) {
			return new WP_Error( 'leadmap_not_found', __( 'Lead not found.', 'leadmap' ), [ 'status' => 404 ] );
		}

		$enrichment = json_decode( (string) $lead->enrichment_json, true );
		$enrichment = is_array( $enrichment ) ? $enrichment : [];

		$out = [ 'lead_id' => $id, 'running' => false, 'strategies' => [] ];

		foreach ( Speed_Status::STRATEGIES as $strategy ) {
			$state = Speed_Status::get( $enrichment, $strategy );

			$out['strategies'][ $strategy ] = [
				'state' => $state['state'],
				'label' => Speed_Status::label( $state['state'] ),
				'detail' => $state['detail'],
				'score' => $enrichment[ 'speed_' . $strategy ]['score'] ?? null,
			];

			if ( Speed_Status::in_progress( $state['state'] ) ) {
				$out['running'] = true;
			}
		}

		$out['staleness'] = null === $lead->staleness_score ? null : (int) $lead->staleness_score;

		return new WP_REST_Response( $out );
	}

	/**
	 * Draft the problem note from the evidence.
	 *
	 * Returns text for the operator to edit. Nothing is saved here — the draft is a starting
	 * point, and the note only exists once a person has approved it.
	 */
	public function draft_problem( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = absint( $request->get_param( 'id' ) );
		$lead = Lead_Repository::find( $id );

		if ( ! $lead ) {
			return new WP_Error( 'leadmap_not_found', __( 'Lead not found.', 'leadmap' ), [ 'status' => 404 ] );
		}

		$enrichment = json_decode( (string) $lead->enrichment_json, true );
		$enrichment = is_array( $enrichment ) ? $enrichment : [];

		$tags = array_values( array_filter(
			array_map( 'sanitize_key', (array) $request->get_param( 'issue_tags' ) ),
			[ Issue_Tags::class, 'exists' ]
		) );

		$primary = sanitize_key( (string) $request->get_param( 'primary_issue' ) );

		$text = ( new Problem_Writer() )->draft( $lead, $enrichment, $tags, $primary );

		if ( is_wp_error( $text ) ) {
			return new WP_Error( $text->get_error_code(), $text->get_error_message(), [ 'status' => 422 ] );
		}

		return new WP_REST_Response( [ 'lead_id' => $id, 'text' => $text ] );
	}

	/** Record an audit. */
	public function save_audit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = absint( $request->get_param( 'id' ) );

		$result = ( new Audit_Service() )->save(
			$id,
			[
				'outcome'       => (string) $request->get_param( 'outcome' ),
				'issue_tags'    => (array) $request->get_param( 'issue_tags' ),
				'primary_issue' => (string) $request->get_param( 'primary_issue' ),
				'problem_notes' => (string) $request->get_param( 'problem_notes' ),
				'is_reachable'  => (bool) $request->get_param( 'is_reachable' ),
				'fit_override'  => $request->get_param( 'fit_override' ),
			]
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 400 ] );
		}

		return new WP_REST_Response( $result );
	}

	/** Record a triage verdict. */
	public function triage( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id    = absint( $request->get_param( 'id' ) );
		$flags = (array) $request->get_param( 'flags' );
		$note  = (string) $request->get_param( 'note' );

		$result = ( new Triage_Service() )->decide( $id, $flags, $note );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[ 'status' => 400 ]
			);
		}

		$result['label'] = Verdicts::label( (string) $result['verdict'] );

		return new WP_REST_Response( $result );
	}

	/** Put a lead back in the triage queue. */
	public function triage_undo( WP_REST_Request $request ): WP_REST_Response {
		$id   = absint( $request->get_param( 'id' ) );
		$done = ( new Triage_Service() )->undo( $id );

		return new WP_REST_Response( [ 'lead_id' => $id, 'undone' => $done ] );
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
		//
		// Wrapped, because this response is the only thing telling the operator what is
		// happening: if running a page throws, they must still be told that, rather than
		// watching a spinner while the endpoint 500s behind it.
		try {
			if ( $this->nudge( $search ) ) {
				$search = Search_Repository::find( $id ) ?? $search;
			}
		} catch ( \Throwable $e ) {
			Logger::error(
				'Driving the search from the progress endpoint failed.',
				[ 'search' => $id, 'error' => $e->getMessage() ]
			);

			Search_Repository::log(
				$id,
				sprintf(
					/* translators: %s: the error message. */
					__( 'Could not fetch the next page: %s', 'leadmap' ),
					$e->getMessage()
				),
				'error'
			);

			Search_Repository::mark_failed( $id, $e->getMessage() );

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
