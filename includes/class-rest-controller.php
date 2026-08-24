<?php
/**
 * REST endpoint that serves normalized, cached Zotero items to both
 * the block editor preview and the frontend filter/pagination script.
 *
 * @package ZoteroDisplay
 */

namespace Zotero_Display;

defined( 'ABSPATH' ) || exit;

/**
 * Provides cached Zotero items through the WordPress REST API.
 */
class REST_Controller {

	const NAMESPACE = 'zotero-display/v1';

	/**
	 * Singleton instance.
	 *
	 * @var REST_Controller|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return REST_Controller
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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the Zotero items REST route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/items',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'library_type'   => array( 'type' => 'string' ),
					'library_id'     => array( 'type' => 'string' ),
					'collection'     => array( 'type' => 'string' ),
					'sort'           => array( 'type' => 'string' ),
					'direction'      => array( 'type' => 'string' ),
					'limit'          => array( 'type' => 'integer' ),
					'item_type'      => array( 'type' => 'string' ),
					'search'         => array( 'type' => 'string' ),
					'filter_type'    => array( 'type' => 'string' ),
					'filter_year'    => array( 'type' => 'string' ),
					'filter_author'  => array( 'type' => 'string' ),
					'include_facets' => array( 'type' => 'boolean' ),
					'page'           => array( 'type' => 'integer' ),
					'per_page'       => array( 'type' => 'integer' ),
				),
			)
		);
	}

	/**
	 * Build the canonical query args for Zotero_API::get_items() from request + saved settings,
	 * letting per-block attributes override plugin-wide defaults.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return array
	 */
	private function resolve_query_args( \WP_REST_Request $request ) {
		$settings     = Settings::get_settings();
		$library_type = $request->get_param( 'library_type' );
		$library_id   = $request->get_param( 'library_id' );
		$collection   = $request->get_param( 'collection' );
		$sort         = $request->get_param( 'sort' );
		$direction    = $request->get_param( 'direction' );
		$item_type    = $request->get_param( 'item_type' );

		return array(
			'library_type'  => $library_type ? $library_type : $settings['library_type'],
			'library_id'    => $library_id ? $library_id : $settings['library_id'],
			'api_key'       => $settings['api_key'],
			'collection'    => $collection ? $collection : '',
			'sort'          => $sort ? $sort : 'date',
			'direction'     => $direction ? $direction : 'desc',
			'limit'         => $request->get_param( 'limit' ) ? (int) $request->get_param( 'limit' ) : 1000,
			'item_type'     => $item_type ? $item_type : '',
			'cache_minutes' => $settings['cache_minutes'],
		);
	}

	/**
	 * GET /zotero-display/v1/items
	 *
	 * Fetches the full (cached) item set for the configured library/collection,
	 * then applies search/filter/sort/pagination in-request so the underlying
	 * Zotero data only needs to be re-fetched once per cache window.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_items( \WP_REST_Request $request ) {
		$query_args = $this->resolve_query_args( $request );

		if ( empty( $query_args['library_id'] ) ) {
			return new \WP_Error(
				'zotero_display_no_library',
				__( 'No Zotero library ID configured. Set one in Settings → Nature Zotero Publications, or on this block.', 'nature-zotero-publications' ),
				array( 'status' => 400 )
			);
		}

		$items = Zotero_API::get_items( $query_args );

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		// --- In-request filtering (search, type, year, author) ---
		$search        = $request->get_param( 'search' );
		$filter_type   = $request->get_param( 'filter_type' );
		$filter_year   = $request->get_param( 'filter_year' );
		$filter_author = $request->get_param( 'filter_author' );

		if ( $search ) {
			$needle = mb_strtolower( $search );
			$items  = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $needle ) {
						$haystack = mb_strtolower(
							implode(
								' ',
								array(
									$item['title'],
									$item['creator_str'],
									$item['date'],
									$item['doi'],
									$item['citation_key'],
									$item['abstract'],
									$item['publication'],
									implode( ' ', $item['tags'] ),
								)
							)
						);
						return false !== mb_strpos( $haystack, $needle );
					}
				)
			);
		}

		if ( $filter_type ) {
			$items = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $filter_type ) {
						return $item['item_type'] === $filter_type;
					}
				)
			);
		}

		if ( $filter_year ) {
			$items = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $filter_year ) {
						return $item['date_year'] === $filter_year;
					}
				)
			);
		}

		if ( $filter_author ) {
			$items = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $filter_author ) {
						return in_array( $filter_author, $item['creators'], true );
					}
				)
			);
		}

		// --- Stats (computed on the filtered set, before pagination) ---
		$include_facets = $request->get_param( 'include_facets' );
		$include_facets = null === $include_facets || rest_sanitize_boolean( $include_facets );
		$stats          = self::compute_stats( $items, $include_facets );

		// --- Pagination ---
		$requested_page     = $request->get_param( 'page' );
		$requested_per_page = $request->get_param( 'per_page' );
		$page               = max( 1, $requested_page ? (int) $requested_page : 1 );
		$per_page           = max( 1, $requested_per_page ? (int) $requested_per_page : 10 );
		$offset             = ( $page - 1 ) * $per_page;
		$total              = count( $items );
		$paged              = array_slice( $items, $offset, $per_page );

		return rest_ensure_response(
			array(
				'items'      => $paged,
				'stats'      => $stats,
				'pagination' => array(
					'page'        => $page,
					'per_page'    => $per_page,
					'total_items' => $total,
					'total_pages' => (int) ceil( $total / $per_page ),
				),
			)
		);
	}

	/**
	 * Compute result statistics and, when requested, filter facets.
	 *
	 * The block uses this method while rendering the initial HTML. Frontend
	 * REST requests can skip facets because those controls are already present,
	 * avoiding repeated aggregation and a large response payload.
	 *
	 * @param array $items          Normalized Zotero items.
	 * @param bool  $include_facets Whether to include type, year, and author facets.
	 * @return array Result statistics.
	 */
	public static function compute_stats( $items, $include_facets = true ) {
		$stats = array(
			'total_items' => count( $items ),
			'total_types' => count( array_unique( array_filter( wp_list_pluck( $items, 'item_type' ) ) ) ),
			'year_range'  => self::compute_year_range( $items ),
		);

		if ( $include_facets ) {
			$stats['available_types']   = self::compute_type_facets( $items );
			$stats['available_years']   = self::compute_available_facets( $items, 'date_year' );
			$stats['available_authors'] = self::compute_list_facets( $items, 'creators' );
		}

		return $stats;
	}

	/**
	 * Compute the minimum and maximum publication years.
	 *
	 * @param array $items Normalized Zotero items.
	 * @return array Year range containing min and max values.
	 */
	private static function compute_year_range( $items ) {
		$years = array_filter( wp_list_pluck( $items, 'date_year' ) );
		if ( empty( $years ) ) {
			return array(
				'min' => '',
				'max' => '',
			);
		}
		return array(
			'min' => min( $years ),
			'max' => max( $years ),
		);
	}

	/**
	 * Count the available values for a facet field.
	 *
	 * @param array  $items Normalized Zotero items.
	 * @param string $field Item field to aggregate.
	 * @return array Facet values and counts.
	 */
	private static function compute_available_facets( $items, $field ) {
		$values = array_filter( wp_list_pluck( $items, $field ) );
		$counts = array_count_values( $values );
		if ( 'date_year' === $field ) {
			krsort( $counts, SORT_NATURAL );
		} else {
			ksort( $counts, SORT_NATURAL | SORT_FLAG_CASE );
		}
		$facets = array();
		foreach ( $counts as $value => $count ) {
			$facets[] = array(
				'value' => $value,
				'count' => $count,
			);
		}
		return $facets;
	}

	/**
	 * Count item types and include their readable labels.
	 *
	 * @param array $items Normalized Zotero items.
	 * @return array Facet values, labels, and counts.
	 */
	private static function compute_type_facets( $items ) {
		$facets = array();
		foreach ( $items as $item ) {
			$value = $item['item_type'];
			if ( ! $value ) {
				continue;
			}
			if ( ! isset( $facets[ $value ] ) ) {
				$facets[ $value ] = array(
					'value' => $value,
					'label' => $item['item_type_label'],
					'count' => 0,
				);
			}
			++$facets[ $value ]['count'];
		}
		uasort(
			$facets,
			function ( $first, $second ) {
				return strcasecmp( $first['label'], $second['label'] );
			}
		);

		return array_values( $facets );
	}

	/**
	 * Count values stored in a normalized item's list field.
	 *
	 * @param array  $items Normalized Zotero items.
	 * @param string $field List field to aggregate.
	 * @return array Facet values and counts.
	 */
	private static function compute_list_facets( $items, $field ) {
		$counts = array();
		foreach ( $items as $item ) {
			foreach ( $item[ $field ] as $value ) {
				$counts[ $value ] = isset( $counts[ $value ] ) ? $counts[ $value ] + 1 : 1;
			}
		}
		uksort( $counts, 'strnatcasecmp' );

		$facets = array();
		foreach ( $counts as $value => $count ) {
			$facets[] = array(
				'value' => $value,
				'count' => $count,
			);
		}

		return $facets;
	}
}
