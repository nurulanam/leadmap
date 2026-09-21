<?php
/**
 * Visits a business website and returns the pages worth mining for contact details.
 *
 * Deliberately shallow: the home page plus up to four likely contact pages. Depth buys
 * almost no extra emails and multiplies both our cost and the load we put on their server.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

use LeadMap\Support\Url_Guard;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Website_Crawler {

	/** Beyond the home page. */
	private const MAX_EXTRA_PAGES = 4;

	/** Link text or href fragments that suggest a contact page, best first. */
	private const CONTACT_PATTERNS = [
		'contact'     => 'contact',
		'kontakt'     => 'contact',
		'contact-us'  => 'contact',
		'get-in-touch'=> 'contact',
		'reach-us'    => 'contact',
		'enquir'      => 'contact',
		'inquir'      => 'contact',
		'impressum'   => 'contact',
		'about'       => 'about',
		'about-us'    => 'about',
		'team'        => 'about',
		'staff'       => 'about',
		'our-people'  => 'about',
		'support'     => 'other',
	];

	public function __construct( private readonly Http_Fetcher $fetcher = new Http_Fetcher() ) {}

	/**
	 * @return array{pages:array<int,array{kind:string,result:Fetch_Result}>,error:?WP_Error}
	 */
	public function crawl( string $website ): array {
		$home = $this->fetcher->fetch( $website );

		if ( is_wp_error( $home ) ) {
			return [ 'pages' => [], 'error' => $home ];
		}

		$pages = [ [ 'kind' => 'home', 'result' => $home ] ];

		if ( ! $home->ok() || ! $home->is_html() ) {
			return [ 'pages' => $pages, 'error' => null ];
		}

		$robots = $this->robots( $home->final_url );

		foreach ( $this->contact_links( $home->body, $home->final_url ) as $url => $kind ) {
			if ( count( $pages ) > self::MAX_EXTRA_PAGES ) {
				break;
			}

			$path = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' );

			if ( ! $robots->allows( $path ) ) {
				continue;
			}

			$page = $this->fetcher->fetch( $url );

			if ( is_wp_error( $page ) || ! $page->ok() ) {
				continue;
			}

			$pages[] = [ 'kind' => $kind, 'result' => $page ];
		}

		return [ 'pages' => $pages, 'error' => null ];
	}

	private function robots( string $base ): Robots {
		$parts = wp_parse_url( $base );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return new Robots();
		}

		$url    = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . '/robots.txt';
		$result = $this->fetcher->fetch( $url );

		if ( is_wp_error( $result ) || ! $result->ok() ) {
			return new Robots();
		}

		return new Robots( $result->body );
	}

	/**
	 * Pick internal links that look like contact or about pages.
	 *
	 * @return array<string,string> absolute URL => page kind.
	 */
	public function contact_links( string $html, string $base ): array {
		if ( ! preg_match_all( '#<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER ) ) {
			return [];
		}

		$base_host = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
		$found     = [];

		foreach ( $matches as $match ) {
			$href = html_entity_decode( trim( $match[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$text = strtolower( wp_strip_all_tags( $match[2] ) );

			if ( '' === $href || str_starts_with( $href, '#' ) || preg_match( '/^(mailto|tel|javascript):/i', $href ) ) {
				continue;
			}

			$absolute = $this->absolutize( $href, $base );

			if ( '' === $absolute ) {
				continue;
			}

			// Never leave the site: an off-site "contact" link is someone else's page.
			if ( strtolower( (string) wp_parse_url( $absolute, PHP_URL_HOST ) ) !== $base_host ) {
				continue;
			}

			if ( is_wp_error( Url_Guard::validate( $absolute ) ) ) {
				continue;
			}

			$haystack = strtolower( $href ) . ' ' . $text;

			foreach ( self::CONTACT_PATTERNS as $needle => $kind ) {
				if ( str_contains( $haystack, $needle ) ) {
					// Strip the fragment so /contact and /contact#form are one page.
					$clean = strtok( $absolute, '#' ) ?: $absolute;

					if ( ! isset( $found[ $clean ] ) ) {
						$found[ $clean ] = $kind;
					}

					break;
				}
			}
		}

		// Contact pages before about pages — they yield addresses far more often.
		uasort(
			$found,
			static function ( string $a, string $b ): int {
				$rank = [ 'contact' => 0, 'about' => 1, 'other' => 2 ];

				return ( $rank[ $a ] ?? 3 ) <=> ( $rank[ $b ] ?? 3 );
			}
		);

		return $found;
	}

	private function absolutize( string $href, string $base ): string {
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) ) {
			return '';
		}

		$parts = wp_parse_url( $base );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = $parts['scheme'] ?? 'https';
		$root   = $scheme . '://' . $parts['host'];

		if ( str_starts_with( $href, '//' ) ) {
			return $scheme . ':' . $href;
		}

		if ( str_starts_with( $href, '/' ) ) {
			return $root . $href;
		}

		$path = (string) ( $parts['path'] ?? '/' );
		$dir  = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 ) ?: '/';

		return $root . $dir . $href;
	}
}
