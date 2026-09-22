<?php
/**
 * The New Search form: industry + location + ZIP, with a live cost estimate.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Admin\Screens;

use LeadMap\Jobs\Job_Runner;
use LeadMap\Jobs\Scheduler;
use LeadMap\Plugin;
use LeadMap\Search\Search_Query;
use LeadMap\Search\Search_Repository;
use LeadMap\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class New_Search_Screen {

	private const NONCE = 'leadmap_new_search';

	public function render(): void {
		if ( ! current_user_can( 'leadmap_search' ) ) {
			wp_die( esc_html__( 'You are not allowed to run searches.', 'leadmap' ), 403 );
		}

		$error = '';

		if ( isset( $_POST['leadmap_submit'] ) ) {
			$error = $this->handle_submit();
		}

		$registry  = Plugin::instance()->providers();
		$providers = $registry->choices();
		$spend     = Search_Repository::spend_this_month();
		$cap       = (float) Settings::get( 'monthly_spend_cap', 0 );

		?>
		<div class="wrap leadmap-wrap">
			<h1><?php esc_html_e( 'New Search', 'leadmap' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Search Google Maps for businesses by industry and location. Results are deduplicated against leads you already hold.', 'leadmap' ); ?>
			</p>

			<?php if ( $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>

			<div class="leadmap-search-layout">
			<form method="post" class="leadmap-form">
				<?php wp_nonce_field( self::NONCE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lm-industry"><?php esc_html_e( 'Industry or niche', 'leadmap' ); ?> <span class="required">*</span></label></th>
						<td>
							<input name="industry" id="lm-industry" type="text" class="regular-text" required
								placeholder="<?php esc_attr_e( 'dentists, plumbers, law firms…', 'leadmap' ); ?>"
								value="<?php echo esc_attr( $this->posted( 'industry' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Whatever you would type into Google Maps.', 'leadmap' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lm-location"><?php esc_html_e( 'City / area', 'leadmap' ); ?></label></th>
						<td>
							<input name="location" id="lm-location" type="text" class="regular-text"
								placeholder="<?php esc_attr_e( 'New York, NY', 'leadmap' ); ?>"
								value="<?php echo esc_attr( $this->posted( 'location' ) ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lm-zip"><?php esc_html_e( 'ZIP / postal code', 'leadmap' ); ?></label></th>
						<td>
							<input name="zip" id="lm-zip" type="text" class="small-text"
								placeholder="10001" value="<?php echo esc_attr( $this->posted( 'zip' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'A ZIP gives tighter, more useful results than a city name alone. Provide at least one of city or ZIP.', 'leadmap' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lm-radius"><?php esc_html_e( 'Radius', 'leadmap' ); ?></label></th>
						<td>
							<select name="radius_m" id="lm-radius">
								<?php
								$radii = [
									1000  => __( '1 km — a few blocks', 'leadmap' ),
									5000  => __( '5 km — a neighbourhood', 'leadmap' ),
									10000 => __( '10 km — a district', 'leadmap' ),
									25000 => __( '25 km — a metro area', 'leadmap' ),
									50000 => __( '50 km — the widest Google allows', 'leadmap' ),
								];

								$selected_radius = (int) ( $this->posted( 'radius_m' ) ?: Settings::get( 'default_radius_m', 5000 ) );

								foreach ( $radii as $metres => $label ) :
									?>
									<option value="<?php echo esc_attr( (string) $metres ); ?>" <?php selected( $selected_radius, $metres ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lm-max"><?php esc_html_e( 'Maximum results', 'leadmap' ); ?></label></th>
						<td>
							<input name="max_results" id="lm-max" type="number" min="20" max="300" step="20" class="small-text"
								value="<?php echo esc_attr( (string) ( $this->posted( 'max_results' ) ?: Settings::get( 'default_max_results', 60 ) ) ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Google returns 20 per page and caps a single query at roughly 60. Higher numbers cost more and rarely return more.', 'leadmap' ); ?>
							</p>
						</td>
					</tr>
					<?php if ( count( $providers ) > 1 ) : ?>
						<tr>
							<th scope="row"><label for="lm-provider"><?php esc_html_e( 'Provider', 'leadmap' ); ?></label></th>
							<td>
								<select name="provider" id="lm-provider">
									<?php foreach ( $providers as $id => $label ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( Settings::get( 'provider' ), $id ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php else : ?>
						<input type="hidden" name="provider" value="<?php echo esc_attr( (string) Settings::get( 'provider', 'google_places' ) ); ?>" />
					<?php endif; ?>
				</table>

				<div class="leadmap-cost-box">
					<strong><?php esc_html_e( 'Estimated API cost', 'leadmap' ); ?></strong>
					<p>
						<?php
						$estimate = $registry->get( (string) Settings::get( 'provider', 'google_places' ) )
							?->cost_estimate( (int) Settings::get( 'default_max_results', 60 ) ) ?? 0.0;

						printf(
							/* translators: 1: estimated cost, 2: spend so far this month. */
							esc_html__( 'About $%1$s for this search. Spent so far this month: $%2$s.', 'leadmap' ),
							esc_html( number_format( $estimate, 2 ) ),
							esc_html( number_format( $spend, 2 ) )
						);

						if ( $cap > 0 ) {
							echo ' ';
							printf(
								/* translators: %s: the monthly cap. */
								esc_html__( 'Monthly cap: $%s.', 'leadmap' ),
								esc_html( number_format( $cap, 2 ) )
							);
						}
						?>
					</p>
				</div>

				<?php submit_button( __( 'Run search', 'leadmap' ), 'primary', 'leadmap_submit' ); ?>
			</form>

			<aside class="leadmap-map-panel">
				<h2><?php esc_html_e( 'Search area', 'leadmap' ); ?></h2>
				<div id="leadmap-map" class="leadmap-map"></div>
				<p id="leadmap-map-status" class="leadmap-map__status"></p>
				<p class="description">
					<?php esc_html_e( 'The circle is the area Google is asked to prioritise. Results just outside it can still appear — it is a bias, not a hard boundary.', 'leadmap' ); ?>
				</p>
			</aside>
			</div>
		</div>
		<?php
	}

	/** @return string Error message, empty on success. */
	private function handle_submit(): string {
		check_admin_referer( self::NONCE );

		if ( ! current_user_can( 'leadmap_search' ) ) {
			return __( 'You are not allowed to run searches.', 'leadmap' );
		}

		$industry = sanitize_text_field( wp_unslash( $_POST['industry'] ?? '' ) );
		$location = sanitize_text_field( wp_unslash( $_POST['location'] ?? '' ) );
		$zip      = sanitize_text_field( wp_unslash( $_POST['zip'] ?? '' ) );
		$provider = sanitize_key( wp_unslash( $_POST['provider'] ?? 'google_places' ) );
		$radius   = absint( $_POST['radius_m'] ?? 5000 );
		$max      = absint( $_POST['max_results'] ?? 60 );

		if ( '' === $industry ) {
			return __( 'Enter an industry or niche to search for.', 'leadmap' );
		}

		if ( '' === $location && '' === $zip ) {
			return __( 'Enter a city or a ZIP code so the search has somewhere to look.', 'leadmap' );
		}

		$registry = Plugin::instance()->providers();

		if ( ! $registry->get( $provider ) ) {
			return __( 'That provider is not available.', 'leadmap' );
		}

		if ( ! $registry->get( $provider )->is_configured() ) {
			return __( 'The provider has no API key configured. Add one under LeadMap → Settings.', 'leadmap' );
		}

		$radius = max( 1000, min( 50000, $radius ) );
		$max    = max( 20, min( 300, $max ) );

		$query = new Search_Query(
			industry: $industry,
			location: $location,
			zip: $zip,
			radius_m: $radius,
			max_results: $max,
			region_code: (string) Settings::get( 'region_code', 'US' ),
			language_code: (string) Settings::get( 'language_code', 'en' ),
		);

		$label = trim( $industry . ' — ' . ( $zip ?: $location ) );

		$search_id = Search_Repository::insert(
			[
				'label'       => $label,
				'industry'    => $industry,
				'location'    => $location,
				'zip'         => $zip,
				'radius_m'    => $radius,
				'provider'    => $provider,
				'max_results' => $max,
				'params_json' => wp_json_encode( $query->to_array() ),
				'status'      => 'queued',
			]
		);

		if ( ! $search_id ) {
			return __( 'The search could not be saved. Check the database tables are installed.', 'leadmap' );
		}

		Scheduler::enqueue( Job_Runner::SEARCH_RUN, [ $search_id ] );

		wp_safe_redirect( admin_url( 'admin.php?page=leadmap-searches&watch=' . $search_id ) );
		exit;
	}

	private function posted( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- redisplay only; the write path verifies.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}
}
