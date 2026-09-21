<?php
/**
 * Table definitions. Applied through dbDelta(), which is additive — it never drops columns.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Install;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/** Bumped whenever a table definition below changes. */
	public const DB_VERSION = 1;

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'leadmap_' . $name;
	}

	/** @return string[] Table name => CREATE TABLE statement. */
	public static function tables(): array {
		global $wpdb;
		$collate = $wpdb->get_charset_collate();

		$tables = [];

		$tables['searches'] = "CREATE TABLE " . self::table( 'searches' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			label VARCHAR(191) NOT NULL DEFAULT '',
			industry VARCHAR(191) NOT NULL DEFAULT '',
			location VARCHAR(191) NOT NULL DEFAULT '',
			zip VARCHAR(20) NOT NULL DEFAULT '',
			radius_m INT UNSIGNED NOT NULL DEFAULT 5000,
			provider VARCHAR(50) NOT NULL DEFAULT 'google_places',
			max_results INT UNSIGNED NOT NULL DEFAULT 60,
			params_json LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			results_found INT UNSIGNED NOT NULL DEFAULT 0,
			results_new INT UNSIGNED NOT NULL DEFAULT 0,
			pages_fetched INT UNSIGNED NOT NULL DEFAULT 0,
			next_page_token VARCHAR(2048) NULL,
			api_cost DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
			error TEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			completed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) $collate;";

		$tables['leads'] = "CREATE TABLE " . self::table( 'leads' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			search_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			provider VARCHAR(50) NOT NULL DEFAULT 'google_places',
			external_id VARCHAR(191) NOT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			phone VARCHAR(50) NOT NULL DEFAULT '',
			phone_e164 VARCHAR(30) NOT NULL DEFAULT '',
			website VARCHAR(500) NOT NULL DEFAULT '',
			domain VARCHAR(191) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			email_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
			email_source VARCHAR(50) NOT NULL DEFAULT '',
			address VARCHAR(500) NOT NULL DEFAULT '',
			city VARCHAR(191) NOT NULL DEFAULT '',
			state VARCHAR(100) NOT NULL DEFAULT '',
			zip VARCHAR(20) NOT NULL DEFAULT '',
			country VARCHAR(10) NOT NULL DEFAULT '',
			lat DECIMAL(10,7) NULL,
			lng DECIMAL(10,7) NULL,
			category VARCHAR(191) NOT NULL DEFAULT '',
			rating DECIMAL(2,1) NULL,
			review_count INT UNSIGNED NOT NULL DEFAULT 0,
			maps_url VARCHAR(500) NOT NULL DEFAULT '',
			status VARCHAR(30) NOT NULL DEFAULT 'new',
			enrichment_json LONGTEXT NULL,
			staleness_score TINYINT UNSIGNED NULL,
			staleness_json LONGTEXT NULL,
			triage_verdict VARCHAR(30) NOT NULL DEFAULT '',
			triage_flags VARCHAR(191) NOT NULL DEFAULT '',
			triage_note TEXT NULL,
			triage_by BIGINT UNSIGNED NULL,
			triage_at DATETIME NULL,
			screenshot_desktop_id BIGINT UNSIGNED NULL,
			screenshot_mobile_id BIGINT UNSIGNED NULL,
			owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			places_refreshed_at DATETIME NULL,
			last_contacted_at DATETIME NULL,
			next_action_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY external_id (external_id),
			KEY domain (domain),
			KEY phone_e164 (phone_e164),
			KEY status_next_action (status, next_action_at),
			KEY status_staleness (status, staleness_score),
			KEY zip_category (zip, category),
			KEY search_id (search_id)
		) $collate;";

		$tables['lead_emails'] = "CREATE TABLE " . self::table( 'lead_emails' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(191) NOT NULL,
			source VARCHAR(50) NOT NULL DEFAULT '',
			confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
			is_role_account TINYINT(1) NOT NULL DEFAULT 0,
			verified_status VARCHAR(30) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY lead_email (lead_id, email),
			KEY email (email)
		) $collate;";

		$tables['events'] = "CREATE TABLE " . self::table( 'events' ) . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			outreach_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			type VARCHAR(50) NOT NULL,
			actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			payload_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY lead_id (lead_id),
			KEY type_created (type, created_at)
		) $collate;";

		return $tables;
	}

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::tables() as $sql ) {
			dbDelta( $sql );
		}

		update_option( 'leadmap_db_version', self::DB_VERSION, false );
	}
}
