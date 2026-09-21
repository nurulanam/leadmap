<?php
/**
 * Runs the whole enrichment pass for one lead: crawl, extract emails, fingerprint the
 * stack, check on-page SEO, and roll it all into a staleness score.
 *
 * PageSpeed is deliberately not called here — it takes up to a minute per URL, so it runs
 * as its own job and folds its result in afterwards.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

use LeadMap\Events\Event_Repository;
use LeadMap\Leads\Lead_Email_Repository;
use LeadMap\Leads\Lead_Repository;
use LeadMap\Support\Logger;
use LeadMap\Support\Normalize;
use LeadMap\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Enrichment_Service {

	public function __construct(
		private readonly Website_Crawler $crawler = new Website_Crawler(),
		private readonly Email_Extractor $emails = new Email_Extractor(),
		private readonly Tech_Detector $tech = new Tech_Detector(),
		private readonly Seo_Analyzer $seo = new Seo_Analyzer(),
		private readonly Staleness_Scorer $staleness = new Staleness_Scorer(),
	) {}

	public function enrich( int $lead_id ): bool {
		$lead = Lead_Repository::find( $lead_id );

		if ( ! $lead ) {
			return false;
		}

		Lead_Repository::update( $lead_id, [ 'status' => 'enriching' ] );

		$website = (string) $lead->website;

		if ( '' === $website ) {
			return $this->finish( $lead_id, [ 'no_website' => true ], [], 'no_website' );
		}

		$this->crawler->set_budget( (int) Settings::get( 'enrich_budget', 60 ) );

		$started = microtime( true );
		$crawl   = $this->crawler->crawl( $website );

		if ( ! $crawl['pages'] ) {
			$error = $crawl['error'];

			Logger::info(
				'Enrichment could not reach the site.',
				[ 'lead' => $lead_id, 'error' => $error?->get_error_message() ]
			);

			return $this->finish(
				$lead_id,
				[
					'unreachable' => true,
					'error'       => $error?->get_error_message() ?? 'Unknown error',
					'error_code'  => $error?->get_error_code() ?? '',
				],
				[],
				'enriched'
			);
		}

		$site_domain = Normalize::domain( $website );
		$home        = $crawl['pages'][0]['result'];

		$enrichment = [
			'elapsed' => round( microtime( true ) - $started, 2 ),
			'http'    => [
				'status'     => $home->status,
				'final_url'  => $home->final_url,
				'redirects'  => $home->redirects,
				'seconds'    => $home->seconds,
				'server'     => $home->header( 'server' ),
				'last_modified' => $home->header( 'last-modified' ),
			],
			'pages_crawled' => [],
		];

		$email_sets = [];

		foreach ( $crawl['pages'] as $page ) {
			$result = $page['result'];

			$enrichment['pages_crawled'][] = [
				'url'    => $result->final_url,
				'kind'   => $page['kind'],
				'status' => $result->status,
			];

			if ( $result->ok() && $result->is_html() ) {
				$email_sets[] = $this->emails->extract( $result->body, $site_domain, $page['kind'] );
			}
		}

		if ( $home->ok() && $home->is_html() ) {
			$enrichment['tech'] = $this->tech->detect( $home );
			$enrichment['seo']  = $this->seo->analyze( $home );
		}

		$candidates = $this->emails->merge( $email_sets );

		if ( $candidates ) {
			Lead_Email_Repository::save_all( $lead_id, $candidates );
		}

		$best = $candidates[0] ?? null;

		return $this->finish(
			$lead_id,
			$enrichment,
			$best ? [
				'email'            => $best->email,
				'email_confidence' => $best->confidence,
				'email_source'     => $best->source,
			] : [],
			'enriched',
			count( $candidates )
		);
	}

	/** Fold a PageSpeed result into an already-enriched lead and rescore. */
	public function apply_speed( int $lead_id, array $speed, string $strategy = 'mobile' ): void {
		$lead = Lead_Repository::find( $lead_id );

		if ( ! $lead ) {
			return;
		}

		$enrichment = json_decode( (string) $lead->enrichment_json, true );
		$enrichment = is_array( $enrichment ) ? $enrichment : [];

		$enrichment[ 'speed_' . $strategy ] = $speed;

		// A later success clears an earlier failure note.
		unset( $enrichment[ 'speed_' . $strategy . '_error' ] );

		$scored = $this->staleness->score( $enrichment );

		Lead_Repository::update(
			$lead_id,
			[
				'enrichment_json' => wp_json_encode( $enrichment ),
				'staleness_score' => $scored['score'],
				'staleness_json'  => wp_json_encode( $scored['signals'] ),
			]
		);
	}

	/** Note why a PageSpeed run produced nothing, so the UI can say so. */
	public function record_speed_failure( int $lead_id, string $strategy, string $reason ): void {
		$lead = Lead_Repository::find( $lead_id );

		if ( ! $lead ) {
			return;
		}

		$enrichment = json_decode( (string) $lead->enrichment_json, true );
		$enrichment = is_array( $enrichment ) ? $enrichment : [];

		$enrichment[ 'speed_' . $strategy . '_error' ] = [
			'reason'    => mb_substr( $reason, 0, 500 ),
			'failed_at' => current_time( 'mysql', true ),
		];

		Lead_Repository::update( $lead_id, [ 'enrichment_json' => wp_json_encode( $enrichment ) ] );
	}

	/**
	 * @param array<string,mixed> $enrichment
	 * @param array<string,mixed> $fields
	 */
	private function finish( int $lead_id, array $enrichment, array $fields, string $status, int $emails_found = 0 ): bool {
		$scored = $this->staleness->score( $enrichment );

		Lead_Repository::update(
			$lead_id,
			array_merge(
				$fields,
				[
					'status'          => $status,
					'enrichment_json' => wp_json_encode( $enrichment ),
					'staleness_score' => $scored['score'],
					'staleness_json'  => wp_json_encode( $scored['signals'] ),
					'enriched_at'     => current_time( 'mysql', true ),
				]
			)
		);

		Event_Repository::log(
			'lead.enriched',
			$lead_id,
			[
				'emails_found'    => $emails_found,
				'staleness_score' => $scored['score'],
				'status'          => $status,
			]
		);

		return true;
	}
}
