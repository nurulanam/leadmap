<?php
/**
 * Reads and writes against the searches table.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Search;

use LeadMap\Install\Schema;

defined( 'ABSPATH' ) || exit;

final class Search_Repository {

	/** @param array<string,mixed> $data */
	public static function insert( array $data ): int {
		global $wpdb;

		$data = wp_parse_args(
			$data,
			[
				'status'     => 'queued',
				'created_by' => get_current_user_id(),
				'created_at' => current_time( 'mysql', true ),
			]
		);

		$wpdb->insert( Schema::table( 'searches' ), $data );

		return (int) $wpdb->insert_id;
	}

	public static function find( int $id ): ?object {
		global $wpdb;

		$table = Schema::table( 'searches' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row ?: null;
	}

	/** @param array<string,mixed> $data */
	public static function update( int $id, array $data ): void {
		global $wpdb;

		$wpdb->update( Schema::table( 'searches' ), $data, [ 'id' => $id ] );
	}

	public static function mark_failed( int $id, string $error ): void {
		self::update(
			$id,
			[
				'status'       => 'failed',
				'error'        => mb_substr( $error, 0, 1000 ),
				'completed_at' => current_time( 'mysql', true ),
			]
		);
	}

	public static function mark_complete( int $id ): void {
		self::update(
			$id,
			[
				'status'       => 'complete',
				'completed_at' => current_time( 'mysql', true ),
			]
		);
	}

	/** @return array{items:array<int,object>,total:int} */
	public static function query( int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$table    = Schema::table( 'searches' );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$items = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset )
		);
		// phpcs:enable

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	public static function delete( int $id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( 'searches' ), [ 'id' => $id ], [ '%d' ] );
	}

	/** Total API spend in the current calendar month, for the spend cap. */
	public static function spend_this_month(): float {
		global $wpdb;

		$table = Schema::table( 'searches' );
		$since = gmdate( 'Y-m-01 00:00:00' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE( SUM( api_cost ), 0 ) FROM {$table} WHERE created_at >= %s", $since )
		);
	}
}
