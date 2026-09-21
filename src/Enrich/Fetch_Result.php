<?php
/**
 * One fetched page.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

defined( 'ABSPATH' ) || exit;

final class Fetch_Result {

	/** @param array<string,string> $headers */
	public function __construct(
		public readonly string $url,
		public readonly string $final_url,
		public readonly int $status,
		public readonly string $body = '',
		public readonly array $headers = [],
		public readonly float $seconds = 0.0,
		public readonly int $redirects = 0,
		public readonly bool $truncated = false,
	) {}

	public function ok(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	public function is_html(): bool {
		$type = strtolower( $this->headers['content-type'] ?? '' );

		return str_contains( $type, 'text/html' ) || str_contains( $type, 'application/xhtml' );
	}

	public function header( string $name ): string {
		return $this->headers[ strtolower( $name ) ] ?? '';
	}
}
