<?php
/**
 * Screenshots captured from PageSpeed.
 *
 * Lighthouse renders the page in a real Chrome at the requested viewport and returns the
 * result as a `final-screenshot` audit. That makes it a genuine mobile render rather than a
 * desktop capture cropped to a phone's shape — and it arrives inside a call we already make,
 * so it costs nothing extra and needs no third-party service.
 *
 * Files live in uploads/leadmap-shots/, not the media library, so they never clutter it and
 * can be purged wholesale.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Triage;

defined( 'ABSPATH' ) || exit;

final class Screenshot_Store {

	private const DIR = 'leadmap-shots';

	/** Refuse anything larger; a page screenshot is well under this. */
	private const MAX_BYTES = 3145728; // 3 MB.

	/** Image types Lighthouse actually returns. */
	private const ALLOWED = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	];

	/**
	 * Store a screenshot for one lead and strategy.
	 *
	 * @param string $data_uri The `data:image/...;base64,...` string from Lighthouse.
	 *
	 * @return string The stored filename, or '' when it could not be stored.
	 */
	public static function save( int $lead_id, string $strategy, string $data_uri ): string {
		if ( $lead_id < 1 || ! in_array( $strategy, [ 'mobile', 'desktop' ], true ) ) {
			return '';
		}

		$decoded = self::decode( $data_uri );

		if ( ! $decoded ) {
			return '';
		}

		$dir = self::dir();

		if ( '' === $dir ) {
			return '';
		}

		// The filename is built from values we control, never from the response.
		$filename = sprintf( '%d-%s.%s', $lead_id, $strategy, $decoded['ext'] );
		$path     = $dir . '/' . $filename;

		if ( false === file_put_contents( $path, $decoded['bytes'] ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return '';
		}

		return $filename;
	}

	/** Public URL for a stored screenshot, with a cache-buster tied to its mtime. */
	public static function url( string $filename ): string {
		if ( '' === $filename || ! self::is_safe_name( $filename ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . self::DIR . '/' . $filename;

		if ( ! is_readable( $path ) ) {
			return '';
		}

		return add_query_arg(
			'v',
			(string) filemtime( $path ),
			trailingslashit( $uploads['baseurl'] ) . self::DIR . '/' . $filename
		);
	}

	public static function delete( string $filename ): void {
		if ( '' === $filename || ! self::is_safe_name( $filename ) ) {
			return;
		}

		$uploads = wp_get_upload_dir();
		$path    = trailingslashit( $uploads['basedir'] ) . self::DIR . '/' . $filename;

		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/** Remove every screenshot belonging to a lead. */
	public static function delete_for_lead( int $lead_id ): void {
		foreach ( [ 'mobile', 'desktop' ] as $strategy ) {
			foreach ( array_values( self::ALLOWED ) as $ext ) {
				self::delete( sprintf( '%d-%s.%s', $lead_id, $strategy, $ext ) );
			}
		}
	}

	/**
	 * Decode and validate a data URI.
	 *
	 * This string comes from a third-party API, so the type is taken from an allow-list, the
	 * payload is size-capped before and after decoding, and the result must actually be an
	 * image — not merely claim to be one.
	 *
	 * @return array{bytes:string,ext:string}|null
	 */
	private static function decode( string $data_uri ): ?array {
		if ( ! preg_match( '#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', trim( $data_uri ), $m ) ) {
			return null;
		}

		$mime = $m[1];

		// Base64 inflates by about a third; reject before allocating the decoded string.
		if ( strlen( $m[2] ) > (int) ceil( self::MAX_BYTES * 4 / 3 ) ) {
			return null;
		}

		$bytes = base64_decode( $m[2], true );

		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
			return null;
		}

		// Confirm the bytes really are the image type claimed.
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_array( $info ) || ( $info['mime'] ?? '' ) !== $mime ) {
			return null;
		}

		return [
			'bytes' => $bytes,
			'ext'   => self::ALLOWED[ $mime ],
		];
	}

	/** Only the names this class generates. */
	private static function is_safe_name( string $filename ): bool {
		return (bool) preg_match( '/^[0-9]+-(?:mobile|desktop)\.(?:jpg|png|webp)$/', $filename );
	}

	/** The storage directory, created on first use. */
	private static function dir(): string {
		$uploads = wp_get_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR;

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		// Stop the directory being browsable on servers that allow indexes.
		$index = $dir . '/index.php';

		if ( ! is_file( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/** Total bytes on disk, for the settings screen. */
	public static function disk_usage(): int {
		$uploads = wp_get_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . self::DIR;

		if ( ! is_dir( $dir ) ) {
			return 0;
		}

		$total = 0;

		foreach ( (array) glob( $dir . '/*.{jpg,png,webp}', GLOB_BRACE ) as $file ) {
			$total += (int) filesize( (string) $file );
		}

		return $total;
	}
}
