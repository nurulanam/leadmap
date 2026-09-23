<?php
/**
 * How good a prospect is this, 0–100?
 *
 * Distinct from the opportunity score, and the distinction matters. Opportunity measures how
 * broken the website is. Fit measures whether it is worth writing to them — a catastrophic
 * site belonging to a business with no email address and four reviews is a poor prospect
 * however much work it needs, and a moderately dated site belonging to a busy firm you can
 * reach by name is a good one.
 *
 * Fit is what the outreach queue sorts by.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Audit;

defined( 'ABSPATH' ) || exit;

final class Fit_Scorer {

	private const WEIGHTS = [
		'opportunity'  => 40,
		'reachability' => 30,
		'viability'    => 20,
		'independence' => 10,
	];

	/**
	 * @param array<string,mixed> $enrichment
	 *
	 * @return array{score:int,factors:array<string,array{score:int,weight:int,summary:string}>}
	 */
	public function score( object $lead, array $enrichment ): array {
		$factors = [
			'opportunity'  => $this->opportunity( $lead ),
			'reachability' => $this->reachability( $lead ),
			'viability'    => $this->viability( $lead ),
			'independence' => $this->independence( $lead ),
		];

		$total = 0.0;

		foreach ( $factors as $name => $factor ) {
			$total += $factor['score'] * self::WEIGHTS[ $name ];
		}

		return [
			'score'   => (int) max( 0, min( 100, round( $total / array_sum( self::WEIGHTS ) ) ) ),
			'factors' => $factors,
		];
	}

	public static function labels(): array {
		return [
			'opportunity'  => __( 'Work needed', 'leadmap' ),
			'reachability' => __( 'Can we reach them', 'leadmap' ),
			'viability'    => __( 'Real, active business', 'leadmap' ),
			'independence' => __( 'Decision made locally', 'leadmap' ),
		];
	}

	/** How much is wrong with the site — the opportunity score, carried over. */
	private function opportunity( object $lead ): array {
		$score = null === $lead->staleness_score ? 50 : (int) $lead->staleness_score;

		return $this->factor(
			$score,
			'opportunity',
			match ( true ) {
				$score >= 70 => __( 'A great deal to fix', 'leadmap' ),
				$score >= 40 => __( 'Clear problems to point at', 'leadmap' ),
				$score >= 15 => __( 'Minor issues only', 'leadmap' ),
				default      => __( 'Little to sell against', 'leadmap' ),
			}
		);
	}

	/**
	 * Can we actually contact them?
	 *
	 * Weighted heavily because the whole pipeline ends in an email. A named address on the
	 * business's own domain is worth far more than a generic inbox, and a lead with only a
	 * phone number cannot enter the sequence at all.
	 */
	private function reachability( object $lead ): array {
		$email = (string) $lead->email;

		if ( '' === $email ) {
			return $this->factor(
				'' !== (string) $lead->phone ? 20 : 0,
				'reachability',
				'' !== (string) $lead->phone
					? __( 'Phone only — cannot be emailed', 'leadmap' )
					: __( 'No way to contact them', 'leadmap' )
			);
		}

		$confidence = (int) $lead->email_confidence;

		return $this->factor(
			$confidence,
			'reachability',
			match ( true ) {
				$confidence >= 80 => __( 'Strong email on their own domain', 'leadmap' ),
				$confidence >= 55 => __( 'Usable email address', 'leadmap' ),
				default           => __( 'Weak email — may not reach anyone', 'leadmap' ),
			}
		);
	}

	/**
	 * Is this a going concern?
	 *
	 * Review count is the proxy. A business with no reviews may be dormant or barely
	 * trading; one with a healthy number is visibly operating and has money moving.
	 */
	private function viability( object $lead ): array {
		$reviews = (int) $lead->review_count;
		$rating  = (float) ( $lead->rating ?? 0 );

		$score = match ( true ) {
			$reviews >= 100 => 100,
			$reviews >= 40  => 85,
			$reviews >= 15  => 70,
			$reviews >= 5   => 45,
			$reviews >= 1   => 25,
			default         => 10,
		};

		// A poorly rated business has other problems and is a harder sell.
		if ( $rating > 0 && $rating < 3.5 && $reviews >= 5 ) {
			$score = (int) round( $score * 0.7 );
		}

		return $this->factor(
			$score,
			'viability',
			match ( true ) {
				$reviews >= 40 => sprintf( /* translators: %d: review count. */ __( 'Busy — %d reviews', 'leadmap' ), $reviews ),
				$reviews >= 5  => sprintf( /* translators: %d: review count. */ __( 'Active — %d reviews', 'leadmap' ), $reviews ),
				default        => __( 'Little sign of activity', 'leadmap' ),
			}
		);
	}

	/**
	 * Is the website decision made here, or at head office?
	 *
	 * A franchise or chain branch cannot commission a new site, so the pitch goes nowhere
	 * however bad the page is. A very high review count alongside a corporate-looking domain
	 * is the cheap signal available without a paid data source.
	 */
	private function independence( object $lead ): array {
		$reviews = (int) $lead->review_count;
		$domain  = (string) $lead->domain;

		$looks_corporate = '' !== $domain && (bool) preg_match(
			'/(^|\.)(locations?|stores?|branch|find)\./i',
			$domain
		);

		if ( $looks_corporate || $reviews >= 1500 ) {
			return $this->factor( 15, 'independence', __( 'Likely a chain or franchise', 'leadmap' ) );
		}

		if ( $reviews >= 600 ) {
			return $this->factor( 45, 'independence', __( 'Large — may not decide locally', 'leadmap' ) );
		}

		if ( '' === $domain ) {
			return $this->factor( 60, 'independence', __( 'No website of their own', 'leadmap' ) );
		}

		return $this->factor( 100, 'independence', __( 'Independent local business', 'leadmap' ) );
	}

	private function factor( int $score, string $name, string $summary ): array {
		return [
			'score'   => (int) max( 0, min( 100, $score ) ),
			'weight'  => self::WEIGHTS[ $name ],
			'summary' => $summary,
		];
	}
}
