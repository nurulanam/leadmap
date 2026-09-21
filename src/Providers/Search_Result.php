<?php
/**
 * One page of provider results.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Providers;

defined( 'ABSPATH' ) || exit;

final class Search_Result {

	/** @param Raw_Place[] $places */
	public function __construct(
		public readonly array $places = [],
		public readonly ?string $next_page_token = null,
		public readonly float $cost = 0.0,
	) {}

	public function count(): int {
		return count( $this->places );
	}

	public function has_more(): bool {
		return ! empty( $this->next_page_token );
	}
}
