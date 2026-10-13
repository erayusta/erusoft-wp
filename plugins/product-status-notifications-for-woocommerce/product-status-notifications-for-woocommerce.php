<?php
/**
 * Plugin Name:       Product Status Notifications for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/product-status-notifications-for-woocommerce/
 * Description:       E-mails a configurable recipient list when a product changes status, goes out of stock or has its price changed.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       product-status-notifications-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_PSN_VERSION', '1.0.0' );
define( 'ERU_PSN_FILE', __FILE__ );
define( 'ERU_PSN_URL', plugin_dir_url( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'product-status-notifications-for-woocommerce' ) . '</p></div>' );
			return;
		}
		require_once __DIR__ . '/includes/class-eru-psn.php';
		// Settings contain translated defaults, so initialise once translations may load.
		add_action( 'init', array( 'Eru_PSN', 'init' ), 5 );
		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-eru-psn-admin.php';
			Eru_PSN_Admin::init();
		}
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_PSN_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-psn' ) ) . '">' . esc_html__( 'Ayarlar', 'product-status-notifications-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
