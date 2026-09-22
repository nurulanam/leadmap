<?php
/**
 * Where each PageSpeed measurement has got to.
 *
 * Without this a missing score is ambiguous: queued, running, rate-limited, or permanently
 * failed all look identical — a blank. Each strategy carries its own state so the UI can
 * say which, and so a half-finished lead (mobile but no desktop) is visibly half-finished.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

use LeadMap\Leads\Lead_Repository;

defined( 'ABSPATH' ) || exit;

final class Speed_Status {

	public const QUEUED  = 'queued';
	public const RUNNING = 'running';
	public const DONE    = 'done';
	public const FAILED  = 'failed';
	public const WAITING = 'waiting'; // Rate limited, will retry.

	public const STRATEGIES = [ 'mobile', 'desktop' ];

	/** Record the state of one strategy for one lead. */
	public static function set( int $lead_id, string $strategy, string $state, string $detail = '' ): void {
		$lead = Lead_Repository::find( $lead_id );

		if ( ! $lead ) {
			return;
		}

		$enrichment = json_decode( (string) $lead->enrichment_json, true );
		$enrichment = is_array( $enrichment ) ? $enrichment : [];

		$enrichment['speed_status'] ??= [];

		$enrichment['speed_status'][ $strategy ] = [
			'state'  => $state,
			'detail' => mb_substr( $detail, 0, 300 ),
			'at'     => current_time( 'mysql', true ),
		];

		// Mirror the pending strategies into a column. Finding outstanding work by scanning
		// enrichment JSON would mean a LIKE over a LONGTEXT on every sweep.
		$pending = [];

		foreach ( self::STRATEGIES as $candidate ) {
			$candidate_state = (string) ( $enrichment['speed_status'][ $candidate ]['state'] ?? '' );

			if ( self::in_progress( $candidate_state ) ) {
				$pending[] = $candidate;
			}
		}

		Lead_Repository::update(
			$lead_id,
			[
				'enrichment_json' => wp_json_encode( $enrichment ),
				'speed_pending'   => implode( ',', $pending ),
			]
		);
	}

	/**
	 * The next measurement waiting to run.
	 *
	 * @return array{lead_id:int,strategy:string,website:string}|null
	 */
	public static function next_pending(): ?array {
		global $wpdb;

		$table = \LeadMap\Install\Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $wpdb->get_row(
			"SELECT id, website, speed_pending FROM {$table}
			 WHERE speed_pending <> '' AND website <> ''
			 ORDER BY staleness_score DESC, id ASC
			 LIMIT 1"
		);

		if ( ! $row ) {
			return null;
		}

		$pending = array_filter( explode( ',', (string) $row->speed_pending ) );

		if ( ! $pending ) {
			return null;
		}

		return [
			'lead_id'  => (int) $row->id,
			'strategy' => (string) reset( $pending ),
			'website'  => (string) $row->website,
		];
	}

	/** How many measurements are still outstanding. */
	public static function pending_count(): int {
		global $wpdb;

		$table = \LeadMap\Install\Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_col( "SELECT speed_pending FROM {$table} WHERE speed_pending <> ''" );

		$total = 0;

		foreach ( $rows as $row ) {
			$total += count( array_filter( explode( ',', (string) $row ) ) );
		}

		return $total;
	}

	/**
	 * @param array<string,mixed> $enrichment
	 *
	 * @return array{state:string,detail:string,at:string}
	 */
	public static function get( array $enrichment, string $strategy ): array {
		$stored = $enrichment['speed_status'][ $strategy ] ?? null;

		if ( is_array( $stored ) ) {
			return [
				'state'  => (string) ( $stored['state'] ?? '' ),
				'detail' => (string) ( $stored['detail'] ?? '' ),
				'at'     => (string) ( $stored['at'] ?? '' ),
			];
		}

		// A score with no recorded state predates this tracking, so infer it.
		if ( isset( $enrichment[ 'speed_' . $strategy ]['score'] ) ) {
			return [ 'state' => self::DONE, 'detail' => '', 'at' => '' ];
		}

		if ( isset( $enrichment[ 'speed_' . $strategy . '_error' ] ) ) {
			return [
				'state'  => self::FAILED,
				'detail' => (string) ( $enrichment[ 'speed_' . $strategy . '_error' ]['reason'] ?? '' ),
				'at'     => '',
			];
		}

		return [ 'state' => '', 'detail' => '', 'at' => '' ];
	}

	/** Human label for a state. */
	public static function label( string $state ): string {
		return match ( $state ) {
			self::QUEUED  => __( 'Queued', 'leadmap' ),
			self::RUNNING => __( 'Measuring…', 'leadmap' ),
			self::WAITING => __( 'Waiting on Google’s rate limit', 'leadmap' ),
			self::FAILED  => __( 'Failed', 'leadmap' ),
			self::DONE    => __( 'Done', 'leadmap' ),
			default       => __( 'Not requested', 'leadmap' ),
		};
	}

	/** True when this strategy is somewhere in the queue rather than finished. */
	public static function in_progress( string $state ): bool {
		return in_array( $state, [ self::QUEUED, self::RUNNING, self::WAITING ], true );
	}

	/**
	 * A single summary for the whole lead, for list columns.
	 *
	 * @param array<string,mixed> $enrichment
	 */
	public static function summary( array $enrichment ): string {
		$states = [];

		foreach ( self::STRATEGIES as $strategy ) {
			$states[ $strategy ] = self::get( $enrichment, $strategy )['state'];
		}

		if ( self::DONE === $states['mobile'] && self::DONE === $states['desktop'] ) {
			return self::DONE;
		}

		foreach ( $states as $state ) {
			if ( self::in_progress( $state ) ) {
				return $state;
			}
		}

		// One success and one failure is partial, not failed — reporting it as failed would
		// hide a score we actually have.
		if ( in_array( self::DONE, $states, true ) ) {
			return 'partial';
		}

		if ( in_array( self::FAILED, $states, true ) ) {
			return self::FAILED;
		}

		return '';
	}
}
