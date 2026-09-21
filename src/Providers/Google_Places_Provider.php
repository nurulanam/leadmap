<?php
/**
 * Google Places API (New) — Text Search.
 *
 * Billing follows the highest-tier field in the mask. Phone, website and rating are
 * Enterprise-tier fields, so a lead-collection mask lands on Text Search Enterprise
 * ($35 per 1,000 calls, first 1,000 calls each month free) — there is no cheaper tier that
 * still returns a phone number. The mask deliberately omits reviews and priceLevel, which
 * would push the call into the pricier Enterprise + Atmosphere SKU for data we do not use.
 *
 * Text Search already returns phone and website, so no separate Place Details call is
 * needed — that halves the per-lead cost versus a two-call design.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Providers;

use LeadMap\Search\Search_Query;
use LeadMap\Support\Http;
use LeadMap\Support\Normalize;
use LeadMap\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Google_Places_Provider implements Search_Provider {

	private const SEARCH_URL  = 'https://places.googleapis.com/v1/places:searchText';
	private const GEOCODE_URL = 'https://maps.googleapis.com/maps/api/geocode/json';

	/**
	 * Only the fields we persist. Adding one can move the call into a pricier SKU — check
	 * the Places tier table before editing. Never add reviews or priceLevel.
	 */
	private const FIELD_MASK = 'places.id,places.displayName,places.formattedAddress,places.addressComponents,places.nationalPhoneNumber,places.internationalPhoneNumber,places.websiteUri,places.rating,places.userRatingCount,places.primaryType,places.location,places.googleMapsUri,nextPageToken';

	/**
	 * USD per Text Search Enterprise request ($35 per 1,000), as of 2026. Ignores the 1,000
	 * free monthly calls, so the estimate is deliberately pessimistic. Override via filter.
	 */
	private const COST_PER_REQUEST = 0.035;

	public const PAGE_SIZE = 20;

	public function get_id(): string {
		return 'google_places';
	}

	public function get_label(): string {
		return __( 'Google Places API (New)', 'leadmap' );
	}

	public function is_configured(): bool {
		return '' !== Settings::google_api_key();
	}

	public function page_size(): int {
		return self::PAGE_SIZE;
	}

	public function cost_estimate( int $results ): float {
		$requests = (int) max( 1, ceil( $results / self::PAGE_SIZE ) );
		$rate     = (float) apply_filters( 'leadmap_google_cost_per_request', self::COST_PER_REQUEST );

		return round( $requests * $rate, 4 );
	}

	public function search( Search_Query $query ): Search_Result|WP_Error {
		$key = Settings::google_api_key();

		if ( '' === $key ) {
			return new WP_Error(
				'leadmap_no_api_key',
				__( 'No Google API key is configured. Add one under LeadMap → Settings.', 'leadmap' )
			);
		}

		$body = $this->build_body( $query );

		$response = Http::post_json(
			self::SEARCH_URL,
			$body,
			[
				'X-Goog-Api-Key'   => $key,
				'X-Goog-FieldMask' => self::FIELD_MASK,
			],
			25
		);

		if ( is_wp_error( $response ) ) {
			return $this->explain( $response );
		}

		$places = [];

		foreach ( (array) ( $response['places'] ?? [] ) as $place ) {
			if ( is_array( $place ) ) {
				$places[] = $this->map_place( $place );
			}
		}

		$rate = (float) apply_filters( 'leadmap_google_cost_per_request', self::COST_PER_REQUEST );

		return new Search_Result(
			$places,
			isset( $response['nextPageToken'] ) ? (string) $response['nextPageToken'] : null,
			$rate
		);
	}

	/**
	 * Turn a ZIP or place name into a center point so locationBias can constrain the search.
	 *
	 * @return array{lat:float,lng:float}|WP_Error
	 */
	public function geocode( string $address, string $region = 'US' ): array|WP_Error {
		$key = Settings::google_api_key();

		if ( '' === $key ) {
			return new WP_Error( 'leadmap_no_api_key', __( 'No Google API key is configured.', 'leadmap' ) );
		}

		$response = Http::get_json(
			self::GEOCODE_URL,
			[
				'address' => $address,
				'region'  => strtolower( $region ),
				'key'     => $key,
			],
			[],
			15
		);

		if ( is_wp_error( $response ) ) {
			return $this->explain( $response );
		}

		$status = (string) ( $response['status'] ?? '' );

		if ( 'OK' !== $status ) {
			if ( 'ZERO_RESULTS' === $status ) {
				return new WP_Error(
					'leadmap_geocode_empty',
					/* translators: %s: the address or ZIP that was searched. */
					sprintf( __( 'Google could not find a location for "%s". Check the ZIP or city name.', 'leadmap' ), $address )
				);
			}

			// Geocoding reports key and billing problems inside a 200 response, so this has
			// to go through the same explainer as a genuine HTTP error.
			return $this->explain(
				new WP_Error(
					'leadmap_geocode_failed',
					(string) ( $response['error_message'] ?? sprintf( 'Geocoding failed (%s).', $status ) )
				)
			);
		}

		$location = $response['results'][0]['geometry']['location'] ?? null;

		if ( ! is_array( $location ) ) {
			return new WP_Error( 'leadmap_geocode_failed', __( 'Geocoding returned no coordinates.', 'leadmap' ) );
		}

		return [
			'lat' => (float) $location['lat'],
			'lng' => (float) $location['lng'],
		];
	}

	/**
	 * Compose the searchText request body.
	 *
	 * A paging request must REPEAT every parameter of the initial request alongside the
	 * token — Google rejects a token-only body with "Empty text_query" — so textQuery,
	 * pageSize and locationBias stay identical across every page of one search.
	 *
	 * @return array<string,mixed>
	 */
	private function build_body( Search_Query $query ): array {
		$body = [
			'textQuery'    => $query->text(),
			'pageSize'     => min( self::PAGE_SIZE, max( 1, $query->max_results ) ),
			'languageCode' => $query->language_code,
			'regionCode'   => $query->region_code,
		];

		if ( null !== $query->lat && null !== $query->lng ) {
			$body['locationBias'] = [
				'circle' => [
					'center' => [
						'latitude'  => $query->lat,
						'longitude' => $query->lng,
					],
					'radius'  => (float) min( 50000, max( 1, $query->radius_m ) ),
				],
			];
		}

		if ( $query->page_token ) {
			$body['pageToken'] = $query->page_token;
		}

		return $body;
	}

	/** @param array<string,mixed> $place */
	private function map_place( array $place ): Raw_Place {
		$components = $this->address_components( (array) ( $place['addressComponents'] ?? [] ) );

		$phone = (string) ( $place['internationalPhoneNumber'] ?? $place['nationalPhoneNumber'] ?? '' );

		return new Raw_Place(
			external_id: (string) ( $place['id'] ?? '' ),
			name: Normalize::text( $place['displayName']['text'] ?? '', 255 ),
			phone: Normalize::text( $phone, 50 ),
			website: Normalize::text( $place['websiteUri'] ?? '', 500 ),
			address: Normalize::text( $place['formattedAddress'] ?? '', 500 ),
			city: $components['locality'] ?? '',
			state: $components['administrative_area_level_1'] ?? '',
			zip: $components['postal_code'] ?? '',
			country: $components['country'] ?? '',
			lat: isset( $place['location']['latitude'] ) ? (float) $place['location']['latitude'] : null,
			lng: isset( $place['location']['longitude'] ) ? (float) $place['location']['longitude'] : null,
			category: Normalize::text( $place['primaryType'] ?? '', 191 ),
			rating: isset( $place['rating'] ) ? (float) $place['rating'] : null,
			review_count: (int) ( $place['userRatingCount'] ?? 0 ),
			maps_url: Normalize::text( $place['googleMapsUri'] ?? '', 500 ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $components
	 *
	 * @return array<string,string>
	 */
	private function address_components( array $components ): array {
		$out = [];

		foreach ( $components as $component ) {
			$types = (array) ( $component['types'] ?? [] );

			foreach ( $types as $type ) {
				if ( isset( $out[ $type ] ) ) {
					continue;
				}

				// Two-letter codes for state and country, full text otherwise.
				$use_short = in_array( $type, [ 'administrative_area_level_1', 'country' ], true );
				$value     = $use_short
					? (string) ( $component['shortText'] ?? $component['longText'] ?? '' )
					: (string) ( $component['longText'] ?? $component['shortText'] ?? '' );

				$out[ $type ] = $value;
			}
		}

		return $out;
	}

	/** Turn raw Google errors into something an operator can act on. */
	private function explain( WP_Error $error ): WP_Error {
		$code    = $error->get_error_code();
		$message = $error->get_error_message();

		// Google's own wording for the common misconfigurations is accurate but gives no
		// remedy, so match on it and say what to actually change.
		$patterns = [
			'are blocked'            => __( 'The key reached Google but is not allowed to call Places API (New). Two things to check, in order: (1) APIs & Services → Library → enable "Places API (New)" — its id is places.googleapis.com, not the legacy "Places API" which is places-backend.googleapis.com; (2) APIs & Services → Credentials → open the key → API restrictions → add Places API (New) to the allowed list. The restriction list only offers APIs already enabled, so step 1 has to come first. Allow a minute or two for the change to apply.', 'leadmap' ),
			'referer restrictions'   => __( 'This key is restricted to HTTP referrers, which only works for keys used in a browser. LeadMap calls Google from your server, where there is no referrer. In Google Cloud Console → APIs & Services → Credentials, open the key and change Application restrictions from "Websites" to "IP addresses" (add this server\'s outbound IP), or to "None" while testing. Changes take a minute or two to apply.', 'leadmap' ),
			'IP address restrictions' => __( 'This key is restricted to IP addresses that do not include this server. Run "curl -s ifconfig.me" on the server to get its outbound IP, then add that IP to the key under APIs & Services → Credentials.', 'leadmap' ),
			'API key not valid'      => __( 'Google does not recognise this key. Check it was copied in full, and that it belongs to the project where you enabled the APIs.', 'leadmap' ),
			'has not been used in project' => __( 'The API is not enabled on this key\'s project. In Google Cloud Console → APIs & Services → Library, enable both "Places API (New)" and "Geocoding API", then retry in a minute.', 'leadmap' ),
			'not authorized to use this API' => __( 'This key is restricted to a different set of APIs. Open the key under APIs & Services → Credentials and make sure API restrictions include Places API (New) and Geocoding API.', 'leadmap' ),
			'billing'                => __( 'Billing is not enabled on the Google Cloud project. Link a payment method under Billing — normal use stays inside the free tier, but Google requires a card on file.', 'leadmap' ),
		];

		foreach ( $patterns as $needle => $hint ) {
			if ( false !== stripos( $message, $needle ) ) {
				return new WP_Error( $code, $hint, $error->get_error_data() );
			}
		}

		$hints = [
			'leadmap_http_400' => __( 'Google rejected the request as malformed. This is a plugin-side problem rather than a settings one — the full message follows:', 'leadmap' ),
			'leadmap_http_403' => __( 'Google denied the key. Check that the key allows this server\'s IP address and that billing is enabled.', 'leadmap' ),
			'leadmap_http_429' => __( 'Google rate limit reached. The search will need to be re-run later, or the quota raised.', 'leadmap' ),
		];

		if ( isset( $hints[ $code ] ) ) {
			return new WP_Error( $code, $hints[ $code ] . ' ' . $message, $error->get_error_data() );
		}

		return $error;
	}
}
