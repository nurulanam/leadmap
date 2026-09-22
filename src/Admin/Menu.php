<?php
/**
 * Admin menu and screen routing.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Admin;

use LeadMap\Admin\Screens\Lead_Detail_Screen;
use LeadMap\Admin\Screens\Leads_Screen;
use LeadMap\Admin\Screens\New_Search_Screen;
use LeadMap\Admin\Screens\Searches_Screen;
use LeadMap\Admin\Screens\Settings_Screen;
use LeadMap\Rest\Rest_Controller;

defined( 'ABSPATH' ) || exit;

final class Menu {

	public const SLUG = 'leadmap';

	/** @var array<string,object> */
	private array $screens = [];

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function add_pages(): void {
		$this->screens = [
			'leadmap'            => new Leads_Screen(),
			'leadmap-new-search' => new New_Search_Screen(),
			'leadmap-searches'   => new Searches_Screen(),
			'leadmap-settings'   => new Settings_Screen(),
			'leadmap-lead'       => new Lead_Detail_Screen(),
		];

		add_menu_page(
			__( 'LeadMap', 'leadmap' ),
			__( 'LeadMap', 'leadmap' ),
			'leadmap_manage',
			self::SLUG,
			[ $this, 'render' ],
			'dashicons-location-alt',
			26
		);

		add_submenu_page( self::SLUG, __( 'Leads', 'leadmap' ), __( 'Leads', 'leadmap' ), 'leadmap_manage', 'leadmap', [ $this, 'render' ] );
		add_submenu_page( self::SLUG, __( 'New Search', 'leadmap' ), __( 'New Search', 'leadmap' ), 'leadmap_search', 'leadmap-new-search', [ $this, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Searches', 'leadmap' ), __( 'Searches', 'leadmap' ), 'leadmap_search', 'leadmap-searches', [ $this, 'render' ] );
		add_submenu_page( self::SLUG, __( 'Settings', 'leadmap' ), __( 'Settings', 'leadmap' ), 'leadmap_settings', 'leadmap-settings', [ $this, 'render' ] );

		// Reachable by URL from the leads list, but not shown as its own menu item.
		add_submenu_page( '', __( 'Lead', 'leadmap' ), __( 'Lead', 'leadmap' ), 'leadmap_manage', 'leadmap-lead', [ $this, 'render' ] );

		// The leads list table must build its columns before the screen renders.
		$leads_hook = get_plugin_page_hookname( 'leadmap', self::SLUG );

		if ( $leads_hook ) {
			add_action( "load-{$leads_hook}", [ $this->screens['leadmap'], 'load' ] );
		}
	}

	public function render(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'leadmap'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$screen = $this->screens[ $page ] ?? null;

		if ( ! $screen ) {
			return;
		}

		$screen->render();
	}

	/**
	 * Cache-busting version for a bundled asset.
	 *
	 * The plugin version is not enough on its own: shipping two different builds under one
	 * version number leaves browsers holding a stale stylesheet, which is exactly how the
	 * modal shipped without its CSS. File modification time cannot drift from the file.
	 */
	private static function asset_version( string $relative ): string {
		$path = LEADMAP_DIR . $relative;
		$time = is_readable( $path ) ? (int) filemtime( $path ) : 0;

		return $time > 0 ? LEADMAP_VERSION . '.' . $time : LEADMAP_VERSION;
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'leadmap' ) ) {
			return;
		}

		wp_enqueue_style(
			'leadmap-admin',
			LEADMAP_URL . 'src/Admin/assets/admin.css',
			[],
			self::asset_version( 'src/Admin/assets/admin.css' )
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'leadmap-new-search' === $page ) {
			$this->enqueue_map();
		}

		if ( 'leadmap-searches' === $page ) {
			$this->enqueue_live();
		}
	}

	/** Leaflet plus our own preview logic. Bundled, so the admin works offline. */
	private function enqueue_map(): void {
		wp_enqueue_style(
			'leadmap-leaflet',
			LEADMAP_URL . 'src/Admin/assets/vendor/leaflet/leaflet.css',
			[],
			'1.9.4'
		);

		wp_enqueue_script(
			'leadmap-leaflet',
			LEADMAP_URL . 'src/Admin/assets/vendor/leaflet/leaflet.js',
			[],
			'1.9.4',
			true
		);

		wp_enqueue_script(
			'leadmap-map',
			LEADMAP_URL . 'src/Admin/assets/map.js',
			[ 'leadmap-leaflet' ],
			self::asset_version( 'src/Admin/assets/map.js' ),
			true
		);

		wp_localize_script(
			'leadmap-map',
			'leadmapMap',
			[
				'geocodeUrl' => rest_url( Rest_Controller::NAMESPACE . '/geocode' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				// A neutral starting view until the operator types something.
				'lat'        => 39.8283,
				'lng'        => -98.5795,
				'i18n'       => [
					'prompt'   => __( 'Enter a city or ZIP to see the search area', 'leadmap' ),
					'locating' => __( 'Finding that location…', 'leadmap' ),
					'showing'  => __( 'Searching within %s km of here', 'leadmap' ),
					'failed'   => __( 'Could not find that location', 'leadmap' ),
				],
			]
		);
	}

	private function enqueue_live(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_id = isset( $_GET['watch'] ) ? absint( $_GET['watch'] ) : 0;

		if ( ! $search_id ) {
			return;
		}

		wp_enqueue_script(
			'leadmap-live',
			LEADMAP_URL . 'src/Admin/assets/search-log.js',
			[],
			self::asset_version( 'src/Admin/assets/search-log.js' ),
			true
		);

		wp_localize_script(
			'leadmap-live',
			'leadmapLive',
			[
				'progressUrl' => rest_url( Rest_Controller::NAMESPACE . '/searches/' . $search_id . '/progress' ),
				'stopUrl'     => rest_url( Rest_Controller::NAMESPACE . '/searches/' . $search_id . '/stop' ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'i18n'        => [
					'running'  => __( 'Searching…', 'leadmap' ),
					'complete' => __( 'Search complete', 'leadmap' ),
					'failed'   => __( 'Search failed', 'leadmap' ),
					'lost'     => __( 'Lost contact with the server. Reload to check progress.', 'leadmap' ),
					'stopped'  => __( 'Search stopped', 'leadmap' ),
					'stop'     => __( 'Stop search', 'leadmap' ),
					'stopping' => __( 'Stopping…', 'leadmap' ),
					'confirmStop' => __( 'Stop this search? Leads already collected are kept, and you can re-run it later.', 'leadmap' ),
					/* translators: 1: new leads, 2: total results, 3: cost. */
					'stats'    => __( '%1$s new leads · %2$s results seen · $%3$s', 'leadmap' ),
				],
			]
		);
	}
}
