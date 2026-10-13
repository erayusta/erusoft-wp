<?php
/**
 * Plugin Name:       Bulk Product Visibility for WooCommerce
 * Plugin URI:        https://wordpress.erusoft.com/bulk-product-visibility-for-woocommerce/
 * Description:       Change catalog visibility or status of many products at once, filtered by category, stock status and price, with a dry-run count before anything is applied and batched processing.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bulk-product-visibility-for-woocommerce
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_BPV_VERSION', '1.0.0' );
define( 'ERU_BPV_FILE', __FILE__ );
define( 'ERU_BPV_URL', plugin_dir_url( __FILE__ ) );

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
			add_action( 'admin_notices', fn() => print '<div class="notice notice-warning"><p>' . esc_html__( 'Bu eklenti WooCommerce gerektirir.', 'bulk-product-visibility-for-woocommerce' ) . '</p></div>' );
			return;
		}
		require_once __DIR__ . '/includes/class-eru-bpv-engine.php';
		if ( is_admin() ) {
			require_once __DIR__ . '/includes/class-eru-bpv-admin.php';
			Eru_BPV_Admin::init();
		}
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_BPV_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=product&page=eru-bpv' ) ) . '">' . esc_html__( 'Toplu görünürlük', 'bulk-product-visibility-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}
);
