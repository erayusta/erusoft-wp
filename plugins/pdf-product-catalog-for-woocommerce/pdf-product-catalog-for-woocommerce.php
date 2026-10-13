<?php
/**
 * Plugin Name:       PDF Product Catalog for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/pdf-product-catalog-for-woocommerce/
 * Description:       Generates a print-ready product catalogue (cover page, products grouped by category) from selected WooCommerce categories; save it as PDF from the browser. Turkish characters supported.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pdf-product-catalog-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_PPC_VERSION', '1.0.0' );
define( 'ERU_PPC_FILE', __FILE__ );
define( 'ERU_PPC_URL', plugin_dir_url( __FILE__ ) );

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
			add_action( 'admin_notices', fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'pdf-product-catalog-for-woocommerce' ) . '</p></div>' );
			return;
		}
		require_once __DIR__ . '/includes/class-eru-ppc.php';
		// Settings contain translated defaults, so initialise once translations may load.
		add_action( 'init', array( 'Eru_PPC', 'init' ), 5 );
		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-eru-ppc-admin.php';
			Eru_PPC_Admin::init();
		}
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_PPC_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-ppc' ) ) . '">' . esc_html__( 'Ayarlar', 'pdf-product-catalog-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
