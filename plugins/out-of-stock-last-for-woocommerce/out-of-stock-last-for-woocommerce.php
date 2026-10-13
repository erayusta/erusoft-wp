<?php
/**
 * Plugin Name:       Out-of-Stock Last for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/out-of-stock-last-for-woocommerce/
 * Description:       Moves out-of-stock products to the end of shop, category, tag, brand and search results while keeping the chosen sort order for everything else.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       out-of-stock-last-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_OSL_VERSION', '1.0.0' );
define( 'ERU_OSL_FILE', __FILE__ );

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
			add_action( 'admin_notices', fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'out-of-stock-last-for-woocommerce' ) . '</p></div>' );
			return;
		}
		require_once __DIR__ . '/includes/class-eru-osl.php';
		Eru_OSL::init();
		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-eru-osl-admin.php';
			Eru_OSL_Admin::init();
		}
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_OSL_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-osl' ) ) . '">' . esc_html__( 'Ayarlar', 'out-of-stock-last-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
