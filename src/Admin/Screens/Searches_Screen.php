<?php
/**
 * Search history with live status.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Admin\Screens;

use LeadMap\Jobs\Job_Runner;
use LeadMap\Jobs\Scheduler;
use LeadMap\Search\Search_Repository;

defined( 'ABSPATH' ) || exit;

final class Searches_Screen {

	public function render(): void {
		if ( ! current_user_can( 'leadmap_search' ) ) {
			wp_die( esc_html__( 'You are not allowed to view searches.', 'leadmap' ), 403 );
		}

		$this->handle_actions();

		$page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = Search_Repository::query( $page, 20 );
		$active = false;

		foreach ( $result['items'] as $search ) {
			if ( in_array( $search->status, [ 'queued', 'running' ], true ) ) {
				$active = true;
				break;
			}
		}

		?>
		<div class="wrap leadmap-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Searches', 'leadmap' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap-new-search' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'New Search', 'leadmap' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$watch = isset( $_GET['watch'] ) ? absint( $_GET['watch'] ) : 0;

			if ( $watch && Search_Repository::find( $watch ) ) {
				$this->render_live( $watch );
			} elseif ( $active ) {
				?>
				<p class="leadmap-running">
					<span class="spinner is-active"></span>
					<?php esc_html_e( 'A search is running.', 'leadmap' ); ?>
					<?php
					foreach ( $result['items'] as $candidate ) {
						if ( in_array( $candidate->status, [ 'queued', 'running' ], true ) ) {
							printf(
								'<a href="%s">%s</a>',
								esc_url( admin_url( 'admin.php?page=leadmap-searches&watch=' . (int) $candidate->id ) ),
								esc_html__( 'Watch it live', 'leadmap' )
							);

							break;
						}
					}
					?>
				</p>
				<?php
			}
			?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Search', 'leadmap' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'leadmap' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Found', 'leadmap' ); ?></th>
						<th scope="col"><?php esc_html_e( 'New', 'leadmap' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Cost', 'leadmap' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Run', 'leadmap' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'leadmap' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $result['items'] ) : ?>
						<tr>
							<td colspan="7">
								<?php esc_html_e( 'No searches yet.', 'leadmap' ); ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap-new-search' ) ); ?>"><?php esc_html_e( 'Run your first one.', 'leadmap' ); ?></a>
							</td>
						</tr>
					<?php endif; ?>

					<?php foreach ( $result['items'] as $search ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( $search->label ?: $search->industry ); ?></strong>
								<div class="row-actions">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: industry, 2: location, 3: radius in km. */
											__( '%1$s · %2$s · %3$s km radius', 'leadmap' ),
											$search->industry,
											$search->zip ?: $search->location,
											number_format( (int) $search->radius_m / 1000, 1 )
										)
									);
									?>
								</div>
							</td>
							<td>
								<span class="leadmap-status leadmap-status--<?php echo esc_attr( $search->status ); ?>">
									<?php echo esc_html( ucfirst( (string) $search->status ) ); ?>
								</span>
								<?php if ( 'failed' === $search->status && $search->error ) : ?>
									<div class="leadmap-error"><?php echo esc_html( (string) $search->error ); ?></div>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $search->results_found ); ?></td>
							<td>
								<?php if ( (int) $search->results_new > 0 ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap&search_id=' . (int) $search->id ) ); ?>">
										<?php echo esc_html( (string) $search->results_new ); ?>
									</a>
								<?php else : ?>
									0
								<?php endif; ?>
							</td>
							<td>$<?php echo esc_html( number_format( (float) $search->api_cost, 2 ) ); ?></td>
							<td>
								<?php
								echo esc_html(
									$search->created_at
										? mysql2date( 'M j, Y H:i', $search->created_at )
										: '—'
								);
								?>
							</td>
							<td>
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap-searches&watch=' . (int) $search->id ) ); ?>">
									<?php echo esc_html( in_array( $search->status, [ 'queued', 'running' ], true ) ? __( 'Watch', 'leadmap' ) : __( 'Log', 'leadmap' ) ); ?>
								</a>
								<?php if ( in_array( $search->status, [ 'queued', 'running' ], true ) ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $this->action_url( 'stop', (int) $search->id ) ); ?>">
										<?php esc_html_e( 'Stop', 'leadmap' ); ?>
									</a>
								<?php endif; ?>
								<?php if ( in_array( $search->status, [ 'failed', 'complete', 'cancelled' ], true ) ) : ?>
									<a class="button button-small" href="<?php echo esc_url( $this->action_url( 'rerun', (int) $search->id ) ); ?>">
										<?php esc_html_e( 'Re-run', 'leadmap' ); ?>
									</a>
								<?php endif; ?>
								<a class="button button-small button-link-delete"
									href="<?php echo esc_url( $this->action_url( 'delete', (int) $search->id ) ); ?>"
									onclick="return confirm('<?php echo esc_js( __( 'Delete this search record? Leads already collected are kept.', 'leadmap' ) ); ?>');">
									<?php esc_html_e( 'Delete', 'leadmap' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$pages = (int) ceil( $result['total'] / 20 );

			if ( $pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">';
				echo wp_kses_post(
					paginate_links(
						[
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $page,
							'total'   => $pages,
						]
					)
				);
				echo '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * The live console, as a modal.
	 *
	 * A search is a chain of background jobs, so without this the screen sits still and looks
	 * broken. A modal rather than an inline panel because the log wants room: full height for
	 * the output, and the table behind it stays where it was when the modal closes.
	 */
	private function render_live( int $search_id ): void {
		$search  = Search_Repository::find( $search_id );
		$log     = Search_Repository::get_log( $search_id );
		$running = in_array( (string) $search->status, [ 'queued', 'running' ], true );
		$close   = admin_url( 'admin.php?page=leadmap-searches' );

		?>
		<div class="leadmap-modal" id="leadmap-live-modal" data-close-url="<?php echo esc_url( $close ); ?>">
			<div class="leadmap-modal__backdrop" data-leadmap-close></div>

			<div class="leadmap-modal__box" role="dialog" aria-modal="true" aria-labelledby="leadmap-live-title">
				<div id="leadmap-live"
					class="leadmap-live<?php echo $running ? ' is-running' : ''; ?><?php echo 'failed' === $search->status ? ' is-failed' : ''; ?>">

					<div class="leadmap-modal__head">
						<span class="leadmap-live__spinner" aria-hidden="true"></span>
						<div class="leadmap-modal__titles">
							<strong class="leadmap-live__state" id="leadmap-live-title">
								<?php
								echo esc_html(
									$running
										? __( 'Searching…', 'leadmap' )
										: ( 'failed' === $search->status ? __( 'Search failed', 'leadmap' ) : __( 'Search complete', 'leadmap' ) )
								);
								?>
							</strong>
							<span class="leadmap-live__title"><?php echo esc_html( (string) ( $search->label ?: $search->industry ) ); ?></span>
						</div>

						<a href="<?php echo esc_url( $close ); ?>" class="leadmap-modal__close"
							data-leadmap-close aria-label="<?php esc_attr_e( 'Close', 'leadmap' ); ?>">&times;</a>
					</div>

					<div class="leadmap-modal__body">
						<div class="leadmap-live__bar<?php echo $running ? '' : ' is-done'; ?>">
							<span style="width: <?php echo esc_attr( (string) $this->percent( $search ) ); ?>%"></span>
						</div>

						<p class="leadmap-live__stats">
							<?php
							printf(
								/* translators: 1: new leads, 2: total results, 3: cost. */
								esc_html__( '%1$s new leads · %2$s results seen · $%3$s', 'leadmap' ),
								esc_html( (string) (int) $search->results_new ),
								esc_html( (string) (int) $search->results_found ),
								esc_html( number_format( (float) $search->api_cost, 2 ) )
							);
							?>
						</p>

						<div class="leadmap-live__log" role="log" aria-live="polite">
							<?php foreach ( $log as $entry ) : ?>
								<div class="leadmap-live__line is-<?php echo esc_attr( (string) ( $entry['level'] ?? 'info' ) ); ?>">
									<span class="leadmap-live__time"><?php echo esc_html( gmdate( 'H:i:s', (int) ( $entry['at'] ?? 0 ) ) ); ?></span>
									<span><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>

						<p class="leadmap-live__hint" <?php echo $running ? '' : 'hidden'; ?>>
							<?php esc_html_e( 'Keep this open and the search keeps moving. You can close it — it carries on in the background either way.', 'leadmap' ); ?>
						</p>
					</div>

					<div class="leadmap-modal__foot">
						<p class="leadmap-live__done" <?php echo $running ? 'hidden' : ''; ?>>
							<a class="button button-primary"
								href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap&search_id=' . $search_id ) ); ?>"
								<?php echo (int) $search->results_new > 0 ? '' : 'hidden'; ?>>
								<?php esc_html_e( 'View the leads', 'leadmap' ); ?>
							</a>
							<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap-new-search' ) ); ?>">
								<?php esc_html_e( 'Run another search', 'leadmap' ); ?>
							</a>
						</p>

						<button type="button" class="button leadmap-live__stop" <?php echo $running ? '' : 'hidden'; ?>>
							<?php esc_html_e( 'Stop search', 'leadmap' ); ?>
						</button>

						<a href="<?php echo esc_url( $close ); ?>" class="button" data-leadmap-close>
							<?php esc_html_e( 'Close', 'leadmap' ); ?>
						</a>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/** Results against the cap — the only honest progress Google gives us. */
	private function percent( object $search ): int {
		if ( ! in_array( (string) $search->status, [ 'queued', 'running' ], true ) ) {
			return 100;
		}

		$max = max( 1, (int) $search->max_results );

		return (int) max( 5, min( 100, round( ( (int) $search->results_found / $max ) * 100 ) ) );
	}

	private function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['lm_action'] ) ? sanitize_key( wp_unslash( $_GET['lm_action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $action || ! $id ) {
			return;
		}

		check_admin_referer( 'leadmap_search_' . $action . '_' . $id );

		if ( ! current_user_can( 'leadmap_search' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'leadmap' ), 403 );
		}

		if ( 'delete' === $action ) {
			Search_Repository::delete( $id );
		}

		if ( 'stop' === $action ) {
			$search = Search_Repository::find( $id );

			if ( $search && in_array( $search->status, [ 'queued', 'running' ], true ) ) {
				Search_Repository::cancel( $id );
				Scheduler::cancel( Job_Runner::SEARCH_RUN, [ $id ] );
				Search_Repository::log(
					$id,
					sprintf(
						/* translators: %d: leads collected before stopping. */
						__( 'Stopped by you — %d leads collected so far have been kept', 'leadmap' ),
						(int) $search->results_new
					),
					'warn'
				);
			}
		}

		if ( 'rerun' === $action ) {
			Search_Repository::update(
				$id,
				[
					'status'          => 'queued',
					'error'           => null,
					'next_page_token' => null,
					'results_found'   => 0,
					'pages_fetched'   => 0,
					'completed_at'    => null,
				]
			);

			Search_Repository::update( $id, [ 'log_json' => null ] );
			Scheduler::enqueue( Job_Runner::SEARCH_RUN, [ $id ] );

			wp_safe_redirect( admin_url( 'admin.php?page=leadmap-searches&watch=' . $id ) );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=leadmap-searches' ) );
		exit;
	}

	private function action_url( string $action, int $id ): string {
		return wp_nonce_url(
			admin_url( 'admin.php?page=leadmap-searches&lm_action=' . $action . '&id=' . $id ),
			'leadmap_search_' . $action . '_' . $id
		);
	}
}
