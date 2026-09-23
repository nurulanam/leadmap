<?php
/**
 * The problems an audit can record.
 *
 * Deliberately a superset of the triage flags: triage is a three-second glance and its
 * verdicts seed this form, but an audit is where things only a person can see get written
 * down. The primary issue chooses which outreach template is used, so every tag here has to
 * be something a message can actually be built around.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Audit;

defined( 'ABSPATH' ) || exit;

final class Issue_Tags {

	/**
	 * @return array<string,array{label:string,key:string,hint:string,from_triage:string}>
	 */
	public static function all(): array {
		return [
			'design'      => [
				'label'       => __( 'Dated design', 'leadmap' ),
				'key'         => '1',
				'hint'        => __( 'Looks years behind its competitors', 'leadmap' ),
				'from_triage' => 'outdated',
			],
			'speed'       => [
				'label'       => __( 'Slow', 'leadmap' ),
				'key'         => '2',
				'hint'        => __( 'Poor PageSpeed, slow to load', 'leadmap' ),
				'from_triage' => 'slow',
			],
			'mobile'      => [
				'label'       => __( 'Not mobile-friendly', 'leadmap' ),
				'key'         => '3',
				'hint'        => __( 'Unusable or broken on a phone', 'leadmap' ),
				'from_triage' => 'not_mobile',
			],
			'seo'         => [
				'label'       => __( 'SEO', 'leadmap' ),
				'key'         => '4',
				'hint'        => __( 'Missing titles, structure, content', 'leadmap' ),
				'from_triage' => 'poor_seo',
			],
			'security'    => [
				'label'       => __( 'Not secure', 'leadmap' ),
				'key'         => '5',
				'hint'        => __( 'No HTTPS, or a broken padlock', 'leadmap' ),
				'from_triage' => 'no_ssl',
			],
			'broken'      => [
				'label'       => __( 'Broken', 'leadmap' ),
				'key'         => '6',
				'hint'        => __( 'Errors, dead links, missing pages', 'leadmap' ),
				'from_triage' => 'broken',
			],
			'listing'     => [
				'label'       => __( 'Weak Google listing', 'leadmap' ),
				'key'         => '7',
				'hint'        => __( 'Few reviews, thin profile', 'leadmap' ),
				'from_triage' => 'weak_gmb',
			],
			// Below here: things only a person looking at the site can judge.
			'content'     => [
				'label'       => __( 'Thin content', 'leadmap' ),
				'key'         => '8',
				'hint'        => __( 'Says little about what they actually do', 'leadmap' ),
				'from_triage' => '',
			],
			'conversion'  => [
				'label'       => __( 'No clear call to action', 'leadmap' ),
				'key'         => '9',
				'hint'        => __( 'Hard to contact or book from the page', 'leadmap' ),
				'from_triage' => '',
			],
			'backlinks'   => [
				'label'       => __( 'Backlinks', 'leadmap' ),
				'key'         => '0',
				'hint'        => __( 'Little or no authority pointing at them', 'leadmap' ),
				'from_triage' => '',
			],
		];
	}

	/** @return string[] */
	public static function keys(): array {
		return array_keys( self::all() );
	}

	public static function exists( string $tag ): bool {
		return array_key_exists( $tag, self::all() );
	}

	public static function label( string $tag ): string {
		return self::all()[ $tag ]['label'] ?? $tag;
	}

	/**
	 * Carry the triage verdict across, so the audit form opens half-filled rather than blank.
	 *
	 * @param string[] $triage_flags
	 *
	 * @return string[]
	 */
	public static function from_triage( array $triage_flags ): array {
		$out = [];

		foreach ( self::all() as $tag => $meta ) {
			if ( '' !== $meta['from_triage'] && in_array( $meta['from_triage'], $triage_flags, true ) ) {
				$out[] = $tag;
			}
		}

		return $out;
	}

	/**
	 * Tags the automated checks support, so the form can mark them as evidenced.
	 *
	 * @param array<string,mixed> $enrichment
	 *
	 * @return string[]
	 */
	public static function evidenced( object $lead, array $enrichment ): array {
		$seo  = (array) ( $enrichment['seo'] ?? [] );
		$tech = (array) ( $enrichment['tech'] ?? [] );
		$psi  = $enrichment['speed_mobile']['score'] ?? null;
		$out  = [];

		if ( ! empty( $tech['dated_markers'] ) ) {
			$out[] = 'design';
		}

		if ( null !== $psi && (int) $psi < 50 ) {
			$out[] = 'speed';
		}

		if ( isset( $seo['has_viewport'] ) && ! $seo['has_viewport'] ) {
			$out[] = 'mobile';
		}

		if ( (int) ( $seo['gap_score'] ?? 0 ) >= 35 ) {
			$out[] = 'seo';
		}

		if ( ( isset( $seo['is_https'] ) && ! $seo['is_https'] ) || ! empty( $seo['mixed_content'] ) ) {
			$out[] = 'security';
		}

		$status = (int) ( $enrichment['http']['status'] ?? 0 );

		if ( ! empty( $enrichment['unreachable'] ) || $status >= 400 ) {
			$out[] = 'broken';
		}

		if ( (int) $lead->review_count < 10 ) {
			$out[] = 'listing';
		}

		return array_values( array_unique( $out ) );
	}
}
