<?php
/**
 * The audit queue: one lead at a time, evidence beside the form.
 *
 * Triage answered "is this worth pitching". This answers "what do we say to them" — and the
 * note written here becomes the opening line of the outreach email, so everything the machine
 * found sits next to the textarea rather than a click away.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Admin\Screens;

use LeadMap\Ai\Gemini_Client;
use LeadMap\Audit\Audit_Service;
use LeadMap\Audit\Fit_Scorer;
use LeadMap\Audit\Issue_Tags;
use LeadMap\Support\Normalize;
use LeadMap\Triage\Screenshotter;

defined( 'ABSPATH' ) || exit;

final class Audit_Screen {

	public function render(): void {
		if ( ! current_user_can( 'leadmap_audit' ) ) {
			wp_die( esc_html__( 'You are not allowed to audit leads.', 'leadmap' ), 403 );
		}

		$service = new Audit_Service();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['lead'] ) ? absint( $_GET['lead'] ) : 0;

		$lead = $requested
			? \LeadMap\Leads\Lead_Repository::find( $requested )
			: ( $service->queue( 1 )[0] ?? null );

		$remaining = $service->queue_size();
		$counts    = $service->counts();

		?>
		<div class="wrap leadmap-wrap leadmap-audit">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Audit', 'leadmap' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap&status=audited' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Audited leads', 'leadmap' ); ?>
			</a>
			<hr class="wp-header-end" />

			<div class="leadmap-tiles">
				<div class="leadmap-tile">
					<span class="leadmap-tile__value"><?php echo esc_html( number_format( $remaining ) ); ?></span>
					<span class="leadmap-tile__label"><?php esc_html_e( 'Waiting', 'leadmap' ); ?></span>
				</div>
				<div class="leadmap-tile">
					<span class="leadmap-tile__value"><?php echo esc_html( number_format( $counts['audited'] ) ); ?></span>
					<span class="leadmap-tile__label"><?php esc_html_e( 'Audited', 'leadmap' ); ?></span>
				</div>
				<div class="leadmap-tile">
					<span class="leadmap-tile__value"><?php echo esc_html( number_format( $counts['not_a_fit'] ) ); ?></span>
					<span class="leadmap-tile__label"><?php esc_html_e( 'Not a fit', 'leadmap' ); ?></span>
				</div>
			</div>

			<?php
			if ( ! $lead ) {
				$this->render_empty();

				return;
			}

			$this->render_lead( $lead, $service );
			?>
		</div>
		<?php
	}

	private function render_empty(): void {
		?>
		<div class="leadmap-empty">
			<h2><?php esc_html_e( 'Nothing to audit', 'leadmap' ); ?></h2>
			<p><?php esc_html_e( 'Leads reach this queue once you have triaged them as worth pitching.', 'leadmap' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap&triage=pending' ) ); ?>">
					<?php esc_html_e( 'Leads awaiting triage', 'leadmap' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap-new-search' ) ); ?>">
					<?php esc_html_e( 'New search', 'leadmap' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	private function render_lead( object $lead, Audit_Service $service ): void {
		$enrichment = json_decode( (string) $lead->enrichment_json, true );
		$enrichment = is_array( $enrichment ) ? $enrichment : [];
		$signals    = json_decode( (string) $lead->staleness_json, true );
		$signals    = is_array( $signals ) ? $signals : [];

		$shots   = new Screenshotter();
		$desktop = $shots->for_lead( $lead, $enrichment, 'desktop' )['url'];
		$mobile  = $shots->for_lead( $lead, $enrichment, 'mobile' )['url'];

		$existing  = $service->find( (int) $lead->id );
		$triage    = array_filter( explode( ',', (string) $lead->triage_flags ) );
		$seeded    = Issue_Tags::from_triage( $triage );
		$evidenced = Issue_Tags::evidenced( $lead, $enrichment );

		$chosen = $existing
			? array_filter( explode( ',', (string) $existing->issue_tags ) )
			: $seeded;

		$fit = ( new Fit_Scorer() )->score( $lead, $enrichment );

		?>
		<div class="leadmap-audit__grid" data-leadmap-audit data-lead-id="<?php echo esc_attr( (string) (int) $lead->id ); ?>">

			<div class="leadmap-audit__evidence">
				<div class="leadmap-card">
					<h2>
						<?php echo esc_html( (string) $lead->name ); ?>
						<a class="leadmap-audit__open" href="<?php echo esc_url( (string) $lead->website ); ?>"
							target="_blank" rel="noopener noreferrer nofollow"><?php esc_html_e( 'Open site', 'leadmap' ); ?></a>
					</h2>

					<p class="leadmap-muted">
						<?php
						echo esc_html(
							implode(
								' · ',
								array_filter(
									[
										Normalize::humanize_type( (string) $lead->category ),
										(string) $lead->city,
										$lead->rating ? sprintf( '%s★ (%d)', number_format( (float) $lead->rating, 1 ), (int) $lead->review_count ) : '',
										(string) $lead->domain,
									]
								)
							)
						);
						?>
					</p>

					<?php if ( '' !== $desktop || '' !== $mobile ) : ?>
						<div class="leadmap-audit__shots">
							<?php if ( '' !== $desktop ) : ?>
								<img src="<?php echo esc_url( $desktop ); ?>" loading="lazy"
									alt="<?php echo esc_attr( sprintf( /* translators: %s: business name. */ __( 'Desktop view of %s', 'leadmap' ), (string) $lead->name ) ); ?>" />
							<?php endif; ?>
							<?php if ( '' !== $mobile ) : ?>
								<img class="leadmap-audit__shot-mobile" src="<?php echo esc_url( $mobile ); ?>" loading="lazy"
									alt="<?php echo esc_attr( sprintf( /* translators: %s: business name. */ __( 'Mobile view of %s', 'leadmap' ), (string) $lead->name ) ); ?>" />
							<?php endif; ?>
						</div>
					<?php else : ?>
						<p class="leadmap-muted"><?php esc_html_e( 'No screenshot — run a PageSpeed check on this lead.', 'leadmap' ); ?></p>
					<?php endif; ?>
				</div>

				<?php $this->render_signals( $lead, $enrichment, $signals ); ?>
				<?php $this->render_fit( $fit ); ?>
			</div>

			<form class="leadmap-audit__form leadmap-card" data-leadmap-audit-form>
				<h2><?php esc_html_e( 'What is wrong with it?', 'leadmap' ); ?></h2>

				<fieldset class="leadmap-audit__tags">
					<legend class="screen-reader-text"><?php esc_html_e( 'Problems', 'leadmap' ); ?></legend>
					<?php foreach ( Issue_Tags::all() as $tag => $meta ) : ?>
						<?php
						$on       = in_array( $tag, $chosen, true );
						$evidence = in_array( $tag, $evidenced, true );
						?>
						<label class="leadmap-verdict<?php echo $on ? ' is-on' : ''; ?><?php echo ! $on && $evidence ? ' is-suggested' : ''; ?>"
							title="<?php echo esc_attr( $evidence ? $meta['hint'] . ' — ' . __( 'the checks found evidence for this', 'leadmap' ) : $meta['hint'] ); ?>">
							<input type="checkbox" name="issue_tags[]" value="<?php echo esc_attr( $tag ); ?>" <?php checked( $on ); ?> />
							<kbd><?php echo esc_html( $meta['key'] ); ?></kbd><?php echo esc_html( $meta['label'] ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<p class="leadmap-audit__field">
					<label for="lm-primary"><strong><?php esc_html_e( 'Lead with', 'leadmap' ); ?></strong></label>
					<select name="primary_issue" id="lm-primary">
						<option value=""><?php esc_html_e( '— choose the one problem to open with —', 'leadmap' ); ?></option>
						<?php foreach ( Issue_Tags::all() as $tag => $meta ) : ?>
							<option value="<?php echo esc_attr( $tag ); ?>"
								<?php selected( $existing ? (string) $existing->primary_issue : ( $chosen[0] ?? '' ), $tag ); ?>>
								<?php echo esc_html( $meta['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'This chooses which outreach template is used.', 'leadmap' ); ?></span>
				</p>

				<p class="leadmap-audit__field">
					<label for="lm-notes">
						<strong><?php esc_html_e( 'What you would say to them', 'leadmap' ); ?></strong>
						<?php if ( ( new Gemini_Client() )->is_configured() ) : ?>
							<button type="button" class="button button-small leadmap-audit__draft" data-leadmap-draft>
								<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span>
								<?php esc_html_e( 'Draft it', 'leadmap' ); ?>
							</button>
						<?php endif; ?>
					</label>
					<textarea name="problem_notes" id="lm-notes" rows="5"
						placeholder="<?php esc_attr_e( 'e.g. the site takes nine seconds to load on a phone and the booking button is below three screens of text', 'leadmap' ); ?>"><?php
						echo esc_textarea( (string) ( $existing->problem_notes ?? '' ) );
					?></textarea>
					<span class="description">
						<?php esc_html_e( 'Written in your own words, and dropped into the email as {{problem}}. Be specific — this is the line that decides whether they reply.', 'leadmap' ); ?>
						<?php if ( ( new Gemini_Client() )->is_configured() ) : ?>
							<br />
							<?php esc_html_e( 'Draft it writes a first version from the measurements above. Read it before you send anything — it is a starting point, not a finished sentence.', 'leadmap' ); ?>
						<?php endif; ?>
					</span>
				</p>

				<p class="leadmap-audit__field">
					<label>
						<input type="checkbox" name="is_reachable" value="1"
							<?php checked( $existing ? (bool) $existing->is_reachable : ( '' !== (string) $lead->email ) ); ?> />
						<strong><?php esc_html_e( 'We can reach a decision-maker', 'leadmap' ); ?></strong>
					</label>
					<span class="description">
						<?php
						echo '' !== (string) $lead->email
							? esc_html( sprintf( /* translators: %s: email address. */ __( 'Found: %s', 'leadmap' ), (string) $lead->email ) )
							: esc_html__( 'No email was found — only a phone number.', 'leadmap' );
						?>
					</span>
				</p>

				<p class="leadmap-audit__field leadmap-audit__field--inline">
					<label for="lm-fit-override"><?php esc_html_e( 'Fit override', 'leadmap' ); ?></label>
					<input type="number" id="lm-fit-override" name="fit_override" class="small-text" min="0" max="100"
						value="<?php echo esc_attr( (string) ( $existing->fit_override ?? '' ) ); ?>"
						placeholder="<?php echo esc_attr( (string) $fit['score'] ); ?>" />
					<span class="description"><?php esc_html_e( 'Blank keeps the calculated score.', 'leadmap' ); ?></span>
				</p>

				<p class="leadmap-audit__status" data-leadmap-audit-status aria-live="polite"></p>

				<div class="leadmap-audit__actions">
					<button type="submit" class="button button-primary" data-outcome="audited">
						<?php esc_html_e( 'Save and next', 'leadmap' ); ?> <kbd><?php esc_html_e( 'Ctrl+Enter', 'leadmap' ); ?></kbd>
					</button>
					<button type="button" class="button" data-leadmap-not-a-fit>
						<?php esc_html_e( 'Not a fit', 'leadmap' ); ?>
					</button>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap-audit&skip=' . (int) $lead->id ) ); ?>">
						<?php esc_html_e( 'Skip for now', 'leadmap' ); ?>
					</a>
					<a class="leadmap-audit__detail" href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap-lead&lead=' . (int) $lead->id ) ); ?>">
						<?php esc_html_e( 'Full lead record', 'leadmap' ); ?>
					</a>
				</div>
			</form>
		</div>
		<?php
	}

	/** @param array<string,mixed> $enrichment */
	private function render_signals( object $lead, array $enrichment, array $signals ): void {
		$seo   = (array) ( $enrichment['seo'] ?? [] );
		$speed = (array) ( $enrichment['speed_mobile'] ?? [] );

		?>
		<div class="leadmap-card">
			<h2><?php esc_html_e( 'What the checks found', 'leadmap' ); ?></h2>

			<ul class="leadmap-signals">
				<?php if ( null !== ( $speed['score'] ?? null ) ) : ?>
					<li>
						<span class="leadmap-signal__weight"><?php echo esc_html( (string) (int) $speed['score'] ); ?></span>
						<?php esc_html_e( 'Google mobile speed score', 'leadmap' ); ?>
						<?php if ( null !== ( $speed['lcp_ms'] ?? null ) ) : ?>
							<span class="leadmap-muted">
								<?php
								printf(
									/* translators: %s: largest contentful paint in seconds. */
									esc_html__( '— largest element appears after %ss', 'leadmap' ),
									esc_html( number_format( (float) $speed['lcp_ms'] / 1000, 1 ) )
								);
								?>
							</span>
						<?php endif; ?>
					</li>
				<?php endif; ?>

				<?php foreach ( array_slice( $signals, 0, 8 ) as $signal ) : ?>
					<li>
						<span class="leadmap-signal__weight"><?php echo esc_html( (string) (int) ( $signal['weight'] ?? 0 ) ); ?></span>
						<?php echo esc_html( (string) ( $signal['label'] ?? '' ) ); ?>
					</li>
				<?php endforeach; ?>

				<?php if ( ! $signals && null === ( $speed['score'] ?? null ) ) : ?>
					<li class="leadmap-muted"><?php esc_html_e( 'Nothing recorded — this lead may not be fully enriched.', 'leadmap' ); ?></li>
				<?php endif; ?>
			</ul>

			<?php if ( ! empty( $seo['title'] ) ) : ?>
				<p class="leadmap-muted leadmap-audit__title">
					<strong><?php esc_html_e( 'Their page title:', 'leadmap' ); ?></strong>
					<?php echo esc_html( (string) $seo['title'] ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array{score:int,factors:array<string,mixed>} $fit */
	private function render_fit( array $fit ): void {
		$labels = Fit_Scorer::labels();
		$band   = $fit['score'] >= 65 ? 'low' : ( $fit['score'] >= 40 ? 'mid' : 'high' );

		?>
		<div class="leadmap-card">
			<h2><?php esc_html_e( 'Fit', 'leadmap' ); ?></h2>

			<div class="leadmap-score leadmap-score--<?php echo esc_attr( 'low' === $band ? 'low' : ( 'mid' === $band ? 'mid' : 'high' ) ); ?>">
				<?php echo esc_html( (string) $fit['score'] ); ?><span>/100</span>
			</div>
			<p class="leadmap-muted"><?php esc_html_e( 'How promising a prospect this is, not how broken the site is.', 'leadmap' ); ?></p>

			<table class="leadmap-breakdown">
				<tbody>
					<?php foreach ( $labels as $key => $label ) : ?>
						<?php $factor = $fit['factors'][ $key ] ?? null; ?>
						<?php if ( ! is_array( $factor ) ) { continue; } ?>
						<tr>
							<th scope="row">
								<?php echo esc_html( $label ); ?>
								<span class="leadmap-breakdown__weight"><?php echo esc_html( (string) $factor['weight'] ); ?>%</span>
							</th>
							<td class="leadmap-breakdown__summary"><?php echo esc_html( (string) $factor['summary'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
