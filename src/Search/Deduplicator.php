<?php
/**
 * Decides whether an incoming place is a business we already hold.
 *
 * Three keys, in order of confidence:
 *   1. Provider place id  — exact, enforced by a unique index as a backstop.
 *   2. Normalized domain  — same website means same business. Shared platforms excluded.
 *   3. E.164 phone        — same line means same business. Empty numbers never match.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Search;

use LeadMap\Install\Schema;
use LeadMap\Support\Normalize;

defined( 'ABSPATH' ) || exit;

final class Deduplicator {

	/** @var array<string,int> Place ids seen during this run. */
	private array $seen_external = [];

	/** @var array<string,int> */
	private array $seen_domain = [];

	/** @var array<string,int> */
	private array $seen_phone = [];

	/**
	 * @return int Existing lead id, or 0 when this is a new business.
	 */
	public function find_duplicate( string $external_id, string $domain, string $phone_e164 ): int {
		global $wpdb;

		$table = Schema::table( 'leads' );

		if ( '' !== $external_id ) {
			if ( isset( $this->seen_external[ $external_id ] ) ) {
				return $this->seen_external[ $external_id ];
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE external_id = %s LIMIT 1", $external_id )
			);

			if ( $id ) {
				return $id;
			}
		}

		if ( '' !== $domain && ! Normalize::is_generic_host( $domain ) ) {
			if ( isset( $this->seen_domain[ $domain ] ) ) {
				return $this->seen_domain[ $domain ];
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE domain = %s LIMIT 1", $domain )
			);

			if ( $id ) {
				return $id;
			}
		}

		if ( '' !== $phone_e164 ) {
			if ( isset( $this->seen_phone[ $phone_e164 ] ) ) {
				return $this->seen_phone[ $phone_e164 ];
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE phone_e164 = %s LIMIT 1", $phone_e164 )
			);

			if ( $id ) {
				return $id;
			}
		}

		return 0;
	}

	/** Record a lead we just created so later pages in the same run match against it. */
	public function remember( int $lead_id, string $external_id, string $domain, string $phone_e164 ): void {
		if ( '' !== $external_id ) {
			$this->seen_external[ $external_id ] = $lead_id;
		}

		if ( '' !== $domain && ! Normalize::is_generic_host( $domain ) ) {
			$this->seen_domain[ $domain ] = $lead_id;
		}

		if ( '' !== $phone_e164 ) {
			$this->seen_phone[ $phone_e164 ] = $lead_id;
		}
	}
}
