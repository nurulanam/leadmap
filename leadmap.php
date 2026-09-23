<?php
/**
 * Plugin Name:       LeadMap — Lead Collector & Outreach
 * Plugin URI:        https://example.com/leadmap
 * Description:       Collect local business leads from Google Maps by industry and ZIP, then triage, audit and reach out — all from WP Admin.
 * Version:           0.6.3
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            LeadMap
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       leadmap
 * Domain Path:       /languages
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap;

defined( 'ABSPATH' ) || exit;

define( 'LEADMAP_VERSION', '0.6.3' );
define( 'LEADMAP_FILE', __FILE__ );
define( 'LEADMAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEADMAP_URL', plugin_dir_url( __FILE__ ) );
define( 'LEADMAP_BASENAME', plugin_basename( __FILE__ ) );

require_once LEADMAP_DIR . 'src/Autoloader.php';
Autoloader::register();

register_activation_hook( __FILE__, [ Install\Installer::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Install\Installer::class, 'deactivate' ] );

add_action( 'plugins_loaded', static function (): void {
	Plugin::instance()->boot();
}, 5 );
