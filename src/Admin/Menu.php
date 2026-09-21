<?php
/**
 * Admin menu and screen routing.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Admin;

use LeadMap\Admin\Screens\Leads_Screen;
use LeadMap\Admin\Screens\New_Search_Screen;
use LeadMap\Admin\Screens\Searches_Screen;
use LeadMap\Admin\Screens\Settings_Screen;

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

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'leadmap' ) ) {
			return;
		}

		wp_enqueue_style(
			'leadmap-admin',
			LEADMAP_URL . 'src/Admin/assets/admin.css',
			[],
			LEADMAP_VERSION
		);
	}
}
