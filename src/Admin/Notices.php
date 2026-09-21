<?php
/**
 * Admin notices: first-run compliance warning and the missing-key prompt.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Admin;

use LeadMap\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Notices {

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'welcome' ] );
		add_action( 'admin_notices', [ $this, 'missing_key' ] );
		add_action( 'admin_post_leadmap_dismiss_welcome', [ $this, 'dismiss_welcome' ] );
	}

	public function welcome(): void {
		if ( ! current_user_can( 'leadmap_manage' ) || ! get_transient( 'leadmap_show_welcome' ) ) {
			return;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=leadmap_dismiss_welcome' ),
			'leadmap_dismiss_welcome'
		);

		?>
		<div class="notice notice-info">
			<p><strong><?php esc_html_e( 'LeadMap is active.', 'leadmap' ); ?></strong></p>
			<p>
				<?php
				esc_html_e(
					'This plugin collects business data and will later send commercial email. Before sending anything, you are responsible for CAN-SPAM compliance: a real postal address in every message, a working unsubscribe link, and honest subject lines. GDPR and CASL add further requirements for EU and Canadian recipients.',
					'leadmap'
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap-settings' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Add your API key', 'leadmap' ); ?></a>
				<a href="<?php echo esc_url( $url ); ?>" class="button"><?php esc_html_e( 'Dismiss', 'leadmap' ); ?></a>
			</p>
		</div>
		<?php
	}

	public function missing_key(): void {
		if ( ! current_user_can( 'leadmap_manage' ) || '' !== Settings::google_api_key() ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! str_contains( (string) $screen->id, 'leadmap' ) ) {
			return;
		}

		if ( isset( $_GET['page'] ) && 'leadmap-settings' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'No Google API key is configured, so searches cannot run.', 'leadmap' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap-settings' ) ); ?>"><?php esc_html_e( 'Add one now.', 'leadmap' ); ?></a>
			</p>
		</div>
		<?php
	}

	public function dismiss_welcome(): void {
		if ( ! current_user_can( 'leadmap_manage' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'leadmap' ), 403 );
		}

		check_admin_referer( 'leadmap_dismiss_welcome' );

		delete_transient( 'leadmap_show_welcome' );

		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=leadmap' ) );
		exit;
	}
}
