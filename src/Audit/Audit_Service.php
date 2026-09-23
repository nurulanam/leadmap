<?php
/**
 * Saving, loading and queueing audits.
 *
 * The audit is where a person decides what to actually say to this business. Everything the
 * machine found is context; the problem note the operator writes is what ends up in the
 * email, so the form's job is to make writing that note quick and well-informed.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Audit;

use LeadMap\Events\Event_Repository;
use LeadMap\Install\Schema;
use LeadMap\Leads\Lead_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Audit_Service {

	public const OUTCOMES = [ 'audited', 'not_a_fit' ];

	public function find( int $lead_id ): ?object {
		global $wpdb;

		$table = Schema::table( 'audits' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE lead_id = %d", $lead_id ) );

		return $row ?: null;
	}

	/**
	 * Record an audit.
	 *
	 * @param array<string,mixed> $input
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function save( int $lead_id, array $input ): array|WP_Error {
		$tags = array_values( array_filter(
			array_map( 'sanitize_key', (array) ( $input['issue_tags'] ?? [] ) ),
			[ Issue_Tags::class, 'exists' ]
		) );

		$primary  = sanitize_key( (string) ( $input['primary_issue'] ?? '' ) );
		$outcome  = in_array( (string) ( $input['outcome'] ?? 'audited' ), self::OUTCOMES, true )
			? (string) $input['outcome']
			: 'audited';
		$notes    = trim( (string) ( $input['problem_notes'] ?? '' ) );
		$reach    = ! empty( $input['is_reachable'] );

		// A lead marked not-a-fit is leaving the pipeline, so none of the rest is required.
		if ( 'audited' === $outcome ) {
			if ( ! $tags ) {
				return new WP_Error( 'leadmap_no_tags', __( 'Choose at least one problem before saving.', 'leadmap' ) );
			}

			if ( '' === $primary || ! Issue_Tags::exists( $primary ) ) {
				return new WP_Error( 'leadmap_no_primary', __( 'Choose which problem to lead with.', 'leadmap' ) );
			}

			if ( ! in_array( $primary, $tags, true ) ) {
				return new WP_Error(
					'leadmap_primary_untagged',
					__( 'The problem you are leading with is not one of the problems you ticked.', 'leadmap' )
				);
			}

			// The note becomes the {{problem}} line of the outreach email, so an empty or
			// throwaway one produces a message not worth sending.
			if ( mb_strlen( $notes ) < 15 ) {
				return new WP_Error(
					'leadmap_thin_note',
					__( 'Write a sentence about what is actually wrong — this is what goes into the email.', 'leadmap' )
				);
			}
		}

		$lead = Lead_Repository::find( $lead_id );

		if ( ! $lead ) {
			return new WP_Error( 'leadmap_lead_missing', __( 'That lead no longer exists.', 'leadmap' ) );
		}

		$enrichment = json_decode( (string) $lead->enrichment_json, true );
		$enrichment = is_array( $enrichment ) ? $enrichment : [];

		$fit      = ( new Fit_Scorer() )->score( $lead, $enrichment );
		$override = isset( $input['fit_override'] ) && '' !== $input['fit_override']
			? max( 0, min( 100, (int) $input['fit_override'] ) )
			: null;

		$now  = current_time( 'mysql', true );
		$data = [
			'lead_id'       => $lead_id,
			'verified_by'   => get_current_user_id(),
			'verified_at'   => $now,
			'is_reachable'  => $reach ? 1 : 0,
			'issue_tags'    => implode( ',', $tags ),
			'primary_issue' => 'audited' === $outcome ? $primary : '',
			'problem_notes' => '' === $notes ? null : mb_substr( $notes, 0, 2000 ),
			'evidence_json' => wp_json_encode(
				[
					'fit'        => $fit,
					'opportunity' => null === $lead->staleness_score ? null : (int) $lead->staleness_score,
					'psi_mobile' => $enrichment['speed_mobile']['score'] ?? null,
					'psi_desktop' => $enrichment['speed_desktop']['score'] ?? null,
					'seo_gap'    => $enrichment['seo']['gap_score'] ?? null,
					'captured_at' => $now,
				]
			),
			'fit_score'     => $fit['score'],
			'fit_override'  => $override,
			'outcome'       => $outcome,
			'updated_at'    => $now,
		];

		global $wpdb;

		$existing = $this->find( $lead_id );

		if ( $existing ) {
			$wpdb->update( Schema::table( 'audits' ), $data, [ 'lead_id' => $lead_id ] );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( Schema::table( 'audits' ), $data );
		}

		Lead_Repository::update(
			$lead_id,
			[ 'status' => 'not_a_fit' === $outcome ? 'bad_fit' : 'audited' ]
		);

		Event_Repository::log(
			'audit.saved',
			$lead_id,
			[
				'outcome' => $outcome,
				'primary' => $data['primary_issue'],
				'tags'    => $tags,
				'fit'     => $override ?? $fit['score'],
			]
		);

		return [
			'lead_id' => $lead_id,
			'outcome' => $outcome,
			'fit'     => $override ?? $fit['score'],
			'primary' => $data['primary_issue'],
		];
	}

	/**
	 * The queue: leads that passed triage and have not been audited.
	 *
	 * Ordered by fit rather than by how broken the site is — the point of this stage is to
	 * spend time on the businesses most likely to reply.
	 *
	 * @return array<int,object>
	 */
	public function queue( int $limit = 1, int $offset = 0 ): array {
		global $wpdb;

		$leads  = Schema::table( 'leads' );
		$audits = Schema::table( 'audits' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.* FROM {$leads} l
				 LEFT JOIN {$audits} a ON a.lead_id = l.id
				 WHERE l.status = 'triaged' AND a.id IS NULL
				 ORDER BY l.email <> '' DESC, l.staleness_score DESC, l.review_count DESC, l.id ASC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	public function queue_size(): int {
		global $wpdb;

		$leads  = Schema::table( 'leads' );
		$audits = Schema::table( 'audits' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$leads} l
			 LEFT JOIN {$audits} a ON a.lead_id = l.id
			 WHERE l.status = 'triaged' AND a.id IS NULL"
		);
	}

	/** @return array{audited:int,not_a_fit:int} */
	public function counts(): array {
		global $wpdb;

		$table = Schema::table( 'audits' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT outcome, COUNT(*) AS total FROM {$table} GROUP BY outcome" );

		$out = [ 'audited' => 0, 'not_a_fit' => 0 ];

		foreach ( $rows as $row ) {
			$out[ (string) $row->outcome ] = (int) $row->total;
		}

		return $out;
	}

	public function delete( int $lead_id ): void {
		global $wpdb;

		$wpdb->delete( Schema::table( 'audits' ), [ 'lead_id' => $lead_id ], [ '%d' ] );
	}
}
