<?php
/**
 * Activation, deactivation and capability setup.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Install;

defined( 'ABSPATH' ) || exit;

final class Installer {

	/** Capabilities granted to administrators on activation. */
	public const CAPS = [
		'leadmap_manage',
		'leadmap_search',
		'leadmap_audit',
		'leadmap_send',
		'leadmap_settings',
	];

	public static function activate(): void {
		Schema::install();
		self::add_caps();

		if ( ! get_option( 'leadmap_activated_at' ) ) {
			update_option( 'leadmap_activated_at', gmdate( 'Y-m-d H:i:s' ), false );
			set_transient( 'leadmap_show_welcome', 1, DAY_IN_SECONDS );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'leadmap/search/run' );
	}

	public static function add_caps(): void {
		$role = get_role( 'administrator' );

		if ( ! $role ) {
			return;
		}

		foreach ( self::CAPS as $cap ) {
			$role->add_cap( $cap );
		}
	}

	/** Runs on every load; applies schema changes after a plugin update. */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( 'leadmap_db_version', 0 );

		if ( $installed === Schema::DB_VERSION ) {
			return;
		}

		Schema::install();
		self::add_caps();
	}
}
