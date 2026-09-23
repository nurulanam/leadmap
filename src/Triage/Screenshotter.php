<?php
/**
 * Screenshots for triage.
 *
 * There is exactly one source: the capture Lighthouse makes during a PageSpeed run. It
 * renders in a real Chrome at the real viewport, so the mobile shot is a genuine phone view
 * rather than a desktop capture cropped to a tall rectangle — and it arrives inside a call
 * the plugin already makes, so it costs nothing and needs no third-party service or key.
 *
 * Third-party providers were supported here previously and are gone: none of them produced a
 * true mobile render for free, which is the only thing that made them worth the complexity.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Triage;

defined( 'ABSPATH' ) || exit;

final class Screenshotter {

	/**
	 * The stored capture for one lead and viewport.
	 *
	 * @param array<string,mixed> $enrichment
	 *
	 * @return array{url:string,real:bool,pending:bool}
	 */
	public function for_lead( object $lead, array $enrichment, string $viewport = 'desktop' ): array {
		$viewport = 'mobile' === $viewport ? 'mobile' : 'desktop';
		$stored   = (string) ( $enrichment[ 'shot_' . $viewport ] ?? '' );

		if ( '' !== $stored ) {
			$url = Screenshot_Store::url( $stored );

			if ( '' !== $url ) {
				return [
					'url'     => $url,
					'real'    => true,
					'pending' => false,
				];
			}
		}

		// No capture yet. Whether one is coming decides what the UI should say.
		$state = \LeadMap\Enrich\Speed_Status::get( $enrichment, $viewport );

		return [
			'url'     => '',
			'real'    => false,
			'pending' => \LeadMap\Enrich\Speed_Status::in_progress( $state['state'] ) || '' === $state['state'],
		];
	}

	/** Whether a lead has any capture at all yet. */
	public function has_any( array $enrichment ): bool {
		return '' !== (string) ( $enrichment['shot_desktop'] ?? '' )
			|| '' !== (string) ( $enrichment['shot_mobile'] ?? '' );
	}
}
