<?php
/**
 * Thin wrapper over the WP HTTP API with JSON decoding and consistent error shapes.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Http {

	/**
	 * @param array<string,string> $headers
	 * @param array<string,mixed>  $body
	 *
	 * @return array<string,mixed>|WP_Error Decoded JSON body.
	 */
	public static function post_json( string $url, array $body, array $headers = [], int $timeout = 20 ): array|WP_Error {
		$response = wp_remote_post(
			$url,
			[
				'timeout'     => $timeout,
				'redirection' => 2,
				'headers'     => array_merge(
					[
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					],
					$headers
				),
				'body'        => wp_json_encode( $body ),
				'user-agent'  => self::user_agent(),
			]
		);

		return self::handle( $response );
	}

	/**
	 * @param array<string,string|int|float> $query
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get_json( string $url, array $query = [], array $headers = [], int $timeout = 20 ): array|WP_Error {
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout'     => $timeout,
				'redirection' => 2,
				'headers'     => array_merge( [ 'Accept' => 'application/json' ], $headers ),
				'user-agent'  => self::user_agent(),
			]
		);

		return self::handle( $response );
	}

	/** @return array<string,mixed>|WP_Error */
	private static function handle( array|WP_Error $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $json )
				? ( $json['error']['message'] ?? $json['error_message'] ?? '' )
				: '';

			return new WP_Error(
				'leadmap_http_' . $code,
				$message ?: sprintf( 'Request failed with HTTP %d.', $code ),
				[ 'status' => $code, 'body' => mb_substr( $body, 0, 500 ) ]
			);
		}

		if ( ! is_array( $json ) ) {
			return new WP_Error( 'leadmap_bad_json', 'The API returned a response that was not valid JSON.' );
		}

		return $json;
	}

	private static function user_agent(): string {
		return sprintf( 'LeadMap/%s (+%s)', LEADMAP_VERSION, home_url( '/' ) );
	}
}
