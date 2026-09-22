<?php
/**
 * All reads and writes against the leads table.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Leads;

use LeadMap\Install\Schema;

defined( 'ABSPATH' ) || exit;

final class Lead_Repository {

	/** Statuses a lead can hold, with their admin labels. */
	public static function statuses(): array {
		return [
			'new'             => __( 'New', 'leadmap' ),
			'enriching'       => __( 'Enriching', 'leadmap' ),
			'enriched'        => __( 'Enriched', 'leadmap' ),
			'triaged'         => __( 'Triaged', 'leadmap' ),
			'skipped'         => __( 'Skipped', 'leadmap' ),
			'no_website'      => __( 'No website', 'leadmap' ),
			'triage_deferred' => __( 'Deferred', 'leadmap' ),
			'audited'         => __( 'Audited', 'leadmap' ),
			'queued'          => __( 'Queued', 'leadmap' ),
			'contacted'       => __( 'Contacted', 'leadmap' ),
			'replied'         => __( 'Replied', 'leadmap' ),
			'meeting_booked'  => __( 'Meeting booked', 'leadmap' ),
			'won'             => __( 'Won', 'leadmap' ),
			'lost'            => __( 'Lost', 'leadmap' ),
			'bad_fit'         => __( 'Bad fit', 'leadmap' ),
			'unsubscribed'    => __( 'Unsubscribed', 'leadmap' ),
			'bounced'         => __( 'Bounced', 'leadmap' ),
		];
	}

	/**
	 * Insert a lead, ignoring it if the place id is already known.
	 *
	 * @param array<string,mixed> $data
	 *
	 * @return int Lead id, or 0 when the row already existed.
	 */
	public static function insert( array $data ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$row = wp_parse_args(
			$data,
			[
				'search_id'           => 0,
				'provider'            => 'google_places',
				'external_id'         => '',
				'name'                => '',
				'phone'               => '',
				'phone_e164'          => '',
				'website'             => '',
				'domain'              => '',
				'address'             => '',
				'city'                => '',
				'state'               => '',
				'zip'                 => '',
				'country'             => '',
				'lat'                 => null,
				'lng'                 => null,
				'category'            => '',
				'rating'              => null,
				'review_count'        => 0,
				'maps_url'            => '',
				'status'              => 'new',
				'places_refreshed_at' => $now,
				'created_at'          => $now,
				'updated_at'          => $now,
			]
		);

		if ( '' === $row['external_id'] ) {
			return 0;
		}

		// Nullable numerics stay NULL rather than becoming a real 0,0 coordinate.
		foreach ( [ 'lat', 'lng', 'rating' ] as $nullable ) {
			if ( '' === $row[ $nullable ] || null === $row[ $nullable ] ) {
				$row[ $nullable ] = null;
			}
		}

		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( Schema::table( 'leads' ), $row );
		$wpdb->suppress_errors( $suppress );

		// A duplicate external_id trips the unique key and returns false — that is the dedupe
		// backstop for two searches running concurrently, not an error worth surfacing.
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function find( int $id ): ?object {
		global $wpdb;

		$table = Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row ?: null;
	}

	public static function find_by_external_id( string $external_id ): ?object {
		global $wpdb;

		$table = Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE external_id = %s", $external_id ) );

		return $row ?: null;
	}

	/** @param array<string,mixed> $data */
	public static function update( int $id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		return false !== $wpdb->update( Schema::table( 'leads' ), $data, [ 'id' => $id ] );
	}

	/** @param int[] $ids */
	public static function delete( array $ids ): int {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( ! $ids ) {
			return 0;
		}

		foreach ( $ids as $id ) {
			\LeadMap\Triage\Screenshot_Store::delete_for_lead( $id );
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$leads        = Schema::table( 'leads' );
		$emails       = Schema::table( 'lead_emails' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$emails} WHERE lead_id IN ({$placeholders})", ...$ids ) );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$leads} WHERE id IN ({$placeholders})", ...$ids ) );
		// phpcs:enable

		return (int) $deleted;
	}

	/**
	 * Paginated, filtered query for the list table.
	 *
	 * @param array<string,mixed> $args
	 *
	 * @return array{items:array<int,object>,total:int}
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			[
				'search'    => '',
				'status'    => '',
				'zip'       => '',
				'category'  => '',
				'search_id' => 0,
				'has_email' => '',
				'triage'    => '',
				'orderby'   => 'created_at',
				'order'     => 'DESC',
				'per_page'  => 25,
				'page'      => 1,
			]
		);

		$table  = Schema::table( 'leads' );
		$where  = [ '1=1' ];
		$params = [];

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '( name LIKE %s OR domain LIKE %s OR phone LIKE %s OR address LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}

		if ( '' !== $args['zip'] ) {
			$where[]  = 'zip = %s';
			$params[] = (string) $args['zip'];
		}

		if ( '' !== $args['category'] ) {
			$where[]  = 'category = %s';
			$params[] = (string) $args['category'];
		}

		if ( $args['search_id'] ) {
			$where[]  = 'search_id = %d';
			$params[] = (int) $args['search_id'];
		}

		if ( '' !== $args['triage'] ) {
			if ( 'auto' === $args['triage'] ) {
				$where[] = "triage_verdict <> '' AND triage_by IS NULL";
			} elseif ( 'pending' === $args['triage'] ) {
				$where[] = "triage_verdict = ''";
			} else {
				$where[]  = 'triage_verdict = %s';
				$params[] = (string) $args['triage'];
			}
		}

		if ( 'yes' === $args['has_email'] ) {
			$where[] = "email <> ''";
		} elseif ( 'no' === $args['has_email'] ) {
			$where[] = "email = ''";
		}

		$allowed_orderby = [ 'id', 'name', 'created_at', 'rating', 'review_count', 'status', 'zip', 'staleness_score' ];
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers are allow-listed above.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			: $wpdb->get_var( $count_sql ) );

		$list_sql        = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$list_params     = array_merge( $params, [ $per_page, $offset ] );
		$items           = (array) $wpdb->get_results( $wpdb->prepare( $list_sql, ...$list_params ) );
		// phpcs:enable

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/** @return array<string,int> status => count */
	public static function status_counts(): array {
		global $wpdb;

		$table = Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" );

		$out = [];

		foreach ( $rows as $row ) {
			$out[ (string) $row->status ] = (int) $row->total;
		}

		return $out;
	}

	/** Distinct values for a filter dropdown. @return string[] */
	public static function distinct( string $column ): array {
		global $wpdb;

		if ( ! in_array( $column, [ 'zip', 'category', 'city', 'state' ], true ) ) {
			return [];
		}

		$table = Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column is allow-listed.
		$values = (array) $wpdb->get_col( "SELECT DISTINCT {$column} FROM {$table} WHERE {$column} <> '' ORDER BY {$column} ASC LIMIT 300" );

		return array_map( 'strval', $values );
	}

	public static function count_all(): int {
		global $wpdb;

		$table = Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
