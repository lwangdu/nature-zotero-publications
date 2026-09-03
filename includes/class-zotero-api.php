<?php
/**
 * Zotero API client for background synchronization.
 *
 * @package ZoteroDisplay
 */

namespace Zotero_Display;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and normalizes Zotero library data.
 */
class Zotero_API {

	const API_BASE = 'https://api.zotero.org';

	/**
	 * Fetch one page of top-level Zotero items for a background sync.
	 *
	 * @param array $args  Zotero query arguments.
	 * @param int   $start Zero-based Zotero result offset.
	 * @param int   $limit Number of items to request, capped at 100 by Zotero.
	 * @return array|\WP_Error Page data or an error.
	 */
	public static function fetch_page( array $args, $start = 0, $limit = 100 ) {
		$library_segment = ( 'group' === $args['library_type'] ) ? 'groups' : 'users';
		$path            = sprintf( '/%s/%s/items/top', $library_segment, rawurlencode( $args['library_id'] ) );

		if ( ! empty( $args['collection'] ) ) {
			$path = sprintf( '/%s/%s/collections/%s/items/top', $library_segment, rawurlencode( $args['library_id'] ), rawurlencode( $args['collection'] ) );
		}

		$query = array(
			'format'    => 'json',
			'include'   => 'data',
			'sort'      => sanitize_key( $args['sort'] ),
			'direction' => ( 'asc' === $args['direction'] ) ? 'asc' : 'desc',
			'limit'     => min( 100, max( 1, (int) $limit ) ),
			'start'     => max( 0, (int) $start ),
		);

		if ( ! empty( $args['item_type'] ) ) {
			$query['itemType'] = sanitize_text_field( $args['item_type'] );
		}

		$request_args = array(
			'timeout' => 20,
			'headers' => array(
				'Zotero-API-Version' => '3',
			),
		);
		if ( ! empty( $args['api_key'] ) ) {
			$request_args['headers']['Authorization'] = 'Bearer ' . $args['api_key'];
		}

		$response = wp_remote_get( add_query_arg( $query, self::API_BASE . $path ), $request_args );
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
				),
				array( 'status' => $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'zotero_invalid_response', __( 'Zotero API returned an unreadable response.', 'nature-zotero-publications' ) );
		}

		$items = array();
		foreach ( $body as $entry ) {
			$normalized = self::normalize_item( $entry, $args );
			if ( $normalized ) {
				$items[] = $normalized;
			}
		}

		return array(
			'items'                 => $items,
			'raw_count'             => count( $body ),
			'total'                 => absint( wp_remote_retrieve_header( $response, 'total-results' ) ),
			'last_modified_version' => absint( wp_remote_retrieve_header( $response, 'last-modified-version' ) ),
		);
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
		$doi_url      = $doi ? esc_url_raw( 'https://doi.org/' . $doi ) : '';
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
