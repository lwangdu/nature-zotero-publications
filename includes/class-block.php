<?php
/**
 * Registers the Nature Zotero Publications block (dynamic, server-rendered) and its assets.
 *
 * @package ZoteroDisplay
 */

namespace Zotero_Display;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the Nature Zotero Publications block.
 */
class Block {

	/**
	 * Singleton instance.
	 *
	 * @var Block|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Block
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register WordPress hooks.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register the dynamic block and its frontend assets.
	 *
	 * @return void
	 */
	public function register_block() {
		register_block_type(
			ZOTERO_DISPLAY_DIR . 'build',
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);

		// The block.json "viewScript" handle is auto-generated; hook in to localize it
		// once WordPress registers it, so frontend.js has the REST URL it needs.
		add_action( 'wp_enqueue_scripts', array( $this, 'localize_view_script' ) );
	}

	/**
	 * Pass the REST URL and translated interface text to frontend.js.
	 */
	public function localize_view_script() {
		$handle = generate_block_asset_handle( 'zotero-display/library', 'viewScript' );
		if ( wp_script_is( $handle, 'registered' ) ) {
			wp_localize_script(
				$handle,
				'zoteroDisplaySettings',
				array(
					'restUrl'     => esc_url_raw( rest_url( REST_Controller::NAMESPACE . '/' ) ),
					'nonce'       => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
					'newTabLabel' => __( 'opens in a new tab', 'nature-zotero-publications' ),
				)
			);
		}
	}

	/**
	 * Server-side render callback for the block.
	 *
	 * Renders the initial markup (good for SEO / no-JS) and outputs a data
	 * payload + container that frontend.js progressively enhances for
	 * client-side filtering/pagination without full reloads.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ) {
		$defaults   = array(
			'libraryType'   => '',
			'libraryId'     => '',
			'collection'    => '',
			'sortBy'        => 'date',
			'sortDirection' => 'desc',
			'itemsPerPage'  => 10,
			'columns'       => 3,
			'showStats'     => true,
			'showFilters'   => true,
			'showSearch'    => true,
			'showAbstract'  => true,
		);
		$attributes = wp_parse_args( $attributes, $defaults );

		$settings = Settings::get_settings();

		$query_args = array(
			'library_type'  => ! empty( $attributes['libraryType'] ) ? $attributes['libraryType'] : $settings['library_type'],
			'library_id'    => ! empty( $attributes['libraryId'] ) ? $attributes['libraryId'] : $settings['library_id'],
			'api_key'       => $settings['api_key'],
			'collection'    => $attributes['collection'],
			'sort'          => $attributes['sortBy'],
			'direction'     => $attributes['sortDirection'],
			'limit'         => 1000,
			'cache_minutes' => $settings['cache_minutes'],
		);

		if ( empty( $query_args['library_id'] ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="zotero-display-error">' . esc_html__( 'Nature Zotero Publications: please configure a library ID in Settings → Nature Zotero Publications or in this block\'s settings.', 'nature-zotero-publications' ) . '</p>';
			}
			return '';
		}

		$items = Zotero_API::get_items( $query_args );

		if ( is_wp_error( $items ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="zotero-display-error">' . esc_html(
					sprintf(
					/* translators: %s: error message */
						__( 'Nature Zotero Publications error: %s', 'nature-zotero-publications' ),
						$items->get_error_message()
					)
				) . '</p>';
			}
			return '';
		}

		$per_page    = max( 1, (int) $attributes['itemsPerPage'] );
		$total       = count( $items );
		$first_page  = array_slice( $items, 0, $per_page );
		$total_pages = (int) ceil( $total / $per_page );
		$stats       = REST_Controller::compute_stats( $items );

		$wrapper_attrs = get_block_wrapper_attributes(
			array(
				'class'               => 'zotero-display-block',
				'data-zotero-display' => 'true',
				'data-library-type'   => $query_args['library_type'],
				'data-library-id'     => esc_attr( $query_args['library_id'] ),
				'data-collection'     => esc_attr( $attributes['collection'] ),
				'data-sort-by'        => esc_attr( $attributes['sortBy'] ),
				'data-sort-direction' => esc_attr( $attributes['sortDirection'] ),
				'data-items-per-page' => esc_attr( $per_page ),
				'data-show-stats'     => $attributes['showStats'] ? '1' : '0',
				'data-show-filters'   => $attributes['showFilters'] ? '1' : '0',
				'data-show-search'    => $attributes['showSearch'] ? '1' : '0',
				'data-show-abstract'  => $attributes['showAbstract'] ? '1' : '0',
			)
		);

		ob_start();
		?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>

			<?php if ( $attributes['showStats'] ) : ?>
				<div class="zotero-display-stats" data-zotero-stats role="status" aria-live="polite" aria-atomic="true">
					<span class="zotero-stat-value" data-zotero-entry-count><?php echo esc_html( $total ); ?></span>
					<span class="zotero-stat-label"><?php esc_html_e( 'entries', 'nature-zotero-publications' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( $attributes['showSearch'] || $attributes['showFilters'] ) : ?>
				<div class="zotero-display-controls" data-zotero-controls>
					<?php if ( $attributes['showSearch'] ) : ?>
						<label><span class="screen-reader-text"><?php esc_html_e( 'Search publications', 'nature-zotero-publications' ); ?></span><input type="search" class="zotero-display-search" data-zotero-search placeholder="<?php esc_attr_e( 'Search publications…', 'nature-zotero-publications' ); ?>" /></label>
					<?php endif; ?>
					<?php if ( $attributes['showFilters'] ) : ?>
						<div class="zotero-filter-row">
							<label><span class="screen-reader-text"><?php esc_html_e( 'Filter by year', 'nature-zotero-publications' ); ?></span><select class="zotero-display-filter" data-zotero-filter-year><option value=""><?php esc_html_e( 'All years', 'nature-zotero-publications' ); ?></option></select></label>
							<label><span class="screen-reader-text"><?php esc_html_e( 'Filter by type', 'nature-zotero-publications' ); ?></span><select class="zotero-display-filter" data-zotero-filter-type><option value=""><?php esc_html_e( 'All types', 'nature-zotero-publications' ); ?></option></select></label>
							<label><span class="screen-reader-text"><?php esc_html_e( 'Filter by author', 'nature-zotero-publications' ); ?></span><select class="zotero-display-filter" data-zotero-filter-author><option value=""><?php esc_html_e( 'All authors', 'nature-zotero-publications' ); ?></option></select></label>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $attributes['showFilters'] ) : ?>
				<script type="application/json" data-zotero-facets><?php echo wp_json_encode( $stats, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is hex-escaped for safe script embedding. ?></script>
			<?php endif; ?>

			<div class="zotero-publication-list" data-zotero-grid>
				<?php $current_year = null; ?>
				<?php foreach ( $first_page as $item ) : ?>
					<?php if ( $current_year !== $item['date_year'] ) : ?>
						<?php $current_year = $item['date_year']; ?>
						<h2 class="zotero-year-heading"><?php echo esc_html( $current_year ? $current_year : __( 'Undated', 'nature-zotero-publications' ) ); ?></h2>
					<?php endif; ?>
					<?php echo self::render_card( $item, $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</div>

			<nav class="zotero-display-pagination" data-zotero-pagination data-total-pages="<?php echo esc_attr( $total_pages ); ?>" data-current-page="1" aria-label="<?php esc_attr_e( 'Publication pagination', 'nature-zotero-publications' ); ?>" <?php echo $total_pages <= 1 ? 'hidden' : ''; ?>>
					<button type="button" class="zotero-page-prev" data-zotero-page-prev disabled><?php esc_html_e( 'Previous', 'nature-zotero-publications' ); ?></button>
					<span class="zotero-page-status" data-zotero-page-status role="status" aria-live="polite" aria-atomic="true">
						<?php
						printf(
							/* translators: 1: current page 2: total pages */
							esc_html__( 'Page %1$s of %2$s', 'nature-zotero-publications' ),
							'<span data-zotero-current-page>1</span>',
							'<span data-zotero-total-pages>' . esc_html( $total_pages ) . '</span>'
						);
						?>
					</span>
					<button type="button" class="zotero-page-next" data-zotero-page-next <?php disabled( $total_pages <= 1 ); ?>><?php esc_html_e( 'Next', 'nature-zotero-publications' ); ?></button>
			</nav>

			<div class="zotero-display-empty" data-zotero-empty role="status" aria-live="polite" aria-atomic="true" hidden><?php esc_html_e( 'No items match your filters.', 'nature-zotero-publications' ); ?></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a single bibliography entry. Shared by both PHP initial render and used as
	 * a reference for the JS template (frontend.js renders its own DOM for re-renders).
	 *
	 * @param array $item       Normalized Zotero item.
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render_card( array $item, array $attributes ) {
		ob_start();
		?>
		<article class="zotero-publication" data-zotero-item-type="<?php echo esc_attr( $item['item_type'] ); ?>" data-zotero-item-year="<?php echo esc_attr( $item['date_year'] ); ?>">
			<p class="zotero-publication-authors"><?php echo esc_html( $item['creator_str'] ); ?></p>
			<h3 class="zotero-publication-title">
				<a href="<?php echo esc_url( $item['external_url'] ? $item['external_url'] : $item['zotero_link'] ); ?>" target="_blank" rel="noopener noreferrer">
					<?php echo esc_html( $item['title'] ); ?><span class="screen-reader-text"> (<?php esc_html_e( 'opens in a new tab', 'nature-zotero-publications' ); ?>)</span>
				</a>
				<?php if ( $item['item_type_label'] ) : ?>
					<span class="zotero-type-badge"><?php echo esc_html( $item['item_type_label'] ); ?></span>
				<?php endif; ?>
			</h3>
			<?php if ( $item['publication'] || $item['date'] ) : ?>
				<p class="zotero-publication-citation">
					<?php
					if ( $item['publication'] ) :
						?>
						<?php esc_html_e( 'In:', 'nature-zotero-publications' ); ?> <?php echo esc_html( $item['publication'] ); ?><?php endif; ?><?php echo $item['publication'] && $item['date'] ? ', ' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static punctuation. ?><?php echo esc_html( $item['date'] ); ?>.
				</p>
			<?php endif; ?>
			<div class="zotero-publication-actions">
				<?php if ( $attributes['showAbstract'] && $item['abstract'] ) : ?>
					<details class="zotero-publication-abstract"><summary><?php esc_html_e( 'Abstract', 'nature-zotero-publications' ); ?></summary><p><?php echo esc_html( $item['abstract'] ); ?></p></details>
				<?php endif; ?>
				<?php if ( $item['doi'] ) : ?>
					<a href="<?php echo esc_url( $item['doi_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'DOI', 'nature-zotero-publications' ); ?><span class="screen-reader-text"> (<?php esc_html_e( 'opens in a new tab', 'nature-zotero-publications' ); ?>)</span></a>
				<?php endif; ?>
				<?php if ( $item['source_url'] ) : ?>
					<a href="<?php echo esc_url( $item['source_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Source', 'nature-zotero-publications' ); ?><span class="screen-reader-text"> (<?php esc_html_e( 'opens in a new tab', 'nature-zotero-publications' ); ?>)</span></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( $item['zotero_link'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Zotero', 'nature-zotero-publications' ); ?><span class="screen-reader-text"> (<?php esc_html_e( 'opens in a new tab', 'nature-zotero-publications' ); ?>)</span></a>
				<?php
				if ( $item['citation_key'] ) :
					?>
					<span><?php esc_html_e( 'Citation key:', 'nature-zotero-publications' ); ?> <code><?php echo esc_html( $item['citation_key'] ); ?></code></span><?php endif; ?>
			</div>
			<?php if ( $item['tags'] ) : ?>
				<p class="zotero-publication-tags"><span><?php esc_html_e( 'Tags:', 'nature-zotero-publications' ); ?></span> <?php echo esc_html( implode( ', ', $item['tags'] ) ); ?></p>
			<?php endif; ?>
		</article>
		<?php
		return ob_get_clean();
	}
}
