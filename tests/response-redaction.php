<?php
// Standalone CLI fixture: never load alongside WordPress.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
const ABSPATH = __DIR__ . '/';
function __( $s, $d ) {
	return $s;}
function wp_remote_retrieve_body( $r ) {
	return $r['body'];}
function wp_remote_retrieve_header( $r, $h ) {
	return $r[ $h ] ?? '';}
function wp_strip_all_tags( $s ) {
	return strip_tags( preg_replace( '~<(script|style)\b[^>]*>.*?</\1>~is', '', $s ) );}
function sanitize_text_field( $s ) {
	return trim( strip_tags( $s ) );}
require dirname( __DIR__ ) . '/includes/class-zotero-api.php';
use Zotero_Display\Zotero_API;
$r   = array(
	'body'          => '<html><title>Bad Request</title><h1>Invalid header</h1><p>secret https://example.com/?key=secret</p><script>private</script></html>',
	'content-type'  => 'text/html',
	'server'        => 'nginx',
	'set-cookie'    => 'private-cookie',
	'authorization' => 'secret',
);
$out = Zotero_API::diagnostic_response( $r, 'secret' );
foreach ( array( 'Bad Request', 'Invalid header', 'server: nginx', '[redacted]' ) as $v ) {
	if ( ! str_contains( $out, $v ) ) {
		throw new Exception( 'missing ' . $v );
	}
}
foreach ( array( 'secret', 'private', 'https://', '<html>' ) as $v ) {
	if ( str_contains( $out, $v ) ) {
		throw new Exception( 'leak ' . $v );
	}
}
foreach ( array( 'sec<b>ret</b>', '%73ecret', '&#115;ecret' ) as $v ) {
	$r['body'] = '<title>' . $v . '</title>';
	if ( str_contains( Zotero_API::diagnostic_response( $r, 'secret' ), 'secret' ) ) {
		throw new Exception( 'encoded leak' );
	}
}
$r['body'] = str_repeat( 'x', 33000 );
if ( strlen( Zotero_API::diagnostic_response( $r, 'secret' ) ) > 1000 ) {
	throw new Exception( 'unbounded' );
}
echo "PASS HTML extraction, header allowlist, credential redaction, encoded/fragmented keys, size bounds\n";
$r   = array(
	'body'         => 'Invalid API version',
	'content-type' => 'text/html; charset=UTF-8',
	'server'       => 'Apache',
);
$out = Zotero_API::diagnostic_response( $r, 'secret' );
if ( ! str_contains( $out, 'body: Invalid API version' ) ) {
	throw new Exception( 'plain HTML-labeled body missing' );
}
$r['body'] = 'secret';
if ( str_contains( Zotero_API::diagnostic_response( $r, 'secret' ), 'secret' ) ) {
	throw new Exception( 'fallback credential leak' );
}
$r['body'] = '';
if ( ! str_contains( Zotero_API::diagnostic_response( $r ), 'No plain-text' ) ) {
	throw new Exception( 'empty fallback' );
}
echo "PASS HTML-labeled plain text, fallback redaction and empty body\n";
