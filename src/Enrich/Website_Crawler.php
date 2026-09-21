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

	/**
	 * Fragments that suggest a contact page, best first.
	 *
	 * Matched against a squashed form of the href, link text, title and image alt — every
	 * non-alphanumeric character removed — so one entry covers `/contact-us`, `/contact_us`,
	 * `/contactus.html`, `/Contact%20Us.aspx` and `/contac-tus.htm` alike. That matters
	 * because the sites worth pitching are exactly the ones with idiosyncratic old URLs.
	 */
	private const CONTACT_PATTERNS = [
		// English.
		'contactus'     => 'contact',
		'contact'       => 'contact',
		'getintouch'    => 'contact',
		'reachus'       => 'contact',
		'reachout'      => 'contact',
		'findus'        => 'contact',
		'enquir'        => 'contact',
		'inquir'        => 'contact',
		'bookus'        => 'contact',
		'emailus'       => 'contact',
		'callus'        => 'contact',
		'requestquote'  => 'contact',
		'requestaquote' => 'contact',
		'freequote'     => 'contact',
		'getquote'      => 'contact',
		'getaquote'     => 'contact',
		'askforaquote'  => 'contact',
		// Other languages a US/EU business site may use.
		'kontakt'       => 'contact',
		'contacto'      => 'contact',
		'contactenos'   => 'contact',
		'contatti'      => 'contact',
		'contattaci'    => 'contact',
		'contato'       => 'contact',
		'contactez'     => 'contact',
		'nouscontacter' => 'contact',
		'impressum'     => 'contact',
		'coordonnees'   => 'contact',
		// About / team pages, a weaker but real source.
		'aboutus'       => 'about',
		'about'         => 'about',
		'ourteam'       => 'about',
		'meettheteam'   => 'about',
		'ourpeople'     => 'about',
		'ourstaff'      => 'about',
		'team'          => 'about',
		'staff'         => 'about',
		'management'    => 'about',
		'quienessomos'  => 'about',
		'chisiamo'      => 'about',
		'ueberuns'      => 'about',
		// Weakest.
		'support'       => 'other',
		'customerservice' => 'other',
		'help'          => 'other',
		'locations'     => 'other',
		'ourlocation'   => 'other',
	];

	/**
	 * Squashed fragments that look like a match but are not. "Contact lenses" on an
	 * optician's site is the common one, and opticians are a prime local-business niche.
	 */
	private const FALSE_FRIENDS = [
		'contactlens', 'contactless', 'contactform7', 'aboutcookies',
		'aboutourservices', 'teambuilding', 'supportgroup',
	];

	/**
	 * Paths to try when a site offers no recognisable contact link — an image-only nav, a
	 * JavaScript menu, or a link we simply did not match. Ordered by how often they exist.
	 * Only used as a fallback, and only while the time budget allows.
	 */
	private const COMMON_PATHS = [
		'/contact',
		'/contact.html',
		'/contact-us',
		'/contact-us.html',
		'/contactus.html',
		'/contact.php',
		'/contact.htm',
		'/contact-us.php',
		'/contact.aspx',
		'/about-us.html',
		'/about.html',
	];

	/** How many speculative URLs to try when no contact link was found. */
	private const MAX_PROBES = 4;

	public function __construct( private readonly Http_Fetcher $fetcher = new Http_Fetcher() ) {}

	/** Give the whole crawl a time budget, in seconds from now. */
	public function set_budget( int $seconds ): void {
		$this->fetcher->set_deadline( microtime( true ) + max( 5, $seconds ) );
	}

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

		// The home page is the one that matters; everything after it is a bonus and is
		// abandoned rather than allowed to overrun the budget.
		if ( $this->fetcher->out_of_time() ) {
			return [ 'pages' => $pages, 'error' => null ];
		}

		$robots = $this->robots( $home->final_url );

		foreach ( $this->contact_links( $home->body, $home->final_url ) as $url => $kind ) {
			if ( count( $pages ) > self::MAX_EXTRA_PAGES || $this->fetcher->out_of_time() ) {
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

		// Nothing recognisable in the markup — an image-only nav, a JavaScript menu, or a
		// link we did not match. Try the handful of URLs that most often exist anyway.
		if ( 1 === count( $pages ) ) {
			foreach ( $this->probe( $home->final_url, $robots ) as $kind => $page ) {
				$pages[] = [ 'kind' => $kind, 'result' => $page ];
			}
		}

		return [ 'pages' => $pages, 'error' => null ];
	}

	/**
	 * Try common contact URLs directly. A 404 costs one cheap request; a hit often yields the
	 * email that the home page alone would not have given us.
	 *
	 * @return array<string,Fetch_Result> kind => result.
	 */
	private function probe( string $base, Robots $robots ): array {
		$parts = wp_parse_url( $base );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return [];
		}

		$root  = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];
		$found = [];
		$tried = 0;

		foreach ( self::COMMON_PATHS as $path ) {
			if ( $tried >= self::MAX_PROBES || $this->fetcher->out_of_time() || $found ) {
				break;
			}

			if ( ! $robots->allows( $path ) ) {
				continue;
			}

			++$tried;

			$page = $this->fetcher->fetch( $root . $path );

			if ( is_wp_error( $page ) || ! $page->ok() || ! $page->is_html() ) {
				continue;
			}

			// Some sites answer every path with the home page. Comparing against the home
			// page's own URL catches the common "soft 404 redirects to /" case.
			if ( rtrim( $page->final_url, '/' ) === rtrim( $base, '/' ) ) {
				continue;
			}

			$found[ str_contains( $path, 'about' ) ? 'about' : 'contact' ] = $page;
		}

		return $found;
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
		if ( ! preg_match_all( '#<a\b([^>]*)href\s*=\s*["\']([^"\']+)["\']([^>]*)>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER ) ) {
			return [];
		}

		$base_host = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
		$found     = [];

		foreach ( $matches as $match ) {
			$attrs_before = $match[1];
			$href         = html_entity_decode( trim( $match[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$attrs_after  = $match[3];
			$inner        = $match[4];

			if ( '' === $href || str_starts_with( $href, '#' ) || preg_match( '/^(mailto|tel|javascript|sms):/i', $href ) ) {
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

			$kind = $this->classify( $href, $inner, $attrs_before . ' ' . $attrs_after );

			if ( '' === $kind ) {
				continue;
			}

			// Strip the fragment so /contact and /contact#form are one page.
			$clean = strtok( $absolute, '#' ) ?: $absolute;

			if ( ! isset( $found[ $clean ] ) ) {
				$found[ $clean ] = $kind;
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

	/**
	 * Decide what kind of page a link points at.
	 *
	 * Everything is squashed to bare alphanumerics first, so separators, capitalisation,
	 * percent-encoding and file extensions stop mattering: `/Contact-Us.aspx`,
	 * `/contact_us.php`, `/contactus.htm` and `/contac-tus.html` all reduce to the same text.
	 * Image alt text and title attributes are included because plenty of older sites use a
	 * picture of the word "Contact" as the link.
	 */
	private function classify( string $href, string $inner_html, string $attrs ): string {
		$text = wp_strip_all_tags( $inner_html );

		// An image link carries its words in alt text, not between the tags.
		if ( preg_match_all( '/\b(?:alt|title|aria-label)\s*=\s*["\']([^"\']*)["\']/i', $inner_html . ' ' . $attrs, $m ) ) {
			$text .= ' ' . implode( ' ', $m[1] );
		}

		// Path and query, but not the domain. Older sites routinely carry the page identity
		// in the query string — /index.php?page=contact — so dropping it loses real matches.
		$parts = wp_parse_url( $href );
		$path  = is_array( $parts )
			? (string) ( $parts['path'] ?? '' ) . ' ' . (string) ( $parts['query'] ?? '' )
			: $href;

		$haystack = $this->squash( rawurldecode( trim( $path ) ) . ' ' . $text );

		if ( '' === $haystack ) {
			return '';
		}

		foreach ( self::FALSE_FRIENDS as $trap ) {
			if ( str_contains( $haystack, $trap ) ) {
				return '';
			}
		}

		foreach ( self::CONTACT_PATTERNS as $needle => $kind ) {
			if ( str_contains( $haystack, $needle ) ) {
				return $kind;
			}
		}

		return '';
	}

	/** Lower-case, strip accents, then drop everything that is not a letter or digit. */
	private function squash( string $value ): string {
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = strtolower( $value );

		// Fold the accented characters that appear in contact words: contáctenos, coordonnées.
		$value = strtr(
			$value,
			[
				'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
				'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
				'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
				'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
				'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
				'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
			]
		);

		return (string) preg_replace( '/[^a-z0-9]+/', '', $value );
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
