<?php
/**
 * Every email found for a lead, not just the chosen one — so the operator can pick a
 * different address when the top-ranked one bounces or feels wrong.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Leads;

use LeadMap\Enrich\Email_Candidate;
use LeadMap\Install\Schema;

defined( 'ABSPATH' ) || exit;

final class Lead_Email_Repository {

	/** @param Email_Candidate[] $candidates */
	public static function save_all( int $lead_id, array $candidates ): int {
		global $wpdb;

		$saved = 0;
		$now   = current_time( 'mysql', true );

		foreach ( $candidates as $candidate ) {
			$suppress = $wpdb->suppress_errors( true );

			$ok = $wpdb->insert(
				Schema::table( 'lead_emails' ),
				[
					'lead_id'         => $lead_id,
					'email'           => $candidate->email,
					'source'          => $candidate->source,
					'confidence'      => $candidate->confidence,
					'is_role_account' => $candidate->is_role_account ? 1 : 0,
					'created_at'      => $now,
				]
			);

			$wpdb->suppress_errors( $suppress );

			if ( $ok ) {
				++$saved;

				continue;
			}

			// Already known for this lead — keep the higher confidence of the two.
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE ' . Schema::table( 'lead_emails' ) . '
					 SET source = %s, confidence = %d
					 WHERE lead_id = %d AND email = %s AND confidence < %d',
					$candidate->source,
					$candidate->confidence,
					$lead_id,
					$candidate->email,
					$candidate->confidence
				)
			);
		}

		return $saved;
	}

	/** @return array<int,object> */
	public static function for_lead( int $lead_id ): array {
		global $wpdb;

		$table = Schema::table( 'lead_emails' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE lead_id = %d ORDER BY confidence DESC, email ASC",
				$lead_id
			)
		);
	}

	public static function delete_for_lead( int $lead_id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( 'lead_emails' ), [ 'lead_id' => $lead_id ], [ '%d' ] );
	}
}
