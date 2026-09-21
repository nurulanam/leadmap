<?php
/**
 * Service container and boot sequence.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap;

use LeadMap\Admin\Menu;
use LeadMap\Admin\Notices;
use LeadMap\Export\Csv_Exporter;
use LeadMap\Install\Installer;
use LeadMap\Jobs\Job_Runner;
use LeadMap\Providers\Provider_Registry;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	private ?Provider_Registry $providers = null;

	private bool $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		load_plugin_textdomain( 'leadmap', false, dirname( LEADMAP_BASENAME ) . '/languages' );

		add_action( 'init', [ Installer::class, 'maybe_upgrade' ] );

		( new Job_Runner() )->register();
		( new Csv_Exporter() )->register();

		if ( is_admin() ) {
			( new Menu() )->register();
			( new Notices() )->register();
		}
	}

	public function providers(): Provider_Registry {
		if ( null === $this->providers ) {
			$this->providers = new Provider_Registry();
		}

		return $this->providers;
	}
}
