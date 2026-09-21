<?php
/**
 * Holds the available lead sources. Third parties add their own on leadmap_register_providers.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Providers;

defined( 'ABSPATH' ) || exit;

final class Provider_Registry {

	/** @var array<string,Search_Provider> */
	private array $providers = [];

	public function __construct() {
		$this->add( new Google_Places_Provider() );

		/**
		 * Register additional lead sources.
		 *
		 * @param Provider_Registry $registry
		 */
		do_action( 'leadmap_register_providers', $this );
	}

	public function add( Search_Provider $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	public function get( string $id ): ?Search_Provider {
		return $this->providers[ $id ] ?? null;
	}

	/** @return array<string,Search_Provider> */
	public function all(): array {
		return $this->providers;
	}

	/** @return array<string,string> id => label, for a select field. */
	public function choices(): array {
		$out = [];

		foreach ( $this->providers as $id => $provider ) {
			$out[ $id ] = $provider->get_label();
		}

		return $out;
	}
}
