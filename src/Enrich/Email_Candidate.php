<?php
/**
 * One email address found on a site, with the evidence behind it.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Enrich;

defined( 'ABSPATH' ) || exit;

final class Email_Candidate {

	public function __construct(
		public readonly string $email,
		public string $source = 'text',
		public int $confidence = 0,
		public bool $is_role_account = false,
	) {}

	public function domain(): string {
		$at = strrpos( $this->email, '@' );

		return false === $at ? '' : strtolower( substr( $this->email, $at + 1 ) );
	}

	public function local_part(): string {
		$at = strrpos( $this->email, '@' );

		return false === $at ? $this->email : substr( $this->email, 0, $at );
	}
}
