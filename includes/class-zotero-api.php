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
		$valid = Sync::validate_source_args( $args );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$retry_at = (int) get_transient( 'zotero_display_backoff' );
		if ( $retry_at > time() ) {
			return new \WP_Error( 'zotero_backoff', __( 'Waiting before retrying Zotero.', 'publications-list-display-for-zotero' ), array( 'retry_after' => $retry_at - time() ) );
		}
		$library_segment = ( 'group' === $args['library_type'] ) ? 'groups' : 'users';
		$path            = sprintf( '/%s/%s/items/top', $library_segment, rawurlencode( $args['library_id'] ) );

		if ( ! empty( $args['collection'] ) ) {
			$path = sprintf( '/%s/%s/collections/%s/items/top', $library_segment, rawurlencode( $args['library_id'] ), rawurlencode( $args['collection'] ) );
		}

		$query = array(
			'format'    => 'json',
			'include'   => 'data',
			'sort'      => sanitize_text_field( $args['sort'] ),
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
			$request_args['headers']['Zotero-API-Key'] = trim( $args['api_key'] );
		}

		if ( ! empty( $args['conditional_version'] ) && 0 === (int) $start ) {
			$request_args['headers']['If-Modified-Since-Version'] = (int) $args['conditional_version'];
		}
		$response = wp_remote_get( add_query_arg( $query, self::API_BASE . $path ), $request_args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$backoff = max( 0, (int) wp_remote_retrieve_header( $response, 'backoff' ) );
		$retry   = wp_remote_retrieve_header( $response, 'retry-after' );
		$retry   = is_numeric( $retry ) ? (int) $retry : max( 0, (int) strtotime( (string) $retry ) - time() );
		// The server deadline is a minimum wait, not a client-side timeout.
		$backoff = max( $backoff, $retry );
		if ( 429 === $code && ! $backoff ) {
			$attempts = min( 8, 1 + (int) get_transient( 'zotero_display_backoff_attempts' ) );
			set_transient( 'zotero_display_backoff_attempts', $attempts, HOUR_IN_SECONDS );
			$backoff = 5 * ( 2 ** $attempts );
		} elseif ( $code >= 200 && $code < 400 ) {
			delete_transient( 'zotero_display_backoff_attempts' );
		}
		if ( $backoff ) {
			set_transient( 'zotero_display_backoff', time() + $backoff, $backoff );
		}
		if ( 304 === $code && ! empty( $args['conditional_version'] ) ) {
			return array( 'not_modified' => true );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'zotero_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Zotero API returned an error (HTTP %d).', 'publications-list-display-for-zotero' ),
					$code
				),
				array(
					'status'       => $code,
					'retry_after'  => $backoff,
					'admin_detail' => self::diagnostic_response( $response, $args['api_key'] ?? '' ),
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'zotero_invalid_response', __( 'Zotero API returned an unreadable response.', 'publications-list-display-for-zotero' ) );
		}

		$items = array();
		foreach ( $body as $offset => $entry ) {
			$normalized = is_array( $entry ) ? self::normalize_item( $entry, $args ) : null;
			if ( $normalized ) {
				$items[ $offset ] = $normalized;
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
	 * Reduce an error response to bounded, credential-redacted plain text.
	 *
	 * @param array  $response HTTP response.
	 * @param string $api_key Request credential, used only for redaction.
	 * @return string Safe administrator detail; never a public error message.
	 */
	public static function diagnostic_response( $response, $api_key = '' ) {
		$body = (string) wp_remote_retrieve_body( $response );
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		if ( str_contains( $type, 'html' ) || preg_match( '/<\s*(?:!doctype|html|body)/i', $body ) ) {
			$details = array( __( 'Zotero response details', 'publications-list-display-for-zotero' ) );
			// Read only identifying headers, never cookies, authentication or locations.
			foreach ( array( 'content-type', 'server', 'via', 'zotero-api-version' ) as $header ) {
				$value = wp_remote_retrieve_header( $response, $header );
				if ( is_string( $value ) && '' !== $value ) {
					$details[] = $header . ': ' . self::redact_diagnostic_text( $value, $api_key );
				}
			}
			if ( strlen( $body ) <= 32768 ) {
				$found = false;
				foreach ( array( 'title', 'h1', 'p' ) as $tag ) {
					if ( preg_match( '~<' . $tag . '\b[^>]*>(.*?)</' . $tag . '\s*>~is', $body, $match ) ) {
						$found     = true;
						$details[] = $tag . ': ' . self::redact_diagnostic_text( $match[1], $api_key );
					}
				}
				if ( ! $found ) {
					$details[] = 'body: ' . self::redact_diagnostic_text( $body, $api_key );
				}
			} else {
				$details[] = __( 'HTML body omitted because it exceeds the diagnostic size limit.', 'publications-list-display-for-zotero' );
			}
			return implode( ' | ', $details );
		}
		if ( strlen( $body ) > 4096 ) {
			return __( 'The error response was too large to display safely.', 'publications-list-display-for-zotero' );
		}
		$json = json_decode( $body, true );
		if ( is_array( $json ) ) {
			$body = $json['message'] ?? $json['error'] ?? '';
			$body = is_string( $body ) ? $body : '';
		}
		return self::redact_diagnostic_text( $body, $api_key );
	}

	/**
	 * Strip markup and redact credentials before limiting diagnostic text.
	 *
	 * @param string $body Diagnostic text.
	 * @param string $api_key Credential to remove.
	 * @return string Plain redacted text.
	 */
	private static function redact_diagnostic_text( $body, $api_key ) {
		if ( strlen( $body ) > 32768 ) {
			return __( 'Diagnostic value omitted because it exceeds the size limit.', 'publications-list-display-for-zotero' );
		}
		$body = html_entity_decode( rawurldecode( $body ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$body = wp_strip_all_tags( $body );
		if ( '' !== $api_key ) {
			$body = str_replace( array( $api_key, base64_encode( $api_key ) ), '[redacted]', $body );
		}
		$body = preg_replace( '~https?://[^\s<>]+~i', '[URL removed]', $body );
		$body = preg_replace( '/(?:authorization|zotero-api-key|api[_ -]?key|token|password|cookie)\s*[:=]\s*[^\r\n]+/i', '[credential removed]', $body );
		$body = preg_replace( '/[A-Za-z0-9_+\/=-]{24,}/', '[token removed]', $body );
		$body = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email removed]', $body );
		$body = preg_replace( '/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/', '[IP removed]', $body );
		$body = sanitize_text_field( $body );
		return '' !== $body ? mb_substr( $body, 0, 400, 'UTF-8' ) : __( 'No plain-text explanation was returned.', 'publications-list-display-for-zotero' );
	}

	/**
	 * Normalize a raw Zotero item into the shape our block expects.
	 *
	 * @param array $entry Raw Zotero item entry.
	 * @param array $args  Original request args (used to build the Zotero web link).
	 * @return array|null
	 */
	private static function normalize_item( array $entry, array $args ) {
		$data = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
		foreach ( array( 'title', 'date', 'DOI', 'url', 'itemType', 'abstractNote', 'publicationTitle', 'bookTitle', 'citationKey', 'extra' ) as $field ) {
			if ( isset( $data[ $field ] ) && ! is_string( $data[ $field ] ) ) {
				$data[ $field ] = '';
			}
		}
		$tags = array();
		foreach ( is_array( $data['tags'] ?? null ) ? $data['tags'] : array() as $tag ) {
			if ( is_array( $tag ) && isset( $tag['tag'] ) && is_string( $tag['tag'] ) ) {
				$tags[] = $tag['tag'];
			}
		}
		if ( ! empty( $data['parentItem'] ) ) {
			return null;
		}

		$creators = array();
		if ( ! empty( $data['creators'] ) && is_array( $data['creators'] ) ) {
			foreach ( $data['creators'] as $creator ) {
				if ( ! is_array( $creator ) ) {
					continue;
				}
				foreach ( array( 'name', 'lastName', 'firstName' ) as $field ) {
					if ( isset( $creator[ $field ] ) && ! is_string( $creator[ $field ] ) ) {
						$creator[ $field ] = '';
					}
				}
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

		$item_key    = isset( $entry['key'] ) && is_string( $entry['key'] ) ? $entry['key'] : '';
		$zotero_link = isset( $entry['links']['alternate']['href'] ) && is_string( $entry['links']['alternate']['href'] ) ? esc_url_raw( $entry['links']['alternate']['href'] ) : '';

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
		$doi_url      = $doi ? esc_url_raw( 'https://doi.org/' . implode( '/', array_map( 'rawurlencode', explode( '/', $doi ) ) ) ) : '';
		$source_url   = ! empty( $data['url'] ) ? esc_url_raw( $data['url'] ) : '';
		$external_url = $source_url ? $source_url : $doi_url;
		$citation_key = self::extract_citation_key( $data );
		$item_type    = isset( $data['itemType'] ) ? $data['itemType'] : '';

		return array(
			'key'             => $item_key,
			'title'           => isset( $data['title'] ) && is_string( $data['title'] ) && '' !== $data['title'] ? $data['title'] : __( 'Untitled', 'publications-list-display-for-zotero' ),
			'creators'        => $creators,
			'creator_str'     => $creators ? implode( ', ', $creators ) : __( 'Unknown author', 'publications-list-display-for-zotero' ),
			'date'            => isset( $data['date'] ) ? $data['date'] : '',
			'date_year'       => self::extract_year( isset( $data['date'] ) ? $data['date'] : '' ),
			'doi'             => $doi,
			'doi_url'         => $doi_url,
			'citation_key'    => $citation_key,
			'source_url'      => $source_url,
			'item_type'       => $item_type,
			'item_type_label' => self::format_item_type( $item_type ),
			'abstract'        => isset( $data['abstractNote'] ) && is_string( $data['abstractNote'] ) ? $data['abstractNote'] : '',
			'zotero_link'     => $zotero_link,
			'external_url'    => $external_url,
			'tags'            => $tags,
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
	 * Clear cached publications through cache-aware APIs, retaining API cooldowns.
	 */
	public static function clear_all_caches() {
		global $wpdb;
		Block::clear_fragment_cache();
		// Enumerate bounded cache slots for each registered source, including Redis transients.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( Sync::STATE_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Enumerate plugin-owned source state.
		foreach ( $names as $name ) {
			$key = substr( $name, strlen( Sync::STATE_PREFIX ) );
			foreach ( array( 'results', 'authors', 'stats' ) as $kind ) {
				for ( $slot = 0; $slot < 32; ++$slot ) {
					delete_transient( 'zotero_display_' . $kind . '_' . $key . '_' . $slot );
				}
			}
			delete_transient( 'zotero_display_schedule_error_' . $key );
		}
		// Clean legacy database transients, without deleting Zotero's rate-limit state.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_' . ZOTERO_DISPLAY_TRANSIENT_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Discover legacy plugin transients for API-based deletion.
		foreach ( $names as $name ) {
			$key = substr( $name, strlen( '_transient_' ) );
			if ( ! in_array( $key, array( 'zotero_display_backoff', 'zotero_display_backoff_attempts' ), true ) ) {
				delete_transient( $key );
			}
		}
	}
}
