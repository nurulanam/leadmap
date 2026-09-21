<?php
/**
 * CSV export, streamed so a large selection never exhausts memory.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Export;

use LeadMap\Install\Schema;

defined( 'ABSPATH' ) || exit;

final class Csv_Exporter {

	private const CHUNK = 500;

	public function register(): void {
		add_action( 'admin_post_leadmap_export', [ $this, 'handle' ] );
	}

	public function handle(): void {
		if ( ! current_user_can( 'leadmap_manage' ) ) {
			wp_die( esc_html__( 'You are not allowed to export leads.', 'leadmap' ), 403 );
		}

		check_admin_referer( 'leadmap_export' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$raw_ids = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
		$ids     = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=leadmap-' . gmdate( 'Y-m-d-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );

		if ( false === $out ) {
			wp_die( esc_html__( 'Could not open the output stream.', 'leadmap' ) );
		}

		// BOM so Excel reads UTF-8 accents correctly.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, $this->headers() );

		foreach ( $this->rows( $ids ) as $lead ) {
			fputcsv( $out, $this->row( $lead ) );
		}

		fclose( $out );
		exit;
	}

	/** @return string[] */
	private function headers(): array {
		return [
			'ID',
			'Business Name',
			'Phone',
			'Phone (E.164)',
			'Email',
			'Email Confidence',
			'Website',
			'Domain',
			'Address',
			'City',
			'State',
			'ZIP',
			'Country',
			'Category',
			'Rating',
			'Reviews',
			'Latitude',
			'Longitude',
			'Google Maps URL',
			'Staleness Score',
			'PageSpeed Mobile',
			'PageSpeed Desktop',
			'Has SSL',
			'Mobile Ready',
			'Platform',
			'Status',
			'Added (UTC)',
		];
	}

	/** @return array<int,string|int|float|null> */
	private function row( object $lead ): array {
		return [
			(int) $lead->id,
			(string) $lead->name,
			(string) $lead->phone,
			(string) $lead->phone_e164,
			(string) $lead->email,
			(int) $lead->email_confidence,
			(string) $lead->website,
			(string) $lead->domain,
			(string) $lead->address,
			(string) $lead->city,
			(string) $lead->state,
			(string) $lead->zip,
			(string) $lead->country,
			(string) $lead->category,
			null === $lead->rating ? '' : (float) $lead->rating,
			(int) $lead->review_count,
			null === $lead->lat ? '' : (float) $lead->lat,
			null === $lead->lng ? '' : (float) $lead->lng,
			(string) $lead->maps_url,
			null === $lead->staleness_score ? '' : (int) $lead->staleness_score,
			$this->enrichment_value( $lead, [ 'speed_mobile', 'score' ] ),
			$this->enrichment_value( $lead, [ 'speed_desktop', 'score' ] ),
			$this->enrichment_bool( $lead, [ 'seo', 'is_https' ] ),
			$this->enrichment_bool( $lead, [ 'seo', 'has_viewport' ] ),
			$this->enrichment_value( $lead, [ 'tech', 'cms' ] ),
			(string) $lead->status,
			(string) $lead->created_at,
		];
	}

	/** @param string[] $path */
	private function enrichment_value( object $lead, array $path ): string {
		$data = json_decode( (string) $lead->enrichment_json, true );

		foreach ( $path as $key ) {
			if ( ! is_array( $data ) || ! isset( $data[ $key ] ) ) {
				return '';
			}

			$data = $data[ $key ];
		}

		return is_scalar( $data ) ? (string) $data : '';
	}

	/** @param string[] $path */
	private function enrichment_bool( object $lead, array $path ): string {
		$value = $this->enrichment_value( $lead, $path );

		if ( '' === $value ) {
			return '';
		}

		return $value && '0' !== $value ? 'yes' : 'no';
	}

	/**
	 * Yield leads in chunks rather than loading everything at once.
	 *
	 * @param int[] $ids Empty means every lead.
	 *
	 * @return \Generator<object>
	 */
	private function rows( array $ids ): \Generator {
		global $wpdb;

		$table = Schema::table( 'leads' );

		if ( $ids ) {
			foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = (array) $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders}) ORDER BY id ASC", ...$chunk )
				);

				foreach ( $rows as $row ) {
					yield $row;
				}
			}

			return;
		}

		$offset = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", self::CHUNK, $offset )
			);

			foreach ( $rows as $row ) {
				yield $row;
			}

			$offset += self::CHUNK;
		} while ( count( $rows ) === self::CHUNK );
	}
}
