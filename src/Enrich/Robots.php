<?php
/**
 * Minimal robots.txt support.
 *
 * We are a well-behaved crawler: honest user agent, one request per second per host, and
 * we do not fetch paths a site has asked us to leave alone. This is not a security control —
 * it is the courtesy that keeps the crawler welcome and off blocklists.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

defined( 'ABSPATH' ) || exit;

final class Robots {

	/** @var string[] Disallowed path prefixes that apply to us. */
	private array $disallow = [];

	private bool $loaded = false;

	public function __construct( private readonly string $body = '' ) {
		$this->parse( $body );
	}

	/** True when we are permitted to fetch this path. Unparseable robots means allowed. */
	public function allows( string $path ): bool {
		if ( ! $this->loaded || ! $this->disallow ) {
			return true;
		}

		$path = '' === $path ? '/' : $path;

		foreach ( $this->disallow as $prefix ) {
			if ( '/' === $prefix ) {
				return false; // Disallow: / bars the whole site.
			}

			if ( str_starts_with( $path, $prefix ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read the records that apply to us: the wildcard group, plus any group naming our bot.
	 * A named group wins outright, as the standard specifies.
	 */
	private function parse( string $body ): void {
		if ( '' === trim( $body ) ) {
			return;
		}

		$this->loaded = true;

		$groups  = [];
		$current = [];
		$agents  = [];
		$in_rule = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $body ) ?: [] as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) ?? '' );

			if ( '' === $line || ! str_contains( $line, ':' ) ) {
				continue;
			}

			[ $field, $value ] = array_map( 'trim', explode( ':', $line, 2 ) );
			$field             = strtolower( $field );

			if ( 'user-agent' === $field ) {
				// A new agent line after rules starts a fresh group.
				if ( $in_rule ) {
					foreach ( $agents as $agent ) {
						$groups[ $agent ] = array_merge( $groups[ $agent ] ?? [], $current );
					}

					$agents  = [];
					$current = [];
					$in_rule = false;
				}

				$agents[] = strtolower( $value );

				continue;
			}

			if ( 'disallow' === $field ) {
				$in_rule = true;

				if ( '' !== $value ) {
					$current[] = $value;
				}
			}
		}

		foreach ( $agents as $agent ) {
			$groups[ $agent ] = array_merge( $groups[ $agent ] ?? [], $current );
		}

		// A group naming us takes precedence over the wildcard group.
		foreach ( [ 'leadmapbot', '*' ] as $key ) {
			if ( isset( $groups[ $key ] ) ) {
				$this->disallow = $groups[ $key ];

				return;
			}
		}
	}
}
