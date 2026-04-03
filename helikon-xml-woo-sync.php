<?php
/**
 * Plugin Name: Helikon XML Woo Sync
 * Plugin URI: https:www.rahimbasdsa.me
 * Description: Safely imports a password-protected XML feed into WooCommerce with locked, resumable batch syncs.
 * Version: 1.1.1
 * Author: Rahim Badsa
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: helikon-xml-woo-sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HTX_XML_WOO_SYNC_VERSION', '1.1.0' );
define( 'HTX_XML_WOO_SYNC_FILE', __FILE__ );
define( 'HTX_XML_WOO_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'HTX_XML_WOO_SYNC_URL', plugin_dir_url( __FILE__ ) );

require_once HTX_XML_WOO_SYNC_PATH . 'includes/class-htx-xml-woo-sync-state.php';
require_once HTX_XML_WOO_SYNC_PATH . 'includes/class-htx-xml-woo-sync-images.php';
require_once HTX_XML_WOO_SYNC_PATH . 'includes/class-htx-xml-woo-sync-feed.php';
require_once HTX_XML_WOO_SYNC_PATH . 'includes/class-htx-xml-woo-sync-products.php';
require_once HTX_XML_WOO_SYNC_PATH . 'includes/class-htx-xml-woo-sync-runner.php';
require_once HTX_XML_WOO_SYNC_PATH . 'includes/class-htx-xml-woo-sync-price-importer.php';
require_once HTX_XML_WOO_SYNC_PATH . 'includes/class-htx-xml-woo-sync-admin.php';
require_once HTX_XML_WOO_SYNC_PATH . 'includes/class-htx-xml-woo-sync-plugin.php';

HTX_XML_Woo_Sync_Plugin::instance();
