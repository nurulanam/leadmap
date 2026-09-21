<?php
/**
 * Polite, guarded HTTP fetching for the crawler.
 *
 * Every request is validated by Url_Guard first, redirects are followed manually so each
 * hop is validated too, responses are size-capped, and requests to one host are spaced out.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

use LeadMap\Support\Settings;
use LeadMap\Support\Url_Guard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Http_Fetcher {

	/** Stop reading after this many bytes — a lead page is never legitimately larger. */
	private const MAX_BYTES = 2097152; // 2 MB.

	/** Fallback when no setting is available. */
	private const TIMEOUT = 10;

	private const MAX_REDIRECTS = 3;

	/** Minimum gap between two requests to the same host, in microseconds. */
	private const HOST_DELAY_US = 1000000; // 1 second.

	/** @var array<string,float> host => last request time. */
	private array $last_request = [];

	/** Unix timestamp after which this fetcher refuses to start another request. */
	private ?float $deadline = null;

	public function __construct( private readonly ?int $timeout = null ) {}

	/** Give the fetcher an overall budget, shared across every request it makes. */
	public function set_deadline( float $timestamp ): void {
		$this->deadline = $timestamp;
	}

	/** True when the budget is spent and no further request should be started. */
	public function out_of_time(): bool {
		return null !== $this->deadline && microtime( true ) >= $this->deadline;
	}

	/** Seconds left in the budget, capped to the per-request timeout. */
	private function timeout(): int {
		$timeout = $this->timeout ?? (int) Settings::get( 'crawl_timeout', self::TIMEOUT );
		$timeout = max( 3, min( 60, $timeout ) );

		if ( null === $this->deadline ) {
			return $timeout;
		}

		$remaining = (int) floor( $this->deadline - microtime( true ) );

		return max( 1, min( $timeout, $remaining ) );
	}

	public function fetch( string $url ): Fetch_Result|WP_Error {
		if ( $this->out_of_time() ) {
			return new WP_Error(
				'leadmap_enrich_timeout',
				__( 'The time budget for this lead ran out before the page could be fetched.', 'leadmap' )
			);
		}

		$redirects = 0;
		$original  = $url;

		while ( true ) {
			$safe = Url_Guard::validate( $url );

			if ( is_wp_error( $safe ) ) {
				return $safe;
			}

			$this->throttle( (string) wp_parse_url( $safe, PHP_URL_HOST ) );

			// The throttle may have eaten the remaining budget.
			if ( $this->out_of_time() ) {
				return new WP_Error( 'leadmap_enrich_timeout', __( 'The time budget for this lead ran out.', 'leadmap' ) );
			}

			$started = microtime( true );

			$response = wp_safe_remote_get(
				$safe,
				[
					'timeout'             => $this->timeout(),
					'redirection'         => 0,      // Followed manually so each hop is validated.
					'limit_response_size' => self::MAX_BYTES,
					'sslverify'           => true,
					'reject_unsafe_urls'  => true,   // Core's own guard, in addition to ours.
					'user-agent'          => self::user_agent(),
					'headers'             => [
						'Accept'          => 'text/html,application/xhtml+xml',
						'Accept-Language' => 'en;q=0.9',
					],
				]
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status  = (int) wp_remote_retrieve_response_code( $response );
			$headers = $this->headers( $response );

			// 3xx with a Location: validate the target and go round again.
			if ( in_array( $status, [ 301, 302, 303, 307, 308 ], true ) && ! empty( $headers['location'] ) ) {
				if ( $redirects >= self::MAX_REDIRECTS ) {
					return new WP_Error( 'leadmap_too_many_redirects', 'The site redirected too many times.' );
				}

				$next = $this->resolve_redirect( $safe, $headers['location'] );

				if ( '' === $next ) {
					return new WP_Error( 'leadmap_bad_redirect', 'The site sent a redirect we could not resolve.' );
				}

				$url = $next;
				++$redirects;

				continue;
			}

			$body = (string) wp_remote_retrieve_body( $response );

			return new Fetch_Result(
				url: $original,
				final_url: $safe,
				status: $status,
				body: $body,
				headers: $headers,
				seconds: round( microtime( true ) - $started, 3 ),
				redirects: $redirects,
				truncated: strlen( $body ) >= self::MAX_BYTES,
			);
		}
	}

	/**
	 * Turn a Location header into an absolute URL.
	 * Returns '' when it cannot be resolved, which the caller treats as a failure.
	 */
	private function resolve_redirect( string $base, string $location ): string {
		$location = trim( $location, " \t\n\r\x0B" );

		if ( '' === $location ) {
			return '';
		}

		// Already absolute.
		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}

		// A scheme we do not follow — do not try to rewrite it into one we do.
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $location ) ) {
			return '';
		}

		$parts = wp_parse_url( $base );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$root = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];

		if ( str_starts_with( $location, '//' ) ) {
			return ( $parts['scheme'] ?? 'https' ) . ':' . $location;
		}

		if ( str_starts_with( $location, '/' ) ) {
			return $root . $location;
		}

		$path = (string) ( $parts['path'] ?? '/' );
		$dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ) ?: '/';

		return $root . $dir . $location;
	}

	/** Space requests to one host so we never hammer a small business's server. */
	private function throttle( string $host ): void {
		if ( '' === $host ) {
			return;
		}

		$last = $this->last_request[ $host ] ?? 0.0;
		$wait = self::HOST_DELAY_US - ( ( microtime( true ) - $last ) * 1000000 );

		if ( $last > 0.0 && $wait > 0 ) {
			usleep( (int) min( $wait, self::HOST_DELAY_US ) );
		}

		$this->last_request[ $host ] = microtime( true );
	}

	/** @return array<string,string> Lower-cased header names. */
	private function headers( array $response ): array {
		$headers = wp_remote_retrieve_headers( $response );

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		$out = [];

		foreach ( (array) $headers as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}

		return $out;
	}

	/** Honest identification with a contact URL, as a well-behaved crawler should. */
	private static function user_agent(): string {
		return sprintf(
			'LeadMapBot/%s (+%s; lead research; contact site owner)',
			LEADMAP_VERSION,
			home_url( '/' )
		);
	}

	public static function max_bytes(): int {
		return self::MAX_BYTES;
	}
}
