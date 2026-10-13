<?php
/**
 * Plugin Name:       Bundle Stock Sync for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/bundle-stock-sync-for-woocommerce/
 * Description:       Define a product as a bundle of component SKUs and quantities. Bundle stock is calculated from the components, selling a bundle reduces the components, and selling or restocking a component recalculates every bundle that contains it.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bundle-stock-sync-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_BSS_VERSION', '1.0.0' );
define( 'ERU_BSS_FILE', __FILE__ );
define( 'ERU_BSS_URL', plugin_dir_url( __FILE__ ) );

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
			add_action( 'admin_notices', fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'bundle-stock-sync-for-woocommerce' ) . '</p></div>' );
			return;
		}
		require_once __DIR__ . '/includes/class-eru-bss-bundles.php';
		require_once __DIR__ . '/includes/class-eru-bss-sync.php';
		Eru_BSS_Sync::init();
		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-eru-bss-admin.php';
			Eru_BSS_Admin::init();
		}
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_BSS_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=product&page=eru-bss' ) ) . '">' . esc_html__( 'Ayarlar', 'bundle-stock-sync-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
