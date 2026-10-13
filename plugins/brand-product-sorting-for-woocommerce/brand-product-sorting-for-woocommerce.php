<?php
/**
 * Plugin Name:       Brand Product Sorting for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/brand-product-sorting-for-woocommerce/
 * Description:       Drag and drop the order of products inside a brand or category archive. The order is stored per term and applied on that archive's main query.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       brand-product-sorting-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_BPS_VERSION', '1.0.0' );
define( 'ERU_BPS_FILE', __FILE__ );
define( 'ERU_BPS_URL', plugin_dir_url( __FILE__ ) );

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
			add_action( 'admin_notices', fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'brand-product-sorting-for-woocommerce' ) . '</p></div>' );
			return;
		}
		require_once __DIR__ . '/includes/class-eru-bps.php';
		Eru_BPS::init();
		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-eru-bps-admin.php';
			Eru_BPS_Admin::init();
		}
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_BPS_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=product&page=eru-bps' ) ) . '">' . esc_html__( 'Sıralama', 'brand-product-sorting-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
