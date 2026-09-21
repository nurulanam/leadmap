<?php
/**
 * An immutable description of one search request, independent of any provider.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Search;

defined( 'ABSPATH' ) || exit;

final class Search_Query {

	public function __construct(
		public readonly string $industry,
		public readonly string $location = '',
		public readonly string $zip = '',
		public readonly int $radius_m = 5000,
		public readonly int $max_results = 60,
		public readonly string $region_code = 'US',
		public readonly string $language_code = 'en',
		public readonly ?float $lat = null,
		public readonly ?float $lng = null,
		public readonly ?string $page_token = null,
	) {}

	/** The free-text query handed to the provider. */
	public function text(): string {
		$where = trim( $this->location . ' ' . $this->zip );

		return trim( $this->industry . ( '' !== $where ? ' in ' . $where : '' ) );
	}

	public function with_page_token( ?string $token ): self {
		return new self(
			$this->industry,
			$this->location,
			$this->zip,
			$this->radius_m,
			$this->max_results,
			$this->region_code,
			$this->language_code,
			$this->lat,
			$this->lng,
			$token
		);
	}

	public function with_center( ?float $lat, ?float $lng ): self {
		return new self(
			$this->industry,
			$this->location,
			$this->zip,
			$this->radius_m,
			$this->max_results,
			$this->region_code,
			$this->language_code,
			$lat,
			$lng,
			$this->page_token
		);
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'industry'      => $this->industry,
			'location'      => $this->location,
			'zip'           => $this->zip,
			'radius_m'      => $this->radius_m,
			'max_results'   => $this->max_results,
			'region_code'   => $this->region_code,
			'language_code' => $this->language_code,
			'lat'           => $this->lat,
			'lng'           => $this->lng,
		];
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['industry'] ?? '' ),
			(string) ( $data['location'] ?? '' ),
			(string) ( $data['zip'] ?? '' ),
			(int) ( $data['radius_m'] ?? 5000 ),
			(int) ( $data['max_results'] ?? 60 ),
			(string) ( $data['region_code'] ?? 'US' ),
			(string) ( $data['language_code'] ?? 'en' ),
			isset( $data['lat'] ) ? (float) $data['lat'] : null,
			isset( $data['lng'] ) ? (float) $data['lng'] : null,
			isset( $data['page_token'] ) ? (string) $data['page_token'] : null,
		);
	}
}
