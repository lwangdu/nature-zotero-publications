<?php
/**
 * Admin settings page: API key, default library, cache duration, manual cache clear.
 *
 * @package ZoteroDisplay
 */

namespace Zotero_Display;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin settings page.
 */
class Settings {

	const OPTION_KEY = 'zotero_display_settings';

	/**
	 * Singleton instance.
	 *
	 * @var Settings|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Settings
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
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_zotero_display_clear_cache', array( $this, 'handle_clear_cache' ) );
	}

	/**
	 * Add the plugin page to the Settings menu.
	 *
	 * @return void
	 */
	public function add_settings_page() {
		add_options_page(
			__( 'Nature Zotero Publications', 'nature-zotero-publications' ),
			__( 'Nature Zotero Publications', 'nature-zotero-publications' ),
			'manage_options',
			'nature-zotero-publications',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register the plugin settings option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'zotero_display_settings_group',
			self::OPTION_KEY,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize plugin settings before saving.
	 *
	 * @param array $input Submitted settings.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$output                  = array();
		$output['api_key']       = isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';
		$output['library_type']  = ( isset( $input['library_type'] ) && 'group' === $input['library_type'] ) ? 'group' : 'user';
		$output['library_id']    = isset( $input['library_id'] ) ? sanitize_text_field( $input['library_id'] ) : '';
		$output['cache_minutes'] = isset( $input['cache_minutes'] ) ? max( 1, absint( $input['cache_minutes'] ) ) : 60;

		// Clear caches automatically whenever core connection settings change.
		Zotero_API::clear_all_caches();

		return $output;
	}

	/**
	 * Get saved settings merged with defaults.
	 *
	 * @return array Plugin settings.
	 */
	public static function get_settings() {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'api_key'       => '',
				'library_type'  => 'user',
				'library_id'    => '',
				'cache_minutes' => 60,
			)
		);
	}

	/**
	 * Process an authenticated cache-clear request.
	 *
	 * @return void
	 */
	public function handle_clear_cache() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nature-zotero-publications' ) );
		}
		check_admin_referer( 'zotero_display_clear_cache' );

		Zotero_API::clear_all_caches();

		wp_safe_redirect( add_query_arg( 'zotero_cache_cleared', '1', wp_get_referer() ? wp_get_referer() : admin_url( 'options-general.php?page=nature-zotero-publications' ) ) );
		exit;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Nature Zotero Publications Settings', 'nature-zotero-publications' ); ?></h1>

			<?php if ( isset( $_GET['zotero_cache_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Zotero cache cleared.', 'nature-zotero-publications' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'zotero_display_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="zotero_api_key"><?php esc_html_e( 'Zotero API Key', 'nature-zotero-publications' ); ?></label></th>
						<td>
							<input type="password" id="zotero_api_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]" value="<?php echo esc_attr( $settings['api_key'] ); ?>" class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Required for private libraries/groups. Public libraries can leave this blank. Create a key at zotero.org/settings/keys.', 'nature-zotero-publications' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zotero_library_type"><?php esc_html_e( 'Default Library Type', 'nature-zotero-publications' ); ?></label></th>
						<td>
							<select id="zotero_library_type" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[library_type]">
								<option value="user" <?php selected( $settings['library_type'], 'user' ); ?>><?php esc_html_e( 'User Library', 'nature-zotero-publications' ); ?></option>
								<option value="group" <?php selected( $settings['library_type'], 'group' ); ?>><?php esc_html_e( 'Group Library', 'nature-zotero-publications' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Can be overridden per-block.', 'nature-zotero-publications' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zotero_library_id"><?php esc_html_e( 'Default Library / Group ID', 'nature-zotero-publications' ); ?></label></th>
						<td>
							<input type="text" id="zotero_library_id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[library_id]" value="<?php echo esc_attr( $settings['library_id'] ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Find your numeric user ID at zotero.org/settings/keys, or the group ID in your group library URL.', 'nature-zotero-publications' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="zotero_cache_minutes"><?php esc_html_e( 'Cache Duration (minutes)', 'nature-zotero-publications' ); ?></label></th>
						<td>
							<input type="number" min="1" id="zotero_cache_minutes" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cache_minutes]" value="<?php echo esc_attr( $settings['cache_minutes'] ); ?>" class="small-text" />
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Cache', 'nature-zotero-publications' ); ?></h2>
			<p><?php esc_html_e( 'Force-refresh all cached Zotero data immediately (otherwise it refreshes automatically based on the cache duration above).', 'nature-zotero-publications' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zotero_display_clear_cache" />
				<?php wp_nonce_field( 'zotero_display_clear_cache' ); ?>
				<?php submit_button( __( 'Clear Zotero Cache', 'nature-zotero-publications' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}
}
