<?php
/**
 * Applying, undoing and automating triage verdicts.
 *
 * Triage is a hard gate: a lead that fails it leaves the pipeline and costs nothing further.
 * Everything downstream only ever sees qualified leads, which is the whole point — the deep
 * audit takes minutes per lead and is wasted on a site with no problem to sell against.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Triage;

use LeadMap\Events\Event_Repository;
use LeadMap\Install\Schema;
use LeadMap\Leads\Lead_Repository;
use LeadMap\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Triage_Service {

	/**
	 * Record a verdict.
	 *
	 * @param string[] $flags One or more of Verdicts::FLAGS, or a single terminal verdict.
	 */
	public function decide( int $lead_id, array $flags, string $note = '', bool $automatic = false ): array|WP_Error {
		// Validate the verdict before touching the database: a malformed request should cost
		// nothing, and it keeps the rules in one place rather than behind a lookup.
		$flags = array_values( array_unique( array_filter( array_map( 'sanitize_key', $flags ) ) ) );

		if ( ! $flags ) {
			return new WP_Error( 'leadmap_no_verdict', __( 'Pick at least one verdict.', 'leadmap' ) );
		}

		$terminal = array_values( array_filter( $flags, [ Verdicts::class, 'is_terminal' ] ) );
		$pitch    = array_values( array_filter( $flags, [ Verdicts::class, 'is_flag' ] ) );

		if ( $terminal && $pitch ) {
			return new WP_Error(
				'leadmap_mixed_verdict',
				__( 'Skip, No website and Unsure cannot be combined with the other verdicts.', 'leadmap' )
			);
		}

		if ( count( $terminal ) > 1 ) {
			return new WP_Error( 'leadmap_mixed_verdict', __( 'Pick only one of Skip, No website or Unsure.', 'leadmap' ) );
		}

		if ( ! $terminal && ! $pitch ) {
			return new WP_Error( 'leadmap_bad_verdict', __( 'That is not a verdict LeadMap recognises.', 'leadmap' ) );
		}

		$lead = Lead_Repository::find( $lead_id );

		if ( ! $lead ) {
			return new WP_Error( 'leadmap_lead_missing', __( 'That lead no longer exists.', 'leadmap' ) );
		}

		$verdict = $terminal ? $terminal[0] : $pitch[0];
		$status  = Verdicts::status_for( $verdict );

		Lead_Repository::update(
			$lead_id,
			[
				'status'         => $status,
				'triage_verdict' => $verdict,
				'triage_flags'   => implode( ',', $terminal ?: $pitch ),
				'triage_note'    => '' === $note ? null : mb_substr( $note, 0, 500 ),
				'triage_by'      => $automatic ? null : get_current_user_id(),
				'triage_at'      => current_time( 'mysql', true ),
			]
		);

		// A skipped lead is out of the pipeline, so its screenshots are dead weight.
		if ( 'skip' === $verdict ) {
			Screenshot_Store::delete_for_lead( $lead_id );
		}

		Event_Repository::log(
			$automatic ? 'triage.auto' : 'triage.verdict',
			$lead_id,
			[
				'verdict' => $verdict,
				'flags'   => $terminal ?: $pitch,
				'note'    => $note,
			]
		);

		return [
			'lead_id' => $lead_id,
			'verdict' => $verdict,
			'status'  => $status,
		];
	}

	/** Put a lead back in the queue. Every verdict is reversible. */
	public function undo( int $lead_id ): bool {
		$lead = Lead_Repository::find( $lead_id );

		if ( ! $lead || '' === (string) $lead->triage_verdict ) {
			return false;
		}

		Lead_Repository::update(
			$lead_id,
			[
				'status'         => 'enriched',
				'triage_verdict' => '',
				'triage_flags'   => '',
				'triage_note'    => null,
				'triage_by'      => null,
				'triage_at'      => null,
			]
		);

		Event_Repository::log( 'triage.undo', $lead_id, [ 'was' => (string) $lead->triage_verdict ] );

		return true;
	}

	/**
	 * The queue: enriched leads awaiting a verdict, most obviously neglected first.
	 *
	 * @return array<int,object>
	 */
	public function queue( int $limit = 12, int $offset = 0 ): array {
		global $wpdb;

		$table = Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE triage_verdict = ''
				   AND status IN ( 'enriched', 'triage_deferred' )
				 ORDER BY staleness_score DESC, review_count DESC, id ASC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	public function queue_size(): int {
		global $wpdb;

		$table = Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			 WHERE triage_verdict = '' AND status IN ( 'enriched', 'triage_deferred' )"
		);
	}

	/** How many leads are still waiting to be enriched before they can be triaged. */
	public function pending_enrichment(): int {
		global $wpdb;

		$table = Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status IN ( 'new', 'enriching' )"
		);
	}

	/**
	 * Decide the unambiguous cases without a human.
	 *
	 * Off by default. Every automatic verdict is logged as triage.auto and listed under an
	 * Auto-triaged filter, so nothing disappears silently — that is the price of letting a
	 * rule remove a lead from the queue.
	 *
	 * @return string The verdict applied, or '' when a human should look.
	 */
	public function auto_verdict( object $lead ): string {
		if ( ! Settings::get( 'auto_triage', false ) ) {
			return '';
		}

		if ( '' === (string) $lead->website ) {
			return 'no_website';
		}

		$enrichment = json_decode( (string) $lead->enrichment_json, true );
		$enrichment = is_array( $enrichment ) ? $enrichment : [];

		if ( ! empty( $enrichment['unreachable'] ) || ! empty( $enrichment['no_website'] ) ) {
			return 'broken';
		}

		$status = (int) ( $enrichment['http']['status'] ?? 0 );

		if ( $status >= 400 ) {
			return 'broken';
		}

		$seo   = (array) ( $enrichment['seo'] ?? [] );
		$speed = (array) ( $enrichment['speed_mobile'] ?? [] );
		$psi   = $speed['score'] ?? null;

		// Skip only when every signal says the site is healthy. Anything ambiguous is a
		// human's call — an automatic skip is the one mistake that loses a real lead.
		$healthy = null !== $psi
			&& $psi >= 90
			&& ! empty( $seo['has_viewport'] )
			&& ! empty( $seo['is_https'] )
			&& empty( $seo['mixed_content'] )
			&& (int) ( $lead->staleness_score ?? 100 ) === 0;

		return $healthy ? 'skip' : '';
	}

	/** Run the auto rules across the waiting queue. @return int Verdicts applied. */
	public function run_auto( int $limit = 200 ): int {
		if ( ! Settings::get( 'auto_triage', false ) ) {
			return 0;
		}

		$applied = 0;

		foreach ( $this->queue( $limit ) as $lead ) {
			$verdict = $this->auto_verdict( $lead );

			if ( '' === $verdict ) {
				continue;
			}

			$result = $this->decide( (int) $lead->id, [ $verdict ], '', true );

			if ( ! is_wp_error( $result ) ) {
				++$applied;
			}
		}

		return $applied;
	}

	/** @return array<string,int> verdict => count, for the progress readout. */
	public function counts(): array {
		global $wpdb;

		$table = Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			"SELECT triage_verdict AS verdict, COUNT(*) AS total
			 FROM {$table} WHERE triage_verdict <> '' GROUP BY triage_verdict"
		);

		$out = [];

		foreach ( $rows as $row ) {
			$out[ (string) $row->verdict ] = (int) $row->total;
		}

		return $out;
	}
}
