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
		add_action( 'update_option_' . self::OPTION_KEY, array( $this, 'settings_updated' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION_KEY, array( $this, 'settings_added' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_zotero_display_diagnostic_batch', array( $this, 'handle_diagnostic_batch' ) );
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
		wp_set_option_autoload_values( array( self::OPTION_KEY => false ) );
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
		$stored = get_option( self::OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();
		if ( ! is_array( $input ) ) {
			return $stored;
		}
		$output            = array();
		$output['api_key'] = ! empty( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : ( $stored['api_key'] ?? '' );
		if ( ! empty( $input['remove_api_key'] ) || defined( 'ZOTERO_DISPLAY_API_KEY' ) ) {
			$output['api_key'] = '';
		}
		$output['library_type'] = ( isset( $input['library_type'] ) && 'group' === $input['library_type'] ) ? 'group' : 'user';
		$library_id             = $input['library_id'] ?? '';
		if ( ! is_string( $library_id ) || ( '' !== $library_id && ! preg_match( '/\A[0-9]+\z/', $library_id ) ) ) {
			add_settings_error(
				self::OPTION_KEY,
				'zotero_display_invalid_library_id',
				__( 'The library ID must contain only digits, or be empty. The previous library ID has been retained.', 'nature-zotero-publications' )
			);
			$library_id = $stored['library_id'] ?? '';
		}
		$output['library_id']    = $library_id;
		$output['cache_minutes'] = isset( $input['cache_minutes'] ) ? max( 1, absint( $input['cache_minutes'] ) ) : 60;

		return $output;
	}

	/**
	 * Invalidate the index only after a connection setting actually changes.
	 *
	 * @param mixed $old Previous option value.
	 * @param mixed $saved Saved option value.
	 * @return void
	 */
	public function settings_updated( $old, $saved ) {
		$old   = is_array( $old ) ? $old : array();
		$saved = is_array( $saved ) ? $saved : array();
		foreach ( array( 'api_key', 'library_type', 'library_id' ) as $key ) {
			if ( 'api_key' === $key && defined( 'ZOTERO_DISPLAY_API_KEY' ) ) {
				continue;
			}
			if ( ( $old[ $key ] ?? '' ) !== ( $saved[ $key ] ?? '' ) ) {
				Zotero_API::clear_all_caches();
				Sync::clear_all();
				return;
			}
		}
		Block::clear_fragment_cache();
	}

	/**
	 * Apply the same invalidation policy on the first successful option save.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value Saved option value.
	 * @return void
	 */
	public function settings_added( $option, $value ) {
		if ( get_option( Sync::SCHEMA_OPTION, false ) ) {
			$this->settings_updated( array(), $value );
		}
	}

	/**
	 * Get saved settings merged with defaults.
	 *
	 * @return array Plugin settings.
	 */
	public static function get_settings() {
		$stored   = get_option( self::OPTION_KEY, array() );
		$settings = wp_parse_args(
			is_array( $stored ) ? $stored : array(),
			array(
				'api_key'       => '',
				'library_type'  => 'user',
				'library_id'    => '',
				'cache_minutes' => 60,
			)
		);
		if ( defined( 'ZOTERO_DISPLAY_API_KEY' ) ) {
			$settings['api_key'] = (string) ZOTERO_DISPLAY_API_KEY;
		}
		return $settings;
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
		Sync::clear_all();

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
							<input type="password" id="zotero_api_key" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]" value="" <?php disabled( defined( 'ZOTERO_DISPLAY_API_KEY' ) ); ?> class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Leave blank to keep the saved key. Required for private libraries/groups. A ZOTERO_DISPLAY_API_KEY constant in wp-config.php overrides this field.', 'nature-zotero-publications' ); ?></p>
							<?php if ( ! defined( 'ZOTERO_DISPLAY_API_KEY' ) ) : ?>
								<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[remove_api_key]" value="1" /> <?php esc_html_e( 'Remove the saved API key', 'nature-zotero-publications' ); ?></label>
							<?php endif; ?>
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
							<input type="text" inputmode="numeric" pattern="[0-9]+" id="zotero_library_id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[library_id]" value="<?php echo esc_attr( $settings['library_id'] ); ?>" class="regular-text" />
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

			<?php $this->render_diagnostics(); ?>
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
	/**
	 * Process an administrator-only manual batch without bypassing retry limits.
	 *
	 * @return void
	 */
	public function handle_diagnostic_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nature-zotero-publications' ) );
		}
		check_admin_referer( 'zotero_display_diagnostic_batch' );
		$key = isset( $_POST['source'] ) && is_string( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		Sync::diagnostic_batch( $key );
		wp_safe_redirect( admin_url( 'options-general.php?page=nature-zotero-publications#zotero-diagnostics' ) );
		exit;
	}

	/**
	 * Display diagnostics without secrets or raw database errors.
	 *
	 * @return void
	 */
	private function render_diagnostics() {
		$report   = Sync::diagnostics();
		$settings = self::get_settings();
		?>
		<hr />
		<h2 id="zotero-diagnostics"><?php esc_html_e( 'Synchronization diagnostics', 'nature-zotero-publications' ); ?></h2>
		<p><?php esc_html_e( 'This panel shows the default source and up to 100 recently stored block sources. Open the frontend page to register a block override. Refresh this settings page to see updated results.', 'nature-zotero-publications' ); ?></p>
		<p><?php echo esc_html( empty( $settings['api_key'] ) ? __( 'API key: not configured.', 'nature-zotero-publications' ) : __( 'API key: configured (permissions not verified).', 'nature-zotero-publications' ) ); ?></p>
		<p><?php echo esc_html( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'Traffic-triggered WP-Cron is disabled. A host scheduler is required.', 'nature-zotero-publications' ) : __( 'Traffic-triggered WP-Cron is enabled; this does not verify execution.', 'nature-zotero-publications' ) ); ?></p>
		<ul>
		<?php foreach ( $report['tables'] as $table => $healthy ) : ?>
			<li><code><?php echo esc_html( $table ); ?></code>: <?php echo esc_html( $healthy ? __( 'Readable', 'nature-zotero-publications' ) : __( 'Missing or unreadable', 'nature-zotero-publications' ) ); ?></li>
		<?php endforeach; ?>
		</ul>
		<?php if ( empty( $report['sources'] ) ) : ?>
			<p><?php esc_html_e( 'No valid source found. Save a numeric default library ID above or open a configured block on the frontend.', 'nature-zotero-publications' ); ?></p>
		<?php endif; ?>
		<?php foreach ( $report['sources'] as $key => $row ) : ?>
			<h3><?php echo esc_html( $row['args']['library_type'] . ' / ' . $row['args']['library_id'] . ' / ' . $row['args']['collection'] . ' / ' . $row['args']['sort'] . ' / ' . $row['args']['direction'] ); ?></h3>
			<p><?php echo esc_html( sprintf( /* translators: 1: status, 2: processed count, 3: total count. */ __( 'Status: %1$s. Processed: %2$d / %3$d.', 'nature-zotero-publications' ), $row['status'], $row['processed'], $row['total'] ) ); ?></p>
			<p><?php esc_html_e( 'Next scheduled batch:', 'nature-zotero-publications' ); ?> <?php echo esc_html( $row['next'] ? wp_date( 'Y-m-d H:i:s T', $row['next'] ) : __( 'None', 'nature-zotero-publications' ) ); ?></p>
			<p><?php esc_html_e( 'Retry not before:', 'nature-zotero-publications' ); ?> <?php echo esc_html( $row['retry'] ? wp_date( 'Y-m-d H:i:s T', $row['retry'] ) : __( 'No pending retry deadline', 'nature-zotero-publications' ) ); ?></p>
			<?php if ( $row['schedule_error'] ) : ?>
				<p><?php esc_html_e( 'WordPress rejected the last scheduling attempt.', 'nature-zotero-publications' ); ?></p>
			<?php endif; ?>
			<?php if ( $row['error'] ) : ?>
				<p><?php echo esc_html( $row['error'] ); ?></p>
			<?php endif; ?>
			<?php if ( $row['admin_detail'] ) : ?>
				<p><strong><?php esc_html_e( 'Response explanation:', 'nature-zotero-publications' ); ?></strong> <?php echo esc_html( $row['admin_detail'] ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="zotero_display_diagnostic_batch" />
				<input type="hidden" name="source" value="<?php echo esc_attr( $key ); ?>" />
				<?php wp_nonce_field( 'zotero_display_diagnostic_batch' ); ?>
				<?php submit_button( __( 'Run one sync batch', 'nature-zotero-publications' ), 'secondary', 'submit', false, in_array( false, $report['tables'], true ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
			</form>
		<?php endforeach; ?>
		<p><?php esc_html_e( 'A manual run processes at most 100 items and may wait up to 20 seconds for Zotero. Locks and retry delays remain enforced; a fresh completed source does not need a new batch. Existing publications are preserved.', 'nature-zotero-publications' ); ?></p>
		<?php
	}
}
