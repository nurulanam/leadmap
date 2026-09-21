<?php
/**
 * Fingerprints the platform and front-end libraries a site runs on.
 *
 * The point is not an inventory — it is age. jQuery 1.x, Bootstrap 3, a Flash embed or a
 * dated site builder are the cheapest reliable evidence that a site has not been touched
 * in years, which is exactly the lead we are looking for.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

defined( 'ABSPATH' ) || exit;

final class Tech_Detector {

	/**
	 * @return array<string,mixed>
	 */
	public function detect( Fetch_Result $page ): array {
		$html    = $page->body;
		$headers = $page->headers;

		$out = [
			'cms'            => '',
			'cms_version'    => '',
			'builder'        => '',
			'jquery_version' => '',
			'libraries'      => [],
			'dated_markers'  => [],
			'server'         => $headers['server'] ?? '',
			'generator'      => '',
		];

		if ( preg_match( '/<meta[^>]+name=["\']generator["\'][^>]+content=["\']([^"\']+)/i', $html, $m ) ) {
			$out['generator'] = trim( $m[1] );
		}

		$out['cms'] = $this->cms( $html, $headers, $out['generator'] );

		if ( 'WordPress' === $out['cms'] && preg_match( '/WordPress\s+([0-9.]+)/i', $out['generator'], $m ) ) {
			$out['cms_version'] = $m[1];

			// WordPress 5.x and below on a live site means years without maintenance.
			if ( version_compare( $m[1], '6.0', '<' ) ) {
				$out['dated_markers'][] = 'wordpress_' . $m[1];
			}
		}

		$out['builder'] = $this->builder( $html );

		if ( preg_match( '#jquery[.\-/]?(?:core)?[.\-]?([0-9]+\.[0-9]+(?:\.[0-9]+)?)(?:\.min)?\.js#i', $html, $m ) ) {
			$out['jquery_version'] = $m[1];

			if ( version_compare( $m[1], '2.0', '<' ) ) {
				$out['dated_markers'][] = 'jquery_' . $m[1];
			}
		}

		$markers = [
			'flash'          => '#<(object|embed)[^>]+(shockwave-flash|\.swf)#i',
			'table_layout'   => '#<table[^>]+(cellpadding|cellspacing|border=)#i',
			'font_tag'       => '#<font\b#i',
			'marquee'        => '#<(marquee|blink)\b#i',
			'frameset'       => '#<frameset\b#i',
			'bootstrap_3'    => '#bootstrap[/.\-]3\.[0-9.]+#i',
			'bootstrap_2'    => '#bootstrap[/.\-]2\.[0-9.]+#i',
			'xhtml_doctype'  => '#<!DOCTYPE[^>]+XHTML#i',
			'frontpage'      => '#content=["\']Microsoft FrontPage#i',
		];

		foreach ( $markers as $name => $pattern ) {
			if ( preg_match( $pattern, $html ) ) {
				$out['dated_markers'][] = $name;
			}
		}

		foreach ( [ 'react' => '#(react(-dom)?[.\-][0-9]|__REACT_|data-reactroot)#i',
			'vue'      => '#(vue[.\-][0-9]|__vue__|data-v-[0-9a-f]{8})#i',
			'tailwind' => '#(tailwind|class="[^"]*\b(?:flex|grid)\b[^"]*\bgap-[0-9])#i',
			'elementor'=> '#elementor#i',
			'jquery'   => '#jquery#i',
		] as $name => $pattern ) {
			if ( preg_match( $pattern, $html ) ) {
				$out['libraries'][] = $name;
			}
		}

		$out['dated_markers'] = array_values( array_unique( $out['dated_markers'] ) );
		$out['libraries']     = array_values( array_unique( $out['libraries'] ) );

		return $out;
	}

	/** @param array<string,string> $headers */
	private function cms( string $html, array $headers, string $generator ): string {
		$signatures = [
			'WordPress' => [ '#/wp-content/#i', '#/wp-includes/#i', '#wp-json#i' ],
			'Shopify'   => [ '#cdn\.shopify\.com#i', '#Shopify\.theme#i' ],
			'Wix'       => [ '#static\.wixstatic#i', '#wix-?code#i' ],
			'Squarespace' => [ '#squarespace\.com#i', '#static1\.squarespace#i' ],
			'Weebly'    => [ '#weebly\.com#i', '#weeblycloud#i' ],
			'Joomla'    => [ '#/media/jui/#i', '#option=com_#i' ],
			'Drupal'    => [ '#/sites/default/files/#i', '#Drupal\.settings#i' ],
			'GoDaddy'   => [ '#godaddysites\.com#i', '#/websitebuilder/#i' ],
			'Duda'      => [ '#dudamobile#i', '#d\.dudaimg#i' ],
			'Webflow'   => [ '#webflow#i' ],
		];

		foreach ( $signatures as $name => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $html ) ) {
					return $name;
				}
			}
		}

		if ( '' !== $generator ) {
			foreach ( array_keys( $signatures ) as $name ) {
				if ( stripos( $generator, $name ) !== false ) {
					return $name;
				}
			}
		}

		return '';
	}

	/** Site builders that signal a template site nobody has revisited. */
	private function builder( string $html ): string {
		$builders = [
			'Wix'          => '#static\.wixstatic#i',
			'Squarespace'  => '#static1\.squarespace#i',
			'Weebly'       => '#weeblycloud#i',
			'GoDaddy'      => '#godaddysites\.com#i',
			'Duda'         => '#d\.dudaimg#i',
			'Elementor'    => '#elementor-#i',
			'Divi'         => '#/themes/Divi/#i',
			'WPBakery'     => '#js_composer#i',
			'Visual Composer' => '#vc_row#i',
		];

		foreach ( $builders as $name => $pattern ) {
			if ( preg_match( $pattern, $html ) ) {
				return $name;
			}
		}

		return '';
	}
}
