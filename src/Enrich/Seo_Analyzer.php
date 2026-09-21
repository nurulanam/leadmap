<?php
/**
 * Checks the on-page SEO basics a pitch can point at.
 *
 * Every check answers a question a business owner would understand: does the page have a
 * title, does it say what the business does, will Google see it on a phone.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

defined( 'ABSPATH' ) || exit;

final class Seo_Analyzer {

	/**
	 * @return array<string,mixed> Flags plus a 0–100 gap score, higher meaning worse.
	 */
	public function analyze( Fetch_Result $page ): array {
		$html = $page->body;

		$title = '';

		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $html, $m ) ) {
			$title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		$description = '';

		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)/i', $html, $m ) ) {
			$description = trim( $m[1] );
		}

		$h1_count = preg_match_all( '#<h1\b[^>]*>#i', $html );

		preg_match_all( '#<img\b[^>]*>#i', $html, $images );
		$image_count = count( $images[0] );
		$with_alt    = 0;

		foreach ( $images[0] as $img ) {
			if ( preg_match( '/\balt\s*=\s*["\'][^"\']+["\']/i', $img ) ) {
				++$with_alt;
			}
		}

		$is_https = str_starts_with( strtolower( $page->final_url ), 'https://' );

		$out = [
			'title'            => $title,
			'title_length'     => mb_strlen( $title ),
			'has_title'        => '' !== $title,
			'title_too_short'  => '' !== $title && mb_strlen( $title ) < 20,
			'title_too_long'   => mb_strlen( $title ) > 65,
			'has_description'  => '' !== $description,
			'description_length' => mb_strlen( $description ),
			'h1_count'         => $h1_count,
			'has_single_h1'    => 1 === $h1_count,
			'image_count'      => $image_count,
			'images_with_alt'  => $with_alt,
			'alt_coverage'     => $image_count > 0 ? (int) round( $with_alt / $image_count * 100 ) : 100,
			'has_viewport'     => (bool) preg_match( '/<meta[^>]+name=["\']viewport["\']/i', $html ),
			'has_schema'       => (bool) preg_match( '#application/ld\+json#i', $html ),
			'has_canonical'    => (bool) preg_match( '/<link[^>]+rel=["\']canonical["\']/i', $html ),
			'has_open_graph'   => (bool) preg_match( '/<meta[^>]+property=["\']og:/i', $html ),
			'is_https'         => $is_https,
			'has_favicon'      => (bool) preg_match( '/<link[^>]+rel=["\'][^"\']*icon/i', $html ),
			'mixed_content'    => $is_https && (bool) preg_match( '#(?:src|href)\s*=\s*["\']http://#i', $html ),
			'copyright_year'   => $this->copyright_year( $html ),
		];

		$out['gap_score'] = $this->gap_score( $out );

		return $out;
	}

	/** The most recent copyright year in the page, or 0 when there is none. */
	private function copyright_year( string $html ): int {
		$text = wp_strip_all_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( ! preg_match_all( '/(?:©|\(c\)|copyright)\s*(?:[0-9]{4}\s*[–-]\s*)?((?:19|20)[0-9]{2})/iu', $text, $m ) ) {
			return 0;
		}

		$years = array_map( 'intval', $m[1] );
		$this_year = (int) gmdate( 'Y' );

		// Ignore anything in the future — a typo should not read as "fresh".
		$years = array_filter( $years, static fn( int $y ): bool => $y <= $this_year );

		return $years ? max( $years ) : 0;
	}

	/** @param array<string,mixed> $f */
	private function gap_score( array $f ): int {
		$score = 0;

		$score += $f['has_title'] ? 0 : 20;
		$score += $f['title_too_short'] ? 5 : 0;
		$score += $f['title_too_long'] ? 3 : 0;
		$score += $f['has_description'] ? 0 : 15;
		$score += $f['has_single_h1'] ? 0 : 10;
		$score += $f['has_viewport'] ? 0 : 20;
		$score += $f['is_https'] ? 0 : 20;
		$score += $f['mixed_content'] ? 10 : 0;
		$score += $f['has_schema'] ? 0 : 8;
		$score += $f['has_canonical'] ? 0 : 4;
		$score += $f['has_open_graph'] ? 0 : 4;
		$score += (int) round( ( 100 - $f['alt_coverage'] ) / 10 );

		return (int) min( 100, $score );
	}
}
