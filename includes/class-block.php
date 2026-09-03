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

	/** Fragment-cache transient prefix. */
	const FRAGMENT_CACHE_PREFIX = 'zotero_display_fragment_';

	/** Increment when cached block markup changes. */
	const FRAGMENT_CACHE_VERSION = 2;

	/** Option containing fragment-cache transient keys for explicit invalidation. */
	const FRAGMENT_CACHE_KEYS_OPTION = 'zotero_display_fragment_cache_keys';

	/** Placeholder replaced with a unique author input ID on every render. */
	const AUTHOR_INPUT_PLACEHOLDER = 'zotero-author-input-placeholder';

	/** Placeholder replaced with a unique author results ID on every render. */
	const AUTHOR_RESULTS_PLACEHOLDER = 'zotero-author-results-placeholder';

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
	 * Delete every cached block fragment.
	 *
	 * @return void
	 */
	public static function clear_fragment_cache() {
		$cache_keys = get_option( self::FRAGMENT_CACHE_KEYS_OPTION, array() );

		if ( is_array( $cache_keys ) ) {
			foreach ( array_unique( $cache_keys ) as $cache_key ) {
				if ( is_string( $cache_key ) && 0 === strpos( $cache_key, self::FRAGMENT_CACHE_PREFIX ) ) {
					delete_transient( $cache_key );
				}
			}
		}

		delete_option( self::FRAGMENT_CACHE_KEYS_OPTION );
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
			'itemsPerPage'  => 100,
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
			'cache_minutes' => $settings['cache_minutes'],
		);

		if ( empty( $query_args['library_id'] ) ) {
			if ( current_user_can( 'edit_posts' ) ) {
				return '<p class="zotero-display-error">' . esc_html__( 'Nature Zotero Publications: please configure a library ID in Settings → Nature Zotero Publications or in this block\'s settings.', 'nature-zotero-publications' ) . '</p>';
			}
			return '';
		}

		$per_page           = max( 1, (int) $attributes['itemsPerPage'] );
		$fragment_cache_key = self::fragment_cache_key( $query_args, $attributes, 1 );
		$cached_fragment    = get_transient( $fragment_cache_key );
		if ( false !== $cached_fragment && is_string( $cached_fragment ) ) {
			return self::personalize_fragment( $cached_fragment );
		}

		$include_facets = $attributes['showStats'] || $attributes['showFilters'];
		$sync_result    = Sync::get_results( $query_args, array(), 1, $per_page, $include_facets, false );
		$sync_state     = Sync::ensure_scheduled( $query_args );

		if ( false === $sync_result && empty( $sync_state['processed'] ) ) {
			$sync_state  = Sync::prime_first_batch( $query_args );
			$sync_result = Sync::get_results( $query_args, array(), 1, $per_page, $include_facets, false );
		}

		if ( false !== $sync_result ) {
			$first_page  = $sync_result['items'];
			$total       = $sync_result['pagination']['total_items'];
			$total_pages = $sync_result['pagination']['total_pages'];
			$stats       = $sync_result['stats'];
		} else {
			return self::render_sync_status( $query_args, $sync_state );
		}

		$public_state = $sync_result['sync'];
		$is_partial   = empty( $public_state['ready'] );
		$sync_message = $is_partial
			? sprintf(
				/* translators: 1: processed publication count, 2: total publication count. */
				__( 'Synchronizing publications: %1$s of %2$s processed. Available publications are shown below.', 'nature-zotero-publications' ),
				number_format_i18n( $public_state['processed'] ),
				number_format_i18n( $public_state['total'] )
			)
			: '';

		$context = array(
			'restUrl'              => esc_url_raw( rest_url( REST_Controller::NAMESPACE . '/' ) ),
			'sourceSignature'      => Sync::source_signature( $query_args ),
			'libraryType'          => $query_args['library_type'],
			'libraryId'            => $query_args['library_id'],
			'collection'           => $attributes['collection'],
			'sortBy'               => $attributes['sortBy'],
			'sortDirection'        => $attributes['sortDirection'],
			'perPage'              => $per_page,
			'showAbstract'         => (bool) $attributes['showAbstract'],
			'page'                 => 1,
			'totalPages'           => $total_pages,
			'totalItems'           => $total,
			'search'               => '',
			'filterType'           => '',
			'filterYear'           => '',
			'filterAuthor'         => '',
			'authorQuery'          => '',
			'authorSuggestions'    => array(),
			'authorOpen'           => false,
			'activeAuthorIndex'    => -1,
			'items'                => array(),
			'hasFetched'           => false,
			'isLoading'            => false,
			'isEmpty'              => false,
			'message'              => __( 'No items match your filters.', 'nature-zotero-publications' ),
			'noResultsMessage'     => __( 'No items match your filters.', 'nature-zotero-publications' ),
			'errorMessage'         => __( 'Unable to load items right now.', 'nature-zotero-publications' ),
			'undatedLabel'         => __( 'Undated', 'nature-zotero-publications' ),
			'inLabel'              => __( 'In:', 'nature-zotero-publications' ),
			'pageLabel'            => __( 'Page', 'nature-zotero-publications' ),
			'requestId'            => 0,
			'authorRequestId'      => 0,
			'syncProcessed'        => (int) $public_state['processed'],
			'syncTotal'            => (int) $public_state['total'],
			'syncProgressMax'      => max( 1, (int) $public_state['total'] ),
			'syncMessage'          => $sync_message,
			/* translators: 1: processed publication count, 2: total publication count. */
			'syncProgressTemplate' => __( 'Synchronizing publications: %1$s of %2$s processed. Available publications are shown below.', 'nature-zotero-publications' ),
			'syncPreparingMessage' => __( 'Preparing publication synchronization…', 'nature-zotero-publications' ),
			'syncErrorMessage'     => __( 'The publication library could not be synchronized. Please try again later.', 'nature-zotero-publications' ),
		);

		$wrapper_args = array(
			'class'               => 'zotero-display-block' . ( $is_partial ? ' zotero-display-sync-status' : '' ),
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
		);
		if ( $is_partial ) {
			$wrapper_args['data-wp-init'] = 'callbacks.pollSync';
		}
		$wrapper_attrs     = get_block_wrapper_attributes( $wrapper_args );
		$author_input_id   = self::AUTHOR_INPUT_PLACEHOLDER;
		$author_results_id = self::AUTHOR_RESULTS_PLACEHOLDER;

		ob_start();
		?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-wp-interactive="zotero-display" <?php echo wp_interactivity_data_wp_context( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core generates an escaped directive attribute. ?>>
			<?php if ( $is_partial ) : ?>
				<p class="zotero-display-sync-message" role="status" aria-live="polite" aria-atomic="true" data-wp-text="context.syncMessage"><?php echo esc_html( $sync_message ); ?></p>
				<progress value="<?php echo esc_attr( $public_state['processed'] ); ?>" max="<?php echo esc_attr( max( 1, (int) $public_state['total'] ) ); ?>" data-wp-bind--value="context.syncProcessed" data-wp-bind--max="context.syncProgressMax">
					<?php echo esc_html( $sync_message ); ?>
				</progress>
			<?php endif; ?>

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
						</article>
					</div>
				</template>
			</div>

			<nav class="zotero-display-pagination" aria-label="<?php esc_attr_e( 'Publication pagination', 'nature-zotero-publications' ); ?>" data-wp-bind--hidden="state.isPaginationHidden">
					<span class="zotero-page-status" data-zotero-page-status role="status" aria-live="polite" aria-atomic="true">
						<?php
						printf(
							/* translators: 1: current page, 2: total pages, 3: total entries. */
							esc_html__( 'Page %1$s of %2$s (%3$s entries)', 'nature-zotero-publications' ),
							'<span data-wp-text="context.page">1</span>',
							'<span data-wp-text="context.totalPages">' . esc_html( $total_pages ) . '</span>',
							'<span data-wp-text="context.totalItems">' . esc_html( $total ) . '</span>'
						);
						?>
					</span>
					<div class="zotero-page-links">
						<button type="button" class="zotero-page-prev" data-wp-on--click="actions.previousPage" data-wp-bind--disabled="state.isPreviousDisabled" data-wp-bind--hidden="state.isPreviousHidden"><?php esc_html_e( 'Previous', 'nature-zotero-publications' ); ?></button>
						<template data-wp-each--pagination="state.paginationItems" data-wp-each-key="context.pagination.key">
							<span>
								<button type="button" class="zotero-page-number" data-wp-bind--aria-current="context.pagination.ariaCurrent" data-wp-bind--aria-label="context.pagination.ariaLabel" data-wp-bind--aria-hidden="context.pagination.isEllipsis" data-wp-class--is-current="context.pagination.isCurrent" data-wp-class--is-ellipsis="context.pagination.isEllipsis" data-wp-on--click="actions.goToPage" data-wp-bind--disabled="context.pagination.isDisabled" data-wp-text="context.pagination.label"></button>
							</span>
						</template>
						<button type="button" class="zotero-page-next" data-wp-on--click="actions.nextPage" data-wp-bind--disabled="state.isNextDisabled" data-wp-bind--hidden="state.isNextHidden"><?php esc_html_e( 'Next', 'nature-zotero-publications' ); ?></button>
					</div>
			</nav>

			<div class="zotero-display-empty" role="status" aria-live="polite" aria-atomic="true" data-wp-bind--hidden="!context.isEmpty" data-wp-text="context.message"><?php esc_html_e( 'No items match your filters.', 'nature-zotero-publications' ); ?></div>
		</div>
		<?php
		$fragment = ob_get_clean();

		if ( 'complete' === $public_state['status'] ) {
			set_transient( $fragment_cache_key, $fragment, self::fragment_cache_ttl( $query_args ) );
			self::remember_fragment_cache_key( $fragment_cache_key );
		}

		return self::personalize_fragment( $fragment );
	}

	/**
	 * Build a stable transient key for one server-rendered result page.
	 *
	 * @param array $query_args Zotero source query arguments.
	 * @param array $attributes Block attributes.
	 * @param int   $page       One-based result page.
	 * @return string
	 */
	private static function fragment_cache_key( array $query_args, array $attributes, $page ) {
		unset( $query_args['api_key'] );

		return self::FRAGMENT_CACHE_PREFIX . md5(
			wp_json_encode(
				array(
					'version'    => ZOTERO_DISPLAY_VERSION . ':' . self::FRAGMENT_CACHE_VERSION,
					'query_args' => $query_args,
					'page'       => max( 1, (int) $page ),
					'attributes' => $attributes,
					'locale'     => determine_locale(),
				)
			)
		);
	}

	/**
	 * Keep the fragment alive no longer than the configured refresh interval.
	 *
	 * @param array $query_args Zotero source query arguments.
	 * @return int
	 */
	private static function fragment_cache_ttl( array $query_args ) {
		$cache_minutes = isset( $query_args['cache_minutes'] ) ? (int) $query_args['cache_minutes'] : 60;

		return max( MINUTE_IN_SECONDS, $cache_minutes * MINUTE_IN_SECONDS );
	}

	/**
	 * Track a transient key so sync completion can purge object-cache entries too.
	 *
	 * @param string $cache_key Transient key.
	 * @return void
	 */
	private static function remember_fragment_cache_key( $cache_key ) {
		$cache_keys = get_option( self::FRAGMENT_CACHE_KEYS_OPTION, array() );
		$cache_keys = is_array( $cache_keys ) ? $cache_keys : array();

		if ( ! in_array( $cache_key, $cache_keys, true ) ) {
			$cache_keys[] = $cache_key;
			update_option( self::FRAGMENT_CACHE_KEYS_OPTION, $cache_keys, false );
		}
	}

	/**
	 * Replace cached ID placeholders so repeated identical blocks remain valid.
	 *
	 * @param string $fragment Cached block HTML.
	 * @return string
	 */
	private static function personalize_fragment( $fragment ) {
		return str_replace(
			array( self::AUTHOR_INPUT_PLACEHOLDER, self::AUTHOR_RESULTS_PLACEHOLDER ),
			array( wp_unique_id( 'zotero-author-' ), wp_unique_id( 'zotero-author-results-' ) ),
			$fragment
		);
	}

	/**
	 * Render a lightweight status while the first complete index is built.
	 *
	 * @param array $query_args Zotero source query arguments.
	 * @param array $sync_state Internal synchronization state.
	 * @return string
	 */
	private static function render_sync_status( array $query_args, array $sync_state ) {
		$public_state = Sync::get_public_state( $query_args );
		$processed    = (int) $public_state['processed'];
		$total        = (int) $public_state['total'];
		$is_error     = 'error' === $public_state['status'];

		if ( $is_error ) {
			$message = __( 'The publication library could not be synchronized. Please try again later.', 'nature-zotero-publications' );
			if ( current_user_can( 'edit_posts' ) && ! empty( $sync_state['error'] ) ) {
				$message = sprintf(
					/* translators: %s: synchronization error message. */
					__( 'Nature Zotero Publications synchronization error: %s', 'nature-zotero-publications' ),
					$sync_state['error']
				);
			}
		} elseif ( $total > 0 ) {
			$message = sprintf(
				/* translators: 1: processed publication count, 2: total publication count. */
				__( 'Synchronizing publications: %1$s of %2$s processed.', 'nature-zotero-publications' ),
				number_format_i18n( $processed ),
				number_format_i18n( $total )
			);
		} else {
			$message = __( 'Preparing publication synchronization…', 'nature-zotero-publications' );
		}

		$context       = array(
			'restUrl'              => esc_url_raw( rest_url( REST_Controller::NAMESPACE . '/' ) ),
			'sourceSignature'      => Sync::source_signature( $query_args ),
			'libraryType'          => $query_args['library_type'],
			'libraryId'            => $query_args['library_id'],
			'collection'           => $query_args['collection'],
			'sortBy'               => $query_args['sort'],
			'sortDirection'        => $query_args['direction'],
			'syncProcessed'        => $processed,
			'syncTotal'            => $total,
			'syncProgressMax'      => max( 1, $total ),
			'syncMessage'          => $message,
			/* translators: 1: processed publication count, 2: total publication count. */
			'syncProgressTemplate' => __( 'Synchronizing publications: %1$s of %2$s processed.', 'nature-zotero-publications' ),
			'syncPreparingMessage' => __( 'Preparing publication synchronization…', 'nature-zotero-publications' ),
			'syncErrorMessage'     => __( 'The publication library could not be synchronized. Please try again later.', 'nature-zotero-publications' ),
		);
		$wrapper_attrs = get_block_wrapper_attributes(
			array(
				'class'               => 'zotero-display-block zotero-display-sync-status',
				'data-zotero-display' => 'true',
			)
		);

		ob_start();
		?>
		<div <?php echo $wrapper_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> data-wp-interactive="zotero-display" <?php echo wp_interactivity_data_wp_context( $context ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core generates an escaped directive attribute. ?>
		<?php
		if ( ! $is_error ) :
			?>
			data-wp-init="callbacks.pollSync"<?php endif; ?>>
			<p class="zotero-display-sync-message" role="status" aria-live="polite" aria-atomic="true" data-wp-text="context.syncMessage"><?php echo esc_html( $message ); ?></p>
			<?php if ( ! $is_error ) : ?>
				<progress value="<?php echo esc_attr( $processed ); ?>" max="<?php echo esc_attr( max( 1, $total ) ); ?>" data-wp-bind--value="context.syncProcessed" data-wp-bind--max="context.syncProgressMax">
					<?php echo esc_html( $message ); ?>
				</progress>
			<?php endif; ?>
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
		</article>
		<?php
		return ob_get_clean();
	}
}
