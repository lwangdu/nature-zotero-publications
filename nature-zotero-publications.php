<?php
/**
 * Plugin Name:       Nature Zotero Publications
 * Description:       Display a searchable and filterable Zotero bibliography with publication metadata, links, and pagination.
 * Version:            1.0.6
 * Requires at least:  7.0
 * Requires PHP:       8.3
 * Author:             Lobsang Wangdu
 * License:            GPL-2.0-or-later
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:        nature-zotero-publications
 *
 * @package ZoteroDisplay
 */

defined( 'ABSPATH' ) || exit;

define( 'ZOTERO_DISPLAY_VERSION', '1.0.6' );
define( 'ZOTERO_DISPLAY_FILE', __FILE__ );
define( 'ZOTERO_DISPLAY_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZOTERO_DISPLAY_URL', plugin_dir_url( __FILE__ ) );
define( 'ZOTERO_DISPLAY_TRANSIENT_PREFIX', 'zotero_display_' );
define( 'ZOTERO_DISPLAY_CACHE_GROUP', 'zotero_display' );

require_once ZOTERO_DISPLAY_DIR . 'includes/class-zotero-api.php';
require_once ZOTERO_DISPLAY_DIR . 'includes/class-settings.php';
require_once ZOTERO_DISPLAY_DIR . 'includes/class-sync.php';
require_once ZOTERO_DISPLAY_DIR . 'includes/class-rest-controller.php';
require_once ZOTERO_DISPLAY_DIR . 'includes/class-block.php';

/**
 * Add a quick Settings link to the Plugins screen.
 *
 * @param array $links Existing plugin action links.
 * @return array Updated plugin action links.
 */
function zotero_display_plugin_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( admin_url( 'options-general.php?page=nature-zotero-publications' ) ),
		esc_html__( 'Settings', 'nature-zotero-publications' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( ZOTERO_DISPLAY_FILE ), 'zotero_display_plugin_action_links' );

/**
 * Boot the plugin.
 */
function zotero_display_init() {
	Zotero_Display\Sync::register_hooks();
	Zotero_Display\Settings::instance();
	Zotero_Display\REST_Controller::instance();
	Zotero_Display\Block::instance();
}
add_action( 'plugins_loaded', 'zotero_display_init' );

/**
 * Activation: create defaults and the local Zotero index.
 */
function zotero_display_activate() {
	if ( false === get_option( 'zotero_display_settings' ) ) {
		add_option(
			'zotero_display_settings',
			array(
				'api_key'       => '',
				'library_type'  => 'user', // Default to a user library.
				'library_id'    => '',
				'cache_minutes' => 60,
			)
		);
	}

	Zotero_Display\Sync::install();
}
register_activation_hook( __FILE__, 'zotero_display_activate' );

/**
 * Deactivation: clear any of our transients so stale data doesn't linger.
 */
function zotero_display_deactivate() {
	Zotero_Display\Block::clear_fragment_cache();
	Zotero_Display\Zotero_API::clear_all_caches();
	Zotero_Display\Sync::unschedule();
}
register_deactivation_hook( __FILE__, 'zotero_display_deactivate' );
