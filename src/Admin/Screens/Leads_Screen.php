<?php
/**
 * The Leads screen — list table, bulk actions and stat tiles.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Admin\Screens;

use LeadMap\Admin\List_Tables\Leads_List_Table;
use LeadMap\Events\Event_Repository;
use LeadMap\Jobs\Job_Runner;
use LeadMap\Leads\Lead_Repository;
use LeadMap\Search\Search_Repository;

defined( 'ABSPATH' ) || exit;

final class Leads_Screen {

	private ?Leads_List_Table $table = null;

	/** Runs on load-{hook} so screen options and columns register before output. */
	public function load(): void {
		if ( ! current_user_can( 'leadmap_manage' ) ) {
			return;
		}

		$this->handle_actions();

		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Leads per page', 'leadmap' ),
				'default' => 25,
				'option'  => 'leadmap_leads_per_page',
			]
		);

		$this->table = new Leads_List_Table();
	}

	public function render(): void {
		if ( ! current_user_can( 'leadmap_manage' ) ) {
			wp_die( esc_html__( 'You are not allowed to view leads.', 'leadmap' ), 403 );
		}

		if ( ! $this->table ) {
			$this->table = new Leads_List_Table();
		}

		$this->table->prepare_items();

		$counts = Lead_Repository::status_counts();
		$total  = array_sum( $counts );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_id = isset( $_GET['search_id'] ) ? absint( $_GET['search_id'] ) : 0;

		?>
		<div class="wrap leadmap-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Leads', 'leadmap' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap-new-search' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'New Search', 'leadmap' ); ?>
			</a>
			<hr class="wp-header-end" />

			<?php $this->render_notice(); ?>

			<?php if ( $search_id ) : ?>
				<?php $search = Search_Repository::find( $search_id ); ?>
				<?php if ( $search ) : ?>
					<div class="notice notice-info">
						<p>
							<?php
							printf(
								/* translators: %s: the search label. */
								esc_html__( 'Showing leads from the search "%s".', 'leadmap' ),
								esc_html( (string) ( $search->label ?: $search->industry ) )
							);
							?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=leadmap' ) ); ?>"><?php esc_html_e( 'Show all leads', 'leadmap' ); ?></a>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="leadmap-tiles">
				<?php
				$tiles = [
					__( 'Total leads', 'leadmap' )  => $total,
					__( 'With email', 'leadmap' )   => $this->with_email_count(),
					__( 'With website', 'leadmap' ) => $this->with_website_count(),
					__( 'New', 'leadmap' )          => $counts['new'] ?? 0,
				];

				foreach ( $tiles as $label => $value ) :
					?>
					<div class="leadmap-tile">
						<span class="leadmap-tile__value"><?php echo esc_html( number_format( (int) $value ) ); ?></span>
						<span class="leadmap-tile__label"><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<form method="get">
				<input type="hidden" name="page" value="leadmap" />
				<?php if ( $search_id ) : ?>
					<input type="hidden" name="search_id" value="<?php echo esc_attr( (string) $search_id ); ?>" />
				<?php endif; ?>
				<?php
				$this->table->search_box( __( 'Search leads', 'leadmap' ), 'leadmap-lead-search' );
				wp_nonce_field( 'leadmap_bulk_leads', '_leadmap_nonce', false );
				$this->table->display();
				?>
			</form>
		</div>
		<?php
	}

	private function handle_actions(): void {
		$table  = new Leads_List_Table();
		$action = $table->current_action();

		// Single-row delete from the row actions link.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['lm_action'] ) && 'delete' === sanitize_key( wp_unslash( $_GET['lm_action'] ) ) ) {
			$id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			check_admin_referer( 'leadmap_lead_delete_' . $id );

			if ( $id ) {
				Lead_Repository::delete( [ $id ] );
				Event_Repository::log( 'lead.deleted', $id );
				$this->redirect( 'deleted', 1 );
			}
		}

		if ( ! $action ) {
			return;
		}

		check_admin_referer( 'leadmap_bulk_leads', '_leadmap_nonce' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified immediately above.
		$ids = array_map( 'absint', (array) ( $_REQUEST['lead'] ?? [] ) );
		$ids = array_filter( $ids );

		if ( ! $ids ) {
			return;
		}

		if ( 'delete' === $action ) {
			$deleted = Lead_Repository::delete( $ids );
			$this->redirect( 'deleted', $deleted );
		}

		if ( 'enrich' === $action ) {
			foreach ( $ids as $id ) {
				Job_Runner::queue_enrich( $id );
			}

			$this->redirect( 'enriching', count( $ids ) );
		}

		if ( 'speed' === $action ) {
			foreach ( $ids as $id ) {
				Job_Runner::queue_speed( $id );
			}

			$this->redirect( 'speed', count( $ids ) );
		}

		if ( 'export' === $action ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=leadmap_export&ids=' . implode( ',', $ids ) ),
				'leadmap_export'
			);

			wp_safe_redirect( $url );
			exit;
		}
	}

	private function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['lm_done'] ) ) {
			return;
		}

		$what  = sanitize_key( wp_unslash( $_GET['lm_done'] ) );
		$count = absint( $_GET['lm_count'] ?? 0 );
		// phpcs:enable

		if ( ! $count || ! in_array( $what, [ 'deleted', 'enriching', 'speed' ], true ) ) {
			return;
		}

		if ( 'speed' === $what ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of leads queued for a speed check. */
						_n(
							'PageSpeed queued for %d lead — mobile and desktop each. Scores appear as the queue clears.',
							'PageSpeed queued for %d leads — mobile and desktop each. Scores appear as the queue clears.',
							$count,
							'leadmap'
						),
						$count
					)
				)
			);

			return;
		}

		$message = 'deleted' === $what
			/* translators: %d: number of leads deleted. */
			? sprintf( _n( '%d lead deleted.', '%d leads deleted.', $count, 'leadmap' ), $count )
			/* translators: %d: number of leads queued for enrichment. */
			: sprintf(
				_n(
					'%d lead queued for enrichment. Emails and site checks appear as each finishes.',
					'%d leads queued for enrichment. Emails and site checks appear as each finishes.',
					$count,
					'leadmap'
				),
				$count
			);

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'deleted' === $what ? 'success' : 'info',
			esc_html( $message )
		);
	}

	private function redirect( string $what, int $count ): void {
		wp_safe_redirect(
			add_query_arg(
				[
					'page'     => 'leadmap',
					'lm_done'  => $what,
					'lm_count' => $count,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function with_email_count(): int {
		$result = Lead_Repository::query( [ 'has_email' => 'yes', 'per_page' => 1 ] );

		return $result['total'];
	}

	private function with_website_count(): int {
		global $wpdb;

		$table = \LeadMap\Install\Schema::table( 'leads' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE website <> ''" );
	}
}
