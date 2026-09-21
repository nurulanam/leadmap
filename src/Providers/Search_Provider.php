<?php
/**
 * The contract every lead source implements. Swapping providers is a config change,
 * never a rewrite.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Providers;

use LeadMap\Search\Search_Query;
use WP_Error;

defined( 'ABSPATH' ) || exit;

interface Search_Provider {

	/** Stable machine id stored on searches and leads, e.g. 'google_places'. */
	public function get_id(): string;

	/** Human label for the admin UI. */
	public function get_label(): string;

	/** True when the provider has everything it needs (API key, etc.). */
	public function is_configured(): bool;

	/** Fetch one page of results. */
	public function search( Search_Query $query ): Search_Result|WP_Error;

	/** Estimated USD cost of fetching the given number of results. */
	public function cost_estimate( int $results ): float;

	/** Maximum results the provider returns per page. */
	public function page_size(): int;
}
