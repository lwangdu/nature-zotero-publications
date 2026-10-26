<?php
/**
 * Resumable full-library Zotero synchronization and local querying.
 *
 * @package ZoteroDisplay
 */

namespace Zotero_Display;

defined( 'ABSPATH' ) || exit;

// The dedicated tables are the persistent cache, so adding an object-cache layer would duplicate the complete index.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Maintains a complete local index of top-level Zotero items.
 */
class Sync {

	const HOOK             = 'zotero_display_sync_batch';
	const SCHEMA_VERSION   = 2;
	const SCHEMA_OPTION    = 'zotero_display_schema_version';
	const STATE_PREFIX     = 'zotero_display_sync_';
	const PAGE_SIZE        = 100;
	const PAGES_PER_RUN    = 4;
	const BATCH_TIME_LIMIT = 12;
	const RETRY_DELAY      = 300;
	const WRITE_BATCH_SIZE = 40;
	const NETWORK_RETRIES  = 3;

	/**
	 * Register synchronization hooks and ensure the schema exists.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_action( self::HOOK, array( __CLASS__, 'run_batch' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
	}

	/**
	 * Create or update the local-index tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$items_table    = self::items_table();
		$creators_table = self::creators_table();
		$charset        = $wpdb->get_charset_collate();

		$items_sql = "CREATE TABLE {$items_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_key char(32) NOT NULL,
			item_key varchar(64) NOT NULL,
			sort_index bigint(20) unsigned NOT NULL DEFAULT 0,
			item_type varchar(64) NOT NULL DEFAULT '',
			item_type_label varchar(191) NOT NULL DEFAULT '',
			date_year varchar(4) NOT NULL DEFAULT '',
			search_text longtext NOT NULL,
			data_json longtext NOT NULL,
			sync_token char(32) NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_item (source_key,item_key),
			KEY source_sort (source_key,sort_index),
			KEY source_token_sort (source_key,sync_token,sort_index),
			KEY source_type (source_key,item_type),
			KEY source_token_type (source_key,sync_token,item_type),
			KEY source_year (source_key,date_year),
			KEY source_token_year (source_key,sync_token,date_year),
			KEY source_sync (source_key,sync_token)
		) {$charset};";

		$creators_sql = "CREATE TABLE {$creators_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_key char(32) NOT NULL,
			item_key varchar(64) NOT NULL,
			creator_name varchar(191) NOT NULL,
			creator_hash char(32) NOT NULL,
			sync_token char(32) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_item_creator (source_key,item_key,creator_hash),
			KEY source_creator (source_key,creator_name),
			KEY source_token_creator (source_key,sync_token,creator_name),
			KEY source_item (source_key,item_key),
			KEY source_sync (source_key,sync_token)
		) {$charset};";

		dbDelta( $items_sql );
		dbDelta( $creators_sql );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Upgrade the schema when the plugin version requires it.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::SCHEMA_VERSION !== (int) get_option( self::SCHEMA_OPTION, 0 ) ) {
			self::install();
		}
	}

	/**
	 * Validate source identifiers before querying or scheduling a source.
	 *
	 * @param array $args Resolved source arguments, including saved defaults.
	 * @return true|\WP_Error True for valid identifiers, otherwise a client error.
	 */
	public static function validate_source_args( array $args ) {
		$library_id = $args['library_id'] ?? '';
		$collection = $args['collection'] ?? '';

		if ( ! is_string( $library_id ) || ! preg_match( '/\A[0-9]+\z/', $library_id ) ) {
			return new \WP_Error(
				'zotero_display_invalid_library_id',
				__( 'The Zotero library ID must contain only digits.', 'nature-zotero-publications' ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_string( $collection ) || ( '' !== $collection && ! preg_match( '/\A[A-Z0-9]{8}\z/', $collection ) ) ) {
			return new \WP_Error(
				'zotero_display_invalid_collection',
				__( 'The Zotero collection key must contain exactly eight uppercase letters or digits, or be empty for the whole library.', 'nature-zotero-publications' ),
				array( 'status' => 400 )
			);
		}

		$sorts = array( 'date', 'title', 'creator', 'dateAdded', 'dateModified', 'publicationTitle', 'itemType', 'publisher', 'journalAbbreviation', 'language', 'accessDate', 'libraryCatalog', 'callNumber', 'rights', 'addedBy' );
		if ( ! in_array( $args['sort'] ?? 'date', $sorts, true ) || ! in_array( $args['direction'] ?? 'desc', array( 'asc', 'desc' ), true ) ) {
			return new \WP_Error( 'zotero_display_invalid_sort', __( 'Choose a supported Zotero sort and direction.', 'nature-zotero-publications' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Schedule a full sync if this source is new, stale, failed, or interrupted.
	 *
	 * @param array $args Zotero source query arguments.
	 * @return array Current sync state.
	 */
	public static function ensure_scheduled( array $args ) {
		$args       = self::normalize_source_args( $args );
		$source_key = self::source_key( $args );
		$state      = self::get_state( $source_key );
		$ttl        = max( 1, (int) $args['cache_minutes'] ) * MINUTE_IN_SECONDS;
		if ( ! empty( $state['source_args'] ) ) {
			if ( 'syncing' === $state['status'] ) {
				self::schedule_batch( $source_key, max( 1, (int) ( $state['retry_at'] ?? 0 ) - time() ) );
				return $state;
			}
			if ( ( 'error' === $state['status'] && time() - (int) $state['updated_at'] < self::RETRY_DELAY ) || ( 'complete' === $state['status'] && time() - (int) $state['last_sync'] < $ttl ) ) {
				return $state;
			}
		}
		$lock_key = 'zotero_sync_lock_' . $source_key;
		$lock     = self::acquire_lock( $lock_key );
		if ( false === $lock ) {
			return self::get_state( $source_key );
		}
		try {
			// Re-read after acquiring the mutex: another request may have started a sync.
			wp_cache_delete( self::STATE_PREFIX . $source_key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$state = self::get_state( $source_key );
			$stale = empty( $state['last_sync'] ) || time() - (int) $state['last_sync'] >= $ttl;
			$retry = 'error' === $state['status'] && time() - (int) $state['updated_at'] >= self::RETRY_DELAY;
			if ( 'error' === $state['status'] && ! $retry ) {
				return $state;
			}
			if ( empty( $state['source_args'] ) || ( $stale && 'syncing' !== $state['status'] ) || $retry ) {
				$state = self::start_sync( $source_key, $args, ! empty( $state['ready'] ) );
			}
			if ( 'syncing' === $state['status'] ) {
				self::schedule_batch( $source_key, max( 1, (int) ( $state['retry_at'] ?? 0 ) - time() ) );
			}
			return $state;
		} finally {
			self::release_lock( $lock_key, $lock );
		}
	}

	/**
	 * Run a bounded number of Zotero pages for one source.
	 *
	 * @param string $source_key Source identifier.
	 * @param int    $max_pages  Maximum pages for this run, bounded by PAGES_PER_RUN.
	 * @return void
	 */
	public static function run_batch( $source_key, $max_pages = self::PAGES_PER_RUN ) {
		$source_key = sanitize_key( $source_key );
		$lock_key   = 'zotero_sync_lock_' . $source_key;
		$lock       = self::acquire_lock( $lock_key );

		if ( false === $lock ) {
			self::schedule_batch( $source_key, 30 );
			return;
		}

		try {
			// Discard progress cached before another worker released the mutex.
			wp_cache_delete( self::STATE_PREFIX . $source_key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$state = self::get_state( $source_key );

			if ( 'syncing' !== $state['status'] || empty( $state['source_args'] ) || empty( $state['sync_token'] ) ) {
				return;
			}

			if ( (int) ( $state['retry_at'] ?? 0 ) > time() ) {
				self::schedule_batch( $source_key, $state['retry_at'] - time() );
				return;
			}
			$settings        = Settings::get_settings();
			$args            = $state['source_args'];
			$args['api_key'] = $settings['api_key'];
			if ( ! empty( $state['ready'] ) && 2 === (int) ( $state['normalization_version'] ?? 0 ) ) {
				$args['conditional_version'] = (int) $state['active_version'];
			}
			$last_modified_version = isset( $state['last_modified_version'] ) ? (int) $state['last_modified_version'] : 0;
			$completed             = false;
			$batch_started         = microtime( true );

			$max_pages = min( self::PAGES_PER_RUN, max( 1, (int) $max_pages ) );
			for ( $page_number = 0; $page_number < $max_pages; ++$page_number ) {
				$page = Zotero_API::fetch_page( $args, (int) $state['next_start'], self::PAGE_SIZE );
				if ( is_wp_error( $page ) ) {
					if ( 'http_request_failed' === $page->get_error_code() ) {
						$attempt = (int) ( $state['network_retries'] ?? 0 );
						if ( $attempt < self::NETWORK_RETRIES ) {
							$delay                    = MINUTE_IN_SECONDS * ( 2 ** $attempt );
							$state['network_retries'] = $attempt + 1;
							$state['retry_at']        = time() + $delay;
							$state['updated_at']      = time();
							self::set_state( $source_key, $state );
							self::schedule_batch( $source_key, $delay );
							return;
						}
					}
					$error_data = $page->get_error_data();
					if ( ! empty( $error_data['retry_after'] ) ) {
						$state['retry_at'] = time() + (int) $error_data['retry_after'];
						self::set_state( $source_key, $state );
						self::schedule_batch( $source_key, (int) $error_data['retry_after'] );
						return;
					}
					$state['status']       = 'error';
					$state['error']        = $page->get_error_message();
					$state['admin_detail'] = is_array( $error_data ) ? ( $error_data['admin_detail'] ?? '' ) : '';
					$state['updated_at']   = time();
					self::set_state( $source_key, $state );
					return;
				}

				$state['admin_detail']    = '';
				$state['network_retries'] = 0;
				$state['retry_at']        = 0;
				if ( ! empty( $page['not_modified'] ) ) {
					$state['status']     = 'complete';
					$state['total']      = (int) ( $state['active_total'] ?? $state['total'] );
					$state['processed']  = $state['total'];
					$state['last_sync']  = time();
					$state['updated_at'] = time();
					$state['error']      = '';
					self::set_state( $source_key, $state );
					return;
				}
				if ( 2 === (int) ( $state['normalization_version'] ?? 0 ) && ! empty( $state['ready'] ) && 0 === (int) $state['next_start'] && ! empty( $state['active_version'] ) && (int) $state['active_version'] === (int) $page['last_modified_version'] ) {
					$state['status']       = 'complete';
					$state['processed']    = (int) $page['total'];
					$state['total']        = (int) $page['total'];
					$state['active_total'] = (int) $page['total'];
					$state['last_sync']    = time();
					$state['updated_at']   = time();
					$state['error']        = '';
					self::set_state( $source_key, $state );
					return;
				}

				if ( $last_modified_version && ! empty( $page['last_modified_version'] ) && $last_modified_version !== (int) $page['last_modified_version'] ) {
					self::start_sync( $source_key, $state['source_args'], ! empty( $state['ready'] ) );
					return;
				}

				$stored = self::store_page( $source_key, $state['sync_token'], (int) $state['next_start'], $page['items'] );
				if ( is_wp_error( $stored ) ) {
					$state['status']     = 'error';
					$state['error']      = $stored->get_error_message();
					$state['updated_at'] = time();
					self::set_state( $source_key, $state );
					return;
				}

				$state['total']                 = (int) $page['total'];
				$state['processed']            += (int) $page['raw_count'];
				$state['next_start']           += (int) $page['raw_count'];
				$state['updated_at']            = time();
				$last_modified_version          = max( $last_modified_version, (int) $page['last_modified_version'] );
				$state['last_modified_version'] = $last_modified_version;
				self::set_state( $source_key, $state );

				if ( 0 === (int) $page['raw_count'] || ( $state['total'] && $state['next_start'] >= $state['total'] ) || (int) $page['raw_count'] < self::PAGE_SIZE ) {
					$completed = true;
					break;
				}

				if ( ( microtime( true ) - $batch_started ) >= self::BATCH_TIME_LIMIT ) {
					break;
				}
			}

			if ( $completed ) {
				self::complete_sync( $source_key, $state );
			} else {
				self::schedule_batch( $source_key );
			}
		} finally {
			self::release_lock( $lock_key, $lock );
		}
	}

	/**
	 * Acquire an expiring database mutex using the unique option name.
	 *
	 * @param string $key Lock name.
	 * @return string|false Owner value, or false when another worker owns it.
	 */
	private static function acquire_lock( $key ) {
		global $wpdb;
		$value = ( time() + 120 ) . ':' . wp_generate_uuid4();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic insert relies on the unique option name.
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $key, $value, 'off' ) );
		if ( 1 === $inserted ) {
			return $value;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Read the lock directly, without an option-cache race.
		$old = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $key ) );
		if ( $old && (int) $old < time() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Compare-and-swap prevents two workers reclaiming an expired lock.
			$replaced = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s', $wpdb->options, $value, $key, $old ) );
			if ( 1 === $replaced ) {
				return $value;
			}
		}
		return false;
	}

	/**
	 * Release only the mutex owned by this worker.
	 *
	 * @param string $key Lock name.
	 * @param string $value Owner value.
	 * @return void
	 */
	private static function release_lock( $key, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Ownership check protects a newer lock from an expired worker.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, $key, $value ) );
	}

	/**
	 * Query a completed local index.
	 *
	 * @param array $args           Zotero source query arguments.
	 * @param array $filters        Search and facet filters.
	 * @param int   $page           One-based result page.
	 * @param int   $per_page       Items per result page.
	 * @param bool  $include_facets  Whether to aggregate filter facets.
	 * @param bool  $include_authors Whether to include the potentially large author facet.
	 * @return array|false Results, or false before the first indexed page is available.
	 */
	public static function get_results( array $args, array $filters, $page, $per_page, $include_facets = true, $include_authors = false ) {
		global $wpdb;

		$source_key = self::source_key( self::normalize_source_args( $args ) );
		$state      = self::get_state( $source_key );
		$sync_token = ! empty( $state['ready'] ) ? $state['active_token'] : ( isset( $state['sync_token'] ) ? $state['sync_token'] : '' );
		if ( empty( $sync_token ) || ( empty( $state['ready'] ) && empty( $state['processed'] ) ) ) {
			return false;
		}

		$page      = max( 1, (int) $page );
		$per_page  = min( 100, max( 1, (int) $per_page ) );
		$identity  = md5( wp_json_encode( array( $source_key, $sync_token, $state['processed'], $filters, $page, $per_page, $include_facets, $include_authors ) ) );
		$cache_key = 'zotero_display_results_' . $source_key . '_' . ( hexdec( substr( $identity, 0, 4 ) ) % 32 );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && $identity === $cached['identity'] ) {
			$cached['data']['sync'] = self::public_state( $state );
			return $cached['data'];
		}
		$offset         = ( $page - 1 ) * $per_page;
		$where          = self::build_where( $source_key, $sync_token, $filters );
		$items_table    = self::items_table();
		$prepared_where = self::prepare_sql( $where['sql'], $where['params'] );
		$total_sql      = "SELECT COUNT(*) FROM {$items_table} i WHERE {$prepared_where}";
		$total          = (int) $wpdb->get_var( $total_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name; all request values are prepared above.
		// The table name is plugin-owned and every request value in the WHERE fragment has already been prepared.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows_sql = $wpdb->prepare(
			"SELECT i.data_json FROM {$items_table} i WHERE {$prepared_where} ORDER BY i.sort_index ASC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_col( $rows_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name; all request values are prepared above.
		$items = array();

		foreach ( $rows as $row ) {
			$item = json_decode( $row, true );
			if ( is_array( $item ) ) {
				$items[] = $item;
			}
		}

		$stats = $include_facets
			? self::get_stats( $where, true, $include_authors )
			: array( 'total_items' => $total );

		$result = array(
			'items'      => $items,
			'sync'       => self::public_state( $state ),
			'stats'      => $stats,
			'pagination' => array(
				'page'        => $page,
				'per_page'    => $per_page,
				'total_items' => $total,
				'total_pages' => (int) ceil( $total / $per_page ),
			),
		);
		set_transient(
			$cache_key,
			array(
				'identity' => $identity,
				'data'     => $result,
			),
			15
		);
		return $result;
	}

	/**
	 * Search the active local creator index without loading every author.
	 *
	 * @param array  $args   Zotero source query arguments.
	 * @param string $search Author search text.
	 * @param int    $limit  Maximum suggestions to return.
	 * @return array|false Author facets, or false before an indexed page is available.
	 */
	public static function get_authors( array $args, $search, $limit = 30 ) {
		global $wpdb;

		$source_key = self::source_key( self::normalize_source_args( $args ) );
		$state      = self::get_state( $source_key );
		$search     = sanitize_text_field( $search );
		$sync_token = ! empty( $state['ready'] ) ? $state['active_token'] : ( isset( $state['sync_token'] ) ? $state['sync_token'] : '' );

		if ( empty( $sync_token ) || 2 > self::string_length( $search ) ) {
			return false;
		}

		$limit     = min( 50, max( 1, (int) $limit ) );
		$identity  = md5( wp_json_encode( array( $sync_token, $state['processed'], $search, $limit ) ) );
		$cache_key = 'zotero_display_authors_' . $source_key . '_' . ( hexdec( substr( $identity, 0, 4 ) ) % 32 );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && $identity === $cached['identity'] ) {
			return $cached['data'];
		}
		$creators_table = self::creators_table();
		$items_table    = self::items_table();
		$like           = '%' . $wpdb->esc_like( $search ) . '%';
		$sql            = $wpdb->prepare(
			'SELECT c.creator_name AS value, COUNT(*) AS count
			FROM %i c
			INNER JOIN %i i ON i.source_key = c.source_key AND i.item_key = c.item_key
			WHERE c.source_key = %s AND i.sync_token = %s AND c.creator_name LIKE %s
			GROUP BY c.creator_name
			ORDER BY c.creator_name ASC
			LIMIT %d',
			$creators_table,
			$items_table,
			$source_key,
			$sync_token,
			$like,
			$limit
		);
		$rows           = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned index query with identifiers and values prepared above.

		$authors = self::normalize_facets( $rows );
		set_transient(
			$cache_key,
			array(
				'identity' => $identity,
				'data'     => $authors,
			),
			15
		);
		return $authors;
	}

	/**
	 * Return non-sensitive synchronization details for a source.
	 *
	 * @param array $args Zotero source query arguments.
	 * @return array Public synchronization state.
	 */
	public static function get_public_state( array $args ) {
		$source_key = self::source_key( self::normalize_source_args( $args ) );

		return self::public_state( self::get_state( $source_key ) );
	}

	/**
	 * Return the non-sensitive source arguments used by public REST requests.
	 *
	 * @param array $args Source arguments.
	 * @return array Public source arguments.
	 */
	public static function public_source_args( array $args ) {
		$source_args = self::normalize_source_args( $args );
		unset( $source_args['cache_minutes'] );

		return $source_args;
	}

	/**
	 * Sign a rendered block's public source arguments.
	 *
	 * @param array $args Source arguments.
	 * @return string Source signature.
	 */
	public static function source_signature( array $args ) {
		return wp_hash( wp_json_encode( self::public_source_args( $args ) ) );
	}

	/**
	 * Remove local index data and pending events.
	 *
	 * @return void
	 */
	public static function clear_all() {
		global $wpdb;

		Block::clear_fragment_cache();
		wp_clear_scheduled_hook( self::HOOK );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::items_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Fixed plugin-owned table identifier is prepared.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::creators_table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Fixed plugin-owned table identifier is prepared.

		$like  = $wpdb->esc_like( self::STATE_PREFIX ) . '%';
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Enumerate plugin-owned state for cache-aware deletion.
		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}

	/**
	 * Stop background events without deleting the completed local index.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Start a new generation while optionally keeping the old one readable.
	 *
	 * @param string $source_key Source identifier.
	 * @param array  $args       Normalized source arguments.
	 * @param bool   $ready      Whether a completed generation already exists.
	 * @return array Sync state.
	 */
	private static function start_sync( $source_key, array $args, $ready ) {
		$previous_state = self::get_state( $source_key );
		$state          = array(
			'status'                => 'syncing',
			'ready'                 => (bool) $ready,
			'source_args'           => $args,
			'sync_token'            => md5( wp_generate_uuid4() ),
			'next_start'            => 0,
			'processed'             => 0,
			'total'                 => $ready ? (int) $previous_state['total'] : 0,
			'normalization_version' => (int) ( $previous_state['normalization_version'] ?? 0 ),
			'active_total'          => $ready ? (int) ( $previous_state['active_total'] ?? $previous_state['total'] ) : 0,
			'last_modified_version' => 0,
			'active_token'          => $ready && ! empty( $previous_state['active_token'] ) ? $previous_state['active_token'] : '',
			'active_version'        => $ready && ! empty( $previous_state['active_version'] ) ? (int) $previous_state['active_version'] : 0,
			'last_sync'             => $ready ? (int) $previous_state['last_sync'] : 0,
			'updated_at'            => time(),
			'error'                 => '',
		);

		self::set_state( $source_key, $state );
		self::schedule_batch( $source_key );

		return $state;
	}

	/**
	 * Store one normalized Zotero page and its creator index.
	 *
	 * @param string $source_key Source identifier.
	 * @param string $sync_token Current generation token.
	 * @param int    $start      Zotero page offset.
	 * @param array  $items      Normalized items.
	 * @return true|\WP_Error True on success, or an error when a write fails.
	 */
	private static function store_page( $source_key, $sync_token, $start, array $items ) {
		$now          = current_time( 'mysql', true );
		$item_rows    = array();
		$creator_rows = array();

		foreach ( $items as $index => $item ) {
			if ( empty( $item['key'] ) ) {
				continue;
			}

			$json = wp_json_encode( $item, JSON_INVALID_UTF8_SUBSTITUTE );
			if ( false === $json ) {
				return new \WP_Error( 'zotero_invalid_item_json', __( 'A Zotero item could not be encoded for storage.', 'nature-zotero-publications' ) );
			}
			// Use the repaired strings for every column, not just the JSON payload.
			$item        = json_decode( $json, true );
			$storage_key = $sync_token . ':' . $item['key'];
			$search_text = implode(
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
			);

			$item_rows[] = array(
				$source_key,
				$storage_key,
				$start + $index,
				$item['item_type'],
				$item['item_type_label'],
				$item['date_year'],
				$search_text,
				$json,
				$sync_token,
				$now,
			);

			foreach ( array_unique( $item['creators'] ) as $creator ) {
				$creator_rows[] = array(
					$source_key,
					$storage_key,
					// Match varchar(191) without splitting UTF-8 characters; retain full-name identity.
					mb_substr( $creator, 0, 191, 'UTF-8' ),
					md5( $creator ),
					$sync_token,
				);
			}
		}

		$items_stored = self::replace_rows(
			self::items_table(),
			array( 'source_key', 'item_key', 'sort_index', 'item_type', 'item_type_label', 'date_year', 'search_text', 'data_json', 'sync_token', 'updated_at' ),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
			$item_rows
		);
		if ( is_wp_error( $items_stored ) ) {
			return $items_stored;
		}

		return self::replace_rows(
			self::creators_table(),
			array( 'source_key', 'item_key', 'creator_name', 'creator_hash', 'sync_token' ),
			array( '%s', '%s', '%s', '%s', '%s' ),
			$creator_rows
		);
	}

	/**
	 * Store rows using short multi-row statements supported by MySQL and SQLite.
	 *
	 * @param string $table   Plugin-owned table name.
	 * @param array  $columns Fixed column names.
	 * @param array  $formats WordPress prepare formats for one row.
	 * @param array  $rows    Row values.
	 * @return true|\WP_Error True on success, or an error when a write fails.
	 */
	private static function replace_rows( $table, array $columns, array $formats, array $rows ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return true;
		}

		$column_sql      = implode( ', ', $columns );
		$row_placeholder = '(' . implode( ', ', $formats ) . ')';

		$prefix = $wpdb->prepare( 'REPLACE INTO %i', $table ) . ' (' . $column_sql . ') VALUES ';
		$chunks = array();
		$chunk  = array();
		$bytes  = strlen( $prefix );
		foreach ( $rows as $row ) {
			$prepared = $wpdb->prepare( $row_placeholder, $row ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed format list supplied by internal callers.
			if ( strlen( $prepared ) + strlen( $prefix ) > 524288 ) {
				return new \WP_Error( 'zotero_item_too_large', __( 'A Zotero index row exceeds the 512 KiB write limit. Reduce the item metadata before retrying.', 'nature-zotero-publications' ) );
			}
			if ( $chunk && ( count( $chunk ) >= self::WRITE_BATCH_SIZE || $bytes + strlen( $prepared ) + 2 > 524288 ) ) {
				$chunks[] = $chunk;
				$chunk    = array();
				$bytes    = strlen( $prefix );
			}
			$chunk[] = $prepared;
			$bytes  += strlen( $prepared ) + 2;
		}
		if ( $chunk ) {
			$chunks[] = $chunk;
		}
		foreach ( $chunks as $chunk ) {
			$result = $wpdb->query( $prefix . implode( ', ', $chunk ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Internal fixed column names; table identifier and all row values prepared above, bounded by actual SQL bytes.
			if ( false === $result ) {
				return new \WP_Error( 'zotero_display_index_write_failed', __( 'Unable to update the local Zotero publication index.', 'nature-zotero-publications' ) );
			}
		}

		return true;
	}

	/**
	 * Atomically finish a generation by pruning rows not seen in it.
	 *
	 * @param string $source_key Source identifier.
	 * @param array  $state      Current sync state.
	 * @return void
	 */
	private static function complete_sync( $source_key, array $state ) {
		global $wpdb;

		$state['status']                = 'complete';
		$state['ready']                 = true;
		$state['active_token']          = $state['sync_token'];
		$state['active_version']        = (int) $state['last_modified_version'];
		$state['normalization_version'] = 2;
		$state['active_total']          = (int) $state['total'];
		$state['last_sync']             = time();
		$state['updated_at']            = time();
		$state['error']                 = '';
		self::set_state( $source_key, $state );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_key = %s AND sync_token <> %s', self::items_table(), $source_key, $state['active_token'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Plugin table identifier and values are prepared.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_key = %s AND sync_token <> %s', self::creators_table(), $source_key, $state['active_token'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- Plugin table identifier and values are prepared.
		Block::clear_fragment_cache();
	}

	/**
	 * Compute statistics and optional facets using the same result conditions.
	 *
	 * @param array $where           Prepared-query components.
	 * @param bool  $include_facets  Whether to include facet arrays.
	 * @param bool  $include_authors Whether to include the author facet.
	 * @return array Statistics.
	 */
	private static function get_stats( array $where, $include_facets, $include_authors ) {
		global $wpdb;

		$items_table    = self::items_table();
		$creators_table = self::creators_table();
		$prepared_where = self::prepare_sql( $where['sql'], $where['params'] );
		$identity       = md5( wp_json_encode( array( $where, (bool) $include_authors ) ) );
		$cache_key      = 'zotero_display_stats_' . $where['params'][0] . '_' . ( hexdec( substr( $identity, 0, 4 ) ) % 32 );
		$cached_stats   = get_transient( $cache_key );
		if ( is_array( $cached_stats ) && $identity === $cached_stats['identity'] ) {
			return $cached_stats['data'];
		}

		$summary_sql = "SELECT COUNT(*) AS total_items, COUNT(DISTINCT NULLIF(i.item_type, '')) AS total_types, MIN(NULLIF(i.date_year, '')) AS min_year, MAX(NULLIF(i.date_year, '')) AS max_year FROM {$items_table} i WHERE {$prepared_where}";
		$summary     = $wpdb->get_row( $summary_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name; all request values are prepared above.
		$stats       = array(
			'total_items' => isset( $summary['total_items'] ) ? (int) $summary['total_items'] : 0,
			'total_types' => isset( $summary['total_types'] ) ? (int) $summary['total_types'] : 0,
			'year_range'  => array(
				'min' => isset( $summary['min_year'] ) ? (string) $summary['min_year'] : '',
				'max' => isset( $summary['max_year'] ) ? (string) $summary['max_year'] : '',
			),
		);

		$types_sql = "SELECT i.item_type AS value, MAX(i.item_type_label) AS label, COUNT(*) AS count FROM {$items_table} i WHERE {$prepared_where} AND i.item_type <> '' GROUP BY i.item_type ORDER BY label ASC";
		$years_sql = "SELECT i.date_year AS value, COUNT(*) AS count FROM {$items_table} i WHERE {$prepared_where} AND i.date_year <> '' GROUP BY i.date_year ORDER BY i.date_year DESC";

		$stats['available_types'] = self::normalize_facets( $wpdb->get_results( $types_sql, ARRAY_A ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name; all request values are prepared above.
		$stats['available_years'] = self::normalize_facets( $wpdb->get_results( $years_sql, ARRAY_A ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name; all request values are prepared above.
		if ( $include_authors ) {
			$authors_sql                = "SELECT c.creator_name AS value, COUNT(*) AS count FROM {$creators_table} c INNER JOIN {$items_table} i ON i.source_key = c.source_key AND i.item_key = c.item_key WHERE {$prepared_where} GROUP BY c.creator_name ORDER BY c.creator_name ASC";
			$stats['available_authors'] = self::normalize_facets( $wpdb->get_results( $authors_sql, ARRAY_A ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned table name; all request values are prepared above.
		}

		set_transient(
			$cache_key,
			array(
				'identity' => $identity,
				'data'     => $stats,
			),
			15
		);

		return $stats;
	}

	/**
	 * Build reusable SQL conditions for local-index queries.
	 *
	 * @param string $source_key   Source identifier.
	 * @param string $active_token Completed generation token.
	 * @param array  $filters      Search and facet filters.
	 * @return array SQL template and values.
	 */
	private static function build_where( $source_key, $active_token, array $filters ) {
		global $wpdb;

		$clauses = array( 'i.source_key = %s', 'i.sync_token = %s' );
		$params  = array( $source_key, $active_token );

		if ( ! empty( $filters['search'] ) ) {
			$clauses[] = 'i.search_text LIKE %s';
			$params[]  = '%' . $wpdb->esc_like( sanitize_text_field( $filters['search'] ) ) . '%';
		}
		if ( ! empty( $filters['type'] ) ) {
			$clauses[] = 'i.item_type = %s';
			$params[]  = sanitize_text_field( $filters['type'] );
		}
		if ( ! empty( $filters['year'] ) ) {
			$clauses[] = 'i.date_year = %s';
			$params[]  = sanitize_text_field( $filters['year'] );
		}
		if ( ! empty( $filters['author'] ) ) {
			$clauses[] = 'EXISTS (SELECT 1 FROM ' . self::creators_table() . ' af WHERE af.source_key = i.source_key AND af.item_key = i.item_key AND af.creator_name = %s)';
			$params[]  = sanitize_text_field( $filters['author'] );
		}

		return array(
			'sql'    => implode( ' AND ', $clauses ),
			'params' => $params,
		);
	}

	/**
	 * Convert database facet rows to REST response values.
	 *
	 * @param array $rows          Database rows.
	 * @param bool  $include_label Whether type labels are present.
	 * @return array Normalized facets.
	 */
	private static function normalize_facets( array $rows, $include_label = false ) {
		foreach ( $rows as &$row ) {
			$row['value'] = (string) $row['value'];
			$row['count'] = (int) $row['count'];
			if ( $include_label ) {
				$row['label'] = (string) $row['label'];
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Prepare a SQL fragment with a variable number of values.
	 *
	 * @param string $sql    SQL containing placeholders.
	 * @param array  $params Placeholder values.
	 * @return string Prepared SQL.
	 */
	private static function prepare_sql( $sql, array $params ) {
		global $wpdb;

		return $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The caller supplies an internal SQL template; every external value is a placeholder parameter.
	}

	/**
	 * Normalize source arguments and remove the private API key.
	 *
	 * @param array $args Source arguments.
	 * @return array Normalized safe arguments.
	 */
	private static function normalize_source_args( array $args ) {
		return array(
			'library_type'  => ( isset( $args['library_type'] ) && 'group' === $args['library_type'] ) ? 'group' : 'user',
			'library_id'    => isset( $args['library_id'] ) ? sanitize_text_field( $args['library_id'] ) : '',
			'collection'    => isset( $args['collection'] ) ? sanitize_text_field( $args['collection'] ) : '',
			'sort'          => isset( $args['sort'] ) ? sanitize_text_field( $args['sort'] ) : 'date',
			'direction'     => isset( $args['direction'] ) && 'asc' === $args['direction'] ? 'asc' : 'desc',
			'item_type'     => isset( $args['item_type'] ) ? sanitize_text_field( $args['item_type'] ) : '',
			'cache_minutes' => isset( $args['cache_minutes'] ) ? max( 1, absint( $args['cache_minutes'] ) ) : 60,
		);
	}

	/**
	 * Build a stable source key that never includes the API key.
	 *
	 * @param array $args Normalized source arguments.
	 * @return string Source hash.
	 */
	private static function source_key( array $args ) {
		$key_args = $args;
		unset( $key_args['cache_minutes'] );

		return md5( wp_json_encode( $key_args ) );
	}

	/**
	 * Get a string length without requiring mbstring.
	 *
	 * @param string $value String value.
	 * @return int String length.
	 */
	public static function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	/**
	 * Schedule one batch when it is not already pending.
	 *
	 * @param string $source_key Source identifier.
	 * @param int    $delay      Delay in seconds.
	 * @return void
	 */
	private static function schedule_batch( $source_key, $delay = 1 ) {
		$event_args = array( $source_key );
		if ( ! wp_next_scheduled( self::HOOK, $event_args ) ) {
			$result = wp_schedule_single_event( time() + max( 1, (int) $delay ), self::HOOK, $event_args, true );

			if ( is_wp_error( $result ) || false === $result ) {
				set_transient( 'zotero_display_schedule_error_' . $source_key, 1, HOUR_IN_SECONDS );
			} else {
				delete_transient( 'zotero_display_schedule_error_' . $source_key );
			}
		}
	}

	/**
	 * Read bounded administrator diagnostics without starting synchronization.
	 *
	 * @return array Safe table and source details.
	 */
	public static function diagnostics() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array();
		}
		global $wpdb;
		$tables = array();
		foreach ( array( self::items_table(), self::creators_table() ) as $table ) {
			// Probe the actual table, rather than trusting the stored schema version.
			$previous = $wpdb->suppress_errors();
			$wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Administrator-only table health probe.
			$tables[ $table ] = empty( $wpdb->last_error );
			$wpdb->suppress_errors( $previous );
		}
		$args    = self::normalize_source_args( Settings::get_settings() );
		$sources = array();
		if ( ! is_wp_error( self::validate_source_args( $args ) ) ) {
			$sources[ self::source_key( $args ) ] = $args;
		}
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 100', $wpdb->options, $wpdb->esc_like( self::STATE_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded administrator-only inventory of plugin state.
		foreach ( $names as $name ) {
			$key   = substr( $name, strlen( self::STATE_PREFIX ) );
			$state = self::get_state( $key );
			if ( ! empty( $state['source_args'] ) ) {
				$args = self::normalize_source_args( $state['source_args'] );
				if ( ! is_wp_error( self::validate_source_args( $args ) ) && self::source_key( $args ) === $key ) {
					$sources[ $key ] = $args;
				}
			}
		}
		$rows = array();
		foreach ( $sources as $key => $args ) {
			$state = self::get_state( $key );
			// Do not expose arbitrary transport messages, URLs, SQL, or credentials.
			$error = '';
			if ( ! empty( $state['error'] ) ) {
				$error = __( 'Synchronization failed. Check Zotero access and database health.', 'nature-zotero-publications' );
				if ( preg_match( '/HTTP ([0-9]{3})/', $state['error'], $match ) ) {
					$error = 'HTTP ' . $match[1];
				} elseif ( str_contains( $state['error'], 'cURL error 28' ) ) {
					$error = __( 'Zotero request timed out.', 'nature-zotero-publications' );
				}
			}
			$rows[ $key ] = array(
				'args'           => $args,
				'status'         => $state['status'],
				'processed'      => (int) $state['processed'],
				'total'          => (int) $state['total'],
				'error'          => $error,
				'admin_detail'   => 'error' === $state['status'] ? ( $state['admin_detail'] ?? '' ) : '',
				'next'           => wp_next_scheduled( self::HOOK, array( $key ) ),
				'retry'          => 'error' === $state['status'] ? (int) $state['updated_at'] + self::RETRY_DELAY : (int) ( $state['retry_at'] ?? 0 ),
				'schedule_error' => (bool) get_transient( 'zotero_display_schedule_error_' . $key ),
			);
		}
		return array(
			'tables'  => $tables,
			'sources' => $rows,
		);
	}

	/**
	 * Run one page for a source from the administrator diagnostic inventory.
	 *
	 * @param string $key Source identifier.
	 * @return void
	 */
	public static function diagnostic_batch( $key ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$report = self::diagnostics();
		if ( empty( $report['sources'][ $key ] ) || in_array( false, $report['tables'], true ) ) {
			return;
		}
		self::ensure_scheduled( $report['sources'][ $key ]['args'] );
		self::run_batch( $key, 1 );
		Block::clear_fragment_cache();
	}

	/**
	 * Get synchronization state with safe defaults.
	 *
	 * @param string $source_key Source identifier.
	 * @return array Sync state.
	 */
	private static function get_state( $source_key ) {
		return wp_parse_args(
			get_option( self::STATE_PREFIX . $source_key, array() ),
			array(
				'status'         => 'idle',
				'ready'          => false,
				'active_token'   => '',
				'active_version' => 0,
				'processed'      => 0,
				'total'          => 0,
				'last_sync'      => 0,
				'updated_at'     => 0,
				'error'          => '',
			)
		);
	}

	/**
	 * Save synchronization state as a non-autoloaded option.
	 *
	 * @param string $source_key Source identifier.
	 * @param array  $state      Sync state.
	 * @return void
	 */
	private static function set_state( $source_key, array $state ) {
		$option = self::STATE_PREFIX . $source_key;
		if ( false === get_option( $option, false ) ) {
			add_option( $option, $state, '', false );
		} else {
			update_option( $option, $state, false );
		}
	}

	/**
	 * Return non-sensitive sync details in REST responses.
	 *
	 * @param array $state Internal state.
	 * @return array Public state.
	 */
	private static function public_state( array $state ) {
		return array(
			'status'     => $state['status'],
			'ready'      => ! empty( $state['ready'] ),
			'processed'  => (int) $state['processed'],
			'total'      => (int) $state['total'],
			'last_sync'  => (int) $state['last_sync'],
			'updated_at' => (int) $state['updated_at'],
		);
	}

	/**
	 * Get the items table name.
	 *
	 * @return string Table name.
	 */
	private static function items_table() {
		global $wpdb;

		return $wpdb->prefix . 'zotero_display_items';
	}

	/**
	 * Get the creators table name.
	 *
	 * @return string Table name.
	 */
	private static function creators_table() {
		global $wpdb;

		return $wpdb->prefix . 'zotero_display_creators';
	}
}
