<?php
/**
 * Binds job hooks to their handlers.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Jobs;

use LeadMap\Search\Search_Runner;

defined( 'ABSPATH' ) || exit;

final class Job_Runner {

	public const SEARCH_RUN = 'leadmap/search/run';

	public function register(): void {
		add_action( self::SEARCH_RUN, [ $this, 'run_search' ], 10, 1 );
	}

	public function run_search( int $search_id = 0 ): void {
		if ( $search_id <= 0 ) {
			return;
		}

		( new Search_Runner() )->run( (int) $search_id );
	}
}
