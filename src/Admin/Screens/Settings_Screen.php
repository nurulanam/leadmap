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
use LeadMap\Triage\Screenshot_Store;
use LeadMap\Support\Encryption;
use LeadMap\Support\Http;
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
						<th scope="row"><label for="lm-search-cap"><?php esc_html_e( 'Spend limit per search (USD)', 'leadmap' ); ?></label></th>
						<td>
							<input type="number" name="max_cost_per_search" id="lm-search-cap" class="small-text" min="0" step="0.05"
								value="<?php echo esc_attr( (string) Settings::get( 'max_cost_per_search', 0.50 ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'A single search stops once it has spent this much, whatever else it is doing. Set 0 to disable. A normal search costs about $0.11.', 'leadmap' ); ?>
							</p>
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
						<th scope="row"><label for="lm-psi-rate"><?php esc_html_e( 'PageSpeed requests per minute', 'leadmap' ); ?></label></th>
						<td>
							<input type="number" name="pagespeed_per_minute" id="lm-psi-rate" class="small-text" min="1" max="60" step="1"
								value="<?php echo esc_attr( (string) Settings::get( 'pagespeed_per_minute', 4 ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Requests are spaced to stay under Google\'s limit. Leave at 4 when calling PageSpeed without a key; 20 is comfortable once your key is accepted.', 'leadmap' ); ?>
								<br />
								<?php esc_html_e( 'This is a ceiling, not a promise: each measurement takes most of a minute, and background jobs only run when WordPress has something to run them. Keeping any LeadMap screen open lets the browser work through the queue directly, which is far faster than waiting on WP-Cron.', 'leadmap' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lm-psi-timeout"><?php esc_html_e( 'PageSpeed timeout', 'leadmap' ); ?></label></th>
						<td>
							<input type="number" name="pagespeed_timeout" id="lm-psi-timeout" class="small-text" min="30" max="180" step="10"
								value="<?php echo esc_attr( (string) Settings::get( 'pagespeed_timeout', 90 ) ); ?>" />
							<span><?php esc_html_e( 'seconds', 'leadmap' ); ?></span>
							<p class="description">
								<?php esc_html_e( 'Google loads and profiles the page in a real browser, so 30–60 seconds is normal and the slowest sites take longer — which is exactly the kind of lead worth having. Timeouts are retried automatically.', 'leadmap' ); ?>
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

				<h2><?php esc_html_e( 'Triage', 'leadmap' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Screenshots', 'leadmap' ); ?></th>
						<td>
							<?php $usage = Screenshot_Store::disk_usage(); ?>
							<p>
								<?php
								printf(
									/* translators: %s: disk space used, already formatted. */
									esc_html__( 'Captured during PageSpeed runs. %s on disk.', 'leadmap' ),
									esc_html( size_format( $usage, 1 ) ?: '0 B' )
								);
								?>
							</p>
							<p class="description">
								<?php esc_html_e( 'Google renders each page in a real browser at both viewports and returns the result, so a speed check produces a genuine desktop and mobile screenshot at no extra cost. There is nothing to configure and no third-party service involved. Captures live in uploads/leadmap-shots/ and are removed when a lead is deleted or skipped.', 'leadmap' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic triage', 'leadmap' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="auto_triage" value="1" <?php checked( (bool) Settings::get( 'auto_triage', false ) ); ?> />
								<?php esc_html_e( 'Decide the unambiguous cases without asking', 'leadmap' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'A dead domain becomes Broken, a lead with no website becomes No website, and a site that is fast, secure, mobile-ready and scores zero for staleness is skipped. Everything else still comes to you. Automatic verdicts are logged and can be undone.', 'leadmap' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Timeouts', 'leadmap' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lm-crawl-timeout"><?php esc_html_e( 'Per-request timeout', 'leadmap' ); ?></label></th>
						<td>
							<input type="number" name="crawl_timeout" id="lm-crawl-timeout" class="small-text" min="3" max="60" step="1"
								value="<?php echo esc_attr( (string) Settings::get( 'crawl_timeout', 10 ) ); ?>" />
							<span><?php esc_html_e( 'seconds', 'leadmap' ); ?></span>
							<p class="description">
								<?php esc_html_e( 'How long to wait for a single page. Raise it if slow sites are being missed; lower it to get through a large search faster.', 'leadmap' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lm-enrich-budget"><?php esc_html_e( 'Time budget per lead', 'leadmap' ); ?></label></th>
						<td>
							<input type="number" name="enrich_budget" id="lm-enrich-budget" class="small-text" min="10" max="300" step="5"
								value="<?php echo esc_attr( (string) Settings::get( 'enrich_budget', 60 ) ); ?>" />
							<span><?php esc_html_e( 'seconds', 'leadmap' ); ?></span>
							<p class="description">
								<?php esc_html_e( 'Total time for one lead\'s whole crawl. When it runs out, the home page is kept and the remaining contact pages are abandoned — a partial result beats a job that never ends.', 'leadmap' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lm-stuck"><?php esc_html_e( 'Treat as stuck after', 'leadmap' ); ?></label></th>
						<td>
							<input type="number" name="stuck_after_minutes" id="lm-stuck" class="small-text" min="5" max="1440" step="5"
								value="<?php echo esc_attr( (string) Settings::get( 'stuck_after_minutes', 15 ) ); ?>" />
							<span><?php esc_html_e( 'minutes', 'leadmap' ); ?></span>
							<p class="description">
								<?php esc_html_e( 'An hourly sweep rescues leads left sitting in "Enriching" — after a killed worker or a PHP timeout. The first stall is retried once; a second gives up and records why.', 'leadmap' ); ?>
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
			'max_cost_per_search' => max( 0, (float) ( $_POST['max_cost_per_search'] ?? 0 ) ),
			'auto_enrich'         => ! empty( $_POST['auto_enrich'] ),
			'auto_triage'         => ! empty( $_POST['auto_triage'] ),
			'auto_pagespeed'      => ! empty( $_POST['auto_pagespeed'] ),
			'pagespeed_per_minute' => max( 1, min( 60, absint( $_POST['pagespeed_per_minute'] ?? 4 ) ) ),
			'pagespeed_timeout'   => max( 30, min( 180, absint( $_POST['pagespeed_timeout'] ?? 90 ) ) ),
			'crawl_timeout'       => max( 3, min( 60, absint( $_POST['crawl_timeout'] ?? 10 ) ) ),
			'enrich_budget'       => max( 10, min( 300, absint( $_POST['enrich_budget'] ?? 60 ) ) ),
			'stuck_after_minutes' => max( 5, min( 1440, absint( $_POST['stuck_after_minutes'] ?? 15 ) ) ),
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
	/**
	 * Ask PageSpeed directly whether it accepts the key.
	 *
	 * @return string Google's reason for refusing it, or '' when the key is accepted.
	 */
	private function test_pagespeed_key(): string {
		$key = Settings::google_api_key();

		if ( '' === $key ) {
			return '';
		}

		$response = Http::get_json(
			'https://www.googleapis.com/pagespeedonline/v5/runPagespeed',
			[
				'url'      => 'https://example.com/',
				'strategy' => 'desktop',
				'category' => 'performance',
				'key'      => $key,
			],
			[],
			60
		);

		if ( ! is_wp_error( $response ) ) {
			return '';
		}

		$message = $response->get_error_message();

		// Only a key or permission problem counts here. A slow page or a rate limit is a
		// different conversation and must not be reported as a bad key.
		foreach ( [ 'api key', 'not authorized', 'blocked', 'has not been used', 'permission', 'forbidden', 'disabled' ] as $needle ) {
			if ( str_contains( strtolower( $message ), $needle ) ) {
				return $message;
			}
		}

		return '';
	}

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

		// PageSpeed is a separate API with its own enablement, and the one that most often
		// fails quietly. This checks the key directly rather than through the fallback, so a
		// refused key is reported as a refused key instead of a rate limit.
		$psi_key = $this->test_pagespeed_key();

		if ( '' !== $psi_key ) {
			$failed  = true;
			$lines[] = '<strong>' . esc_html__( 'PageSpeed Insights: your key was refused.', 'leadmap' ) . '</strong> '
				. esc_html( $psi_key ) . '<br />'
				. esc_html__( 'LeadMap will fall back to unauthenticated requests, which Google limits to a few per minute. Enable PageSpeed Insights API on the key\'s project and add it to the key\'s API restrictions; changes take a few minutes to apply.', 'leadmap' );
		} else {
			$psi = ( new Speed_Analyzer() )->analyze( 'https://example.com/', 'mobile' );

			if ( is_wp_error( $psi ) ) {
				$failed  = true;
				$lines[] = '<strong>' . esc_html__( 'PageSpeed Insights: failed.', 'leadmap' ) . '</strong> '
					. esc_html( $psi->get_error_message() );
			} else {
				$lines[] = '<strong>' . esc_html__( 'PageSpeed Insights: working, with your key.', 'leadmap' ) . '</strong> '
					. esc_html(
						sprintf(
							/* translators: %d: the test score returned for example.com. */
							__( 'Test measurement returned a score of %d.', 'leadmap' ),
							(int) ( $psi['score'] ?? 0 )
						)
					);
			}
		}

		if ( ! $failed ) {
			$lines[] = esc_html__( 'All three APIs are reachable. You are ready to run a search.', 'leadmap' );
		}

		return implode( '<br />', $lines );
	}
}
