<?php
/**
 * The leads list table.
 *
 * @package LeadMap
 */

declare( strict_types=1 );

namespace LeadMap\Admin\List_Tables;

use LeadMap\Leads\Lead_Repository;
use LeadMap\Support\Normalize;
use WP_List_Table;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class Leads_List_Table extends WP_List_Table {

	private int $total = 0;

	public function __construct() {
		parent::__construct(
			[
				'singular' => 'lead',
				'plural'   => 'leads',
				'ajax'     => false,
			]
		);
	}

	/** @return array<string,string> */
	public function get_columns(): array {
		return [
			'cb'        => '<input type="checkbox" />',
			'name'      => __( 'Business', 'leadmap' ),
			'contact'   => __( 'Contact', 'leadmap' ),
			'website'   => __( 'Website', 'leadmap' ),
			'location'  => __( 'Location', 'leadmap' ),
			'rating'    => __( 'Rating', 'leadmap' ),
			'speed'     => __( 'Speed', 'leadmap' ),
			'staleness' => __( 'Staleness', 'leadmap' ),
			'status'    => __( 'Status', 'leadmap' ),
			'created_at' => __( 'Added', 'leadmap' ),
		];
	}

	/** @return array<string,array{0:string,1:bool}> */
	public function get_sortable_columns(): array {
		return [
			'name'       => [ 'name', false ],
			'rating'     => [ 'rating', false ],
			'status'     => [ 'status', false ],
			'staleness'  => [ 'staleness_score', false ],
			'created_at' => [ 'created_at', true ],
		];
	}

	/** @return array<string,string> */
	public function get_bulk_actions(): array {
		return [
			'enrich' => __( 'Enrich (find emails, check site)', 'leadmap' ),
			'export' => __( 'Export to CSV', 'leadmap' ),
			'delete' => __( 'Delete', 'leadmap' ),
		];
	}

	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'leadmap_leads_per_page', 25 );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filtering.
		$args = [
			'search'    => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'status'    => isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '',
			'zip'       => isset( $_REQUEST['zip'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['zip'] ) ) : '',
			'category'  => isset( $_REQUEST['category'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['category'] ) ) : '',
			'has_email' => isset( $_REQUEST['has_email'] ) ? sanitize_key( wp_unslash( $_REQUEST['has_email'] ) ) : '',
			'search_id' => isset( $_REQUEST['search_id'] ) ? absint( $_REQUEST['search_id'] ) : 0,
			'orderby'   => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at',
			'order'     => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc',
			'per_page'  => $per_page,
			'page'      => $this->get_pagenum(),
		];
		// phpcs:enable

		$result       = Lead_Repository::query( $args );
		$this->items  = $result['items'];
		$this->total  = $result['total'];

		$this->set_pagination_args(
			[
				'total_items' => $this->total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $this->total / $per_page ),
			]
		);

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns(), 'name' ];
	}

	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="lead[]" value="%d" />', (int) $item->id );
	}

	public function column_name( $item ): string {
		$name = $item->name ?: __( '(no name)', 'leadmap' );

		$actions = [];

		if ( $item->maps_url ) {
			$actions['maps'] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $item->maps_url ),
				esc_html__( 'View on Maps', 'leadmap' )
			);
		}

		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin.php?page=leadmap&lm_action=delete&id=' . (int) $item->id ),
					'leadmap_lead_delete_' . (int) $item->id
				)
			),
			esc_js( __( 'Delete this lead?', 'leadmap' ) ),
			esc_html__( 'Delete', 'leadmap' )
		);

		$view = add_query_arg(
			[ 'page' => 'leadmap-lead', 'lead' => (int) $item->id ],
			admin_url( 'admin.php' )
		);

		$actions = array_merge(
			[ 'view' => sprintf( '<a href="%s">%s</a>', esc_url( $view ), esc_html__( 'View', 'leadmap' ) ) ],
			$actions
		);

		return sprintf(
			'<strong><a class="row-title" href="%s">%s</a></strong>%s%s',
			esc_url( $view ),
			esc_html( $name ),
			$item->category ? '<br /><span class="leadmap-muted">' . esc_html( Normalize::humanize_type( (string) $item->category ) ) . '</span>' : '',
			$this->row_actions( $actions )
		);
	}

	public function column_contact( $item ): string {
		$out = [];

		if ( $item->phone ) {
			$out[] = sprintf(
				'<a href="tel:%s">%s</a>',
				esc_attr( (string) ( $item->phone_e164 ?: $item->phone ) ),
				esc_html( (string) $item->phone )
			);
		}

		if ( $item->email ) {
			$out[] = sprintf(
				'<a href="mailto:%1$s">%1$s</a>',
				esc_html( (string) $item->email )
			);
		} else {
			$out[] = '<span class="leadmap-muted">' . esc_html__( 'No email yet', 'leadmap' ) . '</span>';
		}

		return implode( '<br />', $out );
	}

	public function column_website( $item ): string {
		if ( ! $item->website ) {
			return '<span class="leadmap-muted leadmap-nosite">' . esc_html__( 'No website', 'leadmap' ) . '</span>';
		}

		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer nofollow">%s</a>',
			esc_url( (string) $item->website ),
			esc_html( (string) ( $item->domain ?: $item->website ) )
		);
	}

	public function column_location( $item ): string {
		$parts = array_filter( [ $item->city, $item->state, $item->zip ] );

		return esc_html( implode( ', ', array_map( 'strval', $parts ) ) ?: '—' );
	}

	public function column_rating( $item ): string {
		if ( null === $item->rating || 0.0 === (float) $item->rating ) {
			return '—';
		}

		return sprintf(
			'%s <span class="leadmap-muted">(%d)</span>',
			esc_html( number_format( (float) $item->rating, 1 ) ),
			(int) $item->review_count
		);
	}

	/** Mobile and desktop side by side, the way PageSpeed Insights reports them. */
	public function column_speed( $item ): string {
		$enrichment = json_decode( (string) $item->enrichment_json, true );

		if ( ! is_array( $enrichment ) ) {
			return '<span class="leadmap-muted">—</span>';
		}

		$parts = [];

		foreach ( [ 'mobile' => __( 'M', 'leadmap' ), 'desktop' => __( 'D', 'leadmap' ) ] as $strategy => $abbr ) {
			$score = $enrichment[ 'speed_' . $strategy ]['score'] ?? null;

			if ( null === $score ) {
				continue;
			}

			$score = (int) $score;
			$band  = $score < 50 ? 'high' : ( $score < 90 ? 'mid' : 'low' );

			$parts[] = sprintf(
				'<span class="leadmap-speed leadmap-speed--%s" title="%s">%s<b>%d</b></span>',
				esc_attr( $band ),
				esc_attr( sprintf(
					/* translators: 1: mobile or desktop, 2: the score. */
					__( '%1$s: %2$d out of 100', 'leadmap' ),
					ucfirst( $strategy ),
					$score
				) ),
				esc_html( $abbr ),
				$score
			);
		}

		return $parts ? implode( ' ', $parts ) : '<span class="leadmap-muted">—</span>';
	}

	public function column_staleness( $item ): string {
		if ( null === $item->staleness_score ) {
			return '<span class="leadmap-muted">—</span>';
		}

		$score = (int) $item->staleness_score;
		$band  = $score >= 60 ? 'high' : ( $score >= 30 ? 'mid' : 'low' );

		return sprintf(
			'<span class="leadmap-pill leadmap-pill--%s">%d</span>',
			esc_attr( $band ),
			$score
		);
	}

	public function column_status( $item ): string {
		$statuses = Lead_Repository::statuses();
		$status   = (string) $item->status;

		return sprintf(
			'<span class="leadmap-status leadmap-status--%s">%s</span>',
			esc_attr( $status ),
			esc_html( $statuses[ $status ] ?? $status )
		);
	}

	public function column_created_at( $item ): string {
		if ( ! $item->created_at ) {
			return '—';
		}

		return esc_html( mysql2date( 'M j, Y', (string) $item->created_at ) );
	}

	public function column_default( $item, $column_name ): string {
		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '';
	}

	public function no_items(): void {
		esc_html_e( 'No leads found. Run a search to collect some.', 'leadmap' );
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status    = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '';
		$zip       = isset( $_REQUEST['zip'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['zip'] ) ) : '';
		$category  = isset( $_REQUEST['category'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['category'] ) ) : '';
		$has_email = isset( $_REQUEST['has_email'] ) ? sanitize_key( wp_unslash( $_REQUEST['has_email'] ) ) : '';
		// phpcs:enable

		$counts = Lead_Repository::status_counts();

		?>
		<div class="alignleft actions">
			<select name="status">
				<option value=""><?php esc_html_e( 'All statuses', 'leadmap' ); ?></option>
				<?php foreach ( Lead_Repository::statuses() as $key => $label ) : ?>
					<?php if ( ! isset( $counts[ $key ] ) && $key !== $status ) { continue; } ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>>
						<?php echo esc_html( $label . ' (' . (int) ( $counts[ $key ] ?? 0 ) . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="zip">
				<option value=""><?php esc_html_e( 'All ZIPs', 'leadmap' ); ?></option>
				<?php foreach ( Lead_Repository::distinct( 'zip' ) as $value ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $zip, $value ); ?>>
						<?php echo esc_html( $value ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="category">
				<option value=""><?php esc_html_e( 'All categories', 'leadmap' ); ?></option>
				<?php foreach ( Lead_Repository::distinct( 'category' ) as $value ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $category, $value ); ?>>
						<?php echo esc_html( Normalize::humanize_type( $value ) ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<select name="has_email">
				<option value=""><?php esc_html_e( 'Email: any', 'leadmap' ); ?></option>
				<option value="yes" <?php selected( $has_email, 'yes' ); ?>><?php esc_html_e( 'Has email', 'leadmap' ); ?></option>
				<option value="no" <?php selected( $has_email, 'no' ); ?>><?php esc_html_e( 'No email', 'leadmap' ); ?></option>
			</select>

			<?php submit_button( __( 'Filter', 'leadmap' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}
}
