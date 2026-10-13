<?php
/**
 * Plugin Name:       Smart Product Search for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/smart-product-search-for-woocommerce/
 * Description:       Turkish-aware product search for WooCommerce: matches ı/i, ş/s, ğ/g, ç/c, ö/o, ü/u and SKUs, ranks results by relevance and offers an optional live dropdown.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-product-search-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_SPS_VERSION', '1.0.0' );
define( 'ERU_SPS_FILE', __FILE__ );
define( 'ERU_SPS_URL', plugin_dir_url( __FILE__ ) );

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
			add_action( 'admin_notices', fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'smart-product-search-for-woocommerce' ) . '</p></div>' );
			return;
		}
		require_once __DIR__ . '/includes/class-eru-sps.php';
		// Settings contain translated defaults, so initialise once translations may load.
		add_action( 'init', array( 'Eru_SPS', 'init' ), 5 );
		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-eru-sps-admin.php';
			Eru_SPS_Admin::init();
		}
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_SPS_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-sps' ) ) . '">' . esc_html__( 'Ayarlar', 'smart-product-search-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
