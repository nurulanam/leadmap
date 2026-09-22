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

		// Remembered so a failure can say *why* the keyed attempt was abandoned. Without it
		// a keyless rate limit reads as "enable the API" — advice for a problem the operator
		// may already have fixed, while the real refusal stays invisible.
		$key_refusal = '';

		// PageSpeed genuinely loads and profiles the page in a real Chrome, so it is slow by
		// nature — and slowest on exactly the neglected sites we care about. A timeout here
		// is usually the site being slow, not Google being unavailable.
		$timeout = (int) max( 30, min( 180, (int) Settings::get( 'pagespeed_timeout', 90 ) ) );

		foreach ( $attempts as $index => $attempt ) {
			$started  = microtime( true );
			$response = Http::get_json( self::ENDPOINT, $attempt, [], $timeout );
			$elapsed  = round( microtime( true ) - $started, 1 );

			if ( ! is_wp_error( $response ) ) {
				$parsed            = $this->parse( $response, $attempt['strategy'], $safe );
				$parsed['seconds'] = $elapsed;
				$parsed['keyed']   = isset( $attempt['key'] );

				return $parsed;
			}

			$last = $response;

			$is_last = $index === count( $attempts ) - 1;

			if ( $is_last || ! $this->is_key_problem( $response ) ) {
				break;
			}

			$key_refusal = $response->get_error_message();

			Logger::error(
				'PageSpeed rejected the API key; retrying without it.',
				[ 'reason' => $key_refusal ]
			);
		}

		return $this->explain(
			$last ?? new WP_Error( 'leadmap_psi_failed', 'PageSpeed returned no result.' ),
			$key_refusal
		);
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
			// Lighthouse renders in a real Chrome at the requested viewport, so this is a
			// genuine mobile screenshot when strategy is mobile — not a cropped desktop one.
			'screenshot'  => (string) ( $audits['final-screenshot']['details']['data'] ?? '' ),
		];
	}

	/**
	 * Turn PageSpeed's refusals into something an operator can act on.
	 *
	 * @param string $key_refusal Why the keyed attempt was abandoned, when one was made.
	 */
	private function explain( WP_Error $error, string $key_refusal = '' ): WP_Error {
		$message = $error->get_error_message();

		// A rate limit reached *after* falling back to an unauthenticated call is not a
		// rate-limit problem — it is a key problem wearing a rate limit's clothes. Report
		// the refusal, which is the thing that can actually be fixed.
		if ( '' !== $key_refusal ) {
			return new WP_Error(
				$error->get_error_code(),
				sprintf(
					/* translators: %s: the reason Google gave for refusing the key. */
					__( 'PageSpeed refused your API key, so the request was retried without one and hit the anonymous rate limit. Google\'s reason for refusing the key was: "%s". Check that PageSpeed Insights API is enabled on the same project the key belongs to, and that the key\'s API restrictions include it. Changes can take a few minutes to take effect.', 'leadmap' ),
					$key_refusal
				),
				$error->get_error_data()
			);
		}

		// A read timeout is its own thing: Google was reachable, the page was too slow to
		// finish profiling. Saying "operation timed out" helps nobody.
		if ( str_contains( $message, 'cURL error 28' ) || str_contains( strtolower( $message ), 'timed out' ) ) {
			return new WP_Error(
				'leadmap_psi_timeout',
				sprintf(
					/* translators: %d: the configured timeout in seconds. */
					__( 'Google did not finish analysing this page within %d seconds. That usually means the site itself is very slow to load — which is a finding in its own right. It will be retried automatically; raise the PageSpeed timeout under Settings if it keeps happening.', 'leadmap' ),
					(int) max( 30, min( 180, (int) Settings::get( 'pagespeed_timeout', 90 ) ) )
				),
				$error->get_error_data()
			);
		}

		$patterns = [
			'Lighthouse returned error'  => __( 'Google loaded the page but Lighthouse could not score it. This usually means the site is too slow to finish loading, blocks automated browsers, or returned an error — which is itself a finding worth noting.', 'leadmap' ),
			'FAILED_DOCUMENT_REQUEST'    => __( 'Google could not load the page at all. The site may be down, blocking Google, or refusing non-browser traffic.', 'leadmap' ),
			'ERRORED_DOCUMENT_REQUEST'   => __( 'The page returned an error when Google fetched it.', 'leadmap' ),
			'DNS_FAILURE'                => __( 'Google could not resolve the domain.', 'leadmap' ),
			'has not been used'          => __( 'The PageSpeed Insights API is not enabled on your Google Cloud project. Enable it under APIs & Services → Library, or leave the key out — PageSpeed also works unauthenticated, just with a lower rate limit.', 'leadmap' ),
			'not authorized'             => __( 'Your API key is restricted to other APIs. Either add PageSpeed Insights API to the key\'s allowed list, or let LeadMap call it without a key.', 'leadmap' ),
			'Quota exceeded'             => Settings::google_api_key()
				? __( 'PageSpeed rate limit reached even with your API key. Lower "PageSpeed requests per minute" under Settings, or raise the quota for the PageSpeed Insights API in Google Cloud.', 'leadmap' )
				: __( 'PageSpeed rate limit reached. No API key is configured, and unauthenticated requests are limited to a few per minute.', 'leadmap' ),
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
