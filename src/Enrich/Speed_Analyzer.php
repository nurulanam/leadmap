<?php
/**
 * PageSpeed Insights — the speed half of the pitch.
 *
 * Free and unauthenticated, but heavily rate-limited without a key, so it runs as its own
 * job rather than inline with the crawl. The numbers matter because they come from Google
 * rather than from us: "Google scores your site 34/100" is a claim the owner can verify.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

use LeadMap\Support\Http;
use LeadMap\Support\Logger;
use LeadMap\Support\Settings;
use LeadMap\Support\Url_Guard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Speed_Analyzer {

	private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function analyze( string $url, string $strategy = 'mobile' ): array|WP_Error {
		$safe = Url_Guard::validate( $url );

		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$query = [
			'url'      => $safe,
			'strategy' => 'desktop' === $strategy ? 'desktop' : 'mobile',
			'category' => 'performance',
		];

		$key = Settings::google_api_key();

		// PageSpeed works without a key but is aggressively throttled, so we send ours when
		// there is one. The catch: a key restricted to Places and Geocoding — which is the
		// correct, tight configuration for this plugin — will be *rejected* by PageSpeed.
		// So a key-related refusal falls back to an unauthenticated call rather than
		// reporting a failure the operator cannot make sense of.
		$attempts = '' === $key
			? [ $query ]
			: [ array_merge( $query, [ 'key' => $key ] ), $query ];

		$last = null;

		foreach ( $attempts as $index => $attempt ) {
			// PageSpeed actually loads the page, so 60s is normal rather than excessive.
			$response = Http::get_json( self::ENDPOINT, $attempt, [], 60 );

			if ( ! is_wp_error( $response ) ) {
				return $this->parse( $response, $attempt['strategy'], $safe );
			}

			$last = $response;

			$is_last = $index === count( $attempts ) - 1;

			if ( $is_last || ! $this->is_key_problem( $response ) ) {
				break;
			}

			Logger::info(
				'PageSpeed rejected the API key; retrying without it.',
				[ 'reason' => $response->get_error_message() ]
			);
		}

		return $this->explain( $last ?? new WP_Error( 'leadmap_psi_failed', 'PageSpeed returned no result.' ) );
	}

	/** True when the refusal is about the key rather than the page being measured. */
	private function is_key_problem( WP_Error $error ): bool {
		$code    = (string) $error->get_error_code();
		$message = strtolower( $error->get_error_message() );

		if ( in_array( $code, [ 'leadmap_http_403', 'leadmap_http_400' ], true ) ) {
			foreach ( [ 'api key', 'not authorized', 'blocked', 'has not been used', 'permission', 'forbidden' ] as $needle ) {
				if ( str_contains( $message, $needle ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $response
	 *
	 * @return array<string,mixed>
	 */
	private function parse( array $response, string $strategy, string $url ): array {
		$lighthouse = $response['lighthouseResult'] ?? [];
		$audits     = $lighthouse['audits'] ?? [];

		$score = $lighthouse['categories']['performance']['score'] ?? null;

		return [
			'strategy'    => $strategy,
			'score'       => null === $score ? null : (int) round( (float) $score * 100 ),
			'lcp_ms'      => $this->numeric( $audits, 'largest-contentful-paint' ),
			'fcp_ms'      => $this->numeric( $audits, 'first-contentful-paint' ),
			'tbt_ms'      => $this->numeric( $audits, 'total-blocking-time' ),
			'cls'         => $this->numeric( $audits, 'cumulative-layout-shift' ),
			'speed_index' => $this->numeric( $audits, 'speed-index' ),
			// PageSpeed's own pass/average/fail verdict per metric, so our colours match theirs.
			'ratings'     => [
				'lcp' => $this->rating( $audits, 'largest-contentful-paint' ),
				'fcp' => $this->rating( $audits, 'first-contentful-paint' ),
				'tbt' => $this->rating( $audits, 'total-blocking-time' ),
				'cls' => $this->rating( $audits, 'cumulative-layout-shift' ),
				'si'  => $this->rating( $audits, 'speed-index' ),
			],
			'final_url'   => (string) ( $lighthouse['finalUrl'] ?? $url ),
			'fetched_at'  => current_time( 'mysql', true ),
		];
	}

	/** Turn PageSpeed's refusals into something an operator can act on. */
	private function explain( WP_Error $error ): WP_Error {
		$message = $error->get_error_message();

		$patterns = [
			'Lighthouse returned error'  => __( 'Google loaded the page but Lighthouse could not score it. This usually means the site is too slow to finish loading, blocks automated browsers, or returned an error — which is itself a finding worth noting.', 'leadmap' ),
			'FAILED_DOCUMENT_REQUEST'    => __( 'Google could not load the page at all. The site may be down, blocking Google, or refusing non-browser traffic.', 'leadmap' ),
			'ERRORED_DOCUMENT_REQUEST'   => __( 'The page returned an error when Google fetched it.', 'leadmap' ),
			'DNS_FAILURE'                => __( 'Google could not resolve the domain.', 'leadmap' ),
			'has not been used'          => __( 'The PageSpeed Insights API is not enabled on your Google Cloud project. Enable it under APIs & Services → Library, or leave the key out — PageSpeed also works unauthenticated, just with a lower rate limit.', 'leadmap' ),
			'not authorized'             => __( 'Your API key is restricted to other APIs. Either add PageSpeed Insights API to the key\'s allowed list, or let LeadMap call it without a key.', 'leadmap' ),
			'Quota exceeded'             => __( 'PageSpeed rate limit reached. Unauthenticated requests are limited to a few per minute — enable the PageSpeed Insights API on your project and the limit rises substantially.', 'leadmap' ),
			'rateLimitExceeded'          => __( 'PageSpeed rate limit reached. Leads already queued will be measured as the limit recovers.', 'leadmap' ),
		];

		foreach ( $patterns as $needle => $hint ) {
			if ( false !== stripos( $message, $needle ) ) {
				return new WP_Error( $error->get_error_code(), $hint, $error->get_error_data() );
			}
		}

		if ( 'leadmap_http_429' === $error->get_error_code() ) {
			return new WP_Error(
				$error->get_error_code(),
				__( 'PageSpeed rate limit reached. Enable the PageSpeed Insights API on your Google Cloud project to raise it.', 'leadmap' ),
				$error->get_error_data()
			);
		}

		return $error;
	}

	/**
	 * Lighthouse scores each metric 0–1; PageSpeed renders <0.5 red, <0.9 amber, else green.
	 *
	 * @param array<string,mixed> $audits
	 */
	private function rating( array $audits, string $id ): string {
		$score = $audits[ $id ]['score'] ?? null;

		if ( null === $score ) {
			return '';
		}

		$score = (float) $score;

		return $score >= 0.9 ? 'good' : ( $score >= 0.5 ? 'average' : 'poor' );
	}

	/** @param array<string,mixed> $audits */
	private function numeric( array $audits, string $id ): ?float {
		$value = $audits[ $id ]['numericValue'] ?? null;

		return null === $value ? null : round( (float) $value, 3 );
	}
}
