<?php
/**
 * Everything known about one lead: contact details, every email found, the automated
 * signals behind its staleness score, and its activity trail.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Admin\Screens;

use LeadMap\Events\Event_Repository;
use LeadMap\Jobs\Job_Runner;
use LeadMap\Leads\Lead_Email_Repository;
use LeadMap\Leads\Lead_Repository;
use LeadMap\Support\Normalize;

defined( 'ABSPATH' ) || exit;

final class Lead_Detail_Screen {

	public function render(): void {
		if ( ! current_user_can( 'leadmap_manage' ) ) {
			wp_die( esc_html__( 'You are not allowed to view leads.', 'leadmap' ), 403 );
		}

		$this->handle_actions();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$lead_id = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;
		$lead    = $lead_id ? Lead_Repository::find( $lead_id ) : null;

		if ( ! $lead ) {
			?>
			<div class="wrap leadmap-wrap">
				<h1><?php esc_html_e( 'Lead not found', 'leadmap' ); ?></h1>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap' ) ); ?>"><?php esc_html_e( 'Back to leads', 'leadmap' ); ?></a></p>
			</div>
			<?php
			return;
		}

		$enrichment = json_decode( (string) $lead->enrichment_json, true );
		$enrichment = is_array( $enrichment ) ? $enrichment : [];
		$signals    = json_decode( (string) $lead->staleness_json, true );
		$signals    = is_array( $signals ) ? $signals : [];
		$emails     = Lead_Email_Repository::for_lead( $lead_id );
		$events     = Event_Repository::for_lead( $lead_id, 30 );
		$statuses   = Lead_Repository::statuses();

		?>
		<div class="wrap leadmap-wrap leadmap-detail">
			<h1 class="wp-heading-inline"><?php echo esc_html( (string) $lead->name ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Back to leads', 'leadmap' ); ?>
			</a>
			<a href="<?php echo esc_url( $this->action_url( 'enrich', $lead_id ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Re-enrich', 'leadmap' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php $this->render_notice(); ?>

			<div class="leadmap-detail__grid">
				<div class="leadmap-detail__main">

					<div class="leadmap-card">
						<h2><?php esc_html_e( 'Contact', 'leadmap' ); ?></h2>
						<table class="widefat striped">
							<tbody>
								<tr>
									<th scope="row"><?php esc_html_e( 'Status', 'leadmap' ); ?></th>
									<td>
										<span class="leadmap-status leadmap-status--<?php echo esc_attr( (string) $lead->status ); ?>">
											<?php echo esc_html( $statuses[ $lead->status ] ?? (string) $lead->status ); ?>
										</span>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Phone', 'leadmap' ); ?></th>
									<td>
										<?php if ( $lead->phone ) : ?>
											<a href="tel:<?php echo esc_attr( (string) ( $lead->phone_e164 ?: $lead->phone ) ); ?>">
												<?php echo esc_html( (string) $lead->phone ); ?>
											</a>
										<?php else : ?>
											<span class="leadmap-muted"><?php esc_html_e( 'None', 'leadmap' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Website', 'leadmap' ); ?></th>
									<td>
										<?php if ( $lead->website ) : ?>
											<a href="<?php echo esc_url( (string) $lead->website ); ?>" target="_blank" rel="noopener noreferrer nofollow">
												<?php echo esc_html( (string) $lead->website ); ?>
											</a>
										<?php else : ?>
											<span class="leadmap-muted"><?php esc_html_e( 'No website', 'leadmap' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Address', 'leadmap' ); ?></th>
									<td><?php echo esc_html( (string) $lead->address ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Category', 'leadmap' ); ?></th>
									<td><?php echo esc_html( Normalize::humanize_type( (string) $lead->category ) ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Rating', 'leadmap' ); ?></th>
									<td>
										<?php
										echo $lead->rating
											? esc_html( number_format( (float) $lead->rating, 1 ) . ' (' . (int) $lead->review_count . ' reviews)' )
											: '—';
										?>
									</td>
								</tr>
								<?php if ( $lead->maps_url ) : ?>
									<tr>
										<th scope="row"><?php esc_html_e( 'Google Maps', 'leadmap' ); ?></th>
										<td><a href="<?php echo esc_url( (string) $lead->maps_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open listing', 'leadmap' ); ?></a></td>
									</tr>
								<?php endif; ?>
							</tbody>
						</table>
					</div>

					<div class="leadmap-card">
						<h2>
							<?php esc_html_e( 'Emails found', 'leadmap' ); ?>
							<span class="leadmap-muted">(<?php echo count( $emails ); ?>)</span>
						</h2>

						<?php if ( ! $emails ) : ?>
							<p class="leadmap-muted">
								<?php esc_html_e( 'No email address was found on the website. The lead is still reachable by phone.', 'leadmap' ); ?>
							</p>
						<?php else : ?>
							<table class="widefat striped">
								<thead>
									<tr>
										<th scope="col"><?php esc_html_e( 'Address', 'leadmap' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Confidence', 'leadmap' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Found in', 'leadmap' ); ?></th>
										<th scope="col"><?php esc_html_e( 'Primary', 'leadmap' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $emails as $email ) : ?>
										<tr>
											<td>
												<a href="mailto:<?php echo esc_attr( (string) $email->email ); ?>"><?php echo esc_html( (string) $email->email ); ?></a>
												<?php if ( $email->is_role_account ) : ?>
													<span class="leadmap-muted"><?php esc_html_e( '(role account)', 'leadmap' ); ?></span>
												<?php endif; ?>
											</td>
											<td>
												<div class="leadmap-meter" title="<?php echo esc_attr( (string) (int) $email->confidence ); ?>">
													<span style="width: <?php echo esc_attr( (string) (int) $email->confidence ); ?>%"></span>
												</div>
												<?php echo esc_html( (string) (int) $email->confidence ); ?>
											</td>
											<td><?php echo esc_html( str_replace( '_', ' ', (string) $email->source ) ); ?></td>
											<td>
												<?php if ( $lead->email === $email->email ) : ?>
													<strong><?php esc_html_e( 'Primary', 'leadmap' ); ?></strong>
												<?php else : ?>
													<a href="<?php echo esc_url( $this->action_url( 'primary', $lead_id, (string) $email->email ) ); ?>">
														<?php esc_html_e( 'Make primary', 'leadmap' ); ?>
													</a>
												<?php endif; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>

					<div class="leadmap-card">
						<h2><?php esc_html_e( 'Activity', 'leadmap' ); ?></h2>
						<?php if ( ! $events ) : ?>
							<p class="leadmap-muted"><?php esc_html_e( 'Nothing recorded yet.', 'leadmap' ); ?></p>
						<?php else : ?>
							<ul class="leadmap-timeline">
								<?php foreach ( $events as $event ) : ?>
									<li>
										<code><?php echo esc_html( (string) $event->type ); ?></code>
										<span class="leadmap-muted"><?php echo esc_html( mysql2date( 'M j, Y H:i', (string) $event->created_at ) ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>

				<div class="leadmap-detail__side">
					<div class="leadmap-card">
						<h2><?php esc_html_e( 'Staleness', 'leadmap' ); ?></h2>

						<?php if ( null === $lead->staleness_score ) : ?>
							<p class="leadmap-muted"><?php esc_html_e( 'Not enriched yet.', 'leadmap' ); ?></p>
						<?php else : ?>
							<div class="leadmap-score leadmap-score--<?php echo esc_attr( $this->band( (int) $lead->staleness_score ) ); ?>">
								<?php echo esc_html( (string) (int) $lead->staleness_score ); ?><span>/100</span>
							</div>
							<p class="leadmap-muted"><?php esc_html_e( 'Higher means more outdated, so more worth pitching.', 'leadmap' ); ?></p>

							<?php if ( $signals ) : ?>
								<ul class="leadmap-signals">
									<?php foreach ( $signals as $signal ) : ?>
										<li>
											<span class="leadmap-signal__weight">+<?php echo esc_html( (string) (int) ( $signal['weight'] ?? 0 ) ); ?></span>
											<?php echo esc_html( (string) ( $signal['label'] ?? '' ) ); ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						<?php endif; ?>
					</div>

					<?php $this->render_speed( $enrichment ); ?>
					<?php $this->render_seo( $enrichment ); ?>
					<?php $this->render_tech( $enrichment ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * PageSpeed, laid out the way Insights itself does it: one gauge per strategy, with the
	 * same colour thresholds (red under 50, amber under 90, green above).
	 *
	 * @param array<string,mixed> $enrichment
	 */
	private function render_speed( array $enrichment ): void {
		$mobile  = is_array( $enrichment['speed_mobile'] ?? null ) ? $enrichment['speed_mobile'] : null;
		$desktop = is_array( $enrichment['speed_desktop'] ?? null ) ? $enrichment['speed_desktop'] : null;

		$errors = array_filter( [
			__( 'Mobile', 'leadmap' )  => (string) ( $enrichment['speed_mobile_error']['reason'] ?? '' ),
			__( 'Desktop', 'leadmap' ) => (string) ( $enrichment['speed_desktop_error']['reason'] ?? '' ),
		] );

		// Nothing measured and nothing failed means the job simply has not run yet. Say that,
		// rather than hiding the whole card and leaving the operator guessing.
		$pending = ! $mobile && ! $desktop && ! $errors;

		if ( $pending ) {
			?>
			<div class="leadmap-card">
				<h2><?php esc_html_e( 'Google PageSpeed', 'leadmap' ); ?></h2>
				<p class="leadmap-muted">
					<?php esc_html_e( 'Not measured yet. PageSpeed runs as a background job and takes up to a minute per site, twice per lead. If this stays empty, check that background jobs are running.', 'leadmap' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		?>
		<div class="leadmap-card">
			<h2><?php esc_html_e( 'Google PageSpeed', 'leadmap' ); ?></h2>

			<div class="leadmap-psi">
				<?php
				$this->render_gauge( __( 'Mobile', 'leadmap' ), $mobile );
				$this->render_gauge( __( 'Desktop', 'leadmap' ), $desktop );
				?>
			</div>

			<?php
			// Mobile metrics are the ones worth quoting — it is how most local searches happen.
			$detail = $mobile ?: $desktop;

			if ( $detail && null !== ( $detail['score'] ?? null ) ) :
				$ratings = (array) ( $detail['ratings'] ?? [] );

				$metrics = [
					'fcp_ms'      => [ __( 'First Contentful Paint', 'leadmap' ), 'fcp', 'ms' ],
					'lcp_ms'      => [ __( 'Largest Contentful Paint', 'leadmap' ), 'lcp', 'ms' ],
					'tbt_ms'      => [ __( 'Total Blocking Time', 'leadmap' ), 'tbt', 'raw_ms' ],
					'cls'         => [ __( 'Cumulative Layout Shift', 'leadmap' ), 'cls', 'ratio' ],
					'speed_index' => [ __( 'Speed Index', 'leadmap' ), 'si', 'ms' ],
				];
				?>
				<p class="leadmap-psi__label">
					<?php
					echo esc_html(
						$mobile
							? __( 'Core Web Vitals — mobile', 'leadmap' )
							: __( 'Core Web Vitals — desktop', 'leadmap' )
					);
					?>
				</p>
				<table class="widefat striped leadmap-psi__metrics">
					<tbody>
						<?php
						foreach ( $metrics as $key => [ $label, $rating_key, $format ] ) :
							if ( null === ( $detail[ $key ] ?? null ) ) {
								continue;
							}

							$rating = (string) ( $ratings[ $rating_key ] ?? '' );
							?>
							<tr>
								<th scope="row">
									<span class="leadmap-psi__dot leadmap-psi__dot--<?php echo esc_attr( $rating ?: 'unknown' ); ?>" aria-hidden="true"></span>
									<?php echo esc_html( $label ); ?>
								</th>
								<td class="leadmap-psi__value leadmap-psi__value--<?php echo esc_attr( $rating ?: 'unknown' ); ?>">
									<?php echo esc_html( $this->format_metric( (float) $detail[ $key ], $format ) ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $mobile && $desktop && null !== ( $mobile['score'] ?? null ) && null !== ( $desktop['score'] ?? null ) ) : ?>
				<?php $gap = (int) $desktop['score'] - (int) $mobile['score']; ?>
				<?php if ( $gap >= 25 ) : ?>
					<p class="leadmap-psi__note">
						<?php
						printf(
							/* translators: %d: how many points better the desktop score is. */
							esc_html__( 'Desktop scores %d points higher than mobile — the owner almost certainly checks the site on a laptop and has never seen how slow it is on a phone.', 'leadmap' ),
							$gap
						);
						?>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<?php foreach ( $errors as $label => $reason ) : ?>
				<div class="notice notice-warning inline leadmap-psi__error">
					<p>
						<strong><?php echo esc_html( sprintf( '%s:', $label ) ); ?></strong>
						<?php echo esc_html( $reason ); ?>
					</p>
				</div>
			<?php endforeach; ?>

			<?php $url = (string) ( $mobile['final_url'] ?? $desktop['final_url'] ?? '' ); ?>
			<?php if ( '' !== $url ) : ?>
				<p class="leadmap-psi__note">
					<a href="<?php echo esc_url( 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( $url ) ); ?>"
						target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Open the full report on pagespeed.web.dev', 'leadmap' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One PSI-style dial. Drawn as an SVG ring so it stays sharp and needs no images.
	 *
	 * @param array<string,mixed>|null $data
	 */
	private function render_gauge( string $label, ?array $data ): void {
		$score = is_array( $data ) ? ( $data['score'] ?? null ) : null;

		?>
		<div class="leadmap-gauge">
			<?php if ( null === $score ) : ?>
				<div class="leadmap-gauge__ring leadmap-gauge__ring--empty">
					<span class="leadmap-gauge__num">—</span>
				</div>
			<?php else : ?>
				<?php
				$score = (int) $score;
				$band  = $this->psi_band( $score );
				// Circumference of an r=52 circle, so the dash offset maps 0–100 onto the ring.
				$circumference = 326.7;
				$offset        = $circumference * ( 1 - ( $score / 100 ) );
				?>
				<div class="leadmap-gauge__ring leadmap-gauge__ring--<?php echo esc_attr( $band ); ?>">
					<svg viewBox="0 0 120 120" role="img"
						aria-label="<?php echo esc_attr( sprintf( '%s: %d out of 100', $label, $score ) ); ?>">
						<circle class="leadmap-gauge__track" cx="60" cy="60" r="52" />
						<circle class="leadmap-gauge__fill" cx="60" cy="60" r="52"
							stroke-dasharray="<?php echo esc_attr( (string) $circumference ); ?>"
							stroke-dashoffset="<?php echo esc_attr( (string) round( $offset, 1 ) ); ?>" />
					</svg>
					<span class="leadmap-gauge__num"><?php echo esc_html( (string) $score ); ?></span>
				</div>
			<?php endif; ?>
			<span class="leadmap-gauge__label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	private function format_metric( float $value, string $format ): string {
		return match ( $format ) {
			'ratio'  => number_format( $value, 3 ),
			'raw_ms' => number_format( $value ) . ' ms',
			default  => number_format( $value / 1000, 1 ) . ' s',
		};
	}

	/** @param array<string,mixed> $enrichment */
	private function render_seo( array $enrichment ): void {
		$seo = $enrichment['seo'] ?? null;

		if ( ! is_array( $seo ) ) {
			return;
		}

		$checks = [
			'is_https'        => __( 'HTTPS', 'leadmap' ),
			'has_viewport'    => __( 'Mobile viewport', 'leadmap' ),
			'has_title'       => __( 'Page title', 'leadmap' ),
			'has_description' => __( 'Meta description', 'leadmap' ),
			'has_single_h1'   => __( 'Single H1', 'leadmap' ),
			'has_schema'      => __( 'Schema markup', 'leadmap' ),
			'has_canonical'   => __( 'Canonical tag', 'leadmap' ),
			'has_open_graph'  => __( 'Open Graph tags', 'leadmap' ),
		];

		?>
		<div class="leadmap-card">
			<h2><?php esc_html_e( 'On-page SEO', 'leadmap' ); ?></h2>
			<ul class="leadmap-checks">
				<?php foreach ( $checks as $key => $label ) : ?>
					<?php $ok = ! empty( $seo[ $key ] ); ?>
					<li class="<?php echo $ok ? 'is-ok' : 'is-bad'; ?>">
						<span aria-hidden="true"><?php echo $ok ? '✓' : '✕'; ?></span>
						<?php echo esc_html( $label ); ?>
					</li>
				<?php endforeach; ?>
				<?php if ( ! empty( $seo['mixed_content'] ) ) : ?>
					<li class="is-bad"><span aria-hidden="true">✕</span> <?php esc_html_e( 'Mixed content', 'leadmap' ); ?></li>
				<?php endif; ?>
			</ul>
			<p class="leadmap-muted">
				<?php
				printf(
					/* translators: 1: images with alt text, 2: total images. */
					esc_html__( 'Alt text on %1$d of %2$d images.', 'leadmap' ),
					(int) ( $seo['images_with_alt'] ?? 0 ),
					(int) ( $seo['image_count'] ?? 0 )
				);

				if ( ! empty( $seo['copyright_year'] ) ) {
					echo ' ';
					printf(
						/* translators: %d: the copyright year found in the footer. */
						esc_html__( 'Footer copyright: %d.', 'leadmap' ),
						(int) $seo['copyright_year']
					);
				}
				?>
			</p>
		</div>
		<?php
	}

	/** @param array<string,mixed> $enrichment */
	private function render_tech( array $enrichment ): void {
		$tech = $enrichment['tech'] ?? null;

		if ( ! is_array( $tech ) ) {
			return;
		}

		?>
		<div class="leadmap-card">
			<h2><?php esc_html_e( 'Technology', 'leadmap' ); ?></h2>
			<table class="widefat striped">
				<tbody>
					<?php
					$rows = [
						__( 'Platform', 'leadmap' ) => $tech['cms'] ?: '—',
						__( 'Builder', 'leadmap' )  => $tech['builder'] ?: '—',
						__( 'jQuery', 'leadmap' )   => $tech['jquery_version'] ?: '—',
						__( 'Server', 'leadmap' )   => $tech['server'] ?: '—',
					];

					foreach ( $rows as $label => $value ) :
						?>
						<tr>
							<th scope="row"><?php echo esc_html( (string) $label ); ?></th>
							<td><?php echo esc_html( (string) $value ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function handle_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['lm_action'] ) ? sanitize_key( wp_unslash( $_GET['lm_action'] ) ) : '';
		$id     = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;
		// phpcs:enable

		if ( '' === $action || ! $id ) {
			return;
		}

		check_admin_referer( 'leadmap_lead_' . $action . '_' . $id );

		if ( 'enrich' === $action ) {
			Job_Runner::queue_enrich( $id );
			$this->redirect( $id, 'enrich_queued' );
		}

		if ( 'primary' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
			$email = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';

			if ( ! is_email( $email ) ) {
				$this->redirect( $id, 'bad_email' );
			}

			// Only accept an address we actually found for this lead.
			$known = false;

			foreach ( Lead_Email_Repository::for_lead( $id ) as $candidate ) {
				if ( strtolower( (string) $candidate->email ) === strtolower( $email ) ) {
					$known = true;

					Lead_Repository::update(
						$id,
						[
							'email'            => $candidate->email,
							'email_confidence' => (int) $candidate->confidence,
							'email_source'     => (string) $candidate->source,
						]
					);

					break;
				}
			}

			$this->redirect( $id, $known ? 'primary_set' : 'bad_email' );
		}
	}

	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$done = isset( $_GET['lm_done'] ) ? sanitize_key( wp_unslash( $_GET['lm_done'] ) ) : '';

		$messages = [
			'enrich_queued' => [ 'info', __( 'Enrichment queued. Reload in a moment to see the result.', 'leadmap' ) ],
			'primary_set'   => [ 'success', __( 'Primary email updated.', 'leadmap' ) ],
			'bad_email'     => [ 'error', __( 'That address is not one of the emails found for this lead.', 'leadmap' ) ],
		];

		if ( ! isset( $messages[ $done ] ) ) {
			return;
		}

		[ $type, $text ] = $messages[ $done ];

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $text )
		);
	}

	private function redirect( int $lead_id, string $done ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => 'leadmap-lead',
					'lead'    => $lead_id,
					'lm_done' => $done,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function action_url( string $action, int $lead_id, string $email = '' ): string {
		$args = [
			'page'      => 'leadmap-lead',
			'lead'      => $lead_id,
			'lm_action' => $action,
		];

		if ( '' !== $email ) {
			$args['email'] = $email;
		}

		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin.php' ) ),
			'leadmap_lead_' . $action . '_' . $lead_id
		);
	}

	private function band( int $score ): string {
		return $score >= 60 ? 'high' : ( $score >= 30 ? 'mid' : 'low' );
	}

	private function psi_band( int $score ): string {
		return $score < 50 ? 'high' : ( $score < 90 ? 'mid' : 'low' );
	}
}
