<?php
/**
 * Settings, including write-only API key storage.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Admin\Screens;

use LeadMap\Enrich\Speed_Analyzer;
use LeadMap\Providers\Google_Places_Provider;
use LeadMap\Search\Search_Query;
use LeadMap\Support\Encryption;
use LeadMap\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class Settings_Screen {

	private const NONCE = 'leadmap_settings';

	public function render(): void {
		if ( ! current_user_can( 'leadmap_settings' ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'leadmap' ), 403 );
		}

		$message = '';
		$test    = '';

		if ( isset( $_POST['leadmap_save'] ) ) {
			$message = $this->save();
		}

		if ( isset( $_POST['leadmap_test'] ) ) {
			check_admin_referer( self::NONCE );
			$test = $this->test_key();
		}

		$from_constant = Settings::key_is_from_constant();
		$stored_key    = Settings::google_api_key();

		?>
		<div class="wrap leadmap-wrap">
			<h1><?php esc_html_e( 'LeadMap Settings', 'leadmap' ); ?></h1>

			<?php if ( $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<?php if ( $test ) : ?>
				<div class="notice notice-info"><p><?php echo wp_kses_post( $test ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>

				<h2><?php esc_html_e( 'Google API', 'leadmap' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lm-key"><?php esc_html_e( 'API key', 'leadmap' ); ?></label></th>
						<td>
							<?php if ( $from_constant ) : ?>
								<p>
									<code><?php echo esc_html( Encryption::mask( $stored_key ) ); ?></code><br />
									<span class="description">
										<?php esc_html_e( 'Set via the LEADMAP_GOOGLE_API_KEY constant in wp-config.php. This is the recommended setup — the key never touches the database. Remove the constant to manage it here instead.', 'leadmap' ); ?>
									</span>
								</p>
							<?php else : ?>
								<input type="password" name="google_api_key" id="lm-key" class="regular-text" autocomplete="off"
									placeholder="<?php echo esc_attr( $stored_key ? Encryption::mask( $stored_key ) : __( 'Paste your key', 'leadmap' ) ); ?>" />
								<p class="description">
									<?php esc_html_e( 'Stored encrypted and never shown again. Leave blank to keep the current key.', 'leadmap' ); ?>
									<br />
									<?php esc_html_e( 'Better still: add define( \'LEADMAP_GOOGLE_API_KEY\', \'...\' ); to wp-config.php so it stays out of the database.', 'leadmap' ); ?>
								</p>
							<?php endif; ?>

							<p class="description">
								<strong><?php esc_html_e( 'The key needs these APIs enabled:', 'leadmap' ); ?></strong>
								<?php esc_html_e( 'Places API (New), Geocoding API. Restrict it by your server\'s IP address — not by HTTP referrer.', 'leadmap' ); ?>
							</p>

							<?php submit_button( __( 'Test connection', 'leadmap' ), 'secondary', 'leadmap_test', false ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lm-region"><?php esc_html_e( 'Region', 'leadmap' ); ?></label></th>
						<td>
							<input type="text" name="region_code" id="lm-region" class="small-text" maxlength="2"
								value="<?php echo esc_attr( (string) Settings::get( 'region_code', 'US' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Two-letter country code. Biases results and sets the phone country code.', 'leadmap' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Search defaults', 'leadmap' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lm-default-radius"><?php esc_html_e( 'Default radius (metres)', 'leadmap' ); ?></label></th>
						<td>
							<input type="number" name="default_radius_m" id="lm-default-radius" class="small-text" min="1000" max="50000" step="1000"
								value="<?php echo esc_attr( (string) Settings::get( 'default_radius_m', 5000 ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lm-default-max"><?php esc_html_e( 'Default maximum results', 'leadmap' ); ?></label></th>
						<td>
							<input type="number" name="default_max_results" id="lm-default-max" class="small-text" min="20" max="300" step="20"
								value="<?php echo esc_attr( (string) Settings::get( 'default_max_results', 60 ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lm-cap"><?php esc_html_e( 'Monthly spend cap (USD)', 'leadmap' ); ?></label></th>
						<td>
							<input type="number" name="monthly_spend_cap" id="lm-cap" class="small-text" min="0" step="1"
								value="<?php echo esc_attr( (string) Settings::get( 'monthly_spend_cap', 50 ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Searches stop once estimated spend reaches this. Set 0 to disable. This is a plugin-side estimate — set a hard quota cap in Google Cloud too.', 'leadmap' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Enrichment', 'leadmap' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic enrichment', 'leadmap' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto_enrich" value="1" <?php checked( (bool) Settings::get( 'auto_enrich', true ) ); ?> />
								<?php esc_html_e( 'Crawl each new lead\'s website to find email addresses and check the site', 'leadmap' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Turn this off to collect leads quickly and enrich a selection later from the Leads screen.', 'leadmap' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'PageSpeed scores', 'leadmap' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto_pagespeed" value="1" <?php checked( (bool) Settings::get( 'auto_pagespeed', true ) ); ?> />
								<?php esc_html_e( 'Measure each reachable site with Google PageSpeed Insights (mobile and desktop)', 'leadmap' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Free, but each site is measured twice — once as a phone, once as a desktop — and each run takes up to a minute, so both go through a separate background queue. Enable the PageSpeed Insights API on your Google Cloud project for a higher rate limit.', 'leadmap' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'leadmap' ), 'primary', 'leadmap_save' ); ?>
			</form>
		</div>
		<?php
	}

	private function save(): string {
		check_admin_referer( self::NONCE );

		if ( ! current_user_can( 'leadmap_settings' ) ) {
			return '';
		}

		$values = [
			'region_code'         => strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['region_code'] ?? 'US' ) ), 0, 2 ) ),
			'default_radius_m'    => max( 1000, min( 50000, absint( $_POST['default_radius_m'] ?? 5000 ) ) ),
			'default_max_results' => max( 20, min( 300, absint( $_POST['default_max_results'] ?? 60 ) ) ),
			'monthly_spend_cap'   => max( 0, (float) ( $_POST['monthly_spend_cap'] ?? 0 ) ),
			'auto_enrich'         => ! empty( $_POST['auto_enrich'] ),
			'auto_pagespeed'      => ! empty( $_POST['auto_pagespeed'] ),
		];

		// An empty field means "keep the existing key", so we never clear it by accident.
		$submitted_key = trim( (string) wp_unslash( $_POST['google_api_key'] ?? '' ) );

		if ( '' !== $submitted_key && ! Settings::key_is_from_constant() ) {
			$values['google_api_key'] = Encryption::encrypt( sanitize_text_field( $submitted_key ) );
		}

		Settings::update( $values );

		return __( 'Settings saved.', 'leadmap' );
	}

	/**
	 * Check both APIs, not just one. Geocoding alone passing is misleading — the key can be
	 * valid and still be blocked from Places, which is the call that actually matters.
	 */
	private function test_key(): string {
		$provider = new Google_Places_Provider();

		if ( ! $provider->is_configured() ) {
			return esc_html__( 'No API key is set, so there is nothing to test.', 'leadmap' );
		}

		$region = (string) Settings::get( 'region_code', 'US' );
		$lines  = [];
		$failed = false;

		$geocode = $provider->geocode( '10001', $region );

		if ( is_wp_error( $geocode ) ) {
			$failed  = true;
			$lines[] = '<strong>' . esc_html__( 'Geocoding API: failed.', 'leadmap' ) . '</strong> '
				. esc_html( $geocode->get_error_message() );
		} else {
			$lines[] = '<strong>' . esc_html__( 'Geocoding API: working.', 'leadmap' ) . '</strong>';
		}

		// One real Text Search, asking for a single result to keep the cost at one call.
		$search = $provider->search(
			new Search_Query(
				industry: 'coffee',
				location: 'New York, NY',
				max_results: 1,
				region_code: $region,
				language_code: (string) Settings::get( 'language_code', 'en' ),
			)
		);

		if ( is_wp_error( $search ) ) {
			$failed  = true;
			$lines[] = '<strong>' . esc_html__( 'Places API (New): failed.', 'leadmap' ) . '</strong> '
				. esc_html( $search->get_error_message() );
		} else {
			$lines[] = '<strong>' . esc_html__( 'Places API (New): working.', 'leadmap' ) . '</strong> '
				. esc_html(
					sprintf(
						/* translators: %d: number of businesses returned by the test search. */
						_n( 'Test search returned %d business.', 'Test search returned %d businesses.', $search->count(), 'leadmap' ),
						$search->count()
					)
				);
		}

		// PageSpeed is a separate API with its own enablement, and it is the one that most
		// often fails silently, so the test covers it too.
		$psi = ( new Speed_Analyzer() )->analyze( 'https://example.com/', 'mobile' );

		if ( is_wp_error( $psi ) ) {
			$failed  = true;
			$lines[] = '<strong>' . esc_html__( 'PageSpeed Insights: failed.', 'leadmap' ) . '</strong> '
				. esc_html( $psi->get_error_message() );
		} else {
			$lines[] = '<strong>' . esc_html__( 'PageSpeed Insights: working.', 'leadmap' ) . '</strong> '
				. esc_html(
					sprintf(
						/* translators: %d: the test score returned for example.com. */
						__( 'Test measurement returned a score of %d.', 'leadmap' ),
						(int) ( $psi['score'] ?? 0 )
					)
				);
		}

		if ( ! $failed ) {
			$lines[] = esc_html__( 'All three APIs are reachable. You are ready to run a search.', 'leadmap' );
		}

		return implode( '<br />', $lines );
	}
}
