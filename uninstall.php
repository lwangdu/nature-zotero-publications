<?php
/**
 * Uninstall cleanup for Nature Zotero Publications.
 *
 * @package ZoteroDisplay
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove plugin-owned tables, options, transients, and scheduled events for one site.
 *
 * @return void
 */
function zotero_display_uninstall_site() {
	global $wpdb;

	wp_clear_scheduled_hook( 'zotero_display_sync_batch' );

	delete_option( 'zotero_display_settings' );
	delete_option( 'zotero_display_schema_version' );
	delete_option( 'zotero_display_fragment_cache_keys' );

	$sync_like = $wpdb->esc_like( 'zotero_display_sync_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $sync_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- WordPress has no delete-options-by-prefix API.

	$transient_like = $wpdb->esc_like( '_transient_zotero_display_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- WordPress has no delete-transients-by-prefix API.

	$transient_timeout_like = $wpdb->esc_like( '_transient_timeout_zotero_display_' ) . '%';
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $transient_timeout_like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- WordPress has no delete-transient-timeouts-by-prefix API.

	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'zotero_display_creators' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table identifier is prepared.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'zotero_display_items' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Plugin-owned table identifier is prepared.

	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'zotero_display' );
	}
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		zotero_display_uninstall_site();
		restore_current_blog();
	}
} else {
	zotero_display_uninstall_site();
}
