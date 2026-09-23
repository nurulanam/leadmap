<?php
/**
 * The triage grid.
 *
 * Built for throughput above everything else: a screenshot to judge from, the automated
 * badges beside it, and a verdict one keystroke away. This is the stage that decides how
 * many leads an operator can get through in an hour, so it never blocks on the network and
 * never asks for anything it does not strictly need.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Admin\Screens;

use LeadMap\Support\Normalize;
use LeadMap\Triage\Screenshotter;
use LeadMap\Triage\Triage_Service;
use LeadMap\Triage\Verdicts;

defined( 'ABSPATH' ) || exit;

final class Triage_Screen {

	private const PER_PAGE = 12;

	public function render(): void {
		if ( ! current_user_can( 'leadmap_audit' ) ) {
			wp_die( esc_html__( 'You are not allowed to triage leads.', 'leadmap' ), 403 );
		}

		$service = new Triage_Service();
		$shots   = new Screenshotter();

		$leads     = $service->queue( self::PER_PAGE );
		$remaining = $service->queue_size();
		$pending   = $service->pending_enrichment();
		$counts    = $service->counts();

		?>
		<div class="wrap leadmap-wrap leadmap-triage">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Triage', 'leadmap' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap&triage=skipped' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Review skipped', 'leadmap' ); ?>
			</a>
			<hr class="wp-header-end" />

			<p class="description leadmap-triage__intro">
				<?php esc_html_e( 'Is this site old or broken enough to be worth pitching? Three seconds each. The deep audit only ever sees what you pass through here.', 'leadmap' ); ?>
				<?php esc_html_e( 'Dotted outlines are what the automated checks found — confirm or overrule them.', 'leadmap' ); ?>
			</p>

			<div class="leadmap-triage__bar">
				<div class="leadmap-tiles">
					<div class="leadmap-tile">
						<span class="leadmap-tile__value" data-leadmap-remaining><?php echo esc_html( number_format( $remaining ) ); ?></span>
						<span class="leadmap-tile__label"><?php esc_html_e( 'Waiting', 'leadmap' ); ?></span>
					</div>
					<div class="leadmap-tile">
						<span class="leadmap-tile__value"><?php echo esc_html( number_format( array_sum( $counts ) ) ); ?></span>
						<span class="leadmap-tile__label"><?php esc_html_e( 'Triaged', 'leadmap' ); ?></span>
					</div>
					<div class="leadmap-tile">
						<span class="leadmap-tile__value"><?php echo esc_html( number_format( (int) ( $counts['skip'] ?? 0 ) ) ); ?></span>
						<span class="leadmap-tile__label"><?php esc_html_e( 'Skipped', 'leadmap' ); ?></span>
					</div>
					<?php if ( $pending > 0 ) : ?>
						<div class="leadmap-tile">
							<span class="leadmap-tile__value"><?php echo esc_html( number_format( $pending ) ); ?></span>
							<span class="leadmap-tile__label"><?php esc_html_e( 'Still enriching', 'leadmap' ); ?></span>
						</div>
					<?php endif; ?>
				</div>

				<button type="button" class="button leadmap-triage__help-toggle" aria-expanded="false">
					<?php esc_html_e( 'Keyboard shortcuts', 'leadmap' ); ?>
				</button>
			</div>

			<div class="leadmap-triage__help" hidden>
				<ul>
					<?php foreach ( Verdicts::flags() as $id => $flag ) : ?>
						<li><kbd><?php echo esc_html( $flag['key'] ); ?></kbd> <?php echo esc_html( $flag['label'] ); ?></li>
					<?php endforeach; ?>
					<?php foreach ( Verdicts::terminal() as $id => $verdict ) : ?>
						<li><kbd><?php echo esc_html( $verdict['key'] ); ?></kbd> <?php echo esc_html( $verdict['label'] ); ?></li>
					<?php endforeach; ?>
					<li><kbd>A</kbd> <?php esc_html_e( 'Accept the suggested flags', 'leadmap' ); ?></li>
					<li><kbd>Enter</kbd> <?php esc_html_e( 'Save and move on', 'leadmap' ); ?></li>
					<li><kbd>&larr;</kbd> <kbd>&rarr;</kbd> <?php esc_html_e( 'Move between cards', 'leadmap' ); ?></li>
					<li><kbd>Ctrl</kbd>+<kbd>Z</kbd> <?php esc_html_e( 'Undo the last verdict', 'leadmap' ); ?></li>
				</ul>
			</div>

			<?php if ( ! $leads ) : ?>
				<?php $this->render_empty( $pending ); ?>
			<?php else : ?>
				<div class="leadmap-grid" data-leadmap-grid>
					<?php foreach ( $leads as $index => $lead ) : ?>
						<?php $this->render_card( $lead, $shots, 0 === $index ); ?>
					<?php endforeach; ?>
				</div>

				<p class="leadmap-triage__more">
					<?php
					printf(
						/* translators: %s: how many leads are still waiting. */
						esc_html__( '%s leads still waiting. Clear this page and the next loads automatically.', 'leadmap' ),
						'<strong data-leadmap-remaining>' . esc_html( number_format( $remaining ) ) . '</strong>'
					);
					?>
				</p>
			<?php endif; ?>

			<div class="leadmap-toast" data-leadmap-toast hidden role="status" aria-live="polite">
				<span data-leadmap-toast-text></span>
				<button type="button" class="button-link" data-leadmap-undo><?php esc_html_e( 'Undo', 'leadmap' ); ?></button>
			</div>
		</div>
		<?php
	}

	private function render_empty( int $pending ): void {
		?>
		<div class="leadmap-empty">
			<?php if ( $pending > 0 ) : ?>
				<h2><?php esc_html_e( 'Nothing to triage yet', 'leadmap' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %d: leads still being enriched. */
						esc_html__( '%d leads are still being enriched. A lead can only be triaged once its website has been checked — reload in a minute.', 'leadmap' ),
						$pending
					);
					?>
				</p>
			<?php else : ?>
				<h2><?php esc_html_e( 'Queue clear', 'leadmap' ); ?></h2>
				<p><?php esc_html_e( 'Every enriched lead has a verdict. Run another search to find more.', 'leadmap' ); ?></p>
			<?php endif; ?>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap-new-search' ) ); ?>">
					<?php esc_html_e( 'New search', 'leadmap' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap' ) ); ?>">
					<?php esc_html_e( 'All leads', 'leadmap' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	private function render_card( object $lead, Screenshotter $shots, bool $is_first ): void {
		$enrichment = json_decode( (string) $lead->enrichment_json, true );
		$enrichment = is_array( $enrichment ) ? $enrichment : [];
		$signals    = json_decode( (string) $lead->staleness_json, true );
		$signals    = is_array( $signals ) ? $signals : [];

		$website     = (string) $lead->website;
		$desktop_src = $shots->for_lead( $lead, $enrichment, 'desktop' );
		$mobile_src  = $shots->for_lead( $lead, $enrichment, 'mobile' );

		$desktop = $desktop_src['url'];
		// Never show a cropped desktop capture as though it were a phone view.
		$mobile  = $mobile_src['real'] ? $mobile_src['url'] : '';
		$score   = null === $lead->staleness_score ? null : (int) $lead->staleness_score;
		$psi     = $enrichment['speed_mobile']['score'] ?? null;

		?>
		<article class="leadmap-card-triage<?php echo $is_first ? ' is-current' : ''; ?>"
			data-leadmap-card
			data-lead-id="<?php echo esc_attr( (string) (int) $lead->id ); ?>"
			tabindex="0"
			aria-label="<?php echo esc_attr( (string) $lead->name ); ?>">

			<div class="leadmap-shot">
				<?php if ( '' === $website ) : ?>
					<div class="leadmap-shot__none">
						<strong><?php esc_html_e( 'No website', 'leadmap' ); ?></strong>
						<span><?php esc_html_e( 'Nothing to look at — press N', 'leadmap' ); ?></span>
					</div>
				<?php elseif ( '' !== $desktop ) : ?>
					<img class="leadmap-shot__desktop" src="<?php echo esc_url( $desktop ); ?>"
						alt="<?php echo esc_attr( sprintf( /* translators: %s: business name. */ __( 'Desktop view of %s', 'leadmap' ), (string) $lead->name ) ); ?>"
						loading="lazy" data-leadmap-shot />
					<?php if ( '' !== $mobile ) : ?>
						<img class="leadmap-shot__mobile" src="<?php echo esc_url( $mobile ); ?>"
							alt="<?php echo esc_attr( sprintf( /* translators: %s: business name. */ __( 'Mobile view of %s', 'leadmap' ), (string) $lead->name ) ); ?>"
							loading="lazy" data-leadmap-shot />
					<?php endif; ?>
				<?php else : ?>
					<div class="leadmap-shot__pending">
						<strong>
							<?php
							echo esc_html(
								$desktop_src['pending']
									? __( 'Screenshot on the way', 'leadmap' )
									: __( 'No screenshot', 'leadmap' )
							);
							?>
						</strong>
						<span>
							<?php
							echo esc_html(
								$desktop_src['pending']
									? __( 'Captured during the PageSpeed check', 'leadmap' )
									: __( 'Run a speed check to capture one', 'leadmap' )
							);
							?>
						</span>
						<a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer nofollow">
							<?php esc_html_e( 'Open the site', 'leadmap' ); ?>
						</a>
					</div>
				<?php endif; ?>

				<?php if ( null !== $score ) : ?>
					<span class="leadmap-shot__score leadmap-pill leadmap-pill--<?php echo esc_attr( $score >= 60 ? 'high' : ( $score >= 30 ? 'mid' : 'low' ) ); ?>"
						title="<?php esc_attr_e( 'Staleness score — higher means more outdated', 'leadmap' ); ?>">
						<?php echo esc_html( (string) $score ); ?>
					</span>
				<?php endif; ?>
			</div>

			<header class="leadmap-card-triage__head">
				<strong class="leadmap-card-triage__name"><?php echo esc_html( (string) $lead->name ); ?></strong>
				<span class="leadmap-muted">
					<?php
					echo esc_html(
						implode(
							' · ',
							array_filter(
								[
									Normalize::humanize_type( (string) $lead->category ),
									(string) $lead->city,
								]
							)
						)
					);
					?>
				</span>
			</header>

			<div class="leadmap-card-triage__signals">
				<?php if ( null !== $psi ) : ?>
					<span class="leadmap-speed leadmap-speed--<?php echo esc_attr( (int) $psi < 50 ? 'high' : ( (int) $psi < 90 ? 'mid' : 'low' ) ); ?>">
						<?php esc_html_e( 'PSI', 'leadmap' ); ?><b><?php echo esc_html( (string) (int) $psi ); ?></b>
					</span>
				<?php endif; ?>

				<?php
				$shown = 0;

				foreach ( $signals as $signal ) :
					if ( $shown >= 3 ) {
						break;
					}

					++$shown;
					?>
					<span class="leadmap-badge"><?php echo esc_html( (string) ( $signal['label'] ?? '' ) ); ?></span>
				<?php endforeach; ?>

				<?php if ( count( $signals ) > 3 ) : ?>
					<span class="leadmap-badge leadmap-badge--more">
						<?php
						printf(
							/* translators: %d: how many further signals there are. */
							esc_html__( '+%d more', 'leadmap' ),
							count( $signals ) - 3
						);
						?>
					</span>
				<?php endif; ?>
			</div>

			<div class="leadmap-card-triage__actions">
				<?php foreach ( Verdicts::flags() as $id => $flag ) : ?>
					<?php $is_suggested = in_array( $id, $suggested, true ); ?>
					<button type="button" class="leadmap-verdict<?php echo $is_suggested ? ' is-suggested' : ''; ?>"
						data-verdict="<?php echo esc_attr( $id ); ?>"
						<?php echo $is_suggested ? 'data-suggested="1"' : ''; ?>
						title="<?php echo esc_attr( $is_suggested ? $flag['hint'] . ' — ' . __( 'the automated checks point at this', 'leadmap' ) : $flag['hint'] ); ?>"
						aria-pressed="false">
						<kbd><?php echo esc_html( $flag['key'] ); ?></kbd><?php echo esc_html( $flag['label'] ); ?>
					</button>
				<?php endforeach; ?>
			</div>

			<div class="leadmap-card-triage__terminal">
				<?php foreach ( Verdicts::terminal() as $id => $verdict ) : ?>
					<button type="button" class="leadmap-verdict leadmap-verdict--terminal" data-terminal="<?php echo esc_attr( $id ); ?>"
						title="<?php echo esc_attr( $verdict['hint'] ); ?>">
						<kbd><?php echo esc_html( $verdict['key'] ); ?></kbd><?php echo esc_html( $verdict['label'] ); ?>
					</button>
				<?php endforeach; ?>

				<a class="leadmap-card-triage__open" href="<?php echo esc_url( $website ?: admin_url( 'admin.php?page=leadmap-lead&lead=' . (int) $lead->id ) ); ?>"
					target="_blank" rel="noopener noreferrer nofollow">
					<?php esc_html_e( 'Open site', 'leadmap' ); ?>
				</a>

				<button type="button" class="leadmap-card-triage__save button button-primary" data-leadmap-save>
					<?php esc_html_e( 'Save', 'leadmap' ); ?> <kbd>&crarr;</kbd>
				</button>
			</div>
		</article>
		<?php
	}
}
