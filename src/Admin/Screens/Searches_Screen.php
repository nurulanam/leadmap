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

			<?php if ( isset( $_GET['started'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Search queued. Results appear below as pages come back — this page refreshes itself while a search is running.', 'leadmap' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $active ) : ?>
				<meta http-equiv="refresh" content="8" />
				<p class="leadmap-running"><span class="spinner is-active"></span> <?php esc_html_e( 'A search is running. Refreshing every 8 seconds…', 'leadmap' ); ?></p>
			<?php endif; ?>

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
								<?php if ( in_array( $search->status, [ 'failed', 'complete' ], true ) ) : ?>
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

			Scheduler::enqueue( Job_Runner::SEARCH_RUN, [ $id ] );
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
