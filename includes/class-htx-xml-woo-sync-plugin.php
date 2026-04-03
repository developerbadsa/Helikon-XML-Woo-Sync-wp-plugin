<?php
/**
 * Plugin bootstrap.
 *
 * @package Helikon_XML_Woo_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTX_XML_Woo_Sync_Plugin {
	const OPTION_KEY              = 'htx_xml_woo_sync_settings';
	const STATE_KEY               = 'htx_xml_woo_sync_state';
	const LOCK_KEY                = 'htx_xml_woo_sync_lock';
	const CRON_SCHEDULE_HOOK      = 'htx_xml_woo_sync_cron';
	const CRON_BATCH_HOOK         = 'htx_xml_woo_sync_process_batch';
	const NONCE_ACTION_SYNC       = 'htx_xml_woo_sync_manual';
	const NONCE_ACTION_TEST_SYNC  = 'htx_xml_woo_sync_test_sync';
	const NONCE_ACTION_TEST       = 'htx_xml_woo_sync_test';
	const NONCE_ACTION_CLEAR_LOCK = 'htx_xml_woo_sync_clear_lock';
	const NONCE_ACTION_CLEAR_LOGS = 'htx_xml_woo_sync_clear_logs';
	const NONCE_ACTION_PURGE_DATA = 'htx_xml_woo_sync_purge_data';
	const NONCE_ACTION_PRICE_LIST = 'htx_xml_woo_sync_price_list';

	const META_GROUP_KEY          = '_htx_group_key';
	const META_GROUP_EXTERNAL     = '_htx_group_external_key';
	const META_SKU_BASE           = '_htx_sku_base';
	const META_IMAGE_SOURCE       = '_htx_source_image_url';
	const META_IS_MANAGED         = '_htx_xml_managed';
	const META_LAST_SYNC_TOKEN    = '_htx_last_sync_token';
	const META_MISSING_MARK_TOKEN = '_htx_missing_mark_token';
	const META_SOURCE_SKU         = '_htx_source_sku';
	const META_PRICE_CURRENCY     = '_htx_price_currency';
	const META_SALE_CURRENCY      = '_htx_sale_currency';
	const META_MSRP_PRICE         = '_htx_msrp_price';
	const META_MSRP_CURRENCY      = '_htx_msrp_currency';

	/**
	 * Singleton instance.
	 *
	 * @var HTX_XML_Woo_Sync_Plugin|null
	 */
	private static $instance = null;

	/**
	 * State instance.
	 *
	 * @var HTX_XML_Woo_Sync_State
	 */
	private $state;

	/**
	 * Admin instance.
	 *
	 * @var HTX_XML_Woo_Sync_Admin
	 */
	private $admin;

	/**
	 * Runner instance.
	 *
	 * @var HTX_XML_Woo_Sync_Runner
	 */
	private $runner;

	/**
	 * Get singleton instance.
	 *
	 * @return HTX_XML_Woo_Sync_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'bootstrap' ) );
		register_activation_hook( HTX_XML_WOO_SYNC_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( HTX_XML_WOO_SYNC_FILE, array( __CLASS__, 'deactivate' ) );
	}

	/**
	 * Bootstrap services and hooks.
	 *
	 * @return void
	 */
	public function bootstrap() {
		$this->state  = new HTX_XML_Woo_Sync_State();
		$images       = new HTX_XML_Woo_Sync_Images( $this->state );
		$feed         = new HTX_XML_Woo_Sync_Feed( $this->state );
		$products     = new HTX_XML_Woo_Sync_Products( $this->state, $images );
		$price_import = new HTX_XML_Woo_Sync_Price_Importer( $this->state );
		$this->runner = new HTX_XML_Woo_Sync_Runner( $this->state, $feed, $products );
		$this->admin  = new HTX_XML_Woo_Sync_Admin( $this->state, $feed, $this->runner, $price_import );

		$this->admin->register_hooks();

		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'update_option_' . self::OPTION_KEY, array( $this, 'handle_settings_updated' ), 10, 2 );

		if ( $this->is_woocommerce_ready() ) {
			add_action( self::CRON_SCHEDULE_HOOK, array( $this->runner, 'maybe_start_scheduled_sync' ) );
			add_action( self::CRON_BATCH_HOOK, array( $this->runner, 'process_batch' ), 10, 1 );
			add_action( 'admin_menu', array( $this->admin, 'add_menu' ) );
		} else {
			add_action( 'admin_notices', array( $this, 'woocommerce_required_notice' ) );
		}
	}

	/**
	 * Check whether WooCommerce is loaded.
	 *
	 * @return bool
	 */
	public function is_woocommerce_ready() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		$state = new HTX_XML_Woo_Sync_State();
		$state->update_state( wp_parse_args( get_option( self::STATE_KEY, array() ), $state->get_default_state() ) );

		$schedule = $state->get_settings()['schedule'];
		wp_clear_scheduled_hook( self::CRON_SCHEDULE_HOOK );
		if ( 'manual' !== $schedule ) {
			wp_schedule_event( time() + 300, $schedule, self::CRON_SCHEDULE_HOOK );
		}
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_SCHEDULE_HOOK );
		wp_clear_scheduled_hook( self::CRON_BATCH_HOOK );
		delete_option( self::LOCK_KEY );
	}

	/**
	 * Register an additional conservative schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['every_30_minutes'] ) ) {
			$schedules['every_30_minutes'] = array(
				'interval' => 30 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 30 Minutes', 'helikon-xml-woo-sync' ),
			);
		}

		return $schedules;
	}

	/**
	 * Reschedule cron after settings updates.
	 *
	 * @param array $old_value Old settings.
	 * @param array $new_value New settings.
	 * @return void
	 */
	public function handle_settings_updated( $old_value, $new_value ) {
		$schedule = isset( $new_value['schedule'] ) ? $new_value['schedule'] : 'daily';
		$this->schedule_recurring_sync( $schedule );
	}

	/**
	 * Maintain the recurring sync schedule.
	 *
	 * @param string $schedule Schedule key.
	 * @return void
	 */
	private function schedule_recurring_sync( $schedule ) {
		wp_clear_scheduled_hook( self::CRON_SCHEDULE_HOOK );

		if ( 'manual' === $schedule ) {
			return;
		}

		wp_schedule_event( time() + 300, $schedule, self::CRON_SCHEDULE_HOOK );
	}

	/**
	 * Show a WooCommerce dependency notice.
	 *
	 * @return void
	 */
	public function woocommerce_required_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Helikon XML Woo Sync requires WooCommerce to be active. Syncs are disabled until WooCommerce is available.', 'helikon-xml-woo-sync' )
		);
	}
}
