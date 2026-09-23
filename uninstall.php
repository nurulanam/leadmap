<?php
/**
 * Removes every trace of the plugin. Runs only on explicit delete, never on deactivate.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Leads are expensive to collect, so data is kept unless the operator opts in.
if ( ! get_option( 'leadmap_delete_data_on_uninstall' ) ) {
	return;
}

$tables = [ 'events', 'audits', 'lead_emails', 'leads', 'searches' ];

foreach ( $tables as $table ) {
	$name = $wpdb->prefix . 'leadmap_' . $table;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
}

delete_option( 'leadmap_settings' );
delete_option( 'leadmap_db_version' );
delete_option( 'leadmap_activated_at' );
delete_option( 'leadmap_delete_data_on_uninstall' );
delete_transient( 'leadmap_show_welcome' );

$role = get_role( 'administrator' );

if ( $role ) {
	foreach ( [ 'leadmap_manage', 'leadmap_search', 'leadmap_audit', 'leadmap_send', 'leadmap_settings' ] as $cap ) {
		$role->remove_cap( $cap );
	}
}
