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
					'library_type'    => array( 'type' => 'string' ),
					'library_id'      => array( 'type' => 'string' ),
					'collection'      => array( 'type' => 'string' ),
					'sort'            => array( 'type' => 'string' ),
					'direction'       => array( 'type' => 'string' ),
					'item_type'       => array( 'type' => 'string' ),
					'search'          => array( 'type' => 'string' ),
					'filter_type'     => array( 'type' => 'string' ),
					'filter_year'     => array( 'type' => 'string' ),
					'filter_author'   => array( 'type' => 'string' ),
					'include_facets'  => array( 'type' => 'boolean' ),
					'include_authors' => array( 'type' => 'boolean' ),
					'page'            => array( 'type' => 'integer' ),
					'per_page'        => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/authors',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_authors' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'library_type' => array( 'type' => 'string' ),
					'library_id'   => array( 'type' => 'string' ),
					'collection'   => array( 'type' => 'string' ),
					'sort'         => array( 'type' => 'string' ),
					'direction'    => array( 'type' => 'string' ),
					'item_type'    => array( 'type' => 'string' ),
					'search'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit'        => array(
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 50,
					),
				),
			)
		);
	}

	/**
	 * Build the canonical query args for the local Zotero index from request + saved settings,
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

		$search             = $request->get_param( 'search' );
		$filter_type        = $request->get_param( 'filter_type' );
		$filter_year        = $request->get_param( 'filter_year' );
		$filter_author      = $request->get_param( 'filter_author' );
		$include_facets     = $request->get_param( 'include_facets' );
		$include_facets     = null === $include_facets || rest_sanitize_boolean( $include_facets );
		$include_authors    = rest_sanitize_boolean( $request->get_param( 'include_authors' ) );
		$requested_page     = $request->get_param( 'page' );
		$requested_per_page = $request->get_param( 'per_page' );
		$page               = max( 1, $requested_page ? (int) $requested_page : 1 );
		$per_page           = max( 1, $requested_per_page ? (int) $requested_per_page : 100 );
		$sync_result        = Sync::get_results(
			$query_args,
			array(
				'search' => $search,
				'type'   => $filter_type,
				'year'   => $filter_year,
				'author' => $filter_author,
			),
			$page,
			$per_page,
			$include_facets,
			$include_authors
		);

		if ( false !== $sync_result ) {
			return rest_ensure_response( $sync_result );
		}

		Sync::prime_first_batch( $query_args );
		$sync_result = Sync::get_results(
			$query_args,
			array(
				'search' => $search,
				'type'   => $filter_type,
				'year'   => $filter_year,
				'author' => $filter_author,
			),
			$page,
			$per_page,
			$include_facets,
			$include_authors
		);

		if ( false !== $sync_result ) {
			return rest_ensure_response( $sync_result );
		}

		return new \WP_REST_Response(
			array(
				'items'      => array(),
				'sync'       => Sync::get_public_state( $query_args ),
				'stats'      => array( 'total_items' => 0 ),
				'pagination' => array(
					'page'        => 1,
					'per_page'    => $per_page,
					'total_items' => 0,
					'total_pages' => 0,
				),
			),
			202
		);
	}

	/**
	 * GET /zotero-display/v1/authors
	 *
	 * Return a small, on-demand set of matching author suggestions from the
	 * completed local index instead of embedding the full author list in HTML.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_authors( \WP_REST_Request $request ) {
		$query_args = $this->resolve_query_args( $request );
		$search     = (string) $request->get_param( 'search' );

		if ( empty( $query_args['library_id'] ) ) {
			return new \WP_Error(
				'zotero_display_no_library',
				__( 'No Zotero library ID configured.', 'nature-zotero-publications' ),
				array( 'status' => 400 )
			);
		}

		if ( 2 > mb_strlen( $search ) ) {
			return rest_ensure_response( array( 'authors' => array() ) );
		}

		$authors = Sync::get_authors( $query_args, $search, $request->get_param( 'limit' ) );
		if ( false === $authors ) {
			return rest_ensure_response( array( 'authors' => array() ) );
		}

		return rest_ensure_response( array( 'authors' => $authors ) );
	}
}
