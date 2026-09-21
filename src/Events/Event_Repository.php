<?php
/**
 * Append-only activity trail.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Events;

use LeadMap\Install\Schema;

defined( 'ABSPATH' ) || exit;

final class Event_Repository {

	/** @param array<string,mixed> $payload */
	public static function log( string $type, int $lead_id = 0, array $payload = [], int $outreach_id = 0 ): void {
		global $wpdb;

		$wpdb->insert(
			Schema::table( 'events' ),
			[
				'lead_id'      => $lead_id,
				'outreach_id'  => $outreach_id,
				'type'         => $type,
				'actor_id'     => get_current_user_id(),
				'payload_json' => $payload ? wp_json_encode( $payload ) : null,
				'created_at'   => current_time( 'mysql', true ),
			],
			[ '%d', '%d', '%s', '%d', '%s', '%s' ]
		);
	}

	/** @return array<int,object> */
	public static function for_lead( int $lead_id, int $limit = 50 ): array {
		global $wpdb;

		$table = Schema::table( 'events' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not user input.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE lead_id = %d ORDER BY id DESC LIMIT %d",
				$lead_id,
				$limit
			)
		);
	}
}
