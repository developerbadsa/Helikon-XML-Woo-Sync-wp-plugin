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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_htx_xml_manual_sync', array( $this, 'handle_manual_sync' ) );
		add_action( 'admin_post_htx_xml_test_sync', array( $this, 'handle_test_sync' ) );
		add_action( 'admin_post_htx_xml_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_htx_xml_clear_lock', array( $this, 'handle_clear_lock' ) );
		add_action( 'admin_post_htx_xml_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'admin_post_htx_xml_purge_data', array( $this, 'handle_purge_data' ) );
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
	 * Enqueue assets for the plugin dashboard.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'woocommerce_page_htx-xml-woo-sync' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'htx-xml-woo-sync-admin',
			HTX_XML_WOO_SYNC_URL . 'assets/admin.css',
			array(),
			HTX_XML_WOO_SYNC_VERSION
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

		$settings         = $this->state->get_settings();
		$state            = $this->state->get_state();
		$lock             = $this->state->get_lock();
		$notice           = isset( $_GET['htx_notice'] ) ? sanitize_key( wp_unslash( $_GET['htx_notice'] ) ) : '';
		$summary          = wp_parse_args( (array) $state['summary'], $this->state->get_empty_summary() );
		$is_running       = ! empty( $state['is_running'] );
		$run_mode_label   = $is_running
			? ( ! empty( $state['is_test_run'] )
				? sprintf( __( 'Test sync (%d rows)', 'helikon-xml-woo-sync' ), (int) $state['max_products'] )
				: __( 'Full sync', 'helikon-xml-woo-sync' ) )
			: __( 'No active run', 'helikon-xml-woo-sync' );
		$phase_label      = $this->format_dashboard_label( $state['phase'] );
		$status_label     = $this->format_dashboard_label( $state['last_status'] );
		$progress_percent = $this->get_progress_percent( $state );
		$schedule_label   = $this->format_schedule_label( $settings['schedule'] );
		$recent_logs      = $this->get_recent_log_entries( (array) $state['log'], 8 );
		$raw_log_output   = implode( "\n", array_reverse( (array) $state['log'] ) );
		$parent_total     = (int) $summary['parents_created'] + (int) $summary['parents_updated'];
		$variation_total  = (int) $summary['variations_created'] + (int) $summary['variations_updated'];
		$imported_total   = (int) $summary['created'] + (int) $summary['updated'];
		$lock_text        = __( 'No active lock', 'helikon-xml-woo-sync' );

		if ( ! empty( $lock['expires_at'] ) ) {
			$lock_text = gmdate( 'Y-m-d H:i:s', (int) $lock['expires_at'] ) . ' UTC';
		}
		?>
		<div class="wrap htx-sync-dashboard">
			<h1><?php esc_html_e( 'Helikon XML Woo Sync', 'helikon-xml-woo-sync' ); ?></h1>
			<p class="htx-sync-dashboard__intro"><?php esc_html_e( 'A cleaner sync dashboard for monitoring feed health, running actions, and managing XML mapping in one place.', 'helikon-xml-woo-sync' ); ?></p>
			<?php $this->render_notice( $notice ); ?>

			<div class="htx-sync-report-grid">
				<div class="htx-sync-report-card <?php echo esc_attr( $is_running ? 'is-success' : 'is-neutral' ); ?>">
					<span class="htx-sync-report-card__label"><?php esc_html_e( 'Run State', 'helikon-xml-woo-sync' ); ?></span>
					<strong class="htx-sync-report-card__value"><?php echo esc_html( $is_running ? __( 'Running', 'helikon-xml-woo-sync' ) : $status_label ); ?></strong>
					<p class="htx-sync-report-card__meta"><?php echo esc_html( sprintf( __( 'Phase: %s', 'helikon-xml-woo-sync' ), $phase_label ) ); ?></p>
				</div>

				<div class="htx-sync-report-card is-brand">
					<span class="htx-sync-report-card__label"><?php esc_html_e( 'Processed Rows', 'helikon-xml-woo-sync' ); ?></span>
					<strong class="htx-sync-report-card__value"><?php echo esc_html( number_format_i18n( (int) $summary['processed'] ) ); ?></strong>
					<p class="htx-sync-report-card__meta"><?php echo esc_html( sprintf( __( 'Checkpoint %1$d of %2$d', 'helikon-xml-woo-sync' ), (int) $state['checkpoint'], (int) $state['total_products'] ) ); ?></p>
				</div>

				<div class="htx-sync-report-card is-neutral">
					<span class="htx-sync-report-card__label"><?php esc_html_e( 'Parent Products', 'helikon-xml-woo-sync' ); ?></span>
					<strong class="htx-sync-report-card__value"><?php echo esc_html( number_format_i18n( $parent_total ) ); ?></strong>
					<p class="htx-sync-report-card__meta"><?php esc_html_e( 'Top-level WooCommerce products shown on All Products.', 'helikon-xml-woo-sync' ); ?></p>
				</div>

				<div class="htx-sync-report-card is-neutral">
					<span class="htx-sync-report-card__label"><?php esc_html_e( 'Variations', 'helikon-xml-woo-sync' ); ?></span>
					<strong class="htx-sync-report-card__value"><?php echo esc_html( number_format_i18n( $variation_total ) ); ?></strong>
					<p class="htx-sync-report-card__meta"><?php esc_html_e( 'Child SKUs linked under the parent products.', 'helikon-xml-woo-sync' ); ?></p>
				</div>

				<div class="htx-sync-report-card is-neutral">
					<span class="htx-sync-report-card__label"><?php esc_html_e( 'Imported Entries', 'helikon-xml-woo-sync' ); ?></span>
					<strong class="htx-sync-report-card__value"><?php echo esc_html( number_format_i18n( $imported_total ) ); ?></strong>
					<p class="htx-sync-report-card__meta"><?php echo esc_html( sprintf( __( '%1$d created, %2$d updated across parents and variations', 'helikon-xml-woo-sync' ), (int) $summary['created'], (int) $summary['updated'] ) ); ?></p>
				</div>

				<div class="htx-sync-report-card <?php echo esc_attr( ( (int) $summary['failed'] > 0 || (int) $summary['skipped'] > 0 ) ? 'is-warning' : 'is-neutral' ); ?>">
					<span class="htx-sync-report-card__label"><?php esc_html_e( 'Exceptions', 'helikon-xml-woo-sync' ); ?></span>
					<strong class="htx-sync-report-card__value"><?php echo esc_html( number_format_i18n( (int) $summary['failed'] + (int) $summary['skipped'] ) ); ?></strong>
					<p class="htx-sync-report-card__meta"><?php echo esc_html( sprintf( __( '%1$d failed, %2$d skipped', 'helikon-xml-woo-sync' ), (int) $summary['failed'], (int) $summary['skipped'] ) ); ?></p>
				</div>

				<div class="htx-sync-report-card is-neutral">
					<span class="htx-sync-report-card__label"><?php esc_html_e( 'Automation', 'helikon-xml-woo-sync' ); ?></span>
					<strong class="htx-sync-report-card__value"><?php echo esc_html( $schedule_label ); ?></strong>
					<p class="htx-sync-report-card__meta"><?php echo esc_html( sprintf( __( 'Batch %1$d, test limit %2$d', 'helikon-xml-woo-sync' ), (int) $settings['batch_size'], (int) $settings['test_sync_limit'] ) ); ?></p>
				</div>
			</div>

			<div class="htx-sync-card htx-sync-card--monitor">
				<div class="htx-sync-monitor__header">
					<div>
						<h2><?php esc_html_e( 'Processing Monitor', 'helikon-xml-woo-sync' ); ?></h2>
						<p><?php echo esc_html( $state['last_message'] ? $state['last_message'] : __( 'No sync has run yet. Use the action buttons below to start one.', 'helikon-xml-woo-sync' ) ); ?></p>
					</div>

					<div class="htx-sync-monitor__badges">
						<span class="htx-sync-badge <?php echo esc_attr( $is_running ? 'is-success' : 'is-neutral' ); ?>"><?php echo esc_html( $status_label ); ?></span>
						<span class="htx-sync-badge is-neutral"><?php echo esc_html( $run_mode_label ); ?></span>
						<span class="htx-sync-badge is-neutral"><?php echo esc_html( $phase_label ); ?></span>
					</div>
				</div>

				<div class="htx-sync-monitor__stats">
					<div class="htx-sync-monitor__stat">
						<span><?php esc_html_e( 'Progress', 'helikon-xml-woo-sync' ); ?></span>
						<strong><?php echo esc_html( sprintf( '%d%%', $progress_percent ) ); ?></strong>
					</div>
					<div class="htx-sync-monitor__stat">
						<span><?php esc_html_e( 'Current Run', 'helikon-xml-woo-sync' ); ?></span>
						<strong><?php echo esc_html( $state['started_at'] ? $state['started_at'] : __( 'Not started', 'helikon-xml-woo-sync' ) ); ?></strong>
					</div>
					<div class="htx-sync-monitor__stat">
						<span><?php esc_html_e( 'Lock', 'helikon-xml-woo-sync' ); ?></span>
						<strong><?php echo esc_html( $lock_text ); ?></strong>
					</div>
				</div>

				<div class="htx-sync-monitor__progress">
					<div class="htx-sync-monitor__progress-bar" style="width: <?php echo esc_attr( $progress_percent ); ?>%;"></div>
				</div>

				<p class="description"><?php esc_html_e( 'Processed Rows counts feed rows. WooCommerce All Products usually shows only the parent products, while child variations stay inside each parent.', 'helikon-xml-woo-sync' ); ?></p>

				<div class="htx-sync-monitor__panels">
					<div class="htx-sync-monitor__panel">
						<span><?php esc_html_e( 'Checkpoint', 'helikon-xml-woo-sync' ); ?></span>
						<strong><?php echo esc_html( sprintf( '%d / %d', (int) $state['checkpoint'], (int) $state['total_products'] ) ); ?></strong>
						<p><?php esc_html_e( 'How many feed rows have already been handled in this run.', 'helikon-xml-woo-sync' ); ?></p>
					</div>

					<div class="htx-sync-monitor__panel">
						<span><?php esc_html_e( 'Last Error', 'helikon-xml-woo-sync' ); ?></span>
						<strong><?php echo esc_html( $state['last_error'] ? __( 'Needs attention', 'helikon-xml-woo-sync' ) : __( 'No error', 'helikon-xml-woo-sync' ) ); ?></strong>
						<p><?php echo esc_html( $state['last_error'] ? $state['last_error'] : __( 'No errors have been recorded for the latest run.', 'helikon-xml-woo-sync' ) ); ?></p>
					</div>

					<div class="htx-sync-monitor__panel">
						<span><?php esc_html_e( 'Last Sync Time', 'helikon-xml-woo-sync' ); ?></span>
						<strong><?php echo esc_html( $state['last_sync_time'] ? $state['last_sync_time'] : __( 'Never', 'helikon-xml-woo-sync' ) ); ?></strong>
						<p><?php echo esc_html( sprintf( __( 'Trigger: %s', 'helikon-xml-woo-sync' ), $this->format_dashboard_label( $state['trigger'] ) ) ); ?></p>
					</div>
				</div>
			</div>

			<div class="htx-sync-card htx-sync-card--status" style="background:#fff;border:1px solid #dcdcde;padding:16px;margin:16px 0;max-width:1100px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Detailed Snapshot', 'helikon-xml-woo-sync' ); ?></h2>
				<div class="htx-sync-status-grid">
				<p><strong><?php esc_html_e( 'Last sync time:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( $state['last_sync_time'] ? $state['last_sync_time'] : __( 'Never', 'helikon-xml-woo-sync' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Last status:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( ucfirst( (string) $state['last_status'] ) ); ?></p>
				<p><strong><?php esc_html_e( 'Status message:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( $state['last_message'] ? $state['last_message'] : __( 'No sync has run yet.', 'helikon-xml-woo-sync' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Running now:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( ! empty( $state['is_running'] ) ? __( 'Yes', 'helikon-xml-woo-sync' ) : __( 'No', 'helikon-xml-woo-sync' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Run mode:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( ! empty( $state['is_test_run'] ) ? sprintf( __( 'Test sync (%d rows)', 'helikon-xml-woo-sync' ), (int) $state['max_products'] ) : __( 'Full sync', 'helikon-xml-woo-sync' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Current phase:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( ucfirst( (string) $state['phase'] ) ); ?></p>
				<p><strong><?php esc_html_e( 'Current checkpoint:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( sprintf( '%d / %d', (int) $state['checkpoint'], (int) $state['total_products'] ) ); ?></p>
				<p><strong><?php esc_html_e( 'Lock expires:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( ! empty( $lock['expires_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $lock['expires_at'] ) . ' UTC' : __( 'No active lock', 'helikon-xml-woo-sync' ) ); ?></p>
				<p><strong><?php esc_html_e( 'Processed rows:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( (int) $summary['processed'] ); ?></p>
				<p><strong><?php esc_html_e( 'Parent products:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( $parent_total ); ?></p>
				<p><strong><?php esc_html_e( 'Variations:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( $variation_total ); ?></p>
				<p><strong><?php esc_html_e( 'Created:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( (int) $summary['created'] ); ?></p>
				<p><strong><?php esc_html_e( 'Updated:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( (int) $summary['updated'] ); ?></p>
				<p><strong><?php esc_html_e( 'Skipped:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( (int) $summary['skipped'] ); ?></p>
				<p><strong><?php esc_html_e( 'Failed:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( (int) $summary['failed'] ); ?></p>
				<p><strong><?php esc_html_e( 'Missing adjusted:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( (int) $summary['missing_adjusted'] ); ?></p>
				<p><strong><?php esc_html_e( 'Last error:', 'helikon-xml-woo-sync' ); ?></strong> <?php echo esc_html( $state['last_error'] ? $state['last_error'] : __( 'None', 'helikon-xml-woo-sync' ) ); ?></p>
				</div>

				<div class="htx-sync-actions" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px;">
					<form class="htx-sync-action-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_SYNC ); ?>
						<input type="hidden" name="action" value="htx_xml_manual_sync">
						<?php submit_button( __( 'Run Sync Now', 'helikon-xml-woo-sync' ), 'primary', 'submit', false ); ?>
					</form>

					<form class="htx-sync-action-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_TEST_SYNC ); ?>
						<input type="hidden" name="action" value="htx_xml_test_sync">
						<?php submit_button( __( 'Run Test Sync', 'helikon-xml-woo-sync' ), 'secondary', 'submit', false ); ?>
					</form>

					<form class="htx-sync-action-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_TEST ); ?>
						<input type="hidden" name="action" value="htx_xml_test_connection">
						<?php submit_button( __( 'Test Connection', 'helikon-xml-woo-sync' ), 'secondary', 'submit', false ); ?>
					</form>

					<form class="htx-sync-action-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_CLEAR_LOCK ); ?>
						<input type="hidden" name="action" value="htx_xml_clear_lock">
						<?php submit_button( __( 'Clear Lock', 'helikon-xml-woo-sync' ), 'delete', 'submit', false ); ?>
					</form>

					<form class="htx-sync-action-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_CLEAR_LOGS ); ?>
						<input type="hidden" name="action" value="htx_xml_clear_logs">
						<?php submit_button( __( 'Clear Logs', 'helikon-xml-woo-sync' ), 'secondary', 'submit', false ); ?>
					</form>

					<form class="htx-sync-action-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'This will permanently delete all imported products, variations, and plugin-imported images. Continue?', 'helikon-xml-woo-sync' ) ); ?>');">
						<?php wp_nonce_field( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_PURGE_DATA ); ?>
						<input type="hidden" name="action" value="htx_xml_purge_data">
						<?php submit_button( __( 'Delete Imported Data', 'helikon-xml-woo-sync' ), 'delete', 'submit', false ); ?>
					</form>
				</div>
				<p class="description" style="margin:12px 0 0;">
					<?php esc_html_e( 'Run Sync Now, Run Test Sync, and Test Connection use the last saved settings. If you change the XML URL or mappings below, click Save Settings first.', 'helikon-xml-woo-sync' ); ?>
				</p>
				<p class="description" style="margin:8px 0 0;">
					<?php esc_html_e( 'Delete Imported Data removes only plugin-managed products, variations, and images. Clear Logs removes the recent log list.', 'helikon-xml-woo-sync' ); ?>
				</p>
			</div>

			<div class="htx-sync-card htx-sync-card--activity">
				<div class="htx-sync-section-head">
					<div>
						<h2><?php esc_html_e( 'Recent Activity', 'helikon-xml-woo-sync' ); ?></h2>
						<p><?php esc_html_e( 'Latest processing and validation events appear here with time and severity, so you can quickly see what is happening now.', 'helikon-xml-woo-sync' ); ?></p>
					</div>
				</div>

				<?php if ( empty( $recent_logs ) ) : ?>
					<p class="htx-sync-empty-state"><?php esc_html_e( 'No log entries yet. Start a sync or run a connection test to see activity.', 'helikon-xml-woo-sync' ); ?></p>
				<?php else : ?>
					<div class="htx-sync-activity-feed">
						<?php foreach ( $recent_logs as $log_entry ) : ?>
							<div class="htx-sync-activity-item htx-sync-activity-item--<?php echo esc_attr( $log_entry['level'] ); ?>">
								<div class="htx-sync-activity-item__head">
									<span class="htx-sync-activity-item__level"><?php echo esc_html( strtoupper( $log_entry['level'] ) ); ?></span>
									<span class="htx-sync-activity-item__time"><?php echo esc_html( $log_entry['time'] ); ?></span>
								</div>
								<p class="htx-sync-activity-item__message"><?php echo esc_html( $log_entry['message'] ); ?></p>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<form class="htx-sync-card htx-sync-card--settings htx-sync-settings" method="post" action="options.php" style="max-width:1100px;">
				<?php settings_fields( 'htx_xml_woo_sync_group' ); ?>
				<div class="htx-sync-settings__header">
					<h2><?php esc_html_e( 'Settings and Mapping', 'helikon-xml-woo-sync' ); ?></h2>
					<p><?php esc_html_e( 'Tune the supplier connection, field mappings, scheduling, and test-sync behaviour from this section.', 'helikon-xml-woo-sync' ); ?></p>
				</div>
				<table class="form-table htx-sync-settings-table" role="presentation">
					<tr>
						<th scope="row"><label for="htx_xml_url"><?php esc_html_e( 'XML URL', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<input
								name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[xml_url]"
								id="htx_xml_url"
								type="url"
								class="large-text code"
								style="max-width:100%;"
								value="<?php echo esc_attr( $settings['xml_url'] ); ?>"
							>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_username"><?php esc_html_e( 'Username', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[username]" id="htx_username" type="text" class="regular-text" value="<?php echo esc_attr( $settings['username'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_password"><?php esc_html_e( 'Password', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[password]" id="htx_password" type="password" class="regular-text" value="<?php echo esc_attr( $settings['password'] ); ?>">
							<p class="description"><?php esc_html_e( 'If the supplier password contains symbols, save it exactly as provided before testing the connection.', 'helikon-xml-woo-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_media_base_url"><?php esc_html_e( 'Media Base URL', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[media_base_url]" id="htx_media_base_url" type="url" class="large-text code" style="max-width:100%;" value="<?php echo esc_attr( $settings['media_base_url'] ); ?>">
							<p class="description"><?php esc_html_e( 'Optional. Used when image paths in the XML are relative.', 'helikon-xml-woo-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_sku_path"><?php esc_html_e( 'SKU Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[sku_path]" id="htx_sku_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['sku_path'] ); ?>">
							<p class="description"><?php esc_html_e( 'Dot path to the unique SKU field. Example: SKU', 'helikon-xml-woo-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_title_path"><?php esc_html_e( 'Title Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[title_path]" id="htx_title_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['title_path'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_description_path"><?php esc_html_e( 'Description Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[description_path]" id="htx_description_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['description_path'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_brand_path"><?php esc_html_e( 'Brand Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[brand_path]" id="htx_brand_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['brand_path'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_manufacturer_path"><?php esc_html_e( 'Manufacturer Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[manufacturer_path]" id="htx_manufacturer_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['manufacturer_path'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_main_photo_path"><?php esc_html_e( 'Main Image Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[main_photo_path]" id="htx_main_photo_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['main_photo_path'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_gallery_photo_path"><?php esc_html_e( 'Gallery Image Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[gallery_photo_path]" id="htx_gallery_photo_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['gallery_photo_path'] ); ?>">
							<p class="description"><?php esc_html_e( 'Repeated nodes are supported. Example: Multimedia.additionalPhotos.photo', 'helikon-xml-woo-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_erp_id_path"><?php esc_html_e( 'ERP ID Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[erp_id_path]" id="htx_erp_id_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['erp_id_path'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_weight_path"><?php esc_html_e( 'Weight Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[weight_path]" id="htx_weight_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['weight_path'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="htx_weight_unit_path"><?php esc_html_e( 'Weight Unit Path', 'helikon-xml-woo-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[weight_unit_path]" id="htx_weight_unit_path" type="text" class="regular-text code" value="<?php echo esc_attr( $settings['weight_unit_path'] ); ?>"></td>
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
							<p class="description"><?php esc_html_e( 'Optional dot path for an explicit parent/group identifier in the XML. Leave blank to auto-detect common keys like groupId.', 'helikon-xml-woo-sync' ); ?></p>
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
						<th scope="row"><label for="htx_test_sync_limit"><?php esc_html_e( 'Test Sync Limit', 'helikon-xml-woo-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( HTX_XML_Woo_Sync_Plugin::OPTION_KEY ); ?>[test_sync_limit]" id="htx_test_sync_limit" type="number" class="small-text" min="1" max="500" value="<?php echo esc_attr( $settings['test_sync_limit'] ); ?>">
							<p class="description"><?php esc_html_e( 'Used by Run Test Sync only. It syncs only the first N feed rows and skips missing-item cleanup.', 'helikon-xml-woo-sync' ); ?></p>
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

			<div class="htx-sync-card htx-sync-card--log" style="background:#fff;border:1px solid #dcdcde;padding:16px;margin:16px 0;max-width:1100px;">
				<div class="htx-sync-section-head">
					<div>
						<h2 style="margin-top:0;"><?php esc_html_e( 'Full Log Console', 'helikon-xml-woo-sync' ); ?></h2>
						<p><?php esc_html_e( 'Raw log output is still available here for copy/paste and debugging, with newest entries shown first.', 'helikon-xml-woo-sync' ); ?></p>
					</div>
				</div>
				<?php if ( '' === $raw_log_output ) : ?>
					<p class="htx-sync-empty-state"><?php esc_html_e( 'No raw logs available yet.', 'helikon-xml-woo-sync' ); ?></p>
				<?php else : ?>
					<pre class="htx-sync-log" style="white-space:pre-wrap;max-height:320px;overflow:auto;margin:0;"><?php echo esc_html( $raw_log_output ); ?></pre>
				<?php endif; ?>
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
	 * Queue a limited test sync.
	 *
	 * @return void
	 */
	public function handle_test_sync() {
		$this->assert_admin_access( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_TEST_SYNC );

		$settings = $this->state->get_settings();
		$result   = $this->runner->start_sync(
			'manual_test',
			array(
				'is_test_run' => true,
				'max_products' => max( 1, absint( $settings['test_sync_limit'] ) ),
			)
		);
		$notice = is_wp_error( $result ) ? 'sync_busy' : 'test_sync_queued';
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
			return;
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
	 * Clear the recent logs.
	 *
	 * @return void
	 */
	public function handle_clear_logs() {
		$this->assert_admin_access( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_CLEAR_LOGS );
		$this->state->clear_logs();
		$this->redirect_with_notice( 'logs_cleared' );
	}

	/**
	 * Remove all imported data created by this plugin.
	 *
	 * @return void
	 */
	public function handle_purge_data() {
		$this->assert_admin_access( HTX_XML_Woo_Sync_Plugin::NONCE_ACTION_PURGE_DATA );

		$result = $this->runner->purge_imported_data();
		if ( is_wp_error( $result ) ) {
			$this->state->add_log( $result->get_error_message(), 'warning' );
			$this->redirect_with_notice( 'purge_failed' );
			return;
		}

		$this->redirect_with_notice( 'purge_done' );
	}

	/**
	 * Convert a stored machine label to a human-readable label.
	 *
	 * @param string $value Raw label.
	 * @return string
	 */
	private function format_dashboard_label( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return __( 'Idle', 'helikon-xml-woo-sync' );
		}

		return ucwords( str_replace( '_', ' ', $value ) );
	}

	/**
	 * Format a schedule label for the dashboard.
	 *
	 * @param string $schedule Schedule key.
	 * @return string
	 */
	private function format_schedule_label( $schedule ) {
		$labels = array(
			'manual'           => __( 'Manual only', 'helikon-xml-woo-sync' ),
			'every_30_minutes' => __( 'Every 30 minutes', 'helikon-xml-woo-sync' ),
			'hourly'           => __( 'Hourly', 'helikon-xml-woo-sync' ),
			'twicedaily'       => __( 'Twice daily', 'helikon-xml-woo-sync' ),
			'daily'            => __( 'Daily', 'helikon-xml-woo-sync' ),
		);

		return isset( $labels[ $schedule ] ) ? $labels[ $schedule ] : $this->format_dashboard_label( $schedule );
	}

	/**
	 * Get progress percent for the current run.
	 *
	 * @param array $state Runtime state.
	 * @return int
	 */
	private function get_progress_percent( $state ) {
		$total = isset( $state['total_products'] ) ? (int) $state['total_products'] : 0;
		if ( $total < 1 ) {
			return 0;
		}

		$percent = ( (int) $state['checkpoint'] / $total ) * 100;
		return max( 0, min( 100, (int) round( $percent ) ) );
	}

	/**
	 * Parse the newest log entries for display.
	 *
	 * @param array $logs  Raw log lines.
	 * @param int   $limit Maximum number of rows to return.
	 * @return array
	 */
	private function get_recent_log_entries( $logs, $limit = 8 ) {
		$entries = array();
		$logs    = array_slice( array_reverse( array_values( (array) $logs ) ), 0, max( 1, (int) $limit ) );

		foreach ( $logs as $log_line ) {
			$entry = array(
				'level'   => 'info',
				'time'    => '',
				'message' => (string) $log_line,
			);

			if ( preg_match( '/^\[([A-Z]+)\]\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s+UTC)\s+(.*)$/', (string) $log_line, $matches ) ) {
				$entry['level']   = strtolower( $matches[1] );
				$entry['time']    = $matches[2];
				$entry['message'] = $matches[3];
			}

			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * Render admin notices from query args.
	 *
	 * @param string $notice Notice code.
	 * @return void
	 */
	private function render_notice( $notice ) {
		$messages = array(
			'sync_queued'      => array( 'success', __( 'Sync queued. It will continue in small batches.', 'helikon-xml-woo-sync' ) ),
			'test_sync_queued' => array( 'success', __( 'Test sync queued. It will process only the configured number of rows.', 'helikon-xml-woo-sync' ) ),
			'sync_busy'        => array( 'warning', __( 'A sync is already running. Check the status box or clear the lock if it is stuck.', 'helikon-xml-woo-sync' ) ),
			'test_ok'          => array( 'success', __( 'Connection test succeeded.', 'helikon-xml-woo-sync' ) ),
			'test_failed'      => array( 'error', __( 'Connection test failed. Check the log for details.', 'helikon-xml-woo-sync' ) ),
			'lock_cleared'     => array( 'warning', __( 'The sync lock was cleared manually.', 'helikon-xml-woo-sync' ) ),
			'logs_cleared'     => array( 'success', __( 'Recent logs were cleared.', 'helikon-xml-woo-sync' ) ),
			'purge_done'       => array( 'warning', __( 'Imported data was deleted.', 'helikon-xml-woo-sync' ) ),
			'purge_failed'     => array( 'error', __( 'Imported data could not be deleted. Check the log for details.', 'helikon-xml-woo-sync' ) ),
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
