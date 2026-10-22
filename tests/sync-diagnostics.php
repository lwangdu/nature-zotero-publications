<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- CLI fixture, not a production class file.
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName, Generic.Files.OneObjectStructurePerFile.MultipleFound -- Standalone fixture keeps its WordPress and database doubles together.
// Standalone CLI fixture: never load alongside WordPress.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
const ABSPATH           = __DIR__ . '/';
const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS   = 3600;
$transients             = array();
function get_transient( $k ) {
	global $transients;
	return $transients[ $k ] ?? false;}
function set_transient( $k, $v, $ttl ) {
	global $transients;
	$transients[ $k ] = $v;}
function delete_transient( $k ) {
	global $transients;
	unset( $transients[ $k ] );}
$allowed       = true;
$options       = array();
$events        = array();
$schedule_fail = false;
function current_user_can( $c ) {
	global $allowed;
	return $allowed; }
function __( $s, $d = null ) {
	return $s; }
function esc_html__( $s, $d = null ) {
	return $s; }
function wp_die( $s ) {
	throw new Exception( 'denied' ); }
function check_admin_referer( $s ) {
	throw new Exception( 'nonce rejected' ); }
function wp_parse_args( $v, $d ) {
	return array_merge( $d, $v ); }
function get_option( $k, $d = false ) {
	global $options;
	return $options[ $k ] ?? $d; }
function add_option( $k, $v, ...$rest ) {
	global $options;
	$options[ $k ] = $v; }
function update_option( $k, $v, ...$rest ) {
	global $options;
	$options[ $k ] = $v; }
function sanitize_text_field( $v ) {
	return trim( strip_tags( $v ) ); }
function absint( $v ) {
	return abs( (int) $v ); }
function wp_json_encode( $v ) {
	return json_encode( $v ); }
function is_wp_error( $v ) {
	return $v instanceof WP_Error; }
function wp_next_scheduled( $h, $a ) {
	global $events;
	return $events[ $a[0] ] ?? false; }
function wp_schedule_single_event( $t, $h, $a, $err ) {
	global $events, $schedule_fail;
	if ( $schedule_fail ) {
		return new WP_Error();
	} $events[ $a[0] ] = $t;
	return true; }
class WP_Error {}
class FakeDB {
	public $prefix     = 'wp_';
	public $options    = 'wp_options';
	public $last_error = '';
	public $missing    = false;
	public function suppress_errors( $v = true ) {
		return false;}
	public function prepare( $q, ...$a ) {
		return $q;}
	public function get_var( $q ) {
		$this->last_error = $this->missing ? 'sensitive SQL text' : '';
		return null;}
	public function get_col( $q ) {
		global $options;
		return array_filter( array_keys( $options ), fn( $k )=>str_starts_with( $k, 'zotero_display_sync_' ) );}
	public function esc_like( $s ) {
		return $s;}
}
$wpdb = new FakeDB();
require dirname( __DIR__ ) . '/includes/class-settings.php';
require dirname( __DIR__ ) . '/includes/class-sync.php';
use Zotero_Display\Sync;
use Zotero_Display\Settings;
function verify( $v, $s ) {
	if ( ! $v ) {
		throw new Exception( $s );
	}echo "PASS $s\n";}
$options[ Settings::OPTION_KEY ] = array(
	'api_key'      => 'SECRET_SENTINEL',
	'library_type' => 'group',
	'library_id'   => '323588',
);
$r                               = Sync::diagnostics();
$key                             = array_key_first( $r['sources'] );
verify( count( $r['tables'] ) === 2 && ! in_array( false, $r['tables'], true ), 'empty readable tables accepted' );
verify( count( $r['sources'] ) === 1 && 'idle' === $r['sources'][ $key ]['status'], 'default source available without registering sync' );
verify( ! str_contains( json_encode( $r ), 'SECRET_SENTINEL' ), 'key excluded from diagnostics' );
$options[ Sync::STATE_PREFIX . $key ] = array(
	'source_args' => $r['sources'][ $key ]['args'],
	'status'      => 'error',
	'error'       => 'HTTP 403 https://secret.example SECRET_SENTINEL',
);
$r                                    = Sync::diagnostics();
verify( 'HTTP 403' === $r['sources'][ $key ]['error'], 'error details reduced to HTTP status' );
$wpdb->missing = true;
$r             = Sync::diagnostics();
verify( in_array( false, $r['tables'], true ) && ! str_contains( json_encode( $r ), 'sensitive SQL' ), 'database failures reported without raw SQL' );
$method        = new ReflectionMethod( Sync::class, 'schedule_batch' );
$schedule_fail = true;
$method->invoke( null, $key, 1 );
verify( Sync::diagnostics()['sources'][ $key ]['schedule_error'], 'scheduling failure captured' );
$schedule_fail = false;
$method->invoke( null, $key, 1 );
$r = Sync::diagnostics();
verify( ! $r['sources'][ $key ]['schedule_error'] && $r['sources'][ $key ]['next'] > 0, 'successful scheduling clears failure' );
$allowed = false;
verify( Sync::diagnostics() === array(), 'non-administrator cannot read diagnostics' );
$settings = ( new ReflectionClass( Settings::class ) )->newInstanceWithoutConstructor();
try {
	$settings->handle_diagnostic_batch();
	throw new Exception( 'should deny' );
} catch ( Exception $e ) {
	verify( $e->getMessage() === 'denied', 'manual run capability enforced' );}
$allowed = true;
try {
	$settings->handle_diagnostic_batch();
	throw new Exception( 'should deny' );
} catch ( Exception $e ) {
	verify( $e->getMessage() === 'nonce rejected', 'manual run nonce enforced before processing' );}
