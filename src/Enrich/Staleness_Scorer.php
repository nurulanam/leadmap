<?php
/**
 * The opportunity score: how much is wrong with this website, 0–100.
 *
 * Earlier this was a flat sum of penalties, which saturated almost immediately — a merely
 * dated site and a catastrophic one both landed on 100, so the number could not rank the
 * queue, which is its only job.
 *
 * It is now a weighted composite of five independent categories, each scored 0–100 on its
 * own terms and then combined. Two consequences matter:
 *
 *  - A category with no data is dropped and the remaining weights are renormalised, so a
 *    lead whose PageSpeed has not arrived yet is not quietly penalised for it. `confidence`
 *    reports how much of the picture was actually available.
 *  - The breakdown is kept, so the UI can say *why* a lead scores what it does rather than
 *    presenting a bare number.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

defined( 'ABSPATH' ) || exit;

final class Staleness_Scorer {

	/** Category weights. Speed leads because it is the claim a prospect can verify. */
	private const WEIGHTS = [
		'speed'       => 30,
		'mobile'      => 20,
		'tech_age'    => 20,
		'seo'         => 15,
		'reliability' => 15,
	];

	/**
	 * @param array<string,mixed> $enrichment
	 *
	 * @return array{score:int,confidence:int,categories:array<string,array<string,mixed>>,signals:array<int,array{key:string,label:string,weight:int}>}
	 */
	public function score( array $enrichment ): array {
		$signals = [];

		$categories = [
			'reliability' => $this->reliability( $enrichment, $signals ),
			'mobile'      => $this->mobile( $enrichment, $signals ),
			'speed'       => $this->speed( $enrichment, $signals ),
			'tech_age'    => $this->tech_age( $enrichment, $signals ),
			'seo'         => $this->seo( $enrichment, $signals ),
		];

		$total          = 0.0;
		$weight_used    = 0;
		$weight_total   = array_sum( self::WEIGHTS );

		foreach ( $categories as $name => $category ) {
			if ( ! $category['available'] ) {
				continue;
			}

			$weight       = self::WEIGHTS[ $name ];
			$total       += $category['score'] * $weight;
			$weight_used += $weight;
		}

		// No data at all: an unknown site is not an opportunity, it is an unknown.
		$score = $weight_used > 0 ? (int) round( $total / $weight_used ) : 0;

		// A dead site is the strongest signal there is; do not let it be diluted by the
		// categories that cannot be measured because the site never loaded.
		if ( $categories['reliability']['available'] && $categories['reliability']['score'] >= 100 ) {
			$score = max( $score, 85 );
		}

		usort( $signals, static fn( array $a, array $b ): int => $b['weight'] <=> $a['weight'] );

		return [
			'score'      => (int) max( 0, min( 100, $score ) ),
			'confidence' => (int) round( $weight_used / $weight_total * 100 ),
			'categories' => $categories,
			'signals'    => $signals,
		];
	}

	/** @return array<string,string> category => label, for the UI. */
	public static function labels(): array {
		return [
			'speed'       => __( 'Speed', 'leadmap' ),
			'mobile'      => __( 'Mobile', 'leadmap' ),
			'tech_age'    => __( 'Age', 'leadmap' ),
			'seo'         => __( 'SEO', 'leadmap' ),
			'reliability' => __( 'Reliability', 'leadmap' ),
		];
	}

	public static function weights(): array {
		return self::WEIGHTS;
	}

	/** Does the site load, and is it secure? */
	private function reliability( array $e, array &$signals ): array {
		$status = (int) ( $e['http']['status'] ?? 0 );
		$seo    = (array) ( $e['seo'] ?? [] );

		if ( ! empty( $e['no_website'] ) ) {
			$this->add( $signals, 'no_website', __( 'No website at all', 'leadmap' ), 100 );

			return $this->cat( 100, true, __( 'No website', 'leadmap' ) );
		}

		if ( ! empty( $e['unreachable'] ) ) {
			$this->add( $signals, 'unreachable', __( 'Website does not respond', 'leadmap' ), 100 );

			return $this->cat( 100, true, __( 'Does not respond', 'leadmap' ) );
		}

		if ( 0 === $status ) {
			return $this->cat( 0, false, __( 'Not checked', 'leadmap' ) );
		}

		if ( $status >= 500 ) {
			$this->add( $signals, 'server_error', __( 'Server error on the home page', 'leadmap' ), 100 );

			return $this->cat( 100, true, sprintf( 'HTTP %d', $status ) );
		}

		if ( $status >= 400 ) {
			$this->add( $signals, 'not_found', __( 'Home page returns an error', 'leadmap' ), 100 );

			return $this->cat( 100, true, sprintf( 'HTTP %d', $status ) );
		}

		$score = 0;

		if ( isset( $seo['is_https'] ) && ! $seo['is_https'] ) {
			$score += 70;

			$this->add( $signals, 'no_ssl', __( 'No HTTPS — browsers show "Not secure"', 'leadmap' ), 70 );
		}

		if ( ! empty( $seo['mixed_content'] ) ) {
			$score += 30;

			$this->add( $signals, 'mixed_content', __( 'Mixed content breaks the padlock', 'leadmap' ), 30 );
		}

		return $this->cat( min( 100, $score ), true, 0 === $score ? __( 'Loads fine over HTTPS', 'leadmap' ) : __( 'Security problems', 'leadmap' ) );
	}

	/** Is it usable on a phone? */
	private function mobile( array $e, array &$signals ): array {
		$seo = (array) ( $e['seo'] ?? [] );

		if ( ! array_key_exists( 'has_viewport', $seo ) ) {
			return $this->cat( 0, false, __( 'Not checked', 'leadmap' ) );
		}

		if ( ! $seo['has_viewport'] ) {
			$this->add( $signals, 'no_viewport', __( 'No mobile viewport — desktop-only layout', 'leadmap' ), 100 );

			return $this->cat( 100, true, __( 'Desktop only', 'leadmap' ) );
		}

		// Responsive in markup, but far worse on a phone than a desktop: the owner works on
		// a laptop and has probably never seen what visitors get.
		$mobile  = $e['speed_mobile']['score'] ?? null;
		$desktop = $e['speed_desktop']['score'] ?? null;

		if ( null !== $mobile && null !== $desktop ) {
			$gap = (int) $desktop - (int) $mobile;

			if ( $gap >= 40 ) {
				$this->add( $signals, 'mobile_gap', sprintf( __( 'Desktop scores %d points better than mobile', 'leadmap' ), $gap ), 60 );

				return $this->cat( 60, true, __( 'Much worse on a phone', 'leadmap' ) );
			}

			if ( $gap >= 25 ) {
				$this->add( $signals, 'mobile_gap', sprintf( __( 'Desktop scores %d points better than mobile', 'leadmap' ), $gap ), 35 );

				return $this->cat( 35, true, __( 'Worse on a phone', 'leadmap' ) );
			}
		}

		return $this->cat( 0, true, __( 'Mobile ready', 'leadmap' ) );
	}

	/** How fast is it, according to Google? */
	private function speed( array $e, array &$signals ): array {
		$mobile  = $e['speed_mobile']['score'] ?? null;
		$desktop = $e['speed_desktop']['score'] ?? null;

		if ( null === $mobile && null === $desktop ) {
			return $this->cat( 0, false, __( 'Not measured yet', 'leadmap' ) );
		}

		// Mobile carries the weight — it is how local searches actually happen — but a
		// single measurement is used in full rather than half-counted.
		if ( null !== $mobile && null !== $desktop ) {
			$penalty = ( ( 100 - (int) $mobile ) * 0.7 ) + ( ( 100 - (int) $desktop ) * 0.3 );
			$summary = sprintf( __( 'Mobile %1$d, desktop %2$d', 'leadmap' ), (int) $mobile, (int) $desktop );
		} elseif ( null !== $mobile ) {
			$penalty = 100 - (int) $mobile;
			$summary = sprintf( __( 'Mobile %d (desktop pending)', 'leadmap' ), (int) $mobile );
		} else {
			$penalty = 100 - (int) $desktop;
			$summary = sprintf( __( 'Desktop %d (mobile pending)', 'leadmap' ), (int) $desktop );
		}

		$primary = $mobile ?? $desktop;

		if ( $primary < 50 ) {
			$this->add(
				$signals,
				'slow',
				sprintf( __( 'Google speed score %d/100', 'leadmap' ), (int) $primary ),
				(int) round( $penalty )
			);
		}

		return $this->cat( (int) round( $penalty ), true, $summary );
	}

	/** How old does the build look? */
	private function tech_age( array $e, array &$signals ): array {
		$tech = $e['tech'] ?? null;
		$seo  = (array) ( $e['seo'] ?? [] );

		if ( ! is_array( $tech ) ) {
			return $this->cat( 0, false, __( 'Not checked', 'leadmap' ) );
		}

		$weights = [
			'flash'         => 50,
			'frameset'      => 50,
			'frontpage'     => 50,
			'marquee'       => 40,
			'font_tag'      => 30,
			'table_layout'  => 25,
			'bootstrap_2'   => 30,
			'bootstrap_3'   => 18,
			'xhtml_doctype' => 15,
		];

		$score  = 0;
		$oldest = '';

		foreach ( (array) ( $tech['dated_markers'] ?? [] ) as $marker ) {
			$marker = (string) $marker;
			$weight = 20;

			foreach ( $weights as $needle => $value ) {
				if ( str_starts_with( $marker, $needle ) ) {
					$weight = $value;

					break;
				}
			}

			if ( str_starts_with( $marker, 'jquery_' ) ) {
				$weight = 25;
			}

			if ( str_starts_with( $marker, 'wordpress_' ) ) {
				$weight = 30;
			}

			$score += $weight;

			if ( '' === $oldest ) {
				$oldest = $this->marker_label( $marker );
			}

			$this->add( $signals, 'dated_' . $marker, $this->marker_label( $marker ), $weight );
		}

		$year = (int) ( $seo['copyright_year'] ?? 0 );

		if ( $year > 0 ) {
			$age = (int) gmdate( 'Y' ) - $year;

			if ( $age >= 5 ) {
				$score += 40;

				$this->add( $signals, 'copyright_very_old', sprintf( __( 'Copyright still says %d', 'leadmap' ), $year ), 40 );
			} elseif ( $age >= 3 ) {
				$score += 22;

				$this->add( $signals, 'copyright_old', sprintf( __( 'Copyright says %d', 'leadmap' ), $year ), 22 );
			}
		}

		$summary = $score >= 60
			? ( $oldest ?: __( 'Looks years out of date', 'leadmap' ) )
			: ( $score > 0 ? __( 'Showing its age', 'leadmap' ) : __( 'Nothing dated found', 'leadmap' ) );

		return $this->cat( min( 100, $score ), true, $summary );
	}

	/** How much on-page SEO is missing? */
	private function seo( array $e, array &$signals ): array {
		$seo = $e['seo'] ?? null;

		if ( ! is_array( $seo ) || ! array_key_exists( 'gap_score', $seo ) ) {
			return $this->cat( 0, false, __( 'Not checked', 'leadmap' ) );
		}

		$gap = (int) $seo['gap_score'];

		if ( $gap >= 50 ) {
			$this->add( $signals, 'seo_poor', __( 'Major on-page SEO gaps', 'leadmap' ), $gap );
		} elseif ( $gap >= 25 ) {
			$this->add( $signals, 'seo_gaps', __( 'Several on-page SEO gaps', 'leadmap' ), $gap );
		}

		$summary = match ( true ) {
			$gap >= 50 => __( 'Major gaps', 'leadmap' ),
			$gap >= 25 => __( 'Several gaps', 'leadmap' ),
			$gap > 0   => __( 'Minor gaps', 'leadmap' ),
			default    => __( 'Basics in place', 'leadmap' ),
		};

		return $this->cat( min( 100, $gap ), true, $summary );
	}

	/** @return array{score:int,available:bool,summary:string} */
	private function cat( int $score, bool $available, string $summary ): array {
		return [
			'score'     => $score,
			'available' => $available,
			'summary'   => $summary,
		];
	}

	private function add( array &$signals, string $key, string $label, int $weight ): void {
		$signals[] = [
			'key'    => $key,
			'label'  => $label,
			'weight' => $weight,
		];
	}

	private function marker_label( string $marker ): string {
		$labels = [
			'flash'         => __( 'Flash content — dead since 2020', 'leadmap' ),
			'frameset'      => __( 'Uses frames — 1990s markup', 'leadmap' ),
			'frontpage'     => __( 'Built in Microsoft FrontPage', 'leadmap' ),
			'marquee'       => __( 'Scrolling marquee text', 'leadmap' ),
			'font_tag'      => __( 'Uses <font> tags', 'leadmap' ),
			'table_layout'  => __( 'Table-based layout', 'leadmap' ),
			'bootstrap_2'   => __( 'Bootstrap 2 — released 2012', 'leadmap' ),
			'bootstrap_3'   => __( 'Bootstrap 3 — released 2013', 'leadmap' ),
			'xhtml_doctype' => __( 'XHTML doctype', 'leadmap' ),
		];

		if ( isset( $labels[ $marker ] ) ) {
			return $labels[ $marker ];
		}

		if ( str_starts_with( $marker, 'jquery_' ) ) {
			return sprintf( __( 'jQuery %s — long out of date', 'leadmap' ), substr( $marker, 7 ) );
		}

		if ( str_starts_with( $marker, 'wordpress_' ) ) {
			return sprintf( __( 'WordPress %s — unmaintained', 'leadmap' ), substr( $marker, 10 ) );
		}

		return $marker;
	}
}
