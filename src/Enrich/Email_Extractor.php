<?php
/**
 * Finds email addresses on a business website and ranks them.
 *
 * No Maps provider returns an email, so this is where lead emails actually come from.
 * Expect a 35–60% hit rate on small local businesses; the scoring matters more than the
 * finding, because a page usually yields several addresses and only one is worth writing to.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

defined( 'ABSPATH' ) || exit;

final class Email_Extractor {

	/** Mailbox names that reach a desk but not a person. Usable, ranked lower. */
	private const ROLE_ACCOUNTS = [
		'info', 'contact', 'hello', 'hi', 'sales', 'support', 'help', 'admin',
		'office', 'enquiries', 'inquiries', 'mail', 'team', 'service', 'services',
		'reception', 'bookings', 'appointments', 'general', 'ask', 'webmaster',
	];

	/** Addresses belonging to tooling embedded in the page, never to the business. */
	private const JUNK_DOMAINS = [
		'example.com', 'example.org', 'example.net', 'domain.com', 'yourdomain.com',
		'email.com', 'sentry.io', 'wixpress.com', 'wix.com', 'squarespace.com',
		'godaddy.com', 'wordpress.org', 'w3.org', 'schema.org', 'sentry-cdn.com',
		'googlemail.com', 'test.com', 'email.tst', 'company.com', 'site.com',
	];

	/** Local parts that indicate a placeholder or a library, not a mailbox. */
	private const JUNK_LOCALS = [
		'email', 'youremail', 'your-email', 'name', 'yourname', 'user', 'username',
		'someone', 'sample', 'test', 'noreply', 'no-reply', 'donotreply', 'do-not-reply',
	];

	/** File extensions that a naive regex mistakes for a TLD. */
	private const IMAGE_TLDS = [ 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'css', 'js', 'woff', 'woff2', 'ttf' ];

	/**
	 * Extract every candidate from one page.
	 *
	 * @return Email_Candidate[] Keyed by lower-cased email.
	 */
	public function extract( string $html, string $site_domain, string $page_kind = 'other' ): array {
		$found = [];

		foreach ( $this->from_mailto( $html ) as $email ) {
			$this->add( $found, $email, 'mailto', $site_domain, $page_kind );
		}

		foreach ( $this->from_json_ld( $html ) as $email ) {
			$this->add( $found, $email, 'json_ld', $site_domain, $page_kind );
		}

		foreach ( $this->from_cloudflare( $html ) as $email ) {
			$this->add( $found, $email, 'cloudflare', $site_domain, $page_kind );
		}

		$text = $this->to_text( $html );

		foreach ( $this->from_text( $text ) as $email ) {
			$this->add( $found, $email, $page_kind . '_text', $site_domain, $page_kind );
		}

		foreach ( $this->from_obfuscated( $text ) as $email ) {
			$this->add( $found, $email, 'obfuscated', $site_domain, $page_kind );
		}

		return $found;
	}

	/**
	 * Merge candidates from several pages, keeping the best evidence for each address.
	 *
	 * @param array<int,array<string,Email_Candidate>> $sets
	 *
	 * @return Email_Candidate[] Sorted best first.
	 */
	public function merge( array $sets ): array {
		$merged = [];

		foreach ( $sets as $set ) {
			foreach ( $set as $key => $candidate ) {
				if ( ! isset( $merged[ $key ] ) || $candidate->confidence > $merged[ $key ]->confidence ) {
					$merged[ $key ] = $candidate;
				}
			}
		}

		uasort(
			$merged,
			static function ( Email_Candidate $a, Email_Candidate $b ): int {
				return $b->confidence <=> $a->confidence ?: strcmp( $a->email, $b->email );
			}
		);

		return array_values( $merged );
	}

	/**
	 * Score a candidate 0–100.
	 *
	 * The dominant signal is whether the address is on the site's own domain: a Gmail
	 * address in a footer is far more often the web designer's than the business's.
	 */
	public function score( Email_Candidate $candidate, string $site_domain, string $page_kind ): int {
		$score = 40;

		$email_domain = $candidate->domain();

		if ( '' !== $site_domain && $this->same_site( $email_domain, $site_domain ) ) {
			$score += 40;
		} elseif ( $this->is_free_mailbox( $email_domain ) ) {
			// Plausible for a very small business, but weak evidence.
			$score += 5;
		} else {
			// A third-party domain — often an agency, supplier or directory.
			$score -= 15;
		}

		$score += match ( $candidate->source ) {
			// A Cloudflare-protected address *is* a mailto link, just encoded, so it carries
			// the same weight.
			'mailto', 'cloudflare' => 25,
			'json_ld'              => 20,
			'obfuscated'           => 10,
			default                => 0,
		};

		$score += match ( $page_kind ) {
			'contact' => 15,
			'about'   => 8,
			'home'    => 4,
			default   => 0,
		};

		if ( $candidate->is_role_account ) {
			$score -= 20;
		}

		return (int) max( 0, min( 100, $score ) );
	}

	/** @param array<string,Email_Candidate> $found */
	private function add( array &$found, string $email, string $source, string $site_domain, string $page_kind ): void {
		$email = $this->normalize( $email );

		if ( '' === $email || ! $this->is_plausible( $email ) ) {
			return;
		}

		$candidate = new Email_Candidate( $email, $source );
		$candidate->is_role_account = in_array( $candidate->local_part(), self::ROLE_ACCOUNTS, true );
		$candidate->confidence      = $this->score( $candidate, $site_domain, $page_kind );

		$key = strtolower( $email );

		// Keep the strongest evidence when the same address appears twice.
		if ( ! isset( $found[ $key ] ) || $candidate->confidence > $found[ $key ]->confidence ) {
			$found[ $key ] = $candidate;
		}
	}

	/** @return string[] */
	private function from_mailto( string $html ): array {
		if ( ! preg_match_all( '/mailto:\s*([^"\'>\s?&]+)/i', $html, $m ) ) {
			return [];
		}

		return array_map( 'rawurldecode', $m[1] );
	}

	/** @return string[] */
	private function from_json_ld( string $html ): array {
		if ( ! preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m ) ) {
			return [];
		}

		$emails = [];

		foreach ( $m[1] as $block ) {
			$data = json_decode( trim( $block ), true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			array_walk_recursive(
				$data,
				static function ( $value, $key ) use ( &$emails ): void {
					if ( is_string( $value ) && 'email' === strtolower( (string) $key ) ) {
						$emails[] = $value;
					}
				}
			);
		}

		return $emails;
	}

	/**
	 * Decode Cloudflare's email obfuscation.
	 *
	 * Cloudflare's "Email Address Obfuscation" is on by default on many plans, and it removes
	 * the mailto: from the HTML entirely — replacing it with a hex blob that its own
	 * JavaScript decodes in the browser. Without this, a large share of small business sites
	 * look as though they publish no email at all.
	 *
	 * The encoding is a single-byte XOR: the first octet is the key, the rest is the address.
	 *
	 * @return string[]
	 */
	private function from_cloudflare( string $html ): array {
		$hex = [];

		// <a class="__cf_email__" data-cfemail="…">
		if ( preg_match_all( '/data-cfemail\s*=\s*["\']([0-9a-f]+)["\']/i', $html, $m ) ) {
			$hex = array_merge( $hex, $m[1] );
		}

		// <a href="/cdn-cgi/l/email-protection#…">
		if ( preg_match_all( '#/cdn-cgi/l/email-protection\#([0-9a-f]+)#i', $html, $m ) ) {
			$hex = array_merge( $hex, $m[1] );
		}

		$emails = [];

		foreach ( array_unique( $hex ) as $encoded ) {
			$decoded = self::decode_cfemail( (string) $encoded );

			if ( '' !== $decoded ) {
				$emails[] = $decoded;
			}
		}

		return $emails;
	}

	/** Single-byte XOR, key first. Public so the behaviour can be asserted directly. */
	public static function decode_cfemail( string $hex ): string {
		// Two hex digits for the key plus at least a few for the address.
		if ( strlen( $hex ) < 8 || 0 !== strlen( $hex ) % 2 || ! ctype_xdigit( $hex ) ) {
			return '';
		}

		$bytes = @hex2bin( $hex ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! is_string( $bytes ) || strlen( $bytes ) < 4 ) {
			return '';
		}

		$key   = ord( $bytes[0] );
		$out   = '';
		$count = strlen( $bytes );

		for ( $i = 1; $i < $count; $i++ ) {
			$char = ord( $bytes[ $i ] ) ^ $key;

			// Anything outside printable ASCII means this was not an address.
			if ( $char < 32 || $char > 126 ) {
				return '';
			}

			$out .= chr( $char );
		}

		return $out;
	}

	/** @return string[] */
	private function from_text( string $text ): array {
		$pattern = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}/i';

		return preg_match_all( $pattern, $text, $m ) ? $m[0] : [];
	}

	/**
	 * Decode the common hand-rolled obfuscations before matching.
	 *
	 * @return string[]
	 */
	private function from_obfuscated( string $text ): array {
		$decoded = $text;

		$replacements = [
			'/\s*[\(\[\{<]\s*at\s*[\)\]\}>]\s*/i'  => '@',
			'/\s*[\(\[\{<]\s*dot\s*[\)\]\}>]\s*/i' => '.',
			'/\s+at\s+/i'                          => '@',
			'/\s+dot\s+/i'                         => '.',
			'/\s*&#64;\s*/'                        => '@',
			'/\s*&#46;\s*/'                        => '.',
			'/\s*%40\s*/i'                         => '@',
		];

		foreach ( $replacements as $pattern => $replacement ) {
			$decoded = preg_replace( $pattern, $replacement, $decoded ) ?? $decoded;
		}

		if ( $decoded === $text ) {
			return [];
		}

		return $this->from_text( $decoded );
	}

	private function normalize( string $email ): string {
		$email = html_entity_decode( trim( $email ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$email = trim( $email, " \t\n\r\x0B.,;:<>()[]{}\"'" );

		return strtolower( $email );
	}

	/** Reject anything that is syntactically an address but obviously not a mailbox. */
	private function is_plausible( string $email ): bool {
		if ( ! is_email( $email ) ) {
			return false;
		}

		if ( strlen( $email ) > 191 ) {
			return false;
		}

		$at     = strrpos( $email, '@' );
		$local  = substr( $email, 0, $at );
		$domain = substr( $email, $at + 1 );

		if ( in_array( $local, self::JUNK_LOCALS, true ) ) {
			return false;
		}

		foreach ( self::JUNK_DOMAINS as $junk ) {
			if ( $domain === $junk || str_ends_with( $domain, '.' . $junk ) ) {
				return false;
			}
		}

		// "logo@2x.png" and friends parse as an address but are image filenames.
		$tld = substr( $domain, (int) strrpos( $domain, '.' ) + 1 );

		if ( in_array( strtolower( $tld ), self::IMAGE_TLDS, true ) ) {
			return false;
		}

		// A hex string local part is a cache-busting hash, not a person.
		if ( preg_match( '/^[0-9a-f]{16,}$/i', $local ) ) {
			return false;
		}

		return true;
	}

	/** True when the email domain is the site's own, ignoring www and one subdomain level. */
	private function same_site( string $email_domain, string $site_domain ): bool {
		$email_domain = preg_replace( '/^www\./', '', strtolower( $email_domain ) ) ?? $email_domain;
		$site_domain  = preg_replace( '/^www\./', '', strtolower( $site_domain ) ) ?? $site_domain;

		if ( $email_domain === $site_domain ) {
			return true;
		}

		return str_ends_with( $site_domain, '.' . $email_domain )
			|| str_ends_with( $email_domain, '.' . $site_domain );
	}

	private function is_free_mailbox( string $domain ): bool {
		return in_array(
			$domain,
			[
				'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'hotmail.com',
				'outlook.com', 'live.com', 'msn.com', 'aol.com', 'icloud.com', 'me.com',
				'mail.com', 'gmx.com', 'protonmail.com', 'proton.me', 'yandex.com',
			],
			true
		);
	}

	/** Strip script, style and tags so their contents cannot be mined for addresses. */
	private function to_text( string $html ): string {
		$html = preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html ) ?? $html;
		$html = preg_replace( '#<[^>]+>#', ' ', $html ) ?? $html;

		return html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
