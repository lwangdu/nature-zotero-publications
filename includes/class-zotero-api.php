<?php
/**
 * Zotero API client with transient-based caching.
 *
 * @package ZoteroDisplay
 */

namespace Zotero_Display;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches, normalizes, and caches Zotero library data.
 */
class Zotero_API {

	const API_BASE     = 'https://api.zotero.org';
	const REFRESH_HOOK = 'zotero_display_refresh_cache';

	/**
	 * Register the background cache refresh callback.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( self::REFRESH_HOOK, array( __CLASS__, 'refresh_cache' ) );
	}

	/**
	 * Fetch items for a library/collection, using a transient cache.
	 *
	 * @param array $args {
	 *     Query arguments.
	 *     @type string $library_type 'user' or 'group'.
	 *     @type string $library_id   Numeric Zotero library/user/group ID.
	 *     @type string $api_key      Zotero API key (optional for public libraries).
	 *     @type string $collection   Optional collection key.
	 *     @type string $sort         Zotero 'sort' param (e.g. 'date', 'title', 'creator').
	 *     @type string $direction    'asc' or 'desc'.
	 *     @type int    $limit        Max items to request from Zotero (cap 100 per call; we paginate internally up to a sane max).
	 *     @type string $item_type    Optional itemType filter passed to Zotero (e.g. 'journalArticle').
	 *     @type int    $cache_minutes Cache TTL override.
	 * }
	 * @return array|\WP_Error Array of normalized items, or WP_Error on failure.
	 */
	public static function get_items( array $args ) {
		$defaults = array(
			'library_type'  => 'user',
			'library_id'    => '',
			'api_key'       => '',
			'collection'    => '',
			'sort'          => 'date',
			'direction'     => 'desc',
			'limit'         => 1000,
			'item_type'     => '',
			'cache_minutes' => 60,
		);
		$args     = wp_parse_args( $args, $defaults );

		if ( empty( $args['library_id'] ) ) {
			return new \WP_Error( 'zotero_missing_library_id', __( 'A Zotero library ID is required.', 'nature-zotero-publications' ) );
		}

		$cache_key = self::build_cache_key( $args );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$stale = get_transient( $cache_key . '_stale' );
		if ( is_array( $stale ) ) {
			self::schedule_refresh( $args );
			return $stale;
		}

		$items = self::fetch_from_api( $args );

		if ( is_wp_error( $items ) ) {
			// On failure, fall back to a stale cache if one exists rather than showing nothing.
			$stale = get_transient( $cache_key . '_stale' );
			if ( is_array( $stale ) ) {
				return $stale;
			}
			return $items;
		}

		self::store_cached_items( $cache_key, $items, $args['cache_minutes'] );

		return $items;
	}

	/**
	 * Refresh a stale cache entry outside the visitor's page request.
	 *
	 * The API key is intentionally excluded from the scheduled event arguments
	 * and read from the server-side plugin settings only when the event runs.
	 *
	 * @param array $args Zotero query arguments without an API key.
	 * @return void
	 */
	public static function refresh_cache( $args ) {
		$settings        = Settings::get_settings();
		$args['api_key'] = $settings['api_key'];
		$items           = self::fetch_from_api( $args );

		if ( is_wp_error( $items ) ) {
			return;
		}

		self::store_cached_items( self::build_cache_key( $args ), $items, $args['cache_minutes'] );
	}

	/**
	 * Schedule one background refresh for a stale query.
	 *
	 * @param array $args Zotero query arguments.
	 * @return void
	 */
	private static function schedule_refresh( $args ) {
		unset( $args['api_key'] );
		$event_args = array( $args );

		if ( ! wp_next_scheduled( self::REFRESH_HOOK, $event_args ) ) {
			wp_schedule_single_event( time() + 1, self::REFRESH_HOOK, $event_args );
		}
	}

	/**
	 * Store the active cache and its longer-lived stale fallback.
	 *
	 * @param string $cache_key    Transient cache key.
	 * @param array  $items        Normalized Zotero items.
	 * @param int    $cache_minutes Active cache duration in minutes.
	 * @return void
	 */
	private static function store_cached_items( $cache_key, $items, $cache_minutes ) {
		$ttl = max( 1, (int) $cache_minutes ) * MINUTE_IN_SECONDS;
		set_transient( $cache_key, $items, $ttl );
		set_transient( $cache_key . '_stale', $items, 7 * DAY_IN_SECONDS );
	}

	/**
	 * Perform the actual HTTP request(s) to Zotero, paginating as needed, and normalize results.
	 *
	 * @param array $args Query args (already merged with defaults).
	 * @return array|\WP_Error
	 */
	private static function fetch_from_api( array $args ) {
		$library_segment = ( 'group' === $args['library_type'] ) ? 'groups' : 'users';

		$path = sprintf( '/%s/%s/items', $library_segment, rawurlencode( $args['library_id'] ) );
		if ( ! empty( $args['collection'] ) ) {
			$path = sprintf( '/%s/%s/collections/%s/items', $library_segment, rawurlencode( $args['library_id'] ), rawurlencode( $args['collection'] ) );
		}

		$per_page  = min( 100, max( 1, (int) $args['limit'] ) );
		$max_items = max( $per_page, (int) $args['limit'] ); // allow requesting more than 100 by paginating.
		$all_items = array();
		$start     = 0;

		do {
			$query = array(
				'format'    => 'json',
				'include'   => 'data',
				'sort'      => sanitize_key( $args['sort'] ),
				'direction' => ( 'asc' === $args['direction'] ) ? 'asc' : 'desc',
				'limit'     => $per_page,
				'start'     => $start,
			);

			if ( ! empty( $args['item_type'] ) ) {
				$query['itemType'] = sanitize_text_field( $args['item_type'] );
			}

			$url = add_query_arg( $query, self::API_BASE . $path );

			$request_args = array(
				'timeout' => 15,
				'headers' => array(
					'Zotero-API-Version' => '3',
				),
			);
			if ( ! empty( $args['api_key'] ) ) {
				$request_args['headers']['Authorization'] = 'Bearer ' . $args['api_key'];
			}

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return new \WP_Error(
					'zotero_api_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'Zotero API returned an error (HTTP %d).', 'nature-zotero-publications' ),
						$code
					)
				);
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) ) {
				return new \WP_Error( 'zotero_invalid_response', __( 'Zotero API returned an unreadable response.', 'nature-zotero-publications' ) );
			}

			foreach ( $body as $entry ) {
				$normalized = self::normalize_item( $entry, $args );
				if ( $normalized ) {
					$all_items[] = $normalized;
				}
			}

			$start                += $per_page;
			$got                   = count( $body );
			$normalized_item_count = count( $all_items );

		} while ( $got === $per_page && $normalized_item_count < $max_items );

		return array_slice( $all_items, 0, $max_items );
	}

	/**
	 * Normalize a raw Zotero item into the shape our block expects.
	 *
	 * @param array $entry Raw Zotero item entry.
	 * @param array $args  Original request args (used to build the Zotero web link).
	 * @return array|null
	 */
	private static function normalize_item( array $entry, array $args ) {
		$data = isset( $entry['data'] ) ? $entry['data'] : array();
		if ( ! empty( $data['parentItem'] ) ) {
			return null;
		}

		$creators = array();
		if ( ! empty( $data['creators'] ) && is_array( $data['creators'] ) ) {
			foreach ( $data['creators'] as $creator ) {
				if ( ! empty( $creator['name'] ) ) {
					$creators[] = $creator['name'];
				} elseif ( ! empty( $creator['lastName'] ) ) {
					$name = $creator['lastName'];
					if ( ! empty( $creator['firstName'] ) ) {
						$name = $creator['firstName'] . ' ' . $name;
					}
					$creators[] = $name;
				}
			}
		}

		$item_key    = isset( $entry['key'] ) ? $entry['key'] : '';
		$zotero_link = isset( $entry['links']['alternate']['href'] ) ? esc_url_raw( $entry['links']['alternate']['href'] ) : '';

		if ( ! $zotero_link ) {
			$library_segment = ( 'group' === $args['library_type'] ) ? 'groups' : 'users';
			$zotero_link     = sprintf(
				'https://www.zotero.org/%s/%s/items/%s',
				$library_segment,
				rawurlencode( $args['library_id'] ),
				rawurlencode( $item_key )
			);
		}

		$doi          = isset( $data['DOI'] ) ? self::normalize_doi( $data['DOI'] ) : '';
		$doi_url      = $doi ? 'https://doi.org/' . rawurlencode( $doi ) : '';
		$source_url   = ! empty( $data['url'] ) ? esc_url_raw( $data['url'] ) : '';
		$external_url = $source_url ? $source_url : $doi_url;
		$citation_key = self::extract_citation_key( $data );
		$item_type    = isset( $data['itemType'] ) ? $data['itemType'] : '';

		return array(
			'key'             => $item_key,
			'title'           => ! empty( $data['title'] ) ? wp_strip_all_tags( $data['title'] ) : __( 'Untitled', 'nature-zotero-publications' ),
			'creators'        => $creators,
			'creator_str'     => $creators ? implode( ', ', $creators ) : __( 'Unknown author', 'nature-zotero-publications' ),
			'date'            => isset( $data['date'] ) ? $data['date'] : '',
			'date_year'       => self::extract_year( isset( $data['date'] ) ? $data['date'] : '' ),
			'doi'             => $doi,
			'doi_url'         => $doi_url,
			'citation_key'    => $citation_key,
			'source_url'      => $source_url,
			'item_type'       => $item_type,
			'item_type_label' => self::format_item_type( $item_type ),
			'abstract'        => isset( $data['abstractNote'] ) ? wp_strip_all_tags( $data['abstractNote'] ) : '',
			'zotero_link'     => $zotero_link,
			'external_url'    => $external_url,
			'tags'            => isset( $data['tags'] ) ? wp_list_pluck( $data['tags'], 'tag' ) : array(),
			'publication'     => isset( $data['publicationTitle'] ) ? $data['publicationTitle'] : ( isset( $data['bookTitle'] ) ? $data['bookTitle'] : '' ),
		);
	}

	/**
	 * Normalize a DOI to its identifier, without a doi.org URL prefix.
	 *
	 * @param string $doi Raw DOI value.
	 * @return string
	 */
	private static function normalize_doi( $doi ) {
		$doi = trim( wp_strip_all_tags( $doi ) );
		$doi = preg_replace( '#^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)#i', '', $doi );

		return trim( $doi );
	}

	/**
	 * Read a native Zotero citation key, falling back to the legacy Better
	 * BibTeX value stored as "Citation Key: value" in the Extra field.
	 *
	 * @param array $data Zotero item data.
	 * @return string
	 */
	private static function extract_citation_key( array $data ) {
		if ( ! empty( $data['citationKey'] ) ) {
			return sanitize_text_field( $data['citationKey'] );
		}

		if ( ! empty( $data['extra'] ) && preg_match( '/^\s*citation\s*key\s*:\s*(.+?)\s*$/im', $data['extra'], $matches ) ) {
			return sanitize_text_field( $matches[1] );
		}

		return '';
	}

	/**
	 * Pull a 4-digit year out of a free-form Zotero date string.
	 *
	 * @param string $date_str Raw date string.
	 * @return string
	 */
	private static function extract_year( $date_str ) {
		if ( preg_match( '/\b(1[5-9]\d{2}|20\d{2})\b/', $date_str, $matches ) ) {
			return $matches[1];
		}
		return '';
	}

	/**
	 * Convert a Zotero item type key into a readable label.
	 *
	 * @param string $item_type Zotero item type key.
	 * @return string
	 */
	private static function format_item_type( $item_type ) {
		$label = preg_replace( '/([a-z])([A-Z])/', '$1 $2', $item_type );

		return ucwords( $label );
	}

	/**
	 * Build a unique, deterministic transient key for a given query.
	 *
	 * @param array $args Normalized query args.
	 * @return string
	 */
	private static function build_cache_key( array $args ) {
		$relevant = array(
			'normalization_version' => 6,
			'library_type'          => $args['library_type'],
			'library_id'            => $args['library_id'],
			'collection'            => $args['collection'],
			'sort'                  => $args['sort'],
			'direction'             => $args['direction'],
			'limit'                 => $args['limit'],
			'item_type'             => $args['item_type'],
		);
		$hash     = md5( wp_json_encode( $relevant ) );
		// Transient keys have a 172 char limit on key name (longer with object cache), keep it short.
		return ZOTERO_DISPLAY_TRANSIENT_PREFIX . $hash;
	}

	/**
	 * Clear every transient this plugin has created.
	 *
	 * Uses a direct DB query since WP core has no "delete by prefix" helper for transients.
	 */
	public static function clear_all_caches() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_' . ZOTERO_DISPLAY_TRANSIENT_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- WordPress has no API for deleting transients by prefix.

		$like_timeout = $wpdb->esc_like( '_transient_timeout_' . ZOTERO_DISPLAY_TRANSIENT_PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- WordPress has no API for deleting transient timeouts by prefix.

		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush_group( ZOTERO_DISPLAY_CACHE_GROUP );
		}
	}
}
