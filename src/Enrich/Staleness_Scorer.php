<?php
/**
 * Rolls every automated signal into one 0–100 "how outdated is this" number.
 *
 * This does not decide anything — it sorts the Phase 3 triage queue so the most obviously
 * neglected sites reach the operator first. A human still gives the verdict.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

defined( 'ABSPATH' ) || exit;

final class Staleness_Scorer {

	/**
	 * @param array<string,mixed> $enrichment
	 *
	 * @return array{score:int,signals:array<int,array{key:string,label:string,weight:int}>}
	 */
	public function score( array $enrichment ): array {
		$signals = [];

		$http    = (array) ( $enrichment['http'] ?? [] );
		$seo     = (array) ( $enrichment['seo'] ?? [] );
		$tech    = (array) ( $enrichment['tech'] ?? [] );
		$speed   = (array) ( $enrichment['speed_mobile'] ?? [] );
		$status  = (int) ( $http['status'] ?? 0 );

		$add = static function ( string $key, string $label, int $weight ) use ( &$signals ): void {
			$signals[] = [ 'key' => $key, 'label' => $label, 'weight' => $weight ];
		};

		// --- Broken ---------------------------------------------------------------
		if ( ! empty( $enrichment['unreachable'] ) ) {
			$add( 'unreachable', __( 'Website does not respond', 'leadmap' ), 45 );
		} elseif ( $status >= 500 ) {
			$add( 'server_error', __( 'Server error on the home page', 'leadmap' ), 40 );
		} elseif ( $status >= 400 ) {
			$add( 'not_found', __( 'Home page returns an error', 'leadmap' ), 40 );
		}

		if ( ! empty( $enrichment['no_website'] ) ) {
			$add( 'no_website', __( 'No website at all', 'leadmap' ), 30 );
		}

		if ( isset( $seo['is_https'] ) && ! $seo['is_https'] ) {
			$add( 'no_ssl', __( 'No HTTPS — browsers show "Not secure"', 'leadmap' ), 25 );
		}

		if ( ! empty( $seo['mixed_content'] ) ) {
			$add( 'mixed_content', __( 'Mixed content breaks the padlock', 'leadmap' ), 10 );
		}

		// --- Not mobile ------------------------------------------------------------
		if ( isset( $seo['has_viewport'] ) && ! $seo['has_viewport'] ) {
			$add( 'no_viewport', __( 'No mobile viewport — desktop-only layout', 'leadmap' ), 25 );
		}

		// --- Old -------------------------------------------------------------------
		$year = (int) ( $seo['copyright_year'] ?? 0 );

		if ( $year > 0 ) {
			$age = (int) gmdate( 'Y' ) - $year;

			if ( $age >= 5 ) {
				$add( 'copyright_very_old', sprintf( __( 'Copyright still says %d', 'leadmap' ), $year ), 20 );
			} elseif ( $age >= 3 ) {
				$add( 'copyright_old', sprintf( __( 'Copyright says %d', 'leadmap' ), $year ), 12 );
			}
		}

		foreach ( (array) ( $tech['dated_markers'] ?? [] ) as $marker ) {
			$weights = [
				'flash'         => 25,
				'frameset'      => 25,
				'frontpage'     => 25,
				'marquee'       => 20,
				'font_tag'      => 15,
				'table_layout'  => 12,
				'bootstrap_2'   => 15,
				'bootstrap_3'   => 10,
				'xhtml_doctype' => 8,
			];

			$weight = 10;

			foreach ( $weights as $needle => $value ) {
				if ( str_starts_with( (string) $marker, $needle ) ) {
					$weight = $value;

					break;
				}
			}

			if ( str_starts_with( (string) $marker, 'jquery_' ) ) {
				$weight = 12;
			}

			if ( str_starts_with( (string) $marker, 'wordpress_' ) ) {
				$weight = 15;
			}

			$add( 'dated_' . $marker, $this->marker_label( (string) $marker ), $weight );
		}

		// --- Slow ------------------------------------------------------------------
		$psi = $speed['score'] ?? null;

		if ( null !== $psi ) {
			if ( $psi < 30 ) {
				$add( 'very_slow', sprintf( __( 'Google mobile speed score %d/100', 'leadmap' ), $psi ), 22 );
			} elseif ( $psi < 50 ) {
				$add( 'slow', sprintf( __( 'Google mobile speed score %d/100', 'leadmap' ), $psi ), 15 );
			} elseif ( $psi < 70 ) {
				$add( 'mediocre_speed', sprintf( __( 'Google mobile speed score %d/100', 'leadmap' ), $psi ), 6 );
			}
		}

		// --- SEO gaps ---------------------------------------------------------------
		$gap = (int) ( $seo['gap_score'] ?? 0 );

		if ( $gap >= 50 ) {
			$add( 'seo_poor', __( 'Major on-page SEO gaps', 'leadmap' ), 15 );
		} elseif ( $gap >= 25 ) {
			$add( 'seo_gaps', __( 'Several on-page SEO gaps', 'leadmap' ), 8 );
		}

		$total = 0;

		foreach ( $signals as $signal ) {
			$total += $signal['weight'];
		}

		return [
			'score'   => (int) min( 100, $total ),
			'signals' => $signals,
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
