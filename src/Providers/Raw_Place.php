<?php
/**
 * One business as returned by a provider, already mapped onto our field names.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Providers;

defined( 'ABSPATH' ) || exit;

final class Raw_Place {

	public function __construct(
		public readonly string $external_id,
		public readonly string $name,
		public readonly string $phone = '',
		public readonly string $website = '',
		public readonly string $address = '',
		public readonly string $city = '',
		public readonly string $state = '',
		public readonly string $zip = '',
		public readonly string $country = '',
		public readonly ?float $lat = null,
		public readonly ?float $lng = null,
		public readonly string $category = '',
		public readonly ?float $rating = null,
		public readonly int $review_count = 0,
		public readonly string $maps_url = '',
	) {}
}
