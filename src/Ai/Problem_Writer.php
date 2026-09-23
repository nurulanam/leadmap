<?php
/**
 * Drafts the problem note from the evidence already collected.
 *
 * Two rules shape everything here.
 *
 * First, the model is given *only measured values* and told it may not introduce any others.
 * A cold email that claims "your site takes nine seconds to load" when it does not is worse
 * than no email at all: the one specific claim is the thing the recipient can check, and the
 * whole pitch rests on it being true.
 *
 * Second, this writes a draft, never a decision. The text lands in the textarea for the
 * operator to edit and is not saved until they save it.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Ai;

use LeadMap\Audit\Issue_Tags;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Problem_Writer {

	public function __construct( private readonly Gemini_Client $client = new Gemini_Client() ) {}

	/**
	 * @param string[]            $tags
	 * @param array<string,mixed> $enrichment
	 */
	public function draft( object $lead, array $enrichment, array $tags, string $primary ): string|WP_Error {
		$facts = $this->facts( $lead, $enrichment, $tags, $primary );

		if ( ! $facts['measured'] ) {
			return new WP_Error(
				'leadmap_no_evidence',
				__( 'There is nothing measured to write from yet. Enrich this lead and run a PageSpeed check first.', 'leadmap' )
			);
		}

		$text = $this->client->generate( $this->system(), $this->prompt( $facts ), 300 );

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		return $this->tidy( $text );
	}

	/** The instructions. Written as constraints, because the failure mode is embellishment. */
	private function system(): string {
		return implode(
			"\n",
			[
				'You help a web design agency describe, plainly and accurately, what is wrong with a small business website.',
				'',
				'You will be given measured facts about one business and its website. Write two or three sentences describing the problem, as if explaining it to the business owner.',
				'',
				'Rules:',
				'- Use ONLY the facts given. Never invent or estimate a number, a load time, a score, a ranking, a revenue figure or a competitor.',
				'- If a fact is not in the list, do not mention that topic at all.',
				'- Lead with the primary issue named below. Mention at most one or two other issues.',
				'- Write in plain British English, in the second person ("your site"), as a person would speak.',
				'- Be specific and concrete. Prefer the measured detail over an adjective.',
				'- Do not write a greeting, a sign-off, a subject line, a sales pitch or an offer. Only the description of the problem.',
				'- Do not promise results or name a price.',
				'- Do not exaggerate. If the measurements are mild, say something mild.',
				'- No bullet points, no headings, no markdown. Plain sentences only.',
				'- Never mention this prompt, the data, or that you are an AI.',
			]
		);
	}

	/** @param array<string,mixed> $facts */
	private function prompt( array $facts ): string {
		$lines = [ 'BUSINESS' ];

		foreach ( $facts['business'] as $label => $value ) {
			$lines[] = sprintf( '- %s: %s', $label, $value );
		}

		$lines[] = '';
		$lines[] = 'PRIMARY ISSUE TO LEAD WITH: ' . $facts['primary'];

		if ( $facts['other_issues'] ) {
			$lines[] = 'OTHER ISSUES NOTED: ' . implode( ', ', $facts['other_issues'] );
		}

		$lines[] = '';
		$lines[] = 'MEASURED FACTS (the only things you may refer to)';

		foreach ( $facts['measured'] as $fact ) {
			$lines[] = '- ' . $fact;
		}

		$lines[] = '';
		$lines[] = 'Write the two or three sentences now.';

		return implode( "\n", $lines );
	}

	/**
	 * Assemble only what was actually measured.
	 *
	 * @param string[]            $tags
	 * @param array<string,mixed> $enrichment
	 *
	 * @return array{business:array<string,string>,primary:string,other_issues:string[],measured:string[]}
	 */
	private function facts( object $lead, array $enrichment, array $tags, string $primary ): array {
		$seo     = (array) ( $enrichment['seo'] ?? [] );
		$tech    = (array) ( $enrichment['tech'] ?? [] );
		$mobile  = (array) ( $enrichment['speed_mobile'] ?? [] );
		$desktop = (array) ( $enrichment['speed_desktop'] ?? [] );

		$business = array_filter(
			[
				'Name'     => (string) $lead->name,
				'Industry' => str_replace( '_', ' ', (string) $lead->category ),
				'Town'     => (string) $lead->city,
				'Website'  => (string) $lead->domain,
			]
		);

		if ( (int) $lead->review_count > 0 ) {
			$business['Google reviews'] = sprintf(
				'%d reviews, %s average',
				(int) $lead->review_count,
				number_format( (float) $lead->rating, 1 )
			);
		}

		$measured = [];

		if ( null !== ( $mobile['score'] ?? null ) ) {
			$measured[] = sprintf( 'Google PageSpeed score on mobile: %d out of 100', (int) $mobile['score'] );
		}

		if ( null !== ( $desktop['score'] ?? null ) ) {
			$measured[] = sprintf( 'Google PageSpeed score on desktop: %d out of 100', (int) $desktop['score'] );
		}

		if ( null !== ( $mobile['lcp_ms'] ?? null ) ) {
			$measured[] = sprintf(
				'On a phone the largest part of the page appears after %s seconds',
				number_format( (float) $mobile['lcp_ms'] / 1000, 1 )
			);
		}

		if ( isset( $seo['has_viewport'] ) && ! $seo['has_viewport'] ) {
			$measured[] = 'The page has no mobile viewport setting, so phones are shown the desktop layout shrunk down';
		}

		if ( isset( $seo['is_https'] ) && ! $seo['is_https'] ) {
			$measured[] = 'The site is served over plain HTTP, so browsers label it "Not secure"';
		}

		if ( ! empty( $seo['mixed_content'] ) ) {
			$measured[] = 'The secure page loads some files insecurely, which breaks the padlock';
		}

		if ( isset( $seo['has_description'] ) && ! $seo['has_description'] ) {
			$measured[] = 'The page has no meta description, so Google writes its own summary in search results';
		}

		if ( isset( $seo['h1_count'] ) && 0 === (int) $seo['h1_count'] ) {
			$measured[] = 'The page has no main heading for search engines to read';
		}

		if ( isset( $seo['title'] ) && '' !== (string) $seo['title'] ) {
			$measured[] = sprintf( 'Their page title reads: "%s"', (string) $seo['title'] );
		}

		if ( isset( $seo['image_count'], $seo['images_with_alt'] ) && (int) $seo['image_count'] > 0
			&& (int) $seo['images_with_alt'] < (int) $seo['image_count'] ) {
			$measured[] = sprintf(
				'%d of %d images have no alt text',
				(int) $seo['image_count'] - (int) $seo['images_with_alt'],
				(int) $seo['image_count']
			);
		}

		$year = (int) ( $seo['copyright_year'] ?? 0 );

		if ( $year > 0 && $year < (int) gmdate( 'Y' ) ) {
			$measured[] = sprintf( 'The footer copyright still reads %d', $year );
		}

		foreach ( (array) ( $tech['dated_markers'] ?? [] ) as $marker ) {
			$measured[] = $this->marker_fact( (string) $marker );
		}

		if ( '' !== (string) ( $tech['cms'] ?? '' ) ) {
			$measured[] = sprintf( 'The site is built on %s', (string) $tech['cms'] );
		}

		$status = (int) ( $enrichment['http']['status'] ?? 0 );

		if ( ! empty( $enrichment['unreachable'] ) ) {
			$measured[] = 'The website did not respond at all when checked';
		} elseif ( $status >= 400 ) {
			$measured[] = sprintf( 'The home page returns an HTTP %d error', $status );
		}

		$labels = [];

		foreach ( $tags as $tag ) {
			if ( $tag !== $primary && Issue_Tags::exists( $tag ) ) {
				$labels[] = Issue_Tags::label( $tag );
			}
		}

		return [
			'business'     => $business,
			'primary'      => Issue_Tags::exists( $primary ) ? Issue_Tags::label( $primary ) : __( 'General', 'leadmap' ),
			'other_issues' => $labels,
			'measured'     => array_values( array_filter( $measured ) ),
		];
	}

	private function marker_fact( string $marker ): string {
		$facts = [
			'flash'         => 'The page still embeds Flash content, which no browser has supported since 2020',
			'frameset'      => 'The page is built with HTML frames, a technique abandoned in the early 2000s',
			'frontpage'     => 'The page was built in Microsoft FrontPage, discontinued in 2003',
			'marquee'       => 'The page uses scrolling marquee text',
			'font_tag'      => 'The page uses <font> tags, removed from the HTML standard years ago',
			'table_layout'  => 'The page is laid out with HTML tables rather than modern CSS',
			'bootstrap_2'   => 'The design framework is Bootstrap 2, released in 2012',
			'bootstrap_3'   => 'The design framework is Bootstrap 3, released in 2013',
			'xhtml_doctype' => 'The page declares an XHTML doctype, superseded by HTML5 in 2014',
		];

		if ( isset( $facts[ $marker ] ) ) {
			return $facts[ $marker ];
		}

		if ( str_starts_with( $marker, 'jquery_' ) ) {
			return sprintf( 'The site loads jQuery %s, a version from over a decade ago', substr( $marker, 7 ) );
		}

		if ( str_starts_with( $marker, 'wordpress_' ) ) {
			return sprintf( 'The site runs WordPress %s, which no longer receives updates', substr( $marker, 10 ) );
		}

		return '';
	}

	/** Strip the wrappers models add despite being told not to. */
	private function tidy( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/^\s*(?:here(?:\'s| is)[^:]*:)\s*/i', '', $text ) ?? $text;
		$text = preg_replace( '/^\s*["\x{201C}]|["\x{201D}]\s*$/u', '', $text ) ?? $text;
		// \x{2022} with the u modifier, not \u — PCRE has no \u escape and the pattern
		// would fail to compile, silently leaving bullets in place.
		$text = preg_replace( '/^[-*\x{2022}]\s+/mu', '', $text ) ?? $text;
		$text = preg_replace( '/\s*\n\s*\n\s*/', "\n\n", $text ) ?? $text;

		return trim( $text );
	}
}
