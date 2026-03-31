<?php
/**
 * Admin UI and actions.
 *
 * @package Helikon_XML_Woo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTX_XML_Woo_Sync_Admin {
	/**
	 * State instance.
	 *
	 * @var HTX_XML_Woo_Sync_State
	 */
	private $state;

	/**
	 * Feed instance.
	 *
	 * @var HTX_XML_Woo_Sync_Feed
	 */
	private $feed;

	/**
	 * Runner instance.
	 *
	 * @var HTX_XML_Woo_Sync_Runner
	 */
	private $runner;

	/**
	 * Constructor.
	 *
	 * @param HTX_XML_Woo_Sync_State  $state  State service.
	 * @param HTX_XML_Woo_Sync_Feed   $feed   Feed service.
	 * @param HTX_XML_Woo_Sync_Runner $runner Sync runner.
	 */
	public function __construct( $state, $feed, $runner ) {
		$this->state  = $state;
		$this->feed   = $feed;
		$this->runner = $runner;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_htx_xml_manual_sync', array( $this, 'handle_manual_sync' ) );
		add_action( 'admin_post_htx_xml_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_htx_xml_clear_lock', array( $this, 'handle_clear_lock' ) );
	}

	/**
	 * Add the submenu page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Helikon XML Sync', 'helikon-xml-woo-sync' ),
			__( 'Helikon XML Sync', 'helikon-xml-woo-sync' ),
			'manage_woocommerce',
			'htx-xml-woo-sync',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'htx_xml_woo_sync_group',
			HTX_XML_Woo_Sync_Plugin::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this->state, 'sanitize_settings' ),
				'default'           => $this->state->get_default_settings(),
			)
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = $this->state->get_settings();
		$state    = $this->state->get_state();
		$lock     = $this->state->get_lock();
		$notice   = isset( $_GET['htx_notice'] ) ? sanitize_key( wp_unslash( $_GET['htx_notice'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Helikon XML Woo Sync', 'helikon-xml-woo-sync' ); ?></h1>
			<?php $this->render_notice( $notice ); ?>

			<div style="background:#fff;border:1px solid #dcdcde;padding:16px;margin:16px 0;max-width:1100px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Current Status', 'helikon-xml-woo-sync' ); ?></h2>
				<p><strong><?php esc_html_e( 'Last sync time:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( $state['last_sync_time'] ? $state['last_sync_time'] : __( 'Never', 'helikon-xml-woo-sync' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Last status:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( ucfirst( (string) $state['last_status'] ) ); ?></p>
				<p><strong><?php esc_html_e( 'Status message:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( $state['last_message'] ? $state['last_message'] : __( 'No sync has run yet.', 'helikon-xml-woo-sync' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Running now:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( ! empty( $state['is_running'] ) ? __( 'Yes', 'helikon-xml-woo-sync' ) : __( 'No', 'helikon-xml-woo-sync' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Current phase:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( ucfirst( (string) $state['phase'] ) ); ?></p>
				<p><strong><?php esc_html_e( 'Current checkpoint:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( sprintf( '%d / %d', (int) $state['checkpoint'], (int) $state['total_products'] ) ); ?></p>
				<p><strong><?php esc_html_e( 'Lock expires:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( ! empty( $lock['expires_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $lock['expires_at'] ) . ' UTC' : __( 'No active lock', 'helikon-xml-woo-sync' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Processed:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( (int) $state['summary']['processed'] ); ?></p>
				<p><strong><?php esc_html_e( 'Created:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( (int) $state['summary']['created'] ); ?></p>
				<p><strong><?php esc_html_e( 'Updated:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( (int) $state['summary']['updated'] ); ?></p>
				<p><strong><?php esc_html_e( 'Skipped:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( (int) $state['summary']['skipped'] ); ?></p>
				<p><strong><?php esc_html_e( 'Failed:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( (int) $state['summary']['failed'] ); ?></p>
				<p><strong><?php esc_html_e( 'Missing adjusted:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( (int) $state['summary']['missing_adjusted'] ); ?></p>
				<p><strong><?php esc_html_e( 'Last error:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( $state['last_error'] ? $state['last_error'] : __( 'None', 'helikon-xml-woo-sync' ) ); ?></p>

				<div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_SYNC ); ?>
						<input type="hidden" name="action" value="htx_xml_manual_sync">
						<?php submit_button( __( 'Run Sync Now', 'helikon-xml-woo-sync' ), 'primary', 'submit', false ); ?>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_TEST ); ?>
						<input type="hidden" name="action" value="htx_xml_test_connection">
						<?php submit_button( __( 'Test Connection', 'helikon-xml-woo-sync' ), 'secondary', 'submit', false ); ?>
					</form>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_CLEAR_LOCK ); ?>
						<input type="hidden" name="action" value="htx_xml_clear_lock">
						<?php submit_button( __( 'Clear Lock', 'helikon-xml-woo-sync' ), 'delete', 'submit', false ); ?>
					</form>
				</div>
			</div>

			<form method="post" action="options.php" style="max-width:1100px;">
				<?php settings_fields( 'htx_xml_woo_sync_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="htx_xml_url"><?php esc_html_e( 'XML URL', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[xml_url]" id="htx_xml_url" type="url" class="regular-text code" value="<?php echo esc_attr( $settings['xml_url'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_username"><?php esc_html_e( 'Username', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[username]" id="htx_username" type="text" class="regular-text" value="<?php echo esc_attr( $settings['username'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_password"><?php esc_html_e( 'Password', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[password]" id="htx_password" type="password" class="regular-text" value="<?php echo esc_attr( $settings['password'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_media_base_url"><?php esc_html_e( 'Media Base URL', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[media_base_url]" id="htx_media_base_url" type="url" class="regular-text code" value="<?php echo esc_attr( $settings['media_base_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. Used when image paths in the XML are relative.', 'helikon-xml-woo-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_grouping_mode"><?php esc_html_e( 'Grouping Mode', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[grouping_mode]" id="htx_grouping_mode">
								<option value="field_then_sku_base" <?php selected( $settings['grouping_mode'], 'field_then_sku_base' ); ?>><?php esc_html_e( 'Use group field, then SKU base fallback', 'helikon-xml-woo-sync' ); ?></option>
								<option value="field_only" <?php selected( $settings['grouping_mode'], 'field_only' ); ?>><?php esc_html_e( 'Use only the group field', 'helikon-xml-woo-sync' ); ?></option>
								<option value="sku_base" <?php selected( $settings['grouping_mode'], 'sku_base' ); ?>><?php esc_html_e( 'Use normalized SKU base', 'helikon-xml-woo-sync' ); ?></option>
								<option value="name_and_sku_base" <?php selected( $settings['grouping_mode'], 'name_and_sku_base' ); ?>><?php esc_html_e( 'Use product name plus SKU base', 'helikon-xml-woo-sync' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_grouping_path"><?php esc_html_e( 'Grouping Field Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[grouping_path]" id="htx_grouping_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['grouping_path'] ); ?>">
							<p class="description"><?php esc_html_e( 'Optional dot path for an explicit parent/group identifier in the XML.', 'helikon-xml-woo-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_variant_attribute_map"><?php esc_html_e( 'Variant Attribute Map', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<textarea name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[variant_attribute_map]" id="htx_variant_attribute_map" rows="5" class="large-text code"><?php echo esc_textarea( $settings['variant_attribute_map'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One mapping per line in path=Label format. Example: size.tagEU=Size', 'helikon-xml-woo-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_price_path"><?php esc_html_e( 'Price Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[price_path]" id="htx_price_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['price_path'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_sale_price_path"><?php esc_html_e( 'Sale Price Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[sale_price_path]" id="htx_sale_price_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['sale_price_path'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_stock_qty_path"><?php esc_html_e( 'Stock Quantity Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[stock_qty_path]" id="htx_stock_qty_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['stock_qty_path'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_stock_status_path"><?php esc_html_e( 'Stock Status Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[stock_status_path]" id="htx_stock_status_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['stock_status_path'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_schedule"><?php esc_html_e( 'Schedule', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[schedule]" id="htx_schedule">
								<option value="manual" <?php selected( $settings['schedule'], 'manual' ); ?>><?php esc_html_e( 'Manual only', 'helikon-xml-woo-sync' ); ?></option>
								<option value="every_30_minutes" <?php selected( $settings['schedule'], 'every_30_minutes' ); ?>><?php esc_html_e( 'Every 30 minutes', 'helikon-xml-woo-sync' ); ?></option>
								<option value="hourly" <?php selected( $settings['schedule'], 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'helikon-xml-woo-sync' ); ?></option>
								<option value="twicedaily" <?php selected( $settings['schedule'], 'twicedaily' ); ?>><?php esc_html_e( 'Twice daily', 'helikon-xml-woo-sync' ); ?></option>
								<option value="daily" <?php selected( $settings['schedule'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'helikon-xml-woo-sync' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_batch_size"><?php esc_html_e( 'Batch Size', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[batch_size]" id="htx_batch_size" type="number" class="small-text" min="1" max="100" value="<?php echo esc_attr( $settings['batch_size'] ); ?>">
							<p class="description"><?php esc_html_e( 'Conservative shared-hosting default is 20.', 'helikon-xml-woo-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_missing_action"><?php esc_html_e( 'Missing Item Action', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[missing_action]" id="htx_missing_action">
								<option value="ignore" <?php selected( $settings['missing_action'], 'ignore' ); ?>><?php esc_html_e( 'Do nothing', 'helikon-xml-woo-sync' ); ?></option>
								<option value="outofstock" <?php selected( $settings['missing_action'], 'outofstock' ); ?>><?php esc_html_e( 'Mark variations out of stock', 'helikon-xml-woo-sync' ); ?></option>
								<option value="draft" <?php selected( $settings['missing_action'], 'draft' ); ?>><?php esc_html_e( 'Set variations to draft', 'helikon-xml-woo-sync' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Nothing is deleted by default.', 'helikon-xml-woo-sync' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'helikon-xml-woo-sync' ) ); ?>
			</form>

			<div style="background:#fff;border:1px solid #dcdcde;padding:16px;margin:16px 0;max-width:1100px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Recent Log', 'helikon-xml-woo-sync' ); ?></h2>
				<pre style="white-space:pre-wrap;max-height:320px;overflow:auto;margin:0;"><?php echo esc_html( implode( "\n", array_reverse( (array) $state['log'] ) ) ); ?></pre>
			</div>
		</div>
		<?php
	}

	/**
	 * Queue a manual sync.
	 *
	 * @return void
	 */
	public function handle_manual_sync() {
		$this->assert_admin_access( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_SYNC );

		$result = $this->runner->start_sync( 'manual' );
		$notice = is_wp_error( $result ) ? 'sync_busy' : 'sync_queued';
		if ( is_wp_error( $result ) ) {
			$this->state->add_log( $result->get_error_message(), 'warning' );
		}

		$this->redirect_with_notice( $notice );
	}

	/**
	 * Test remote feed connectivity.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		$this->assert_admin_access( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_TEST );

		$result = $this->feed->test_connection( $this->state->get_settings() );
		if ( is_wp_error( $result ) ) {
			$this->state->add_log( $result->get_error_message(), 'warning' );
			$this->redirect_with_notice( 'test_failed' );
		}

		$this->state->add_log( $result['message'] );
		$this->redirect_with_notice( 'test_ok' );
	}

	/**
	 * Clear the lock.
	 *
	 * @return void
	 */
	public function handle_clear_lock() {
		$this->assert_admin_access( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_CLEAR_LOCK );
		$this->runner->clear_lock_and_reset();
		$this->redirect_with_notice( 'lock_cleared' );
	}

	/**
	 * Render admin notices from query args.
	 *
	 * @param string $notice Notice code.
	 * @return void
	 */
	private function render_notice( $notice ) {
		$messages = array(
			'sync_queued'  => array( 'success', __( 'Sync queued. It will continue in small batches.', 'helikon-xml-woo-sync' ) ),
			'sync_busy'    => array( 'warning', __( 'A sync is already running. Check the status box or clear the lock if it is stuck.', 'helikon-xml-woo-sync' ) ),
			'test_ok'      => array( 'success', __( 'Connection test succeeded.', 'helikon-xml-woo-sync' ) ),
			'test_failed'  => array( 'error', __( 'Connection test failed. Check the log for details.', 'helikon-xml-woo-sync' ) ),
			'lock_cleared' => array( 'warning', __( 'The sync lock was cleared manually.', 'helikon-xml-woo-sync' ) ),
		);

		if ( empty( $messages[ $notice ] ) ) {
			return;
		}

		list( $class, $message ) = $messages[ $notice ];
		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Verify capability and nonce.
	 *
	 * @param string $nonce_action Nonce action.
	 * @return void
	 */
	private function assert_admin_access( $nonce_action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this sync.', 'helikon-xml-woo-sync' ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Redirect back to the settings page with a notice.
	 *
	 * @param string $notice Notice code.
	 * @return void
	 */
	private function redirect_with_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'htx-xml-woo-sync',
					'htx_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
