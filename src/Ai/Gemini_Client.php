<?php
/**
 * Minimal Gemini API client.
 *
 * Uses the Generative Language API, which has a free tier. This is a separate key from the
 * Google Cloud Maps key — a different product with its own quota — so it is stored and
 * tested separately.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Ai;

use LeadMap\Support\Encryption;
use LeadMap\Support\Http;
use LeadMap\Support\Rate_Limiter;
use LeadMap\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Gemini_Client {

	private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

	/** Free-tier limits are per minute, so requests are spaced rather than burst. */
	private const BUCKET = 'gemini';

	public static function api_key(): string {
		if ( defined( 'LEADMAP_GEMINI_API_KEY' ) && '' !== (string) LEADMAP_GEMINI_API_KEY ) {
			return (string) LEADMAP_GEMINI_API_KEY;
		}

		return Encryption::decrypt( (string) Settings::get( 'gemini_api_key', '' ) );
	}

	public static function key_is_from_constant(): bool {
		return defined( 'LEADMAP_GEMINI_API_KEY' ) && '' !== (string) LEADMAP_GEMINI_API_KEY;
	}

	public function is_configured(): bool {
		return '' !== self::api_key();
	}

	public function model(): string {
		$model = trim( (string) Settings::get( 'gemini_model', 'gemini-2.0-flash' ) );

		// Only a model name, never a path — this goes straight into the request URL.
		return preg_match( '/^[a-z0-9.\-]{1,64}$/i', $model ) ? $model : 'gemini-2.0-flash';
	}

	/**
	 * Generate text.
	 *
	 * @param string $system Instructions describing the job and its limits.
	 * @param string $prompt The facts to work from.
	 *
	 * @return string|WP_Error
	 */
	public function generate( string $system, string $prompt, int $max_tokens = 400 ): string|WP_Error {
		$key = self::api_key();

		if ( '' === $key ) {
			return new WP_Error(
				'leadmap_no_gemini_key',
				__( 'No Gemini API key is configured. Add one under LeadMap → Settings.', 'leadmap' )
			);
		}

		$wait = Rate_Limiter::wait_for( self::BUCKET );

		if ( $wait > 0 ) {
			return new WP_Error(
				'leadmap_gemini_busy',
				sprintf(
					/* translators: %d: seconds to wait. */
					__( 'Gemini\'s free tier allows only a few requests a minute. Try again in %d seconds.', 'leadmap' ),
					$wait
				)
			);
		}

		Rate_Limiter::consume( self::BUCKET, (float) Settings::get( 'gemini_per_minute', 10 ) );

		$response = Http::post_json(
			self::BASE . '/models/' . rawurlencode( $this->model() ) . ':generateContent?key=' . rawurlencode( $key ),
			[
				'system_instruction' => [
					'parts' => [ [ 'text' => $system ] ],
				],
				'contents'           => [
					[
						'role'  => 'user',
						'parts' => [ [ 'text' => $prompt ] ],
					],
				],
				'generationConfig'   => [
					// Low temperature: this is a factual summary, not creative writing.
					'temperature'     => 0.4,
					'maxOutputTokens' => $max_tokens,
					'topP'            => 0.9,
				],
			],
			[],
			45
		);

		if ( is_wp_error( $response ) ) {
			return $this->explain( $response );
		}

		$candidate = $response['candidates'][0] ?? null;

		if ( ! is_array( $candidate ) ) {
			$blocked = $response['promptFeedback']['blockReason'] ?? '';

			return new WP_Error(
				'leadmap_gemini_empty',
				'' !== $blocked
					? __( 'Gemini declined to answer for this lead. Write the note by hand.', 'leadmap' )
					: __( 'Gemini returned no text. Try again, or write the note by hand.', 'leadmap' )
			);
		}

		$text = '';

		foreach ( (array) ( $candidate['content']['parts'] ?? [] ) as $part ) {
			$text .= (string) ( $part['text'] ?? '' );
		}

		$text = trim( $text );

		if ( '' === $text ) {
			return new WP_Error( 'leadmap_gemini_empty', __( 'Gemini returned nothing usable.', 'leadmap' ) );
		}

		return $text;
	}

	/** List the models this key can actually use — model names change, keys differ. */
	public function models(): array|WP_Error {
		$key = self::api_key();

		if ( '' === $key ) {
			return new WP_Error( 'leadmap_no_gemini_key', __( 'No Gemini API key is configured.', 'leadmap' ) );
		}

		$response = Http::get_json( self::BASE . '/models', [ 'key' => $key ], [], 20 );

		if ( is_wp_error( $response ) ) {
			return $this->explain( $response );
		}

		$names = [];

		foreach ( (array) ( $response['models'] ?? [] ) as $model ) {
			$methods = (array) ( $model['supportedGenerationMethods'] ?? [] );

			if ( ! in_array( 'generateContent', $methods, true ) ) {
				continue;
			}

			$name = (string) ( $model['name'] ?? '' );

			if ( str_starts_with( $name, 'models/' ) ) {
				$names[] = substr( $name, 7 );
			}
		}

		sort( $names );

		return $names;
	}

	private function explain( WP_Error $error ): WP_Error {
		$message = $error->get_error_message();

		$hints = [
			'API key not valid'   => __( 'Gemini does not recognise this key. Create one at aistudio.google.com/apikey — it is separate from your Maps key.', 'leadmap' ),
			'has not been used'   => __( 'The Generative Language API is not enabled on this key\'s project. Enable it, or create a key at aistudio.google.com/apikey which has it on by default.', 'leadmap' ),
			'not found'           => __( 'That model name is not available to this key. Use Test connection under Settings to see which models it can use.', 'leadmap' ),
			'quota'               => __( 'Gemini\'s free-tier quota is used up for now. It resets shortly; write the note by hand in the meantime.', 'leadmap' ),
			'RESOURCE_EXHAUSTED'  => __( 'Gemini\'s free-tier rate limit was hit. Wait a moment and try again.', 'leadmap' ),
		];

		foreach ( $hints as $needle => $hint ) {
			if ( false !== stripos( $message, $needle ) ) {
				return new WP_Error( $error->get_error_code(), $hint, $error->get_error_data() );
			}
		}

		return $error;
	}
}
