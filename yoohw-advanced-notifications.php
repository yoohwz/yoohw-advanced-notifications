<?php
/**
 * Plugin Name: YoOhw Advanced Notifications
 * Plugin URI: https://wordpress.org/plugins/yoohw-advanced-notifications
 * Description: Compose, preview, schedule and track WooCommerce customer email campaigns.
 * Version: 0.2.2
 * Author: YoOhw Studio
 * Author URI: https://yoohw.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Text Domain: yoohw-advanced-notifications
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YOOHW_AN_VERSION', '0.2.2' );

function yoohw_an_load_textdomain() {
	// Register bundled language packs for private installations without WordPress.org language packs.
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Bundled translations must load for this privately distributed plugin.
	load_plugin_textdomain( 'yoohw-advanced-notifications', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'yoohw_an_load_textdomain', 0 );

require_once __DIR__ . '/includes/class-customer-email-notify.php';

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'YoOhw Advanced Notifications requires WooCommerce to be active.', 'yoohw-advanced-notifications' ) . '</p></div>';
			}
		} );
		return;
	}

	new YOOHW_AN_Customer_Email_Notify();
}, 20 );

register_deactivation_hook( __FILE__, function () {
	$notify = new YOOHW_AN_Customer_Email_Notify();
	$notify->deactivate();
} );
