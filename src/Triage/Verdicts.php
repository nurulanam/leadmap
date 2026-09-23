<?php
/**
 * The triage vocabulary.
 *
 * Four "pitchable" flags that are multi-select — a site can be both outdated and broken —
 * and three terminal outcomes that take the lead out of the queue.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Triage;

defined( 'ABSPATH' ) || exit;

final class Verdicts {

	/** Flags that mean "worth pitching"; combinable. */
	public const FLAGS = [ 'outdated', 'broken', 'not_mobile', 'slow', 'no_ssl', 'poor_seo', 'weak_gmb' ];

	/** Terminal verdicts; mutually exclusive and not combinable with flags. */
	public const TERMINAL = [ 'skip', 'no_website', 'unsure' ];

	/** @return array<string,array{label:string,key:string,hint:string}> */
	public static function flags(): array {
		return [
			'outdated'   => [
				'label' => __( 'Outdated', 'leadmap' ),
				'key'   => '1',
				'hint'  => __( 'Works, but looks years old', 'leadmap' ),
			],
			'broken'     => [
				'label' => __( 'Broken', 'leadmap' ),
				'key'   => '2',
				'hint'  => __( 'Errors, dead pages, no SSL', 'leadmap' ),
			],
			'not_mobile' => [
				'label' => __( 'Not mobile', 'leadmap' ),
				'key'   => '3',
				'hint'  => __( 'Unusable on a phone', 'leadmap' ),
			],
			'slow'       => [
				'label' => __( 'Slow', 'leadmap' ),
				'key'   => '4',
				'hint'  => __( 'Looks fine, but poor PageSpeed', 'leadmap' ),
			],
			'no_ssl'     => [
				'label' => __( 'No SSL', 'leadmap' ),
				'key'   => '5',
				'hint'  => __( 'Browsers mark it "Not secure"', 'leadmap' ),
			],
			'poor_seo'   => [
				'label' => __( 'Poor SEO', 'leadmap' ),
				'key'   => '6',
				'hint'  => __( 'Missing titles, descriptions, structure', 'leadmap' ),
			],
			'weak_gmb'   => [
				'label' => __( 'Weak listing', 'leadmap' ),
				'key'   => '7',
				'hint'  => __( 'Few reviews or a thin Google profile', 'leadmap' ),
			],
		];
	}

	/**
	 * Which flags the automated checks point at.
	 *
	 * Offered as hints, never pre-selected. Pre-ticking them would turn a judgement into a
	 * rubber stamp, and the whole value of this stage is a human looking at the thing.
	 *
	 * @param array<string,mixed> $enrichment
	 *
	 * @return string[]
	 */
	public static function suggest( object $lead, array $enrichment ): array {
		$seo   = (array) ( $enrichment['seo'] ?? [] );
		$tech  = (array) ( $enrichment['tech'] ?? [] );
		$cats  = (array) ( $enrichment['score_categories'] ?? [] );
		$psi   = $enrichment['speed_mobile']['score'] ?? null;
		$out   = [];

		$status = (int) ( $enrichment['http']['status'] ?? 0 );

		if ( ! empty( $enrichment['unreachable'] ) || $status >= 400 ) {
			$out[] = 'broken';
		}

		if ( isset( $seo['is_https'] ) && ! $seo['is_https'] ) {
			$out[] = 'no_ssl';
			$out[] = 'broken';
		}

		if ( isset( $seo['has_viewport'] ) && ! $seo['has_viewport'] ) {
			$out[] = 'not_mobile';
		}

		if ( null !== $psi && (int) $psi < 50 ) {
			$out[] = 'slow';
		}

		if ( ! empty( $tech['dated_markers'] ) ) {
			$out[] = 'outdated';
		}

		$age = (int) ( $cats['tech_age']['score'] ?? 0 );

		if ( $age >= 40 ) {
			$out[] = 'outdated';
		}

		if ( (int) ( $seo['gap_score'] ?? 0 ) >= 35 ) {
			$out[] = 'poor_seo';
		}

		// A business with almost no reviews has a weak listing whatever its site looks like.
		if ( (int) $lead->review_count < 10 ) {
			$out[] = 'weak_gmb';
		}

		return array_values( array_unique( array_intersect( $out, self::FLAGS ) ) );
	}

	/** @return array<string,array{label:string,key:string,status:string,hint:string}> */
	public static function terminal(): array {
		return [
			'skip'       => [
				'label'  => __( 'Skip', 'leadmap' ),
				'key'    => 'S',
				'status' => 'skipped',
				'hint'   => __( 'Modern and fine — nothing to sell', 'leadmap' ),
			],
			'no_website' => [
				'label'  => __( 'No website', 'leadmap' ),
				'key'    => 'N',
				'status' => 'no_website',
				'hint'   => __( 'Nothing to audit — a build lead instead', 'leadmap' ),
			],
			'unsure'     => [
				'label'  => __( 'Unsure', 'leadmap' ),
				'key'    => 'U',
				'status' => 'triage_deferred',
				'hint'   => __( 'Come back to this one', 'leadmap' ),
			],
		];
	}

	public static function is_flag( string $value ): bool {
		return in_array( $value, self::FLAGS, true );
	}

	public static function is_terminal( string $value ): bool {
		return in_array( $value, self::TERMINAL, true );
	}

	/** The lead status a verdict results in. */
	public static function status_for( string $verdict ): string {
		$terminal = self::terminal();

		return $terminal[ $verdict ]['status'] ?? 'triaged';
	}

	/** Triage flags seed the deep audit's issue tags, so it starts half-filled. */
	public static function label( string $value ): string {
		$flags    = self::flags();
		$terminal = self::terminal();

		return $flags[ $value ]['label'] ?? $terminal[ $value ]['label'] ?? $value;
	}
}
