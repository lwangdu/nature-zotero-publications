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
			'limit'         => Zotero_API::MAX_ITEMS,
			'cache_minutes' => $settings['cache_minutes'],
		);

		if ( empty( $query_args['library_id'] ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="zotero-display-error">' . esc_html__( 'Nature Zotero Publications: please configure a library ID in Settings → Nature Zotero Publications or in this block\'s settings.', 'nature-zotero-publications' ) . '</p>';
			}
			return '';
		}

		$per_page    = max( 1, (int) $attributes['itemsPerPage'] );
		$sync_result = Sync::get_results( $query_args, array(), 1, $per_page, true, false );
		Sync::ensure_scheduled( $query_args );

		if ( false !== $sync_result ) {
			$first_page  = $sync_result['items'];
			$total       = $sync_result['pagination']['total_items'];
			$total_pages = $sync_result['pagination']['total_pages'];
			$stats       = $sync_result['stats'];
		} else {
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

			$total       = count( $items );
			$first_page  = array_slice( $items, 0, $per_page );
			$total_pages = (int) ceil( $total / $per_page );
			$stats       = REST_Controller::compute_stats( $items, true, false );
		}

		$context = array(
			'restUrl'           => esc_url_raw( rest_url( REST_Controller::NAMESPACE . '/' ) ),
			'libraryType'       => $query_args['library_type'],
			'libraryId'         => $query_args['library_id'],
			'collection'        => $attributes['collection'],
			'sortBy'            => $attributes['sortBy'],
			'sortDirection'     => $attributes['sortDirection'],
			'perPage'           => $per_page,
			'showAbstract'      => (bool) $attributes['showAbstract'],
			'page'              => 1,
			'totalPages'        => $total_pages,
			'totalItems'        => $total,
			'search'            => '',
			'filterType'        => '',
			'filterYear'        => '',
			'filterAuthor'      => '',
			'authorQuery'       => '',
			'authorSuggestions' => array(),
			'authorOpen'        => false,
			'activeAuthorIndex' => -1,
			'items'             => array(),
			'hasFetched'        => false,
			'isLoading'         => false,
			'isEmpty'           => false,
			'message'           => __( 'No items match your filters.', 'nature-zotero-publications' ),
			'noResultsMessage'  => __( 'No items match your filters.', 'nature-zotero-publications' ),
			'errorMessage'      => __( 'Unable to load items right now.', 'nature-zotero-publications' ),
			'undatedLabel'      => __( 'Undated', 'nature-zotero-publications' ),
			'inLabel'           => __( 'In:', 'nature-zotero-publications' ),
			'requestId'         => 0,
			'authorRequestId'   => 0,
		);

		$wrapper_attrs     = get_block_wrapper_attributes(
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
		$author_input_id   = wp_unique_id( 'zotero-author-' );
		$author_results_id = wp_unique_id( 'zotero-author-results-' );

		ob_start();
		?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-wp-interactive="zotero-display" <?php echo wp_interactivity_data_wp_context( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core generates an escaped directive attribute. ?>>

			<?php if ( $attributes['showStats'] ) : ?>
				<div class="zotero-display-stats" data-zotero-stats role="status" aria-live="polite" aria-atomic="true">
					<span class="zotero-stat-value" data-zotero-entry-count data-wp-text="context.totalItems"><?php echo esc_html( $total ); ?></span>
					<span class="zotero-stat-label"><?php esc_html_e( 'entries', 'nature-zotero-publications' ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( $attributes['showSearch'] || $attributes['showFilters'] ) : ?>
				<div class="zotero-display-controls" data-zotero-controls>
					<?php if ( $attributes['showSearch'] ) : ?>
						<label><span class="screen-reader-text"><?php esc_html_e( 'Search publications', 'nature-zotero-publications' ); ?></span><input type="search" class="zotero-display-search" placeholder="<?php esc_attr_e( 'Search publications…', 'nature-zotero-publications' ); ?>" data-wp-bind--value="context.search" data-wp-on--input="actions.search" /></label>
					<?php endif; ?>
					<?php if ( $attributes['showFilters'] ) : ?>
						<div class="zotero-filter-row">
							<label><span class="screen-reader-text"><?php esc_html_e( 'Filter by year', 'nature-zotero-publications' ); ?></span><select class="zotero-display-filter" data-wp-on--change="actions.filterYear"><option value=""><?php esc_html_e( 'All years', 'nature-zotero-publications' ); ?></option>
							<?php
							foreach ( $stats['available_years'] as $facet ) :
								?>
								<option value="<?php echo esc_attr( $facet['value'] ); ?>"><?php echo esc_html( $facet['value'] . ' (' . $facet['count'] . ')' ); ?></option><?php endforeach; ?></select></label>
							<label><span class="screen-reader-text"><?php esc_html_e( 'Filter by type', 'nature-zotero-publications' ); ?></span><select class="zotero-display-filter" data-wp-on--change="actions.filterType"><option value=""><?php esc_html_e( 'All types', 'nature-zotero-publications' ); ?></option>
							<?php
							foreach ( $stats['available_types'] as $facet ) :
								?>
								<option value="<?php echo esc_attr( $facet['value'] ); ?>"><?php echo esc_html( $facet['label'] . ' (' . $facet['count'] . ')' ); ?></option><?php endforeach; ?></select></label>
							<div class="zotero-author-combobox">
								<label for="<?php echo esc_attr( $author_input_id ); ?>"><span class="screen-reader-text"><?php esc_html_e( 'Filter by author', 'nature-zotero-publications' ); ?></span></label>
								<input id="<?php echo esc_attr( $author_input_id ); ?>" type="search" class="zotero-display-filter" placeholder="<?php esc_attr_e( 'Search authors…', 'nature-zotero-publications' ); ?>" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="<?php echo esc_attr( $author_results_id ); ?>" data-wp-bind--aria-expanded="context.authorOpen" data-wp-bind--value="context.authorQuery" data-wp-on--input="actions.searchAuthors" data-wp-on--keydown="actions.authorKeydown" />
								<ul id="<?php echo esc_attr( $author_results_id ); ?>" class="zotero-author-results" role="listbox" data-wp-bind--hidden="!context.authorOpen">
									<template data-wp-each--author="context.authorSuggestions" data-wp-each-key="context.author.value">
										<li><button type="button" role="option" data-wp-on--click="actions.selectAuthor" data-wp-on--keydown="actions.selectAuthorKeydown"><span data-wp-text="context.author.value"></span> <span aria-hidden="true">(<span data-wp-text="context.author.count"></span>)</span></button></li>
									</template>
								</ul>
							</div>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="zotero-publication-list" data-zotero-grid data-wp-bind--aria-busy="context.isLoading" data-wp-bind--hidden="context.hasFetched">
				<?php $current_year = null; ?>
				<?php foreach ( $first_page as $item ) : ?>
					<?php if ( $current_year !== $item['date_year'] ) : ?>
						<?php $current_year = $item['date_year']; ?>
						<h2 class="zotero-year-heading"><?php echo esc_html( $current_year ? $current_year : __( 'Undated', 'nature-zotero-publications' ) ); ?></h2>
					<?php endif; ?>
					<?php echo self::render_card( $item, $attributes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endforeach; ?>
			</div>

			<div class="zotero-publication-list" data-wp-bind--aria-busy="context.isLoading" data-wp-bind--hidden="!context.hasFetched">
				<template data-wp-each--item="context.items" data-wp-each-key="context.item.key">
					<div>
						<h2 class="zotero-year-heading" data-wp-bind--hidden="!context.item.showYearHeading" data-wp-text="context.item.yearLabel"></h2>
						<article class="zotero-publication" data-wp-bind--data-zotero-item-type="context.item.item_type" data-wp-bind--data-zotero-item-year="context.item.date_year">
							<p class="zotero-publication-authors" data-wp-text="context.item.creator_str"></p>
							<h3 class="zotero-publication-title"><a target="_blank" rel="noopener noreferrer" data-wp-bind--href="context.item.linkHref"><span data-wp-text="context.item.title"></span><span class="screen-reader-text"> (<?php esc_html_e( 'opens in a new tab', 'nature-zotero-publications' ); ?>)</span></a> <span class="zotero-type-badge" data-wp-bind--hidden="!context.item.item_type_label" data-wp-text="context.item.item_type_label"></span></h3>
							<p class="zotero-publication-citation" data-wp-bind--hidden="!context.item.citationText" data-wp-text="context.item.citationText"></p>
							<div class="zotero-publication-actions">
								<details class="zotero-publication-abstract" data-wp-bind--hidden="!context.item.showAbstract"><summary><?php esc_html_e( 'Abstract', 'nature-zotero-publications' ); ?></summary><p data-wp-text="context.item.abstract"></p></details>
								<a target="_blank" rel="noopener noreferrer" data-wp-bind--hidden="!context.item.doi" data-wp-bind--href="context.item.doi_url"><?php esc_html_e( 'DOI', 'nature-zotero-publications' ); ?><span class="screen-reader-text"> (<?php esc_html_e( 'opens in a new tab', 'nature-zotero-publications' ); ?>)</span></a>
								<a target="_blank" rel="noopener noreferrer" data-wp-bind--hidden="!context.item.source_url" data-wp-bind--href="context.item.source_url"><?php esc_html_e( 'Source', 'nature-zotero-publications' ); ?><span class="screen-reader-text"> (<?php esc_html_e( 'opens in a new tab', 'nature-zotero-publications' ); ?>)</span></a>
								<a target="_blank" rel="noopener noreferrer" data-wp-bind--href="context.item.zotero_link"><?php esc_html_e( 'Zotero', 'nature-zotero-publications' ); ?><span class="screen-reader-text"> (<?php esc_html_e( 'opens in a new tab', 'nature-zotero-publications' ); ?>)</span></a>
								<span data-wp-bind--hidden="!context.item.citation_key"><?php esc_html_e( 'Citation key:', 'nature-zotero-publications' ); ?> <code data-wp-text="context.item.citation_key"></code></span>
							</div>
							<p class="zotero-publication-tags" data-wp-bind--hidden="!context.item.tagsText"><span><?php esc_html_e( 'Tags:', 'nature-zotero-publications' ); ?></span> <span data-wp-text="context.item.tagsText"></span></p>
						</article>
					</div>
				</template>
			</div>

			<nav class="zotero-display-pagination" aria-label="<?php esc_attr_e( 'Publication pagination', 'nature-zotero-publications' ); ?>" data-wp-bind--hidden="state.isPaginationHidden">
					<button type="button" class="zotero-page-prev" data-wp-on--click="actions.previousPage" data-wp-bind--disabled="state.isPreviousDisabled"><?php esc_html_e( 'Previous', 'nature-zotero-publications' ); ?></button>
					<span class="zotero-page-status" data-zotero-page-status role="status" aria-live="polite" aria-atomic="true">
						<?php
						printf(
							/* translators: 1: current page 2: total pages */
							esc_html__( 'Page %1$s of %2$s', 'nature-zotero-publications' ),
							'<span data-wp-text="context.page">1</span>',
							'<span data-wp-text="context.totalPages">' . esc_html( $total_pages ) . '</span>'
						);
						?>
					</span>
					<button type="button" class="zotero-page-next" data-wp-on--click="actions.nextPage" data-wp-bind--disabled="state.isNextDisabled"><?php esc_html_e( 'Next', 'nature-zotero-publications' ); ?></button>
			</nav>

			<div class="zotero-display-empty" role="status" aria-live="polite" aria-atomic="true" data-wp-bind--hidden="!context.isEmpty" data-wp-text="context.message"><?php esc_html_e( 'No items match your filters.', 'nature-zotero-publications' ); ?></div>
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
