<?php
/**
 * Screenshot URLs for the triage grid.
 *
 * These are rendered straight into an <img>, not fetched and stored. That keeps the whole
 * feature free by default, costs the server nothing, and means a skipped lead leaves no
 * attachment behind to clean up later.
 *
 * The default source is WordPress.com's mShots, the same service the plugin directory uses
 * for its own previews: no API key, no account, no per-image cost. It generates on first
 * request, so an early hit can return a placeholder — the grid retries.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Triage;

use LeadMap\Support\Encryption;
use LeadMap\Support\Settings;
use LeadMap\Support\Url_Guard;

defined( 'ABSPATH' ) || exit;

final class Screenshotter {

	public const DESKTOP_WIDTH = 900;
	public const DESKTOP_HEIGHT = 600;
	public const MOBILE_WIDTH = 390;
	public const MOBILE_HEIGHT = 700;

	/** @return array<string,string> provider id => label. */
	public static function providers(): array {
		return [
			'mshots'        => __( 'PageSpeed capture, falling back to mShots — free, no key', 'leadmap' ),
			'screenshotone' => __( 'ScreenshotOne — API key required', 'leadmap' ),
			'apiflash'      => __( 'ApiFlash — API key required', 'leadmap' ),
			'none'          => __( 'PageSpeed captures only — no fallback service', 'leadmap' ),
		];
	}

	/** Whether a *fallback* provider is configured. PageSpeed captures work regardless. */
	public function enabled(): bool {
		return 'none' !== (string) Settings::get( 'screenshot_provider', 'mshots' );
	}

	public function provider(): string {
		return (string) Settings::get( 'screenshot_provider', 'mshots' );
	}

	/**
	 * A screenshot URL for one site, or '' when none can be produced.
	 *
	 * The target is validated first: these URLs come from a third-party API and are handed
	 * to an image service, so the same rules apply as to anything else we fetch.
	 */
	/**
	 * Does the configured provider actually render at a mobile viewport?
	 *
	 * This matters because a desktop render cropped to a phone's aspect ratio *looks* like a
	 * mobile screenshot while showing none of what a mobile visitor sees. Presenting one as
	 * the other would make the whole triage judgement wrong, so when a provider cannot
	 * emulate mobile the UI offers a real preview instead of a misleading thumbnail.
	 */
	public function emulates_mobile(): bool {
		return in_array( $this->provider(), [ 'mshots', 'screenshotone', 'apiflash' ], true );
	}

	/**
	 * The best available screenshot for a lead.
	 *
	 * Prefers the capture Lighthouse already made during the PageSpeed run: it is a real
	 * render at the real viewport, it is on our own disk, and it needed no extra request.
	 * Falls back to the configured provider when PageSpeed has not run yet.
	 *
	 * @param array<string,mixed> $enrichment
	 */
	public function for_lead( object $lead, array $enrichment, string $viewport = 'desktop', int $bust = 0 ): array {
		$stored = (string) ( $enrichment[ 'shot_' . $viewport ] ?? '' );

		if ( '' !== $stored ) {
			$url = Screenshot_Store::url( $stored );

			if ( '' !== $url ) {
				return [
					'url'    => $url,
					'source' => 'pagespeed',
					'real'   => true,
				];
			}
		}

		$url = $this->url( (string) $lead->website, $viewport, $bust );

		return [
			'url'    => $url,
			'source' => '' === $url ? '' : $this->provider(),
			// Only PageSpeed and the paid providers truly emulate a phone.
			'real'   => '' !== $url && ( 'desktop' === $viewport || $this->emulates_mobile() ),
		];
	}

	public function url( string $website, string $viewport = 'desktop', int $bust = 0 ): string {
		if ( '' === trim( $website ) ) {
			return '';
		}

		// The browser loads these, not this server, so the DNS round trip is skipped — see
		// Url_Guard::validate_shape(). Twelve cards would otherwise cost 24 lookups a page.
		if ( is_wp_error( Url_Guard::validate_shape( $website ) ) ) {
			return '';
		}

		$width  = 'mobile' === $viewport ? self::MOBILE_WIDTH : self::DESKTOP_WIDTH;
		$height = 'mobile' === $viewport ? self::MOBILE_HEIGHT : self::DESKTOP_HEIGHT;

		return match ( $this->provider() ) {
			'mshots'        => $this->mshots( $website, $width, $height, $viewport, $bust ),
			'screenshotone' => $this->screenshotone( $website, $width, $height, $viewport, $bust ),
			'apiflash'      => $this->apiflash( $website, $width, $height, $bust ),
			default         => '',
		};
	}

	private function mshots( string $url, int $width, int $height, string $viewport, int $bust ): string {
		// mShots takes the target URL-encoded in the path, not as a query parameter.
		$args = [
			'w' => $width,
			'h' => $height,
		];

		// vpw sets the browser viewport, which is what makes responsive CSS engage. Without
		// it mShots renders at desktop width and the result is a crop, not a mobile view.
		if ( 'mobile' === $viewport ) {
			$args['vpw'] = self::MOBILE_WIDTH;
		}

		if ( $bust > 0 ) {
			$args['r'] = $bust;
		}

		return sprintf(
			'https://s0.wp.com/mshots/v1/%s?%s',
			rawurlencode( $url ),
			http_build_query( $args )
		);
	}

	private function screenshotone( string $url, int $width, int $height, string $viewport, int $bust = 0 ): string {
		$key = Encryption::decrypt( (string) Settings::get( 'screenshot_key', '' ) );

		if ( '' === $key ) {
			return '';
		}

		return add_query_arg(
			[
				'access_key'      => $key,
				'url'             => $url,
				'viewport_width'  => $width,
				'viewport_height' => $height,
				'format'          => 'webp',
				'block_ads'       => 'true',
				'block_cookie_banners' => 'true',
				'cache'           => 'true',
				'cache_ttl'       => 2592000,
				'is_mobile'       => 'mobile' === $viewport ? 'true' : 'false',
				'device_scale_factor' => 'mobile' === $viewport ? 2 : 1,
			]
			+ ( $bust > 0 ? [ 'cache' => 'false' ] : [] ),
			'https://api.screenshotone.com/take'
		);
	}

	private function apiflash( string $url, int $width, int $height, int $bust = 0 ): string {
		$key = Encryption::decrypt( (string) Settings::get( 'screenshot_key', '' ) );

		if ( '' === $key ) {
			return '';
		}

		return add_query_arg(
			[
				'access_key' => $key,
				'url'        => $url,
				'width'      => $width,
				'height'     => $height,
				'format'     => 'webp',
				'response_type' => 'image',
				'no_cookie_banners' => 'true',
				'ttl'        => $bust > 0 ? 0 : 2592000,
				'fresh'      => $bust > 0 ? 'true' : 'false',
			],
			'https://api.apiflash.com/v1/urltoimage'
		);
	}
}
