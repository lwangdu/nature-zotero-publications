<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- Standalone CLI regression fixture.
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Namespaces.DisallowCurlyBraceSyntax.Forbidden, Universal.Namespaces.OneDeclarationPerFile.MultipleFound, Universal.Namespaces.DisallowDeclarationWithoutName.Forbidden -- Isolated namespaced and global WordPress test doubles.
namespace Zotero_Display {
	class Block {
		public static function clear_fragment_cache() {}
	}
}
namespace {
	if ( PHP_SAPI !== 'cli' ) {
		exit; }
	const ABSPATH                         = __DIR__ . '/';
	const HOUR_IN_SECONDS                 = 3600;
	const ZOTERO_DISPLAY_TRANSIENT_PREFIX = 'zotero_display_';
	$transients                           = array();
	function __( $s, $domain = '' ) {
		return $s; }
	function get_transient( $key ) {
		global $transients;
		return $transients[ $key ] ?? false; }
	function set_transient( $key, $value, $ttl ) {
		global $transients;
		$transients[ $key ] = $value; }
	function delete_transient( $key ) {
		global $transients;
		unset( $transients[ $key ] ); }
	function sanitize_text_field( $s ) {
		return trim( strip_tags( $s ) ); }
	function wp_strip_all_tags( $s ) {
		return strip_tags( $s ); }
	function esc_url_raw( $s ) {
		return $s; }
	function absint( $n ) {
		return abs( (int) $n ); }
	function is_wp_error( $v ) {
		return $v instanceof WP_Error; }
	function add_query_arg( $args, $url ) {
		return $url . '?' . http_build_query( $args ); }
	function wp_remote_get( $url, $args ) {
		global $http_calls;
		++$http_calls;
		return array(); }
	function wp_remote_retrieve_response_code( $r ) {
		return 429; }
	function wp_remote_retrieve_header( $r, $h ) {
		global $response_headers;
		return $response_headers[ $h ] ?? ''; }
	function wp_remote_retrieve_body( $r ) {
		return 'Rate limited'; }
	class WP_Error {
		public function __construct( public $code = '', public $message = '', public $data = array() ) {}
	}
	class FakeDB {
		public $prefix  = 'wp_';
		public $options = 'wp_options';
		public $queries = array();
		public function esc_like( $s ) {
			return $s; }
		public function prepare( $sql, ...$args ) {
			if ( count( $args ) === 1 && is_array( $args[0] ) ) {
				$args = $args[0]; }
			return preg_replace_callback(
				'/%[isd]/',
				function ( $m ) use ( &$args ) {
					$value = array_shift( $args );
					return "'" . str_replace( "'", "''", (string) $value ) . "'";
				},
				$sql
			);
		}
		public function get_col( $sql ) {
			return str_contains( $sql, '_transient_' ) ? array( '_transient_zotero_display_backoff', '_transient_zotero_display_backoff_attempts', '_transient_zotero_display_legacy' ) : array( 'zotero_display_sync_fixture' );
		}
		public function query( $sql ) {
			if ( ! mb_check_encoding( $sql, 'UTF-8' ) ) {
				return false;
			}
			$this->queries[] = $sql;
			return 1; }
	}
	require dirname( __DIR__ ) . '/includes/class-zotero-api.php';
	require dirname( __DIR__ ) . '/includes/class-sync.php';
	function verify( $condition, $name ) {
		if ( ! $condition ) {
			throw new \Exception( $name );
		} echo "PASS $name\n"; }
	$wpdb       = new FakeDB();
	$transients = array(
		'zotero_display_backoff'            => 123,
		'zotero_display_backoff_attempts'   => 2,
		'zotero_display_legacy'             => 1,
		'zotero_display_results_fixture_31' => 1,
	);
	\Zotero_Display\Zotero_API::clear_all_caches();
	verify( 123 === $transients['zotero_display_backoff'] && 2 === $transients['zotero_display_backoff_attempts'], 'cache clear preserves cooldown and attempts' );
	verify( ! isset( $transients['zotero_display_legacy'] ) && ! isset( $transients['zotero_display_results_fixture_31'] ), 'cache clear deletes legacy and object-cache slots' );
	$method = new \ReflectionMethod( \Zotero_Display\Zotero_API::class, 'normalize_item' );
	$args   = array(
		'library_type' => 'user',
		'library_id'   => '123',
		'collection'   => '',
		'sort'         => 'date',
		'direction'    => 'desc',
		'api_key'      => '',
	);
	$item   = $method->invoke(
		null,
		array(
			'key'  => 'ABCD1234',
			'data' => array(
				'DOI'      => '10.1234/a#b?c%d',
				'tags'     => array( 'bad', array( 'tag' => array() ), array( 'tag' => 'good' ) ),
				'creators' => array( null, array( 'name' => array() ), array( 'name' => 'Author' ) ),
			),
		),
		$args
	);
	verify( 'https://doi.org/10.1234/a%23b%3Fc%25d' === $item['doi_url'], 'DOI reserved characters encoded while slash retained' );
	verify( array( 'good' ) === $item['tags'] && array( 'Author' ) === $item['creators'], 'malformed tags and creators ignored' );
	foreach ( array(
		array( 'retry-after' => '7200' ),
		array(
			'backoff'     => '10800',
			'retry-after' => '7200',
		),
	) as $response_headers ) {
		$transients = array();
		$http_calls = 0;
		$expected   = max( array_map( 'intval', $response_headers ) );
		$before     = time();
		$error      = \Zotero_Display\Zotero_API::fetch_page( $args );
		verify( $expected === $error->data['retry_after'], 'full server retry delay preserved' );
		verify( $transients['zotero_display_backoff'] >= $before + $expected, 'stored deadline preserves full cooldown' );
		$error = \Zotero_Display\Zotero_API::fetch_page( $args );
		verify( 'zotero_backoff' === $error->code && 1 === $http_calls, 'cooldown prevents another HTTP request' );
	}
	$write         = new \ReflectionMethod( \Zotero_Display\Sync::class, 'replace_rows' );
	$wpdb->queries = array();
	$result        = $write->invoke( null, 'items', array( 'data' ), array( '%s' ), array_fill( 0, 12, array( str_repeat( "'", 30000 ) ) ) );
	verify( true === $result && count( $wpdb->queries ) > 1, 'abstract-heavy rows split into byte-bounded statements' );
	foreach ( $wpdb->queries as $sql ) {
		verify( strlen( $sql ) <= 524288, 'prepared SQL stays within byte budget' ); }
	function wp_hash( $value ) {
		return hash( 'sha256', $value ); }
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags ); }
	function wp_unslash( $value ) {
		return $value; }
	function current_user_can( $capability ) {
		global $editor;
		return ! empty( $editor ); }
	function wp_using_ext_object_cache() {
		return false; }
	class WP_REST_Request {
		public function __construct( public $signature ) {}
		public function get_param( $key ) {
			return $this->signature; }
	}
	require dirname( __DIR__ ) . '/includes/class-rest-controller.php';
	$controller = ( new \ReflectionClass( \Zotero_Display\REST_Controller::class ) )->newInstanceWithoutConstructor();
	$authorize  = new \ReflectionMethod( \Zotero_Display\REST_Controller::class, 'authorize_source_request' );
	$request    = new WP_REST_Request( \Zotero_Display\Sync::source_signature( $args ) );
	$transients = array();
	for ( $i = 0; $i < 120; ++$i ) {
		if ( true !== $authorize->invoke( $controller, $request, $args ) ) {
			throw new \Exception( 'premature throttle' ); }
	}
	$result = $authorize->invoke( $controller, $request, $args );
	verify( $result instanceof WP_Error && 429 === $result->data['status'], 'public allowance enforced' );
	$editor = true;
	verify( true === $authorize->invoke( $controller, $request, $args ), 'editor bypasses public allowance' );

	function current_time( $format, $gmt = false ) {
		return '2026-10-22 12:00:00'; }
	$store            = new \ReflectionMethod( \Zotero_Display\Sync::class, 'store_page' );
	$item['title']    = 'Café 水 Bad byte ' . chr( 255 );
	$item['creators'] = array( 'René ' . chr( 255 ) );
	$item['tags']     = array( 'tag ' . chr( 255 ) );
	$wpdb->queries    = array();
	verify( true === $store->invoke( null, 'source', 'generation', 0, array( $item ) ), 'invalid UTF-8 item remains storable' );
	verify( mb_check_encoding( implode( '', $wpdb->queries ), 'UTF-8' ) && count( $wpdb->queries ) === 2, 'all item and creator SQL contains valid UTF-8' );
	verify( str_contains( implode( '', $wpdb->queries ), 'Café 水' ), 'valid Unicode remains unchanged in searchable text' );
	verify( str_contains( implode( '', $wpdb->queries ), '\\ufffd' ), 'invalid UTF-8 replaced in stored JSON instead of blank JSON' );

}
